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
    private static function coordinate(array $p): string
    {
        return sprintf('%.6f, %.6f',$p[1],$p[0]);
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
        if (!$parts) {$parts=[['name'=>$road['name'],'ref'=>$road['ref']??null,'reversed'=>false,'geometry'=>$road['geometry']]];}
        $instructions=['Start on '.$road['name'].' at '.self::coordinate($points[0]).'.'];
        $distance=0;
        foreach ($parts as $part) {
            $p=$part['geometry']['coordinates'];if ($part['reversed']) {$p=array_reverse($p);}
            if (count($p)<2) {continue;}
            $length=Geometry::length($p);$distance+=$length;
            $heading=fmod(Geometry::bearing($p[0],$p[1])+360,360);
            $direction=['north','northeast','east','southeast','south','southwest','west','northwest'][(int)round($heading/45)%8];
            $name=$part['name']?:($part['ref']?:$road['name']);
            $instructions[]=sprintf('At %.2f mi, follow %s initially %s for %.2f mi to %s.',
                ($distance-$length)/1609.344,$name,$direction,$length/1609.344,self::coordinate($p[count($p)-1]));
        }
        $instructions[]='End of selected stretch at '.self::coordinate($points[count($points)-1]).'.';
        $road['instructions']=$instructions;
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
        $out.='<trk><name>'.$name.'</name><desc>'.self::xml(implode("\n",$road['instructions'])).'</desc><trkseg>';
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
            '<Placemark><name>'.$name.'</name><description>'.self::xml(implode("\n",$road['instructions'])).'</description>'.
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
    private static function pdfText(string $text,float $x,float $y,int $size=11): string
    {
        if (function_exists('iconv')) {$text=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$text)?:$text;}
        $text=preg_replace('/[^\x20-\x7e]/','?',$text);
        $text=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);
        return sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n",$size,$x,$y,$text);
    }
    /** Small dependency-free, multipage US Letter PDF with a vector road sketch. */
    public static function pdf(array $road): string
    {
        $points=$road['geometry']['coordinates'];$first=$points[0];$last=$points[count($points)-1];
        $content=self::pdfText('SweepLine - '.$road['name'],40,750,17).
            self::pdfText(sprintf('%.2f miles | %d mapped stops + %d uncertain controls',
                $road['length_m']/1609.344,$road['mandatory_stops'],$road['uncertain_controls']),40,725).
            self::pdfText('Start: '.self::coordinate($first).'   End: '.self::coordinate($last),40,706).
            self::pdfText('Road overview - north up; S = start, E = end. No basemap.',40,682);
        $cos=cos(deg2rad(($first[1]+$last[1])/2));$xs=[];$ys=[];
        foreach ($points as $p) {$xs[]=$p[0]*$cos;$ys[]=$p[1];}
        $minX=min($xs);$minY=min($ys);$dx=max($xs)-$minX;$dy=max($ys)-$minY;
        $scale=min(480/max($dx,0.000001),210/max($dy,0.000001));
        $plot=static fn($p)=>[56+($p[0]*$cos-$minX)*$scale,440+($p[1]-$minY)*$scale];
        $content.="q 0.145 0.388 0.651 RG 1.7 w\n";
        $stride=max(1,(int)ceil(count($points)/3000));
        foreach ($points as $i=>$p) {
            if ($i!==0 && $i!==count($points)-1 && $i%$stride!==0) {continue;}
            [$x,$y]=$plot($p);$content.=sprintf("%.2f %.2f %s\n",$x,$y,$i===0?'m':'l');
        }
        $content.="S Q\n";
        foreach ([['S',$first],['E',$last]] as [$label,$p]) {
            [$x,$y]=$plot($p);$content.=self::pdfText($label,$x+4,$y+4,12);
        }
        $lines=['Road-section directions (not turn-by-turn navigation):',...$road['instructions'],
            'Instructions describe the selected road only; no route to its start is included.',
            'Initial compass headings describe section starts, not every bend.',
            'Obey posted signs and verify access, closures, stops and conditions.',
            'Road data (c) OpenStreetMap contributors - openstreetmap.org/copyright'];
        $pages=[];$y=400;
        foreach ($lines as $line) {
            foreach (explode("\n",wordwrap($line,85,"\n",true)) as $wrapped) {
                if ($y<50) {$pages[]=$content;$content=self::pdfText($road['name'].' - continued',40,750,14);$y=720;}
                $content.=self::pdfText($wrapped,40,$y);$y-=16;
            }
            $y-=8;
        }
        $pages[]=$content;
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];$kids=[];
        foreach ($pages as $i=>$stream) {
            $pageId=4+$i*2;$streamId=$pageId+1;$kids[]=$pageId.' 0 R';
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[$streamId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';
        ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];
        foreach ($objects as $id=>$object) {$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        foreach ($objects as $id=>$_) {$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]);}
        return $pdf.'trailer << /Size '.(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
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
