<?php
declare(strict_types=1);
namespace SweepLine;

/** CPU-only BC3/sRGB textures, with a complete mip chain and straight alpha. */
final class BeamDds
{
    public static function rgba(int $width,int $height,string $pixels): string
    {
        if($width<1||$height<1||$width>2048||$height>2048||($width&($width-1))||($height&($height-1))||strlen($pixels)!==$width*$height*4)
            throw new \InvalidArgumentException('Invalid road texture dimensions.');
        $levels=1+(int)log(max($width,$height),2);$header=array_fill(0,31,0);
        $header[0]=124;$header[1]=0xA1007;$header[2]=$height;$header[3]=$width;$header[4]=max(1,(int)ceil($width/4))*max(1,(int)ceil($height/4))*16;$header[6]=$levels;
        $header[18]=32;$header[19]=4;$header[20]=unpack('V','DX10')[1];$header[26]=0x401008;
        $out='DDS '.pack('V*',...$header).pack('V5',78,3,0,1,1);$cache=[];
        $covered=0;for($i=3;$i<strlen($pixels);$i+=4)if(ord($pixels[$i])>=128)$covered++;
        $coverage=$covered/($width*$height);
        while(true){
            for($y=0;$y<$height;$y+=4)for($x=0;$x<$width;$x+=4){
                $block='';for($dy=0;$dy<4;$dy++)for($dx=0;$dx<4;$dx++){
                    $at=(min($height-1,$y+$dy)*$width+min($width-1,$x+$dx))*4;
                    $pixel=substr($pixels,$at,4);$block.=$pixel[3]==="\0"?"\xff\xff\xff\0":$pixel;
                }
                if(!isset($cache[$block])){if(count($cache)>=1024)$cache=[];$cache[$block]=self::block($block);}
                $out.=$cache[$block];
            }
            if($width===1&&$height===1)break;
            $pixels=self::mip($pixels,$width,$height,$coverage);$width=max(1,intdiv($width,2));$height=max(1,intdiv($height,2));
        }
        return $out;
    }
    private static function mip(string $pixels,int $width,int $height,float $coverage): string
    {
        $w=max(1,intdiv($width,2));$h=max(1,intdiv($height,2));$out='';$histogram=array_fill(0,256,0);
        for($y=0;$y<$h;$y++)for($x=0;$x<$w;$x++){
            $sum=[0,0,0];$alpha=0;$samples=0;
            for($dy=0;$dy<min(2,$height);$dy++)for($dx=0;$dx<min(2,$width);$dx++){
                $at=((2*$y+$dy)*$width+2*$x+$dx)*4;$a=ord($pixels[$at+3]);$alpha+=$a;$samples++;
                foreach([0,1,2] as $k)$sum[$k]+=ord($pixels[$at+$k])*$a;
            }
            foreach($sum as $v)$out.=chr($alpha?(int)round($v/$alpha):255);
            $a=(int)round($alpha/$samples);$out.=chr($a);$histogram[$a]++;
        }
        // Preserve cutout coverage while it is representable. Never turn a tiny
        // far mip into an opaque sheet; the rounded target can legitimately be zero.
        $target=(int)round($coverage*$w*$h);$best=1.;$error=PHP_INT_MAX;
        foreach([1.,.5,.75,1.25,1.5,2.,3.,4.,6.,8.] as $scale){
            $count=0;for($a=1;$a<256;$a++)if($a*$scale>=127.5)$count+=$histogram[$a];
            if(abs($count-$target)<$error){$error=abs($count-$target);$best=$scale;}
        }
        if($best!==1.)for($i=3;$i<strlen($out);$i+=4)$out[$i]=chr(min(255,(int)round(ord($out[$i])*$best)));
        return $out;
    }
    private static function block(string $bytes): string
    {
        $pixels=[];$lo=255;$hi=0;$dark=null;$light=null;$min=INF;$max=-INF;
        for($i=0;$i<16;$i++){
            $p=array_values(unpack('C4',substr($bytes,$i*4,4)));$pixels[]=$p;$lo=min($lo,$p[3]);$hi=max($hi,$p[3]);
            if(!$p[3])continue;$v=$p[0]*3+$p[1]*6+$p[2];
            if($v<$min){$min=$v;$dark=$p;}if($v>$max){$max=$v;$light=$p;}
        }
        if(!$hi)return str_repeat("\0",8).pack('vvV',65535,65535,0);
        $palette=[$hi,$lo];
        if($hi>$lo){for($i=1;$i<=6;$i++)$palette[]=(int)round((($hi*(7-$i))+$lo*$i)/7);}
        else{for($i=1;$i<=4;$i++)$palette[]=(int)round((($hi*(5-$i))+$lo*$i)/5);array_push($palette,0,255);}
        $alpha=0;foreach($pixels as $i=>$p){$best=0;$error=INF;foreach($palette as $j=>$a)if(abs($p[3]-$a)<$error){$error=abs($p[3]-$a);$best=$j;}$alpha|=$best<<($i*3);}
        $rgb565=static fn($p)=>((int)round($p[0]*31/255)<<11)|((int)round($p[1]*63/255)<<5)|(int)round($p[2]*31/255);
        $c0=$rgb565($light);$c1=$rgb565($dark);if($c0<$c1)[$c0,$c1]=[$c1,$c0];
        $expand=static fn($c)=>[(($c>>11)&31)*255/31,(($c>>5)&63)*255/63,($c&31)*255/31];$colors=[$expand($c0),$expand($c1)];
        foreach([1,2] as $i){$c=[];foreach([0,1,2] as $k)$c[]=($colors[0][$k]*(3-$i)+$colors[1][$k]*$i)/3;$colors[]=$c;}
        $indices=0;foreach($pixels as $i=>$p){$best=0;$error=INF;if($p[3])foreach($colors as $j=>$c){$e=($p[0]-$c[0])**2+($p[1]-$c[1])**2+($p[2]-$c[2])**2;if($e<$error){$error=$e;$best=$j;}}$indices|=$best<<($i*2);}
        return chr($hi).chr($lo).pack('Vv',$alpha&0xffffffff,($alpha>>32)&0xffff).pack('vvV',$c0,$c1,$indices);
    }
}