<?php
declare(strict_types=1);
namespace SweepLine;

/** Export-local lane anchoring and estimated median treatment; never surveyed barriers. */
final class BeamMedian
{
    /** Fixed left edge for each contiguous one-way run, including reverse-oriented exports. */
    public static function anchor(array $points,array $profiles): array
    {
        $out=$points;$n=count($points);
        for($first=0;$first<$n;){
            if(empty($profiles[$first]['oneway'])){$first++;continue;}
            $forward=$profiles[$first]['legal_forward']??true;$end=$first;
            while($end+1<$n&&!empty($profiles[$end+1]['oneway'])&&($profiles[$end+1]['legal_forward']??true)===$forward)$end++;
            $base=INF;for($i=$first;$i<=$end;$i++)$base=min($base,$profiles[$i]['width_m']);
            for($i=$first;$i<=$end;$i++){
                $a=$points[max(0,$i-1)];$b=$points[min($n-1,$i+1)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l=hypot($dx,$dy)?:1;
                $offset=($profiles[$i]['width_m']-$base)/2*($forward?1:-1);
                $out[$i][0]+=$dy/$l*$offset;$out[$i][1]-=$dx/$l*$offset;
            }$first=$end+1;
        }return $out;
    }
    /** Keep mapped structure stations when lane anchoring changes the sampled XY chainage. */
    public static function remapSpans(array $spans,array $before,array $after): array
    {
        $old=[0.];$new=[0.];
        for($i=1;$i<count($before);$i++){
            $old[]=$old[$i-1]+hypot($before[$i][0]-$before[$i-1][0],$before[$i][1]-$before[$i-1][1]);
            $new[]=$new[$i-1]+hypot($after[$i][0]-$after[$i-1][0],$after[$i][1]-$after[$i-1][1]);
        }
        foreach($spans as &$span){
            foreach(['start_m','end_m'] as $key)for($i=1;$i<count($old);$i++)if($old[$i]>=$span[$key]-1e-8){
                $t=max(0,min(1,($span[$key]-$old[$i-1])/max(1e-8,$old[$i]-$old[$i-1])));
                $span[$key]=$new[$i-1]+$t*($new[$i]-$new[$i-1]);break;
            }
            $span['length_m']=$span['end_m']-$span['start_m'];
        }unset($span);return $spans;
    }
    private static function covered(float $at,array $spans,float $margin=15): bool
    {
        foreach($spans as $span)if($at>=$span['start_m']-$margin&&$at<=$span['end_m']+$margin)return true;return false;
    }
    /** Pair facing paved edges only on opposed, one-way, non-structure sections. */
    public static function pairs(array $main,array $profiles,array $track,array $spans=[]): array
    {
        $index=new BeamRoadIndex([['points'=>$main]]);$sections=BeamJunctionBoundary::sections($track['road'],$track['profiles']);
        $mainSections=BeamJunctionBoundary::sections($main,$profiles);$out=[];
        foreach($track['road'] as $i=>$p){
            $hit=$index->nearest($p,80);if(!$hit||$hit['raw']<0||$hit['raw']>1)continue;
            $j=$hit['segment'];$t=$hit['fraction'];$profile=$track['profiles'][$i];
            if(empty($profile['oneway'])||empty($profiles[$j]['oneway'])||self::covered($hit['along'],$spans)||self::covered($track['chain'][$i],array_merge($track['bridges'],$track['tunnels'])))continue;
            $a=$track['road'][max(0,$i-1)];$b=$track['road'][min(count($track['road'])-1,$i+1)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l=hypot($dx,$dy)?:1;
            $dot=($dx*$hit['dx']+$dy*$hit['dy'])/$l;
            $legal=($profile['legal_forward']??true)?1:-1;$mainLegal=($profiles[$j]['legal_forward']??true)?1:-1;
            if($dot*$legal*$mainLegal> -0.8)continue;
            $center=[$main[$j-1][0]*(1-$t)+$main[$j][0]*$t,$main[$j-1][1]*(1-$t)+$main[$j][1]*$t];
            $vx=$p[0]-$center[0];$vy=$p[1]-$center[1];$distance=hypot($vx,$vy);if($distance<4)continue;$nx=$vx/$distance;$ny=$vy/$distance;
            $side=($hit['dx']*$vy-$hit['dy']*$vx)>0?0:1;
            $edge=[];foreach([0,1,2] as $axis)$edge[]=$mainSections[$j-1][$side][$axis]*(1-$t)+$mainSections[$j][$side][$axis]*$t;
            $otherSide=(($dx*($center[1]-$p[1])-$dy*($center[0]-$p[0]))>0)?0:1;
            $other=$sections[$i][$otherSide];$gap=($other[0]-$edge[0])*$nx+($other[1]-$edge[1])*$ny;
            $out[$i]=['a'=>$edge,'b'=>$other,'gap'=>$gap,'normal'=>[$nx,$ny],'along'=>$track['chain'][$i]];
        }return $out;
    }
    /** Move only estimated overlapping companion pavement outward, never remove driving lanes.
     * A 1:20 envelope avoids introducing abrupt lateral kinks. Large/structure conflicts remain reported.
     */
    public static function separate(array $main,array $profiles,array $track,array $spans=[]): array
    {
        $pairs=self::pairs($main,$profiles,$track,$spans);$offset=array_fill(0,count($track['road']),0.);$limited=0;
        foreach($pairs as $i=>$pair)if($pair['gap']<0.4){$need=0.4-$pair['gap'];if($need>6){$limited++;continue;}$offset[$i]=$need;}
        for($i=1;$i<count($offset);$i++)$offset[$i]=max($offset[$i],$offset[$i-1]-($track['chain'][$i]-$track['chain'][$i-1])/20);
        for($i=count($offset)-2;$i>=0;$i--)$offset[$i]=max($offset[$i],$offset[$i+1]-($track['chain'][$i+1]-$track['chain'][$i])/20);
        // Fade adjustments at protected spans and unmatched ends rather than creating lateral steps.
        $cap=array_fill(0,count($offset),INF);
        foreach($offset as $i=>$unused)if(!isset($pairs[$i]))$cap[$i]=0.;
        for($i=1;$i<count($cap);$i++)$cap[$i]=min($cap[$i],$cap[$i-1]+($track['chain'][$i]-$track['chain'][$i-1])/20);
        for($i=count($cap)-2;$i>=0;$i--)$cap[$i]=min($cap[$i],$cap[$i+1]+($track['chain'][$i+1]-$track['chain'][$i])/20);
        foreach($offset as $i=>&$value)$value=min($value,$cap[$i]);unset($value);
        $changed=0;$maximum=0.;
        foreach($pairs as $i=>$pair)if($offset[$i]>1e-6){$track['road'][$i][0]+=$pair['normal'][0]*$offset[$i];$track['road'][$i][1]+=$pair['normal'][1]*$offset[$i];$changed++;$maximum=max($maximum,$offset[$i]);}
        $track['median_alignment']=['adjusted_samples'=>$changed,'maximum_outward_shift_m'=>$maximum,'limited_samples'=>$limited,'estimated'=>true];
        return $track;
    }
    /** Fit median-edge support on the native grid; caller rechecks exact pavement clearance. */
    public static function fitEdges(array $targets,BeamRaster $raster,float $origin,string $protected,string $holes,callable $progress): array
    {
        $n=$raster->size;$step=$raster->side/$n;$changed=[];$maximum=0.;$skipped=0;
        foreach($targets as $i=>$point){
            if($i%100===0)$progress(null);
            $gx=$point[0]/$step;$gy=$point[1]/$step;$x=(int)floor($gx);$y=(int)floor($gy);
            if($x<0||$y<0||$x>=$n-1||$y>=$n-1||($holes[$y*$n+$x]??"\0")==="\xff"){$skipped++;continue;}
            $tx=$gx-$x;$ty=$gy-$y;$weights=[(1-$tx)*(1-$ty),$tx*(1-$ty),(1-$tx)*$ty,$tx*$ty];
            $corners=[[$x,$y],[$x+1,$y],[$x,$y+1],[$x+1,$y+1]];$norm=0.;
            foreach($corners as $k=>[$cx,$cy])if(($protected[$cy*$n+$cx]??"\0")==="\0")$norm+=$weights[$k]**2;
            $delta=$point[2]+$origin-0.05-$raster->height($point[0],$point[1]);
            if($delta<=0.05)continue;if($norm<0.1){$skipped++;continue;}
            foreach($corners as $k=>[$cx,$cy])if(($protected[$cy*$n+$cx]??"\0")==="\0"&&$weights[$k]>0){
                $raise=min(0.5,$delta)*$weights[$k]/$norm;
                $raster->put($cx,$cy,$raster->cell($cx,$cy)+$raise);$changed[$cy*$n+$cx]=true;$maximum=max($maximum,$raise);
            }
        }return ['raised_vertices'=>count($changed),'maximum_raise_m'=>$maximum,'protected_or_hole_contacts_skipped'=>$skipped];
    }

    /** Smooth the native median and create closed divider walls for narrow median sections. */
    public static function build(array $main,array $profiles,array $tracks,array $spans,BeamRaster $raster,float $origin,string $mask,callable $progress): array
    {
        $walls=[];$report=[];$targets=[];$step=$raster->side/$raster->size;$changed=[];
        foreach($tracks as $track){
            $pairs=self::pairs($main,$profiles,$track,$spans);foreach($pairs as $pair)if($pair['gap']>0.8&&$pair['gap']<=40){$targets[]=$pair['a'];$targets[]=$pair['b'];}$vertices=[];$faces=[];$previous=null;$wallSegments=0;
            foreach($pairs as $i=>$pair){
                if($i%100===0)$progress(null);
                if($previous!==null&&$i===$previous[0]+1){
                    $prev=$previous[1];
                    if(min($pair['gap'],$prev['gap'])>=0&&max($pair['gap'],$prev['gap'])<=40){
                        // Include the supporting cell corners beyond both paved edges. Merely
                        // filling vertices inside the median leaves sub-cell strips unsupported.
                        $support=[];
                        foreach([$prev,$pair] as $ring){
                            $a=$ring['a'];$b=$ring['b'];$width=hypot($b[0]-$a[0],$b[1]-$a[1]);
                            $pad=sqrt(2)*$step;$factor=$pad/max(0.01,$width);
                            $support[]=[
                                [$a[0]-($b[0]-$a[0])*$factor,$a[1]-($b[1]-$a[1])*$factor,$a[2]],
                                [$b[0]+($b[0]-$a[0])*$factor,$b[1]+($b[1]-$a[1])*$factor,$b[2]]
                            ];
                        }
                        $quad=[$support[0][0],$support[0][1],$support[1][1],$support[1][0]];
                        $core=[$prev['a'],$prev['b'],$pair['b'],$pair['a']];
                        foreach([[0,1,2],[0,2,3]] as $ids){
                            $tri=array_map(static fn($id)=>$quad[$id],$ids);$area=BeamPolygon::cross(...$tri);if(abs($area)<1e-8)continue;
                            $xs=array_column($tri,0);$ys=array_column($tri,1);
                            for($y=max(0,(int)floor(min($ys)/$step));$y<=min($raster->size-1,(int)ceil(max($ys)/$step));$y++)
                            for($x=max(0,(int)floor(min($xs)/$step));$x<=min($raster->size-1,(int)ceil(max($xs)/$step));$x++){
                                if(($mask[$y*$raster->size+$x]??"\0")!=="\0")continue;$p=[$x*$step,$y*$step];
                                $w=[BeamPolygon::cross($tri[1],$tri[2],$p)/$area,BeamPolygon::cross($tri[2],$tri[0],$p)/$area,BeamPolygon::cross($tri[0],$tri[1],$p)/$area];
                                if(min($w)<-1e-8)continue;
                                // The extended strip supports a flat inner shoulder on each road;
                                // interpolate only through the actual median, not the padded width.
                                $near=BeamTerrainContact::weights($p,array_map(static fn($id)=>$core[$id],$ids));
                                $z=$origin-0.05;
                                if($near){
                                    $left=$near[0]*($ids[0]===0||$ids[0]===3?1:0)+$near[1]*($ids[1]===0||$ids[1]===3?1:0)+$near[2]*($ids[2]===0||$ids[2]===3?1:0);
                                    $across=max(0,min(1,1-$left));
                                    $axisA=$prev['a'];$axisB=$pair['a'];$dx=$axisB[0]-$axisA[0];$dy=$axisB[1]-$axisA[1];$length2=$dx*$dx+$dy*$dy;
                                    $along=$length2>1e-8?max(0,min(1,(($p[0]-$axisA[0])*$dx+($p[1]-$axisA[1])*$dy)/$length2)):0.;
                                    $za=$prev['a'][2]*(1-$along)+$pair['a'][2]*$along;
                                    $zb=$prev['b'][2]*(1-$along)+$pair['b'][2]*$along;
                                    $z+=(1-$across)*$za+$across*$zb;
                                }else foreach($w as $k=>$weight)$z+=$weight*$tri[$k][2];
                                $raster->put($x,$y,$z);$changed[$y*$raster->size+$x]=true;
                            }
                        }
                        if(max($pair['gap'],$prev['gap'])<=0.8){
                            $base=count($vertices);
                            foreach([$prev,$pair] as $ring){
                                $a=$ring['a'];$b=$ring['b'];$nx=$ring['normal'][0];$ny=$ring['normal'][1];
                                $cx=($a[0]+$b[0])/2;$cy=($a[1]+$b[1])/2;$top=max($a[2],$b[2])+1.0;$bottom=min($a[2],$b[2])-0.3;
                                array_push($vertices,[$cx-$nx*0.15,$cy-$ny*0.15,$bottom],[$cx+$nx*0.15,$cy+$ny*0.15,$bottom],[$cx+$nx*0.15,$cy+$ny*0.15,$top],[$cx-$nx*0.15,$cy-$ny*0.15,$top]);
                            }
                            foreach([[0,3,2],[0,2,1],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]] as $face)$faces[]=array_map(static fn($v)=>$v+$base,$face);
                            $wallSegments++;
                        }
                    }
                }$previous=[$i,$pair];
            }
            if($faces)$walls[]=['key'=>$track['name'],'vertices'=>$vertices,'faces'=>$faces];
            $report[]=['track'=>$track['name'],'paired_samples'=>count($pairs),'divider_segments'=>$wallSegments,'alignment'=>$track['median_alignment']??null];
        }return ['walls'=>$walls,'edge_targets'=>$targets,'report'=>['tracks'=>$report,'terrain_vertices'=>count($changed),'support_padding_m'=>sqrt(2)*$step,'divider_gap_threshold_m'=>0.8,'method'=>'Estimated facing-edge medians; closed concrete divider for narrow gaps, native terrain interpolation for open gaps; structures excluded']];
    }
}
