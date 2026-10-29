<?php
declare(strict_types=1);
namespace SweepLine;
final class Surface
{
    public const PAVED=['asphalt','paved','concrete','concrete:plates','concrete:lanes','chipseal','paving_stones','paving_stones:lanes','sett','cobblestone','unhewn_cobblestone'];
    public const UNPAVED=['unpaved','gravel','fine_gravel','compacted','dirt','earth','ground','sand','mud','grass','pebblestone','rock','rocks','unsealed'];
    public static function classify(array $surfaces): string
    {
        $unknown=!$surfaces;
        foreach ($surfaces as $surface) {
            $value=strtolower(trim((string)$surface));
            if (in_array($value,self::UNPAVED,true)) {return 'unpaved';}
            if (!in_array($value,self::PAVED,true)) {$unknown=true;}
        }
        return $unknown?'unknown':'paved';
    }
    public static function allowed(string $status,string $policy): bool
    {
        return $policy==='any' || ($policy==='known_paved'?$status==='paved':$status!=='unpaved');
    }
}
