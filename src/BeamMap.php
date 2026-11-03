<?php
declare(strict_types=1);
namespace SweepLine;
use ZipArchive;
final class BeamMap
{
    public const ROAD_ELEVATION_OFFSET_M=0.25;
    public const ROAD_CLEARANCE_M=0.05;
    private static function json(mixed $value): string {return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);}
    public static function spawn(array $road,array $profiles,bool $end,bool $oneway): array
    {
        // Move about 30 m inward to leave clearance from the mesh endpoint.
        $i=$end?count($road)-2:1;$distance=0.0;
        while(($end?$i>1:$i<count($road)-2) && $distance<30){$j=$i+($end?-1:1);$distance+=hypot($road[$j][0]-$road[$i][0],$road[$j][1]-$road[$i][1]);$i=$j;}
        $p=$road[$i];$a=$road[$i-1];$b=$road[$i+1];$profile=$profiles[$i];$reverse=$end;
        $dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy)?:1;$dz=($b[2]-$a[2])/$length;
        $dx/=$length;$dy/=$length;
        $laneWidth=$profile['width_m']/$profile['lanes'];
        $offset=$profile['lanes']>1?$profile['width_m']/2-$laneWidth/2:0;
        if($reverse){$offset=-$offset;$dx=-$dx;$dy=-$dy;$dz=-$dz;}
        // Keep lane position independent of the vehicle-facing correction.
        // Scene JSON stores each local basis axis contiguously (column-major).
        $ox=($b[1]-$a[1])/$length*$offset;$oy=-($b[0]-$a[0])/$length*$offset;
        $norm=sqrt(1+$dz*$dz);$fx=$dx/$norm;$fy=$dy/$norm;$fz=$dz/$norm;
        $right=[$dy,-$dx,0];$up=[-$dx*$fz,-$dy*$fz,1/$norm];
        // Rotate local X/Y by 180 degrees; retain up/grade and spawn position.
        return ['class'=>'SpawnSphere','name'=>$end?'spawns_end':'spawns_start','__parent'=>'MissionGroup','position'=>[$p[0]+$ox,$p[1]+$oy,$p[2]+0.8],
            'rotationMatrix'=>[-$right[0],-$right[1],0,-$fx,-$fy,-$fz,$up[0],$up[1],$up[2]],'scale'=>[1,1,1],'dataBlock'=>'SpawnSphereMarker','autoplaceOnSpawn'=>false];
    }
    /** Follow road chainage from a spawn, not a straight offset across bends. */
    public static function archAnchor(array $road,array $profiles,array $spawn,bool $end,float $distance=30.48): array
    {
        $best=INF;$index=0;
        foreach($road as $i=>$point){$d=hypot($point[0]-$spawn['position'][0],$point[1]-$spawn['position'][1]);if($d<$best){$best=$d;$index=$i;}}
        $direction=$end?-1:1;$remaining=$distance;
        while(isset($road[$index+$direction])){
            $next=$index+$direction;$a=$road[$index];$b=$road[$next];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);
            if($length>0.000001&&$remaining<=$length){
                $t=$remaining/$length;
                return ['point'=>[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t,$a[2]+($b[2]-$a[2])*$t],
                    'width'=>$profiles[$index]['width_m']*(1-$t)+$profiles[$next]['width_m']*$t,
                    'dx'=>($b[0]-$a[0])/$length,'dy'=>($b[1]-$a[1])/$length];
            }
            $remaining-=$length;$index=$next;
        }
        // Short stretches stop at their endpoint rather than placing an arch off-road.
        $other=max(0,min(count($road)-1,$index-$direction));$dx=$road[$index][0]-$road[$other][0];$dy=$road[$index][1]-$road[$other][1];$length=hypot($dx,$dy)?:1;
        return ['point'=>$road[$index],'width'=>$profiles[$index]['width_m'],'dx'=>$dx/$length,'dy'=>$dy/$length];
    }

    public static function build(array $plan,BeamRaster $raster,string $id,string $zipPath,callable $progress): array
    {
        $name='sweepline_'.substr($id,0,16);$base='levels/'.$name.'/';$path='/'.$base;$n=$raster->size;$side=$raster->side;$step=$side/$n;
        $world=BeamPlan::sample($plan['points']);$profiles=BeamRoadSurface::taper($world,BeamLanes::profiles($world,$plan['sources'],$plan['bounds']));
        $points=[];$rawHeights=[];
        foreach($world as $p){$x=$p[0]-$plan['bounds'][0];$y=$p[1]-$plan['bounds'][1];$points[]=[$x,$y];$rawHeights[]=$raster->roadHeight($x,$y);}
        $min=INF;$max=-INF;$black=0;$categories=str_repeat("\0",$n*$n);
        $progress('Validating elevation and estimating regional land cover');
        for($y=0;$y<$n;$y++) {
            if($y%32===0)$progress(null);
            for($x=0;$x<$n;$x++){$h=$raster->cell($x,$y);$min=min($min,$h);$max=max($max,$h);$rgb=$raster->rgb($x,$y);if(array_sum($rgb)<6)$black++;$categories[$y*$n+$x]=chr(BeamRaster::cover($rgb));}
        }
        if($black/($n*$n)>0.1)throw new \RuntimeException('NAIP aerial coverage is unavailable in a substantial part of this square. No fabricated imagery was substituted.');
        $terrainStyle=$plan['terrain_style']??'stock';$terrainClassification=null;
        if($terrainStyle==='stock'){$progress('Classifying grass, forest floor, soil, gravel, stone, rock and sand with aerial fallback');$terrainClassification=BeamTerrain::classify($raster,$plan['center'],$plan['building_data']['footprints']??[],$progress);$categories=$terrainClassification['layers'];unset($terrainClassification['layers']);}
        $origin=$min-32;$maxHeight=max(64,ceil(($max-$origin+32)/64)*64);
        $road=[];$estimated=0;$chain=[0.0];
        for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $bridges=BeamStructures::spans($world,$plan['sources']);
        $rawHeights=BeamStructures::deckSamples($points,$chain,$rawHeights,$bridges,$raster);
        foreach($points as $i=>$p) {
            $sum=0.0;$weights=0.0;
            $left=$i;while($left>0 && $chain[$i]-$chain[$left-1]<40)$left--;
            for($j=$left;$j<count($points) && $chain[$j]-$chain[$i]<40;$j++){$weight=exp(-0.5*(($chain[$j]-$chain[$i])/12)**2);$sum+=$rawHeights[$j]*$weight;$weights+=$weight;}
            $z=$sum/$weights;
            if($i>0 && abs($z-($road[$i-1][2]+$origin-self::ROAD_ELEVATION_OFFSET_M))/max(0.01,hypot($p[0]-$points[$i-1][0],$p[1]-$points[$i-1][1]))>0.35)throw new \RuntimeException('The sampled elevation creates an unsupported road grade. Higher-quality road elevation is required for this stretch.');
            $road[]=[$p[0],$p[1],$z-$origin+self::ROAD_ELEVATION_OFFSET_M];if($profiles[$i]['estimated_lanes'])$estimated++;
        }
        $progress('Shaping the road corridor while preserving surrounding terrain');
        $nearest=str_repeat(pack('g',INF),$n*$n);
        $bridgeMask=str_repeat("\0",$n*$n);
        foreach($bridges as $bridge){[$first,$last]=BeamStructures::indices($chain,$bridge);
            for($i=$first;$i<=$last;$i++){
                if($i%100===0)$progress(null);
                if($profiles[$i]['width_m']>20)throw new \RuntimeException('Bridge width exceeds the 20-meter simple-bridge export limit.');
                $p=$road[$i];$radius=$profiles[$i]['width_m']/2+2*$step;$cells=(int)ceil($radius/$step);$cx=(int)round($p[0]/$step);$cy=(int)round($p[1]/$step);
                for($y=max(0,$cy-$cells);$y<=min($n-1,$cy+$cells);$y++)for($x=max(0,$cx-$cells);$x<=min($n-1,$cx+$cells);$x++)if(hypot($x*$step-$p[0],$y*$step-$p[1])<=$radius)$bridgeMask[$y*$n+$x]="\1";
            }
        }
        foreach($road as $i=>$p) {
            if($i%100===0)$progress(null);$width=$profiles[$i]['width_m'];$carveRadius=$width/2+max(3,2*$step);$radius=max($carveRadius,$width/2+20+2*$step);$cells=(int)ceil($radius/$step);
            $cx=(int)round($p[0]/$step);$cy=(int)round($p[1]/$step);
            for($y=max(0,$cy-$cells);$y<=min($n-1,$cy+$cells);$y++)for($x=max(0,$cx-$cells);$x<=min($n-1,$cx+$cells);$x++) {
                $distance=hypot($x*$step-$p[0],$y*$step-$p[1]);if($distance>$radius)continue;

                if($distance>$carveRadius || $bridgeMask[$y*$n+$x]!=="\0")continue;
                $offset=($y*$n+$x)*4;$best=unpack('g',substr($nearest,$offset,4))[1];if($distance>=$best)continue;
                $bytes=pack('g',$distance);for($j=0;$j<4;$j++)$nearest[$offset+$j]=$bytes[$j];
                $fade=max(0,min(1,($distance-$width/2-$step)/max(1,$step)));
                $raster->put($x,$y,($p[2]+$origin-self::ROAD_CLEARANCE_M)*(1-$fade)+$raster->cell($x,$y)*$fade);

            }
        }
        unset($nearest);
        $progress('Checking and removing terrain intersections beneath the road');
        $terrainCorrection=BeamRoadSurface::clearTerrain($road,$profiles,$raster,$origin,$progress,$bridgeMask);
        $shoulders=BeamRoadSurface::shoulders($road,$profiles,$raster,$origin,$bridgeMask,$progress);
        $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Could not create BeamNG archive.');
        $put=static function($file,$bytes)use($zip,$base){if(!$zip->addFromString($base.$file,$bytes))throw new \RuntimeException('Could not write BeamNG archive.');};
        try {
            $prefix='sl_'.substr($id,0,12);$materials=[];$terrainNames=[];$ground=['GRASS','DIRT','ROCK','CONCRETE','DIRT'];
            $progress('Writing collision terrain and generic surface materials');
            // v0.40 terrain UVs are in layer/grid units, not world meters.
            for($i=0;$i<($terrainStyle==='stock'?count(BeamTerrain::PALETTE):5);$i++) {
                $mat=$prefix.'_terrain_'.$i;$terrainNames[]=$mat;
                if($terrainStyle==='stock'){$materials[$mat]=BeamTerrain::material($mat,$i,$path,$n,$step);continue;}
                $materials[$mat]=['class'=>'TerrainMaterial','name'=>$mat,'internalName'=>$mat,'baseColorBaseTex'=>$path.'art/terrains/aerial.color.png','baseColorBaseTexSize'=>$n,'baseColorDetailTex'=>$path.'art/terrains/detail.color.png','baseColorDetailTexSize'=>5,'baseColorDetailStrength'=>[0.15,0],'normalBaseTex'=>$path.'art/terrains/flat.normal.png','normalBaseTexSize'=>$n,'roughnessBaseTex'=>$path.'art/terrains/roughness.data.png','roughnessBaseTexSize'=>$n,'aoBaseTex'=>$path.'art/terrains/ao.data.png','aoBaseTexSize'=>$n,'heightBaseTex'=>$path.'art/terrains/roughness.data.png','heightBaseTexSize'=>$n,'groundmodelName'=>$ground[$i]];
            }
            $terrain=chr(9).pack('V',$n);
            for($y=0;$y<$n;$y++){
                if($y%32===0)$progress(null);
                for($x=0;$x<$n;$x++)$terrain.=pack('v',max(0,min(65535,(int)round(($raster->cell($x,$y)-$origin)/$maxHeight*65536))));
            }
            $terrain.=$categories.pack('V',count($terrainNames));foreach($terrainNames as $material)$terrain.=chr(strlen($material)).$material;
            $put('theTerrain.ter',$terrain);unset($terrain);
            $put('theTerrain.terrain.json',self::json(['version'=>9,'datafile'=>$path.'theTerrain.ter','size'=>$n,'heightMapSize'=>$n*$n,'heightMapItemSize'=>2,'layerMapSize'=>$n*$n,'layerMapItemSize'=>1,'materials'=>$terrainNames]));
            $materials[$prefix.'_terrain_set']=['class'=>'TerrainMaterialTextureSet','name'=>$prefix.'_terrain_set','baseTexSize'=>[$terrainStyle==='stock'?1024:$n,$terrainStyle==='stock'?1024:$n],'macroTexSize'=>[64,64],'detailTexSize'=>[$terrainStyle==='stock'?1024:64,$terrainStyle==='stock'?1024:64]];
            $put('art/terrains/main.materials.json',self::json($materials));
            $put('art/terrains/flat.normal.png',BeamPng::rgb($terrainStyle==='stock'?1024:$n,$terrainStyle==='stock'?1024:$n,static fn()=>[128,128,255]));
            $put('art/terrains/roughness.data.png',BeamPng::rgb($terrainStyle==='stock'?1024:$n,$terrainStyle==='stock'?1024:$n,static fn()=>[190,190,190]));
            $put('art/terrains/ao.data.png',BeamPng::rgb($terrainStyle==='stock'?1024:$n,$terrainStyle==='stock'?1024:$n,static fn()=>[255,255,255]));
            $put('art/terrains/detail.color.png',BeamPng::rgb(64,64,static fn($x,$y)=>[128+(($x*17+$y*23)%17)-8,128+(($x*17+$y*23)%17)-8,128+(($x*17+$y*23)%17)-8]));
            $put('art/terrains/aerial.color.png',BeamPng::rgb($n,$n,static fn($x,$y)=>BeamRaster::saturated($raster->rgb($x,$n-1-$y))));
            if($terrainStyle==='stock'){
                $put('art/terrains/detail-neutral.color.png',BeamPng::rgb(1024,1024,static fn()=>[128,128,128]));
                $put('art/terrains/neutral.color.png',BeamPng::rgb(64,64,static fn()=>[128,128,128]));
                $put('art/terrains/neutral.normal.png',BeamPng::rgb(64,64,static fn()=>[128,128,255]));
                $put('art/terrains/neutral.data.png',BeamPng::rgb(64,64,static fn()=>[128,128,128]));
            }
            if($terrainStyle==='stock'){
                $progress('Blending bounded aerial colors into stock terrain materials');
                $tints=BeamTerrain::tintPixels($raster,$progress);
                foreach(BeamTerrain::PALETTE as $layerId=>$layer)if(isset($layer['color'])){
                    $put('art/terrains/palette-'.$layerId.'.color.png',BeamPng::rgb(1024,1024,static function($x,$y)use($tints,$layerId,$progress){
                        if($x===0&&$y%32===0)$progress(null);
                        $offset=($y*1024+$x)*3;
                        return BeamTerrain::blendedColor($layerId,[ord($tints[$offset]),ord($tints[$offset+1]),ord($tints[$offset+2])]);
                    }));
                }
                unset($tints);
            }
            if($terrainStyle==='stock')$put('art/terrains/fallback.color.png',BeamPng::rgb(1024,1024,static fn($x,$y)=>BeamRaster::saturated($raster->rgb((int)($x/1024*$n),$n-1-(int)($y/1024*$n)))));
            $objects=[['class'=>'LevelInfo','name'=>'theLevelInfo','__parent'=>'MissionGroup','levelName'=>$plan['name'],'visibleDistance'=>16000,'fogDensity'=>0.000015,'fogAtmosphereHeight'=>2000],
                ['class'=>'ScatterSky','name'=>'theSky','__parent'=>'MissionGroup','sunSize'=>1,'skyBrightness'=>40,'exposure'=>1,'elevation'=>50,'azimuth'=>180,'ambientScale'=>[1,1,1,1],'sunScale'=>[1,1,1,1],'ambientScaleGradientFile'=>'art/sky_gradients/default/gradient_ambient.png','sunScaleGradientFile'=>'art/sky_gradients/default/gradient_sunscale.png','colorizeGradientFile'=>'art/sky_gradients/default/gradient_colorize.png'],
                ['class'=>'TimeOfDay','name'=>'timeOfDay','__parent'=>'MissionGroup','time'=>19/24,'startTime'=>19/24,'version'=>2,'play'=>false],
                ['class'=>'TerrainBlock','name'=>'theTerrain','__parent'=>'MissionGroup','position'=>[0,0,0],'terrainFile'=>$path.'theTerrain.ter','squareSize'=>$step,'maxHeight'=>$maxHeight,'baseTexSize'=>1024,'castShadows'=>true,'materialTextureSet'=>$prefix.'_terrain_set']];
            $bridgeMaterial=$prefix.'_bridge';
            if($bridges){
                $put('art/shapes/bridge-pier.dae',BeamAssets::shape($bridgeMaterial,'bridge-pier'));
                $put('art/shapes/bridge.materials.json',self::json([$bridgeMaterial=>['class'=>'Material','name'=>$bridgeMaterial,'mapTo'=>$bridgeMaterial,'version'=>1,'Stages'=>[['diffuseColor'=>[0.48,0.49,0.5,1]]]]]));
                foreach($bridges as $bi=>$bridge){[$first,$last]=BeamStructures::indices($chain,$bridge);if($last<=$first)throw new \RuntimeException('Bridge span is too short for reliable deck geometry.');
                    foreach([-1,1] as $sideSign){$rail=[];
                        for($i=$first;$i<=$last;$i++){
                if($i%100===0)$progress(null);$p=$road[$i];$a=$road[max(0,$i-1)];$b=$road[min(count($road)-1,$i+1)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$len=hypot($dx,$dy)?:1;$offset=$sideSign*($profiles[$i]['width_m']/2+0.25);
                            $rail[]=[$p[0]+$dy/$len*$offset,$p[1]-$dx/$len*$offset,$p[2]+1.1,0.3,0.8,0,0,1];}
                        $objects[]=['class'=>'MeshRoad','name'=>'bridge_'.$bi.'_rail_'.($sideSign>0?'right':'left'),'__parent'=>'MissionGroup','topMaterial'=>$bridgeMaterial,'bottomMaterial'=>$bridgeMaterial,'sideMaterial'=>$bridgeMaterial,'breakAngle'=>1,'nodes'=>$rail];
                    }
                    $lastPier=-INF;
                    for($i=$first;$i<=$last;$i++)if($chain[$i]-$lastPier>=25){$lastPier=$chain[$i];$p=$road[$i];$ground=$raster->height($p[0],$p[1])-$origin;$height=$p[2]-0.5-$ground;
                        if($height>0.4)$objects[]=['class'=>'TSStatic','name'=>'bridge_'.$bi.'_pier_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.'art/shapes/bridge-pier.dae','position'=>[$p[0],$p[1],$ground-0.2],'scale'=>[1.5,1.5,$height+0.2],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
                    }
                }
            }
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
                $objects[]=['class'=>'MeshRoad','name'=>'road_'.$i,'__parent'=>'MissionGroup','topMaterial'=>$mat,'bottomMaterial'=>$mat,'sideMaterial'=>$mat,'textureLength'=>BeamPng::ROAD_TEXTURE_LENGTH_M,'breakAngle'=>1,'widthSubdivisions'=>1,'nodes'=>$chunk['nodes']];
            }
            $shoulderMat=$prefix.'_shoulder';
            $roadMaterials['shoulder']=['class'=>'Material','name'=>$shoulderMat,'mapTo'=>$shoulderMat,'version'=>1,'groundType'=>'DIRT','Stages'=>[['diffuseMap'=>$path.'art/roads/shoulder.color.png','diffuseColor'=>[1,1,1,1],'specularPower'=>1,'useAnisotropic'=>true]]];
            $put('art/roads/shoulder.color.png',BeamPng::rgb(64,64,static function($x,$y){$noise=(($x*17+$y*31)%19)-9;return [142+$noise,130+$noise,108+$noise];}));
            foreach($shoulders['runs'] as $runIndex=>$run)foreach(['bed','left','right'] as $strip){
                $objects[]=['class'=>'MeshRoad','name'=>'shoulder_'.$runIndex.'_'.$strip,'__parent'=>'MissionGroup','topMaterial'=>$shoulderMat,'bottomMaterial'=>$shoulderMat,'sideMaterial'=>$shoulderMat,'textureLength'=>4,'breakAngle'=>1,'widthSubdivisions'=>1,'nodes'=>array_column($run,$strip)];
            }
            unset($shoulders['runs']);

            $put('art/roads/main.materials.json',self::json(array_combine(array_column($roadMaterials,'name'),array_values($roadMaterials))));
            $start=self::spawn($road,$profiles,false,$plan['oneway']);$end=self::spawn($road,$profiles,true,$plan['oneway']);$objects[]=$start;$objects[]=$end;
            $archMaterials=[];
            foreach([['start',$start,'START',[0.08,0.35,0.8,1]],['finish',$end,'FINISH',[0.15,0.18,0.23,1]]] as [$archName,$spawn,$label,$color]){
                $anchor=self::archAnchor($road,$profiles,$spawn,$archName==='finish');
                $point=$anchor['point'];$span=$anchor['width']+4;$dx=$anchor['dx'];$dy=$anchor['dy'];
                foreach(['arch','arch-label'] as $kind){
                    $mat=$prefix.'_'.$archName.'_'.$kind;$file='art/shapes/'.$archName.'-'.$kind.'.dae';
                    $archMaterials[$mat]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'Stages'=>[['diffuseColor'=>$kind==='arch'?$color:[1,1,1,1]]]];
                    $put($file,BeamAssets::shape($mat,$kind,$span,$label));
                    $objects[]=['class'=>'TSStatic','name'=>$archName.'_'.$kind,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$point,'rotationMatrix'=>[$dy,-$dx,0,$dx,$dy,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>$kind==='arch'?'Visible Mesh Final':'None','decalType'=>'None'];
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
            $buildingData=$plan['building_data']??['footprints'=>[]];
            $mappedBuildings=BeamBuildings::place($buildingData['footprints'],$road,$profiles,$raster);
            $buildingMaterial=$prefix.'_mapped_building';
            if($mappedBuildings['objects'])$sceneryMaterials[$buildingMaterial]=['class'=>'Material','name'=>$buildingMaterial,'mapTo'=>$buildingMaterial,'version'=>1,'Stages'=>[['diffuseColor'=>[0.6,0.57,0.5,1]]]];
            foreach($mappedBuildings['objects'] as $i=>$building){$file='art/shapes/building_'.$i.'.dae';$p=$building['position'];
                $put($file,BeamAssets::shape($buildingMaterial,'mapped-building',12,'START',$building['points'],$building['height']));
                $objects[]=['class'=>'TSStatic','name'=>'mapped_building_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[$p[0],$p[1],$p[2]-$origin],'scale'=>[1,1,1],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
            }
            unset($buildingData['footprints']);$buildingData['placed']=count($mappedBuildings['objects']);$buildingData['rejected']=$mappedBuildings['rejected'];
            $scenery=BeamScenery::place($road,$profiles,$raster,$seed,$progress,$plan['center'],$mappedBuildings['objects']);
            $scenery['counts']['building']=$buildingData['placed'];$scenery['building_policy']='Only mapped convex building footprints; heights may be estimated.';

            $nativeVegetation=BeamVegetation::write($scenery['objects'],$prefix,$origin,$put,$terrainStyle==='stock'?BeamTerrain::paths():[]);
            $objects[]=['class'=>'Forest','name'=>'theForest','__parent'=>'MissionGroup'];
            $count=$nativeVegetation+$buildingData['placed'];
            foreach($scenery['objects'] as $object){$kind=$object['kind'];if(isset(BeamVegetation::SHAPES[$kind]))continue;$p=$object['position'];$scale=$object['scale'];
                $objects[]=['class'=>'TSStatic','name'=>'scenery_'.$count++,'__parent'=>'MissionGroup','shapeName'=>$path.'art/shapes/'.$kind.'.dae','position'=>[$p[0],$p[1],$p[2]-$origin],'scale'=>[$scale,$scale,$scale],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
            }
            $vegetationAssets=BeamVegetation::used($scenery['objects']);
            unset($scenery['objects']);
            $put('art/shapes/main.materials.json',self::json((object)$sceneryMaterials));
            $items='';foreach($objects as $object)$items.=json_encode($object,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
            $put('main/items.level.json',json_encode(['class'=>'SimGroup','name'=>'MissionGroup','persistentId'=>substr($id,0,8).'-'.substr($id,8,4).'-4'.substr($id,13,3).'-8'.substr($id,17,3).'-'.substr($id,20,12)],JSON_THROW_ON_ERROR)."\n");
            $put('main/MissionGroup/items.level.json',$items);
            $put('info.json',self::json(['title'=>$plan['name'].' - SweepLine','description'=>'Generated real-scale road recreation. '.implode('; ',$plan['alternate_names']).' Target: BeamNG.drive v0.40. Scenery and missing lane attributes are estimates.','authors'=>'SweepLine; OpenStreetMap contributors; USGS; USDA','size'=>[$side,$side],'defaultSpawnPointName'=>'spawns_start','spawnPoints'=>[['translationId'=>'Start','objectname'=>'spawns_start','preview'=>'spawn-start.png','description'=>'Default road start'],['translationId'=>'End','objectname'=>'spawns_end','preview'=>'spawn-end.png','description'=>'Road end, facing back toward Start (simulation)']],'previews'=>['preview.png']]));
            $put('preview.png',BeamPreview::render($raster,$road,$plan['name']));
            $put('spawn-start.png',BeamPreview::render($raster,$road,$plan['name'],$start['position'],'START'));
            $put('spawn-end.png',BeamPreview::render($raster,$road,$plan['name'],$end['position'],'END'));
            $report=['target'=>'BeamNG.drive v0.40','orientation'=>$plan['orientation']??'forward','in_game_verified'=>false,'map_side_m'=>$side,'scale'=>'1:1 local UTM meters','epsg'=>$plan['epsg'],'bounds'=>$plan['bounds'],'padding_miles'=>$plan['padding_miles'],'terrain_resolution'=>$n,'elevation_sampling_resolution'=>$raster->elevationResolution(),'terrain_spacing_m'=>$step,'elevation_origin_m'=>$origin,'alternate_names'=>$plan['alternate_names'],'bridges'=>$bridges,'bridge_warning'=>$bridges?'Bridge deck heights, supports and guardrails are generic estimates derived from approaches; not surveyed structures.':null,'lane_estimated_samples'=>$estimated,'road_samples'=>count($road),'scenery_objects'=>$count,'scenery_placement'=>$scenery,'vegetation_assets'=>$vegetationAssets,'stock_scenery_assets'=>$vegetationAssets,'vegetation_material_libraries'=>BeamVegetation::materialLibraries($vegetationAssets),'vegetation_pipeline'=>'Stock Forest meshes only for trees, bushes and rocks; no generated scenery placeholders.','building_data'=>$buildingData,'visible_distance_m'=>16000,'terrain_saturation'=>BeamRaster::TERRAIN_SATURATION,'terrain_style'=>$terrainStyle,'terrain_material_method'=>$terrainStyle==='stock'?'Bounded aerial/palette blended base colors plus stock PBR detail; v0.40 terrain shader layout':'Aerial base','terrain_classification'=>$terrainClassification,'stock_terrain_assets'=>$terrainStyle==='stock'?BeamTerrain::paths():[],'lane_taper_each_side_ft'=>100,'terrain_overlap_correction'=>$terrainCorrection,'shoulder_transition'=>$shoulders,
                'sources'=>['elevation'=>'USGS 3DEP bare-earth elevation','aerial'=>'USGS/USDA NAIP imagery','roads'=>'OpenStreetMap contributors'],
                'limitations'=>['Road elevation is sampled from bare-earth terrain, then smoothed; banking and road engineering are estimated.','Aerial colors and regional heuristics estimate scenery; they do not reconstruct individual real objects.','Short simple bridges use estimated approach-derived decks. Complex bridges and tunnels are refused. Unmapped structures may be inaccurate.','Navigation graph covers only the selected road; traffic behavior requires in-game validation.','Requires in-game v0.40 validation.']];
            $put('sweepline-report.json',self::json($report));
            $zip->addFromString('INSTALL.txt',"SweepLine BeamNG.drive v0.40 map\nCopy this ZIP into the mods folder opened from BeamNG Launcher > Manage User Folder. Do not unpack. Open Free Roam, choose ".$plan['name']." - SweepLine, then Start or End.\nStart is the default spawn. Source attribution and approximation details: ".$base."sweepline-report.json\nOpenStreetMap data: https://www.openstreetmap.org/copyright\nUses installed BeamNG.drive 0.40 vegetation from Utah, West Coast USA, East Coast USA or Johnson Valley. Required references are listed in the report. No BeamNG game assets are redistributed. Package structure tested; in-game compatibility must be verified in v0.40.\n");
            $progress('Finalizing the map ZIP');
        } finally {$zip->close();}
        return $report;
    }
}

