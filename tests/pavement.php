<?php
use SweepLine\{BeamBuildings,BeamRaster,BeamLanes,BeamPolygon,BeamPavement,BeamIntersections};
$broad=[[0,100,100],[400,100,100]];$bp=array_fill(0,2,['width_m'=>8]);$br=new BeamRaster(128,400,str_repeat(pack('g',100),16384),str_repeat(chr(100),49152));
$foot=static fn($p)=>['points'=>$p,'tags'=>['building'=>'yes']];
$long=$foot([[20,108],[100,108],[100,112],[20,112],[20,108]]);
check(count(BeamBuildings::place([$long],$broad,$bp,$br)['objects'])===1,'Long narrow building beside pavement passes actual footprint clearance instead of an oversized circle');
$l=$foot([[50,120],[60,120],[60,124],[54,124],[54,130],[50,130],[50,120]]);
check(count(BeamBuildings::place([$l],$broad,$bp,$br)['objects'])===1,'Mapped concave L-shaped building is placed');
$ring=BeamPolygon::clean($l['points']);$area=0;foreach(BeamPolygon::triangles($ring) as [$a,$b,$c])$area+=abs(BeamPolygon::cross($ring[$a],$ring[$b],$ring[$c]))/2;
check(abs($area-BeamPolygon::area($ring))<0.000001&&!BeamPolygon::inside([58,128],$ring),'Concave roof triangles preserve footprint area and do not fill the missing corner');
$one=$foot([[100,120],[110,120],[110,130],[100,130],[100,120]]);
$two=$foot([[110,120],[120,120],[120,130],[110,130],[110,120]]);
check(count(BeamBuildings::place([$one,$two],$broad,$bp,$br)['objects'])===2,'Adjacent row buildings sharing a wall are not rejected by overlapping bounding circles');
$duplicate=BeamBuildings::place([$one,$one],$broad,$bp,$br);
check(count($duplicate['objects'])===1&&$duplicate['rejection_reasons']['overlapping_building']===1,'Actual overlapping building footprints are rejected with a reason');
$onRoad=$foot([[100,98],[110,98],[110,108],[100,108],[100,98]]);
check((BeamBuildings::place([$onRoad],$broad,$bp,$br)['rejection_reasons']['pavement_overlap']??0)===1,'Buildings that actually cross pavement remain excluded');
$far=$foot([[100,190],[110,190],[110,200],[100,200],[100,190]]);
check((BeamBuildings::place([$far],$broad,$bp,$br)['rejection_reasons']['outside_roadside_range']??0)===1,'The 200-foot roadside limit remains enforced for full footprints');
$profile=BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0);
$main=[[0,100,50.25],[100,100,50.25],[400,100,50.25]];$mp=array_fill(0,3,$profile);
$prepared=BeamIntersections::prepare(['branches'=>[['points'=>[[100,100],[100,280]],'width_m'=>8]]],$main,$mp,$br,[0,0,400,400],50,[],static fn($m)=>null);
$branch=$prepared['roads'][0];$mesh=BeamPavement::build($branch,$main,$mp,'test');
$xml=simplexml_load_string($mesh['xml'],SimpleXMLElement::class,LIBXML_NONET);
check($xml!==false&&$mesh['connector'],'Side road and junction connector export as one valid surface mesh');
$positions=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//*[local-name()="source"][@id="positions"]/*[local-name()="float_array"]')[0])));
$firstSection=BeamPavement::section($branch['points'],$branch['profiles'],0);$welded=true;
foreach($firstSection as $p){$count=0;for($i=0;$i<count($positions);$i+=3)if(abs($positions[$i]+$mesh['position'][0]-$p[0])<0.000001&&abs($positions[$i+1]+$mesh['position'][1]-$p[1])<0.000001&&abs($positions[$i+2]+$mesh['position'][2]-$p[2])<0.000001)$count++;$welded=$welded&&$count===1;}
check($welded,'Connector and side road share each interface vertex once with zero position/height seam');
$normals=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//*[local-name()="source"][@id="normals"]/*[local-name()="float_array"]')[0])));
$up=true;for($i=2;$i<count($normals);$i+=3)if($normals[$i]<0.1)$up=false;
check($up,'Pavement collision has upward-facing top surfaces without tire-catching sidewalls');
$before=$main;$rings=BeamPavement::connectorRings($branch,$main,$mp);
check($main===$before&&end($rings)===BeamPavement::section($branch['points'],$branch['profiles'],0),'Connector ends use the exact side-road face while primary geometry stays unchanged');
$prepared['aprons']=[BeamPavement::contact($branch,$main,$mp)];$shapeRaster=clone $br;$mask=str_repeat("\0",16384);
BeamIntersections::shape($prepared,$main,$mp,$shapeRaster,50,$mask,static fn($m)=>null);
$final=BeamIntersections::conform($prepared,$shapeRaster,50,static fn($m)=>null,$main,$mp,$mask);
check($final['roads'][0]['points']===$branch['points']&&abs(100.25-$shapeRaster->height(100,200)-0.05)<0.01,'Final native side-road shoulders meet unchanged pavement at the five-centimetre target');
$marking=BeamIntersections::markingSections($main,$mp,[['along'=>100,'radius'=>4]]);
$blank=0;$total=0;foreach($marking['points'] as $i=>$p)if($i){$d=hypot($p[0]-$marking['points'][$i-1][0],$p[1]-$marking['points'][$i-1][1]);$total+=$d;if(!empty($marking['profiles'][$i]['unmarked']))$blank+=$d;}
check(abs($blank-8)<0.00001&&abs($total-400)<0.00001,'Road lines stop exactly at the junction interval rather than a padded multi-node gap');

$denseMain=[];for($i=0;$i<=1000;$i++){$x=$i/10;$denseMain[]=[$x,200-$x/2,50+$x*0.04];}
$denseProfiles=array_fill(0,count($denseMain),$profile);$denseJoin=BeamIntersections::nearest([50,175],$denseMain,$denseProfiles);
$denseBranch=['points'=>[[60,182,52.2],[62,182,52.2]],'profiles'=>[$profile,$profile],'join'=>$denseJoin];
check((bool)BeamPavement::connectorRings($denseBranch,$denseMain,$denseProfiles),'Connector searches a distance-bounded main-road window instead of failing on densely sampled geometry');

$greenRaster=clone $br;$greenRaster->colors=str_repeat(chr(65),16384).str_repeat(chr(110),16384).str_repeat(chr(55),16384);
$largeBuilding=['position'=>[200,140,100],'radius'=>60];
$vegetation=SweepLine\BeamScenery::place($broad,$bp,$greenRaster,12345,static fn($m)=>null,[-85,40],[$largeBuilding]);
$clear=(bool)$vegetation['objects'];foreach($vegetation['objects'] as $object)if(hypot($object['position'][0]-200,$object['position'][1]-140)<60+SweepLine\BeamScenery::radius($object['kind'],$object['scale'],$object['asset']))$clear=false;
check($clear,'Large mapped buildings reserve all occupied spatial cells so trees cannot appear through their roofs');
