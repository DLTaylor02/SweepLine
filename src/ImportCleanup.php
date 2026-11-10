<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
use PDOException;

/** Delete inactive import data in short, resumable idle-time transactions. */
final class ImportCleanup
{
    public const BATCHES=[
        ['candidate_segments',2000,'candidate'],
        ['road_scores',2000,'candidate'],
        ['road_candidates',25,'job'],
        ['road_connectivity',2000,'job'],
        ['road_segments',250,'job'],
        ['osm_nodes',5000,'job'],
    ];
    public function __construct(private PDO $db) {}
    public function runOne(): bool
    {
        if($this->db->inTransaction())throw new \LogicException('Import cleanup requires its own transaction.');
        $this->db->beginTransaction();
        try{
            // Local settings also bound sessions left unlimited by normal processing.
            $this->db->exec("SET LOCAL statement_timeout='2s'; SET LOCAL lock_timeout='250ms'");
            $q=$this->db->query("SELECT j.id FROM import_jobs j JOIN regions r ON r.id=j.region_id
                WHERE NOT EXISTS(SELECT 1 FROM regions active WHERE active.active_job_id=j.id)
                  AND ((j.cancel_requested AND j.status NOT IN ('running','complete')
                        AND NOT EXISTS(SELECT 1 FROM data_tasks t WHERE t.import_job_id=j.id))
                    OR (r.slug LIKE 'auto-%' AND j.status IN ('complete','failed') AND j.created_at<now()-interval '7 days'))
                ORDER BY j.created_at,j.id LIMIT 1 FOR UPDATE OF j,r SKIP LOCKED");
            $id=$q->fetchColumn();
            if($id===false){$this->db->commit();return false;}
            foreach(self::BATCHES as [$table,$limit,$kind]){
                // Table names and limits are internal constants, never request input.
                $selection=$kind==='candidate'
                    ?"SELECT child.ctid FROM $table child JOIN road_candidates c ON c.id=child.candidate_id WHERE c.job_id=? LIMIT $limit"
                    :"SELECT child.ctid FROM $table child WHERE child.job_id=? LIMIT $limit";
                $q=$this->db->prepare("WITH batch AS ($selection) DELETE FROM $table target USING batch WHERE target.ctid=batch.ctid");
                $q->execute([(int)$id]);$count=$q->rowCount();
                if($count){
                    WorkerStatus::heartbeat($this->db,"Idle cleanup: import $id, $table ($count rows)");
                    $this->db->commit();echo date('c')." idle import cleanup: import $id, $table, $count rows\n";return true;
                }
            }
            $q=$this->db->prepare('DELETE FROM import_jobs WHERE id=?');$q->execute([(int)$id]);
            WorkerStatus::heartbeat($this->db,"Idle cleanup: removed obsolete import $id");
            $this->db->commit();echo date('c')." idle import cleanup: removed import $id\n";return true;
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if($e instanceof PDOException&&in_array((string)$e->getCode(),['57014','55P03'],true)){
                WorkerStatus::heartbeat($this->db,'Idle cleanup deferred: database busy; queued jobs take priority');
                error_log('SweepLine idle import cleanup deferred (SQLSTATE '.$e->getCode().')');return false;
            }
            throw $e;
        }
    }
}
