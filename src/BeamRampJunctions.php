<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded paved merge aprons, outside existing pavement; never reshape the selected road. */
final class BeamRampJunctions
{
    public static function apply(array $prepared): array
    {
        $primary=$prepared['main_surfaces'][0]??null;$count=0;$area=0.;
        foreach($prepared['roads'] as &$r){
            if(($r['kind']??'')!=='ramp'||empty($r['join']))continue;
            $parent=$r['junction_parent']??$primary;if(!$parent)continue;
            $road=$parent['points'];$profiles=$parent['profiles'];$join=$r['join'];
            $line=array_merge($r['connector']??[],$r['points']);if(count($line)<2)continue;
            $cp=$r['profiles'][0];unset($cp['section_override']);$cps=[];
            foreach($r['connector']??[] as $i=>$p)$cps[]=$cp+(isset($r['connector_sections'][$i])?['section_override'=>$r['connector_sections'][$i]]:[]);
            $rp=array_merge($cps,$r['profiles']);
            $sections=BeamJunctionBoundary::sections($line,$rp);$outer=[];$along=0.;$side=null;
            foreach($line as $i=>$p){
                if($i)$along+=hypot($p[0]-$line[$i-1][0],$p[1]-$line[$i-1][1]);if($along>80.)break;
                $signed=($p[0]-$join['x'])*(-$join['dy'])+($p[1]-$join['y'])*$join['dx'];
                if(abs($signed)<.5)continue;$thisSide=$signed>0?0:1;
                if($side!==null&&$side!==$thisSide)continue;$side=$thisSide;
                $near=BeamIntersections::nearest($p,$road,$profiles);
                if(abs($p[2]-$near['z'])>1.)break;
                $outer[]=$sections[$i][$side];
                if($near['distance']>$near['half_width']+$rp[$i]['width_m']/2+2.)break;
            }
            if($side===null||count($outer)<2)continue;
            $parentSections=BeamJunctionBoundary::sections($road,$profiles);
            $hull=$outer;$at=0.;
            for($i=1;$i<count($road);$i++){
                $start=$at;$at+=hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
                foreach([$join['along']-30.48,$join['along'],$join['along']+30.48] as $station){
                    if($station<$start||$station>$at||$at-$start<1e-8)continue;$t=($station-$start)/($at-$start);$p=[];
                    foreach([0,1,2] as $axis)$p[]=$parentSections[$i-1][$side][$axis]*(1-$t)+$parentSections[$i][$side][$axis]*$t;$hull[]=$p;
                }
            }
            $hull=self::hull($hull);if(count($hull)<3)continue;
            $vertices=$hull;$faces=[];
            foreach(BeamPolygon::triangles($hull) as $ids)$faces[]=['v'=>$ids,'uv'=>[[0,0],[0,0],[0,0]],'material'=>'fill'];
            // Only add the missing pavement; no duplicate self-collision sheets.
            [$vertices,$faces]=BeamJunctionBoundary::trim($vertices,$faces,$r['points'],$r['profiles'],null,true,1.);
            if($r['connector']){
                $cp=array_fill(0,count($r['connector']),$r['profiles'][0]);
                [$vertices,$faces]=BeamJunctionBoundary::trim($vertices,$faces,$r['connector'],$cp,null,true,1.);
            }
            $triangles=[];foreach($faces as $f){$t=array_map(static fn($id)=>$vertices[$id],$f['v']);if(abs(BeamPolygon::cross(...$t))<1e-7)continue;$triangles[]=$t;}
            if(!$triangles)continue;$r['junction_fill']=$triangles;$count++;foreach($triangles as $t)$area+=abs(BeamPolygon::cross(...$t))/2;
        }unset($r);
        $byName=array_column($prepared['roads'],null,'name');
        $refresh=static function($joins,$owner)use($byName){
            foreach($joins as &$j)if(isset($byName[$j['branch_name']??'']))$j['barrier_openings']=BeamBridgeOpenings::junction($owner['points'],$owner['profiles'],$byName[$j['branch_name']],$j);
            unset($j);return $joins;
        };
        if($primary)$prepared['junctions']=$refresh($prepared['junctions']??[],$primary);
        foreach($prepared['main_surfaces']??[] as $s)if(isset($prepared['external_junctions'][$s['name']]))$prepared['external_junctions'][$s['name']]=$refresh($prepared['external_junctions'][$s['name']],$s);
        foreach($prepared['roads'] as &$r)if(isset($r['junctions']))$r['junctions']=$refresh($r['junctions'],$r);unset($r);
        $prepared['ramp_junction_fills']=['junctions'=>$count,'area_before_parent_clipping_m2'=>$area,'approach_length_m'=>30.48,'method'=>'Fill merge concavities; original highway pavement and lane geometry unchanged'];
        return $prepared;
    }
    private static function hull(array $points): array
    {
        usort($points,static fn($a,$b)=>($a[0]<=>$b[0])?:($a[1]<=>$b[1]));$unique=[];foreach($points as $p)$unique[sprintf('%.5f:%.5f',$p[0],$p[1])]=$p;$points=array_values($unique);
        $half=static function($list){$out=[];foreach($list as $p){while(count($out)>1&&BeamPolygon::cross($out[count($out)-2],end($out),$p)<=1e-9)array_pop($out);$out[]=$p;}return $out;};
        $lo=$half($points);$hi=$half(array_reverse($points));array_pop($lo);array_pop($hi);return array_merge($lo,$hi);
    }
}
