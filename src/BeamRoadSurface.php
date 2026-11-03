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
    /** Blend native terrain to pavement height without adding collision objects. */
    public static function blendTerrain(array $road,array $profiles,BeamRaster $raster,float $origin,string $protected,callable $progress): array
    {
        $step=$raster->side/$raster->size;$original=clone $raster;
        $nearest=str_repeat(pack('g',INF),$raster->size*$raster->size);$count=0;$maxChange=0.0;
        $inner=max(1.5,sqrt(2)*$step);$blend=max(6,3*$step);
        for($i=1;$i<count($road);$i++){
            if($i%50===0)$progress(null);
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length2=$dx*$dx+$dy*$dy;
            if($length2<0.000001)continue;
            $radius=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+$inner+$blend;
            $loX=max(0,(int)floor((min($a[0],$b[0])-$radius)/$step));$hiX=min($raster->size-1,(int)ceil((max($a[0],$b[0])+$radius)/$step));
            $loY=max(0,(int)floor((min($a[1],$b[1])-$radius)/$step));$hiY=min($raster->size-1,(int)ceil((max($a[1],$b[1])+$radius)/$step));
            for($y=$loY;$y<=$hiY;$y++)for($x=$loX;$x<=$hiX;$x++){
                $key=$y*$raster->size+$x;if(($protected[$key]??"\0")!=="\0")continue;
                $wx=$x*$step;$wy=$y*$step;$t=max(0,min(1,(($wx-$a[0])*$dx+($wy-$a[1])*$dy)/$length2));
                $half=($profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t)/2;
                $distance=hypot($wx-$a[0]-$dx*$t,$wy-$a[1]-$dy*$t);$edge=max(0,$distance-$half);
                if($edge>=$inner+$blend)continue;
                $offset=$key*4;$best=unpack('g',substr($nearest,$offset,4))[1];if($distance>=$best)continue;
                if(is_infinite($best))$count++;
                $bytes=pack('g',$distance);for($j=0;$j<4;$j++)$nearest[$offset+$j]=$bytes[$j];
                $fade=max(0,min(1,($edge-$inner)/$blend));$fade=$fade*$fade*(3-2*$fade);
                $before=$original->cell($x,$y);$target=$a[2]+($b[2]-$a[2])*$t+$origin-0.05;
                $height=$target*(1-$fade)+$before*$fade;
                $raster->put($x,$y,$height);$maxChange=max($maxChange,abs($height-$before));
            }
        }
        return ['method'=>'Native terrain only; no shoulder or supporting collision meshes','clearance_target_m'=>0.05,
            'inner_margin_m'=>$inner,'blend_width_m'=>$blend,'changed_vertices'=>$count,'maximum_height_change_m'=>$maxChange];
    }

    /** Lower all vertices of any terrain cell touched by a sampled road ribbon. */
    public static function clearTerrain(array $road,array $profiles,BeamRaster $raster,float $origin,callable $progress,string $protected=""): array
    {
        $step=$raster->side/$raster->size;$targets=[];$checks=0;
        for($i=1;$i<count($road);$i++){$a=$road[$i-1];$b=$road[$i];$len=hypot($b[0]-$a[0],$b[1]-$a[1]);if($len<0.001)continue;
            $steps=max(1,(int)ceil($len/min(1.0,$step/2)));
            for($j=0;$j<=$steps;$j++){$t=$j/$steps;$x=$a[0]+$t*($b[0]-$a[0]);$y=$a[1]+$t*($b[1]-$a[1]);$z=$a[2]+$t*($b[2]-$a[2]);$half=($profiles[$i-1]["width_m"]*(1-$t)+$profiles[$i]["width_m"]*$t)/2+0.25;
                $across=max(1,(int)ceil(2*$half/min(1.0,$step/2)));
                for($k=0;$k<=$across;$k++){$offset=-$half+2*$half*$k/$across;$gx=($x+($b[1]-$a[1])/$len*$offset)/$step;$gy=($y-($b[0]-$a[0])/$len*$offset)/$step;
                    $cx=(int)floor($gx);$cy=(int)floor($gy);$target=$z+$origin-0.05;
                    for($yy=$cy;$yy<=$cy+1;$yy++)for($xx=$cx;$xx<=$cx+1;$xx++)if($xx>=0&&$yy>=0&&$xx<$raster->size&&$yy<$raster->size){$key=$yy*$raster->size+$xx;if(($protected[$key]??"\0")==="\0")$targets[$key]=min($targets[$key]??INF,$target);}
                }
                if(++$checks%100===0)$progress(null);
                if(count($targets)>1500000)throw new \RuntimeException("Road terrain-overlap check exceeds the safe cell limit.");
            }
        }
        $lowered=0;$cut=0.0;$processed=0;
        foreach($targets as $key=>$target){$x=$key%$raster->size;$y=intdiv($key,$raster->size);$before=$raster->cell($x,$y);if($before>$target){$raster->put($x,$y,$target);$lowered++;$cut=max($cut,$before-$target);}if(++$processed%1000===0)$progress(null);}
        return ["checked_vertices"=>count($targets),"lowered_vertices"=>$lowered,"maximum_cut_m"=>$cut,"clearance_m"=>0.05];
    }
}
