<?php
use SweepLine\RegionData;
(function(): void {
    check(RegionData::key('auto-m119500-39000-500')==='auto-m119500-39000-500','Region keys accept planned application identifiers');
    foreach(['../../etc/passwd','region/key','bad key',[],null,str_repeat('a',161)] as $bad)check(rejects(fn()=>RegionData::key($bad)),'Invalid region identifier rejected');
    $shape=RegionData::rectangle([-120,39,-119.5,39.5]);
    check($shape['coordinates'][0][0]===[-120.0,39.0]&&count($shape['coordinates'][0])===5,'Region map uses closed geographic boundary boxes');
    foreach([[0,0,0,1],[-181,0,-180,1],[0,0,1,NAN],['x',0,1,1],null] as $bad)check(RegionData::rectangle($bad)===null,'Malformed regional bounds rejected');
    $outside=tempnam(sys_get_temp_dir(),'sl-region-external-');file_put_contents($outside,'external fixture');
    try{check(RegionData::managedFile($outside)===null,'Region cleanup cannot target external source files');}finally{unlink($outside);}
    $root=SWEEPLINE_ROOT.'/var/downloads';if(!is_dir($root))mkdir($root,0750,true);
    $file=$root.'/roads-'.random_int(100000000,999999999).'.osm';file_put_contents($file,'fixture');
    try{check(RegionData::managedFile($file)===realpath($file),'Only specifically named managed downloads are eligible for cleanup');}finally{unlink($file);}
    $file=$root.'/unrelated-'.bin2hex(random_bytes(4));file_put_contents($file,'keep');
    try{check(RegionData::managedFile($file)===null,'Unrelated application files inside downloads remain protected');}finally{unlink($file);}
})();
