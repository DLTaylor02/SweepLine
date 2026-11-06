<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;

final class Api
{
    public function __construct(private PDO $db) {}
    public function zip(string $zip): array
    {
        Validation::zip($zip);
        $q=$this->db->prepare('SELECT zip,place,state,ST_X(location::geometry) AS lon,ST_Y(location::geometry) AS lat,source FROM zip_codes WHERE zip=?'); $q->execute([$zip]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('ZIP code not found in local data.');
    }
    public function nearestZip(array $input): array
    {
        $lon=Validation::number($input,'lon',NAN,-180,180);
        $lat=Validation::number($input,'lat',NAN,-90,90);
        $q=$this->db->prepare("WITH point AS (SELECT ST_SetSRID(ST_MakePoint(?,?),4326)::geography AS geom)
            SELECT z.zip,z.place,z.state,ST_Distance(z.location,p.geom) AS distance_m
            FROM zip_codes z CROSS JOIN point p WHERE ST_DWithin(z.location,p.geom,40233.6)
            ORDER BY ST_Distance(z.location,p.geom),z.zip LIMIT 1");
        $q->execute([$lon,$lat]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('No US ZIP center found within 25 miles. Choose a point closer to a populated area or enter a ZIP manually.');
    }
    public function coverage(): array
    {
        return $this->db->query('SELECT slug,name,imported_at,active_job_id,ST_AsGeoJSON(coverage) AS geometry FROM regions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function search(array $input): array
    {
        $p=Validation::search($input); $zip=$this->zip($p['zip']);
        // All geometry and scores are precomputed. Indexed spheroidal distance, not a bbox filter.
        // Plan IDs/point counts first: do not transfer unbounded full-resolution geometry.
        $plan=$this->db->prepare("SELECT c.id,ST_NPoints(c.geom::geometry) AS point_count
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id
            JOIN road_scores s ON s.candidate_id=c.id AND s.profile=:profile
            WHERE ST_DWithin(c.geom,ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography,:meters)
            ORDER BY s.score DESC,c.length_m DESC,c.id LIMIT 601");
        $plan->execute(['profile'=>$p['profile'],'lon'=>$zip['lon'],'lat'=>$zip['lat'],'meters'=>$p['radius_miles']*1609.344]);
        $planned=$plan->fetchAll(PDO::FETCH_ASSOC);$truncated=count($planned)>600;$planned=array_slice($planned,0,600);
        if(array_sum(array_column($planned,'point_count'))>200000)throw new HttpException(422,'Road geometry limit reached. Use a smaller search radius.');
        $rows=[];
        if($planned) {
            $ids=array_column($planned,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
            $q=$this->db->prepare("SELECT c.*,s.score,s.confidence,s.dimensions,
                COALESCE((SELECT rs.direction*CASE WHEN m.reversed THEN -1 ELSE 1 END FROM candidate_segments m JOIN road_segments rs ON rs.id=m.segment_id WHERE m.candidate_id=c.id AND rs.direction<>0 ORDER BY m.position LIMIT 1),0) AS travel_direction,
                ST_AsGeoJSON(c.geom::geometry,9) AS geometry
                FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id
                JOIN road_scores s ON s.candidate_id=c.id AND s.profile=?
                WHERE c.id IN ($marks)");
            $q->execute(array_merge([$p['profile']],$ids));$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        $service=new RoadConsolidator($this->db);$results=[];
        foreach(RoadOverlap::groups(array_map([$this,'decode'],$rows)) as $group) {
            $road=$service->combine($group,$p['profile']);
            if(!RoadDiagnostics::reasons($road,$p))$results[]=$road;
        }
        usort($results,static fn($a,$b)=>($b['score']<=>$a['score'])?:($b['length_m']<=>$a['length_m'])?:($a['id']<=>$b['id']));
        if(count($results)>100)$truncated=true;$results=array_slice($results,0,100);
        $coverage=$this->db->prepare('SELECT COALESCE(ST_Covers(ST_UnaryUnion(ST_Collect(coverage)),ST_Buffer(ST_SetSRID(ST_MakePoint(?,?),4326)::geography,?)::geometry),false) FROM regions WHERE active_job_id IS NOT NULL');
        $coverage->execute([$zip['lon'],$zip['lat'],$p['radius_miles']*1609.344]);
        return ['center'=>$zip,'parameters'=>$p,'results'=>$results,'truncated'=>$truncated,'full_radius_covered'=>(bool)$coverage->fetchColumn(),
            'notes'=>['Overlapping unbranched geometry is combined before filters; up to 600 source candidates and 200,000 geometry points are analyzed.','Candidates intersect the radius; metrics describe the entire stretch.','Stop counts are mapped lower bounds; the filter includes uncertain controls.','OSM may omit stops, closures, surfaces and speed limits. Verify conditions before driving.']];
    }
    public function detail(int $id,string $profile,array $memberIds=[]): array
    {
        if (!isset(Scorer::profiles()[$profile])) { throw new \InvalidArgumentException('Unknown profile'); }
        if(count($memberIds)>1) {
            $ids=self::memberIds($memberIds,$id);$members=[];
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $budget=$this->db->prepare("SELECT count(DISTINCT c.id) AS count,COALESCE(sum(ST_NPoints(c.geom::geometry)),0) AS points FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id WHERE c.id IN ($marks)");
            $budget->execute($ids);$size=$budget->fetch(PDO::FETCH_ASSOC);
            if((int)$size['count']!==count($ids))throw new \OutOfBoundsException('Road not found in active coverage');
            if((int)$size['points']>200000)throw new HttpException(422,'Road geometry limit reached. Select a smaller stretch.');
            foreach($ids as $member)$members[]=$this->detail($member,$profile);
            $groups=RoadOverlap::groups($members);
            if(count($groups)!==1)throw new \InvalidArgumentException('Selected road pieces do not form one overlapping, direction-compatible continuous stretch.');
            return (new RoadConsolidator($this->db))->combine($groups[0],$profile);
        }
        $q=$this->db->prepare('SELECT c.*,s.score,s.confidence,s.dimensions,COALESCE((SELECT rs.direction*CASE WHEN m.reversed THEN -1 ELSE 1 END FROM candidate_segments m JOIN road_segments rs ON rs.id=m.segment_id WHERE m.candidate_id=c.id AND rs.direction<>0 ORDER BY m.position LIMIT 1),0) AS travel_direction,ST_AsGeoJSON(c.geom::geometry,9) AS geometry FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id JOIN road_scores s ON s.candidate_id=c.id AND s.profile=? WHERE c.id=?'); $q->execute([$profile,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('Road not found in active coverage'); unset($row['geom']);
        return $this->decode($row);
    }
    public function exportRoad(int $id,string $profile,bool $withMap=false,array $memberIds=[]): array
    {
        $road=$this->detail($id,$profile,$memberIds);
        if(count($memberIds)>1) {
            // The union is already oriented legally; do not replay full source ways.
            $road['parts']=[['name'=>$road['name'],'ref'=>$road['ref'],'direction'=>$road['travel_direction'],'reversed'=>false,'geometry'=>$road['geometry']]];
            $marks=implode(',',array_fill(0,count($memberIds),'?'));
            $attributes=$this->db->prepare("SELECT DISTINCT s.id,s.osm_id,s.name,s.ref,s.direction,s.highway,s.tags,ST_AsGeoJSON(s.geom,9) AS geometry FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id IN ($marks) LIMIT 501");
            $attributes->execute($memberIds);$road['source_parts']=$attributes->fetchAll(PDO::FETCH_ASSOC);
            foreach($road['source_parts'] as &$part){$part['geometry']=json_decode($part['geometry'],true,512,JSON_THROW_ON_ERROR);$part['tags']=json_decode($part['tags'],true,512,JSON_THROW_ON_ERROR);}unset($part);
            if($withMap)$road['basemap']=ExportMap::load($this->db,$road);return $road;
        }
        $q=$this->db->prepare('SELECT ST_AsGeoJSON(c.geom::geometry,9) FROM road_candidates c WHERE c.id=?');
        $q->execute([$id]);$road['geometry']=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $q=$this->db->prepare('SELECT s.osm_id,s.name,s.ref,s.direction,s.highway,s.tags,m.reversed,ST_AsGeoJSON(s.geom,9) AS geometry
            FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=? ORDER BY m.position');
        $q->execute([$id]);$road['parts']=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($road['parts'] as &$part) { $part['geometry']=json_decode($part['geometry'],true,512,JSON_THROW_ON_ERROR);$part['tags']=json_decode($part['tags'],true,512,JSON_THROW_ON_ERROR); }
        unset($part);
        if ($withMap) {$road['basemap']=ExportMap::load($this->db,$road);}
        return $road;
    }
    public static function memberIds(mixed $input,int $anchor): array
    {
        if($input===null || $input==='')return [];
        if(is_string($input))$input=explode(',',$input);
        if(!is_array($input) || count($input)>50 || !$input)throw new \InvalidArgumentException('A road may combine at most 50 candidate IDs.');
        $ids=[];
        foreach($input as $value) {
            if((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,14}$/D',(string)$value))throw new \InvalidArgumentException('Invalid road member ID.');
            $ids[]=(int)$value;
        }
        $ids=array_values(array_unique($ids));sort($ids);
        if(!in_array($anchor,$ids,true))throw new \InvalidArgumentException('Road members must include the selected road.');
        return $ids;
    }
    private function decode(array $row): array
    {
        foreach (['geometry','metrics','dimensions','alternate_names'] as $key) { if (isset($row[$key])) { $row[$key]=json_decode($row[$key],true,512,JSON_THROW_ON_ERROR); } }
        foreach (['id','mandatory_stops','uncertain_controls'] as $key) { if (isset($row[$key])) { $row[$key]=(int)$row[$key]; } }
        foreach (['length_m','score','confidence','speed_mph','elevation_gain_m','elevation_loss_m'] as $key) { if (isset($row[$key])) { $row[$key]=(float)$row[$key]; } }
        unset($row['geom']);$row['alternate_names']=RoadNames::collect([$row],$row['name']);
        if(isset($row['metrics']['curve_density'])) {$row['metrics']['curve_density_score']=round(Scorer::densityScore((float)$row['metrics']['curve_density']),1);}
        if(isset($row['metrics']['curvature'])) {$row['metrics']['curvature_quality_score']=round(Scorer::qualityScore((float)$row['metrics']['curvature']),2);}
        return $row;
    }
}
