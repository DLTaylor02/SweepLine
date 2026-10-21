<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class Favorites
{
    public function __construct(private PDO $db) {}
    public function find(int $user,string $key,string $profile): ?int
    {
        $q=$this->db->prepare('SELECT id FROM favorites WHERE user_id=? AND geometry_key=? AND profile=?');
        $q->execute([$user,$key,$profile]);$id=$q->fetchColumn();return $id===false?null:(int)$id;
    }
    public function save(int $user,array $input): array
    {
        $id=Validation::number($input,'road_id',NAN,1,9007199254740991);
        if (floor($id)!==$id || !is_array($input['search']??null)) {throw new \InvalidArgumentException('A road ID and its search parameters are required');}
        $search=Validation::search($input['search']);$profile=$input['profile']??$search['profile'];
        if (!is_string($profile) || $profile!==$search['profile']) {throw new \InvalidArgumentException('Profile must match the search');}
        // Only server-owned road data is saved; clients cannot inject geometry or scores.
        $road=(new Api($this->db))->exportRoad((int)$id,$profile,true);$key=$road['geometry_key'];
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT id FROM app_users WHERE id=? FOR UPDATE')->execute([$user]);
            $existing=$this->find($user,$key,$profile);
            $count=$this->db->prepare('SELECT count(*) FROM favorites WHERE user_id=?');$count->execute([$user]);
            if (!$existing && (int)$count->fetchColumn()>=200) {throw new HttpException(409,'Favorites limit reached (200). Remove a favorite before saving another road.');}
            $q=$this->db->prepare('INSERT INTO favorites(user_id,geometry_key,profile,snapshot,search_parameters) VALUES (?,?,?,?::jsonb,?::jsonb)
                ON CONFLICT(user_id,geometry_key,profile) DO UPDATE SET snapshot=excluded.snapshot,search_parameters=excluded.search_parameters RETURNING id');
            $q->execute([$user,$key,$profile,json_encode($road,JSON_THROW_ON_ERROR),json_encode($search,JSON_THROW_ON_ERROR)]);
            $id=(int)$q->fetchColumn();$this->db->commit();return ['id'=>$id,'saved'=>true];
        } catch (\Throwable $e) {$this->db->rollBack();throw $e;}
    }
    public function recent(int $user): array
    {
        $q=$this->db->prepare("SELECT id,profile,created_at,search_parameters,snapshot-'geometry'-'parts'-'basemap' AS road,
            ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_GeomFromGeoJSON((snapshot->'geometry')::text),0.0001),6) AS geometry
            FROM favorites WHERE user_id=? ORDER BY id DESC LIMIT 200");
        $q->execute([$user]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['search_parameters','road','geometry'] as $key) {$row[$key]=json_decode($row[$key],true,512,JSON_THROW_ON_ERROR);}
            $row['road']['geometry']=$row['geometry'];unset($row['geometry']);
        }
        return $rows;
    }
    public function detail(int $user,int $id): array
    {
        $q=$this->db->prepare('SELECT id,profile,created_at,snapshot AS road,search_parameters FROM favorites WHERE id=? AND user_id=?');
        $q->execute([$id,$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {throw new \OutOfBoundsException('Favorite not found');}
        foreach (['road','search_parameters'] as $key) {$row[$key]=json_decode($row[$key],true,512,JSON_THROW_ON_ERROR);}
        $row['road']['favorite_id']=(int)$row['id'];return $row;
    }
    public function delete(int $user,int $id): array
    {
        $q=$this->db->prepare('DELETE FROM favorites WHERE id=? AND user_id=?');$q->execute([$id,$user]);
        if (!$q->rowCount()) {throw new \OutOfBoundsException('Favorite not found');}
        return ['deleted'=>true];
    }
}
