<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

// Independent from imports/exports: bound discovery concurrency to one CPU lane.
$db=SweepLine\Database::connect();
if(!$db->query('SELECT pg_try_advisory_lock(73019425)')->fetchColumn())exit("Another search worker is active\n");
$searches=new SweepLine\SearchJobs($db);
$searches->recover();$searches->maintain();
$once=in_array('--once',$argv,true);$maintenance=time();
do{
    if(!$searches->runOne()&&!$once)sleep(1);
    if(time()-$maintenance>3600){$searches->maintain();$maintenance=time();}
}while(!$once);
