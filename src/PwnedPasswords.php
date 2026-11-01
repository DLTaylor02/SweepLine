<?php
declare(strict_types=1);
namespace SweepLine;
/** Sends only a SHA-1 prefix. Never logs or persists passwords or hashes. */
final class PwnedPasswords
{
    public function __construct(private ?\Closure $fetch=null) {}
    public function compromised(string $password): ?bool
    {
        $hash=strtoupper(sha1($password));
        try {
            $body=$this->fetch?($this->fetch)(substr($hash,0,5)):self::range(substr($hash,0,5));
            if (!is_string($body) || $body==='' || strlen($body)>1048576) {return null;}
            $found=false;$valid=0;
            foreach (preg_split('/\r?\n/',$body) as $line) {
                if ($line==='') {continue;}
                if (!preg_match('/^([A-F0-9]{35}):([0-9]+)$/D',strtoupper($line),$m)) {return null;}
                $valid++;
                if (hash_equals(substr($hash,5),$m[1]) && (float)$m[2]>0) {$found=true;}
            }
            return $valid?$found:null;
        } catch (\Throwable $e) {return null;}
    }
    private static function range(string $prefix): string
    {
        $curl=curl_init('https://api.pwnedpasswords.com/range/'.$prefix);$body='';
        curl_setopt_array($curl,[
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_FAILONERROR=>true,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_USERAGENT=>'SweepLine password screening',CURLOPT_HTTPHEADER=>['Add-Padding: true'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body): int {
                if (strlen($body)+strlen($chunk)>1048576) {return 0;}
                $body.=$chunk;return strlen($chunk);
            }
        ]);
        try {
            if (!curl_exec($curl) || curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) {throw new \RuntimeException('Screening unavailable');}
            return $body;
        } finally {curl_close($curl);}
    }
}

