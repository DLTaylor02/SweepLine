<?php
declare(strict_types=1);
namespace SweepLine;
/** Bounded projected-metre nearest-segment lookup for export preparation. */
final class BeamRoadIndex
{
    private array $segments=[],$cells=[];
    public function __construct(array $lines)
    {
        $entries=0;
        foreach($lines as $line){$along=0.;
            for($i=1;$i<count($line['points']);$i++){
                $a=$line['points'][$i-1];$b=$line['points'][$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);if($length<1e-7)continue;
                $id=count($this->segments);$this->segments[]=[$a,$b,$length,$along,$i,$line];
                for($x=(int)floor(min($a[0],$b[0])/100);$x<=(int)floor(max($a[0],$b[0])/100);$x++)
                for($y=(int)floor(min($a[1],$b[1])/100);$y<=(int)floor(max($a[1],$b[1])/100);$y++){
                    if(++$entries>250000)throw new \RuntimeException('Carriageway spatial-index budget exceeded.');
                    $this->cells[$x.':'.$y][]=$id;
                }$along+=$length;
            }
        }
    }
    public function nearest(array $p,float $radius=200): ?array
    {
        $cx=(int)floor($p[0]/100);$cy=(int)floor($p[1]/100);$n=(int)ceil($radius/100);$best=$radius;$out=null;$seen=[];
        for($x=$cx-$n;$x<=$cx+$n;$x++)for($y=$cy-$n;$y<=$cy+$n;$y++)foreach($this->cells[$x.':'.$y]??[] as $id){
            if(isset($seen[$id]))continue;$seen[$id]=true;[$a,$b,$length,$along,$i,$line]=$this->segments[$id];
            $dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$raw=(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/($length*$length);$t=max(0,min(1,$raw));
            $d=hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy);
            if($d<$best){$best=$d;$out=['distance'=>$d,'along'=>$along+$t*$length,'raw'=>$raw,'segment'=>$i,'fraction'=>$t,'dx'=>$dx/$length,'dy'=>$dy/$length,'line'=>$line];}
        }return $out;
    }
}
