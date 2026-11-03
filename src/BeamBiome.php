<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamBiome
{
    private static function clamp(float $v): float {return max(0,min(1,$v));}
    /** Broad visual heuristic, not a species survey or authoritative land cover. */
    public static function context(array $center,float $elevation,array $pixels): array
    {
        $latitude=(float)($center[1]??39);$western=($center[0]??-119)<=-103;
        $lower=max(800,min(1900,1300-($latitude-39)*45));
        $upper=max(2300,min(4000,3500-($latitude-39)*65));
        $montane=$western?self::clamp(($elevation-$lower)/650):self::clamp(($elevation-900)/700)*0.5;
        $treeLine=self::clamp(($upper-$elevation)/450);
        $green=0;$shadow=0;$water=0;$bare=0;$count=max(1,count($pixels));
        foreach($pixels as [$r,$g,$b]){
            $brightness=($r+$g+$b)/3;
            if($g>$r*1.02 && $g>$b*0.95)$green++;
            if($brightness<85 && $b<$g*1.35 && $g>$r*0.8)$shadow++;
            if($b>$r*1.3 && $b>$g*1.35)$water++;
            if($brightness>160 && max($r,$g,$b)-min($r,$g,$b)<40)$bare++;
        }
        $canopy=max($green/$count,$shadow/$count*0.9);
        $treeChance=(0.7*$green/$count+0.55*$shadow/$count+$montane*(0.18+0.35*$canopy))*$treeLine;
        $treeChance*=1-0.95*$bare/$count;
        return ["zone"=>$treeLine<=0?"high_alpine":($montane>0.35?"montane":($western?"western_lowland":"eastern_lowland")),"montane"=>$montane,"tree_probability"=>min(0.9,max(0.0,$treeChance)),"pine_probability"=>min(0.95,0.25+0.7*$montane),"water"=>$water/$count>=0.6,"canopy_evidence"=>$canopy];
    }
    public static function sample(BeamRaster $raster,float $x,float $y,array $center): array
    {
        $pixels=[];$distance=max(12,$raster->side/$raster->size);
        foreach([[0,0],[-1,0],[1,0],[0,-1],[0,1],[-1,-1],[1,1],[-1,1],[1,-1]] as [$dx,$dy])$pixels[]=$raster->rgb((int)(($x+$dx*$distance)/$raster->side*$raster->size),(int)(($y+$dy*$distance)/$raster->side*$raster->size));
        return self::context($center,$raster->roadHeight($x,$y),$pixels);
    }
}
