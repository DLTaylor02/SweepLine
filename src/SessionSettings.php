<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class SessionSettings
{
    public const DEFAULT_DAYS=7;
    public const MAX_DAYS=30;
    public const MAX_SECONDS=self::MAX_DAYS*86400;
    public function __construct(private PDO $db) {}
    public function read(): array
    {
        $value=$this->db->query("SELECT value FROM app_settings WHERE key='session_timeout_days'")->fetchColumn();
        return ['session_timeout_days'=>$value===false?self::DEFAULT_DAYS:max(1,min(self::MAX_DAYS,(int)$value))];
    }
    public function save(array $input): array
    {
        $days=Validation::number($input,'session_timeout_days',NAN,1,self::MAX_DAYS);
        if (floor($days)!==$days) {throw new \InvalidArgumentException('Session lifetime must be a whole number of days.');}
        $this->db->prepare("INSERT INTO app_settings(key,value) VALUES ('session_timeout_days',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([(string)(int)$days]);
        return ['session_timeout_days'=>(int)$days];
    }
    /** Absolute lifetime: browsing and polling do not silently postpone reauthentication. */
    public static function valid(int $loginAt,int $now,int $days): bool
    {
        return $loginAt>0 && $loginAt<=$now && $now-$loginAt<$days*86400;
    }
}

