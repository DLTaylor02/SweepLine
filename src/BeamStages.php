<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamStages
{
    public const LABELS=['Queued','Road network','Elevation','Aerial imagery','Geographic context','Terrain analysis','Road shaping','Water','Structures and median','Terrain materials','Roads, traffic and events','Scenery','Packaging'];
    public static function index(string $message,int $previous=0): int
    {
        $patterns=[12=>'Finalizing.*ZIP',11=>'Placing regional scenery',10=>'Building road surfaces',
            9=>'Writing collision|Blending bounded aerial|Writing terrain materials',8=>'Building bridges|Updating tunnel seams|Building exact pavement-edge median',
            7=>'Preparing mapped water surfaces',6=>'Shaping the road|Checking and removing terrain',
            5=>'Validating elevation|Classifying grass|aerial shadows',4=>'Preparing geographic context|Preparing terrain|Fetching mapped water|water boundaries|building|guardrail|intersecting road',
            3=>'NAIP|aerial|Aligning aerial',2=>'USGS elevation|Aligning dem',1=>'Matching.*(carriageway|road network)'];
        foreach($patterns as $index=>$pattern)if(preg_match('/'.$pattern.'/i',$message))return max($previous,$index);
        return $previous;
    }
}
