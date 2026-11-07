<?php
use SweepLine\{BeamLanes,BeamRoadSurface,BeamPng,BeamPavement};
$layoutPoints=[];for($x=0;$x<=300;$x+=5)$layoutPoints[]=[$x,0,0];
foreach([false,true] as $reverse){
 $raw=[];foreach($layoutPoints as $p){$oneway=$p[0]>=100&&$p[0]<200;$v=BeamLanes::infer(['lanes'=>$oneway?'2':'4'],$oneway?'trunk':'secondary',$oneway?1:0);$v['legal_forward']=!$reverse;$raw[]=$v;}
 $profiles=BeamRoadSurface::taper($layoutPoints,$raw);$before=$profiles[10]['lane_paint'];$middle=$profiles[30]['lane_paint'];
 check(abs($middle['center']['visible']-1)<1e-8&&abs($middle['center']['u']-($reverse?1:0))<1e-8,'Divided-road yellow median boundary survives the split in orientation '.(int)$reverse);
 check(abs($middle['b1']['visible'])<1e-8&&abs($before['b1']['visible']-1)<1e-8,'Opposing lane markings stay on shared two-way approaches, orientation '.(int)$reverse);
}
$a=BeamLanes::infer(['lanes'=>'2'],'trunk',0);$b=BeamLanes::infer(['lanes'=>'2'],'trunk',1);$sameWidth=array_fill(0,count($layoutPoints),$a);for($i=20;$i<count($sameWidth);$i++)$sameWidth[$i]=$b;
$transition=BeamRoadSurface::taper($layoutPoints,$sameWidth);
check($transition[20]['transition']&&isset($transition[20]['lane_paint']),'Equal-width direction changes still get a split/merge paint transition');
$oneway=BeamLanes::infer(['lanes'=>'2'],'trunk',1);$oneway['legal_forward']=true;
$reversed=$oneway;$reversed['legal_forward']=false;
check(BeamPavement::key($oneway)!==BeamPavement::key($reversed),'Reversed one-way paint textures have distinct material identities');

foreach([false,true] as $reverse){
 $raw=[];foreach($layoutPoints as $p){$oneway=$p[0]>=100&&$p[0]<200;$v=BeamLanes::infer(['lanes'=>$oneway?'2':'4'],'trunk',$oneway?1:0);$v['legal_forward']=!$reverse;$raw[]=$v;}
 $v=BeamRoadSurface::taper($layoutPoints,$raw);$good=true;
 foreach($v as $i=>$p)if($raw[$i]['oneway']&&$layoutPoints[$i][0]>=135&&$layoutPoints[$i][0]<=165)$good=$good&&abs($p['lane_paint']['center']['u']-($reverse?1:0))<1e-7;
 check($good,'Yellow median paint stays at the edge outside the split/merge approach, orientation '.(int)$reverse);
}

(function(): void {
    $points=[];$raw=[];for($x=0;$x<=300;$x+=2){$points[]=[$x,0];$one=$x>=100&&$x<200;$p=\SweepLine\BeamLanes::infer(['lanes'=>$one?'2':'4'],'trunk',$one?1:0);$p['legal_forward']=true;$raw[]=$p;}
    $profiles=\SweepLine\BeamRoadSurface::taper($points,$raw);$joined=\SweepLine\BeamMedian::anchor($points,$profiles);$shared=true;
    foreach($profiles as $i=>$p)if(!$raw[$i]['oneway'])$shared=$shared&&abs($p['lane_paint']['center']['u']-0.5)<1e-9;
    check($shared,'Split and merge yellow lines never sweep across the opposing shared lanes');
    check(abs($joined[50][1])<1e-9&&abs($joined[99][1])<1e-9,'Divided-road anchor starts and ends without a sideways centreline step');
    $maximum=0.;for($i=1;$i<count($joined);$i++)$maximum=max($maximum,abs($joined[$i][1]-$joined[$i-1][1]));
    check($maximum<0.25,'Split/merge lane anchoring has a bounded gradual lateral transition');
})();
