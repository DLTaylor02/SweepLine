<?php
(function(PDO $db):void{
    $db->beginTransaction();
    try{
        $region=$db->query("INSERT INTO regions(slug,name) VALUES ('synthetic-ramp-network','Synthetic ramp network') RETURNING id")->fetchColumn();
        $q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'complete','offline ramp fixture',ST_MakeEnvelope(-85,40,-83,42,4326)) RETURNING id");$q->execute([$region]);$job=$q->fetchColumn();
        $db->prepare('UPDATE regions SET active_job_id=? WHERE id=?')->execute([$job,$region]);
        $nodes=[1=>[700000,4500100],2=>[700100,4500100],3=>[700800,4500100],4=>[700200,4500105],5=>[700300,4500140],6=>[700400,4500200],7=>[700400,4500300],8=>[700200,4500300],9=>[700600,4500300]];
        $q=$db->prepare('INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) SELECT ?,?,ST_X(g),ST_Y(g),?::jsonb FROM (SELECT ST_Transform(ST_SetSRID(ST_MakePoint(?,?),32616),4326) g) p');
        foreach($nodes as $id=>$xy)$q->execute([$job,$id,'{}',$xy[0],$xy[1]]);
        $q=$db->prepare('INSERT INTO road_segments(job_id,osm_id,highway,direction,node_ids,tags,geom) VALUES (?,?,?,?,?::jsonb,?::jsonb,ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),32616),4326)) RETURNING id');
        $connect=$db->prepare('INSERT INTO road_connectivity(job_id,node_id,segment_id,point_index) VALUES (?,?,?,?)');
        foreach([[100,'motorway',[1,2,3],1],[101,'motorway_link',[2,4,5],1],[102,'motorway_link',[5,6,7],1],[103,'secondary',[8,7,9],0]] as [$osm,$highway,$ids,$direction]){
            $coords=array_map(static fn($id)=>$nodes[$id],$ids);
            $q->execute([$job,$osm,$highway,$direction,json_encode($ids),json_encode(['highway'=>$highway,'lanes'=>$direction?'1':'2']),json_encode(['type'=>'LineString','coordinates'=>$coords])]);$segment=$q->fetchColumn();
            foreach($ids as $i=>$node)$connect->execute([$job,$node,$segment,$i]);
        }
        $plan=['epsg'=>32616,'points'=>[[700000,4500100],[700800,4500100]],'sources'=>[['osm_id'=>100]],'bounds'=>[700000,4500000,701000,4501000]];
        $result=SweepLine\BeamIntersections::fetch($db,$plan,static fn($m)=>null);
        check($result['ramp_network']['ramps']===1&&$result['ramp_network']['receiving_arms']===2,'Native indexed OSM junction query discovers a shallow ramp and follows it across way IDs');
        check(count($result['branches'])===3&&SweepLine\BeamRampNetwork::length($result['branches'][0]['points'])>400,'Native ramp fetch includes full connected ramp plus its receiving-road arms');
    }finally{$db->rollBack();}
})($db);
