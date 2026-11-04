<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded optional OSM context downloads, with cancellation-aware transient retries. */
final class BeamOsm
{
    public static function download(string $url,string $file,string $query,callable $progress,?callable $download=null,?callable $sleep=null,string $label='buildings'): array
    {
        $sleep??=static fn()=>usleep(1000000);$provider=ucfirst(rtrim($label,'s')).' provider';
        for($attempt=1;$attempt<=3;$attempt++){
            $progress('Fetching mapped '.$label.' (attempt '.$attempt.'/3)');
            try{
                ($download??[Download::class,'file'])($url,$file,16777216,$query,$progress);
                $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                if(isset($data['remark'])){
                    $temporary=(bool)preg_match('/timed? ?out|timeout|rate.?limit|too many requests|server.*busy/i',(string)$data['remark']);
                    throw new DownloadFailure($provider.' returned an incomplete response.',$temporary);
                }
                if(!is_array($data)||!isset($data['elements'])||!is_array($data['elements']))throw new \RuntimeException($provider.' returned an invalid response.');
                return $data;
            }catch(DownloadFailure $e){
                if(!$e->retryable||$attempt===3)throw new DownloadFailure($e->retryable?$provider.' remained unavailable after 3 attempts.':$provider.' request failed without a retryable error.',$e->retryable);
                $seconds=$attempt*5;$progress($provider.' temporarily unavailable; retrying in '.$seconds.' seconds (attempt '.($attempt+1).'/3)');
                for($i=0;$i<$seconds;$i++){$progress(null);$sleep();}
            }
        }
        throw new \LogicException('OSM retry loop did not finish.');
    }
}
