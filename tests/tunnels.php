<?php
// Synthetic tunnel/terrain fixtures; no provider calls.
use SweepLine\{BeamTunnels,BeamRaster,BeamMap};
$tRoad=[[40,160],[120,160],[200,160],[280,160]];
$tSource=[['points'=>[[120,160],[200,160]],'tags'=>['tunnel'=>'yes'],'highway'=>'secondary','direction'=>0]];
$tSpans=BeamTunnels::spans($tRoad,$tSource);
check(count($tSpans)===1&&$tSpans[0]['start_m']===80.0&&$tSpans[0]['length_m']===80.0,'Tunnel overlap uses chainage, not entire selected road');
check(BeamTunnels::spans($tRoad,[['points'=>[[120,180],[200,180]],'tags'=>['tunnel'=>'yes']]])===[],'Off-route tunnel does not affect export');
check(BeamTunnels::spans($tRoad,[['points'=>[[160,120],[160,200]],'tags'=>['tunnel'=>'yes']]])===[],'Crossing tunnel is not selected');
check(count(BeamTunnels::spans(array_reverse($tRoad),$tSource))===1,'Tunnel detection respects reversed road orientation');
check(rejects(fn()=>BeamTunnels::spans([[120,160],[200,160]],$tSource)),'Partial tunnel without approaches is rejected');
$badSource=$tSource;$badSource[0]['tags']['tunnel']='building_passage';
check(rejects(fn()=>BeamTunnels::spans($tRoad,$badSource)),'Unsupported passage gives explicit rejection');
$clearSource=$tSource;$clearSource[0]['tags']['maxheight']='12 ft';
check(abs(BeamTunnels::spans($tRoad,$clearSource)[0]['clearance_m']-3.9076)<0.0001,'Posted imperial tunnel clearance is converted and labelled estimated');
$tn=64;$tside=320.;$th='';
for($y=$tn-1;$y>=0;$y--)for($x=0;$x<$tn;$x++)$th.=pack('g',100+max(0,20-abs($x*5-160)/2));
$tRaster=new BeamRaster($tn,$tside,$th,str_repeat(chr(90),$tn*$tn*3));
$tPoints=[];$tChain=[];$tHeights=[];
for($x=40;$x<=280;$x+=2){$tPoints[]=[$x,160];$tChain[]=$x-40.;$tHeights[]=$tRaster->height($x,160);}
$tSamples=BeamTunnels::samples($tPoints,$tChain,$tHeights,$tSpans,$tRaster);
check(abs($tSamples[60]-100)<0.001&&$tHeights[60]>119,'Tunnel floor follows approaches rather than hilltop DEM');
$tMeshRoad=array_map(static fn($p)=>[$p[0],$p[1],10.],$tPoints);
$tProfiles=array_fill(0,count($tMeshRoad),['width_m'=>8.]);
$tBefore=$tRaster->heights;
$tBuilt=BeamTunnels::build($tMeshRoad,$tProfiles,$tSpans,$tRaster,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){});
check($tBuilt['report']['hole_cells']>0&&substr_count($tBuilt['layers'],chr(255))===$tBuilt['report']['hole_cells'],'Tunnel writes native collision-hole layer bytes');
check($tRaster->heights===$tBefore,'Tunnel keeps original mountain elevation rather than digging an open trench');
check(ord($tBuilt['layers'][0])===0,'Terrain outside tunnel patch remains unchanged');
check(count($tBuilt['objects'])>0&&count($tBuilt['portal_exclusions'])===2,'Tunnel includes estimated lights and two portal scenery exclusions');
$tXml=simplexml_load_string($tBuilt['terrain']);$tXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$tPositions=$tXml->xpath('//c:source/c:float_array')[0];
$tValues=array_map('floatval',preg_split('/\s+/',trim((string)$tPositions)));
$tTop=false;$tIntrusion=false;
for($i=0;$i<count($tValues);$i+=3){[$x,$y,$z]=array_slice($tValues,$i,3);if($x>145&&$x<175&&abs($y-160)<8&&$z>20)$tTop=true;if($x>122&&$x<198&&abs($y-160)<4.5&&$z<14.79)$tIntrusion=true;}
check($tTop,'Replacement terrain retains overburden above tunnel roof');
check(!$tIntrusion,'Replacement terrain does not close tunnel drivable bore');
$tShell=simplexml_load_string($tBuilt['shell']);check($tShell!==false,'Tunnel concrete interior is valid COLLADA');
$tPlan=['terrain_style'=>'aerial','name'=>'Synthetic Tunnel','alternate_names'=>[],'resolution'=>$tn,'padding_miles'=>0.5,'epsg'=>32611,'bounds'=>[0,0,320,320],'points'=>$tRoad,'sources'=>[
 ['points'=>[[40,160],[120,160]],'tags'=>['lanes'=>'2'],'highway'=>'secondary','direction'=>0],
 $tSource[0],
 ['points'=>[[200,160],[280,160]],'tags'=>['lanes'=>'2'],'highway'=>'secondary','direction'=>0]
],'center'=>[-119.8,39.5],'oneway'=>false];
$tFile=tempnam(sys_get_temp_dir(),'sweepline-tunnel-');
try{
 $tReport=BeamMap::build($tPlan,$tRaster,str_repeat('9',32),$tFile,static function($m){});
 $tZip=new ZipArchive();$tZip->open($tFile);$tBase='levels/sweepline_9999999999999999/';
 check($tZip->getFromName($tBase.'art/tunnels/interior.dae')!==false&&$tZip->getFromName($tBase.'art/tunnels/terrain-patch.dae')!==false,'Full tunnel export includes collision interior and preserved terrain patch');
 $tTerrain=$tZip->getFromName($tBase.'theTerrain.ter');$tLayers=substr($tTerrain,5+2*$tn*$tn,$tn*$tn);
 check(substr_count($tLayers,chr(255))===$tReport['tunnel_data']['hole_cells'],'Terrain ZIP stores actual hole map alongside replacement collision mesh');
 check(count($tReport['tunnels'])===1&&$tReport['tunnel_data']['estimated'],'Export report labels tunnel engineering estimates');
 $tZip->close();
}finally{@unlink($tFile);}

$tNormals=$tShell->xpath('//*[local-name()="source" and @id="normals"]/*[local-name()="float_array"]');
$tShellPoints=$tShell->xpath('//*[local-name()="source" and @id="positions"]/*[local-name()="float_array"]');
$tNv=array_map('floatval',preg_split('/\s+/',trim((string)$tNormals[0])));
$tPv=array_map('floatval',preg_split('/\s+/',trim((string)$tShellPoints[0])));
$tRoofNormal=false;$tWalkNormal=false;
for($i=0;$i<count($tPv);$i+=3){if(abs($tPv[$i+2]-14.8)<0.001&&$tNv[$i+2]<-0.99)$tRoofNormal=true;if(abs($tPv[$i+2]-9.98)<0.001&&$tNv[$i+2]>0.99)$tWalkNormal=true;}
check($tRoofNormal&&$tWalkNormal,'Tunnel ceiling collision faces inward and walkways face upward');
$tWide=array_fill(0,count($tMeshRoad),['width_m'=>21.]);
try{BeamTunnels::build($tMeshRoad,$tWide,$tSpans,$tRaster,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){});check(false,'Wide tunnel rejected');}
catch(RuntimeException $e){check(str_contains($e->getMessage(),'20 metres'),'Wide tunnel reports supported pavement limit');}
$tSteep=new BeamRaster($tn,$tside,str_repeat(pack('g',100),$tn*$tn),str_repeat(chr(90),$tn*$tn*3));
for($y=0;$y<$tn;$y++)for($x=0;$x<$tn;$x++)$tSteep->put($x,$y,100+$x*5*.2);
try{BeamTunnels::samples($tPoints,$tChain,$tHeights,$tSpans,$tSteep);check(false,'Steep tunnel rejected');}
catch(RuntimeException $e){check(str_contains($e->getMessage(),'12%'),'Unreliable approach grade is explicitly rejected');}
$tCombined=$tSource;$tCombined[]=['points'=>[[65,160],[90,160]],'tags'=>['bridge'=>'yes']];
check(rejects(fn()=>BeamTunnels::spans($tRoad,$tCombined)),'Overlapping bridge and tunnel approaches are rejected');
