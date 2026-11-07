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
            foreach([[$a,$b,$c],[$a,$c,$d]] as $triangle)if(BeamPolygon::cross(...$triangle)>1e-8)$triangles[]=$triangle;
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
    public static function triangles(array $triangles,BeamRaster $raster,float $origin,string $protected,callable $progress,bool $partialCells=false,string $holes=''): array
    {
        $n=$raster->size;$step=$raster->side/$n;$lowered=[];$contacts=0;$skipped=0;$blocked=0;$holeSkipped=0;
        foreach($triangles as $i=>$pavement){
            if($i%100===0)$progress(null);
            if(abs(BeamPolygon::cross(...$pavement))<1e-9)continue;
            $xs=array_column($pavement,0);$ys=array_column($pavement,1);
            for($y=max(0,(int)floor(min($ys)/$step));$y<=min($n-2,(int)floor(max($ys)/$step));$y++)
            for($x=max(0,(int)floor(min($xs)/$step));$x<=min($n-2,(int)floor(max($xs)/$step));$x++){
                if(($holes[$y*$n+$x]??"\0")==="\xff"){$holeSkipped++;continue;}
                $ids=[$y*$n+$x,$y*$n+$x+1,($y+1)*$n+$x,($y+1)*$n+$x+1];
                // Structure support and other protected corridors must never be excavated here.
                $editable=[];foreach($ids as $id)$editable[]=($protected[$id]??"\0")==="\0";
                if(!in_array(true,$editable,true)||(!$partialCells&&in_array(false,$editable,true))){$skipped++;continue;}
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
                        foreach($tw as $k=>$w){$id=$ids[$indices[$k]];$height+=$w*$raster->cell($id%$n,intdiv($id,$n));if($editable[$indices[$k]])$norm+=$w*$w;}
                        $contacts++;$excess=$height-$target;if($excess<=0.00001)continue;if($norm<0.1){$blocked++;continue;}
                        foreach($tw as $k=>$w)if($w>0&&$editable[$indices[$k]]){
                            $id=$ids[$indices[$k]];$delta=$excess*$w/$norm+0.0001;
                            $raster->put($id%$n,intdiv($id,$n),$raster->cell($id%$n,intdiv($id,$n))-$delta);
                            $lowered[$id]=($lowered[$id]??0)+$delta;
                        }
                    }
                }
            }
        }
        return ['method'=>'Exact pavement/terrain triangle intersections; both native diagonals; lower only','contacts'=>$contacts,'lowered_vertices'=>count($lowered),'maximum_cut_m'=>$lowered?max($lowered):0,'protected_cells_skipped'=>$skipped,'protected_contacts_unresolved'=>$blocked,'partial_cells'=>$partialCells,'tunnel_hole_cells_skipped'=>$holeSkipped,'clearance_m'=>0.05];
    }

    /** Final contact repair uses actual structure footprints, not shoulder-influence buffers. */
    public static function repairCorridors(array $surfaces,BeamRaster $raster,float $origin,callable $progress,string $holes=''): array
    {
        $n=$raster->size;$step=$raster->side/$n;$protected=str_repeat("\0",$n*$n);
        foreach($surfaces as $surface){
            $road=$surface['road'];$profiles=$surface['profiles'];$spans=self::protectedSpans($surface['spans']??[],max(2.,2*$step));$along=0.;
            for($i=1;$i<count($road);$i++){
                if($i%100===0)$progress(null);$a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);$start=$along;$along+=$length;if($length<1e-6)continue;
                $near=[];foreach($spans as $span)if($span['start_m']<=$along&&$span['end_m']>=$start)$near[]=$span;
                if(!$near)continue;$radius=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+0.5;
                for($y=max(0,(int)floor((min($a[1],$b[1])-$radius)/$step));$y<=min($n-1,(int)ceil((max($a[1],$b[1])+$radius)/$step));$y++)
                for($x=max(0,(int)floor((min($a[0],$b[0])-$radius)/$step));$x<=min($n-1,(int)ceil((max($a[0],$b[0])+$radius)/$step));$x++){
                    $t=(($x*$step-$a[0])*$dx+($y*$step-$a[1])*$dy)/($length*$length);if($t<0||$t>1)continue;
                    $at=$start+$t*$length;$covered=false;foreach($near as $span)if($at>=$span['start_m']&&$at<=$span['end_m']){$covered=true;break;}
                    if(!$covered)continue;$half=($profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t)/2+0.5;
                    if(hypot($x*$step-$a[0]-$dx*$t,$y*$step-$a[1]-$dy*$t)<=$half)$protected[$y*$n+$x]="\1";
                }
            }
        }
        $reports=[];
        foreach($surfaces as $surface){
            $triangles=self::ordinaryTriangles($surface['road'],$surface['profiles'],self::protectedSpans($surface['spans']??[],max(2.,2*$step)),$surface['render']??null);
            $triangles=self::visibleTriangles($triangles,$surface['obstacles']??[]);
            $reports[$surface['name']]=self::triangles($triangles,$raster,$origin,$protected,$progress,true,$holes);
        }
        return ['method'=>'Exact ordinary-pavement contact with only actual structure-footprint vertices frozen; mixed terrain cells remain editable','protected_vertices'=>substr_count($protected,"\1"),'abutment_contact_band_m'=>max(2.,2*$step),'roads'=>$reports];
    }
    /** Clip by chainage inside each existing pavement triangle, retaining its exact plane. */
    public static function ordinaryTriangles(array $road,array $profiles,array $spans,?array $render=null): array
    {
        $points=$render??$road;$sections=$render?BeamJunctionBoundary::renderSections($road,$profiles,$render):BeamJunctionBoundary::sections($road,$profiles);$along=0.;$out=[];
        $clipStation=static function(array $poly,float $boundary,bool $after):array{
            $result=[];
            foreach($poly as $i=>$p){$q=$poly[($i+1)%count($poly)];$a=$after?$p[3]-$boundary:$boundary-$p[3];$b=$after?$q[3]-$boundary:$boundary-$q[3];
                if($a>=-1e-8)$result[]=$p;
                if(($a>0&&$b<0)||($a<0&&$b>0)){$t=$a/($a-$b);$v=[];foreach([0,1,2,3] as $axis)$v[]=$p[$axis]+($q[$axis]-$p[$axis])*$t;$result[]=$v;}
            }return $result;
        };
        for($i=1;$i<count($points);$i++){
            $start=$along;$along+=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);if($along-$start<1e-8)continue;
            $intervals=[[$start,$along]];
            foreach($spans as $span){$next=[];foreach($intervals as [$lo,$hi]){
                if($span['end_m']<=$lo||$span['start_m']>=$hi){$next[]=[$lo,$hi];continue;}
                if($span['start_m']>$lo)$next[]=[$lo,min($hi,$span['start_m'])];
                if($span['end_m']<$hi)$next[]=[max($lo,$span['end_m']),$hi];
            }$intervals=$next;}
            [$a,$b]=$sections[$i-1];[$d,$c]=$sections[$i];$a[]=$start;$b[]=$start;$c[]=$along;$d[]=$along;
            foreach(array_filter([[$a,$b,$c],[$a,$c,$d]],static fn($triangle)=>BeamPolygon::cross(...$triangle)>1e-8) as $triangle)foreach($intervals as [$lo,$hi]){
                $poly=$clipStation($clipStation($triangle,$lo,true),$hi,false);
                for($j=1;$j<count($poly)-1;$j++)$out[]=[array_slice($poly[0],0,3),array_slice($poly[$j],0,3),array_slice($poly[$j+1],0,3)];
            }
        }
        return $out;
    }


    /** Bridge landing contact may be corrected over two cells; preserve deep deck valleys and all tunnel roofs. */
    private static function protectedSpans(array $spans,float $band): array
    {
        $out=[];foreach($spans as $span){
            if(!isset($span['clearance_m'])){$span['start_m']+=$band;$span['end_m']-=$band;}
            if($span['end_m']>$span['start_m'])$out[]=$span;
        }return $out;
    }


    /** Use the same priority clipping as the rendered counterpart; hidden pavement must not cut terrain. */
    private static function visibleTriangles(array $triangles,array $obstacles): array
    {
        foreach($obstacles as $obstacle){
            $main=$obstacle['road'];$profiles=$obstacle['profiles'];$index=new BeamRoadIndex([['points'=>$main]]);$half=max(array_column($profiles,'width_m'))/2;$out=[];
            foreach($triangles as $triangle){
                $center=[($triangle[0][0]+$triangle[1][0]+$triangle[2][0])/3,($triangle[0][1]+$triangle[1][1]+$triangle[2][1])/3];
                $radius=0.;foreach($triangle as $p)$radius=max($radius,hypot($p[0]-$center[0],$p[1]-$center[1]));
                if(!$index->nearest($center,$half+$radius+0.01)){$out[]=$triangle;continue;}
                [$vertices,$faces]=BeamJunctionBoundary::trim($triangle,[['v'=>[0,1,2],'uv'=>[[0,0],[1,0],[0,1]],'material'=>'contact']],$main,$profiles,null,$obstacle['weld_boundary']??true);
                foreach($faces as $face)$out[]=array_map(static fn($id)=>$vertices[$id],$face['v']);
            }
            $triangles=$out;
        }
        return $triangles;
    }

}
