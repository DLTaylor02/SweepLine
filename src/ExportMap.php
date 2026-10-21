<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** A local OSM street basemap, projected consistently with the exported track. */
final class ExportMap
{
    public static function project(array $p): array
    {
        $lat=max(-85.0,min(85.0,(float)$p[1]));
        return [6378137*deg2rad($p[0]),6378137*log(tan(M_PI/4+deg2rad($lat)/2))];
    }
    public static function bounds(array $points): array
    {
        $xs=[];$ys=[];
        foreach ($points as $p) {[$x,$y]=self::project($p);$xs[]=$x;$ys[]=$y;}
        $cx=(min($xs)+max($xs))/2;$cy=(min($ys)+max($ys))/2;
        $width=max(1200,(max($xs)-min($xs))*1.25);$height=max(1200,(max($ys)-min($ys))*1.25);
        $ratio=532/280;
        if ($width/$height<$ratio) {$width=$height*$ratio;} else {$height=$width/$ratio;}
        return [$cx-$width/2,$cy-$height/2,$cx+$width/2,$cy+$height/2];
    }
    public static function load(PDO $db,array $road): array
    {
        $bounds=self::bounds($road['geometry']['coordinates']);
        $q=$db->prepare("SELECT s.name,s.highway,ST_AsGeoJSON(ST_SimplifyPreserveTopology(s.geom,0.00008),6) AS geometry
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id
            WHERE ST_Intersects(s.geom,ST_Transform(ST_MakeEnvelope(?,?,?,?,3857),4326))
            ORDER BY CASE s.highway WHEN 'motorway' THEN 0 WHEN 'trunk' THEN 1 WHEN 'primary' THEN 2
                WHEN 'secondary' THEN 3 WHEN 'tertiary' THEN 4 ELSE 5 END,s.id LIMIT 1201");
        $q->execute($bounds);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$truncated=count($rows)>1200;$rows=array_slice($rows,0,1200);
        foreach ($rows as &$row) {$row['geometry']=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR);}
        return ['roads'=>array_reverse($rows),'truncated'=>$truncated,'bounds'=>$bounds];
    }
}
