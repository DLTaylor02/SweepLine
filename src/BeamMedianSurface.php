<?php
declare(strict_types=1);
namespace SweepLine;

/** Locally refined median ground, sharing exact road edges without changing pavement. */
final class BeamMedianSurface
{
    public const EDGE_CLEARANCE_M=0.05;
    public const MAX_TRIANGLES=120000;
    public static function build(array $main,array $profiles,array $tracks,array $spans,BeamRaster $raster,string $protected,string $holes,callable $progress,float $origin=0,float $heightRange=0): array
    {
        $sections=BeamJunctionBoundary::sections($main,$profiles);$chain=[0.];
        for($i=1;$i<count($main);$i++)$chain[]=$chain[$i-1]+hypot($main[$i][0]-$main[$i-1][0],$main[$i][1]-$main[$i-1][1]);
        $vertices=[];$faces=[];$uv=[];$kinds=[];$lookup=[];$skipped=0;$patches=0;$pieces=[];$runs=[];$footprints=[];
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
                $runKey=$track['name'];
                $lastPiece=$pieces?end($pieces):null;
                $run=$lastPiece&&$lastPiece['track']===$runKey&&$lastPiece['last']===$i-1?$lastPiece['run']:count($runs);
                $runs[$run]=isset($runs[$run])?[$runs[$run][0],$pair['along']]:[$prev['along'],$pair['along']];
                $pieces[]=['polygon'=>$polygon,'triangles'=>$triangles,'run'=>$run,'track'=>$runKey,'last'=>$i,'start'=>$prev['along'],'end'=>$pair['along'],
                    'a'=>[($prev['a'][0]+$prev['b'][0])/2,($prev['a'][1]+$prev['b'][1])/2],'b'=>[($pair['a'][0]+$pair['b'][0])/2,($pair['a'][1]+$pair['b'][1])/2]];
                $patches++;
            }
        }
        // Refine at native cell diagonals BEFORE fading: run ends then match the
        // adjacent native triangles exactly, rather than a bilinear approximation.
        foreach($pieces as $piece){
            [$runStart,$runEnd]=$runs[$piece['run']];$dx=$piece['b'][0]-$piece['a'][0];$dy=$piece['b'][1]-$piece['a'][1];$length2=$dx*$dx+$dy*$dy;
            foreach($piece['triangles'] as $face){
                $tri=array_map(static fn($id)=>$piece['polygon'][$id],$face);$footprints[]=$tri;
                for($y=max(0,(int)floor(min(array_column($tri,1))/$step));$y<=min($n-2,(int)floor(max(array_column($tri,1))/$step));$y++)
                for($x=max(0,(int)floor(min(array_column($tri,0))/$step));$x<=min($n-2,(int)floor(max(array_column($tri,0))/$step));$x++){
                    $cell=[[$x*$step,$y*$step],[($x+1)*$step,$y*$step],[($x+1)*$step,($y+1)*$step],[$x*$step,($y+1)*$step]];
                    foreach([[0,1,2],[0,2,3]] as $ids){
                        $poly=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$tri),array_map(static fn($id)=>$cell[$id],$ids));
                        for($j=1;$j<count($poly)-1;$j++){
                            $out=[];foreach([$poly[0],$poly[$j],$poly[$j+1]] as $p){
                                $weights=BeamTerrainContact::weights($p,$tri);$target=-self::EDGE_CLEARANCE_M;foreach($weights as $k=>$w)$target+=$w*$tri[$k][2];
                                $t=$length2>1e-9?max(0,min(1,(($p[0]-$piece['a'][0])*$dx+($p[1]-$piece['a'][1])*$dy)/$length2)):0.;
                                $at=$piece['start']+($piece['end']-$piece['start'])*$t;$fade=max(0,min(1,min($at-$runStart,$runEnd-$at)/20));$fade=$fade*$fade*(3-2*$fade);
                                $ground=self::nativeHeight($raster,$p[0],$p[1],$origin,$heightRange);$out[]=[$p[0],$p[1],$ground*(1-$fade)+$target*$fade];
                            }
                            if(abs(BeamPolygon::cross(...$out))<1e-8)continue;
                            if(count($faces)>=self::MAX_TRIANGLES)throw new \RuntimeException('Median terrain refinement exceeds its triangle budget.');
                            $faces[]=array_map($vertex,$out);$kinds[]='dirt';
                        }
                    }
                }
            }
        }
        return ['footprints'=>$footprints,'vertices'=>$vertices,'faces'=>$faces,'uv'=>$uv,'kinds'=>$kinds,
            'report'=>['method'=>'Exact pavement-edge constrained median terrain mesh; top-only collision, no pavement overlap or collision sidewalls','patches'=>$patches,'triangles'=>count($faces),'skipped_pieces'=>$skipped,'transition_length_m'=>20,'edge_clearance_m'=>self::EDGE_CLEARANCE_M,'collision_ground_types'=>array_values(array_unique($kinds))]];
    }
    public static function nativeHeight(BeamRaster $raster,float $x,float $y,float $origin=0,float $heightRange=0): float
    {
        $step=$raster->side/$raster->size;$gx=max(0,min($raster->size-1,$x/$step));$gy=max(0,min($raster->size-1,$y/$step));$ix=(int)floor($gx);$iy=(int)floor($gy);$tx=$gx-$ix;$ty=$gy-$iy;
        $a=self::nativeCell($raster,$ix,$iy,$origin,$heightRange);$b=self::nativeCell($raster,$ix+1,$iy,$origin,$heightRange);$c=self::nativeCell($raster,$ix+1,$iy+1,$origin,$heightRange);$d=self::nativeCell($raster,$ix,$iy+1,$origin,$heightRange);
        return $tx>=$ty?(1-$tx)*$a+($tx-$ty)*$b+$ty*$c:(1-$ty)*$a+$tx*$c+($ty-$tx)*$d;
    }
    public static function nativeCell(BeamRaster $raster,int $x,int $y,float $origin,float $heightRange=0): float
    {
        $z=$raster->cell($x,$y)-$origin;
        return $heightRange>0?max(0,min(65535,round($z/$heightRange*65536)))*$heightRange/65536:$z;
    }
    /** Replace complete affected cells, retaining original terrain outside the median.
     * This gives one collision surface and exact outer cell seams, including end transitions.
     */
    public static function replaceCells(array $surface,BeamRaster $raster,float $origin,string $layers,callable $progress,float $heightRange=0): array
    {
        $n=$raster->size;$step=$raster->side/$n;$cells=[];
        $footprints=$surface['footprints']??self::triangles($surface);
        foreach($footprints as $i=>$tri){
            for($y=max(0,(int)floor(min(array_column($tri,1))/$step));$y<=min($n-2,(int)floor(max(array_column($tri,1))/$step));$y++)
            for($x=max(0,(int)floor(min(array_column($tri,0))/$step));$x<=min($n-2,(int)floor(max(array_column($tri,0))/$step));$x++)$cells[$y*$n+$x][]=$i;
        }
        $replaced=0;$counter=0;$lookup=[];foreach($surface['vertices'] as $id=>$p)$lookup[implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p))]=$id;
        foreach($cells as $key=>$ids){
            if(++$counter%100===0)$progress(null);if(($layers[$key]??"\0")==="\xff")continue;$x=$key%$n;$y=intdiv($key,$n);$corners=[];
            foreach([[0,0],[1,0],[1,1],[0,1]] as [$dx,$dy])$corners[]=[($x+$dx)*$step,($y+$dy)*$step,self::nativeCell($raster,$x+$dx,$y+$dy,$origin,$heightRange),($x+$dx)/$n,($y+$dy)/$n];
            $parts=[[$corners[0],$corners[1],$corners[2]],[$corners[0],$corners[2],$corners[3]]];$affected=false;
            foreach(array_unique($ids) as $id){
                $cut=$footprints[$id];
                $intersection=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$cut),[$corners[0],$corners[1],$corners[2]]);
                $intersection2=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$cut),[$corners[0],$corners[2],$corners[3]]);
                if(abs(BeamPolygon::area($intersection))+abs(BeamPolygon::area($intersection2))<1e-8)continue;$affected=true;
                if(BeamPolygon::cross(...$cut)<0)$cut=array_reverse($cut);$next=[];
                foreach($parts as $part)array_push($next,...BeamJunctionBoundary::subtract($part,$cut));$parts=$next;
            }
            if(!$affected)continue;$layers[$key]="\xff";$replaced++;
            foreach($parts as $part)for($i=1;$i<count($part)-1;$i++){
                $tri=[$part[0],$part[$i],$part[$i+1]];if(abs(BeamPolygon::cross(...$tri))<1e-8)continue;
                if(count($surface['faces'])>250000)throw new \RuntimeException('Median replacement-cell terrain exceeds its triangle budget.');
                $face=[];foreach($tri as $p){$p=array_slice($p,0,3);$vertexKey=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p));if(!isset($lookup[$vertexKey])){$lookup[$vertexKey]=count($surface['vertices']);$surface['vertices'][]=$p;}$face[]=$lookup[$vertexKey];}
                $surface['faces'][]=$face;$surface['kinds'][]='dirt';
            }
        }
        $surface['layers']=$layers;$surface['report']['replacement_cells']=$replaced;$surface['report']['replacement_triangles']=count($surface['faces']);
        return $surface;
    }
    public static function triangles(array $surface): array
    {
        return array_map(static fn($face)=>array_map(static fn($id)=>$surface['vertices'][$id],$face),$surface['faces']);
    }
}
