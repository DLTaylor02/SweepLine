<?php
declare(strict_types=1);
namespace SweepLine;

/** Apply the existing structure estimators to connected context edges. */
final class BeamNetworkStructures
{
    public static function tagged(array $tags): bool
    {
        foreach(['bridge','tunnel'] as $key)if(!in_array(strtolower((string)($tags[$key]??'no')),['no','false','0',''],true))return true;
        return false;
    }
    public static function segments(array $sources,array $bounds): array
    {
        $out=[];foreach($sources as $s)if(self::tagged($s['tags']??[]))
            for($i=1;$i<count($s['points']);$i++)$out[]=[[$s['points'][$i-1][0]-$bounds[0],$s['points'][$i-1][1]-$bounds[1]],[$s['points'][$i][0]-$bounds[0],$s['points'][$i][1]-$bounds[1]]];
        return $out;
    }
    /** Walk real connected geometry for approach samples; never extend a tangent into unmapped ground. */
    private static function approach(array $point,array $toward,array $candidates): array
    {
        $out=[$point];$remaining=80.;$seen=[];$direction=[$toward[0]-$point[0],$toward[1]-$point[1]];
        for($hop=0;$hop<128&&$remaining>.001;$hop++){
            $best=null;
            foreach($candidates as $key=>$line){
                if(isset($seen[$key])||count($line)<2)continue;
                // Parent attachment may be inside a highway segment, not at its endpoint.
                $profile=array_fill(0,count($line),['width_m'=>1]);
                $near=BeamIntersections::nearest($point,$line,$profile);
                if(!$near||$near['distance']>.25)continue;
                foreach([false,true] as $forward){
                    $arm=[array_slice($point,0,2)];$station=0.;
                    foreach($line as $i=>$p){if($i)$station+=hypot($p[0]-$line[$i-1][0],$p[1]-$line[$i-1][1]);if(($forward&&$station>$near['along']+.001)||(!$forward&&$station<$near['along']-.001))$arm[]=array_slice($p,0,2);}
                    if(!$forward){$rest=array_reverse(array_slice($arm,1));$arm=[$arm[0],...$rest];}
                    if(count($arm)<2)continue;
                    $dx=$arm[1][0]-$point[0];$dy=$arm[1][1]-$point[1];$score=($dx*$direction[0]+$dy*$direction[1])/max(.001,hypot($dx,$dy)*hypot(...$direction));
                    if($score<-.1)continue;
                    if($best===null||$score>$best['score'])$best=['key'=>$key,'arm'=>$arm,'score'=>$score];
                }
            }
            if($best===null)break;$seen[$best['key']]=true;$arm=BeamIntersections::clip($best['arm'],$remaining);
            if(count($arm)<2)break;
            array_push($out,...array_slice($arm,1));$remaining-=BeamRampNetwork::length($arm);
            $last=count($out)-1;$direction=[$out[$last][0]-$out[$last-1][0],$out[$last][1]-$out[$last-1][1]];$point=$out[$last];
        }
        return $out;
    }
    public static function sample(array $sampled,array $branch,array $all,array $parent,array $bounds,BeamRaster $raster): array
    {
        $raw=array_map(static fn($p)=>$raster->roadHeight($p[0],$p[1]),$sampled);
        $sources=$branch['source_parts']??[];if(!array_filter($sources,static fn($s)=>self::tagged($s['tags']??[])))return ['raw'=>$raw,'bridges'=>[],'tunnels'=>[]];
        $candidates=[$parent];$reachable=[];
        foreach(['start_node','end_node'] as $key)if(isset($branch[$key]))$reachable[(string)$branch[$key]]=true;
        $pending=$all;$connected=[];
        for($pass=0;$pass<count($all)&&$pending;$pass++){
            $next=[];$changed=false;
            foreach($pending as $other){
                if(($other['context_id']??null)===($branch['context_id']??null))continue;
                if(isset($reachable[(string)($other['start_node']??'')])||isset($reachable[(string)($other['end_node']??'')])){
                    $connected[]=$other;$reachable[(string)$other['start_node']]=true;$reachable[(string)$other['end_node']]=true;$changed=true;
                }else $next[]=$other;
            }
            if(!$changed)break;$pending=$next;
        }
        foreach($connected as $other)$candidates[]=array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],$other['points']);
        $last=count($sampled)-1;
        $before=self::approach($sampled[0],[2*$sampled[0][0]-$sampled[1][0],2*$sampled[0][1]-$sampled[1][1]],$candidates);
        $after=self::approach($sampled[$last],[2*$sampled[$last][0]-$sampled[$last-1][0],2*$sampled[$last][1]-$sampled[$last-1][1]],$candidates);
        // Preserve exact road samples, so adjusted heights can be sliced without interpolation.
        $prefix=array_reverse(array_slice($before,1));$offset=BeamRampNetwork::length($before);
        $full=[...$prefix,...$sampled,...array_slice($after,1)];$chain=[0.];
        foreach($full as $i=>$p)if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$full[$i-1][0],$p[1]-$full[$i-1][1]);
        $localSources=[];foreach($sources as $s){$s['points']=array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],$s['points']);$localSources[]=$s;}
        try{
            $bridges=BeamStructures::spans($full,$localSources,true);
            $tunnels=BeamTunnels::spans($full,$localSources);
            $heights=array_map(static fn($p)=>$raster->roadHeight($p[0],$p[1]),$full);
            $heights=BeamTunnels::samples($full,$chain,$heights,$tunnels,$raster);
            $heights=BeamStructures::deckSamples($full,$chain,$heights,$bridges,$raster,'Interchange edge '.($branch['context_id']??$branch['osm_id']??'unknown'));
        }catch(\InvalidArgumentException|\RuntimeException $e){
            throw new \RuntimeException('Interchange structure '.($branch['context_id']??$branch['osm_id']??'unknown').': '.$e->getMessage(),0,$e);
        }
        $length=BeamRampNetwork::length($sampled);
        $shift=static function($spans)use($offset,$length){foreach($spans as &$s){$s['start_m']=max(0.,$s['start_m']-$offset);$s['end_m']=min($length,$s['end_m']-$offset);$s['length_m']=$s['end_m']-$s['start_m'];}unset($s);return array_values(array_filter($spans,static fn($s)=>$s['length_m']>.01));};
        return ['raw'=>array_slice($heights,count($prefix),count($sampled)),'bridges'=>$shift($bridges),'tunnels'=>$shift($tunnels),'approach_before_m'=>$offset,'approach_after_m'=>BeamRampNetwork::length($after)];
    }
    public static function tracks(array $prepared): array
    {
        $out=[];foreach($prepared['roads'] as $b)if(!empty($b['bridges'])||!empty($b['tunnels'])){
            $chain=[0.];foreach($b['points'] as $i=>$p)if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$b['points'][$i-1][0],$p[1]-$b['points'][$i-1][1]);
            $out[]=['name'=>$b['name'],'road'=>$b['points'],'profiles'=>$b['profiles'],'chain'=>$chain,'bridges'=>$b['bridges'],'tunnels'=>$b['tunnels'],'junctions'=>$b['junctions']??[]];
        }return $out;
    }
    /** Repair landward bridge approaches without raising the valley floor below the deck. */
    public static function approaches(array $tracks,array $mainSurfaces,BeamRaster $raster,float $origin,callable $progress): void
    {
        foreach($tracks as $index=>$track)if($track['bridges']){
            $mask=BeamRoadSurface::bridgeApproachMask($track['road'],$track['profiles'],$track['chain'],$track['bridges'],$raster,$progress);
            foreach($tracks as $j=>$other)if($j!==$index)$mask=BeamTrack::mask($other,$raster,$mask,$progress);
            foreach($mainSurfaces as $surface){
                $other=['road'=>$surface['points'],'profiles'=>$surface['profiles'],'chain'=>[0.],'bridges'=>$surface['spans']??[],'tunnels'=>[]];
                foreach($other['road'] as $i=>$p)if($i)$other['chain'][]=$other['chain'][$i-1]+hypot($p[0]-$other['road'][$i-1][0],$p[1]-$other['road'][$i-1][1]);
                $mask=BeamTrack::mask($other,$raster,$mask,$progress);
                $mask=BeamRoadSurface::primaryContactMask($surface['points'],$surface['profiles'],$raster,$mask);
            }
            $tunnel=$track;$tunnel['bridges']=[];$mask=BeamTrack::mask($tunnel,$raster,$mask,$progress);
            BeamRoadSurface::blendTerrain($track['road'],$track['profiles'],$raster,$origin,$mask,$progress,$track['bridges']);
            BeamRoadSurface::clearTerrain($track['road'],$track['profiles'],$raster,$origin,$progress,$mask,true);
            BeamTerrainContact::clear($track['road'],$track['profiles'],$raster,$origin,$mask,$progress);
        }
    }
    public static function writeBridges(array $tracks,string $prefix,string $path,callable $put,BeamRaster $raster,float $origin,array $obstacles=[]): array
    {
        $objects=[];$material=$prefix.'_bridge';
        $static=static fn($name,$file,$collision='Visible Mesh Final')=>['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>$collision,'decalType'=>'None','castShadows'=>true];
        foreach($tracks as $track)if($track['bridges']){
            $put('art/shapes/bridge-pier.dae',BeamAssets::shape($material,'bridge-pier'));
            $put('art/shapes/bridge.materials.json',json_encode([$material=>['class'=>'Material','name'=>$material,'mapTo'=>$material,'version'=>1,'groundType'=>'CONCRETE','Stages'=>[['diffuseColor'=>[.48,.49,.5,1]]]]],JSON_THROW_ON_ERROR));
            $sections=BeamJunctionBoundary::sections($track['road'],$track['profiles']);$key=$track['name'];
            $deck=BeamBridgeDeck::build($track['road'],$sections,$track['bridges'],$material);
            $file='art/shapes/'.$key.'-deck.dae';$put($file,$deck['xml']);$objects[]=$static($key.'_deck',$file,'None');
            foreach($track['bridges'] as $i=>$span){
                foreach(BeamBridgeDeck::barriers($track['road'],$sections,$span,$material,$track['junctions']??[]) as $side=>$xml){
                    $file='art/shapes/'.$key.'-bridge-'.$i.'-'.$side.'.dae';$put($file,$xml);$objects[]=$static($key.'_bridge_'.$i.'_rail_'.$side,$file);
                }
                [$first,$last]=BeamStructures::indices($track['chain'],$span);$previous=-INF;
                for($j=$first;$j<=$last;$j++)if($track['chain'][$j]-$previous>=25){
                    $previous=$track['chain'][$j];$p=$track['road'][$j];$ground=$raster->height($p[0],$p[1])-$origin;$height=$p[2]-.5-$ground;
                    $blocked=false;foreach($obstacles as $other){
                        if(($other['name']??null)===$key)continue;
                        $near=BeamIntersections::nearest($p,$other['points'],$other['profiles']);
                        if($near&&$near['distance']<$near['half_width']+3&&$near['z']<$p[2]&&$near['z']>$ground-1){$blocked=true;break;}
                    }
                    if($height>.4&&!$blocked){$o=$static($key.'_bridge_'.$i.'_pier_'.$j,'art/shapes/bridge-pier.dae');$o['position']=[$p[0],$p[1],$ground-.2];$o['scale']=[1.5,1.5,$height+.2];$objects[]=$o;}
                }
            }
        }return $objects;
    }
}
