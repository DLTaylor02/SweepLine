<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
use XMLReader;

final class Importer
{
    private const CLASSES = ['motorway','trunk','primary','secondary','tertiary','unclassified','residential','living_street','service'];
    private bool $allowEmpty=false;
    public function __construct(private PDO $db) {}
    private function progress(int $id,string $message): void
    {
        $this->db->prepare('UPDATE import_jobs SET progress=? WHERE id=?')->execute([$message,$id]);
        $this->db->prepare("INSERT INTO app_settings(key,value) VALUES ('worker_heartbeat',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([date('c')]);
        echo date('c')." job $id: $message\n";
    }
    public function run(array $job): void
    {
        $id=(int)$job['id']; $this->allowEmpty=(bool)($job['allow_empty']??false);
        try {
            $this->db->exec("SET statement_timeout = '0'");
            $this->db->prepare('UPDATE import_jobs SET source_sha256=? WHERE id=?')->execute([hash_file('sha256',$job['source_path']),$id]);
            $this->progress($id,'Reading OSM nodes');
            $this->nodes($id,$job['source_path']);
            $this->progress($id,'Normalizing public motor roads');
            $this->ways($id,$job['source_path']);
            $this->progress($id,'Building connectivity and continuous candidates');
            $this->analyze($id);
            $this->db->prepare("UPDATE road_candidates SET geometry_key=md5(encode(ST_AsEWKB(ST_Normalize(geom::geometry)),'hex')) WHERE job_id=?")->execute([$id]);
            $count=$this->db->prepare('SELECT count(*) FROM road_candidates WHERE job_id=?'); $count->execute([$id]);
            if ((int)$count->fetchColumn()<1 && !$this->allowEmpty) { throw new \RuntimeException('No usable candidates: previous dataset remains active'); }
            $this->db->beginTransaction();
            $this->db->prepare('UPDATE regions SET active_job_id=?, coverage=(SELECT coverage FROM import_jobs WHERE id=?), imported_at=now() WHERE id=?')->execute([$id,$id,$job['region_id']]);
            $this->db->prepare("UPDATE import_jobs SET status='complete',progress='Active',finished_at=now() WHERE id=?")->execute([$id]);
            $this->db->commit();
            $this->progress($id,'Import validated and activated');
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $this->db->prepare("UPDATE import_jobs SET status='failed',error=?,finished_at=now() WHERE id=?")->execute([substr($e->getMessage(),0,2000),$id]);
            throw $e;
        }
    }
    private function read(string $path,string $element): \Generator
    {
        $xml=new XMLReader();
        if (!$xml->open($path,null,LIBXML_NONET | LIBXML_COMPACT)) { throw new \RuntimeException('Cannot open OSM XML'); }
        $xml->setParserProperty(XMLReader::SUBST_ENTITIES,false);
        libxml_use_internal_errors(true); libxml_clear_errors();
        try {
            while ($xml->read()) {
                if ($xml->nodeType===XMLReader::DOC_TYPE) { throw new \RuntimeException('DTD is forbidden'); }
                if ($xml->nodeType===XMLReader::ELEMENT && $xml->depth===0 && $xml->name!=='osm') { throw new \RuntimeException('Expected an OSM XML root'); }
                if ($xml->nodeType===XMLReader::ELEMENT && $xml->name==='remark') { throw new \RuntimeException('OSM extract contains an error remark; incomplete data refused'); }
                if ($xml->nodeType!==XMLReader::ELEMENT || $xml->name!==$element) { continue; }
                $item=simplexml_load_string($xml->readOuterXml(),\SimpleXMLElement::class,LIBXML_NONET);
                if ($item===false) { throw new \RuntimeException('Invalid OSM element'); }
                yield $item;
            }
            if (libxml_get_errors()) { throw new \RuntimeException('Malformed or truncated OSM XML'); }
        } finally { $xml->close(); libxml_clear_errors(); }
    }
    private function tags(\SimpleXMLElement $item): array
    {
        $tags=[]; foreach ($item->tag as $t) { $tags[(string)$t['k']]=(string)$t['v']; } return $tags;
    }
    private function nodes(int $id,string $path): void
    {
        $insert=$this->db->prepare('INSERT INTO osm_nodes(job_id,osm_id,lon,lat,tags) VALUES (?,?,?,?,?::jsonb)');
        $this->db->beginTransaction(); $count=0;
        foreach ($this->read($path,'node') as $node) {
            if (++$count>1000000) { throw new \RuntimeException('Node limit exceeded; clip the extract to a smaller region (1M nodes max)'); }
            $lon=(float)$node['lon']; $lat=(float)$node['lat'];
            if (!is_finite($lon) || !is_finite($lat) || abs($lon)>180 || abs($lat)>90) { throw new \RuntimeException('Invalid node coordinates'); }
            $insert->execute([$id,(int)$node['id'],$lon,$lat,json_encode((object)$this->tags($node),JSON_THROW_ON_ERROR)]);
            if ($count%10000===0) { $this->db->commit(); $this->progress($id,"Loaded $count nodes"); $this->db->beginTransaction(); }
        }
        $this->db->commit();
        if (!$count && !$this->allowEmpty) { throw new \RuntimeException('OSM contains no nodes'); }
    }
    public static function speed(?string $value): ?float
    {
        if ($value===null || !preg_match('/^(\d+(?:\.\d+)?)\s*(mph|km\/h)?$/i',trim($value),$m)) { return null; }
        $speed=(float)$m[1] * (strtolower($m[2]??'')==='mph' ? 1 : 0.621371);
        return $speed>0 && $speed<=100 ? $speed : null;
    }
    private function ways(int $id,string $path): void
    {
        $insert=$this->db->prepare('INSERT INTO road_segments(job_id,osm_id,name,ref,highway,direction,surface,speed_mph,node_ids,tags,geom) VALUES (?,?,?,?,?,?,?,?,?::jsonb,?::jsonb,ST_SetSRID(ST_GeomFromGeoJSON(?),4326)) RETURNING id');
        $connect=$this->db->prepare('INSERT INTO road_connectivity(job_id,node_id,segment_id,point_index) VALUES (?,?,?,?) ON CONFLICT DO NOTHING');
        $count=0; $missing=0; $this->db->beginTransaction();
        foreach ($this->read($path,'way') as $way) {
            $t=$this->tags($way);
            if (!in_array($t['highway']??'',self::CLASSES,true)) { continue; }
            foreach (['access','vehicle','motor_vehicle','motorcar'] as $key) {
                if (isset($t[$key]) && !in_array($t[$key], ['yes','designated','permissive'],true)) { continue 2; }
            }
            if (($t['area']??'')==='yes' || ($t['construction']??'')!=='' || isset($t['access:conditional']) || isset($t['motor_vehicle:conditional']) || isset($t['motorcar:conditional'])) { continue; }
            $nodes=[]; foreach ($way->nd as $nd) { $nodes[]=(int)$nd['ref']; }
            if (count($nodes)<2) { continue; }
            if (count($nodes)>10000) { throw new \RuntimeException('Way node limit exceeded'); }
            $q=$this->db->prepare('SELECT osm_id,lon,lat FROM osm_nodes WHERE job_id=? AND osm_id IN ('.implode(',',array_fill(0,count($nodes),'?')).')');
            $q->execute(array_merge([$id],$nodes)); $coords=[];
            while ($row=$q->fetch(PDO::FETCH_ASSOC)) { $coords[$row['osm_id']]=[(float)$row['lon'],(float)$row['lat']]; }
            $points=[]; foreach ($nodes as $node) { if (!isset($coords[$node])) { $missing++; continue 2; } $points[]=$coords[$node]; }
            if (Geometry::length($points)<15) { continue; }
            $direction=match($t['oneway']??'') {'yes','1','true'=>1,'-1'=>-1,default=>(($t['junction']??'')==='roundabout' || $t['highway']==='motorway') ? 1 : 0};
            $insert->execute([$id,(int)$way['id'],$t['name']??null,$t['ref']??null,$t['highway'],$direction,$t['surface']??null,self::speed($t['maxspeed']??null),json_encode($nodes),json_encode((object)$t),json_encode(['type'=>'LineString','coordinates'=>$points])]);
            $segment=(int)$insert->fetchColumn();
            foreach ($nodes as $i=>$node) { $connect->execute([$id,$node,$segment,$i]); }
            if (++$count>30000) { throw new \RuntimeException('Road limit exceeded; use a smaller extract (30k ways max)'); }
            if ($count%1000===0) { $this->db->commit(); $this->progress($id,"Normalized $count ways"); $this->db->beginTransaction(); }
        }
        $this->db->commit();
        if ($missing>0) { throw new \RuntimeException("$missing ways have missing nodes; use osmium complete_ways extraction"); }
    }
    private function analyze(int $id): void
    {
        $profiles=Scorer::profiles();
        foreach ($profiles as $slug=>$definition) { $this->db->prepare('INSERT INTO driving_profiles(slug,definition) VALUES (?,?::jsonb) ON CONFLICT(slug) DO UPDATE SET definition=excluded.definition')->execute([$slug,json_encode($definition)]); }
        $q=$this->db->prepare('SELECT *,ST_AsGeoJSON(geom) AS geometry FROM road_segments WHERE job_id=? ORDER BY id'); $q->execute([$id]); $roads=[];
        while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
            $row['nodes']=json_decode($row['node_ids'],true); $row['points']=json_decode($row['geometry'],true)['coordinates'];
            $row['direction']=(int)$row['direction']; unset($row['geometry'],$row['geom'],$row['node_ids'],$row['tags']); $roads[]=$row;
        }
        $q=$this->db->prepare("SELECT osm_id,tags FROM osm_nodes WHERE job_id=? AND tags <> '{}'::jsonb"); $q->execute([$id]); $tags=[];
        while ($row=$q->fetch(PDO::FETCH_ASSOC)) { $tags[$row['osm_id']]=json_decode($row['tags'],true); }
        $insert=$this->db->prepare('INSERT INTO road_candidates(job_id,name,ref,highway,surface_known_paved,length_m,mandatory_stops,uncertain_controls,speed_mph,metrics,geom) VALUES (?,?,?,?,?::boolean,?,?,?,?,?::jsonb,ST_SetSRID(ST_GeomFromGeoJSON(?),4326)::geography) RETURNING id');
        $score=$this->db->prepare('INSERT INTO road_scores(candidate_id,profile,score,confidence,dimensions) VALUES (?,?,?,?,?::jsonb)');
        $member=$this->db->prepare('INSERT INTO candidate_segments(candidate_id,segment_id,position,reversed) VALUES (?,?,?,?::boolean)');
        $count=0;
        foreach (RoadGraph::candidates($roads) as $candidate) {
            foreach ($candidate['nodes'] as $node) {
                $nodeTags=$tags[$node]??[];
                if (in_array($nodeTags['access']??'', ['no','private'],true) || in_array($nodeTags['motor_vehicle']??'', ['no','private'],true) || in_array($nodeTags['barrier']??'', ['bollard','block','cycle_barrier'],true)) { continue 2; }
            }
            $metrics=Geometry::metrics($candidate['points']);
            if ($metrics['length_m']<400) { continue; }
            $first=$candidate['parts'][0]; $paved=true; $knownSpeeds=[]; $speedKnown=true;
            foreach ($candidate['parts'] as $part) {
                $paved=$paved && in_array($part['surface'],['asphalt','paved','concrete','concrete:plates'],true);
                if ($part['speed_mph']===null) { $speedKnown=false; } else { $knownSpeeds[]=(float)$part['speed_mph']; }
            }
            $metrics+=Controls::analyze($candidate['nodes'],$tags,$candidate['uses']);
            $metrics+=['paved'=>$paved,'highway'=>$first['highway'],'speed_mph'=>$speedKnown && $knownSpeeds ? min($knownSpeeds) : null,
                'elevation_source'=>null,'stop_inventory_complete'=>false,'endpoint_count'=>2];
            $insert->execute([$id,$first['name']?:($first['ref']?:'Unnamed road'),$first['ref'],$first['highway'],$paved?'true':'false',$metrics['length_m'],$metrics['mandatory_stops'],$metrics['uncertain_controls'],$metrics['speed_mph'],json_encode($metrics),json_encode(['type'=>'LineString','coordinates'=>$candidate['points']])]);
            $cid=(int)$insert->fetchColumn();
            foreach ($candidate['parts'] as $i=>$part) { $member->execute([$cid,$part['id'],$i,$part['reversed']?'true':'false']); }
            foreach ($profiles as $slug=>$_) { $s=Scorer::score($metrics,$slug); $score->execute([$cid,$slug,$s['score'],$s['confidence'],json_encode($s['dimensions'])]); }
            if (++$count%500===0) { $this->progress($id,"Scored $count candidates"); }
        }
    }
}
