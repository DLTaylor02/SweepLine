<?php
// Offline scenery/material fixtures; no game files or provider access required.
(function(): void {
    $east=[-84,41];$west=[-118,38];
    check(\SweepLine\BeamGrass::asset([80,145,70],$east,300)==='green_grass','Lit vegetation supports stock grass');
    check(\SweepLine\BeamGrass::asset([45,55,40],$east,300)===null&&\SweepLine\BeamGrass::asset([160,145,125],$east,300)===null&&\SweepLine\BeamGrass::asset([60,90,150],$east,300)===null,'Grass rejects dark canopy, bare soil and water colors');
    check(\SweepLine\BeamGrass::asset([70,80,65],$east,300)!==null,'Muted green grass remains eligible without accepting dark canopy');
    check(\SweepLine\BeamGrass::asset([80,145,70],$west,5000)===null,'Grass respects high-alpine exclusion');
    $n=64;$raster=new \SweepLine\BeamRaster($n,256,str_repeat(pack('g',100),$n*$n),str_repeat(chr(80),$n*$n).str_repeat(chr(145),$n*$n).str_repeat(chr(70),$n*$n));
    $road=[[30,128,100],[220,128,100]];$profiles=array_fill(0,2,\SweepLine\BeamLanes::infer(['lanes'=>'2'],'secondary',0));
    $reserved=[['position'=>[120,145,100],'radius'=>12]];$g=new \SweepLine\BeamScenery($road,$profiles);
    $placed=\SweepLine\BeamGrass::place($road,$profiles,$raster,542,static fn($m)=>null,$east,$reserved,[],180);
    $valid=count($placed['objects'])>50&&count($placed['objects'])<=180;
    foreach($placed['objects'] as $o)$valid=$valid&&$g->allows($o['position'][0],$o['position'][1],\SweepLine\BeamScenery::radius('grass',$o['scale'],$o['asset']))&&hypot($o['position'][0]-120,$o['position'][1]-145)>=12;
    check($valid,'Grass stays bounded, off pavement and clear of mapped buildings');
    $files=[];\SweepLine\BeamVegetation::write($placed['objects'],'sl_grass',0,static function($p,$b)use(&$files){$files[$p]=$b;});
    $defs=json_decode($files['art/forest/managedItemData.json'],true);
    check(count($defs)===2&&count(array_filter($defs,static fn($d)=>$d['collidable']!==false))===0&&str_contains($files['mainLevel.lua'],'3d_grass_test/main.materials.json'),'Mixed short/tall grass uses native Forest clumps with materials and disabled collision');
    $neutral=\SweepLine\BeamRockPalette::factor([[140,140,135]]);$warm=\SweepLine\BeamRockPalette::factor([[170,120,85]]);
    check($neutral[0]<1&&$neutral[2]>1&&$warm===[1,1,1],'Utah rock tint neutralizes red on gray ground while preserving warm-ground coloration');
    $lua=\SweepLine\BeamRockPalette::lua($neutral);
    check(str_contains($lua,'onClientEndMission')&&str_contains($lua,'savedRockFactor')&&!str_contains($lua,'saveDirty'),'Rock tint restores the prior in-memory material and never writes game assets');
    $rails=\SweepLine\BeamGuardrails::stockObjects([['points'=>[[10,20,5,4.25],[14,20,5,4.25],[14,24,6,5.25]]]]);
    check(count($rails)===5&&$rails[0]['collisionType']==='Collision Mesh'&&str_contains($rails[0]['shapeName'],'eca_guardrail1.dae'),'Mapped rails use native rail and post assets with collision meshes');
    $rot=$rails[1]['rotationMatrix'];$dot=$rot[0]*$rot[6]+$rot[1]*$rot[7]+$rot[2]*$rot[8];
    check(abs($dot)<1e-9&&$rot[2]>0,'Stock rail orientation follows graded runs with orthogonal axes');
    $m=\SweepLine\BeamRoadMaterials::material('sl_road','/levels/test/art/roads/paint.color.png',$profiles[0]);$s=$m['Stages'][0];$paint=$m['Stages'][1];
    check($m['version']===1.5&&$m['groundType']==='ASPHALT'&&str_contains($s['baseColorMap'],'AsphaltRoad_variation_01')&&$paint['baseColorMap']==='/levels/test/art/roads/paint.color.png','Lightly weathered stock asphalt is the base and lane paint occupies a separate masked PBR layer');
    check(!isset($s['opacityMap'])&&!isset($m['translucent'])&&$paint['opacityMap']==='/levels/test/art/roads/paint.paint.data.png','Road remains opaque while only its lane-paint layer uses a mask');
    $mask=imagecreatefromstring(\SweepLine\BeamPng::paintMask($profiles[0]));
    check((imagecolorat($mask,64,64)&255)===0&&(imagecolorat($mask,128,64)&255)===255,'Paint mask exposes stock pavement while retaining the existing center line');
    imagedestroy($mask);
    $gray=new \SweepLine\BeamRaster($n,256,str_repeat(pack('g',100),$n*$n),str_repeat(chr(150),3*$n*$n));
    $empty=\SweepLine\BeamGrass::place($road,$profiles,$gray,542,static fn($m)=>null,$east,[],[],80);
    check($empty['objects']===[],'Clustered grass placement cannot seed itself on bare gray ground');
})();
