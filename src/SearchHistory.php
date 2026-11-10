<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class SearchHistory
{
    public function __construct(private PDO $db) {}
    public function record(int $userId,array $parameters): int
    {
        $parameters=Validation::search($parameters);
        $this->db->beginTransaction();
        try {
            // Serialize retention and duplicate suppression for this user only.
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$userId]);
            $json=json_encode($parameters,JSON_THROW_ON_ERROR);
            $q=$this->db->prepare("INSERT INTO search_history(user_id,parameters)
                SELECT ?,?::jsonb WHERE NOT EXISTS(SELECT 1 FROM search_history
                WHERE user_id=? AND parameters=?::jsonb) RETURNING id");
            $q->execute([$userId,$json,$userId,$json]);$id=$q->fetchColumn();
            if($id===false){$existing=$this->db->prepare("SELECT id FROM search_history WHERE user_id=? AND parameters=?::jsonb ORDER BY id DESC LIMIT 1");$existing->execute([$userId,$json]);$id=$existing->fetchColumn();}
            $this->db->prepare('DELETE FROM search_history WHERE user_id=? AND id NOT IN
                (SELECT id FROM search_history WHERE user_id=? ORDER BY id DESC LIMIT 100)')->execute([$userId,$userId]);
            $this->db->commit();return (int)$id;
        } catch (\Throwable $e) { $this->db->rollBack();throw $e; }
    }
    public function capture(int $user,int $id,array $parameters,array $result): void
    {
        $snapshot=json_encode($result,JSON_THROW_ON_ERROR);
        if(strlen($snapshot)>16777216)throw new HttpException(422,'Search results exceed the history snapshot limit.');
        $q=$this->db->prepare('UPDATE search_history SET result_snapshot=?::jsonb,snapshot_at=now() WHERE id=? AND user_id=? AND parameters=?::jsonb');
        $q->execute([$snapshot,$id,$user,json_encode($parameters,JSON_THROW_ON_ERROR)]);
        if(!$q->rowCount())throw new \OutOfBoundsException('Search history entry not found for these parameters.');
    }
    public function detail(int $user,int $id): array
    {
        $q=$this->db->prepare('SELECT id,parameters,created_at,snapshot_at,result_snapshot FROM search_history WHERE id=? AND user_id=?');
        $q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new \OutOfBoundsException('Search history entry not found.');
        $row['parameters']=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR);
        $row['result_snapshot']=$row['result_snapshot']===null?null:json_decode($row['result_snapshot'],true,512,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function delete(int $userId,int $id): array
    {
        $q=$this->db->prepare('DELETE FROM search_history WHERE id=? AND user_id=?');
        $q->execute([$id,$userId]);
        if (!$q->rowCount()) { throw new \OutOfBoundsException('Search not found'); }
        return ['deleted'=>true];
    }
    public function recent(int $userId): array
    {
        $q=$this->db->prepare("SELECT h.id,h.parameters,h.created_at,(h.result_snapshot IS NOT NULL) AS has_snapshot,z.place AS city,z.state
            FROM search_history h LEFT JOIN zip_codes z ON z.zip=h.parameters->>'zip'
            WHERE h.user_id=? ORDER BY h.id DESC LIMIT 100");
        $q->execute([$userId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['parameters']=json_decode($row['parameters'],true,512,JSON_THROW_ON_ERROR); }
        return $rows;
    }
}
