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
        $drivingWidth=$profile['carriageway_width_m']??$profile['width_m'];$laneWidth=$drivingWidth/$profile['lanes'];
        $offset=$profile['lanes']>1?$drivingWidth/2-$laneWidth/2:0;
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

    public static function build(array $plan,BeamRaster $raster,string $id,string $zipPath,callable $progress,?BeamCompute $compute=null,?BeamBuildLog $buildLog=null): array
    {
        if($buildLog===null){$buildLog=new BeamBuildLog();$originalProgress=$progress;$progress=static function($message)use($originalProgress,$buildLog){$buildLog->event($message);$originalProgress($message);};}
        $compute??=new BeamCompute(dirname($zipPath),\Closure::fromCallable(static fn()=>$progress(null)));
        $name='sweepline_'.substr($id,0,16);$base='levels/'.$name.'/';$path='/'.$base;$n=$raster->size;$side=$raster->side;$step=$side/$n;
        $waterSource=!empty($plan['water_data']['features'])?clone $raster:null;
        $mergeNodes=[];$anchors=[];foreach($plan['intersection_data']['branches']??[] as $branch)if(isset($branch['points'][0]))$anchors[]=$branch['points'][0];
        foreach($plan['carriageways']??[] as $track)foreach($track['merge_nodes']??[] as $node){$anchors[]=$node;$mergeNodes[]=$node;}
        $structureSegments=[];foreach($plan['sources'] as $source)if(!in_array($source['tags']['bridge']??'no',['no','false','0'],true)||!in_array($source['tags']['tunnel']??'no',['no','false','0'],true))
            for($i=1;$i<count($source['points']);$i++)$structureSegments[]=[$source['points'][$i-1],$source['points'][$i]];
        $rounded=BeamCurve::smooth($plan['points'],$anchors,$structureSegments);$world=BeamPlan::sample($rounded,8,0.001);
        $profiles=BeamRoadSurface::shoulders(BeamRoadSurface::taper($world,BeamRoadSurface::alignMergeProfiles($world,BeamLanes::profiles($world,$plan['sources'],$plan['bounds']),$mergeNodes)));
        $sourceWorld=$world;$world=BeamMedian::anchor($world,$profiles);

        $points=[];$rawHeights=[];
        foreach($world as $p){$x=$p[0]-$plan['bounds'][0];$y=$p[1]-$plan['bounds'][1];$points[]=[$x,$y];$rawHeights[]=$raster->roadHeight($x,$y);}
        $min=INF;$max=-INF;$black=0;$categories=str_repeat("\0",$n*$n);
        $progress('Validating elevation and estimating regional land cover');
        for($y=0;$y<$n;$y++) {
            if($y%32===0)$progress(null);
            for($x=0;$x<$n;$x++){$h=$raster->cell($x,$y);$min=min($min,$h);$max=max($max,$h);$rgb=$raster->rgb($x,$y);if(array_sum($rgb)<6)$black++;$categories[$y*$n+$x]=chr(BeamRaster::cover($rgb));}
        }
        if($black/($n*$n)>0.1)throw new \RuntimeException('NAIP aerial coverage is unavailable in a substantial part of this square. No fabricated imagery was substituted.');
        $shadowCorrection=BeamShadows::correct($raster,$plan['center'],$progress);$terrainRaster=$shadowCorrection['raster'];unset($shadowCorrection['raster']);
        $terrainStyle=$plan['terrain_style']??'stock';$terrainClassification=null;
        if($terrainStyle==='stock'){$progress('Classifying grass, forest floor, soil, gravel, stone, rock and sand with aerial fallback');$terrainClassification=BeamTerrain::classify($terrainRaster,$plan['center'],$plan['building_data']['footprints']??[],$progress,$compute);$categories=$terrainClassification['layers'];unset($terrainClassification['layers']);}
        $origin=$min-32;$maxHeight=max(64,ceil(($max-$origin+32)/64)*64);
        $road=[];$estimated=0;$chain=[0.0];
        for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $bridges=BeamMedian::remapSpans(BeamStructures::spans($sourceWorld,$plan['sources'],true),$sourceWorld,$world);$tunnels=BeamMedian::remapSpans(BeamTunnels::spans($sourceWorld,$plan['sources']),$sourceWorld,$world);
        $coveredSpans=array_merge($bridges,$tunnels);
        $rawHeights=BeamTunnels::samples($points,$chain,$rawHeights,$tunnels,$raster);
        $rawHeights=BeamStructures::deckSamples($points,$chain,$rawHeights,$bridges,$raster,'Selected road ('.$plan['name'].')');
        foreach($points as $i=>$p) {
            $sum=0.0;$weights=0.0;
            $left=$i;while($left>0 && $chain[$i]-$chain[$left-1]<40)$left--;
            for($j=$left;$j<count($points) && $chain[$j]-$chain[$i]<40;$j++){$weight=exp(-0.5*(($chain[$j]-$chain[$i])/12)**2);$sum+=$rawHeights[$j]*$weight;$weights+=$weight;}
            $z=$sum/$weights;
            if($i>0 && abs($z-($road[$i-1][2]+$origin-self::ROAD_ELEVATION_OFFSET_M))/max(0.01,hypot($p[0]-$points[$i-1][0],$p[1]-$points[$i-1][1]))>0.35)throw new \RuntimeException('The sampled elevation creates an unsupported road grade. Higher-quality road elevation is required for this stretch.');
            $road[]=[$p[0],$p[1],$z-$origin+self::ROAD_ELEVATION_OFFSET_M];if($profiles[$i]['estimated_lanes'])$estimated++;
        }
        $carriageways=[];foreach($plan['carriageways']??[] as $input)$carriageways[]=BeamCarriageways::conformMerges(BeamTrack::prepare($input,$plan,$raster,$origin,$progress),$road,$profiles,$plan['bounds']);
        foreach($carriageways as &$track){
            $track=BeamMedian::separate($road,$profiles,$track,$coveredSpans);
            $track['context']=BeamIntersections::prepare($track['intersection_data']??['branches'=>[]],$track['road'],$track['profiles'],$raster,$plan['bounds'],$origin,array_merge($track['bridges'],$track['tunnels']),$progress);
            $track['context']=BeamTraffic::turnarounds($track['context'],$track['road'],$track['profiles'],$raster,$origin,$progress,$plan['building_data']['footprints']??[]);
        }unset($track);
        if(count($road)+array_sum(array_map(static fn($t)=>count($t['road']),$carriageways))>20000)throw new \RuntimeException('Combined carriageway geometry exceeds 20,000 samples. Select a shorter stretch.');
        $intersectionData=$plan['intersection_data']??['branches'=>[]];
        $preparedIntersections=BeamIntersections::prepare($intersectionData,$road,$profiles,$raster,$plan['bounds'],$origin,$coveredSpans,$progress);
        $preparedIntersections=BeamTraffic::turnarounds($preparedIntersections,$road,$profiles,$raster,$origin,$progress,$plan['building_data']['footprints']??[]);
        $explicitPavement=(bool)$carriageways||(bool)$tunnels||(bool)$preparedIntersections['roads']||$rounded!==$plan['points']||$world!==$sourceWorld;
        $progress('Shaping the road corridor while preserving surrounding terrain');

        $bridgeMask=str_repeat("\0",$n*$n);
        foreach($coveredSpans as $bridge){[$first,$last]=BeamStructures::indices($chain,$bridge);
            for($i=$first;$i<=$last;$i++){
                if($i%100===0)$progress(null);
                if($profiles[$i]['width_m']>20)throw new \RuntimeException('Structure pavement exceeds the 20-meter simple-structure export limit.');
                $p=$road[$i];$radius=$profiles[$i]['width_m']/2+max(1.5,sqrt(2)*$step)+max(6,3*$step)+$step;$cells=(int)ceil($radius/$step);$cx=(int)round($p[0]/$step);$cy=(int)round($p[1]/$step);
                for($y=max(0,$cy-$cells);$y<=min($n-1,$cy+$cells);$y++)for($x=max(0,$cx-$cells);$x<=min($n-1,$cx+$cells);$x++)if(hypot($x*$step-$p[0],$y*$step-$p[1])<=$radius)$bridgeMask[$y*$n+$x]="\1";
            }
        }
        $mainStructureMask=$bridgeMask;$companionMask=str_repeat("\0",$n*$n);
        foreach($carriageways as &$track){$track['structure_mask']=BeamTrack::mask($track,$raster,str_repeat("\0",$n*$n),$progress);$companionMask=$companionMask|$track['structure_mask'];}unset($track);
        $bridgeMask=$bridgeMask|$companionMask;
        if($carriageways){
            $surfaces=[['points'=>$road,'profiles'=>$profiles,'bridges'=>$coveredSpans]];
            foreach($carriageways as $track)$surfaces[]=['points'=>$track['road'],'profiles'=>$track['profiles'],'bridges'=>array_merge($track['bridges'],$track['tunnels'])];
            $shoulders=BeamRoadSurface::blendTerrainSet($surfaces,$raster,$origin,$bridgeMask,$progress);
        }else $shoulders=BeamRoadSurface::blendTerrain($road,$profiles,$raster,$origin,$bridgeMask,$progress,$coveredSpans);
        $intersectionShaping=[];
        $profiles=BeamIntersections::markJunctions($road,$profiles,$preparedIntersections['junctions']);
        $progress('Checking and removing terrain intersections beneath the road');
        $terrainCorrection=BeamRoadSurface::clearTerrain($road,$profiles,$raster,$origin,$progress,$bridgeMask);
        $bridgeApproachCorrection=null;
        if($bridges){
            $approachMask=BeamRoadSurface::bridgeApproachMask($road,$profiles,$chain,$bridges,$raster,$progress);
            $approachMask=$approachMask|$companionMask;
            foreach($carriageways as $track)$approachMask=BeamRoadSurface::primaryContactMask($track['road'],$track['profiles'],$raster,$approachMask);
            $approachShaping=BeamRoadSurface::blendTerrain($road,$profiles,$raster,$origin,$approachMask,$progress,$bridges);
            $bridgeApproachCorrection=BeamRoadSurface::clearTerrain($road,$profiles,$raster,$origin,$progress,$approachMask,true);
            $bridgeApproachCorrection['shaping']=$approachShaping;
            $bridgeApproachCorrection['exact_pavement']=BeamTerrainContact::clear($road,$profiles,$raster,$origin,$approachMask,$progress);
            unset($approachMask);
        }


        foreach($carriageways as $index=>&$track){
            $mask=BeamRoadSurface::primaryContactMask($road,$profiles,$raster,$bridgeMask);
            foreach($carriageways as $j=>$other)if($j!==$index)$mask=BeamRoadSurface::primaryContactMask($other['road'],$other['profiles'],$raster,$mask);
            $track['terrain_correction']=BeamRoadSurface::clearTerrain($track['road'],$track['profiles'],$raster,$origin,$progress,$mask);
            if($track['bridges']){
                $approach=BeamRoadSurface::bridgeApproachMask($track['road'],$track['profiles'],$track['chain'],$track['bridges'],$raster,$progress)|$mainStructureMask;
                $approach=BeamRoadSurface::primaryContactMask($road,$profiles,$raster,$approach);
                foreach($carriageways as $j=>$other)if($j!==$index)$approach=BeamRoadSurface::primaryContactMask($other['road'],$other['profiles'],$raster,$approach|$other['structure_mask']);
                BeamRoadSurface::blendTerrain($track['road'],$track['profiles'],$raster,$origin,$approach,$progress,$track['bridges']);
                BeamRoadSurface::clearTerrain($track['road'],$track['profiles'],$raster,$origin,$progress,$approach,true);
                BeamTerrainContact::clear($track['road'],$track['profiles'],$raster,$origin,$approach,$progress);
            }
            $track['context']['aprons']=[];
            foreach($track['context']['roads'] as $branch){$contact=BeamPavement::contact($branch,$track['road'],$track['profiles']);if($contact){$contact['owner']=$branch['name'];$track['context']['aprons'][]=$contact;}}
            $track['context_shaping']=BeamIntersections::shape($track['context'],$track['road'],$track['profiles'],$raster,$origin,$mask,$progress);
            $track['context']=BeamIntersections::conform($track['context'],$raster,$origin,$progress,$track['road'],$track['profiles'],$mask);
        }unset($track);
        foreach($carriageways as $track)$bridgeMask=BeamRoadSurface::primaryContactMask($track['road'],$track['profiles'],$raster,$bridgeMask);
        $preparedIntersections['aprons']=[];
        foreach($preparedIntersections['roads'] as $branch){$contact=BeamPavement::contact($branch,$road,$profiles);if($contact!==null){$contact['owner']=$branch['name'];$preparedIntersections['aprons'][]=$contact;}}
        $intersectionShaping=$preparedIntersections['roads']?BeamIntersections::shape($preparedIntersections,$road,$profiles,$raster,$origin,$bridgeMask,$progress):[];
        $preparedIntersections=BeamIntersections::conform($preparedIntersections,$raster,$origin,$progress,$road,$profiles,$bridgeMask);
        // Final exact check follows every corridor-shaping pass.
        $terrainCorrection['exact_pavement']=BeamTerrainContact::clear($road,$profiles,$raster,$origin,$mainStructureMask|$companionMask,$progress,BeamIntersections::markingSections($road,$profiles,$preparedIntersections['junctions'])['points']);
        foreach($carriageways as &$track)$track['terrain_correction']['exact_pavement']=BeamTerrainContact::clear($track['road'],$track['profiles'],$raster,$origin,$mainStructureMask|$companionMask,$progress,BeamIntersections::markingSections($track['road'],$track['profiles'],$track['context']['junctions'])['points']);
        unset($track);
        $medianMask=$mainStructureMask|$companionMask;
        $medianSurfaceMask=str_repeat("\0",$n*$n);
        foreach($preparedIntersections['roads'] as $branch){$medianMask=BeamRoadSurface::primaryContactMask($branch['points'],$branch['profiles'],$raster,$medianMask);$medianSurfaceMask=BeamRoadSurface::primaryContactMask($branch['points'],$branch['profiles'],$raster,$medianSurfaceMask);}
        foreach($carriageways as $track)foreach($track['context']['roads'] as $branch){$medianMask=BeamRoadSurface::primaryContactMask($branch['points'],$branch['profiles'],$raster,$medianMask);$medianSurfaceMask=BeamRoadSurface::primaryContactMask($branch['points'],$branch['profiles'],$raster,$medianSurfaceMask);}
        $median=BeamMedian::build($road,$profiles,$carriageways,$coveredSpans,$raster,$origin,$medianMask,$progress);
        $waterTracks=[['road'=>$road,'profiles'=>$profiles,'bridges'=>$bridges,'tunnels'=>$tunnels]];
        foreach($carriageways as $track)$waterTracks[]=$track;
        foreach(array_merge([$preparedIntersections],array_column($carriageways,'context')) as $context)foreach($context['roads'] as $branch)$waterTracks[]=['road'=>$branch['points'],'profiles'=>$branch['profiles']];
        $water=BeamWater::build($plan['water_data']??[],$waterSource??$raster,$raster,$origin,$waterTracks,$progress);unset($waterSource,$waterTracks,$mainStructureMask,$companionMask,$bridgeMask,$medianMask,$other,$surfaces,$approach);
        foreach($carriageways as &$track)unset($track['structure_mask']);unset($track);
        $repairSurfaces=[['name'=>'primary','road'=>$road,'profiles'=>$profiles,'spans'=>$coveredSpans,'render'=>BeamIntersections::markingSections($road,$profiles,$preparedIntersections['junctions'])['points']]];
        foreach($carriageways as $track)$repairSurfaces[]=['name'=>$track['name'],'road'=>$track['road'],'profiles'=>$track['profiles'],'obstacles'=>[['road'=>$road,'profiles'=>$profiles,'weld_boundary'=>false]],'spans'=>array_merge($track['bridges'],$track['tunnels']),'render'=>BeamIntersections::markingSections($track['road'],$track['profiles'],$track['context']['junctions'])['points']];
        $zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Could not create BeamNG archive.');

        // libzip keeps addFromString buffers until close; large terrain meshes
        // use private file-backed sources so memory does not grow with archive size.
        $meshFiles=[];
        $putMesh=static function($file,$bytes)use($zip,$base,$zipPath,&$meshFiles){
            $tmp=tempnam(dirname($zipPath),'sl-mesh-');if($tmp===false)throw new \RuntimeException('Cannot allocate terrain mesh workspace.');
            $meshFiles[]=$tmp;chmod($tmp,0600);
            if(file_put_contents($tmp,$bytes)!==strlen($bytes)||!$zip->addFile($tmp,$base.$file))throw new \RuntimeException('Cannot write terrain mesh archive entry.');
        };
        $put=$putMesh;
        try {
            $groundLayers=$categories;
            $prefix='sl_'.substr($id,0,12);$materials=[];$terrainNames=[];$ground=['GRASS','DIRT','ROCK','CONCRETE','DIRT'];
            $tunnelGeometry=BeamTunnels::build($road,$profiles,$tunnels,$raster,$origin,$categories,$prefix.'_tunnel',$prefix.'_tunnel_terrain',$progress,$carriageways,$maxHeight,true);
            $contactRepair=BeamTerrainContact::repairCorridors($repairSurfaces,$raster,$origin,$progress,$tunnelGeometry['layers']);
            // Native shaping remains the broad base; exact median ground no longer depends on grid fitting.
            $median['report']['contact_passes']=[];
            unset($repairSurfaces,$median['edge_targets']);
            $contactRepair['median_contact_refined']=false;
            $contactRepair['tunnel_patch_rebuilt']=false;
            if($tunnelGeometry['shell']!==null&&(array_sum(array_column($contactRepair['roads'],'lowered_vertices'))>0||$contactRepair['median_contact_refined'])){
                unset($tunnelGeometry);$progress('Updating tunnel seams after final terrain clearance checks');
                $tunnelGeometry=BeamTunnels::build($road,$profiles,$tunnels,$raster,$origin,$categories,$prefix.'_tunnel',$prefix.'_tunnel_terrain',$progress,$carriageways,$maxHeight,true);$contactRepair['tunnel_patch_rebuilt']=true;
            }
            $categories=$tunnelGeometry['layers'];unset($tunnelGeometry['layers']);
            $tunnelGround=null;
            if(isset($tunnelGeometry['terrain_surface'])){
                $tunnelGround=BeamGroundMaterials::prepare($tunnelGeometry['terrain_surface'],$groundLayers,$raster,$terrainStyle,$prefix.'_tunnel_ground',$path,$progress);
                unset($tunnelGeometry['terrain_surface'],$tunnelGeometry['terrain']);
                $tunnelGeometry['terrain_files']=BeamGroundMaterials::write($tunnelGround,'art/tunnels/terrain-patch.dae',$putMesh);$tunnelGeometry['report']['mesh_parts']=count($tunnelGeometry['terrain_files']);$tunnelGeometry['report']['ground_materials']=$tunnelGround['report'];$tunnelGround=['materials'=>$tunnelGround['materials']];
            }
            $progress('Building exact pavement-edge median terrain');
            $medianSurface=BeamMedianSurface::build($road,$profiles,$carriageways,$coveredSpans,$raster,$medianSurfaceMask,$categories,$progress,$origin,$maxHeight);
            $medianSurface=BeamMedianSurface::replaceCells($medianSurface,$raster,$origin,$categories,$progress,$maxHeight);
            $categories=$medianSurface['layers'];unset($medianSurface['layers']);
            $median['report']['refined_surface']=$medianSurface['report'];
            $medianGround=BeamGroundMaterials::prepare($medianSurface,$groundLayers,$raster,$terrainStyle,$prefix.'_median_ground',$path,$progress);unset($medianSurface);
            $median['report']['ground_materials']=$medianGround['report'];
            $median['report']['refined_surface']['collision_ground_types']=array_values(array_unique(array_column($medianGround['materials'],'groundType')));
            $medianGround=['files'=>BeamGroundMaterials::write($medianGround,'art/terrains/median-ground.dae',$putMesh),'materials'=>$medianGround['materials'],'has_faces'=>(bool)$medianGround['faces']];
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
            BeamTextures::write($terrainRaster,$terrainStyle,$compute,$put,$progress);
            unset($terrainRaster,$categories,$groundLayers,$medianSurfaceMask,$materials);
            $objects=[['class'=>'LevelInfo','name'=>'theLevelInfo','__parent'=>'MissionGroup','levelName'=>$plan['name'],'visibleDistance'=>16000,'fogDensity'=>0.000015,'fogAtmosphereHeight'=>2000],
                ['class'=>'ScatterSky','name'=>'theSky','__parent'=>'MissionGroup','sunSize'=>1,'skyBrightness'=>40,'exposure'=>1,'elevation'=>50,'azimuth'=>180,'ambientScale'=>[1,1,1,1],'sunScale'=>[1,1,1,1],'ambientScaleGradientFile'=>'art/sky_gradients/default/gradient_ambient.png','sunScaleGradientFile'=>'art/sky_gradients/default/gradient_sunscale.png','colorizeGradientFile'=>'art/sky_gradients/default/gradient_colorize.png'],
                ['class'=>'TimeOfDay','name'=>'timeOfDay','__parent'=>'MissionGroup','time'=>19/24,'startTime'=>19/24,'version'=>2,'play'=>false],
                ['class'=>'TerrainBlock','name'=>'theTerrain','__parent'=>'MissionGroup','position'=>[0,0,0],'terrainFile'=>$path.'theTerrain.ter','squareSize'=>$step,'maxHeight'=>$maxHeight,'baseTexSize'=>1024,'castShadows'=>true,'materialTextureSet'=>$prefix.'_terrain_set']];
            $objects=array_merge($objects,$water['objects']);unset($water['objects']);
            if($tunnelGeometry['shell']!==null){
                $put('art/tunnels/interior.dae',$tunnelGeometry['shell']);
                $put('art/tunnels/concrete.color.png',BeamTunnelFinish::texture());
                $put('art/tunnels/light-fixture.dae',BeamTunnelFinish::fixture($prefix.'_tunnel_fixture'));
                $tunnelMaterial=$prefix.'_tunnel';
                $put('art/tunnels/main.materials.json',self::json([
                    ...BeamTunnelFinish::materials($tunnelMaterial,$path),
                ]+($tunnelGround['materials']??[])));
                foreach(['interior'] as $part)$objects[]=['class'=>'TSStatic','name'=>'tunnel_'.$part,'__parent'=>'MissionGroup','shapeName'=>$path.'art/tunnels/'.$part.'.dae','position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None','castShadows'=>true];
                foreach($tunnelGeometry['terrain_files'] as $i=>$file)$objects[]=['class'=>'TSStatic','name'=>'tunnel_terrain-patch'.($i?'_'.$i:''),'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None','castShadows'=>true];
                foreach($tunnelGeometry['objects'] as &$tunnelObject)if($tunnelObject['class']==='TSStatic')$tunnelObject['shapeName']=$path.$tunnelObject['shapeName'];unset($tunnelObject);
                $objects=array_merge($objects,$tunnelGeometry['objects']);unset($tunnelGeometry['shell'],$tunnelGeometry['terrain'],$tunnelGeometry['objects']);
            }
            if($medianGround['has_faces']){
                $put('art/terrains/median.materials.json',self::json($medianGround['materials']));
                foreach($medianGround['files'] as $i=>$file)$objects[]=['class'=>'TSStatic','name'=>'median_ground'.($i?'_'.$i:''),'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'Visible Mesh Final','castShadows'=>true];
            }
            unset($medianGround,$tunnelGround);
            $bridgeMaterial=$prefix.'_bridge';
            if($bridges){
                $put('art/shapes/bridge-pier.dae',BeamAssets::shape($bridgeMaterial,'bridge-pier'));
                $put('art/shapes/bridge.materials.json',self::json([$bridgeMaterial=>['class'=>'Material','name'=>$bridgeMaterial,'mapTo'=>$bridgeMaterial,'version'=>1,'groundType'=>'CONCRETE','Stages'=>[['diffuseColor'=>[0.48,0.49,0.5,1]]]]]));
                foreach($bridges as $bi=>$bridge){[$first,$last]=BeamStructures::indices($chain,$bridge);if($last<=$first)throw new \RuntimeException('Bridge span is too short for reliable deck geometry.');
                    $bridgeSections=BeamJunctionBoundary::sections($road,$profiles);
                    foreach(BeamBridgeDeck::barriers($road,$bridgeSections,$bridge,$bridgeMaterial) as $railSide=>$xml){
                        $file='art/shapes/bridge-'.$bi.'-'.$railSide.'.dae';$put($file,$xml);
                        $objects[]=['class'=>'TSStatic','name'=>'bridge_'.$bi.'_rail_'.$railSide,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None','castShadows'=>true];
                    }
                    $lastPier=-INF;
                    for($i=$first;$i<=$last;$i++)if($chain[$i]-$lastPier>=25){$lastPier=$chain[$i];$p=$road[$i];$ground=$raster->height($p[0],$p[1])-$origin;$height=$p[2]-0.5-$ground;
                        if($height>0.4)$objects[]=['class'=>'TSStatic','name'=>'bridge_'.$bi.'_pier_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.'art/shapes/bridge-pier.dae','position'=>[$p[0],$p[1],$ground-0.2],'scale'=>[1.5,1.5,$height+0.2],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
                    }
                }
            }

            $roadMaterials=BeamLanePaint::materials($prefix);
            $mainRendering=BeamIntersections::markingSections($road,$profiles,$preparedIntersections['junctions']);
            $renderRoads=array_merge([['name'=>'road']+$mainRendering],$preparedIntersections['roads']);
            foreach($renderRoads as $renderRoad){
                $chunks=[];$last=null;$renderPoints=$renderRoad['points'];$renderProfiles=$renderRoad['profiles'];
                foreach($renderPoints as $i=>$p){
                    $profile=$renderProfiles[$i];$key=BeamPavement::key($profile);
                    if(!isset($roadMaterials[$key])){
                        $mat=$prefix.'_road_'.$key;$texture='art/roads/'.$key.'.color.png';
                        $roadMaterials[$key]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'groundType'=>'ASPHALT','Stages'=>[['diffuseMap'=>$path.$texture,'diffuseColor'=>[1,1,1,1],'specularPower'=>8,'useAnisotropic'=>true]]];
                        $put($texture,BeamPng::road($profile));
                    }
                    if($last!==$key){$chunks[]=['key'=>$key,'nodes'=>$i>0?[array_merge($renderPoints[$i-1],[$renderProfiles[$i-1]['width_m'],0.5,0,0,1])]:[]];$last=$key;}
                    $chunks[count($chunks)-1]['nodes'][]=array_merge($p,[$profile['width_m'],0.5,0,0,1]);
                }
                if($renderRoad['name']!=='road'||$explicitPavement)continue;
                foreach($chunks as $i=>$chunk)if(count($chunk['nodes'])>1){
                    $mat=$prefix.'_road_'.$chunk['key'];
                    $objects[]=['class'=>'MeshRoad','name'=>$renderRoad['name'].'_'.$i,'__parent'=>'MissionGroup','topMaterial'=>$mat,'bottomMaterial'=>$mat,'sideMaterial'=>$mat,
                        'textureLength'=>BeamPng::ROAD_TEXTURE_LENGTH_M,'breakAngle'=>1,'widthSubdivisions'=>1,'nodes'=>$chunk['nodes']];
                }
            }
            // A common explicit primary ribbon avoids engine spline drift at collision joins.
            if($explicitPavement){
                $primarySections=BeamJunctionBoundary::renderSections($road,$profiles,$mainRendering['points']);
                $primary=BeamPavement::build($mainRendering,$road,$profiles,$prefix,$primarySections);
                $put('art/roads/primary.dae',$primary['xml']);
                $put('art/roads/primary-edge.dae',$primary['fascia_xml']);
                $objects[]=['class'=>'TSStatic','name'=>'road_visual_edge','__parent'=>'MissionGroup','shapeName'=>$path.'art/roads/primary-edge.dae','position'=>$primary['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None'];

                $objects[]=['class'=>'TSStatic','name'=>'road_surface','__parent'=>'MissionGroup','shapeName'=>$path.'art/roads/primary.dae','position'=>$primary['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'Visible Mesh Final'];
            }
            unset($primary,$primarySections);
            if($bridges){
                $bridgeSections=BeamJunctionBoundary::renderSections($road,$profiles,$mainRendering['points']);
                $underdeck=BeamBridgeDeck::build($mainRendering['points'],$bridgeSections,$bridges,$bridgeMaterial,$explicitPavement?0.5:0.52);
                $put('art/shapes/bridge-deck.dae',$underdeck['xml']);unset($underdeck['xml']);
                $objects[]=['class'=>'TSStatic','name'=>'bridge_deck_shell','__parent'=>'MissionGroup','shapeName'=>$path.'art/shapes/bridge-deck.dae','position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>true];
            }
            $paint=BeamLanePaint::build($mainRendering,BeamJunctionBoundary::renderSections($road,$profiles,$mainRendering['points']),$road,$profiles,$prefix);
            $paintReport=null;
            if($paint){$put('art/roads/lane-paint.dae',$paint['xml']);$objects[]=['class'=>'TSStatic','name'=>'lane_transition_paint','__parent'=>'MissionGroup','shapeName'=>$path.'art/roads/lane-paint.dae','position'=>$paint['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>false];unset($paint['xml']);$paintReport=$paint;}
            $pavementReports=[];
            foreach($preparedIntersections['roads'] as $i=>$branch){
                $pavement=BeamPavement::build($branch,$road,$profiles,$prefix,null,array_map(static fn($t)=>['road'=>$t['road'],'profiles'=>$t['profiles']],$carriageways));$file='art/roads/side-'.$i.'.dae';$put($file,$pavement['xml']);
                $objects[]=['class'=>'TSStatic','name'=>$branch['name'].'_surface','__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$pavement['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'Visible Mesh Final'];
                $edgeFile='art/roads/side-'.$i.'-edge.dae';$put($edgeFile,$pavement['fascia_xml']);
                $objects[]=['class'=>'TSStatic','name'=>$branch['name'].'_visual_edge','__parent'=>'MissionGroup','shapeName'=>$path.$edgeFile,'position'=>$pavement['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None'];
                unset($pavement['xml'],$pavement['fascia_xml']);$pavement['name']=$branch['name'];$pavement['connector_expected']=isset($branch['join']);$pavementReports[]=$pavement;
            }
            $intersectionExclusions=array_map(static fn($b)=>['points'=>$b['points'],'width_m'=>max(array_column($b['profiles'],'width_m'))],$preparedIntersections['roads']);
            foreach($preparedIntersections['aprons'] as $apron)$intersectionExclusions[]=['points'=>$apron['points'],'width_m'=>$apron['width_m']];
            foreach($carriageways as $track){
                $intersectionExclusions[]=['points'=>$track['road'],'width_m'=>max(array_column($track['profiles'],'width_m'))];
                foreach($track['context']['roads'] as $branch)$intersectionExclusions[]=['points'=>$branch['points'],'width_m'=>max(array_column($branch['profiles'],'width_m'))];
            }
            $intersectionExclusions=array_merge($intersectionExclusions,$water['exclusions']);
            foreach($tunnelGeometry['portal_exclusions']??[] as $portal)$intersectionExclusions[]=$portal;
            $exportRoads=array_merge([['road'=>$road,'profiles'=>$profiles]],$carriageways);
            $roadGeometries=array_map(static fn($t)=>new BeamScenery($t['road'],$t['profiles']),$exportRoads);
            $guardrails=BeamGuardrails::prepare($plan['guardrail_data']??['lines'=>[]],$road,$profiles,$raster,$origin,$intersectionExclusions,$bridges,$progress);
            if($carriageways){
                $sets=[$guardrails['runs']];$extraExclusions=array_merge($intersectionExclusions,[['points'=>$road,'width_m'=>max(array_column($profiles,'width_m'))]]);
                foreach($carriageways as $track)$sets[]=BeamGuardrails::prepare($plan['guardrail_data']??['lines'=>[]],$track['road'],$track['profiles'],$raster,$origin,$extraExclusions,$track['bridges'],$progress)['runs'];
                $guardrails['runs']=[];$railLength=0.;
                foreach($sets as $owner=>$runs)foreach($runs as $run){
                    if(BeamCarriageways::owner($run['points'][intdiv(count($run['points']),2)],$roadGeometries)!==$owner)continue;
                    $guardrails['runs'][]=$run;for($i=1;$i<count($run['points']);$i++)$railLength+=hypot($run['points'][$i][0]-$run['points'][$i-1][0],$run['points'][$i][1]-$run['points'][$i-1][1]);
                }
                $guardrails['report']['placed_runs']=count($guardrails['runs']);$guardrails['report']['length_m']=round($railLength,2);$guardrails['report']['terminals']=2*count($guardrails['runs']);
            }
            if($guardrails['runs']){
                $guardMaterial=$prefix.'_guardrail';
                $put('art/guardrails/main.materials.json',self::json([$guardMaterial=>['class'=>'Material','name'=>$guardMaterial,'mapTo'=>$guardMaterial,'version'=>1,'groundType'=>'METAL','Stages'=>[['diffuseColor'=>[0.53,0.56,0.59,1],'specularPower'=>32]]]]));
                foreach($guardrails['runs'] as $i=>$run){
                    $mesh=BeamGuardrails::mesh($run['points'],$guardMaterial);$file='art/guardrails/rail-'.$i.'.dae';$put($file,$mesh['xml']);
                    $objects[]=['class'=>'TSStatic','name'=>'mapped_guardrail_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$mesh['position'],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
                }
            }
            $carriagewayReports=[];$carriagewayNavigation=[];
            foreach($carriageways as $track){
                $rendered=BeamTrack::write($track,$road,$profiles,$prefix,$path,$put,$roadMaterials,$raster,$origin,$progress,$preparedIntersections['roads']);
                $objects=array_merge($objects,$rendered['objects']);$carriagewayNavigation+=$rendered['segments'];$carriagewayReports[]=$rendered['report'];
            }
            foreach($median['walls'] as $wall){
                $material=$prefix.'_median_concrete';$file='art/roads/'.$wall['key'].'-median.dae';
                $put($file,BeamAssets::mesh($wall['vertices'],$wall['faces'],$material));
                $objects[]=['class'=>'TSStatic','name'=>$wall['key'].'_median','__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
                $roadMaterials['median']=['class'=>'Material','name'=>$material,'mapTo'=>$material,'version'=>1,'groundType'=>'CONCRETE','Stages'=>[['diffuseColor'=>[0.52,0.53,0.54,1]]]];
            }
            $edgeMaterial=$prefix.'_road_edge';$roadMaterials['edge']=['class'=>'Material','name'=>$edgeMaterial,'mapTo'=>$edgeMaterial,'version'=>1,'doubleSided'=>true,'Stages'=>[['diffuseColor'=>[0.18,0.18,0.19,1]]]];
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
            $navigation=BeamTraffic::graph($road,$profiles,$preparedIntersections);$objects=array_merge($objects,$navigation['objects']);
            $navigation['segments']+=$carriagewayNavigation;
            $carriagewayLinks=BeamCarriageways::connectNavigation($objects,$carriageways,$navigation['segments']);
            $put('map.json',self::json(['segments'=>$navigation['segments']]));
            $progress('Placing regional scenery clear of the driving corridor');
            $seed=(int)hexdec(substr($id,0,7));$count=0;$sceneryMaterials=[];
            $buildingData=$plan['building_data']??['footprints'=>[]];
            $mappedBuildings=BeamBuildings::place($buildingData['footprints'],$road,$profiles,$raster,$intersectionExclusions);
            if($carriageways){
                $allBuildings=[];foreach($mappedBuildings['objects'] as $o)$allBuildings[json_encode($o['position'])]=$o;
                $avoid=array_merge($intersectionExclusions,[['points'=>$road,'width_m'=>max(array_column($profiles,'width_m'))]]);
                foreach($carriageways as $track){
                    $extra=BeamBuildings::place($buildingData['footprints'],$track['road'],$track['profiles'],$raster,$avoid);
                    foreach($extra['objects'] as $o)$allBuildings[json_encode($o['position'])]??=$o;
                }
                $mappedBuildings['objects']=array_values($allBuildings);
            }
            $facadeCounts=[];$facadeMaterials=[];$detailPrefix=$prefix.'_building';
            if($mappedBuildings['objects'])foreach(['trim'=>[0.86,0.84,0.78,1],'glass'=>[0.12,0.20,0.27,1],'door'=>[0.27,0.23,0.18,1]] as $kind=>$color){
                $mat=$detailPrefix.'_'.$kind;$sceneryMaterials[$mat]=['class'=>'Material','name'=>$mat,'mapTo'=>$mat,'version'=>1,'Stages'=>[['diffuseColor'=>$color,'specularPower'=>$kind==='glass'?64:12]]];
            }
            foreach($mappedBuildings['objects'] as $i=>$building){$file='art/shapes/building_'.$i.'.dae';$p=$building['position'];
                $category=$building['style']['category'];$facadeCounts[$category]=($facadeCounts[$category]??0)+1;
                $identity=round($p[0]+$plan['bounds'][0],1).','.round($p[1]+$plan['bounds'][1],1);
                $appearance=BeamBuildingStyle::appearance($category,$identity);$buildingMaterial=$prefix.'_building_'.$appearance['key'];$roofMaterial=$prefix.'_building_roof_'.$appearance['roof'];
                $facadeMaterials[$appearance['key']]=$appearance;
                $sceneryMaterials[$buildingMaterial]=['class'=>'Material','name'=>$buildingMaterial,'mapTo'=>$buildingMaterial,'version'=>1,'Stages'=>[['diffuseMap'=>$appearance['texture'],'normalMap'=>$appearance['normal'],'specularMap'=>$appearance['specular'],'diffuseColor'=>array_merge($appearance['color'],[1]),'specularPower'=>16,'useAnisotropic'=>true]]];
                $sceneryMaterials[$roofMaterial]=['class'=>'Material','name'=>$roofMaterial,'mapTo'=>$roofMaterial,'version'=>1,'Stages'=>[['diffuseColor'=>array_merge($appearance['roof_color'],[1])]]];
                $near=BeamIntersections::nearest($p,$road,$profiles);
                $put($file,BeamBuildingStyle::mesh($building['points'],$building['height'],$buildingMaterial,$roofMaterial,[
                    'details'=>true,'category'=>$category,'foundation'=>$building['foundation_height_m'],'levels'=>$building['levels'],
                    'front'=>[$near['x']-$p[0],$near['y']-$p[1]],'trim'=>$detailPrefix.'_trim','glass'=>$detailPrefix.'_glass','door'=>$detailPrefix.'_door']));
                $objects[]=['class'=>'TSStatic','name'=>'mapped_building_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[$p[0],$p[1],$p[2]-$origin],'scale'=>[1,1,1],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'collisionType'=>'Visible Mesh Final','decalType'=>'None'];
            }
            unset($buildingData['footprints']);$buildingData['placed']=count($mappedBuildings['objects']);$buildingData['rejected']=$mappedBuildings['rejected'];$buildingData['rejection_reasons']=$mappedBuildings['rejection_reasons'];$buildingData['facade_counts']=$facadeCounts;$buildingData['facade_textures']=BeamBuildingStyle::TEXTURES;$buildingData['facade_materials']=$facadeMaterials;$buildingData['facade_details']='Generic estimated windows, road-facing door, trim and roof/siding variation; not surveyed architectural features.';
            $scenery=BeamScenery::place($road,$profiles,$raster,$seed,$progress,$plan['center'],$mappedBuildings['objects'],$intersectionExclusions,(int)floor(24000/count($exportRoads)));
            if($carriageways){
                $packs=[$scenery];$avoid=array_merge($intersectionExclusions,[['points'=>$road,'width_m'=>max(array_column($profiles,'width_m'))]]);
                foreach($carriageways as $i=>$track)$packs[]=BeamScenery::place($track['road'],$track['profiles'],$raster,$seed+$i+1,$progress,$plan['center'],$mappedBuildings['objects'],$avoid,(int)floor(24000/count($exportRoads)));
                $scenery['objects']=[];$scenery['counts']=['tree'=>0,'bush'=>0,'rock'=>0,'building'=>0];$scenery['asset_counts']=[];$scenery['biome_counts']=[];
                foreach($packs as $owner=>$pack)foreach($pack['objects'] as $o){
                    if(BeamCarriageways::owner($o['position'],$roadGeometries)!==$owner)continue;
                    $scenery['objects'][]=$o;$scenery['counts'][$o['kind']]++;
                    if($o['asset']!==null)$scenery['asset_counts'][$o['asset']]=($scenery['asset_counts'][$o['asset']]??0)+1;
                }
                $scenery['corridors']=count($exportRoads);$scenery['shared_object_budget']=24000;$scenery['target']=array_sum(array_column($packs,'target'));$scenery['attempts']=array_sum(array_column($packs,'attempts'));
                foreach($packs as $pack)foreach($pack['biome_counts'] as $zone=>$count)$scenery['biome_counts'][$zone]=($scenery['biome_counts'][$zone]??0)+$count;
                $scenery['biome_count_scope']='Candidate placements before shared-corridor ownership filtering';
            }
            $scenery['counts']['building']=$buildingData['placed'];$scenery['building_policy']='Only mapped simple building footprints; polygon clearance and estimated foundations/heights.';

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
            $put('main/MissionGroup/items.level.json',$items);unset($items,$objects,$object);
            $put('info.json',self::json(['title'=>$plan['name'].' - '.Version::label(),'description'=>'Generated real-scale road recreation. '.implode('; ',$plan['alternate_names']).' Target: BeamNG.drive v0.40. Scenery and missing lane attributes are estimates.','authors'=>'SweepLine; OpenStreetMap contributors; USGS; USDA','size'=>[$side,$side],'defaultSpawnPointName'=>'spawns_start','spawnPoints'=>[['translationId'=>'Start','objectname'=>'spawns_start','preview'=>'spawn-start.png','description'=>'Default road start'],['translationId'=>'End','objectname'=>'spawns_end','preview'=>'spawn-end.png','description'=>'Road end, facing back toward Start (simulation)']],'previews'=>['preview.png']]));
            $put('preview.png',BeamPreview::render($raster,$road,$plan['name']));
            $put('spawn-start.png',BeamPreview::render($raster,$road,$plan['name'],$start['position'],'START'));
            $put('spawn-end.png',BeamPreview::render($raster,$road,$plan['name'],$end['position'],'END'));
            if(!empty($preparedIntersections['final_clearance']['limited_branches']))$intersectionData['warning']=trim(($intersectionData['warning']??'').' Some side-road stretches were shortened or omitted where coarse terrain could not meet pavement without disturbing primary-road support.');
            unset($intersectionData['branches']);$intersectionData['rendered_branches']=count(array_filter($preparedIntersections['roads'],static fn($r)=>!isset($r['turnaround_for'])));$intersectionData['skipped_branches']=$preparedIntersections['skipped'];$intersectionData['surface_method']='Shared explicit primary ribbon and clipped side-road surfaces; original sampled centreline, elevations and widths preserved';$intersectionData['pavement_meshes']=$pavementReports;$unjoined=count(array_filter($pavementReports,static fn($p)=>$p['connector_expected']&&!$p['connector']));if($unjoined)$intersectionData['warning']=trim(($intersectionData['warning']??'').' '.$unjoined.' side-road entrances could not be matched to primary pavement.');$intersectionData['terrain_shaping']=$intersectionShaping;$intersectionData['final_clearance']=$preparedIntersections['final_clearance'];$intersectionData['turnarounds']=$preparedIntersections['turnarounds'];$intersectionData['navigation_nodes']=count($navigation['objects']);$intersectionData['navigation_edges']=count($navigation['segments']);
            $report=['mapped_merge_profile_samples'=>count(array_filter($profiles,static fn($p)=>!empty($p['mapped_merge_aligned']))),'median'=>$median['report'],'lane_widening'=>'Fixed travel-left edge; additional width expands to travel-right','terrain_contact_repair'=>$contactRepair,'lane_transition_paint'=>$paintReport,'carriageways'=>['tracks'=>$carriagewayReports,'mapped_junction_links'=>$carriagewayLinks,'matching'=>$plan['carriageway_data']??null],'bridge_deck_shell'=>$underdeck??null,'tunnels'=>$tunnels,'tunnel_data'=>$tunnelGeometry['report'],'cpu_processing'=>$compute->report(),'paved_shoulder_each_side_ft'=>2,'curve_smoothing'=>['method'=>'Bounded quadratic corner rounds; endpoints, junctions, structures and sharp turns protected','max_deviation_m'=>BeamCurve::MAX_DEVIATION_M,'rounded'=>$rounded!==$plan['points'],'explicit_pavement'=>$explicitPavement],'water_data'=>$water['report'],'guardrail_data'=>$guardrails['report'],'shadow_correction'=>$shadowCorrection,'intersection_context'=>$intersectionData,'target'=>'BeamNG.drive v0.40','orientation'=>$plan['orientation']??'forward','in_game_verified'=>false,'map_side_m'=>$side,'scale'=>'1:1 local UTM meters','epsg'=>$plan['epsg'],'bounds'=>$plan['bounds'],'padding_miles'=>$plan['padding_miles'],'terrain_resolution'=>$n,'elevation_sampling_resolution'=>$raster->elevationResolution(),'terrain_spacing_m'=>$step,'elevation_origin_m'=>$origin,'alternate_names'=>$plan['alternate_names'],'bridges'=>$bridges,'bridge_warning'=>$bridges?'Bridge deck heights, supports and guardrails are generic estimates derived from approaches; not surveyed structures.':null,'lane_estimated_samples'=>$estimated,'road_samples'=>count($road),'scenery_objects'=>$count,'scenery_placement'=>$scenery,'vegetation_assets'=>$vegetationAssets,'stock_scenery_assets'=>$vegetationAssets,'vegetation_material_libraries'=>BeamVegetation::materialLibraries($vegetationAssets),'vegetation_pipeline'=>'Stock Forest meshes only for trees, bushes and rocks; no generated scenery placeholders.','building_data'=>$buildingData,'visible_distance_m'=>16000,'terrain_saturation'=>BeamRaster::TERRAIN_SATURATION,'terrain_style'=>$terrainStyle,'terrain_material_method'=>$terrainStyle==='stock'?'Bounded aerial/palette blended base colors plus stock PBR detail; v0.40 terrain shader layout':'Aerial base','terrain_classification'=>$terrainClassification,'stock_terrain_assets'=>$terrainStyle==='stock'?BeamTerrain::paths():[],'lane_taper_each_side_ft'=>100,'terrain_overlap_correction'=>$terrainCorrection,'shoulder_transition'=>$shoulders,'bridge_approach_correction'=>$bridgeApproachCorrection,
                'sources'=>['elevation'=>'USGS 3DEP bare-earth elevation','aerial'=>'USGS/USDA NAIP imagery','roads'=>'OpenStreetMap contributors'],
                'limitations'=>['Road elevation is sampled from bare-earth terrain, then smoothed; banking and road engineering are estimated.','Aerial colors and regional heuristics estimate scenery; they do not reconstruct individual real objects.','Fixed beam/slab bridges and viaducts up to 2000 meters use estimated approach-derived decks. Complex bridges and unsupported tunnel types are refused. Simple tunnel profiles, clearance and portals are estimated from approaches; not surveyed. Unmapped structures may be inaccurate.','Navigation includes the primary road, side branches and supported synthetic return loops. Enable traffic in-game; maneuvers require in-game validation.','Requires in-game v0.40 validation.']];
            $report['sweepline_version']=Version::number();
            $report['build_log']='build.log';
            $report['worker_memory']=['heap_before_diagnostics_bytes'=>memory_get_usage(true),'peak_heap_bytes'=>memory_get_peak_usage(true),'php_limit'=>ini_get('memory_limit')];
            $put('sweepline-report.json',self::json($report));
            $zip->addFromString('INSTALL.txt',Version::label()." BeamNG.drive v0.40 map\nCopy this ZIP into the mods folder opened from BeamNG Launcher > Manage User Folder. Do not unpack. Open Free Roam, choose ".$plan['name']." - SweepLine, then Start or End.\nStart is the default spawn. Detailed selection, sources, processing and geometry diagnostics: build.log at the root of this ZIP. Source attribution and approximation details: ".$base."sweepline-report.json\nOpenStreetMap data: https://www.openstreetmap.org/copyright\nUses installed BeamNG.drive 0.40 vegetation from Utah, West Coast USA, East Coast USA or Johnson Valley. Required references are listed in the report. No BeamNG game assets are redistributed. Package structure tested; in-game compatibility must be verified in v0.40.\n");
            $progress('Finalizing the map ZIP');
            $profileFields=array_flip(['lanes','forward','backward','oneway','legal_forward','width_m','carriageway_width_m','shoulder_m','estimated_lanes','estimated_width','transition','unmarked','mapped_merge_aligned','shared_junction_paint','section_override']);
            $summarize=static fn($p)=>array_intersect_key($p,$profileFields);
            $tracks=[];foreach($carriageways as $track)$tracks[]=['name'=>$track['name'],'points'=>$track['road'],'profiles'=>array_map($summarize,$track['profiles']),'bridges'=>$track['bridges'],'tunnels'=>$track['tunnels'],'intersections'=>$track['context']];
            $generated=['primary'=>['points'=>$road,'profiles'=>array_map($summarize,$profiles),'chainage_m'=>$chain],'carriageways'=>$tracks,'intersections'=>$preparedIntersections,'raster_provenance'=>$raster->provenance,'spawn_start'=>$start,'spawn_finish'=>$end];
            $files=[];for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$files[]=['name'=>$stat['name'],'uncompressed_bytes'=>$stat['size']];}
            $logFile=tempnam(dirname($zipPath),'sl-log-');if($logFile===false)throw new \RuntimeException('Cannot allocate export log workspace.');$meshFiles[]=$logFile;chmod($logFile,0600);
            $buildLog->write($logFile,$plan,$report,$generated,$files);if(!$zip->addFile($logFile,'build.log'))throw new \RuntimeException('Could not write export build.log');
        } finally {try{if(!$zip->close())throw new \RuntimeException('Could not finalize BeamNG ZIP.');}finally{foreach($meshFiles as $tmp)if(is_file($tmp))unlink($tmp);}}
        return $report;
    }
}

