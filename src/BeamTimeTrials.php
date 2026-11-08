<?php
declare(strict_types=1);
namespace SweepLine;

/** Native v0.40 point-to-point missions, generated only from the final exported road. */
final class BeamTimeTrials
{
    /** BeamNG quaternions rotate vectors with the inverse of the usual active convention. */
    public static function rotation(array $direction): array
    {
        [$x,$y,$z]=$direction;$yaw=atan2($x,$y);$pitch=atan2($z,hypot($x,$y));
        $s=sin($pitch/2);$c=cos($pitch/2);$a=sin($yaw/2);$b=cos($yaw/2);
        return [-$s*$b,$s*$a,$c*$a,$c*$b];
    }
    private static function station(array $road,array $profiles,array $chain,float $at): array
    {
        $lo=1;$hi=count($road)-1;while($lo<$hi){$mid=(int)(($lo+$hi)/2);if($chain[$mid]<$at)$lo=$mid+1;else $hi=$mid;}
        $i=$lo;$a=$road[$i-1];$b=$road[$i];$length=max(1e-8,$chain[$i]-$chain[$i-1]);$t=max(0,min(1,($at-$chain[$i-1])/$length));
        $point=[];for($j=0;$j<3;$j++)$point[]=$a[$j]+($b[$j]-$a[$j])*$t;
        $profile=$profiles[$i];$width=$profiles[$i-1]['width_m']*(1-$t)+$profile['width_m']*$t;
        return ['point'=>$point,'direction'=>[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]],'width'=>$width,'profile'=>$profile,'at'=>$at];
    }
    private static function grid(array $station,bool $reverse): array
    {
        $direction=array_map(static fn($v)=>$reverse?-$v:$v,$station['direction']);
        $length=hypot($direction[0],$direction[1]);$profile=$station['profile'];
        $width=$profile['carriageway_width_m']??$station['width'];$lanes=max(1,$profile['lanes']??2);
        $offset=$lanes>1?$width/2-$width/$lanes/2:0;
        $p=$station['point'];$p[0]+=$direction[1]/$length*$offset;$p[1]-=$direction[0]/$length*$offset;$p[2]+=0.05;
        return ['pos'=>$p,'rot'=>self::rotation($direction)];
    }
    public static function build(string $level,array $plan,array $road,array $profiles,array $start,array $finish): array
    {
        $chain=[0.];for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
        $from=$start['chainage_m'];$to=$finish['chainage_m'];
        if($to-$from<5)return ['files'=>[],'report'=>['events'=>[],'warning'=>'Time trials omitted: start and finish arches do not enclose at least five metres in the selected direction.']];
        $stations=[self::station($road,$profiles,$chain,$from)];$last=$from;$turn=0.;$heading=null;$minimum=max(8,($to-$from)/1500);
        for($i=1;$i<count($road);$i++){
            if($chain[$i]<=$from||$chain[$i]>=$to)continue;
            $angle=atan2($road[$i][1]-$road[$i-1][1],$road[$i][0]-$road[$i-1][0]);
            if($heading!==null)$turn+=abs(atan2(sin($angle-$heading),cos($angle-$heading)));$heading=$angle;
            if($chain[$i]-$last>=$minimum&&($chain[$i]-$last>=150||$turn>=M_PI/6)){$stations[]=self::station($road,$profiles,$chain,$chain[$i]);$last=$chain[$i];$turn=0.;}
        }
        $stations[]=self::station($road,$profiles,$chain,$to);
        // The arch points are the authoritative event endpoints, including their final elevations.
        $stations[0]['point']=$start['point'];$stations[count($stations)-1]['point']=$finish['point'];
        $files=[];$events=[];
        foreach([false,true] as $reverse){
            $label=$reverse?'Reverse':'Forward';$key=strtolower($label);$ordered=$reverse?array_reverse($stations):$stations;
            $folder='gameplay/missions/'.$level.'/timeTrial/sweepline-'.$key.'/';$count=count($ordered);
            $nodes=[];$segments=[];$positions=[];
            foreach($ordered as $i=>$station){
                $direction=array_map(static fn($v)=>$reverse?-$v:$v,$station['direction']);$norm=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$direction)));
                $normal=array_map(static fn($v)=>$v/$norm,$direction);$grid=self::grid($station,$reverse);$recovery=10000+$i;
                $positions[]=['oldId'=>$recovery,'name'=>$i===0?'Standing start':'Recovery '.$i,'group'=>$i===0?'start':'recovery']+$grid;
                $nodes[]=['oldId'=>$i+1,'name'=>$i===0?'Start arch':($i===$count-1?'Finish arch':'Checkpoint '.$i),
                    'mode'=>'manual','navRadiusScale'=>1,'pos'=>$station['point'],'normal'=>$normal,'radius'=>max(3,$station['width']/2+1),
                    'recovery'=>$recovery,'reverseRecovery'=>-1,'sidePadding'=>[0,0],'visible'=>true,'useAsSplit'=>true];
                if($i)$segments[]=['oldId'=>$count+$i,'name'=>'Road section '.$i,'mode'=>'waypoint','from'=>$i,'to'=>$i+1,'capsules'=>[]];
            }
            $title=$plan['name'].' - '.$label.' Time Trial - '.Version::label();
            $description='Drive the exported road from '.($reverse?'Finish to Start':'Start to Finish').'. '.Version::label().'.';
            if($plan['alternate_names']??[])$description.=' Also known as: '.implode('; ',$plan['alternate_names']).'.';
            $type=['closed'=>false,'defaultLaps'=>1,'canChangeLapAmount'=>false,'reversible'=>false,'allowRollingStart'=>false,
                'allowRecover'=>true,'recoverLimit'=>-1,'recoverPenalty'=>0,'allowFlip'=>false,'flipLimit'=>0,'flipPenalty'=>0,
                'justFinishPenalty'=>-1,'mapPreviewMode'=>'waypoints','startScreenText'=>'Follow the checkpoints to the opposite arch. Use your current vehicle.','dynamicRewardsExponentForLaps'=>1];
            // Native result aggregation reads these keys even when timed medal stars are inactive.
            foreach(['bronze','silver','gold'] as $medal){
                foreach(['Time','Time2','Time3','TimeTotal','TimePenalty','1Laps','2Laps','3Laps','DynamicLapTimeBase'] as $suffix)$type[$medal.$suffix]=-1;
                $type[$medal.'DynamicLapTimePerLap']=0;
            }
            for($i=1;$i<=5;$i++)$type['lapTime'.$i]=-1;
            $base='/levels/'.$level.'/';$preview=$base.($reverse?'spawn-end.png':'spawn-start.png');
            $files[$folder.'info.json']=['name'=>$title,'description'=>$description,'author'=>'SweepLine','missionType'=>'timeTrial',
                'missionTypeData'=>$type,'retryBehaviour'=>'infiniteRetries','devMission'=>false,
                'additionalAttributes'=>['vehicle'=>'own'],'customAdditionalAttributes'=>(object)[],
                'startTrigger'=>['type'=>'coordinates','level'=>$level,'pos'=>$ordered[0]['point'],'rot'=>$positions[0]['rot'],'radius'=>max(4,min(12,$ordered[0]['width']/2+1))],
                'startCondition'=>['type'=>'always'],'visibleCondition'=>['type'=>'always'],
                'careerSetup'=>['showInFreeroam'=>true,'showInCareer'=>false,'skill'=>'(none)','starsActive'=>['justFinish'=>true],'defaultStarKeys'=>['justFinish'],'starRewards'=>(object)[],'starOutroTexts'=>(object)[]],
                'setupModules'=>['vehicles'=>['enabled'=>true,'includePlayerVehicle'=>true,'prioritizePlayerVehicle'=>true,'vehicles'=>[],'playerVehicleDiffs'=>(object)[]],
                    'traffic'=>['enabled'=>false],'environment'=>['enabled'=>false]],'previewFile'=>$preview,'thumbnailFile'=>$preview];
            $files[$folder.'race.race.json']=['name'=>$title,'description'=>$description,'authors'=>'SweepLine','difficulty'=>0,'defaultLaps'=>1,
                'startNode'=>1,'endNode'=>$count,'defaultStartPosition'=>10000,'reverseStartPosition'=>-1,'rollingStartPosition'=>-1,'rollingReverseStartPosition'=>-1,
                'pathnodes'=>$nodes,'segments'=>$segments,'startPositions'=>$positions,'pacenotes'=>[],'prefabs'=>[],'forwardPrefabs'=>[],'reversePrefabs'=>[],
                'hideMission'=>false,'classification'=>['closed'=>false,'branching'=>false,'reversible'=>false,'allowRollingStart'=>false]];
            $events[]=['direction'=>$key,'mission'=>$folder.'info.json','race'=>$folder.'race.race.json','start'=>$ordered[0]['point'],'finish'=>$ordered[$count-1]['point'],'checkpoints'=>$count,'length_m'=>$to-$from];
        }
        return ['files'=>$files,'report'=>['events'=>$events,'vehicle'=>'Current player vehicle','course'=>'Selected exported carriageway between arches; reverse follows the same stretch in the opposite simulation direction','checkpoint_spacing_m'=>150,'in_game_verified'=>false]];
    }
}
