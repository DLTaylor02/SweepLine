<?php
declare(strict_types=1);
namespace SweepLine;

final class Scorer
{
    public const MAX_CURVE_DENSITY=1609.344/30;
    public static function densityScore(float $raw): float {return 1+9*min(self::MAX_CURVE_DENSITY,max(0,$raw))/self::MAX_CURVE_DENSITY;}
    public static function rawDensity(float $score): float {return ($score-1)/9*self::MAX_CURVE_DENSITY;}
    public static function eligibility(array $parameters): array
    {
        $rules=self::profiles()[$parameters['profile']]['eligibility']??[];
        if ($parameters['profile']==='twisty' && isset($parameters['min_curve_density'])) {
            $rules['curve_density']=$parameters['min_curve_density'];
        }
        if ($parameters['profile']==='twisty') {
            if (isset($parameters['min_curvature_quality'])) {$rules['curvature']=$parameters['min_curvature_quality'];}
            if (isset($parameters['min_sustained_curve_m'])) {$rules['sustained_curve_m']=$parameters['min_sustained_curve_m'];}
        }
        return $rules;
    }
    public static function profiles(): array { return require SWEEPLINE_ROOT . '/config/profiles.php'; }
    public static function qualifies(array $metrics,string $profile): bool
    {
        $definition=self::profiles()[$profile]??throw new \InvalidArgumentException('Unknown profile');
        foreach ($definition['eligibility']??[] as $metric=>$minimum) {
            $value=$metrics[$metric]??null;
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<$minimum) {return false;}
        }
        return true;
    }
    /** Refresh cached weights from the central config, without importing geometry again. */
    public static function refreshScores(\PDO $db,bool $force=false): void
    {
        foreach (self::profiles() as $profile=>$definition) {
            $db->beginTransaction();
            try {
                $db->query('SELECT pg_advisory_xact_lock(73019423)');
                $json=json_encode($definition,JSON_THROW_ON_ERROR);
                $q=$db->prepare('SELECT definition=?::jsonb FROM driving_profiles WHERE slug=?');
                $q->execute([$json,$profile]);
                if ($q->fetchColumn() && !$force) {$db->commit();continue;}
                $db->prepare('INSERT INTO driving_profiles(slug,definition) VALUES (?,?::jsonb)
                    ON CONFLICT(slug) DO UPDATE SET definition=excluded.definition')->execute([$profile,$json]);
                $q=$db->prepare("UPDATE road_scores s SET score=ROUND((100*(0.7+0.3*s.confidence)*
                    COALESCE((SELECT SUM(COALESCE((s.dimensions->>w.key)::double precision,0)*w.value::double precision)
                    FROM jsonb_each_text(?::jsonb) w),0))::numeric,1) WHERE s.profile=?");
                $q->execute([json_encode((object)$definition['weights'],JSON_THROW_ON_ERROR),$profile]);
                $db->commit();echo "Updated cached scores for $profile\n";
            } catch (\Throwable $e) {$db->rollBack();throw $e;}
        }
    }
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
        $confidence=round($confidence,3);
        return ['score'=>round(100*$score*(0.7+0.3*$confidence),1), 'confidence'=>round($confidence,3), 'dimensions'=>$dimensions];
    }
}
