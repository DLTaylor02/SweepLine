<?php
(function():void{
    $noop=static fn($m)=>null;
    $node=static fn($name,$p)=>['class'=>'BeamNGWaypoint','name'=>$name,'__parent'=>'MissionGroup','position'=>$p,'scale'=>[4.,4.,4.]];
    $link=static fn($a,$b,$changes=[])=>array_replace(['nodes'=>[$a,$b],'drivability'=>1,'oneWay'=>false,'autoLanes'=>false,'lanesLeft'=>1,'lanesRight'=>1,'autoJunction'=>false,'hiddenInNavi'=>false,'speedLimit'=>20.],$changes);
    $objects=[['class'=>'TSStatic','name'=>'pavement','position'=>[0,0,0]],$node('a',[0.,0.,10.]),$node('b',[50.,0.,10.]),$node('side',[20.,10.,10.])];
    $segments=['coarse'=>$link('a','b')];$last='a';
    foreach([10,20,30,40] as $x){$name='n'.$x;$objects[]=$node($name,[$x,0.,10.]);$segments[$name]=$link($last,$name);$last=$name;}
    $segments['end']=$link($last,'b');$segments['branch']=$link('n20','side');$snapshot=serialize([$objects,$segments]);
    $out=SweepLine\BeamNavigationOverlap::merge($objects,$segments,$noop);$nodes=array_column(array_filter($out['objects'],static fn($o)=>$o['class']==='BeamNGWaypoint'),null,'name');$pairs=[];$unique=true;$adj=[];
    foreach($out['segments'] as $edge){[$a,$b]=$edge['nodes'];$pair=[$a,$b];sort($pair);$pair=implode(':',$pair);if(isset($pairs[$pair])||!isset($nodes[$a],$nodes[$b]))$unique=false;$pairs[$pair]=true;$adj[$a][$b]=true;$adj[$b][$a]=true;}
    check($unique&&count($out['segments'])===count($nodes)-1&&$out['report']['duplicate_edges_removed']===5,'Differently sampled copies of one physical AI road become one connected trace instead of a reducible parallel loop');
    check($out['segments']['branch']===$segments['branch']&&count($adj['n20'])===3&&serialize([$objects,$segments])===$snapshot,'Shared road overlay retains branch attachments, non-road objects and immutable inputs');
    $again=SweepLine\BeamNavigationOverlap::merge($out['objects'],$out['segments'],$noop);
    check($again['objects']===$out['objects']&&$again['segments']===$out['segments'],'Shared navigation segmentation is repeatable without generating further links');

    $twoway=[$node('a',[0.,0.,10.]),$node('b',[50.,0.,10.])];
    $reverse=['forward'=>$link('a','b',['lanesLeft'=>1,'lanesRight'=>2]),'reverse'=>$link('b','a',['lanesLeft'=>2,'lanesRight'=>1])];
    $out=SweepLine\BeamNavigationOverlap::merge($twoway,$reverse,$noop);
    check(count($out['segments'])===1&&$out['segments']['forward']===$reverse['forward'],'Reversed two-way aliases preserve asymmetric left/right lane counts in travel orientation');
    $opposed=['forward'=>$link('a','b',['oneWay'=>true,'lanesLeft'=>0]),'reverse'=>$link('b','a',['oneWay'=>true,'lanesLeft'=>0])];
    $out=SweepLine\BeamNavigationOverlap::merge($twoway,$opposed,$noop);
    check($out['segments']===$opposed,'Opposing one-way permissions are retained rather than treating them as interchangeable edges');
    foreach([['speedLimit'=>10.],['drivability'=>0],['type'=>'private'],['lanesLeft'=>2],['hiddenInNavi'=>true]] as $difference){
        $edges=['first'=>$link('a','b'),'different'=>$link('a','b',$difference)];$out=SweepLine\BeamNavigationOverlap::merge($twoway,$edges,$noop);
        check($out['segments']===$edges,'Routing metadata differences survive overlap processing: '.json_encode($difference));
    }

    $aliases=[$node('a',[0.,0.,10.]),$node('b',[50.,0.,10.]),$node('c',[0.,.03,10.02]),$node('d',[50.,.03,10.02])];
    $out=SweepLine\BeamNavigationOverlap::merge($aliases,['first'=>$link('a','b'),'copy'=>$link('c','d')],$noop);
    check(count($out['objects'])===2&&count($out['segments'])===1&&$out['aliases']===['c'=>'a','d'=>'b']&&$out['objects'][0]===$aliases[0],'Coincident endpoint aliases retain existing waypoint positions and radii within the measured tolerance');
    $third=[$node('a',[0.,0.,10.]),$node('b',[40.,0.,10.]),$node('c',[10.,0.,10.]),$node('d',[20.,0.,10.]),$node('e',[20.,0.,10.]),$node('f',[30.,0.,10.])];
    $out=SweepLine\BeamNavigationOverlap::merge($third,['long'=>$link('a','b'),'left'=>$link('c','d'),'right'=>$link('e','f')],$noop);
    $valid=true;foreach($out['segments'] as $edge){[$a,$b]=$edge['nodes'];$byName=array_column($out['objects'],null,'name');if($byName[$a]['position']===$byName[$b]['position'])$valid=false;}
    check($valid&&count($out['segments'])===4&&($out['aliases']['e']??null)==='d','Three overlapping traces share an interior joint without producing a zero-length navigation edge');

    foreach([[[0.,1.,10.],[50.,1.,10.]],[[0.,0.,14.],[50.,0.,14.]],[[25.,-20.,10.],[25.,20.,10.]]] as $line){
        $separate=array_merge($twoway,[$node('c',$line[0]),$node('d',$line[1])]);$edges=['main'=>$link('a','b'),'other'=>$link('c','d')];$out=SweepLine\BeamNavigationOverlap::merge($separate,$edges,$noop);
        check($out['objects']===$separate&&$out['segments']===$edges,'Parallel roads, grade-separated roads and transverse crossings are not connected by proximity: '.json_encode($line));
    }
    $loop=[$node('a',[0.,0.,10.]),$node('b',[20.,0.,10.]),$node('c',[10.,15.,10.])];$edges=['ab'=>$link('a','b'),'bc'=>$link('b','c'),'ca'=>$link('c','a')];
    $out=SweepLine\BeamNavigationOverlap::merge($loop,$edges,$noop);
    check($out['objects']===$loop&&$out['segments']===$edges,'A genuine curved navigation loop is preserved');
    $vertical=[$node('a',[10.,10.,10.]),$node('b',[10.,10.,14.])];$vertical[1]['scale']=[5.,5.,5.];$edges=['vertical'=>$link('a','b')];
    $out=SweepLine\BeamNavigationOverlap::merge($vertical,$edges,$noop);
    check($out['objects']===$vertical&&$out['segments']===$edges&&$out['report']['maximum_inserted_radius_error_m']===0.,'Unchanged vertical navigation links do not acquire false displacement or radius errors');
    $cancelled=false;try{SweepLine\BeamNavigationOverlap::merge($twoway,['road'=>$link('a','b')],static function($m){throw new RuntimeException('cancel fixture');});}catch(RuntimeException $e){$cancelled=$e->getMessage()==='cancel fixture';}
    check($cancelled,'Navigation overlap processing honors worker cancellation');
})();
