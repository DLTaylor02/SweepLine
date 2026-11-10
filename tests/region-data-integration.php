<?php
// Runs only inside the guarded disposable PostGIS integration database.
(function()use($db): void{
    $external=tempnam(sys_get_temp_dir(),'sl-region-source-');file_put_contents($external,'source fixture');
    $user=(int)$db->query("SELECT id FROM app_users WHERE role='superadmin'")->fetchColumn();
    $manager=new \SweepLine\RegionData($db);$keys=['auto-region-scope-a','auto-region-scope-b','us-zips'];$regions=[];$jobs=[];
    $downloadRoot=SWEEPLINE_ROOT.'/var/downloads';if(!is_dir($downloadRoot))mkdir($downloadRoot,0750,true);
    $shared=$downloadRoot.'/roads-'.random_int(100000000,999999999).'.osm';file_put_contents($shared,'shared source fixture');
    try{
        foreach($keys as $key){
            $q=$db->prepare("INSERT INTO regions(slug,name,coverage,imported_at) VALUES (?,? ,ST_MakeEnvelope(-80,35,-79.9,35.1,4326),now()) RETURNING id");$q->execute([$key,$key]);$regions[]=(int)$q->fetchColumn();
            $q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'complete',?,ST_MakeEnvelope(-80,35,-79.9,35.1,4326)) RETURNING id");$q->execute([end($regions),$external]);$jobs[]=(int)$q->fetchColumn();
            $db->prepare('UPDATE regions SET active_job_id=? WHERE id=?')->execute([end($jobs),end($regions)]);
            $db->prepare("INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) VALUES (?,1,-79.95,35.05,'{}')")->execute([end($jobs)]);
        }
        $size=$manager->detail($keys[0]);check($size['database_estimated_bytes']>0&&$size['file_bytes']===0&&$size['external_source_files_retained']===1,'Region size scans owned rows and identifies retained external source files');
        $db->prepare('UPDATE import_jobs SET source_path=? WHERE id IN (?,?)')->execute([$shared,$jobs[1],$jobs[2]]);
        $manager->action($user,$keys[0],'refresh');
        $q=$db->prepare('SELECT active_job_id FROM regions WHERE id=?');$q->execute([$regions[0]]);
        check((int)$q->fetchColumn()===$jobs[0],'Explicit refresh retains active validated data');
        $q=$db->prepare("SELECT status,manual_priority,payload FROM data_tasks WHERE task_key=?");$q->execute([$keys[0]]);$task=$q->fetch(PDO::FETCH_ASSOC);
        check($task['status']==='queued'&&$task['manual_priority']&&json_decode($task['payload'],true)['key']===$keys[0],'Refresh creates an immediately eligible prioritized download for the exact region');
        $manager->action($user,$keys[0],'discard');$q=$db->prepare('SELECT active_job_id FROM regions WHERE id=?');$q->execute([$regions[0]]);
        check($q->fetchColumn()===null,'Discard immediately removes selected coverage from discovery');
        for($step=0;$step<30;$step++)if(!$manager->runOne())break;
        $q=$db->prepare('SELECT count(*) FROM regions WHERE id=?');$q->execute([$regions[0]]);check((int)$q->fetchColumn()===0,'Worker removes discarded region after bounded child-row cleanup');
        $q=$db->prepare('SELECT count(*) FROM osm_nodes WHERE job_id=?');$q->execute([$jobs[1]]);check((int)$q->fetchColumn()===1&&is_file($external),'Discard preserves another region and external source files');
        $queued=(new \SweepLine\DataQueue($db))->ensure('custom-region-queue-test','roads',['key'=>'custom-region-queue-test','bounds'=>[-81,35,-80.9,35.1]]);
        $map=$manager->map();check((bool)array_filter($map['features'],static fn($f)=>$f['properties']['key']==='custom-region-queue-test'&&$f['properties']['status']==='queued'),'Region map includes queued areas before a region row exists');
        $manager->action($user,'custom-region-queue-test','discard');$manager->runOne();
        $q=$db->prepare('SELECT count(*) FROM data_tasks WHERE task_key=?');$q->execute(['custom-region-queue-test']);check((int)$q->fetchColumn()===0,'Discard removes a queued-only area without requiring an imported region');
        $zipTask=(new \SweepLine\DataQueue($db))->ensure('us-zips','zips',[]);
        try{$manager->action($user,'us-zips','refresh');check(false,'ZIP task collision rejected');}catch(\SweepLine\HttpException $e){check($e->status===409,'Manual region refresh cannot overwrite the global ZIP task');}
        $manager->action($user,'us-zips','discard');for($step=0;$step<30;$step++)if(!$manager->runOne())break;
        $q=$db->prepare('SELECT kind FROM data_tasks WHERE id=?');$q->execute([$zipTask['id']]);
        check($q->fetchColumn()==='zips'&&is_file($shared),'Discard preserves ZIP tasks and managed sources still referenced by another region');
    }finally{
        foreach($regions as $id){$db->prepare('UPDATE regions SET active_job_id=null WHERE id=?')->execute([$id]);$db->prepare('DELETE FROM import_jobs WHERE region_id=?')->execute([$id]);$db->prepare('DELETE FROM regions WHERE id=?')->execute([$id]);}
        foreach($keys as $key)$db->prepare('DELETE FROM data_tasks WHERE task_key=?')->execute([$key]);
        unlink($external);if(is_file($shared))unlink($shared);
    }
})();
