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


// Broad bridge buffers must not hide ordinary-road or bridge-landing penetration.
$landingN=32;$landingSide=320.;$landingHeights='';
for($y=$landingN-1;$y>=0;$y--)for($x=0;$x<$landingN;$x++)$landingHeights.=pack('g',$x*10>=70&&$x*10<150?90:100.4);
$landingRaster=new SweepLine\BeamRaster($landingN,$landingSide,$landingHeights,str_repeat(chr(100),$landingN*$landingN*3));
$landingRoad=[];for($x=20;$x<=200;$x+=5)$landingRoad[]=[$x,80,100.];
$landingProfiles=array_fill(0,count($landingRoad),['width_m'=>8]);$landingChain=[];foreach($landingRoad as $p)$landingChain[]=$p[0]-20;
$landingSpan=['start_m'=>50.,'end_m'=>130.];$landingTrack=['road'=>$landingRoad,'profiles'=>$landingProfiles,'chain'=>$landingChain,'bridges'=>[$landingSpan],'tunnels'=>[]];
$oldLanding=clone $landingRaster;$broadMask=SweepLine\BeamTrack::mask($landingTrack,$oldLanding,str_repeat("\0",$landingN*$landingN),static function($m){});
BeamTerrainContact::clear($landingRoad,$landingProfiles,$oldLanding,0,$broadMask,static function($m){});
check($oldLanding->cell(16,8)>100,'Generated fixture reproduces broad-mask terrain penetration after a bridge');
$landingStats=BeamTerrainContact::repairCorridors([['name'=>'primary','road'=>$landingRoad,'profiles'=>$landingProfiles,'spans'=>[$landingSpan]]],$landingRaster,0,static function($m){});
check($landingRaster->cell(16,8)<100&&$landingRaster->cell(15,8)<100,'Final contact repair clears both the landward approach and mapped deck endpoint');
check($landingRaster->cell(10,8)===90.&&$landingRaster->cell(30,30)>100,'Bridge valley interior and distant terrain remain unchanged');
check($landingStats['roads']['primary']['protected_contacts_unresolved']===0,'Generated bridge-landing correction resolves all editable contact constraints');

// Native tunnel holes have no terrain collision surface; preserve their overburden.
$holeRaster=new SweepLine\BeamRaster(8,80,str_repeat(pack('g',120),64),str_repeat(chr(100),192));$beforeHole=$holeRaster->heights;
$holeRoad=[[10,20,100],[60,20,100]];$holeProfiles=array_fill(0,2,['width_m'=>8]);
$holeStats=BeamTerrainContact::repairCorridors([['name'=>'primary','road'=>$holeRoad,'profiles'=>$holeProfiles,'spans'=>[]]],$holeRaster,0,static function($m){},str_repeat("\xff",64));
check($holeRaster->heights===$beforeHole&&$holeStats['roads']['primary']['tunnel_hole_cells_skipped']>0,'Final repair does not excavate preserved terrain in native tunnel holes');

// Frozen core vertices remain unchanged while other corners are corrected.
$mixedRaster=new SweepLine\BeamRaster(8,80,str_repeat(pack('g',101),64),str_repeat(chr(100),192));$mixedRaster->put(2,2,99);
$mixedMask=str_repeat("\0",64);$mixedMask[2*8+2]="\1";$mixedTriangles=[[[15,15,100],[35,15,100],[15,35,100]]];
$mixedStats=BeamTerrainContact::triangles($mixedTriangles,$mixedRaster,0,$mixedMask,static function($m){},true);
check($mixedRaster->cell(2,2)===99.&&$mixedStats['lowered_vertices']>0,'Mixed-cell correction preserves frozen structure vertices while lowering editable corners');
$clippedOrdinary=BeamTerrainContact::ordinaryTriangles([[0,0,10],[10,0,12]],array_fill(0,2,['width_m'=>6]),[['start_m'=>4,'end_m'=>6]]);
$clippedArea=0.;$badStructureTriangle=false;
foreach($clippedOrdinary as $triangle){$clippedArea+=abs(SweepLine\BeamPolygon::cross(...$triangle))/2;foreach($triangle as $v)if($v[0]>4.000001&&$v[0]<5.999999)$badStructureTriangle=true;}
check(abs($clippedArea-48)<1e-6&&!$badStructureTriangle,'Exact chainage clipping excludes structure interiors without dropping whole pavement segments');


$priorityRaster=new SweepLine\BeamRaster(64,128,str_repeat(pack('g',99),4096),str_repeat(chr(100),12288));
$priorityRoad=[[10,40,100],[110,40,100]];$lowRoad=[[110,48,95],[10,48,95]];$priorityProfiles=array_fill(0,2,['width_m'=>20]);
BeamTerrainContact::repairCorridors([
 ['name'=>'primary','road'=>$priorityRoad,'profiles'=>$priorityProfiles,'spans'=>[]],
 ['name'=>'opposite','road'=>$lowRoad,'profiles'=>$priorityProfiles,'spans'=>[],'obstacles'=>[['road'=>$priorityRoad,'profiles'=>$priorityProfiles]]]
],$priorityRaster,0,static function($m){});
check($priorityRaster->cell(25,22)===99.&&$priorityRaster->cell(25,28)<99,'Hidden clipped counterpart pavement cannot dig a ledge beneath the selected road');
