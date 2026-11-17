<?php
(function():void{
    $raster=new SweepLine\BeamRaster(64,640,str_repeat(pack('g',120),4096),str_repeat(chr(100),12288));
    $road=[];for($x=50;$x<=600;$x+=10)$road[]=[$x,300,110];$profiles=array_fill(0,count($road),['width_m'=>8]);
    $span=['start_m'=>100.,'end_m'=>450.,'clearance_m'=>4.8];$track=['road'=>$road,'profiles'=>$profiles,'chain'=>range(0,550,10),'bridges'=>[],'tunnels'=>[$span]];
    $mask=SweepLine\BeamTrack::mask($track,$raster,str_repeat("\0",4096),static fn($m)=>null);
    $branch=['name'=>'diagnostic_road','osm_id'=>123,'network'=>true,'points'=>[[200,300,100],[400,300,100]],'profiles'=>[['width_m'=>8],['width_m'=>8]],'bridges'=>[],'tunnels'=>[]];
    $prepared=['network'=>true,'roads'=>[$branch],'aprons'=>[],'structure_mask'=>$mask,'graph'=>['network_edge_count'=>1],
        'main_surfaces'=>[['name'=>'primary','points'=>$road,'profiles'=>$profiles,'spans'=>[$span]]]];
    $capture=null;
    try{SweepLine\BeamIntersections::conform($prepared,$raster,0,static fn($m)=>null);}catch(SweepLine\BeamTerrainConflict $e){$capture=$e->diagnostic;}
    check($capture!==null&&count($capture['raster']['heights_m'])<=19,'Actual terrain-clearance exception captures a bounded local snapshot');
    $hit=SweepLine\BeamTerrainDiagnostic::replay($capture);
    check($hit!==null&&$hit['overlap_m']>0&&SweepLine\BeamTerrainDiagnostic::replay($capture,true)!==null,'Captured tunnel conflict reproduces offline and remains protected during underpass repair');
    check(array_sum(array_map('array_sum',$capture['raster']['exact_protected']))>0&&count($capture['nearby_surfaces'])>0,'Diagnostic records the actual protection flags and nearby structure geometry');
    $file=tempnam(sys_get_temp_dir(),'sl-terrain-diagnostic-');
    try{
        SweepLine\BeamTerrainDiagnostic::write($file,$capture,['name'=>'Test','epsg'=>32611,'bounds'=>[0,0,640,640],'points'=>[[200,300],[400,300]],'env'=>['password'=>'PRIVATE-SENTINEL'],'mapillary_access_token'=>'PRIVATE-SENTINEL'],'Terrain conflict');
        $zip=new ZipArchive();$zip->open($file);$json=$zip->getFromName('diagnostic.json');$zip->close();
        $data=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        check(!str_contains($json,'PRIVATE-SENTINEL')&&$data['selection']['start']===[200,300],'ZIP retains selected road coordinates while excluding unapproved configuration and credentials');
        check(SweepLine\BeamTerrainDiagnostic::replay($data)!==null,'Serialized diagnostic ZIP retains replayable floating-point geometry');
    }finally{if(is_file($file))unlink($file);}
})();

(function():void{
    $data=json_decode(file_get_contents(__DIR__.'/fixtures/bridge-underpass-contact.json'),true,512,JSON_THROW_ON_ERROR);
    check(SweepLine\BeamTerrainDiagnostic::replay($data)!==null&&SweepLine\BeamTerrainDiagnostic::replay($data,true)===null,
        'An anonymized measured bridge-ground failure reproduces before correction and clears through the general underpass rule');
})();
