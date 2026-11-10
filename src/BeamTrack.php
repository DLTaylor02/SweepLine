<?php
declare(strict_types=1);
namespace SweepLine;

/** Independent full-length export track, sharing the map's raster and elevation origin. */
final class BeamTrack
{
    public static function prepare(array $input,array $plan,BeamRaster $raster,float $origin,callable $progress): array
    {
        $structures=[];foreach($input['sources'] as $s)if(!in_array($s['tags']['bridge']??'no',['no','false','0'],true)||!in_array($s['tags']['tunnel']??'no',['no','false','0'],true))
            for($i=1;$i<count($s['points']);$i++)$structures[]=[$s['points'][$i-1],$s['points'][$i]];
        $anchors=array_map(static fn($b)=>$b['points'][0],$input['intersection_data']['branches']??[]);
        $world=BeamPlan::sample(BeamCurve::smooth($input['points'],$anchors,$structures),8,0.001);
        $profiles=BeamRoadSurface::shoulders(BeamRoadSurface::taper($world,BeamLanes::profiles($world,$input['sources'],$plan['bounds'])));
        $sourceWorld=$world;$world=BeamMedian::anchor($world,$profiles);
        $points=[];$raw=[];$chain=[0.];
        foreach($world as $i=>$p){$local=[$p[0]-$plan['bounds'][0],$p[1]-$plan['bounds'][1]];$points[]=$local;$raw[]=$raster->roadHeight(...$local);if($i)$chain[]=$chain[$i-1]+hypot($local[0]-$points[$i-1][0],$local[1]-$points[$i-1][1]);}
        $bridges=BeamMedian::remapSpans(BeamStructures::spans($sourceWorld,$input['sources'],true),$sourceWorld,$world);$tunnels=BeamMedian::remapSpans(BeamTunnels::spans($sourceWorld,$input['sources']),$sourceWorld,$world);
        $raw=BeamTunnels::samples($points,$chain,$raw,$tunnels,$raster);$raw=BeamStructures::deckSamples($points,$chain,$raw,$bridges,$raster,'Opposite carriageway ('.($input['road_ref']??$input['road_name']??$input['name']??'unnamed').')');$road=[];
        foreach($points as $i=>$p){
            if($i%100===0)$progress(null);$sum=0.;$weights=0.;$left=$i;
            while($left>0&&$chain[$i]-$chain[$left-1]<40)$left--;
            for($j=$left;$j<count($points)&&$chain[$j]-$chain[$i]<40;$j++){$w=exp(-0.5*(($chain[$j]-$chain[$i])/12)**2);$sum+=$raw[$j]*$w;$weights+=$w;}
            $z=$sum/$weights-$origin+BeamMap::ROAD_ELEVATION_OFFSET_M;
            if($i&&abs($z-$road[$i-1][2])/max(0.01,$chain[$i]-$chain[$i-1])>0.35)throw new \RuntimeException('Opposite carriageway elevation produces an unsupported grade.');
            $road[]=[$p[0],$p[1],$z];
        }
        $context=BeamIntersections::prepare($input['intersection_data']??['branches'=>[]],$road,$profiles,$raster,$plan['bounds'],$origin,array_merge($bridges,$tunnels),$progress);
        $context=BeamTraffic::turnarounds($context,$road,$profiles,$raster,$origin,$progress,$plan['building_data']['footprints']??[]);
        return $input+['road'=>$road,'profiles'=>$profiles,'chain'=>$chain,'bridges'=>$bridges,'tunnels'=>$tunnels,'context'=>$context];
    }
    public static function mask(array $track,BeamRaster $raster,string $mask,callable $progress): string
    {
        $n=$raster->size;$step=$raster->side/$n;
        foreach(array_merge($track['bridges'],$track['tunnels']) as $span){
            [$first,$last]=BeamStructures::indices($track['chain'],$span);
            for($i=$first;$i<=$last;$i++){
                if($i%100===0)$progress(null);$p=$track['road'][$i];$width=$track['profiles'][$i]['width_m'];
                if($width>20)throw new \RuntimeException('Opposite carriageway structure pavement exceeds 20 metres.');
                $radius=$width/2+max(1.5,sqrt(2)*$step)+max(6,3*$step)+$step;$cells=(int)ceil($radius/$step);$cx=(int)round($p[0]/$step);$cy=(int)round($p[1]/$step);
                for($y=max(0,$cy-$cells);$y<=min($n-1,$cy+$cells);$y++)for($x=max(0,$cx-$cells);$x<=min($n-1,$cx+$cells);$x++)if(hypot($x*$step-$p[0],$y*$step-$p[1])<=$radius)$mask[$y*$n+$x]="\1";
            }
        }return $mask;
    }
    public static function write(array $track,array $main,array $mainProfiles,string $prefix,string $path,callable $put,array &$materials,BeamRaster $raster,float $origin,callable $progress,array $otherContext=[]): array
    {
        $key=$track['name'];$road=$track['road'];$profiles=$track['profiles'];$context=$track['context'];$objects=[];$bridgeMaterial=$prefix.'_bridge';
        $static=static fn($name,$file,$position,$collision='Visible Mesh Final')=>['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$position,'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>$collision,'decalType'=>($collision==='Visible Mesh Final'?'Visible Mesh Final':'None'),'castShadows'=>true];
        $render=BeamIntersections::markingSections($road,$profiles,$context['junctions']);
        $surfaces=array_merge([['name'=>$key]+$render],$context['roads']);$report=[];
        foreach($surfaces as $i=>$surface){
            foreach($surface['profiles'] as $p){$m=BeamPavement::key($p);if(isset($materials[$m]))continue;
                $file='art/roads/'.$m.'.color.png';$materials[$m]=BeamRoadMaterials::material($prefix.'_road_'.$m,$path.$file,$p);$put($file,BeamPng::road($p));$put(BeamRoadMaterials::maskPath($file),BeamPng::paintMask($p));
            }
            $sections=$i===0?BeamJunctionBoundary::renderSections($road,$profiles,$surface['points']):null;
            $obstacles=[['road'=>$main,'profiles'=>$mainProfiles,'weld_boundary'=>$i!==0,'preserve_junction_paint'=>$i===0&&!empty($track['shared_junctions'])]];if($i)foreach($otherContext as $other){
                $obstacles[]=['road'=>$other['points'],'profiles'=>$other['profiles']];
                $rings=BeamPavement::connectorRings($other,$main,$mainProfiles);
                if(count($rings)>1){$contact=BeamPavement::contact($other,$main,$mainProfiles);$obstacles[]=['road'=>$contact['surface'],'profiles'=>$contact['profiles'],'sections'=>$rings];}
            }
            $mesh=BeamPavement::build($surface,$road,$profiles,$prefix,$sections,$obstacles);if(!$mesh['triangles'])continue;$file='art/roads/'.$key.'-'.$i.'.dae';$put($file,$mesh['xml']);$objects[]=$static($key.'_surface_'.$i,$file,$mesh['position']);
            $edge='art/roads/'.$key.'-'.$i.'-edge.dae';$put($edge,$mesh['fascia_xml']);$objects[]=$static($key.'_edge_'.$i,$edge,$mesh['position'],'None');
            $paint=BeamLanePaint::build($surface,$sections??BeamJunctionBoundary::sections($surface['points'],$surface['profiles']),$road,$profiles,$prefix,$obstacles);
            if($paint){$file='art/roads/'.$key.'-'.$i.'-paint.dae';$put($file,$paint['xml']);$o=$static($key.'_paint_'.$i,$file,$paint['position'],'None');$o['castShadows']=false;$objects[]=$o;unset($paint['xml']);$mesh['lane_transition_paint']=$paint;}
            unset($mesh['xml'],$mesh['fascia_xml']);$mesh['boundary_height_mode']=$i===0?'Independent carriageway elevation; XY overlap clipping only':'Mapped junction boundary welding';$report[]=$mesh;
        }
        if($track['bridges']){
            $put('art/shapes/bridge-pier.dae',BeamAssets::shape($bridgeMaterial,'bridge-pier'));
            $put('art/shapes/bridge.materials.json',json_encode([$bridgeMaterial=>['class'=>'Material','name'=>$bridgeMaterial,'mapTo'=>$bridgeMaterial,'version'=>1,'groundType'=>'CONCRETE','Stages'=>[['diffuseColor'=>[0.48,0.49,0.5,1]]]]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            $sections=BeamJunctionBoundary::renderSections($road,$profiles,$render['points']);
            $deck=BeamBridgeDeck::build($render['points'],$sections,$track['bridges'],$bridgeMaterial);$file='art/shapes/'.$key.'-deck.dae';$put($file,$deck['xml']);$objects[]=$static($key.'_deck',$file,[0,0,0],'None');
            foreach($track['bridges'] as $bi=>$span){[$first,$last]=BeamStructures::indices($track['chain'],$span);
                foreach(BeamBridgeDeck::barriers($render['points'],$sections,$span,$bridgeMaterial) as $railSide=>$xml){
                    $file='art/shapes/'.$key.'-bridge-'.$bi.'-'.$railSide.'.dae';$put($file,$xml);$objects[]=$static($key.'_bridge_'.$bi.'_rail_'.$railSide,$file,[0,0,0]);
                }
                $lastPier=-INF;for($i=$first;$i<=$last;$i++)if($track['chain'][$i]-$lastPier>=25){
                    $lastPier=$track['chain'][$i];$p=$road[$i];$ground=$raster->height($p[0],$p[1])-$origin;$height=$p[2]-0.5-$ground;
                    if($height>0.4){$o=$static($key.'_bridge_'.$bi.'_pier_'.$i,'art/shapes/bridge-pier.dae',[$p[0],$p[1],$ground-0.2]);$o['scale']=[1.5,1.5,$height+0.2];$objects[]=$o;}
                }
            }
        }
        $warning=$track['intersection_data']['warning']??null;
        if(!empty($track['divided_junction_alignment']['limited_samples']))$warning=trim(($warning??'').' Some divided-road junctions exceed the supported lateral alignment; lane joins may be incomplete.');
        if(!empty($context['final_clearance']['limited_branches']))$warning=trim(($warning??'').' Some opposite-carriageway side roads were shortened to preserve full-road terrain support.');
        $nav=BeamTraffic::graph($road,$profiles,$context);
        foreach($nav['objects'] as &$o)$o['name']=$key.'_'.$o['name'];unset($o);
        $segments=[];foreach($nav['segments'] as $name=>$s){$s['nodes']=array_map(static fn($n)=>$key.'_'.$n,$s['nodes']);$segments[$key.'_'.$name]=$s;}
        return ['objects'=>array_merge($objects,$nav['objects']),'segments'=>$segments,'report'=>['name'=>$track['road_name']??$key,'key'=>$key,'ref'=>$track['road_ref']??null,'candidate_id'=>$track['candidate_id']??null,'network_path_id'=>$track['network_path_id']??null,'source_way_ids'=>$track['source_way_ids']??[],'pavement_overlap'=>$track['pavement_overlap']??null,'length_m'=>end($track['chain']),'lane_estimated_samples'=>count(array_filter($profiles,static fn($p)=>$p['estimated_lanes'])),'width_estimated_samples'=>count(array_filter($profiles,static fn($p)=>$p['estimated_width'])),'bridges'=>$track['bridges'],'tunnels'=>$track['tunnels'],'divided_junction_alignment'=>$track['divided_junction_alignment']??null,'terrain_correction'=>$track['terrain_correction']??null,'intersection_context'=>['rendered_branches'=>count($context['roads']),'final_clearance'=>$context['final_clearance']??null,'warning'=>$warning],'surfaces'=>$report]];
    }
}
