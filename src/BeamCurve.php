<?php
declare(strict_types=1);
namespace SweepLine;

/** Small, bounded corner rounds; source endpoints, sharp turns and junctions remain fixed. */
final class BeamCurve
{
    public const MAX_DEVIATION_M=0.35;
    public static function smooth(array $points,array $anchors=[],array $structures=[]): array
    {
        if(count($points)<3)return $points;$out=[$points[0]];
        for($i=1;$i<count($points)-1;$i++){
            $a=$points[$i-1];$p=$points[$i];$b=$points[$i+1];$la=hypot($p[0]-$a[0],$p[1]-$a[1]);$lb=hypot($b[0]-$p[0],$b[1]-$p[1]);$fixed=false;
            foreach($anchors as $anchor)if(hypot($anchor[0]-$p[0],$anchor[1]-$p[1])<20){$fixed=true;break;}
            foreach($structures as [$c,$d])if(BeamLanes::distance($p,$c,$d)[0]<25){$fixed=true;break;}
            if($fixed||min($la,$lb)<0.5){$out[]=$p;continue;}
            $u=[($p[0]-$a[0])/$la,($p[1]-$a[1])/$la];$v=[($b[0]-$p[0])/$lb,($b[1]-$p[1])/$lb];$angle=acos(max(-1,min(1,$u[0]*$v[0]+$u[1]*$v[1])));
            if($angle<deg2rad(2)||$angle>deg2rad(70)){$out[]=$p;continue;}
            $trim=min(6,$la*0.25,$lb*0.25,2*self::MAX_DEVIATION_M/sin($angle/2));
            $entry=[$p[0]-$u[0]*$trim,$p[1]-$u[1]*$trim];$exit=[$p[0]+$v[0]*$trim,$p[1]+$v[1]*$trim];
            $steps=max(2,(int)ceil(2*$trim/2),(int)ceil(rad2deg($angle)/3));$out[]=$entry;
            for($j=1;$j<=$steps;$j++){$t=$j/$steps;$out[]=[(1-$t)**2*$entry[0]+2*(1-$t)*$t*$p[0]+$t*$t*$exit[0],(1-$t)**2*$entry[1]+2*(1-$t)*$t*$p[1]+$t*$t*$exit[1]];}
            if(count($out)>20000)throw new \RuntimeException('Smoothed road geometry exceeds the BeamNG processing limit; select a shorter stretch.');
        }
        $out[]=end($points);return $out;
    }
}
