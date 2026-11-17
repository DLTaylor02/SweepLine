<?php
declare(strict_types=1);
namespace SweepLine;

/** Share lane transitions across actual same-road graph edges, without changing XY/Z. */
final class BeamNetworkLanes
{
    public static function identity(array $branch,array $point): array
    {
        $best=INF;$chosen=[];
        foreach($branch['source_parts']??[] as $source)for($i=1;$i<count($source['points']);$i++){
            $d=BeamLanes::distance($point,$source['points'][$i-1],$source['points'][$i])[0];
            if($d<$best){$best=$d;$chosen=$source;}
        }
        $tags=$chosen['tags']??$branch['tags']??[];
        return ['name'=>strtolower(trim($tags['name']??'')),'ref'=>strtolower(trim($tags['ref']??'')),
            'highway'=>$chosen['highway']??$branch['highway']??'','osm_id'=>$chosen['osm_id']??$branch['osm_id']??null];
    }
    private static function reverse(array $p): array
    {
        if(empty($p['oneway']))[$p['forward'],$p['backward']]=[$p['backward'],$p['forward']];
        $p['legal_forward']=empty($p['oneway'])||!($p['legal_forward']??true);
        if(isset($p['lane_paint'])){
            $paint=[];foreach($p['lane_paint'] as $id=>$line){
                $line['u']=1-$line['u'];
                if(empty($p['oneway'])&&$id!=='center')$id=($id[0]==='f'?'b':'f').substr($id,1);
                $paint[$id]=$line;
            }$p['lane_paint']=$paint;
        }
        return $p;
    }
    public static function apply(array $roads): array
    {
        $nodes=[];$ends=[];$links=[];$report=['joined_boundaries'=>0,'chains'=>0,'changed_samples'=>0,'ambiguous_endpoints'=>0];
        foreach($roads as $i=>$r){
            if(empty($r['network'])||empty($r['mapped_profiles'])||count($r['points'])!==count($r['mapped_profiles']))continue;
            foreach([0,1] as $side){
                $n=$side?count($r['points'])-1:0;$p=$r['points'][$n];$q=$r['points'][$side?$n-1:1];$length=hypot($q[0]-$p[0],$q[1]-$p[1]);if($length<.001)continue;
                $node=$r[$side?'end_node':'start_node']??null;if($node===null)continue;
                $key=$i.':'.$side;$nodes[(string)$node][]=$key;
                $ends[$key]=['road'=>$i,'side'=>$side,'point'=>$p,'dx'=>($q[0]-$p[0])/$length,'dy'=>($q[1]-$p[1])/$length,
                    'identity'=>$r['lane_endpoints'][$side]??[],'profile'=>$r['mapped_profiles'][$n]];
            }
        }
        $choices=[];
        foreach($nodes as $keys)foreach($keys as $a){
            $candidates=[];$x=$ends[$a];
            foreach($keys as $b){
                $y=$ends[$b];if($x['road']===$y['road'])continue;
                $u=$x['identity'];$v=$y['identity'];
                if(empty($u['highway'])||$u['highway']!==($v['highway']??null))continue;
                $same=(!empty($u['name'])&&$u['name']===($v['name']??null))||
                    (!empty($u['ref'])&&$u['ref']===($v['ref']??null))||
                    (!empty($u['osm_id'])&&$u['osm_id']===($v['osm_id']??null));
                if(!$same||($x['profile']['oneway']??false)!==($y['profile']['oneway']??false))continue;
                if(!empty($x['profile']['oneway'])){
                    $outX=($x['profile']['legal_forward']??true)!==(bool)$x['side'];
                    $outY=($y['profile']['legal_forward']??true)!==(bool)$y['side'];
                    if($outX===$outY)continue;
                }
                if(hypot($x['point'][0]-$y['point'][0],$x['point'][1]-$y['point'][1])>.25||abs($x['point'][2]-$y['point'][2])>.5)continue;
                $score=-($x['dx']*$y['dx']+$x['dy']*$y['dy']);
                if($score<cos(deg2rad(40)))continue;$candidates[$b]=$score;
            }
            arsort($candidates);$ids=array_keys($candidates);
            if(count($ids)>1&&$candidates[$ids[0]]-$candidates[$ids[1]]<.05){$report['ambiguous_endpoints']++;continue;}
            if($ids)$choices[$a]=$ids[0];
        }
        foreach($choices as $a=>$b)if(($choices[$b]??null)===$a)$links[$a]=$b;
        $seen=[];
        // Begin only at open chain ends; closed loops retain their existing profiles.
        $open=array_filter(array_keys($ends),static fn($key)=>!isset($links[$key]));
        usort($open,static function($a,$b)use($ends,$roads){
            $x=$ends[$a];$y=$ends[$b];
            return strcmp((string)($roads[$x['road']]['context_id']??$roads[$x['road']]['name']).':'.$x['side'],
                (string)($roads[$y['road']]['context_id']??$roads[$y['road']]['name']).':'.$y['side']);
        });
        foreach($open as $key){$end=$ends[$key];
            if(isset($links[$key])||isset($seen[$end['road']]))continue;
            $chain=[];$current=$key;
            while(isset($ends[$current])){
                $e=$ends[$current];$i=$e['road'];if(isset($seen[$i]))break;$seen[$i]=true;
                $chain[]=[$i,(bool)$e['side']];$exit=$i.':'.(1-$e['side']);
                if(!isset($links[$exit]))break;$current=$links[$exit];
            }
            if(count($chain)<2)continue;
            $points=[];$raw=[];$spans=[];$slices=[];$along=0.;
            foreach($chain as [$i,$reverse]){
                $r=$roads[$i];$p=$reverse?array_reverse($r['points']):$r['points'];
                $attrs=$reverse?array_map(self::reverse(...),array_reverse($r['mapped_profiles'])):$r['mapped_profiles'];
                $length=BeamRampNetwork::length($p);$offset=$along;$start=max(0,count($points)-1);
                foreach($r['bridges']??[] as $s)$spans[]=['start_m'=>$offset+($reverse?$length-$s['end_m']:$s['start_m']),
                    'end_m'=>$offset+($reverse?$length-$s['start_m']:$s['end_m'])];
                foreach($p as $j=>$v){
                    if($points&&$j===0){$raw[count($raw)-1]=$attrs[0];continue;}
                    if($points)$along+=hypot($v[0]-end($points)[0],$v[1]-end($points)[1]);
                    $points[]=$v;$raw[]=$attrs[$j];
                }
                $slices[]=[$i,$reverse,$start,count($p)];
            }
            $taper=BeamRoadSurface::shoulders(BeamRoadSurface::taper($points,$raw,$spans));
            foreach($slices as [$i,$reverse,$start,$count]){
                $profiles=array_slice($taper,$start,$count);
                if($reverse)$profiles=array_map(self::reverse(...),array_reverse($profiles));
                foreach($profiles as $j=>$p){
                    $p['lane_paint_segment']=!empty($p['transition'])||($j>0&&!empty($profiles[$j-1]['transition']));
                    if(abs($p['width_m']-$roads[$i]['profiles'][$j]['width_m'])>1e-6)$report['changed_samples']++;
                    // Existing geometry/marking metadata belongs to this edge, not its neighbor.
                    $roads[$i]['profiles'][$j]=array_replace($roads[$i]['profiles'][$j],$p);
                }
            }
            $report['chains']++;$report['joined_boundaries']+=count($chain)-1;
        }
        foreach($roads as &$r)unset($r['mapped_profiles'],$r['lane_endpoints']);unset($r);
        return ['roads'=>$roads,'report'=>$report];
    }
}
