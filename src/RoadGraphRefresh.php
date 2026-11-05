<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class RoadGraphRefresh
{
    /** Installer/CLI only: preserve active data until each replacement validates. */
    public static function run(PDO $db): void
    {
        $q=$db->prepare('SELECT j.id FROM regions r JOIN import_jobs j ON j.id=r.active_job_id WHERE j.status=\'complete\' AND j.analysis_version<? ORDER BY j.id');
        $q->execute([RoadGraph::VERSION]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
        foreach($ids as $id)(new Importer($db))->rebuild((int)$id);
    }
}
