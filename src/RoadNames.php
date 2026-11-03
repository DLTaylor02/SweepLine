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
    public static function prefer(array $road,mixed $name): array
    {
        if($name===null)return $road;
        if(!is_string($name)||strlen($name)>512)throw new \InvalidArgumentException('Choose a mapped primary or alternate road name.');
        $name=trim($name);$choices=array_merge([$road['name']],self::collect(array_merge([$road],$road['parts']??[]),$road['name']));
        if(!in_array($name,$choices,true))throw new \InvalidArgumentException('Choose a mapped primary or alternate road name.');
        $road['alternate_names']=self::collect(array_merge([$road],$road['parts']??[]),$name);$road['name']=$name;return $road;
    }
    public static function description(array $road): string
    {
        $names=self::collect([$road],$road['name']);
        return $names?'Also known as: '.implode('; ',$names):'';
    }
}

