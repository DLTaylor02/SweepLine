<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
/** Opportunistic work in the search service, independent of region download success. */
final class FavoriteThumbnails {
    public function __construct(private PDO $db){}
    public function runOne(): bool {
        $row=$this->db->query("SELECT f.id,f.user_id FROM favorites f LEFT JOIN favorite_thumbnails t ON t.favorite_id=f.id WHERE t.svg IS NULL AND (t.next_attempt_at IS NULL OR t.next_attempt_at<=now()) ORDER BY f.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$row)return false;
        try{
            $this->db->exec("SET statement_timeout='8s'; SET lock_timeout='1s'");
            $road=(new Favorites($this->db))->detail((int)$row['user_id'],(int)$row['id'])['road'];
            $road['basemap']=ExportMap::load($this->db,$road);
            if(!$road['basemap']['roads'])throw new \RuntimeException('Local background roads are not available yet.');
            $svg=FavoriteThumbnail::render($road);
            $this->db->prepare("INSERT INTO favorite_thumbnails(favorite_id,svg) SELECT id,? FROM favorites WHERE id=? ON CONFLICT(favorite_id) DO UPDATE SET svg=excluded.svg,error=null,updated_at=now()")->execute([$svg,$row['id']]);
        }catch(\Throwable $e){
            error_log('SweepLine favorite thumbnail '.$row['id'].': '.get_class($e).' code='.$e->getCode());
            $this->db->prepare("INSERT INTO favorite_thumbnails(favorite_id,next_attempt_at,error) SELECT id,now()+interval '5 minutes','Thumbnail background unavailable; retry scheduled' FROM favorites WHERE id=? ON CONFLICT(favorite_id) DO UPDATE SET next_attempt_at=excluded.next_attempt_at,error=excluded.error,updated_at=now()")->execute([$row['id']]);
        }finally{$this->db->exec("SET statement_timeout='15s'; SET lock_timeout='5s'");}
        return true;
    }
}
