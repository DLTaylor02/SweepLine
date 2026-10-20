<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class SearchHistory
{
    public function __construct(private PDO $db) {}
    public function record(int $userId,array $parameters): void
    {
        $this->db->beginTransaction();
        try {
            // Serialize retention and duplicate suppression for this user only.
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$userId]);
            $json=json_encode($parameters,JSON_THROW_ON_ERROR);
            $q=$this->db->prepare("INSERT INTO search_history(user_id,parameters)
                SELECT ?,?::jsonb WHERE NOT EXISTS(SELECT 1 FROM search_history
                WHERE user_id=? AND parameters=?::jsonb AND created_at>now()-interval '10 seconds')");
            $q->execute([$userId,$json,$userId,$json]);
            $this->db->prepare('DELETE FROM search_history WHERE user_id=? AND id NOT IN
                (SELECT id FROM search_history WHERE user_id=? ORDER BY id DESC LIMIT 100)')->execute([$userId,$userId]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
    }
    public function recent(int $userId): array
    {
        $q=$this->db->prepare('SELECT id,parameters,created_at FROM search_history WHERE user_id=? ORDER BY id DESC LIMIT 100');
        $q->execute([$userId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['parameters']=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR); }
        return $rows;
    }
}
