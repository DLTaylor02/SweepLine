<?php
// Disposable PostGIS: verify projection, nearby-source lookup and adjusted endpoint metadata.
(function()use($db,$api,$mainId):void{
    $road=$api->exportRoad((int)$mainId,'open');
    $a=[-119.8,39.52];$b=[-119.799,39.52];$c=[-119.795,39.52];$outside=[-119.801,39.52];
    $road['name']='Synthetic Approach Road';$road['ref']=null;$road['alternate_names']=[];
    $road['geometry']=['type'=>'LineString','coordinates'=>[$a,$b,$c]];
    $part=static fn($line,$tags)=>['name'=>'Synthetic Approach Road','direction'=>0,'reversed'=>false,'highway'=>'secondary','geometry'=>['type'=>'LineString','coordinates'=>$line],'tags'=>$tags];
    $road['parts']=[$part([$a,$b],['bridge'=>'yes']),$part([$b,$c],[])];
    $road['source_parts']=$road['parts'];
    $db->beginTransaction();
    try{
    $insert=$db->prepare("INSERT INTO road_segments(job_id,osm_id,name,highway,direction,node_ids,tags,geom) SELECT job_id,-900000001,'Renamed Connector','secondary',0,'[]'::jsonb,'{}'::jsonb,ST_SetSRID(ST_GeomFromGeoJSON(?),4326) FROM road_candidates WHERE id=?");
    $insert->execute([json_encode(['type'=>'LineString','coordinates'=>[$outside,$a]],JSON_THROW_ON_ERROR),$mainId]);
    foreach(['forward','reverse'] as $orientation){
        $plan=\SweepLine\BeamPlan::project($db,$road,\SweepLine\BeamPlan::options(['orientation'=>$orientation]));
        $extension=$plan['selection']['bridge_approach_extension'];
        check($extension[$orientation==='forward'?'start_m':'finish_m']>=30,'Projected BeamNG plan includes bridge approaches in the requested orientation');
        check(count($plan['bridges'])===1&&$plan['bridges'][0]['start_m']>=25,'Extended plan still passes actual bridge approach validation');
        $requested=$plan['selection']['requested_geometry']['coordinates'];$actual=$plan['selection']['exported_geometry']['coordinates'];
        check($requested!==$actual&&$plan['selection']['exported_start']===$actual[0]&&$plan['selection']['exported_finish']===$actual[count($actual)-1],'Exported endpoint metadata reflects the extended geometry while preserving requested geometry');
    }
    }finally{$db->rollBack();}
})();
