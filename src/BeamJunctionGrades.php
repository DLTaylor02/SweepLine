<?php
declare(strict_types=1);
namespace SweepLine;

/** Shared at-grade junction planes; selected roads and mapped structures remain authoritative. */
final class BeamJunctionGrades
{
    public static function apply(array $prepared): array
    {
        $roads=$prepared['roads'];$nodes=[];$chains=[];
        foreach($roads as $i=>$r){
            if(empty($r['network']))continue;
            $chain=[0.];for($j=1;$j<count($r['points']);$j++)$chain[]=$chain[$j-1]+hypot($r['points'][$j][0]-$r['points'][$j-1][0],$r['points'][$j][1]-$r['points'][$j-1][1]);
            $chains[$i]=$chain;
            foreach([0,1] as $side){
                $key=(string)$r[$side?'end_node':'start_node'];if(str_starts_with($key,'limit:'))continue;
                $p=$side?end($r['points']):($r['connector'][0]??$r['points'][0]);
                $nodes[$key]['members'][]=[$i,$side];$nodes[$key]['point']??=$p;
                $nodes[$key]['radius']=max($nodes[$key]['radius']??0.,max(array_column($r['profiles'],'width_m'))/2+2.);
                foreach(array_merge($r['bridges']??[],$r['tunnels']??[]) as $span){
                    $at=$side?end($chain):0.;if($at>=$span['start_m']-.1&&$at<=$span['end_m']+.1)$nodes[$key]['structure']=true;
                }
            }
        }
        $parent=[];foreach($nodes as $key=>$n)$parent[$key]=$key;
        $root=static function($key)use(&$parent){while($parent[$key]!==$key)$key=$parent[$key];return $key;};
        // Merge only short, directly connected at-grade junction footprints.
        foreach($roads as $i=>$r)if(isset($chains[$i])){
            $a=(string)$r['start_node'];$b=(string)$r['end_node'];
            if(!isset($nodes[$a],$nodes[$b])||!empty($r['bridges'])||!empty($r['tunnels'])||!empty($nodes[$a]['structure'])||!empty($nodes[$b]['structure']))continue;
            if(end($chains[$i])>min(30.,$nodes[$a]['radius']+$nodes[$b]['radius']))continue;
            $ra=$root($a);$rb=$root($b);if($ra===$rb)continue;$valid=true;
            foreach($nodes as $ka=>$na)if($root($ka)===$ra)foreach($nodes as $kb=>$nb)if($root($kb)===$rb)
                if(hypot($na['point'][0]-$nb['point'][0],$na['point'][1]-$nb['point'][1])>50.||abs($na['point'][2]-$nb['point'][2])>3.){$valid=false;break 2;}
            if($valid)$parent[$rb]=$ra;
        }
        $groups=[];foreach($nodes as $key=>$n)$groups[$root($key)][$key]=$n;
        $fields=[];$report=['junction_groups'=>0,'adjusted_samples'=>0,'maximum_height_change_m'=>0.,'protected_structure_groups'=>0];
        foreach($groups as $group){
            $members=[];$cx=0.;$cy=0.;$z=0.;$radius=0.;$locked=false;
            foreach($group as $n){$cx+=$n['point'][0];$cy+=$n['point'][1];$z+=$n['point'][2];$locked=$locked||!empty($n['structure']);foreach($n['members'] as [$i,$side])$members[$i]=true;}
            if(count($members)<2)continue;
            if($locked){$report['protected_structure_groups']++;continue;}
            $cx/=count($group);$cy/=count($group);$z/=count($group);$samples=[];$fixed=null;
            foreach($group as $n){
                $radius=max($radius,hypot($n['point'][0]-$cx,$n['point'][1]-$cy)+$n['radius']);
                $samples[]=[$n['point'],20.];
                foreach($n['members'] as [$i,$side]){
                    $r=$roads[$i];$c=$chains[$i];$total=end($c);
                    foreach($r['points'] as $j=>$p){$distance=$side?$total-$c[$j]:$c[$j];if($distance>=10&&$distance<=30)$samples[]=[$p,1.];}
                    if(!$side&&empty($r['parent_name'])){
                        foreach($prepared['main_surfaces']??[] as $s)if($s['name']===($r['external_main']??'primary')){
                            $near=BeamIntersections::nearest([$r['join']['x'],$r['join']['y']],$s['points'],$s['profiles']);
                            if($near)$fixed=[$near['z']+$near['grade']*(($cx-$near['x'])*$near['dx']+($cy-$near['y'])*$near['dy']),$near['grade']*$near['dx'],$near['grade']*$near['dy']];
                        }
                    }
                }
            }
            // Fit slope about the shared nodal height; no geographical assumptions.
            $xx=.0001;$xy=0.;$yy=.0001;$xz=0.;$yz=0.;
            foreach($samples as [$p,$w]){$x=$p[0]-$cx;$y=$p[1]-$cy;$dz=$p[2]-$z;$xx+=$w*$x*$x;$xy+=$w*$x*$y;$yy+=$w*$y*$y;$xz+=$w*$x*$dz;$yz+=$w*$y*$dz;}
            $regularization=max($xx,$yy)*.05;$xx+=$regularization;$yy+=$regularization;
            $det=$xx*$yy-$xy*$xy;$gx=($xz*$yy-$yz*$xy)/$det;$gy=($yz*$xx-$xz*$xy)/$det;
            if($fixed)[$z,$gx,$gy]=$fixed;
            $slope=hypot($gx,$gy);if($slope>.25){$gx*=.25/$slope;$gy*=.25/$slope;}
            $fields[]=['x'=>$cx,'y'=>$cy,'z'=>$z,'gx'=>$gx,'gy'=>$gy,'radius'=>$radius,'members'=>$members,'fixed'=>$fixed!==null];
        }
        // Adjacent ribbons must evaluate the same grading fields where their
        // pavement overlaps, including a parent's own upstream junction blend.
        foreach($fields as &$field)for($hop=0;$hop<2;$hop++){
            $touching=[];foreach($field['members'] as $i=>$unused){$touching[(string)$roads[$i]['start_node']]=true;$touching[(string)$roads[$i]['end_node']]=true;}
            foreach($roads as $i=>$r)if(!empty($r['network'])&&empty($r['bridges'])&&empty($r['tunnels'])&&(isset($touching[(string)$r['start_node']])||isset($touching[(string)$r['end_node']])))$field['members'][$i]=true;
        }unset($field);
        foreach($roads as $i=>&$r){
            if(!isset($chains[$i]))continue;$sections=BeamJunctionBoundary::sections($r['points'],$r['profiles']);$active=array_values(array_filter($fields,static fn($f)=>isset($f['members'][$i])));
            if(!$active)continue;$chain=$chains[$i];$spans=array_merge($r['bridges']??[],$r['tunnels']??[]);
            $locked=[];foreach($nodes as $node)if(!empty($node['structure']))foreach($node['members'] as [$member,$side])if($member===$i)$locked[]=$node['point'];

            $height=static function(array $p,float $at)use($active,$spans,$locked):float{
                $strength=1.;foreach($spans as $s){if($at>=$s['start_m']&&$at<=$s['end_m'])return $p[2];$strength=min($strength,min(abs($at-$s['start_m']),abs($at-$s['end_m']))/8.);}
                foreach($locked as $node)$strength=min($strength,hypot($p[0]-$node[0],$p[1]-$node[1])/8.);
                $sum=0.;$weight=0.;$strongest=0.;$core=null;
                foreach($active as $f){
                    $d=hypot($p[0]-$f['x'],$p[1]-$f['y']);$w=max(0.,min(1.,1-($d-$f['radius'])/25.));$w=$w*$w*(3-2*$w);if($w<=0)continue;
                    $target=$f['z']+$f['gx']*($p[0]-$f['x'])+$f['gy']*($p[1]-$f['y']);
                    if($w>.999999&&($core===null||$d<$core[0]))$core=[$d,$target];
                    $sum+=$w*$target;$weight+=$w;$strongest=max($strongest,$w);
                }
                if($core!==null){$sum=$core[1];$weight=1.;$strongest=1.;}
                if(!$weight)return $p[2];$t=$strongest*max(0.,min(1.,$strength));return $p[2]+($sum/$weight-$p[2])*$t;
            };
            foreach($r['points'] as $j=>&$p){
                $old=$p[2];$p[2]=$height($p,$chain[$j]);$edges=$sections[$j];foreach($edges as &$edge)$edge[2]=$height($edge,$chain[$j]);unset($edge);
                $delta=max(abs($p[2]-$old),abs($edges[0][2]-$sections[$j][0][2]),abs($edges[1][2]-$sections[$j][1][2]));
                if($delta>1e-7){$r['profiles'][$j]['section_override']=$edges;$r['profiles'][$j]['cross_grade']=($edges[1][2]-$edges[0][2])/max(.01,$r['profiles'][$j]['width_m']);$report['adjusted_samples']++;$report['maximum_height_change_m']=max($report['maximum_height_change_m'],$delta);}
            }unset($p);
            if($r['connector']){
                $cp=$r['profiles'][0];unset($cp['section_override']);$cp['cross_grade']=0.;
                $rings=BeamJunctionBoundary::sections($r['connector'],array_fill(0,count($r['connector']),$cp));
                foreach($rings as &$ring)foreach($ring as &$edge)$edge[2]=$height($edge,0.);unset($edge,$ring);
                $r['connector_sections']=$rings;
            }
            foreach($r['connector'] as &$p)$p[2]=$height($p,0.);unset($p);
        }unset($r);
        $report['junction_groups']=count($fields);$prepared['roads']=$roads;$prepared['junction_grading']=$report;
        return self::refresh($prepared);
    }

    public static function refresh(array $prepared): array
    {
        $lookup=array_column($prepared['roads'],null,'name');$main=array_column($prepared['main_surfaces']??[],null,'name');$previous=[];
        foreach($prepared['roads'] as &$r){
            if(empty($r['network']))continue;
            $owner=isset($r['parent_name'])?($lookup[$r['parent_name']]??null):($main[$r['external_main']??'primary']??null);
            if($owner){
                $join=BeamIntersections::nearest([$r['join']['x'],$r['join']['y']],$owner['points'],$owner['profiles']);$r['join']=array_replace($r['join'],$join);
                if(isset($r['parent_name'])||isset($r['junction_parent']))$r['junction_parent']=['points'=>$owner['points'],'profiles'=>$owner['profiles']];
            }
            $r['junction_obstacles']=[];
            foreach($previous as $other)if(($r['parent_name']??null)!==$other['name']&&array_intersect([$r['start_node'],$r['end_node']],[$other['start_node'],$other['end_node']]))
                $r['junction_obstacles'][]=['name'=>$other['name'],'road'=>$other['points'],'profiles'=>$other['profiles']];
            if(isset($r['end_owner']['road_index'])){$end=$prepared['roads'][$r['end_owner']['road_index']];$r['end_owner']['surface']=['points'=>$end['points'],'profiles'=>$end['profiles']];}
            $previous[]=$r;
        }unset($r);
        $byName=array_column($prepared['roads'],null,'name');
        foreach(['junctions'] as $key)foreach($prepared[$key]??[] as $i=>$j)if(isset($byName[$j['branch_name']??'']))$prepared[$key][$i]=array_replace($j,$byName[$j['branch_name']]['join']);
        foreach($prepared['external_junctions']??[] as $name=>$joins)foreach($joins as $i=>$j)if(isset($byName[$j['branch_name']??'']))$prepared['external_junctions'][$name][$i]=array_replace($j,$byName[$j['branch_name']]['join']);
        return $prepared;
    }
}
