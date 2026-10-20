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
        $complete=0; $failed=0; $failureMessage=null; $message=$roads?'Preparing road coverage':'Preparing US ZIP coordinates';
        foreach ($tasks as $task) {
            if ($task['status']==='complete') { $complete++; }
            if ($task['status']==='failed') { $failed++; $failureMessage=$task['error']?:'Data preparation failed.'; }
            elseif ($task['status']==='running' || $task['status']==='retry') { $message=$task['progress']; }
        }
        return ['state'=>$failed?'failed':($complete===count($tasks)?'ready':'preparing'),
            'complete'=>$complete,'total'=>count($tasks),'failed'=>$failed,'message'=>$failureMessage??$message];
    }
    public function overview(): array
    {
        return ['tasks'=>$this->db->query('SELECT id,task_key,kind,status,attempts,progress,error,updated_at FROM data_tasks ORDER BY updated_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC),
            'worker_heartbeat'=>$this->db->query("SELECT value FROM app_settings WHERE key='worker_heartbeat'")->fetchColumn()?:null,
            'zip_last_sync'=>$this->db->query("SELECT value FROM app_settings WHERE key='zip_last_sync'")->fetchColumn()?:null];
    }
    public function retry(int $id): array
    {
        $q=$this->db->prepare("UPDATE data_tasks SET status='queued',attempts=0,error=null,next_attempt_at=now(),progress='Retry requested',updated_at=now() WHERE id=? AND status='failed'");
        $q->execute([$id]); return ['queued'=>$q->rowCount()>0];
    }
}
