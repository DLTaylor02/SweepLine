<?php
// Native PostGIS courtyard and part topology. Uses the existing isolated integration DB.
use SweepLine\{BeamBuildingGeometry as BuildingGeometry,BeamPolygon as BuildingPolygon};
$outer=[[0,0],[20,0],[20,20],[0,20]];
$hole=[[5,5],[15,5],[15,15],[5,15]];
$prepared=BuildingGeometry::topology($db,[['osm_id'=>'relation/1','part'=>false,'points'=>$outer,'holes'=>[$hole],'tags'=>['height'=>'30']]]);
check(count($prepared)===1&&count($prepared[0]['holes'])===1,'Native building topology preserves a mapped courtyard');
$area=array_sum(array_map(static fn($t)=>abs(BuildingPolygon::area($t)),$prepared[0]['roof_triangles']));
check(abs($area-300)<1e-6,'Native constrained triangulation leaves the courtyard roof and floor open');
foreach($prepared[0]['roof_triangles'] as $tri){
    $center=[array_sum(array_column($tri,0))/3,array_sum(array_column($tri,1))/3];
    if(BuildingPolygon::inside($center,$hole))throw new RuntimeException('Courtyard contains a roof triangle');
}
$part=['osm_id'=>'way/2','part'=>true,'points'=>[[0,0],[10,0],[10,20],[0,20]],'holes'=>[],'tags'=>['building:part'=>'yes','height'=>'45']];
$parent=['osm_id'=>'way/1','part'=>false,'points'=>$outer,'holes'=>[],'tags'=>['building'=>'commercial','height'=>'12','building:colour'=>'#abc']];
$split=BuildingGeometry::topology($db,[$parent,$part]);$outline=array_values(array_filter($split,static fn($f)=>!$f['part']));$wing=array_values(array_filter($split,static fn($f)=>$f['part']));
check(count($outline)===1&&abs(BuildingPolygon::area($outline[0]['points'])-200)<1e-6,'Mapped part replaces only its parent-covered footprint, retaining the uncovered wing');
check($wing[0]['tags']['height']==='45'&&$wing[0]['tags']['building:colour']==='#abc'&&$wing[0]['assembly']==='way/1','Building parts inherit missing parent appearance without replacing explicit part height');
$part['points']=$outer;
$covered=BuildingGeometry::topology($db,[$parent,$part]);
check(count($covered)===1&&$covered[0]['part'],'Fully described parent outline does not create a duplicate full-height volume');

$levelsPart=$part;$levelsPart['tags']=['building:part'=>'yes','building:levels'=>'5'];
$levelAssembly=BuildingGeometry::topology($db,[$parent,$levelsPart]);
$levelWing=array_values(array_filter($levelAssembly,static fn($f)=>$f['part']))[0];
check(!isset($levelWing['tags']['height'])&&SweepLine\BeamBuildingAttributes::read($levelWing['tags'])['height_m']===15.0,'Explicit part floor count outranks an inherited parent height');
$outside=$part;$outside['points']=[[10,0],[30,0],[30,20],[10,20]];
$notContained=BuildingGeometry::topology($db,[$parent,$outside]);
$standalone=array_values(array_filter($notContained,static fn($f)=>$f['part']))[0];
check($standalone['assembly']==='way/2','A part extending outside an outline is not associated by centroid alone');
