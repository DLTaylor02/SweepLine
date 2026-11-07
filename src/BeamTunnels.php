<?php
declare(strict_types=1);
namespace SweepLine;

/** Simple single-bore tunnel estimates, not surveyed underground engineering. */
final class BeamTunnels
{
    public const MAX_LENGTH_M=1500;
    public const MAX_PATCH_CELLS=30000;
    public static function spans(array $road,array $sources): array
    {
        $segments=[];foreach($sources as $source){$tags=$source['tags']??[];if(in_array($tags['tunnel']??'no',['no','false','0'],true))continue;
            for($i=1;$i<count($source['points']);$i++)$segments[]=[$source['points'][$i-1],$source['points'][$i],$tags];}
        if(!$segments)return [];$hits=[];$chain=0.0;$checks=0;
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);if($length<0.001)continue;
            foreach($segments as [$c,$d,$tags]){
                if(++$checks>2000000)throw new \InvalidArgumentException('Tunnel geometry is too complex.');
                if(max($c[0],$d[0])<min($a[0],$b[0])-0.1||min($c[0],$d[0])>max($a[0],$b[0])+0.1||max($c[1],$d[1])<min($a[1],$b[1])-0.1||min($c[1],$d[1])>max($a[1],$b[1])+0.1)continue;
                if(abs(($c[0]-$a[0])*$dy-($c[1]-$a[1])*$dx)/$length>0.1||abs(($d[0]-$a[0])*$dy-($d[1]-$a[1])*$dx)/$length>0.1)continue;
                $tc=(($c[0]-$a[0])*$dx+($c[1]-$a[1])*$dy)/$length;$td=(($d[0]-$a[0])*$dx+($d[1]-$a[1])*$dy)/$length;
                $lo=max(0,min($tc,$td));$hi=min($length,max($tc,$td));if($hi-$lo<0.01)continue;
                if(($tags['tunnel']??'')!=='yes'||!in_array($tags['bridge']??'no',['no','false','0'],true)||isset($tags['junction']))
                    throw new \InvalidArgumentException('Only ordinary single-bore tunnel=yes road tunnels are currently supported; passages, culverts and complex structures are excluded.');
                $height=4.8;$evidence='generic_estimate';
                if(preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(m|ft|feet)?$/i',(string)($tags['maxheight']??''),$m)){
                    $posted=(float)$m[1]*(in_array(strtolower($m[2]??''),['ft','feet'],true)?0.3048:1);
                    if($posted<2.4||$posted>8)throw new \InvalidArgumentException('Tunnel clearance is outside the supported 2.4-8 metre range.');
                    $height=$posted+0.25;$evidence='posted_limit_plus_estimated_margin';
                }
                $hits[]=['start_m'=>$chain+$lo,'end_m'=>$chain+$hi,'clearance_m'=>$height,'clearance_source'=>$evidence];
            }$chain+=$length;
        }
        usort($hits,static fn($a,$b)=>$a['start_m']<=>$b['start_m']);$out=[];
        foreach($hits as $hit){$last=count($out)-1;if($last>=0&&$hit['start_m']<=$out[$last]['end_m']+0.2){$out[$last]['end_m']=max($out[$last]['end_m'],$hit['end_m']);if($hit['clearance_m']<$out[$last]['clearance_m']){$out[$last]['clearance_m']=$hit['clearance_m'];$out[$last]['clearance_source']=$hit['clearance_source'];}}else $out[]=$hit;}
        if(count($out)>4)throw new \InvalidArgumentException('This export exceeds the four-tunnel limit.');
        foreach($out as $index=>&$span){
            if($index>0&&$span['start_m']-$out[$index-1]['end_m']<100)throw new \InvalidArgumentException('Tunnel approaches overlap; connected tunnel complexes require surveyed profiles.');$span['length_m']=$span['end_m']-$span['start_m'];
            if($span['length_m']<8||$span['length_m']>self::MAX_LENGTH_M)throw new \InvalidArgumentException('Supported tunnels must be 8-1500 metres long.');
            if($span['start_m']<50||$chain-$span['end_m']<50)throw new \InvalidArgumentException('A tunnel requires at least 50 metres of selected approach road beyond each portal. Extend the selected stretch.');
            $span['estimated']=true;
        }unset($span);
        if($out)foreach(BeamStructures::spans($road,$sources,true) as $bridge)foreach($out as $tunnel)
            if($bridge['end_m']+100>$tunnel['start_m']&&$bridge['start_m']-100<$tunnel['end_m'])
                throw new \InvalidArgumentException('A bridge overlaps the tunnel approach; this combined structure requires surveyed elevations.');
        return $out;
    }
    public static function samples(array $points,array $chain,array $heights,array $spans,BeamRaster $raster): array
    {
        foreach($spans as $span){
            $lo=$span['start_m']-50;$hi=$span['end_m']+50;$a=BeamStructures::at($points,$lo);$b=BeamStructures::at($points,$hi);
            $za=$raster->roadHeight(...$a);$zb=$raster->roadHeight(...$b);
            if(abs($zb-$za)/($hi-$lo)>0.12)throw new \RuntimeException('Tunnel approach elevations imply a grade above 12%; an underground profile cannot be estimated reliably.');
            foreach($chain as $i=>$at)if($at>=$lo&&$at<=$hi)$heights[$i]=$za+($zb-$za)*(($at-$lo)/($hi-$lo));
        }return $heights;
    }
    private static function station(array $road,array $profiles,float $at): array
    {
        $chain=0.0;for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);if($length<0.001)continue;
            if($chain+$length>=$at){$t=max(0,min(1,($at-$chain)/$length));return ['point'=>[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t,$a[2]+($b[2]-$a[2])*$t],'width'=>$profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t];}$chain+=$length;
        }throw new \RuntimeException('Tunnel station lies outside the selected road.');
    }
    /** Convex-prism subtraction preserves interpolated terrain positions and UVs. */
    public static function subtract(array $polygon,array $planes,?array &$removed=null): array
    {
        $out=[];$inside=$polygon;
        foreach($planes as $plane){
            if(count($inside)<3)break;$keep=[];$reject=[];
            $distance=static fn($p)=>$plane[0]*$p[0]+$plane[1]*$p[1]+$plane[2]*$p[2]+$plane[3];
            foreach($inside as $i=>$p){
                $q=$inside[($i+1)%count($inside)];$dp=$distance($p);$dq=$distance($q);if(abs($dp)<1e-8)$dp=0;if(abs($dq)<1e-8)$dq=0;
                if($dp>=0)$keep[]=$p;if($dp<=0)$reject[]=$p;
                if(($dp>0&&$dq<0)||($dp<0&&$dq>0)){$t=$dp/($dp-$dq);$v=[];foreach($p as $axis=>$value)$v[]=$value+($q[$axis]-$value)*$t;$keep[]=$v;$reject[]=$v;}
            }
            if(count($reject)>=3&&array_filter($inside,static fn($p)=>$distance($p)<-1e-8))$out[]=$reject;$inside=$keep;
        }$removed=count($inside)>=3?$inside:[];return $out;
    }
    public static function build(array $road,array $profiles,array $spans,BeamRaster $raster,float $origin,string $layers,string $material,string $terrainMaterial,callable $progress,array $additional=[],float $heightRange=0,bool $deferTerrainMesh=false): array
    {
        $work=[['road'=>$road,'profiles'=>$profiles,'spans'=>$spans]];$reportedSpans=$spans;
        foreach($additional as $track){$work[]=['road'=>$track['road'],'profiles'=>$track['profiles'],'spans'=>$track['tunnels']];foreach($track['tunnels'] as $s)$reportedSpans[]=$s+['track'=>$track['name']];}
        if(count($reportedSpans)>4)throw new \RuntimeException('Combined carriageways exceed the four-tunnel export limit.');
        if(!$reportedSpans)return ['layers'=>$layers,'shell'=>null,'terrain'=>null,'objects'=>[],'report'=>['spans'=>[],'hole_cells'=>0]];
        $n=$raster->size;$step=$raster->side/$n;$vertices=[];$faces=[];$uv=[];$cells=[];$volumes=[];$lights=[];$exclusions=[];
        $quad=static function($a,$b,$c,$d)use(&$vertices,&$faces,&$uv){
            $base=count($vertices);array_push($vertices,$a,$b,$c,$d);$faces[]=[$base,$base+1,$base+2];$faces[]=[$base,$base+2,$base+3];
            $length=hypot($b[0]-$a[0],$b[1]-$a[1]);$uv[]=[[0,0],[$length/4,0],[$length/4,1]];$uv[]=[[0,0],[$length/4,1],[0,1]];
        };
        foreach($work as $trackIndex=>$track){$road=$track['road'];$profiles=$track['profiles'];
        foreach($track['spans'] as $number=>$span){
            $progress(null);$start=$span['start_m']-2;$end=$span['end_m']+2;$stations=[];$at=$start;
            while($at<$end){$stations[]=self::station($road,$profiles,$at);$at+=2;}$stations[]=self::station($road,$profiles,$end);
            $points=array_column($stations,'point');$widths=array_map(static fn($s)=>['width_m'=>$s['width']+1.5],$stations);
            foreach($stations as $i=>$station)if($station['width']>20)throw new \RuntimeException('Tunnel pavement wider than 20 metres is not supported.');
            $rings=[];
            foreach($stations as $i=>$s){
                [$left,$right]=BeamPavement::section($points,$widths,$i);$p=$s['point'];$half=$s['width']/2+0.75;
                $dx=($right[0]-$left[0])/(2*$half);$dy=($right[1]-$left[1])/(2*$half);$h=$span['clearance_m'];
                $profile=[[-$half-0.35,-0.3],[-$half,-0.3],[-$half,$h],[$half,$h],[$half,-0.3],[$half+0.35,-0.3],[$half+0.35,$h+0.35],[-$half-0.35,$h+0.35]];
                $ring=[];foreach($profile as [$across,$z])$ring[]=[$p[0]+$dx*$across,$p[1]+$dy*$across,$p[2]+$z];$rings[]=$ring;
                if($i){
                    $previous=$rings[$i-1];for($j=0;$j<8;$j++){$k=($j+1)%8;$quad($previous[$j],$ring[$j],$ring[$k],$previous[$k]);}
                    // Walkway bands meet the road edge without placing another floor under driving lanes.
                    $a=$points[$i-1];$b=$p;$oldHalf=$stations[$i-1]['width']/2;$newHalf=$s['width']/2;
                    foreach([-1,1] as $sign){$da=[$previous[3][0]-$previous[2][0],$previous[3][1]-$previous[2][1]];$dl=hypot(...$da);$da=[$da[0]/$dl,$da[1]/$dl];
                        $band=[[$a[0]+$da[0]*$oldHalf*$sign,$a[1]+$da[1]*$oldHalf*$sign,$a[2]-0.02],
                            [$b[0]+$dx*$newHalf*$sign,$b[1]+$dy*$newHalf*$sign,$b[2]-0.02],
                            [$b[0]+$dx*$half*$sign,$b[1]+$dy*$half*$sign,$b[2]-0.02],
                            [$a[0]+$da[0]*($oldHalf+0.75)*$sign,$a[1]+$da[1]*($oldHalf+0.75)*$sign,$a[2]-0.02]];if($sign>0)$band=array_reverse($band);$quad(...$band);}
                }
                if($i%20===10)$lights[]=['class'=>'PointLight','name'=>'tunnel_'.$trackIndex.'_'.$number.'_light_'.$i,'__parent'=>'MissionGroup','position'=>[$p[0],$p[1],$p[2]+$h-0.3],'radius'=>24,'brightness'=>0.8,'color'=>[0.9,0.86,0.78,1],'castShadows'=>false];
            }
            foreach([0,count($rings)-1] as $endIndex){
                $p=$stations[$endIndex]['point'];$half=$stations[$endIndex]['width']/2+0.75;$h=$span['clearance_m'];
                $profile=[[-$half-0.35,-0.3],[-$half,-0.3],[-$half,$h],[$half,$h],[$half,-0.3],[$half+0.35,-0.3],[$half+0.35,$h+0.35],[-$half-0.35,$h+0.35]];
                foreach(BeamPolygon::triangles($profile) as $t){$base=count($vertices);foreach($t as $j)$vertices[]=$rings[$endIndex][$j];$faces[]=$endIndex===0?[$base,$base+1,$base+2]:[$base+2,$base+1,$base];$uv[]=[[0,0],[1,0],[1,1]];}
            }
            $exclusions[]=['points'=>array_slice($points,0,min(12,count($points))),'width_m'=>$stations[0]['width']+2*$step+3];
            $exclusions[]=['points'=>array_slice($points,-min(12,count($points))),'width_m'=>$stations[count($stations)-1]['width']+2*$step+3];
            // Extend excavation into the approaches so protected portal cells cannot block the entrance.
            $cutDistances=[$span['start_m']-40,$span['end_m']+40];
            for($at=$span['start_m']-40;$at<$span['end_m']+40;$at+=2)$cutDistances[]=$at;
            // Keep exact pavement heading/grade breaks; a uniform 2 m chord can
            // cut across an original bend and leave ground above the road edge.
            $at=0.;foreach($road as $i=>$point){
                if($i)$at+=hypot($point[0]-$road[$i-1][0],$point[1]-$road[$i-1][1]);
                if($at>$span['start_m']-40&&$at<$span['end_m']+40)$cutDistances[]=$at;
            }
            sort($cutDistances,SORT_NUMERIC);$cutStations=[];$previous=-INF;
            foreach($cutDistances as $at){if($at-$previous<1e-7)continue;$cutStations[]=self::station($road,$profiles,$at);$previous=$at;}
            for($i=1;$i<count($cutStations);$i++){
                $a=$cutStations[$i-1]['point'];$b=$cutStations[$i]['point'];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);if($length<0.001)continue;$ux=$dx/$length;$uy=$dy/$length;
                $ha=$cutStations[$i-1]['width']/2+0.9;$hb=$cutStations[$i]['width']/2+0.9;$slope=($b[2]-$a[2])/$length;$change=($hb-$ha)/$length;
                $planes=[[$ux,$uy,0,-$ux*$a[0]-$uy*$a[1]],[-$ux,-$uy,0,$ux*$b[0]+$uy*$b[1]],
                    [$change*$ux-$uy,$change*$uy+$ux,0,$ha-($change*$ux-$uy)*$a[0]-($change*$uy+$ux)*$a[1]],
                    [$change*$ux+$uy,$change*$uy-$ux,0,$ha-($change*$ux+$uy)*$a[0]-($change*$uy-$ux)*$a[1]],
                    [$slope*$ux,$slope*$uy,-1,$a[2]+$span['clearance_m']+0.15-$slope*($ux*$a[0]+$uy*$a[1])],
                    // Preserve terrain already below pavement; only excavate the open bore.
                    [-$slope*$ux,-$slope*$uy,1,-$a[2]+0.025+$slope*($ux*$a[0]+$uy*$a[1])]];
                // Independent square-ended strips leave wedges at bends. Extend each
                // internal end by the offset needed for its neighboring heading.
                $padding=static function(array $a,array $b,array $c,float $width):float{
                    $u=[$b[0]-$a[0],$b[1]-$a[1]];$v=[$c[0]-$b[0],$c[1]-$b[1]];
                    $length=hypot(...$u)*hypot(...$v);if($length<1e-9)return 0.;
                    $cos=max(-0.99,min(1,($u[0]*$v[0]+$u[1]*$v[1])/$length));
                    return min(20,$width*sqrt(max(0,(1-$cos)/(1+$cos))));
                };
                $startPad=$i>1?$padding($cutStations[$i-2]['point'],$a,$b,max($ha,$hb)):0.;
                $endPad=$i<count($cutStations)-1?$padding($a,$b,$cutStations[$i+1]['point'],max($ha,$hb)):0.;
                $planes[0][3]+=$startPad;$planes[1][3]+=$endPad;
                $id=count($volumes);$volumes[]=$planes;$margin=max($ha,$hb)+$step+max($startPad,$endPad);
                for($y=max(0,(int)floor((min($a[1],$b[1])-$margin)/$step));$y<=min($n-2,(int)floor((max($a[1],$b[1])+$margin)/$step));$y++)
                    for($x=max(0,(int)floor((min($a[0],$b[0])-$margin)/$step));$x<=min($n-2,(int)floor((max($a[0],$b[0])+$margin)/$step));$x++){
                        if(BeamLanes::distance([($x+0.5)*$step,($y+0.5)*$step],$a,$b)[0]>max($ha,$hb)+sqrt(2)*$step+max($startPad,$endPad))continue;
                        $cells[$y*$n+$x][]=$id;if(count($cells)>self::MAX_PATCH_CELLS)throw new \RuntimeException('Tunnel terrain patch exceeds the export cell budget.');
                    }
            }
        }
        }
        $terrainVertices=[];$terrainFaces=[];$terrainUv=[];$counter=0;$floorPieces=0;$walls=[];
        foreach($cells as $key=>$ids){
            if(++$counter%100===0)$progress(null);$x=$key%$n;$y=intdiv($key,$n);$layers[$key]=chr(255);$corners=[];
            foreach([[0,0],[1,0],[1,1],[0,1]] as [$ox,$oy]){$wx=($x+$ox)*$step;$wy=($y+$oy)*$step;$corners[]=[$wx,$wy,BeamMedianSurface::nativeCell($raster,$x+$ox,$y+$oy,$origin,$heightRange),$wx/$raster->side,$wy/$raster->side];}
            foreach([[$corners[0],$corners[1],$corners[2]],[$corners[0],$corners[2],$corners[3]]] as $triangle){
                $pieces=[$triangle];foreach(array_unique($ids) as $id){
                    $next=[];foreach($pieces as $piece){
                        $removed=[];array_push($next,...self::subtract($piece,$volumes[$id],$removed));
                        if($removed){
                            // A tunnel hole must still have ground below its open volume.
                            // Reuse clipped UVs and keep the floor 5 cm below pavement.
                            $floor=$volumes[$id][5];
                            foreach($removed as $edgeIndex=>$a){
                                $b=$removed[($edgeIndex+1)%count($removed)];
                                $boundary=false;foreach(array_slice($volumes[$id],0,5) as $plane){
                                    $da=$plane[0]*$a[0]+$plane[1]*$a[1]+$plane[2]*$a[2]+$plane[3];$db=$plane[0]*$b[0]+$plane[1]*$b[1]+$plane[2]*$b[2]+$plane[3];
                                    if(abs($da)<1e-6&&abs($db)<1e-6){$boundary=true;break;}
                                }
                                if(!$boundary||hypot($b[0]-$a[0],$b[1]-$a[1])<1e-7)continue;
                                $lowA=$a;$lowB=$b;
                                $lowA[2]=-($floor[0]*$a[0]+$floor[1]*$a[1]+$floor[3])-0.025;
                                $lowB[2]=-($floor[0]*$b[0]+$floor[1]*$b[1]+$floor[3])-0.025;
                                $walls[]=['polygon'=>[$a,$b,$lowB,$lowA],'volume'=>$id];
                            }
                            foreach($removed as &$p)$p[2]=min($p[2],-($floor[0]*$p[0]+$floor[1]*$p[1]+$floor[3])-0.025);
                            unset($p);$next[]=$removed;$floorPieces++;
                        }
                    }$pieces=$next;if(!$pieces)break;
                }
                foreach($pieces as $piece)for($j=1;$j<count($piece)-1;$j++){
                    $points3=[$piece[0],$piece[$j],$piece[$j+1]];if(abs(BeamPolygon::cross(...$points3))<1e-8)continue;
                    $base=count($terrainVertices);foreach($points3 as $p)$terrainVertices[]=array_slice($p,0,3);$terrainFaces[]=[$base,$base+1,$base+2];$terrainUv[]=array_map(static fn($p)=>array_slice($p,3,2),$points3);
                }
            }
        }
        // Close excavation boundaries, trimming portal rims around the open driving bore.
        // Clip away internal walls where adjoining excavation segments overlap.
        $wallFaces=0;
        foreach($walls as $index=>$wall){
            if($index%100===0)$progress(null);
            $polygon=$wall['polygon'];$cx=(int)floor(($polygon[0][0]+$polygon[1][0])/2/$step);$cy=(int)floor(($polygon[0][1]+$polygon[1][1])/2/$step);$neighbors=[];
            for($oy=-1;$oy<=1;$oy++)for($ox=-1;$ox<=1;$ox++){
                $x=$cx+$ox;$y=$cy+$oy;if($x<0||$y<0||$x>=$n||$y>=$n)continue;
                foreach($cells[$y*$n+$x]??[] as $id)if($id!==$wall['volume'])$neighbors[$id]=true;
            }
            $pieces=[$polygon];
            foreach(array_keys($neighbors) as $id){
                $next=[];foreach($pieces as $piece)array_push($next,...self::subtract($piece,array_slice($volumes[$id],0,5)));
                $pieces=$next;if(!$pieces)break;
            }
            {
                // Every connecting face must respect the open bore, including
                // side/end faces on curved, overlapping excavation sections.
                $neighbors[$wall['volume']]=true;
                foreach(array_keys($neighbors) as $id){
                    // The excavation is 15 cm wider/higher than the usable bore.
                    $bore=array_slice($volumes[$id],0,5);foreach([2,3,4] as $plane)$bore[$plane][3]-=0.15;
                    $next=[];foreach($pieces as $piece)array_push($next,...self::subtract($piece,$bore));
                    $pieces=$next;if(!$pieces)break;
                }
            }
            foreach($pieces as $piece)for($i=1;$i<count($piece)-1;$i++){
                $triangle=[$piece[0],$piece[$i],$piece[$i+1]];
                $a=$triangle[0];$b=$triangle[1];$c=$triangle[2];$u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
                $area=hypot($u[0]*$v[1]-$u[1]*$v[0],hypot($u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2]));if($area<1e-9)continue;
                $base=count($terrainVertices);foreach($triangle as $p)$terrainVertices[]=array_slice($p,0,3);
                $terrainFaces[]=[$base,$base+1,$base+2];$terrainUv[]=array_map(static fn($p)=>array_slice($p,3,2),$triangle);$wallFaces++;
            }
        }
        $pavements=[];
        foreach($work as $track){
            $cross=BeamJunctionBoundary::sections($track['road'],$track['profiles']);
            for($i=1;$i<count($cross);$i++){[$a,$b]=$cross[$i-1];[$d,$c]=$cross[$i];$pavements[]=[$a,$b,$c];$pavements[]=[$a,$c,$d];}
        }
        $clearance=max(1.,min(array_column($reportedSpans,'clearance_m'))-0.15);
        $terrainSurface=BeamTunnelClearance::clear(['vertices'=>$terrainVertices,'faces'=>$terrainFaces,'uv'=>$terrainUv],$pavements,$progress,$clearance);
        unset($terrainVertices,$terrainFaces,$terrainUv,$pavements);
        if($deferTerrainMesh)unset($terrainSurface['uv']);
        return ['terrain_surface'=>$terrainSurface,'layers'=>$layers,'shell'=>BeamAssets::mesh($vertices,$faces,$material,$uv),'terrain'=>$deferTerrainMesh?null:BeamAssets::mesh($terrainSurface['vertices'],$terrainSurface['faces'],$terrainMaterial,$terrainSurface['uv']),
            'objects'=>$lights,'portal_exclusions'=>$exclusions,'report'=>['spans'=>$reportedSpans,'hole_cells'=>count($cells),'exact_pavement_clearance'=>$terrainSurface['report'],'terrain_patch_triangles'=>count($terrainSurface['faces']),'excavated_floor_pieces'=>$floorPieces,'excavation_wall_triangles'=>$wallFaces,'floor_clearance_m'=>0.05,'estimated'=>true,
                'method'=>'Approach-derived floor; generic concrete single bore and portals; native holes with clipped replacement terrain preserving overburden.','limitations'=>'Underground grade, clearance, portals and lighting are estimates. Not a surveyed tunnel model.']];
    }
}
