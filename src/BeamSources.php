<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamSources
{
    public function __construct(private ?\Closure $download=null) {}
    public function downloadRaster(string $url,string $path,string $label,callable $progress,?callable $sleep=null): void
    {
        $sleep??=static fn()=>usleep(1000000);
        for($attempt=1;$attempt<=3;$attempt++) {
            $progress($label.': downloading (attempt '.$attempt.'/3)');
            try {
                ($this->download??[Download::class,'file'])($url,$path,67108864,null,static fn($message)=>$progress($label.': '.$message));
                return;
            } catch(DownloadFailure $e) {
                if(!$e->retryable || $attempt===3)throw new \RuntimeException($label.': '.$e->getMessage().($e->retryable?' after 3 attempts. Try generating again later.':''),0,$e);
                $seconds=$attempt*5;
                $progress($label.': provider temporarily unavailable; retrying in '.$seconds.' seconds (attempt '.($attempt+1).'/3)');
                for($i=0;$i<$seconds;$i++){$progress(null);$sleep();}
            }
        }
    }
    public static function run(array $command,callable $checkpoint,int $timeout=180): string
    {
        $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new \RuntimeException('Could not launch GDAL. Rerun the installer to install gdal-bin.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $started=time();$last=0;$output='';$error='';$exit=-1;
        try {
            while(true) {
                $output=substr($output.stream_get_contents($pipes[1]),-65536);$error=substr($error.stream_get_contents($pipes[2]),-65536);
                $status=proc_get_status($process);
                if(!$status['running']){$exit=$status['exitcode'];break;}
                if(time()-$last>=1){$checkpoint();$last=time();}
                if(time()-$started>$timeout)throw new \RuntimeException('Geographic raster processing timed out.');
                usleep(100000);
            }
            $output.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
            if($exit!==0)throw new \RuntimeException('Geographic raster conversion failed. Verify gdal-bin and the provider coverage. '.substr(trim($error),0,350));
            return $output;
        } finally {
            $status=proc_get_status($process);if($status['running']){proc_terminate($process);usleep(100000);proc_terminate($process,9);}
            fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        }
    }
    public function fetch(array $plan,string $dir,callable $progress): BeamRaster
    {
        $n=$plan['resolution'];[$w,$s,$e,$north]=$plan['bounds'];$step=($e-$w)/$n;
        $bbox=[$w-$step/2,$s-$step/2,$e-$step/2,$north-$step/2];
        $common=['bbox'=>implode(',',$bbox),'bboxSR'=>$plan['epsg'],'imageSR'=>$plan['epsg'],'size'=>$n.','.$n,'format'=>'tiff','f'=>'image','adjustAspectRatio'=>'false','interpolation'=>'RSP_BilinearInterpolation','renderingRule'=>json_encode(['rasterFunction'=>'None'])];
        foreach(['dem'=>'https://elevation.nationalmap.gov/arcgis/rest/services/3DEPElevation/ImageServer/exportImage',
                 'aerial'=>'https://imagery.nationalmap.gov/arcgis/rest/services/USGSNAIPImagery/ImageServer/exportImage'] as $kind=>$url) {
            $progress($kind==='dem'?'Downloading real USGS elevation':'Downloading NAIP aerial colors');
            if($kind==='aerial')$common['renderingRule']=json_encode(['rasterFunction'=>'NaturalColor']);
            $params=$common+($kind==='dem'?['pixelType'=>'F32']:['pixelType'=>'U8','bandIds'=>'0,1,2']);
            $this->downloadRaster($url.'?'.http_build_query($params),$dir.'/'.$kind.'.tif',$kind==='dem'?'USGS elevation':'NAIP aerial imagery',$progress);
            $progress('Aligning '.$kind.' raster to the square map');
            self::run(['gdalwarp','-q','-overwrite','-t_srs','EPSG:'.$plan['epsg'],'-te',...(array_map('strval',$bbox)),'-ts',(string)$n,(string)$n,'-r','bilinear','-dstnodata',$kind==='dem'?'-9999':'0',$dir.'/'.$kind.'.tif',$dir.'/'.$kind.'-aligned.tif'],static fn()=>$progress(null));
            $args=['gdal_translate','-q','-of','ENVI','-ot',$kind==='dem'?'Float32':'Byte','-co','INTERLEAVE=BSQ'];
            if($kind==='aerial')array_push($args,'-b','1','-b','2','-b','3');
            self::run([...$args,$dir.'/'.$kind.'-aligned.tif',$dir.'/'.$kind.'.bin'],static fn()=>$progress(null));
            $header=file_get_contents($dir.'/'.$kind.'.hdr');
            if(!preg_match('/byte order\s*=\s*0/',$header))throw new \RuntimeException('GDAL did not produce a supported little-endian raster.');
        }
        return new BeamRaster($n,$e-$w,file_get_contents($dir.'/dem.bin'),file_get_contents($dir.'/aerial.bin'));
    }
}

