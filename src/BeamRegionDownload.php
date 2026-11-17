<?php
declare(strict_types=1);
namespace SweepLine;

/** Download complete OSM source in bounded pieces, then merge mapped identities before activation. */
final class BeamRegionDownload
{
    public static function fetch(string $url,string $path,array $bounds,callable $download,callable $progress,?callable $sleep=null,bool $separateImports=false,float $halo=0.): array
    {
        $sleep??=static fn()=>usleep(1000000);$files=[];$chunks=[];$retain=false;$requests=0;$splits=0;
        $children=static function($b){[$w,$s,$e,$n]=$b;$x=($w+$e)/2;$y=($s+$n)/2;return [[$w,$s,$x,$y],[$x,$s,$e,$y],[$w,$y,$x,$n],[$x,$y,$e,$n]];};
        $fetch=function(array $b,int $depth,string $tilePath='')use(&$fetch,&$files,&$chunks,&$requests,&$splits,$children,$url,$path,$download,$progress,$sleep,$halo):void{
            if(max($b[2]-$b[0],$b[3]-$b[1])>.2&&$depth<4){$splits++;$progress('Splitting large region refresh into smaller source requests');foreach($children($b) as $i=>$child)$fetch($child,$depth+1,ltrim($tilePath.'-'.$i,'-'));return;}
            $file=$path.'.chunk-'.count($files).'.osm';
            $margin=$depth===0?$halo:min($halo,CoveragePlanner::halo(['bounds'=>$b]));
            $bbox=implode(',',[max(-90,$b[1]-$margin),max(-180,$b[0]-$margin),min(90,$b[3]+$margin),min(180,$b[2]+$margin)]);
            $query='[out:xml][timeout:180][maxsize:134217728];way[highway~"^(motorway|motorway_link|trunk|trunk_link|primary|primary_link|secondary|secondary_link|tertiary|tertiary_link|unclassified|residential|living_street|service)$"]('.$bbox.');(._;>;);out body;';
            $failure=null;
            for($attempt=1;$attempt<=3;$attempt++){
                $free=disk_free_space(dirname($path));if($free!==false&&$free<536870912)throw new \RuntimeException('Region source download paused: less than 512 MB free storage.');
                if(++$requests>48)throw new \RuntimeException('Region source remains unavailable after the bounded refresh request budget (48 attempts). Retry when the provider recovers.');
                $progress('Downloading region source piece '.(count($files)+1).' (attempt '.$attempt.'/3)');
                try{
                    $download($url,$file,104857600,http_build_query(['data'=>$query]),$progress,240);
                    try{BeamRegionRefresh::validate($file);}
                    catch(\RuntimeException $e){throw new DownloadFailure($e->getMessage(),!str_contains($e->getMessage(),'way limit'));}
                    $files[]=$file;$chunks[]=['path'=>$file,'bounds'=>$b,'tile_path'=>$tilePath];return;
                }catch(DownloadFailure $e){
                    $failure=$e;if(!$e->retryable||$attempt===3)break;
                    $delay=$attempt*5;$progress('Region provider temporarily unavailable; retrying in '.$delay.' seconds (attempt '.($attempt+1).'/3)');
                    for($i=0;$i<$delay;$i++){$progress(null);$sleep();}
                }finally{if(!in_array($file,$files,true)&&is_file($file))unlink($file);}
            }
            $canSplit=$depth<4&&max($b[2]-$b[0],$b[3]-$b[1])>.015625;
            if($canSplit&&($failure->retryable||str_contains($failure->getMessage(),'safe download limit')||str_contains($failure->getMessage(),'way limit'))){
                $splits++;$progress('Region source request needs smaller areas; subdividing after bounded attempts');
                foreach($children($b) as $i=>$child)$fetch($child,$depth+1,ltrim($tilePath.'-'.$i,'-'));
                return;
            }
            throw new \RuntimeException('Region source download failed after '.$attempt.' attempt(s): '.$failure->getMessage(),0,$failure);
        };
        try{
            $fetch($bounds,0);
            if($separateImports){$retain=true;return ['requests'=>$requests,'pieces'=>count($files),'splits'=>$splits,'chunks'=>$chunks];}
            $progress('Merging complete regional source pieces');
            $out=fopen($path,'wb');if(!$out)throw new \RuntimeException('Cannot create merged regional source.');
            $seen=[];$ways=0;$nodes=0;
            $write=static function(string $text)use($out):void{if(fwrite($out,$text)!==strlen($text))throw new \RuntimeException('Cannot write merged regional source.');};
            try{
                $write('<?xml version="1.0" encoding="UTF-8"?><osm version="0.6" generator="SweepLine source merge">'."\n");
                foreach($files as $file){
                    $reader=new \XMLReader();
                    if(!$reader->open($file,null,LIBXML_NONET|LIBXML_COMPACT))throw new \RuntimeException('Cannot read validated regional source piece.');
                    try{
                        while($reader->read()){
                            if($reader->nodeType!==\XMLReader::ELEMENT||!in_array($reader->name,['node','way'],true))continue;
                            $key=$reader->name.':'.$reader->getAttribute('id');if(isset($seen[$key]))continue;$seen[$key]=true;
                            if($reader->name==='way'&&++$ways>30000)throw new \RuntimeException('Combined region refresh exceeds the 30,000-way import limit.');
                            if($reader->name==='node'&&++$nodes>1000000)throw new \RuntimeException('Combined region refresh exceeds the node safety limit.');
                            if(($ways+$nodes)%1000===0)$progress(null);
                            $write($reader->readOuterXml()."\n");
                        }
                    }finally{$reader->close();}
                }
                $write('</osm>');
            }finally{fclose($out);}
            BeamRegionRefresh::validate($path);
            return ['requests'=>$requests,'pieces'=>count($files),'splits'=>$splits,'ways'=>$ways,'nodes'=>$nodes];
        }catch(\Throwable $e){if(is_file($path))unlink($path);throw $e;}
        finally{if(!$retain)foreach($files as $file)if(is_file($file))unlink($file);}
    }
}
