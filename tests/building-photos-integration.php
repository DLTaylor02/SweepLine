<?php
// Native PDO/PostGIS projection path with synthetic image content and no external requests.
(function(PDO $db):void{
    $old=$db->query("SELECT value FROM app_settings WHERE key='mapillary_access_token'")->fetchColumn();
    $db->prepare("INSERT INTO app_settings(key,value) VALUES ('mapillary_access_token',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute(['offline-building-photo-token']);
    $dir=sys_get_temp_dir().'/sl-building-photo-pg-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/cache',0700);
    try{
        $plan=['epsg'=>32616,'bounds'=>[700000,4500000,700250,4500250],'points'=>[[700000,4500100],[700200,4500100]],
            'building_data'=>['footprints'=>[['osm_id'=>'way/offline-fixture','points'=>[[40,120],[80,120],[80,140],[40,140]],'tags'=>['building'=>'house']]]]];
        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),4326))');
        $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>[[700050,4500100],[700060,4500100]]],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $coordinates=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];$calls=0;
        $transport=static function($endpoint,$params,$token,$progress)use($coordinates,&$calls){
            $calls++;check($endpoint==='images'&&$params['is_pano']==='true'&&count(explode(',',$params['bbox']))===4,'Native photo search supplies a geographic bbox and requests panoramas');
            $rows=[];foreach($coordinates as $i=>$xy)$rows[]=['id'=>(string)(101+$i),'computed_geometry'=>['type'=>'Point','coordinates'=>$xy],
                'computed_compass_angle'=>0,'camera_type'=>'spherical','thumb_1024_url'=>'https://scontent.example.fbcdn.net/fixture.jpg',
                'creator'=>['username'=>'Synthetic integration fixture'],'captured_at'=>1700000000000];
            return ['data'=>$rows];
        };
        $download=static function($url,$path){$im=imagecreatetruecolor(1024,512);imagefill($im,0,0,imagecolorallocate($im,180,170,150));imagepng($im,$path);imagedestroy($im);};
        $raster=new SweepLine\BeamRaster(128,250,str_repeat(pack('g',100),16384),str_repeat(chr(100),49152));
        SweepLine\BeamBuildingPhotos::fetch($db,$plan,$raster,$dir,static fn($m)=>null,$transport,$download,new SweepLine\BeamResourceCache($dir.'/cache'));
        check($calls===1&&$plan['building_data']['photo_analysis']['matched']===1,'Native PDO bbox and image projections produce a two-view building appearance match');
    }finally{
        if($old===false)$db->exec("DELETE FROM app_settings WHERE key='mapillary_access_token'");
        else $db->prepare("UPDATE app_settings SET value=? WHERE key='mapillary_access_token'")->execute([$old]);
        foreach(glob($dir.'/*') as $entry){if(is_dir($entry)){foreach(glob($entry.'/*') as $file)unlink($file);rmdir($entry);}else unlink($entry);}rmdir($dir);
    }
})($db);
