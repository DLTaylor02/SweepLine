#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
try{
    $zip=new ZipArchive();if($argc!==2||$zip->open($argv[1])!==true)throw new RuntimeException('Usage: php bin/replay-terrain-diagnostic.php diagnostic.zip');
    $stat=$zip->statName('diagnostic.json');if(!$stat||$stat['size']>8388608)throw new RuntimeException('Missing or oversized diagnostic.');
    $json=$zip->getFromName('diagnostic.json');$zip->close();
    $hit=SweepLine\BeamTerrainDiagnostic::replay(json_decode($json,true,512,JSON_THROW_ON_ERROR));
    echo json_encode(['reproduced'=>$hit!==null,'contact'=>$hit],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    exit($hit?0:2);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
