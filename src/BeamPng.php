<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamPng
{
    public const ROAD_TEXTURE_LENGTH_M=48;
    public const PAINT_REPEAT_LENGTH_M=12;
    public const ROAD_TEXTURE_HEIGHT=1024;
    public const ROAD_DASH_LENGTH_M=3;
    public static function rgb(int $width,int $height,callable $pixel): string
    {
        $raw='';
        for($y=0;$y<$height;$y++){$raw.="\0";for($x=0;$x<$width;$x++){[$r,$g,$b]=$pixel($x,$y);$raw.=chr(max(0,min(255,(int)$r))).chr(max(0,min(255,(int)$g))).chr(max(0,min(255,(int)$b)));}}
        $chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).$chunk('IDAT',gzcompress($raw,6)).$chunk('IEND','');
    }
    public static function road(array $profile): string
    {
        return self::rgb(256,self::ROAD_TEXTURE_HEIGHT,static fn($x,$y)=>self::roadPixel($profile,$x,$y%256));
    }
    public static function paintMask(array $profile): string
    {
        return self::rgb(256,self::ROAD_TEXTURE_HEIGHT,static function($x,$y)use($profile){$value=self::roadPixel($profile,$x,$y%256)[0]>150?255:0;return [$value,$value,$value];});
    }
    private static function roadPixel(array $profile,int $x,int $y): array
    {
            $noise=(($x*17+$y*31)%11)-5;$color=[62+$noise,63+$noise,65+$noise];
            $edgeMargin=isset($profile['shoulder_m'])?(int)round(255*$profile['shoulder_m']/$profile['width_m']):0;
            if(!empty($profile['unmarked'])){if((!empty($profile['keep_edge_left'])&&abs($x-$edgeMargin)<=1)||(!empty($profile['keep_edge_right'])&&abs($x-(255-$edgeMargin))<=1))return [235,235,225];return $color;}
            $margin=isset($profile['shoulder_m'])?(int)round(255*$profile['shoulder_m']/$profile['width_m']):0;
            if($margin?abs($x-$margin)<=1||abs($x-(255-$margin))<=1:$x<5||$x>250){
                $inner=!empty($profile['oneway'])&&(($profile['legal_forward']??true)?$x<128:$x>=128);
                if($inner&&!empty($profile['lane_paint_segment']))return $color;
                return $inner?[235,190,35]:[235,235,225];
            }
            if(!empty($profile['lane_paint_segment']))return $color;
            for($lane=1;$lane<$profile['lanes'];$lane++) {
                $line=(int)round($margin+$lane/$profile['lanes']*(255-2*$margin));
                $center=!$profile['oneway'] && $lane===$profile['backward'];
                if(abs($x-$line)<=1 && ($center || $y<(int)round(256*self::ROAD_DASH_LENGTH_M/self::PAINT_REPEAT_LENGTH_M)))$color=$center?[235,190,35]:[225,225,215];
            }
            return $color;
    }
}

