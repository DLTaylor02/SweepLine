<?php
declare(strict_types=1);
namespace SweepLine;

/** Polygon-aware water geometry. Even/odd rings retain islands without giant planes. */
final class BeamWaterGeometry
{
    public static function contains(array $point,array $rings): bool
    {
        if(!$rings||!BeamPolygon::inside($point,$rings[0]))return false;
        foreach(array_slice($rings,1) as $hole)if(BeamPolygon::inside($point,$hole))return false;
        return true;
    }
    public static function bounds(array $rings): array
    {
        $points=$rings[0];return [min(array_column($points,0)),min(array_column($points,1)),max(array_column($points,0)),max(array_column($points,1))];
    }
    public static function boundaryDistance(array $point,array $rings): float
    {
        $best=INF;foreach($rings as $ring)foreach($ring as $i=>$p)$best=min($best,BeamLanes::distance($point,$p,$ring[($i+1)%count($ring)])[0]);return $best;
    }
    /** Horizontal trapezoids exactly cover valid polygons, including their holes.
     * The provider normalizer validates topology; this stage never closes gaps. */
    public static function patches(array $rings,float $maxLength=INF,float $maxWidth=INF,int $budget=1024): array
    {
        $levels=[];$edges=[];
        foreach($rings as $ring)foreach($ring as $i=>$p){$q=$ring[($i+1)%count($ring)];$levels[]=round($p[1],7);if(abs($q[1]-$p[1])>1e-8)$edges[]=[$p,$q];}
        sort($levels);$levels=array_values(array_unique($levels));$extra=[];
        if(is_finite($maxLength))for($i=1;$i<count($levels);$i++)for($y=$levels[$i-1]+$maxLength;$y<$levels[$i]-1e-7;$y+=$maxLength)$extra[]=$y;
        $levels=array_merge($levels,$extra);sort($levels);$out=[];
        for($i=1;$i<count($levels);$i++){
            $y0=$levels[$i-1];$y1=$levels[$i];if($y1-$y0<1e-6)continue;$mid=($y0+$y1)/2;$crossings=[];
            foreach($edges as [$a,$b])if($mid>min($a[1],$b[1])&&$mid<max($a[1],$b[1])){
                $x=static fn($y)=>$a[0]+($b[0]-$a[0])*($y-$a[1])/($b[1]-$a[1]);$crossings[]=[$x($mid),$x($y0),$x($y1)];
            }
            usort($crossings,static fn($a,$b)=>$a[0]<=>$b[0]);if(count($crossings)%2)throw new \RuntimeException('Water polygon has unresolved boundaries.');
            for($j=0;$j<count($crossings);$j+=2){
                $left=$crossings[$j];$right=$crossings[$j+1];$width=max($right[1]-$left[1],$right[2]-$left[2]);if($width<0.01)continue;
                $parts=is_finite($maxWidth)?max(1,(int)ceil($width/$maxWidth)):1;
                for($k=0;$k<$parts;$k++){
                    $lo=$k/$parts;$hi=($k+1)/$parts;
                    $a=[$left[1]+($right[1]-$left[1])*$lo,$y0];$b=[$left[1]+($right[1]-$left[1])*$hi,$y0];
                    $c=[$left[2]+($right[2]-$left[2])*$hi,$y1];$d=[$left[2]+($right[2]-$left[2])*$lo,$y1];
                    if(abs(BeamPolygon::area([$a,$b,$c,$d]))<0.1)continue;
                    if(count($out)>=$budget)throw new \RuntimeException('Water surface patch budget reached.');
                    $out[]=[$a,$b,$c,$d];
                }
            }
        }return $out;
    }

    /** Axis-aligned native River strips cannot follow skew cross-sections.
     * Inscribed rectangles keep water inside shorelines, with at most 2 m edge inset. */
    public static function rectangles(array $patches,int $budget=1024): array
    {
        $out=[];
        foreach($patches as [$a,$b,$c,$d]){
            $parts=max(1,(int)ceil(max(abs($d[0]-$a[0]),abs($c[0]-$b[0]))/2));
            if($parts>$budget-count($out))throw new \RuntimeException('Water shoreline patch budget reached.');
            for($i=0;$i<$parts;$i++){
                $t=$i/$parts;$u=($i+1)/$parts;
                $left=max($a[0]+($d[0]-$a[0])*$t,$a[0]+($d[0]-$a[0])*$u);
                $right=min($b[0]+($c[0]-$b[0])*$t,$b[0]+($c[0]-$b[0])*$u);
                if($right-$left<0.05)continue;
                $y=$a[1]+($d[1]-$a[1])*$t;$z=$a[1]+($d[1]-$a[1])*$u;
                $out[]=[[$left,$y],[$right,$y],[$right,$z],[$left,$z]];
            }
        }return $out;
    }

    /** Even/odd scanline intervals; work scales with rows rather than every raster pixel. */
    public static function intervals(array $rings,float $y): array
    {
        $xs=[];foreach($rings as $ring)foreach($ring as $i=>$a){$b=$ring[($i+1)%count($ring)];if(($a[1]>$y)!==($b[1]>$y))$xs[]=$a[0]+($b[0]-$a[0])*($y-$a[1])/($b[1]-$a[1]);}
        sort($xs);$out=[];for($i=1;$i<count($xs);$i+=2)$out[]=[$xs[$i-1],$xs[$i]];return $out;
    }

    /** Coalesce conservative grid cells into rectangles, rather than one patch per vertex.
     * Every band is intersected at all shoreline breakpoints, so islands cannot be bridged. */
    public static function cover(array $rings,float $initialStep,int $budget=1024,bool $river=false,?callable $progress=null): array
    {
        if($budget<1)throw new \RuntimeException('Water coverage patch budget reached.');
        [$x0,$y0,$x1,$y1]=self::bounds($rings);$vertices=array_merge(...$rings);$lastError='Water coverage patch budget reached.';
        for($attempt=0;$attempt<6;$attempt++){
            $step=$initialStep*2**$attempt;if($step>max(32,$initialStep*8))break;
            $out=[];$active=[];$overflow=false;$first=(int)floor($y0/$step);$last=(int)ceil($y1/$step);
            for($row=$first;$row<$last;$row++){
                if($row%32===0&&$progress)$progress(null);
                $bottom=max($y0,$row*$step);$top=min($y1,($row+1)*$step);$epsilon=min(1e-5,($top-$bottom)/1000);
                $samples=[$bottom+$epsilon,$top-$epsilon];
                foreach($vertices as $v)if($v[1]>$bottom+$epsilon&&$v[1]<$top-$epsilon){$samples[]=$v[1]-$epsilon;$samples[]=$v[1]+$epsilon;}
                $intervals=null;
                foreach($samples as $y){
                    $next=self::intervals($rings,$y);
                    if($intervals===null){$intervals=$next;continue;}
                    $intersect=[];$i=0;$j=0;
                    while(isset($intervals[$i],$next[$j])){
                        $left=max($intervals[$i][0],$next[$j][0]);$right=min($intervals[$i][1],$next[$j][1]);
                        if($right-$left>1e-6)$intersect[]=[$left,$right];
                        if($intervals[$i][1]<$next[$j][1])$i++;else $j++;
                    }
                    $intervals=$intersect;if(!$intervals)break;
                }
                $current=[];
                foreach($intervals??[] as [$left,$right]){
                    $start=(int)ceil(($left-1e-7)/$step);$end=(int)floor(($right+1e-7)/$step);
                    $chunk=$river?max(1,(int)floor(512/$step)):max(1,$end-$start);
                    for($a=$start;$a<$end;$a+=$chunk){
                        $b=min($end,$a+$chunk);$key=$a.':'.$b;
                        if(isset($active[$key])&&(!$river||$top-$out[$active[$key]][0][1]<=256+1e-6)){
                            $id=$active[$key];$out[$id][2][1]=$top;$out[$id][3][1]=$top;
                        }else{
                            if(count($out)>=$budget){$overflow=true;break 2;}
                            $id=count($out);$out[]=[[$a*$step,$bottom],[$b*$step,$bottom],[$b*$step,$top],[$a*$step,$top]];
                        }
                        $current[$key]=$id;
                    }
                }
                if($overflow)break;$active=$current;
            }
            if(!$overflow&&$out)return ['patches'=>$out,'shoreline_cell_m'=>$step,'covered_area_m2'=>array_sum(array_map(static fn($q)=>abs(BeamPolygon::area($q)),$out))];
        }
        throw new \RuntimeException($lastError);
    }
}
