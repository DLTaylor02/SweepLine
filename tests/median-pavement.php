<?php
// Close, mapped one-way roads retain a continuous counterpart and one collision surface per overlap.
use SweepLine\{BeamMap,BeamRaster};
$medianMapPoints=[[100,600],[1100,600]];$medianOpposite=[[1100,612],[100,612]];
$medianTags=['ref'=>'TEST 12','lanes'=>'4','oneway'=>'yes'];
$medianSource=static fn($p)=>['points'=>$p,'direction'=>1,'highway'=>'motorway','tags'=>$medianTags];
$medianMapPlan=['name'=>'Generated Narrow Median','alternate_names'=>[],'points'=>$medianMapPoints,'sources'=>[$medianSource($medianMapPoints)],'bounds'=>[0,0,1200,1200],'resolution'=>64,'padding_miles'=>0.5,'epsg'=>32611,'center'=>[-90,40],'oneway'=>true,'terrain_style'=>'aerial',
 'carriageways'=>[['name'=>'carriageway_0','points'=>$medianOpposite,'sources'=>[$medianSource($medianOpposite)],'intersection_data'=>['branches'=>[]]]]];
$medianMapRaster=new BeamRaster(64,1200,str_repeat(pack('g',100),4096),str_repeat(chr(100),12288));$medianMapFile=tempnam(sys_get_temp_dir(),'sl-median-pavement-');
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
 $medianZip->close();
}finally{@unlink($medianMapFile);}
