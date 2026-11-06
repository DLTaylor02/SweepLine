<?php
use SweepLine\{BeamBuildLog,BeamLanePaint,BeamRoadSurface,BeamJunctionBoundary,BeamBridgeDeck};
$diagnosticLog=new BeamBuildLog();$diagnosticLog->event('Downloading elevation (attempt 1/3)');$diagnosticLog->event('Classifying terrain');
$text=$diagnosticLog->render(['name'=>'Generated test','points'=>[[1,2],[3,4]],'selection'=>['original'=>['clicked_start'=>[0,0]]],'env'=>['password'=>'DO-NOT-EXPORT'],'user'=>['password'=>'DO-NOT-EXPORT']],['building_data'=>['rejection_reasons'=>['pavement_overlap'=>2]]],['primary'=>['points'=>[[0,0,10],[10,0,11]]]],[]);
check(str_contains($text,'clicked_start')&&str_contains($text,'Classifying terrain')&&str_contains($text,'pavement_overlap'),'Build log records selection, stages and real rejection reasons');
check(!str_contains($text,'DO-NOT-EXPORT'),'Build log excludes unapproved environment and account fields');
$boundedLog=new BeamBuildLog();for($i=0;$i<2003;$i++)$boundedLog->event('step '.$i);
check(str_contains($boundedLog->render([],[],[],[]),'"omitted_event_count": 3'),'Build log bounds progress events and explicitly records truncation');
$paintPoints=[];$paintProfiles=[];
for($i=0;$i<=40;$i++){$paintPoints[]=[$i*5,100,40];$paintProfiles[]=SweepLine\BeamLanes::infer(['lanes'=>$i<20?'3':'2','oneway'=>'yes'],'primary',1);}
$paintProfiles=BeamRoadSurface::shoulders(BeamRoadSurface::taper($paintPoints,$paintProfiles));
check(abs($paintProfiles[0]['lane_paint']['f1']['u']-1/3)<1e-8&&abs($paintProfiles[40]['lane_paint']['f1']['u']-0.5)<1e-8,'Surviving lane boundary joins the old and new normal lane positions');
check(abs($paintProfiles[40]['lane_paint']['f2']['u']-1)<1e-8&&abs($paintProfiles[40]['lane_paint']['f2']['visible'])<1e-8,'Outermost right lane closes against the right paved edge');
check($paintProfiles[20]['lane_paint']['f2']['u']>$paintProfiles[20]['lane_paint']['f1']['u'],'Taper keeps lane boundaries ordered through the merge');
$paint=BeamLanePaint::build(['points'=>$paintPoints,'profiles'=>$paintProfiles],BeamJunctionBoundary::sections($paintPoints,$paintProfiles),$paintPoints,$paintProfiles,'test');
check($paint!==null&&$paint['triangles']>0&&$paint['collision']==='None','Lane tapers produce independent visual paint without driving collision');
$paintXml=simplexml_load_string($paint['xml']);$paintXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$paintValues=array_map('floatval',preg_split('/\s+/',trim((string)$paintXml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
$z=[];for($i=2;$i<count($paintValues);$i+=3)$z[]=$paintValues[$i]+$paint['position'][2];
check(min($z)>40&&max($z)<40.01,'Paint follows pavement with only a small visual offset');
$reverseProfiles=[];for($i=0;$i<=40;$i++)$reverseProfiles[]=SweepLine\BeamLanes::infer(['lanes'=>$i<20?'3':'2','oneway'=>'yes'],'primary',1)+['legal_forward'=>false];
$reverseProfiles=BeamRoadSurface::taper($paintPoints,$reverseProfiles);
check(abs($reverseProfiles[0]['lane_paint']['f2']['u']-1/3)<1e-8&&abs($reverseProfiles[40]['lane_paint']['f2']['u'])<1e-8,'Right-hand lane merge reverses geometrically with mapped legal direction');
$twoWayProfiles=[];for($i=0;$i<=40;$i++)$twoWayProfiles[]=SweepLine\BeamLanes::infer(['lanes'=>$i<20?'4':'2'],'primary',0);
$twoWayProfiles=BeamRoadSurface::taper($paintPoints,$twoWayProfiles);
check(count(array_filter($twoWayProfiles,static fn($p)=>abs($p['lane_paint']['center']['u']-0.5)>1e-8))===0,'Two-way lane merges preserve the opposing-traffic divider');
$hidden=$paintProfiles;foreach($hidden as &$p)$p['unmarked']=true;unset($p);
check(BeamLanePaint::build(['points'=>$paintPoints,'profiles'=>$hidden],BeamJunctionBoundary::sections($paintPoints,$hidden),$paintPoints,$hidden,'test')===null,'Taper paint remains absent through intersection openings');

// Entirely synthetic short bridge; the straight primary stays on the native MeshRoad path.
$shortPlan=$largePlan;$shortPlan['name']='Generated Short Bridge';$shortPlan['points']=[[100,800],[400,800],[500,800],[1500,800]];
$shortPlan['sources'][0]['points']=[[100,800],[400,800]];$shortPlan['sources'][1]['points']=[[400,800],[500,800]];$shortPlan['sources'][2]['points']=[[500,800],[1500,800]];
$shortPlan['selection']=['original'=>['method'=>'custom','clicked_start'=>[0,0],'clicked_finish'=>[0.01,0]],'exported_start'=>[0,0],'exported_finish'=>[0.01,0]];
$shortHeights='';for($y=$largeN-1;$y>=0;$y--)for($x=0;$x<$largeN;$x++)$shortHeights.=pack('g',$x*25>400&&$x*25<500?60:100);
$shortRaster=new SweepLine\BeamRaster($largeN,$largeSide,$shortHeights,str_repeat(chr(100),$largeN*$largeN*3));
$shortRaster->provenance=['dem'=>['fixture'=>true,'sha256'=>hash('sha256',$shortHeights)]];
$shortFile=tempnam(sys_get_temp_dir(),'sl-short-diagnostics-');
try{
 $shortReport=SweepLine\BeamMap::build($shortPlan,$shortRaster,str_repeat('9',32),$shortFile,static function($m){});
 $zip=new ZipArchive();$zip->open($shortFile);$base='levels/sweepline_9999999999999999/';
 check(!$shortReport['curve_smoothing']['explicit_pavement']&&$zip->getFromName($base.'art/shapes/bridge-deck.dae')!==false,'Straight short native bridge receives a visible closed underside');
 $log=$zip->getFromName('build.log');$logData=json_decode(substr($log,strpos($log,"\n{")+1),true,512,JSON_THROW_ON_ERROR);
 check($logData['inputs']['selection']['original']['clicked_finish']===[0.01,0]&&$logData['generated_geometry']['primary']['points']&&$logData['generated_geometry']['raster_provenance']['dem']['fixture'],'ZIP build.log contains input selection, constructed geometry and raster provenance');
 check(count($logData['archive_files_before_build_log'])>10&&$logData['analysis_and_rejections']['bridge_deck_shell']['spans']===1,'ZIP build.log inventories archive files and structure decisions');
 $objects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($zip->getFromName($base.'main/MissionGroup/items.level.json'))));
 $rails=array_values(array_filter($objects,static fn($o)=>str_starts_with($o['name']??'','bridge_0_rail_')));
 check(count($rails)===2&&count(array_filter($rails,static fn($o)=>$o['class']==='TSStatic'&&$o['collisionType']==='Visible Mesh Final'))===2,'Short bridge uses two closed collision walls instead of floating MeshRoad rails');
 $deckZ=100.25-$shortReport['elevation_origin_m'];$connected=true;
 foreach($rails as $rail){
  $xml=simplexml_load_string($zip->getFromName(ltrim($rail['shapeName'],'/')));$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
  $v=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//c:source[@id="positions"]/c:float_array')[0])));$zs=[];$xs=[];$ys=[];
  for($i=0;$i<count($v);$i+=3){$xs[]=$v[$i];$ys[]=$v[$i+1];$zs[]=$v[$i+2];}
  $connected=$connected&&min($zs)<$deckZ-0.49&&max($zs)>$deckZ+1.09&&abs(min($xs)-400)<1e-6&&abs(max($xs)-500)<1e-6;
  $half=(8+2*BeamRoadSurface::PAVED_SHOULDER_M)/2;
  $connected=$connected&&(abs(min($ys)-(800-$half))<1e-6||abs(max($ys)-(800+$half))<1e-6);
 }
 check($connected,'Bridge walls intersect the slab, share the exact pavement edge and reach both mapped span ends');
 $zip->close();
}finally{@unlink($shortFile);}

check(min(array_map(static fn($p)=>$p['lane_paint']['f2']['u']-$p['lane_paint']['f1']['u'],$paintProfiles))>0.3,'Middle lane retains positive width throughout the rightmost-lane drop');
$addProfiles=[];
for($i=0;$i<=40;$i++)$addProfiles[]=SweepLine\BeamLanes::infer(['lanes'=>$i<20?'2':'3','oneway'=>'yes'],'primary',1);
$addProfiles=BeamRoadSurface::taper($paintPoints,$addProfiles);
check(abs($addProfiles[0]['lane_paint']['f2']['u']-1)<1e-8&&abs($addProfiles[40]['lane_paint']['f2']['u']-2/3)<1e-8,'Added lane opens from the outer paved edge instead of splitting a middle lane');
check($addProfiles[20]['lane_paint']['f2']['u']>$addProfiles[20]['lane_paint']['f1']['u'],'Existing lanes remain ordered when an outer lane is added');
