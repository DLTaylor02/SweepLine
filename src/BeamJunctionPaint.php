<?php
declare(strict_types=1);
namespace SweepLine;

/** Paint-only junction footprints; highway ramp merges retain their through-lane markings. */
final class BeamJunctionPaint
{
    private static function ribbon(array $r): array
    {
        if(empty($r['connector']))return $r;
        $cp=$r['profiles'][0];unset($cp['section_override']);$profiles=[];
        foreach($r['connector'] as $i=>$p)$profiles[]=$cp+(isset($r['connector_sections'][$i])?['section_override'=>$r['connector_sections'][$i]]:[]);
        return ['points'=>array_merge($r['connector'],$r['points']),'profiles'=>array_merge($profiles,$r['profiles'])]+$r;
    }
    public static function prepare(array $prepared): array
    {
        $main=array_column($prepared['main_surfaces']??[],null,'name');$roads=array_column($prepared['roads'],null,'name');$lines=$main;$groups=[];$ranges=[];
        foreach($roads as $name=>$r)$lines[$name]=self::ribbon($r);
        $lengths=[];foreach($lines as $name=>$r)$lengths[$name]=BeamRampNetwork::length($r['points']);
        $add=static function($node,$name,$station,$side,$direction,$vectors,$profile)use(&$groups){
            $groups[(string)$node][$name.':'.(string)$side]=['name'=>$name,'station'=>$station,'side'=>$side,'direction'=>$direction,'vectors'=>$vectors,
                'width'=>$profile['width_m'],'oneway'=>!empty($profile['oneway']),'street'=>isset($profile['road_class'])&&!in_array($profile['road_class'],['motorway','trunk','motorway_link','trunk_link'],true)];
        };
        $through=static function($node,$surface,$point)use($add){
            $n=BeamIntersections::nearest($point,$surface['points'],$surface['profiles']);if(!$n)return;$d=[$n['dx'],$n['dy']];
            $add($node,$surface['name'],$n['along'],'through',$d,[$d,[-$d[0],-$d[1]]],$surface['profiles'][$n['segment']]);
        };
        foreach($roads as $name=>$r){
            if(empty($r['network']))continue;$line=$lines[$name];$p=$line['points'];$last=count($p)-1;
            foreach([0,1] as $side){$node=$r[$side?'end_node':'start_node']??null;if($node===null)continue;$i=$side?$last:0;$j=$side?$last-1:1;
                while(isset($p[$j])&&hypot($p[$j][0]-$p[$i][0],$p[$j][1]-$p[$i][1])<.01)$j+=$side?-1:1;if(!isset($p[$j]))continue;
                $dx=$p[$j][0]-$p[$i][0];$dy=$p[$j][1]-$p[$i][1];$length=hypot($dx,$dy);$out=[$dx/$length,$dy/$length];$direction=$side?[-$out[0],-$out[1]]:$out;
                $add($node,$name,$side?$lengths[$name]:0.,$side,$direction,[$out],$line['profiles'][$i]);
            }
            if(empty($r['parent_name'])&&isset($main[$r['external_main']??'primary']))$through($r['start_node'],$main[$r['external_main']??'primary'],[$r['join']['x'],$r['join']['y']]);
            if(isset($r['end_owner']['surface']['name'],$main[$r['end_owner']['surface']['name']]))$through($r['end_node'],$main[$r['end_owner']['surface']['name']],end($p));
        }
        $junctions=0;$merges=0;
        foreach($groups as $members){
            $vectors=[];$street=false;$oneway=true;$radius=0.;
            foreach($members as $m){$street=$street||$m['street'];$oneway=$oneway&&$m['oneway'];$radius=max($radius,$m['width']/2+.6);
                foreach($m['vectors'] as $v){$duplicate=false;foreach($vectors as $u)if($u[0]*$v[0]+$u[1]*$v[1]>.965925826){$duplicate=true;break;}if(!$duplicate)$vectors[]=$v;}}
            if(count($vectors)<3)continue;$cross=0.;foreach($vectors as $v)foreach($vectors as $u)$cross=max($cross,abs($v[0]*$u[1]-$v[1]*$u[0]));
            if($cross<($street&&!$oneway?.258819045:.707106781)){$merges++;continue;}$junctions++;
            $axis=null;$arm=null;
            if(count($vectors)===3)foreach($vectors as $i=>$v)foreach($vectors as $j=>$u)if($i<$j&&$v[0]*$u[0]+$v[1]*$u[1]<-.94){$axis=$v;$arm=$vectors[3-$i-$j];break 2;}
            foreach($members as $m){$left=true;$right=true;$d=$m['direction'];
                if($axis&&abs($d[0]*$axis[0]+$d[1]*$axis[1])>.94){if(-$d[1]*$arm[0]+$d[0]*$arm[1]>0)$right=false;else $left=false;}
                $lo=$m['station']-($m['side']===0?0.:$radius);$hi=$m['station']+($m['side']===1?0.:$radius);
                if($left)$ranges[$m['name']][]=[$lo,$hi,'left'];if($right)$ranges[$m['name']][]=[$lo,$hi,'right'];
            }
        }
        // Legacy non-network branches still use their measured at-grade edge openings.
        foreach($lines as $name=>$line){
            $joins=$roads[$name]['junctions']??($name===array_key_first($main)?($prepared['junctions']??[]):($prepared['external_junctions'][$name]??[]));
            $joins=array_values(array_filter($joins,static fn($j)=>empty($roads[$j['branch_name']??'']['network'])));
            $mask=self::ranges($line,$ranges[$name]??[]);$legacy=self::openings($line,$joins,$roads);$mask=base64_encode(base64_decode($mask).base64_decode($legacy));
            if(isset($roads[$name]))$roads[$name]['paint_exclusions']=$mask;
            elseif($name===array_key_first($main))$prepared['paint_exclusions']=$mask;
            else $prepared['external_paint_exclusions'][$name]=$mask;
        }
        foreach($prepared['roads'] as &$r)$r['paint_exclusions']=$roads[$r['name']]['paint_exclusions']??'';unset($r);
        $prepared['junction_paint']=['crossroad_junctions'=>$junctions,'highway_merges_preserved'=>$merges,'method'=>'Suppress all incident approaches at street/ramp crossings; preserve highway through paint at shallow merges and the far edge of T junctions'];
        return $prepared;
    }
    public static function openings(array $owner,array $joins,array $branches): string
    {
        $ranges=[];
        foreach($joins as $join){
            $branch=$branches[$join['branch_name']??'']??null;
            if(!empty($join['preserve_through_lanes'])){
                if(!$branch)continue;$p=$branch['connector'][0]??$branch['points'][0];$q=$branch['connector'][1]??$branch['points'][1];$n=BeamIntersections::nearest($p,$owner['points'],$owner['profiles']);
                $dx=$q[0]-$p[0];$dy=$q[1]-$p[1];$length=max(.001,hypot($dx,$dy));
                if(abs(($dx*$n['dx']+$dy*$n['dy'])/$length)>.707106781)continue;
            }
            foreach($join['barrier_openings']??[] as $side=>$intervals)foreach($intervals as [$lo,$hi])if($hi>$lo)$ranges[]=[$lo,$hi,$side];
        }
        return self::ranges($owner,$ranges);
    }
    private static function ranges(array $owner,array $ranges): string
    {
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