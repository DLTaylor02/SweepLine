<?php
(function():void{
    $make=static function($id,$points,$nodes,$highway='motorway_link',$at=0){
        $tags=['highway'=>$highway,'lanes'=>$highway==='motorway_link'?'1':'2'];
        $profile=SweepLine\BeamLanes::infer($tags,$highway,0);
        return ['osm_id'=>$id,'highway'=>$highway,'direction'=>0,'tags'=>$tags,'points'=>$points,'node_ids'=>$nodes,'point_index'=>$at,'profile'=>$profile,'width_m'=>$profile['width_m']];
    };
    $seed=$make(10,[[100,300],[200,420]],[1,2]);$seed['full_points']=$seed['points'];$seed['arm_nodes']=[1,2];
    $left=$make(11,[[200,420],[250,470]],[2,3]);
    $right=$make(12,[[200,420],[250,390]],[2,4]);
    $leftEnd=$make(13,[[250,470],[300,430]],[3,5]);
    $rightEnd=$make(14,[[250,390],[300,430]],[4,5]);
    $street=$make(20,[[250,520],[300,430],[500,430]],[6,5,7],'secondary',1);
    $lookup=static fn($n)=>match($n){2=>[$left,$right],3=>[$leftEnd],4=>[$rightEnd],5=>[$street],default=>[]};
    $graph=SweepLine\BeamRampNetwork::walk([$seed],$lookup,static fn($m)=>null);
    check($graph['ramp_network']['ramps']===3&&$graph['ramp_network']['receiving_arms']===2,'A fork includes both branches through their common receiving-road junction: '.json_encode($graph['ramp_network']));
    $seed2=$seed;$seed2['osm_id']=30;$seed2['points']=[[600,260],[600,120]];$seed2['full_points']=$seed2['points'];$seed2['arm_nodes']=[100,101];
    $street2=$make(31,[[400,120],[600,120],[800,120]],[102,101,103],'secondary',1);
    $both=SweepLine\BeamRampNetwork::walk([$seed,$seed2],static fn($n)=>$n===101?[$street2]:$lookup($n),static fn($m)=>null);
    check($both['ramp_network']['requested_ramps']===2&&$both['ramp_network']['receiving_arms']===4,'Both highway attachments receive their own ramp and receiving-road coverage');
    $many=[];for($i=0;$i<12;$i++){
        $r=$make(100+$i,[[100+$i*30,0],[100+$i*30,1000]],[1000+$i*2,1001+$i*2]);$r['full_points']=$r['points'];$r['arm_nodes']=$r['node_ids'];$many[]=$r;
    }
    $all=SweepLine\BeamRampNetwork::walk($many,static fn()=>[],static fn($m)=>null);
    check($all['ramp_network']['ramps']===12&&abs($all['ramp_network']['total_ramp_m']-12000)<.001,'More than eight kilometres of independent ramps are preserved without starving later attachments');
    $dup=SweepLine\BeamRampNetwork::walk([$seed,$seed],$lookup,static fn($m)=>null);
    check(count($dup['branches'])===count($graph['branches']),'Duplicate highway attachments do not duplicate shared physical edges');

    $interior=$make(40,[[0,0],[100,0],[200,0]],[200,201,202]);$interior['full_points']=$interior['points'];$interior['arm_nodes']=$interior['node_ids'];
    $fork=$make(41,[[100,0],[100,100]],[201,203]);
    $inside=SweepLine\BeamRampNetwork::walk([$interior],static fn()=>[],static fn($m)=>null,[],3218.688,static fn($nodes,$own)=>$own===40?['201'=>[$fork]]:[]);
    check($inside['ramp_network']['ramps']===3,'A junction inside an OSM way is split and traversed instead of being missed');
    $loopSeed=$make(50,[[0,0],[100,0]],[300,301]);$loopSeed['full_points']=$loopSeed['points'];$loopSeed['arm_nodes']=$loopSeed['node_ids'];
    $loopA=$make(51,[[100,0],[100,100]],[301,302]);$loopB=$make(52,[[100,100],[0,0]],[302,300]);
    $loop=SweepLine\BeamRampNetwork::walk([$loopSeed],static fn($n)=>match($n){301=>[$loopA],302=>[$loopB],default=>[]},static fn($m)=>null);
    check(count($loop['branches'])===1&&$loop['branches'][0]['start_node']===$loop['branches'][0]['end_node'],'Mapped ramp loops close once without repeated traversal');

    $raster=new SweepLine\BeamRaster(64,1000,str_repeat(pack('g',100),4096),str_repeat(chr(100),12288));
    $main=[[0,300,50.25],[800,300,50.25]];$profiles=array_fill(0,2,SweepLine\BeamLanes::infer(['lanes'=>'2','width'=>'8'],'motorway',1));
    $companion=[[800,260,50.25],[0,260,50.25]];
    $both['main_surfaces']=[['name'=>'primary','points'=>$main,'profiles'=>$profiles,'spans'=>[]],['name'=>'carriageway_0','points'=>$companion,'profiles'=>$profiles,'spans'=>[]]];
    $prepared=SweepLine\BeamIntersections::prepare($both,$main,$profiles,$raster,[0,0,1000,1000],50,[],static fn($m)=>null);
    check(count($prepared['roads'])===8&&count($prepared['external_junctions']['carriageway_0']??[])===1,'A shared network prepares both carriageway attachments and all fork/receiving edges: '.count($prepared['roads']));
    $before=serialize(array_column($prepared['roads'],'points'));$mainBefore=serialize($main);
    for($y=0;$y<64;$y++)for($x=0;$x<64;$x++)$raster->put($x,$y,104);
    $prepared['structure_mask']=str_repeat("\0",4096);
    SweepLine\BeamIntersections::shape($prepared,$main,$profiles,$raster,50,str_repeat("\0",4096),static fn($m)=>null);
    $conformed=SweepLine\BeamIntersections::conform($prepared,$raster,50,static fn($m)=>null,$main,$profiles);
    check(count($conformed['roads'])===8&&serialize(array_column($conformed['roads'],'points'))===$before,'Terrain more than half a metre above the network is corrected without shortening roads');
    check(serialize($main)===$mainBefore&&$conformed['final_clearance']['limited_branches']===0,'Terrain correction preserves the selected highway geometry');
    $nav=SweepLine\BeamTraffic::graph($main,$profiles,$conformed);
    $junctions=array_filter($nav['objects'],static fn($n)=>hypot($n['position'][0]-300,$n['position'][1]-430)<.01);
    check(count($junctions)===1,'Fork branches and receiving roads share one navigation node at their mapped merge');
    $keys=[];foreach($nav['segments'] as $segment){$pair=$segment['nodes'];sort($pair);$keys[implode(':',$pair)]=true;}
    check(count($keys)===count($nav['segments']),'Shared graph navigation does not create duplicate physical links');

    $oversized=['bounds'=>[0,0,100,100],'intersection_data'=>['branches'=>[['points'=>[[0,0],[SweepLine\BeamPlan::MAX_SIDE+1000,0]]]]]];
    $limited=false;try{SweepLine\BeamRampNetwork::extent($oversized);}catch(SweepLine\BeamInterchangeLimit $e){$limited=true;}
    check($limited,'Oversized connected networks fail explicitly rather than keeping an undersized terrain footprint');
})();
