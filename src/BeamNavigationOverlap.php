<?php
declare(strict_types=1);
namespace SweepLine;

/** Give coincident AI road traces a shared segmentation without changing pavement. */
final class BeamNavigationOverlap
{
    private const GRID_M=32.;
    private const MIN_OVERLAP_M=0.05;
    private const ENDPOINT_SNAP_M=0.05;

    public static function merge(array $objects,array $segments,callable $progress): array
    {
        $nodes=[];$parents=[];$members=[];
        foreach($objects as $o)if(($o['class']??'')==='BeamNGWaypoint'){$name=$o['name'];$nodes[$name]=$o;$parents[$name]=$name;}
        $find=static function(string $name)use(&$parents):string{
            $root=$name;while($parents[$root]!==$root)$root=$parents[$root];
            while($parents[$name]!==$name){$next=$parents[$name];$parents[$name]=$root;$name=$next;}return $root;
        };
        $blocked=0;
        $union=static function(string $a,string $b)use(&$parents,&$members,$find,$nodes,&$blocked):void{
            $a=$find($a);$b=$find($b);if($a===$b)return;
            if(strcmp($a,$b)>0)[$a,$b]=[$b,$a];$group=array_merge($members[$a]??[$a],$members[$b]??[$b]);$target=$nodes[$a];
            foreach($group as $name){$node=$nodes[$name];
                if(hypot($node['position'][0]-$target['position'][0],$node['position'][1]-$target['position'][1])>BeamNavigation::XY_ERROR_M||abs($node['position'][2]-$target['position'][2])>BeamNavigation::Z_ERROR_M||self::radiusError($node,$target)>BeamNavigation::RADIUS_ERROR_M){$blocked++;return;}
            }
            $parents[$b]=$a;$members[$a]=$group;unset($members[$b]);
        };
        $keys=array_keys($segments);$grid=[];$signatures=[];$cuts=[];
        foreach($keys as $i=>$key){
            $edge=$segments[$key];
            if(count($edge['nodes'])!==2||!isset($nodes[$edge['nodes'][0]],$nodes[$edge['nodes'][1]]))throw new \RuntimeException('Navigation overlap references an invalid waypoint pair.');
            [$a,$b]=array_map(static fn($name)=>$nodes[$name]['position'],$edge['nodes']);
            $signatures[$i]=[self::attributes($edge,false),self::attributes($edge,true)];
            foreach(self::bins($a,$b) as $bin)$grid[$bin]=($grid[$bin]??'').pack('V',$i);
        }
        $insert=static function(int $edgeId,string $name)use($keys,$segments,$nodes,&$cuts,$union):void{
            [$a,$b]=$segments[$keys[$edgeId]]['nodes'];$p=$nodes[$name]['position'];$u=$nodes[$a]['position'];$v=$nodes[$b]['position'];
            $dx=$v[0]-$u[0];$dy=$v[1]-$u[1];$length=hypot($dx,$dy);if($length<1e-8)return;
            $t=(($p[0]-$u[0])*$dx+($p[1]-$u[1])*$dy)/($length*$length);
            if($t< -self::ENDPOINT_SNAP_M/$length||$t>1+self::ENDPOINT_SNAP_M/$length)return;
            if($t*$length<=self::ENDPOINT_SNAP_M){$union($a,$name);return;}
            if((1-$t)*$length<=self::ENDPOINT_SNAP_M){$union($b,$name);return;}
            $cuts[$edgeId][]=['t'=>$t,'node'=>$name];
        };
        $overlaps=0;
        foreach($keys as $i=>$key){
            if($i%100===0)$progress(null);$edge=$segments[$key];[$a,$b]=array_map(static fn($name)=>$nodes[$name],$edge['nodes']);$seen=[];
            foreach(self::bins($a['position'],$b['position']) as $bin)foreach(unpack('V*',$grid[$bin]??'') as $j){
                if($j<=$i||isset($seen[$j]))continue;$seen[$j]=true;$other=$segments[$keys[$j]];[$c,$d]=array_map(static fn($name)=>$nodes[$name],$other['nodes']);
                $dot=($b['position'][0]-$a['position'][0])*($d['position'][0]-$c['position'][0])+($b['position'][1]-$a['position'][1])*($d['position'][1]-$c['position'][1]);
                if($signatures[$i][0]!==$signatures[$j][$dot<0?1:0]||!self::overlap($a,$b,$c,$d))continue;
                $overlaps++;foreach($other['nodes'] as $name)$insert($i,$name);foreach($edge['nodes'] as $name)$insert($j,$name);
            }
        }
        unset($grid,$signatures,$seen,$insert);
        // Several traces can put the same joint inside another edge. Give those
        // samples one existing waypoint rather than creating a zero-length link.
        foreach($cuts as $i=>&$list){
            [$a,$b]=$segments[$keys[$i]]['nodes'];$list[]=['t'=>0.,'node'=>$a];$list[]=['t'=>1.,'node'=>$b];
            usort($list,static fn($a,$b)=>($a['t']<=>$b['t'])?:strcmp($a['node'],$b['node']));
            $length=hypot($nodes[$b]['position'][0]-$nodes[$a]['position'][0],$nodes[$b]['position'][1]-$nodes[$a]['position'][1]);
            for($j=1;$j<count($list);$j++)if(($list[$j]['t']-$list[$j-1]['t'])*$length<1e-6)$union($list[$j]['node'],$list[$j-1]['node']);
        }unset($list,$union,$members);
        $out=[];$pairs=[];$duplicate=0;$split=0;$zero=0;$conflicts=0;$pairSignatures=[];$maxXY=0.;$maxZ=0.;$maxRadius=0.;
        foreach($keys as $i=>$key){
            $edge=$segments[$key];[$a,$b]=$edge['nodes'];$list=$cuts[$i]??[['t'=>0.,'node'=>$a],['t'=>1.,'node'=>$b]];$path=[];
            foreach($list as $cut){$name=$find($cut['node']);if(!$path||end($path)!==$name)$path[]=$name;}
            $u=$nodes[$a]['position'];$v=$nodes[$b]['position'];$dx=$v[0]-$u[0];$dy=$v[1]-$u[1];$length2=$dx*$dx+$dy*$dy;
            foreach($path as $name){
                if($name===$a||$name===$b)continue;
                $p=$nodes[$name]['position'];$t=$length2>1e-12?max(0.,min(1.,(($p[0]-$u[0])*$dx+($p[1]-$u[1])*$dy)/$length2)):0.;
                $maxXY=max($maxXY,hypot($p[0]-$u[0]-$t*$dx,$p[1]-$u[1]-$t*$dy));$maxZ=max($maxZ,abs($p[2]-$u[2]-$t*($v[2]-$u[2])));
                foreach([0,1,2] as $axis)$maxRadius=max($maxRadius,abs(($nodes[$name]['scale'][$axis]??1)-($nodes[$a]['scale'][$axis]??1)*(1-$t)-($nodes[$b]['scale'][$axis]??1)*$t));
            }
            if(count($path)>2)$split++;if(count($path)<2){$zero++;continue;}
            for($j=1;$j<count($path);$j++){
                $part=$edge;$part['nodes']=[$path[$j-1],$path[$j]];[$u,$v]=$part['nodes'];$reverse=strcmp($u,$v)>0;$pair=$reverse?$v."\0".$u:$u."\0".$v;$signature=self::attributes($part,$reverse);$identity=$pair."\0".$signature;
                if(isset($pairs[$identity])){$duplicate++;continue;}
                if(isset($pairSignatures[$pair])&&$pairSignatures[$pair]!==$signature)$conflicts++;
                $pairSignatures[$pair]=$signature;$pairs[$identity]=true;
                $partKey=$j===1?$key:$key.'_overlap_'.$j;while(isset($out[$partKey]))$partKey.='_';$out[$partKey]=$part;
            }
        }
        $aliases=[];$result=[];
        foreach($objects as $o){
            if(($o['class']??'')==='BeamNGWaypoint'&&($root=$find($o['name']))!==$o['name']){$aliases[$o['name']]=$root;continue;}$result[]=$o;
        }
        return ['objects'=>$result,'segments'=>$out,'aliases'=>$aliases,'report'=>[
            'method'=>'Shared segmentation of coincident navigation corridors; deduplicate only matching direction, lanes and routing attributes; pavement unchanged',
            'waypoints_before'=>count($nodes),'waypoints_after'=>count($nodes)-count($aliases),'edges_before'=>count($segments),'edges_after'=>count($out),
            'overlapping_edge_pairs'=>$overlaps,'split_source_edges'=>$split,'duplicate_edges_removed'=>$duplicate,'merged_waypoints'=>count($aliases),'collapsed_zero_length_edges'=>$zero,
            'incompatible_edge_pairs_retained'=>$conflicts,'bounded_node_unions_skipped'=>$blocked,
            'maximum_inserted_xy_error_m'=>$maxXY,'maximum_inserted_z_error_m'=>$maxZ,'maximum_inserted_radius_error_m'=>$maxRadius,
            'xy_tolerance_m'=>BeamNavigation::XY_ERROR_M,'z_tolerance_m'=>BeamNavigation::Z_ERROR_M,'radius_tolerance_m'=>BeamNavigation::RADIUS_ERROR_M]];
    }
    private static function attributes(array $edge,bool $reverse): string
    {
        unset($edge['nodes']);$oneway=(bool)($edge['oneWay']??false);
        if($reverse&&!$oneway){
            $left=$edge['lanesLeft']??null;$right=$edge['lanesRight']??null;unset($edge['lanesLeft'],$edge['lanesRight']);
            if($right!==null)$edge['lanesLeft']=$right;if($left!==null)$edge['lanesRight']=$left;
        }
        ksort($edge);return json_encode([$oneway?($reverse?-1:1):0,$edge],JSON_THROW_ON_ERROR);
    }
    private static function radiusError(array $a,array $b): float
    {
        $error=0.;foreach([0,1,2] as $axis)$error=max($error,abs(($a['scale'][$axis]??1)-($b['scale'][$axis]??1)));return $error;
    }
    private static function overlap(array $a,array $b,array $c,array $d): bool
    {
        $p=$a['position'];$q=$b['position'];$u=$c['position'];$v=$d['position'];$dx=$q[0]-$p[0];$dy=$q[1]-$p[1];$ex=$v[0]-$u[0];$ey=$v[1]-$u[1];$l2=$dx*$dx+$dy*$dy;$m2=$ex*$ex+$ey*$ey;
        if(min($l2,$m2)<1e-12||abs($dx*$ex+$dy*$ey)<.995*sqrt($l2*$m2))return false;
        $t0=(($u[0]-$p[0])*$dx+($u[1]-$p[1])*$dy)/$l2;$t1=(($v[0]-$p[0])*$dx+($v[1]-$p[1])*$dy)/$l2;
        $lo=max(0.,min($t0,$t1));$hi=min(1.,max($t0,$t1));if(($hi-$lo)*sqrt($l2)<self::MIN_OVERLAP_M)return false;
        foreach([$lo,$hi] as $t){
            $x=$p[0]+$t*$dx;$y=$p[1]+$t*$dy;$s=max(0.,min(1.,(($x-$u[0])*$ex+($y-$u[1])*$ey)/$m2));
            if(hypot($x-$u[0]-$s*$ex,$y-$u[1]-$s*$ey)>BeamNavigation::XY_ERROR_M||abs($p[2]+$t*($q[2]-$p[2])-$u[2]-$s*($v[2]-$u[2]))>BeamNavigation::Z_ERROR_M)return false;
            foreach([0,1,2] as $axis)if(abs(($a['scale'][$axis]??1)*(1-$t)+($b['scale'][$axis]??1)*$t-($c['scale'][$axis]??1)*(1-$s)-($d['scale'][$axis]??1)*$s)>BeamNavigation::RADIUS_ERROR_M)return false;
        }
        return true;
    }
    private static function bins(array $a,array $b): \Generator
    {
        $pad=BeamNavigation::XY_ERROR_M;
        for($x=(int)floor((min($a[0],$b[0])-$pad)/self::GRID_M);$x<=(int)floor((max($a[0],$b[0])+$pad)/self::GRID_M);$x++)
        for($y=(int)floor((min($a[1],$b[1])-$pad)/self::GRID_M);$y<=(int)floor((max($a[1],$b[1])+$pad)/self::GRID_M);$y++)yield $x.':'.$y;
    }
}
