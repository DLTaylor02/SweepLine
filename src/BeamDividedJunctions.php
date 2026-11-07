<?php
declare(strict_types=1);
namespace SweepLine;

/** Lane-aware handoff at verified shared nodes, not inferred nearby roads. */
final class BeamDividedJunctions
{
    public const REACH_M=80.;
    public const MAX_SHIFT_M=6.;
    public static function align(array $track,array $main,array $profiles,array $bounds): array
    {
        $road=$track['road'];$own=$track['profiles'];$chain=$track['chain'];$total=end($chain);
        $mainIndex=new BeamRoadIndex([['points'=>$main]]);$sections=BeamJunctionBoundary::sections($main,$profiles);
        $offset=array_fill(0,count($road),0.);$normals=[];$flags=[];$junctions=[];$limited=0;
        foreach($road as $i=>$p){$a=$road[max(0,$i-1)];$b=$road[min(count($road)-1,$i+1)];$length=hypot($b[0]-$a[0],$b[1]-$a[1])?:1;$sign=($own[$i]['legal_forward']??true)?1:-1;$normals[$i]=[($b[1]-$a[1])/$length*$sign,-($b[0]-$a[0])/$length*$sign];}
        foreach([0,count($road)-1] as $endpoint){
            $p=$road[$endpoint];$mapped=false;
            foreach($track['merge_nodes']??[] as $node)if(hypot($p[0]+$bounds[0]-$node[0],$p[1]+$bounds[1]-$node[1])<0.1)$mapped=true;
            $protectedEndpoint=false;foreach(array_merge($track['bridges']??[],$track['tunnels']??[]) as $span)if($chain[$endpoint]>=$span['start_m']-10&&$chain[$endpoint]<=$span['end_m']+10)$protectedEndpoint=true;
            if(!$mapped||$protectedEndpoint)continue;$hit=$mainIndex->nearest($p,0.2);if(!$hit)continue;
            $node=$hit['fraction']>0.5?$hit['segment']:$hit['segment']-1;$shared=$profiles[$node];
            if(!empty($shared['oneway']))continue;
            $oneway=null;$best=INF;$at=0.;
            foreach($main as $j=>$v){if($j)$at+=hypot($v[0]-$main[$j-1][0],$v[1]-$main[$j-1][1]);if(!empty($profiles[$j]['oneway'])&&abs($at-$hit['along'])<$best){$best=abs($at-$hit['along']);$oneway=$profiles[$j];}}
            if(!$oneway||$best>40)continue;$left=$oneway['legal_forward']??true;
            $lanes=$left?$shared['backward']:$shared['forward'];
            if($lanes<1||$lanes!==$own[$endpoint]['lanes'])continue;
            $width=($shared['carriageway_width_m']??$shared['width_m'])*$lanes/max(1,$shared['lanes']);
            if($width/$lanes<2.4)continue;
            $junctions[$endpoint]=['primary_position'=>$main[$node],'main_along'=>$hit['along'],'receiving_lanes'=>$lanes,'receiving_width_m'=>$width];
            $nodeRing=$sections[$node];$nodeWidth=hypot($nodeRing[1][0]-$nodeRing[0][0],$nodeRing[1][1]-$nodeRing[0][1]);
            $normal=[];foreach([0,1,2] as $axis)$normal[$axis]=($nodeRing[1][$axis]-$nodeRing[0][$axis])/$nodeWidth*($left?-1:1);
            $nodeU=$shared['lane_paint']['center']['u']??($shared['backward']/max(1,$shared['lanes']));$nodeMargin=($shared['shoulder_m']??0)/$nodeWidth;$nodeU=$nodeMargin+(1-2*$nodeMargin)*$nodeU;
            $divider=[];$center=[];$inner=[];$outer=[];$shoulder=$own[$endpoint]['shoulder_m']??0;
            foreach([0,1,2] as $axis){$divider[$axis]=$nodeRing[0][$axis]+($nodeRing[1][$axis]-$nodeRing[0][$axis])*$nodeU;$center[$axis]=$divider[$axis]+$normal[$axis]*$width/2;$inner[$axis]=$divider[$axis]-$normal[$axis]*$shoulder;$outer[$axis]=$divider[$axis]+$normal[$axis]*($width+$shoulder);}
            $junctions[$endpoint]['center']=$center;$junctions[$endpoint]['section']=($own[$endpoint]['legal_forward']??true)?[$inner,$outer]:[$outer,$inner];

            foreach($road as $i=>$v){
                $distance=$endpoint===0?$chain[$i]:$total-$chain[$i];if($distance>self::REACH_M)continue;
                $blocked=false;foreach(array_merge($track['bridges']??[],$track['tunnels']??[]) as $span)if($chain[$i]>=$span['start_m']-10&&$chain[$i]<=$span['end_m']+10)$blocked=true;
                if($blocked)continue;$near=$mainIndex->nearest($v,80);if(!$near||abs($near['along']-$hit['along'])>self::REACH_M+15)continue;
                $j=$near['segment'];$t=$near['fraction'];$ring=[];foreach([0,1] as $side)foreach([0,1,2] as $axis)$ring[$side][$axis]=$sections[$j-1][$side][$axis]*(1-$t)+$sections[$j][$side][$axis]*$t;
                $w=hypot($ring[1][0]-$ring[0][0],$ring[1][1]-$ring[0][1]);if($w<1)continue;
                $u0=$profiles[$j-1]['lane_paint']['center']['u']??($profiles[$j-1]['backward']/max(1,$profiles[$j-1]['lanes']));
                $u1=$profiles[$j]['lane_paint']['center']['u']??($profiles[$j]['backward']/max(1,$profiles[$j]['lanes']));
                $margin=(($profiles[$j-1]['shoulder_m']??0)*(1-$t)+($profiles[$j]['shoulder_m']??0)*$t)/$w;
                $u=$margin+(1-2*$margin)*($u0*(1-$t)+$u1*$t);$divider=[$ring[0][0]+($ring[1][0]-$ring[0][0])*$u,$ring[0][1]+($ring[1][1]-$ring[0][1])*$u];
                $out=[($ring[1][0]-$ring[0][0])/$w*($left?-1:1),($ring[1][1]-$ring[0][1])/$w*($left?-1:1)];
                $dot=$out[0]*$normals[$i][0]+$out[1]*$normals[$i][1];if($dot<0.5)continue;
                $blend=max(0,min(1,$distance/30.48));$blend=$blend*$blend*(3-2*$blend);
                $existing=$own[$i]['carriageway_width_m']??$own[$i]['width_m'];$desired=$width*(1-$blend)+$existing*$blend;
                $own[$i]['carriageway_width_m']=$desired;$own[$i]['width_m']=$desired+2*($own[$i]['shoulder_m']??0);
                $lateral=($v[0]-$divider[0])*$out[0]+($v[1]-$divider[1])*$out[1];$need=max(0,($desired/2-$lateral)/$dot);
                if($need>self::MAX_SHIFT_M){$limited++;$need=self::MAX_SHIFT_M;}
                $offset[$i]=max($offset[$i],$need);$flags[$i]=true;
            }
        }
        if(!$junctions)return $track;
        // A bounded slope envelope avoids replacing a pinched lane with a kink.
        for($i=1;$i<count($offset);$i++)$offset[$i]=max($offset[$i],$offset[$i-1]-($chain[$i]-$chain[$i-1])/20);
        for($i=count($offset)-2;$i>=0;$i--)$offset[$i]=max($offset[$i],$offset[$i+1]-($chain[$i+1]-$chain[$i])/20);
        $caps=array_fill(0,count($road),self::MAX_SHIFT_M);
        foreach($caps as $i=>$_)foreach(array_merge($track['bridges']??[],$track['tunnels']??[]) as $span)if($chain[$i]>=$span['start_m']-10&&$chain[$i]<=$span['end_m']+10)$caps[$i]=0.;
        for($i=1;$i<count($caps);$i++)$caps[$i]=min($caps[$i],$caps[$i-1]+($chain[$i]-$chain[$i-1])/20);
        for($i=count($caps)-2;$i>=0;$i--)$caps[$i]=min($caps[$i],$caps[$i+1]+($chain[$i+1]-$chain[$i])/20);
        $maximum=0.;foreach($road as $i=>&$v){$shift=min($offset[$i],$caps[$i]);if($offset[$i]>$caps[$i]+1e-6)$limited++;if($shift>1e-8){$v[0]+=$normals[$i][0]*$shift;$v[1]+=$normals[$i][1]*$shift;}$maximum=max($maximum,$shift);
            if(isset($flags[$i])||$shift>1e-6){$own[$i]['lane_paint_segment']=true;$own[$i]['shared_junction_paint']=true;}
        }unset($v);
        foreach($junctions as $endpoint=>$junction){if(hypot($junction['center'][0]-$track['road'][$endpoint][0],$junction['center'][1]-$track['road'][$endpoint][1])>self::MAX_SHIFT_M){$limited++;continue;}
            $road[$endpoint]=$junction['center'];$own[$endpoint]['section_override']=$junction['section'];
        }
        $track['bridges']=BeamMedian::remapSpans($track['bridges']??[],$track['road'],$road);$track['tunnels']=BeamMedian::remapSpans($track['tunnels']??[],$track['road'],$road);
        $track['chain']=[0.];foreach($road as $i=>$p)if($i)$track['chain'][]=$track['chain'][$i-1]+hypot($p[0]-$road[$i-1][0],$p[1]-$road[$i-1][1]);
        $track['road']=$road;$track['profiles']=$own;$track['shared_junctions']=$junctions;
        $track['divided_junction_alignment']=['junctions'=>count($junctions),'maximum_outward_shift_m'=>$maximum,'limited_samples'=>$limited,'method'=>'Opposing lanes meet their directional half of the shared receiving road; main pavement unchanged','estimated'=>true];
        return $track;
    }
}
