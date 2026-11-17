<?php
(function():void{
    $objects=[['class'=>'SpawnSphere','name'=>'spawns_start','__parent'=>'MissionGroup','position'=>[1.,2.,3.]]];
    for($i=0;$i<=SweepLine\BeamScene::BATCH_SIZE**2;$i++)$objects[]=['class'=>'BeamNGWaypoint','name'=>'node_'.$i,'__parent'=>'MissionGroup','position'=>[$i,2,3],'scale'=>[1,1,1]];
    $seen=[];$groups=[];$parents=[];$max=0;$rootSpawn=false;
    $report=SweepLine\BeamScene::write($objects,str_repeat('a',32),static function($path,$text)use(&$seen,&$groups,&$parents,&$max,&$rootSpawn):void{
        $lines=explode("\n",trim($text));$max=max($max,count($lines));
        foreach($lines as $line){
            $o=json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if($o['class']==='SimGroup'){$groups[$o['name']]=dirname($path);continue;}
            $parents[$o['__parent']]=true;
            if($o['class']==='SpawnSphere'){$rootSpawn=$o['__parent']==='MissionGroup'&&$o['position']===[1,2,3];continue;}
            $i=(int)substr($o['name'],5);
            if(isset($seen[$i])||$o['position']!==[$i,2,3]||$o['scale']!==[1,1,1])throw new RuntimeException('Scene batching changed or duplicated waypoint geometry');
            $seen[$i]=true;
        }
    });
    check(count($seen)===count($objects)-1&&$rootSpawn,'Large hierarchical scene retains every waypoint position/radius and keeps spawn lookups at the root');
    check($max<=SweepLine\BeamScene::BATCH_SIZE&&$report['maximum_objects_per_file']===$max&&$report['groups']>SweepLine\BeamScene::BATCH_SIZE,'Both object batches and multi-level group indexes remain bounded beyond 65,536 objects');
    check(array_diff_key($parents,$groups)===[],'Every batched object has a serialized native parent group');
    $files=[];$small=[['class'=>'BeamNGWaypoint','name'=>'single','position'=>[1,2,3],'__parent'=>'MissionGroup']];
    SweepLine\BeamScene::write($small,str_repeat('a',32),static function($p,$v)use(&$files){$files[$p]=$v;});
    check(count($files)===2&&json_decode(trim($files['main/MissionGroup/items.level.json']),true)===$small[0],'Small scenes retain their existing flat native layout');
})();
