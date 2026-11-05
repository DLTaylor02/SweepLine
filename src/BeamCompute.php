<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded, short-lived PHP helpers; database connections and ZIP writes stay in the parent. */
final class BeamCompute
{
    private int $workers,$peak=0,$parallel=0,$serial=0;
    private array $detected;
    public function __construct(private string $directory,private \Closure $checkpoint,?int $testWorkers=null)
    {
        $this->detected=self::limits();$this->workers=$testWorkers??$this->detected['workers'];
        $this->workers=max(1,min(16,$this->workers));
        if(PHP_SAPI!=='cli'||!function_exists('proc_open'))$this->workers=1;
    }
    public static function capacity(int $cpus,int $availableMiB,string $setting='auto'): int
    {
        $wanted=$setting==='auto'?min(4,max(1,$cpus-1)):(ctype_digit($setting)?max(1,min(16,(int)$setting)):1);
        return max(1,min($wanted,max(1,$cpus),max(1,(int)floor(($availableMiB-512)/256))));
    }
    public static function limits(): array
    {
        $cpus=max(1,(int)(getenv('NUMBER_OF_PROCESSORS')?:1));$status=@file_get_contents('/proc/self/status');
        if($status&&preg_match('/^Cpus_allowed_list:\s*([^\r\n]+)/m',$status,$m)){
            $cpus=0;foreach(explode(',',trim($m[1])) as $range){$ends=explode('-',$range);$cpus+=isset($ends[1])?(int)$ends[1]-(int)$ends[0]+1:1;}
        }
        $memory=768;$info=@file_get_contents('/proc/meminfo');
        if($info&&preg_match('/MemAvailable:\s+(\d+) kB/',$info,$m))$memory=(int)floor((int)$m[1]/1024);
        $paths=['/sys/fs/cgroup'];$groups=@file_get_contents('/proc/self/cgroup');
        if($groups&&preg_match('/^0::(\/[^\r\n]*)/m',$groups,$m)&&!str_contains($m[1],'..')){
            $path='/sys/fs/cgroup'.rtrim($m[1],'/');
            while($path!=='/sys/fs/cgroup'&&str_starts_with($path,'/sys/fs/cgroup/')){$paths[]=$path;$path=dirname($path);}
        }
        foreach(array_unique($paths) as $path){
            $quota=@file_get_contents($path.'/cpu.max');
            if($quota&&preg_match('/^(\d+)\s+(\d+)/',$quota,$m)&&$m[2]>0)$cpus=min($cpus,max(1,(int)floor((int)$m[1]/(int)$m[2])));
            $max=@file_get_contents($path.'/memory.max');$current=@file_get_contents($path.'/memory.current');
            if($max!==false&&ctype_digit(trim($max))&&$current!==false&&ctype_digit(trim($current)))$memory=min($memory,max(0,(int)floor(((int)$max-(int)$current)/1048576)));
        }
        $raw=getenv('BEAMNG_CPU_WORKERS');$setting=$raw===false||trim($raw)===''?'auto':trim($raw);
        return ['available_cpus'=>$cpus,'available_memory_mib'=>$memory,'setting'=>$setting,'workers'=>self::capacity($cpus,$memory,$setting)];
    }
    public function workers(): int {return $this->workers;}
    public function report(): array {return $this->detected+['workers_limit'=>$this->workers,'peak_children'=>$this->peak,'parallel_tasks'=>$this->parallel,'serial_tasks'=>$this->serial];}
    public function rows(int $size): array
    {
        $count=min($size,$this->workers);$out=[];for($i=0;$i<$count;$i++)$out[]=['start'=>(int)floor($i*$size/$count),'end'=>(int)floor(($i+1)*$size/$count)];return $out;
    }
    public function map(array $tasks,array $shared,callable $serial): array
    {
        if($this->workers===1||count($tasks)<2){$out=[];foreach($tasks as $key=>$task){($this->checkpoint)();$out[$key]=$serial($task);$this->serial++;}return $out;}
        $files=[];$running=[];$results=[];$keys=array_keys($tasks);$values=array_values($tasks);$outputs=[];$logs=[];$next=0;$last=0.0;
        $temporary=function()use(&$files){$path=tempnam($this->directory,'sl-cpu-');if($path===false)throw new \RuntimeException('Cannot allocate parallel export workspace.');@chmod($path,0600);$files[]=$path;return $path;};
        try{
            ($this->checkpoint)();$state=$temporary();
            foreach($values as $_){$outputs[]=$temporary();$logs[]=$temporary();}
            if(file_put_contents($state,serialize(['shared'=>$shared,'tasks'=>$values,'outputs'=>$outputs]))===false)throw new \RuntimeException('Cannot write parallel export state.');
            while($next<count($values)||$running){
                if(microtime(true)-$last>=1){($this->checkpoint)();$last=microtime(true);}
                while($next<count($values)&&count($running)<$this->workers){
                    $i=$next++;$pipes=[];
                    $command=[PHP_BINARY,'-d','memory_limit=256M','-d','max_execution_time=0',SWEEPLINE_ROOT.'/bin/beam-compute.php',$state,(string)$i];
                    $process=proc_open($command,[0=>['pipe','r'],1=>['file',$logs[$i],'a'],2=>['file',$logs[$i],'a']],$pipes,null,null,['bypass_shell'=>true]);
                    if(!is_resource($process))throw new \RuntimeException('Could not start a parallel export helper. Set BEAMNG_CPU_WORKERS=1 to use serial processing.');
                    fclose($pipes[0]);$running[$i]=['process'=>$process,'started'=>microtime(true)];$this->peak=max($this->peak,count($running));
                }
                foreach($running as $i=>$child){
                    $status=proc_get_status($child['process']);
                    if($status['running']){if(microtime(true)-$child['started']>600)throw new \RuntimeException('Parallel terrain task timed out.');continue;}
                    $exit=$status['exitcode'];proc_close($child['process']);unset($running[$i]);
                    if($exit!==0)throw new \RuntimeException('Parallel terrain task failed: '.substr(trim((string)file_get_contents($logs[$i])),0,350));
                    $result=file_get_contents($outputs[$i]);if($result===false)throw new \RuntimeException('Cannot read parallel terrain result.');
                    $results[$i]=$result;$this->parallel++;
                }
                if($running)usleep(50000);
            }
            $ordered=[];foreach($keys as $i=>$key)$ordered[$key]=$results[$i];return $ordered;
        }finally{
            foreach($running as $child){proc_terminate($child['process']);}
            if($running)usleep(100000);
            foreach($running as $child){if(proc_get_status($child['process'])['running'])proc_terminate($child['process'],9);proc_close($child['process']);}
            foreach($files as $file)if(is_file($file))unlink($file);
        }
    }
}
