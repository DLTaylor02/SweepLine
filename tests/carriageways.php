<?php
use SweepLine\{BeamCarriageways,BeamMap,BeamRaster,BeamPlan,BeamTrack};
$cwPlan=['name'=>'Test Highway','points'=>[[100,600],[1100,600]],'sources'=>[['points'=>[[100,600],[1100,600]],'direction'=>1,'highway'=>'primary','tags'=>['name'=>'Test Highway','ref'=>'TEST 42','oneway'=>'yes','lanes'=>'2']]]];
$cwCandidate=['id'=>1,'name'=>'Test Highway','ref'=>'TEST 42','highway'=>'primary','direction'=>1,'points'=>[[1100,630],[100,630]]];
$cwMatch=BeamCarriageways::match($cwPlan,[$cwCandidate],static function($m){});
check($cwMatch['tracks'][0]['road_name']==='Test Highway'&&$cwMatch['tracks'][0]['road_ref']==='TEST 42','Matched carriageway retains its name and route reference for export provenance');
check(count($cwMatch['tracks'])===1&&$cwMatch['tracks'][0]['length_m']>900,'Opposite carriageway is matched for the full selected length, beyond intersection-stub limits');
foreach([
 ['direction'=>0],
 ['points'=>[[100,630],[1100,630]]],
 ['name'=>'Frontage Road','ref'=>'OTHER'],
 ['highway'=>'secondary'],
 ['points'=>[[1100,900],[100,900]]],
 ['points'=>[[1100,600],[100,600]]]
] as $change)check(BeamCarriageways::match($cwPlan,[array_replace($cwCandidate,$change)],static function($m){})['tracks']===[],'Reject unrelated, same-direction, shared or out-of-range carriageway');
$reversePlan=$cwPlan;$reversePlan['points']=array_reverse($reversePlan['points']);
check(count(BeamCarriageways::match($reversePlan,[$cwCandidate],static function($m){})['tracks'])===1,'Opposite matching follows mapped legal direction even when selected export orientation is reversed');
$negative=$cwCandidate;$negative['points']=array_reverse($negative['points']);$negative['direction']=-1;
check(count(BeamCarriageways::match($cwPlan,[$negative],static function($m){})['tracks'])===1,'Reverse OSM one-way encoding is normalized correctly');
$ambiguous=$cwCandidate;$ambiguous['id']=2;$ambiguous['points']=[[1100,631.5],[100,631.5]];
$ambiguity=BeamCarriageways::match($cwPlan,[$cwCandidate,$ambiguous],static function($m){});
check($ambiguity['tracks']===[]&&$ambiguity['ambiguous_matches']>0,'Ambiguous competing opposite paths are not guessed');
$duplicate=$cwCandidate;$duplicate['id']=3;
check(count(BeamCarriageways::match($cwPlan,[$cwCandidate,$duplicate],static function($m){})['tracks'])===1,'Duplicate regional copies of one carriageway produce one track');
$cwN=128;$cwSide=1200.;$cwHeights=str_repeat(pack('g',100),$cwN*$cwN);$cwColors=str_repeat(chr(100),$cwN*$cwN*3);
$cwRaster=new BeamRaster($cwN,$cwSide,$cwHeights,$cwColors);
$cwExport=$cwPlan+['resolution'=>$cwN,'bounds'=>[0,0,1200,1200],'terrain_style'=>'aerial','padding_miles'=>0.5,'epsg'=>32611,'alternate_names'=>[],'center'=>[-90,40],'oneway'=>true];
$cwExport['carriageways']=[['name'=>'carriageway_0','candidate_id'=>1,'points'=>$cwCandidate['points'],'sources'=>[['points'=>$cwCandidate['points'],'direction'=>1,'highway'=>'primary','tags'=>['lanes'=>'3','oneway'=>'yes','ref'=>'TEST 42']]],'intersection_data'=>['branches'=>[]]]];
$cwFile=tempnam(sys_get_temp_dir(),'sl-cw-');
try{
 $cwReport=BeamMap::build($cwExport,$cwRaster,str_repeat('6',32),$cwFile,static function($m){});
 $cwZip=new ZipArchive();$cwZip->open($cwFile);$cwBase='levels/sweepline_6666666666666666/';
 $cwObjects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($cwZip->getFromName($cwBase.'main/MissionGroup/items.level.json'))));
 check($cwZip->getFromName($cwBase.'art/roads/carriageway_0-0.dae')!==false,'Full-length opposite carriageway has its own collision pavement mesh');
 $cwNav=json_decode($cwZip->getFromName($cwBase.'map.json'),true);
 $cwOpposite=array_filter($cwNav['segments'],static fn($s,$name)=>str_starts_with($name,'carriageway_0_'),ARRAY_FILTER_USE_BOTH);
 check(count($cwOpposite)>100,'Opposite carriageway is included in minimap and navigation along its full length');
 check(count(array_filter($cwOpposite,static fn($s)=>!$s['oneWay']||$s['lanesRight']!==3))===0,'Opposite traffic retains one-way travel and three mapped lanes');
 $cwNames=array_column($cwObjects,'name');check(count($cwNames)===count(array_unique($cwNames)),'Both carriageways have collision-free scene object names');
 $cwMap=[];foreach($cwObjects as $o)if(isset($o['name'],$o['position']))$cwMap[$o['name']]=$o['position'];
 $cwFirst=reset($cwOpposite);check($cwMap[$cwFirst['nodes'][1]][0]<$cwMap[$cwFirst['nodes'][0]][0],'Opposite AI graph follows its legal westward direction');
 check(count(array_filter($cwNav['segments'],static fn($s)=>str_starts_with($s['nodes'][0],'carriageway_0_')!==str_starts_with($s['nodes'][1],'carriageway_0_')))===0,'Independent parallel tracks do not receive invented median connections');
 check(count(array_filter($cwObjects,static fn($o)=>$o['class']==='SpawnSphere'))===2,'Selected road retains its two original spawn points');
 check(count($cwReport['carriageways']['tracks'])===1&&$cwReport['carriageways']['tracks'][0]['length_m']>900,'Export reports companion-road coverage separately');
 check($cwReport['scenery_placement']['shared_object_budget']===24000,'All carriageways share a bounded scenery budget');
 $cwZip->close();
}finally{@unlink($cwFile);}

$twoWayPlan=$cwPlan;$twoWayPlan['sources']=[
 ['points'=>[[100,600],[600,600]],'direction'=>1,'highway'=>'primary','tags'=>['ref'=>'TEST 42','lanes'=>'2','oneway'=>'yes']],
 ['points'=>[[600,600],[1100,600]],'direction'=>0,'highway'=>'primary','tags'=>['ref'=>'TEST 42','lanes'=>'2']]
];
$partial=BeamCarriageways::match($twoWayPlan,[$cwCandidate],static function($m){});
check(count($partial['tracks'])===1&&max(array_column($partial['tracks'][0]['points'],0))<=600,'Two-way primary approaches are not mistaken for divided-road coverage');
$verifiedTrack=['points'=>$cwCandidate['points'],'sources'=>[['points'=>$cwCandidate['points'],'direction'=>1,'highway'=>'primary','tags'=>['oneway'=>'yes','lanes'=>'3']]]];
check(count(BeamCarriageways::verifiedRuns($cwPlan,$verifiedTrack))===1,'Confirmed one-way tags and adequate pavement separation retain a match');
$verifiedTrack['sources'][0]['direction']=0;
check(BeamCarriageways::verifiedRuns($cwPlan,$verifiedTrack)===[],'A two-way parallel road is not exported as an opposite carriageway');
$verifiedTrack['sources'][0]['direction']=1;$verifiedTrack['points']=[[1100,606],[100,606]];$verifiedTrack['sources'][0]['points']=$verifiedTrack['points'];
check(BeamCarriageways::verifiedRuns($cwPlan,$verifiedTrack)===[],'Overlapping lane widths are rejected rather than narrowing the opposite pavement');
$legalProfiles=SweepLine\BeamLanes::profiles(array_reverse($cwPlan['points']),$cwPlan['sources'],[0,0,1200,1200]);
$legalRoad=[[1100,600,10],[100,600,10]];
$legalNav=SweepLine\BeamTraffic::graph($legalRoad,$legalProfiles,['roads'=>[],'junctions'=>[]]);
$legalEdge=reset($legalNav['segments']);$legalObjects=array_column($legalNav['objects'],null,'name');
check($legalObjects[$legalEdge['nodes'][1]]['position'][0]>$legalObjects[$legalEdge['nodes'][0]]['position'][0],'Reversing export orientation does not reverse mapped one-way AI travel');

foreach(['bridge','tunnel'] as $structure){
 $p=$cwExport;$p['points']=[[100,600],[350,600],[550,600],[1100,600]];
 $make=static fn($points,$tags,$lanes)=>['points'=>$points,'direction'=>1,'highway'=>'primary','tags'=>$tags+['ref'=>'TEST 42','lanes'=>(string)$lanes,'oneway'=>'yes']];
 $p['sources']=[$make([[100,600],[350,600]],[],2),$make([[350,600],[550,600]],[$structure=>'yes'],2),$make([[550,600],[1100,600]],[],2)];
 $p['carriageways'][0]['points']=[[1100,630],[550,630],[350,630],[100,630]];
 $p['carriageways'][0]['sources']=[$make([[1100,630],[550,630]],[],3),$make([[550,630],[350,630]],[$structure=>'yes'],3),$make([[350,630],[100,630]],[],3)];
 $h='';for($y=$cwN-1;$y>=0;$y--)for($x=0;$x<$cwN;$x++){
  $wx=$x*$cwSide/$cwN;$height=$wx>350&&$wx<550?($structure==='bridge'?80:120):100;$h.=pack('g',$height);
 }
 $raster=new BeamRaster($cwN,$cwSide,$h,$cwColors);$file=tempnam(sys_get_temp_dir(),'sl-cw-structure-');
 try{
  $report=BeamMap::build($p,$raster,str_repeat($structure==='bridge'?'4':'5',32),$file,static function($m){});
  $zip=new ZipArchive();$zip->open($file);$base='levels/sweepline_'.str_repeat($structure==='bridge'?'4':'5',16).'/';
  $objects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($zip->getFromName($base.'main/MissionGroup/items.level.json'))));
  if($structure==='bridge'){
   check(count($report['bridges'])===1&&count($report['carriageways']['tracks'][0]['bridges'])===1,'Each carriageway retains its own bridge spans');
   check($zip->getFromName($base.'art/shapes/carriageway_0-deck.dae')!==false,'Opposite bridge includes a closed underside');
   check(count(array_filter($objects,static fn($o)=>str_starts_with($o['name']??'','carriageway_0_bridge_0_rail_')))===2,'Opposite bridge includes its own barriers');
   check(abs($raster->height(450,630)-80)<0.01,'Opposite bridge keeps its valley unfilled');
  }else{
   check(count($report['tunnels'])===1&&count($report['carriageways']['tracks'][0]['tunnels'])===1,'Each carriageway retains its own tunnel span');
   check(count($report['tunnel_data']['spans'])===2,'Both tunnel bores share one combined terrain excavation');
   check(abs($raster->height(450,630)-120)<0.01,'Parallel tunnel bores preserve the mountain above them');
   check($zip->getFromName($base.'art/tunnels/interior.dae')!==false,'Parallel tunnel interiors are packaged in the map');
  }
  $zip->close();
 }finally{@unlink($file);}
}

$crossPlan=$cwExport;
$branch=static fn($points)=>['points'=>$points,'width_m'=>6.,'profile'=>SweepLine\BeamLanes::infer(['lanes'=>'2','width'=>'6'],'secondary',0),'osm_id'=>900];
$crossPlan['intersection_data']=['branches'=>[$branch([[600,600],[600,800]]),$branch([[600,600],[600,500]])]];
$crossPlan['carriageways'][0]['intersection_data']=['branches'=>[$branch([[600,630],[600,800]]),$branch([[600,630],[600,500]])]];
$crossRaster=new BeamRaster($cwN,$cwSide,$cwHeights,$cwColors);$crossFile=tempnam(sys_get_temp_dir(),'sl-cw-crossing-');
try{
 $crossReport=BeamMap::build($crossPlan,$crossRaster,str_repeat('3',32),$crossFile,static function($m){});
 $zip=new ZipArchive();$zip->open($crossFile);$base='levels/sweepline_3333333333333333/';
 $objects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($zip->getFromName($base.'main/MissionGroup/items.level.json'))));$triangles=[];
 foreach($objects as $object)if(($object['class']??'')==='TSStatic'&&($object['collisionType']??'')==='Visible Mesh Final'&&str_contains($object['shapeName']??'','/art/roads/')){
  $xml=simplexml_load_string($zip->getFromName(ltrim($object['shapeName'],'/')));$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
  $v=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
  foreach($xml->xpath('//c:triangles/c:p') as $p){$ix=array_map('intval',preg_split('/\s+/',trim((string)$p)));
   for($i=0;$i<count($ix);$i+=9){$triangle=[];foreach([0,3,6] as $offset){$point=array_slice($v,$ix[$i+$offset]*3,3);foreach([0,1,2] as $axis)$point[$axis]+=$object['position'][$axis];$triangle[]=$point;}$triangles[$object['name']][]=$triangle;}
  }
 }
 foreach([580,589,602,610,620,630,640,650,670,710,780,790] as $y){
  $cover=0;foreach($triangles as $mesh)foreach($mesh as [$a,$b,$c]){
   $d=($b[1]-$c[1])*($a[0]-$c[0])+($c[0]-$b[0])*($a[1]-$c[1]);if(abs($d)<1e-10)continue;
   $u=(($b[1]-$c[1])*(600-$c[0])+($c[0]-$b[0])*($y-$c[1]))/$d;$v=(($c[1]-$a[1])*(600-$c[0])+($a[0]-$c[0])*($y-$c[1]))/$d;
   if(min($u,$v,1-$u-$v)>=-1e-8){$cover++;break;}
  }
  check($cover===1,'Crossing street has exactly one collision pavement through both carriageways at y='.$y);
 }
 check($crossReport['carriageways']['mapped_junction_links']>0,'Traffic networks connect at the mapped crossing street rather than across the median');
 check($crossReport['carriageways']['tracks'][0]['intersection_context']['rendered_branches']>=2,'Opposite carriageway keeps its intersecting-road context');
 $zip->close();
}finally{@unlink($crossFile);}
