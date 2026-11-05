<?php
// Offline process-pool integration: real short-lived PHP helpers, no database/network.
use SweepLine\{BeamCompute,BeamComputeTask,BeamRaster,BeamTerrain,BeamPng,JobCancelled};
check(BeamCompute::capacity(8,4096,'auto')===4&&BeamCompute::capacity(2,4096,'auto')===1,'Automatic CPU budget caps at four helpers and leaves one core available');
check(BeamCompute::capacity(8,768,'8')===1&&BeamCompute::capacity(8,1024,'8')===2,'Memory budget limits helper concurrency before spawning processes');
check(BeamCompute::capacity(8,4096,'1')===1&&BeamCompute::capacity(8,4096,'invalid')===1,'Explicit serial mode and invalid worker settings safely use one process');
$dir=sys_get_temp_dir().'/sl-compute-'.bin2hex(random_bytes(4));mkdir($dir,0700);
try{
    $n=32;$raster=BeamRaster::fromElevation($n,256,str_repeat(pack('g',100),64),str_repeat(chr(75),1024).str_repeat(chr(110),1024).str_repeat(chr(65),1024),8,static fn($m)=>null);
    $restored=BeamRaster::restore($raster->snapshot());
    check($restored->elevationResolution()===8&&$restored->roadHeight(57,80)===$raster->roadHeight(57,80)&&$restored->colors===$raster->colors,'Worker snapshots preserve original elevation resolution and color band order');
    $serial=new BeamCompute($dir,static fn()=>null,1);$parallel=new BeamCompute($dir,static fn()=>null,2);
    $tasks=['first'=>['kind'=>'classify_rows','start'=>0,'end'=>16],'second'=>['kind'=>'classify_rows','start'=>16,'end'=>32]];
    $shared=['raster'=>$raster->snapshot(),'center'=>[-85,40]];
    $a=$serial->map($tasks,$shared,static fn($t)=>BeamComputeTask::run($shared,$t));$b=$parallel->map($tasks,$shared,static fn($t)=>BeamComputeTask::run($shared,$t));
    check($a===$b&&array_keys($b)===['first','second']&&strlen(implode('',$b))===1024,'Parallel classification rows are byte-identical to serial output and remain in source order');
    check($parallel->report()['peak_children']===2&&$parallel->report()['parallel_tasks']===2,'A single export actually launches concurrent CPU helpers');
    $tasks=[['kind'=>'aerial_png','size'=>32],['kind'=>'constant_png','size'=>64,'rgb'=>[128,128,255]]];
    $a=$serial->map($tasks,$shared,static fn($t)=>BeamComputeTask::run($shared,$t));$b=$parallel->map($tasks,$shared,static fn($t)=>BeamComputeTask::run($shared,$t));
    check($a===$b,'Parallel PNG output is byte-identical to the serial encoder');
    check(glob($dir.'/*')===[],'Successful parallel phases remove their private inputs, logs and outputs');
    $failed=false;try{$parallel->map([['kind'=>'invalid'],['kind'=>'constant_png','size'=>1024,'rgb'=>[1,2,3]]],[],static fn($t)=>'');}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'Parallel terrain task failed');}
    check($failed&&glob($dir.'/*')===[],'Failed helpers stop the batch and clean private artifacts');
    $cancel=null;$cancel=new BeamCompute($dir,static function()use(&$cancel){if($cancel&&$cancel->report()['peak_children']>0)throw new JobCancelled('Fixture cancellation');},2);
    $cancelled=false;try{$cancel->map([['kind'=>'constant_png','size'=>2048,'rgb'=>[1,2,3]],['kind'=>'constant_png','size'=>2048,'rgb'=>[2,3,4]]],[],static fn($t)=>'');}catch(JobCancelled){$cancelled=true;}
    check($cancelled&&glob($dir.'/*')===[],'Cancellation terminates active helpers and removes their working files');
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}

$dir=sys_get_temp_dir().'/sl-compute-grid-'.bin2hex(random_bytes(4));mkdir($dir,0700);
try{
    $colors=['','',''];for($y=0;$y<256;$y++)for($x=0;$x<256;$x++){foreach(($x<128?[65,110,55]:[150,145,135]) as $c=>$v)$colors[$c].=chr($v);}
    $gridRaster=new BeamRaster(256,256,str_repeat(pack('g',100),65536),implode('',$colors));
    $building=[['points'=>[[100,120],[140,120],[140,135],[100,135]]]];
    $serialResult=BeamTerrain::classify($gridRaster,[-85,40],$building,static fn($m)=>null);
    $pool=new BeamCompute($dir,static fn()=>null,2);
    $parallelResult=BeamTerrain::classify($gridRaster,[-85,40],$building,static fn($m)=>null,$pool);
    check($serialResult===$parallelResult,'Full parallel classification preserves neighbor filtering, row boundaries and building masks exactly');
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
