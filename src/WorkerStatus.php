<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class WorkerStatus
{
    public static function heartbeat(PDO $db,?string $activity=null): void
    {
        if($activity===null){
            $db->prepare("INSERT INTO app_settings(key,value) VALUES ('worker_heartbeat',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([date('c')]);return;
        }
        $db->prepare("INSERT INTO app_settings(key,value) VALUES ('worker_heartbeat',?),('worker_activity',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([date('c'),$activity]);
    }
}
