<?php
declare(strict_types=1);
namespace SweepLine;

/** Connected minimap/traffic graph and optional synthetic stub-end turnarounds. */
final class BeamTraffic
{
    public static function turnarounds(array $prepared,array $main,array $profiles,BeamRaster $raster,float $origin,callable $progress,array $buildings=[]): array
    {
        $created=0;$skipped=0;$original=$prepared['roads'];$avoidWidths=[];
        foreach($original as $r)$avoidWidths[$r['name']]=max(array_column($r['profiles'],'width_m'))/2+3;
        foreach($original as $branch){
            $progress(null);$points=$branch['points'];$end=end($points);$before=$points[count($points)-2];$profile=end($branch['profiles']);
            if($profile['oneway']){$skipped++;continue;}
            $dx=$end[0]-$before[0];$dy=$end[1]-$before[1];$length=hypot($dx,$dy);if($length<0.1){$skipped++;continue;}$dx/=$length;$dy/=$length;
            $candidate=null;
            foreach([1,-1] as $sign){
                $xy=[];$valid=true;$heights=[];
                $offsets=[[2,0.1],[6,4.5],[9,9],[6.364,15.364],[0,18],[-6.364,15.364],[-9,9],[-6,4.5],[-2,0.1]];
                foreach($offsets as [$right,$forward]){
                    $x=$end[0]+$sign*$dy*$right+$dx*$forward;$y=$end[1]-$sign*$dx*$right+$dy*$forward;
                    if($x<3||$y<3||$x>$raster->side-3||$y>$raster->side-3){$valid=false;break;}
                    $near=BeamIntersections::nearest([$x,$y],$main,$profiles);
                    if($near['distance']<$near['half_width']+4){$valid=false;break;}
                    foreach([[-2,0],[2,0],[0,-2],[0,2]] as [$ox,$oy]){
                        $h=$raster->height($x+$ox,$y+$oy);$heights[]=$h;
                        if(abs($h-($end[2]+$origin-0.25))>1.2||BeamRaster::cover($raster->rgb((int)(($x+$ox)/$raster->side*$raster->size),(int)(($y+$oy)/$raster->side*$raster->size)))===4){$valid=false;break 2;}
                    }
                    foreach($buildings as $building){$xs=array_column($building['points'],0);$ys=array_column($building['points'],1);if($xs&&$x>=min($xs)-3&&$x<=max($xs)+3&&$y>=min($ys)-3&&$y<=max($ys)+3){$valid=false;break;}}
                    if(!$valid)break;
                    // Do not create a turnaround through another exported branch.
                    foreach($original as $other)if($other['name']!==$branch['name'])
                        for($j=1;$j<count($other['points']);$j++)if(BeamLanes::distance([$x,$y],$other['points'][$j-1],$other['points'][$j])[0]<$avoidWidths[$other['name']]){$valid=false;break 3;}
                    $xy[]=[$x,$y];
                }
                if($valid&&count($xy)===count($offsets)&&max($heights)-min($heights)<=1.5){$candidate=$xy;break;}
            }
            if($candidate===null){$skipped++;continue;}
            $loop=BeamPlan::sample($candidate,3);$p=BeamLanes::infer(['lanes'=>'1','width'=>'4','oneway'=>'yes'],'service',1)+['unmarked'=>true];
            $prepared['roads'][]=['name'=>'turnaround_'.$created,'points'=>array_map(static fn($xy)=>[$xy[0],$xy[1],$end[2]],$loop),
                'profiles'=>array_fill(0,count($loop),$p),'turnaround_for'=>$branch['name']];
            $created++;
        }
        $prepared['turnarounds']=['created'=>$created,'skipped'=>$skipped,'synthetic'=>true,'policy'=>'Two-way stub ends only; require flat land, water/road clearance and map bounds.'];
        return $prepared;
    }
    public static function graph(array $main,array $profiles,array $prepared): array
    {
        $waypoints=[];$segments=[];$sequence=[];$along=0.0;
        foreach($main as $i=>$p){if($i)$along+=hypot($p[0]-$main[$i-1][0],$p[1]-$main[$i-1][1]);$sequence[]=['point'=>$p,'profile'=>$profiles[$i],'along'=>$along];}
        foreach($prepared['junctions'] as $j)$sequence[]=['point'=>[$j['x'],$j['y'],$j['z']],'profile'=>$profiles[min(count($profiles)-1,$j['segment'])],'along'=>$j['along']];
        usort($sequence,static fn($a,$b)=>$a['along']<=>$b['along']);
        $primary=[];$last=null;$lastAlong=-INF;
        $node=static function($name,$p,$width)use(&$waypoints){$waypoints[]=['class'=>'BeamNGWaypoint','name'=>$name,'__parent'=>'MissionGroup','position'=>array_slice($p,0,3),'scale'=>array_fill(0,3,max(0.5,$width/2))];};
        $link=static function($name,$a,$b,$profile,$speed=null,$drivability=1)use(&$segments){
            if($a===$b)return;
            $segments[$name]=['nodes'=>[$a,$b],'drivability'=>$drivability,'oneWay'=>$profile['oneway'],'autoLanes'=>false,
                'lanesLeft'=>$profile['backward'],'lanesRight'=>$profile['forward'],'autoJunction'=>false,'hiddenInNavi'=>false]+($speed===null?[]:['speedLimit'=>$speed]);
        };
        foreach($sequence as $item){
            if($item['along']-$lastAlong<0.05)continue;
            $name='sl_nav_'.count($primary);$node($name,$item['point'],$item['profile']['width_m']);
            if($last!==null)$link('road_'.count($primary),$last,$name,$item['profile']);
            $primary[]=['name'=>$name,'along'=>$item['along']];$last=$name;$lastAlong=$item['along'];
        }
        $ends=[];

        foreach($prepared['roads'] as $index=>$branch){
            if(isset($branch['turnaround_for']))continue;
            $join=$branch['join'];$parent=$primary[0]['name'];$best=INF;
            foreach($primary as $p)if(abs($p['along']-$join['along'])<$best){$best=abs($p['along']-$join['along']);$parent=$p['name'];}
            $last=$parent;$profile=$branch['profiles'][0];
            $path=array_merge($branch['connector']??[], $branch['points']);$lastPoint=[$join['x'],$join['y'],$join['z']];
            foreach($path as $i=>$p){
                if(hypot($p[0]-$lastPoint[0],$p[1]-$lastPoint[1])<0.05)continue;
                $name='sl_side_'.$index.'_'.$i;$node($name,$p,$profile['width_m']);
                // One-way truncated arms have no safe return path; show but don't route traffic into them.
                $link('side_'.$index.'_'.$i,$last,$name,$profile,null,$profile['oneway']?0:1);
                $last=$name;$lastPoint=$p;
            }
            $ends[$branch['name']]=$last;
        }
        foreach($prepared['roads'] as $index=>$branch)if(isset($branch['turnaround_for'],$ends[$branch['turnaround_for']])){
            $parent=$ends[$branch['turnaround_for']];$last=$parent;$profile=$branch['profiles'][0];
            foreach($branch['points'] as $i=>$p){$name='sl_turn_'.$index.'_'.$i;$node($name,$p,1.5);$link('turn_'.$index.'_'.$i,$last,$name,$profile,3);$last=$name;}
            $link('turn_'.$index.'_return',$last,$parent,$profile,3);
        }
        return ['objects'=>$waypoints,'segments'=>$segments,'turnarounds'=>$prepared['turnarounds']??['created'=>0]];
    }
}
