<?php
declare(strict_types=1);
namespace SweepLine;

final class CoveragePlanner
{
    /** Fixed reusable half-degree tiles. Bounding boxes plan downloads, never search distances. */
    public static function tiles(float $lon,float $lat,float $miles): array
    {
        $angle=$miles*1609.344/6371008.8;
        $dy=rad2deg($angle);
        $dx=rad2deg(asin(min(1,sin($angle)/max(0.01,cos(deg2rad($lat))))));
        $out=[];
        for ($y=(int)floor(($lat-$dy)*2);$y<=(int)floor(($lat+$dy)*2);$y++) {
            for ($x=(int)floor(($lon-$dx)*2);$x<=(int)floor(($lon+$dx)*2);$x++) {
                $w=$x/2; $s=$y/2; $e=$w+0.5; $n=$s+0.5;
                if ($s< -90 || $n>90) { continue; }
                $center=[($w+$e)/2,($s+$n)/2];
                $diagonal=max(Geometry::distance($center,[$w,$s]),Geometry::distance($center,[$w,$n]));
                if (Geometry::distance([$lon,$lat],$center)>$miles*1609.344+$diagonal+500) { continue; }
                // Wrap the dateline for Alaska / Aleutian ZIP codes.
                while ($w< -180) { $w+=360; $e+=360; }
                while ($e>180) { $w-=360; $e-=360; }
                $key='auto-'.str_replace('-','m',(string)round($w*1000)).'-'.str_replace('-','m',(string)round($s*1000)).'-500';
                $out[$key]=['key'=>$key,'bounds'=>[$w,$s,$e,$n]];
            }
        }
        if (count($out)>512) { throw new \InvalidArgumentException('This search spans too many geographic areas. Use a smaller radius.'); }
        return array_values($out);
    }
    /** One bounded extract keeps custom-road continuity without half-degree overfetch. */
    public static function customArea(array $start,array $finish): array
    {
        if(abs($start[0]-$finish[0])>180)throw new \InvalidArgumentException('Custom selections across the date line are not supported.');
        $padding=max(402.336,min(1609.344,Geometry::distance($start,$finish)*0.05));
        $lat=($start[1]+$finish[1])/2;
        $dy=rad2deg($padding/6371008.8);$dx=$dy/max(0.05,cos(deg2rad($lat)));
        // Stable 0.001-degree boundaries reuse nearby equivalent requests.
        $bounds=[max(-180,floor((min($start[0],$finish[0])-$dx)*1000)/1000),
            max(-90,floor((min($start[1],$finish[1])-$dy)*1000)/1000),
            min(180,ceil((max($start[0],$finish[0])+$dx)*1000)/1000),
            min(90,ceil((max($start[1],$finish[1])+$dy)*1000)/1000)];
        return ['key'=>'custom-'.substr(hash('sha256',json_encode($bounds,JSON_THROW_ON_ERROR)),0,24),'bounds'=>$bounds,'halo'=>0.002];
    }

    public static function canSplit(array $tile): bool
    {
        return max($tile['bounds'][2]-$tile['bounds'][0],$tile['bounds'][3]-$tile['bounds'][1])>0.015625;
    }
    public static function oversized(string $message): bool
    {
        return str_contains($message,'limit exceeded') || str_contains($message,'download limit') || str_contains($message,'error remark');
    }
    public static function halo(array $tile): float
    {
        return isset($tile['halo'])?min(0.04,max(0,(float)$tile['halo'])):min(0.04,($tile['bounds'][2]-$tile['bounds'][0])*0.32);
    }
    public static function children(array $tile): array
    {
        [$w,$s,$e,$n]=$tile['bounds']; $mx=($w+$e)/2; $my=($s+$n)/2; $out=[];
        foreach ([[$w,$s,$mx,$my],[$mx,$s,$e,$my],[$w,$my,$mx,$n],[$mx,$my,$e,$n]] as $i=>$bounds) {
            $out[]=['key'=>$tile['key'].'-'.$i,'bounds'=>$bounds]+array_intersect_key($tile,['halo'=>true]);
        }
        return $out;
    }
}
