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
        $q=$this->db->prepare('SELECT * FROM (SELECT DISTINCT ON (c.geometry_key) c.id,c.name,c.ref,c.highway,c.length_m,c.mandatory_stops,c.uncertain_controls,c.speed_mph,c.metrics,c.elevation_gain_m,c.elevation_loss_m,s.score,s.confidence,s.dimensions,
            ST_AsGeoJSON(ST_SimplifyPreserveTopology(c.geom::geometry,0.00006),5) AS geometry
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id
            JOIN road_scores s ON s.candidate_id=c.id AND s.profile=:profile
            WHERE ST_DWithin(c.geom,ST_SetSRID(ST_MakePoint(:lon,:lat),4326)::geography,:meters)
            AND c.length_m > :minimum
            AND c.length_m <= :maximum
            AND c.mandatory_stops+c.uncertain_controls <= :stops
            AND (CAST(:paved AS boolean)=false OR c.surface_known_paved=true)
            ORDER BY c.geometry_key,r.imported_at DESC,s.confidence DESC,c.id) ranked
            ORDER BY score DESC,length_m DESC,id LIMIT 101');
        $q->execute(['profile'=>$p['profile'],'lon'=>$zip['lon'],'lat'=>$zip['lat'],'meters'=>$p['radius_miles']*1609.344,
            'minimum'=>$p['min_length_miles']*1609.344,'maximum'=>$p['max_length_miles']===null ? PHP_FLOAT_MAX : $p['max_length_miles']*1609.344,'stops'=>$p['max_stops'],'paved'=>$p['paved_only']?'true':'false']);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC); $truncated=count($rows)>100; $rows=array_slice($rows,0,100);
        $results=array_map([$this,'decode'],$rows);
        $coverage=$this->db->prepare('SELECT COALESCE(ST_Covers(ST_UnaryUnion(ST_Collect(coverage)),ST_Buffer(ST_SetSRID(ST_MakePoint(?,?),4326)::geography,?)::geometry),false) FROM regions WHERE active_job_id IS NOT NULL');
        $coverage->execute([$zip['lon'],$zip['lat'],$p['radius_miles']*1609.344]);
        return ['center'=>$zip,'parameters'=>$p,'results'=>$results,'truncated'=>$truncated,'full_radius_covered'=>(bool)$coverage->fetchColumn(),
            'notes'=>['Candidates intersect the radius; metrics describe the entire stretch.','Stop counts are mapped lower bounds; the filter includes uncertain controls.','OSM may omit stops, closures, surfaces and speed limits. Verify conditions before driving.']];
    }
    public function detail(int $id,string $profile): array
    {
        if (!isset(Scorer::profiles()[$profile])) { throw new \InvalidArgumentException('Unknown profile'); }
        $q=$this->db->prepare('SELECT c.*,s.score,s.confidence,s.dimensions,ST_AsGeoJSON(c.geom::geometry,6) AS geometry FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id JOIN road_scores s ON s.candidate_id=c.id AND s.profile=? WHERE c.id=?'); $q->execute([$profile,$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC) ?: throw new \OutOfBoundsException('Road not found in active coverage'); unset($row['geom']);
        return $this->decode($row);
    }
    public function exportRoad(int $id,string $profile): array
    {
        $road=$this->detail($id,$profile);
        $q=$this->db->prepare('SELECT ST_AsGeoJSON(c.geom::geometry,9) FROM road_candidates c WHERE c.id=?');
        $q->execute([$id]);$road['geometry']=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $q=$this->db->prepare('SELECT s.name,s.ref,s.direction,m.reversed,ST_AsGeoJSON(s.geom,9) AS geometry
            FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=? ORDER BY m.position');
        $q->execute([$id]);$road['parts']=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($road['parts'] as &$part) { $part['geometry']=json_decode($part['geometry'],true,512,JSON_THROW_ON_ERROR); }
        return $road;
    }
    private function decode(array $row): array
    {
        foreach (['geometry','metrics','dimensions'] as $key) { if (isset($row[$key])) { $row[$key]=json_decode($row[$key],true,512,JSON_THROW_ON_ERROR); } }
        foreach (['id','mandatory_stops','uncertain_controls'] as $key) { if (isset($row[$key])) { $row[$key]=(int)$row[$key]; } }
        foreach (['length_m','score','confidence','speed_mph','elevation_gain_m','elevation_loss_m'] as $key) { if (isset($row[$key])) { $row[$key]=(float)$row[$key]; } }
        return $row;
    }
}
