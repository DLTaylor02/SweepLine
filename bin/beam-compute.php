<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
if(PHP_OS_FAMILY!=='Windows'&&function_exists('proc_nice'))@proc_nice(5);
try{
    if(count($argv)!==3||!ctype_digit($argv[2]))throw new InvalidArgumentException('Expected private state file and task index.');
    $state=unserialize(file_get_contents($argv[1]),['allowed_classes'=>false]);$i=(int)$argv[2];
    if(!is_array($state)||!isset($state['tasks'][$i],$state['outputs'][$i]))throw new InvalidArgumentException('Invalid terrain task.');
    $result=SweepLine\BeamComputeTask::run($state['shared'],$state['tasks'][$i]);
    if(file_put_contents($state['outputs'][$i],$result)===false)throw new RuntimeException('Cannot save terrain task result.');
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1);}
