<?php
declare(strict_types=1);
// Optional read-only verification against an installed game, not a test dependency.
require dirname(__DIR__)."/src/BeamVegetation.php";
$root=$argv[1]??throw new InvalidArgumentException("Pass the BeamNG.drive installation directory.");
$checks=0;
foreach(SweepLine\BeamVegetation::CATALOG as $id=>$asset){
    $zip=new ZipArchive();if($zip->open($root."/content/levels/".$asset["map"].".zip")!==true)throw new RuntimeException("Missing stock level: ".$asset["map"]);
    foreach(["shape","materials"] as $kind){if($zip->locateName(ltrim($asset[$kind],"/"))===false)throw new RuntimeException("Missing stock reference: ".$asset[$kind]);$checks++;}
    $zip->close();
}
echo $checks." installed stock asset references verified; no files copied or modified.\n";
