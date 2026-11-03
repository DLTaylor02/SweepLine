<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamScenery
{
    public const RANGE_M=60.96; // 200 feet from the road edge, including object footprint.
    private array $segments=[],$index=[];
    public function __construct(array $road,array $profiles)
    {
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$half=max($profiles[$i-1]["width_m"],$profiles[$i]["width_m"])/2;
            $id=count($this->segments);$this->segments[]=[$a,$b,$half];
            for($x=(int)floor((min($a[0],$b[0])-$half)/64);$x<=(int)floor((max($a[0],$b[0])+$half)/64);$x++)
                for($y=(int)floor((min($a[1],$b[1])-$half)/64);$y<=(int)floor((max($a[1],$b[1])+$half)/64);$y++)$this->index[$x.":".$y][]=$id;
        }
    }
    public function edgeDistance(float $x,float $y): float
    {
        $cx=(int)floor($x/64);$cy=(int)floor($y/64);$best=INF;$seen=[];
        for($ix=$cx-2;$ix<=$cx+2;$ix++)for($iy=$cy-2;$iy<=$cy+2;$iy++)foreach($this->index[$ix.":".$iy]??[] as $id){
            if(isset($seen[$id]))continue;$seen[$id]=true;[$a,$b,$half]=$this->segments[$id];
            $best=min($best,BeamLanes::distance([$x,$y],$a,$b)[0]-$half);
        }
        return $best;
    }
    public function allows(float $x,float $y,float $radius): bool
    {
        $distance=$this->edgeDistance($x,$y);
        return $distance+1e-8>=$radius && $distance+$radius<=self::RANGE_M+1e-8;
    }
    public static function radius(string $kind,float $scale): float
    {
        return match($kind){"tree"=>7.0,"building"=>sqrt(41),"bush"=>1.2,default=>2.0}*$scale;
    }
    public static function place(array $road,array $profiles,BeamRaster $raster,int $seed,callable $progress): array
    {
        $geometry=new self($road,$profiles);$chain=[0.0];
        for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
        $length=$chain[count($chain)-1];$target=min(12000,max(400,(int)ceil($length/1000*250)));$limit=min(200000,$target*25);
        $random=static function()use(&$seed): float {$seed=($seed*1664525+1013904223)&0xFFFFFFFF;return $seed/4294967296;};
        $out=[];$occupied=[];$types=["tree"=>0,"bush"=>0,"rock"=>0,"building"=>0];$skipped=["water"=>0,"footprint"=>0,"slope"=>0,"spacing"=>0,"bounds"=>0];
        for($attempt=0;$attempt<$limit && count($out)<$target;$attempt++){
            if($attempt%100===0)$progress(null);
            $distance=$random()*$length;$lo=1;$hi=count($chain)-1;
            while($lo<$hi){$mid=(int)(($lo+$hi)/2);if($chain[$mid]<$distance)$lo=$mid+1;else $hi=$mid;}
            $i=$lo;$a=$road[$i-1];$b=$road[$i];$len=$chain[$i]-$chain[$i-1];if($len<0.001)continue;$t=($distance-$chain[$i-1])/$len;
            $offset=(max($profiles[$i-1]["width_m"],$profiles[$i]["width_m"])/2+$random()*self::RANGE_M)*($random()<0.5?-1:1);
            $x=$a[0]+$t*($b[0]-$a[0])+($b[1]-$a[1])/$len*$offset;$y=$a[1]+$t*($b[1]-$a[1])-($b[0]-$a[0])/$len*$offset;
            if($x<0||$y<0||$x>=$raster->side||$y>=$raster->side){$skipped["bounds"]++;continue;}
            $cover=BeamRaster::cover($raster->rgb((int)($x/$raster->side*$raster->size),(int)($y/$raster->side*$raster->size)));
            if($cover===4){$skipped["water"]++;continue;}
            $pick=$random();$kind=$cover===0?($pick<0.7?"tree":"bush"):($pick<0.65?"bush":"rock");
            $scale=0.7+$random()*0.6;$radius=self::radius($kind,$scale);
            if(!$geometry->allows($x,$y,$radius)){$skipped["footprint"]++;continue;}
            if($x<$radius||$y<$radius||$x+$radius>=$raster->side||$y+$radius>=$raster->side){$skipped["bounds"]++;continue;}
            $h=$raster->height($x,$y);$slope=0.0;
            foreach([[$radius,0],[-$radius,0],[0,$radius],[0,-$radius]] as [$dx,$dy])$slope=max($slope,abs($raster->height($x+$dx,$y+$dy)-$h)/$radius);
            if($slope>($kind==="building"?0.15:0.7)){$skipped["slope"]++;continue;}
            $cx=(int)floor($x/20);$cy=(int)floor($y/20);$overlap=false;
            for($ix=$cx-1;$ix<=$cx+1;$ix++)for($iy=$cy-1;$iy<=$cy+1;$iy++)foreach($occupied[$ix.":".$iy]??[] as $other)if(hypot($x-$other[0],$y-$other[1])<$radius+$other[2])$overlap=true;
            if($overlap){$skipped["spacing"]++;continue;}
            $occupied[$cx.":".$cy][]=[$x,$y,$radius];$types[$kind]++;
            $out[]=["kind"=>$kind,"position"=>[$x,$y,$h],"scale"=>$scale];
        }
        return ["objects"=>$out,"counts"=>$types,"skipped"=>$skipped,"target"=>$target,"attempts"=>$attempt,"range_from_road_edge_ft"=>200,"building_policy"=>"No buildings inferred from aerial colors; mapped footprint evidence is required."];
    }
}
