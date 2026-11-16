<?php
(function():void{
    $p=SweepLine\BeamLanes::infer(['lanes'=>'1','oneway'=>'yes'],'motorway_link',1);
    $seed=['osm_id'=>10,'highway'=>'motorway_link','tags'=>['oneway'=>'yes'],'profile'=>$p,'width_m'=>$p['width_m'],'points'=>[[100,100],[200,105],[300,140]],'full_points'=>[[100,100],[200,105],[300,140]],'arm_nodes'=>[1,2,3]];
    $next=['osm_id'=>11,'highway'=>'motorway_link','direction'=>1,'tags'=>['oneway'=>'yes'],'points'=>[[300,140],[400,200],[400,300]],'node_ids'=>[3,4,5],'point_index'=>0];
    $street=['osm_id'=>12,'highway'=>'secondary','direction'=>0,'tags'=>['lanes'=>'2'],'points'=>[[200,300],[400,300],[600,300]],'node_ids'=>[6,5,7],'point_index'=>1];
    $network=SweepLine\BeamRampNetwork::walk([$seed],static fn($node)=>$node===3?[$next]:[$street],static fn($m)=>null);
    $raster=new SweepLine\BeamRaster(64,1000,str_repeat(pack('g',100),4096),str_repeat(chr(100),12288));
    $plan=['terrain_style'=>'aerial','name'=>'Synthetic highway ramp','alternate_names'=>[],'resolution'=>64,'padding_miles'=>.5,'epsg'=>32616,'bounds'=>[0,0,1000,1000],
        'points'=>[[0,100],[800,100]],'sources'=>[['points'=>[[0,100],[800,100]],'tags'=>['lanes'=>'2','oneway'=>'yes'],'highway'=>'motorway','direction'=>1]],
        'center'=>[-84,41],'oneway'=>true,'intersection_data'=>$network];
    $file=tempnam(sys_get_temp_dir(),'sl-ramp-zip-');
    try{
        $report=SweepLine\BeamMap::build($plan,$raster,str_repeat('7',32),$file,static fn($m)=>null);
        check($report['intersection_context']['rendered_branches']===3,'Finished ZIP retains the ramp and both receiving-road arms after final terrain clearance');
        $z=new ZipArchive();$z->open($file);$base='levels/sweepline_7777777777777777/';
        for($i=0;$i<3;$i++)check($z->getFromName($base.'art/roads/side-'.$i.'.dae')!==false,'Finished ZIP contains the ramp/receiving-road collision mesh');
        $nav=json_decode($z->getFromName($base.'map.json'),true,512,JSON_THROW_ON_ERROR);
        check(count($nav['segments'])>10,'Finished minimap includes ramp/receiving-road graph edges');
        check(count(array_filter($report['intersection_context']['pavement_meshes'],static fn($p)=>$p['connector_expected']&&!$p['connector']))===0,'No ramp-network connector is missing from the final ZIP');

    }finally{if(isset($z))$z->close();if(is_file($file))unlink($file);}
})();
