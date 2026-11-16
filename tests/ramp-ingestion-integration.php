<?php
// Exercises the real importer and legacy supplementation, not preinserted ramp rows.
(function(PDO $db):void{
    $region=$db->query("INSERT INTO regions(slug,name) VALUES ('ramp-import-regression','Ramp import regression') RETURNING id")->fetchColumn();
    $q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'running',?,ST_MakeEnvelope(-119.81,39.49,-119.78,39.55,4326)) RETURNING *");
    $file=tempnam(sys_get_temp_dir(),'sl-ramp-import-');$dir=sys_get_temp_dir().'/sl-ramp-backfill-'.bin2hex(random_bytes(4));mkdir($dir,0700);
    $xml=file_get_contents(__DIR__.'/fixture.osm');
    $extra='<node id="7" lat="39.5202" lon="-119.7995"/><way id="991"><nd ref="3"/><nd ref="7"/><tag k="highway" v="motorway_link"/><tag k="oneway" v="yes"/></way><node id="8" lat="39.52021" lon="-119.79949"/><way id="992"><nd ref="7"/><nd ref="8"/><tag k="highway" v="motorway_link"/><tag k="oneway" v="yes"/></way>';
    file_put_contents($file,str_replace('</osm>',$extra.'</osm>',$xml));
    $q->execute([$region,$file]);$job=$q->fetch(PDO::FETCH_ASSOC);
    try{
        (new SweepLine\Importer($db))->run($job);
        $count=$db->prepare("SELECT count(*) FROM road_segments WHERE job_id=? AND highway='motorway_link'");$count->execute([$job['id']]);
        check((int)$count->fetchColumn()===2,'Actual XML importer retains motorway_link ways and short connector connectivity');
        $db->prepare('DELETE FROM road_segments WHERE job_id=? AND osm_id=991')->execute([$job['id']]);
        $before=$db->prepare('SELECT count(*) FROM road_candidates WHERE job_id=?');$before->execute([$job['id']]);$candidates=(int)$before->fetchColumn();
        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(geom,32611)) FROM road_segments WHERE job_id=? AND osm_id=10');$q->execute([$job['id']]);
        $plan=['epsg'=>32611,'points'=>json_decode($q->fetchColumn(),true)['coordinates']];
        $way=['type'=>'way','id'=>991,'nodes'=>[3,7],'tags'=>['highway'=>'motorway_link','oneway'=>'yes'],'geometry'=>[['lon'=>-119.80,'lat'=>39.52],['lon'=>-119.7995,'lat'=>39.5202]]];
        $calls=0;$download=static function($url,$path)use($way,&$calls){$calls++;file_put_contents($path,json_encode(['elements'=>[$way]],JSON_THROW_ON_ERROR));};
        $result=SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,$download);
        check($result['upgraded']===1&&$result['added_ramp_ways']===1,'Export preparation automatically backfills a legacy region missing ramps');
        $count->execute([$job['id']]);$before->execute([$job['id']]);
        check((int)$count->fetchColumn()===2&&(int)$before->fetchColumn()===$candidates,'Backfill adds ramp rows without replacing selected road candidates');
        SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,$download);
        check($calls===1,'Completed legacy region backfill is reused on subsequent exports');
        $db->prepare('UPDATE import_jobs SET ramp_context_version=0 WHERE id=?')->execute([$job['id']]);
        $failed=SweepLine\BeamRampBackfill::ensure($db,$plan,$dir,static fn($m)=>null,static function(){throw new SweepLine\DownloadFailure('Offline failure',false);});
        check(count($failed['failures'])===1,'Failed supplemental provider is reported while existing road data remains active');
        $state=$db->prepare('SELECT ramp_context_version FROM import_jobs WHERE id=?');$state->execute([$job['id']]);
        check((int)$state->fetchColumn()===0,'Failed backfill remains eligible for retry on a later export');
    }finally{
        if($db->inTransaction())$db->rollBack();
        $db->prepare('UPDATE regions SET active_job_id=NULL WHERE id=?')->execute([$region]);
        $db->prepare('DELETE FROM import_jobs WHERE region_id=?')->execute([$region]);$db->prepare('DELETE FROM regions WHERE id=?')->execute([$region]);
        if(is_file($file))unlink($file);foreach(glob($dir.'/*') as $p)unlink($p);rmdir($dir);
    }
})($db);
