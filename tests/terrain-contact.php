<?php
// Entirely generated geometry: no road- or locality-specific clearance rules.
use SweepLine\{BeamTerrainContact,BeamJunctionBoundary};
$contactRoad=[[31,31,30],[55,48,34],[62,74,35],[89,90,39]];
$contactProfiles=array_fill(0,count($contactRoad),['width_m'=>11.2]);
$contactRaster=new SweepLine\BeamRaster(32,128,str_repeat(pack('g',42),1024),str_repeat(chr(100),3072));
$contactBefore=clone $contactRaster;
$stats=BeamTerrainContact::clear($contactRoad,$contactProfiles,$contactRaster,0,'',static function($m){});
check($stats['lowered_vertices']>0,'Exact clearance lowers terrain intersecting curved, graded pavement');
$sections=BeamJunctionBoundary::sections($contactRoad,$contactProfiles);$contactTriangles=[];
for($i=1;$i<count($sections);$i++){[$a,$b]=$sections[$i-1];[$d,$c]=$sections[$i];$contactTriangles[]=[$a,$b,$c];$contactTriangles[]=[$a,$c,$d];}
$violations=0;
foreach($contactTriangles as $triangle)for($u=0;$u<=20;$u++)for($v=0;$v<=20-$u;$v++){
    $w=[$u/20,$v/20,1-($u+$v)/20];$p=[0.,0.,0.];foreach($w as $k=>$value)foreach([0,1,2] as $axis)$p[$axis]+=$value*$triangle[$k][$axis];
    $gx=$p[0]/4;$gy=$p[1]/4;$x=(int)floor($gx);$y=(int)floor($gy);$fx=$gx-$x;$fy=$gy-$y;
    $h=[$contactRaster->cell($x,$y),$contactRaster->cell($x+1,$y),$contactRaster->cell($x,$y+1),$contactRaster->cell($x+1,$y+1)];
    $weights=[$fx+$fy<=1?[1-$fx-$fy,$fx,$fy,0]:[0,1-$fy,1-$fx,$fx+$fy-1],$fx>=$fy?[1-$fx,$fx-$fy,0,$fy]:[1-$fy,0,$fy-$fx,$fx]];
    foreach($weights as $tw){$z=0.;foreach($tw as $k=>$value)$z+=$value*$h[$k];if($z>$p[2]-0.045)$violations++;}
}
check($violations===0,'Both native terrain diagonals stay below pavement interiors and mitered edges');
check($contactRaster->cell(0,0)===$contactBefore->cell(0,0),'Exact contact repair leaves distant terrain untouched');
$protectedRaster=clone $contactBefore;$protectedBytes=$protectedRaster->heights;
BeamTerrainContact::clear($contactRoad,$contactProfiles,$protectedRaster,0,str_repeat("\1",1024),static function($m){});
check($protectedRaster->heights===$protectedBytes,'Protected bridge and tunnel terrain remains byte-for-byte unchanged');

$mergeMain=['points'=>[[100,500],[300,500],[900,500],[1100,500]]];
$mergeRaw=[[900,500],[850,530],[350,530],[300,500]];
$mergeSource=['points'=>$mergeRaw,'direction'=>1,'highway'=>'primary','tags'=>['oneway'=>'yes','lanes'=>'2']];
$mergeSample=SweepLine\BeamPlan::sample($mergeRaw,20,0.001);
$mergeRun=array_slice($mergeSample,4,-4);
$mergeTrack=['points'=>$mergeRun,'candidate_points'=>$mergeRaw,'sources'=>[$mergeSource]];
$extended=SweepLine\BeamCarriageways::mergeApproaches($mergeMain,$mergeTrack,$mergeRun);
check($extended[0]===[900,500]&&end($extended)===[300,500],'Mapped carriageway approaches extend to shared nodes at both ends');
$notShared=$mergeMain;$notShared['points']=[[100,501],[300,501],[900,501],[1100,501]];
check(SweepLine\BeamCarriageways::mergeApproaches($notShared,$mergeTrack,$mergeRun)===$mergeRun,'Nearby unconnected roads do not receive invented merge approaches');
$mergeTrack['sources'][0]['direction']=0;
check(SweepLine\BeamCarriageways::mergeApproaches($mergeMain,$mergeTrack,$mergeRun)===$mergeRun,'Two-way extensions are not invented as opposite carriageways');
$mergeRoad=array_map(static fn($p)=>[$p[0],$p[1],45.],$extended);$chain=[0.];
for($i=1;$i<count($mergeRoad);$i++)$chain[]=$chain[$i-1]+hypot($mergeRoad[$i][0]-$mergeRoad[$i-1][0],$mergeRoad[$i][1]-$mergeRoad[$i-1][1]);
$mergePrepared=['road'=>$mergeRoad,'profiles'=>array_fill(0,count($mergeRoad),['width_m'=>8]),'chain'=>$chain,'merge_nodes'=>[$extended[0],end($extended)]];
$mainRoad=array_map(static fn($p)=>[$p[0],$p[1],40.],$mergeMain['points']);
$aligned=SweepLine\BeamCarriageways::conformMerges($mergePrepared,$mainRoad,array_fill(0,count($mainRoad),['width_m'=>8]),[0,0,1200,1200]);
check(abs($aligned['road'][0][2]-40)<0.00001&&abs(end($aligned['road'])[2]-40)<0.00001,'Both mapped merges meet selected-road elevations');
check($mainRoad===array_map(static fn($p)=>[$p[0],$p[1],40.],$mergeMain['points']),'Merge elevation alignment preserves the selected road');

$mergeGraphObjects=[
 ['class'=>'BeamNGWaypoint','name'=>'sl_nav_merge','position'=>$aligned['road'][0]],
 ['class'=>'BeamNGWaypoint','name'=>'carriageway_0_sl_nav_0','position'=>$aligned['road'][0]]
];
$aligned['name']='carriageway_0';$aligned['context']=['junctions'=>[]];$mergeEdges=[];
check(SweepLine\BeamCarriageways::connectNavigation($mergeGraphObjects,[$aligned],$mergeEdges)===1,'Traffic connects at a confirmed shared merge node without a median crossing');
