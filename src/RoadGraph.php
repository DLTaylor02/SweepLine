<?php
declare(strict_types=1);
namespace SweepLine;

final class RoadGraph
{
    public const VERSION=2;
    public static function identity(array $road): string
    {
        return strtolower(trim($road['ref'] ?: ($road['name'] ?: '')));
    }
    /** Split at shared OSM nodes, then follow unambiguous, direction-compatible continuations. */
    public static function candidates(array $roads,?callable $checkpoint=null): \Generator
    {
        $uses=[]; $neighbors=[];
        foreach ($roads as $r) {
            if ($checkpoint) { $checkpoint(); }
            foreach (array_unique($r['nodes']) as $n) { $uses[$n]=($uses[$n]??0)+1; }
            for ($i=1;$i<count($r['nodes']);$i++) {
                $a=$r['nodes'][$i-1]; $b=$r['nodes'][$i];
                $neighbors[$a][$b]=true; $neighbors[$b][$a]=true;
            }
        }
        $junctions=[];
        foreach ($neighbors as $node=>$adjacent) { $junctions[$node]=count($adjacent)>2 ? 2 : 1; }
        unset($neighbors);
        $edges=[]; $adj=[];
        foreach ($roads as $r) {
            // Local streets inform junction density but never become discoveries.
            if (!in_array($r['highway'],['motorway','trunk','primary','secondary','tertiary','unclassified'],true)) { continue; }
            $start=0; $last=count($r['nodes'])-1;
            for ($i=1;$i<=$last;$i++) {
                if ($i!==$last && ($uses[$r['nodes'][$i]]??0)<2) { continue; }
                $e=$r; $e['nodes']=array_slice($r['nodes'],$start,$i-$start+1);
                $e['points']=array_slice($r['points'],$start,$i-$start+1); $e['reversed']=false;
                $id=count($edges); $edges[$id]=$e;
                $adj[$e['nodes'][0]][]=$id; $adj[$e['nodes'][count($e['nodes'])-1]][]=$id;
                $start=$i;
            }
        }
        // Seed legal one-way corridors first. Opposite carriageways may share
        // two-way approaches, so global coverage only suppresses redundant seeds.
        $seeds=array_keys($edges);
        usort($seeds,static fn($a,$b)=>(abs($edges[$b]['direction'])<=>abs($edges[$a]['direction']))?:($a<=>$b));
        $visited=[];
        foreach ($seeds as $seed) {
            $edge=$edges[$seed];
            if ($checkpoint) { $checkpoint(); }
            if (isset($visited[$seed])) { continue; }
            $visited[$seed]=true; $local=[$seed=>true]; $chain=[$edge]; $length=Geometry::length($edge['points']);
            // Extend both ends. Reversing the chain preserves each way's legal direction.
            for ($side=0;$side<2;$side++) {
                $direction=self::direction($chain);
                while ($length<40000) {
                    $tail=$chain[count($chain)-1]; $node=$tail['nodes'][count($tail['nodes'])-1];
                    $choices=[]; if($checkpoint){$checkpoint();}
                    foreach (array_unique($adj[$node]??[]) as $id) {
                        if (isset($local[$id])) { continue; }
                        $next=$edges[$id];
                        if ($next['nodes'][0]!==$node) { $next=self::reverse($next); }
                        if (!self::compatible($tail,$next) || ($direction!==0 && $next['direction']!==0 && $direction!==$next['direction'])) { continue; }
                        $choices[$id]=$next;
                    }
                    if (count($choices)!==1) { break; }
                    $id=array_key_first($choices); $next=$choices[$id];
                    $nextLength=Geometry::length($next['points']);
                    if ($length+$nextLength>50000) { break; }
                    $visited[$id]=true; $local[$id]=true; if($next['direction']!==0)$direction=$next['direction']; $chain[]=$next; $length+=$nextLength;
                }
                $chain=array_map([self::class,'reverse'],array_reverse($chain));
            }
            $points=[]; $nodes=[];
            foreach ($chain as $part) {
                $points=array_merge($points,$points ? array_slice($part['points'],1) : $part['points']);
                $nodes=array_merge($nodes,$nodes ? array_slice($part['nodes'],1) : $part['nodes']);
            }
            yield ['parts'=>$chain,'points'=>$points,'nodes'=>$nodes,'uses'=>$junctions];
        }
    }
    /** Legal direction relative to the entire chain, including two-way ends. */
    public static function direction(array $parts): int
    {
        $direction=0;
        foreach($parts as $part){
            $d=(int)$part['direction'];
            if($d!==0){if($direction!==0&&$direction!==$d)throw new \LogicException('Inconsistent road traversal directions.');$direction=$d;}
        }
        return $direction;
    }
    public static function reverse(array $r): array
    {
        $r['points']=array_reverse($r['points']); $r['nodes']=array_reverse($r['nodes']);
        $r['direction']= -$r['direction']; $r['reversed']=!$r['reversed']; return $r;
    }
    public static function compatible(array $a,array $b): bool
    {
        if (self::identity($a)==='' || self::identity($a)!==self::identity($b) || $a['highway']!==$b['highway']) { return false; }
        if ($a['direction']!==0 && $b['direction']!==0 && $a['direction']!==$b['direction']) { return false; }
        $p=$a['points']; $q=$b['points'];
        return abs(Geometry::turn(Geometry::bearing($p[max(0,count($p)-2)],$p[count($p)-1]),Geometry::bearing($q[0],$q[1]))) < 55;
    }
}
