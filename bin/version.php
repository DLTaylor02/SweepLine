<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
if($argc!==1){fwrite(STDERR,"Usage: php bin/version.php\nEdit VERSION in the source checkout to set a release version.\n");exit(2);}
require dirname(__DIR__).'/src/bootstrap.php';
echo SweepLine\Version::number()."\n";
