<?php
declare(strict_types=1);
namespace SweepLine;
final class RoadNames
{
    public static function collect(array $roads,string $primary): array
    {
        $out=[];$seen=[strtolower(trim($primary))=>true];
        foreach ($roads as $road) {
            $values=array_merge([$road['name']??null,$road['ref']??null],$road['alternate_names']??[]);
            foreach (['alt_name','official_name','loc_name','short_name','old_name','name:en'] as $key) {$values[]=$road['tags'][$key]??null;}
            foreach ($values as $value) {
                if (!is_string($value)) {continue;}
                foreach (explode(';',$value) as $name) {
                    $name=trim($name);$key=strtolower($name);
                    if ($name!=='' && $key!=='unnamed road' && !isset($seen[$key])) {$seen[$key]=true;$out[]=$name;}
                }
            }
        }
        return $out;
    }
    public static function description(array $road): string
    {
        $names=self::collect([$road],$road['name']);
        return $names?'Also known as: '.implode('; ',$names):'';
    }
}

