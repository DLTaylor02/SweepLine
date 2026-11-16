<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded context network; connectivity is established by real OSM node IDs, never proximity. */
final class BeamRampNetwork
{
    public const MAX_RAMP_M=3218.688;
    public static function link(string $highway): bool {return in_array($highway,['motorway_link','trunk_link','primary_link','secondary_link','tertiary_link'],true);}
    public static function allowed(array $tags): bool
    {
        foreach(['access','vehicle','motor_vehicle','motorcar'] as $key)if(in_array($tags[$key]??'',['no','private'],true))return false;
        return !isset($tags['construction']);
    }
    public static function expand(\PDO $db,array $plan,array $branches,callable $progress): array
    {
        $cache=[];
        $load=static function(array $nodes)use($db,$plan,&$cache):array{
            $missing=array_values(array_filter(array_unique($nodes),static fn($n)=>!array_key_exists((string)$n,$cache)));
            if($missing){
                $q=$db->prepare("SELECT DISTINCT ON (c.node_id,s.osm_id) c.node_id,s.osm_id,s.highway,s.direction,s.tags,s.node_ids,c.point_index,ST_AsGeoJSON(ST_Transform(s.geom,CAST(? AS integer)),6) geometry
                    FROM road_connectivity c JOIN road_segments s ON s.id=c.segment_id JOIN regions r ON r.active_job_id=s.job_id
                    WHERE c.node_id IN (SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb))
                    AND ST_NPoints(s.geom)<=2000
                    AND s.highway IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary','secondary_link','tertiary','tertiary_link','unclassified','residential','living_street')
                    ORDER BY c.node_id,s.osm_id,s.job_id DESC LIMIT 8001");
                $q->execute([$plan['epsg'],json_encode($missing,JSON_THROW_ON_ERROR)]);$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
                if(count($rows)>=8001)throw new BeamInterchangeLimit('Interchange connectivity query exceeded its bounded record count.');
                foreach($missing as $n)$cache[(string)$n]=[];
                foreach($rows as $row){
                    $row['points']=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
                    $row['node_ids']=json_decode($row['node_ids'],true,512,JSON_THROW_ON_ERROR);$row['tags']=json_decode($row['tags'],true,512,JSON_THROW_ON_ERROR);
                    unset($row['geometry']);$cache[(string)$row['node_id']][]=$row;
                }
            }
            $out=[];foreach($nodes as $n)$out[(string)$n]=$cache[(string)$n]??[];return $out;
        };
        $lookup=static fn(int $node)=>$load([$node])[(string)$node]??[];
        $junctions=static function(array $nodes,int $own)use($load):array{
            $out=[];foreach($load($nodes) as $node=>$rows){$rows=array_values(array_filter($rows,static fn($r)=>(int)$r['osm_id']!==$own));if($rows)$out[$node]=$rows;}return $out;
        };
        $main=[];foreach($plan['sources']??[] as $s)if(isset($s['osm_id']))$main[(string)$s['osm_id']]=true;
        return self::walk($branches,$lookup,$progress,$main,self::MAX_RAMP_M,$junctions);
    }
    public static function walk(array $branches,callable $lookup,callable $progress,array $main=[],float $budget=self::MAX_RAMP_M,?callable $junctions=null): array
    {
        return BeamInterchangeGraph::build($branches,$lookup,$progress,$main,$budget,$junctions);
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
        $side=max($east-$west,$north-$south);if($side>BeamPlan::MAX_SIDE)throw new BeamInterchangeLimit('Connected interchange exceeds the maximum map extent; choose a shorter selected road.');
        $cx=($west+$east)/2;$cy=($south+$north)/2;return [$cx-$side/2,$cy-$side/2,$cx+$side/2,$cy+$side/2];
    }
}
