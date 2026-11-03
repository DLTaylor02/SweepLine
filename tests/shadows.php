<?php
use SweepLine\BeamShadows;
$greenNeighbors=array_fill(0,24,[105,135,90]);
$dryNeighbors=array_fill(0,24,[170,145,110]);
$midwestShadow=BeamShadows::replacement([25,30,45],$greenNeighbors,0.01,[-85,40],250);
check($midwestShadow!==null&&$midwestShadow[1]>$midwestShadow[0]&&$midwestShadow[1]>$midwestShadow[2],'Dark blue Midwest shadow with lit vegetation receives muted green');
$desertShadow=BeamShadows::replacement([25,30,45],$dryNeighbors,0.1,[-113,35],500);
check($desertShadow!==null&&$desertShadow[0]>$desertShadow[1]&&$desertShadow[1]>$desertShadow[2],'Desert shadow receives dusty local ground color');
$mountainShadow=BeamShadows::replacement([25,30,45],array_fill(0,24,[25,30,45]),0.2,[-119,39],2400);
check($mountainShadow!==null&&$mountainShadow[1]>$mountainShadow[0],'Shaded mountain slope uses a forest regional fallback without lit neighbors');
check(BeamShadows::replacement([15,25,50],array_fill(0,24,[20,35,65]),0.001,[-85,40],250)===null,'Flat consistently blue water-like neighborhoods are preserved');
check(BeamShadows::replacement([45,45,45],array_fill(0,24,[45,45,45]),0.001,[-85,40],250)===null,'Uniform dark flat ground is not invented into a shadow');
foreach([[0,0,0],[240,240,240],[20,60,150],[140,130,100]] as $pixel)
    check(BeamShadows::replacement($pixel,$greenNeighbors,0.2,[-85,40],250)===null,'Missing black, snow, bright blue and normal ground remain unchanged');
check(BeamShadows::regional([-85,40],200)===[99,121,75]&&BeamShadows::regional([-113,35],500)===[162,141,111],'Regional defaults distinguish Midwest vegetation from western lowland dust');
check(SweepLine\BeamTerrain::category(array_fill(0,5,$midwestShadow),0.01,[-85,40],250)===0,'Corrected blue shadow becomes grass rather than aerial water fallback');
$shadowN=16;$shadowPlane=$shadowN*$shadowN;
$shadowColors=str_repeat(chr(105),$shadowPlane).str_repeat(chr(135),$shadowPlane).str_repeat(chr(90),$shadowPlane);
$shadowIndex=(15-8)*16+8;
foreach([25,30,45] as $channel=>$value)$shadowColors[$channel*$shadowPlane+$shadowIndex]=chr($value);
$shadowRaster=new SweepLine\BeamRaster(16,160,str_repeat(pack('g',250),$shadowPlane),$shadowColors);
$shadowProgress=0;$shadowResult=BeamShadows::correct($shadowRaster,[-85,40],static function($m)use(&$shadowProgress){$shadowProgress++;});
check($shadowResult['corrected_pixels']===1&&$shadowResult['raster']->rgb(8,8)[1]>$shadowResult['raster']->rgb(8,8)[2],'Full-resolution correction repairs the actual shadow pixel with correct north-up indexing');
check($shadowResult['raster']->rgb(0,0)===$shadowRaster->rgb(0,0)&&$shadowRaster->colors===$shadowColors,'Shadow correction preserves lit pixels and does not mutate the raw source image');
check($shadowResult['raster']->heights===$shadowRaster->heights&&$shadowProgress>1,'Shadow color repair retains original elevation geometry and cancellation checkpoints');
