<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamStructures
{
    public const MAX_LENGTH_M=2000;
    public const SIMPLE_LENGTH_M=200;
    private static function tagged(array $tags,string $key): bool {return isset($tags[$key])&&!in_array(strtolower((string)$tags[$key]),["no","false","0"],true);}
    /** Match collinear overlap, not merely a crossing or proximity. Distances are planar meters. */
    public static function spans(array $road,array $sources,bool $allowTunnels=false): array
    {
        $structures=[];
        foreach($sources as $source){$tags=$source["tags"]??[];if(!self::tagged($tags,"bridge")&&!self::tagged($tags,"tunnel"))continue;
            for($j=1;$j<count($source["points"]);$j++)$structures[]=[$source["points"][$j-1],$source["points"][$j],$tags];}
        if(!$structures)return [];
        $hits=[];$chain=0.0;$checks=0;
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);if($length<0.001)continue;
            foreach($structures as [$c,$d,$tags]){
                if(++$checks>2000000)throw new \InvalidArgumentException("Structure geometry is too complex for this export.");
                if(max($c[0],$d[0])<min($a[0],$b[0])-0.1||min($c[0],$d[0])>max($a[0],$b[0])+0.1||max($c[1],$d[1])<min($a[1],$b[1])-0.1||min($c[1],$d[1])>max($a[1],$b[1])+0.1)continue;
                $crossC=abs(($c[0]-$a[0])*$dy-($c[1]-$a[1])*$dx)/$length;$crossD=abs(($d[0]-$a[0])*$dy-($d[1]-$a[1])*$dx)/$length;
                if($crossC>0.1||$crossD>0.1)continue;
                $tc=(($c[0]-$a[0])*$dx+($c[1]-$a[1])*$dy)/$length;$td=(($d[0]-$a[0])*$dx+($d[1]-$a[1])*$dy)/$length;
                $lo=max(0,min($tc,$td));$hi=min($length,max($tc,$td));if($hi-$lo<0.01)continue;
                if(self::tagged($tags,"tunnel")&&$allowTunnels)continue;
                if(self::tagged($tags,"tunnel"))throw new \InvalidArgumentException("The selected stretch includes a mapped tunnel. Terrain openings and tunnel interiors are not supported yet.");
                if(!in_array($tags["bridge"]??"",["yes","viaduct"],true) || !in_array($tags["bridge:structure"]??"beam",["beam","slab"],true) || isset($tags["bridge:movable"]) || abs((float)($tags["layer"]??0))>1)
                    throw new \InvalidArgumentException("The selected stretch includes a complex bridge. Only fixed beam/slab bridges and viaducts can currently be estimated.");
                $hits[]=[$chain+$lo,$chain+$hi];
            }
            $chain+=$length;
        }
        usort($hits,static fn($a,$b)=>$a[0]<=>$b[0]);$spans=[];
        foreach($hits as [$lo,$hi]){$last=count($spans)-1;if($last>=0&&$lo<=$spans[$last]["end_m"]+0.2)$spans[$last]["end_m"]=max($spans[$last]["end_m"],$hi);else $spans[]=["start_m"=>$lo,"end_m"=>$hi];}
        if(count($spans)>16)throw new \InvalidArgumentException("This export exceeds the 16-bridge limit.");
        foreach($spans as &$span){$span["length_m"]=$span["end_m"]-$span["start_m"];
            if($span["length_m"]<8)throw new \InvalidArgumentException("A selected bridge is shorter than the reliable eight-meter deck sampling limit.");
            if($span["length_m"]>self::MAX_LENGTH_M)throw new \InvalidArgumentException("A selected bridge is longer than the 2000-meter estimated-bridge export limit.");
            $span["estimated"]=true;$span["large"]=$span["length_m"]>self::SIMPLE_LENGTH_M;
            $span["approach_m"]=$span["large"]?50:25;
            if($span["start_m"]<$span["approach_m"]||$chain-$span["end_m"]<$span["approach_m"])throw new \InvalidArgumentException("A selected bridge lacks the required approach road at both ends (25 meters for short bridges, 50 meters for large bridges). Choose a longer stretch that includes both approaches.");
            $a=self::at($road,$span["start_m"]);$b=self::at($road,$span["end_m"]);
            if(hypot($b[0]-$a[0],$b[1]-$a[1])/$span["length_m"]<($span["large"]?0.85:0.95))throw new \InvalidArgumentException("A selected bridge is too curved for a simple estimated deck.");
        }unset($span);
        return $spans;
    }
    public static function at(array $road,float $distance): array
    {
        $chain=0.0;
        for($i=1;$i<count($road);$i++){$a=$road[$i-1];$b=$road[$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);if($chain+$length>=$distance&&$length>0){$t=($distance-$chain)/$length;return [$a[0]+$t*($b[0]-$a[0]),$a[1]+$t*($b[1]-$a[1])];}$chain+=$length;}
        return array_slice($road[count($road)-1],0,2);
    }
    public static function indices(array $chain,array $span): array
    {
        $start=0;while($start<count($chain)-1&&$chain[$start]<$span["start_m"])$start++;
        $end=count($chain)-1;while($end>0&&$chain[$end]>$span["end_m"])$end--;
        return [$start,$end];
    }
    /** Replace valley-floor samples only around bridge spans; ordinary road analysis is unchanged. */
    public static function deckSamples(array $points,array $chain,array $heights,array $spans,BeamRaster $raster): array
    {
        foreach($spans as $span){$anchor=!empty($span["large"])?50:20;$lo=max(0,$span["start_m"]-$anchor);$hi=min($chain[count($chain)-1],$span["end_m"]+$anchor);
            $a=self::at($points,$lo);$b=self::at($points,$hi);$za=$raster->roadHeight(...$a);$zb=$raster->roadHeight(...$b);
            if(abs($zb-$za)/max(1,$hi-$lo)>0.12)throw new \RuntimeException("Bridge approach elevations imply a grade above 12%; a reliable deck cannot be estimated.");
            foreach($chain as $i=>$distance)if($distance>=$lo&&$distance<=$hi){$z=$za+($zb-$za)*(($distance-$lo)/($hi-$lo));
                if($distance>=$span["start_m"]&&$distance<=$span["end_m"]&&$raster->roadHeight(...$points[$i])>$z+0.15)throw new \RuntimeException("Terrain rises above the estimated bridge deck. Accurate structure elevations are required for this bridge.");
                if($distance>=$span["start_m"]&&$distance<=$span["end_m"]&&$z-$raster->roadHeight(...$points[$i])>150)throw new \RuntimeException("Estimated bridge clearance exceeds 150 meters; surveyed structure elevations are required.");
                $heights[$i]=$z;
            }
        }
        return $heights;
    }
}
