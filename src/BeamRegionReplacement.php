<?php
declare(strict_types=1);
namespace SweepLine;

/** Stage bounded child imports, then activate complete replacement coverage in one transaction. */
final class BeamRegionReplacement
{
    public static function run(\PDO $db,array $row,array $tile,array $chunks,callable $progress): array
    {
        $jobs=[];$locks=[];$tasks=[];
        try{
            foreach($chunks as $chunk){
                $budget=(new StorageSettings($db))->read()['database_budget_mb']*1048576;
                if((int)$db->query('SELECT pg_database_size(current_database())')->fetchColumn()>$budget)throw new \RuntimeException('Regional replacement reached the configured database budget; previous coverage remains active.');
                $leaf=$tile;$steps=$chunk['tile_path']===''?[]:explode('-',$chunk['tile_path']);
                foreach($steps as $index){
                    $tasks[$leaf['key']]=['tile'=>$leaf,'status'=>'split','job_id'=>null];
                    $leaf=CoveragePlanner::children($leaf)[(int)$index];
                    $leaf['halo']=min($tile['halo']??.04,CoveragePlanner::halo(['bounds'=>$leaf['bounds']]));
                }
                $coverage=$db->prepare('SELECT ST_AsGeoJSON(g),ST_Area(g) FROM (SELECT ST_Intersection(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),ST_MakeEnvelope(?,?,?,?,4326)) g) p');
                $coverage->execute([$row['coverage'],...$chunk['bounds']]);$geometry=$coverage->fetch(\PDO::FETCH_NUM);
                if((float)$geometry[1]<=0)continue;
                if($steps){
                    $lock=$db->prepare('SELECT pg_try_advisory_lock(73019423,hashtext(?))');$lock->execute(['sweepline-region:'.$leaf['key']]);
                    if(!$lock->fetchColumn())throw new \RuntimeException('A replacement child region is busy. Retry after its current operation completes.');
                    $locks[]=$leaf['key'];
                    $db->prepare('INSERT INTO regions(slug,name) VALUES (?,?) ON CONFLICT(slug) DO NOTHING')->execute([$leaf['key'],'Refreshed roads: '.$leaf['key']]);
                    $q=$db->prepare('SELECT id,active_job_id FROM regions WHERE slug=?');$q->execute([$leaf['key']]);$region=$q->fetch(\PDO::FETCH_ASSOC);
                }else $region=['id'=>$row['region_id'],'active_job_id'=>$row['job_id']];
                $progress('Matching road network: staging refreshed area '.$leaf['key']);
                $insert=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage,allow_empty,started_at,ramp_context_version)
                    VALUES (?,'running',?,ST_SetSRID(ST_GeomFromGeoJSON(?),4326),true,now(),?) RETURNING *");
                $insert->execute([$region['id'],$chunk['path'],$geometry[0],BeamRegionRefresh::VERSION]);$job=$insert->fetch(\PDO::FETCH_ASSOC);
                $jobs[]=['region_id'=>(int)$region['id'],'job_id'=>(int)$job['id'],'previous_job_id'=>$region['active_job_id']===null?null:(int)$region['active_job_id'],'key'=>$leaf['key'],'source_sha256'=>hash_file('sha256',$chunk['path'])];
                (new Importer($db,static function(bool $lock=false)use($progress){$progress(null);}))->run($job,false);
                $tasks[$leaf['key']]=['tile'=>$leaf,'status'=>'complete','job_id'=>$job['id']];
            }
            if(!$jobs)throw new \RuntimeException('Refreshed source pieces do not cover the previous region.');
            $progress('Matching road network: validating complete replacement coverage');
            $db->beginTransaction();
            $q=$db->prepare('SELECT active_job_id FROM regions WHERE id=? FOR UPDATE');$q->execute([$row['region_id']]);
            if((int)$q->fetchColumn()!==(int)$row['job_id'])throw new \RuntimeException('Original region changed during refresh; replacement was not activated.');
            foreach($jobs as $job){
                $q=$db->prepare('SELECT active_job_id FROM regions WHERE id=? FOR UPDATE');$q->execute([$job['region_id']]);$active=$q->fetchColumn();
                if(($active===null?null:(int)$active)!==$job['previous_job_id'])throw new \RuntimeException('A child region changed during refresh; replacement was not activated.');
            }
            $q=$db->prepare("SELECT ST_Covers(ST_UnaryUnion(ST_Collect(coverage)),ST_SetSRID(ST_GeomFromGeoJSON(?),4326))
                FROM import_jobs WHERE id IN (SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb)) AND status='complete'");
            $q->execute([$row['coverage'],json_encode(array_column($jobs,'job_id'),JSON_THROW_ON_ERROR)]);
            if(!$q->fetchColumn())throw new \RuntimeException('Refreshed child regions leave a coverage gap; previous data remains active.');
            $queue=new DataQueue($db);
            foreach($tasks as $task){
                $entry=$queue->ensure($task['tile']['key'],'roads',$task['tile']);
                if($entry['kind']!=='roads'||$entry['status']==='running'||$entry['cancel_requested'])throw new \RuntimeException('Region preparation changed during refresh; replacement was not activated.');
                $db->prepare("UPDATE data_tasks SET status=?,payload=?::jsonb,import_job_id=?,manual_priority=false,error=null,progress=?,updated_at=now() WHERE id=?")
                    ->execute([$task['status'],json_encode($task['tile'],JSON_THROW_ON_ERROR),$task['job_id'],$task['status']==='split'?'Replaced by refreshed smaller areas':'Local data ready',$entry['id']]);
            }
            $progress(null);
            foreach($jobs as $job){
                $db->prepare('UPDATE regions SET active_job_id=?,coverage=(SELECT coverage FROM import_jobs WHERE id=?),imported_at=now() WHERE id=?')->execute([$job['job_id'],$job['job_id'],$job['region_id']]);
                $db->prepare("UPDATE import_jobs SET progress='Active' WHERE id=?")->execute([$job['job_id']]);
            }
            if(!in_array((int)$row['region_id'],array_column($jobs,'region_id'),true))$db->prepare('UPDATE regions SET active_job_id=null WHERE id=?')->execute([$row['region_id']]);
            $db->commit();return $jobs;
        }catch(\Throwable $e){
            if($db->inTransaction())$db->rollBack();
            foreach($jobs as $job)$db->prepare("UPDATE import_jobs SET status='failed',error='Replacement coverage not activated; previous data retained',finished_at=now() WHERE id=? AND NOT EXISTS(SELECT 1 FROM regions WHERE active_job_id=?)")->execute([$job['job_id'],$job['job_id']]);
            throw $e;
        }finally{foreach($locks as $key)$db->prepare('SELECT pg_advisory_unlock(73019423,hashtext(?))')->execute(['sweepline-region:'.$key]);}
    }
}
