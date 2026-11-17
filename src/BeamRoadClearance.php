<?php
declare(strict_types=1);
namespace SweepLine;

/** Shared exported-pavement clearance, including connectors and merge aprons. */
final class BeamRoadClearance
{
    private const CELL_M=32.;
    public static function prepare(array $lines): array
    {
        $out=[];
        foreach($lines as $line){
            if(count($line['points']??[])<2||!($line['profiles']??[]))continue;
            if(isset($line['clearance_mesh'],$line['clearance_cells'])){$out[]=$line;continue;}
            $bytes='';$cells=[];$bounds=[INF,INF,-INF,-INF];
            $triangle=static function($t)use(&$bytes,&$cells,&$bounds){
                if(abs(BeamPolygon::cross(...$t))<1e-8)return;
                $x=array_column($t,0);$y=array_column($t,1);$b=[min($x),min($y),max($x),max($y)];$id=intdiv(strlen($bytes),72);
                foreach($t as $p)$bytes.=pack('e3',...$p);
                $bounds=[min($bounds[0],$b[0]),min($bounds[1],$b[1]),max($bounds[2],$b[2]),max($bounds[3],$b[3])];
                for($cy=(int)floor($b[1]/self::CELL_M);$cy<=(int)floor($b[3]/self::CELL_M);$cy++)for($cx=(int)floor($b[0]/self::CELL_M);$cx<=(int)floor($b[2]/self::CELL_M);$cx++){
                    $key=$cx.':'.$cy;$cells[$key]=($cells[$key]??'').pack('V',$id);
                }
            };
            if(isset($line['collision_top'])){
                $raw=base64_decode($line['collision_top'],true);if($raw===false||strlen($raw)%72)throw new \RuntimeException('Invalid pavement clearance geometry.');
                for($at=0;$at<strlen($raw);$at+=72){$v=array_values(unpack('e9',substr($raw,$at,72)));$triangle([array_slice($v,0,3),array_slice($v,3,3),array_slice($v,6,3)]);}
            }else{
                $sets=[BeamJunctionBoundary::sections($line['points'],$line['profiles'])];
                $rings=BeamPavement::connectorRings($line,$line['junction_parent']['points']??$line['points'],$line['junction_parent']['profiles']??$line['profiles']);if($rings)$sets[]=$rings;
                foreach($sets as $sections)for($i=1;$i<count($sections);$i++){
                    [$a,$b]=$sections[$i-1];[$d,$c]=$sections[$i];$triangle([$a,$b,$c]);$triangle([$a,$c,$d]);
                }
                foreach($line['junction_fill']??[] as $t)$triangle($t);
            }
            $line['clearance_mesh']=$bytes;$line['clearance_cells']=$cells;$line['clearance_bounds']=$bounds;$out[]=$line;
        }return $out;
    }
    /** Exact top height at the footprint, or its nearest edge within the object's radius. */
    private static function height(array $p,array $triangle,float $padding): ?float
    {
        $weights=BeamTerrainContact::weights($p,$triangle);
        if($weights&&min($weights)>=-1e-8){$z=0.;foreach($weights as $i=>$w)$z+=$w*$triangle[$i][2];return $z;}
        $distance=$padding+1e-9;$height=null;
        foreach([[0,1],[1,2],[2,0]] as [$i,$j]){
            $a=$triangle[$i];$b=$triangle[$j];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=$dx*$dx+$dy*$dy;if($length<1e-12)continue;
            $t=max(0.,min(1.,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$length));$d=hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy);
            if($d<=$distance){$distance=$d;$height=$a[2]+($b[2]-$a[2])*$t;}
        }return $height;
    }
    public static function hit(array $point,array $lines,float $bottom,float $top,float $padding=0.,?string $exclude=null): ?array
    {
        foreach($lines as $line){
            if($exclude!==null&&($line['name']??null)===$exclude)continue;$b=$line['clearance_bounds'];
            if($point[0]<$b[0]-$padding||$point[0]>$b[2]+$padding||$point[1]<$b[1]-$padding||$point[1]>$b[3]+$padding)continue;
            $seen=[];
            for($y=(int)floor(($point[1]-$padding)/self::CELL_M);$y<=(int)floor(($point[1]+$padding)/self::CELL_M);$y++)for($x=(int)floor(($point[0]-$padding)/self::CELL_M);$x<=(int)floor(($point[0]+$padding)/self::CELL_M);$x++){
                $ids=$line['clearance_cells'][$x.':'.$y]??'';
                for($at=0;$at<strlen($ids);$at+=4){
                    $id=unpack('V',substr($ids,$at,4))[1];if(isset($seen[$id]))continue;$seen[$id]=true;
                    $v=array_values(unpack('e9',substr($line['clearance_mesh'],$id*72,72)));
                    $z=self::height($point,[array_slice($v,0,3),array_slice($v,3,3),array_slice($v,6,3)],$padding);
                    if($z===null||$z<$bottom||$z>$top)continue;
                    $n=BeamIntersections::nearest($point,$line['points'],$line['profiles']);if(!$n)continue;$n['z']=$z;
                    return ['line'=>$line,'hit'=>$n];
                }
            }
        }return null;
    }
    public static function pierBlocked(array $point,float $bottom,float $top,array $lines,string $exclude): bool
    {
        return self::hit($point,$lines,$bottom-5.,$top+.25,1.6,$exclude)!==null;
    }
    /** Only an actual ramp overlap authorizes moving an otherwise mapped sign. */
    public static function relocateSign(array $point,array $lines,BeamRaster $raster,float $origin): ?array
    {
        return self::relocateObject($point,$lines,$raster,$origin,.75,true);
    }
    public static function relocateObject(array $point,array $lines,BeamRaster $raster,float $origin,float $padding=.75,bool $rampOnly=false): ?array
    {
        $ground=$raster->height(...$point)-$origin;
        $ramps=array_values(array_filter($lines,static fn($line)=>($line['kind']??'')==='ramp'));
        $blocked=self::hit($point,$rampOnly?$ramps:$lines,$ground-.5,$ground+3.,$padding);
        if(!$blocked)return ['point'=>$point,'shift_m'=>0.];
        $n=$blocked['hit'];$normal=[-$n['dy'],$n['dx']];$offset=($point[0]-$n['x'])*$normal[0]+($point[1]-$n['y'])*$normal[1];$options=[];
        foreach([1.,-1.] as $side){
            $start=max(.5,$n['half_width']+$padding+.1-$side*$offset);
            for($distance=$start;$distance<=30.;$distance+=.5){
                $candidate=[$point[0]+$side*$normal[0]*$distance,$point[1]+$side*$normal[1]*$distance];
                if(min($candidate)<0||max($candidate)>=$raster->side)continue;
                $z=$raster->height(...$candidate)-$origin;
                if(self::hit($candidate,$lines,$z-.5,$z+3.,$padding))continue;
                $options[]=['point'=>$candidate,'shift_m'=>$distance,'reason'=>$rampOnly?'ramp_pavement_overlap':'pavement_overlap','ramp'=>$blocked['line']['name']??null];break;
            }
        }
        if(!$options)return null;
        usort($options,static fn($a,$b)=>$a['shift_m']<=>$b['shift_m']);return $options[0];
    }
}