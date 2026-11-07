<?php
declare(strict_types=1);
namespace SweepLine;

/** Locally refined median ground, sharing exact road edges without changing pavement. */
final class BeamMedianSurface
{
    public const EDGE_CLEARANCE_M=0.05;
    public const MAX_TRIANGLES=120000;
    public static function build(array $main,array $profiles,array $tracks,array $spans,BeamRaster $raster,string $protected,string $holes,callable $progress): array
    {
        $sections=BeamJunctionBoundary::sections($main,$profiles);$chain=[0.];
        for($i=1;$i<count($main);$i++)$chain[]=$chain[$i-1]+hypot($main[$i][0]-$main[$i-1][0],$main[$i][1]-$main[$i-1][1]);
        $vertices=[];$faces=[];$uv=[];$kinds=[];$lookup=[];$skipped=0;$patches=0;
        $vertex=static function(array $p)use(&$vertices,&$lookup):int{
            $key=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p));
            if(!isset($lookup[$key])){$lookup[$key]=count($vertices);$vertices[]=$p;}return $lookup[$key];
        };
        $step=$raster->side/$raster->size;$n=$raster->size;
        foreach($tracks as $track){
            $pairs=BeamMedian::pairs($main,$profiles,$track,$spans);$previous=null;
            foreach($pairs as $i=>$pair){
                if($i%100===0)$progress(null);
                if($previous===null||$i!==$previous[0]+1){$previous=[$i,$pair];continue;}
                $prev=$previous[1];$previous=[$i,$pair];
                if(min($prev['gap'],$pair['gap'])<0||max($prev['gap'],$pair['gap'])>40||$prev['main_side']!==$pair['main_side']){$skipped++;continue;}
                $lo=min($prev['main_along'],$pair['main_along']);$hi=max($prev['main_along'],$pair['main_along']);
                if($hi-$lo>80){$skipped++;continue;}
                // Include EVERY primary edge station; otherwise a patch chord cuts a curved road edge.
                $edge=[$prev['a']];$inside=[];
                for($j=max(0,min($prev['main_segment'],$pair['main_segment'])-1);$j<=min(count($main)-1,max($prev['main_segment'],$pair['main_segment']));$j++)
                    if($chain[$j]>$lo+1e-7&&$chain[$j]<$hi-1e-7)$inside[]=$sections[$j][$pair['main_side']];
                if($prev['main_along']>$pair['main_along'])$inside=array_reverse($inside);
                array_push($edge,...$inside);$edge[]=$pair['a'];
                $polygon=array_merge($edge,[$pair['b'],$prev['b']]);
                // Remove identical adjacent boundary vertices, keeping elevation/grade stations.
                $clean=[];foreach($polygon as $p)if(!$clean||hypot($p[0]-end($clean)[0],$p[1]-end($clean)[1])>1e-7)$clean[]=$p;
                $polygon=$clean;$triangles=BeamPolygon::triangles($polygon);if(!$triangles){$skipped++;continue;}
                // Exclude complete pieces touching protected support or actual native tunnel holes.
                $blocked=false;
                foreach($triangles as $face){
                    $tri=array_map(static fn($id)=>$polygon[$id],$face);$xs=array_column($tri,0);$ys=array_column($tri,1);
                    for($y=max(0,(int)floor(min($ys)/$step));$y<=min($n-2,(int)floor(max($ys)/$step))&&!$blocked;$y++)
                    for($x=max(0,(int)floor(min($xs)/$step));$x<=min($n-2,(int)floor(max($xs)/$step));$x++){
                        $cell=[[$x*$step,$y*$step],[($x+1)*$step,$y*$step],[($x+1)*$step,($y+1)*$step],[$x*$step,($y+1)*$step]];
                        if(!BeamPolygon::overlaps($tri,$cell))continue;
                        foreach([$y*$n+$x,$y*$n+$x+1,($y+1)*$n+$x,($y+1)*$n+$x+1] as $key)
                            if(($protected[$key]??"\0")!=="\0"){$blocked=true;break;}
                        if(($holes[$y*$n+$x]??"\0")==="\xff")$blocked=true;
                        if($blocked)break;
                    }
                    if($blocked)break;
                }
                if($blocked){$skipped++;continue;}
                foreach($polygon as &$p)$p[2]-=self::EDGE_CLEARANCE_M;unset($p);
                foreach($triangles as $face){
                    if(count($faces)>=self::MAX_TRIANGLES)throw new \RuntimeException('Median terrain refinement exceeds the export triangle budget.');
                    $tri=array_map(static fn($id)=>$polygon[$id],$face);
                    $faces[]=array_map($vertex,$tri);
                    $uv[]=array_map(static fn($p)=>[$p[0]/$raster->side,$p[1]/$raster->side],$tri);
                    $cx=array_sum(array_column($tri,0))/3;$cy=array_sum(array_column($tri,1))/3;
                    $cover=BeamRaster::cover($raster->rgb((int)round($cx/$step),(int)round($cy/$step)));
                    $kinds[]=match($cover){0=>'grass',2=>'rock',3=>'gravel',default=>'dirt'};
                }
                $patches++;
            }
        }
        return ['vertices'=>$vertices,'faces'=>$faces,'uv'=>$uv,'kinds'=>$kinds,
            'report'=>['method'=>'Exact pavement-edge constrained median terrain mesh; top-only collision, no pavement overlap or collision sidewalls','patches'=>$patches,'triangles'=>count($faces),'skipped_pieces'=>$skipped,'edge_clearance_m'=>self::EDGE_CLEARANCE_M,'collision_ground_types'=>array_values(array_unique($kinds))]];
    }
    public static function triangles(array $surface): array
    {
        return array_map(static fn($face)=>array_map(static fn($id)=>$surface['vertices'][$id],$face),$surface['faces']);
    }
}
