<?php
// Disposable PostGIS integration only. Coordinates and topology are synthetic.
$splitRegion=$db->query("INSERT INTO regions(slug,name) VALUES ('divided-test','Synthetic divided test') RETURNING id")->fetchColumn();
$splitImport=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'running',?,ST_MakeEnvelope(-85,40,-83,42,4326)) RETURNING *");
$splitImport->execute([$splitRegion,__DIR__.'/fixture-divided.osm']);$splitJob=$splitImport->fetch(PDO::FETCH_ASSOC);
(new SweepLine\Importer($db))->run($splitJob);$splitJobId=(int)$splitJob['id'];
$splitIds=$db->prepare('SELECT id FROM road_candidates WHERE job_id=? ORDER BY id');$splitIds->execute([$splitJobId]);$beforeIds=$splitIds->fetchAll(PDO::FETCH_COLUMN);
check(count($beforeIds)===2,'PostGIS import builds two full divided-road candidates');
foreach($beforeIds as $splitId){
 $detail=$api->detail((int)$splitId,'open');
 check((int)$detail['travel_direction']!==0,'API direction includes nonzero segment after two-way prefix');
 check($detail['length_m']>3300,'Discovery candidate spans divided section and both two-way approaches');
}
$db->exec("INSERT INTO zip_codes(zip,place,state,location) VALUES ('12346','Synthetic split','ZZ',ST_GeogFromText('SRID=4326;POINT(-84.38 41.20)'))");
$splitResults=$api->search(['zip'=>'12346','profile'=>'open','min_length_miles'=>2,'max_stops'=>10]);
check(count($splitResults['results'])===2,'Discovery retains complete opposite-carriageway results above length threshold');
$splitCustom=new SweepLine\CustomRoads($db);
foreach([[-84.398,-84.362],[-84.362,-84.398]] as [$firstLon,$lastLon]){
 $selection=$splitCustom->create($customUser,['start'=>['lon'=>$firstLon,'lat'=>41.2],'finish'=>['lon'=>$lastLon,'lat'=>41.2],'profile'=>'open']);
 check(isset($selection['road']['custom_id'])&&$selection['road']['length_m']>2900,'Custom selection crosses split and rejoin using retained local data');
 $selected=$selection['road'];$prepared=SweepLine\RoadExport::prepare($selected,$selected['preferred_orientation']);
 $selectedPoints=$prepared['geometry']['coordinates'];
 check(abs($selectedPoints[0][0]-$firstLon)<0.00001,'Custom split export follows clicked starting endpoint');
 $middleLats=array_column($selectedPoints,1);
 check($firstLon<$lastLon?max($middleLats)>41.2002:min($middleLats)<41.1998,'Custom shared approaches choose carriageway matching click order');
}
// Existing regions are rebuilt from retained raw OSM without reading the source file.
$db->prepare('UPDATE import_jobs SET analysis_version=1,source_path=? WHERE id=?')->execute(['/nonexistent-no-download.osm',$splitJobId]);
SweepLine\RoadGraphRefresh::run($db);
$splitIds->execute([$splitJobId]);$rebuiltIds=$splitIds->fetchAll(PDO::FETCH_COLUMN);
check(count($rebuiltIds)===2&&$rebuiltIds!==$beforeIds,'Upgrade rebuild replaces derived candidates without reimporting OSM');
SweepLine\RoadGraphRefresh::run($db);$splitIds->execute([$splitJobId]);
check($splitIds->fetchAll(PDO::FETCH_COLUMN)===$rebuiltIds,'Connectivity refresh is repeatable and leaves current versions unchanged');
// Force a failed replacement and verify the transaction preserves candidates/version.
$db->prepare('UPDATE import_jobs SET analysis_version=1 WHERE id=?')->execute([$splitJobId]);
$db->exec("CREATE FUNCTION reject_test_road_refresh() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Synthetic rebuild failure''; END'");
$db->exec("CREATE TRIGGER reject_test_road_refresh BEFORE INSERT ON road_candidates FOR EACH ROW EXECUTE FUNCTION reject_test_road_refresh()");
try{
 try{(new SweepLine\Importer($db))->rebuild($splitJobId);check(false,'Synthetic rebuild must fail');}
 catch(PDOException $e){check(true,'Rebuild failure propagates for installer diagnostics');}
 $splitIds->execute([$splitJobId]);check($splitIds->fetchAll(PDO::FETCH_COLUMN)===$rebuiltIds,'Failed connectivity refresh retains all previously active candidates');
 $version=$db->prepare('SELECT analysis_version FROM import_jobs WHERE id=?');$version->execute([$splitJobId]);
 check((int)$version->fetchColumn()===1,'Failed rebuild does not advance analysis version');
}finally{$db->exec('DROP TRIGGER reject_test_road_refresh ON road_candidates');$db->exec('DROP FUNCTION reject_test_road_refresh()');}

// Opposite-carriageway matching reads active imported OSM only; no provider calls.
$pairPlan=SweepLine\BeamPlan::project($db,$api->exportRoad((int)$rebuiltIds[0],'open'),SweepLine\BeamPlan::options([]));
$pair=SweepLine\BeamCarriageways::fetch($db,$pairPlan,static function($m){});
check(count($pair['tracks'])===1,'PostGIS matching finds the opposite carriageway of the imported divided road');
check($pair['tracks'][0]['length_m']>1000,'Database matching exports the corresponding carriageway beyond 600-foot side-road context');
check(count($pair['tracks'][0]['sources'])>0,'Matched opposite carriageway retains real OSM lane and structure tags');
foreach($pair['tracks'][0]['sources'] as $part)check(isset($part['tags'],$part['osm_id'],$part['direction']),'Projected opposite source attributes preserve identity and direction');


// Regional copies and clipped discovery labels must not govern source-network coverage.
$db->beginTransaction();
try{
    $copyRegion=$db->query("INSERT INTO regions(slug,name,imported_at) VALUES ('carriageway-regional-copy','Synthetic raw-way duplicate',now()+interval '1 minute') RETURNING id")->fetchColumn();
    $copyJob=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) SELECT ?,'complete','synthetic-no-source-file',coverage FROM import_jobs WHERE id=? RETURNING id");
    $copyJob->execute([$copyRegion,$splitJobId]);$copyJobId=(int)$copyJob->fetchColumn();
    $copyWays=$db->prepare('INSERT INTO road_segments(job_id,osm_id,name,ref,highway,direction,surface,speed_mph,node_ids,tags,geom) SELECT ?,osm_id,name,ref,highway,direction,surface,speed_mph,node_ids,tags,geom FROM road_segments WHERE job_id=?');
    $copyWays->execute([$copyJobId,$splitJobId]);
    $db->prepare('UPDATE regions SET active_job_id=? WHERE id=?')->execute([$copyJobId,$copyRegion]);
    $db->prepare("UPDATE road_candidates SET ref='NOT-A-SOURCE-MATCH',name='Unrelated discovery label' WHERE job_id=?")->execute([$splitJobId]);
    $rawPair=SweepLine\BeamCarriageways::fetch($db,$pairPlan,static function($m){});
    check(count($rawPair['tracks'])===1&&$rawPair['tracks'][0]['length_m']>1000,'Opposite-carriageway export uses connected raw ways even when discovery candidates do not match');
    check($rawPair['source_ways']===2,'Active regional OSM copies are deduplicated before building carriageway topology');
    check($rawPair['tracks'][0]['candidate_id']===null&&isset($rawPair['tracks'][0]['network_path_id'],$rawPair['tracks'][0]['source_way_ids']),'Raw network path provenance is not mislabeled as a discovery database candidate');
}finally{$db->rollBack();}
