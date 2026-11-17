<?php
(function():void{
    $dir=sys_get_temp_dir().'/sl-region-fetch-'.bin2hex(random_bytes(4));mkdir($dir,0700);$file=$dir.'/region.osm';
    $xml='<osm version="0.6"><node id="1" lat="39" lon="-119"/><node id="2" lat="39.0001" lon="-119"/><way id="3"><nd ref="1"/><nd ref="2"/><tag k="highway" v="secondary"/></way></osm>';
    try{
        $calls=0;$wait=0;
        $result=SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function($url,$path)use(&$calls,$xml){if(++$calls<3)throw new SweepLine\DownloadFailure('Data provider HTTP 502',true);file_put_contents($path,$xml);},static fn($m)=>null,static function()use(&$wait){$wait++;});
        check($calls===3&&$wait===15&&$result['pieces']===1,'Region refresh retries transient failures with five- and ten-second cancellation-aware waits');
        $calls=0;$widths=[];
        $result=SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function($url,$path,$max,$post)use(&$calls,&$widths,$xml){
            $calls++;parse_str($post,$data);preg_match('/\]\(([-0-9.,]+)\);/',$data['data'],$m);$bbox=array_map('floatval',explode(',',$m[1]));$width=$bbox[3]-$bbox[1];$widths[]=$width;
            if($width>.06)throw new SweepLine\DownloadFailure('Data provider HTTP 502',true);
            file_put_contents($path,$xml);
        },static fn($m)=>null,static fn()=>null);
        $doc=simplexml_load_file($file);
        check($calls===7&&$result['pieces']===4&&count($doc->node)===2&&count($doc->way)===1,'Repeated gateway failures split into smaller complete source requests and deduplicate shared mapped identities');
        $result=SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.5,.5],static function($url,$path)use($xml){file_put_contents($path,$xml);},static fn($m)=>null,static fn()=>null);
        check($result['pieces']===16,'Large legacy regions split before requesting full geometry');
        $calls=0;$failed=false;
        try{SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function()use(&$calls){$calls++;throw new SweepLine\DownloadFailure('Data provider HTTP 400',false);},static fn($m)=>null,static fn()=>null);}catch(RuntimeException $e){$failed=true;}
        check($failed&&$calls===1&&!is_file($file)&&count(glob($dir.'/*'))===0,'Non-transient provider rejection stops immediately and removes incomplete source files');
        $cancelled=false;
        try{SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function(){throw new SweepLine\DownloadFailure('HTTP 502',true);},static function($message){if($message===null)throw new SweepLine\JobCancelled('Cancelled');},static fn()=>null);}catch(SweepLine\JobCancelled $e){$cancelled=true;}
        check($cancelled&&count(glob($dir.'/*'))===0,'Cancellation interrupts retry backoff and cleans temporary sources');
        $calls=0;$failed=false;
        try{SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function()use(&$calls){$calls++;throw new SweepLine\DownloadFailure('HTTP 502',true);},static fn($m)=>null,static fn()=>null);}catch(RuntimeException $e){$failed=true;}
        check($failed&&$calls<=48&&!is_file($file)&&count(glob($dir.'/*'))===0,'Persistent outage remains bounded and cannot publish an incomplete replacement');

        $many='<osm version="0.6">';for($i=1;$i<=8001;$i++)$many.='<way id="'.$i.'"/>';$many.='</osm>';$piece=0;
        $parts=SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.25,.25],static function($url,$path)use($many,&$piece){file_put_contents($path,str_replace('id="','id="'.(++$piece),$many));},static fn($m)=>null,static fn()=>null,true);
        $distinct=[];foreach($parts['chunks'] as $chunk){$r=new XMLReader();$r->open($chunk['path']);while($r->read())if($r->nodeType===XMLReader::ELEMENT&&$r->name==='way')$distinct[$r->getAttribute('id')]=true;$r->close();}
        check(count($parts['chunks'])===4&&count($distinct)===32004&&array_column($parts['chunks'],'tile_path')===['0','1','2','3'],'Dense source uses separate bounded imports even when unique combined way count exceeds 30,000');
        foreach($parts['chunks'] as $chunk)unlink($chunk['path']);

        $large='<osm version="0.6">'.str_repeat('<way id="9"/>',30001).'</osm>';$calls=0;$waits=0;
        $parts=SweepLine\BeamRegionDownload::fetch('https://example.test',$file,[0,0,.1,.1],static function($url,$path,$max,$post)use(&$calls,$large,$many){
            $calls++;parse_str($post,$request);preg_match('/\]\(([-0-9.,]+)\);/',$request['data'],$m);$bbox=array_map('floatval',explode(',',$m[1]));
            file_put_contents($path,$bbox[3]-$bbox[1]>.12?$large:$many);
        },static fn($m)=>null,static function()use(&$waits){$waits++;},true,.04);
        check($calls===5&&$waits===0&&count($parts['chunks'])===4,'Oversized source splits immediately and reduces child halos rather than retrying the same dense request');
        foreach($parts['chunks'] as $chunk)unlink($chunk['path']);
    }finally{foreach(glob($dir.'/*') as $path)unlink($path);rmdir($dir);}
})();
