<?php
declare(strict_types=1);
namespace SweepLine;

final class Scorer
{
    public static function profiles(): array { return require SWEEPLINE_ROOT . '/config/profiles.php'; }
    public static function score(array $m, string $profile): array
    {
        $miles=max(0.1,$m['length_m']/1609.344);
        $class = ['motorway'=>1.0,'trunk'=>0.95,'primary'=>0.9,'secondary'=>0.9,'tertiary'=>0.8,'unclassified'=>0.55][$m['highway']] ?? 0.15;
        $dimensions = [
            'curvature'=>$m['curvature'], 'straightness'=>$m['straightness'],
            'length'=>min(1,log(1+$miles)/log(21)),
            'speed'=>isset($m['speed_mph']) ? min(1,max(0,($m['speed_mph']-20)/45)) : 0.25,
            'intersections'=>1/(1+$m['intersections']/$miles*0.6),
            'stops'=>1/(1+($m['mandatory_stops']+$m['uncertain_controls']*0.5)/$miles*3),
            'classification'=>$class, 'surface'=>$m['paved'] ? 1 : 0.2,
        ];
        $score=0;
        foreach (self::profiles()[$profile]['weights'] as $key=>$weight) { $score += $dimensions[$key]*$weight; }
        // Missing surface/speed/control direction is not a quality bonus.
        $confidence = 0.35 + ($m['paved'] ? 0.25 : 0) + (isset($m['speed_mph']) ? 0.2 : 0)
            + 0.2/(1+$m['uncertain_controls']);
        return ['score'=>round(100*$score*(0.7+0.3*$confidence),1), 'confidence'=>round($confidence,3), 'dimensions'=>$dimensions];
    }
}
