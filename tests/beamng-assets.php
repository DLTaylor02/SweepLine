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
$terrainZip->close();
echo $checks." installed stock asset references verified; no files copied or modified.\n";
