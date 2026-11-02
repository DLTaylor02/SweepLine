<?php
declare(strict_types=1);
namespace SweepLine;
use ZipArchive;
final class BeamMap
{
    public const ROAD_CLEARANCE_M=0.01;
    private static function json(mixed $value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);}
    public static function spawn(array $road,array $profiles,bool $end,bool $oneway): array
    {
        // Move about 30 m inward to leave clearance from the mesh endpoint.
        $i=$end?count($road)-2:1;$distance=0.0;
        while(($end?$i>1:$i<count($road)-2) && $distance<30){$j=$i+($end?-1:1);$distance+=hypot($road[$j][0]-$road[$i][0],$road[$j][1]-$road[$i][1]);$i=$j;}
        $p=$road[$i];$a=$road[$i-1];$b=$road[$i+1];$profile=$profiles[$i];$reverse=$end&&!$oneway;
        $dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy)?:1;$dz=($b[2]-$a[2])/$length;
        $dx/=$length;$dy/=$length;
        $laneWidth=$profile['width_m']/$profile['lanes'];
        $offset=$profile['lanes']>1?$profile['width_m']/2-$laneWidth/2:0;
        if($reverse){$offset=-$offset;$dx=-$dx;$dy=-$dy;$dz=-$dz;}
        // Lane position uses original road direction; frame +Y follows travel.
        $ox=($b[1]-$a[1])/$length*$offset;$oy=-($b[0]-$a[0])/$length*$offset;
        $norm=sqrt(1+$dz*$dz);$fx=$dx/$norm;$fy=$dy/$norm;$fz=$dz/$norm;
        $right=[$dy,-$dx,0];$up=[-$dx*$fz,-$dy*$fz,1/$norm];
        return ['class'=>'SpawnSphere','name'=>$end?'spawns_end':'spawns_start','__parent'=>'MissionGroup','position'=>[$p[0]+$ox,$p[1]+$oy,$p[2]+0.8],
            'rotationMatrix'=>[$right[0],$fx,$up[0],$right[1],$fy,$up[1],0,$fz,$up[2]],'scale'=>[1,1,1],'dataBlock'=>'SpawnSphereMarker','autoplaceOnSpawn'=>false];
    }
    public static function build(array $plan,BeamRaster $raster,string $id,string $zipPath,callable $progress): array
    {
        $name='sweepline_'.substr($id,0,16);$base='levels/'.$name.'/';$path='/'.$base;$n=$raster->size;$side=$raster->side;$step=$side/$n;
        $world=BeamPlan::sample($plan['points']);$profiles=BeamLanes::profiles($world,$plan['sources'],$plan['bounds']);
        $points=[];$rawHeights=[];
        foreach($world as $p){$x=$p[0]-$plan['bounds'][0];$y=$p[1]-$plan['bounds'][1];$points[]=[$x,$y];$rawHeights[]=$raster->height($x,$y);}
        $min=INF;$max=-INF;$black=0;$categories=str_repeat("\0",$n*$n);
        $progress('Validating elevation and estimating regional land cover');
        for($y=0;$y<$n;$y++) {
            if($y%32===0)$progress(null);
            for($x=0;$x<$n;$x++){$h=$raster->cell($x,$y);$min=min($min,$h);$max=max($max,$h);$rgb=$raster->rgb($x,$y);if(array_sum($rgb)<6)$black++;$categories[$y*$n+$x]=chr(BeamRaster::cover($rgb));}
        }
        if($black/($n*$n)>0.1)throw new \RuntimeException('NAIP aerial coverage is unavailable in a substantial part of this square. No fabricated imagery was substituted.');
        $origin=$min-32;$maxHeight=max(64,ceil(($max-$origin+32)/64)*64);
        $road=[];$estimated=0;$chain=[0.0];
        for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        foreach($points as $i=>$p) {
            $sum=0.0;$weights=0.0;
            $left=$i;while($left>0 && $chain[$i]-$chain[$left-1]<40)$left--;
            for($j=$left;$j<count($points) && $chain[$j]-$chain[$i]<40;$j++){$weight=exp(-0.5*(($chain[$j]-$chain[$i])/12)**2);$sum+=$rawHeights[$j]*$weight;$weights+=$weight;}
            $z=$sum/$weights;
            if($i>0 && abs($z-($road[$i-1][2]+$origin-self::ROAD_CLEARANCE_M))/max(0.01,hypot($p[0]-$points[$i-1][0],$p[1]-$points[$i-1][1]))>0.35)throw new \RuntimeException('The sampled elevation creates an unsupported road grade. Higher-quality road elevation is required for this stretch.');
            $road[]=[$p[0],$p[1],$z-$origin+self::ROAD_CLEARANCE_M];if($profiles[$i]['estimated_lanes'])$estimated++;
        }
        $progress('Shaping the road corridor while preserving surrounding terrain');
        $nearest=str_repeat(pack('g',INF),$n*$n);$corridor=str_repeat("\0",$n*$n);
        foreach($road as $i=>$p) {
            if($i%100===0)$progress(null);$width=$profiles[$i]['width_m'];$carveRadius=$width/2+max(3,2*$step);$radius=max($carveRadius,$width/2+20+2*$step);$cells=(int)ceil($radius/$step);
            $cx=(int)round($p[0]/$step);$cy=(int)round($p[1]/$step);
            for($y=max(0,$cy-$cells);$y<=min($n-1,$cy+$cells);$y++)for($x=max(0,$cx-$cells);$x<=min($n-1,$cx+$cells);$x++) {
                $distance=hypot($x*$step-$p[0],$y*$step-$p[1]);if($distance>$radius)continue;
                if($distance<$width/2+20+2*$step)$corridor[$y*$n+$x]="\1";
                if($distance>$carveRadius)continue;
                $offset=($y*$n+$x)*4;$best=unpack('g',substr($nearest,$offset,4))[1];if($distance>=$best)continue;
                $bytes=pack('g',$distance);for($j=0;$j<4;$j++)$nearest[$offset+$j]=$bytes[$j];
                $fade=max(0,min(1,($distance-$width/2-$step)/max(1,$step)));
                $raster->put($x,$y,($p[2]+$origin-self::ROAD_CLEARANCE_M)*(1-$fade)+$raster->cell($x,$y)*$fade);
                if($distance<$width/2+$step)$corridor[$y*$n+$x]="\1";
            }
        }
        unset($nearest);
        $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Could not create BeamNG archive.');
        $put=static function($file,$bytes)use($zip,$base){if(!$zip->addFromString($base.$file,$bytes))throw new \RuntimeException('Could not write BeamNG archive.');};
        try {
            $prefix='sl_'.substr($id,0,12);$materials=[];$terrainNames=[];$ground=['GRASS','DIRT','ROCK','CONCRETE','DIRT'];
            $progress('Writing collision terrain and generic surface materials');
            for($i=0;$i<5;$i++) {
                $mat=$prefix.'_terrain_'.$i;$terrainNames[]=$mat;
                $materials[$mat]=['class'=>'TerrainMaterial','name'=>$mat,'internalName'=>$mat,'baseColorBaseTex'=>$path.'art/terrains/aerial.color.png','baseColorBaseTexSize'=>$side,'baseColorDetailTex'=>$path.'art/terrains/detail.color.png','baseColorDetailTexSize'=>5,'baseColorDetailStrength'=>[0.15,0],'normalBaseTex'=>$path.'art/terrains/flat.normal.png','normalBaseTexSize'=>$side,'roughnessBaseTex'=>$path.'art/terrains/roughness.data.png','roughnessBaseTexSize'=>$side,'aoBaseTex'=>$path.'art/terrains/ao.data.png','aoBaseTexSize'=>$side,'heightBaseTex'=>$path.'art/terrains/roughness.data.png','heightBaseTexSize'=>$side,'groundmodelName'=>$ground[$i]];
            }
            $terrain=chr(9).pack('V',$n);
            for($y=0;$y<$n;$y++){
                if($y%32===0)$progress(null);
                for($x=0;$x<$n;$x++)$terrain.=pack('v',max(0,min(65535,(int)round(($raster->cell($x,$y)-$origin)/$maxHeight*65536))));
            }
            $terrain.=$categories.pack('V',count($terrainNames));foreach($terrainNames as $material)$terrain.=chr(strlen($material)).$material;
            $put('theTerrain.ter',$terrain);unset($terrain);
            $put('theTerrain.terrain.json',self::json(['version'=>9,'datafile'=>$path.'theTerrain.ter','size'=>$n,'heightMapSize'=>$n*$n,'heightMapItemSize'=>2,'layerMapSize'=>$n*$n,'layerMapItemSize'=>1,'materials'=>$terrainNames]));
            $materials[$prefix.'_terrain_set']=['class'=>'TerrainMaterialTextureSet','name'=>$prefix.'_terrain_set','baseTexSize'=>[$n,$n],'macroTexSize'=>[64,64],'detailTexSize'=>[64,64]];
            $put('art/terrains/main.materials.json',self::json($materials));
            $put('art/terrains/flat.normal.png',BeamPng::rgb($n,$n,static fn()=>[128,128,255]));
            $put('art/terrains/roughness.data.png',BeamPng::rgb($n,$n,static fn()=>[190,190,190]));
            $put('art/terrains/ao.data.png',BeamPng::rgb($n,$n,static fn()=>[255,255,255]));
            $put('art/terrains/detail.color.png',BeamPng::rgb(64,64,static fn($x,$y)=>[128+(($x*17+$y*23)%17)-8,128+(($x*17+$y*23)%17)-8,128+(($x*17+$y*23)%17)-8]));
            $put('art/terrains/aerial.color.png',BeamPng::rgb($n,$n,static fn($x,$y)=>$raster->rgb($x,$n-1-$y)));
            $objects=[['class'=>'LevelInfo','name'=>'theLevelInfo','__parent'=>'MissionGroup','levelName'=>$plan['name']],
                ['class'=>'ScatterSky','name'=>'theSky','__parent'=>'MissionGroup','sunSize'=>1,'skyBrightness'=>40,'exposure'=>1,'elevation'=>50,'azimuth'=>180,'ambientScale'=>[1,1,1,1],'sunScale'=>[1,1,1,1],'ambientScaleGradientFile'=>'art/sky_gradients/default/gradient_ambient.png','sunScaleGradientFile'=>'art/sky_gradients/default/gradient_sunscale.png','colorizeGradientFile'=>'art/sky_gradients/default/gradient_colorize.png'],
                ['class'=>'TimeOfDay','name'=>'timeOfDay','__parent'=>'MissionGroup','time'=>0.35,'play'=>false],
                ['class'=>'TerrainBlock','name'=>'theTerrain','__parent'=>'MissionGroup','position'=>[0,0,0],'terrainFile'=>$path.'theTerrain.ter','squareSize'=>$step,'maxHeight'=>$maxHeight,'baseTexSize'=>1024,'castShadows'=>true,'materialTextureSet'=>$prefix.'_terrain_set']];
            $roadMaterials=[];$chunks=[];$last=null;
            foreach($road as $i=>$p) {
                $profile=$profiles[$i];$key=$profile['lanes'].'_'.$profile['backward'].'_'.(int)$profile['oneway'];
                if(!isset($roadMaterials[$key])) {
                    $mat=$prefix.'_road_'.$key;$texture='art/roads/'.$key.'.color.png';
                    $roadMaterials[$key]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'groundType'=>'ASPHALT','Stages'=>[['diffuseMap'=>$path.$texture,'diffuseColor'=>[1,1,1,1],'specularPower'=>8,'useAnisotropic'=>true]]];
                    $put($texture,BeamPng::road($profile));
                }
                if($last!==$key) {
                    $chunks[]=['key'=>$key,'nodes'=>$i>0?[array_merge($road[$i-1],[$profiles[$i-1]['width_m'],0.5,0,0,1])]:[]];$last=$key;
                }
                $chunks[count($chunks)-1]['nodes'][]=array_merge($p,[$profile['width_m'],0.5,0,0,1]);
            }
            foreach($chunks as $i=>$chunk)if(count($chunk['nodes'])>1) {
                $mat=$prefix.'_road_'.$chunk['key'];
                $objects[]=['class'=>'MeshRoad','name'=>'road_'.$i,'__parent'=>'MissionGroup','topMaterial'=>$mat,'bottomMaterial'=>$mat,'sideMaterial'=>$mat,'textureLength'=>12,'breakAngle'=>1,'widthSubdivisions'=>1,'nodes'=>$chunk['nodes']];
            }
            $put('art/roads/main.materials.json',self::json(array_combine(array_column($roadMaterials,'name'),array_values($roadMaterials))));
            $start=self::spawn($road,$profiles,false,$plan['oneway']);$end=self::spawn($road,$profiles,true,$plan['oneway']);$objects[]=$start;$objects[]=$end;
            $archMaterials=[];
            foreach([['start',$start,'START',[0.08,0.35,0.8,1]],['finish',$end,'FINISH',[0.15,0.18,0.23,1]]] as [$archName,$spawn,$label,$color]){
                $best=INF;$index=0;foreach($road as $i=>$point){$d=hypot($point[0]-$spawn['position'][0],$point[1]-$spawn['position'][1]);if($d<$best){$best=$d;$index=$i;}}
                $point=$road[$index];$span=$profiles[$index]['width_m']+4;
                $m=$spawn['rotationMatrix'];$len=hypot($m[1],$m[4])?:1;$dx=$m[1]/$len;$dy=$m[4]/$len;
                foreach(['arch','arch-label'] as $kind){
                    $mat=$prefix.'_'.$archName.'_'.$kind;$file='art/shapes/'.$archName.'-'.$kind.'.dae';
                    $archMaterials[$mat]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'Stages'=>[['diffuseColor'=>$kind==='arch'?$color:[1,1,1,1]]]];
                    $put($file,BeamAssets::shape($mat,$kind,$span,$label));
                    $objects[]=['class'=>'TSStatic','name'=>$archName.'_'.$kind,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$point,'rotationMatrix'=>[$dy,$dx,0,-$dx,$dy,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>$kind==='arch'?'Visible Mesh Final':'None','decalType'=>'None'];
                }
            }
            $put('art/shapes/arches.materials.json',self::json($archMaterials));
            $segments=[];
            foreach($road as $i=>$point){
                $objects[]=['class'=>'BeamNGWaypoint','name'=>'sl_nav_'.$i,'__parent'=>'MissionGroup','position'=>$point,'scale'=>array_fill(0,3,$profiles[$i]['width_m']/2)];
                if($i>0)$segments['road_'.$i]=['nodes'=>['sl_nav_'.($i-1),'sl_nav_'.$i],'drivability'=>1,'oneWay'=>$plan['oneway'],'autoLanes'=>false,'lanesLeft'=>$profiles[$i]['backward'],'lanesRight'=>$profiles[$i]['forward'],'autoJunction'=>false,'hiddenInNavi'=>false];
            }
            $put('map.json',self::json(['segments'=>$segments]));
            $progress('Placing regional scenery clear of the driving corridor');
            $seed=(int)hexdec(substr($id,0,7));$count=0;$sceneryMaterials=[];
            foreach(['tree'=>[0.16,0.29,0.12,1],'rock'=>[0.38,0.35,0.29,1],'building'=>[0.55,0.48,0.38,1]] as $kind=>$color) {
                $mat=$prefix.'_'.$kind;$sceneryMaterials[$mat]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'Stages'=>[['diffuseColor'=>$color]]];
                $put('art/shapes/'.$kind.'.dae',BeamAssets::shape($mat,$kind));
            }
            for($attempt=0;$attempt<12000 && $count<400;$attempt++) {
                $seed=($seed*1664525+1013904223)&0xFFFFFFFF;$x=20+($seed/4294967296)*max(1,$side-40);
                $seed=($seed*1664525+1013904223)&0xFFFFFFFF;$y=20+($seed/4294967296)*max(1,$side-40);
                $cx=min($n-1,(int)($x/$step));$cy=min($n-1,(int)($y/$step));$cover=ord($categories[$cy*$n+$cx]);
                if($corridor[$cy*$n+$cx]!=="\0" || $cover===4)continue;
                $h=$raster->height($x,$y);if(abs($raster->height(min($side-1,$x+10),$y)-$h)>5)continue;
                $kind=$cover===0?'tree':($cover===3 && $seed%5===0?'building':'rock');
                if($kind==='rock' && $seed%4!==0)continue;
                $scale=$kind==='tree'&&$plan['center'][0]> -105?1.25:0.7+($seed%60)/100;
                $objects[]=['class'=>'TSStatic','name'=>'scenery_'.$count++,'__parent'=>'MissionGroup','shapeName'=>$path.'art/shapes/'.$kind.'.dae','position'=>[$x,$y,$h-$origin],'scale'=>[$scale,$scale,$scale],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
                if($attempt%100===0)$progress(null);
            }
            $put('art/shapes/main.materials.json',self::json($sceneryMaterials));
            $items='';foreach($objects as $object)$items.=json_encode($object,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
            $put('main/items.level.json',json_encode(['class'=>'SimGroup','name'=>'MissionGroup','persistentId'=>substr($id,0,8).'-'.substr($id,8,4).'-4'.substr($id,13,3).'-8'.substr($id,17,3).'-'.substr($id,20,12)],JSON_THROW_ON_ERROR)."\n");
            $put('main/MissionGroup/items.level.json',$items);
            $put('info.json',self::json(['title'=>$plan['name'].' — SweepLine','description'=>'Generated real-scale road recreation. '.implode('; ',$plan['alternate_names']).' Target: BeamNG.drive v0.40. Scenery and missing lane attributes are estimates.','authors'=>'SweepLine; OpenStreetMap contributors; USGS; USDA','size'=>[$side,$side],'defaultSpawnPointName'=>'spawns_start','spawnPoints'=>[['translationId'=>'Start','objectname'=>'spawns_start','preview'=>'spawn-start.png','description'=>'Default road start'],['translationId'=>'End','objectname'=>'spawns_end','preview'=>'spawn-end.png','description'=>$plan['oneway']?'Near road end, facing legal traffic direction':'Road end, facing back along the road']],'previews'=>['preview.png']]));
            $put('preview.png',BeamPreview::render($raster,$road,$plan['name']));
            $put('spawn-start.png',BeamPreview::render($raster,$road,$plan['name'],$start['position'],'START'));
            $put('spawn-end.png',BeamPreview::render($raster,$road,$plan['name'],$end['position'],'END'));
            $report=['target'=>'BeamNG.drive v0.40','in_game_verified'=>false,'map_side_m'=>$side,'scale'=>'1:1 local UTM meters','epsg'=>$plan['epsg'],'bounds'=>$plan['bounds'],'padding_miles'=>$plan['padding_miles'],'terrain_resolution'=>$n,'terrain_spacing_m'=>$step,'elevation_origin_m'=>$origin,'alternate_names'=>$plan['alternate_names'],'lane_estimated_samples'=>$estimated,'road_samples'=>count($road),'scenery_objects'=>$count,
                'sources'=>['elevation'=>'USGS 3DEP bare-earth elevation','aerial'=>'USGS/USDA NAIP imagery','roads'=>'OpenStreetMap contributors'],
                'limitations'=>['Road elevation is sampled from bare-earth terrain, then smoothed; banking and road engineering are estimated.','Aerial colors and regional heuristics estimate scenery; they do not reconstruct individual real objects.','Mapped bridges and tunnels are refused. Unmapped structures may be inaccurate.','Navigation graph covers only the selected road; traffic behavior requires in-game validation.','Requires in-game v0.40 validation.']];
            $put('sweepline-report.json',self::json($report));
            $zip->addFromString('INSTALL.txt',"SweepLine BeamNG.drive v0.40 map\nCopy this ZIP into the mods folder opened from BeamNG Launcher > Manage User Folder. Do not unpack. Open Free Roam, choose ".$plan['name']." — SweepLine, then Start or End.\nStart is the default spawn. Source attribution and approximation details: ".$base."sweepline-report.json\nOpenStreetMap data: https://www.openstreetmap.org/copyright\nNo BeamNG game assets are redistributed. Package structure tested; in-game compatibility must be verified in v0.40.\n");
            $progress('Finalizing the map ZIP');
        } finally {$zip->close();}
        return $report;
    }
}

