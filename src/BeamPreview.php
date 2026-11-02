<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamPreview
{
    public static function render(BeamRaster $raster,array $road,string $name,?array $spawn=null,string $label=""): string
    {
        if(!function_exists("imagecreatetruecolor"))throw new \RuntimeException("BeamNG previews require PHP GD. Rerun sudo ./setup.sh.");
        $w=1024;$h=576;$side=$raster->side;$cx=$side/2;$cy=$side/2;$span=$side;
        if($spawn){$span=min($side,600);$cx=max($span/2,min($side-$span/2,$spawn[0]));$cy=max($span/2,min($side-$span/2,$spawn[1]));}
        $left=$cx-$span/2;$bottom=$cy-$span/2;
        $png=BeamPng::rgb(512,288,static fn($x,$y)=>$raster->rgb((int)(($left+$x/512*$span)/$side*$raster->size),(int)(($bottom+(1-$y/288)*$span)/$side*$raster->size)));
        $small=imagecreatefromstring($png);$im=imagecreatetruecolor($w,$h);imagecopyresampled($im,$small,0,0,0,0,$w,$h,512,288);imagedestroy($small);
        $project=static fn($p)=>[(int)(($p[0]-$left)/$span*$w),(int)((1-($p[1]-$bottom)/$span)*$h)];
        $blue=imagecolorallocate($im,40,160,255);$white=imagecolorallocate($im,255,255,255);$dark=imagecolorallocate($im,12,22,38);
        imagesetthickness($im,6);for($i=1;$i<count($road);$i++){[$x,$y]=$project($road[$i-1]);[$xx,$yy]=$project($road[$i]);imageline($im,$x,$y,$xx,$yy,$blue);}
        if($spawn){[$x,$y]=$project($spawn);imagefilledellipse($im,$x,$y,22,22,$white);imagefilledellipse($im,$x,$y,12,12,$blue);}
        imagefilledrectangle($im,0,0,$w,108,$dark);imagefilledrectangle($im,0,$h-42,$w,$h,$dark);
        $text=static function(string $text,int $y)use($im,$white){$text=preg_replace("/[^ -~]/","?",$text);$layer=imagecreatetruecolor(500,18);$bg=imagecolorallocate($layer,12,22,38);imagefill($layer,0,0,$bg);imagestring($layer,5,0,0,$text,imagecolorallocate($layer,255,255,255));imagecopyresized($im,$layer,20,$y,0,0,1000,36,500,18);imagedestroy($layer);};
        $text("SWEEPLINE".($label!==""?" / ".$label:""),12);
        $title=wordwrap($name,52,"\n",true);$lines=explode("\n",$title);$text($lines[0],55);if(isset($lines[1]))$text($lines[1],87);
        imagestring($im,4,20,$h-30,"Map-derived preview | OpenStreetMap contributors | USGS / USDA NAIP",$white);
        ob_start();imagepng($im);$out=ob_get_clean();imagedestroy($im);return $out;
    }
}
