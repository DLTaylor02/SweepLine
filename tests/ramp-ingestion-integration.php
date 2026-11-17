<?php
// Exercise the normal importer, source refresh and rollback using real OSM-length coordinates.
(function(PDO $db):void{
    $region=$db->query("INSERT INTO regions(slug,name) VALUES ('ramp-import-regression','Ramp import regression') RETURNING id")->fetchColumn();
    $q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'running',?,ST_MakeEnvelope(-119.81,39.49,-119.78,39.55,4326)) RETURNING *");
    $file=tempnam(sys_get_temp_dir(),'sl-ramp-import-');$dir=sys_get_temp_dir().'/sl-ramp-refresh-'.bin2hex(random_bytes(4));mkdir($dir,0700);
    $xml=file_get_contents(__DIR__.'/fixture.osm');
    $extra='<node id="7" lat="39.5202" lon="-119.7995"/><node id="8" lat="39.52021" lon="-119.79949"/><node id="9" lat="39.52022" lon="-119.79948"/><way id="991"><nd ref="3"/><nd ref="7"/><tag k="highway" v="motorway_link"/><tag k="oneway" v="yes"/></way><way id="992"><nd ref="7"/><nd ref="8"/><tag k="highway" v="motorway_link"/><tag k="oneway" v="yes"/></way><way id="993"><nd ref="8"/><nd ref="9"/><tag k="highway" v="secondary"/><tag k="name" v="Short mapped connector"/></way>';
    $xml=str_replace('</osm>',$extra.'</osm>',$xml);file_put_contents($file,$xml);
    $q->execute([$region,$file]);$job=$q->fetch(PDO::FETCH_ASSOC);
    try{
        (new SweepLine\Importer($db))->run($job);
        $count=$db->prepare("SELECT count(*) FROM road_segments WHERE job_id=? AND highway='motorway_link'");$count->execute([$job['id']]);
        check((int)$count->fetchColumn()===2,'Actual XML importer retains motorway_link ways and tiny link connectivity');
        $exists=$db->prepare('SELECT count(*) FROM road_segments WHERE job_id=? AND osm_id=993');$exists->execute([$job['id']]);
        check((int)$exists->fetchColumn()===1,'Actual XML importer retains non-link roads shorter than 15 metres for connectivity');
        $db->prepare('DELETE FROM road_segments WHERE job_id=? AND osm_id IN (991,993)')->execute([$job['id']]);
        $db->prepare('UPDATE import_jobs SET ramp_context_version=2 WHERE id=?')->execute([$job['id']]);
        $before=$db->prepare('SELECT count(*) FROM road_candidates WHERE job_id=?');$before->execute([$job['id']]);$candidates=(int)$before->fetchColumn();
        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(geom,32611)) FROM road_segments WHERE job_id=? AND osm_id=10');$q->execute([$job['id']]);
        $plan=['epsg'=>32611,'points'=>json_decode($q->fetchColumn(),true)['coordinates']];
        $calls=0;$download=static function($url,$path,$max,$post)use($xml,&$calls){
            $calls++;parse_str($post,$request);
            if(!str_contains($request['data'],'out:xml')||str_contains($request['data'],'length()'))throw new RuntimeException('Expected a complete regional extract request.');
            file_put_contents($path,$xml);
        };
        $result=SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,$download);
        $active=$db->prepare('SELECT active_job_id FROM regions WHERE id=?');$active->execute([$region]);$replacement=(int)$active->fetchColumn();
        check($result['upgraded']===1&&$replacement!==(int)$job['id'],'Export preparation refreshes the entire obsolete region into a new import');
        $exists->execute([$replacement]);$count->execute([$replacement]);$before->execute([$job['id']]);
        check((int)$exists->fetchColumn()===1&&(int)$count->fetchColumn()===2&&(int)$before->fetchColumn()===$candidates,'Refreshed source restores missing connectors while retaining previous candidate rows');
        SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,$download);
        check($calls===1,'Current topology version reuses the refreshed dataset without another download');
        $db->prepare('UPDATE import_jobs SET ramp_context_version=2 WHERE id=?')->execute([$replacement]);
        $failed=false;try{SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,static function(){throw new SweepLine\DownloadFailure('Offline failure',false);});}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'refresh failed');}
        $active->execute([$region]);
        check($failed&&(int)$active->fetchColumn()===$replacement,'Unavailable source stops export and preserves the active regional dataset');
        $failed=false;try{SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,static function($url,$path){file_put_contents($path,'<osm><remark>incomplete</remark></osm>');},static fn()=>null);}catch(RuntimeException $e){$failed=true;}
        $active->execute([$region]);
        check($failed&&(int)$active->fetchColumn()===$replacement,'Invalid replacement source cannot activate or mark the region current');
        $incomplete=str_replace('<nd ref="9"/>','<nd ref="999999"/>',$xml);
        $failed=false;try{SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,static function($url,$path)use($incomplete){file_put_contents($path,$incomplete);});}catch(RuntimeException $e){$failed=true;}
        $active->execute([$region]);
        check($failed&&(int)$active->fetchColumn()===$replacement,'An import with missing nodes preserves the previously active dataset');
        $db->prepare('UPDATE import_jobs SET coverage=ST_MakeEnvelope(-119.9,39.4,-119.65,39.65,4326) WHERE id=?')->execute([$replacement]);
        $db->prepare('UPDATE regions SET coverage=(SELECT coverage FROM import_jobs WHERE id=?) WHERE id=?')->execute([$replacement,$region]);
        $calls=0;$broken=static function($url,$path)use(&$calls,$xml,$incomplete){file_put_contents($path,++$calls===4?$incomplete:$xml);};
        $failed=false;try{SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,$broken,static fn()=>null);}catch(RuntimeException $e){$failed=true;}
        $active->execute([$region]);$children=$db->query("SELECT count(*) FROM regions WHERE slug LIKE 'ramp-import-regression-%' AND active_job_id IS NOT NULL")->fetchColumn();
        check($failed&&(int)$active->fetchColumn()===$replacement&&(int)$children===0,'Failure in a later staged child preserves the parent and activates no partial replacement');
        $fresh=SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,static function($url,$path)use($xml){file_put_contents($path,$xml);},static fn()=>null);
        $active->execute([$region]);$parentActive=$active->fetchColumn();$children=$db->query("SELECT count(*) FROM regions WHERE slug LIKE 'ramp-import-regression-%' AND active_job_id IS NOT NULL")->fetchColumn();
        $parentTask=$db->query("SELECT status FROM data_tasks WHERE task_key='ramp-import-regression'")->fetchColumn();
        check($parentActive===null&&(int)$children===4&&$parentTask==='split'&&count($fresh['refreshed_regions'][0]['replacement_imports'])===4,'Validated children replace the dense parent together and mark its preparation task split');
    }finally{
        if($db->inTransaction())$db->rollBack();
        $ids=$db->query("SELECT id FROM regions WHERE slug='ramp-import-regression' OR slug LIKE 'ramp-import-regression-%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach($ids as $id)$db->prepare('UPDATE regions SET active_job_id=null WHERE id=?')->execute([$id]);
        $db->exec("DELETE FROM data_tasks WHERE task_key='ramp-import-regression' OR task_key LIKE 'ramp-import-regression-%'");
        foreach($ids as $id){$db->prepare('DELETE FROM import_jobs WHERE region_id=?')->execute([$id]);$db->prepare('DELETE FROM regions WHERE id=?')->execute([$id]);}
        if(is_file($file))unlink($file);foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);
    }
})($db);
