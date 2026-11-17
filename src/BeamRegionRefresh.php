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
    public static function ensure(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array
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
        $report=['legacy_regions'=>count($rows),'upgraded'=>0,'refreshed_regions'=>[],'added_ramp_ways'=>0,'failures'=>[],'method'=>'Fresh complete regional OSM extract and normal atomic importer'];
        $download??=[Download::class,'file'];
        foreach($rows as $row){
            $progress('Matching road network: refreshing outdated region '.$row['slug']);
            $lock=$db->prepare('SELECT pg_try_advisory_lock(73019423,hashtext(?))');$lock->execute(['sweepline-region:'.$row['slug']]);
            if(!$lock->fetchColumn())throw new \RuntimeException('A required region is currently being modified. Retry after its refresh or discard completes.');
            $path=$dir.'/region-refresh-'.$row['job_id'].'.osm';$job=null;
            try{
                $state=$db->prepare('SELECT active_job_id FROM regions WHERE id=?');$state->execute([$row['region_id']]);
                if((int)$state->fetchColumn()!==(int)$row['job_id'])throw new \RuntimeException('Region changed before refresh; retry the export with current coverage.');
                if((float)$row['area']>1e10)throw new \RuntimeException('Region is too large for a bounded automatic refresh. Refresh smaller regional tiles in Manage.');
                $free=disk_free_space($dir);if($free!==false&&$free<536870912)throw new \RuntimeException('Region refresh needs at least 512 MB of free storage.');
                $budget=(new StorageSettings($db))->read()['database_budget_mb']*1048576;
                if((int)$db->query('SELECT pg_database_size(current_database())')->fetchColumn()>$budget)throw new \RuntimeException('Region refresh paused at the application database storage budget; review Manage.');
                $ring=json_decode($row['ring'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
                $tile=!empty($row['tile'])?json_decode($row['tile'],true,512,JSON_THROW_ON_ERROR):[];
                $bounds=$tile['bounds']??[min(array_column($ring,0)),min(array_column($ring,1)),max(array_column($ring,0)),max(array_column($ring,1))];
                $halo=CoveragePlanner::halo($tile+['bounds'=>$bounds]);
                [$w,$s,$e,$n]=$bounds;$w=max(-180,$w-$halo);$e=min(180,$e+$halo);$s=max(-90,$s-$halo);$n=min(90,$n+$halo);
                $poly=$s.' '.$w.' '.$s.' '.$e.' '.$n.' '.$e.' '.$n.' '.$w.' '.$s.' '.$w;
                if(strlen($poly)>100000)throw new \RuntimeException('Region refresh coverage is too complex.');
                $query='[out:xml][timeout:180][maxsize:134217728];way[highway~"^(motorway|motorway_link|trunk|trunk_link|primary|primary_link|secondary|secondary_link|tertiary|tertiary_link|unclassified|residential|living_street|service)$"](poly:"'.$poly.'");(._;>;);out body;';
                for($attempt=1;;$attempt++){
                    try{
                        $download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$path,104857600,http_build_query(['data'=>$query]),static fn($m)=>$progress('Matching road network: refreshing '.$row['slug'].' · '.$m),240);
                        break;
                    }catch(DownloadFailure $e){if(!$e->retryable||$attempt>=3)throw $e;$progress('Matching road network: retrying region refresh ('.($attempt+1).'/3)');usleep(250000*$attempt);}
                }
                self::validate($path);$sourceHash=hash_file('sha256',$path);$progress('Matching road network: importing refreshed region '.$row['slug']);
                $insert=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage,allow_empty,started_at,ramp_context_version)
                    VALUES (?,'running',?,ST_SetSRID(ST_GeomFromGeoJSON(?),4326),true,now(),?) RETURNING *");
                $insert->execute([$row['region_id'],$path,$row['coverage'],self::VERSION]);$job=$insert->fetch(\PDO::FETCH_ASSOC);
                $checkpoint=static function(bool $lock=false)use($db,$row,$progress):void{
                    $progress(null);
                    if($lock){
                        $q=$db->prepare('SELECT active_job_id FROM regions WHERE id=? FOR UPDATE');$q->execute([$row['region_id']]);
                        if((int)$q->fetchColumn()!==(int)$row['job_id'])throw new \RuntimeException('Region changed during refresh; replacement was not activated.');
                    }
                };
                (new Importer($db,$checkpoint))->run($job);
                $report['upgraded']++;$report['refreshed_regions'][]=['region_id'=>(int)$row['region_id'],'previous_job_id'=>(int)$row['job_id'],'job_id'=>(int)$job['id'],'source_sha256'=>$sourceHash];
            }catch(\Throwable $e){
                if($db->inTransaction())$db->rollBack();
                if($job)$db->prepare("UPDATE import_jobs SET status='failed',error='Region refresh failed; previous active dataset retained',finished_at=now() WHERE id=? AND status='running'")->execute([$job['id']]);
                if($e instanceof JobCancelled)throw $e;
                throw new \RuntimeException('Required region refresh failed for '.$row['slug'].': '.$e->getMessage().' Previous regional data remains active.',0,$e);
            }finally{
                if(is_file($path))unlink($path);
                $db->prepare('SELECT pg_advisory_unlock(73019423,hashtext(?))')->execute(['sweepline-region:'.$row['slug']]);
            }
        }
        return $report;
    }
}
