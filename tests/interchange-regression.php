<?php
(function():void{
    $noop=static fn($m)=>null;
    $points=[];$raw=[];
    for($x=0;$x<=300;$x+=5){$points[]=[$x,0];$raw[]=SweepLine\BeamLanes::infer(['lanes'=>($x<100||$x>200)?'2':'3','oneway'=>'yes'],'motorway',1);}
    $taper=SweepLine\BeamRoadSurface::taper($points,$raw,[['start_m'=>100.,'end_m'=>200.]]);
    $full=true;foreach($points as $i=>$p)if($p[0]>=100&&$p[0]<=200&&$taper[$i]['width_m']<11.49999)$full=false;
    check($full&&abs($taper[0]['width_m']-8)<1e-8&&abs($taper[60]['width_m']-8)<1e-8,'A mapped three-lane bridge has full width across its entire deck while approach tapers remain bounded');
    check(abs($taper[20]['lane_paint']['f1']['u']-1/3)<1e-8&&abs($taper[40]['lane_paint']['f1']['u']-1/3)<1e-8&&$taper[20]['lane_paint']['f2']['visible']>.999,'Bridge lane dividers and pavement finish their widening on the same approach clock');

    $raster=new SweepLine\BeamRaster(256,256,str_repeat(pack('g',100.),256*256),str_repeat(chr(100),256*256*3));
    $p=SweepLine\BeamLanes::infer(['lanes'=>'1','oneway'=>'yes'],'motorway_link',1);
    $branch=['osm_id'=>100,'context_id'=>'test_edge','network'=>true,'kind'=>'ramp','start_node'=>1,'end_node'=>2,'points'=>[[50,30],[50,110],[50,200]],'limit_m'=>250.,'width_m'=>$p['width_m'],'profile'=>$p,
        'source_parts'=>[
            ['points'=>[[50,30],[50,110]],'tags'=>['lanes'=>'1','oneway'=>'yes'],'highway'=>'motorway_link','direction'=>1],
            ['points'=>[[50,110],[50,200]],'tags'=>['lanes'=>'3','oneway'=>'yes'],'highway'=>'motorway_link','direction'=>1]]];
    $main=[[0,30,100.25],[250,30,100.25]];$mp=array_fill(0,2,SweepLine\BeamLanes::infer(['lanes'=>'2','oneway'=>'yes'],'motorway',1));
    $prepared=SweepLine\BeamIntersections::prepare(['branches'=>[$branch],'graph'=>['attachments'=>[1=>[50,30]]]],$main,$mp,$raster,[0,0,256,256],0.,[],$noop);
    $road=$prepared['roads'][0];$error=max(array_map(static fn($p)=>abs($p[0]-50),$road['points']));
    check($error<1e-8&&max(array_column($road['profiles'],'width_m'))-min(array_column($road['profiles'],'width_m'))>3,'Interchange lane changes retain their mapped centerline and connector alignment instead of shifting the road sideways');

    $roads=SweepLine\BeamRoadClearance::prepare([
        ['name'=>'upper','points'=>[[10,100,20],[220,100,20]],'profiles'=>array_fill(0,2,['width_m'=>8])],
        ['name'=>'below','points'=>[[100,10,5],[100,220,5]],'profiles'=>array_fill(0,2,['width_m'=>10])]]);
    check(SweepLine\BeamRoadClearance::pierBlocked([100,100,20],0.,19.5,$roads,'upper')&&!SweepLine\BeamRoadClearance::pierBlocked([130,100,20],0.,19.5,$roads,'upper'),'Bridge supports are omitted over lower connecting pavement while clear support sites remain usable');
    check(!SweepLine\BeamRoadClearance::pierBlocked([100,100,20],11.,19.5,$roads,'upper'),'A road below the full vehicle clearance envelope does not block an unrelated elevated support');

    $roads=SweepLine\BeamRoadClearance::prepare([
        ['name'=>'main','points'=>[[10,100,100.25],[220,100,100.25]],'profiles'=>array_fill(0,2,['width_m'=>8])],
        ['name'=>'ramp','kind'=>'ramp','points'=>[[10,110,100.25],[220,110,100.25]],'profiles'=>array_fill(0,2,['width_m'=>8])]]);
    $move=SweepLine\BeamRoadClearance::relocateSign([100,110],$roads,$raster,0.);
    check($move!==null&&$move['shift_m']>0&&SweepLine\BeamRoadClearance::hit($move['point'],$roads,99.,103.,.75)===null,'A sign overlapping a ramp moves to the nearest clear roadside position beyond all pavement');
    $unchanged=SweepLine\BeamRoadClearance::relocateSign([100,100],$roads,$raster,0.);
    $outside=SweepLine\BeamRoadClearance::relocateSign([100,119],$roads,$raster,0.);
    $beyond=SweepLine\BeamRoadClearance::relocateSign([6,110],$roads,$raster,0.);
    check($beyond['point']===[6,110]&&$unchanged['point']===[100,100]&&$unchanged['shift_m']===0.&&$outside['point']===[100,119]&&$outside['shift_m']===0.,'Signs on ordinary pavement or already clear of ramps are never repositioned by the ramp-only exception');

    $sign=['id'=>'1','osm_id'=>'1','source'=>'fixture','code'=>'R2-1[45]','bearing'=>null,'point'=>[100,110]];
    $panel=$sign;$panel['id']='1_p1';$panel['panel_index']=1;
    $placed=SweepLine\BeamSigns::write(['signs'=>[$sign,$panel]],$roads,$raster,0.,'test','/levels/test/',static fn($p,$s)=>null,$noop,$roads);
    check($placed['report']['placed']===2&&$placed['report']['records'][0]['source_point']===[100,110]&&isset($placed['report']['records'][0]['placement_adjustment'])&&
        abs($placed['objects'][2]['position'][1]-$placed['objects'][0]['position'][1]-1.2)<1e-7,'Ramp-conflicting compound signs move as an assembly, preserve mapped coordinates in diagnostics and retain panel spacing');


    $objectMove=SweepLine\BeamRoadClearance::relocateObject([100,100],$roads,$raster,0.,3.);
    check($objectMove!==null&&$objectMove['shift_m']>0&&SweepLine\BeamRoadClearance::hit($objectMove['point'],$roads,99.,103.,3.)===null,'Roadside object footprints move clear of ordinary roads as well as ramps');
    $feature=static fn($id,$kind,$p)=>['id'=>$id,'kind'=>$kind,'points'=>$p,'tags'=>[],'timestamp'=>null];
    $context=SweepLine\BeamRoadside::write(['features'=>[
        $feature('pole','power_pole',[[100,110]]),
        $feature('fence','fence',[[100,105],[100,115]])
    ]],[$roads[0]],$raster,0.,'clearance','/levels/test/',static fn($p,$s)=>null,$noop,$roads);
    check(isset($context['report']['placement_adjustments']['pole'])&&($context['report']['placed_features']['power_pole']??0)===1,'Roadside point placement accounts for ramps absent from the nearest-road association list');
    check(($context['report']['skipped']['linear_segment_pavement_or_corridor_overlap']??0)>0,'Fence segments crossing another exported road are omitted explicitly');
})();
