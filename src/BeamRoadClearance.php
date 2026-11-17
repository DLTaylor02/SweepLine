<?php
declare(strict_types=1);
namespace SweepLine;

/** Shared exported-road clearance for generated objects, never an inferred road source. */
final class BeamRoadClearance
{
    public static function prepare(array $lines): array
    {
        $out=[];
        foreach($lines as $line){
            if(count($line['points']??[])<2||!($line['profiles']??[]))continue;
            $half=max(array_column($line['profiles'],'width_m'))/2;
            $xs=array_column($line['points'],0);$ys=array_column($line['points'],1);
            $line['clearance_bounds']=[min($xs)-$half,min($ys)-$half,max($xs)+$half,max($ys)+$half];$out[]=$line;
        }return $out;
    }
    public static function hit(array $point,array $lines,float $bottom,float $top,float $padding=0.,?string $exclude=null): ?array
    {
        foreach($lines as $line){
            if($exclude!==null&&($line['name']??null)===$exclude)continue;
            $b=$line['clearance_bounds'];
            if($point[0]<$b[0]-$padding||$point[0]>$b[2]+$padding||$point[1]<$b[1]-$padding||$point[1]>$b[3]+$padding)continue;
            $n=BeamIntersections::nearest($point,$line['points'],$line['profiles']);
            if($n){
                $i=$n['segment'];$a=$line['points'][$i-1];$end=$line['points'][$i];$dx=$end[0]-$a[0];$dy=$end[1]-$a[1];$length=hypot($dx,$dy);
                $at=(($point[0]-$a[0])*$dx+($point[1]-$a[1])*$dy)/max(.001,$length);
                if(($i===1&&$at<-$padding)||($i===count($line['points'])-1&&$at>$length+$padding))continue;
            }
            if($n&&$n['distance']<$n['half_width']+$padding&&$n['z']>=$bottom&&$n['z']<=$top)return ['line'=>$line,'hit'=>$n];
        }return null;
    }
    public static function pierBlocked(array $point,float $bottom,float $top,array $lines,string $exclude): bool
    {
        // Include the post footprint and a vehicle envelope on roads below it.
        return self::hit($point,$lines,$bottom-5.,$top+.25,1.6,$exclude)!==null;
    }
    /** Only an actual ramp overlap authorizes moving an otherwise mapped sign. */
    public static function relocateSign(array $point,array $lines,BeamRaster $raster,float $origin): ?array
    {
        $ground=$raster->height(...$point)-$origin;
        $ramps=array_values(array_filter($lines,static fn($line)=>($line['kind']??'')==='ramp'));
        $blocked=self::hit($point,$ramps,$ground-.5,$ground+3.,.75);
        if(!$blocked)return ['point'=>$point,'shift_m'=>0.];
        $n=$blocked['hit'];$normal=[-$n['dy'],$n['dx']];$offset=($point[0]-$n['x'])*$normal[0]+($point[1]-$n['y'])*$normal[1];$options=[];
        foreach([1.,-1.] as $side){
            $start=max(.5,$n['half_width']+.85-$side*$offset);
            for($distance=$start;$distance<=30.;$distance+=.5){
                $candidate=[$point[0]+$side*$normal[0]*$distance,$point[1]+$side*$normal[1]*$distance];
                if(min($candidate)<0||max($candidate)>=$raster->side)continue;
                $z=$raster->height(...$candidate)-$origin;
                if(self::hit($candidate,$lines,$z-.5,$z+3.,.75))continue;
                $options[]=['point'=>$candidate,'shift_m'=>$distance,'reason'=>'ramp_pavement_overlap','ramp'=>$blocked['line']['name']??null];break;
            }
        }
        if(!$options)return null;
        usort($options,static fn($a,$b)=>$a['shift_m']<=>$b['shift_m']);return $options[0];
    }
}
