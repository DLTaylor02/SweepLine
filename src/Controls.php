<?php
declare(strict_types=1);
namespace SweepLine;

final class Controls
{
    public static function analyze(array $nodes, array $tags, array $uses): array
    {
        $mandatory=0; $uncertain=0; $intersections=0;
        foreach (array_unique($nodes) as $node) {
            $t=$tags[$node]??[];
            if (($uses[$node]??0)>1) { $intersections++; }
            if (($t['highway']??'')==='traffic_signals') {
                // At a shared node the signal's approach scope may be ambiguous.
                if (($uses[$node]??0)>1 || isset($t['traffic_signals:direction'])) { $uncertain++; }
                else { $mandatory++; }
            }
            if (($t['highway']??'')==='stop') {
                if (($t['stop']??'')==='all') { $mandatory++; }
                else { $uncertain++; }
            }
            if (isset($t['barrier']) || in_array($t['access']??'', ['private','no'],true)) { $uncertain++; }
        }
        return ['mandatory_stops'=>$mandatory,'uncertain_controls'=>$uncertain,'intersections'=>$intersections];
    }
}
