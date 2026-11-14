<?php
declare(strict_types=1);
namespace SweepLine;
/** Generic MUTCD-style artwork, not a claim of exact surveyed dimensions or typography. */
final class BeamSignFace {
    public static function png(string $code): string {
        $im=imagecreatetruecolor(64,64);$white=imagecolorallocate($im,242,242,232);$black=imagecolorallocate($im,18,18,18);$red=imagecolorallocate($im,190,25,25);$yellow=imagecolorallocate($im,244,194,28);
        $warning=str_starts_with($code,'W');
        imagefill($im,0,0,$warning?$yellow:$white);
        $poly=static function(array $xy,int $color)use($im):void{imagefilledpolygon($im,$xy,$color);};
        $text=static function(string $label,int $y,int $color,int $font=5)use($im):void{imagestring($im,$font,(int)((64-imagefontwidth($font)*strlen($label))/2),$y,$label,$color);};
        if($code==='R1-1'){
            imagefill($im,0,0,$red);$poly([19,3,45,3,61,19,61,45,45,61,19,61,3,45,3,19],$white);
            $poly([20,6,44,6,58,20,58,44,44,58,20,58,6,44,6,20],$red);$text('STOP',23,$white);
        }elseif($code==='R1-2'){
            imagefill($im,0,0,$red);$poly([9,8,55,8,32,53],$white);$text('YIELD',18,$red,3);
        }elseif(str_starts_with($code,'R2-1')){
            imagerectangle($im,2,2,61,61,$black);$text('SPEED',6,$black,3);$text('LIMIT',19,$black,3);
            preg_match('/\[([0-9]+)/',$code,$m);$text($m[1],39,$black);
        }else{
            imagesetthickness($im,2);if(str_starts_with($code,'W1-8'))imagerectangle($im,2,2,61,61,$black);else imagepolygon($im,[32,3,61,32,32,61,3,32],$black);
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
                imagesetthickness($im,6);
                for($i=1;$i<count($points);$i++)imageline($im,$flip($points[$i-1][0]),$points[$i-1][1],$flip($points[$i][0]),$points[$i][1],$black);
                $tip=end($points);$head=$type<=2?[11,$tip[1],24,$tip[1]-8,24,$tip[1]+8]:[$tip[0],8,$tip[0]-8,21,$tip[0]+8,21];
                for($i=0;$i<count($head);$i+=2)$head[$i]=$flip($head[$i]);$poly($head,$black);
            }
        }
        ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($im);return $png;
    }
    public static function mesh(string $material,string $code): string {
        $shape=$code==='R1-1'?[[-.19,.45],[-.45,.19],[-.45,-.19],[-.19,-.45],[.19,-.45],[.45,-.19],[.45,.19],[.19,.45]]:
            ($code==='R1-2'?[[-.45,.4],[0,-.4],[.45,.4]]:(str_starts_with($code,'W1-8')?[[-.35,.45],[-.35,-.45],[.35,-.45],[.35,.45]]:(str_starts_with($code,'W')?[[0,.55],[-.55,0],[0,-.55],[.55,0]]:[[-.3,.45],[-.3,-.45],[.3,-.45],[.3,.45]])));
        $vertices=[[0,-.05,0],[0,-.03,0]];$tri=[];$uv=[];$mats=[];
        foreach($shape as [$x,$z]){$vertices[]=[$x,-.05,$z];$vertices[]=[$x,-.03,$z];}
        $left=min(array_column($shape,0));$right=max(array_column($shape,0));$bottom=min(array_column($shape,1));$top=max(array_column($shape,1));
        $uvOf=static fn($p)=>[($p[0]-$left)/($right-$left),($top-$p[2])/($top-$bottom)];
        for($i=0;$i<count($shape);$i++){
            $a=2+2*$i;$b=2+2*(($i+1)%count($shape));
            foreach([[[0,$a,$b],$material],[ [1,$b+1,$a+1],$material.'_back'],[[$a,$a+1,$b+1],$material.'_back'],[[$a,$b+1,$b],$material.'_back']] as [$face,$name]){
                $tri[]=$face;$uv[]=array_map(static fn($id)=>$uvOf($vertices[$id]),$face);$mats[]=$name;
            }
        }
        return BeamAssets::mesh($vertices,$tri,$material,$uv,$mats);
    }
}
