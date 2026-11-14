<?php
(function()use($db,$api,$mainId):void{
    $plan=\SweepLine\BeamPlan::project($db,$api->exportRoad((int)$mainId,'open'),\SweepLine\BeamPlan::options([]));
    $dir=sys_get_temp_dir().'/sl-signs-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    try{
        $download=static function($url,$file,$limit,$post,$progress):void{
            parse_str($post,$query);check(str_contains($query['data'],'node[traffic_sign]')&&!str_contains($query['data'],'way['),'Sign acquisition requests explicit physical nodes only');
            file_put_contents($file,json_encode(['elements'=>[['type'=>'node','id'=>99001,'lon'=>-119.8001,'lat'=>39.51,'timestamp'=>'2026-01-01T00:00:00Z','tags'=>['traffic_sign'=>'US:W1-2L','direction'=>'270']]]],JSON_THROW_ON_ERROR));
        };
        $data=\SweepLine\BeamSigns::fetch($db,$plan,$dir,static function($m){},$download);
        check($data['fetch_status']==='complete'&&count($data['signs'])===1&&count($data['signs'][0]['point'])===2,'Native PostGIS projects real sign coordinates without ambiguous parameter types');
        check(abs($data['signs'][0]['grid_bearing']-270)<5&&$data['signs'][0]['bearing']===270.,'Native projection retains source bearing and compensates grid convergence');
        $failed=\SweepLine\BeamSigns::fetch($db,$plan,$dir,static function($m){},static function(){throw new \SweepLine\DownloadFailure('Offline failure fixture',false);});
        check($failed['fetch_status']==='unavailable'&&$failed['signs']===[]&&!empty($failed['warning']),'Sign provider failure is explicit and does not fabricate signs');
    }finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
})();
