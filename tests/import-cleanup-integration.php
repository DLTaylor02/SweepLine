<?php
// Dedicated disposable PostGIS database only; all records below are synthetic.
$db->exec(file_get_contents(SWEEPLINE_ROOT.'/database/migrations/018_import_cleanup_indexes.sql'));
check((int)$db->query("SELECT count(*) FROM pg_indexes WHERE schemaname='public' AND indexname IN ('candidate_segments_segment','data_tasks_import_job','regions_active_job','import_jobs_region','import_jobs_cleanup_age')")->fetchColumn()===5,'Cleanup foreign-key/eligibility indexes exist and migration is repeatable');
$cleanupRegion=(int)$db->query("INSERT INTO regions(slug,name) VALUES ('auto-cleanup-regression','Synthetic cleanup region') RETURNING id")->fetchColumn();
$newImport=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage,created_at) VALUES (?,?,?,ST_MakeEnvelope(-91,39,-89,41,4326),now()-CAST(? AS integer)*interval '1 day') RETURNING id");
$newImport->execute([$cleanupRegion,'complete',__FILE__,9]);$obsolete=(int)$newImport->fetchColumn();
$newImport->execute([$cleanupRegion,'complete',__FILE__,8]);$retained=(int)$newImport->fetchColumn();
$newImport->execute([$cleanupRegion,'complete',__FILE__,0]);$young=(int)$newImport->fetchColumn();
$db->prepare('UPDATE regions SET active_job_id=? WHERE id=?')->execute([$retained,$cleanupRegion]);
$db->prepare("INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) SELECT ?,g,-90,40,'{}'::jsonb FROM generate_series(1,6000) g")->execute([$obsolete]);
$db->prepare("INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) VALUES (?,1,-90,40,'{}'::jsonb)")->execute([$retained]);
$segments=$db->prepare("INSERT INTO road_segments(job_id,osm_id,name,highway,direction,node_ids,tags,geom) SELECT ?,g,'Synthetic road','secondary',0,'[1,2]'::jsonb,'{}'::jsonb,ST_GeomFromText('LINESTRING(-90 40,-89.999 40)',4326) FROM generate_series(1,300) g");
$segments->execute([$obsolete]);
$addCandidate=$db->prepare("INSERT INTO road_candidates(job_id,name,highway,surface_known_paved,length_m,mandatory_stops,uncertain_controls,metrics,geom) VALUES (?,'Synthetic road','secondary',true,100,0,0,'{}'::jsonb,ST_GeogFromText('SRID=4326;LINESTRING(-90 40,-89.999 40)')) RETURNING id");
$addCandidate->execute([$obsolete]);$obsoleteCandidate=(int)$addCandidate->fetchColumn();
$db->prepare('INSERT INTO candidate_segments(candidate_id,segment_id,position,reversed) SELECT ?,id,(row_number() OVER (ORDER BY id)-1)::integer,false FROM road_segments WHERE job_id=?')->execute([$obsoleteCandidate,$obsolete]);
$db->prepare("INSERT INTO road_scores(candidate_id,profile,score,confidence,dimensions) VALUES (?,'open',50,0.5,'{}'::jsonb)")->execute([$obsoleteCandidate]);
$db->prepare('INSERT INTO road_connectivity(job_id,node_id,segment_id,point_index) SELECT job_id,1,id,0 FROM road_segments WHERE job_id=?')->execute([$obsolete]);
$beforeTimeout=$db->query('SHOW statement_timeout')->fetchColumn();$beforeLock=$db->query('SHOW lock_timeout')->fetchColumn();
$cleaner=new SweepLine\ImportCleanup($db);
$holder=SweepLine\Database::connect();$holder->beginTransaction();
$holder->prepare('SELECT id FROM import_jobs WHERE id=? FOR UPDATE')->execute([$obsolete]);
try{check(!$cleaner->runOne(),'Idle cleanup skips a locked obsolete import without waiting');}
finally{$holder->rollBack();}
check($cleaner->runOne(),'One bounded cleanup step runs on obsolete data');
$q=$db->prepare('SELECT count(*) FROM import_jobs WHERE id=?');$q->execute([$obsolete]);
check((int)$q->fetchColumn()===1,'Partial cleanup retains obsolete job metadata');
$steps=1;
while($steps<25){
 $q->execute([$obsolete]);if(!(int)$q->fetchColumn())break;
 check($cleaner->runOne(),'Next inactive-import cleanup batch resumes');$steps++;
}
$q->execute([$obsolete]);check((int)$q->fetchColumn()===0&&$steps>3,'A multi-table import is fully removed across several bounded transactions');
$q->execute([$retained]);check((int)$q->fetchColumn()===1,'Old active automatic import is preserved');
$q->execute([$young]);check((int)$q->fetchColumn()===1,'Young inactive import retains its seven-day grace period');
$q=$db->prepare('SELECT count(*) FROM osm_nodes WHERE job_id=?');$q->execute([$retained]);check((int)$q->fetchColumn()===1,'Current active geographic data is unchanged');
check($db->query('SHOW statement_timeout')->fetchColumn()===$beforeTimeout&&$db->query('SHOW lock_timeout')->fetchColumn()===$beforeLock,'Cleanup restores caller statement/lock deadlines after committing');
$manualRegion=(int)$db->query("INSERT INTO regions(slug,name) VALUES ('manual-cleanup-regression','Synthetic manual region') RETURNING id")->fetchColumn();
$newImport->execute([$manualRegion,'complete',__FILE__,10]);$manualRetained=(int)$newImport->fetchColumn();
check(!$cleaner->runOne(),'Old noncancelled manual imports are not automatically pruned');
$q=$db->prepare('SELECT count(*) FROM import_jobs WHERE id=?');$q->execute([$manualRetained]);check((int)$q->fetchColumn()===1,'Manual import metadata is preserved');
$newImport->execute([$manualRegion,'failed',__FILE__,0]);$cancelled=(int)$newImport->fetchColumn();
$db->prepare('UPDATE import_jobs SET cancel_requested=true WHERE id=?')->execute([$cancelled]);
$db->prepare("INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) VALUES (?,1,-90,40,'{}'::jsonb)")->execute([$cancelled]);
(new SweepLine\DataQueue($db))->purgeCancelled();$q->execute([$cancelled]);
check((int)$q->fetchColumn()===1,'Web queue cleanup leaves cancelled geographic data for bounded idle cleanup');
for($step=0;$step<10;$step++){if(!$cleaner->runOne())break;}
$q->execute([$cancelled]);check((int)$q->fetchColumn()===0,'Idle cleanup finishes cancelled inactive geographic imports');
