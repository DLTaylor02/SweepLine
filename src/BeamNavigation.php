<?php
declare(strict_types=1);
namespace SweepLine;

/** Compact only the AI/minimap polyline; never resample collision or visual pavement. */
final class BeamNavigation
{
    public const XY_ERROR_M=.15;
    public const Z_ERROR_M=.10;
    public const RADIUS_ERROR_M=.10;
    public const MAX_SPACING_M=20.;

    public static function compact(array $objects,array $segments,callable $progress): array
    {
        $nodes=[];$incoming=[];$outgoing=[];$degree=[];$signatures=[];
        foreach($objects as $o)if(($o['class']??'')==='BeamNGWaypoint')$nodes[$o['name']]=$o;
        foreach($segments as $key=>$s){
            if(count($s['nodes'])!==2)throw new \RuntimeException('Navigation compaction requires pairwise edges.');
            [$a,$b]=$s['nodes'];
            if(!isset($nodes[$a],$nodes[$b]))throw new \RuntimeException('Navigation edge references a missing waypoint.');
            $incoming[$b][]=$key;$outgoing[$a][]=$key;$degree[$a]=($degree[$a]??0)+1;$degree[$b]=($degree[$b]??0)+1;
            unset($s['nodes']);ksort($s);$signatures[$key]=json_encode($s,JSON_THROW_ON_ERROR);
        }
        $pass=[];
        foreach($nodes as $name=>$o){
            if(($degree[$name]??0)!==2||count($incoming[$name]??[])!==1||count($outgoing[$name]??[])!==1)continue;
            $a=$incoming[$name][0];$b=$outgoing[$name][0];
            if($a===$b||$signatures[$a]!==$signatures[$b]||$segments[$a]['nodes'][0]===$segments[$b]['nodes'][1])continue;
            if(array_diff(array_keys($o),['class','name','__parent','position','scale']))continue;
            $u=$nodes[$segments[$a]['nodes'][0]]['position'];$p=$o['position'];$v=$nodes[$segments[$b]['nodes'][1]]['position'];
            if(($p[0]-$u[0])*($v[0]-$p[0])+($p[1]-$u[1])*($v[1]-$p[1])<=0)continue;
            $pass[$name]=true;
        }
        $visited=[];$removed=[];$out=[];$chains=0;$maxXY=0.;$maxZ=0.;$maxRadius=0.;
        $emit=static function($first)use(&$visited,&$removed,&$out,&$chains,&$maxXY,&$maxZ,&$maxRadius,$segments,$nodes,$pass,$outgoing,$progress):void{
            $keys=[];$path=[];$key=$first;
            while(!isset($visited[$key])){
                $visited[$key]=true;$keys[]=$key;$s=$segments[$key];
                if(!$path)$path[]=$s['nodes'][0];$path[]=$s['nodes'][1];
                $end=$s['nodes'][1];if(!isset($pass[$end]))break;
                $key=$outgoing[$end][0];
            }
            // Preserve cycles verbatim rather than risking a degenerate shortcut.
            if(count($keys)<2||$path[0]===end($path)){
                foreach($keys as $key)$out[$key]=$segments[$key];return;
            }
            $progress(null);$chains++;$chain=[0.];
            for($i=1;$i<count($path);$i++){
                $a=$nodes[$path[$i-1]]['position'];$b=$nodes[$path[$i]]['position'];
                $chain[]=$chain[$i-1]+hypot($b[0]-$a[0],$b[1]-$a[1]);
            }
            $keep=[0=>true,count($path)-1=>true];$todo=[[0,count($path)-1]];
            while($todo){
                [$lo,$hi]=array_pop($todo);if($hi-$lo<2)continue;
                $a=$nodes[$path[$lo]];$b=$nodes[$path[$hi]];$worst=1.;$split=null;
                for($i=$lo+1;$i<$hi;$i++){
                    [$xy,$z,$radius]=self::errors($nodes[$path[$i]],$a,$b);
                    $error=max($xy/self::XY_ERROR_M,$z/self::Z_ERROR_M,$radius/self::RADIUS_ERROR_M);
                    if($error>$worst){$worst=$error;$split=$i;}
                }
                if($split===null&&$chain[$hi]-$chain[$lo]>self::MAX_SPACING_M){
                    $target=($chain[$lo]+$chain[$hi])/2;$distance=INF;
                    for($i=$lo+1;$i<$hi;$i++)if(abs($chain[$i]-$target)<$distance){$distance=abs($chain[$i]-$target);$split=$i;}
                }
                if($split!==null){$keep[$split]=true;$todo[]=[$lo,$split];$todo[]=[$split,$hi];}
            }
            ksort($keep);$indices=array_keys($keep);
            for($j=1;$j<count($indices);$j++){
                $lo=$indices[$j-1];$hi=$indices[$j];$s=$segments[$keys[$lo]];
                $s['nodes']=[$path[$lo],$path[$hi]];$out[$keys[$lo]]=$s;
                for($i=$lo+1;$i<$hi;$i++){
                    [$xy,$z,$radius]=self::errors($nodes[$path[$i]],$nodes[$path[$lo]],$nodes[$path[$hi]]);
                    $maxXY=max($maxXY,$xy);$maxZ=max($maxZ,$z);$maxRadius=max($maxRadius,$radius);$removed[$path[$i]]=true;
                }
            }
        };
        foreach($segments as $key=>$s)if(!isset($pass[$s['nodes'][0]])&&!isset($visited[$key]))$emit($key);
        foreach($segments as $key=>$s)if(!isset($visited[$key]))$emit($key);
        $result=[];
        foreach($objects as $o)if(($o['class']??'')!=='BeamNGWaypoint'||!isset($removed[$o['name']]))$result[]=$o;
        return ['objects'=>$result,'segments'=>$out,'report'=>['method'=>'Topology/attribute-preserving bounded AI/minimap polyline compaction; pavement unchanged',
            'waypoints_before'=>count($nodes),'waypoints_after'=>count($nodes)-count($removed),'removed_interior_waypoints'=>count($removed),
            'edges_before'=>count($segments),'edges_after'=>count($out),'chains'=>$chains,
            'maximum_removed_xy_error_m'=>$maxXY,'maximum_removed_z_error_m'=>$maxZ,'maximum_removed_radius_error_m'=>$maxRadius,
            'xy_tolerance_m'=>self::XY_ERROR_M,'z_tolerance_m'=>self::Z_ERROR_M,'radius_tolerance_m'=>self::RADIUS_ERROR_M,'maximum_generated_spacing_m'=>self::MAX_SPACING_M]];
    }

    private static function errors(array $node,array $a,array $b): array
    {
        $p=$node['position'];$u=$a['position'];$v=$b['position'];$dx=$v[0]-$u[0];$dy=$v[1]-$u[1];$length=$dx*$dx+$dy*$dy;
        $t=$length>1e-12?max(0.,min(1.,(($p[0]-$u[0])*$dx+($p[1]-$u[1])*$dy)/$length)):0.;
        $radius=0.;foreach([0,1,2] as $axis)$radius=max($radius,abs(($node['scale'][$axis]??1)-(($a['scale'][$axis]??1)*(1-$t)+($b['scale'][$axis]??1)*$t)));
        return [hypot($p[0]-$u[0]-$dx*$t,$p[1]-$u[1]-$dy*$t),abs($p[2]-($u[2]*(1-$t)+$v[2]*$t)),$radius];
    }
}
