<?php
declare(strict_types=1);
namespace SweepLine;

/** Refresh obsolete local road topology from a complete extract; never synthesize missing connections. */
final class BeamRegionRefresh
{
    public const VERSION=3;
    public static function validate(string $path): void
    {
        $old=libxml_use_internal_errors(true);libxml_clear_errors();$xml=new \XMLReader();$opened=false;
        try{
            if(!$xml->open($path,null,LIBXML_NONET|LIBXML_COMPACT))throw new \RuntimeException('Region refresh did not return readable OSM XML.');
            $opened=true;$root=false;$ways=0;
            while($xml->read()){
                if($xml->nodeType!==\XMLReader::ELEMENT)continue;
                if($xml->depth===0){if($xml->name!=='osm')throw new \RuntimeException('Region refresh returned a non-OSM document.');$root=true;}
                if($xml->name==='remark'||$xml->name==='error')throw new \RuntimeException('Region provider returned an incomplete extract; current region data was retained.');
                if($xml->name==='way'&&++$ways>30000)throw new \RuntimeException('Region refresh exceeds the 30,000-way limit; refresh smaller regional tiles in Manage.');
            }
            if(!$root||libxml_get_errors())throw new \RuntimeException('Region refresh returned incomplete XML; current region data was retained.');
        }finally{if($opened)$xml->close();libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    public static function ensure(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null,?callable $sleep=null): array
    {
        $lines=[$plan['points']];foreach($plan['carriageways']??[] as $t)$lines[]=$t['points'];
        $q=$db->prepare("SELECT r.id region_id,r.slug,j.id job_id,(SELECT payload FROM data_tasks WHERE task_key=r.slug AND kind='roads' LIMIT 1) tile,ST_AsGeoJSON(j.coverage) coverage,ST_AsGeoJSON(ST_ExteriorRing(j.coverage),7) ring,ST_Area(j.coverage::geography) area
            FROM regions r JOIN import_jobs j ON j.id=r.active_job_id
            WHERE j.ramp_context_version<? AND j.status='complete'
            AND j.coverage && ST_Transform(ST_Expand(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),3500),4326)
            ORDER BY j.id LIMIT 65");
        $q->execute([self::VERSION,json_encode(['type'=>'MultiLineString','coordinates'=>$lines],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        if(count($rows)>64)throw new \RuntimeException('More than 64 obsolete regions need a source refresh. Refresh a smaller area in Manage before exporting.');
        $report=['legacy_regions'=>count($rows),'upgraded'=>0,'refreshed_regions'=>[],'added_ramp_ways'=>0,'failures'=>[],'method'=>'Fresh complete source tiles and jointly activated regional imports'];
        $download??=[Download::class,'file'];
        foreach($rows as $row){
            $progress('Matching road network: refreshing outdated region '.$row['slug']);
            $lock=$db->prepare('SELECT pg_try_advisory_lock(73019423,hashtext(?))');$lock->execute(['sweepline-region:'.$row['slug']]);
            if(!$lock->fetchColumn())throw new \RuntimeException('A required region is currently being modified. Retry after its refresh or discard completes.');
            $path=$dir.'/region-refresh-'.$row['job_id'].'.osm';$chunks=[];
            try{
                $state=$db->prepare('SELECT active_job_id FROM regions WHERE id=?');$state->execute([$row['region_id']]);
                if((int)$state->fetchColumn()!==(int)$row['job_id']){
                    $current=$db->prepare('SELECT j.ramp_context_version FROM regions r JOIN import_jobs j ON j.id=r.active_job_id WHERE r.id=?');$current->execute([$row['region_id']]);
                    if((int)$current->fetchColumn()>=self::VERSION)continue;
                    throw new \RuntimeException('Region changed before refresh; retry the export with current coverage.');
                }
                if((float)$row['area']>1e10)throw new \RuntimeException('Region is too large for a bounded automatic refresh. Refresh smaller regional tiles in Manage.');
                $free=disk_free_space($dir);if($free!==false&&$free<536870912)throw new \RuntimeException('Region refresh needs at least 512 MB of free storage.');
                $budget=(new StorageSettings($db))->read()['database_budget_mb']*1048576;
                if((int)$db->query('SELECT pg_database_size(current_database())')->fetchColumn()>$budget)throw new \RuntimeException('Region refresh paused at the application database storage budget; review Manage.');
                $ring=json_decode($row['ring'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
                $tile=!empty($row['tile'])?json_decode($row['tile'],true,512,JSON_THROW_ON_ERROR):[];
                $bounds=[min(array_column($ring,0)),min(array_column($ring,1)),max(array_column($ring,0)),max(array_column($ring,1))];
                $halo=CoveragePlanner::halo($tile+['bounds'=>$bounds]);
                $acquisition=BeamRegionDownload::fetch(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$path,$bounds,$download,
                    static fn($m)=>$progress('Matching road network: refreshing '.$row['slug'].' - '.$m),$sleep,true,$halo);
                $chunks=$acquisition['chunks'];unset($acquisition['chunks']);
                $rootTile=array_replace($tile,['key'=>$row['slug'],'bounds'=>$bounds,'halo'=>$halo]);
                $replacement=BeamRegionReplacement::run($db,$row,$rootTile,$chunks,$progress);
                $report['upgraded']++;$report['refreshed_regions'][]=['region_id'=>(int)$row['region_id'],'previous_job_id'=>(int)$row['job_id'],
                    'job_id'=>count($replacement)===1?$replacement[0]['job_id']:null,'replacement_imports'=>$replacement,'acquisition'=>$acquisition];
            }catch(\Throwable $e){
                if($db->inTransaction())$db->rollBack();
                if($e instanceof JobCancelled)throw $e;
                throw new \RuntimeException('Required region refresh failed for '.$row['slug'].': '.$e->getMessage().' Previous regional data remains active.',0,$e);
            }finally{
                foreach($chunks as $chunk)if(is_file($chunk['path']))unlink($chunk['path']);
                if(is_file($path))unlink($path);
                $db->prepare('SELECT pg_advisory_unlock(73019423,hashtext(?))')->execute(['sweepline-region:'.$row['slug']]);
            }
        }
        return $report;
    }
}
