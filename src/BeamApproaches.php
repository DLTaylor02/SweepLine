<?php
declare(strict_types=1);
namespace SweepLine;
/** Bounded physical continuation; never invent a straight extension or choose a fork. */
final class BeamApproaches {
    public static function extend(array $points,array $sources,float $before,float $after): array {
        $key=static fn(array $p)=>sprintf('%.4f,%.4f',$p[0],$p[1]);
        $edges=[];$vertices=[];$ends=[$points[0],$points[count($points)-1]];
        $pointBudget=0;
        foreach($sources as $source){
            $pointBudget+=count($source['points']);
            if($pointBudget>40000)throw new \InvalidArgumentException('Bridge approach source geometry exceeds the processing budget. Select a longer stretch explicitly.');
            $line=$source['points'];$direction=(int)($source['direction']??0);
            for($i=1;$i<count($line);$i++){
                $a=$line[$i-1];$b=$line[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l2=$dx*$dx+$dy*$dy;if($l2<0.000001)continue;
                $cuts=[0=>$a,1000000=>$b];
                foreach($ends as $p){$t=(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$l2;
                    if($t>=0&&$t<=1&&hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy)<0.1)$cuts[(int)round($t*1000000)]=$p;
                }
                ksort($cuts);$cuts=array_values($cuts);
                for($j=1;$j<count($cuts);$j++){
                    $u=$key($cuts[$j-1]);$v=$key($cuts[$j]);if($u===$v)continue;
                    $vertices[$u]=$cuts[$j-1];$vertices[$v]=$cuts[$j];
                    if($direction>=0)$edges[$u][$v]=true;
                    if($direction<=0)$edges[$v][$u]=true;
                }
            }
        }
        $incoming=[];foreach($edges as $u=>$neighbors)foreach($neighbors as $v=>$_)$incoming[$v][$u]=true;
        $walk=static function(bool $start,float $needed)use($points,$vertices,$edges,$incoming,$key):array{
            if($needed<=0)return [];
            if($needed>300)throw new \InvalidArgumentException('Bridge approach extension exceeds the 984 ft per-end limit.');
            $ordered=$start?$points:array_reverse($points);$at=$ordered[0];$inside=$ordered[1];$heading=[$at[0]-$inside[0],$at[1]-$inside[1]];
            $visited=[];foreach($points as $p)$visited[$key($p)]=true;
            $path=[];$length=0.;$adj=$start?$incoming:$edges;
            for($step=0;$step<2000&&$length<$needed;$step++){
                $choices=[];
                foreach($adj[$key($at)]??[] as $next=>$_){
                    if(isset($visited[$next]))continue;$p=$vertices[$next];$d=[$p[0]-$at[0],$p[1]-$at[1]];$distance=hypot(...$d);
                    $cos=($d[0]*$heading[0]+$d[1]*$heading[1])/max(0.000001,$distance*hypot(...$heading));
                    if($cos>=0.5)$choices[]=[$p,$d,$distance,$next];
                }
                if(count($choices)!==1)throw new \InvalidArgumentException('Bridge needs more approach road at the '.($start?'start':'finish').', but local road data has '.($choices?'an ambiguous junction':'no supported continuous extension').'. Refresh the region or choose a longer stretch.');
                [$p,$d,$distance,$next]=$choices[0];$take=min($distance,$needed-$length);$t=$take/$distance;
                $at=[$at[0]+$d[0]*$t,$at[1]+$d[1]*$t];$path[]=$at;$length+=$take;$visited[$next]=true;$heading=$d;
            }
            if($length+0.001<$needed)throw new \InvalidArgumentException('Bridge approach continuation exceeds the geometry processing limit.');
            return $path;
        };
        $head=$walk(true,$before);$tail=$walk(false,$after);
        return array_merge(array_reverse($head),$points,$tail);
    }
    public static function needed(array $points,array $spans): array {
        $length=0.;for($i=1;$i<count($points);$i++)$length+=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $before=0.;$after=0.;
        foreach($spans as $s){$required=$s['approach_m'];
            if($s['start_m']<$required)$before=max($before,$required-$s['start_m']+5);
            if($length-$s['end_m']<$required)$after=max($after,$required-($length-$s['end_m'])+5);
        }
        return [(float)$before,(float)$after];
    }
}
