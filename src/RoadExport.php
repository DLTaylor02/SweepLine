<?php
declare(strict_types=1);
namespace SweepLine;
use ZipArchive;

final class RoadExport
{
    private static function xml(string $text): string
    {
        return htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8');
    }
    private static function coordinate(array $point): string
    {
        return sprintf('%.6f, %.6f',$point[1],$point[0]);
    }
    /** Orient a one-way chain legally, and use the same ordering in all formats. */
    public static function prepare(array $road): array
    {
        $parts=$road['parts']??[];$positive=0;$negative=0;
        foreach ($parts as $part) {
            $d=(int)$part['direction']*($part['reversed']?-1:1);
            if ($d>0) {$positive++;} elseif ($d<0) {$negative++;}
        }
        if ($positive && $negative) { throw new \InvalidArgumentException('Road has inconsistent mapped travel directions and cannot be exported as a continuous drive.'); }
        if ($negative) {
            $road['geometry']['coordinates']=array_reverse($road['geometry']['coordinates']);
            $parts=array_reverse($parts);
            foreach ($parts as &$part) {$part['reversed']=!$part['reversed'];}
            unset($part);
        }
        $points=$road['geometry']['coordinates'];
        if (count($points)<2) {throw new \InvalidArgumentException('Road geometry is empty.');}
        // Never sum part geometries: several pieces can refer to one full OSM way.
        $analysis=RoadNotes::analyze($points);
        $road['length_m']=$analysis['length_m'];
        $road['notes']=$analysis['notes'];
        $road['instructions']=RoadNotes::instructions($points,$analysis);
        return $road;
    }
    public static function gpx(array $road): string
    {
        $name=self::xml($road['name']);$p=$road['geometry']['coordinates'];
        $out='<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<gpx xmlns="http://www.topografix.com/GPX/1/1" version="1.1" creator="SweepLine">'.
            '<metadata><name>'.$name.'</name><desc>Selected road track. Road data © OpenStreetMap contributors. Verify access and conditions.</desc></metadata>';
        foreach ([['Start',$p[0]],['End',$p[count($p)-1]]] as [$label,$point]) {
            $out.=sprintf('<wpt lat="%.9f" lon="%.9f"><name>%s</name></wpt>',$point[1],$point[0],$label);
        }
        $out.='<trk><name>'.$name.'</name><desc>'.self::xml("Approximate road-shape notes. Mileage from start; (+mi) since previous note.\n".implode("\n",$road['instructions'])).'</desc><trkseg>';
        foreach ($p as $point) {$out.=sprintf('<trkpt lat="%.9f" lon="%.9f"/>',$point[1],$point[0]);}
        return $out.'</trkseg></trk></gpx>';
    }
    public static function kml(array $road): string
    {
        $name=self::xml($road['name']);$coordinates=[];
        foreach ($road['geometry']['coordinates'] as $p) {$coordinates[]=sprintf('%.9f,%.9f,0',$p[0],$p[1]);}
        $out='<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><name>'.$name.'</name>'.
            '<description>Road data © OpenStreetMap contributors. Verify access and conditions.</description>'.
            '<Placemark><name>'.$name.'</name><description>'.self::xml("Approximate road-shape notes. Mileage from start; (+mi) since previous note.\n".implode("\n",$road['instructions'])).'</description>'.
            '<Style><LineStyle><color>ffa66325</color><width>4</width></LineStyle></Style>'.
            '<LineString><tessellate>1</tessellate><altitudeMode>clampToGround</altitudeMode><coordinates>'.
            implode(' ',$coordinates).'</coordinates></LineString></Placemark>';
        $points=$road['geometry']['coordinates'];
        foreach ([['Start',$points[0]],['End',$points[count($points)-1]]] as [$label,$p]) {
            $out.='<Placemark><name>'.$label.'</name><Point><coordinates>'.sprintf('%.9f,%.9f,0',$p[0],$p[1]).'</coordinates></Point></Placemark>';
        }
        return $out.'</Document></kml>';
    }
    public static function kmz(array $road): string
    {
        $path=tempnam(sys_get_temp_dir(),'sweepline-export-');
        if ($path===false) {throw new \RuntimeException('Cannot create export.');}
        try {
            $zip=new ZipArchive();
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) {throw new \RuntimeException('Cannot create KMZ.');}
            try {if (!$zip->addFromString('doc.kml',self::kml($road))) {throw new \RuntimeException('Cannot write KML.');}}
            finally {$zip->close();}
            $bytes=file_get_contents($path);
            if ($bytes===false) {throw new \RuntimeException('Cannot read KMZ.');}
            return $bytes;
        } finally {unlink($path);}
    }
    public static function pdf(array $road): string
    {
        return RoadPdf::render($road);
    }
    public static function download(array $road,string $format): never
    {
        if (!in_array($format,['gpx','kmz','pdf'],true)) {throw new \InvalidArgumentException('Unknown export format');}
        $road=self::prepare($road);
        $body=match($format) {'gpx'=>self::gpx($road),'kmz'=>self::kmz($road),'pdf'=>self::pdf($road)};
        $mime=match($format) {'gpx'=>'application/gpx+xml','kmz'=>'application/vnd.google-earth.kmz','pdf'=>'application/pdf'};
        header('Content-Type: '.$mime);
        header('Content-Disposition: attachment; filename="sweepline-road-'.(int)$road['id'].'.'.$format.'"');
        header('Content-Length: '.strlen($body));echo $body;exit;
    }
}
