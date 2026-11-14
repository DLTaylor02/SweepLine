<?php
(function():void{
    $material=\SweepLine\BeamRoadMaterials::material('test','/levels/test/art/roads/paint.color.png',[]);
    $base=$material['Stages'][0];$paint=$material['Stages'][1];
    check($material['activeLayers']===2&&!isset($paint['detailMap'])&&$paint['opacityMap']==='/levels/test/art/roads/paint.paint.data.png','Asphalt variation does not modulate or mask lane paint');
    check($base['detailMap']==='/levels/test/art/roads/variation.color.png'&&$base['detailMapUseUV']===0&&abs($base['detailScale'][1]*733-48)<1e-9,'Road variation uses an independent long period on primary pavement UVs');
    $png=\SweepLine\BeamRoadMaterials::variation();$image=imagecreatefromstring($png);$seam=true;$gradient=true;$colors=[];
    for($x=0;$x<128;$x++)$seam=$seam&&imagecolorat($image,$x,0)===imagecolorat($image,$x,511);
    for($y=0;$y<512;$y++){
        $seam=$seam&&imagecolorat($image,0,$y)===imagecolorat($image,127,$y);
        if($y)$gradient=$gradient&&abs((imagecolorat($image,64,$y)&255)-(imagecolorat($image,64,$y-1)&255))<=3;
    }
    for($i=0;$i<15;$i++)$colors[imagecolorat($image,64,(int)round($i*48/733*511))]=true;
    check($seam&&$gradient,'Macro asphalt variation wraps seamlessly without sharp tonal steps');
    check(count($colors)>6,'Successive stock asphalt repeats receive different broad color shading');
    check($png===\SweepLine\BeamRoadMaterials::variation(),'Road variation is reproducible without random generation or provider calls');
    imagedestroy($image);
    $uniform=[110,115,105];
    check(\SweepLine\BeamTerrain::shadeSample($uniform,$uniform)===$uniform,'Uniform aerial ground colors remain unchanged');
    $shadow=\SweepLine\BeamTerrain::shadeSample([30,35,30],[100,120,95]);
    check(array_sum($shadow)>95&&array_sum($shadow)<315&&$shadow[1]>$shadow[0],'Photographed shadows recover bounded neighborhood brightness and ground hue');
    $pixels='';for($y=0;$y<64;$y++)for($x=0;$x<64;$x++)$pixels.=($x>=31&&$x<=33&&$y>=30&&$y<=32)?chr(30).chr(35).chr(30):chr(100).chr(120).chr(95);
    $raster=new \SweepLine\BeamRaster(64,128,str_repeat(pack('g',0),4096),$pixels);
    $row=\SweepLine\BeamTerrain::tintRows($raster,512,513,static function($m){});
    check(ord($row[512*3])+ord($row[512*3+1])+ord($row[512*3+2])>95,'Generated tint field uses broad neighborhood recovery inside a local dark patch');
    $bright=\SweepLine\BeamTerrain::shadeSample([180,170,160],[120,115,110]);
    check($bright[0]-$bright[1]===10&&$bright[1]-$bright[2]===10,'Ordinary ground hue survives broad illumination blending');
})();
