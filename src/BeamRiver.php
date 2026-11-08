<?php
declare(strict_types=1);
namespace SweepLine;

/** Bank-constrained native river ribbons; no inferred widths outside mapped water polygons. */
final class BeamRiver
{
    /** Intersections of a perpendicular line with all mapped banks, retaining island gaps. */
    public static function banks(array $p,array $tangent,array $rings): ?array
    {
        $n=hypot($tangent[0],$tangent[1]);if($n<1e-7)return null;
        $normal=[-$tangent[1]/$n,$tangent[0]/$n];$hits=[];
        foreach($rings as $ring)foreach($ring as $i=>$a){
            $b=$ring[($i+1)%count($ring)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];
            $den=$normal[0]*$dy-$normal[1]*$dx;if(abs($den)<1e-9)continue;
            $ax=$a[0]-$p[0];$ay=$a[1]-$p[1];$t=($ax*$dy-$ay*$dx)/$den;$u=($ax*$normal[1]-$ay*$normal[0])/$den;
            if($u>=-1e-8&&$u<1-1e-8)$hits[]=$t;
        }
        sort($hits);$unique=[];foreach($hits as $t)if(!$unique||abs($t-end($unique))>1e-5)$unique[]=$t;
        for($i=1;$i<count($unique);$i++){
            $left=$unique[$i-1];$right=$unique[$i];if($left>0||$right<0)continue;
            $mid=[$p[0]+$normal[0]*($left+$right)/2,$p[1]+$normal[1]*($left+$right)/2];
            if(!BeamWaterGeometry::contains($mid,$rings))continue;
            if($right-$left<0.5||$right-$left>2000)return null;
            return ['left'=>[$p[0]+$normal[0]*$left,$p[1]+$normal[1]*$left],'right'=>[$p[0]+$normal[0]*$right,$p[1]+$normal[1]*$right],
                'center'=>$mid,'width'=>$right-$left];
        }
        return null;
    }
    public static function ribbons(array $feature,array $model): array
    {
        $out=[];
        foreach($model['paths']??[($model['points']??[])] as $path){
            $run=[];
            $finish=static function()use(&$out,&$run){if(count($run)>1)$out[]=$run;$run=[];};
            foreach($path as $i=>$p){
                $a=$path[max(0,$i-1)];$b=$path[min(count($path)-1,$i+1)];
                $bank=self::banks($p,[$b[0]-$a[0],$b[1]-$a[1]],$feature['rings']);
                if($bank===null){$finish();continue;}
                $bank['z']=$p[2];
                if($run){
                    $previous=end($run);
                    // Refuse a chord across dry land or an island rather than filling it with water.
                    $wet=true;
                    for($j=1;$j<4;$j++){$t=$j/4;$mid=[$previous['center'][0]+($bank['center'][0]-$previous['center'][0])*$t,$previous['center'][1]+($bank['center'][1]-$previous['center'][1])*$t];if(!BeamWaterGeometry::contains($mid,$feature['rings']))$wet=false;}
                    if(!$wet)$finish();
                }
                $run[]=$bank;
            }
            $finish();
        }
        return $out;
    }
}
