<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class StorageSettings
{
    public function __construct(private PDO $db) {}
    public function read(): array
    {
        $value=$this->db->query("SELECT value FROM app_settings WHERE key='database_budget_mb'")->fetchColumn();
        return ['database_budget_mb'=>$value===false?(int)(getenv('DATA_DATABASE_BUDGET_MB')?:5120):(int)$value];
    }
    public function save(array $input): array
    {
        $budget=Validation::number($input,'database_budget_mb',NAN,512,1048576);
        if (floor($budget)!==$budget) {throw new \InvalidArgumentException('Database budget must be a whole number of MiB');}
        $this->db->beginTransaction();
        try {
            $this->db->exec('SELECT pg_advisory_xact_lock(73019424)');
            $previous=$this->read()['database_budget_mb'];
            $this->db->prepare("INSERT INTO app_settings(key,value) VALUES ('database_budget_mb',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([(string)(int)$budget]);
            $resumed=0;
            if ($budget>$previous) {
                $q=$this->db->prepare("UPDATE data_tasks SET status='queued',attempts=0,error=null,progress='Storage budget increased; preparation queued',next_attempt_at=now(),updated_at=now() WHERE status IN ('retry','failed') AND cancel_requested=false AND error LIKE 'Application data storage limit reached.%'");
                $q->execute();$resumed=$q->rowCount();
            }
            $this->db->commit();
            return ['database_budget_mb'=>(int)$budget,'resumed_jobs'=>$resumed];
        } catch (\Throwable $e) {if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}

