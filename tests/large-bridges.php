<?php
// Synthetic large fixed bridge over a valley; no provider requests.
use SweepLine\{BeamStructures,BeamRaster,BeamMap};
$largeLine=[[0,0],[100,0],[800,0],[1000,0]];
$largeSource=static fn($points,$tags)=>['points'=>$points,'tags'=>$tags,'highway'=>'secondary','direction'=>0];
$largeSpan=BeamStructures::spans($largeLine,[$largeSource([[100,0],[800,0]],['bridge'=>'viaduct'])]);
check($largeSpan[0]['large']&&$largeSpan[0]['approach_m']===50&&$largeSpan[0]['estimated'],'Large viaduct records longer approaches and explicit estimate status');
check(rejects(fn()=>BeamStructures::spans([[0,0],[2500,0]],[$largeSource([[100,0],[2201,0]],['bridge'=>'yes'])])),'Bridge spans over two kilometres are rejected');
check(rejects(fn()=>BeamStructures::spans([[0,0],[1000,0]],[$largeSource([[40,0],[800,0]],['bridge'=>'yes'])])),'Large bridge requires fifty metre approaches');
foreach(['arch','suspension','cable-stayed'] as $kind)check(rejects(fn()=>BeamStructures::spans($largeLine,[$largeSource([[100,0],[800,0]],['bridge'=>'yes','bridge:structure'=>$kind])])),'Unsupported bridge structure rejected: '.$kind);
check(rejects(fn()=>BeamStructures::spans($largeLine,[$largeSource([[100,0],[800,0]],['bridge'=>'yes','bridge:movable'=>'swing'])])),'Movable large bridge is rejected');
$largeN=64;$largeSide=1600.;$largeHeights='';
for($y=$largeN-1;$y>=0;$y--)for($x=0;$x<$largeN;$x++)$largeHeights.=pack('g',$x*25>400&&$x*25<1100?60:100);
$largeRaster=new BeamRaster($largeN,$largeSide,$largeHeights,str_repeat(chr(100),$largeN*$largeN*3));
$largePlan=['terrain_style'=>'aerial','name'=>'Synthetic Large Viaduct','alternate_names'=>[],'resolution'=>$largeN,'padding_miles'=>0.5,'epsg'=>32611,'bounds'=>[0,0,1600,1600],'points'=>[[100,800],[400,800],[1100,800],[1500,800]],'sources'=>[
 $largeSource([[100,800],[400,800]],['lanes'=>'2']),
 $largeSource([[400,800],[1100,800]],['bridge'=>'viaduct','lanes'=>'2']),
 $largeSource([[1100,800],[1500,800]],['lanes'=>'2'])
],'center'=>[-119.8,39.5],'oneway'=>false];
$largeFile=tempnam(sys_get_temp_dir(),'sl-large-bridge-');
try{
 $largeReport=BeamMap::build($largePlan,$largeRaster,str_repeat('8',32),$largeFile,static function($m){});
 $largeZip=new ZipArchive();$largeZip->open($largeFile);$largeBase='levels/sweepline_8888888888888888/';
 $largeObjects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($largeZip->getFromName($largeBase.'main/MissionGroup/items.level.json'))));
 check($largeReport['bridges'][0]['large']&&abs($largeReport['bridges'][0]['length_m']-700)<0.01,'Complete ZIP supports a seven hundred metre viaduct');
 check(abs($largeRaster->height(750,800)-60)<0.01,'Large bridge preserves native valley terrain');
 check(count(array_filter($largeObjects,static fn($o)=>str_starts_with($o['name']??'','bridge_0_pier_')))>15,'Large bridge generates bounded intermediate supports');
 check(count(array_filter($largeObjects,static fn($o)=>str_starts_with($o['name']??'','bridge_0_rail_')))===2,'Large bridge retains both collision barriers');
 $largeDeck=array_values(array_filter($largeObjects,static fn($o)=>$o['class']==='MeshRoad'&&str_starts_with($o['name']??'','road_')))[0];
 $largeMiddle=array_values(array_filter($largeDeck['nodes'],static fn($p)=>abs($p[0]-750)<5))[0];
 check(abs($largeMiddle[2]+$largeReport['elevation_origin_m']-100.25)<0.1,'Long deck follows approach elevations instead of valley floor');
 $largeZip->close();
}finally{@unlink($largeFile);}
