<?php
declare(strict_types=1);
namespace SweepLine;

/** Approximate road-shape notes, independent of OSM way boundaries. */
final class RoadNotes
{
    private const SPACING=15.0;
    private const MIN_ANGLE=15.0;
    public static function analyze(array $points): array
    {
        $clean=[];
        foreach ($points as $p) {
            if (!is_array($p) || count($p)<2 || !is_numeric($p[0]) || !is_numeric($p[1]) ||
                !is_finite((float)$p[0]) || !is_finite((float)$p[1]) || abs((float)$p[0])>180 || abs((float)$p[1])>90) {
                throw new \InvalidArgumentException('Invalid road coordinates');
            }
            $p=[(float)$p[0],(float)$p[1]];
            if (!$clean || Geometry::distance($clean[count($clean)-1],$p)>0.01) {$clean[]=$p;}
        }
        if (count($clean)<2) {throw new \InvalidArgumentException('Road has no measurable length');}
        $length=Geometry::length($clean);
        $sample=Geometry::sample($clean,self::SPACING);
        $end=$clean[count($clean)-1];
        if (Geometry::distance($sample[count($sample)-1],$end)>0.01) {$sample[]=$end;}
        $last=count($sample)-1;$headings=[];
        // A ~60 m chord reduces effects of tiny mapping zigzags and uneven sampling.
        foreach ($sample as $i=>$_) {
            $headings[]=Geometry::bearing($sample[max(0,$i-2)],$sample[min($last,$i+2)]);
        }
        $notes=[];$active=null;$previous=0.0;
        $finish=static function() use (&$active,&$notes,&$previous,$length): void {
            if ($active!==null && abs($active['angle'])>=self::MIN_ANGLE) {
                $angle=(int)(round(abs($active['angle'])/5)*5);
                $side=$active['angle']>0?'right':'left';
                $description=match(true) {
                    $angle>=260=>'looping '.$side.' bend',
                    $angle>=150=>$side.' hairpin',
                    $angle>=100=>'sharp '.$side.' bend',
                    $angle>=45=>$side.' bend',
                    default=>'gentle '.$side.' bend'
                };
                $distance=max($previous,min($length,$active['start']));
                $notes[]=['distance_m'=>$distance,'distance_since_previous_m'=>$distance-$previous,
                    'end_distance_m'=>min($length,$active['end']),'angle_degrees'=>$angle,
                    'direction'=>$side,'description'=>ucfirst($description)];
                $previous=$distance;
            }
            $active=null;
        };
        for ($i=1;$i<=$last;$i++) {
            $delta=Geometry::turn($headings[$i-1],$headings[$i]);
            if (abs($delta)<0.5) {
                if ($active!==null && $i-$active['last']>=4) {$finish();}
                continue;
            }
            if ($active!==null && (($delta>0)!==($active['angle']>0))) {$finish();}
            if ($active===null) {
                $active=['start'=>max(0,($i-2)*self::SPACING),'end'=>0.0,'angle'=>0.0,'last'=>$i];
            }
            $active['angle']+=$delta;$active['last']=$i;
            $active['end']=min($length,($i+2)*self::SPACING);
        }
        $finish();
        return ['length_m'=>$length,'notes'=>$notes];
    }
    public static function instructions(array $points,array $analysis): array
    {
        $coord=static fn($p)=>sprintf('%.6f, %.6f',$p[1],$p[0]);
        $lines=['0.00 mi: Start at '.$coord($points[0]).'.'];
        foreach ($analysis['notes'] as $note) {
            $lines[]=sprintf('%.2f mi (+%.2f mi): %s, %d degrees.',
                $note['distance_m']/1609.344,$note['distance_since_previous_m']/1609.344,
                $note['description'],$note['angle_degrees']);
        }
        if (!$analysis['notes']) {$lines[]='No bends of 15 degrees or more detected in the mapped geometry.';}
        $lines[]=sprintf('%.2f mi: End of selected stretch at %s.',
            $analysis['length_m']/1609.344,$coord($points[count($points)-1]));
        return $lines;
    }
}
