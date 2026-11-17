<?php
require_once __DIR__.'/scene-archive.php';
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
$largePlan['traffic_sign_data']=['fetch_status'=>'complete','source'=>'Explicit synthetic OSM fixture','signs'=>[['id'=>'99001','code'=>'W1-2L','bearing'=>270.,'point'=>[200,810],'lon'=>-100.,'lat'=>40.,'timestamp'=>'2026-01-01T00:00:00Z','source'=>'OpenStreetMap']]];
$largePlan['roadside_data']=['fetch_status'=>'complete','features'=>[
 ['id'=>'node:99002','kind'=>'bench','tags'=>['amenity'=>'bench'],'points'=>[[200,820]],'node_ids'=>[],'timestamp'=>null],
 ['id'=>'way:99003','kind'=>'wall','tags'=>['barrier'=>'wall'],'points'=>[[250,820],[280,820]],'node_ids'=>[],'timestamp'=>null]
]];
$largePlan['mapillary_data']=['status'=>'complete','signs'=>0,'point_features'=>1,'attribution'=>'Mapillary'];
$largePlan['building_data']=['footprints'=>[[
 'points'=>[[200,830],[220,830],[220,845],[200,845]],'osm_id'=>'way/99004',
 'tags'=>['building'=>'house','height'=>'18','roof:height'=>'3','roof:shape'=>'gabled','building:material'=>'brick','building:colour'=>'#d4b08a','roof:colour'=>'#444444']
]]];
$photoSources=[['image_id'=>'101','creator'=>'Fixture photographer','captured_at'=>1700000000000,'url'=>'https://www.mapillary.com/app/?pKey=101'],['image_id'=>'102','creator'=>'Fixture photographer','captured_at'=>1700000000000,'url'=>'https://www.mapillary.com/app/?pKey=102']];
$largePlan['building_data']['footprints'][]=['points'=>[[250,850],[270,850],[270,865],[250,865]],'osm_id'=>'way/99005','tags'=>['building'=>'yes'],
 'photo_match'=>['color'=>[.7,.6,.5],'material'=>'wood','confidence'=>'two_view_heuristic','sources'=>$photoSources]];
$largePlan['building_data']['photo_analysis']=['status'=>'complete','matched'=>1,'image_sources'=>$photoSources];
$largeFile=tempnam(sys_get_temp_dir(),'sl-large-bridge-');
try{
 $largeReport=BeamMap::build($largePlan,$largeRaster,str_repeat('8',32),$largeFile,static function($m){});
 $largeZip=new ZipArchive();$largeZip->open($largeFile);$largeBase='levels/sweepline_8888888888888888/';
 $largeObjects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim(slSceneText($largeZip,$largeBase))));
 check($largeZip->getFromName($largeBase.'art/roads/variation.color.png')===false&&!str_contains($largeZip->getFromName($largeBase.'art/roads/main.materials.json'),'variation.color.png')&&$largeReport['surface_appearance']['asphalt_variation_strength']===0,'Archive uses restored asphalt without the regressed detail-map shader');
 check($largeReport['traffic_sign_data']['placed']===1&&$largeZip->getFromName($largeBase.'art/signs/W1_2L.dae')!==false&&$largeZip->getFromName($largeBase.'art/signs/W1_2L.color.png')!==false,'Generated BeamNG archive contains mapped sign objects, source report and face assets');
 check($largeReport['building_data']['placed']===2&&$largeReport['building_data']['attribute_evidence'][0]['height_m']===18.0&&$largeReport['building_data']['attribute_evidence'][0]['roof_shape']==='gabled','Finished archive retains mapped building height and roof-shape evidence');
 check($largeZip->getFromName($largeBase.'art/shapes/building_0.dae')!==false&&$largeReport['building_data']['attribute_evidence'][0]['appearance_sources']['facade_material']==='building:material','Finished archive contains the mapped building mesh and explicit material provenance');
 check($largeReport['building_data']['placed_photo_matches']===1&&str_contains($largeReport['building_data']['attribute_evidence'][1]['appearance_sources']['facade_material'],'Mapillary'),'Archive uses photo-informed stock building materials alongside explicit OSM materials');
 check(str_contains($largeZip->getFromName('BUILDING-PHOTO-ATTRIBUTION.txt'),'Fixture photographer')&&str_contains($largeZip->getFromName('BUILDING-PHOTO-ATTRIBUTION.txt'),'CC BY-SA 4.0'),'Photo-derived appearance includes source attribution and license in the finished ZIP');
 check($largeReport['bridges'][0]['large']&&abs($largeReport['bridges'][0]['length_m']-700)<0.01,'Complete ZIP supports a seven hundred metre viaduct');
 check(abs($largeRaster->height(750,800)-60)<0.01,'Large bridge preserves native valley terrain');
 check(count(array_filter($largeObjects,static fn($o)=>str_starts_with($o['name']??'','bridge_0_pier_')))>15,'Large bridge generates bounded intermediate supports');
 check(count(array_filter($largeObjects,static fn($o)=>str_starts_with($o['name']??'','bridge_0_rail_')))===2,'Large bridge retains both collision barriers');
 $largeDeck=array_values(array_filter($largeObjects,static fn($o)=>$o['class']==='MeshRoad'&&str_starts_with($o['name']??'','road_')))[0];
 $largeMiddle=array_values(array_filter($largeDeck['nodes'],static fn($p)=>abs($p[0]-750)<5))[0];
 check(abs($largeMiddle[2]+$largeReport['elevation_origin_m']-100.25)<0.1,'Long deck follows approach elevations instead of valley floor');
 check(($largeReport['roadside_data']['stock_assets']['bench']??0)===1&&$largeReport['roadside_data']['mesh_parts']>0,'Archive combines stock roadside furniture and generated mapped paths');
 check(str_contains($largeZip->getFromName($largeBase.'mainLevel.lua'),'/levels/east_coast_usa/art/shapes/buildings/main.materials.json'),'Archive loads referenced stock furniture materials');
 check(count(array_filter($largeObjects,static fn($o)=>($o['shapeName']??'')==='/levels/east_coast_usa/art/shapes/clutter/clutter_city_bench_wood.dae'))===1,'Archive references stock bench without copying game geometry');
 check($largeZip->getFromName('MAPILLARY-ATTRIBUTION.txt')!==false&&str_contains($largeZip->getFromName($largeBase.'info.json'),'Mapillary'),'Mapillary-enabled archive credits its provider without bundling imagery');
 check(count(array_filter($largeObjects,static fn($o)=>($o['name']??'')==='mapped_sign_99001'&&($o['collisionType']??'')==='Visible Mesh Final'))===1&&count(array_filter($largeObjects,static fn($o)=>str_starts_with($o['name']??'','mapped_stock_')&&($o['collisionType']??'')==='Collision Mesh'))>0,'Archive enables collision on generated sign panels and stock furniture');
 $largeZip->close();
}finally{@unlink($largeFile);}

// A small DEM/linear-profile discrepancy must identify the estimate, not assert a real obstruction.
$conflictN=64;$conflictSide=640.;$conflictBand='';
for($y=$conflictN-1;$y>=0;$y--)for($x=0;$x<$conflictN;$x++)$conflictBand.=pack('g',$x===30?100.25:100);
$conflictRaster=new BeamRaster($conflictN,$conflictSide,$conflictBand,str_repeat(chr(100),$conflictN*$conflictN*3));
$conflictPoints=[[100,300],[200,300],[300,300],[400,300],[500,300]];$conflictChain=[0,100,200,300,400];
$conflictSpan=[['start_m'=>100,'end_m'=>300,'large'=>false]];
foreach(['Selected road (Test Highway)','Opposite carriageway (TEST 1)'] as $context){
    $message='';
    try{BeamStructures::deckSamples($conflictPoints,$conflictChain,[100,100,100.25,100,100],$conflictSpan,$conflictRaster,$context);}
    catch(RuntimeException $e){$message=$e->getMessage();}
    check(str_starts_with($message,$context.': bridge 1 of 1'),'Bridge elevation rejection identifies the failing carriageway');
    check(str_contains($message,'0.82 ft above')&&str_contains($message,'656 ft long')&&str_contains($message,'0.12 mi'),'Bridge conflict reports measured discrepancy, span length and location');
    check(str_contains($message,'32.8 ft')&&str_contains($message,'66 ft outside'),'Bridge conflict reports original elevation sampling and approach distance');
    check(str_contains($message,'not confirmation that the real bridge is obstructed'),'Bridge conflict distinguishes estimated geometry from real-world conditions');
}
$acceptableBand=str_repeat(pack('g',100),$conflictN*$conflictN);$acceptableRaster=new BeamRaster($conflictN,$conflictSide,$acceptableBand,str_repeat(chr(100),$conflictN*$conflictN*3));
check(BeamStructures::deckSamples($conflictPoints,$conflictChain,[100,100,60,100,100],$conflictSpan,$acceptableRaster)===[100,100.,100.,100.,100],'Compatible bridge samples retain the existing approach-based deck calculation');
