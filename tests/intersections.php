<?php
use SweepLine\BeamIntersections;
$stub=BeamIntersections::clip([[0,0],[50,0],[50,100]]);
check(count($stub)===3&&abs($stub[2][1]-41.44)<0.000001,'Intersection stubs follow bends and stop at 300 feet of actual road length');
check(BeamIntersections::clip([[0,0],[0,0],[10,0]])===[[0,0],[10,0]],'Intersection clipping ignores duplicate vertices');
check(BeamIntersections::clip([[0,0]])===[],'Degenerate intersection branch cannot produce a decal');
$context=['branches'=>[['points'=>[[100,100],[100,300]],'width_m'=>8]]];
$contextRaster=new SweepLine\BeamRaster(8,400,str_repeat(pack('g',100),64),str_repeat(chr(100),192));
$contextObjects=BeamIntersections::objects($context,$contextRaster,[0,0,400,400],50,'test_context');
check(count($contextObjects)===1&&$contextObjects[0]['class']==='DecalRoad'&&!$contextObjects[0]['overObjects']&&$contextObjects[0]['hiddenInNavi'],'Side roads use terrain decals without adding pavement collision meshes or navigation branches');
check($contextObjects[0]['nodes'][0]==[100,100,50,8],'Context decals use local coordinates and terrain elevation');
$contextGeometry=new SweepLine\BeamScenery([[0,100],[400,100]],array_fill(0,2,['width_m'=>8]),BeamIntersections::exclusions($context,[0,0,400,400]));
check(!$contextGeometry->allows(100,140,2)&&$contextGeometry->allows(140,140,2),'Trees and buildings avoid side-road stubs while retaining the selected-road scenery range');
check(SweepLine\BeamVegetation::choose('rock',[-85,40],200,3,0.5)===null,'Midwest exports exclude Utah boulders even on gray aerial patches');
check(SweepLine\BeamVegetation::choose('rock',[-110,38],1500,2,0.5,['canopy_evidence'=>0.1,'tree_probability'=>0.1])==='utah_rock','Dry bare western terrain can use Utah boulders');
check(SweepLine\BeamVegetation::choose('rock',[-119,39],2000,0,0.5,['canopy_evidence'=>0.9,'tree_probability'=>0.8])===null,'Green forested western terrain does not inherit desert boulders');
