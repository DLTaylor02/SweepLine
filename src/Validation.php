<?php
declare(strict_types=1);
namespace SweepLine;

final class Validation
{
    public static function zip(mixed $zip): string
    {
        if (!is_string($zip) || !preg_match('/^\d{5}$/D',$zip)) { throw new \InvalidArgumentException('ZIP must contain exactly five digits'); }
        return $zip;
    }
    public static function number(array $input,string $key,float $default,float $min,float $max): float
    {
        $value=$input[$key]??$default;
        if (!is_scalar($value) || !is_numeric($value) || !is_finite((float)$value) || (float)$value<$min || (float)$value>$max) { throw new \InvalidArgumentException("$key must be between $min and $max"); }
        return (float)$value;
    }
    public static function search(array $input): array
    {
        $profile=$input['profile']??'twisty';
        if (!is_string($profile) || !isset(Scorer::profiles()[$profile])) { throw new \InvalidArgumentException('Unknown driving profile'); }
        $paved=$input['paved_only']??true;
        if (!is_bool($paved)) { throw new \InvalidArgumentException('paved_only must be a JSON boolean'); }
        $max=self::number($input,'max_stops',5,0,100);
        if (floor($max)!==$max) { throw new \InvalidArgumentException('max_stops must be an integer'); }
        $minimum=self::number($input,'min_length_miles',0,0,30);
        $maximum=isset($input['max_length_miles']) ? self::number($input,'max_length_miles',0,0,150) : null;
        if ($maximum!==null && $maximum<=$minimum) {
            throw new \InvalidArgumentException('Maximum length must be greater than minimum length');
        }
        $radius=self::number($input,'radius_miles',50,1,50);
        if (floor($radius)!==$radius) { throw new \InvalidArgumentException('radius_miles must be an integer between 1 and 50'); }
        return ['zip'=>self::zip($input['zip']??null),'radius_miles'=>$radius,
            'min_length_miles'=>$minimum,'max_length_miles'=>$maximum,
            'max_stops'=>(int)$max,'profile'=>$profile,'paved_only'=>$paved];
    }
}
