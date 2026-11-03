<?php
declare(strict_types=1);
// Optional read-only verification against an installed game, not a test dependency.
require dirname(__DIR__)."/src/BeamVegetation.php";
require dirname(__DIR__)."/src/BeamTerrain.php";
$root=$argv[1]??throw new InvalidArgumentException("Pass the BeamNG.drive installation directory.");
$checks=0;
foreach(SweepLine\BeamVegetation::CATALOG as $id=>$asset){
    $zip=new ZipArchive();if($zip->open($root."/content/levels/".$asset["map"].".zip")!==true)throw new RuntimeException("Missing stock level: ".$asset["map"]);
    $mappings=[];
    foreach(array_merge([$asset["shape"]],SweepLine\BeamVegetation::materialLibraries([$asset])) as $path){
        $entry=$zip->locateName(ltrim($path,"/"));if($entry===false)throw new RuntimeException("Missing stock reference: ".$path);$checks++;
        if(str_ends_with($path,".json"))foreach(json_decode($zip->getFromIndex($entry),true,512,JSON_THROW_ON_ERROR) as $material)if(isset($material["mapTo"]))$mappings[strtolower($material["mapTo"])]=true;
    }
    $dae=simplexml_load_string($zip->getFromName(ltrim($asset["shape"],"/")),SimpleXMLElement::class,LIBXML_NONET);
    foreach($dae->xpath("//*[local-name()=\"material\"]") as $material){$name=(string)$material["name"];if(!isset($mappings[strtolower($name)]))throw new RuntimeException("Missing material mapping for ".$id.": ".$name);$checks++;}
    $zip->close();
}
$terrainZip=new ZipArchive();if($terrainZip->open($root."/content/assets/materials/terrain.zip")!==true)throw new RuntimeException("Missing stock terrain archive.");
foreach(SweepLine\BeamTerrain::paths() as $path){$bytes=$terrainZip->getFromName(ltrim($path,"/"));if($bytes===false)throw new RuntimeException("Missing stock texture: ".$path);$dimensions=unpack("Nwidth/Nheight",substr($bytes,16,8));if($dimensions["width"]!==1024||$dimensions["height"]!==1024)throw new RuntimeException("Stock texture dimensions changed: ".$path);$checks++;}

foreach([0,1,2] as $id){
    $layer=SweepLine\BeamTerrain::PALETTE[$id];
    $image=imagecreatefromstring($terrainZip->getFromName(ltrim($layer["prefix"],"/")."_b.png"));$sum=[0,0,0];$count=0;
    for($y=0;$y<1024;$y+=16)for($x=0;$x<1024;$x+=16){$color=imagecolorat($image,$x,$y);$sum[0]+=($color>>16)&255;$sum[1]+=($color>>8)&255;$sum[2]+=$color&255;$count++;}
    unset($image);$result=[];
    foreach($sum as $channel=>$value)$result[]=$layer["color"][$channel]/255+(2*$value/$count/255-1)*0.18;
    if($id<2&& !($result[1]>$result[0]&&$result[1]>$result[2]))throw new RuntimeException("Installed stock detail lost the green base for ".$layer["name"]);
    if($id===2&& !(min($result)>0.35&&$result[0]>$result[2]))throw new RuntimeException("Installed soil detail lost the lighter warm base");
    $checks++;
}

$terrainZip->close();
echo $checks." installed stock asset and shader-color checks passed; no game files modified.\n";
