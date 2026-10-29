<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class RoadDiagnostics
{
    public function __construct(private PDO $db) {}
    public static function reasons(array $road,array $p): array
    {
        $out=[];
        if ($road['length_m']<=$p['min_length_miles']*1609.344) {$out[]='Does not exceed the requested minimum length.';}
        if ($p['max_length_miles']!==null && $road['length_m']>$p['max_length_miles']*1609.344) {$out[]='Longer than the requested maximum length.';}
        $status=$road['surface_status']??Surface::classify($road['surface_values']??[]);
        if (!Surface::allowed($status,$p['surface_filter'])) {
            $out[]=$status==='unpaved'?'At least one piece is explicitly tagged as unpaved.':
                'Some pieces have unknown or unrecognized surface tags; confirmed paved is required.';
            $values=array_map(static fn($v)=>$v===null || $v===''?'missing':$v,$road['surface_values']??[]);
            if($values) {$out[]='Surface tags: '.implode(', ',$values).'.';}
        }
        if ($road['mandatory_stops']+$road['uncertain_controls']>$p['max_stops']) {$out[]='Mapped stops plus uncertain controls exceed the limit.';}
        foreach (Scorer::eligibility($p) as $key=>$minimum) {
            $value=$road['metrics'][$key]??null;
            if (!is_numeric($value) || (float)$value<$minimum) {
                if($key==='curve_density') {$out[]='Curve density: '.($value===null?'missing':round(Scorer::densityScore((float)$value),2).'/10').' (requires at least '.round($p['min_curve_density_score'],2).'/10).';}
                elseif($key==='curvature') {$out[]='Curvature quality: '.($value===null?'missing':round(Scorer::qualityScore((float)$value),2).'/10').' (requires at least '.round($p['min_curvature_quality_score'],2).'/10).';}
                elseif($key==='sustained_curve_m') {$out[]='Sustained curve length: '.($value===null?'missing':round((float)$value/0.3048,1).' ft').' (requires at least '.round($p['min_sustained_curve_ft'],1).' ft).';}
                else {$out[]=$key.': '.($value===null?'missing':round((float)$value,3)).' (requires at least '.$minimum.').';}
            }
        }
        return $out;
    }
    public function run(array $input): array
    {
        $p=Validation::search($input);$name=$input['road']??null;
        if (!is_string($name) || strlen(trim($name))<2 || strlen($name)>100) {throw new \InvalidArgumentException('Enter a road name or route number (2–100 characters).');}
        $name=trim($name);$pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$name).'%';
        $api=new Api($this->db);$zip=$api->zip($p['zip']);
        $prefix="WITH center AS (SELECT ST_SetSRID(ST_MakePoint(?,?),4326)::geography AS geom,?::double precision AS meters),
            area AS (SELECT geom,meters,ST_Envelope(ST_Buffer(geom,meters)::geometry) AS bounds FROM center) ";
        $base=[$zip['lon'],$zip['lat'],$p['radius_miles']*1609.344];
        $q=$this->db->prepare($prefix."SELECT DISTINCT ON(s.osm_id) s.osm_id,s.name,s.ref,s.highway,s.surface
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id CROSS JOIN area a
            WHERE s.geom && a.bounds AND ST_DWithin(s.geom::geography,a.geom,a.meters)
            AND (s.name ILIKE ? OR s.ref ILIKE ?) ORDER BY s.osm_id,r.imported_at DESC LIMIT 101");
        $q->execute([...$base,$pattern,$pattern]);$ways=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$this->db->prepare($prefix."SELECT c.id,c.name,c.ref,c.highway,c.length_m,c.mandatory_stops,c.uncertain_controls,c.surface_known_paved,c.surface_status,c.metrics,
            (SELECT jsonb_agg(DISTINCT s.surface) FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=c.id) AS surface_values
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id CROSS JOIN area a
            WHERE ST_DWithin(c.geom,a.geom,a.meters)
            AND (c.name ILIKE ? OR c.ref ILIKE ? OR EXISTS(
                SELECT 1 FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id
                WHERE m.candidate_id=c.id AND (s.name ILIKE ? OR s.ref ILIKE ?)))
            ORDER BY c.length_m DESC,c.id LIMIT 101");
        $q->execute([...$base,$pattern,$pattern,$pattern,$pattern]);$roads=$q->fetchAll(PDO::FETCH_ASSOC);
        $search=$api->search($p);$visible=array_column($search['results'],'id');
        $truncated=count($ways)>100 || count($roads)>100;$ways=array_slice($ways,0,100);$roads=array_slice($roads,0,100);
        foreach ($roads as &$road) {
            $road['surface_values']=json_decode($road['surface_values']?:'[]',true,512,JSON_THROW_ON_ERROR);
            $road['metrics']=json_decode($road['metrics'],true,512,JSON_THROW_ON_ERROR);
            $road['reasons']=self::reasons($road,$p);$road['returned']=in_array((int)$road['id'],$visible,true);
            if (!$road['reasons'] && !$road['returned']) {$road['reasons'][]='Eligible, but may be hidden by identical-geometry deduplication or the 100-result limit.';}
        }
        return ['road'=>$name,'parameters'=>$p,'ways'=>$ways,'candidates'=>$roads,'truncated'=>$truncated,
            'full_radius_covered'=>$search['full_radius_covered'],
            'note'=>'Checks active local data only. Source ways are not continuous candidates. No filters were relaxed and no imports were started.'];
    }
}
