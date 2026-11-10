<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
use ZipArchive;

final class DataWorker
{
    public function __construct(private PDO $db,private ?\Closure $download=null) {}
    private function download(string $url,string $path,int $maxBytes,?string $post=null,?callable $progress=null,int $timeout=240): void
    {
        ($this->download ?? [Download::class,'file'])($url,$path,$maxBytes,$post,$progress,$timeout);
    }
    private function queueChild(DataQueue $queue,array $child,bool $force): void
    {
        $next=$queue->ensure($child['key'],'roads',$child);
        if($force)$this->db->prepare("UPDATE data_tasks SET status='queued',manual_priority=true,attempts=0,error=null,import_job_id=null,next_attempt_at=now(),progress='SuperAdmin refresh requested',updated_at=now() WHERE id=? AND status<>'running'")->execute([$next['id']]);
    }
    public function recover(): void
    {
        $this->db->exec("UPDATE import_jobs SET status='failed',error='Worker interrupted; previous active data retained',finished_at=now() WHERE status='running'");
        $this->db->exec("UPDATE data_tasks SET status='cancelled',progress='Cancelled by SuperAdmin',updated_at=now() WHERE status='running' AND cancel_requested=true");
        (new DataQueue($this->db))->purgeCancelled();
        $interrupted=$this->db->query("SELECT * FROM data_tasks WHERE status='running' AND cancel_requested=false")->fetchAll(PDO::FETCH_ASSOC);
        $queue=new DataQueue($this->db);
        foreach ($interrupted as $task) {
            $tile=json_decode($task['payload'],true);
            if ($task['kind']==='roads' && (int)$task['attempts']>=2 && CoveragePlanner::canSplit($tile)) {
                $this->db->beginTransaction();
                try {
                    foreach (CoveragePlanner::children($tile) as $child) { $this->queueChild($queue,$child,(bool)($task['manual_priority']??false)); }
                    $this->db->prepare("UPDATE data_tasks SET status='split',progress='Smaller areas queued after interrupted processing',updated_at=now() WHERE id=?")->execute([$task['id']]);
                    $this->db->commit();
                } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
            } else {
                $failed=(int)$task['attempts']>=4;
                $this->db->prepare("UPDATE data_tasks SET status=?,progress=?,error=?,next_attempt_at=now(),updated_at=now() WHERE id=?")
                    ->execute([$failed?'failed':'retry',$failed?'Worker repeatedly interrupted':'Resuming after worker restart',
                        $failed?'Worker repeatedly interrupted; check memory and storage in server logs.':null,$task['id']]);
            }
        }
    }
    private function checkpoint(int $id,bool $lock=false): void
    {
        $q=$this->db->prepare('SELECT cancel_requested FROM data_tasks WHERE id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id]);
        if ($q->fetchColumn()) { throw new JobCancelled('Cancelled by SuperAdmin'); }
        if ($lock) { $this->db->prepare("UPDATE data_tasks SET status='complete',manual_priority=false,progress='Local data ready',error=null,updated_at=now() WHERE id=?")->execute([$id]); }
    }
    private function progress(int $id,string $message): void
    {
        $this->checkpoint($id);
        $this->db->prepare('UPDATE data_tasks SET progress=?,updated_at=now() WHERE id=?')->execute([$message,$id]);
        $this->heartbeat($message); echo date('c')." task $id: $message\n";
    }
    public function heartbeat(?string $activity=null): void
    {
        WorkerStatus::heartbeat($this->db,$activity);
    }
    public function runOne(): bool
    {
        $this->db->beginTransaction();
        $task=$this->db->query("SELECT * FROM data_tasks WHERE status IN ('queued','retry') AND cancel_requested=false AND next_attempt_at<=now() ORDER BY CASE WHEN kind='zips' THEN 0 WHEN manual_priority THEN 1 ELSE 2 END,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($task) { $this->db->prepare("UPDATE data_tasks SET status='running',import_job_id=null,attempts=attempts+1,progress='Starting preparation',updated_at=now() WHERE id=?")->execute([$task['id']]); $task['attempts']++; }
        $this->db->commit();
        if (!$task) { return false; }
        $id=(int)$task['id']; $payload=json_decode($task['payload'],true,512,JSON_THROW_ON_ERROR);
        try {
            $this->db->exec("SET statement_timeout='0'");
            $this->checkpoint($id);
            $dir=SWEEPLINE_ROOT.'/var/downloads';
            if (!is_dir($dir)) { mkdir($dir,0750,true); }
            $free=disk_free_space($dir);
            if ($free!==false && $free<536870912) { throw new \RuntimeException('Data preparation paused: less than 512 MB of free disk space.'); }
            $budget=(new StorageSettings($this->db))->read()['database_budget_mb']*1048576;
            if ((int)$this->db->query('SELECT pg_database_size(current_database())')->fetchColumn()>$budget) {
                throw new \RuntimeException('Application data storage limit reached. SuperAdmin: increase the database budget on Manage.');
            }
            if ($task['kind']==='zips') { $this->zips($id,$dir); }
            else { $this->roads($id,$dir,$payload); }
            $this->db->prepare("UPDATE data_tasks SET status='complete',manual_priority=false,progress='Local data ready',error=null,updated_at=now() WHERE id=? AND cancel_requested=false")->execute([$id]);
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $this->db->beginTransaction();
            $cancel=$this->db->prepare('SELECT cancel_requested FROM data_tasks WHERE id=? FOR UPDATE'); $cancel->execute([$id]);
            if ($e instanceof JobCancelled || $cancel->fetchColumn()) {
                $this->db->prepare("UPDATE data_tasks SET status='cancelled',progress='Cancelled by SuperAdmin',error=null,updated_at=now() WHERE id=?")->execute([$id]);
                $this->db->commit(); (new DataQueue($this->db))->purgeCancelled(); $this->heartbeat(); return true;
            }
            $message=substr($e->getMessage(),0,1500);
            if ($task['kind']==='roads' && CoveragePlanner::canSplit($payload) && CoveragePlanner::oversized($message)) {
                $queue=new DataQueue($this->db);
                try {
                    foreach (CoveragePlanner::children($payload) as $child) { $this->queueChild($queue,$child,(bool)($task['manual_priority']??false)); }
                    $this->db->prepare("UPDATE data_tasks SET status='split',progress='Divided dense area into smaller downloads',error=null,updated_at=now() WHERE id=?")->execute([$id]);
                } catch (\Throwable $splitError) { $this->db->rollBack(); throw $splitError; }
            } else {
                $retry=(int)$task['attempts']<4; $delay=min(3600,60*(5**max(0,(int)$task['attempts']-1)));
                $this->db->prepare("UPDATE data_tasks SET status=?,error=?,progress=?,next_attempt_at=now()+(? * interval '1 second'),updated_at=now() WHERE id=?")
                    ->execute([$retry?'retry':'failed',$message,$retry?'Provider or processing error; automatic retry scheduled':$message,$delay,$id]);
            }
            $this->db->commit();
            error_log("Data task $id: $message");
        }
        $this->heartbeat(); return true;
    }
    private function zips(int $id,string $dir): void
    {
        $archive=$dir.'/us-zips.zip'; $text=$dir.'/US.txt';
        try {
            $this->download('https://download.geonames.org/export/zip/US.zip',$archive,20971520,null,fn($m)=>$this->progress($id,$m));
            $zip=new ZipArchive();
            if ($zip->open($archive)!==true) { throw new \RuntimeException('Invalid ZIP coordinate archive.'); }
            try {
                $stat=$zip->statName('US.txt');
                if (!$stat || $stat['size']>52428800) { throw new \RuntimeException('ZIP coordinate archive is missing US.txt or exceeds limits.'); }
                $in=$zip->getStream('US.txt'); $out=fopen($text,'wb');
                if (!$in || !$out) { throw new \RuntimeException('Cannot extract US ZIP coordinates.'); }
                try { $written=stream_copy_to_stream($in,$out,52428801); }
                finally { fclose($in); fclose($out); }
                if ($written>52428800) { throw new \RuntimeException('ZIP coordinates exceed extraction limit.'); }
            } finally { $zip->close(); }
            $this->progress($id,'Importing US ZIP coordinates');
            $count=ZipImporter::run($this->db,$text,fn($lock=false)=>$this->checkpoint($id,$lock)); $this->progress($id,"Updated $count ZIP coordinates");
        } finally {
            foreach ([$archive,$text] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }
    private function roads(int $id,string $dir,array $tile): void
    {
        [$w,$s,$e,$n]=$tile['bounds'];
        // A small halo improves continuity around cell boundaries; only the core is advertised as covered.
        $halo=CoveragePlanner::halo($tile);
        $bbox=implode(',', [max(-90,$s-$halo),max(-180,$w-$halo),min(90,$n+$halo),min(180,$e+$halo)]);
        $selector='way[highway~"^(motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street|service)$"]('.$bbox.');';
        if(CoveragePlanner::canSplit($tile)){
            $countPath=$dir.'/roads-'.$id.'-count.osm';$count=null;
            $this->progress($id,'Checking road density before downloading geometry');
            try{
                $this->download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$countPath,65536,http_build_query(['data'=>'[out:xml][timeout:12];'.$selector.'out count;']),fn($m)=>$this->progress($id,'Checking road density'),15);
                $count=CoveragePlanner::roadCount((string)file_get_contents($countPath));
            }catch(JobCancelled $cancelled){throw $cancelled;}
            catch(\Throwable $densityError){$this->progress($id,'Density estimate unavailable; using bounded fallback');}
            finally{if(is_file($countPath))unlink($countPath);}
            if($count!==null&&$count>=12000)throw new \RuntimeException('Road density limit exceeded: '.$count.' ways; splitting before geometry download.');
            if($count===null&&max($e-$w,$n-$s)>0.25)throw new \RuntimeException('Road density limit exceeded: estimate unavailable for a large area; splitting conservatively.');
        }
        $query='[out:xml][timeout:180][maxsize:134217728];way[highway~"^(motorway|trunk|primary|secondary|tertiary|unclassified|residential|living_street|service)$"]('.$bbox.');(._;>;);out body;';
        $path=$dir.'/roads-'.$id.'.osm';
        try {
            $this->download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$path,104857600,
                http_build_query(['data'=>$query]),fn($m)=>$this->progress($id,$m));
            $this->db->beginTransaction();
            $q=$this->db->prepare('INSERT INTO regions(slug,name) VALUES (?,?) ON CONFLICT(slug) DO UPDATE SET name=excluded.name RETURNING id');
            $q->execute([$tile['key'],'Roads near '.round(($s+$n)/2,3).', '.round(($w+$e)/2,3)]); $region=$q->fetchColumn();
            $q=$this->db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage,allow_empty,started_at) VALUES (?,'running',?,ST_MakeEnvelope(?,?,?,?,4326),true,now()) RETURNING *");
            $q->execute([$region,$path,$w,$s,$e,$n]); $job=$q->fetch(PDO::FETCH_ASSOC);
            $this->db->prepare('UPDATE data_tasks SET import_job_id=? WHERE id=?')->execute([$job['id'],$id]);
            $this->db->commit(); $this->progress($id,'Analyzing connected roads and driving profiles');
            (new Importer($this->db,fn($lock=false)=>$this->checkpoint($id,$lock)))->run($job);
            // Active imported geometry remains; only the validated temporary download is discarded.
        } finally { if (is_file($path)) { unlink($path); } }
    }
    public function maintain(): void
    {
        $queue=new DataQueue($this->db); $queue->purgeCancelled(); $queue->ensure('us-zips','zips',[],true);
        // Keep old active data, prune only nonactive automatic attempts after a week.
        // Bulk import pruning runs only in short idle-time ImportCleanup steps.
        $this->db->exec("DELETE FROM region_operations WHERE status='complete' AND updated_at<now()-interval '30 days'");
        $this->db->exec("DELETE FROM auth_attempts WHERE window_start<now()-interval '1 day'");
        $this->db->exec("DELETE FROM road_combination_cache WHERE created_at<now()-interval '7 days' OR cache_key IN (SELECT cache_key FROM road_combination_cache ORDER BY created_at DESC,cache_key OFFSET 500)");
        foreach (glob(SWEEPLINE_ROOT.'/var/downloads/*') as $file) {
            if (is_file($file) && !is_link($file) && preg_match('/^(roads-[0-9]+(-count)?\\.osm|us-zips\\.zip|US\\.txt)(\\.part)?$/',basename($file))
                && filemtime($file)<time()-86400) { unlink($file); }
        }
        $this->heartbeat();
    }
}
