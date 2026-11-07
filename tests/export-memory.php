<?php
use SweepLine\{BeamGroundMaterials,BeamFailure};
check(BeamGroundMaterials::batchSize('512M',100*1048576)===10000,'Terrain serializer retains bounded throughput with ample heap');
check(BeamGroundMaterials::batchSize('512M',460*1048576)<4000,'Terrain serialization reduces batches under heap pressure');
check(BeamGroundMaterials::batchSize('-1',0)===10000,'Unlimited PHP heap still uses bounded terrain mesh parts');
$stopped=false;try{BeamGroundMaterials::batchSize('512M',500*1048576);}catch(RuntimeException $e){$stopped=str_contains($e->getMessage(),'stopped safely');}
check($stopped,'Insufficient serialization headroom fails safely rather than exhausting PHP');
check(!str_contains(BeamFailure::message('secret internal error','Meshing'),'secret'),'Public fatal export messages do not expose raw PHP exception text');
foreach(['memory','fatal'] as $mode){
 $file=tempnam(sys_get_temp_dir(),'sl-export-fatal-');$pipes=[];
 try{
  $process=proc_open([PHP_BINARY,'-d','memory_limit=16M',__DIR__.'/fixtures/export-fatal.php',$file,$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
  if(!is_resource($process))throw new RuntimeException('Cannot start fatal export regression fixture.');
  fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
  $lines=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if(!$lines)fwrite(STDERR,'Fatal export fixture ('.$mode.') saved no status; child exit '.$exit.': '.substr(trim($out."\n".$err),0,2000)."\n");$saved=$lines?json_decode($lines[0],true,512,JSON_THROW_ON_ERROR):[];
  check($exit!==0&&count($lines)===1&&($saved[1]??null)===str_repeat('a',32),'Fatal '.$mode.' persists the active job status through one worker shutdown hook');
  check(str_contains($saved[0]??'','Serializing terrain')&&str_contains($saved[0]??'',$mode==='memory'?'memory limit':'PHP fatal error'),'Fatal '.$mode.' exposes the stage and failure category without a generic restart message');
 }finally{unlink($file);}
}
