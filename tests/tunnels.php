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
$tDeferred=BeamTunnels::build($tMeshRoad,$tProfiles,$tSpans,$tRaster,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){},[],0,true);
check($tDeferred['terrain']===null&&!isset($tDeferred['terrain_surface']['uv'])&&count($tDeferred['terrain_surface']['faces'])>0,'Map export defers redundant full tunnel XML and raw UV buffers');
check($tDeferred['report']['terrain_patch_triangles']===$tBuilt['report']['terrain_patch_triangles'],'Deferred tunnel serialization retains the exact same collision geometry');
unset($tDeferred);

check(ord($tBuilt['layers'][0])===0,'Terrain outside tunnel patch remains unchanged');
check(count($tBuilt['objects'])>0&&count($tBuilt['portal_exclusions'])===2,'Tunnel includes estimated lights and two portal scenery exclusions');
$tXml=simplexml_load_string($tBuilt['terrain']);$tXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$tPositions=$tXml->xpath('//c:source/c:float_array')[0];
$tValues=array_map('floatval',preg_split('/\s+/',trim((string)$tPositions)));
$tTop=false;$tIntrusion=false;
for($i=0;$i<count($tValues);$i+=3){[$x,$y,$z]=array_slice($tValues,$i,3);if($x>145&&$x<175&&abs($y-160)<8&&$z>20)$tTop=true;if($x>122&&$x<198&&abs($y-160)<4.5&&$z>10.001&&$z<14.79)$tIntrusion=true;}
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
 $patchDoc=simplexml_load_string($tZip->getFromName($tBase.'art/tunnels/terrain-patch.dae'));$patchDoc->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
 check(count($patchDoc->xpath('//c:triangles/c:input[@semantic="TEXCOORD" and @set="1"]'))>0,'Exported tunnel terrain has independent detail texture UVs');
 $patchMaterials=json_decode($tZip->getFromName($tBase.'art/tunnels/main.materials.json'),true,512,JSON_THROW_ON_ERROR);$hasSharedGround=false;
 foreach($patchMaterials as $key=>$mat)if(str_contains($key,'_tunnel_ground_'))$hasSharedGround=$hasSharedGround||($mat['version']===1.5&&isset($mat['Stages'][0]['baseColorMap']));
 check($hasSharedGround,'Exported tunnel terrain shares the native-ground PBR material pipeline');


 check($tZip->getFromName($tBase.'art/tunnels/concrete.color.png')!==false && $tZip->getFromName($tBase.'art/tunnels/light-fixture.dae')!==false,'Tunnel archive includes its concrete texture and visible fixture mesh');
 $roofTextured=false;$lensLit=false;foreach($patchMaterials as $key=>$mat){if(str_ends_with($key,'_tunnel_roof'))$roofTextured=isset($mat['Stages'][0]['diffuseMap']);if(str_ends_with($key,'_fixture_lens'))$lensLit=$mat['Stages'][0]['emissive']??false;}
 check($roofTextured&&$lensLit,'Tunnel roof is textured and fixture lenses are emissive');
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

$tFlat=new BeamRaster($tn,$tside,str_repeat(pack('g',100),$tn*$tn),str_repeat(chr(90),$tn*$tn*3));
$tFlatRoad=array_map(static fn($p)=>[$p[0],$p[1],10.25],$tPoints);
$tPreserved=BeamTunnels::build($tFlatRoad,$tProfiles,$tSpans,$tFlat,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){});
$tPreservedXml=simplexml_load_string($tPreserved['terrain']);$tPreservedXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$tGroundValues=array_map('floatval',preg_split('/\s+/',trim((string)$tPreservedXml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
$tOutsideUnchanged=true;$tFloorSupported=false;for($i=0;$i<count($tGroundValues);$i+=3){[$x,$y,$z]=array_slice($tGroundValues,$i,3);if(abs($y-160)>5&&abs($z-10)>0.00001)$tOutsideUnchanged=false;if($x>130&&$x<190&&abs($y-160)<4.75&&abs($z-10.2)<0.00001)$tFloorSupported=true;}
check($tOutsideUnchanged&&$tFloorSupported,'Tunnel support raises low corridor ground while preserving terrain outside its shoulders');
$tCutRoad=array_map(static fn($p)=>[$p[0],$p[1],9.],$tPoints);
$tCut=BeamTunnels::build($tCutRoad,$tProfiles,$tSpans,$tFlat,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){});
$tCutXml=simplexml_load_string($tCut['terrain']);$tCutXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$tCutValues=array_map('floatval',preg_split('/\s+/',trim((string)$tCutXml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
$tCoverage=static function(array $values,float $x,float $y):?float{
 for($i=0;$i<count($values);$i+=9){
  $a=array_slice($values,$i,3);$b=array_slice($values,$i+3,3);$c=array_slice($values,$i+6,3);
  $den=($b[1]-$c[1])*($a[0]-$c[0])+($c[0]-$b[0])*($a[1]-$c[1]);if(abs($den)<1e-10)continue;
  $u=(($b[1]-$c[1])*($x-$c[0])+($c[0]-$b[0])*($y-$c[1]))/$den;
  $v=(($c[1]-$a[1])*($x-$c[0])+($a[0]-$c[0])*($y-$c[1]))/$den;$w=1-$u-$v;
  if(min($u,$v,$w)>=-1e-8)return $u*$a[2]+$v*$b[2]+$w*$c[2];
 }return null;
};
foreach([[90,164.4],[110,155.6],[210,164.4],[230,155.6]] as [$x,$y]){
 $height=$tCoverage($tCutValues,$x,$y);
 check($height!==null&&abs($height-8.95)<0.00001,'Portal-side ground has textured collision surface at '.$x.','.$y);
}
check($tCut['report']['excavated_floor_pieces']>0&&$tCut['report']['floor_clearance_m']===0.05,'Tunnel report records replacement ground beneath excavation');

$tProjectedArea=0.;
for($i=0;$i<count($tCutValues);$i+=9)$tProjectedArea+=abs(SweepLine\BeamPolygon::cross(array_slice($tCutValues,$i,3),array_slice($tCutValues,$i+3,3),array_slice($tCutValues,$i+6,3)))/2;
check(abs($tProjectedArea-$tCut['report']['hole_cells']*($tside/$tn)**2)<0.0001,'Replacement terrain covers the full native-hole area after excavation');

$tWallCrosses=static function(array $values,int $axis,float $s,float $height,float $lo,float $hi):bool{
 $other=$axis===0?1:0;
 for($i=0;$i<count($values);$i+=9){
  $a=array_slice($values,$i,3);$b=array_slice($values,$i+3,3);$c=array_slice($values,$i+6,3);
  $den=($b[2]-$c[2])*($a[$other]-$c[$other])+($c[$other]-$b[$other])*($a[2]-$c[2]);if(abs($den)<1e-10)continue;
  $u=(($b[2]-$c[2])*($s-$c[$other])+($c[$other]-$b[$other])*($height-$c[2]))/$den;
  $v=(($c[2]-$a[2])*($s-$c[$other])+($a[$other]-$c[$other])*($height-$c[2]))/$den;$w=1-$u-$v;
  $at=$u*$a[$axis]+$v*$b[$axis]+$w*$c[$axis];
  if(min($u,$v,$w)>=-1e-8&&$at>=$lo&&$at<=$hi)return true;
 }return false;
};
foreach([90,110,210,230] as $x)check($tWallCrosses($tCutValues,1,$x,9.5,164,166),'Tunnel excavation connects vertically to hillside at '.$x);
check(!$tWallCrosses($tCutValues,0,160,9.5,85,235),'Adjacent excavation segments leave no blocking walls across the driving corridor');
check($tPreserved['report']['excavation_wall_triangles']===0,'Unexcavated lower ground does not acquire vertical terrain walls');
check($tCut['report']['excavation_wall_triangles']>0,'Tunnel report records vertical terrain stitching');

check($tWallCrosses($tValues,0,164.85,12,110,145),'Portal rim joins the hillside beside the usable tunnel opening');
check(!$tWallCrosses($tValues,0,160,12,110,145),'Portal rim leaves the centre of the driving opening clear');

$tCurvedPoints=[[40,160,8.5],[100,160,8.7],[140,170,9],[180,190,9.2],[220,210,9.4],[280,210,9.5]];
$tCurvedWidth=[8,9,10,9,8,8];
$tWallIntrusions=static function(array $built,array $road,array $profiles):int{
 $xml=simplexml_load_string($built['terrain']);$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
 $values=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
 $count=0;
 for($i=count($values)-9*$built['report']['excavation_wall_triangles'];$i<count($values);$i+=9){
  $a=array_slice($values,$i,3);$b=array_slice($values,$i+3,3);$c=array_slice($values,$i+6,3);$samples=[$a,$b,$c];
  foreach([[1/3,1/3,1/3],[0.5,0.5,0],[0.5,0,0.5],[0,0.5,0.5]] as [$u,$v,$w])$samples[]=[$a[0]*$u+$b[0]*$v+$c[0]*$w,$a[1]*$u+$b[1]*$v+$c[1]*$w,$a[2]*$u+$b[2]*$v+$c[2]*$w];
  $bad=false;foreach($samples as $p)for($j=1;$j<count($road);$j++){
   $a=$road[$j-1];$b=$road[$j];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$d=$dx*$dx+$dy*$dy;if($d<1e-9)continue;
   $t=max(0,min(1,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$d));
   $distance=hypot($p[0]-$a[0]-$dx*$t,$p[1]-$a[1]-$dy*$t);$z=$a[2]+($b[2]-$a[2])*$t;
   $half=($profiles[$j-1]['width_m']*(1-$t)+$profiles[$j]['width_m']*$t)/2;
   if($distance<$half-0.1&&$p[2]>$z+0.025&&$p[2]<$z+4.65){$bad=true;break;}
  }
  if($bad)$count++;
 }return $count;
};
foreach([false,true] as $reverse){
 $points=$reverse?array_reverse($tCurvedPoints):$tCurvedPoints;$widths=$reverse?array_reverse($tCurvedWidth):$tCurvedWidth;
 $profiles=array_map(static fn($width)=>['width_m'=>$width],$widths);
 $length=0.;for($i=1;$i<count($points);$i++)$length+=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
 $span=['start_m'=>$reverse?$length-160:80,'end_m'=>$reverse?$length-80:160,'clearance_m'=>4.8];
 $built=BeamTunnels::build($points,$profiles,[$span],$tFlat,90,str_repeat(chr(0),$tn*$tn),'test_tunnel','test_patch',static function($m){});
 check($tWallIntrusions($built,$points,$profiles)===0,'All connecting faces stay outside a curved, sloped, varying-width driving corridor; reverse='.(int)$reverse);
 check($built['report']['excavation_wall_triangles']>0,'Curved tunnel retains exterior terrain connections; reverse='.(int)$reverse);
}

$fixtureObjects=array_values(array_filter($tBuilt['objects'],static fn($o)=>$o['class']==='TSStatic'));
$lightObjects=array_values(array_filter($tBuilt['objects'],static fn($o)=>$o['class']==='PointLight'));
check(count($fixtureObjects)===count($lightObjects)&&count($fixtureObjects)>0,'Every tunnel light has one visible fixture');
$fixtureFits=true;foreach($fixtureObjects as $o)$fixtureFits=$fixtureFits&&$o['position'][2]-0.16>10+$tSpans[0]['clearance_m']-0.2&&$o['collisionType']==='None';
check($fixtureFits,'Tunnel fixtures remain mounted above driving clearance without collision geometry');
check(str_contains($tBuilt['shell'],'test_tunnel_roof'),'Tunnel ceiling uses its own textured material');
