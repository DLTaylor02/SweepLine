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
check(count($api->search(['zip'=>'12345'])['results'])===2,'Overlapping automatic and manual cells do not duplicate identical roads');
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
