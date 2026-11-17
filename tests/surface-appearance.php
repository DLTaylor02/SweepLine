<?php
(function():void{
    $material=\SweepLine\BeamRoadMaterials::material('test','/levels/test/art/roads/paint.color.png',[]);
    $base=$material['Stages'][0];$paint=\SweepLine\BeamRoadMaterials::paintMaterial('paint','/levels/test/art/roads/paint.color.png');
    check($material['activeLayers']===1&&$paint['alphaTest']&&$paint['Stages'][0]['diffuseMap']==='/levels/test/art/roads/paint.color.png','Asphalt and single-sample alpha paint use independent surfaces');
    check(!isset($base['detailMap'])&&!isset($base['detailBaseColorMapStrength'])&&!isset($paint['detailMap']),'Road material excludes the detail-map shader feature added in v0.152.0');
    check($material['groundType']==='ASPHALT'&&isset($base['normalMap'],$base['roughnessMap']),'Restored material retains stock asphalt physics and PBR surface detail');
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
