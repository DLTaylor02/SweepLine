<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$db=SweepLine\Database::connect();
if (!$db->query('SELECT pg_try_advisory_lock(73019422)')->fetchColumn()) { exit("Another data worker is active\n"); }
$worker=new SweepLine\DataWorker($db);$worker->heartbeat('Starting worker recovery');
SweepLine\BeamFailure::install($db);
$exports=new SweepLine\BeamJobs($db);$exports->recover();$exports->maintain();
$worker->recover(); $worker->maintain();
$cleanup=new SweepLine\ImportCleanup($db);$regions=new SweepLine\RegionData($db);

$once=in_array('--once',$argv,true); $lastMaintenance=time();
do {
    $worker->heartbeat('Checking queued work');
    $regionWork=$regions->runOne();
    $priority=$db->query("SELECT EXISTS(SELECT 1 FROM data_tasks WHERE manual_priority AND status IN ('queued','retry') AND NOT cancel_requested AND next_attempt_at<=now())")->fetchColumn();
    $handled=($priority&&$worker->runOne()) || $exports->runOne() || $worker->runOne() || $regionWork;
    if (!$handled) {
        // Retain the explicit local import tools for diagnostics and advanced provisioning.
        $db->beginTransaction();
        $job=$db->query("SELECT * FROM import_jobs WHERE status='queued' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($job) { $db->prepare("UPDATE import_jobs SET status='running',started_at=now() WHERE id=?")->execute([$job['id']]); }
        $db->commit();
        if ($job) { try { (new SweepLine\Importer($db))->run($job); } catch (Throwable $e) { error_log($e->getMessage()); if ($once) { exit(1); } } }
        else {
            $pruned=$cleanup->runOne();
            if(!$once)sleep($pruned?1:3);
        }
    }
    if (time()-$lastMaintenance>3600) { $worker->maintain();$lastMaintenance=time(); }
} while (!$once);
