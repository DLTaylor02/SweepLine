<?php
use SweepLine\{BeamBridgeDeck,BeamPavement};
$deckPoints=[[0,0,10],[50,0,11],[100,0,12]];
$deckProfiles=[['width_m'=>8],['width_m'=>10],['width_m'=>12]];
$deckSections=[];foreach($deckPoints as $i=>$_)$deckSections[]=BeamPavement::section($deckPoints,$deckProfiles,$i);
$deck=BeamBridgeDeck::build($deckPoints,$deckSections,[['start_m'=>12.5,'end_m'=>87.5]],'test_deck');
$deckXml=simplexml_load_string($deck['xml']);$deckXml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$deckValues=array_map('floatval',preg_split('/\s+/',trim((string)$deckXml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
$deckNormals=array_map('floatval',preg_split('/\s+/',trim((string)$deckXml->xpath('//c:source[@id="normals"]/c:float_array')[0])));
$deckDown=false;$deckUp=false;$deckStart=false;$deckEnd=false;$deckEdges=[];
for($i=0;$i<count($deckValues);$i+=9){
 $triangle=[array_slice($deckValues,$i,3),array_slice($deckValues,$i+3,3),array_slice($deckValues,$i+6,3)];
 $deckDown=$deckDown||$deckNormals[$i+2]<-0.99;$deckUp=$deckUp||$deckNormals[$i+2]>0.99;
 $deckStart=$deckStart||$deckNormals[$i]<-0.99;$deckEnd=$deckEnd||$deckNormals[$i]>0.99;
 foreach([[0,1],[1,2],[2,0]] as [$a,$b]){$keys=[];foreach([$a,$b] as $v)$keys[]=implode(',',array_map(static fn($n)=>sprintf('%.7f',$n),$triangle[$v]));sort($keys);$key=implode('|',$keys);$deckEdges[$key]=($deckEdges[$key]??0)+1;}
}
check($deckDown&&$deckUp&&$deckStart&&$deckEnd,'Bridge shell has outward bottom, top and end-cap normals');
check(count(array_filter($deckEdges,static fn($count)=>$count!==2))===0,'Bridge shell is closed with every edge shared by exactly two faces');
$deckX=[];for($i=0;$i<count($deckValues);$i+=3)$deckX[]=$deckValues[$i];
check(min($deckX)===12.5&&max($deckX)===87.5,'Bridge shell stops exactly at mapped span boundaries');
check(str_contains($deck['collision'],'visual')&&$deck['depth_m']===0.5,'Bridge shell supplies visible thickness without adding driving collision');
$explicitPlan=$largePlan;
$explicitPlan['points']=[[100,800],[200,800],[260,805],[400,800],[1100,800],[1500,800]];
$explicitPlan['sources'][0]['points']=array_slice($explicitPlan['points'],0,4);
$explicitRaster=new SweepLine\BeamRaster($largeN,$largeSide,$largeHeights,str_repeat(chr(100),$largeN*$largeN*3));
$explicitFile=tempnam(sys_get_temp_dir(),'sl-bridge-deck-');
try{
 $explicitReport=SweepLine\BeamMap::build($explicitPlan,$explicitRaster,str_repeat('7',32),$explicitFile,static function($m){});
 $explicitZip=new ZipArchive();$explicitZip->open($explicitFile);$explicitBase='levels/sweepline_7777777777777777/';
 check($explicitReport['curve_smoothing']['explicit_pavement']&&$explicitZip->getFromName($explicitBase.'art/shapes/bridge-deck.dae')!==false,'Explicit-pavement bridge ZIP includes underside mesh');
 $explicitObjects=array_map(static fn($line)=>json_decode($line,true),explode("\n",trim($explicitZip->getFromName($explicitBase.'main/MissionGroup/items.level.json'))));
 $shellObjects=array_values(array_filter($explicitObjects,static fn($o)=>($o['name']??'')==='bridge_deck_shell'));
 check(count($shellObjects)===1&&$shellObjects[0]['collisionType']==='None'&&$shellObjects[0]['castShadows'],'Bridge underside is instantiated with shadows and visual-only geometry');
 check($explicitReport['bridge_deck_shell']['spans']===1&&abs($explicitRaster->height(750,800)-60)<0.01,'Bridge underside report preserves span count and valley terrain');
 $explicitZip->close();
}finally{@unlink($explicitFile);}
