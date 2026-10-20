<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if ($argc!==2 || !is_readable($argv[1])) { exit("Usage: php bin/import-zips.php /path/to/US.txt\n"); }
$count=SweepLine\ZipImporter::run(SweepLine\Database::connect(),$argv[1]);
echo "Imported $count ZIP coordinates\n";
