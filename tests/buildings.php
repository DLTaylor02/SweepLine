<?php
// Offline building-provider retry tests. No network requests or real delays.
$file=tempnam(sys_get_temp_dir(),'sl-building-retry-');
try{
    $attempts=0;$waits=0;$messages=[];
    $transport=static function($url,$path,$limit,$post,$progress)use(&$attempts){
        if(++$attempts<3)throw new SweepLine\DownloadFailure('Synthetic HTTP 504',true);
        file_put_contents($path,'{"elements":[]}');
    };
    $data=SweepLine\BeamBuildings::downloadBuildings('https://example.invalid',$file,'query',static function($m)use(&$messages){$messages[]=$m;},$transport,static function()use(&$waits){$waits++;});
    check($data===['elements'=>[]]&&$attempts===3&&$waits===15,'Building fetch recovers on third attempt after 5 and 10 second waits');
    check(count(array_filter($messages,static fn($m)=>is_string($m)&&str_contains($m,'retrying')))===2,'Building retry progress explains each wait');
    $attempts=0;$waits=0;
    try{
        SweepLine\BeamBuildings::downloadBuildings('https://example.invalid',$file,'query',static fn($m)=>null,static function()use(&$attempts){$attempts++;throw new SweepLine\DownloadFailure('Synthetic timeout',true);},static function()use(&$waits){$waits++;});
        check(false,'Building retry budget must end');
    }catch(SweepLine\DownloadFailure $e){check($attempts===3&&$waits===15&&str_contains($e->getMessage(),'after 3 attempts'),'Building provider exhaustion stops with an explanatory error');}
    $attempts=0;
    try{
        SweepLine\BeamBuildings::downloadBuildings('https://example.invalid',$file,'query',static fn($m)=>null,static function()use(&$attempts){$attempts++;throw new SweepLine\DownloadFailure('Synthetic invalid request',false);},static function(){throw new RuntimeException('Should not wait');});
        check(false,'Permanent building error must stop');
    }catch(SweepLine\DownloadFailure $e){check($attempts===1&&!$e->retryable,'Permanent building download errors are not retried');}
    $attempts=0;
    $data=SweepLine\BeamBuildings::downloadBuildings('https://example.invalid',$file,'query',static fn($m)=>null,static function($url,$path)use(&$attempts){file_put_contents($path,++$attempts===1?'{"remark":"runtime error: Query timed out","elements":[]}':'{"elements":[]}');},static fn()=>null);
    check($attempts===2&&$data===['elements'=>[]],'HTTP-success Overpass timeout response is retried rather than treated as empty coverage');
    $attempts=0;
    try{
        SweepLine\BeamBuildings::downloadBuildings('https://example.invalid',$file,'query',static function($m){if($m===null)throw new SweepLine\JobCancelled('Test cancellation');},static function()use(&$attempts){$attempts++;throw new SweepLine\DownloadFailure('Synthetic timeout',true);},static fn()=>null);
        check(false,'Cancellation must escape building retry');
    }catch(SweepLine\JobCancelled $e){check($attempts===1,'Cancellation interrupts retry backoff before another provider request');}
}finally{unlink($file);}
