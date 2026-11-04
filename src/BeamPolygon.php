<?php
declare(strict_types=1);
namespace SweepLine;

final class BeamPolygon
{
    public static function cross(array $a,array $b,array $c): float {return ($b[0]-$a[0])*($c[1]-$a[1])-($b[1]-$a[1])*($c[0]-$a[0]);}
    public static function clean(array $points): array
    {
        $out=[];foreach($points as $p)if(!$out||hypot($p[0]-end($out)[0],$p[1]-end($out)[1])>0.001)$out[]=array_slice($p,0,2);
        if(count($out)>1&&hypot($out[0][0]-end($out)[0],$out[0][1]-end($out)[1])<0.001)array_pop($out);
        do{$changed=false;foreach($out as $i=>$p){$n=count($out);if($n<4)break;if(abs(self::cross($out[($i+$n-1)%$n],$p,$out[($i+1)%$n]))<0.000001){array_splice($out,$i,1);$changed=true;break;}}}while($changed);
        if(self::area($out)<0)$out=array_reverse($out);return $out;
    }
    public static function area(array $p): float {$a=0;foreach($p as $i=>$v){$b=$p[($i+1)%count($p)];$a+=$v[0]*$b[1]-$b[0]*$v[1];}return $a/2;}
    public static function inside(array $p,array $ring): bool
    {
        $inside=false;$n=count($ring);
        for($i=0,$j=$n-1;$i<$n;$j=$i++){
            $a=$ring[$j];$b=$ring[$i];
            if(abs(self::cross($a,$b,$p))<0.000001&&$p[0]>=min($a[0],$b[0])-0.000001&&$p[0]<=max($a[0],$b[0])+0.000001&&$p[1]>=min($a[1],$b[1])-0.000001&&$p[1]<=max($a[1],$b[1])+0.000001)return false;
            if(($a[1]>$p[1])!==($b[1]>$p[1])&&$p[0]<($b[0]-$a[0])*($p[1]-$a[1])/($b[1]-$a[1])+$a[0])$inside=!$inside;
        }
        return $inside;
    }
    public static function overlaps(array $a,array $b): bool
    {
        if(max(array_column($a,0))<=min(array_column($b,0))+0.000001||max(array_column($b,0))<=min(array_column($a,0))+0.000001||max(array_column($a,1))<=min(array_column($b,1))+0.000001||max(array_column($b,1))<=min(array_column($a,1))+0.000001)return false;
        foreach($a as $i=>$p){$q=$a[($i+1)%count($a)];foreach($b as $j=>$r){$s=$b[($j+1)%count($b)];
            if(self::cross($p,$q,$r)*self::cross($p,$q,$s)<-0.00000001&&self::cross($r,$s,$p)*self::cross($r,$s,$q)<-0.00000001)return true;
        }}
        foreach($a as $p)if(self::inside($p,$b))return true;
        foreach($b as $p)if(self::inside($p,$a))return true;
        // Coincident footprints have no strictly interior vertices.
        foreach(self::triangles($a) as [$i,$j,$k])if(self::inside([($a[$i][0]+$a[$j][0]+$a[$k][0])/3,($a[$i][1]+$a[$j][1]+$a[$k][1])/3],$b))return true;
        return false;
    }
    public static function triangles(array $points): array
    {
        if(count($points)<3||count($points)>100||abs(self::area($points))<0.000001)return [];
        foreach($points as $i=>$a){$b=$points[($i+1)%count($points)];foreach($points as $j=>$c){if($j<=$i+1||($i===0&&$j===count($points)-1))continue;$d=$points[($j+1)%count($points)];if(self::cross($a,$b,$c)*self::cross($a,$b,$d)<-0.00000001&&self::cross($c,$d,$a)*self::cross($c,$d,$b)<-0.00000001)return [];}}
        $ids=array_keys($points);if(self::area($points)<0)$ids=array_reverse($ids);$out=[];
        while(count($ids)>3){
            $found=false;$n=count($ids);
            foreach($ids as $i=>$b){$a=$ids[($i+$n-1)%$n];$c=$ids[($i+1)%$n];if(self::cross($points[$a],$points[$b],$points[$c])<=0.000001)continue;
                $blocked=false;
                foreach($ids as $v)if($v!==$a&&$v!==$b&&$v!==$c){
                    if(self::cross($points[$a],$points[$b],$points[$v])>=-0.000001&&self::cross($points[$b],$points[$c],$points[$v])>=-0.000001&&self::cross($points[$c],$points[$a],$points[$v])>=-0.000001){$blocked=true;break;}
                }
                if($blocked)continue;$out[]=[$a,$b,$c];array_splice($ids,$i,1);$found=true;break;
            }
            if(!$found)return [];
        }
        $out[]=$ids;return $out;
    }
}
