<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Context-only roadside branches from genuine shared OSM junction nodes. */
final class BeamIntersections
{
    public const LENGTH_M=91.44;
    public static function clip(array $points,float $limit=self::LENGTH_M): array
    {
        if(count($points)<2)return [];
        $out=[$points[0]];$remaining=$limit;
        for($i=1;$i<count($points)&&$remaining>0.001;$i++){
            $a=$points[$i-1];$b=$points[$i];$d=hypot($b[0]-$a[0],$b[1]-$a[1]);if($d<0.001)continue;
            $t=min(1,$remaining/$d);$out[]=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t];$remaining-=$d;
        }
        return count($out)>1?$out:[];
    }
    public static function fetch(PDO $db,array $plan,callable $progress): array
    {
        $progress('Preparing intersecting road context from local OSM junctions');
        // Spatially restrict first, then require a shared OSM node on the selected line.
        $q=$db->prepare("WITH route AS (
            SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)) g
        ), nearby AS MATERIALIZED (
            SELECT s.*,ST_Transform(s.geom,ST_SRID(p.g)) projected
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id CROSS JOIN route p
            WHERE s.geom && ST_Transform(ST_Expand(p.g,2),4326)
        ), main AS (
            SELECT s.id,s.job_id FROM nearby s CROSS JOIN route p
            WHERE ST_Length(ST_Intersection(s.projected,ST_Buffer(p.g,0.5)))>LEAST(10,ST_Length(s.projected)*0.6)
        ), junctions AS (
            SELECT DISTINCT c.job_id,c.node_id
            FROM main m JOIN road_connectivity c ON c.segment_id=m.id
            JOIN osm_nodes n ON n.job_id=c.job_id AND n.osm_id=c.node_id CROSS JOIN route p
            WHERE ST_DWithin(ST_Transform(ST_SetSRID(ST_MakePoint(n.lon,n.lat),4326),ST_SRID(p.g)),p.g,0.5)
        )
        SELECT DISTINCT s.osm_id,s.tags,s.highway,s.direction,c.point_index,ST_AsGeoJSON(s.projected,6) geometry
        FROM junctions j JOIN road_connectivity c ON c.job_id=j.job_id AND c.node_id=j.node_id
        JOIN nearby s ON s.id=c.segment_id
        WHERE NOT EXISTS(SELECT 1 FROM main m WHERE m.id=s.id)
        AND ST_NPoints(s.projected)<=2000
        AND s.highway IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary','secondary_link','tertiary','tertiary_link','unclassified','residential','living_street')
        AND COALESCE(s.tags->>'access','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motor_vehicle','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motorcar','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'bridge','no') IN ('no','false','0')
        AND COALESCE(s.tags->>'tunnel','no') IN ('no','false','0')
        ORDER BY s.osm_id,c.point_index LIMIT 1001");
        $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$out=[];$seen=[];$truncated=count($rows)>1000;
        foreach(array_slice($rows,0,1000) as $row){
            $progress(null);$points=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];$at=(int)$row['point_index'];
            $tags=json_decode($row['tags'],true,512,JSON_THROW_ON_ERROR);
            foreach([array_reverse(array_slice($points,0,$at+1)),array_slice($points,$at)] as $arm){
                $arm=self::clip($arm);if(!$arm)continue;
                $key=hash('sha256',json_encode(array_map(static fn($p)=>[round($p[0],2),round($p[1],2)],$arm)));
                if(isset($seen[$key]))continue;$seen[$key]=true;
                if(count($out)>=256){$truncated=true;break 2;}
                $profile=BeamLanes::infer($tags,$row['highway'],(int)$row['direction']);
                $out[]=['points'=>$arm,'width_m'=>$profile['width_m'],'profile'=>$profile,'osm_id'=>(int)$row['osm_id']];
            }
        }
        return ['branches'=>$out,'truncated'=>$truncated,'source'=>'Local active OSM shared-node connectivity','limit_ft'=>300];
    }
    /** Native terrain decals add context without overlapping road collision meshes. */
    public static function objects(array $data,BeamRaster $raster,array $bounds,float $origin,string $material): array
    {
        $out=[];
        foreach($data['branches']??[] as $i=>$branch){
            $nodes=[];
            $arm=self::clip($branch['points']);if(!$arm)continue;
            foreach(BeamPlan::sample($arm) as $p){
                $x=$p[0]-$bounds[0];$y=$p[1]-$bounds[1];
                if($x<0||$y<0||$x>=$raster->side||$y>=$raster->side)break;
                $nodes[]=[$x,$y,$raster->height($x,$y)-$origin,$branch['width_m']];
            }
            if(count($nodes)<2)continue;
            $out[]=['class'=>'DecalRoad','name'=>'intersection_context_'.$i,'__parent'=>'MissionGroup','position'=>array_slice($nodes[0],0,3),
                'material'=>$material,'nodes'=>$nodes,'textureLength'=>BeamPng::ROAD_TEXTURE_LENGTH_M,'overObjects'=>false,'improvedSpline'=>false,
                'drivability'=>0,'hiddenInNavi'=>true,'lanesLeft'=>0,'lanesRight'=>0];
        }
        return $out;
    }
    public static function exclusions(array $data,array $bounds): array
    {
        return array_map(static fn($b)=>['points'=>array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],self::clip($b['points'])),'width_m'=>$b['width_m']],$data['branches']??[]);
    }
}
