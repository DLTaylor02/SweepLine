<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class RoadConsolidator
{
    public function __construct(private PDO $db) {}
    public function combine(array $group,string $profile,bool $recalculate=false): array
    {
        $members=$group['members'];
        usort($members,static fn($a,$b)=>($b['length_m']<=>$a['length_m'])?:($a['id']<=>$b['id']));
        $road=$members[0];$ids=array_map(static fn($r)=>(int)$r['id'],$members);sort($ids);
        $road['member_ids']=$ids;
        $road['alternate_names']=RoadNames::collect($members,$road['name']);
        if(count($members)===1&&!$recalculate)return $road;
        $key=hash('sha256',json_encode([$profile,Scorer::profiles()[$profile],$members,$recalculate?$group['points']:null],JSON_THROW_ON_ERROR));
        $cached=$this->db->prepare("SELECT snapshot FROM road_combination_cache WHERE cache_key=? AND created_at>now()-interval '7 days'");
        $cached->execute([$key]);$snapshot=$cached->fetchColumn();
        if($snapshot!==false)return json_decode($snapshot,true,512,JSON_THROW_ON_ERROR);
        $points=$group['points'];$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->prepare("WITH segments AS (
            SELECT DISTINCT s.id,s.job_id,s.node_ids FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id IN ($marks)
        ) SELECT DISTINCT n.job_id,n.osm_id,n.lon,n.lat,n.tags
            FROM segments s CROSS JOIN LATERAL jsonb_array_elements_text(s.node_ids) ids(node_id)
            JOIN osm_nodes n ON n.job_id=s.job_id AND n.osm_id=ids.node_id::bigint ORDER BY n.job_id DESC");
        $q->execute($ids);$coordinates=[];$tags=[];$jobs=[];
        while($n=$q->fetch(PDO::FETCH_ASSOC)) {
            $pointKey=RoadOverlap::pointKey([(float)$n['lon'],(float)$n['lat']]);
            if(!isset($coordinates[$pointKey])){$coordinates[$pointKey]=(int)$n['osm_id'];$tags[(int)$n['osm_id']]=json_decode($n['tags'],true,512,JSON_THROW_ON_ERROR);}
            $jobs[(int)$n['job_id']]=true;
        }
        $nodes=[];$missing=0;
        foreach($points as $pointIndex=>$p) {$keyPoint=RoadOverlap::pointKey($p);if(isset($coordinates[$keyPoint]))$nodes[]=$coordinates[$keyPoint];else{$nodes[]='missing:'.$keyPoint;if(!$recalculate||($pointIndex>0&&$pointIndex<count($points)-1))$missing++;}}
        $neighbors=[];
        if($jobs) {
            $jobMarks=implode(',',array_fill(0,count($jobs),'?'));
            $q=$this->db->prepare("SELECT x.node_id,CASE WHEN x.point_index>0 THEN s.node_ids->>(x.point_index-1) END AS previous,s.node_ids->>(x.point_index+1) AS next
                FROM road_connectivity x JOIN road_segments s ON s.id=x.segment_id
                WHERE x.job_id IN ($jobMarks) AND x.node_id IN (SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb))");
            $numeric=array_values(array_filter($nodes,'is_int'));
            $q->execute(array_merge(array_keys($jobs),[json_encode($numeric,JSON_THROW_ON_ERROR)]));
            while($n=$q->fetch(PDO::FETCH_ASSOC))foreach(['previous','next'] as $side)if($n[$side]!==null)$neighbors[(int)$n['node_id']][(int)$n[$side]]=true;
        }
        $uses=[];foreach($neighbors as $node=>$adjacent)$uses[$node]=count($adjacent)>2?2:1;
        $metrics=Geometry::metrics($points)+Controls::analyze($nodes,$tags,$uses);
        $metrics['uncertain_controls']+=$missing;
        $statuses=array_column($members,'surface_status');
        $surface=in_array('unpaved',$statuses,true)?'unpaved':(count(array_filter($statuses,static fn($v)=>$v==='paved'))===count($members)?'paved':'unknown');
        $speeds=array_column($members,'speed_mph');
        $speed=count($speeds)===count($members) && !in_array(null,$speeds,true)?min($speeds):null;
        $metrics+=['paved'=>$surface==='paved','surface_status'=>$surface,'highway'=>$road['highway'],'speed_mph'=>$speed,'elevation_source'=>null,'stop_inventory_complete'=>false,'endpoint_count'=>2];
        $metrics['curve_density_score']=round(Scorer::densityScore($metrics['curve_density']),1);
        $metrics['curvature_quality_score']=round(Scorer::qualityScore($metrics['curvature']),2);
        $keyQuery=$this->db->prepare("SELECT md5(encode(ST_AsEWKB(ST_Normalize(ST_SetSRID(ST_GeomFromGeoJSON(?),4326))),'hex'))");
        $keyQuery->execute([json_encode(['type'=>'LineString','coordinates'=>$points],JSON_THROW_ON_ERROR)]);
        $geometryKey=$keyQuery->fetchColumn();
        $road=array_replace($road,Scorer::score($metrics,$profile),[
            'geometry'=>['type'=>'LineString','coordinates'=>$points],'metrics'=>$metrics,'length_m'=>$metrics['length_m'],
            'mandatory_stops'=>$metrics['mandatory_stops'],'uncertain_controls'=>$metrics['uncertain_controls'],
            'surface_status'=>$surface,'surface_known_paved'=>$surface==='paved','speed_mph'=>$speed,
            'elevation_gain_m'=>null,'elevation_loss_m'=>null,
            'geometry_key'=>$geometryKey,'travel_direction'=>$group['direction']
        ]);
        $this->db->prepare("INSERT INTO road_combination_cache(cache_key,snapshot) VALUES (?,?::jsonb) ON CONFLICT(cache_key) DO UPDATE SET snapshot=excluded.snapshot,created_at=now()")->execute([$key,json_encode($road,JSON_THROW_ON_ERROR)]);
        $this->db->exec("DELETE FROM road_combination_cache WHERE created_at<now()-interval '7 days' OR cache_key IN (SELECT cache_key FROM road_combination_cache ORDER BY created_at DESC,cache_key OFFSET 500)");
        return $road;
    }
}

