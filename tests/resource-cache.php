<?php
use SweepLine\BeamResourceCache;
$cacheDirectory=sys_get_temp_dir().'/sl-resource-'.bin2hex(random_bytes(4));mkdir($cacheDirectory,0700);$now=1000000;$fetches=0;
$cache=new BeamResourceCache($cacheDirectory,static function()use(&$now){return $now;});$key=BeamResourceCache::key('fixture','https://example.invalid/data','query');$dest=$cacheDirectory.'/job-copy';
$produce=static function($path)use(&$fetches){$fetches++;file_put_contents($path,'{"elements":[]}');};
$validate=static function($path){$value=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!isset($value['elements']))throw new RuntimeException('Invalid fixture input');};
try{
 $first=$cache->fetch($key,$dest,$produce,$validate,static fn($m)=>null);$second=$cache->fetch($key,$dest,$produce,$validate,static fn($m)=>null);
 check($fetches===1&&!$first['cache_hit']&&$second['cache_hit']&&$first['sha256']===$second['sha256'],'Validated repeated BeamNG resource requests reuse one download');
 $now+=BeamResourceCache::TTL-1;$hit=$cache->fetch($key,$dest,$produce,$validate,static fn($m)=>null);
 check($hit['cache_hit']&&$fetches===1&&$hit['created_at']===$first['created_at'],'Cache reads do not extend the one-week resource lifetime');
 $now++;$fresh=$cache->fetch($key,$dest,$produce,$validate,static fn($m)=>null);
 check(!$fresh['cache_hit']&&$fetches===2&&$fresh['created_at']===$now,'Resources download again at the one-week boundary');
 file_put_contents($cacheDirectory.'/'.$key.'.data','corrupt');$repaired=$cache->fetch($key,$dest,$produce,$validate,static fn($m)=>null);
 check(!$repaired['cache_hit']&&$fetches===3,'Corrupt cached bytes are replaced instead of reused');
 $badKey=BeamResourceCache::key('fixture','https://example.invalid/bad');$rejected=false;
 try{$cache->fetch($badKey,$dest,static fn($path)=>file_put_contents($path,'bad'),$validate,static fn($m)=>null);}catch(JsonException $e){$rejected=true;}
 check($rejected&&!is_file($cacheDirectory.'/'.$badKey.'.json')&&glob($cacheDirectory.'/input-*')===[],'Invalid or interrupted downloads never become reusable cache entries');
 check(BeamResourceCache::key('dem','u','a')!==BeamResourceCache::key('aerial','u','a')&&BeamResourceCache::key('dem','u','a')!==BeamResourceCache::key('dem','u','b'),'Cache keys separate resource type and exact request body');
 $now+=BeamResourceCache::TTL;
 check($cache->prune()===1&&!is_file($cacheDirectory.'/'.$key.'.data')&&!is_file($cacheDirectory.'/'.$key.'.json'),'Scheduled expiry removes week-old source data and metadata');
}finally{foreach(glob($cacheDirectory.'/*') as $file)unlink($file);rmdir($cacheDirectory);}
