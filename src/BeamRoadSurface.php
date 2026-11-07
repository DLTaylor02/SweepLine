<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamRoadSurface
{
    public const PAVED_SHOULDER_M=0.6096;
    /** Add two feet outside each existing carriageway edge, exactly once. */
    public static function shoulders(array $profiles): array
    {
        foreach($profiles as &$profile){
            if(isset($profile['shoulder_m']))continue;
            $profile['carriageway_width_m']=$profile['width_m'];$profile['shoulder_m']=self::PAVED_SHOULDER_M;
            $profile['width_m']+=2*self::PAVED_SHOULDER_M;
        }unset($profile);return $profiles;
    }
    public static function taper(array $points,array $profiles): array
    {
        $original=$profiles;
        $chain=[0.0];for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $changes=[];for($i=1;$i<count($profiles);$i++)if(abs($profiles[$i]["width_m"]-$profiles[$i-1]["width_m"])>0.01 || $profiles[$i]["lanes"]!==$profiles[$i-1]["lanes"] || ($profiles[$i]["oneway"]??false)!==($profiles[$i-1]["oneway"]??false) || ($profiles[$i]["forward"]??0)!==($profiles[$i-1]["forward"]??0) || ($profiles[$i]["backward"]??0)!==($profiles[$i-1]["backward"]??0))$changes[]=[$chain[$i],$profiles[$i]["width_m"]-$profiles[$i-1]["width_m"]];
        $baseWidth=$profiles[0]["width_m"];
        foreach($profiles as $i=>&$profile){$width=$baseWidth;$profile["transition"]=false;foreach($changes as [$at,$delta]){if(abs($chain[$i]-$at)<30.48)$profile["transition"]=true;$t=max(0,min(1,($chain[$i]-$at+30.48)/60.96));$width+=$delta*($t*$t*(3-2*$t));}$profile["width_m"]=max(2.4,$width);}unset($profile);
        return BeamLanePaint::profiles($chain,$profiles,$original);
    }
    /** Protect every terrain cell corner supporting primary pavement during branch passes. */
    public static function primaryContactMask(array $road,array $profiles,BeamRaster $raster,string $base=""): string
    {
        $n=$raster->size;$step=$raster->side/$n;$mask=$base?:str_repeat("\0",$n*$n);
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$radius=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+0.25+sqrt(2)*$step;
            for($y=max(0,(int)floor((min($a[1],$b[1])-$radius)/$step));$y<=min($n-1,(int)ceil((max($a[1],$b[1])+$radius)/$step));$y++)
                for($x=max(0,(int)floor((min($a[0],$b[0])-$radius)/$step));$x<=min($n-1,(int)ceil((max($a[0],$b[0])+$radius)/$step));$x++)
                    if(BeamLanes::distance([$x*$step,$y*$step],$a,$b)[0]<=$radius)$mask[$y*$n+$x]="\1";
        }
        return $mask;
    }

    /** Blend native terrain to pavement height without adding collision objects. */
    public static function blendTerrain(array $road,array $profiles,BeamRaster $raster,float $origin,string $protected,callable $progress,array $bridges=[]): array
    {
        return self::blendTerrainSet([['points'=>$road,'profiles'=>$profiles,'bridges'=>$bridges]],$raster,$origin,$protected,$progress);
    }
    /** Resolve overlapping road/connector corridors against one untouched terrain baseline. */
    public static function blendTerrainSet(array $surfaces,BeamRaster $raster,float $origin,string $protected,callable $progress): array
    {
        $step=$raster->side/$raster->size;$original=clone $raster;
        $nearest=str_repeat(pack('g',INF),$raster->size*$raster->size);$count=0;$maxChange=0.0;
        $inner=max(1.5,sqrt(2)*$step);$blend=max(6,3*$step);
        foreach($surfaces as $surface){$road=$surface['points'];$profiles=$surface['profiles'];$bridges=$surface['bridges']??[];$chainage=0.0;
        for($i=1;$i<count($road);$i++){
            if($i%50===0)$progress(null);
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length2=$dx*$dx+$dy*$dy;
            if($length2<0.000001)continue;
            $start=$chainage;$chainage+=sqrt($length2);$deck=false;
            foreach($bridges as $span)if($start<$span['end_m']-0.00001&&$chainage>$span['start_m']+0.00001){$deck=true;break;}
            if($deck)continue;
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
                $before=$original->cell($x,$y);$cross=($profiles[$i-1]['cross_grade']??0)*(1-$t)+($profiles[$i]['cross_grade']??0)*$t;
                $lateral=(($wx-$a[0]-$dx*$t)*$dy-($wy-$a[1]-$dy*$t)*$dx)/sqrt($length2);
                $target=$a[2]+($b[2]-$a[2])*$t+$cross*$lateral+$origin-0.05;
                $height=$target*(1-$fade)+$before*$fade;
                $raster->put($x,$y,$height);$maxChange=max($maxChange,abs($height-$before));
            }
        }
        }
        return ['surface_count'=>count($surfaces),'method'=>'Native terrain only; shared nearest-corridor blend; no shoulder or supporting collision meshes','clearance_target_m'=>0.05,
            'inner_margin_m'=>$inner,'blend_width_m'=>$blend,'changed_vertices'=>$count,'maximum_height_change_m'=>$maxChange];
    }

    /** Only landward vertices in short abutment zones may be corrected. */
    public static function bridgeApproachMask(array $road,array $profiles,array $chain,array $bridges,BeamRaster $raster,callable $progress): string
    {
        $mask=str_repeat("\1",$raster->size*$raster->size);$step=$raster->side/$raster->size;$best=[];
        $margin=max(25,4*$step);
        for($i=1;$i<count($road);$i++){
            if($i%100===0)$progress(null);
            $near=false;foreach($bridges as $span)foreach([$span['start_m'],$span['end_m']] as $end)
                if($chain[$i]>=$end-$margin&&$chain[$i-1]<=$end+$margin)$near=true;
            if(!$near)continue;
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l2=$dx*$dx+$dy*$dy;if($l2<0.000001)continue;
            $radius=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+max(1.5,sqrt(2)*$step)+max(6,3*$step)+$step;
            for($y=max(0,(int)floor((min($a[1],$b[1])-$radius)/$step));$y<=min($raster->size-1,(int)ceil((max($a[1],$b[1])+$radius)/$step));$y++)
            for($x=max(0,(int)floor((min($a[0],$b[0])-$radius)/$step));$x<=min($raster->size-1,(int)ceil((max($a[0],$b[0])+$radius)/$step));$x++){
                $key=$y*$raster->size+$x;
                $t=max(0,min(1,(($x*$step-$a[0])*$dx+($y*$step-$a[1])*$dy)/$l2));
                $distance=hypot($x*$step-$a[0]-$t*$dx,$y*$step-$a[1]-$t*$dy);
                if($distance>$radius||$distance>=($best[$key]??INF))continue;$best[$key]=$distance;
                $at=$chain[$i-1]+$t*sqrt($l2);$landward=false;$deck=false;
                foreach($bridges as $span){
                    if($at>=$span['start_m']-0.5&&$at<=$span['end_m']+0.5)$deck=true;
                    if(($at<$span['start_m']&&$at>=$span['start_m']-$margin)||($at>$span['end_m']&&$at<=$span['end_m']+$margin))$landward=true;
                }
                $mask[$key]=$landward&&!$deck?"\0":"\1";
            }
        }
        return $mask;
    }

    /** Correct interpolated road contact heights without flattening graded cells. */
    public static function clearTerrain(array $road,array $profiles,BeamRaster $raster,float $origin,callable $progress,string $protected="",bool $partialCells=false,float $maxCutPerVertex=INF): array
    {
        $step=$raster->side/$raster->size;$checked=[];$lowered=[];$clamped=[];$cut=0.0;$checks=0;
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$len=hypot($b[0]-$a[0],$b[1]-$a[1]);if($len<0.001)continue;
            $steps=max(1,(int)ceil($len/min(1.0,$step/2)));
            for($j=0;$j<=$steps;$j++){
                $t=$j/$steps;$x=$a[0]+$t*($b[0]-$a[0]);$y=$a[1]+$t*($b[1]-$a[1]);$z=$a[2]+$t*($b[2]-$a[2]);
                $half=($profiles[$i-1]["width_m"]*(1-$t)+$profiles[$i]["width_m"]*$t)/2+0.25;$across=max(1,(int)ceil(2*$half/min(1.0,$step/2)));
                for($k=0;$k<=$across;$k++){
                    $offset=-$half+2*$half*$k/$across;$gx=($x+($b[1]-$a[1])/$len*$offset)/$step;$gy=($y-($b[0]-$a[0])/$len*$offset)/$step;
                    $cx=(int)floor($gx);$cy=(int)floor($gy);
                    if($cx<0||$cy<0||$cx+1>=$raster->size||$cy+1>=$raster->size)continue;
                    $ids=[$cy*$raster->size+$cx,$cy*$raster->size+$cx+1,($cy+1)*$raster->size+$cx,($cy+1)*$raster->size+$cx+1];
                    $editable=[];foreach($ids as $id)$editable[]=($protected[$id]??"\0")==="\0";
                    if(!in_array(true,$editable,true)||(!$partialCells&&in_array(false,$editable,true)))continue;
                    $u=$gx-$cx;$v=$gy-$cy;
                    // Check both possible native terrain triangle diagonals, plus bilinear sampling.
                    $weights=[[(1-$u)*(1-$v),$u*(1-$v),(1-$u)*$v,$u*$v],
                        $u+$v<=1?[1-$u-$v,$u,$v,0]:[0,1-$v,1-$u,$u+$v-1],
                        $u>=$v?[1-$u,$u-$v,0,$v]:[1-$v,0,$v-$u,$u]];
                    foreach($weights as $w){
                        $height=0.0;$norm=0.0;
                        foreach($ids as $n=>$id){$height+=$raster->cell($id%$raster->size,intdiv($id,$raster->size))*$w[$n];if($editable[$n]){$norm+=$w[$n]*$w[$n];$checked[$id]=true;}}
                        $cross=($profiles[$i-1]['cross_grade']??0)*(1-$t)+($profiles[$i]['cross_grade']??0)*$t;
                        $excess=$height-($z+$cross*$offset+$origin-0.05);
                        if($excess<=0.0001||$norm<0.05)continue;
                        foreach($ids as $n=>$id)if($editable[$n]&&$w[$n]>0){
                            $xx=$id%$raster->size;$yy=intdiv($id,$raster->size);$delta=$excess*$w[$n]/$norm;$remaining=$maxCutPerVertex-($lowered[$id]??0);if($delta>$remaining){$clamped[$id]=true;$delta=max(0,$remaining);}if($delta<=0)continue;
                            $raster->put($xx,$yy,$raster->cell($xx,$yy)-$delta);
                            $lowered[$id]=($lowered[$id]??0)+$delta;$cut=max($cut,$lowered[$id]);
                        }
                    }
                }
                if(++$checks%100===0)$progress(null);
                if(count($checked)>1500000)throw new \RuntimeException("Road terrain-overlap check exceeds the safe cell limit.");
            }
        }
        return ["clamped_vertices"=>count($clamped),"checked_vertices"=>count($checked),"lowered_vertices"=>count($lowered),"maximum_cut_m"=>$cut,"clearance_m"=>0.05,
            "method"=>"Interpolated contact correction; preserve graded terrain cells"];
    }
}
