<?php
require_once __DIR__.'/scene-archive.php';
if(!function_exists('slNetworkStructuresFixture'))require __DIR__.'/network-structures.php';
(function():void{
    [$plan,$raster]=slNetworkStructuresFixture();$file=tempnam(sys_get_temp_dir(),'sl-network-structures-');
    try{
        $report=SweepLine\BeamMap::build($plan,$raster,str_repeat('9',32),$file,static fn($m)=>null);
        $zip=new ZipArchive();$zip->open($file);$base='levels/sweepline_9999999999999999/';
        check(count($report['intersection_context']['structures'])===1&&count($report['tunnel_data']['spans'])===1,'Final ZIP report includes ramp bridge and tunnel');
        check($zip->getFromName($base.'art/shapes/intersection_context_0-deck.dae')!==false,'Ramp bridge includes an underside deck shell');
        check($zip->getFromName($base.'art/tunnels/interior.dae')!==false&&$report['tunnel_data']['hole_cells']>0,'Ramp tunnel includes an interior and actual native terrain openings');
        $items=slSceneText($zip,$base);$objects=array_map(static fn($s)=>json_decode($s,true),array_filter(explode("\n",$items)));
        check(count(array_filter($objects,static fn($o)=>str_contains($o['name']??'','intersection_context_0_bridge_')&&($o['collisionType']??'')==='Visible Mesh Final'))>0,'Ramp bridge barriers and supports have collision');
        check($report['intersection_context']['network_manifest']['missing_context_ids']===[],'No structured network edge disappears during ZIP generation');
    }finally{if(isset($zip))$zip->close();if(is_file($file))unlink($file);}
})();
