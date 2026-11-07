<?php
require dirname(__DIR__,2).'/src/bootstrap.php';
$log=new SweepLine\BeamBuildLog();$shared=str_repeat('x',4096);
$before=memory_get_usage(true);
$log->write($argv[1],['sources'=>array_fill(0,8192,$shared)],[],[],[]);
echo json_encode(['bytes'=>filesize($argv[1]),'extra_peak'=>memory_get_peak_usage(true)-$before],JSON_THROW_ON_ERROR);
