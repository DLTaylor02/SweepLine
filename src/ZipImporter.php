<?php
declare(strict_types=1);
namespace SweepLine;

use PDO;
final class ZipImporter
{
    public static function run(PDO $db,string $path,?callable $checkpoint=null): int
    {
        $f=fopen($path,'r'); if (!$f) { throw new \RuntimeException('Cannot open ZIP coordinates.'); }
        $db->beginTransaction(); $count=0;
        $q=$db->prepare("INSERT INTO zip_codes(zip,place,state,location) VALUES (?,?,?,ST_SetSRID(ST_MakePoint(?,?),4326)::geography)
            ON CONFLICT(zip) DO UPDATE SET place=excluded.place,state=excluded.state,location=excluded.location,updated_at=now()");
        try {
            while (($row=fgetcsv($f,0,"\t",'"',''))!==false) {
                if ($checkpoint && $count%100===0) { $checkpoint(false); }
                if (count($row)<11 || $row[0]!=='US' || !preg_match('/^\d{5}$/D',$row[1]) ||
                    !is_numeric($row[9]) || !is_numeric($row[10]) || abs((float)$row[9])>90 || abs((float)$row[10])>180) {
                    throw new \RuntimeException('Invalid GeoNames row '.($count+1));
                }
                $q->execute([$row[1],$row[2],$row[4],$row[10],$row[9]]); $count++;
            }
            if (!$count) { throw new \RuntimeException('No ZIP coordinates in source.'); }
            $db->prepare("INSERT INTO app_settings(key,value) VALUES ('zip_last_sync',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=now()")->execute([date('c')]);
            if ($checkpoint) { $checkpoint(true); }
            $db->commit(); return $count;
        } catch (\Throwable $e) { $db->rollBack(); throw $e; }
        finally { fclose($f); }
    }
}
