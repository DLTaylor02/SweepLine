<?php
declare(strict_types=1);
namespace SweepLine;

/** Legacy supplement helpers; exports now refresh obsolete regions through the normal importer. */
final class BeamRampBackfill
{
    public const VERSION=3;
    public static function parse(array $data): array
    {
        $ways=[];$vertices=0;
        if(count($data['elements']??[])>=5001)throw new \RuntimeException('Ramp supplement exceeded its geometry limit.');
        foreach($data['elements']??[] as $e){
            if(($e['type']??'')!=='way'||!BeamRampNetwork::link($e['tags']['highway']??''))continue;
            $tags=$e['tags'];foreach(['access','vehicle','motor_vehicle','motorcar'] as $k)if(isset($tags[$k])&&!in_array($tags[$k],['yes','designated','permissive'],true))continue 2;
            if(($tags['area']??'')==='yes'||isset($tags['construction'])||isset($tags['access:conditional'])||isset($tags['motor_vehicle:conditional'])||isset($tags['motorcar:conditional']))continue;
            $nodes=$e['nodes']??[];$geometry=$e['geometry']??[];
            if(!is_numeric($e['id']??null)||count($nodes)<2||count($nodes)!==count($geometry)||count($nodes)>2000)throw new \RuntimeException('Incomplete ramp supplement geometry.');
            $vertices+=count($geometry);if($vertices>50000)throw new \RuntimeException('Ramp supplement vertex limit exceeded.');
            $points=[];foreach($geometry as $i=>$p){
                if(!is_numeric($nodes[$i])||!is_numeric($p['lon']??null)||!is_numeric($p['lat']??null)||!is_finite((float)$p['lon'])||!is_finite((float)$p['lat'])||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90)throw new \RuntimeException('Invalid ramp supplement coordinates.');
                $points[]=[(float)$p['lon'],(float)$p['lat']];
            }
            $ways[]=['id'=>(int)$e['id'],'nodes'=>array_map('intval',$nodes),'points'=>$points,'tags'=>$tags,'direction'=>match($tags['oneway']??''){'yes','1','true'=>1,'-1'=>-1,default=>(($tags['junction']??'')==='roundabout'||$tags['highway']==='motorway')?1:0}];
        }return $ways;
    }
    public static function store(\PDO $db,int $region,int $job,array $ways): int
    {
        $db->beginTransaction();
        try{
            $q=$db->prepare('SELECT active_job_id FROM regions WHERE id=? FOR UPDATE');$q->execute([$region]);
            if((int)$q->fetchColumn()!==$job)throw new \RuntimeException('Region changed during ramp preparation.');
            $node=$db->prepare("INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) VALUES (?,?,?,?,'{}'::jsonb) ON CONFLICT(job_id,osm_id) DO NOTHING");
            $segment=$db->prepare('INSERT INTO road_segments(job_id,osm_id,highway,direction,node_ids,tags,geom) VALUES (?,?,?,?,?::jsonb,?::jsonb,ST_SetSRID(ST_GeomFromGeoJSON(?),4326)) ON CONFLICT(job_id,osm_id) DO NOTHING RETURNING id');
            $connect=$db->prepare('INSERT INTO road_connectivity(job_id,node_id,segment_id,point_index) VALUES (?,?,?,?) ON CONFLICT DO NOTHING');$added=0;
            foreach($ways as $w){
                $segment->execute([$job,$w['id'],$w['tags']['highway'],$w['direction'],json_encode($w['nodes'],JSON_THROW_ON_ERROR),json_encode($w['tags'],JSON_THROW_ON_ERROR),json_encode(['type'=>'LineString','coordinates'=>$w['points']],JSON_THROW_ON_ERROR)]);
                $id=$segment->fetchColumn();if($id===false)continue;
                foreach($w['nodes'] as $i=>$n){$node->execute([$job,$n,$w['points'][$i][0],$w['points'][$i][1]]);$connect->execute([$job,$n,$id,$i]);}$added++;
            }
            $db->prepare('UPDATE import_jobs SET ramp_context_version=2 WHERE id=?')->execute([$job]);$db->commit();return $added;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function ensure(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null,?callable $sleep=null): array
    {
        return BeamRegionRefresh::ensure($db,$plan,$dir,$progress,$download,$sleep);
    }
}
