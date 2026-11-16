<?php
// Offline facade imagery, geometry and cache fixtures. No live API or game files.
use SweepLine\{BeamBuildingPhotos as Photos,BeamBuildingStyle as PhotoStyle,BeamScenery as PhotoCorridor,BeamBuildings as PhotoBuildings,BeamRaster as PhotoRaster,BeamResourceCache as PhotoCache};
final class BuildingPhotoFixtureStatement extends PDOStatement {
    public function __construct(private mixed $value){}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->value;}
}
final class BuildingPhotoFixtureDatabase extends PDO {
    public function __construct(private array $values){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return new BuildingPhotoFixtureStatement(array_shift($this->values));}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new BuildingPhotoFixtureStatement(array_shift($this->values));}
}
(function():void{
    $road=[[0,100,100],[200,100,100]];$profiles=array_fill(0,2,['width_m'=>8.]);$corridor=new PhotoCorridor($road,$profiles);
    $partial=[[30,155],[60,155],[60,180],[30,180]];
    check($corridor->polygonReason($partial)==='outside_roadside_range'&&$corridor->polygonReason($partial,false)===null,'Whole building is retained when its footprint only partly intersects the 200-foot corridor');
    check(!$corridor->polygonInRange([[20,166],[50,166],[50,190],[20,190]]),'Buildings entirely outside the pavement-edge corridor remain excluded');
    $endRoad=new PhotoCorridor([[100,0,0],[100,100,0]],array_fill(0,2,['width_m'=>8.]));
    check($endRoad->polygonInRange([[30,140],[170,140],[170,160],[30,160]]),'Corridor detects a long edge crossing the rounded endpoint buffer even when no corners are inside');
    check($corridor->polygonReason([[40,99],[60,99],[60,120],[40,120]],false)==='pavement_overlap','Intersection eligibility preserves pavement collision rejection');
    $terrain=new PhotoRaster(128,250,str_repeat(pack('g',100),16384),str_repeat(chr(100),49152));
    $placed=PhotoBuildings::place([['points'=>$partial,'tags'=>['building'=>'house']]],$road,$profiles,$terrain);
    check(count($placed['objects'])===1&&max(array_column($placed['objects'][0]['world_footprint'],1))===180,'Placed building keeps its complete footprint beyond the corridor instead of clipping it');
    foreach(['http://scontent.fbcdn.net/a','https://fbcdn.net.attacker.example/a','https://127.0.0.1/a','https://user@scontent.fbcdn.net/a','https://scontent.fbcdn.net:8443/a'] as $bad)
        check(rejects(fn()=>Photos::thumbnailUrl($bad)),'Imagery download rejects untrusted URLs and ports');
    check(Photos::thumbnailUrl('https://scontent.example.fbcdn.net/a?x=1')==='https://scontent.example.fbcdn.net/a?x=1','Provider CDN thumbnails are accepted without following arbitrary redirects');
    $face=Photos::face([[40,120],[80,120],[80,140],[40,140]],[60,100]);
    check($face!==null&&$face['index']===0&&Photos::face([[40,120],[80,120],[80,140],[40,140]],[60,130])===null,'Facade matching selects visible walls and rejects cameras inside buildings');
    $flat=array_fill(0,24,array_fill(0,32,[180,170,150]));
    $sample=Photos::analyze($flat);
    check($sample!==null&&$sample['material']==='plaster','Uniform facade evidence supports an explicitly estimated smooth stock material');
    check(Photos::analyze(array_fill(0,24,array_fill(0,32,[50,130,45])))===null&&Photos::analyze(array_fill(0,24,array_fill(0,32,[70,140,210])))===null,'Vegetation and sky dominated samples cannot tint building walls');
    check(Photos::analyze(array_fill(0,24,array_fill(0,32,[10,12,11])))===null,'Severely shadowed facade samples retain the generic fallback');
    $brick=[];$wood=[];
    for($j=0;$j<24;$j++)for($i=0;$i<32;$i++){
        $brick[$j][$i]=($j%6===0||$i%8===0)?[210,190,170]:[160,90,60];
        $wood[$j][$i]=$j%5===0?[110,80,50]:[170,130,90];
    }
    check(Photos::analyze($brick)['material']==='brick'&&Photos::analyze($wood)['material']==='wood','Warm masonry grid and directional siding patterns suggest different stock materials');
    $view=$sample+['image_id'=>'101','creator'=>'Fixture photographer','captured_at'=>1700000000000,'point'=>[50,100],'face_index'=>0];
    $second=$view;$second['image_id']='102';$second['point']=[60,100];
    $matched=Photos::consensus([$view,$second]);
    check($matched!==null&&count($matched['sources'])===2&&Photos::consensus([$view])===null,'Appearance requires agreement from two spatially separated images');
    $bad=$second;$bad['color']=[.9,.1,.1];check(Photos::consensus([$view,$bad])===null,'Conflicting photo colors cannot override fallback appearance');
    $bad=$second;$bad['point']=[51,100];check(Photos::consensus([$view,$bad])===null,'Nearby duplicate views do not count as independent evidence');
    $bad=$second;$bad['face_index']=1;check(Photos::consensus([$view,$bad])===null,'Different building walls are not falsely treated as matching views of one facade');
    $appearance=PhotoStyle::appearance('residential','test',[],$matched);
    check($appearance['color']===$matched['color']&&str_contains($appearance['sources']['facade_color'],'Mapillary'),'Accepted photo color reaches the actual building material');
    $osm=PhotoStyle::appearance('residential','test',['building:material'=>'brick','building:colour'=>'#ffffff'],$matched);
    check($osm['color']===[1,1,1]&&$osm['texture']===PhotoStyle::TEXTURES['commercial']&&$osm['sources']['facade_color']==='building:colour','Explicit OSM appearance takes priority over photo estimates');
    $dir=sys_get_temp_dir().'/sl-building-photos-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/cache',0700);
    $graphCalls=0;$downloads=0;$cache=new PhotoCache($dir.'/cache');
    $source=static fn($id,$x)=>['id'=>(string)$id,'geometry'=>['type'=>'Point','coordinates'=>[-84+$x*.00001,41]],'compass_angle'=>0,'camera_type'=>'spherical','thumb_1024_url'=>'https://scontent.example.fbcdn.net/'.$id.'.jpg?signature=PRIVATE','creator'=>['username'=>'Fixture photographer'],'captured_at'=>1700000000000];
    $graph=static function($endpoint,$params,$token,$progress)use(&$graphCalls,$source){$graphCalls++;return ['data'=>[$source(101,0),$source(102,1)]];};
    $download=static function($url,$path,$max,$post,$progress,$timeout)use(&$downloads){$downloads++;$im=imagecreatetruecolor(1024,512);imagefill($im,0,0,imagecolorallocate($im,180,170,150));imagepng($im,$path);imagedestroy($im);};
    $plan=['points'=>$road,'bounds'=>[0,0,250,250],'epsg'=>32616,'building_data'=>['footprints'=>[['osm_id'=>'way/fixture','points'=>[[40,120],[80,120],[80,140],[40,140]],'tags'=>['building'=>'house']]]]];
    $db=static fn()=>new BuildingPhotoFixtureDatabase(['fixture-token',['w'=>-84,'s'=>41,'e'=>-83.99,'n'=>41.01],'{"type":"MultiPoint","coordinates":[[50,100],[60,100]]}']);
    try{
        Photos::fetch($db(),$plan,$terrain,$dir,static fn($m)=>null,$graph,$download,$cache);
        check($plan['building_data']['photo_analysis']['matched']===1&&isset($plan['building_data']['footprints'][0]['photo_match']),'End-to-end injected imagery acquisition, projection, sampling and consensus attach a real analyzed match');
        check($graphCalls===1&&$downloads===2,'Two accepted views share one metadata acquisition');
        $json=json_encode($plan,JSON_THROW_ON_ERROR);
        check(!str_contains($json,'PRIVATE')&&!str_contains($json,'fixture-token')&&str_contains($json,'Fixture photographer'),'Exportable evidence contains attribution and image IDs without signed thumbnail URLs or tokens');
        Photos::fetch($db(),$plan,$terrain,$dir,static fn($m)=>null,$graph,$download,$cache);
        check($graphCalls===1&&$downloads===2,'Repeated matching reuses the existing seven-day private metadata/image cache');
        $disabled=$plan;Photos::fetch(new BuildingPhotoFixtureDatabase(['']),$disabled,$terrain,$dir,static fn($m)=>null,$graph,$download,$cache);
        check($disabled['building_data']['photo_analysis']['status']==='not_configured','Missing Mapillary configuration leaves exports usable');
        $fallback=$plan;$fallback['building_data']['footprints'][0]['photo_match']=null;
        Photos::fetch($db(),$fallback,$terrain,$dir,static fn($m)=>null,static function(){throw new SweepLine\DownloadFailure('Fixture provider unavailable',false);},$download,new PhotoCache($dir.'/failed-cache'));
        check($fallback['building_data']['photo_analysis']['status']==='partial'&&$fallback['building_data']['footprints'][0]['photo_match']===null,'Unavailable imagery fails open to the existing mapped/generic building appearance');
        $cancelled=false;try{Photos::fetch($db(),$plan,$terrain,$dir,static function(){throw new SweepLine\JobCancelled('Fixture cancellation');},$graph,$download,$cache);}catch(SweepLine\JobCancelled $e){$cancelled=true;}
        check($cancelled,'Building imagery preserves export cancellation');
    }finally{
        foreach(glob($dir.'/*') as $entry){if(is_dir($entry)){foreach(glob($entry.'/*') as $file)unlink($file);rmdir($entry);}else unlink($entry);}rmdir($dir);
    }
})();
