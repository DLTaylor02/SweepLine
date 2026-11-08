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
$plan=['terrain_style'=>'aerial','name'=>'SweepLine Export Validation Café','alternate_names'=>['Synthetic Fixture Road'],'resolution'=>$n,'padding_miles'=>0.5,'epsg'=>32611,'bounds'=>[1000,2000,2200,3200],'points'=>[[1200,2400],[1900,2400],[1900,2900]],'sources'=>[
    ['points'=>[[1200,2400],[1900,2400]],'tags'=>['lanes'=>'2'],'highway'=>'secondary','direction'=>0],
    ['points'=>[[1900,2400],[1900,2900]],'tags'=>['lanes'=>'3','lanes:forward'=>'2','lanes:backward'=>'1'],'highway'=>'secondary','direction'=>0]
],'center'=>[-119.8,39.5],'oneway'=>false];
$plan['water_data']=['features'=>[['id'=>'fixture:lake','source'=>'test','kind'=>'lake','rings'=>[[[40,40],[160,40],[160,160],[40,160]],[[80,80],[120,80],[120,120],[80,120]]]]],'providers'=>['fixture'=>['status'=>'complete']]];
$plan['water_data']['features'][]=['id'=>'fixture:river','source'=>'test','kind'=>'river','rings'=>[[[20,200],[300,200],[300,240],[20,240]]]];
$plan['water_data']['flowlines']=[['points'=>[[40,220],[280,220]]]];
$file=tempnam(sys_get_temp_dir(),'sweepline-beam-test-');$phases=[];
try {
    $report=BeamMap::build($plan,$raster,str_repeat('a',32),$file,static function($message)use(&$phases){if($message!==null)$phases[]=$message;});
    $archive=new ZipArchive();check($archive->open($file)===true,'Generated BeamNG ZIP opens');
    $base='levels/sweepline_aaaaaaaaaaaaaaaa/';
    $info=json_decode($archive->getFromName($base.'info.json'),true,512,JSON_THROW_ON_ERROR);
    check($info['title']===$plan['name'].' - '.SweepLine\Version::label() && !str_contains($archive->getFromName($base.'info.json'),'\u') && str_contains($archive->getFromName($base.'info.json'),'Café'),'BeamNG menu title uses plain separator and UTF-8 names without escaped Unicode labels');
    check($report['sweepline_version']===SweepLine\Version::number() && str_contains($archive->getFromName('INSTALL.txt'),SweepLine\Version::label()) && str_contains($archive->getFromName('build.log'),SweepLine\Version::label()),'BeamNG archive preserves generating version in report, instructions and build log');
    check($info['defaultSpawnPointName']==='spawns_start' && count($info['spawnPoints'])===2,'Two selectable spawns with Start default');
    check($info['size']===[1200,1200],'World dimensions remain true-scale and square');
    foreach(['preview.png','spawn-start.png','spawn-end.png'] as $preview){
        $image=imagecreatefromstring($archive->getFromName($base.$preview));check(imagesx($image)===1024 && imagesy($image)===576,'BeamNG labeled preview has widescreen dimensions');
        $textPixels=0;for($y=12;$y<48;$y++)for($x=20;$x<240;$x++)if((imagecolorat($image,$x,$y)&0xffffff)===0xffffff)$textPixels++;
        check($textPixels>100,'Preview includes visible SweepLine text');unset($image);
    }
    foreach($info['spawnPoints'] as $spawnPoint)check($archive->locateName($base.$spawnPoint['preview'])!==false,'Spawn picker references an included thumbnail');
    $nav=json_decode($archive->getFromName($base.'map.json'),true,512,JSON_THROW_ON_ERROR);
    check(count($nav['segments'])>1,'Selected road includes minimap navigation edges');
    $root=json_decode(trim($archive->getFromName($base.'main/items.level.json')),true,512,JSON_THROW_ON_ERROR);
    check($root['class']==='SimGroup' && $root['name']==='MissionGroup','Scene root matches installed v0.40 layout');
    $objects=array_map(static fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),explode("\n",trim($archive->getFromName($base.'main/MissionGroup/items.level.json'))));
    $nativeWater=array_values(array_filter($objects,static fn($o)=>$o['class']==='River'));
    check(count($nativeWater)===5&&$report['water_data']['rendered_features']===2,'Complete BeamNG archive includes bounded native water and provenance report');
    $waterLog=json_decode(substr($archive->getFromName('build.log'),strpos($archive->getFromName('build.log'),'{')),true,512,JSON_THROW_ON_ERROR);
    check(isset($waterLog['inputs']['water_data'])&&$waterLog['analysis_and_rejections']['water_data']['native_water_objects']===5,'Water source inputs and decisions are included in build.log');

    $clock=array_values(array_filter($objects,fn($o)=>$o['class']==='TimeOfDay'))[0];
    check(abs(fmod($clock['time']+0.5,1)*24-7)<0.000001 && abs(fmod($clock['startTime']+0.5,1)*24-7)<0.000001 && $clock['version']===2 && !$clock['play'],'Both initial and runtime BeamNG clock fields specify fixed 7 AM');
    $spawns=array_values(array_filter($objects,static fn($o)=>$o['class']==='SpawnSphere'));
    check(count($spawns)===2 && $spawns[0]['position']!==$spawns[1]['position'],'Endpoint spawn objects are distinct');
    check($archive->locateName($base.'art/shapes/rock.dae')===false && $archive->locateName($base.'art/shapes/tree.dae')===false && $archive->locateName($base.'art/shapes/bush.dae')===false,'Export contains no procedural rock or plant placeholders');
    $levelInfo=array_values(array_filter($objects,fn($o)=>$o['class']==='LevelInfo'))[0];
    check($levelInfo['visibleDistance']===16000 && $levelInfo['fogDensity']===0.000015,'Generated level explicitly sets long draw distance and light fog');
    check(count(array_filter($objects,fn($o)=>$o['class']==='Forest'))===1,'Stock vegetation uses native Forest rendering');
    $forestDefs=json_decode($archive->getFromName($base.'art/forest/managedItemData.json'),true);
    $forestCount=0;
    foreach($forestDefs as $type=>$definition){
        check(in_array($definition['shapeFile'],array_column(SweepLine\BeamVegetation::CATALOG,'shape'),true)&&$definition['class']==='TSForestItemData','Forest references a verified stock multi-map mesh path');
        $forestLines=trim($archive->getFromName($base.'forest/'.$type.'.forest4.json'));
        if($forestLines!=='')foreach(explode("\n",$forestLines) as $line){$instance=json_decode($line,true,512,JSON_THROW_ON_ERROR);if($instance['type']!==$type)throw new RuntimeException('Forest type mismatch');$forestCount++;}
        check($archive->locateName(ltrim($definition['shapeFile'],'/'))===false,'Stock mesh is referenced without redistributing game assets');
    }
    check($forestCount===$report['scenery_placement']['counts']['tree']+$report['scenery_placement']['counts']['bush']+$report['scenery_placement']['counts']['rock'],'All trees, bushes and rocks are serialized into native forest files');
    check(str_contains($archive->getFromName($base.'mainLevel.lua'),SweepLine\BeamVegetation::MATERIALS),'Map pre-start hook loads installed vegetation material definitions');

    $roads=array_values(array_filter($objects,static fn($o)=>$o['class']==='MeshRoad'&&str_starts_with($o['name'],'road_')));
    check(count($roads)>=2,'Lane changes create correctly textured road sections');
    check($archive->locateName($base.'art/roads/transition.color.png')===false,'Lane transitions no longer substitute edge-only unmarked road textures');

    $arches=array_values(array_filter($objects,fn($o)=>in_array($o['name']??'',['start_arch','finish_arch'],true)));
    check(count($arches)===2,'Start and Finish each have one physical arch');

    $openShoulders=array_values(array_filter($objects,static fn($o)=>str_starts_with($o['name']??'','shoulder_')));
    check($openShoulders===[],'Export contains no separate shoulder or supporting collision objects');

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
        check(-$m[3]*$dx*$sign-$m[4]*$dy*$sign>0.99,'Vehicle-facing axis points along intended travel after 180-degree spawn correction');
        $position=$spawn['position'];$rightDistance=($position[0]*$dy-$position[1]*$dx)*$sign;
        check(abs($rightDistance-2)<0.001,'Both endpoints spawn in center of right-hand travel lane');
        check(abs($m[5]+$sign*0.1/sqrt(1.01))<0.001,'Spawn pitch follows road elevation');
    }
}

// Flat reference terrain isolates road clearance from DEM slopes and sampling.
$landmarkColors="";for($band=0;$band<3;$band++)for($row=0;$row<$n;$row++)for($x=0;$x<$n;$x++)$landmarkColors.=chr($band===0?($x*2):($band===1?$row*2:70));
$flatRaster=new BeamRaster($n,$side,str_repeat(pack('g',100),$n*$n),$landmarkColors);
$flatFile=tempnam(sys_get_temp_dir(),'sl-flush-');
try{
    $flatReport=BeamMap::build($plan,$flatRaster,str_repeat('b',32),$flatFile,fn($m)=>null);
    $flatZip=new ZipArchive();$flatZip->open($flatFile);
    $flatBase='levels/sweepline_bbbbbbbbbbbbbbbb/';
    $flatObjects=array_map(fn($line)=>json_decode($line,true),explode("\n",trim($flatZip->getFromName($flatBase.'main/MissionGroup/items.level.json'))));
    $flatRoads=array_values(array_filter($flatObjects,fn($o)=>$o['class']==='MeshRoad'&&str_starts_with($o['name'],'road_')));
    $terrainObject=array_values(array_filter($flatObjects,fn($o)=>$o['class']==='TerrainBlock'))[0];
    $binary=$flatZip->getFromName($flatBase.'theTerrain.ter');$maxGap=0;$minGap=INF;
    foreach($flatRoads as $mesh)foreach($mesh['nodes'] as $point){
        $x=max(0,min($n-1,(int)round($point[0]/$side*$n)));$y=max(0,min($n-1,(int)round($point[1]/$side*$n)));
        $ground=unpack('v',substr($binary,5+($y*$n+$x)*2,2))[1]/65536*$terrainObject['maxHeight'];
        $gap=$point[2]-$ground;$maxGap=max($maxGap,$gap);$minGap=min($minGap,$gap);
    }
    check($minGap>=0 && $minGap>0.04 && $maxGap<=0.10,'Terrain recess targets 5 cm and stays within 10 cm without changing elevation sampling');
    check(abs($flatRoads[0]['nodes'][0][2]+$flatReport['elevation_origin_m']-100.25)<0.001,'Original road surface offset is 25 cm above sampled elevation');
    $mats=json_decode($flatZip->getFromName($flatBase.'art/terrains/main.materials.json'),true);
    $aerial=imagecreatefromstring($flatZip->getFromName($flatBase.'art/terrains/aerial.color.png'));
    $mat=array_values(array_filter($mats,fn($m)=>$m['class']==='TerrainMaterial'))[0];
    // Reproduce v0.40 shader: grid UV / BaseTexSize, then Y flip.
    foreach([[17,27],[83,101],[110,40]] as [$gx,$gy]){
        $tu=($gx+0.5)/$mat['baseColorBaseTexSize'];$tv=1-($gy+0.5)/$mat['baseColorBaseTexSize'];
        $px=min($n-1,(int)floor($tu*$n));$py=min($n-1,(int)floor($tv*$n));
        $actual=imagecolorat($aerial,$px,$py);$expected=BeamRaster::saturated($flatRaster->rgb($gx,$gy));
        check([($actual>>16)&255,($actual>>8)&255,$actual&255]===$expected,'Terrain shader samples the correct asymmetric aerial landmark');
    }
    check($mat['baseColorBaseTexSize']===$n && $side!==$n,'Aerial texture spans full grid even when world spacing is not one meter');
    $flatZip->close();
}finally{unlink($flatFile);}

// Elevation source resolution is independent of imagery/terrain output resolution.
$sourcePlan=['resolution'=>2048,'bounds'=>[100,200,8292,8392]];
$demGrid=SweepLine\BeamSources::grid($sourcePlan,'dem');$imageGrid=SweepLine\BeamSources::grid($sourcePlan,'aerial');
check($demGrid['size']===1024 && $imageGrid['size']===2048,'Original elevation download grid remains 1024 while imagery remains 2048');
check($demGrid['bbox']===[96,196,8288,8388] || $demGrid['bbox']===[96.0,196.0,8288.0,8388.0],'Original elevation pixel centers retain original half-cell alignment');
$lowHeights="";for($y=0;$y<4;$y++)for($x=0;$x<4;$x++)$lowHeights.=pack('g',100+$x*$x+($y%2)*3);
$low=new BeamRaster(4,80,$lowHeights,str_repeat(chr(70),48));$checkpoints=0;
$high=BeamRaster::fromElevation(8,80,$lowHeights,str_repeat(chr(70),192),4,function($m)use(&$checkpoints){$checkpoints++;});
check($high->size===8 && $high->elevationResolution()===4 && $checkpoints>0,'Terrain upsampling preserves source metadata and cancellation checkpoints');
foreach([[0,0],[12,21],[39,47],[79,79]] as [$x,$y])check(abs($high->roadHeight($x,$y)-$low->height($x,$y))<0.000001,'Road heights match original coarse elevation interpolation');
check(abs($high->cell(3,4)-$low->height(30,40))<0.00001,'Output terrain samples coarse elevation at world coordinates');
$before=$high->roadHeight(40,40);$high->put(4,4,150);
check($high->roadHeight(40,40)===$before && $high->height(40,40)===150.0,'Corridor shaping cannot modify original road elevation samples');
try{BeamRaster::fromElevation(8,80,str_repeat(pack('g',-9999),16),str_repeat(chr(70),192),4,fn($m)=>null);check(false,'Invalid source elevation rejected during upsampling');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'No synthetic'),'Invalid source elevation rejected during upsampling');}

// Structure tags apply only to collinear overlap with the selected stretch.
$structRoad=[[0,0],[100,0],[200,0],[300,0],[500,0]];
$structSource=fn($points,$tags)=>['points'=>$points,'tags'=>$tags,'highway'=>'secondary','direction'=>0];
check(SweepLine\BeamStructures::spans($structRoad,[$structSource([[700,0],[800,0]],['tunnel'=>'yes'])])===[],'Tunnel elsewhere on source data does not reject selected road');
check(SweepLine\BeamStructures::spans($structRoad,[$structSource([[150,-50],[150,50]],['tunnel'=>'yes'])])===[],'Crossing tunnel does not imply the selected road enters a tunnel');
check(SweepLine\BeamStructures::spans($structRoad,[$structSource([[100,3],[200,3]],['bridge'=>'yes'])])===[],'Parallel nearby bridge is not attributed to selected carriageway');
check(rejects(fn()=>SweepLine\BeamStructures::spans($structRoad,[$structSource([[100,0],[200,0]],['tunnel'=>'yes'])])),'Actual selected tunnel is rejected');
check(rejects(fn()=>SweepLine\BeamStructures::spans($structRoad,[$structSource([[100,0],[200,0]],['bridge'=>'yes','bridge:structure'=>'suspension'])])),'Complex bridge rejected explicitly');
check(SweepLine\BeamStructures::spans($structRoad,[$structSource([[100,0],[350,0]],['bridge'=>'yes'])])[0]['large'],'Bridge over 200 meters uses bounded large-bridge support');
check(rejects(fn()=>SweepLine\BeamStructures::spans($structRoad,[$structSource([[0,0],[100,0]],['bridge'=>'yes'])])),'Bridge without start approach is rejected');
$spans=SweepLine\BeamStructures::spans($structRoad,[$structSource([[100,0],[150,0]],['bridge'=>'yes']),$structSource([[150,0],[200,0]],['bridge'=>'yes'])]);
check(count($spans)===1 && abs($spans[0]['length_m']-100)<0.001,'Contiguous mapped bridge pieces form one span');
$bridgePlan=$plan;$bridgePlan['bounds']=[0,0,$side,$side];$bridgePlan['points']=[[100,600],[350,600],[450,600],[900,600]];
$bridgePlan['sources']=[$structSource($bridgePlan['points'],['lanes'=>'2']),$structSource([[350,600],[450,600]],['bridge'=>'yes','lanes'=>'2'])];
$bridgeHeights="";for($row=0;$row<$n;$row++)for($x=0;$x<$n;$x++)$bridgeHeights.=pack('g',($x*$side/$n>350 && $x*$side/$n<450)?80:100);
$bridgeRaster=new BeamRaster($n,$side,$bridgeHeights,$colors);$bridgeFile=tempnam(sys_get_temp_dir(),'sl-bridge-');
try{
    $bridgeReport=BeamMap::build($bridgePlan,$bridgeRaster,str_repeat('c',32),$bridgeFile,fn($m)=>null);
    $bridgeZip=new ZipArchive();$bridgeZip->open($bridgeFile);$bridgeBase='levels/sweepline_cccccccccccccccc/';
    $bridgeObjects=array_map(fn($line)=>json_decode($line,true),explode("\n",trim($bridgeZip->getFromName($bridgeBase.'main/MissionGroup/items.level.json'))));
    $deck=array_values(array_filter($bridgeObjects,fn($o)=>$o['class']==='MeshRoad' && str_starts_with($o['name'],'road_')))[0];
    $bridgeNode=array_values(array_filter($deck['nodes'],fn($p)=>$p[0]>390&&$p[0]<410))[0];
    check(abs($bridgeNode[2]+$bridgeReport['elevation_origin_m']-100.25)<0.05,'Bridge deck follows approaches rather than valley floor');
    check(abs($bridgeRaster->height(400,600)-80)<0.01,'Under-bridge terrain remains unfilled');
    check(count(array_filter($bridgeObjects,fn($o)=>str_starts_with($o['name']??'','bridge_0_rail_')))===2,'Bridge has two generic collision guardrails');
    check(count(array_filter($bridgeObjects,fn($o)=>str_starts_with($o['name']??'','bridge_0_pier_')))>0,'Elevated bridge includes generated supports');
    check(!empty($bridgeReport['bridge_warning'])&&count($bridgeReport['bridges'])===1,'Export report discloses estimated bridge geometry');
    $bridgeZip->close();
}finally{unlink($bridgeFile);}

// Roadside scenery is bounded by real object footprints, not terrain-grid masks.
$testRoad=[[100,600,100],[600,600,100],[1100,600,100]];$testProfiles=array_fill(0,3,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
$sceneryGeometry=new SweepLine\BeamScenery($testRoad,$testProfiles);
check($sceneryGeometry->allows(500,605,1),'Object footprint can meet road edge with no extra exclusion zone');
check(!$sceneryGeometry->allows(500,604.9,1),'Object footprint overlapping pavement is rejected');
check($sceneryGeometry->allows(500,664.96-1,1),'Object outer edge may meet the 200-foot scenery limit');
check(!$sceneryGeometry->allows(500,664.97-1,1),'Object footprint beyond 200 feet is rejected');
$parallelRoad=[[100,600,100],[1100,600,100],[1100,615,100],[100,615,100]];
$parallelGeometry=new SweepLine\BeamScenery($parallelRoad,array_fill(0,4,$testProfiles[0]));
check(!$parallelGeometry->allows(500,615,2),'Placement avoids adjacent road sections as well as the sampled section');
$flatSceneryRaster=new BeamRaster($n,$side,str_repeat(pack('g',100),$n*$n),$colors);
$sceneryCheckpoints=0;$placements=SweepLine\BeamScenery::place($testRoad,$testProfiles,$flatSceneryRaster,12345,function($m)use(&$sceneryCheckpoints){$sceneryCheckpoints++;});
$allAllowed=true;foreach($placements['objects'] as $object){[$x,$y]=$object['position'];if(!$sceneryGeometry->allows($x,$y,SweepLine\BeamScenery::radius($object['kind'],$object['scale'],$object['asset']??null)))$allAllowed=false;}
check($placements['additional_tree_target']>0&&$placements['additional_trees_placed']>0,'Tree-bearing corridors receive an additional tree-only placement pass');
check($placements['counts']['tree']>=$placements['additional_tree_target']&&$placements['counts']['tree']<=$placements['additional_tree_target']*2,'Additional pass aims for double trees with a bounded placement budget');
check(count($placements['objects'])>100 && $allAllowed,'Generated vegetation stays beside road without footprint overlap or distant objects');
check($placements['counts']['tree']>0 && $placements['counts']['bush']>0,'Vegetated corridor includes trees and bushes');
check(array_sum($placements['counts'])===count($placements['objects']) && $sceneryCheckpoints>0,'Scenery reports category counts and checks cancellation during placement');
check(simplexml_load_string(SweepLine\BeamAssets::shape('test_bush','bush'),SimpleXMLElement::class,LIBXML_NONET)!==false,'Generated bush asset is valid COLLADA');

$grayRaster=new BeamRaster($n,$side,str_repeat(pack('g',100),$n*$n),str_repeat(chr(170),3*$n*$n));
$grayPlacement=SweepLine\BeamScenery::place($testRoad,$testProfiles,$grayRaster,6789,fn($m)=>null);
check($grayPlacement['counts']['building']===0,'Gray rock or pavement never produces guessed buildings');
check(SweepLine\BeamScenery::radius('tree',1)>=7 && SweepLine\BeamScenery::radius('bush',1)>=1.1,'Road clearance accounts for stock vegetation mesh footprints');

check(SweepLine\BeamVegetation::choose('tree',[-82,40],300,0,0.1)==='beech_b' && SweepLine\BeamVegetation::choose('tree',[-82,40],300,0,0.8)==='beech_c','Eastern palette mixes stock beech variants');
check(SweepLine\BeamVegetation::choose('tree',[-119,39],1800,0,0.1)==='coastal_pine','Western mountain palette includes pine');
check(SweepLine\BeamVegetation::choose('bush',[-115,35],400,1,0.1)==='desert_shrub','Dry western cover includes Johnson Valley shrubs');
check(SweepLine\BeamVegetation::choose('bush',[-82,40],300,0,0.1)==='eastern_bush','Eastern bushes come from East Coast USA');
$assetsFiles=[];$multiPlacements=[];
foreach(SweepLine\BeamVegetation::CATALOG as $asset=>$definition)$multiPlacements[]=['kind'=>$definition['kind'],'asset'=>$asset,'position'=>[100,200,300],'scale'=>1];
$assetCount=SweepLine\BeamVegetation::write($multiPlacements,'sl_test',100,function($path,$bytes)use(&$assetsFiles){$assetsFiles[$path]=$bytes;});
$multiDefs=json_decode($assetsFiles['art/forest/managedItemData.json'],true);
check($assetCount===count(SweepLine\BeamVegetation::CATALOG) && count($multiDefs)===count(SweepLine\BeamVegetation::CATALOG),'Mixed stock variants serialize to distinct forest types');
$materials=array_unique(array_column(SweepLine\BeamVegetation::CATALOG,'materials'));
$materialRefsValid=true;foreach($materials as $path)if(substr_count($assetsFiles['mainLevel.lua'],$path)!==1)$materialRefsValid=false;
check($materialRefsValid,'Each used stock material library loads once despite shared variants');
check(count(SweepLine\BeamVegetation::used($multiPlacements))===count(SweepLine\BeamVegetation::CATALOG),'Export report identifies actual stock dependencies');

$taperPoints=[];$taperProfiles=[];for($i=0;$i<=20;$i++){$taperPoints[]=[$i*10,0,100];$taperProfiles[]=BeamLanes::infer(['lanes'=>$i<10?'2':'4','width'=>$i<10?'8':'15'],'secondary',0);}
$taper=SweepLine\BeamRoadSurface::taper($taperPoints,$taperProfiles);
check(abs($taper[0]['width_m']-8)<0.001 && abs($taper[20]['width_m']-15)<0.001,'Taper leaves pavement widths outside transition unchanged');
check(abs($taper[10]['width_m']-11.5)<0.001 && $taper[8]['width_m']>8 && $taper[12]['width_m']<15,'Lane width tapers on both sides of mapped transition');
check($taper[10]['transition'] && !$taper[4]['transition'],'Transition flag remains bounded to the pavement taper');
check(BeamRaster::saturated([130,130,130])===[130,130,130],'Saturation preserves neutral terrain colors');
$boosted=BeamRaster::saturated([100,140,100]);check($boosted[1]-$boosted[0]>40 && max($boosted)<=255,'Terrain saturation increases color separation without overflow');
$spikeRaster=new BeamRaster(16,160,str_repeat(pack('g',200),256),str_repeat(chr(80),768));
$spikeRoad=[[20,80,100],[140,80,100]];$spikeProfiles=array_fill(0,2,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
$correction=SweepLine\BeamRoadSurface::clearTerrain($spikeRoad,$spikeProfiles,$spikeRaster,0,fn($m)=>null);
check($correction['lowered_vertices']>0 && $spikeRaster->height(75,80)<100,'Overlap pass removes terrain above road between geometry nodes');
check($spikeRaster->height(80,10)===200.0,'Overlap correction leaves distant terrain unchanged');
$footprint=['points'=>[[480,625],[490,625],[490,635],[480,635],[480,625]],'tags'=>['building'=>'house','building:levels'=>'2']];
$placedBuildings=SweepLine\BeamBuildings::place([$footprint],$testRoad,$testProfiles,$flatSceneryRaster);
check(count($placedBuildings['objects'])===1 && $placedBuildings['objects'][0]['mapped'],'Mapped roadside footprint creates a building');
$badFootprint=$footprint;$badFootprint['points']=[[480,598],[490,598],[490,608],[480,608],[480,598]];
check(SweepLine\BeamBuildings::place([$badFootprint],$testRoad,$testProfiles,$flatSceneryRaster)['objects']===[],'Building overlapping pavement is rejected');
$building=$placedBuildings['objects'][0];check(simplexml_load_string(SweepLine\BeamAssets::shape('test_mapped','mapped-building',12,'START',$building['points'],$building['height']),SimpleXMLElement::class,LIBXML_NONET)!==false,'Mapped building extrusion is valid COLLADA');
$polyVegetation=false;foreach(array_keys($assetsFiles) as $path)if(preg_match('~art/shapes/(tree|bush).*\\.dae$~',$path))$polyVegetation=true;
check(!$polyVegetation && str_contains($assetsFiles['mainLevel.lua'],'FS:fileExists(shape)'),'Vegetation packaging contains stock references and missing-asset diagnostics without generated plants');

$sharedMaterials=SweepLine\BeamVegetation::materialLibraries(array_values(SweepLine\BeamVegetation::CATALOG));
check(count(array_filter($sharedMaterials,fn($p)=>str_ends_with($p,'/trees/main.materials.json')))===4,'Every used stock map includes parent vegetation material dependencies');
check(strpos($assetsFiles['mainLevel.lua'],'/levels/Utah/art/shapes/trees/main.materials.json')<strpos($assetsFiles['mainLevel.lua'],SweepLine\BeamVegetation::MATERIALS),'Shared vegetation materials load before species libraries');

check(SweepLine\BeamVegetation::choose('rock',[-119,39],1800,1,0.5)==='utah_rock','Eligible western rock scatter selects a stock model');
check(SweepLine\BeamVegetation::CATALOG['utah_rock']['radius']>=1.4,'Stock rock footprint conservatively covers installed mesh bounds');

check(BeamRaster::TERRAIN_SATURATION===1.25 && BeamRaster::saturated([100,140,100])[1]-BeamRaster::saturated([100,140,100])[0]>BeamRaster::saturated([100,140,100],1.2)[1]-BeamRaster::saturated([100,140,100],1.2)[0],'Reduced aerial boost retains modest color enhancement');

$mutedForest=array_fill(0,9,[65,68,75]);
$montane=SweepLine\BeamBiome::context([-119,39],2200,$mutedForest);
$lowland=SweepLine\BeamBiome::context([-119,39],500,$mutedForest);
check($montane['zone']==='montane' && $montane['tree_probability']>$lowland['tree_probability'],'Elevation and shaded canopy jointly increase mountain tree placement');
check(!$montane['water'] && $montane['tree_probability']>0.6,'Muted blue-gray forest shadows are not automatically treated as water');
$alpine=SweepLine\BeamBiome::context([-110,44],4000,$mutedForest);
check($alpine['tree_probability']===0.0,'Tree placement fades out above broad high-elevation limit');
$bare=SweepLine\BeamBiome::context([-119,39],2200,array_fill(0,9,[210,210,205]));
check($bare['tree_probability']<$montane['tree_probability']*0.1,'Bright bare or snow-like terrain suppresses mountain trees');
$water=SweepLine\BeamBiome::context([-119,39],2200,array_fill(0,9,[25,60,130]));
check($water['water'],'Coherent blue water remains excluded from scenery');
check(SweepLine\BeamVegetation::choose('tree',[-119,39],2200,2,0.8,$montane)==='coastal_pine','Montane tree palette strongly favors stock pine assets');
$otherMountain=SweepLine\BeamBiome::context([-112,41],2200,$mutedForest);
check($otherMountain['zone']==='montane' && $otherMountain['tree_probability']>0.6,'Mountain rule generalizes beyond any named road or ZIP');
$forestPixels=str_repeat(chr(65),$n*$n).str_repeat(chr(68),$n*$n).str_repeat(chr(75),$n*$n);
$mountainRaster=new BeamRaster($n,$side,str_repeat(pack('g',2200),$n*$n),$forestPixels);
$mountainPlacement=SweepLine\BeamScenery::place($testRoad,$testProfiles,$mountainRaster,54321,fn($m)=>null,[-119,39]);
check(($mountainPlacement['asset_counts']['coastal_pine']??0)>($mountainPlacement['asset_counts']['juniper_tree']??0),'Generated mountain scenery contains more pines than junipers');
check(array_sum($mountainPlacement['biome_counts'])===count($mountainPlacement['objects']),'Export records inferred biome and actual stock asset counts');

check(BeamPlan::options([])['terrain_style']==='stock' && rejects(fn()=>BeamPlan::options(['terrain_style'=>'invented'])),'Terrain style defaults to stock and rejects invalid modes');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[70,130,65]),0.02,[-119,39],500)===0,'Green lowland patches classify as grass');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[50,65,45]),0.1,[-119,39],2200)===1,'Dark vegetated montane patches classify as forest floor');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[150,115,80]),0.05,[-119,39],500)===2,'Tan patches classify as dry soil');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[125,123,120]),0.2,[-119,39],500)===3,'Moderately sloped gray patches classify as gravel');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[125,123,120]),0.7,[-119,39],500)===4,'Steep gray patches classify as rock');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[125,123,120]),0.0,[-119,39],500)===5,'Ambiguous flat gray areas retain aerial fallback');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[20,70,140]),0.1,[-119,39],500)===5 && SweepLine\BeamTerrain::category(array_fill(0,5,[230,230,225]),0.1,[-119,39],2500)===5,'Water-like and snow-like colors keep imagery without invented surfaces');
$stockFile=tempnam(sys_get_temp_dir(),'sl-stock-terrain-');
try{
    $stockPlan=$plan;$stockPlan['terrain_style']='stock';
    $stockPlan['building_data']=['footprints'=>[['points'=>[[300,430],[310,430],[310,440],[300,440],[300,430]],'tags'=>['building'=>'house']]]];
    $stockPlan['guardrail_data']=['lines'=>[['points'=>[[300,408],[500,408]],'osm_ids'=>[123],'tags'=>['barrier'=>'guard_rail']]],'source'=>'Offline explicit guardrail fixture'];
    $stockPlan['intersection_data']=['branches'=>[['points'=>[[1900,2400],[2100,2400]],'width_m'=>8]],'truncated'=>false,'limit_ft'=>600];
    $stockRaster=new BeamRaster($n,$side,$heights,$colors);
    $stockPool=new SweepLine\BeamCompute(dirname($stockFile),static fn()=>null,2);
    $stockReport=BeamMap::build($stockPlan,$stockRaster,str_repeat('d',32),$stockFile,fn($m)=>null,$stockPool);
    check($stockReport['cpu_processing']['peak_children']===2,'Generated stock-terrain export actually uses concurrent PHP helper processes');
    $stockZip=new ZipArchive();$stockZip->open($stockFile);$stockBase='levels/sweepline_dddddddddddddddd/';
    check(isset($stockReport['shadow_correction']['corrected_pixels'])&&$stockReport['shadow_correction']['corrected_pixels']===0,'Generated export records shadow correction while leaving well-lit fixture colors intact');
    check($stockReport['intersection_context']['rendered_branches']===1,'Generated ZIP report includes the connected side-road context');
    check(($stockReport['building_data']['facade_counts']['residential']??0)===1,'Generated ZIP classifies mapped residential building facades');
    $buildingMesh=$stockZip->getFromName($stockBase.'art/shapes/building_0.dae');
    check(str_contains($buildingMesh,'semantic="TEXCOORD"')&&str_contains($buildingMesh,'_building_residential')&&str_contains($buildingMesh,'_building_roof'),'Generated building has textured walls and an independent roof material');
    check(str_contains($buildingMesh,'_building_glass')&&str_contains($buildingMesh,'_building_trim')&&str_contains($buildingMesh,'_building_door'),'Generated residential building includes architectural detail materials');
    $facadeMaterial=array_values($stockReport['building_data']['facade_materials'])[0];
    check(isset($facadeMaterial['normal'],$facadeMaterial['specular']),'Export report records the actual facade texture and shading channels');
    $contextScene=$stockZip->getFromName($stockBase.'main/MissionGroup/items.level.json');
    check($stockReport['guardrail_data']['placed_runs']===1&&$stockReport['guardrail_data']['terminals']===2,'Generated ZIP report includes mapped guardrail placement and rigid terminals');
    check($stockZip->locateName($stockBase.'art/guardrails/rail-0.dae')!==false&&str_contains($contextScene,'"name":"mapped_guardrail_0"'),'Generated BeamNG scene references its mapped guardrail collision mesh');
    $guardMaterials=json_decode($stockZip->getFromName($stockBase.'art/guardrails/main.materials.json'),true);
    check(array_values($guardMaterials)[0]['groundType']==='METAL','Guardrail collision material uses the metal ground type');

    check(str_contains($contextScene,'"class":"TSStatic","name":"intersection_context_0_surface"')&&$stockZip->locateName($stockBase.'art/roads/side-0.dae')!==false,'Generated level includes one welded pavement mesh for the side road and connector');
    $junctionObjects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($contextScene)));
    check($stockZip->locateName($stockBase.'art/roads/primary.dae')!==false&&count(array_filter($junctionObjects,static fn($o)=>($o['class']??'')==='TSStatic'&&($o['name']??'')==='road_surface'))===1,'Junction exports use one explicit primary collision surface sharing side-road boundaries');
    check(count(array_filter($junctionObjects,static fn($o)=>($o['class']??'')==='MeshRoad'&&str_starts_with($o['name']??'','road_')))===0,'Junction exports contain no independent smoothed primary MeshRoad overlapping the shared ribbon');
    $visualEdges=array_values(array_filter($junctionObjects,static fn($o)=>str_ends_with($o['name']??'','visual_edge')));
    check(count($visualEdges)>=2&&count(array_filter($visualEdges,static fn($o)=>$o['collisionType']!=='None'))===0,'Primary and side pavement edges render with thickness but have no seam collision sidewalls');
    check($stockZip->locateName($stockBase.'art/roads/primary-edge.dae')!==false,'Generated map includes primary pavement visual-edge geometry');
    $stockTer=$stockZip->getFromName($stockBase.'theTerrain.ter');$offset=5+3*$n*$n;
    check(unpack('V',substr($stockTer,$offset,4))[1]===8,'Stock terrain writes seven physical classes plus aerial fallback');
    $stockMaterials=json_decode($stockZip->getFromName($stockBase.'art/terrains/main.materials.json'),true);
    $stockLayer=$stockMaterials['sl_dddddddddddd_terrain_0'];$stockFallback=$stockMaterials['sl_dddddddddddd_terrain_5'];
    check(str_starts_with($stockLayer['baseColorDetailTex'],'/assets/materials/terrain/') && $stockFallback['baseColorBaseTexSize']===$n,'Stock layers reference shared assets while fallback spans whole map');
    $fallbackImage=getimagesizefromstring($stockZip->getFromName($stockBase.'art/terrains/fallback.color.png'));
    check($fallbackImage[0]===1024 && $fallbackImage[1]===1024,'Fallback base texture matches stock texture array dimensions');
    check(array_sum($stockReport['terrain_classification']['counts'])===$n*$n && $stockReport['terrain_style']==='stock','Report accounts for every terrain cell and chosen style');
    check(count($stockReport['stock_terrain_assets'])===35 && $stockZip->locateName(ltrim($stockLayer['baseColorDetailTex'],'/'))===false,'Stock terrain references all PBR channels without copying game assets');

    check($stockMaterials['sl_dddddddddddd_terrain_set']['detailTexSize']===[1024,1024],'Stock detail texture array retains source resolution rather than reducing to 64 pixels');
    foreach(SweepLine\BeamTerrain::PALETTE as $layerId=>$layer)if(isset($layer['color'])){
        $layerMaterial=$stockMaterials['sl_dddddddddddd_terrain_'.$layerId];
        $image=imagecreatefromstring($stockZip->getFromName($stockBase.'art/terrains/palette-'.$layerId.'.color.png'));
        $color=imagecolorat($image,400,400);$rgb=[($color>>16)&255,($color>>8)&255,$color&255];
        check($rgb===SweepLine\BeamTerrain::blendedColor($layerId,[65,110,55])&&imagesx($image)===1024,'Generated base color for '.$layer['name'].' uses the photo-balanced tint at full base resolution');
        unset($image);
        check(str_starts_with($layerMaterial['baseColorDetailTex'],$layer['prefix'])&&$layerMaterial['baseColorDetailStrength'][0]>0,'Stock '.$layer['name'].' is a detail layer over the generated base');
        // v0.40 terrain.h.hlsl calculateValue: base + (2*detail-1)*strength.
        $rendered=array_map(static fn($c)=>$c/255+(2*0.5-1)*$layerMaterial['baseColorDetailStrength'][0],$rgb);
        if($layerId<2)check($rendered[1]>$rendered[0]&&$rendered[1]>$rendered[2],'Neutral stock detail preserves visibly green '.$layer['name'].' color');

        if($layerId<2){
            // Worst possible detail tint: red=1, green=0, blue=1, at full near strength.
            $strength=$layerMaterial['baseColorDetailStrength'][0];
            check($rgb[1]/255-$strength>max($rgb[0]/255+$strength,$rgb[2]/255+$strength),'Even extreme near detail cannot turn '.$layer['name'].' brown');
            check($layerMaterial['normalDetailStrength'][0]<=0.25&&$layerMaterial['aoDetailStrength'][0]<=0.04,'Vegetation close-up normals and AO do not overwhelm base shading');
        }
        check($layerMaterial['heightDetailStrength']===[0,0],'Detail height cannot move '.$layer['name'].' material borders with camera distance');

        if($layerId===2)check(min($rendered)>0.35&&$rendered[0]>$rendered[2],'Neutral soil detail preserves lighter warm brown instead of gray/dark base');
    }

    $stockZip->close();
}finally{unlink($stockFile);}

// A one-pixel-wide stripe must survive without becoming a coarse square block.
$detailSize=512;$detailColors="";
for($band=0;$band<3;$band++)for($y=0;$y<$detailSize;$y++)for($x=0;$x<$detailSize;$x++)$detailColors.=chr(($x===101?[220,100,30]:[50,160,20])[$band]);
$detailRaster=new BeamRaster($detailSize,512,str_repeat(pack('g',500),$detailSize*$detailSize),$detailColors);
$detailHeartbeats=0;$detailClasses=SweepLine\BeamTerrain::classify($detailRaster,[-119,39],[],function($m)use(&$detailHeartbeats){$detailHeartbeats++;});
check($detailClasses['analysis_resolution']===512 && strlen($detailClasses['layers'])===512*512,'Terrain classification uses full source resolution above old 256 cap');
check(ord($detailClasses['layers'][200*512+101])===2 && ord($detailClasses['layers'][200*512+100])===0 && ord($detailClasses['layers'][200*512+102])===0,'Single-pixel material feature survives without enlarging adjacent pixels');
check($detailHeartbeats>100 && abs($detailClasses['cell_spacing_m']-1)<0.000001,'Full-resolution classification retains cancellation progress and reports spacing');

check(SweepLine\BeamTerrain::category(array_fill(0,5,[200,180,140]),0.02,[-119,39],1800)===7,'Warm pale sandy ground receives stock sand instead of aerial fallback');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[210,208,200]),0.1,[-119,39],1800)===6,'Pale exposed stone receives stock stone instead of snow fallback');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[70,69,65]),0.35,[-119,39],2200)===6,'Shaded neutral mineral slopes receive stone rather than false forest floor');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[70,69,65]),0.65,[-119,39],2200)===4,'Steeper shaded mineral slopes receive rock');
check(str_contains(SweepLine\BeamTerrain::PALETTE[1]['prefix'],'t_ind_grass')&&str_contains(SweepLine\BeamTerrain::PALETTE[2]['prefix'],'t_dirt_sandy'),'Forest floor and soil use replacement stock textures');

$roofRaster=new BeamRaster(16,16,str_repeat(pack('g',500),256),str_repeat(chr(50),256).str_repeat(chr(160),256).str_repeat(chr(20),256));
$roofClasses=SweepLine\BeamTerrain::classify($roofRaster,[-119,39],[['points'=>[[3,3],[10,3],[10,10],[3,10],[3,3]]]],static function($m){});
check(ord($roofClasses['layers'][6*16+6])===5 && ord($roofClasses['layers'][1*16+1])===0,'Mapped roof interiors retain aerial fallback without replacing surrounding grass');

check(SweepLine\BeamTerrain::category(array_fill(0,5,[50,65,45]),0.8,[-119,39],2200)===1,'Steep green forest stays forest rather than bare rock');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[65,68,75]),0.35,[-119,39],2200)===1,'Muted blue-green canopy shadows stay forest rather than stone');
check(SweepLine\BeamTerrain::category(array_fill(0,5,[110,114,105]),0.7,[-119,39],500)===0,'Subtle green grass on steep terrain stays vegetation');
check(SweepLine\BeamTerrain::category([[65,68,75],[65,68,75],[65,68,75],[65,68,75],[100,100,95]],0.35,[-119,39],2200)===1,'Mixed canopy neighborhood retains vegetation despite a bare pixel');
check(str_contains(SweepLine\BeamTerrain::PALETTE[1]['prefix'],'/grass/ind_grass/') && str_contains(SweepLine\BeamTerrain::PALETTE[4]['prefix'],'terrain_dirt_rocky_large'),'Forest uses grass blades and exposed rock uses a lighter replacement');

$archRoad=[[0,0,0],[20,0,2],[20,40,6]];
$archProfiles=array_fill(0,3,['width_m'=>6]);
$startAnchor=BeamMap::archAnchor($archRoad,$archProfiles,['position'=>[0,-1,0]],false);
$finishAnchor=BeamMap::archAnchor($archRoad,$archProfiles,['position'=>[21,40,6]],true);
check(abs($startAnchor['point'][0]-20)<0.001&&abs($startAnchor['point'][1]-10.48)<0.001,'START arch follows 100 feet of curved road from the start spawn');
check(abs($finishAnchor['point'][1]-9.52)<0.001&&$finishAnchor['dy']===-1.0,'FINISH arch moves 100 feet inward in the finish spawn travel direction');
check(abs($startAnchor['point'][2]-3.048)<0.001&&$startAnchor['dy']===1.0,'Moved arch uses the new road elevation and tangent');
$shortAnchor=BeamMap::archAnchor([[0,0,0],[5,0,1]],array_fill(0,2,['width_m'=>6]),['position'=>[0,0,0]],false);
check($shortAnchor['point']===[5,0,1],'Short-road arch movement clamps at the endpoint');

foreach([0,1,2,3,4,6,7] as $id){
    $base=SweepLine\BeamTerrain::PALETTE[$id]['color'];
    check(SweepLine\BeamTerrain::blendedColor($id,[0,0,0])===$base&&SweepLine\BeamTerrain::blendedColor($id,[240,240,240])===$base&&SweepLine\BeamTerrain::blendedColor($id,[20,60,150])===$base,'Black, snow and water-like aerial samples retain '.$id.' palette');
    $dark=SweepLine\BeamTerrain::blendedColor($id,[45,50,40]);$light=SweepLine\BeamTerrain::blendedColor($id,[180,170,140]);
    check($dark!==$light&&min($dark)>=min($base)*0.84,'Aerial brightness varies '.$id.' while limiting shadow darkening');
}
foreach([0,1] as $id)foreach([[70,60,30],[50,80,40],[170,150,120]] as $sample){
    $rgb=SweepLine\BeamTerrain::blendedColor($id,$sample);
    check(max($rgb)<=255&&min($rgb)>=0,'Photo-balanced vegetation tint stays within RGB bounds');
}
$mutedGrass=SweepLine\BeamTerrain::blendedColor(0,[110,115,105]);
check($mutedGrass===[92,109,70],'Muted aerial vegetation meets the previous tint and exposure-matched photograph halfway');
check(SweepLine\BeamRaster::TERRAIN_SATURATION===1.25,'Aerial fallback saturation lies halfway between original imagery and previous boost');
$tintRaster=new BeamRaster(2,2,str_repeat(pack('g',100),4),chr(180).chr(180).chr(50).chr(50).chr(160).chr(160).chr(70).chr(70).chr(120).chr(120).chr(40).chr(40));
$tintField=SweepLine\BeamTerrain::tintPixels($tintRaster,static function($m){});
check(strlen($tintField)===1024*1024*3&&ord($tintField[0])>ord($tintField[(1023*1024)*3]),'Blended aerial field retains north-up orientation and full base resolution');

$strongTint=SweepLine\BeamTerrain::blendedColor(4,[180,170,140]);
check($strongTint[0]-SweepLine\BeamTerrain::PALETTE[4]['color'][0]>20,'Warm aerial highlights produce stronger local color variation than the original blend');

$markingImage=imagecreatefromstring(SweepLine\BeamPng::road(['lanes'=>3,'backward'=>1,'oneway'=>false,'transition'=>true]));
$dashRows=0;for($y=0;$y<256;$y++)if((imagecolorat($markingImage,170,$y)&0xffffff)===0xe1e1d7)$dashRows++;
check($dashRows===64&&SweepLine\BeamPng::ROAD_TEXTURE_LENGTH_M-SweepLine\BeamPng::ROAD_DASH_LENGTH_M===9,'Lane stripes use one 3-meter dash and 9-meter gap per 12-meter repeat');
check((imagecolorat($markingImage,85,180)&0xffffff)===0xebbe23,'Two-way transition retains a continuous yellow opposing-traffic divider');
check((imagecolorat($markingImage,170,20)&0xffffff)===0xe1e1d7&&(imagecolorat($markingImage,170,100)&0xffffff)!==0xe1e1d7,'Transition retains same-direction dashed lane markings with wider gaps');
check((imagecolorat($markingImage,1,180)&0xffffff)===0xebebe1,'Edge markings remain continuous through lane transitions');
unset($markingImage);


$blendRoad=[[10,30,12],[30,30,12],[50,30,12]];$blendProfiles=array_fill(0,3,['width_m'=>8]);
$blendRaster=new BeamRaster(64,64,str_repeat(pack('g',10),4096),str_repeat(chr(100),4096*3));
$originalBlend=$blendRaster->heights;$originalColors=$blendRaster->colors;
$blendResult=SweepLine\BeamRoadSurface::blendTerrain($blendRoad,$blendProfiles,$blendRaster,0,str_repeat("\0",4096),fn($m)=>null);
check(abs($blendRaster->cell(30,30)-11.95)<0.0001,'Native terrain rises to five centimeters below ordinary pavement');
check($blendRaster->cell(30,36)>$blendRaster->cell(30,38)&&$blendRaster->cell(30,38)>10,'Shoulder range smoothly slopes back to surrounding terrain');
check($blendRaster->cell(30,44)===10.0&&$blendRaster->colors===$originalColors,'Terrain outside shoulder range and terrain material colors stay unchanged');
$fullyProtected=new BeamRaster(64,64,$originalBlend,$originalColors);
$protectedBlend=SweepLine\BeamRoadSurface::blendTerrain($blendRoad,$blendProfiles,$fullyProtected,0,str_repeat("\1",4096),fn($m)=>null);
check($fullyProtected->heights===$originalBlend&&$protectedBlend['changed_vertices']===0,'Native terrain blending leaves protected bridge areas exactly unchanged');
$downRaster=new BeamRaster(64,64,$originalBlend,$originalColors);
$downRoad=array_map(static fn($p)=>[$p[0],$p[1],8],$blendRoad);
SweepLine\BeamRoadSurface::blendTerrain($downRoad,$blendProfiles,$downRaster,0,str_repeat("\0",4096),fn($m)=>null);
check(abs($downRaster->cell(30,30)-7.95)<0.0001&&$downRaster->cell(30,38)>7.95,'Native terrain can lower uphill ground with the same gradual transition');
check(str_contains($blendResult['method'],'Native terrain only')&&$blendResult['blend_width_m']>=6,'Export reports the native terrain approach and transition width');

$gradeSize=32;$gradeStep=3.5207388128021364;$gradeHeights='';
for($y=0;$y<$gradeSize;$y++)for($x=0;$x<$gradeSize;$x++)$gradeHeights.=pack('g',100+0.1*$x*$gradeStep+0.2);
$gradeRaster=new BeamRaster($gradeSize,$gradeSize*$gradeStep,$gradeHeights,str_repeat(chr(100),$gradeSize*$gradeSize*3));
$gradeRoad=[[4*$gradeStep,16.25*$gradeStep,100+0.4*$gradeStep+0.25],[28*$gradeStep,16.25*$gradeStep,100+2.8*$gradeStep+0.25]];
$gradeProfiles=array_fill(0,2,['width_m'=>8]);$beforeGrade=$gradeRaster->heights;
$gradeCorrection=SweepLine\BeamRoadSurface::clearTerrain($gradeRoad,$gradeProfiles,$gradeRaster,0,fn($m)=>null);
check($gradeRaster->heights===$beforeGrade&&$gradeCorrection['lowered_vertices']===0,'Clipping pass preserves a correctly graded shoulder instead of lowering each cell to its lowest road sample');
$gap=100+0.1*(10.6*$gradeStep)+0.25-$gradeRaster->height(10.6*$gradeStep,16.25*$gradeStep+4);
check(abs($gap-0.05)<0.001,'Five-centimeter road-edge clearance survives clipping on a ten-percent grade at real export grid spacing');

$abutmentHeights='';for($y=0;$y<40;$y++)for($x=0;$x<40;$x++)$abutmentHeights.=pack('g',$x>=12&&$x<=18?8:10.5);
$abutmentRaster=new BeamRaster(40,40,$abutmentHeights,str_repeat(chr(100),40*40*3));$beforeAbutment=clone $abutmentRaster;
$abutmentRoad=[[2,20,10],[12,20,10],[18,20,10],[36,20,10]];$abutmentProfiles=array_fill(0,4,['width_m'=>4]);
$abutmentMask=SweepLine\BeamRoadSurface::bridgeApproachMask($abutmentRoad,$abutmentProfiles,[0,10,16,34],[['start_m'=>10,'end_m'=>16]],$abutmentRaster,fn($m)=>null);
check($abutmentMask[20*40+14]==="\1"&&$abutmentMask[20*40+21]==="\0",'Bridge approach mask protects deck-interior ground while permitting landward correction');
SweepLine\BeamRoadSurface::blendTerrain($abutmentRoad,$abutmentProfiles,$abutmentRaster,0,$abutmentMask,fn($m)=>null,[['start_m'=>10,'end_m'=>16]]);
SweepLine\BeamRoadSurface::clearTerrain($abutmentRoad,$abutmentProfiles,$abutmentRaster,0,fn($m)=>null,$abutmentMask,true);
check($abutmentRaster->height(21,20)<10&&$abutmentRaster->cell(14,20)===8.0,'Raised terrain after a bridge is corrected without raising the valley floor beneath the deck');
$outsideIntact=true;for($y=0;$y<40;$y++)for($x=0;$x<40;$x++)if($abutmentMask[$y*40+$x]!=="\0"&&$abutmentRaster->cell($x,$y)!==$beforeAbutment->cell($x,$y))$outsideIntact=false;
check($outsideIntact,'Bridge approach fix leaves all terrain outside its allowed landing zones unchanged');
check($flatReport['bridge_approach_correction']===null,'Ordinary exports do not run the bridge-approach correction');

$ridgeRoad=[[20,32,20],[44,32,20]];$ridgeProfiles=array_fill(0,2,['width_m'=>8]);$ridgeHeights=str_repeat(pack('g',10),4096);
$ridgeOriginal=new BeamRaster(64,64,$ridgeHeights,str_repeat(chr(100),4096*3));
$narrowBridgeMask=str_repeat("\0",4096);for($y=28;$y<=36;$y++)for($x=16;$x<=48;$x++)$narrowBridgeMask[$y*64+$x]="\1";
$ridgeControl=clone $ridgeOriginal;
SweepLine\BeamRoadSurface::blendTerrain($ridgeRoad,$ridgeProfiles,$ridgeControl,0,$narrowBridgeMask,fn($m)=>null);
check($ridgeControl->cell(32,39)>15,'Regression fixture reproduces an artificial ridge beyond a narrow bridge mask');
$ridgeFixed=clone $ridgeOriginal;
SweepLine\BeamRoadSurface::blendTerrain($ridgeRoad,$ridgeProfiles,$ridgeFixed,0,$narrowBridgeMask,fn($m)=>null,[['start_m'=>0,'end_m'=>24]]);
check($ridgeFixed->heights===$ridgeHeights,'Elevated bridge deck samples never raise terrain beside or beneath the bridge');
$ordinaryA=clone $ridgeOriginal;$ordinaryB=clone $ridgeOriginal;
SweepLine\BeamRoadSurface::blendTerrain($ridgeRoad,$ridgeProfiles,$ordinaryA,0,str_repeat("\0",4096),fn($m)=>null);
SweepLine\BeamRoadSurface::blendTerrain($ridgeRoad,$ridgeProfiles,$ordinaryB,0,str_repeat("\0",4096),fn($m)=>null,[]);
check($ordinaryA->heights===$ordinaryB->heights,'Bridge exclusion leaves ordinary shoulder height output byte-for-byte unchanged');

$curveFile=tempnam(sys_get_temp_dir(),'sl-rounded-map-');
try{
    $curvePlan=$plan;$curvePlan['points']=[[1200,2400],[1600,2400],[1900,2500],[1900,2900]];
    $curveRaster=new BeamRaster($n,$side,$heights,$colors);
    $curveReport=BeamMap::build($curvePlan,$curveRaster,str_repeat('f',32),$curveFile,static fn($m)=>null);
    $curveZip=new ZipArchive();$curveZip->open($curveFile);$curveBase='levels/sweepline_ffffffffffffffff/';
    check($curveReport['curve_smoothing']['rounded']&&$curveReport['curve_smoothing']['explicit_pavement']&&$curveZip->locateName($curveBase.'art/roads/primary.dae')!==false,'Rounded roads use explicit pavement so engine spline interpolation cannot introduce new overshoot');
    check($curveReport['paved_shoulder_each_side_ft']===2,'Export report records the two-foot paved shoulder on each side');
    $curveZip->close();
}finally{unlink($curveFile);}
