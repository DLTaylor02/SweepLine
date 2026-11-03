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
    public static function shoulders(array $road,array $profiles,BeamRaster $raster,float $origin,string $protected,callable $progress,string $categories=""): array
    {
        $step=$raster->side/$raster->size;$runs=[];$run=[];$maxDrop=0.0;
        foreach($road as $i=>$p){
            if($i%100===0)$progress(null);
            $cx=(int)floor($p[0]/$step);$cy=(int)floor($p[1]/$step);$blocked=false;
            for($yy=$cy;$yy<=$cy+1;$yy++)for($xx=$cx;$xx<=$cx+1;$xx++)
                if($xx>=0&&$yy>=0&&$xx<$raster->size&&$yy<$raster->size&&($protected[$yy*$raster->size+$xx]??"\0")!=="\0")$blocked=true;
            if($blocked){if(count($run)>1)$runs[]=$run;$run=[];continue;}
            $a=$road[max(0,$i-1)];$b=$road[min(count($road)-1,$i+1)];$length=hypot($b[0]-$a[0],$b[1]-$a[1])?:1;
            $dx=($b[0]-$a[0])/$length;$dy=($b[1]-$a[1])/$length;$grade=($b[2]-$a[2])/$length;
            $surfaceAt=static fn($x,$y)=>ord($categories[max(0,min($raster->size-1,(int)floor($y/$step)))*$raster->size+max(0,min($raster->size-1,(int)floor($x/$step)))]??"\0");
            $section=['bed_surface'=>$surfaceAt($p[0],$p[1]),'bed'=>[$p[0],$p[1],$p[2]-0.05,$profiles[$i]['width_m']+0.04,0.03,0,0,1]];
            foreach([-1,1] as $side){
                $nx=$dy*$side;$ny=-$dx*$side;$half=$profiles[$i]['width_m']/2;
                $width=1.5;
                for($attempt=0;$attempt<4;$attempt++){
                    $outer=[$p[0]+$nx*($half+$width),$p[1]+$ny*($half+$width)];
                    if(min($outer)<0||max($outer)>=$raster->side){$blocked=true;break;}
                    // Read final shaped terrain, never the preserved source DEM.
                    $ground=$raster->height(...$outer)-$origin;
                    $needed=max(1.5,min(6,abs($p[2]-$ground)/0.35));
                    if($needed<=$width||$attempt===3)break;$width=$needed;
                }
                if($blocked)break;
                $width+=0.75;$outer=[$p[0]+$nx*($half+$width),$p[1]+$ny*($half+$width)];
                if(min($outer)<0||max($outer)>=$raster->side){$blocked=true;break;}
                $ground=$raster->height(...$outer)-$origin;
                $section[($side<0?'left':'right').'_surface']=$surfaceAt(...$outer);
                $ox=(int)floor($outer[0]/$step);$oy=(int)floor($outer[1]/$step);
                for($yy=$oy;$yy<=$oy+1;$yy++)for($xx=$ox;$xx<=$ox+1;$xx++)
                    if($xx>=0&&$yy>=0&&$xx<$raster->size&&$yy<$raster->size&&($protected[$yy*$raster->size+$xx]??"\0")!=="\0")$blocked=true;
                if($blocked)break;
                $inner=$p[2]-0.005;$outerZ=$ground-0.15;
                $maxDrop=max($maxDrop,$inner-$outerZ);$rise=($outerZ-$inner)/$width;
                $normal=[-$grade*$dx-$rise*$nx,-$grade*$dy-$rise*$ny,1];$norm=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$normal)));
                $section[$side<0?'left':'right']=[$p[0]+$nx*($half+$width/2),$p[1]+$ny*($half+$width/2),($inner+$outerZ)/2,hypot($width,$outerZ-$inner),0.03,$normal[0]/$norm,$normal[1]/$norm,1/$norm];
            }
            if($blocked){if(count($run)>1)$runs[]=$run;$run=[];continue;}$run[]=$section;
        }
        if(count($run)>1)$runs[]=$run;
        return ['runs'=>$runs,'roadbed_clearance_m'=>0.05,'minimum_shoulder_width_m'=>2.25,'maximum_shoulder_width_m'=>6.75,'edge_overlap_m'=>0.75,'edge_embed_m'=>0.15,'maximum_shoulder_drop_m'=>$maxDrop];
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
