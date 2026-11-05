<?php
// Generated planar fixtures exercise geometry without any real road or locality.
foreach([0, M_PI/3, M_PI] as $angle){
$rotate=static fn($p)=>[160+($p[0]-160)*cos($angle)-($p[1]-160)*sin($angle),160+($p[0]-160)*sin($angle)+($p[1]-160)*cos($angle),$p[2]];
$f=['size'=>64,'side'=>320.,'road'=>array_map($rotate,[[40,160,8.5],[100,160,8.7],[140,170,9.],[180,190,9.2],[220,210,9.4],[280,210,9.5]]),
    'profiles'=>array_map(static fn($w)=>['width_m'=>$w],[8,9,10,9,8,8]),
    'span'=>['start_m'=>80.,'end_m'=>160.,'clearance_m'=>4.8],'pavement_triangles'=>[]];
$sections=[];foreach($f['road'] as $i=>$_)$sections[]=SweepLine\BeamPavement::section($f['road'],$f['profiles'],$i);
foreach($sections as $i=>$pair)if($i){$a=$sections[$i-1][0];$b=$sections[$i-1][1];$c=$pair[1];$d=$pair[0];$f['pavement_triangles'][]=[$a,$b,$c];$f['pavement_triangles'][]=[$a,$c,$d];}
$heights='';for($y=63;$y>=0;$y--)for($x=0;$x<64;$x++){
 $sourceX=160+($x*5-160)*cos($angle)+($y*5-160)*sin($angle);
 $height=$sourceX>=100&&$sourceX<=210?10+max(0,12-abs($sourceX-160)/4):0.;
 $heights.=pack('g',$height);
}
$terrain=new SweepLine\BeamRaster($f['size'],$f['side'],$heights,str_repeat(chr(90),$f['size']*$f['size']*3));
$originalHeights=$terrain->heights;
$mesh=SweepLine\BeamTunnels::build($f['road'],$f['profiles'],[$f['span']],$terrain,0,str_repeat(chr(0),$f['size']*$f['size']),'test_tunnel','test_terrain',static function($m){});
$xml=simplexml_load_string($mesh['terrain']);$xml->registerXPathNamespace('c','http://www.collada.org/2005/11/COLLADASchema');
$values=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//c:source[@id="positions"]/c:float_array')[0])));
$grid=[];
foreach($f['pavement_triangles'] as $id=>$t){
 for($y=(int)floor(min(array_column($t,1))/8);$y<=(int)floor(max(array_column($t,1))/8);$y++)
 for($x=(int)floor(min(array_column($t,0))/8);$x<=(int)floor(max(array_column($t,0))/8);$x++)$grid[$x.','.$y][]=$id;
}
$roadHeight=static function(float $x,float $y)use($grid,$f):?float{
 foreach($grid[(int)floor($x/8).','.(int)floor($y/8)]??[] as $id){
  [$a,$b,$c]=$f['pavement_triangles'][$id];$d=($b[1]-$c[1])*($a[0]-$c[0])+($c[0]-$b[0])*($a[1]-$c[1]);if(abs($d)<1e-10)continue;
  $u=(($b[1]-$c[1])*($x-$c[0])+($c[0]-$b[0])*($y-$c[1]))/$d;
  $v=(($c[1]-$a[1])*($x-$c[0])+($a[0]-$c[0])*($y-$c[1]))/$d;$w=1-$u-$v;
  if(min($u,$v,$w)>=-1e-8)return $u*$a[2]+$v*$b[2]+$w*$c[2];
 }return null;
};
$intruding=0;$checked=0;
for($i=0;$i<count($values);$i+=9){
 $a=array_slice($values,$i,3);$b=array_slice($values,$i+3,3);$c=array_slice($values,$i+6,3);
 foreach([[1,0,0],[0,1,0],[0,0,1],[1/3,1/3,1/3],[0.5,0.5,0],[0,0.5,0.5],[0.5,0,0.5]] as [$u,$v,$w]){
  $p=[$a[0]*$u+$b[0]*$v+$c[0]*$w,$a[1]*$u+$b[1]*$v+$c[1]*$w,$a[2]*$u+$b[2]*$v+$c[2]*$w];
  $z=$roadHeight($p[0],$p[1]);if($z===null)continue;$checked++;
  if($p[2]>$z+0.005&&$p[2]<$z+4.5){$intruding++;break;}
 }
}
check($checked>100,'Generated fixture checks ground and wall faces against actual pavement triangles');
check($intruding===0,'Generated terrain has no sampled roadway intrusion, including bend wedges and pavement edges');
check($mesh['report']['excavation_wall_triangles']>0&&$mesh['report']['excavated_floor_pieces']>0,'Generic tunnel repair retains excavation floors and exterior joins');
check($terrain->heights===$originalHeights,'Bend clearance repair leaves native hillside elevation unchanged');

}
