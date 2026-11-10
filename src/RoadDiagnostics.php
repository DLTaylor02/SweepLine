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
    /** Explain the actual selection pipeline separately from raw candidate eligibility. */
    public static function selectionReasons(array $road,array $trace): array {
        if($road['returned'])return ['Included in the returned road results. Source metrics below describe the original candidate.'];
        $id=(int)$road['id'];$group=$trace['groups'][$id]??null;
        if($group){
            $label='After combining overlapping candidates into “'.$group['name'].'” (#'.$group['id'].'): ';
            if($group['reasons'])return array_map(static fn($reason)=>$label.$reason,$group['reasons']);
            $rank=array_search((int)$group['id'],$trace['ranked']??[],true);
            if($rank!==false&&$rank>=100)return [$label.'Ranked '.($rank+1).'; only the top 100 qualifying roads are displayed. Try a smaller radius.'];
            return ['The candidate was processed, but its result could not be accounted for. Retry diagnosis; imported data may have changed during this check.'];
        }
        if(($road['profile_score']??null)===null)return ['No cached score exists for this driving profile, so discovery could not consider this candidate. A SuperAdmin should review region analysis or refresh its data.'];
        if(!in_array($id,$trace['planned']??[],true))return ['Not analyzed: discovery considers only the top 600 nearby source candidates by cached profile score, length and ID, before combining roads and applying filters. Try a smaller radius.'];
        return ['The candidate entered analysis, but no continuous geometry group was recorded. Its geometry or active region may have changed; retry diagnosis or ask a SuperAdmin to inspect the region.'];
    }
    public static function roadName(array $input): string {
        $name=$input['road']??null;
        if(!is_string($name)||strlen(trim($name))<2||strlen($name)>100)throw new \InvalidArgumentException('Enter a road name or route number (2–100 characters).');
        return trim($name);
    }
    public function run(array $input,?callable $progress=null): array
    {
        $progress??=static function(string $stage):void{};
        $p=Validation::search($input);$name=self::roadName($input);
        $name=trim($name);$pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$name).'%';
        $progress('Looking up the diagnostic ZIP location');
        $api=new Api($this->db);$zip=$api->zip($p['zip']);
        $prefix="WITH center AS (SELECT ST_SetSRID(ST_MakePoint(?,?),4326)::geography AS geom,?::double precision AS meters),
            area AS (SELECT geom,meters,ST_Envelope(ST_Buffer(geom,meters)::geometry) AS bounds FROM center) ";
        $base=[$zip['lon'],$zip['lat'],$p['radius_miles']*1609.344];
        $progress('Checking imported source road tags');
        $q=$this->db->prepare($prefix."SELECT DISTINCT ON(s.osm_id) s.osm_id,s.name,s.ref,s.highway,s.surface
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id CROSS JOIN area a
            WHERE s.geom && a.bounds AND ST_DWithin(s.geom::geography,a.geom,a.meters)
            AND (s.name ILIKE ? OR s.ref ILIKE ?) ORDER BY s.osm_id,r.imported_at DESC LIMIT 101");
        $q->execute([...$base,$pattern,$pattern]);$ways=$q->fetchAll(PDO::FETCH_ASSOC);
        $progress('Checking continuous road candidates');
        $q=$this->db->prepare($prefix."SELECT c.id,c.name,c.ref,c.highway,c.length_m,c.mandatory_stops,c.uncertain_controls,c.surface_known_paved,c.surface_status,c.metrics,c.alternate_names,
            (SELECT score FROM road_scores WHERE candidate_id=c.id AND profile=?) AS profile_score,
            (SELECT jsonb_agg(DISTINCT s.surface) FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=c.id) AS surface_values
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id CROSS JOIN area a
            WHERE ST_DWithin(c.geom,a.geom,a.meters)
            AND (c.name ILIKE ? OR c.ref ILIKE ? OR c.alternate_names::text ILIKE ? OR EXISTS(
                SELECT 1 FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id
                WHERE m.candidate_id=c.id AND (s.name ILIKE ? OR s.ref ILIKE ?)))
            ORDER BY c.length_m DESC,c.id LIMIT 101");
        $q->execute([...$base,$p['profile'],$pattern,$pattern,$pattern,$pattern,$pattern]);$roads=$q->fetchAll(PDO::FETCH_ASSOC);
        $trace=[];
        $search=$api->search($p,$progress,static function(string $event,array $data)use(&$trace):void{
            if($event==='group'){foreach($data['members'] as $id)$trace['groups'][$id]=$data;}
            else $trace[$event]=$data;
        });$visible=[];$combined=[];
        foreach($search['results'] as $result)foreach($result['member_ids']??[$result['id']] as $member){$visible[]=(int)$member;$combined[(int)$member]=$result['id'];}
        $truncated=count($ways)>100 || count($roads)>100;$ways=array_slice($ways,0,100);$roads=array_slice($roads,0,100);
        foreach ($roads as &$road) {
            $road['surface_values']=json_decode($road['surface_values']?:'[]',true,512,JSON_THROW_ON_ERROR);
            $road['alternate_names']=json_decode($road['alternate_names'],true,512,JSON_THROW_ON_ERROR);
            $road['metrics']=json_decode($road['metrics'],true,512,JSON_THROW_ON_ERROR);
            $road['reasons']=self::reasons($road,$p);$road['returned']=in_array((int)$road['id'],$visible,true);
            $road['combined_into']=$combined[(int)$road['id']]??null;
            if($road['returned'])$road['reasons'][]='Included in a consolidated result; metrics here describe the original source candidate.';
            $road['source_reasons']=$road['reasons'];
            $road['reasons']=self::selectionReasons($road,$trace);
            if($road['returned'])$road['selection_status']='returned';
            elseif(isset($trace['groups'][(int)$road['id']]))$road['selection_status']=$trace['groups'][(int)$road['id']]['reasons']?'combined_filter':'ranking_limit';
            else $road['selection_status']=$road['profile_score']===null?'missing_score':'candidate_limit';
        }
        return ['road'=>$name,'parameters'=>$p,'ways'=>$ways,'candidates'=>$roads,'truncated'=>$truncated,
            'full_radius_covered'=>$search['full_radius_covered'],
            'note'=>'Checks active local data only. Source ways are not continuous candidates. No filters were relaxed and no imports were started.'];
    }
}
