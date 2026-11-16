<?php
function slNetworkStructuresFixture(): array
{
    $make=static function($id,$a,$b,$tags=[]){
        $tags+=['highway'=>'motorway_link','lanes'=>'1','oneway'=>'yes'];$p=SweepLine\BeamLanes::infer($tags,'motorway_link',1);
        return ['osm_id'=>$id,'highway'=>'motorway_link','direction'=>1,'tags'=>$tags,'points'=>[[400,$a],[400,$b]],'node_ids'=>[$a,$b],'point_index'=>0,'profile'=>$p,'width_m'=>$p['width_m']];
    };
    $seed=$make(1,200,400);$seed['full_points']=$seed['points'];$seed['arm_nodes']=$seed['node_ids'];
    $lookup=static fn($n)=>match($n){400=>[$make(2,400,500,['bridge'=>'yes'])],500=>[$make(3,500,800)],800=>[$make(4,800,900,['tunnel'=>'yes'])],900=>[$make(5,900,1200)],default=>[]};
    $network=SweepLine\BeamRampNetwork::walk([$seed],$lookup,static fn($m)=>null);
    $points=[[100,200],[1300,200]];
    $plan=['terrain_style'=>'aerial','name'=>'Synthetic ramp structures','alternate_names'=>[],'resolution'=>128,'padding_miles'=>.5,'epsg'=>32616,'bounds'=>[0,0,1400,1400],'points'=>$points,'sources'=>[['points'=>$points,'tags'=>['lanes'=>'2'],'highway'=>'primary','direction'=>0]],'center'=>[-84,41],'oneway'=>false,'intersection_data'=>$network];
    $heights='';for($y=0;$y<128;$y++)for($x=0;$x<128;$x++){
        $wy=(127-$y)*1400/128;$wx=$x*1400/128;
        $h=100.;if(abs($wx-400)<80&&$wy>420&&$wy<480)$h=80.;
        if(abs($wx-400)<80&&$wy>820&&$wy<880)$h=125.;
        $heights.=pack('g',$h);
    }
    return [$plan,new SweepLine\BeamRaster(128,1400,$heights,str_repeat(chr(100),128*128*3))];
}
(function():void{
    [$plan,$raster]=slNetworkStructuresFixture();$branch=$plan['intersection_data']['branches'][0];
    check(count($branch['source_parts'])===5,'Ramp graph retains mapped bridge and tunnel ways in its connected path');
    $main=[[100,200,1.25],[1300,200,1.25]];$p=SweepLine\BeamRoadSurface::shoulders([SweepLine\BeamLanes::infer(['lanes'=>'2'],'primary',0)])[0];$profiles=[$p,$p];
    $prepared=SweepLine\BeamIntersections::prepare($plan['intersection_data'],$main,$profiles,$raster,$plan['bounds'],99.,[],static fn($m)=>null);
    $edge=$prepared['roads'][0];
    check(count($edge['bridges'])===1&&count($edge['tunnels'])===1,'Ramp preparation detects both structure spans rather than draping the road over them');
    $near=static function($y)use($edge){$best=null;foreach($edge['points'] as $p)if($best===null||abs($p[1]-$y)<abs($best[1]-$y))$best=$p;return $best;};
    check(abs($near(450)[2]-1.25)<.1&&abs($near(850)[2]-1.25)<.1,'Bridge deck spans the valley and tunnel floor passes below the hill');
    check($prepared['network_manifest']['discovered']===$prepared['network_manifest']['prepared'],'Structured ramp remains in network completeness accounting');
    $tracks=SweepLine\BeamNetworkStructures::tracks($prepared);
    $mask=SweepLine\BeamTrack::mask($tracks[0],$raster,str_repeat("\0",128*128),static fn($m)=>null);
    $prepared['structure_mask']=$mask;
    SweepLine\BeamIntersections::shape($prepared,$main,$profiles,$raster,99.,$mask,static fn($m)=>null);
    $final=SweepLine\BeamIntersections::conform($prepared,$raster,99.,static fn($m)=>null,$main,$profiles,$mask);
    check($raster->height(400,850)>115&&$raster->height(400,450)<90,'Terrain shaping preserves tunnel overburden and ground below the bridge');
    check(count($final['roads'])===1&&$final['network_verification']['terrain_removed_edges']===0,'Structure clearance audit preserves the complete ramp edge');

    $flat=new SweepLine\BeamRaster(64,600,str_repeat(pack('g',100),4096),str_repeat(chr(100),64*64*3));
    $edge=['context_id'=>'connected-approach','osm_id'=>70,'source_parts'=>[
        ['points'=>[[200,200],[200,300]],'tags'=>['bridge'=>'yes']],
        ['points'=>[[200,300],[200,400]],'tags'=>[]]]];
    $heights=SweepLine\BeamNetworkStructures::sample([[200,200],[200,300],[200,400]],$edge,[],[[200,0,0],[200,200,0]],[0,0,600,600],$flat);
    check($heights['approach_before_m']>=50&&count($heights['bridges'])===1&&$heights['bridges'][0]['start_m']===0.,'Bridge at a graph boundary uses genuine connected parent approach geometry');
    $noApproach=false;try{SweepLine\BeamNetworkStructures::sample([[200,200],[200,300],[200,400]],$edge,[],[[0,0,0],[100,0,0]],[0,0,600,600],$flat);}catch(RuntimeException $e){$noApproach=str_contains($e->getMessage(),'approach');}
    check($noApproach,'Missing connected approaches fail explicitly instead of inventing approach geometry');
    $complex=$edge;$complex['source_parts'][0]['tags']['bridge:structure']='arch';
    $rejected=false;try{SweepLine\BeamNetworkStructures::sample([[200,200],[200,300],[200,400]],$complex,[],[[200,0,0],[200,200,0]],[0,0,600,600],$flat);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'complex bridge');}
    check($rejected,'Unsupported complex ramp bridges retain the existing explicit structure safeguards');
    $profile=SweepLine\BeamLanes::infer(['lanes'=>'1'],'motorway_link',0);$profiles=[$profile,$profile];
    $high=['points'=>[[100,300,10],[500,300,10]],'profiles'=>$profiles,'network'=>true,'connector'=>[],'join'=>['x'=>300,'y'=>300]];
    $low=[[300,100,0],[300,500,0]];
    $plain=$high;unset($plain['join']);
    $uncut=SweepLine\BeamPavement::build($plain,$low,$profiles,'test');
    $crossing=SweepLine\BeamPavement::build($high,$low,$profiles,'test');
    check($uncut['triangles']===$crossing['triangles'],'Grade-separated ramp crossing keeps its pavement instead of being clipped to the road below');

    $bridgePoints=[[0,0,5],[100,0,5]];$cross=SweepLine\BeamJunctionBoundary::sections($bridgePoints,$profiles);
    $rails=SweepLine\BeamBridgeDeck::barriers($bridgePoints,$cross,['start_m'=>0.,'end_m'=>100.],'test',[['along'=>50.,'radius'=>5.,'side'=>'right']]);
    check(count(array_filter(array_keys($rails),static fn($k)=>str_starts_with($k,'right_')))===2&&isset($rails['left_0']),'Bridge barrier has an opening on the connected ramp side while the opposite barrier remains intact');
})();
