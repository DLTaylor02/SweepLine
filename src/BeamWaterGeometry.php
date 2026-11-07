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
}
