<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamRoadSurface
{
    public static function taper(array $points,array $profiles): array
    {
        $chain=[0.0];for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $changes=[];for($i=1;$i<count($profiles);$i++)if(abs($profiles[$i]["width_m"]-$profiles[$i-1]["width_m"])>0.01 || $profiles[$i]["lanes"]!==$profiles[$i-1]["lanes"])$changes[]=[$chain[$i],$profiles[$i]["width_m"]-$profiles[$i-1]["width_m"]];
        $baseWidth=$profiles[0]["width_m"];
        foreach($profiles as $i=>&$profile){$width=$baseWidth;$profile["transition"]=false;foreach($changes as [$at,$delta]){if(abs($chain[$i]-$at)<30.48)$profile["transition"]=true;$t=max(0,min(1,($chain[$i]-$at+30.48)/60.96));$width+=$delta*($t*$t*(3-2*$t));}$profile["width_m"]=max(2.4,$width);}unset($profile);
        return $profiles;
    }
    /** Lower all vertices of any terrain cell touched by a sampled road ribbon. */
    public static function clearTerrain(array $road,array $profiles,BeamRaster $raster,float $origin,callable $progress): array
    {
        $step=$raster->side/$raster->size;$targets=[];$checks=0;
        for($i=1;$i<count($road);$i++){$a=$road[$i-1];$b=$road[$i];$len=hypot($b[0]-$a[0],$b[1]-$a[1]);if($len<0.001)continue;
            $steps=max(1,(int)ceil($len/min(1.0,$step/2)));
            for($j=0;$j<=$steps;$j++){$t=$j/$steps;$x=$a[0]+$t*($b[0]-$a[0]);$y=$a[1]+$t*($b[1]-$a[1]);$z=$a[2]+$t*($b[2]-$a[2]);$half=($profiles[$i-1]["width_m"]*(1-$t)+$profiles[$i]["width_m"]*$t)/2+0.25;
                $across=max(1,(int)ceil(2*$half/min(1.0,$step/2)));
                for($k=0;$k<=$across;$k++){$offset=-$half+2*$half*$k/$across;$gx=($x+($b[1]-$a[1])/$len*$offset)/$step;$gy=($y-($b[0]-$a[0])/$len*$offset)/$step;
                    $cx=(int)floor($gx);$cy=(int)floor($gy);$target=$z+$origin-0.08;
                    for($yy=$cy;$yy<=$cy+1;$yy++)for($xx=$cx;$xx<=$cx+1;$xx++)if($xx>=0&&$yy>=0&&$xx<$raster->size&&$yy<$raster->size){$key=$yy*$raster->size+$xx;$targets[$key]=min($targets[$key]??INF,$target);}
                }
                if(++$checks%100===0)$progress(null);
                if(count($targets)>1500000)throw new \RuntimeException("Road terrain-overlap check exceeds the safe cell limit.");
            }
        }
        $lowered=0;$cut=0.0;$processed=0;
        foreach($targets as $key=>$target){$x=$key%$raster->size;$y=intdiv($key,$raster->size);$before=$raster->cell($x,$y);if($before>$target){$raster->put($x,$y,$target);$lowered++;$cut=max($cut,$before-$target);}if(++$processed%1000===0)$progress(null);}
        return ["checked_vertices"=>count($targets),"lowered_vertices"=>$lowered,"maximum_cut_m"=>$cut,"clearance_m"=>0.08];
    }
}
