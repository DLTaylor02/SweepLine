<?php
(function():void{
    $make=static function(array $points,array $changes=[]):array{
        $objects=[['class'=>'TSStatic','name'=>'pavement','position'=>[0,0,0]]];$segments=[];
        foreach($points as $i=>$p){
            $objects[]=['class'=>'BeamNGWaypoint','name'=>'n'.$i,'position'=>$p,'scale'=>[4.,4.,4.],'__parent'=>'MissionGroup'];
            if($i)$segments['e'.$i]=['nodes'=>['n'.($i-1),'n'.$i],'drivability'=>1,'oneWay'=>true,'lanesLeft'=>0,'lanesRight'=>2,'autoJunction'=>false]+($changes[$i]??[]);
        }
        return [$objects,$segments];
    };
    $noop=static fn($m)=>null;$points=[];
    for($x=0;$x<=100;$x++)$points[]=[$x,0.,10.];
    [$objects,$segments]=$make($points);$before=serialize([$objects,$segments]);
    $fit=SweepLine\BeamNavigation::compact($objects,$segments,$noop);
    $names=array_fill_keys(array_column($fit['objects'],'name'),true);$valid=true;
    foreach($fit['segments'] as $s){[$a,$b]=$s['nodes'];$valid=$valid&&isset($names[$a],$names[$b])&&$s['oneWay']&&$s['lanesRight']===2;}
    check(count($fit['objects'])<15&&$valid&&isset($names['n0'],$names['n100'])&&serialize([$objects,$segments])===$before,'Dense straight AI paths compact while preserving endpoints, direction, lane rules, non-road objects and immutable inputs');
    $fork=$objects;$fork[]=['class'=>'BeamNGWaypoint','name'=>'side','position'=>[50,20,10],'scale'=>[4,4,4]];
    $forkEdges=$segments;$forkEdges['side']=['nodes'=>['n50','side'],'oneWay'=>false,'drivability'=>1];
    $f=SweepLine\BeamNavigation::compact($fork,$forkEdges,$noop);
    check(in_array('n50',array_column($f['objects'],'name'),true)&&$f['segments']['side']===$forkEdges['side'],'Shared fork nodes and side-road connections remain explicit');
    $changed=$segments;$changed['e51']['speedLimit']=15;$changed['e52']['lanesRight']=1;$changed['e53']['drivability']=0;
    $f=SweepLine\BeamNavigation::compact($objects,$changed,$noop);$names=array_fill_keys(array_column($f['objects'],'name'),true);
    check(isset($names['n50'],$names['n51'],$names['n52'],$names['n53'])&&$f['segments']['e51']===$changed['e51']&&$f['segments']['e52']===$changed['e52']&&$f['segments']['e53']===$changed['e53'],'Speed, lane and drivability transitions cannot be merged away');
    $curves=[];for($i=0;$i<=100;$i++)$curves[]=[$i,sin($i/10)*4,10+sin($i/12)*2];
    [$o,$s]=$make($curves);$o[51]['scale']=[5,5,5];$f=SweepLine\BeamNavigation::compact($o,$s,$noop);$r=$f['report'];
    check($r['removed_interior_waypoints']>0&&$r['maximum_removed_xy_error_m']<=.15&&$r['maximum_removed_z_error_m']<=.10&&$r['maximum_removed_radius_error_m']<=.10,'Curved AI paths and road-width changes retain tight lateral, vertical and radius tolerances');
    $shortcuts=true;
    foreach($f['segments'] as $edge){[$a,$b]=$edge['nodes'];$lo=(int)substr($a,1);$hi=(int)substr($b,1);if($hi-$lo<2)continue;$length=0.;for($i=$lo+1;$i<=$hi;$i++)$length+=hypot($curves[$i][0]-$curves[$i-1][0],$curves[$i][1]-$curves[$i-1][1]);if($length>20.000001)$shortcuts=false;}
    check($shortcuts,'Compacted AI edges respect the maximum newly merged path spacing');
    [$o,$s]=$make([[0,0,0],[5,0,0],[2,0,0],[7,0,0]]);$f=SweepLine\BeamNavigation::compact($o,$s,$noop);
    check(count($f['objects'])===count($o)&&count($f['segments'])===3,'Short collinear reversals are retained instead of turning backtracking into a shortcut');
    [$o,$s]=$make([[0,0,0],[5,0,0],[5,5,0]]);$s['close']=['nodes'=>['n2','n0']]+$s['e1'];
    $f=SweepLine\BeamNavigation::compact($o,$s,$noop);
    check(count($f['segments'])===3&&count($f['objects'])===count($o),'Closed navigation cycles remain connected without degenerate collapsed edges');
    $split=$segments;$split['other']=['nodes'=>['n10','n9']]+$segments['e10'];
    $f=SweepLine\BeamNavigation::compact($objects,$split,$noop);
    check(isset($f['segments']['other'])&&in_array('n9',array_column($f['objects'],'name'),true),'Direction conflicts and parallel return edges preserve their explicit topology');
})();
