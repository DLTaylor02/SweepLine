<?php
declare(strict_types=1);
namespace SweepLine;

final class Geometry
{
    public static function distance(array $a, array $b): float
    {
        $p = deg2rad($a[1]); $q = deg2rad($b[1]);
        $h = sin(($q-$p)/2)**2 + cos($p)*cos($q)*sin(deg2rad($b[0]-$a[0])/2)**2;
        return 6371008.8 * 2 * asin(sqrt(min(1, $h)));
    }
    public static function bearing(array $a, array $b): float
    {
        $p = deg2rad($a[1]); $q = deg2rad($b[1]); $d = deg2rad($b[0]-$a[0]);
        return rad2deg(atan2(sin($d)*cos($q), cos($p)*sin($q)-sin($p)*cos($q)*cos($d)));
    }
    public static function turn(float $a, float $b): float
    {
        return fmod($b-$a+540, 360)-180;
    }
    public static function length(array $points): float
    {
        $length = 0.0;
        for ($i=1; $i<count($points); $i++) { $length += self::distance($points[$i-1], $points[$i]); }
        return $length;
    }
    // Uniform sampling suppresses dependence on OSM vertex density.
    public static function sample(array $points, float $spacing = 30): array
    {
        $out = [$points[0]]; $remaining = $spacing;
        for ($i=1; $i<count($points); $i++) {
            $a = $points[$i-1]; $b = $points[$i]; $distance = self::distance($a,$b);
            while ($distance >= $remaining && $distance > 0) {
                $t = $remaining/$distance;
                $a = [$a[0]+($b[0]-$a[0])*$t, $a[1]+($b[1]-$a[1])*$t];
                $out[] = $a; $distance = self::distance($a,$b); $remaining = $spacing;
            }
            $remaining -= $distance;
        }
        return $out;
    }
    public static function metrics(array $points): array
    {
        $length = self::length($points); $sample = self::sample($points);
        $curves=0; $quality=0.0; $straight=0; $run=0; $bestRun=0; $angleSum=0.0;
        for ($i=1; $i<count($sample)-1; $i++) {
            $angle = abs(self::turn(self::bearing($sample[$i-1],$sample[$i]),self::bearing($sample[$i],$sample[$i+1])));
            $angleSum += $angle;
            $radius = $angle > 0.1 ? 30 / (2*sin(deg2rad($angle)/2)) : INF;
            if ($radius >= 40 && $radius <= 600 && $angle < 55) {
                $curves++; $run++; $bestRun=max($bestRun,$run);
                $quality += min(1, 200/$radius);
            } else { $run=0; }
            if ($angle < 3) { $straight++; }
        }
        $n=max(1,count($sample)-2); $miles=max(0.1,$length/1609.344);
        return ['length_m'=>$length, 'curve_density'=>$curves/$miles,
            'heading_change_per_mile'=>$angleSum/$miles,
            'curvature'=>min(1, ($quality/$n)*2.8) * min(1,$bestRun/5) * min(1,$length/1609.344),
            'straightness'=>$straight/$n, 'sustained_curve_m'=>$bestRun*30];
    }
}
