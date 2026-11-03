<?php
declare(strict_types=1);
namespace SweepLine;

/** Conservative visual shadow repair, not authoritative land-cover detection. */
final class BeamShadows
{
    public static function candidate(array $rgb): bool
    {
        $light=array_sum($rgb)/3;
        return $light>=12&&$light<65&&$rgb[2]>=$rgb[0]*0.75;
    }
    public static function blue(array $rgb): bool {return $rgb[2]>$rgb[0]*1.2&&$rgb[2]>$rgb[1]*1.15;}
    public static function regional(array $center,float $elevation): array
    {
        $biome=BeamBiome::context($center,$elevation,[]);
        if($biome['zone']==='high_alpine')return [151,148,136];
        if($biome['zone']==='montane')return [90,111,70];
        if($center[0]>-103||($center[0]<-122&&$center[1]>40))return [99,121,75];
        return [162,141,111];
    }
    public static function replacement(array $rgb,array $neighbors,float $slope,array $center,float $elevation): ?array
    {
        if(!self::candidate($rgb))return null;
        $lit=[];$blue=0;
        foreach($neighbors as $p){
            if(self::blue($p))$blue++;
            $light=array_sum($p)/3;
            if($light>=80&&$light<=220&&!self::blue($p))$lit[]=$p;
        }
        // Flat blue neighborhoods may be real water. Leave ambiguous cases intact.
        if(self::blue($rgb)&&$slope<0.04&&($blue>=count($neighbors)/3||count($lit)<3))return null;
        $light=array_sum($rgb)/3;
        if($slope<0.04&&(count($lit)<3||array_sum(array_map('array_sum',$lit))/(3*count($lit))<$light*1.6))return null;
        $base=self::regional($center,$elevation);
        if(count($lit)>=3){
            $green=0;$warm=0;
            foreach($lit as [$r,$g,$b]){
                if($g>$r*1.02&&$g>$b*1.02)$green++;
                if($r>$g*1.06&&$g>$b*1.04)$warm++;
            }
            // Local vegetation/dry ground can override broad regional assumptions.
            if($green/count($lit)>=0.6)$base=($elevation>1500&&$center[0]<=-103)?[90,111,70]:[99,121,75];
            elseif($warm/count($lit)>=0.6)$base=[162,141,111];
        }
        $weight=max(0.65,min(1,(75-$light)/40));
        return array_map(static fn($v,$i)=>(int)round($rgb[$i]*(1-$weight)+$v*$weight),$base,array_keys($base));
    }
    public static function correct(BeamRaster $raster,array $center,callable $progress): array
    {
        $corrected=clone $raster;$colors=$raster->colors;$n=$raster->size;$plane=$n*$n;$step=$raster->side/$n;$changed=0;$candidates=0;
        $progress('Correcting likely aerial shadows using local terrain and regional colors');
        $offsets=[[1,0],[-1,0],[0,1],[0,-1],[1,1],[-1,-1],[1,-1],[-1,1]];
        for($y=0;$y<$n;$y++){
            if($y%8===0)$progress(null);
            for($x=0;$x<$n;$x++){
                $rgb=$raster->rgb($x,$y);if(!self::candidate($rgb))continue;$candidates++;
                $neighbors=[];
                foreach([10,30,90] as $meters){
                    $radius=max(1,(int)round($meters/$step));
                    foreach($offsets as [$dx,$dy])$neighbors[]=$raster->rgb($x+$dx*$radius,$y+$dy*$radius);
                }
                $wx=$x*$step;$wy=$y*$step;$h=$raster->height($wx,$wy);$slope=0.0;
                foreach([[1,0],[-1,0],[0,1],[0,-1]] as [$dx,$dy]){
                    $xx=max(0,min($raster->side-$step,$wx+$dx*10));$yy=max(0,min($raster->side-$step,$wy+$dy*10));
                    $d=hypot($xx-$wx,$yy-$wy);
                    if($d>0)$slope=max($slope,abs($raster->height($xx,$yy)-$h)/$d);
                }
                $replacement=self::replacement($rgb,$neighbors,$slope,$center,$h);if($replacement===null)continue;
                $index=($n-1-$y)*$n+$x;
                foreach($replacement as $channel=>$value)$colors[$channel*$plane+$index]=chr($value);
                $changed++;
            }
        }
        $corrected->colors=$colors;
        return ['raster'=>$corrected,'candidate_pixels'=>$candidates,'corrected_pixels'=>$changed,
            'method'=>'Dark neutral/blue candidates, 10/30/90 m neighborhoods, slope and muted regional palettes; flat ambiguous blue preserved.',
            'limitation'=>'Visual heuristic: shadows, water, dark roofs and dark ground cannot always be distinguished from RGB imagery.'];
    }
}
