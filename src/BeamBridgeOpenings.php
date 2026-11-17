<?php
declare(strict_types=1);
namespace SweepLine;

/** Actual at-grade pavement intersections with a bridge edge, in bridge chainage. */
final class BeamBridgeOpenings
{
    public static function intervals(array $road,array $sections,array $other,array $profiles,int $side,array $window,array $heightRange=[-.5,.5],array $extraTriangles=[]): array
    {
        $segments=[];$vertices=[];$along=0.;
        for($i=1;$i<count($road);$i++){
            $start=$along;$along+=hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
            if($along<=$window[0]||$start>=$window[1]||$along-$start<1e-8)continue;
            $a=$sections[$i-1][$side];$b=$sections[$i][$side];$segments[]=[$a,$b,$start,$along];array_push($vertices,$a,$b);
        }
        if(!$segments||count($other)<2)return [];
        $cutters=array_merge(BeamJunctionBoundary::cutters($other,$profiles,$vertices),$extraTriangles);$intervals=[];
        foreach($segments as [$a,$b,$start,$end])foreach($cutters as $triangle){
            if(max($a[0],$b[0])<min(array_column($triangle,0))||min($a[0],$b[0])>max(array_column($triangle,0))||max($a[1],$b[1])<min(array_column($triangle,1))||min($a[1],$b[1])>max(array_column($triangle,1)))continue;
            $lo=max(0.,($window[0]-$start)/($end-$start));$hi=min(1.,($window[1]-$start)/($end-$start));
            foreach([0,1,2] as $j){
                $c=$triangle[$j];$d=$triangle[($j+1)%3];$v=BeamPolygon::cross($c,$d,$a);$delta=BeamPolygon::cross($c,$d,$b)-$v;
                if(abs($delta)<1e-10){if($v< -1e-7){$hi=-1;break;}continue;}
                $t=-$v/$delta;if($delta>0)$lo=max($lo,$t);else $hi=min($hi,$t);
            }
            if($hi-$lo<1e-7)continue;
            $wa=BeamTerrainContact::weights($a,$triangle);$wb=BeamTerrainContact::weights($b,$triangle);if(!$wa||!$wb)continue;
            $za=$a[2];$zb=$b[2];foreach([0,1,2] as $j){$za-=$wa[$j]*$triangle[$j][2];$zb-=$wb[$j]*$triangle[$j][2];}
            $delta=$zb-$za;
            if(abs($delta)<1e-10){if($za<$heightRange[0]||$za>$heightRange[1])continue;}
            else{$x=($heightRange[0]-$za)/$delta;$y=($heightRange[1]-$za)/$delta;$lo=max($lo,min($x,$y));$hi=min($hi,max($x,$y));}
            if($hi-$lo>1e-7)$intervals[]=[max($window[0],$start+$lo*($end-$start)-.15),min($window[1],$start+$hi*($end-$start)+.15)];
        }
        usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);$out=[];
        foreach($intervals as $part){$last=count($out)-1;if($last>=0&&$part[0]<=$out[$last][1]+.02)$out[$last][1]=max($out[$last][1],$part[1]);else $out[]=$part;}
        return $out;
    }
    public static function junction(array $road,array $profiles,array $branch,array $join): array
    {
        $points=array_merge($branch['connector']??[],$branch['points']);
        $cp=$branch['profiles'][0];unset($cp['section_override']);$cps=[];
        foreach($branch['connector']??[] as $i=>$point)$cps[]=$cp+(isset($branch['connector_sections'][$i])?['section_override'=>$branch['connector_sections'][$i]]:[]);
        $p=array_merge($cps,$branch['profiles']);
        $sections=BeamJunctionBoundary::sections($road,$profiles);$out=[];
        foreach([0=>'left',1=>'right'] as $side=>$name)$out[$name]=self::intervals($road,$sections,$points,$p,$side,
            [max(0.,$join['along']-max($join['radius'],empty($branch['junction_fill'])?0.:30.48)-1),$join['along']+max($join['radius'],empty($branch['junction_fill'])?0.:30.48)+1],[-.5,.5],$branch['junction_fill']??[]);
        return $out;
    }
}
