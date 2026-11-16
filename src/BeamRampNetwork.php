<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded context network; connectivity is established by real OSM node IDs, never proximity. */
final class BeamRampNetwork
{
    public const MAX_RAMP_M=3218.688;
    public const TOTAL_RAMP_M=8000.;
    public const MAX_PIECES=32;
    public static function share(float $remaining,int $carriageways): float {return max(0,$remaining)/max(1,$carriageways);}
    public static function link(string $highway): bool {return in_array($highway,['motorway_link','trunk_link','primary_link','secondary_link','tertiary_link'],true);}
    public static function allowed(array $tags): bool
    {
        foreach(['access','vehicle','motor_vehicle','motorcar'] as $key)if(in_array($tags[$key]??'',['no','private'],true))return false;
        return in_array($tags['bridge']??'no',['no','false','0'],true)&&in_array($tags['tunnel']??'no',['no','false','0'],true)&&!isset($tags['construction']);
    }
    public static function expand(\PDO $db,array $plan,array $branches,callable $progress): array
    {
        $cache=[];
        $lookup=static function(int $node)use($db,$plan,&$cache):array{
            if(isset($cache[$node]))return $cache[$node];
            $q=$db->prepare("SELECT DISTINCT ON (s.osm_id) s.osm_id,s.highway,s.direction,s.tags,s.node_ids,c.point_index,ST_AsGeoJSON(ST_Transform(s.geom,CAST(? AS integer)),6) geometry
                FROM road_connectivity c JOIN road_segments s ON s.id=c.segment_id JOIN regions r ON r.active_job_id=s.job_id
                WHERE c.node_id=? AND ST_NPoints(s.geom)<=2000
                AND s.highway IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary','secondary_link','tertiary','tertiary_link','unclassified','residential','living_street')
                ORDER BY s.osm_id,s.job_id DESC LIMIT 65");
            $q->execute([$plan['epsg'],$node]);$rows=[];
            foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $r){
                $r['points']=json_decode($r['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
                $r['node_ids']=json_decode($r['node_ids'],true,512,JSON_THROW_ON_ERROR);$r['tags']=json_decode($r['tags'],true,512,JSON_THROW_ON_ERROR);$rows[]=$r;
            }return $cache[$node]=$rows;
        };
        $main=[];foreach($plan['sources']??[] as $s)if(isset($s['osm_id']))$main[(string)$s['osm_id']]=true;
        return self::walk($branches,$lookup,$progress,$main,max(0,(float)($plan['ramp_budget_m']??self::TOTAL_RAMP_M)));
    }
    public static function walk(array $branches,callable $lookup,callable $progress,array $main=[],float $budget=self::TOTAL_RAMP_M): array
    {
        $out=[];$seen=[];$total=0.;$report=['ramps'=>0,'receiving_arms'=>0,'reasons'=>[],'ramp_limit_m'=>self::MAX_RAMP_M,'total_ramp_limit_m'=>$budget];
        $reject=static function($r)use(&$report){$report['reasons'][$r]=($report['reasons'][$r]??0)+1;};
        foreach($branches as $branch){
            if(!self::link($branch['highway']??'')){unset($branch['full_points'],$branch['arm_nodes']);$out[]=$branch;continue;}
            if(!self::allowed($branch['tags']??[])){$reject('mapped_structure_or_restriction');continue;}
            $points=$branch['full_points']??$branch['points'];$nodes=$branch['arm_nodes']??[];
            if(count($nodes)!==count($points)){$out[]=$branch;$reject('missing_node_identity');continue;}
            $length=self::length($points);$used=[(string)$branch['osm_id']=>true];$receiving=[];$complete=false;
            for($piece=0;$piece<self::MAX_PIECES;$piece++){
                $progress(null);
                if($length>self::MAX_RAMP_M||$total+$length>$budget){$reject('ramp_length_budget');break;}
                $neighbors=$lookup((int)end($nodes));if(count($neighbors)>=65){$reject('dense_junction');break;}
                $links=[];$receiving=[];
                foreach($neighbors as $r){
                    if(isset($used[(string)$r['osm_id']])||isset($main[(string)$r['osm_id']]))continue;
                    if(!self::allowed($r['tags'])){$reject('mapped_structure_or_restriction');continue;}
                    $at=(int)$r['point_index'];
                    foreach([0,1] as $side){
                        $arm=$side?array_slice($r['points'],$at):array_reverse(array_slice($r['points'],0,$at+1));
                        $ids=$side?array_slice($r['node_ids'],$at):array_reverse(array_slice($r['node_ids'],0,$at+1));
                        if(count($arm)<2||count($ids)!==count($arm))continue;
                        if((string)$ids[0]!==(string)end($nodes)||hypot($arm[0][0]-end($points)[0],$arm[0][1]-end($points)[1])>.2){$reject('inconsistent_node_geometry');continue;}
                        $r['arm']=$arm;$r['arm_ids']=$ids;$r['aligned']=$side===1;
                        if(self::link($r['highway'])){
                            $legal=(int)$r['direction']===0||(((int)$r['direction']>0)===$r['aligned']);
                            if((int)$r['direction']!==0&&$legal!==($branch['profile']['legal_forward']??true)){$reject('inconsistent_travel_direction');continue;}
                            $links[]=$r;
                        }
                        else $receiving[]=$r;
                    }
                }
                if($receiving){$complete=true;break;}
                if(count($links)!==1){$reject($links?'ambiguous_ramp_fork':'missing_local_connection');break;}
                $next=$links[0];$used[(string)$next['osm_id']]=true;
                array_push($points,...array_slice($next['arm'],1));array_push($nodes,...array_slice($next['arm_ids'],1));$length=self::length($points);
                if($piece===self::MAX_PIECES-1)$reject('piece_budget');
            }
            $limit=max(0,min(self::MAX_RAMP_M,$budget-$total));$points=BeamIntersections::clip($points,$limit);
            if(count($points)<2){$reject('ramp_length_budget');continue;}
            $actual=self::length($points);$total+=$actual;$complete=$complete&&abs($actual-$length)<.01;
            $id='ramp_'.substr(hash('sha256',json_encode([$branch['osm_id'],$points[0]],JSON_THROW_ON_ERROR)),0,16);
            $branch['points']=$points;$branch['context_id']=$id;$branch['kind']='ramp';$branch['limit_m']=$limit;
            $branch['source_way_ids']=array_keys($used);$branch['receiving_connection']=$complete;
            unset($branch['full_points'],$branch['arm_nodes']);$out[]=$branch;$report['ramps']++;
            if(!$complete)continue;
            foreach($receiving as $r){
                if(count($out)>=256){$reject('branch_budget');break;}
                $arm=BeamIntersections::clip($r['arm']);if(!$arm)continue;$key=hash('sha256',json_encode($arm,JSON_THROW_ON_ERROR));if(isset($seen[$key]))continue;$seen[$key]=true;
                $profile=BeamLanes::infer($r['tags'],$r['highway'],(int)$r['direction'],$r['aligned']);
                $profile['legal_forward']=(int)$r['direction']===0||(((int)$r['direction']>0)===$r['aligned']);
                $out[]=['points'=>$arm,'width_m'=>$profile['width_m'],'profile'=>$profile,'osm_id'=>(int)$r['osm_id'],'kind'=>'receiving_road','parent_context_id'=>$id,
                    'context_id'=>$id.'_road_'.substr($key,0,12),'highway'=>$r['highway'],'tags'=>$r['tags']];
                $report['receiving_arms']++;
            }
        }
        $report['total_ramp_m']=$total;return ['branches'=>array_slice($out,0,256),'ramp_network'=>$report,'truncated'=>count($out)>256||isset($report['reasons']['branch_budget'])];
    }
    public static function length(array $points): float
    {
        $d=0.;for($i=1;$i<count($points);$i++)$d+=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);return $d;
    }
    public static function extent(array $plan): array
    {
        $b=$plan['bounds'];$west=$b[0];$south=$b[1];$east=$b[2];$north=$b[3];
        $sets=[$plan['intersection_data']??[]];foreach($plan['carriageways']??[] as $track)$sets[]=$track['intersection_data']??[];
        foreach($sets as $set)foreach($set['branches']??[] as $branch)foreach($branch['points'] as $p){$west=min($west,$p[0]-80);$south=min($south,$p[1]-80);$east=max($east,$p[0]+80);$north=max($north,$p[1]+80);}
        $side=max($east-$west,$north-$south);if($side>BeamPlan::MAX_SIDE)return $b;
        $cx=($west+$east)/2;$cy=($south+$north)/2;return [$cx-$side/2,$cy-$side/2,$cx+$side/2,$cy+$side/2];
    }
}
