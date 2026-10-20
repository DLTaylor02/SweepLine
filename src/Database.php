<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        $db = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'sweepline'), getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $db->exec("SET statement_timeout = '15s'; SET lock_timeout = '5s'");
        return $db;
    }

    public static function migrate(PDO $db): void
    {
        $db->exec('SELECT pg_advisory_lock(73019421)');
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
            foreach (glob(SWEEPLINE_ROOT . '/database/migrations/*.sql') as $file) {
                $version = basename($file);
                $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
                $check->execute([$version]);
                if ($check->fetchColumn()) { continue; }
                $db->beginTransaction();
                try {
                    $db->exec(file_get_contents($file));
                    $db->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([$version]);
                    $db->commit();
                    echo "Applied $version\n";
                } catch (\Throwable $e) { $db->rollBack(); throw $e; }
            }
        } finally { $db->exec('SELECT pg_advisory_unlock(73019421)'); }
    }
}
