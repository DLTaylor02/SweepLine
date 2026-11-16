<?php
declare(strict_types=1);
namespace SweepLine;

/** Add missing link-road context to legacy active imports without replacing selected roads/candidates. */
final class BeamRampBackfill
{
    public const VERSION=2;
    public static function parse(array $data): array
    {
        $ways=[];$vertices=0;
        if(count($data['elements']??[])>=5001)throw new \RuntimeException('Ramp supplement exceeded its geometry limit.');
        foreach($data['elements']??[] as $e){
            if(($e['type']??'')!=='way'||!BeamRampNetwork::link($e['tags']['highway']??''))continue;
            $tags=$e['tags'];foreach(['access','vehicle','motor_vehicle','motorcar'] as $k)if(isset($tags[$k])&&!in_array($tags[$k],['yes','designated','permissive'],true))continue 2;
            if(isset($tags['construction'])||isset($tags['access:conditional'])||isset($tags['motor_vehicle:conditional'])||isset($tags['motorcar:conditional']))continue;
            $nodes=$e['nodes']??[];$geometry=$e['geometry']??[];
            if(!is_numeric($e['id']??null)||count($nodes)<2||count($nodes)!==count($geometry)||count($nodes)>2000)throw new \RuntimeException('Incomplete ramp supplement geometry.');
            $vertices+=count($geometry);if($vertices>50000)throw new \RuntimeException('Ramp supplement vertex limit exceeded.');
            $points=[];foreach($geometry as $i=>$p){
                if(!is_numeric($nodes[$i])||!is_numeric($p['lon']??null)||!is_numeric($p['lat']??null)||!is_finite((float)$p['lon'])||!is_finite((float)$p['lat'])||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90)throw new \RuntimeException('Invalid ramp supplement coordinates.');
                $points[]=[(float)$p['lon'],(float)$p['lat']];
            }
            $ways[]=['id'=>(int)$e['id'],'nodes'=>array_map('intval',$nodes),'points'=>$points,'tags'=>$tags,'direction'=>match($tags['oneway']??''){'yes','1','true'=>1,'-1'=>-1,default=>($tags['junction']??'')==='roundabout'?1:0}];
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
    public static function ensure(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array
    {
        $lines=[$plan['points']];foreach($plan['carriageways']??[] as $t)$lines[]=$t['points'];
        $q=$db->prepare("SELECT r.id region_id,j.id job_id,ST_AsGeoJSON(ST_ExteriorRing(j.coverage),6) ring,ST_Area(j.coverage::geography) area
            FROM regions r JOIN import_jobs j ON j.id=r.active_job_id
            WHERE j.ramp_context_version<2 AND j.status='complete'
            AND j.coverage && ST_Transform(ST_Expand(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),3500),4326)
            ORDER BY j.id LIMIT 65");
        $q->execute([json_encode(['type'=>'MultiLineString','coordinates'=>$lines],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);$report=['legacy_regions'=>count($rows),'upgraded'=>0,'added_ramp_ways'=>0,'failures'=>[]];
        foreach(array_slice($rows,0,64) as $row){
            $progress('Matching road network: backfilling missing ramp data');
            try{
                if((float)$row['area']>1e10)throw new \RuntimeException('Legacy region is too large for a bounded ramp supplement.');
                $ring=json_decode($row['ring'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
                $poly=implode(' ',array_map(static fn($p)=>$p[1].' '.$p[0],$ring));if(strlen($poly)>100000)throw new \RuntimeException('Ramp supplement corridor is too complex.');
                $query='[out:json][timeout:90][maxsize:16777216];way[highway~"^(motorway|trunk|primary|secondary|tertiary)_link$"](poly:"'.$poly.'");out geom 5001;';
                $data=BeamOsm::download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/ramp-supplement-'.$row['job_id'].'.json',http_build_query(['data'=>$query]),static fn($m)=>$progress(null),$download,null,'ramp supplements');
                $ways=self::parse($data);$report['added_ramp_ways']+=self::store($db,(int)$row['region_id'],(int)$row['job_id'],$ways);$report['upgraded']++;
            }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$report['failures'][]=['job_id'=>(int)$row['job_id'],'reason'=>$e instanceof DownloadFailure?BeamOsm::failureReason($e):'Supplement validation or storage failed.'];}
        }
        if(count($rows)>64)$report['failures'][]=['reason'=>'Legacy region supplement budget reached.'];
        return $report;
    }
}
