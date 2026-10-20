<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
final class DataQueue
{
    public function __construct(private PDO $db) {}
    public function ensure(string $key,string $kind,array $payload=[],bool $refresh=false): array
    {
        $q=$this->db->prepare("INSERT INTO data_tasks(task_key,kind,payload) SELECT ?,?,?::jsonb
            WHERE (SELECT count(*) FROM data_tasks WHERE status IN ('queued','running','retry'))<1024
            ON CONFLICT(task_key) DO NOTHING");
        $q->execute([$key,$kind,json_encode((object)$payload,JSON_THROW_ON_ERROR)]);
        if ($refresh) {
            $this->db->prepare("UPDATE data_tasks SET status='queued',attempts=0,error=null,progress='Refreshing cached data',updated_at=now(),next_attempt_at=now()
                WHERE task_key=? AND status='complete' AND updated_at<now()-interval '30 days'")->execute([$key]);
        }
        $q=$this->db->prepare('SELECT * FROM data_tasks WHERE task_key=?'); $q->execute([$key]); $task=$q->fetch(PDO::FETCH_ASSOC);
        if (!$task) { throw new HttpException(429,'Background queue is full. Please try this search after current preparation finishes.'); }
        return $task;
    }
    public function prepare(array $p): array
    {
        $zip=$this->db->prepare('SELECT ST_X(location::geometry) lon,ST_Y(location::geometry) lat FROM zip_codes WHERE zip=?');
        $zip->execute([$p['zip']]); $center=$zip->fetch(PDO::FETCH_ASSOC);
        $zipTask=$this->ensure('us-zips','zips',[],true);
        if (!$center) {
            if ($zipTask['status']==='complete') { throw new \OutOfBoundsException('ZIP code not found in the current US postal dataset.'); }
            return $this->summary([$zipTask],false);
        }
        // Existing genuine manual or automatic coverage can satisfy a search immediately.
        $cover=$this->db->prepare("SELECT COALESCE(ST_Covers(ST_UnaryUnion(ST_Collect(coverage)),ST_Buffer(ST_SetSRID(ST_MakePoint(?,?),4326)::geography,?)::geometry),false)
            FROM regions WHERE active_job_id IS NOT NULL AND imported_at>now()-interval '30 days'");
        $cover->execute([$center['lon'],$center['lat'],$p['radius_miles']*1609.344]);
        if ($cover->fetchColumn()) { return ['state'=>'ready','complete'=>1,'total'=>1,'message'=>'Local data ready']; }
        $tasks=[];
        foreach (CoveragePlanner::tiles((float)$center['lon'],(float)$center['lat'],$p['radius_miles']) as $tile) {
            $this->collect($tile,$tasks);
            if (count($tasks)>1024) { throw new HttpException(422,'Too many areas for this radius. Please narrow the search.'); }
        }
        return $this->summary($tasks,true);
    }
    private function collect(array $tile,array &$tasks): void
    {
        $task=$this->ensure($tile['key'],'roads',$tile,true);
        if ($task['status']==='split') {
            foreach (CoveragePlanner::children($tile) as $child) { $this->collect($child,$tasks); }
        } else { $tasks[]=$task; }
    }
    private function summary(array $tasks,bool $roads): array
    {
        $complete=0; $failed=0; $pending=0; $failureMessage=null; $message=$roads?'Preparing road coverage':'Preparing US ZIP coordinates';
        foreach ($tasks as $task) {
            if ($task['status']==='complete') { $complete++; }
            if (in_array($task['status'],['queued','running','retry'],true) && !($task['cancel_requested']??false)) { $pending++; }
            if ($task['status']==='cancelled' || ($task['cancel_requested']??false)) { $failed++; $failureMessage='Preparation cancelled by a SuperAdmin. Existing results remain available.'; }
            elseif ($task['status']==='failed') { $failed++; $failureMessage=$task['error']?:'Data preparation failed.'; }
            elseif ($task['status']==='running' || $task['status']==='retry') { $message=$task['progress']; }
        }
        return ['state'=>$pending?'preparing':($failed?'failed':'ready'),
            'complete'=>$complete,'total'=>count($tasks),'failed'=>$failed,'message'=>$failureMessage??$message];
    }
    public function overview(): array
    {
        $this->purgeCancelled();
        return ['storage'=>StorageUsage::read($this->db),'tasks'=>$this->db->query("SELECT id,task_key,kind,status,attempts,progress,error,cancel_requested,next_attempt_at,updated_at FROM data_tasks WHERE cancel_requested=false AND status<>'cancelled' ORDER BY CASE WHEN status IN ('running','queued','retry') THEN 0 ELSE 1 END,updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC),
            'imports'=>$this->db->query("SELECT j.id,r.name,j.status,j.progress,j.error,j.cancel_requested,j.created_at FROM import_jobs j JOIN regions r ON r.id=j.region_id WHERE j.cancel_requested=false AND NOT EXISTS(SELECT 1 FROM data_tasks t WHERE t.import_job_id=j.id) ORDER BY CASE WHEN j.status IN ('queued','running') THEN 0 ELSE 1 END,j.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC),
            'counts'=>$this->db->query('SELECT status,count(*) AS count FROM data_tasks WHERE cancel_requested=false GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
            'worker_heartbeat'=>$this->db->query("SELECT value FROM app_settings WHERE key='worker_heartbeat'")->fetchColumn()?:null,
            'zip_last_sync'=>$this->db->query("SELECT value FROM app_settings WHERE key='zip_last_sync'")->fetchColumn()?:null];
    }
    public function purgeCancelled(): void
    {
        // Running rows remain private until the worker acknowledges cancellation.
        $this->db->exec("UPDATE import_jobs j SET cancel_requested=true FROM data_tasks t WHERE t.import_job_id=j.id AND (t.status='cancelled' OR t.cancel_requested=true) AND j.status<>'complete'");
        $this->db->exec("DELETE FROM data_tasks WHERE status='cancelled' OR (cancel_requested=true AND status<>'running')");
        $this->db->exec("DELETE FROM import_jobs j WHERE cancel_requested=true AND status NOT IN ('running','complete')
            AND NOT EXISTS(SELECT 1 FROM regions r WHERE r.active_job_id=j.id) AND NOT EXISTS(SELECT 1 FROM data_tasks t WHERE t.import_job_id=j.id)");
    }
    public function cancel(int $id,bool $import=false): array
    {
        $this->db->beginTransaction();
        try {
            if ($import) {
                $q=$this->db->prepare("UPDATE import_jobs j SET cancel_requested=true,status=CASE WHEN status='queued' THEN 'failed' ELSE status END,
                    progress='Cancellation requested by SuperAdmin' WHERE id=? AND cancel_requested=false AND status IN ('queued','running')
                    AND NOT EXISTS(SELECT 1 FROM data_tasks t WHERE t.import_job_id=j.id) RETURNING id");
            } else {
                $q=$this->db->prepare("UPDATE data_tasks t SET cancel_requested=true,task_key='cancelled-task:'||id,
                    status=CASE WHEN status IN ('queued','retry') THEN 'cancelled' ELSE status END,
                    progress='Cancellation requested by SuperAdmin',updated_at=now()
                    WHERE id=? AND cancel_requested=false AND status IN ('queued','running','retry')
                    AND NOT EXISTS(SELECT 1 FROM import_jobs j WHERE j.id=t.import_job_id AND j.status='complete') RETURNING task_key");
            }
            $q->execute([$id]);$key=$q->fetchColumn();
            if ($key===false) { throw new HttpException(409,'Job has already finished or changed. Refresh the job list.'); }
            $this->purgeCancelled();$this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
        return ['cancel_requested'=>true,'removed'=>true];
    }
    public function retry(int $id): array
    {
        $q=$this->db->prepare("UPDATE data_tasks SET status='queued',cancel_requested=false,import_job_id=null,attempts=0,error=null,next_attempt_at=now(),progress='Retry requested',updated_at=now() WHERE id=? AND status='failed' AND cancel_requested=false");
        $q->execute([$id]); return ['queued'=>$q->rowCount()>0];
    }
}
