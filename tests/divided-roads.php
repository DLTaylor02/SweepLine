<?php
use SweepLine\RoadGraph;
$splitPoints=[1=>[-84.40,41.20],2=>[-84.39,41.20],3=>[-84.38,41.2003],4=>[-84.38,41.1997],5=>[-84.37,41.20],6=>[-84.36,41.20]];
$splitWay=static function($id,$nodes,$direction=0)use($splitPoints){return road($id,$nodes,array_map(static fn($n)=>$splitPoints[$n],$nodes),'Split Road',$direction);};
$splitRoads=[$splitWay(11,[1,2]),$splitWay(12,[2,3,5],1),$splitWay(13,[5,4,2],1),$splitWay(14,[5,6])];
$splitCandidates=iterator_to_array(RoadGraph::candidates($splitRoads));
check(count($splitCandidates)===2,'Divided road yields two continuous legal carriageway paths');
$splitSignatures=[];
foreach($splitCandidates as $candidate){
 $direction=RoadGraph::direction($candidate['parts']);$nodes=$direction<0?array_reverse($candidate['nodes']):$candidate['nodes'];$splitSignatures[]=implode(',',$nodes);
 check(count($candidate['parts'])===3,'Divided road includes both shared two-way approaches');
 check(!(in_array(3,$nodes,true)&&in_array(4,$nodes,true)),'Candidate never combines opposite carriageways or draws a median connector');
 check($direction!==0,'One-way restriction survives two-way candidate endpoints');
 $export=['name'=>'Split Road','geometry'=>['type'=>'LineString','coordinates'=>$candidate['points']],'parts'=>array_map(static fn($part)=>['direction'=>$part['direction'],'reversed'=>false],$candidate['parts'])];
 $prepared=SweepLine\RoadExport::prepare($export);
 check($prepared['geometry']['coordinates'][0]===$splitPoints[$nodes[0]],'Default export orientation respects divided section legal direction');
}
sort($splitSignatures);
check($splitSignatures===['1,2,3,5,6','6,5,4,2,1'],'Split and rejoin retain both complete travel directions');
$permuted=iterator_to_array(RoadGraph::candidates(array_reverse($splitRoads)));$signatures=[];
foreach($permuted as $c){$ns=RoadGraph::direction($c['parts'])<0?array_reverse($c['nodes']):$c['nodes'];$signatures[]=implode(',',$ns);}sort($signatures);
check($signatures===$splitSignatures,'Divided-road coverage does not depend on OSM import order');
$opposing=[$splitWay(21,[1,2],1),$splitWay(22,[2,3]),$splitWay(23,[3,5],-1)];
foreach(RoadGraph::candidates($opposing) as $c)check(count(array_unique(array_filter(array_column($c['parts'],'direction'))))<=1,'Opposing one-way signs cannot join through a two-way segment');
$fork=[$splitWay(31,[1,2]),$splitWay(32,[2,3],1),$splitWay(33,[2,4],1)];
foreach(RoadGraph::candidates($fork) as $c)check(!(in_array(3,$c['nodes'],true)&&in_array(4,$c['nodes'],true)),'Ambiguous same-direction forks do not merge branches');
check(count(iterator_to_array(RoadGraph::candidates([$splitWay(41,[1,2]),$splitWay(42,[5,6],1)])))===2,'Matching names cannot connect across a mapped gap');
$negative=$splitRoads;$negative[1]=$splitWay(12,[5,3,2],-1);
$negativePaths=iterator_to_array(RoadGraph::candidates($negative));
check(count($negativePaths)===2&&max(array_map(static fn($c)=>count($c['parts']),$negativePaths))===3,'OSM oneway=-1 crosses split and rejoin correctly');
