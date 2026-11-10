<?php
declare(strict_types=1);
namespace SweepLine;

final class Download
{
    public static function file(string $url,string $path,int $maxBytes,?string $post=null,?callable $progress=null,int $timeout=240): void
    {
        if (!str_starts_with($url,'https://')) { throw new \RuntimeException('Data providers must use HTTPS.'); }
        $f=fopen($path.'.part','wb');
        if (!$f) { throw new \RuntimeException('Cannot write data download.'); }
        $curl=curl_init($url); $bytes=0; $last=0; $tooLarge=false;
        curl_setopt_array($curl,[
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>min(20,max(1,$timeout)), CURLOPT_TIMEOUT=>max(1,$timeout),
            CURLOPT_FAILONERROR=>true, CURLOPT_USERAGENT=>'SweepLine/0.2 (self-hosted road discovery)',
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use ($f,$maxBytes,&$bytes,&$tooLarge): int {
                $bytes+=strlen($chunk);
                if ($bytes>$maxBytes) { $tooLarge=true; return 0; }
                return fwrite($f,$chunk);
            },
            CURLOPT_NOPROGRESS=>false,
            CURLOPT_XFERINFOFUNCTION=>static function ($handle,$total,$received) use ($progress,&$last): int {
                if ($progress && time()-$last>=5) { $last=time(); $progress('Downloading '.round($received/1048576,1).' MB'); }
                return 0;
            },
        ]);
        if ($post!==null) { curl_setopt($curl,CURLOPT_POST,true); curl_setopt($curl,CURLOPT_POSTFIELDS,$post); }
        try {
            if (!curl_exec($curl)) {
                $http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
                $transient=!$tooLarge && (in_array($http,[408,429,500,502,503,504],true) || in_array(curl_errno($curl),[CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_CONNECT,CURLE_OPERATION_TIMEDOUT,CURLE_RECV_ERROR,CURLE_SEND_ERROR],true));
                throw new DownloadFailure($tooLarge?'Extract exceeds the safe download limit.':'Data provider unavailable: '.curl_error($curl),$transient);
            }
            if (curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200 || $bytes===0) { throw new \RuntimeException('Data provider returned an empty or unsuccessful response.'); }
            fclose($f); $f=null;
            if (!rename($path.'.part',$path)) { throw new \RuntimeException('Cannot finalize download.'); }
        } finally {
            curl_close($curl); if (is_resource($f)) { fclose($f); }
            if (is_file($path.'.part')) { unlink($path.'.part'); }
        }
    }
}
