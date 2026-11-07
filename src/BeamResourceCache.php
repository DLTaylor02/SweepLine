<?php
declare(strict_types=1);
namespace SweepLine;

/** Private, immutable one-week cache of validated external BeamNG inputs. */
final class BeamResourceCache
{
    public const TTL=604800;
    public function __construct(private ?string $root=null,private ?\Closure $clock=null){}
    private function root(): string {return $this->root??SWEEPLINE_ROOT.'/var/beam-resources';}
    private function now(): int {return $this->clock?($this->clock)():time();}
    public static function key(string $kind,string $url,?string $body=null): string {return hash('sha256','beam-input-v1'.json_encode([$kind,$url,$body],JSON_THROW_ON_ERROR));}
    public function fetch(string $key,string $destination,callable $produce,callable $validate,callable $progress): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new \InvalidArgumentException('Invalid resource cache key.');
        $root=$this->root();if(is_link($root))throw new \RuntimeException('Refusing a symlinked resource cache.');
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new \RuntimeException('Cannot create private resource cache.');
        if(is_link($destination))throw new \RuntimeException('Refusing a symlinked resource destination.');
        $base=$root.'/'.$key;foreach(['.lock','.data','.json'] as $suffix)if(is_link($base.$suffix))throw new \RuntimeException('Refusing a symlinked cached resource.');
        $lock=fopen($base.'.lock','c');if(!$lock)throw new \RuntimeException('Cannot lock resource cache.');chmod($base.'.lock',0600);$tmp=null;$metaTmp=null;$start=time();
        try{
            while(!flock($lock,LOCK_EX|LOCK_NB)){if(time()-$start>60)throw new \RuntimeException('Resource cache is busy; try again shortly.');$progress(null);usleep(100000);}
            clearstatcache(true,$base.'.data');$meta=is_file($base.'.json')?json_decode(file_get_contents($base.'.json'),true):null;
            if(is_array($meta)&&is_int($meta['created_at']??null)&&is_int($meta['bytes']??null)&&is_string($meta['sha256']??null)&&$meta['created_at']<=$this->now()&&$meta['created_at']+self::TTL>$this->now()&&is_file($base.'.data')&&filesize($base.'.data')===$meta['bytes']&&hash_file('sha256',$base.'.data')===$meta['sha256']){
                try{$validate($base.'.data');$valid=true;}catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$valid=false;}
                if($valid){$progress('Using cached BeamNG source resource');if(!copy($base.'.data',$destination))throw new \RuntimeException('Cannot copy cached resource into export workspace.');return $meta+['cache_hit'=>true,'fetched_utc'=>gmdate('c',$meta['created_at'])];}
            }
            $tmp=tempnam($root,'input-');if($tmp===false)throw new \RuntimeException('Cannot allocate cached resource.');chmod($tmp,0600);
            $produce($tmp);$validate($tmp);clearstatcache(true,$tmp);
            $meta=['created_at'=>$this->now(),'ttl'=>self::TTL,'bytes'=>filesize($tmp),'sha256'=>hash_file('sha256',$tmp)];
            $metaTmp=tempnam($root,'meta-');if($metaTmp===false||file_put_contents($metaTmp,json_encode($meta,JSON_THROW_ON_ERROR))===false)throw new \RuntimeException('Cannot record source cache metadata.');chmod($metaTmp,0600);
            if(!rename($tmp,$base.'.data'))throw new \RuntimeException('Cannot publish cached source resource.');$tmp=null;
            if(!rename($metaTmp,$base.'.json'))throw new \RuntimeException('Cannot publish source metadata.');$metaTmp=null;
            if(!copy($base.'.data',$destination))throw new \RuntimeException('Cannot copy downloaded resource into export workspace.');
            return $meta+['cache_hit'=>false,'fetched_utc'=>gmdate('c',$meta['created_at'])];
        }finally{if($tmp&&is_file($tmp))unlink($tmp);if($metaTmp&&is_file($metaTmp))unlink($metaTmp);flock($lock,LOCK_UN);fclose($lock);}
    }
    public function prune(): int
    {
        $root=$this->root();if(!is_dir($root)||is_link($root))return 0;$removed=0;
        foreach(glob($root.'/*.json') as $file){
            $key=basename($file,'.json');if(!preg_match('/^[a-f0-9]{64}$/D',$key)||is_link($file)||is_link($root.'/'.$key.'.lock'))continue;
            $lock=fopen($root.'/'.$key.'.lock','c');if(!$lock)continue;
            try{
                if(!flock($lock,LOCK_EX|LOCK_NB))continue;
                $meta=json_decode(file_get_contents($file),true);if(is_array($meta)&&($meta['created_at']??0)+self::TTL>$this->now())continue;
                foreach(['.data','.json'] as $suffix){$path=$root.'/'.$key.$suffix;if(is_file($path)&&!is_link($path))unlink($path);}$removed++;
            }finally{flock($lock,LOCK_UN);fclose($lock);}
        }
        foreach(glob($root.'/*.data') as $file){
            $key=basename($file,'.data');if(!preg_match('/^[a-f0-9]{64}$/D',$key)||is_link($file)||is_file($root.'/'.$key.'.json')||filemtime($file)>$this->now()-self::TTL||is_link($root.'/'.$key.'.lock'))continue;
            $lock=fopen($root.'/'.$key.'.lock','c');if(!$lock)continue;
            try{if(flock($lock,LOCK_EX|LOCK_NB)&&!is_file($root.'/'.$key.'.json')&&is_file($file)){unlink($file);$removed++;}}finally{flock($lock,LOCK_UN);fclose($lock);}
        }
        foreach(array_merge(glob($root.'/input-*'),glob($root.'/meta-*')) as $file)if(is_file($file)&&!is_link($file)&&filemtime($file)<$this->now()-86400)unlink($file);
        return $removed;
    }
}
