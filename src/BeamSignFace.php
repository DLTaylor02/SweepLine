<?php
declare(strict_types=1);
namespace SweepLine;
/** Generic MUTCD-style artwork, not a claim of exact surveyed dimensions or typography. */
final class BeamSignFace {
    public static function png(string $code): string {
        $im=imagecreatetruecolor(512,512);$white=imagecolorallocate($im,242,242,232);$black=imagecolorallocate($im,18,18,18);$red=imagecolorallocate($im,190,25,25);$yellow=imagecolorallocate($im,244,194,28);
        $fontPath=null;foreach(['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','C:/Windows/Fonts/arialbd.ttf'] as $font)if(is_file($font)){$fontPath=$font;break;}
        imageantialias($im,true);
        $rect=static fn($x1,$y1,$x2,$y2,$color)=>imagerectangle($im,$x1*8,$y1*8,$x2*8,$y2*8,$color);
        $outline=static function($xy,$color)use($im):void{imageantialias($im,false);imagepolygon($im,array_map(static fn($v)=>(int)round($v*8),$xy),$color);imageantialias($im,true);};
        $line=static fn($x1,$y1,$x2,$y2,$color)=>imageline($im,$x1*8,$y1*8,$x2*8,$y2*8,$color);
        $thickness=static fn($width)=>imagesetthickness($im,$width*8);
        $style=BeamSignStyle::describe($code);$ink=imagecolorallocate($im,...$style['fg']);$background=imagecolorallocate($im,...$style['bg']);
        imagefill($im,0,0,$background);
        $poly=static function(array $xy,int $color)use($im):void{imagefilledpolygon($im,array_map(static fn($v)=>(int)round($v*8),$xy),$color);};
        $text=static function(string $label,int $y,int $color,int $font=5,float $maxWidth=440)use($im,$fontPath):void{
            $size=($font>=10?$font:($font===1?4.5:($font===3?6.:9.)))*8;
            if($fontPath&&function_exists('imagettftext')){
                $box=imagettfbbox($size,0,$fontPath,$label);$width=$box[2]-$box[0];if($width>$maxWidth){$size*=$maxWidth/$width;$box=imagettfbbox($size,0,$fontPath,$label);}$x=(512-($box[2]-$box[0]))/2-$box[0];$baseline=$y*8-min($box[5],$box[7]);
                imagettftext($im,$size,0,(int)round($x),(int)round($baseline),$color,$fontPath,$label);
            }else{
                $small=imagecreatetruecolor(max(1,imagefontwidth($font)*strlen($label)),imagefontheight($font));
                $bg=imagecolorat($im,256,min(511,$y*8));imagefill($small,0,0,$bg);imagestring($small,$font,0,0,$label,$color);
                imagecopyresampled($im,$small,(int)((512-imagesx($small)*8)/2),$y*8,0,0,imagesx($small)*8,imagesy($small)*8,imagesx($small),imagesy($small));imagedestroy($small);
            }
        };
        if($code==='R1-1'){
            imagefill($im,0,0,$red);$poly([19,3,45,3,61,19,61,45,45,61,19,61,3,45,3,19],$white);
            $poly([20,6,44,6,58,20,58,44,44,58,20,58,6,44,6,20],$red);$text('STOP',26,$white,12);
        }elseif($code==='R1-2'){
            imagefill($im,0,0,$red);$poly([9,8,55,8,32,53],$white);$text('YIELD',18,$red,3);
        }elseif(preg_match('/^R2-1\[[0-9]+(?: mph)?\]$/D',$code)){
            $rect(2,2,61,61,$black);$text('SPEED',6,$black,3);$text('LIMIT',19,$black,3);
            preg_match('/\[([0-9]+)/',$code,$m);$text($m[1],31,$black,20);
        }elseif($style['number']!==null){
            $thickness(1);$rect(2,2,61,61,$ink);
            if($style['family']==='advisory')$text('ADVISORY',8,$ink,3);
            else{$text('SPEED',5,$ink,3);$text('LIMIT',17,$ink,3);}
            $text($style['number'],31,$ink,20);
        }elseif($style['symbol']==='do_not_enter'){
            imagefill($im,0,0,$white);imagefilledellipse($im,256,256,448,448,$red);imagefilledrectangle($im,56,216,456,296,$white);$text('DO NOT',14,$white,3);$text('ENTER',43,$white,3);
        }elseif($style['symbol']==='no_parking'){
            imagefilledellipse($im,256,256,380,380,$red);imagefilledellipse($im,256,256,332,332,$white);$text('P',22,$black,20);imagefilledpolygon($im,[116,136,136,116,396,376,376,396],$red);$thickness(1);$rect(2,2,61,61,$black);
        }elseif(!preg_match('/^W1-(?:[1-5]|8)[LR]$/D',$code)){
            $thickness(1);if($style['shape']==='diamond')$outline([32,3,61,32,32,61,3,32],$ink);else $rect(2,2,61,61,$ink);
            $label=preg_replace('/[^\x20-\x7e]/','?',$style['label']);
            $rows=explode("\n",wordwrap(substr($label,0,120),14,"\n",true));$rows=array_slice($rows,0,6);$top=max(5,(64-count($rows)*8)/2);
            foreach($rows as $i=>$row){$y=(int)($top+$i*8);$width=$style['shape']==='diamond'?max(12,54-2*abs($y+4-32))*8:440;$text($row,$y,$ink,$style['family']==='prohibition'?5:3,$width);}
        }else{
            $thickness(2);if(str_starts_with($code,'W1-8'))$rect(2,2,61,61,$black);else $outline([32,3,61,32,32,61,3,32],$black);
            $right=str_ends_with($code,'R');$flip=static fn($x)=>$right?64-$x:$x;
            if(str_starts_with($code,'W1-8')){
                $xy=[43,15,31,15,14,32,31,49,43,49,26,32];for($i=0;$i<count($xy);$i+=2)$xy[$i]=$flip($xy[$i]);$poly($xy,$black);
            }else{
                $type=(int)substr($code,3,1);
                $points=match($type){
                    1=>[[38,49],[38,26],[18,26]],
                    2=>[[40,49],[40,40],[38,32],[33,27],[25,24],[18,24]],
                    3=>[[31,50],[31,39],[21,39],[21,24],[42,24],[42,14]],
                    4=>[[31,50],[23,43],[20,37],[23,31],[34,28],[42,22],[42,14]],
                    default=>[[32,51],[24,44],[24,38],[40,31],[40,25],[30,20],[30,13]],
                };
                $thickness(6);
                for($i=1;$i<count($points);$i++)$line($flip($points[$i-1][0]),$points[$i-1][1],$flip($points[$i][0]),$points[$i][1],$black);
                $tip=end($points);$head=$type<=2?[11,$tip[1],24,$tip[1]-8,24,$tip[1]+8]:[$tip[0],8,$tip[0]-8,21,$tip[0]+8,21];
                for($i=0;$i<count($head);$i+=2)$head[$i]=$flip($head[$i]);$poly($head,$black);
            }
        }
        ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);return $png;
    }
    public static function mesh(string $material,string $code): string {
        $style=BeamSignStyle::describe($code);
        $shape=$code==='R1-1'?[[-.19,.45],[-.45,.19],[-.45,-.19],[-.19,-.45],[.19,-.45],[.45,-.19],[.45,.19],[.19,.45]]:
            ($code==='R1-2'?[[-.45,.4],[0,-.4],[.45,.4]]:(str_starts_with($code,'W1-8')?[[-.35,.45],[-.35,-.45],[.35,-.45],[.35,.45]]:($style['shape']==='diamond'?[[0,.55],[-.55,0],[0,-.55],[.55,0]]:($style['shape']==='wide'?[[-.55,.35],[-.55,-.35],[.55,-.35],[.55,.35]]:[[-.3,.45],[-.3,-.45],[.3,-.45],[.3,.45]]))));
        $vertices=[[0,-.05,0],[0,-.03,0]];$tri=[];$uv=[];$mats=[];
        foreach($shape as [$x,$z]){$vertices[]=[$x,-.05,$z];$vertices[]=[$x,-.03,$z];}
        $left=min(array_column($shape,0));$right=max(array_column($shape,0));$bottom=min(array_column($shape,1));$top=max(array_column($shape,1));
        $uvOf=static fn($p)=>[($p[0]-$left)/($right-$left),($p[2]-$bottom)/($top-$bottom)];
        for($i=0;$i<count($shape);$i++){
            $a=2+2*$i;$b=2+2*(($i+1)%count($shape));
            foreach([[[0,$a,$b],$material],[ [1,$b+1,$a+1],$material.'_back'],[[$a,$a+1,$b+1],$material.'_back'],[[$a,$b+1,$b],$material.'_back']] as [$face,$name]){
                $tri[]=$face;$uv[]=array_map(static fn($id)=>$uvOf($vertices[$id]),$face);$mats[]=$name;
            }
        }
        return BeamAssets::mesh($vertices,$tri,$material,$uv,$mats);
    }
}
