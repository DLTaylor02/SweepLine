<?php
declare(strict_types=1);
namespace SweepLine;

/** Check the actual pavement triangles, including mitered edges, against native terrain. */
final class BeamTerrainContact
{
    public static function clear(array $road,array $profiles,BeamRaster $raster,float $origin,string $protected,callable $progress,?array $render=null): array
    {
        $sections=$render?BeamJunctionBoundary::renderSections($road,$profiles,$render):BeamJunctionBoundary::sections($road,$profiles);$triangles=[];
        for($i=1;$i<count($sections);$i++){
            [$a,$b]=$sections[$i-1];[$d,$c]=$sections[$i];
            $triangles[]=[$a,$b,$c];$triangles[]=[$a,$c,$d];
        }
        return self::triangles($triangles,$raster,$origin,$protected,$progress);
    }
    public static function weights(array $p,array $triangle): ?array
    {
        [$a,$b,$c]=$triangle;$den=BeamPolygon::cross($a,$b,$c);if(abs($den)<1e-10)return null;
        $v=BeamPolygon::cross($a,$p,$c)/$den;$w=BeamPolygon::cross($a,$b,$p)/$den;
        return [1-$v-$w,$v,$w];
    }
    /** Convex intersection in XY; heights are evaluated separately on each surface. */
    public static function clip(array $polygon,array $triangle): array
    {
        if(BeamPolygon::cross(...$triangle)<0)$triangle=array_reverse($triangle);
        foreach([0,1,2] as $i){
            $a=$triangle[$i];$b=$triangle[($i+1)%3];$out=[];
            foreach($polygon as $j=>$p){
                $next=$polygon[($j+1)%count($polygon)];$dp=BeamPolygon::cross($a,$b,$p);$dq=BeamPolygon::cross($a,$b,$next);
                if($dp>=-1e-9)$out[]=$p;
                if(($dp>0&&$dq<0)||($dp<0&&$dq>0)){$t=$dp/($dp-$dq);$out[]=[$p[0]+($next[0]-$p[0])*$t,$p[1]+($next[1]-$p[1])*$t];}
            }
            $polygon=$out;if(!$polygon)break;
        }
        return $polygon;
    }
    public static function triangles(array $triangles,BeamRaster $raster,float $origin,string $protected,callable $progress): array
    {
        $n=$raster->size;$step=$raster->side/$n;$lowered=[];$contacts=0;$skipped=0;
        foreach($triangles as $i=>$pavement){
            if($i%100===0)$progress(null);
            if(abs(BeamPolygon::cross(...$pavement))<1e-9)continue;
            $xs=array_column($pavement,0);$ys=array_column($pavement,1);
            for($y=max(0,(int)floor(min($ys)/$step));$y<=min($n-2,(int)floor(max($ys)/$step));$y++)
            for($x=max(0,(int)floor(min($xs)/$step));$x<=min($n-2,(int)floor(max($xs)/$step));$x++){
                $ids=[$y*$n+$x,$y*$n+$x+1,($y+1)*$n+$x,($y+1)*$n+$x+1];
                // Structure support and other protected corridors must never be excavated here.
                foreach($ids as $id)if(($protected[$id]??"\0")!=="\0"){$skipped++;continue 2;}
                $corners=[[$x*$step,$y*$step],[($x+1)*$step,$y*$step],[$x*$step,($y+1)*$step],[($x+1)*$step,($y+1)*$step]];
                // Check both engine diagonal choices, not only a bilinear height sample.
                foreach([[0,1,2],[1,3,2],[0,1,3],[0,3,2]] as $indices){
                    $terrain=array_map(static fn($k)=>$corners[$k],$indices);
                    $polygon=self::clip(array_map(static fn($v)=>array_slice($v,0,2),$pavement),$terrain);
                    foreach($polygon as $point){
                        $pw=self::weights($point,$pavement);$tw=self::weights($point,$terrain);if(!$pw||!$tw||min($pw)<-0.00001||min($tw)<-0.00001)continue;
                        $tw=array_map(static fn($w)=>max(0.,$w),$tw);
                        $target=$origin-0.05;$height=0.;$norm=0.;
                        foreach($pw as $k=>$w)$target+=$w*$pavement[$k][2];
                        foreach($tw as $k=>$w){$id=$ids[$indices[$k]];$height+=$w*$raster->cell($id%$n,intdiv($id,$n));$norm+=$w*$w;}
                        $contacts++;$excess=$height-$target;if($excess<=0.00001||$norm<0.1)continue;
                        foreach($tw as $k=>$w)if($w>0){
                            $id=$ids[$indices[$k]];$delta=$excess*$w/$norm+0.0001;
                            $raster->put($id%$n,intdiv($id,$n),$raster->cell($id%$n,intdiv($id,$n))-$delta);
                            $lowered[$id]=($lowered[$id]??0)+$delta;
                        }
                    }
                }
            }
        }
        return ['method'=>'Exact pavement/terrain triangle intersections; both native diagonals; lower only','contacts'=>$contacts,'lowered_vertices'=>count($lowered),'maximum_cut_m'=>$lowered?max($lowered):0,'protected_cells_skipped'=>$skipped,'clearance_m'=>0.05];
    }
}
