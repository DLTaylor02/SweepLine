<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if (PHP_SAPI!=='cli') { exit(2); }
try {
    $db=SweepLine\Database::connect();
    $mode=$argv[1]??'';
    if ($mode==='--exists') { exit(SweepLine\SuperAdmin::exists($db)?0:1); }
    if (!in_array($mode,['--create','--reset'],true)) { fwrite(STDERR,"Usage: php bin/superadmin.php --exists|--create|--reset (password via stdin)\n"); exit(2); }
    if ($mode==='--create' && SweepLine\SuperAdmin::exists($db)) { echo "Existing SuperAdmin preserved.\n"; exit(0); }
    // A byte beyond the accepted limit detects overlong input; passwords never enter argv or .env.
    $password=stream_get_contents(STDIN,73);
    if ($mode==='--reset') {
        $username=SweepLine\SuperAdmin::reset($db,$password); unset($password);
        echo "SuperAdmin password reset. Username: $username. Existing sessions invalidated.\n";
        exit(0);
    }
    $created=SweepLine\SuperAdmin::ensure($db,$password);
    unset($password);
    echo $created?"SuperAdmin created. Username: superadmin\n":"Existing SuperAdmin preserved.\n";
} catch (InvalidArgumentException $e) {
    fwrite(STDERR,$e->getMessage()."\n"); exit(1);
} catch (Throwable $e) {
    error_log((string)$e); fwrite(STDERR,"SuperAdmin provisioning failed; inspect the error above.\n"); exit(2);
}
