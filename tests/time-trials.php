<?php
// Synthetic final-road geometry; native formats checked against installed v0.40 readers.
(function():void{
    $road=[];$profiles=[];
    for($i=0;$i<=80;$i++){$road[]=[$i*10,30*sin($i/12),50+$i*0.2];$profiles[]=SweepLine\BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0);}
    $start=SweepLine\BeamMap::spawn($road,$profiles,false,false);$end=SweepLine\BeamMap::spawn($road,$profiles,true,false);
    $a=SweepLine\BeamMap::archAnchor($road,$profiles,$start,false);$b=SweepLine\BeamMap::archAnchor($road,$profiles,$end,true);
    $plan=['name'=>'Synthetic preferred name','alternate_names'=>['Alternate test name'],'orientation'=>'reverse'];
    $result=SweepLine\BeamTimeTrials::build('sweepline_test',$plan,$road,$profiles,$a,$b);
    check(count($result['files'])===4&&count($result['report']['events'])===2,'Both arch time trials include mission metadata and a native race file');
    $forward=$result['files']['gameplay/missions/sweepline_test/timeTrial/sweepline-forward/race.race.json'];
    $reverse=$result['files']['gameplay/missions/sweepline_test/timeTrial/sweepline-reverse/race.race.json'];
    $info=$result['files']['gameplay/missions/sweepline_test/timeTrial/sweepline-forward/info.json'];
    $reverseInfo=$result['files']['gameplay/missions/sweepline_test/timeTrial/sweepline-reverse/info.json'];
    check($info['startTrigger']['pos']===$a['point']&&$reverseInfo['startTrigger']['pos']===$b['point']&&end($forward['pathnodes'])['pos']===$b['point']&&end($reverse['pathnodes'])['pos']===$a['point'],'Forward and reverse activate and finish exactly at the matching exported arches');
    check($info['careerSetup']['showInFreeroam']&&!$info['careerSetup']['showInCareer']&&$info['setupModules']['vehicles']['includePlayerVehicle']&&$info['setupModules']['vehicles']['vehicles']===[],'Freeroam events use the current vehicle without career locks or copied vehicles');
    check(str_contains($info['name'],$plan['name'])&&str_contains($info['name'],SweepLine\Version::label())&&str_contains($info['description'],'Alternate test name'),'Time-trial names, aliases and version follow the export presentation');
    check(!$forward['classification']['closed']&&!$forward['classification']['reversible']&&$forward['defaultLaps']===1&&$info['missionTypeData']['bronzeTime']===-1,'Each direction is a separate single-pass trial without invented medal targets');
    $valid=true;
    foreach([$forward,$reverse] as $race){
        $ids=[];foreach(array_merge($race['startPositions'],$race['pathnodes'],$race['segments']) as $obj){if(isset($ids[$obj['oldId']]))$valid=false;$ids[$obj['oldId']]=true;}
        $nodes=array_column($race['pathnodes'],null,'oldId');$spawns=array_column($race['startPositions'],null,'oldId');
        $valid=$valid&&isset($nodes[$race['startNode']],$nodes[$race['endNode']],$spawns[$race['defaultStartPosition']]);
        foreach($race['segments'] as $segment)$valid=$valid&&isset($nodes[$segment['from']],$nodes[$segment['to']])&&$segment['to']===$segment['from']+1;
        foreach($nodes as $node)$valid=$valid&&isset($spawns[$node['recovery']])&&$node['radius']>=3;
    }
    check($valid&&count($forward['pathnodes'])>2,'Native oldId references form valid ordered graphs with checkpoint recovery positions');
    // Match the inverse quaternion-vector convention used by BeamNG mathlib.
    $rotate=static function($q,$v){[$x,$y,$z,$w]=$q;$u=[$x,$y,$z];$cross=static fn($a,$b)=>[$a[1]*$b[2]-$a[2]*$b[1],$a[2]*$b[0]-$a[0]*$b[2],$a[0]*$b[1]-$a[1]*$b[0]];$c=$cross($u,$v);$d=$cross($u,$c);return [$v[0]-2*$w*$c[0]+2*$d[0],$v[1]-2*$w*$c[1]+2*$d[1],$v[2]-2*$w*$c[2]+2*$d[2]];};
    $aligned=true;foreach([$forward,$reverse] as $race){
        $node=$race['pathnodes'][0];$spawn=$race['startPositions'][0];$heading=$rotate($spawn['rot'],[0,1,0]);$dot=array_sum(array_map(static fn($a,$b)=>$a*$b,$heading,$node['normal']));$aligned=$aligned&&$dot>0.99999;
    }
    check($aligned,'Standing grids and checkpoints face forward in both directions under BeamNG quaternion conventions');
    $short=SweepLine\BeamTimeTrials::build('short',$plan,$road,$profiles,$b,$a);
    check($short['files']===[]&&!empty($short['report']['warning']),'Crossed or overlapping arch limits omit unsupported events without failing the map');
})();
