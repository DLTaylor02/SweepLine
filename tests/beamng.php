<?php
// Synthetic rasters and planar road geometry are explicit offline test fixtures.
use SweepLine\{BeamPlan,BeamLanes,BeamRaster,BeamPng,BeamMap,BeamJobs};
check(BeamPlan::options([])['resolution']===2048,'BeamNG default terrain resolution is 2048');
check(BeamPlan::options([])['padding_miles']===0.5,'BeamNG defaults to half-mile padding');
foreach([['padding_miles'=>0],['padding_miles'=>6],['resolution'=>1536]] as $invalid)check(rejects(fn()=>BeamPlan::options($invalid)),'Invalid BeamNG options rejected');
$square=BeamPlan::square([[10,20],[1010,320]],100);
check($square===[-90.0,-430.0,1110.0,770.0],'Map bounds are square with symmetric padding');
check(rejects(fn()=>BeamPlan::square([[0,0],[40000,0]],100)),'Oversized maps rejected without stretching');
$sample=BeamPlan::sample([[0,0],[10,0],[10,10]],8);
check(in_array([10,0],$sample),'Resampling retains sharp original corners');
$lanes=BeamLanes::infer(['lanes'=>'4','lanes:forward'=>'3','lanes:backward'=>'1','width'=>'48 ft'],'primary',0);
check($lanes['lanes']===4 && $lanes['forward']===3 && abs($lanes['width_m']-14.6304)<0.0001,'Mapped lane counts and imperial widths retained');
$reverse=BeamLanes::infer(['lanes'=>'4','lanes:forward'=>'3','lanes:backward'=>'1'],'primary',0,false);
check($reverse['forward']===1 && $reverse['backward']===3,'Lane direction follows exported geometry orientation');
check(BeamLanes::infer([],'secondary',0)['estimated_lanes'],'Missing lane counts explicitly estimated');
check(BeamLanes::infer(['lanes'=>'3'],'motorway',1)['oneway'],'One-way lane handling');
try{BeamJobs::validId('../escape');check(false,'Unsafe export ID rejected');}catch(OutOfBoundsException $e){check(true,'Unsafe export ID rejected');}
$n=128;$side=1200.0;$heights='';$colors='';
for($row=0;$row<$n;$row++)for($x=0;$x<$n;$x++)$heights.=pack('g',100+$x*0.02+($n-1-$row)*0.01);
$plane=$n*$n;$colors=str_repeat(chr(65),$plane).str_repeat(chr(110),$plane).str_repeat(chr(55),$plane);
$raster=new BeamRaster($n,$side,$heights,$colors);
check(abs($raster->cell(0,0)-100)<0.001 && $raster->cell(0,$n-1)>101,'North-up input rasters become south-up terrain coordinates');
check(abs($raster->height($side/2,$side/2)-101.92)<0.002,'DEM elevation bilinear interpolation');
check(BeamRaster::cover([65,110,55])===0 && BeamRaster::cover([30,40,90])===4,'Aerial color classes distinguish vegetation and likely water');
$bad=new BeamRaster($n,$side,str_repeat(pack('g',-9999),$plane),$colors);
try{$bad->cell(0,0);check(false,'Missing elevation fails');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'No synthetic'),'Missing elevation never replaced with fabricated terrain');}
$plan=['name'=>'SweepLine Export Validation','alternate_names'=>['Synthetic Fixture Road'],'resolution'=>$n,'padding_miles'=>0.5,'epsg'=>32611,'bounds'=>[1000,2000,2200,3200],'points'=>[[1200,2400],[1900,2400],[1900,2900]],'sources'=>[
    ['points'=>[[1200,2400],[1900,2400]],'tags'=>['lanes'=>'2'],'highway'=>'secondary','direction'=>0],
    ['points'=>[[1900,2400],[1900,2900]],'tags'=>['lanes'=>'3','lanes:forward'=>'2','lanes:backward'=>'1'],'highway'=>'secondary','direction'=>0]
],'center'=>[-119.8,39.5],'oneway'=>false];
$file=tempnam(sys_get_temp_dir(),'sweepline-beam-test-');$phases=[];
try {
    $report=BeamMap::build($plan,$raster,str_repeat('a',32),$file,static function($message)use(&$phases){if($message!==null)$phases[]=$message;});
    $archive=new ZipArchive();check($archive->open($file)===true,'Generated BeamNG ZIP opens');
    $base='levels/sweepline_aaaaaaaaaaaaaaaa/';
    $info=json_decode($archive->getFromName($base.'info.json'),true,512,JSON_THROW_ON_ERROR);
    check($info['defaultSpawnPointName']==='spawns_start' && count($info['spawnPoints'])===2,'Two selectable spawns with Start default');
    check($info['size']===[1200,1200],'World dimensions remain true-scale and square');
    foreach(['preview.png','spawn-start.png','spawn-end.png'] as $preview){
        $image=imagecreatefromstring($archive->getFromName($base.$preview));check(imagesx($image)===1024 && imagesy($image)===576,'BeamNG labeled preview has widescreen dimensions');
        $textPixels=0;for($y=12;$y<48;$y++)for($x=20;$x<240;$x++)if((imagecolorat($image,$x,$y)&0xffffff)===0xffffff)$textPixels++;
        check($textPixels>100,'Preview includes visible SweepLine text');imagedestroy($image);
    }
    foreach($info['spawnPoints'] as $spawnPoint)check($archive->locateName($base.$spawnPoint['preview'])!==false,'Spawn picker references an included thumbnail');
    $nav=json_decode($archive->getFromName($base.'map.json'),true,512,JSON_THROW_ON_ERROR);
    check(count($nav['segments'])>1,'Selected road includes minimap navigation edges');
    $root=json_decode(trim($archive->getFromName($base.'main/items.level.json')),true,512,JSON_THROW_ON_ERROR);
    check($root['class']==='SimGroup' && $root['name']==='MissionGroup','Scene root matches installed v0.40 layout');
    $objects=array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),explode("\n",trim($archive->getFromName($base.'main/MissionGroup/items.level.json'))));
    $spawns=array_values(array_filter($objects,static fn($o)=>$o['class']==='SpawnSphere'));
    check(count($spawns)===2 && $spawns[0]['position']!==$spawns[1]['position'],'Endpoint spawn objects are distinct');
    $roads=array_values(array_filter($objects,static fn($o)=>$o['class']==='MeshRoad'));
    check(count($roads)>=2,'Lane changes create correctly textured road sections');
    $arches=array_values(array_filter($objects,fn($o)=>in_array($o['name']??'',['start_arch','finish_arch'],true)));
    check(count($arches)===2,'Start and Finish each have one physical arch');
    foreach($arches as $arch){
        // Transform the local beam X axis using the serialized axis columns.
        $m=$arch['rotationMatrix'];$nearest=INF;$tangent=null;
        foreach($roads as $mesh)for($i=1;$i<count($mesh['nodes']);$i++){
            $a=$mesh['nodes'][$i-1];$b=$mesh['nodes'][$i];$d=hypot($b[0]-$arch['position'][0],$b[1]-$arch['position'][1]);
            if($d<$nearest){$nearest=$d;$tangent=[$b[0]-$a[0],$b[1]-$a[1]];}
        }
        $length=hypot(...$tangent)?:1;
        check(abs(($m[0]*$tangent[0]+$m[1]*$tangent[1])/$length)<0.15,'Arch beam world axis crosses road rather than running along it');
    }

    foreach($arches as $arch){
        $dae=simplexml_load_string($archive->getFromName(ltrim($arch['shapeName'],'/')),SimpleXMLElement::class,LIBXML_NONET);
        check($dae!==false && $arch['collisionType']==='Visible Mesh Final','Arch mesh is bundled and collidable');
        $arrays=$dae->xpath('//*[local-name()="float_array" and @id="positions-array"]');$vertices=preg_split('/\s+/',trim((string)$arrays[0]));$lowestCenter=INF;
        for($i=0;$i<count($vertices);$i+=3)if(abs((float)$vertices[$i])<3)$lowestCenter=min($lowestCenter,(float)$vertices[$i+2]);
        check($lowestCenter>=6,'Arch overhead beam leaves six meters clearance');
    }
    $archLabels=array_values(array_filter($objects,fn($o)=>in_array($o['name']??'',['start_arch-label','finish_arch-label'],true)));
    check(count($archLabels)===2 && $archLabels[0]['shapeName']!==$archLabels[1]['shapeName'],'Distinct START and FINISH lettering meshes are included');

    $waypoints=array_column(array_values(array_filter($objects,fn($o)=>$o['class']==='BeamNGWaypoint')),null,'name');
    foreach($nav['segments'] as $segment){foreach($segment['nodes'] as $node)if(!isset($waypoints[$node]))throw new RuntimeException('Dangling navigation node');}
    check(count($waypoints)===count($nav['segments'])+1,'Navigation follows a connected chain of existing waypoints');
    foreach($roads as $road)check(count($road['nodes'][0])===8 && $road['nodes'][0][4]>0,'Road nodes include collision depth and normals');
    $roadPoints=[];foreach($roads as $road)$roadPoints=array_merge($roadPoints,$road['nodes']);
    $profiles=array_fill(0,count($roadPoints),BeamLanes::infer(['lanes'=>'2'],'secondary',0));
    $oneEnd=BeamMap::spawn($roadPoints,$profiles,true,true);$twoEnd=BeamMap::spawn($roadPoints,$profiles,true,false);
    $forward=$oneEnd['rotationMatrix'];$back=$twoEnd['rotationMatrix'];
    check(abs($forward[1]-$back[1])<0.00001 && abs($forward[4]-$back[4])<0.00001,'Finish spawn always faces back toward Start in simulation');
    $terrain=$archive->getFromName($base.'theTerrain.ter');$size=unpack('V',substr($terrain,1,4))[1];$offset=5+3*$size*$size;$count=unpack('V',substr($terrain,$offset,4))[1];$offset+=4;
    $terrainMaterials=json_decode($archive->getFromName($base.'art/terrains/main.materials.json'),true,512,JSON_THROW_ON_ERROR);
    check(ord($terrain[0])===9 && $size===$n && $count===5,'Terrain binary v9 header and arrays are valid');
    for($i=0;$i<$count;$i++){$length=ord($terrain[$offset++]);$material=substr($terrain,$offset,$length);$offset+=$length;check(isset($terrainMaterials[$material]),'Terrain byte-length material name resolves');}
    check($offset===strlen($terrain),'Terrain binary has no missing or extra payload');
    $textures=json_decode($archive->getFromName($base.'art/roads/main.materials.json'),true,512,JSON_THROW_ON_ERROR);
    foreach($roads as $road){$material=$textures[$road['topMaterial']];check($archive->locateName(ltrim($material['Stages'][0]['diffuseMap'],'/'))!==false,'Road texture is bundled locally');}
    foreach($objects as $o)if($o['class']==='TSStatic') {
        check(simplexml_load_string($archive->getFromName(ltrim($o['shapeName'],'/')),SimpleXMLElement::class,LIBXML_NONET)!==false,'Procedural scenery mesh is valid COLLADA');
        break;
    }
    check($archive->locateName($base.'sweepline-report.json')!==false && !$report['in_game_verified'],'Export discloses approximation and verification status');
    check(count($phases)>=4,'Background export reports real generation stages');
    if(in_array('--keep-beamng-fixture',$argv,true)) {
        if(!is_dir(SWEEPLINE_ROOT.'/var'))mkdir(SWEEPLINE_ROOT.'/var',0750);
        copy($file,SWEEPLINE_ROOT.'/var/beamng-validation.zip');
        echo 'Validation ZIP: '.SWEEPLINE_ROOT."/var/beamng-validation.zip\n";
    }
    $archive->close();
}finally{unlink($file);}


// Provider retry tests use injected failures; no remote requests or sleeping.
$attempts=0;$waits=0;$messages=[];
$source=new SweepLine\BeamSources(function()use(&$attempts){if(++$attempts<3)throw new SweepLine\DownloadFailure('HTTP 504',true);});
$source->downloadRaster('https://example.invalid','unused','USGS elevation',function($message)use(&$messages){$messages[]=$message;},function()use(&$waits){$waits++;});
check($attempts===3 && $waits===15,'Transient raster failures retry with bounded backoff');
check(count(array_filter($messages,fn($m)=>is_string($m)&&str_contains($m,'retrying')))===2,'Provider retry progress is visible');
$attempts=0;
$source=new SweepLine\BeamSources(function()use(&$attempts){$attempts++;throw new SweepLine\DownloadFailure('HTTP 404',false);});
try{$source->downloadRaster('https://example.invalid','unused','NAIP aerial imagery',fn($m)=>null,fn()=>null);check(false,'Permanent failure rejected');}catch(RuntimeException $e){check($attempts===1 && str_contains($e->getMessage(),'NAIP aerial imagery'),'Permanent failure identifies provider without retrying');}
$attempts=0;
$source=new SweepLine\BeamSources(function()use(&$attempts){$attempts++;throw new SweepLine\DownloadFailure('HTTP 504',true);});
try{$source->downloadRaster('https://example.invalid','unused','USGS elevation',fn($m)=>null,fn()=>null);check(false,'Retry exhaustion rejected');}catch(RuntimeException $e){check($attempts===3 && str_contains($e->getMessage(),'after 3 attempts'),'Raster retries terminate with actionable error');}
$attempts=0;
try{$source->downloadRaster('https://example.invalid','unused','USGS elevation',function($m){if($m===null)throw new SweepLine\JobCancelled('Cancelled');},fn()=>null);check(false,'Retry wait cancellation propagated');}catch(SweepLine\JobCancelled $e){check($attempts===1,'Cancellation interrupts provider backoff immediately');}

foreach([[1,0],[0,1],[-1,0],[0,-1],[sqrt(0.5),sqrt(0.5)],[-sqrt(0.5),sqrt(0.5)]] as [$dx,$dy]){
    $track=[];for($i=0;$i<20;$i++)$track[]=[$i*8*$dx,$i*8*$dy,$i*0.8];
    $laneProfiles=array_fill(0,20,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
    foreach([false,true] as $end){$spawn=BeamMap::spawn($track,$laneProfiles,$end,false);$m=$spawn['rotationMatrix'];$sign=$end?-1:1;
        check($m[3]*$dx*$sign+$m[4]*$dy*$sign>0.99,'Spawn frame faces travel along cardinal road and follows grade');
        $position=$spawn['position'];$rightDistance=($position[0]*$dy-$position[1]*$dx)*$sign;
        check(abs($rightDistance-2)<0.001,'Both endpoints spawn in center of right-hand travel lane');
        check(abs($m[5]-$sign*0.1/sqrt(1.01))<0.001,'Spawn pitch follows road elevation');
    }
}

// Flat reference terrain isolates road clearance from DEM slopes and sampling.
$flatRaster=new BeamRaster($n,$side,str_repeat(pack('g',100),$n*$n),$colors);
$flatFile=tempnam(sys_get_temp_dir(),'sl-flush-');
try{
    $flatReport=BeamMap::build($plan,$flatRaster,str_repeat('b',32),$flatFile,fn($m)=>null);
    $flatZip=new ZipArchive();$flatZip->open($flatFile);
    $flatBase='levels/sweepline_bbbbbbbbbbbbbbbb/';
    $flatObjects=array_map(fn($line)=>json_decode($line,true),explode("\n",trim($flatZip->getFromName($flatBase.'main/MissionGroup/items.level.json'))));
    $flatRoads=array_values(array_filter($flatObjects,fn($o)=>$o['class']==='MeshRoad'));
    $terrainObject=array_values(array_filter($flatObjects,fn($o)=>$o['class']==='TerrainBlock'))[0];
    $binary=$flatZip->getFromName($flatBase.'theTerrain.ter');$maxGap=0;$minGap=INF;
    foreach($flatRoads as $mesh)foreach($mesh['nodes'] as $point){
        $x=max(0,min($n-1,(int)round($point[0]/$side*$n)));$y=max(0,min($n-1,(int)round($point[1]/$side*$n)));
        $ground=unpack('v',substr($binary,5+($y*$n+$x)*2,2))[1]/65536*$terrainObject['maxHeight'];
        $gap=$point[2]-$ground;$maxGap=max($maxGap,$gap);$minGap=min($minGap,$gap);
    }
    check($minGap>=0 && $maxGap<0.09,'Serialized road surface meets terrain within 9 cm including height quantization');
    check(abs($flatRoads[0]['nodes'][0][2]+$flatReport['elevation_origin_m']-100.08)<0.001,'Road uses ground elevation with only anti-flicker clearance');
    $flatZip->close();
}finally{unlink($flatFile);}
