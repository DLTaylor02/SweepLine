<?php
declare(strict_types=1);
namespace SweepLine;

/** Paint-only intersection openings. Collision pavement is never rebuilt from these masks. */
final class BeamJunctionPaint
{
    public static function prepare(array $prepared): array
    {
        $byName=array_column($prepared['roads'],null,'name');$primary=$prepared['main_surfaces'][0]??null;$joins=[];
        if($primary)$joins[$primary['name']]=$prepared['junctions']??[];
        foreach($prepared['external_junctions']??[] as $name=>$list)$joins[$name]=$list;
        foreach($prepared['roads'] as $r)$joins[$r['name']]=$r['junctions']??[];
        // A ramp may return to an already prepared road at its other end.
        // Include that genuine shared-node opening without changing the graph or pavement.
        foreach($prepared['roads'] as $r){
            $end=$r['end_owner']??null;$owner=null;
            if(isset($end['road_index']))$owner=$prepared['roads'][$end['road_index']]??null;
            elseif(isset($end['surface']['name']))$owner=$end['surface'];
            if(!$owner)continue;$points=$r['points'];$last=count($points)-1;$n=BeamIntersections::nearest($points[$last],$owner['points'],$owner['profiles']);if(!$n)continue;
            $dx=$points[$last][0]-$points[$last-1][0];$dy=$points[$last][1]-$points[$last-1][1];$length=max(.001,hypot($dx,$dy));$sine=abs($n['dx']*$dy-$n['dy']*$dx)/$length;
            $join=$n+['radius'=>$r['profiles'][$last]['width_m']/2/max(.05,$sine),'branch_name'=>$r['name'],'preserve_through_lanes'=>($r['kind']??'')==='ramp'||$sine<.2];
            $join['barrier_openings']=BeamBridgeOpenings::junction($owner['points'],$owner['profiles'],$r,$join);$joins[$owner['name']][]=$join;
        }
        $count=0;
        if($primary){$prepared['paint_exclusions']=self::openings($primary,$joins[$primary['name']]??[],$byName);$count+=strlen($prepared['paint_exclusions'])?1:0;}
        foreach($prepared['roads'] as &$r){$r['paint_exclusions']=self::openings($r,$joins[$r['name']]??[],$byName);$count+=strlen($r['paint_exclusions'])?1:0;}unset($r);
        foreach($prepared['main_surfaces']??[] as $s)if($primary&&$s['name']!==$primary['name']&&isset($joins[$s['name']])){
            $prepared['external_paint_exclusions'][$s['name']]=self::openings($s,$joins[$s['name']],$byName);
        }
        $prepared['junction_paint']=['roads_with_openings'=>$count,'method'=>'Clip paint at actual at-grade junction openings; retain the far edge of a T junction and original collision pavement'];
        return $prepared;
    }
    public static function openings(array $owner,array $joins,array $branches): string
    {
        $ranges=[];
        foreach($joins as $join){
            $branch=$branches[$join['branch_name']??'']??null;
            // A collinear section boundary is a road continuation, not an intersection.
            if(!empty($join['preserve_through_lanes'])&&($branch['kind']??'')!=='ramp')continue;
            foreach($join['barrier_openings']??[] as $side=>$intervals)foreach($intervals as [$lo,$hi])if($hi>$lo)$ranges[]=[$lo,$hi,$side];
        }
        if(!$ranges)return '';
        $cuts=[];foreach($ranges as [$lo,$hi]){ $cuts[]=$lo;$cuts[]=$hi;}sort($cuts);$cuts=array_values(array_unique($cuts));
        $sections=BeamJunctionBoundary::sections($owner['points'],$owner['profiles']);$chain=[0.];
        for($i=1;$i<count($owner['points']);$i++)$chain[]=$chain[$i-1]+hypot($owner['points'][$i][0]-$owner['points'][$i-1][0],$owner['points'][$i][1]-$owner['points'][$i-1][1]);
        $mesh=['vertices'=>[],'triangles'=>[]];$at=1;
        for($i=1;$i<count($cuts);$i++){
            $lo=max(0.,$cuts[$i-1]);$hi=min(end($chain),$cuts[$i]);if($hi-$lo<1e-7)continue;$mid=($lo+$hi)/2;$left=false;$right=false;
            foreach($ranges as [$a,$b,$side])if($mid>$a&&$mid<$b){if($side==='left')$left=true;else $right=true;}
            if(!$left&&!$right)continue;
            while($at<count($chain)-1&&$chain[$at]<=$lo)$at++;
            for($j=$at;$j<count($chain)&&$chain[$j-1]<$hi;$j++){
                $start=max($lo,$chain[$j-1]);$end=min($hi,$chain[$j]);$length=$chain[$j]-$chain[$j-1];if($length<1e-8||$end-$start<1e-8)continue;
                $rings=[];
                foreach([$start,$end] as $s){
                    $t=($s-$chain[$j-1])/$length;$p=$owner['profiles'];$width=$p[$j-1]['width_m']*(1-$t)+$p[$j]['width_m']*$t;
                    $shoulder=($p[$j-1]['shoulder_m']??0)*(1-$t)+($p[$j]['shoulder_m']??0)*$t;
                    $margin=min(.25,($shoulder+.20)/max(.01,$width));$ring=[];
                    foreach([$left?0.:$margin,$right?1.:1.-$margin] as $u){
                        $v=[];foreach([0,1,2] as $axis){$a=$sections[$j-1][0][$axis]*(1-$t)+$sections[$j][0][$axis]*$t;$b=$sections[$j-1][1][$axis]*(1-$t)+$sections[$j][1][$axis]*$t;$v[]=$a*(1-$u)+$b*$u;}$ring[]=$v;
                    }$rings[]=$ring;
                }
                [$a,$b]=$rings[0];[$d,$c]=$rings[1];
                foreach([[$a,$b,$c],[$a,$c,$d]] as $triangle){if(BeamPolygon::cross(...$triangle)<=1e-8)continue;$base=count($mesh['vertices']);array_push($mesh['vertices'],...$triangle);$mesh['triangles'][]=['v'=>[$base,$base+1,$base+2]];}
            }
        }
        return BeamJunctionBoundary::encode($mesh);
    }
    public static function clip(array $vertices,array $triangles,string $mask): array
    {
        return $mask===''||!$triangles?[$vertices,$triangles]:BeamJunctionBoundary::trim($vertices,$triangles,[],[],null,false,1.,$mask);
    }
}