<?php
use SweepLine\{BeamGroundMaterials,BeamMedianSurface,BeamRaster,BeamTerrain,BeamPolygon,BeamTunnelClearance};
$nativeMat=BeamTerrain::material('native',0,'/levels/test/',16,2);
$groundMat=BeamGroundMaterials::material('ground',0,'/levels/test/','stock',8);
check($groundMat['Stages'][0]['baseColorMap']===$nativeMat['baseColorBaseTex'],'Replacement terrain shares native palette tint texture');
check($groundMat['Stages'][0]['detailMap']===$nativeMat['baseColorDetailTex']&&$groundMat['Stages'][0]['detailNormalMap']===$nativeMat['normalDetailTex'],'Replacement terrain shares native stock color and normal detail');
check($groundMat['Stages'][0]['detailBaseColorMapStrength']===$nativeMat['baseColorDetailStrength'][0]&&$groundMat['Stages'][0]['detailNormalMapStrength']===$nativeMat['normalDetailStrength'][0],'Replacement terrain retains native bounded grass detail strengths');
check($groundMat['Stages'][0]['detailMapUseUV']===1&&$groundMat['Stages'][0]['detailScale']===[0.125,0.125],'Stock mesh detail uses world-scale secondary UVs');
$aerialMat=BeamGroundMaterials::material('ground',5,'/levels/test/','aerial',8);
check(str_ends_with($aerialMat['Stages'][0]['baseColorMap'],'aerial.color.png')&&!isset($aerialMat['Stages'][0]['detailMap']),'Aerial-only terrain keeps its original fallback material');
$materialRaster=new BeamRaster(4,40,str_repeat(pack('g',0),16),str_repeat(chr(100),48));
$materialLayers=str_repeat(chr(0),16);$materialLayers[1*4+1]=chr(7);
$materialSurface=['vertices'=>[[1,11,3],[19,11,3],[19,19,3]],'faces'=>[[0,1,2]]];
$textured=BeamGroundMaterials::prepare($materialSurface,$materialLayers,$materialRaster,'stock','ground','/levels/test/',static fn($m)=>null);
check(isset($textured['materials']['ground_0'],$textured['materials']['ground_7']),'Replacement terrain follows native layer boundaries instead of reclassifying imagery');
$xml=simplexml_load_string(BeamGroundMaterials::xml($textured));$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
check(count($xml->xpath('//c:triangles/c:input[@semantic="TEXCOORD" and @set="1"]'))>0,'Terrain COLLADA exports detail UV layer independently from geographic tint UVs');
$wall=['vertices'=>[[10,10,0],[10,20,0],[10,20,10]],'faces'=>[[0,1,2]]];
$wallTextured=BeamGroundMaterials::prepare($wall,$materialLayers,$materialRaster,'stock','wall','/levels/test/',static fn($m)=>null);
check(abs(BeamPolygon::cross(...$wallTextured['detail_uv'][0]))>1,'Vertical tunnel terrain receives non-collapsed detail texture coordinates');

// Abrupt median boundaries must meet the original terrain, even off grid lines.
$joinRaster=new BeamRaster(16,240,str_repeat(pack('g',2),256),str_repeat(chr(100),768));
$joinMain=[[20,50,10],[200,50,10]];$joinProfiles=array_fill(0,2,['width_m'=>10.,'oneway'=>true,'legal_forward'=>true]);
$joinRoad=[];for($x=200;$x>=20;$x-=10)$joinRoad[]=[$x,80,11];
$joinTrack=['name'=>'join','road'=>$joinRoad,'profiles'=>array_fill(0,count($joinRoad),['width_m'=>10.,'oneway'=>true,'legal_forward'=>true]),'chain'=>range(0,180,10),'bridges'=>[],'tunnels'=>[]];
$join=BeamMedianSurface::build($joinMain,$joinProfiles,[$joinTrack],[],$joinRaster,str_repeat("\0",256),'',static fn($m)=>null);
$boundary=[];$middle=[];foreach($join['vertices'] as $v){if(abs($v[0]-20)<1e-7||abs($v[0]-200)<1e-7)$boundary[]=$v[2];if($v[0]>80&&$v[0]<140)$middle[]=$v[2];}
check($boundary&&max(array_map(static fn($z)=>abs($z-2),$boundary))<1e-7,'Median ends meet original native terrain exactly instead of ending in a ledge');
check($middle&&max($middle)>10,'Median transition preserves full road-edge support away from run ends');
$originalLayers=str_repeat(chr(0),256);$replacement=BeamMedianSurface::replaceCells($join,$joinRaster,0,$originalLayers,static fn($m)=>null);
check($replacement['report']['replacement_cells']>0&&substr_count($replacement['layers'],chr(255))===$replacement['report']['replacement_cells'],'Median refinement replaces native cells to avoid overlapping ground collision');
$area=0.;foreach(BeamMedianSurface::triangles($replacement) as $tri)$area+=abs(BeamPolygon::cross(...$tri))/2;
check(abs($area-$replacement['report']['replacement_cells']*225)<1e-5,'Median plus preserved outside terrain covers each replaced cell exactly once');
$cornersMatch=true;foreach($replacement['vertices'] as $v)if(abs($v[0]/15-round($v[0]/15))<1e-7&&abs($v[1]/15-round($v[1]/15))<1e-7&&($v[1]<55||$v[1]>75))$cornersMatch=$cornersMatch&&abs($v[2]-2)<1e-7;
check($cornersMatch,'Replacement terrain retains original grid-corner heights outside the median');

// Exact road prisms remove protruding replacement ground while preserving the roof.
$cutSurface=['vertices'=>[[0,0,0.2],[10,0,0.2],[10,10,0.2],[0,0,6],[10,0,6],[10,10,6]],'faces'=>[[0,1,2],[3,4,5]],'uv'=>[[[0,0],[1,0],[1,1]],[[0,0],[1,0],[1,1]]]];
$cleared=BeamTunnelClearance::clear($cutSurface,[[[0,0,0],[10,0,0],[10,10,0]]],static fn($m)=>null);
$heights=array_column($cleared['vertices'],2);
check(min($heights)<-0.049&&abs(max($heights)-6)<1e-8,'Exact tunnel cut lowers protruding floor below pavement and preserves overburden');
check($cleared['report']['corrected_pieces']>0&&count($cleared['uv'])===count($cleared['faces']),'Exact tunnel correction records work and preserves texture coordinates');

$absoluteRaster=new BeamRaster(16,240,str_repeat(pack('g',102),256),str_repeat(chr(100),768));
$absoluteJoin=BeamMedianSurface::build($joinMain,$joinProfiles,[$joinTrack],[],$absoluteRaster,str_repeat("\0",256),'',static fn($m)=>null,100);
check($absoluteJoin['vertices']===$join['vertices'],'Median end blending uses the export elevation origin instead of absolute DEM heights');
$qRaster=new BeamRaster(4,40,str_repeat(pack('g',102.123),16),str_repeat(chr(100),48));
$qExpected=round(($qRaster->cell(0,0)-100)/64*65536)*64/65536;
check(abs(BeamMedianSurface::nativeHeight($qRaster,15,15,100,64)-$qExpected)<1e-10,'Replacement boundary heights match native terrain serialization precision');

$chunkFixture=['vertices'=>[[0,0,0],[1,0,0],[0,1,0]],'faces'=>array_fill(0,10001,[0,1,2]),'uv'=>array_fill(0,10001,[[0,0],[1,0],[0,1]]),'detail_uv'=>array_fill(0,10001,[[0,0],[1,0],[0,1]]),'face_materials'=>array_fill(0,10001,'chunk'),'materials'=>['chunk'=>[]]];
$chunks=[];$files=BeamGroundMaterials::write($chunkFixture,'ground.dae',static function($file,$xml)use(&$chunks){$doc=simplexml_load_string($xml);$doc->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');$chunks[$file]=(int)$doc->xpath('//c:triangles')[0]['count'];});
check($files===['ground.dae','ground-1.dae']&&array_sum($chunks)===10001&&max($chunks)===10000,'Terrain serialization uses bounded mesh parts without losing collision faces');

$fallbackNative=BeamTerrain::material('fallback',5,'/levels/test/',16,2);
$fallbackMesh=BeamGroundMaterials::material('fallback',5,'/levels/test/','stock',8);
check(isset($fallbackMesh['Stages'][0]['detailMap'])&&$fallbackMesh['Stages'][0]['detailMap']===$fallbackNative['baseColorDetailTex']&&str_ends_with($fallbackMesh['Stages'][0]['baseColorMap'],'fallback.color.png'),'Stock imagery fallback retains its aerial color and gains shared neutral ground detail');
check(isset($groundMat['Stages'][0]['detailMapUseUV'],$groundMat['Stages'][0]['normalDetailMapUseUV'])&&!isset($groundMat['Stages'][0]['detailMapUV']),'Terrain materials use BeamNG documented detail UV property names');
$lowSurface=['vertices'=>[[-5,-5,-2],[15,-5,-2],[15,15,-2],[-5,15,-2]],'faces'=>[[0,1,2],[0,2,3]],'uv'=>[[[0,0],[1,0],[1,1]],[[0,0],[1,1],[0,1]]]];
$supportPavements=[['points'=>[[0,0,0],[10,0,0],[10,10,0]],'raise_floor'=>true],['points'=>[[0,0,0],[10,10,0],[0,10,0]],'raise_floor'=>true]];
$supported=BeamTunnelClearance::clear($lowSurface,$supportPavements,static fn($m)=>null);
check(max(array_column($supported['vertices'],2))>-.051&&$supported['report']['connecting_faces']>0,'Low tunnel ground reaches the road floor with connecting terrain faces');
$diagonalWall=false;foreach(SweepLine\BeamMedianSurface::triangles($supported) as $tri)if(abs(BeamPolygon::cross(...$tri))<1e-8){$internal=true;foreach($tri as $p)$internal=$internal&&abs($p[0]-$p[1])<1e-7&&$p[0]>0.01&&$p[0]<9.99;$diagonalWall=$diagonalWall||$internal;}
check(!$diagonalWall,'Tunnel connecting faces never become internal walls across a pavement diagonal');
