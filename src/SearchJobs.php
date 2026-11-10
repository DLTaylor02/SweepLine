<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Heavy discovery runs in the existing CLI worker, outside PHP-FPM's request deadline. */
final class SearchJobs
{
    public function __construct(private PDO $db) {}
    public function enqueue(int $user,array $input,?string $diagnosticRoad=null,?array $favorite=null): array {
        $p=Validation::search($input);$history=null;
        if($input['record_history']??false)$history=(new SearchHistory($this->db))->record($user,$p);
        elseif(isset($input['history_id'])){
            $value=Validation::number($input,'history_id',NAN,1,9007199254740991);
            if(floor($value)!==$value)throw new \InvalidArgumentException('History ID must be a whole number.');
            $q=$this->db->prepare('SELECT id FROM search_history WHERE id=? AND user_id=? AND parameters=?::jsonb');
            $q->execute([(int)$value,$user,json_encode($p,JSON_THROW_ON_ERROR)]);$history=$q->fetchColumn();
            if($history===false)throw new \OutOfBoundsException('Search history entry not found.');
        }
        if($diagnosticRoad!==null)$p['_diagnostic_road']=$diagnosticRoad;
        if($favorite!==null)$p['_favorite']=$favorite;
        $fingerprint=hash('sha256',json_encode([$p,Scorer::profiles()[$p['profile']]],JSON_THROW_ON_ERROR));
        $this->db->beginTransaction();
        try{
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$user]);
            $this->db->exec('SELECT pg_advisory_xact_lock(73019424)');
            $q=$this->db->prepare("SELECT id FROM search_jobs WHERE user_id=? AND fingerprint=? AND status IN ('queued','running') AND NOT cancel_requested");
            $q->execute([$user,$fingerprint]);$id=$q->fetchColumn();
            if($id===false){
                $q=$this->db->prepare("SELECT count(*) FROM search_jobs WHERE user_id=? AND status IN ('queued','running') AND NOT cancel_requested");$q->execute([$user]);
                if((int)$q->fetchColumn()>=4)throw new HttpException(429,'Four searches are already pending. Wait for them to finish; a SuperAdmin can cancel unused searches in Manage.');
                if((int)$this->db->query("SELECT count(*) FROM search_jobs WHERE status IN ('queued','running') AND NOT cancel_requested")->fetchColumn()>=128)throw new HttpException(429,'Search queue is full. Please try again shortly.');
                $q=$this->db->prepare('INSERT INTO search_jobs(user_id,fingerprint,parameters,history_id) VALUES (?,?,?::jsonb,?) RETURNING id');
                $q->execute([$user,$fingerprint,json_encode($p,JSON_THROW_ON_ERROR),$history]);$id=$q->fetchColumn();
            }
            if($history!==null)$this->db->prepare('UPDATE search_jobs SET history_id=COALESCE(history_id,?) WHERE id=?')->execute([$history,$id]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        return $this->status($user,(int)$id);
    }
    public function status(int $user,int $id): array {
        $q=$this->db->prepare('SELECT j.id,j.status,j.progress,j.error,j.history_id,j.result,COALESCE(j.preparation,(SELECT old.preparation FROM search_jobs old WHERE old.user_id=j.user_id AND old.fingerprint=j.fingerprint AND old.id<j.id AND old.preparation IS NOT NULL ORDER BY old.id DESC LIMIT 1)) AS preparation FROM search_jobs j WHERE j.id=? AND j.user_id=?');
        $q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new \OutOfBoundsException('Search job not found or expired.');
        $result=['search_job'=>['id'=>(int)$row['id'],'status'=>$row['status'],'progress'=>$row['progress'],'error'=>$row['error']],'history_id'=>$row['history_id']===null?null:(int)$row['history_id']];
        if($row['status']==='queued'){
            $ahead=$this->db->prepare("SELECT count(*) FROM search_jobs WHERE id<? AND status IN ('queued','running') AND NOT cancel_requested");
            $ahead->execute([$id]);$result['search_job']['ahead']=(int)$ahead->fetchColumn();
        }
        if(!empty($row['preparation'])){
            $preparation=json_decode($row['preparation'],true,512,JSON_THROW_ON_ERROR);
            if(!empty($preparation['task_ids']))$preparation=(new DataQueue($this->db))->taskStatus($preparation['task_ids']);
            $result['preparation']=$preparation;
        }
        if($row['status']==='complete'){
            $result['result']=json_decode($row['result'],true,512,JSON_THROW_ON_ERROR);
            if($row['history_id']===null)unset($result['result']['history_id']);
            else $result['result']['history_id']=(int)$row['history_id'];
        }
        return $result;
    }
    public function cancel(int $id): array {
        $q=$this->db->prepare("UPDATE search_jobs SET cancel_requested=true,status=CASE WHEN status='queued' THEN 'cancelled' ELSE status END,updated_at=now() WHERE id=? AND status IN ('queued','running')");
        $q->execute([$id]);return ['cancel_requested'=>$q->rowCount()>0];
    }
    public function recover(): void {
        $this->db->exec("UPDATE search_jobs SET status=CASE WHEN cancel_requested THEN 'cancelled' ELSE 'failed' END,error=CASE WHEN cancel_requested THEN null ELSE 'Search worker was interrupted. Run the search again.' END,updated_at=now() WHERE status='running'");
    }
    public function maintain(): void {
        $this->db->exec("DELETE FROM search_jobs WHERE status NOT IN ('queued','running') AND updated_at<now()-interval '30 minutes'");
    }
    public function runOne(): bool {
        $this->db->beginTransaction();
        try{
            $row=$this->db->query("SELECT * FROM search_jobs WHERE status='queued' AND NOT cancel_requested ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch(PDO::FETCH_ASSOC);
            if($row)$this->db->prepare("UPDATE search_jobs SET status='running',updated_at=now() WHERE id=?")->execute([$row['id']]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        if(!$row)return false;
        $start=microtime(true);$last=0.0;$stage='';
        $progress=function(string $message)use($row,$start,&$last,&$stage): void{
            $now=microtime(true);
            if($message===$stage&&$now-$last<1)return;
            $q=$this->db->prepare('SELECT cancel_requested FROM search_jobs WHERE id=?');$q->execute([$row['id']]);$current=$q->fetch(PDO::FETCH_ASSOC);
            if(!$current||$current['cancel_requested'])throw new JobCancelled('Search cancelled.');
            if($now-$start>180)throw new HttpException(422,'Search processing exceeded three minutes. Try a smaller radius.');
            if($message!==$stage)echo date('c').' search '.$row['id'].': '.$message.' ('.round($now-$start,2)."s)\n";
            $stage=$message;$last=$now;
            $this->db->prepare('UPDATE search_jobs SET progress=?,updated_at=now() WHERE id=?')->execute([$message,$row['id']]);
            // Search service has its own lifecycle; do not overwrite the import/export heartbeat.
        };
        try{
            $this->db->exec("SET statement_timeout='45s'; SET lock_timeout='2s'");
            $p=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR);
            if(isset($p['_favorite'])){
                $progress('Resolving and saving favorite road');
                $result=(new Favorites($this->db))->save((int)$row['user_id'],$p['_favorite']);
            }elseif(isset($p['_diagnostic_road'])){
                $result=(new RoadDiagnostics($this->db))->run($p+['road'=>$p['_diagnostic_road']],$progress);
            }else{
            $progress('Checking local coverage');
            $preparation=(new DataQueue($this->db))->prepare($p);
            $this->db->prepare('UPDATE search_jobs SET preparation=?::jsonb WHERE id=?')->execute([json_encode($preparation,JSON_THROW_ON_ERROR),$row['id']]);
            try{$result=(new Api($this->db))->search($p,$progress);}
            catch(\OutOfBoundsException $e){if($preparation['state']==='ready')throw $e;$result=['results'=>[],'parameters'=>$p];}
            $result['preparation']=$preparation;
            }
            $progress('Saving search results');
            $historyQuery=$this->db->prepare('SELECT history_id FROM search_jobs WHERE id=?');$historyQuery->execute([$row['id']]);$currentHistory=$historyQuery->fetchColumn();
            $row['history_id']=$currentHistory===false?null:$currentHistory;
            if($row['history_id']!==null){
                try{(new SearchHistory($this->db))->capture((int)$row['user_id'],(int)$row['history_id'],$p,$result);}catch(\OutOfBoundsException $e){/* Owner deleted History while search ran. */}
                $result['history_id']=(int)$row['history_id'];
            }
            $json=json_encode($result,JSON_THROW_ON_ERROR);
            if(strlen($json)>16777216)throw new HttpException(422,'Search result limit exceeded. Use a smaller radius.');
            $this->db->prepare("UPDATE search_jobs SET status=CASE WHEN cancel_requested THEN 'cancelled' ELSE 'complete' END,result=?::jsonb,error=null,progress='Results ready',updated_at=now() WHERE id=?")->execute([$json,$row['id']]);
        }catch(\Throwable $e){
            $cancel=$e instanceof JobCancelled;
            $error=$cancel?null:($e instanceof \PDOException?'Database operation failed during '.$stage.'. Try again or use a smaller radius.':($e instanceof HttpException||$e instanceof \InvalidArgumentException||$e instanceof \OutOfBoundsException?$e->getMessage():'Search failed during '.$stage.'. Check the worker journal.'));
            $this->db->prepare("UPDATE search_jobs SET status=?,error=?,updated_at=now() WHERE id=?")->execute([$cancel?'cancelled':'failed',$error,$row['id']]);
            if(!$cancel)error_log('SweepLine search '.$row['id'].' failed during '.$stage.': '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine().($e instanceof \PDOException?' SQLSTATE='.$e->getCode():''));
        }finally{$this->db->exec("SET statement_timeout='0'; SET lock_timeout='5s'");}
        return true;
    }
}
