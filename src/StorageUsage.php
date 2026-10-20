<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class StorageUsage
{
    /** Persistent local filesystems only; repeated devices/bind mounts count once. */
    public static function volumes(string $output): array
    {
        $volumes=[];$seen=[];
        foreach (array_slice(explode("\n",trim($output)),1) as $line) {
            $parts=preg_split('/\s+/',trim($line),5);
            if (count($parts)!==5) { continue; }
            [$source,$type,$total,$free,$mount]=$parts;
            if (in_array($type,['tmpfs','devtmpfs','squashfs','iso9660','udf'],true)) { continue; }
            if (!str_starts_with($source,'/dev/') && !in_array($type,['zfs','overlay'],true)) { continue; }
            if (!ctype_digit($total) || !ctype_digit($free) || (float)$total<=0 || (float)$free>(float)$total) { continue; }
            // GNU df already suppresses duplicate device mounts. Source keys also
            // deduplicate repeated lines without probing private mount contents.
            $key=$type.':'.preg_replace('/\\[.*\\]$/','',$source);
            if (isset($seen[$key])) { continue; }
            $seen[$key]=true;
            $volumes[]=['mount'=>$mount,'total_bytes'=>(float)$total,'free_bytes'=>(float)$free];
        }
        return $volumes;
    }
    public static function summarize(float $database,array $volumes): array
    {
        $total=array_sum(array_column($volumes,'total_bytes'));
        $free=array_sum(array_column($volumes,'free_bytes'));
        if (!$volumes || !is_finite($database) || $database<0 || $database>$total-$free) {
            return ['available'=>false,'database_bytes'=>$database,'message'=>'Storage totals could not be reconciled. Database usage may be on another host or volume.'];
        }
        return ['available'=>true,'database_bytes'=>$database,'other_bytes'=>$total-$free-$database,
            'free_bytes'=>$free,'total_bytes'=>$total,'volumes'=>$volumes,
            'message'=>'Mounted local disks combined. Other used space includes all other files, database overhead, filesystem metadata and reserved space. Free space is space available to ordinary users.'];
    }
    public static function read(PDO $db): array
    {
        $database=(float)$db->query('SELECT pg_database_size(current_database())')->fetchColumn();
        $host=getenv('DB_HOST')?:'127.0.0.1';
        if (!in_array($host,['127.0.0.1','localhost','::1'],true) && !str_starts_with($host,'/')) {
            return ['available'=>false,'database_bytes'=>$database,'message'=>'Combined storage chart unavailable: PostgreSQL is configured on a separate host.'];
        }
        try {
            if (!function_exists('proc_open')) { throw new \RuntimeException('Disk reporting is disabled.'); }
            $pipes=[];
            $process=proc_open(['/bin/df','--local','--block-size=1','--output=source,fstype,size,avail,target'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['LC_ALL'=>'C']);
            if (!is_resource($process)) { throw new \RuntimeException('Cannot read disk usage.'); }
            fclose($pipes[0]);
            $output=stream_get_contents($pipes[1],1048576);fclose($pipes[1]);
            stream_get_contents($pipes[2]);fclose($pipes[2]);
            $code=proc_close($process);
            if ($code!==0 || $output===false || strlen($output)>=1048576) { throw new \RuntimeException('Cannot read disk usage.'); }
            return self::summarize($database,self::volumes($output));
        } catch (\Throwable $e) {
            return ['available'=>false,'database_bytes'=>$database,'message'=>'System disk usage unavailable; database size is still shown.'];
        }
    }
}
