<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$db=SweepLine\Database::connect();
(new SweepLine\DataQueue($db))->ensure('us-zips','zips');
if (!$db->query('SELECT EXISTS(SELECT 1 FROM app_users)')->fetchColumn()) {
    $path=SWEEPLINE_ROOT.'/var/setup-code';
    if (!is_dir(dirname($path))) { mkdir(dirname($path),0750,true); }
    $token=is_file($path)?trim(file_get_contents($path)):bin2hex(random_bytes(24));
    file_put_contents($path,$token."\n",LOCK_EX); chmod($path,0640);
    $db->prepare("INSERT INTO app_settings(key,value) VALUES ('setup_token_hash',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([hash('sha256',$token)]);
}
echo "Application initialized; automatic ZIP preparation queued.\n";
