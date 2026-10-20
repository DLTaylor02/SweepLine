<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$db=SweepLine\Database::connect();
if (!$db->query('SELECT pg_try_advisory_lock(73019422)')->fetchColumn()) { exit("Another data worker is active\n"); }
$worker=new SweepLine\DataWorker($db); $worker->recover(); $worker->maintain();
$once=in_array('--once',$argv,true); $lastMaintenance=time();
do {
    $worker->heartbeat();
    $handled=$worker->runOne();
    if (!$handled) {
        // Retain the explicit local import tools for diagnostics and advanced provisioning.
        $db->beginTransaction();
        $job=$db->query("SELECT * FROM import_jobs WHERE status='queued' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($job) { $db->prepare("UPDATE import_jobs SET status='running',started_at=now() WHERE id=?")->execute([$job['id']]); }
        $db->commit();
        if ($job) { try { (new SweepLine\Importer($db))->run($job); } catch (Throwable $e) { error_log($e->getMessage()); if ($once) { exit(1); } } }
        elseif (!$once) { sleep(3); }
    }
    if (time()-$lastMaintenance>3600) { $worker->maintain(); $lastMaintenance=time(); }
} while (!$once);
