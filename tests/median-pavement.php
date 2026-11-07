<?php
// Close, mapped one-way roads retain a continuous counterpart and one collision surface per overlap.
use SweepLine\{BeamMap,BeamRaster};
$medianMapPoints=[[100,600],[1100,600]];$medianOpposite=[[1100,612],[100,612]];
$medianTags=['ref'=>'TEST 12','lanes'=>'4','oneway'=>'yes'];
$medianSource=static fn($p)=>['points'=>$p,'direction'=>1,'highway'=>'motorway','tags'=>$medianTags];
$medianMapPlan=['name'=>'Generated Narrow Median','alternate_names'=>[],'points'=>$medianMapPoints,'sources'=>[$medianSource($medianMapPoints)],'bounds'=>[0,0,1200,1200],'resolution'=>64,'padding_miles'=>0.5,'epsg'=>32611,'center'=>[-90,40],'oneway'=>true,'terrain_style'=>'aerial',
 'carriageways'=>[['name'=>'carriageway_0','points'=>$medianOpposite,'sources'=>[$medianSource($medianOpposite)],'intersection_data'=>['branches'=>[]]]]];
$medianHeights='';for($row=63;$row>=0;$row--)for($column=0;$column<64;$column++)$medianHeights.=pack('g',100+($row*1200/64-600)*0.1);
$medianMapRaster=new BeamRaster(64,1200,$medianHeights,str_repeat(chr(100),12288));$medianMapFile=tempnam(sys_get_temp_dir(),'sl-median-pavement-');
try{
 $medianMapReport=BeamMap::build($medianMapPlan,$medianMapRaster,str_repeat('a',32),$medianMapFile,static function($m){});
 $medianZip=new ZipArchive();$medianZip->open($medianMapFile);$medianBase='levels/sweepline_aaaaaaaaaaaaaaaa/';
 $medianScene=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($medianZip->getFromName($medianBase.'main/MissionGroup/items.level.json'))));$medianTriangles=[];
 foreach($medianScene as $object)if(($object['class']??'')==='TSStatic'&&($object['collisionType']??'')==='Visible Mesh Final'&&str_contains($object['shapeName']??'','/art/roads/')){
  $xml=simplexml_load_string($medianZip->getFromName(ltrim($object['shapeName'],'/')));$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
  $v=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
  foreach($xml->xpath('//c:triangles/c:p') as $p){$ix=array_map('intval',preg_split('/\s+/',trim((string)$p)));
   for($i=0;$i<count($ix);$i+=9){$t=[];foreach([0,3,6] as $offset){$point=array_slice($v,$ix[$i+$offset]*3,3);foreach([0,1,2] as $axis)$point[$axis]+=$object['position'][$axis];$t[]=$point;}$medianTriangles[$object['name']][]=$t;}
  }
 }
 foreach([600,604,607,610,612,618] as $y){$surfaces=0;
  foreach($medianTriangles as $mesh)foreach($mesh as $t){$w=SweepLine\BeamTerrainContact::weights([600,$y],$t);if($w&&min($w)>=-1e-7){$surfaces++;break;}}
  check($surfaces===1,'Narrow median has exactly one driving collision surface at y='.$y);
 }
 check($medianMapReport['carriageways']['tracks'][0]['length_m']>990,'Narrow-median counterpart retains its full length in the generated ZIP');
 $returnZ=101.45-$medianMapReport['elevation_origin_m'];$mainZ=100.25-$medianMapReport['elevation_origin_m'];$returnError=0.;$mainError=0.;
 foreach($medianTriangles as $name=>$mesh)foreach($mesh as $triangle)foreach($triangle as $vertex){if($name==='carriageway_0_surface_0')$returnError=max($returnError,abs($vertex[2]-$returnZ));elseif($name==='road_surface')$mainError=max($mainError,abs($vertex[2]-$mainZ));}
 check($returnError<1e-6,'Clipped returning pavement stays at its own elevation instead of forming ramps to the other carriageway');
 check($mainError<1e-6,'Independent counterpart clipping leaves selected-road elevations unchanged');
 check($medianMapReport['carriageways']['tracks'][0]['surfaces'][0]['boundary_height_welding']===false,'ZIP report records independent carriageway height clipping');
 $medianReport=$medianMapReport['median'];
 check(($medianReport['tracks'][0]['alignment']['adjusted_samples']??0)>0,'Generated overlapping carriageways are separated without removing their inner lanes');
 $wallFile=$medianZip->getFromName($medianBase.'art/roads/carriageway_0-median.dae');
 check(is_string($wallFile)&&str_contains($wallFile,'COLLADA')&&($medianReport['tracks'][0]['divider_segments']??0)>0,'Generated ZIP contains closed collision median divider geometry');

 $medianGround=array_values(array_filter($medianScene,static fn($o)=>($o['name']??'')==='median_ground'));
 check(count($medianGround)===1&&$medianGround[0]['collisionType']==='Visible Mesh Final','Generated ZIP includes the exact median terrain collision surface');
 $groundXml=$medianZip->getFromName($medianBase.'art/terrains/median-ground.dae');
 check(is_string($groundXml)&&simplexml_load_string($groundXml)!==false&&$medianMapReport['median']['refined_surface']['triangles']>0,'Generated median geometry is valid COLLADA and recorded in build diagnostics');
 $groundMaterials=json_decode($medianZip->getFromName($medianBase.'art/terrains/median.materials.json'),true,512,JSON_THROW_ON_ERROR);
 check($groundMaterials&&count(array_filter($groundMaterials,static fn($m)=>in_array($m['groundType'],['GRASS','DIRT','ROCK','GRAVEL'],true)))===count($groundMaterials),'Median collision uses terrain ground types instead of roadway or custom sticky material');

 $medianZip->close();
}finally{@unlink($medianMapFile);}
