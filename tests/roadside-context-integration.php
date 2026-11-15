<?php
(function()use($db,$api,$mainId):void{
    $plan=\SweepLine\BeamPlan::project($db,$api->exportRoad((int)$mainId,'open'),\SweepLine\BeamPlan::options([]));
    $dir=sys_get_temp_dir().'/sl-roadside-'.bin2hex(random_bytes(6));mkdir($dir,0700);
    try{
        $download=static function($url,$file,$limit,$post,$progress):void{
            parse_str($post,$query);check(str_contains($query['data'],'node[highway=street_lamp]')||str_contains($query['data'],'way[railway=rail]')||str_contains($query['data'],'node[highway=milestone]'),'Roadside acquisition requests explicit mapped infrastructure');
            file_put_contents($file,json_encode(['elements'=>[
                ['type'=>'node','id'=>99002,'lon'=>-119.8001,'lat'=>39.51,'tags'=>['amenity'=>'bench']],
                ['type'=>'way','id'=>99003,'geometry'=>[['lon'=>-119.8001,'lat'=>39.51],['lon'=>-119.8002,'lat'=>39.5101]],'tags'=>['barrier'=>'fence']]
            ]],JSON_THROW_ON_ERROR));
        };
        $data=\SweepLine\BeamRoadsideData::fetch($db,$plan,$dir,static function($m){},$download);
        check($data['fetch_status']==='complete'&&count($data['features'])===2&&count($data['features'][0]['points'])===1&&count($data['features'][1]['points'])===2,'Native PostGIS projects mixed roadside point and line geometry');
        $failed=\SweepLine\BeamRoadsideData::fetch($db,$plan,$dir,static function($m){},static function(){throw new \SweepLine\DownloadFailure('Offline fixture',false);});
        check($failed['fetch_status']==='unavailable'&&$failed['features']===[]&&!empty($failed['warning']),'Roadside provider failure warns without fabricated features');
    }finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
})();
