<?php
(function()use($db,$api,$mainId):void{

    $previous=$db->query("SELECT value FROM app_settings WHERE key='mapillary_access_token'")->fetchColumn();
    try{
        $settings=new \SweepLine\MapillarySettings($db);$saved=$settings->save(['access_token'=>'fixture-private-token']);
        check($saved['configured']&&!str_contains(json_encode($saved),'fixture-private-token')&&$settings->token()==='fixture-private-token','Mapillary settings persist a token without returning its value');
        $settings->save(['access_token'=>'']);check($settings->token()==='fixture-private-token','Blank token submission preserves the existing credential');
        $settings->save(['clear_token'=>true]);check(!$settings->read()['configured']&&$settings->token()==='','Removing token explicitly disables environment fallback');
    }finally{
        if($previous===false)$db->exec("DELETE FROM app_settings WHERE key='mapillary_access_token'");
        else $db->prepare("UPDATE app_settings SET value=? WHERE key='mapillary_access_token'")->execute([$previous]);
    }
    $saved=getenv('MAPILLARY_ACCESS_TOKEN');putenv('MAPILLARY_ACCESS_TOKEN=integration-fixture-token');
    $dir=sys_get_temp_dir().'/sl-mapillary-db-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/cache',0700);
    try{
        $plan=\SweepLine\BeamPlan::project($db,$api->exportRoad((int)$mainId,'open'),\SweepLine\BeamPlan::options([]));$calls=0;
        $result=\SweepLine\BeamMapillary::fetch($db,$plan,$dir,static fn($m)=>null,static function($params,$token,$progress)use(&$calls){$calls++;$box=array_map('floatval',explode(',',$params['bbox']));check($box[2]-$box[0]<=.0051&&$box[3]-$box[1]<=.0051,'Mapillary request bounds are clipped to narrow roadside regions');return ['data'=>[['id'=>'99004','object_type'=>'point','object_value'=>'object--bench','geometry'=>['type'=>'Point','coordinates'=>[-119.8001,39.51]]]]];},new \SweepLine\BeamResourceCache($dir.'/cache'));
        check($calls>0&&$result['status']==='complete'&&count($result['features'])===1&&count($result['features'][0]['points'][0])===2,'Native PostGIS queries Mapillary corridor tiles and projects normalized point geometry');
    }finally{
        putenv($saved===false?'MAPILLARY_ACCESS_TOKEN':'MAPILLARY_ACCESS_TOKEN='.$saved);
        foreach(glob($dir.'/cache/*') as $f)unlink($f);rmdir($dir.'/cache');foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);
    }
})();
