<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamLanes
{
    public static function infer(array $tags,string $highway,int $direction,bool $aligned=true): array
    {
        $number=static fn($v)=>is_scalar($v)&&preg_match('/^[1-9][0-9]?$/D',(string)$v)?min(12,(int)$v):null;
        $lanes=$number($tags['lanes']??null);$forward=$number($tags['lanes:forward']??null);$backward=$number($tags['lanes:backward']??null);
        $oneway=$direction!==0 || in_array($tags['oneway']??'', ['yes','1','-1','true'],true);
        $estimated=$lanes===null;
        if($lanes===null)$lanes=$forward!==null&&$backward!==null?$forward+$backward:($oneway&&in_array($highway,['motorway','trunk'],true)?2:($oneway?1:2));
        $lanes=max(1,min(12,$lanes));
        if($oneway){$forward=$lanes;$backward=0;}
        else {
            $forward=$forward??($backward!==null?$lanes-$backward:(int)ceil($lanes/2));
            $backward=$backward??max(0,$lanes-$forward);
            if(!$aligned)[$forward,$backward]=[$backward,$forward];
        }
        $width=null;
        if(preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(m|meters?|ft|feet)?$/i',trim((string)($tags['width']??'')),$m)) {
            $width=(float)$m[1]*(in_array(strtolower($m[2]??''),['ft','feet'],true)?0.3048:1);
            if($width<$lanes*2.4 || $width>60)$width=null;
        }
        return ['lanes'=>$lanes,'forward'=>$forward,'backward'=>$backward,'oneway'=>$oneway,'width_m'=>$width??($lanes*3.5+1.0),'estimated_lanes'=>$estimated,'estimated_width'=>$width===null];
    }
    public static function distance(array $p,array $a,array $b): array
    {
        $dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=$dx*$dx+$dy*$dy;
        $t=$length?max(0,min(1,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$length)):0;
        return [hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy),$dx,$dy];
    }
    public static function profiles(array $points,array $sources,array $bounds): array
    {
        $index=[];$segments=[];$entries=0;
        foreach($sources as $source)for($i=1;$i<count($source['points']);$i++) {
            $a=$source['points'][$i-1];$b=$source['points'][$i];
            if(max($a[0],$b[0])<$bounds[0] || min($a[0],$b[0])>$bounds[2] || max($a[1],$b[1])<$bounds[1] || min($a[1],$b[1])>$bounds[3])continue;
            $id=count($segments);$segments[]=[$a,$b,$source];
            for($x=(int)floor(max($bounds[0],min($a[0],$b[0]))/100);$x<=(int)floor(min($bounds[2],max($a[0],$b[0]))/100);$x++)
                for($y=(int)floor(max($bounds[1],min($a[1],$b[1]))/100);$y<=(int)floor(min($bounds[3],max($a[1],$b[1]))/100);$y++) {
                    $index[$x.':'.$y][]=$id;if(++$entries>200000)throw new \RuntimeException('Lane-attribute geometry limit reached.');
                }
        }
        $out=[];
        foreach($points as $i=>$p) {
            $best=INF;$chosen=null;$vector=null;$x=(int)floor($p[0]/100);$y=(int)floor($p[1]/100);
            for($dx=-1;$dx<=1;$dx++)for($dy=-1;$dy<=1;$dy++)foreach($index[($x+$dx).':'.($y+$dy)]??[] as $id) {
                [$a,$b,$source]=$segments[$id];$distance=self::distance($p,$a,$b);
                if($distance[0]<$best){$best=$distance[0];$chosen=$source;$vector=$distance;}
            }
            $next=$points[min(count($points)-1,$i+1)];$prev=$points[max(0,$i-1)];
            $aligned=$vector===null || ($next[0]-$prev[0])*$vector[1]+($next[1]-$prev[1])*$vector[2]>=0;
            $profile=self::infer($chosen['tags']??[],$chosen['highway']??'secondary',(int)($chosen['direction']??0),$aligned);
            $profile['legal_forward']=!$profile['oneway']||(((int)($chosen['direction']??0)?:((($chosen['tags']['oneway']??'')==='-1')?-1:1))*($aligned?1:-1)>0);
            $out[]=$profile;
        }
        return $out;
    }
}

