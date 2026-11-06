<?php
declare(strict_types=1);
namespace SweepLine;

/** Visual bridge thickness below explicit pavement; the driving collision stays unchanged. */
final class BeamBridgeDeck
{
    public const DEPTH_M=0.5;
    public static function build(array $points,array $sections,array $spans,string $material,float $depth=self::DEPTH_M): array
    {
        $chain=[0.];for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $vertices=[];$faces=[];$count=0;
        $station=static function(float $at)use($chain,$sections){
            for($i=1;$i<count($chain);$i++){
                $length=$chain[$i]-$chain[$i-1];if($length<1e-9||$chain[$i]<$at-1e-7)continue;
                $t=max(0,min(1,($at-$chain[$i-1])/$length));$pair=[];
                foreach([0,1] as $side){$a=$sections[$i-1][$side];$b=$sections[$i][$side];$pair[]=array_map(static fn($axis)=>$a[$axis]+($b[$axis]-$a[$axis])*$t,[0,1,2]);}
                return $pair;
            }throw new \RuntimeException('Bridge deck station is outside the pavement.');
        };
        foreach($spans as $span){
            $distances=[$span['start_m']];
            foreach($chain as $at)if($at>$span['start_m']+1e-7&&$at<$span['end_m']-1e-7)$distances[]=$at;
            $distances[]=$span['end_m'];$first=count($vertices);$previous=null;
            foreach($distances as $at){
                [$left,$right]=$station($at);$base=count($vertices);
                array_push($vertices,[$left[0],$left[1],$left[2]-0.005],[$right[0],$right[1],$right[2]-0.005],
                    [$right[0],$right[1],$right[2]-$depth],[$left[0],$left[1],$left[2]-$depth]);
                if($previous!==null)for($j=0;$j<4;$j++){$k=($j+1)%4;$faces[]=[$previous+$j,$previous+$k,$base+$k];$faces[]=[$previous+$j,$base+$k,$base+$j];}
                $previous=$base;
            }
            $faces[]=[$first+3,$first+2,$first+1];$faces[]=[$first+3,$first+1,$first];
            $faces[]=[$previous,$previous+1,$previous+2];$faces[]=[$previous,$previous+2,$previous+3];$count++;
        }
        return ['xml'=>BeamAssets::mesh($vertices,$faces,$material),'spans'=>$count,'depth_m'=>$depth,'collision'=>'Existing pavement only; shell is visual'];
    }

    /** Closed collision barriers share the deck edge, with foundations extending into its slab. */
    public static function barriers(array $points,array $sections,array $span,string $material): array
    {
        $chain=[0.];for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $stations=[$span['start_m']];foreach($chain as $at)if($at>$span['start_m']+1e-7&&$at<$span['end_m']-1e-7)$stations[]=$at;$stations[]=$span['end_m'];
        $rings=[];
        foreach($stations as $at)for($i=1;$i<count($chain);$i++){
            $length=$chain[$i]-$chain[$i-1];if($length<1e-9||$chain[$i]<$at-1e-7)continue;
            $t=max(0,min(1,($at-$chain[$i-1])/$length));$pair=[];
            foreach([0,1] as $side){$a=$sections[$i-1][$side];$b=$sections[$i][$side];$pair[]=array_map(static fn($axis)=>$a[$axis]+($b[$axis]-$a[$axis])*$t,[0,1,2]);}
            $rings[]=$pair;break;
        }
        $out=[];
        foreach([0=>'left',1=>'right'] as $side=>$name){
            $v=[];$faces=[];$previous=null;
            foreach($rings as $ring){
                $edge=$ring[$side];$other=$ring[1-$side];$width=hypot($other[0]-$edge[0],$other[1]-$edge[1]);$f=min(0.3/max(0.001,$width),0.5);
                $inner=[$edge[0]+($other[0]-$edge[0])*$f,$edge[1]+($other[1]-$edge[1])*$f,$edge[2]+($other[2]-$edge[2])*$f];$base=count($v);
                $a=$side===0?$edge:$inner;$b=$side===0?$inner:$edge;
                array_push($v,[$a[0],$a[1],$a[2]+1.1],[$b[0],$b[1],$b[2]+1.1],[$b[0],$b[1],$b[2]-0.5],[$a[0],$a[1],$a[2]-0.5]);
                if($previous!==null)for($j=0;$j<4;$j++){$k=($j+1)%4;$faces[]=[$previous+$j,$previous+$k,$base+$k];$faces[]=[$previous+$j,$base+$k,$base+$j];}
                $previous=$base;
            }
            $faces[]=[3,2,1];$faces[]=[3,1,0];$faces[]=[$previous,$previous+1,$previous+2];$faces[]=[$previous,$previous+2,$previous+3];
            $out[$name]=BeamAssets::mesh($v,$faces,$material);
        }
        return $out;
    }

}
