<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded stock mesh groundcover, separate from the tree/bush budget. */
final class BeamGrass
{
    public static function asset(array $rgb,array $center,float $elevation): ?string
    {
        [$r,$g,$b]=$rgb;$light=array_sum($rgb)/3;
        if($g<=$r*1.025||$g<=$b*1.04||$light<65||$light>215)return null;
        if(BeamBiome::context($center,$elevation,[$rgb])['zone']==='high_alpine')return null;
        return $center[0]>-103||$g>$r*1.2?'green_grass':'dry_grass';
    }
    public static function place(array $road,array $profiles,BeamRaster $raster,int $seed,callable $progress,array $center,array $reserved,array $exclusions,int $budget): array
    {
        $budget=max(0,min(8000,$budget));$geometry=new BeamScenery($road,$profiles,$exclusions);$out=[];$occupied=[];$chain=[0.0];
        for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
        $buildingIndex=[];
        foreach($reserved as $building){[$bx,$by]=$building['position'];$br=$building['radius']+2;
            for($ix=(int)floor(($bx-$br)/32);$ix<=(int)floor(($bx+$br)/32);$ix++)for($iy=(int)floor(($by-$br)/32);$iy<=(int)floor(($by+$br)/32);$iy++)$buildingIndex[$ix.':'.$iy][]=$building;
        }
        $length=end($chain);$target=min($budget,(int)ceil($length*1.5));$attempt=0;
        $random=static function()use(&$seed): float {$seed=($seed*1664525+1013904223)&0xFFFFFFFF;return $seed/4294967296;};
        for(;$attempt<min(100000,$target*16)&&count($out)<$target;$attempt++){
            if($attempt%100===0)$progress(null);
            $d=$random()*$length;$lo=1;$hi=count($road)-1;
            while($lo<$hi){$m=(int)(($lo+$hi)/2);if($chain[$m]<$d)$lo=$m+1;else $hi=$m;}
            $i=$lo;$a=$road[$i-1];$b=$road[$i];$len=$chain[$i]-$chain[$i-1];if($len<0.001)continue;
            $t=($d-$chain[$i-1])/$len;
            // Concentrate small clumps near verges, while retaining the 200 ft limit.
            $offset=(max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+pow($random(),2)*BeamScenery::RANGE_M)*($random()<0.5?-1:1);
            $x=$a[0]+$t*($b[0]-$a[0])+($b[1]-$a[1])/$len*$offset;$y=$a[1]+$t*($b[1]-$a[1])-($b[0]-$a[0])/$len*$offset;
            // Grow around proven grass evidence rather than repeatedly sampling bare hillsides.
            if(count($out)>=8&&$random()<0.8){
                $anchor=$out[(int)floor($random()*count($out))]['position'];$angle=$random()*2*M_PI;$spread=1+sqrt($random())*5;
                $x=$anchor[0]+cos($angle)*$spread;$y=$anchor[1]+sin($angle)*$spread;
            }
            if($x<1||$y<1||$x>=$raster->side-1||$y>=$raster->side-1)continue;
            $rgb=$raster->rgb((int)($x/$raster->side*$raster->size),(int)($y/$raster->side*$raster->size));
            $asset=self::asset($rgb,$center,$raster->roadHeight($x,$y));if($asset===null)continue;
            if($asset==='green_grass'&&$random()<0.35&&$geometry->edgeDistance($x,$y)>2)$asset='tall_grass';
            $scale=0.7+$random()*0.5;$radius=BeamScenery::radius('grass',$scale,$asset);
            if(!$geometry->allows($x,$y,$radius))continue;
            $h=$raster->height($x,$y);
            if(max(abs($raster->height($x+1,$y)-$h),abs($raster->height($x,$y+1)-$h))>0.6)continue;
            $blocked=false;foreach($buildingIndex[(int)floor($x/32).':'.(int)floor($y/32)]??[] as $building)if(hypot($x-$building['position'][0],$y-$building['position'][1])<$building['radius']+$radius){$blocked=true;break;}
            $cx=(int)floor($x/2);$cy=(int)floor($y/2);
            for($ix=$cx-1;$ix<=$cx+1;$ix++)for($iy=$cy-1;$iy<=$cy+1;$iy++)foreach($occupied[$ix.':'.$iy]??[] as $p)if(hypot($x-$p[0],$y-$p[1])<0.8)$blocked=true;
            if($blocked)continue;
            $occupied[$cx.':'.$cy][]=[$x,$y];$out[]=['kind'=>'grass','asset'=>$asset,'position'=>[$x,$y,$h-0.03],'scale'=>$scale];
        }
        return ['objects'=>$out,'target'=>$target,'attempts'=>$attempt,'attempt_limit_reached'=>count($out)<$target&&$attempt>=min(100000,$target*16),'minimum_aerial_brightness'=>65,'method'=>'Mixed short/tall stock grass in seeded patches with locally clustered infill; every clump rechecks aerial evidence, pavement, mapped water, buildings, slope and alpine exclusions.'];
    }
}
