<?php
declare(strict_types=1);
namespace SweepLine;

final class FavoriteThumbnail
{
    public static function render(array $road): string
    {
        $escape=static fn($s)=>htmlspecialchars((string)$s,ENT_XML1|ENT_QUOTES,'UTF-8');
        [$left,$bottom,$right,$top]=ExportMap::bounds($road['geometry']['coordinates']);
        $scale=532/($right-$left);
        $plot=static function($p) use ($left,$bottom,$scale) {
            [$x,$y]=ExportMap::project($p);return [($x-$left)*$scale,280-($y-$bottom)*$scale];
        };
        $line=static function($points) use ($plot): string {
            $out=[];$stride=max(1,(int)ceil(count($points)/400));
            foreach ($points as $i=>$p) {
                if ($i%$stride && $i!==count($points)-1) {continue;}
                [$x,$y]=$plot($p);$out[]=sprintf('%.2f,%.2f',$x,$y);
            }
            return implode(' ',$out);
        };
        $out='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 532 280" role="img" aria-labelledby="title">'.
            '<title id="title">'.$escape($road['name']).' on a local OpenStreetMap street basemap</title>'.
            '<rect width="532" height="280" fill="#eef3f8"/><g fill="none" stroke-linejoin="round" stroke-linecap="round">';
        $roads=array_slice($road['basemap']['roads']??[],-300);
        foreach ($roads as $r) {
            $major=in_array($r['highway'],['motorway','trunk','primary','secondary'],true);$width=$major?3.4:1.7;
            $p=$line($r['geometry']['coordinates']);
            $out.='<polyline points="'.$p.'" stroke="#c4ced9" stroke-width="'.($width+1.2).'"/>'.
                '<polyline points="'.$p.'" stroke="#ffffff" stroke-width="'.$width.'"/>';
        }
        $out.='</g><g font-family="sans-serif" font-size="10" fill="#53657a">';$used=[];$labels=0;
        foreach (array_reverse($roads) as $r) {
            if (!$r['name'] || $r['name']===$road['name'] || isset($used['name:'.$r['name']])) {continue;}
            $p=$r['geometry']['coordinates'];[$x,$y]=$plot($p[(int)floor(count($p)/2)]);
            $cell=(int)floor($x/110).':'.(int)floor($y/40);
            if ($x<8 || $x>410 || $y<20 || $y>245 || isset($used[$cell])) {continue;}
            $used[$cell]=true;$used['name:'.$r['name']]=true;
            $out.='<text x="'.round($x,2).'" y="'.round($y,2).'">'.$escape(preg_replace('/^(.{0,23}).*$/us','$1',$r['name'])).'</text>';
            if (++$labels>=8) {break;}
        }
        $path=$line($road['geometry']['coordinates']);
        $out.='</g><polyline points="'.$path.'" fill="none" stroke="#fff" stroke-width="7"/>'.
            '<polyline points="'.$path.'" fill="none" stroke="#2563a6" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
        $points=$road['geometry']['coordinates'];
        foreach ([['S',$points[0]],['E',$points[count($points)-1]]] as [$label,$p]) {
            [$x,$y]=$plot($p);$out.='<circle cx="'.$x.'" cy="'.$y.'" r="9" fill="#172b46" stroke="#fff" stroke-width="2"/>'.
                '<text x="'.$x.'" y="'.($y+3).'" text-anchor="middle" font-family="sans-serif" font-size="9" font-weight="bold" fill="#fff">'.$label.'</text>';
        }
        $caption=$roads?'© OpenStreetMap contributors':'Local map context unavailable';
        return $out.'<rect x="0" y="260" width="532" height="20" fill="#fff" fill-opacity=".9"/>'.
            '<text x="8" y="274" font-family="sans-serif" font-size="10" fill="#53657a">'.$escape($caption).'</text>'.
            '<text x="507" y="20" font-family="sans-serif" font-size="12" font-weight="bold" fill="#172b46">N ↑</text></svg>';
    }
}
