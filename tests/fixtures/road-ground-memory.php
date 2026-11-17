<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use SweepLine\{BeamRaster,BeamRoadGround};
$resident=str_repeat('x',50*1048576);
$n=128;$raster=new BeamRaster($n,1280.,str_repeat(pack('g',0.),$n*$n),str_repeat(chr(100),$n*$n*3));
$layers=str_repeat("\0",$n*$n);
$sources=static function():Generator{
    for($i=0;$i<32;$i++){
        $x=20.+$i*38.;$road=[];for($y=20.;$y<=1220.;$y+=20.)$road[]=[$x,$y,5.+$i*.01];
        yield ['road'=>$road,'profiles'=>array_fill(0,count($road),['width_m'=>4.]),'spans'=>[]];
    }
};
$files=0;$bytes=0;$baseline=memory_get_usage(true);$start=microtime(true);
try{
    $r=BeamRoadGround::write(iterator_to_array($sources()),$raster,0.,$layers,$layers,'stock','memory','/levels/test/',
        static function($name,$data)use(&$files,&$bytes){$files++;$bytes+=strlen($data);},static fn($m)=>null);
    echo json_encode(['cells'=>$r['report']['replacement_cells'],'triangles'=>$r['report']['triangles'],'files'=>$files,'bytes'=>$bytes,
        'extra_peak'=>memory_get_peak_usage(true)-$baseline,'baseline'=>$baseline,'elapsed'=>microtime(true)-$start])."\n";
}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");exit(1);}
if(strlen($resident)!==50*1048576)exit(2);
