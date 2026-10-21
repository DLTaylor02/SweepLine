<?php
declare(strict_types=1);
namespace SweepLine;

/** Styled vector PDF roadbook: no font download, image service or PDF runtime. */
final class RoadPdf
{
    private static function text(string $value,float $x,float $y,int $size=11,string $font='F1',string $color='0.10 0.17 0.27'): string
    {
        if (function_exists('iconv')) {$value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;}
        $value=preg_replace('/[^\x20-\x7e]/','?',$value);
        $value=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$value);
        return sprintf("q %s rg BT /%s %d Tf %.2f %.2f Td (%s) Tj ET Q\n",$color,$font,$size,$x,$y,$value);
    }
    private static function box(float $x,float $y,float $w,float $h,string $fill): string
    {
        return sprintf("q %s rg %.2f %.2f %.2f %.2f re f Q\n",$fill,$x,$y,$w,$h);
    }
    private static function coord(array $p): string
    {
        return sprintf('%.6f, %.6f',$p[1],$p[0]);
    }
    private static function header(string $name,string $section): string
    {
        $out=self::box(0,770,612,22,'0.15 0.39 0.65').
            self::text('SWEEPLINE',40,745,12,'F2','0.15 0.39 0.65').
            self::text($section,400,745,9,'F2','0.38 0.45 0.54');
        $lines=explode("\n",wordwrap($name,44,"\n",true));
        foreach (array_slice($lines,0,2) as $i=>$line) {$out.=self::text($line,40,713-$i*24,22,'F2');}
        return $out;
    }
    private static function footer(string $content,int $page,int $total): string
    {
        return $content."q 0.85 0.89 0.94 RG 40 37 m 572 37 l S Q\n".
            self::text('SweepLine | Road data (c) OpenStreetMap contributors',40,23,8,'F1','0.38 0.45 0.54').
            self::text($page.' / '.$total,535,23,9,'F2');
    }
    private static function path(array $points,callable $plot): string
    {
        $out='';$last=count($points)-1;$stride=max(1,(int)ceil(count($points)/2000));
        foreach ($points as $i=>$p) {
            if ($i && $i!==$last && $i%$stride) {continue;}
            [$x,$y]=$plot($p);$out.=sprintf("%.2f %.2f %s\n",$x,$y,$i===0?'m':'l');
        }
        return $out;
    }
    private static function map(array $road): string
    {
        $bounds=ExportMap::bounds($road['geometry']['coordinates']);
        [$left,$bottom,$right,$top]=$bounds;$scale=532/($right-$left);
        $plot=static function($p) use ($left,$bottom,$scale) {
            [$x,$y]=ExportMap::project($p);return [40+($x-$left)*$scale,300+($y-$bottom)*$scale];
        };
        $out=self::box(40,300,532,280,'0.95 0.97 0.98')."q 40 300 532 280 re W n\n";
        // Projected geographic grid establishes context even where no side roads are mapped.
        $out.="0.88 0.91 0.94 RG 0.4 w\n";
        for ($i=1;$i<5;$i++) {$x=40+$i*532/5;$y=300+$i*280/5;$out.="$x 300 m $x 580 l S\n40 $y m 572 $y l S\n";}
        $context=$road['basemap']['roads']??[];
        foreach ($context as $r) {
            $major=in_array($r['highway'],['motorway','trunk','primary','secondary'],true);
            $width=$major?2.7:1.1;
            $path=self::path($r['geometry']['coordinates'],$plot);
            $out.="0.77 0.81 0.85 RG ".($width+1)." w\n".$path."S\n".
                "1 1 1 RG $width w\n".$path."S\n";
        }
        $used=[];$labels=0;
        foreach (array_reverse($context) as $r) {
            if (!$r['name'] || $r['name']===$road['name'] || isset($used['name:'.$r['name']])) {continue;}
            $p=$r['geometry']['coordinates'];[$x,$y]=$plot($p[(int)floor(count($p)/2)]);
            $cell=(int)floor(($x-40)/95).':'.(int)floor(($y-300)/32);
            if ($x<47 || $x>465 || $y<316 || $y>562 || isset($used[$cell])) {continue;}
            $used[$cell]=true;$used['name:'.$r['name']]=true;
            $out.=self::text(substr($r['name'],0,27),$x,$y,7,'F1','0.38 0.43 0.48');
            if (++$labels>=18) {break;}
        }
        $path=self::path($road['geometry']['coordinates'],$plot);
        $out.="1 1 1 RG 5 w\n".$path."S\n0.15 0.39 0.65 RG 3 w\n".$path."S\nQ\n";
        $points=$road['geometry']['coordinates'];
        foreach ([['S',$points[0]],['E',$points[count($points)-1]]] as [$label,$p]) {
            [$x,$y]=$plot($p);$out.=self::box($x-7,$y-7,14,14,'0.10 0.17 0.27').
                self::text($label,$x-3.5,$y-3.5,9,'F2','1 1 1');
        }
        // North arrow and a ground-distance scale at the map's center latitude.
        $out.=self::box(538,530,25,40,'1 1 1').
            "q 0.15 0.39 0.65 rg 550 549 m 545 539 l 555 539 l h f Q\n".
            self::text('N',547,557,8,'F2');
        $lat=rad2deg(2*atan(exp((($bottom+$top)/2)/6378137))-M_PI/2);
        $groundPerPixel=cos(deg2rad($lat))/$scale;
        $target=100*$groundPerPixel;$power=10**floor(log10(max(1,$target)));
        $meters=max(1,floor($target/$power)*$power);$pixels=$meters/$groundPerPixel;
        $out.=self::box(48,307,140,23,'1 1 1')."q 0.10 0.17 0.27 RG 1.2 w 56 314 m ".(56+$pixels)." 314 l S Q\n".
            self::text($meters>=160.9344?sprintf('%.2f mi',$meters/1609.344):sprintf('%.0f ft',$meters/0.3048),56,320,7,'F2');
        $caption=$context?'Local OSM street map | North up | S start / E end':'Surrounding roads unavailable in local data | North up | S start / E end';
        if ($road['basemap']['truncated']??false) {$caption.=' | Minor roads limited';}
        return $out.self::text($caption,40,284,8,'F1','0.38 0.45 0.54').
            self::text('openstreetmap.org/copyright',40,272,8,'F1','0.38 0.45 0.54');
    }
    private static function icon(string $kind,float $x,float $y): string
    {
        $out=sprintf("q 0.15 0.39 0.65 RG 0.15 0.39 0.65 rg 2.2 w 1 J 1 j 1 0 0 1 %.2f %.2f cm\n",$x,$y);
        if ($kind==='start') {$out.="-6 -6 12 12 re f\n";}
        elseif ($kind==='end') {$out.="-7 -7 14 14 re S -3 -3 6 6 re f\n";}
        elseif (str_contains($kind,'hairpin')) {
            $sign=str_contains($kind,'left')?-1:1;
            $out.="$sign 0 0 1 0 0 cm\n-6 -8 m -6 5 l -6 14 6 14 6 5 c 6 -8 l S\n2 -3 m 6 -8 l 10 -3 l S\n";
        } else {
            $sign=str_contains($kind,'left')?-1:1;
            $out.="$sign 0 0 1 0 0 cm\n-5 -10 m -5 6 l 9 6 l S\n4 11 m 9 6 l 4 1 l S\n";
        }
        return $out."Q\n";
    }
    public static function render(array $road): string
    {
        $points=$road['geometry']['coordinates'];$last=$points[count($points)-1];
        $cover=self::header($road['name'],'ROAD OVERVIEW');
        $stats=[['DISTANCE',sprintf('%.2f mi',$road['length_m']/1609.344)],
            ['DRIVING SCORE',isset($road['score'])?$road['score'].' / 100':'Not available'],
            ['MAPPED CONTROLS',$road['mandatory_stops'].' stops + '.$road['uncertain_controls'].' uncertain']];
        foreach ($stats as $i=>[$label,$value]) {
            $x=40+$i*180;$cover.=self::box($x,614,172,52,'0.93 0.96 0.99').
                self::text($label,$x+10,649,8,'F2','0.38 0.45 0.54').self::text($value,$x+10,629,$i===2?11:16,'F2');
        }
        $cover.=self::text('THE ROAD',40,593,11,'F2').self::map($road).
            self::text('START',40,242,9,'F2','0.15 0.39 0.65').self::text(self::coord($points[0]),115,242,11,'F2').
            self::text('FINISH',40,219,9,'F2','0.15 0.39 0.65').self::text(self::coord($last),115,219,11,'F2').
            self::text('READING YOUR ROADBOOK',40,177,11,'F2').
            self::text('The following pages list bends in travel order with mileage and angle.',40,155).
            self::text('Miles are cumulative from the start. +mi is distance since the previous note.',40,137,10).
            self::text('Notes describe mapped road shape, not surveyed pace notes or junction turns.',40,110,9,'F3').
            self::text('Verify access, closures and conditions; obey posted signs.',40,94,9,'F3');
        $pages=[$cover];
        $notes=$road['notes']??[];$rows=[['mile'=>0,'gap'=>null,'kind'=>'start','title'=>'START','detail'=>self::coord($points[0])]];
        foreach ($notes as $note) {
            $rows[]=['mile'=>$note['distance_m']/1609.344,'gap'=>$note['distance_since_previous_m']/1609.344,
                'kind'=>strtolower($note['description']),'title'=>$note['description'].' - '.$note['angle_degrees'].' degrees','detail'=>''];
        }
        $rows[]=['mile'=>$road['length_m']/1609.344,'gap'=>null,'kind'=>'end','title'=>'FINISH','detail'=>self::coord($last)];
        $content='';$y=0;
        foreach ($rows as $i=>$row) {
            if ($y<110) {
                if ($content!=='') {$pages[]=$content;}
                $content=self::header($road['name'],'ROAD-SHAPE NOTES').
                    self::text('Road-shape notes',40,662,15,'F2').
                    self::text('CUMULATIVE MI',48,634,8,'F2','0.38 0.45 0.54').
                    self::text('DIRECTION / BEND',205,634,8,'F2','0.38 0.45 0.54');
                $y=615;
            }
            $content.=self::box(40,$y-52,532,52,$i%2?'0.95 0.97 0.99':'1 1 1').
                self::text(sprintf('%.2f mi',$row['mile']),50,$y-20,14,'F2').
                ($row['gap']===null?'':self::text(sprintf('+%.2f mi',$row['gap']),50,$y-38,9,'F1','0.38 0.45 0.54')).
                self::icon($row['kind'],176,$y-27).
                self::text($row['title'],205,$y-21,12,'F2').
                self::text($row['detail'],205,$y-39,9);
            $y-=56;
        }
        if (!$notes && $y>105) {$content.=self::text('No bends of 15 degrees or more detected in the mapped geometry.',50,$y-18,10);}
        $pages[]=$content;
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'',
            3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            5=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique >>'];$kids=[];
        foreach ($pages as $i=>$stream) {
            $stream=self::footer($stream,$i+1,count($pages));$pageId=6+$i*2;$streamId=$pageId+1;$kids[]=$pageId.' 0 R';
            $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents '.$streamId.' 0 R >>';
            $objects[$streamId]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
        }
        $objects[2]='<< /Type /Pages /Count '.count($pages).' /Kids ['.implode(' ',$kids).'] >>';
        ksort($objects);$pdf="%PDF-1.4\n";$offsets=[0];
        foreach ($objects as $id=>$object) {$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        foreach ($objects as $id=>$_) {$pdf.=sprintf("%010d 00000 n \n",$offsets[$id]);}
        return $pdf.'trailer << /Size '.(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
