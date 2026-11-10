<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
use PDOException;

/** SuperAdmin region ownership and bounded worker cleanup. No shared export assets are removed. */
final class RegionData
{
    public function __construct(private PDO $db) {}
    public static function key(mixed $key): string {
        if(!is_string($key)||!preg_match('/^[A-Za-z0-9_.:-]{1,160}$/D',$key))throw new \InvalidArgumentException('Invalid region key.');
        return $key;
    }
    public function map(): array {
        $rows=$this->db->query("SELECT r.id,r.slug AS region_key,r.name,r.active_job_id,r.imported_at,
            ST_AsGeoJSON(COALESCE(r.coverage,(SELECT coverage FROM import_jobs j WHERE j.region_id=r.id ORDER BY j.id DESC LIMIT 1))) AS geometry,
            t.status,t.progress,t.payload FROM regions r LEFT JOIN data_tasks t ON t.task_key=r.slug AND t.kind='roads' AND NOT t.cancel_requested
            ORDER BY r.id DESC LIMIT 3001")->fetchAll(PDO::FETCH_ASSOC);
        $truncated=count($rows)>3000;$rows=array_slice($rows,0,3000);
        $tasks=$this->db->query("SELECT task_key AS region_key,task_key AS name,status,progress,payload FROM data_tasks t WHERE kind='roads' AND NOT cancel_requested
            AND NOT EXISTS(SELECT 1 FROM regions r WHERE r.slug=t.task_key) ORDER BY id DESC LIMIT 3001")->fetchAll(PDO::FETCH_ASSOC);
        $operations=$this->db->query("SELECT DISTINCT ON (region_key) region_key,status,progress FROM region_operations ORDER BY region_key,id DESC")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $features=[];
        foreach(array_merge($rows,array_slice($tasks,0,3000)) as $row){
            $payload=isset($row['payload'])?json_decode($row['payload'],true):[];
            $geometry=!empty($row['geometry'])?json_decode($row['geometry'],true):self::rectangle($payload['bounds']??null);
            if(!$geometry)continue;
            $operation=$operations[$row['region_key']]??null;
            if(($operation['status']??'')==='complete')$operation=null;
            $status=$operation?(($operation['status']==='failed')?'failed':'discarding'):($row['status']??(!empty($row['active_job_id'])?'complete':'failed'));
            $features[]=['type'=>'Feature','geometry'=>$geometry,'properties'=>['key'=>$row['region_key'],'name'=>$row['name'],
                'status'=>$status,'progress'=>$operation['progress']??$row['progress']??'Local data ready','downloaded'=>!empty($row['active_job_id'])]];
        }
        return ['type'=>'FeatureCollection','features'=>$features,'truncated'=>$truncated||count($tasks)>3000];
    }
    public static function rectangle(mixed $bounds): ?array {
        if(!is_array($bounds)||!array_is_list($bounds)||count($bounds)!==4)return null;
        foreach($bounds as $v)if(!is_numeric($v)||!is_finite((float)$v))return null;
        [$w,$s,$e,$n]=array_map('floatval',$bounds);
        if($w< -180||$e>180||$s< -90||$n>90||$w>=$e||$s>=$n)return null;
        return ['type'=>'Polygon','coordinates'=>[[[$w,$s],[$e,$s],[$e,$n],[$w,$n],[$w,$s]]]];
    }
    private function lookup(string $key): array {
        $q=$this->db->prepare("SELECT r.id,r.slug AS region_key,r.name,r.active_job_id,
            ST_XMin(Box3D(COALESCE(r.coverage,j.coverage))) w,ST_YMin(Box3D(COALESCE(r.coverage,j.coverage))) s,
            ST_XMax(Box3D(COALESCE(r.coverage,j.coverage))) e,ST_YMax(Box3D(COALESCE(r.coverage,j.coverage))) n
            FROM regions r LEFT JOIN LATERAL(SELECT coverage FROM import_jobs WHERE region_id=r.id ORDER BY id DESC LIMIT 1) j ON true WHERE r.slug=?");
        $q->execute([$key]);$region=$q->fetch(PDO::FETCH_ASSOC);
        $q=$this->db->prepare("SELECT * FROM data_tasks WHERE kind='roads' AND task_key=? AND NOT cancel_requested");$q->execute([$key]);$task=$q->fetch(PDO::FETCH_ASSOC);
        if(!$region&&!$task)throw new \OutOfBoundsException('Region not found.');
        $tile=$task?json_decode($task['payload'],true):['key'=>$key,'bounds'=>array_map('floatval',[$region['w'],$region['s'],$region['e'],$region['n']])];
        if(!self::rectangle($tile['bounds']??null))throw new \InvalidArgumentException('Region has no valid geographic boundary.');
        $tile['key']=$key;
        return ['region'=>$region?:null,'task'=>$task?:null,'tile'=>$tile];
    }
    public function detail(string $key): array {
        $key=self::key($key);$item=$this->lookup($key);$region=$item['region'];$bytes=0;$files=0;$external=0;$seenFiles=[];$estimate=0;$warning=null;
        if($region){
            $q=$this->db->prepare('SELECT source_path FROM import_jobs WHERE region_id=?');$q->execute([$region['id']]);
            foreach($q->fetchAll(PDO::FETCH_COLUMN) as $path){$safe=self::managedFile($path);if($safe!==null&&is_file($safe)&&!isset($seenFiles[$safe])){$bytes+=filesize($safe);$files++;$seenFiles[$safe]=true;}elseif($safe===null&&is_file($path))$external++;}
            $this->db->beginTransaction();
            try{
                $this->db->exec("SET LOCAL statement_timeout='1500ms'");
                foreach(['osm_nodes','road_segments','road_connectivity','road_candidates'] as $table){
                    $q=$this->db->prepare("SELECT COALESCE(sum(pg_column_size(t)),0) FROM $table t JOIN import_jobs j ON j.id=t.job_id WHERE j.region_id=?");
                    $q->execute([$region['id']]);$estimate+=(int)$q->fetchColumn();
                }
                foreach(['candidate_segments','road_scores'] as $table){
                    $q=$this->db->prepare("SELECT COALESCE(sum(pg_column_size(t)),0) FROM $table t JOIN road_candidates c ON c.id=t.candidate_id JOIN import_jobs j ON j.id=c.job_id WHERE j.region_id=?");
                    $q->execute([$region['id']]);$estimate+=(int)$q->fetchColumn();
                }
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if(!($e instanceof PDOException)||(string)$e->getCode()!=='57014')throw $e;$estimate=null;$warning='Database size estimate temporarily unavailable while the database is busy.';}
        }
        if($item['task'])foreach(['.osm','.osm.part'] as $suffix){$path=SWEEPLINE_ROOT.'/var/downloads/roads-'.$item['task']['id'].$suffix;if(is_file($path)&&!is_link($path)&&!isset($seenFiles[realpath($path)])){$bytes+=filesize($path);$files++;$seenFiles[realpath($path)]=true;}}
        $op=$this->db->prepare('SELECT status,progress FROM region_operations WHERE region_key=? ORDER BY id DESC LIMIT 1');$op->execute([$key]);$operation=$op->fetch(PDO::FETCH_ASSOC);
        if($operation&&$operation['status']!=='complete'){$item['task']=['status'=>$operation['status']==='failed'?'failed':'discarding','progress'=>$operation['progress']];}
        return ['key'=>$key,'name'=>$region['name']??$key,'downloaded'=>!empty($region['active_job_id']),'status'=>$item['task']['status']??'complete',
            'progress'=>$item['task']['progress']??'Local data ready','file_bytes'=>$bytes,'file_count'=>$files,'database_estimated_bytes'=>$estimate,
            'external_source_files_retained'=>$external,'warning'=>$warning,
            'size_note'=>'Database row-size estimate excludes shared indexes, free pages and unrelated caches. Deleted database pages are reused; physical database files may not shrink.'];
    }
    public static function managedFile(string $path): ?string {
        $root=realpath(SWEEPLINE_ROOT.'/var/downloads');$real=realpath($path);
        if(!$root||!$real||is_link($path)||dirname($real)!==$root||!preg_match('/^roads-[0-9]+\.osm(?:\.part)?$/D',basename($real)))return null;
        return $real;
    }
    private function removeManagedFile(string $path): void {
        $file=self::managedFile($path);if($file===null)return;
        $q=$this->db->prepare('SELECT source_path FROM import_jobs WHERE source_path=? OR source_path LIKE ? OR source_path=?');
        $q->execute([$file,'%/'.basename($file),basename($file)]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $source)if(self::managedFile($source)===$file)return;
        if(!unlink($file))throw new \RuntimeException('A managed download could not be removed; inspect application file permissions.');
    }
    public function action(int $user,string $key,string $action): array {
        $key=self::key($key);if(!in_array($action,['refresh','discard'],true))throw new \InvalidArgumentException('Unknown region action.');
        $this->db->beginTransaction();
        try{
            $this->db->exec("SET LOCAL lock_timeout='500ms'; SET LOCAL statement_timeout='4s'");
            $q=$this->db->prepare("SELECT pg_try_advisory_xact_lock(73019423,hashtext(?))");$q->execute(['sweepline-region:'.$key]);
            if(!$q->fetchColumn())throw new HttpException(409,'Region is busy. Try again shortly.');
            $q=$this->db->prepare("SELECT 1 FROM region_operations WHERE region_key=? AND status IN ('queued','running')");$q->execute([$key]);
            if($q->fetchColumn())throw new HttpException(409,'Region discard is already in progress.');
            $item=$this->lookup($key);$region=$item['region'];
            if($action==='refresh'){
                if(($item['task']['status']??'')==='running')throw new HttpException(409,'This region is already being downloaded or analyzed.');
                $task=(new DataQueue($this->db))->ensure($key,'roads',$item['tile']);
                if($task['kind']!=='roads')throw new HttpException(409,'This name belongs to a different application task.');
                $this->db->prepare("UPDATE region_operations SET status='complete',progress='Superseded by explicit refresh',updated_at=now() WHERE region_key=? AND status='failed'")->execute([$key]);
                $this->db->prepare("UPDATE data_tasks SET status='queued',manual_priority=true,attempts=0,error=null,import_job_id=null,next_attempt_at=now(),progress='SuperAdmin refresh requested',updated_at=now() WHERE id=?")->execute([$task['id']]);
            }else{
                $q=$this->db->prepare("UPDATE data_tasks SET cancel_requested=true,task_key='cancelled-task:'||id,
                    status=CASE WHEN status='running' THEN status ELSE 'cancelled' END,updated_at=now()
                    WHERE kind='roads' AND (task_key=? OR import_job_id IN (SELECT id FROM import_jobs WHERE region_id=?)) RETURNING id");
                $q->execute([$key,$region['id']??0]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
                if($region)$this->db->prepare('UPDATE regions SET active_job_id=null WHERE id=?')->execute([$region['id']]);
                $this->db->prepare('INSERT INTO region_operations(region_key,region_id,task_ids,requested_by) VALUES (?,?,?::jsonb,?)')->execute([$key,$region['id']??null,json_encode($ids,JSON_THROW_ON_ERROR),$user]);
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&in_array((string)$e->getCode(),['57014','55P03'],true))throw new HttpException(409,'Region is busy; no action was applied. Try again shortly.');throw $e;}
        return ['queued'=>true,'message'=>$action==='refresh'?'Fresh download queued with priority for the next available worker slot. Current validated coverage remains available until replacement succeeds.':'Region removed from discovery. Data cleanup is queued; active searches can request it again later.'];
    }
    public function runOne(): bool {
        $this->db->beginTransaction();
        try{
            $this->db->exec("SET LOCAL statement_timeout='2s'; SET LOCAL lock_timeout='250ms'");
            $op=$this->db->query("SELECT * FROM region_operations WHERE status IN ('queued','running') ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch(PDO::FETCH_ASSOC);
            if(!$op){$this->db->commit();return false;}
            $region=(int)($op['region_id']??0);
            // Catch tasks recreated by an active search while the discard was queued.
            $q=$this->db->prepare("UPDATE data_tasks SET cancel_requested=true,task_key='cancelled-task:'||id,status=CASE WHEN status='running' THEN status ELSE 'cancelled' END WHERE kind='roads' AND task_key=? RETURNING id");
            $q->execute([$op['region_key']]);$ids=array_values(array_unique(array_merge(json_decode($op['task_ids'],true),array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)))));
            $this->db->prepare("UPDATE region_operations SET task_ids=?::jsonb,status='running',updated_at=now() WHERE id=?")->execute([json_encode($ids,JSON_THROW_ON_ERROR),$op['id']]);
            if($region){
                $this->db->prepare("UPDATE import_jobs SET cancel_requested=true WHERE region_id=? AND status='running'")->execute([$region]);
                $q=$this->db->prepare("SELECT 1 FROM import_jobs WHERE region_id=? AND status='running'");$q->execute([$region]);
                if($q->fetchColumn()){$this->db->commit();return false;}
                $this->db->prepare('UPDATE regions SET active_job_id=null WHERE id=?')->execute([$region]);
                $q=$this->db->prepare('SELECT id,source_path FROM import_jobs WHERE region_id=? ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED');$q->execute([$region]);$job=$q->fetch(PDO::FETCH_ASSOC);
                if($job){
                    $cache=$this->db->prepare("WITH batch AS (SELECT DISTINCT cache.ctid FROM road_candidates c JOIN road_combination_cache cache ON (cache.snapshot->'member_ids') @> jsonb_build_array(c.id) WHERE c.job_id=? LIMIT 50) DELETE FROM road_combination_cache target USING batch WHERE target.ctid=batch.ctid");
                    $cache->execute([$job['id']]);
                    if($cache->rowCount()){$this->db->prepare('UPDATE region_operations SET progress=? WHERE id=?')->execute(['Discarding road combination cache',$op['id']]);$this->db->commit();return true;}
                    foreach(ImportCleanup::BATCHES as [$table,$limit,$kind]){
                        $selection=$kind==='candidate'?"SELECT child.ctid FROM $table child JOIN road_candidates c ON c.id=child.candidate_id WHERE c.job_id=? LIMIT $limit":"SELECT child.ctid FROM $table child WHERE child.job_id=? LIMIT $limit";
                        $q=$this->db->prepare("WITH batch AS ($selection) DELETE FROM $table target USING batch WHERE target.ctid=batch.ctid");$q->execute([$job['id']]);
                        if($q->rowCount()){$this->db->prepare('UPDATE region_operations SET progress=? WHERE id=?')->execute(['Discarding '.$table,$op['id']]);$this->db->commit();return true;}
                    }
                    $this->db->prepare('DELETE FROM import_jobs WHERE id=?')->execute([$job['id']]);$this->db->commit();
                    $this->removeManagedFile($job['source_path']);return true;
                }
                $remaining=$this->db->prepare('SELECT 1 FROM import_jobs WHERE region_id=? LIMIT 1');$remaining->execute([$region]);
                if($remaining->fetchColumn()){$this->db->commit();return false;}
                $this->db->prepare('DELETE FROM regions WHERE id=?')->execute([$region]);
            }
            foreach($ids as $id)foreach(['.osm','.osm.part'] as $suffix){$this->removeManagedFile(SWEEPLINE_ROOT.'/var/downloads/roads-'.(int)$id.$suffix);}
            $this->db->prepare("DELETE FROM data_tasks WHERE kind='roads' AND (task_key=? OR (cancel_requested AND status<>'running' AND id IN (SELECT jsonb_array_elements_text(?::jsonb)::bigint)))")->execute([$op['region_key'],json_encode($ids)]);
            $this->db->prepare("UPDATE region_operations SET status='complete',progress='Region discarded',updated_at=now() WHERE id=?")->execute([$op['id']]);
            $this->db->commit();return true;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException&&in_array((string)$e->getCode(),['57014','55P03'],true))return false;
            if(!isset($op['id']))throw $e;
            if(isset($op['id']))$this->db->prepare("UPDATE region_operations SET status='failed',progress='Discard failed; check worker journal',updated_at=now() WHERE id=?")->execute([$op['id']]);
            error_log('SweepLine region discard failed: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());return true;
        }
    }
}
