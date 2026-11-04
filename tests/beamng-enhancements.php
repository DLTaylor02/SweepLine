<?php
// Offline appearance and road-generation fixtures.
use SweepLine\{BeamBuildingStyle,BeamCurve,BeamRoadSurface,BeamLanes,BeamPng,BeamPlan,BeamMap,BeamRaster};
$house=BeamBuildingStyle::classify(['building'=>'house']);
check($house['category']==='residential'&&$house['confidence']==='mapped_building_tag','Mapped building type selects a residential facade');
check(BeamBuildingStyle::classify(['building'=>'yes','shop'=>'convenience'])['category']==='commercial','Mapped shop use selects a business facade');
check(BeamBuildingStyle::classify(['building'=>'warehouse'])['category']==='industrial','Warehouse tags select an industrial facade');
$footprint=[[10,10],[20,10],[20,20],[10,20]];$area=['points'=>[[0,0],[30,0],[30,30],[0,30]],'landuse'=>'residential'];
$estimated=BeamBuildingStyle::classify(['building'=>'yes'],$footprint,[$area]);
check($estimated['category']==='residential'&&$estimated['confidence']==='landuse_estimate','Containing mapped land use provides an explicitly estimated facade category');
check(BeamBuildingStyle::classify(['building'=>'warehouse'],$footprint,[$area])['category']==='industrial','Specific building tags take priority over surrounding land use');
$other=$area;$other['landuse']='industrial';
check(BeamBuildingStyle::classify(['building'=>'yes'],$footprint,[$area,$other])['category']==='generic','Conflicting mapped land uses retain a neutral fallback');
check(BeamBuildingStyle::classify(['building'=>'yes'])['category']==='generic','Unknown building use does not become an invented business or residence');
$xml=BeamBuildingStyle::mesh($footprint,9,'wall_fixture','roof_fixture');$doc=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);
check($doc!==false&&count($doc->xpath('//*[local-name()="material"]'))===2,'Building mesh separates textured walls from the roof material');
check(count($doc->xpath('//*[local-name()="triangles"]/*[local-name()="input"][@semantic="TEXCOORD"]'))===2,'Building wall and roof faces have bound texture coordinates');
$uv=array_map('floatval',preg_split('/\s+/',trim((string)$doc->xpath('//*[local-name()="source"][@id="uv"]/*[local-name()="float_array"]')[0])));
check(max($uv)>=3,'Facade UVs repeat at physical scale instead of stretching once over a whole building');
$base=BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0);$shouldered=BeamRoadSurface::shoulders([$base])[0];
check(abs($shouldered['width_m']-9.2192)<1e-8&&abs($shouldered['shoulder_m']-0.6096)<1e-8&&$shouldered['carriageway_width_m']===8.0,'Paved surface adds exactly two feet on each side of unchanged lane width');
check(BeamRoadSurface::shoulders([$shouldered])[0]===$shouldered,'Repeated shoulder preparation cannot widen the road twice');
$image=imagecreatefromstring(BeamPng::road($shouldered));$margin=(int)round(255*$shouldered['shoulder_m']/$shouldered['width_m']);
check((imagecolorat($image,0,128)&0xffffff)!==0xebebe1&&(imagecolorat($image,$margin,128)&0xffffff)===0xebebe1,'Edge lines mark the lane boundary with paved shoulder beyond them');
$spawnRoad=[[0,0,20],[50,0,20],[100,0,20],[150,0,20]];
$plainSpawn=BeamMap::spawn($spawnRoad,array_fill(0,4,$base),false,false);$wideSpawn=BeamMap::spawn($spawnRoad,array_fill(0,4,$shouldered),false,false);
check($plainSpawn['position']===$wideSpawn['position'],'Adding shoulders does not move the spawn into the shoulder or widen the traffic lanes');
$native=new BeamRaster(128,128,str_repeat(pack('g',5),16384),str_repeat(chr(100),49152));
BeamRoadSurface::blendTerrain([[10,64,10],[118,64,10]],array_fill(0,2,$shouldered),$native,0,str_repeat("\0",16384),static fn($m)=>null);
check(abs($native->height(60,68.6)-9.95)<0.001,'Native terrain meets the widened pavement outer edge with the existing five-centimetre recess');
$bend=[[0,0],[50,0],[100,20],[140,55]];$smooth=BeamCurve::smooth($bend);
check(count($smooth)>count($bend)&&$smooth[0]===$bend[0]&&end($smooth)===end($bend),'Curve rounding adds intermediate directions and preserves route endpoints');
$deviation=0;$turn=0;foreach($smooth as $i=>$p){
    $nearest=INF;for($j=1;$j<count($bend);$j++)$nearest=min($nearest,BeamLanes::distance($p,$bend[$j-1],$bend[$j])[0]);$deviation=max($deviation,$nearest);
    if($i&&isset($smooth[$i+1])){$a=$smooth[$i-1];$b=$smooth[$i+1];$dx=$p[0]-$a[0];$dy=$p[1]-$a[1];$ex=$b[0]-$p[0];$ey=$b[1]-$p[1];$turn=max($turn,rad2deg(acos(max(-1,min(1,($dx*$ex+$dy*$ey)/max(1e-8,hypot($dx,$dy)*hypot($ex,$ey)))))));}
}
check($deviation<=BeamCurve::MAX_DEVIATION_M+1e-7&&$turn<5,'Rounded curves stay within the deviation limit and replace abrupt angle changes with small steps');
check(BeamCurve::smooth($bend,[$bend[1],$bend[2]])===$bend,'Mapped junction anchors prevent nearby curve displacement');
check(BeamCurve::smooth($bend,[],[[$bend[0],$bend[2]],[$bend[2],$bend[3]]])===$bend,'Bridge/structure corridors retain original geometry');
$sharp=[[0,0],[50,0],[50,50]];
check(BeamCurve::smooth($sharp)===$sharp,'A genuinely sharp right-angle turn is preserved instead of cut across');
check(count(BeamPlan::sample($smooth,8,0.001))>=count($smooth),'Fine curve supports survive road resampling');
$directory=sys_get_temp_dir().'/sl-building-style-'.bin2hex(random_bytes(4));mkdir($directory,0700);
try{
    $db=new GuardrailFixtureDatabase([
        '{"coordinates":[[-84,41],[-84,42],[-85,41],[-84,41]]}',
        '{"geometries":[{"coordinates":[[10,10],[20,10],[20,20],[10,20],[10,10]]},{"coordinates":[[0,0],[30,0],[30,30],[0,30],[0,0]]}]}'
    ]);
    $response=['elements'=>[
        ['type'=>'way','id'=>1,'tags'=>['building'=>'yes'],'geometry'=>[['lon'=>-84.1,'lat'=>41.1],['lon'=>-84.0,'lat'=>41.1],['lon'=>-84.0,'lat'=>41.2],['lon'=>-84.1,'lat'=>41.2],['lon'=>-84.1,'lat'=>41.1]]],
        ['type'=>'way','id'=>2,'tags'=>['landuse'=>'retail'],'geometry'=>[['lon'=>-84.2,'lat'=>41.0],['lon'=>-83.9,'lat'=>41.0],['lon'=>-83.9,'lat'=>41.3],['lon'=>-84.2,'lat'=>41.3],['lon'=>-84.2,'lat'=>41.0]]]
    ]];
    $fetched=SweepLine\BeamBuildings::fetch($db,['points'=>[[0,0],[100,0]],'epsg'=>32616,'bounds'=>[0,0,400,400]],$directory,static fn($m)=>null,static function($url,$file,$limit,$post)use($response){file_put_contents($file,json_encode($response));});
    check(count($fetched['footprints'])===1&&$fetched['landuse_areas']===1&&$fetched['footprints'][0]['style']['category']==='commercial','Building fetch keeps land-use areas separate from real footprints and applies the containing-use style');
}finally{foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}

$directory=sys_get_temp_dir().'/sl-building-landuse-failure-'.bin2hex(random_bytes(4));mkdir($directory,0700);
try{
    $db=new GuardrailFixtureDatabase(['{"coordinates":[[-84,41],[-84,42],[-85,41],[-84,41]]}','{"geometries":[{"coordinates":[[10,10],[20,10],[20,20],[10,20],[10,10]]}]}']);$calls=0;
    $fetched=SweepLine\BeamBuildings::fetch($db,['points'=>[[0,0],[100,0]],'epsg'=>32616,'bounds'=>[0,0,400,400]],$directory,static fn($m)=>null,
        static function($url,$file,$limit,$post)use(&$calls,$response){if(++$calls===2)throw new SweepLine\DownloadFailure('Fixture optional provider failure',false);file_put_contents($file,json_encode(['elements'=>[$response['elements'][0]]]));});
    check(count($fetched['footprints'])===1&&isset($fetched['landuse_warning'])&&$fetched['footprints'][0]['style']['category']==='generic','Land-use provider failure retains downloaded real buildings with a neutral facade');
}finally{foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}
