<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$db=SweepLine\Database::connect();
(new SweepLine\DataQueue($db))->ensure('us-zips','zips');
// Remove any retired browser-setup secret left by an earlier version.
$legacyCode=SWEEPLINE_ROOT.'/var/setup-code';
if (is_file($legacyCode)) { unlink($legacyCode); }
echo "Application initialized; automatic ZIP preparation queued.\n";
