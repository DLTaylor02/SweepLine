<?php
// Offline integration tests: synthetic provider responses are injected explicitly.
$queue=new SweepLine\DataQueue($db);
$firstTask=$queue->ensure('us-zips','zips'); $secondTask=$queue->ensure('us-zips','zips');
check($firstTask['id']===$secondTask['id'],'Duplicate preparations share a task');
$db->exec("UPDATE data_tasks SET status='complete' WHERE task_key='us-zips'");
check($queue->prepare(SweepLine\Validation::search(['zip'=>'12345','radius_miles'=>25]))['state']==='ready','Fresh coverage needs no road download');
$task=$queue->ensure('auto-test-pipeline','roads',['key'=>'auto-test-pipeline','bounds'=>[-120,39.5,-119.5,40]]);
$downloads=0;
$fakeDownload=static function(string $url,string $path,int $maxBytes,?string $post=null,?callable $progress=null) use (&$downloads): void {
    $downloads++; copy(__DIR__.'/fixture.osm',$path);
    if ($progress) { $progress('Test fixture download; no network'); }
};
$worker=new SweepLine\DataWorker($db,$fakeDownload);
check($worker->runOne(),'Queued automatic road preparation runs');
check($downloads===1,'Worker fetches once per queued area');
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-test-pipeline'")->fetchColumn()==='complete','Download, analysis and activation complete');
$activeJob=(int)$db->query("SELECT active_job_id FROM regions WHERE slug='auto-test-pipeline'")->fetchColumn();
check($activeJob>0,'Automatic area activates validated dataset');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345'])['results'])===2,'Overlapping automatic and manual cells do not duplicate identical roads');
check(!$worker->runOne() && $downloads===1,'Completed area reused without download');
$db->exec("UPDATE data_tasks SET status='queued',attempts=0 WHERE task_key='auto-test-pipeline'");
$failing=new SweepLine\DataWorker($db,static function(): void { throw new RuntimeException('Test provider unavailable'); });
$failing->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-test-pipeline'")->fetchColumn()==='retry','Provider failure schedules retry');
check((int)$db->query("SELECT active_job_id FROM regions WHERE slug='auto-test-pipeline'")->fetchColumn()===$activeJob,'Provider failure retains active dataset');
$db->exec("UPDATE data_tasks SET attempts=3,next_attempt_at=now() WHERE task_key='auto-test-pipeline'");
$failing->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-test-pipeline'")->fetchColumn()==='failed','Retries bounded');
check($queue->retry((int)$task['id'])['queued'],'SuperAdmin service retries failed job');
$db->exec("UPDATE data_tasks SET status='complete' WHERE task_key='auto-test-pipeline'");
$queue->ensure('auto-empty-test','roads',['key'=>'auto-empty-test','bounds'=>[-119,39.5,-118.5,40]]);
$emptyWorker=new SweepLine\DataWorker($db,static function(string $url,string $path): void { file_put_contents($path,'<?xml version="1.0"?><osm version="0.6"></osm>'); });
$emptyWorker->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-empty-test'")->fetchColumn()==='complete','Empty verified OSM response is valid coverage');
$queue->ensure('auto-dense-test','roads',['key'=>'auto-dense-test','bounds'=>[-120,39.5,-119.5,40]]);
$denseWorker=new SweepLine\DataWorker($db,static function(): void { throw new RuntimeException('Extract exceeds the safe download limit.'); });
$denseWorker->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-dense-test'")->fetchColumn()==='split','Dense area subdivides automatically');
check((int)$db->query("SELECT count(*) FROM data_tasks WHERE task_key LIKE 'auto-dense-test-%'")->fetchColumn()===4,'Four child areas queued');
$db->exec("UPDATE data_tasks SET status='complete' WHERE status='queued'");
$queue->ensure('auto-city-limit','roads',['key'=>'auto-city-limit','bounds'=>[-83,39.875,-82.875,40]]);
$cityWorker=new SweepLine\DataWorker($db,static function(): void {throw new RuntimeException('Road limit exceeded; use a smaller extract (30k ways max)');});
$cityWorker->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-city-limit'")->fetchColumn()==='split','Road-count cap subdivides at former minimum cell size');
$db->exec("UPDATE data_tasks SET status='complete' WHERE status='queued'");
$db->exec("UPDATE data_tasks SET status='running',attempts=2 WHERE task_key='auto-dense-test-0'");
$worker->recover();
check($db->query("SELECT status FROM data_tasks WHERE task_key='auto-dense-test-0'")->fetchColumn()==='split','Interrupted dense area recovers by subdivision');
$db->exec("UPDATE data_tasks SET status='complete' WHERE status='queued'");
$db->exec("UPDATE data_tasks SET status='queued' WHERE task_key='us-zips'");
$zipWorker=new SweepLine\DataWorker($db,static function(string $url,string $path): void {
    $archive=new ZipArchive();
    if ($archive->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { throw new RuntimeException('Cannot create test ZIP'); }
    $archive->addFromString('US.txt',"US\t12345\tSynthetic place\tSynthetic state\tZZ\tSynthetic county\t000\t\t\t39.52\t-119.80\t4\n"); $archive->close();
});
$zipWorker->runOne();
check($db->query("SELECT status FROM data_tasks WHERE task_key='us-zips'")->fetchColumn()==='complete','Automatic ZIP download/extraction/import completes');
check((bool)$db->query("SELECT value FROM app_settings WHERE key='zip_last_sync'")->fetchColumn(),'ZIP refresh timestamp recorded');
// Keep only the original road fixture visible for subsequent HTTP checks.
$db->exec("UPDATE regions SET active_job_id=null WHERE slug LIKE 'auto-%'");

// Cancellation removes an attempt, while later demand creates a fresh job.
$cancelTask=$queue->ensure('auto-cancel-test','roads',['key'=>'auto-cancel-test','bounds'=>[-120,39.5,-119.5,40]]);
$cancelId=(int)$cancelTask['id'];
check($queue->cancel($cancelId)['cancel_requested'],'Queued cancellation accepted');
check(!$queue->retry($cancelId)['queued'],'Deleted attempt cannot be retried by old ID');
check((int)$db->query('SELECT count(*) FROM data_tasks WHERE id='.$cancelId)->fetchColumn()===0,'Queued cancellation physically removes queue row');
$replacement=$queue->ensure('auto-cancel-test','roads',['key'=>'auto-cancel-test','bounds'=>[-120,39.5,-119.5,40]]);
check($replacement['status']==='queued' && $replacement['id']!=$cancelId,'New demand creates a fresh queued attempt');
$db->exec("UPDATE data_tasks SET status='complete' WHERE id=".(int)$replacement['id']);
$cancelTask=$queue->ensure('auto-cancel-running','roads',['key'=>'auto-cancel-running','bounds'=>[-120,39.5,-119.5,40]]);
$cancelId=(int)$cancelTask['id'];$replacement=null;
$cancellingWorker=new SweepLine\DataWorker($db,static function(string $url,string $path,int $maxBytes,?string $post,?callable $progress) use ($queue,$cancelId,&$replacement): void {
    copy(__DIR__.'/fixture.osm',$path);
    $queue->cancel($cancelId);
    $replacement=$queue->ensure('auto-cancel-running','roads',['key'=>'auto-cancel-running','bounds'=>[-120,39.5,-119.5,40]]);
    check($replacement['status']==='queued' && $replacement['id']!=$cancelId,'New demand can queue while previous attempt stops');
    $progress('Downloading fixture');
});
$cancellingWorker->runOne();
check((int)$db->query('SELECT count(*) FROM data_tasks WHERE id='.$cancelId)->fetchColumn()===0,'Cancelled running attempt is deleted');
check(!is_file(SWEEPLINE_ROOT.'/var/downloads/roads-'.$cancelId.'.osm'),'Cancelled download cleaned up');
$worker->recover();
check($queue->ensure('auto-cancel-running','roads')['id']===$replacement['id'],'Restart preserves replacement attempt');
check($worker->runOne(),'Replacement job runs after cancelled attempt stops');
check($queue->ensure('auto-cancel-running','roads')['status']==='complete','Replacement completes normally');
$db->exec("UPDATE regions SET active_job_id=null WHERE slug='auto-cancel-running'");
try { $queue->cancel((int)$task['id']); check(false,'Completed task cancellation refused'); }
catch (SweepLine\HttpException $e) { check($e->status===409,'Completed task cancellation refused'); }
// Cancellation during analysis must not replace an active dataset.
$region=$db->query("SELECT id FROM regions WHERE slug='auto-test-pipeline'")->fetchColumn();
$q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage,allow_empty) SELECT region_id,'running',?,coverage,true FROM import_jobs WHERE id=? RETURNING *");
$q->execute([__DIR__.'/fixture.osm',$activeJob]);$cancelImport=$q->fetch(PDO::FETCH_ASSOC);
$db->exec('UPDATE regions SET active_job_id='.(int)$activeJob.' WHERE id='.(int)$region);
$cancelDb=SweepLine\Database::connect();$cancelQueue=new SweepLine\DataQueue($cancelDb);$requested=false;
try {
    (new SweepLine\Importer($db,function($lock=false) use ($cancelQueue,$cancelDb,$cancelImport,&$requested): void {
        $progress=$cancelDb->query('SELECT progress FROM import_jobs WHERE id='.(int)$cancelImport['id'])->fetchColumn();
        if (!$requested && ($lock || str_contains($progress,'Building connectivity'))) { $cancelQueue->cancel((int)$cancelImport['id'],true);$requested=true; }
    }))->run($cancelImport);
    check(false,'Importer honours cancellation');
} catch (SweepLine\JobCancelled $e) { check(true,'Importer honours cancellation'); }
check((int)$db->query('SELECT active_job_id FROM regions WHERE id='.(int)$region)->fetchColumn()===$activeJob,'Cancelled import preserves previous active data');
$db->exec('UPDATE regions SET active_job_id=null WHERE id='.(int)$region);
check($db->query('SELECT status FROM import_jobs WHERE id='.(int)$cancelImport['id'])->fetchColumn()==='failed','Cancelled manual import leaves running state');

check((int)$db->query('SELECT count(*) FROM data_tasks WHERE id='.$cancelId)->fetchColumn()===0,'Worker acknowledgement physically removes running cancellation');
$legacy=$queue->ensure('auto-legacy-cancelled','roads',['key'=>'auto-legacy-cancelled','bounds'=>[-120,39.5,-119.5,40]]);
$db->exec("UPDATE data_tasks SET status='cancelled',cancel_requested=true WHERE id=".(int)$legacy['id']);
$queue->purgeCancelled();
check((int)$db->query("SELECT count(*) FROM data_tasks WHERE task_key='auto-legacy-cancelled'")->fetchColumn()===0,'Previously cancelled tasks are purged');
check($queue->ensure('auto-legacy-cancelled','roads',['key'=>'auto-legacy-cancelled','bounds'=>[-120,39.5,-119.5,40]])['status']==='queued','Previously cancelled area can be queued again');
$db->exec("UPDATE data_tasks SET status='complete' WHERE task_key='auto-legacy-cancelled'");
check((int)$db->query('SELECT count(*) FROM import_jobs WHERE id='.(int)$cancelImport['id'])->fetchColumn()===0,'Cancelled inactive manual imports are removed');
