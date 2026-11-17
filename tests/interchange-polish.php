<?php
(function():void{
    $noop=static fn($m)=>null;
    $road=[[0,0,10],[20,0,11],[30,10,12],[50,10,13]];
    $p=array_fill(0,4,['width_m'=>8.,'lanes'=>2,'forward'=>1,'backward'=>1,'oneway'=>false]);
    $p[1]['section_override']=[[19,5,11],[21,-5,11]];
    $marked=SweepLine\BeamIntersections::markingSections($road,$p,[['along'=>18.,'radius'=>5.,'side'=>'left']]);
    $expected=SweepLine\BeamJunctionBoundary::renderSections($road,$p,$marked['points']);
    $actual=SweepLine\BeamJunctionBoundary::sections($marked['points'],$marked['profiles']);$error=0.;
    foreach($actual as $i=>$ring)foreach([0,1] as $side)foreach([0,1,2] as $axis)$error=max($error,abs($ring[$side][$axis]-$expected[$i][$side][$axis]));
    check(count($marked['points'])>count($road)&&$error<1e-8,'Junction paint cuts preserve the exact original curved/overridden pavement cross-sections');

    $wide=['points'=>[[20,10,100],[20,50,102]],'profiles'=>array_fill(0,2,['width_m'=>20.])];
    $narrow=['points'=>[[36,10,99],[36,50,101]],'profiles'=>array_fill(0,2,['width_m'=>4.])];
    $r=new SweepLine\BeamRaster(64,64,str_repeat(pack('g',90),4096),str_repeat(chr(100),12288));$other=clone $r;
    $mask=str_repeat("\0",4096);$mask[31*64+31]="\1";
    SweepLine\BeamRoadSurface::blendTerrainSet([$wide,$narrow],$r,0.,$mask,$noop);
    SweepLine\BeamRoadSurface::blendTerrainSet([$narrow,$wide],$other,0.,$mask,$noop);
    check(abs($r->cell(20,30)-100.95)<.0001&&abs($r->cell(36,30)-99.95)<.0001&&$r->cell(20,30)===$other->cell(20,30)&&$r->cell(31,31)===90.,'Restored centerline-based terrain support stays deterministic and preserves protected vertices');

    $main=[[0,0,10],[100,0,10]];$profiles=array_fill(0,2,['width_m'=>8.]);
    $sections=SweepLine\BeamJunctionBoundary::sections($main,$profiles);
    $branch=[[50,0,10],[50,20,10]];$bp=array_fill(0,2,['width_m'=>4.]);
    $cuts=SweepLine\BeamBridgeOpenings::intervals($main,$sections,$branch,$bp,0,[0.,100.]);
    $raised=array_map(static fn($v)=>[$v[0],$v[1],$v[2]+10],$branch);
    check(count($cuts)===1&&$cuts[0][0]>47&&$cuts[0][1]<53&&SweepLine\BeamBridgeOpenings::intervals($main,$sections,$raised,$bp,0,[0.,100.])===[],'Bridge openings use actual narrow pavement contact and reject vertically separated crossings');
    $walls=SweepLine\BeamBridgeDeck::barriers($main,$sections,['start_m'=>0.,'end_m'=>100.],'test',[
        ['side'=>'left','along'=>50.,'radius'=>200.,'barrier_openings'=>['left'=>$cuts]]]);
    check(count($walls)===3&&isset($walls['right_0'],$walls['left_0'],$walls['left_1']),'An exaggerated shallow-angle paint radius cannot remove an entire bridge guard wall');
    $inside=[[0,2,10],[100,2,10]];
    $overlap=SweepLine\BeamBridgeDeck::barriers($inside,SweepLine\BeamJunctionBoundary::sections($inside,$bp),['start_m'=>0.,'end_m'=>100.],'test',[],[['points'=>$main,'profiles'=>array_fill(0,2,['width_m'=>16.])]]);
    check($overlap===[],'Ramp bridge walls overlapping a same-level highway are omitted instead of crossing through driving pavement');

    $line=[[0,0,10],[50,0,10],[100,0,10]];$lp=[['width_m'=>8.,'oneway'=>true],['width_m'=>16.,'oneway'=>true],['width_m'=>16.,'oneway'=>true]];
    $anchored=SweepLine\BeamMedian::anchor($line,$lp);$rings=SweepLine\BeamJunctionBoundary::sections($anchored,$lp);
    check(abs($rings[1][0][1]-4)<.01,'Existing main-carriageway lane anchoring remains unchanged');

    $paint=imagecreatefromstring(SweepLine\BeamPng::paint(['lanes'=>2,'backward'=>1,'oneway'=>false]));
    $center=imagecolorat($paint,128,128);$background=imagecolorat($paint,64,128);
    check((($center>>24)&127)===0&&(($center>>16)&255)>(($center>>8)&255)&&(($background>>24)&127)===127,'Paint RGBA retains the continuous yellow center and fully transparent non-paint pixels');
    imagedestroy($paint);
    $dash=imagecreatefromstring(SweepLine\BeamPng::paint(['lanes'=>2,'backward'=>0,'oneway'=>true]));
    check(((imagecolorat($dash,128,10)>>24)&127)===0&&((imagecolorat($dash,128,180)>>24)&127)===127,'Dashed paint uses one shared color/alpha sample with transparent gaps');
    imagedestroy($dash);
    $written=[];$o=SweepLine\BeamRoadMaterials::writePaint(['xml'=>'<material name="test_road_2"/>','position'=>[1,2,3]],'test','art/paint.dae','/levels/test/',static function($p,$s)use(&$written){$written[$p]=$s;},'paint');
    check($written['art/paint.dae']==='<material name="test_road_paint_2"/>'&&abs($o['position'][2]-3.004)<1e-9&&$o['collisionType']==='None'&&$o['decalType']==='None','Paint overlay reuses clipped pavement, has a small render offset, and adds no collision/decal target');

    $rampProfiles=array_fill(0,2,['width_m'=>8.,'lanes'=>2,'forward'=>2,'backward'=>0,'oneway'=>true]);
    $rampMarked=SweepLine\BeamIntersections::markingSections([[0,0,10],[100,0,10]],$rampProfiles,[
        ['along'=>20.,'radius'=>200.,'side'=>'left','preserve_through_lanes'=>true,'barrier_openings'=>['left'=>[],'right'=>[[15.,25.]]]]]);
    $inside=false;$valid=true;
    foreach($rampMarked['profiles'] as $rp){if($rp['unmarked']||!empty($rp['open_edge_left']))$valid=false;if(!empty($rp['open_edge_right']))$inside=true;}
    check($valid&&$inside,'Ramp paint retains through lanes and opens the geometrically correct edge despite an ambiguous initial side');
    $inner=imagecreatefromstring(SweepLine\BeamPng::paint(['lanes'=>2,'backward'=>0,'oneway'=>true,'unmarked'=>true,'keep_edge_left'=>true]));
    $c=imagecolorat($inner,0,10);
    check((($c>>16)&255)>(($c>>8)&255)&&(($c>>24)&127)===0,'Retained one-way median edge stays yellow across ordinary intersection paint suppression');
    imagedestroy($inner);

})();
