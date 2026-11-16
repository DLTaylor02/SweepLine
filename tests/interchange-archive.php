<?php
// End-to-end synthetic fork/rejoin interchange on two carriageways.
(function():void{
    $make=static function($id,$points,$ids,$class='motorway_link',$at=0){
        $tags=['highway'=>$class,'lanes'=>$class==='motorway_link'?'1':'2'];
        $p=SweepLine\BeamLanes::infer($tags,$class,0);
        return ['osm_id'=>$id,'highway'=>$class,'direction'=>0,'tags'=>$tags,'points'=>$points,'node_ids'=>$ids,'point_index'=>$at,'profile'=>$p,'width_m'=>$p['width_m']];
    };
    $a=$make(10,[[100,300],[200,420]],[1,2]);$a['full_points']=$a['points'];$a['arm_nodes']=[1,2];
    $b=$make(30,[[600,260],[600,120]],[100,101]);$b['full_points']=$b['points'];$b['arm_nodes']=[100,101];
    $lookup=static fn($n)=>match($n){
        2=>[$make(11,[[200,420],[250,470]],[2,3]),$make(12,[[200,420],[250,390]],[2,4])],
        3=>[$make(13,[[250,470],[300,430]],[3,5])],
        4=>[$make(14,[[250,390],[300,430]],[4,5])],
        5=>[$make(20,[[250,520],[300,430],[500,430]],[6,5,7],'secondary',1)],
        101=>[$make(31,[[400,120],[600,120],[800,120]],[102,101,103],'secondary',1)],default=>[]};
    $network=SweepLine\BeamRampNetwork::walk([$a,$b],$lookup,static fn($m)=>null);
    $source=static fn($points)=>['points'=>$points,'tags'=>['lanes'=>'2','oneway'=>'yes','width'=>'8'],'highway'=>'motorway','direction'=>1];
    $main=[[0,300],[800,300]];$opposite=[[800,260],[0,260]];
    $plan=['terrain_style'=>'aerial','name'=>'Synthetic complete interchange','alternate_names'=>[],'resolution'=>64,'padding_miles'=>.5,'epsg'=>32616,'bounds'=>[0,0,1000,1000],
        'points'=>$main,'sources'=>[$source($main)],'center'=>[-84,41],'oneway'=>true,'intersection_data'=>$network,
        'carriageways'=>[['name'=>'carriageway_0','road_name'=>'Parallel synthetic highway','points'=>$opposite,'sources'=>[$source($opposite)],'intersection_data'=>['branches'=>[]]]]];
    $raster=new SweepLine\BeamRaster(64,1000,str_repeat(pack('g',100),4096),str_repeat(chr(100),12288));
    $file=tempnam(sys_get_temp_dir(),'sl-interchange-');
    try{
        $report=SweepLine\BeamMap::build($plan,$raster,str_repeat('6',32),$file,static fn($m)=>null);
        $manifest=$report['intersection_context']['network_manifest'];$verified=$report['intersection_context']['network_verification'];
        check($manifest['discovered']===8&&$manifest['prepared']===8&&$manifest['missing_context_ids']===[],'Whole ZIP preserves every discovered fork, ramp and receiving-road edge');
        check($verified['prepared_edges']===8&&$verified['terrain_removed_edges']===0,'No connected interchange edge is removed by final terrain processing');
        check($report['carriageways']['mapped_junction_links']>=1,'Shared interchange navigation joins the opposite carriageway');
        $zip=new ZipArchive();$zip->open($file);$base='levels/sweepline_6666666666666666/';
        check($zip->getFromName($base.'map.json')!==false,'Whole interchange publishes its connected minimap graph');
        $log=$zip->getFromName('build.log');$document=json_decode(substr($log,strpos($log,'{')),true,512,JSON_THROW_ON_ERROR);
        $roads=array_filter($document['generated_geometry']['intersections']['roads'],static fn($r)=>!empty($r['network']));
        check(count(array_filter($roads,static fn($r)=>$r['kind']==='receiving_road'))===4,'All receiving-road arms remain in final exported geometry');
        check(count(array_filter($report['intersection_context']['pavement_meshes'],static fn($p)=>$p['connector_expected']&&!$p['connector']))===0,'Fork and receiving-road connectors are present in final pavement');
    }finally{if(isset($zip))$zip->close();if(is_file($file))unlink($file);}
})();
