<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Private, server-validated subsections of a single continuous mapped candidate. */
final class CustomRoads
{
    public function __construct(private PDO $db) {}
    public static function orientation(float $first,float $last,int $direction): string
    {
        return (($first>$last)!==($direction<0))?'reverse':'forward';
    }

    public static function identifier(array $input): int
    {
        $id=Validation::number($input,'custom_id',NAN,1,9007199254740991);
        if(floor($id)!==$id)throw new \InvalidArgumentException('Custom road ID must be a whole number.');
        return (int)$id;
    }

    public static function parameters(array $input): array
    {
        $points=[];
        foreach(['start','finish'] as $key){
            if(!is_array($input[$key]??null))throw new \InvalidArgumentException('Choose two map points.');
            $points[]= [Validation::number($input[$key],'lon',NAN,-180,180),Validation::number($input[$key],'lat',NAN,-85,85)];
        }
        $distance=Geometry::distance(...$points);
        if($distance<30||$distance>80467.2)throw new \InvalidArgumentException('Choose points between 100 feet and 50 miles apart.');
        $profile=$input['profile']??'twisty';
        if(!is_string($profile)||!isset(Scorer::profiles()[$profile]))throw new \InvalidArgumentException('Unknown driving profile');
        return ['points'=>$points,'profile'=>$profile];
    }
    public function create(int $user,array $input): array
    {
        $p=self::parameters($input);[$a,$b]=$p['points'];$profile=$p['profile'];
        $queue=new DataQueue($this->db);
        if(!$this->db->query('SELECT EXISTS(SELECT 1 FROM zip_codes)')->fetchColumn()){
            $task=$queue->ensure('us-zips','zips',[],true);
            if($task['status']==='failed')throw new HttpException(503,'ZIP preparation failed. A SuperAdmin can retry it in Manage.');
            return ['preparation'=>['state'=>'preparing','message'=>'Preparing ZIP coordinates','complete'=>0,'total'=>1]];
        }

        $preparation=$queue->prepareCustom($a,$b);
        if($preparation['state']!=='ready')return ['preparation'=>$preparation];
        // Prefer the legal carriageway matching click order on shared approaches.
        $q=$this->db->prepare("WITH clicks AS (
            SELECT ST_SetSRID(ST_MakePoint(?,?),4326) a,ST_SetSRID(ST_MakePoint(?,?),4326) b
        ), nearby AS (
            SELECT c.id,c.geom,p.a,p.b,ST_LineLocatePoint(c.geom::geometry,p.a) AS first,
                ST_LineLocatePoint(c.geom::geometry,p.b) AS last,
                COALESCE((SELECT s.direction*CASE WHEN m.reversed THEN -1 ELSE 1 END
                    FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id
                    WHERE m.candidate_id=c.id AND s.direction<>0 ORDER BY m.position LIMIT 1),0) AS direction
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id CROSS JOIN clicks p
            WHERE ST_DWithin(c.geom,p.a::geography,50) AND ST_DWithin(c.geom,p.b::geography,50)
              AND ST_Distance(c.geom,p.a::geography)<=(SELECT min(ST_Distance(n.geom,p.a::geography)) FROM road_candidates n JOIN regions nr ON nr.active_job_id=n.job_id WHERE ST_DWithin(n.geom,p.a::geography,50))+5
              AND ST_Distance(c.geom,p.b::geography)<=(SELECT min(ST_Distance(n.geom,p.b::geography)) FROM road_candidates n JOIN regions nr ON nr.active_job_id=n.job_id WHERE ST_DWithin(n.geom,p.b::geography,50))+5
              AND ST_NPoints(c.geom::geometry)<=200000 AND ST_IsSimple(c.geom::geometry)
        ) SELECT id,first,last FROM nearby
            ORDER BY CASE WHEN direction*(last-first)<0 THEN 1 ELSE 0 END,
                ST_Distance(geom,a::geography)+ST_Distance(geom,b::geography),id LIMIT 1");
        $q->execute([$a[0],$a[1],$b[0],$b[1]]);$match=$q->fetch(PDO::FETCH_ASSOC);
        if(!$match)throw new HttpException(422,'These points do not lie on one supported continuous road. Click within 165 feet of the same road; ambiguous forks, disconnected carriageways and gaps cannot be joined. Divided sections must follow a connected mapped path.');
        $first=(float)$match['first'];$last=(float)$match['last'];
        if(abs($first-$last)<0.0000001)throw new \InvalidArgumentException('Both points snap to the same position. Choose a longer stretch.');
        $q=$this->db->prepare('SELECT ST_AsGeoJSON(ST_LineSubstring(geom::geometry,?,?),9) FROM road_candidates WHERE id=?');
        $q->execute([min($first,$last),max($first,$last),$match['id']]);$geometry=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        if(count($geometry['coordinates'])>20000)throw new HttpException(422,'Too many geometry points. Select a shorter stretch.');
        if(Geometry::length($geometry['coordinates'])<30)throw new \InvalidArgumentException('The selected road stretch must be at least 100 feet long.');
        $api=new Api($this->db);$source=$api->exportRoad((int)$match['id'],$profile);
        $road=(new RoadConsolidator($this->db))->combine(['members'=>[$source],'points'=>$geometry['coordinates'],'direction'=>$source['travel_direction']],$profile,true);
        $road['parts']=[['name'=>$road['name'],'ref'=>$road['ref'],'direction'=>$road['travel_direction'],'reversed'=>false,'geometry'=>$geometry]];
        // Preserve source tags for lane/structure analysis, but clip their geometry to this selection.
        $q=$this->db->prepare("WITH selected AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),4326)::geography g)
            SELECT s.osm_id,s.name,s.ref,s.direction,s.highway,s.tags,ST_AsGeoJSON((ST_Dump(ST_CollectionExtract(ST_Intersection(s.geom,ST_Buffer(p.g,1)::geometry),2))).geom,9) geometry
            FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id CROSS JOIN selected p
            WHERE m.candidate_id=? AND ST_DWithin(s.geom::geography,p.g,2) LIMIT 501");
        $q->execute([json_encode($geometry,JSON_THROW_ON_ERROR),$match['id']]);$road['source_parts']=[];
        while($part=$q->fetch(PDO::FETCH_ASSOC)){
            $part['geometry']=json_decode($part['geometry'],true,512,JSON_THROW_ON_ERROR);
            $part['tags']=json_decode($part['tags'],true,512,JSON_THROW_ON_ERROR);$road['source_parts'][]=$part;
        }
        if(count($road['source_parts'])>500)throw new HttpException(422,'Too many source segments. Select a shorter stretch.');
        $road['selection']=['method'=>'custom','clicked_start'=>$a,'clicked_finish'=>$b,'snapped_geometry'=>$geometry];
        $road['preferred_orientation']=self::orientation($first,$last,(int)$road['travel_direction']);
        $zip=$this->db->prepare('SELECT zip FROM zip_codes ORDER BY location <-> ST_SetSRID(ST_MakePoint(?,?),4326)::geography LIMIT 1');
        $zip->execute($a);$search=Validation::search(['zip'=>$zip->fetchColumn(),'profile'=>$profile,'radius_miles'=>5]);
        $this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$user]);
            $this->db->prepare("DELETE FROM custom_roads WHERE user_id=? AND (created_at<now()-interval '7 days' OR id IN (SELECT id FROM custom_roads WHERE user_id=? ORDER BY id DESC OFFSET 99))")->execute([$user,$user]);
            $q=$this->db->prepare('INSERT INTO custom_roads(user_id,profile,snapshot,search_parameters) VALUES (?,?,?::jsonb,?::jsonb) RETURNING id');
            $q->execute([$user,$profile,json_encode($road,JSON_THROW_ON_ERROR),json_encode($search,JSON_THROW_ON_ERROR)]);$id=(int)$q->fetchColumn();
            $this->db->commit();
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
        return ['road'=>$this->detail($user,$id),'parameters'=>$search,'preparation'=>$preparation];
    }
    public function detail(int $user,int $id,bool $map=false): array
    {
        $q=$this->db->prepare("SELECT snapshot,search_parameters FROM custom_roads WHERE id=? AND user_id=? AND created_at>now()-interval '7 days'");
        $q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new \OutOfBoundsException('Custom road expired or not found. Select it again.');
        $road=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$road['custom_id']=$id;
        $road['search_parameters']=json_decode($row['search_parameters'],true,512,JSON_THROW_ON_ERROR);
        $road['favorite_id']=(new Favorites($this->db))->find($user,$road['geometry_key'],$road['search_parameters']['profile']);
        if($map)$road['basemap']=ExportMap::load($this->db,$road);
        return $road;
    }
}
