<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded optional OSM context downloads, with cancellation-aware transient retries. */
final class BeamOsm
{
    /** Never expose provider bodies, URLs or credentials in public diagnostics. */
    public static function failureReason(\Throwable $e): string
    {
        $message=$e->getMessage();
        if(preg_match('/(?:HTTP(?: status)?|error:)\\s*([45][0-9]{2})/i',$message,$m))return 'HTTP '.$m[1];
        foreach(['timeout'=>'/timed? ?out|timeout/i','DNS lookup failed'=>'/resolve host/i','connection failed'=>'/couldn.t connect|failed to connect/i','response exceeded size limit'=>'/safe download limit/i','incomplete provider response'=>'/incomplete response/i','invalid JSON response'=>'/JSON|syntax error/i'] as $reason=>$pattern)if(preg_match($pattern,$message))return $reason;
        return $e instanceof DownloadFailure?'provider request failed':'response or local processing failed';
    }
    public static function download(string $url,string $file,string $query,callable $progress,?callable $download=null,?callable $sleep=null,string $label='buildings'): array
    {
        if($download===null){
            (new BeamResourceCache())->fetch(BeamResourceCache::key('osm',$url,$query),$file,
                static fn($path)=>self::download($url,$path,$query,$progress,[Download::class,'file'],$sleep,$label),
                static function($path){
                    $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
                    if(!is_array($data)||isset($data['remark'])||!isset($data['elements'])||!is_array($data['elements']))throw new \RuntimeException('Cached OSM context is incomplete.');
                },$progress);
            return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        }
        $sleep??=static fn()=>usleep(1000000);$provider=ucfirst(rtrim($label,'s')).' provider';
        for($attempt=1;$attempt<=3;$attempt++){
            $progress('Fetching mapped '.$label.' (attempt '.$attempt.'/3)');
            try{
                $download($url,$file,16777216,$query,$progress);
                try{$data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}
                catch(\JsonException $e){throw new DownloadFailure('Invalid JSON response.',true);}
                if(isset($data['remark'])){
                    $temporary=(bool)preg_match('/timed? ?out|timeout|rate.?limit|too many requests|server.*busy/i',(string)$data['remark']);
                    throw new DownloadFailure($provider.' returned an incomplete response.',$temporary);
                }
                if(!is_array($data)||!isset($data['elements'])||!is_array($data['elements']))throw new DownloadFailure($provider.' returned an invalid JSON response.',true);
                return $data;
            }catch(DownloadFailure $e){
                $reason=self::failureReason($e);
                if(!$e->retryable||$attempt===3){
                    $message=$provider.($e->retryable?' exhausted 3 attempts: ':' stopped on attempt '.$attempt.'/3 (non-retryable): ').$reason;
                    $progress($message);
                    // Retain legacy wording used by callers and diagnostics.
                    if($e->retryable)$message=$provider.' remained unavailable after 3 attempts: '.$reason;
                    throw new DownloadFailure($message,$e->retryable);
                }
                $seconds=$attempt*5;$progress($provider.' temporarily unavailable ('.$reason.'); retrying in '.$seconds.' seconds (attempt '.($attempt+1).'/3)');
                for($i=0;$i<$seconds;$i++){$progress(null);$sleep();}
            }
        }
        throw new \LogicException('OSM retry loop did not finish.');
    }
}
