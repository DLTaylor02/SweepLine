<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class GeometryRefresh
{
    /** Recalculate local active tracks once per geometry version, with bounded batches. */
    public static function run(PDO $db): void
    {
        $last=0;$count=0;
        while (true) {
            $q=$db->prepare("SELECT c.id,c.metrics,ST_AsGeoJSON(c.geom::geometry,9) AS geometry
                FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id
                WHERE c.id>? AND COALESCE(c.metrics->>'geometry_version','0')<>'2' ORDER BY c.id LIMIT 100");
            $q->execute([$last]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {break;}
            $db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $last=(int)$row['id'];$m=json_decode($row['metrics'],true,512,JSON_THROW_ON_ERROR);
                    $m=array_replace($m,Geometry::metrics(json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates']));
                    $db->prepare('UPDATE road_candidates SET length_m=?,metrics=?::jsonb WHERE id=?')->execute([$m['length_m'],json_encode($m,JSON_THROW_ON_ERROR),$last]);
                    foreach (Scorer::profiles() as $profile=>$_) {
                        $s=Scorer::score($m,$profile);
                        $db->prepare('UPDATE road_scores SET score=?,confidence=?,dimensions=?::jsonb WHERE candidate_id=? AND profile=?')
                            ->execute([$s['score'],$s['confidence'],json_encode($s['dimensions'],JSON_THROW_ON_ERROR),$last,$profile]);
                    }
                    $count++;
                }
                $db->commit();echo "Updated geometry metrics for $count roads\n";
            } catch (\Throwable $e) {$db->rollBack();throw $e;}
        }
    }
}
