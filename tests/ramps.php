<?php
(function():void{
    check(SweepLine\BeamRampNetwork::share(8000,2)===4000.0&&SweepLine\BeamRampNetwork::share(7000,1)===7000.0,'Ramp budgets reserve a fair share and carry unused capacity to remaining carriageways');
    $profile=SweepLine\BeamLanes::infer(['lanes'=>'1','oneway'=>'yes'],'motorway_link',1);$profile['legal_forward']=true;
    $seed=['osm_id'=>10,'highway'=>'motorway_link','tags'=>['oneway'=>'yes'],'profile'=>$profile,'width_m'=>$profile['width_m'],
        'points'=>[[100,100],[200,105],[300,140]],'full_points'=>[[100,100],[200,105],[300,140]],'arm_nodes'=>[1,2,3]];
    $next=['osm_id'=>11,'highway'=>'motorway_link','direction'=>1,'tags'=>['oneway'=>'yes'],'points'=>[[300,140],[400,200],[400,300]],'node_ids'=>[3,4,5],'point_index'=>0];
    $street=['osm_id'=>12,'highway'=>'secondary','direction'=>0,'tags'=>['name'=>'Synthetic receiving road','lanes'=>'2'],'points'=>[[200,300],[400,300],[600,300]],'node_ids'=>[6,5,7],'point_index'=>1];
    $lookup=static fn($node)=>match($node){3=>[$next],5=>[$street],default=>[]};
    $network=SweepLine\BeamRampNetwork::walk([$seed],$lookup,static fn($m)=>null);
    check(count($network['branches'])===3&&$network['ramp_network']['receiving_arms']===2,'Ramp chain reaches its receiving road and includes both 600-foot arms');
    check(SweepLine\BeamRampNetwork::length($network['branches'][0]['points'])>400&&hypot(end($network['branches'][0]['points'])[0]-400,end($network['branches'][0]['points'])[1]-300)<1e-6,'Ramp geometry continues across OSM way IDs beyond the old 600-foot cutoff');
    check($network['branches'][1]['parent_context_id']===$network['branches'][0]['context_id'],'Receiving roads retain their ramp parent identity');
    $private=$street;$private['tags']['access']='private';
    $blocked=SweepLine\BeamRampNetwork::walk([$seed],static fn($node)=>$node===3?[$next]:[$private],static fn($m)=>null);
    check($blocked['ramp_network']['receiving_arms']===0&&isset($blocked['ramp_network']['reasons']['mapped_structure_or_restriction']),'Private receiving roads are excluded with a reason');
    $bridge=$seed;$bridge['tags']['bridge']='yes';
    check(SweepLine\BeamRampNetwork::walk([$bridge],$lookup,static fn($m)=>null)['branches']===[],'Unsupported ramp bridge is reported instead of draped onto terrain');
    $fork=$next;$fork['osm_id']=20;$fork['points']=[[300,140],[250,200]];$fork['node_ids']=[3,22];
    $ambiguous=SweepLine\BeamRampNetwork::walk([$seed],static fn()=>[$next,$fork],static fn($m)=>null);
    check(isset($ambiguous['ramp_network']['reasons']['ambiguous_ramp_fork']),'Ambiguous ramp forks stop without selecting an invented continuation');
    $limited=SweepLine\BeamRampNetwork::walk([$seed],$lookup,static fn($m)=>null,[],200);
    check(count($limited['branches'])===1&&SweepLine\BeamRampNetwork::length($limited['branches'][0]['points'])<=200.001,'Shared ramp geometry budget clips context and omits disconnected receiving arms');
    $raster=new SweepLine\BeamRaster(128,1000,str_repeat(pack('g',100),16384),str_repeat(chr(100),49152));
    $main=[[0,100,50.25],[800,100,50.25]];$profiles=array_fill(0,2,SweepLine\BeamLanes::infer(['lanes'=>'2','width'=>'8'],'motorway',1));
    $prepared=SweepLine\BeamIntersections::prepare($network,$main,$profiles,$raster,[0,0,1000,1000],50,[],static fn($m)=>null);
    check(count($prepared['roads'])===3,'Shallow ramp and both receiving arms survive geometry preparation');
    if(count($prepared['roads'])===3){
        check(end($prepared['roads'][0]['points'])[1]===300.0&&$prepared['roads'][1]['parent_name']===$prepared['roads'][0]['name'],'Ramp reaches the actual receiving junction and child surfaces use its elevation');
        foreach($prepared['roads'] as $road){
            $mesh=SweepLine\BeamPavement::build($road,$main,$profiles,'ramp_fixture');
            check($mesh['triangles']>0&&$mesh['connector'],'Ramp and receiving-road collision meshes contain their welded connectors');
        }
        $nav=SweepLine\BeamTraffic::graph($main,$profiles,$prepared);
        check(count(array_filter($nav['segments'],static fn($s)=>str_starts_with($s['nodes'][0],'sl_side_')))>0,'Minimap graph includes connected ramp and receiving-road paths');
    }
    $plan=['bounds'=>[0,0,200,200],'intersection_data'=>$network];
    $extent=SweepLine\BeamRampNetwork::extent($plan);
    check($extent[2]>600&&abs(($extent[2]-$extent[0])-($extent[3]-$extent[1]))<1e-7,'Terrain footprint expands to cover ramp context while remaining square');
})();
