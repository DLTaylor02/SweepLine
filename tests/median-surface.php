<?php
use SweepLine\{BeamMedianSurface,BeamJunctionBoundary,BeamRaster,BeamTerrainContact,BeamPolygon};
$groundRaster=new BeamRaster(16,240,str_repeat(pack('g',0),256),str_repeat(chr(100),768));
$groundMain=[[0,50,10],[50,50,11],[75,55,12],[100,50,11],[200,50,10]];
$groundProfiles=array_fill(0,count($groundMain),['width_m'=>10.,'oneway'=>true,'legal_forward'=>true]);
$groundReturn=[];for($x=200;$x>=0;$x-=20)$groundReturn[]=[$x,80,13];
$groundTrack=['name'=>'generic_median','road'=>$groundReturn,'profiles'=>array_fill(0,count($groundReturn),['width_m'=>10.,'oneway'=>true,'legal_forward'=>true]),'chain'=>range(0,200,20),'bridges'=>[],'tunnels'=>[]];
$ground=SweepLine\BeamMedianSurface::build($groundMain,$groundProfiles,[$groundTrack],[],$groundRaster,str_repeat("\0",256),'',static fn($m)=>null);
check(count($ground['faces'])>0,'Refined median ground is produced independently of native heightfield resolution');
$mainEdge=BeamJunctionBoundary::sections($groundMain,$groundProfiles)[2][0];$mainEdge[2]-=0.05;
$hasVertex=static function(array $point,array $vertices):bool{foreach($vertices as $v)if(hypot($point[0]-$v[0],$point[1]-$v[1])<1e-7&&abs($point[2]-$v[2])<1e-7)return true;return false;};
check($hasVertex($mainEdge,$ground['vertices']),'Median keeps intermediate primary edge curvature and grade vertices rather than bridging them with a chord');
$edge=SweepLine\BeamJunctionBoundary::sections($groundReturn,$groundTrack['profiles'])[4][0];$edge[2]-=0.05;
check($hasVertex($edge,$ground['vertices']),'Returning pavement edge meets refined terrain exactly five centimetres below pavement');
$upward=true;foreach(BeamMedianSurface::triangles($ground) as $tri)$upward=$upward&&BeamPolygon::cross(...$tri)>1e-8;
check($upward,'Median collision has upward top triangles only, with no vertical sidewalls or hidden caps');
$inside=true;foreach(BeamMedianSurface::triangles($ground) as $tri){
 $center=[array_sum(array_column($tri,0))/3,array_sum(array_column($tri,1))/3];
 $hit=(new SweepLine\BeamRoadIndex([['points'=>$groundMain]]))->nearest($center,100);
 $inside=$inside&&$hit['distance']>=5-0.01&&$center[1]<=75+1e-7;
}
check($inside,'Refined median triangles stay outside both driving pavement footprints');
$blocked=BeamMedianSurface::build($groundMain,$groundProfiles,[$groundTrack],[],$groundRaster,str_repeat("\1",256),'',static fn($m)=>null);
check($blocked['faces']===[],'Refined median does not cover protected structure or side-road support');
$tunnelTrack=$groundTrack;$tunnelTrack['tunnels']=[['start_m'=>0,'end_m'=>200]];
$blocked=BeamMedianSurface::build($groundMain,$groundProfiles,[$tunnelTrack],[],$groundRaster,str_repeat("\0",256),'',static fn($m)=>null);
check($blocked['faces']===[],'Refined median excludes mapped tunnel spans');
$holes=str_repeat("\xff",256);
$blocked=BeamMedianSurface::build($groundMain,$groundProfiles,[$groundTrack],[],$groundRaster,str_repeat("\0",256),$holes,static fn($m)=>null);
check($blocked['faces']===[],'Refined median cannot cover actual native tunnel holes');
$terrainHigh=new BeamRaster(16,240,str_repeat(pack('g',20),256),str_repeat(chr(100),768));
$native=BeamTerrainContact::triangles(BeamMedianSurface::triangles($ground),$terrainHigh,0,str_repeat("\0",256),static fn($m)=>null,true);
check($native['lowered_vertices']>0,'Native terrain is cleared below refined median ground rather than intersecting it');

$reverseMain=array_reverse($groundMain);$reverseProfiles=array_reverse($groundProfiles);foreach($reverseProfiles as &$profile)$profile['legal_forward']=false;unset($profile);
$reverseGround=BeamMedianSurface::build($reverseMain,$reverseProfiles,[$groundTrack],[],$groundRaster,str_repeat("\0",256),'',static fn($m)=>null);
check($reverseGround['faces']&&$hasVertex($mainEdge,$reverseGround['vertices']),'Reversing selected-road orientation preserves the same physical median seam');

(function(): void {
    $main=[];$return=[];for($x=0;$x<=480;$x+=5)$main[]=[$x,50,10];for($x=480;$x>=0;$x-=5)$return[]=[$x,80,13];
    $p=array_fill(0,count($main),['width_m'=>10.,'oneway'=>true,'legal_forward'=>true]);$r=new \SweepLine\BeamRaster(64,512,str_repeat(pack('g',0),4096),str_repeat(chr(100),12288));
    $track=['name'=>'approach-fixture','road'=>$return,'profiles'=>$p,'chain'=>range(0,480,5),'bridges'=>[['start_m'=>220,'end_m'=>280]],'tunnels'=>[]];
    $surface=\SweepLine\BeamMedianSurface::build($main,$p,[$track],[['start_m'=>200,'end_m'=>260]],$r,str_repeat("\0",4096),'',static fn($m)=>null);
    $contact=false;$deckFilled=false;
    foreach($surface['vertices'] as $v){if(abs($v[0]-190)<1e-7&&abs($v[1]-55)<1e-7&&abs($v[2]-9.95)<1e-7)$contact=true;if($v[0]>200.0001&&$v[0]<259.9999)$deckFilled=true;}
    check($contact,'Median retains exact pavement contact ten metres before a bridge');
    check(!$deckFilled,'Approach refinement never fills the actual bridge span');
    $endContact=false;foreach($surface['vertices'] as $v)if(abs($v[0]-10)<1e-7&&abs($v[1]-55)<1e-7&&abs($v[2]-9.95)<1e-7)$endContact=true;
    check($endContact,'Median road edges retain support while the centre fades toward native terrain');
})();
