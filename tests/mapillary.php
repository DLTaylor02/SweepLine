<?php
(function():void{
    $row=static fn($id,$value,$type='point')=>['id'=>(string)$id,'object_type'=>$type,'object_value'=>$value,'geometry'=>['type'=>'Point','coordinates'=>[-119.8,39.5]],'last_seen_at'=>1700000000000];
    $data=\SweepLine\BeamMapillary::normalize([$row(1,'regulatory--stop--g1','trafficsign'),$row(2,'object--support--utility-pole'),$row(3,'object--street-light'),$row(4,'object--bench'),$row(5,'object--bollard'),$row(6,'object--wire-group'),$row(1,'regulatory--stop--g1','trafficsign')]);
    check(count($data['signs'])===1&&$data['signs'][0]['code']==='R1-1'&&$data['signs'][0]['bearing']===null,'Mapillary positioned signs normalize with facing left for road-based inference');
    check(array_column($data['features'],'kind')===['power_pole','streetlight','bench','bollard'],'Supported Mapillary point classes use existing stock-model categories');
    check($data['unsupported_or_invalid']===2,'Duplicate and unsupported image-derived classes do not invent geometry');
    $bad=$row(8,'object--bench');$bad['geometry']['coordinates']=[INF,40];check(\SweepLine\BeamMapillary::normalize([$bad])['features']===[],'Invalid projected source coordinates are rejected');
    $speed=\SweepLine\BeamMapillary::normalize([$row(9,'regulatory--maximum-speed-limit-50--g1','trafficsign')]);
    check($speed['signs'][0]['speed']===null,'Numeric Mapillary classes do not silently assume mph units');
    $scrub=\SweepLine\BeamMapillary::sanitize(['data'=>[$row(1,'warning--curve-left--g1','trafficsign')],'paging'=>['next'=>'https://example.invalid/?access_token=SECRET','cursors'=>['after'=>'page2']]]);
    check(!str_contains(json_encode($scrub),'SECRET')&&$scrub['after']==='page2','Paging URLs and credentials are excluded from cached provider data');
    $osm=['id'=>'42','source'=>'OpenStreetMap','kind'=>'bench','points'=>[[10,10]]];
    $extra=['id'=>'mly_1','source'=>'Mapillary','kind'=>'bench','points'=>[[11,10]]];
    $merged=\SweepLine\BeamMapillary::append([$osm],[$extra],false);
    check(count($merged['items'])===1&&$merged['items'][0]['source']==='OpenStreetMap'&&$merged['duplicates']===1,'Matching nearby cross-provider objects prefer OSM positions');
    $extra['kind']='bollard';check(count(\SweepLine\BeamMapillary::append([$osm],[$extra],false)['items'])===2,'Different physical feature classes are not consolidated');
    $directory=sys_get_temp_dir().'/sl-mapillary-'.bin2hex(random_bytes(5));mkdir($directory,0700);mkdir($directory.'/cache',0700);$cache=new \SweepLine\BeamResourceCache($directory.'/cache');$calls=0;$paramsSeen=[];$token='test-token-'.bin2hex(random_bytes(4));
    try{
        $transport=static function($params,$secret,$progress)use(&$calls,&$paramsSeen,$row,$token){
            check($secret===$token&&!isset($params['access_token']),'Mapillary token is separate from URL query parameters');$calls++;$paramsSeen[]=$params;
            if(!isset($params['after']))return ['data'=>[$row(11,'object--bench')],'paging'=>['next'=>'https://graph.mapillary.com/?access_token='.$token,'cursors'=>['after'=>'next']]];
            return ['data'=>[$row(11,'object--bench'),$row(12,'regulatory--stop--g1','trafficsign')]];
        };
        $boxes=[[-119.81,39.49,-119.79,39.51]];
        $result=\SweepLine\BeamMapillary::collect($boxes,$directory,$token,static fn($m)=>null,$transport,static fn()=>null,$cache);
        check($calls===2&&count($result['signs'])===1&&count($result['features'])===1,'Mapillary pagination deduplicates stable feature IDs');
        check(($paramsSeen[1]['after']??null)==='next','Pagination reconstructs a fixed-host request from the cursor');
        $again=\SweepLine\BeamMapillary::collect($boxes,$directory,$token,static fn($m)=>null,static function(){throw new RuntimeException('Cache expected');},static fn()=>null,$cache);
        check($again['status']==='complete'&&count($again['features'])===1,'Validated Mapillary responses reuse the one-week resource cache');
        $clean=true;foreach(glob($directory.'/*.json') as $f)$clean=$clean&&!str_contains(file_get_contents($f),$token);foreach(glob($directory.'/cache/*') as $f)$clean=$clean&&!str_contains(file_get_contents($f),$token);
        check($clean,'Export workspace and cache bytes contain no raw access token');
        $attempts=0;
        $retried=\SweepLine\BeamMapillary::collect($boxes,$directory,'retry-token',static fn($m)=>null,static function()use(&$attempts){if(++$attempts<3)throw new \SweepLine\DownloadFailure('Mapillary HTTP 502',true);return ['data'=>[]];},static fn()=>null,$cache);
        check($attempts===3&&$retried['status']==='complete','Transient Mapillary failures retry within a finite budget');
        $attempts=0;$failed=\SweepLine\BeamMapillary::collect($boxes,$directory,'bad-token',static fn($m)=>null,static function()use(&$attempts){$attempts++;throw new \SweepLine\DownloadFailure('Mapillary HTTP 401',false);},static fn()=>null,$cache);
        check($attempts===1&&$failed['status']==='unavailable'&&$failed['requests'][0]['reason']==='HTTP 401','Invalid credentials stop promptly with safe diagnostics');
        $loop=\SweepLine\BeamMapillary::collect($boxes,$directory,'loop-token',static fn($m)=>null,static fn()=>['data'=>[],'paging'=>['next'=>'ignored','cursors'=>['after'=>'repeat']]],static fn()=>null,$cache);
        check($loop['truncated']&&count($loop['requests'])===2,'Repeated pagination cursors stop with incomplete coverage');
        $cancelled=false;try{\SweepLine\BeamMapillary::collect($boxes,$directory,'cancel-token',static fn($m)=>null,static function(){throw new \SweepLine\JobCancelled('fixture');},static fn()=>null,$cache);}catch(\SweepLine\JobCancelled $e){$cancelled=true;}
        check($cancelled,'Mapillary cancellation propagates rather than becoming an optional-provider warning');
        $plan=['traffic_sign_data'=>['signs'=>[],'fetch_status'=>'complete'],'roadside_data'=>['features'=>[],'fetch_status'=>'complete']];
        \SweepLine\BeamMapillary::apply($plan,$result);
        check(count($plan['traffic_sign_data']['signs'])===1&&count($plan['roadside_data']['features'])===1&&$plan['mapillary_data']['attribution']==='Mapillary','Normalized provider results enter normal export pipelines with attribution');
        $point=$data['features'][0];$point['points']=[[100,130]];
        $raster=new \SweepLine\BeamRaster(64,640,str_repeat(pack('g',10),4096),str_repeat(chr(100),12288));
        $rendered=\SweepLine\BeamRoadside::write(['features'=>[$point]],[['points'=>[[0,100,10],[600,100,10]],'profiles'=>array_fill(0,2,['width_m'=>8])]],$raster,0,'test','/levels/test/',static fn($n,$b)=>null,static fn($m)=>null);
        check($rendered['report']['placed_features']['power_pole']===1&&$rendered['report']['records'][0]['source']==='Mapillary','Mapillary poles use stock placement and retain provider provenance');
    }finally{foreach(glob($directory.'/cache/*') as $f)unlink($f);rmdir($directory.'/cache');foreach(glob($directory.'/*') as $f)unlink($f);rmdir($directory);}
})();

check(\SweepLine\MapillarySettings::validate('MLY|valid-example')==='MLY|valid-example','Mapillary settings accept a header-safe token');
foreach(["bad\r\nInjected: value",'token with spaces',str_repeat('x',4097),['secret']] as $bad)check(rejects(static fn()=>\SweepLine\MapillarySettings::validate($bad)),'Mapillary settings reject unsafe or oversized credentials without echoing them');
