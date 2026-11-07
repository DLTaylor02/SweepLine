<?php
use SweepLine\{BeamBuildings,BeamRaster,BeamLanes,BeamPolygon,BeamPavement,BeamIntersections,BeamRoadSurface};
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

$curvedMain=[[0,100,20],[40,104,21],[80,100,23],[120,115,24]];
$curvedProfiles=array_fill(0,count($curvedMain),$profile);
$curvedSections=SweepLine\BeamJunctionBoundary::sections($curvedMain,$curvedProfiles);
$edgeStart=$curvedSections[1][0];$edgeEnd=$curvedSections[2][0];
$edgeMid=array_map(static fn($a,$b)=>($a+$b)/2,$edgeStart,$edgeEnd);
$hit=SweepLine\BeamJunctionBoundary::hit([$edgeMid[0],$edgeMid[1]+20,0],[0,1],$curvedMain,$curvedProfiles,['along'=>60]);
check($hit!==null&&hypot($hit[0]-$edgeMid[0],$hit[1]-$edgeMid[1])<1e-7&&abs($hit[2]-$edgeMid[2])<1e-7,'Curved-road connector hits the actual ribbon edge and exact edge elevation');
$overlapVertices=[[50,100,30],[70,100,30],[70,120,30],[50,120,30]];
$overlapTriangles=[['v'=>[0,1,2],'uv'=>[[0,0],[1,0],[1,1]],'material'=>'fixture'],['v'=>[0,2,3],'uv'=>[[0,0],[1,1],[0,1]],'material'=>'fixture']];
[$trimmedVertices,$trimmedTriangles]=SweepLine\BeamJunctionBoundary::trim($overlapVertices,$overlapTriangles,$curvedMain,$curvedProfiles);
$overlapArea=0;$contactCount=0;$contactError=0;
foreach($trimmedTriangles as $t){
    $pieces=[array_map(static fn($i)=>$trimmedVertices[$i],$t['v'])];$original=abs(BeamPolygon::cross(...$pieces[0]))/2;
    foreach(SweepLine\BeamJunctionBoundary::cutters($curvedMain,$curvedProfiles,$trimmedVertices) as $c){
        $next=[];foreach($pieces as $piece)array_push($next,...SweepLine\BeamJunctionBoundary::subtract($piece,$c));$pieces=$next;
    }
    $left=0;foreach($pieces as $piece)for($j=1;$j<count($piece)-1;$j++)$left+=abs(BeamPolygon::cross($piece[0],$piece[$j],$piece[$j+1]))/2;
    $overlapArea+=max(0,$original-$left);
}
foreach($trimmedVertices as $p){
    $dx=$edgeEnd[0]-$edgeStart[0];$dy=$edgeEnd[1]-$edgeStart[1];$t=(($p[0]-$edgeStart[0])*$dx+($p[1]-$edgeStart[1])*$dy)/($dx*$dx+$dy*$dy);
    if($t>=0&&$t<=1&&hypot($p[0]-$edgeStart[0]-$t*$dx,$p[1]-$edgeStart[1]-$t*$dy)<1e-6){$contactCount++;$contactError=max($contactError,abs($p[2]-$edgeStart[2]-$t*($edgeEnd[2]-$edgeStart[2])));}
}
check(count($trimmedTriangles)>0&&$overlapArea<1e-7,'Clipping removes raised branch pavement from the primary footprint while retaining the side road');
check($contactCount>0&&$contactError<1e-7,'Clipped contact vertices have the exact main-road elevation, not the raised branch elevation');
$rendered=BeamIntersections::markingSections($curvedMain,$curvedProfiles,[['along'=>60,'radius'=>4]]);
$paintSections=SweepLine\BeamJunctionBoundary::renderSections($curvedMain,$curvedProfiles,$rendered['points']);
$unchanged=true;
foreach($rendered['points'] as $i=>$p)foreach($curvedMain as $j=>$original)if(hypot($p[0]-$original[0],$p[1]-$original[1])<1e-7)$unchanged=$unchanged&&$paintSections[$i]===$curvedSections[$j];
check($unchanged&&count($paintSections)>count($curvedMain),'Paint-only subdivisions retain original cross-sections rather than recomputing and shifting the road edge');
$primaryMesh=BeamPavement::build($rendered,$curvedMain,$curvedProfiles,'fixture',$paintSections);
check(!$primaryMesh['connector']&&$primaryMesh['triangles']>0,'Explicit primary pavement uses the same boundary definition without adding a second junction surface');

$touchTriangle=[[0,0,0],[10,0,0],[0,10,0]];
$outsideTouch=[[0,0,5],[10,0,5],[5,-4,5]];
$pieces=SweepLine\BeamJunctionBoundary::subtract($outsideTouch,$touchTriangle);$area=0;
foreach($pieces as $piece)for($i=1;$i<count($piece)-1;$i++)$area+=abs(BeamPolygon::cross($piece[0],$piece[$i],$piece[$i+1]))/2;
check(abs($area-20)<1e-8,'Boundary-coincident vertices preserve all adjacent outside pavement instead of dropping a touching triangle');
$cuts=SweepLine\BeamJunctionBoundary::cutters($curvedMain,$curvedProfiles,$trimmedVertices);$outside=true;
foreach($trimmedTriangles as $t){
    $p=array_map(static fn($id)=>$trimmedVertices[$id],$t['v']);
    $samples=[array_map(static fn($a,$b,$c)=>($a+$b+$c)/3,...$p)];
    foreach([0,1,2] as $i)$samples[]=array_map(static fn($a,$b)=>($a+$b)/2,$p[$i],$p[($i+1)%3]);
    foreach($samples as $sample)foreach($cuts as $cut){
        $inside=true;foreach([0,1,2] as $i)if(BeamPolygon::cross($cut[$i],$cut[($i+1)%3],$sample)<=1e-7)$inside=false;
        if($inside)$outside=false;
    }
}
check($outside,'Independent half-plane checks find no retained branch triangle centres or edges inside the primary pavement');

$tMark=BeamIntersections::markingSections($main,$mp,[['along'=>100,'radius'=>4,'side'=>'left']]);
$tProfile=array_values(array_filter($tMark['profiles'],static fn($p)=>!empty($p['unmarked'])))[0];
$tImage=imagecreatefromstring(SweepLine\BeamPng::road($tProfile));
check(!empty($tProfile['keep_edge_right'])&&empty($tProfile['keep_edge_left'])&&(imagecolorat($tImage,255,128)&0xffffff)===0xebebe1&&(imagecolorat($tImage,0,128)&0xffffff)!==0xebebe1,'T-junction preserves the far-side white line while opening the entrance edge');
$xMark=BeamIntersections::markingSections($main,$mp,[['along'=>100,'radius'=>4,'side'=>'left'],['along'=>100,'radius'=>4,'side'=>'right']]);
$xProfile=array_values(array_filter($xMark['profiles'],static fn($p)=>!empty($p['unmarked'])))[0];
check(empty($xProfile['keep_edge_right'])&&empty($xProfile['keep_edge_left'])&&BeamPavement::key($xProfile)!==BeamPavement::key($tProfile),'Opposing junction arms suppress both edges and cannot reuse the T-junction paint material');
$edgeXml=simplexml_load_string($mesh['fascia_xml'],SimpleXMLElement::class,LIBXML_NONET);
check($edgeXml!==false&&$mesh['visual_edge_depth_m']===0.18,'Pavement has a separate visual edge mesh while its top collision geometry remains unchanged');
$supportRaster=new BeamRaster(64,256,str_repeat(pack('g',100.2),4096),str_repeat(chr(100),12288));
$supportRoad=[[10,100,100.25],[240,100,100.25]];$supportProfiles=BeamRoadSurface::shoulders(array_fill(0,2,$profile));
$lowBranch=['name'=>'low_branch','points'=>[[100,108,95],[100,190,95]],'profiles'=>array_fill(0,2,$profile)];
$unprotected=clone $supportRaster;
BeamRoadSurface::clearTerrain($lowBranch['points'],$lowBranch['profiles'],$unprotected,0,static fn($m)=>null,str_repeat("\0",4096),true);
$fixed=BeamIntersections::conform(['roads'=>[$lowBranch],'aprons'=>[],'junctions'=>[],'skipped'=>0],$supportRaster,0,static fn($m)=>null,$supportRoad,$supportProfiles,str_repeat("\0",4096));
check($unprotected->height(100,104.6)<99.7&&abs($supportRaster->height(100,104.6)-100.2)<0.001,'Regression reproduces branch excavation under the main edge and verifies full-cell protection preserves its terrain support');
check($fixed['final_clearance']['limited_branches']===1&&$fixed['roads']===[],'A side road incompatible with protected main-road terrain is omitted instead of exposed through an excavated gap');
$bounded=new BeamRaster(32,128,str_repeat(pack('g',105),1024),str_repeat(chr(100),3072));
$boundedResult=BeamRoadSurface::clearTerrain([[20,60,100],[100,60,100]],array_fill(0,2,$profile),$bounded,0,static fn($m)=>null,str_repeat("\0",1024),true,0.5);
check($boundedResult['maximum_cut_m']<=0.500001&&$boundedResult['clamped_vertices']>0,'Secondary clearance cannot excavate a terrain vertex by more than its explicit per-pass cut budget');

$pairedProfiles=BeamRoadSurface::shoulders(array_fill(0,2,$profile));
$aRoad=['name'=>'side-a','points'=>[[20,100,10],[220,100,10]],'profiles'=>$pairedProfiles];
$bRoad=['name'=>'side-b','points'=>[[20,114,5],[220,114,5]],'profiles'=>$pairedProfiles];
$basePair=new BeamRaster(256,256,str_repeat(pack('g',0),65536),str_repeat(chr(100),196608));
$oldPair=clone $basePair;$pairMask=str_repeat("\0",65536);
BeamRoadSurface::blendTerrain($aRoad['points'],$pairedProfiles,$oldPair,0,$pairMask,static fn($m)=>null);
BeamRoadSurface::blendTerrain($bRoad['points'],$pairedProfiles,$oldPair,0,$pairMask,static fn($m)=>null);
$pairMain=[[10,20,0.05],[240,20,0.05]];$pairMainProfiles=array_fill(0,2,$profile);
$paired=['roads'=>[$aRoad,$bRoad],'aprons'=>[],'junctions'=>[],'skipped'=>0];$pairRaster=clone $basePair;
$pairReport=BeamIntersections::shape($paired,$pairMain,$pairMainProfiles,$pairRaster,0,$pairMask,static fn($m)=>null);
$paired=BeamIntersections::conform($paired,$pairRaster,0,static fn($m)=>null,$pairMain,$pairMainProfiles,$pairMask);
$half=$pairedProfiles[0]['width_m']/2;
check($oldPair->height(100,100+$half)<9.8,'Regression reproduces a later side-road blend lowering an earlier road edge');
$matched=true;foreach([[100,10],[114,5]] as [$y,$z])foreach([-1,1] as $sign)$matched=$matched&&abs($pairRaster->height(100,$y+$sign*$half)-($z-0.05))<0.001;
check($matched&&count($paired['roads'])===2,'Both side roads retain the same native terrain contact at their full paved edges after every clearance pass');
$reversePair=clone $basePair;BeamIntersections::shape(['roads'=>[$bRoad,$aRoad],'aprons'=>[]],$pairMain,$pairMainProfiles,$reversePair,0,$pairMask,static fn($m)=>null);
check(abs($reversePair->height(100,100+$half)-$pairRaster->height(100,100+$half))<0.001&&abs($reversePair->height(100,114-$half)-$pairRaster->height(100,114-$half))<0.001,'Side-road ordering cannot overwrite another road shoulder');
check($pairRaster->height(100,90)>0&&$pairRaster->height(100,90)<9.95&&$pairRaster->height(100,80)===0.0&&$pairRaster->height(100,20)===0.0,'Side shoulders blend gradually to original ground while leaving distant terrain and primary support unchanged');
check($pairReport[0]['shoulders']['surface_count']===2,'Terrain report identifies the shared side-road corridor blend');


$heightMain=[[0,100,50],[100,100,50]];$heightProfiles=array_fill(0,2,['width_m'=>10]);
$heightVertices=[[20,102,52],[80,102,52],[80,112,52],[20,112,52]];
$heightFaces=[['v'=>[0,1,2],'uv'=>[[0,0],[1,0],[1,1]],'material'=>'test'],['v'=>[0,2,3],'uv'=>[[0,0],[1,1],[0,1]],'material'=>'test']];
[$unweldedVertices,$unweldedFaces]=SweepLine\BeamJunctionBoundary::trim($heightVertices,$heightFaces,$heightMain,$heightProfiles,null,false);
check(count($unweldedFaces)>0&&max(array_map(static fn($p)=>abs($p[2]-52),$unweldedVertices))<1e-8,'Independent overlapping pavement preserves its original height at every new clip vertex');
[$weldedVertices,$weldedFaces]=SweepLine\BeamJunctionBoundary::trim($heightVertices,$heightFaces,$heightMain,$heightProfiles);
check(abs(min(array_column($weldedVertices,2))-50)<1e-8&&abs(max(array_column($weldedVertices,2))-52)<1e-8,'True-junction clipping retains its default primary-edge height welding');

(function(): void {
    $main=[[0,0,0],[100,0,0]];$p=\SweepLine\BeamLanes::infer(['lanes'=>'2'],'secondary',0);$p['width_m']=10.;
    $branch=['points'=>[[0,6,0],[20,6,0],[40,6,0],[60,6,0],[80,6,0],[100,6,0]],'profiles'=>array_fill(0,6,$p)];
    $mesh=\SweepLine\BeamPavement::build($branch,$main,[$p,$p],'fin_fixture',null,[['road'=>$main,'profiles'=>[$p,$p],'weld_boundary'=>false]]);
    $doc=simplexml_load_string($mesh['fascia_xml']);$v=$doc->xpath('//*[local-name()="source" and @id="positions"]/*[local-name()="float_array"]');
    $values=array_map('floatval',preg_split('/\s+/',trim((string)$v[0])));$valid=(bool)$values;
    for($i=0;$i<count($values);$i+=3){$x=$values[$i]+$mesh['position'][0];$y=$values[$i+1]+$mesh['position'][1];$valid=$valid&&(abs($x)<1e-6||abs($x-100)<1e-6||abs($y-1)<1e-6||abs($y-11)<1e-6);}
    check($valid,'Clipped road interiors never receive vertical visual fins');
})();
