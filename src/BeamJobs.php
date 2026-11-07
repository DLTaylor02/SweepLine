<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class BeamJobs
{
    public function __construct(private PDO $db,private ?\Closure $rasterSource=null,private ?string $storageRoot=null) {}
    public static function validId(string $id): string
    {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \OutOfBoundsException('Export not found.');
        return $id;
    }
    private function root(): string
    {
        $root=$this->storageRoot??SWEEPLINE_ROOT.'/var/beamng';
        if(!is_dir($root) && !mkdir($root,0700,true) && !is_dir($root))throw new \RuntimeException('Cannot create private export directory.');
        return $root;
    }
    private function directory(string $id): string {return $this->root().'/'.self::validId($id);}
    private function remove(string $id): void
    {
        $dir=$this->directory($id);
        if(is_link($dir))throw new \RuntimeException('Refusing a symlinked export directory.');
        if(!is_dir($dir))return;
        // No recursively computed deletion outside the validated job directory.
        foreach(scandir($dir) as $file)if($file!=='.'&&$file!=='..') {
            $path=$dir.'/'.$file;if(is_file($path)||is_link($path))unlink($path);
        }
        rmdir($dir);
    }
    public function create(int $user,array $input): array
    {
        $options=BeamPlan::options($input);
        if(isset($input['custom_id'])){
            $road=(new CustomRoads($this->db))->detail($user,CustomRoads::identifier($input));
            if(!isset($input['orientation']))$options['orientation']=$road['preferred_orientation'];
        } elseif(isset($input['favorite_id'])) {
            $favorite=Validation::number($input,'favorite_id',NAN,1,9007199254740991);
            if(floor($favorite)!==$favorite)throw new \InvalidArgumentException('Favorite ID must be a whole number.');
            $road=(new Favorites($this->db))->detail($user,(int)$favorite)['road'];
            if(!isset($input['orientation']))$options['orientation']=$road['preferred_orientation']??'forward';
        } else {
            $id=Validation::number($input,'road_id',NAN,1,9007199254740991);
            if(floor($id)!==$id)throw new \InvalidArgumentException('Road ID must be a whole number.');
            $profile=$input['profile']??'twisty';if(!is_string($profile))throw new \InvalidArgumentException('Unknown profile.');
            $road=(new Api($this->db))->exportRoad((int)$id,$profile,false,Api::memberIds($input['member_ids']??null,(int)$id));
        }
        $road=RoadNames::prefer($road,$input['preferred_name']??null);
        $plan=BeamPlan::project($this->db,$road,$options);$payload=json_encode($plan,JSON_THROW_ON_ERROR);
        if(strlen($payload)>2097152)throw new \InvalidArgumentException('This road contains too much geometry for BeamNG export. Choose a shorter stretch.');
        $fingerprint=hash('sha256','beamng-v0.40-78-'.Version::number().$payload);
        $this->db->beginTransaction();
        try {
            $this->db->exec('SELECT pg_advisory_xact_lock(73019425)');
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$user]);
            $q=$this->db->prepare("SELECT id FROM beamng_exports WHERE user_id=? AND fingerprint=? AND status IN ('queued','running') ORDER BY created_at DESC LIMIT 1");
            $q->execute([$user,$fingerprint]);$id=$q->fetchColumn();
            if(!$id) {
                $q=$this->db->prepare("SELECT count(*) FROM beamng_exports WHERE status IN ('queued','running') AND (user_id=? OR (SELECT count(*) FROM beamng_exports WHERE status IN ('queued','running'))>=20)");
                $q->execute([$user]);if((int)$q->fetchColumn()>=3)throw new HttpException(429,'Export queue is busy. Finish or cancel an existing BeamNG export first.');
                $id=bin2hex(random_bytes(16));
                $this->db->prepare('INSERT INTO beamng_exports(id,user_id,fingerprint,name,payload) VALUES (?,?,?,?,?::jsonb)')->execute([$id,$user,$fingerprint,$plan['name'],$payload]);
            }
            $this->db->commit();return $this->status($user,$id);
        } catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function status(int $user,string $id): array
    {
        self::validId($id);
        $q=$this->db->prepare("SELECT id,name,status,progress,error,expires_at,file_bytes,cancel_requested,report,jsonb_array_length(COALESCE(payload->'bridges','[]'::jsonb)) AS bridge_count,jsonb_array_length(COALESCE(payload->'tunnels','[]'::jsonb)) AS tunnel_count FROM beamng_exports WHERE id=? AND user_id=?");
        $q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC)?:throw new \OutOfBoundsException('Export not found.');
        if($row['status']==='complete' && strtotime($row['expires_at'])<=time()){$row['status']='expired';$row['progress']='Download expired. Generate a new export.';}
        $row['report']=$row['report']?json_decode($row['report'],true,512,JSON_THROW_ON_ERROR):null;
        foreach($row['report']['carriageways']['tracks']??[] as $track)if(!empty($track['intersection_context']['warning']))$row['warning']=trim(($row['warning']??'').' '.$track['intersection_context']['warning']);
        if(!empty($row['report']['carriageways']['matching']['warning']))$row['warning']=trim(($row['warning']??'').' '.$row['report']['carriageways']['matching']['warning']);
        if(!empty($row['report']['building_data']['warning']))$row['warning']=trim(($row['warning']??'').' '.$row['report']['building_data']['warning']);
        if(!empty($row['report']['intersection_context']['warning']))$row['warning']=trim(($row['warning']??'').' '.$row['report']['intersection_context']['warning']);
        if(!empty($row['report']['guardrail_data']['warning']))$row['warning']=trim(($row['warning']??'').' '.$row['report']['guardrail_data']['warning']);
        if($row['status']==='complete')$row['download_url']='/api/beamng/'.$id.'/download';
        return $row;
    }
    public function cancel(string $id,?int $user=null): array
    {
        self::validId($id);
        $q=$this->db->prepare("UPDATE beamng_exports SET cancel_requested=true,status=CASE WHEN status='queued' THEN 'cancelled' ELSE status END,progress='Cancellation requested',updated_at=now() WHERE id=? AND status IN ('queued','running')".($user===null?'':' AND user_id=?'));
        $q->execute($user===null?[$id]:[$id,$user]);
        return ['cancel_requested'=>$q->rowCount()>0];
    }
    public function download(int $user,string $id): never
    {
        $job=$this->status($user,$id);
        if($job['status']==='expired')throw new HttpException(410,'This download expired. Generate a new BeamNG export.');
        if($job['status']!=='complete')throw new HttpException(409,'This export is not ready.');
        $file=$this->directory($id).'/map.zip';
        if(is_link($file) || !is_file($file))throw new HttpException(410,'The temporary export is no longer available.');
        $handle=fopen($file,'rb');if(!$handle)throw new \RuntimeException('Cannot read export.');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.pathinfo(RoadExport::filename($job['name'],'gpx'),PATHINFO_FILENAME).'-beamng.zip"');
        header('Content-Length: '.fstat($handle)['size']);
        fpassthru($handle);fclose($handle);exit;
    }
    public function recover(): void
    {
        $rows=$this->db->query("UPDATE beamng_exports SET status=CASE WHEN cancel_requested THEN 'cancelled' ELSE 'failed' END,progress='Worker restarted; generate a new export',error='Export generation was interrupted during: '||left(progress,240)||'. Check the worker service journal for the exit reason.',updated_at=now() WHERE status='running' RETURNING id")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as $id)$this->remove($id);
    }
    public function maintain(): void
    {
        if(!$this->db->query('SELECT pg_try_advisory_lock(73019426)')->fetchColumn())return;
        try {
        $rows=$this->db->query("UPDATE beamng_exports SET status='expired',progress='Temporary files expired',payload='{}'::jsonb WHERE status='complete' AND expires_at<=now() RETURNING id")->fetchAll(PDO::FETCH_COLUMN);
        foreach($rows as $id)$this->remove($id);
        $this->db->exec("DELETE FROM beamng_exports WHERE status IN ('failed','cancelled','expired') AND updated_at<now()-interval '1 day'");
        // Remove orphaned work directories (for deleted accounts or interrupted processes).
        foreach(glob($this->root().'/*') as $dir)if(preg_match('/^[a-f0-9]{32}$/D',basename($dir))) {
            $q=$this->db->prepare("SELECT 1 FROM beamng_exports WHERE id=? AND status IN ('queued','running','complete')");$q->execute([basename($dir)]);
            if(!$q->fetchColumn())$this->remove(basename($dir));
        }
        } finally {$this->db->exec('SELECT pg_advisory_unlock(73019426)');}
    }
    public function runOne(): bool
    {
        $this->db->beginTransaction();
        $job=$this->db->query("SELECT * FROM beamng_exports WHERE status='queued' AND cancel_requested=false ORDER BY created_at,id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if($job)$this->db->prepare("UPDATE beamng_exports SET status='running',progress='Preparing map',updated_at=now() WHERE id=?")->execute([$job['id']]);
        $this->db->commit();if(!$job)return false;
        $id=$job['id'];BeamFailure::begin($id);$dir=$this->directory($id);$lastHeartbeat=0;$buildLog=new BeamBuildLog();
        $progress=function(?string $message)use($id,&$lastHeartbeat,$buildLog): void {
            $buildLog->event($message);
            if($message!==null){BeamFailure::stage($message);if(PHP_SAPI==='cli')error_log('SweepLine BeamNG '.$id.': '.substr(str_replace(["\r","\n"],' ',$message),0,240).' [heap '.round(memory_get_usage(true)/1048576).' MiB; peak '.round(memory_get_peak_usage(true)/1048576).' MiB]');}
            $q=$this->db->prepare('SELECT cancel_requested FROM beamng_exports WHERE id=?');$q->execute([$id]);$state=$q->fetch(PDO::FETCH_ASSOC);
            if(!$state || $state['cancel_requested'])throw new JobCancelled('Export cancelled.');
            if($message!==null)$this->db->prepare('UPDATE beamng_exports SET progress=?,updated_at=now() WHERE id=?')->execute([$message,$id]);
            if(time()-$lastHeartbeat>=3){WorkerStatus::heartbeat($this->db,$message===null?'BeamNG export processing':$message);$lastHeartbeat=time();}
        };
        try {
            if(!is_dir($dir) && !mkdir($dir,0700))throw new \RuntimeException('Cannot create job workspace.');
            if(disk_free_space($dir)<536870912)throw new \RuntimeException('Export needs at least 512 MiB free disk space.');
            $budget=(new StorageSettings($this->db))->read()['database_budget_mb']*1048576;
            if((int)$this->db->query('SELECT pg_database_size(current_database())')->fetchColumn()>$budget)throw new \RuntimeException('Database storage budget reached. SuperAdmin can raise it on Manage.');
            $this->db->exec("SET statement_timeout='0'");$plan=json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);
            if(!$this->rasterSource){
                $plan['carriageway_data']=BeamCarriageways::fetch($this->db,$plan,$progress);
                $plan['carriageways']=$plan['carriageway_data']['tracks'];unset($plan['carriageway_data']['tracks']);
                if($plan['carriageways']){$extent=$plan['points'];foreach($plan['carriageways'] as $t)array_push($extent,...$t['points']);$plan['bounds']=BeamPlan::square($extent,$plan['padding_miles']*1609.344);}
            }
            $raster=$this->rasterSource?($this->rasterSource)($plan,$dir,$progress):(new BeamSources())->fetch($plan,$dir,$progress);
            if(!$this->rasterSource){$plan['building_data']=BeamBuildings::fetch($this->db,$plan,$dir,$progress);$plan['guardrail_data']=BeamGuardrails::fetch($this->db,$plan,$dir,$progress);}
            try{$plan['intersection_data']=BeamIntersections::fetch($this->db,$plan,$progress);}
            catch(JobCancelled $e){throw $e;}
            catch(\Throwable $e){$plan['intersection_data']=['branches'=>[],'warning'=>'Local intersecting-road context could not be prepared.'];}
            $plan['intersection_data']=BeamCarriageways::filterContext($plan['intersection_data'],$plan['carriageways']??[]);
            $contextBudget=max(0,256-count($plan['intersection_data']['branches']));
            foreach($plan['carriageways']??[] as $i=>$track){
                $other=array_replace($plan,['points'=>$track['points'],'sources'=>$track['sources']]);
                if(!$this->rasterSource){
                    $buildings=BeamBuildings::fetch($this->db,$other,$dir,$progress);$rails=BeamGuardrails::fetch($this->db,$other,$dir,$progress);
                    foreach([['building_data','footprints',$buildings],['guardrail_data','lines',$rails]] as [$key,$field,$data]){
                        $items=[];foreach(array_merge($plan[$key][$field]??[],$data[$field]??[]) as $item)$items[hash('sha256',json_encode($item['points'],JSON_THROW_ON_ERROR))]=$item;
                        $plan[$key][$field]=array_values($items);
                        if(isset($data['warning']))$plan[$key]['warning']=trim(($plan[$key]['warning']??'').' '.$data['warning']);
                    }
                }
                try{$context=BeamIntersections::fetch($this->db,$other,$progress);}
                catch(JobCancelled $e){throw $e;}
                catch(\Throwable $e){$context=['branches'=>[],'warning'=>'Opposite-carriageway intersection context was unavailable.'];}
                $context=BeamCarriageways::filterContext($context,$plan['carriageways']);
                if(count($context['branches'])>$contextBudget){$context['branches']=array_slice($context['branches'],0,$contextBudget);$context['warning']=trim(($context['warning']??'').' Intersecting-road context reached the shared 256-branch budget.');}
                $contextBudget-=count($context['branches']);$plan['carriageways'][$i]['intersection_data']=$context;
            }
            if(isset($plan['guardrail_data']['lines'])){$railIds=[];foreach($plan['guardrail_data']['lines'] as $line)foreach($line['osm_ids']??[] as $osmId)$railIds[$osmId]=true;$plan['guardrail_data']['source_ways']=count($railIds);}
            foreach(scandir($dir) as $inputFile)if(preg_match('/\\.(tif|json|bin|hdr|xml)$/i',$inputFile))$buildLog->artifact($inputFile,$dir.'/'.$inputFile);
            $report=BeamMap::build($plan,$raster,$id,$dir.'/map.zip.part',$progress,null,$buildLog);$progress(null);
            $this->db->beginTransaction();
            $q=$this->db->prepare('SELECT cancel_requested FROM beamng_exports WHERE id=? FOR UPDATE');$q->execute([$id]);$state=$q->fetch(PDO::FETCH_ASSOC);if(!$state || $state['cancel_requested'])throw new JobCancelled('Export cancelled.');
            if(!rename($dir.'/map.zip.part',$dir.'/map.zip'))throw new \RuntimeException('Cannot finalize ZIP.');
            foreach(scandir($dir) as $file)if($file!=='.'&&$file!=='..'&&$file!=='map.zip'&&is_file($dir.'/'.$file))unlink($dir.'/'.$file);
            $this->db->prepare("UPDATE beamng_exports SET status='complete',progress='Map ready. Download available for 30 minutes.',file_bytes=?,report=?::jsonb,expires_at=now()+interval '30 minutes',payload='{}'::jsonb,updated_at=now() WHERE id=?")->execute([filesize($dir.'/map.zip'),json_encode($report,JSON_THROW_ON_ERROR),$id]);$this->db->commit();
        } catch(\Throwable $e) {
            if($this->db->inTransaction())$this->db->rollBack();$this->remove($id);
            $cancel=$e instanceof JobCancelled;
            $this->db->prepare("UPDATE beamng_exports SET status=?,progress=?,error=?,payload='{}'::jsonb,updated_at=now() WHERE id=?")->execute([$cancel?'cancelled':'failed',$cancel?'Export cancelled':'Export failed',$cancel?null:substr($e->getMessage(),0,1000),$id]);
        }
        BeamFailure::finish();return true;
    }
}

