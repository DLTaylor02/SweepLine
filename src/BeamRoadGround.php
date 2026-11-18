<?php
declare(strict_types=1);
namespace SweepLine;

/** Keep native ground unless a local pavement contact needs sub-cell refinement. */
final class BeamRoadGround
{
    private const TILE_CELLS=32;
    private const MIN_NATIVE_CLEARANCE_M=0.01;
    private const MAX_NATIVE_EDGE_GAP_M=0.10;
    // Clipping nearly coincident boundaries can leave micrometre XY residuals.
    // Use the same tolerance for deciding a patch and holding its road contact.
    private const PAVEMENT_EDGE_EPSILON_M=0.00001;
    public static function write(iterable $surfaces,BeamRaster $raster,float $origin,string $layers,string $classification,string $style,string $prefix,string $path,callable $put,callable $progress,float $heightRange=0.): array
    {
        $geometry=tmpfile();if($geometry===false)throw new \RuntimeException('Cannot create private terrain refinement workspace.');
        try{
            $n=$raster->size;$step=$raster->side/$n;$blend=max(4.,min(8.,$step));$tiles=[];$count=0;$tileColumns=(int)ceil($n/self::TILE_CELLS);
            foreach($surfaces as $surface){
                $progress(null);
                if(isset($surface['triangles']))$triangles=($surface['triangles'])();
                else{
                    $sections=BeamJunctionBoundary::sections($surface['road'],$surface['profiles']);$triangles=[];
                    for($i=1;$i<count($sections);$i++)foreach([[$sections[$i-1][0],$sections[$i-1][1],$sections[$i][1]],[$sections[$i-1][0],$sections[$i][1],$sections[$i][0]]] as $t)
                        if(BeamPolygon::cross(...$t)>1e-8)$triangles[]=$t;
                    $triangles=BeamTerrainContact::excludeSpans($triangles,$surface['road'],$surface['spans']??[]);
                }
                foreach($triangles as $tri){
                    if(abs(BeamPolygon::cross(...$tri))<1e-8)continue;
                    $id=$count++;$flat=[];foreach($tri as $p)array_push($flat,...$p);
                    if(fwrite($geometry,pack('d*',...$flat))!==72)throw new \RuntimeException('Cannot write terrain refinement workspace.');
                    $xs=array_column($tri,0);$ys=array_column($tri,1);$margin=$blend+$step;
                    $loX=max(0,(int)floor((min($xs)-$margin)/$step));$hiX=min($n-2,(int)floor((max($xs)+$margin)/$step));
                    $loY=max(0,(int)floor((min($ys)-$margin)/$step));$hiY=min($n-2,(int)floor((max($ys)+$margin)/$step));
                    for($ty=intdiv($loY,self::TILE_CELLS);$ty<=intdiv($hiY,self::TILE_CELLS);$ty++)
                    for($tx=intdiv($loX,self::TILE_CELLS);$tx<=intdiv($hiX,self::TILE_CELLS);$tx++){
                        $key=$ty*$tileColumns+$tx;$tiles[$key]=($tiles[$key]??'').pack('V',$id);
                    }
                }unset($triangles,$sections);
            }
            unset($surfaces,$surface,$tri,$flat);
            ksort($tiles);$vertices=[];$faces=[];$files=[];$materials=[];$part=0;
            $report=['replacement_cells'=>0,'triangles'=>0,'source_pavement_triangles'=>$count,'existing_hole_cells_skipped'=>0,'edge_clearance_m'=>.05,'blend_width_m'=>$blend,
                'index_tile_cells'=>self::TILE_CELLS,'geometry_storage'=>'Private temporary file; compact tile-local cell index','minimum_face_batch'=>null,'maximum_face_batch'=>0,
                'inspected_cells'=>0,'required_cells'=>0,'transition_cells'=>0,'native_cells_retained'=>0,'maximum_native_edge_gap_m'=>0.,
                'selection_reasons'=>['edge_gap'=>0,'terrain_intrusion'=>0],'maximum_allowed_native_edge_gap_m'=>self::MAX_NATIVE_EDGE_GAP_M,'minimum_native_clearance_m'=>self::MIN_NATIVE_CLEARANCE_M,'pavement_edge_tolerance_m'=>self::PAVEMENT_EDGE_EPSILON_M,
                'method'=>'Native terrain by default; replace only measured pavement gaps or intrusions plus a native-matching transition ring; no pavement changes'];
            $originalLayers=$layers;if($layers!=="")$layers[0]=$layers[0];
            // Decide from the final shaped/quantized terrain, not road class or the
            // presence of curves/ramps elsewhere. Inspect exposed union boundaries:
            // internal pavement tessellation edges are not roadside terrain.
            $candidates=str_repeat("\0",$n*$n);$required=$candidates;$at=0;
            foreach($tiles as $tileKey=>$tileIds){
                foreach(self::cells($geometry,$tileIds,$tileKey,$tileColumns,$n,$step,$blend) as $key=>$triangles){
                    if(++$at%100===0)$progress(null);
                    if(($originalLayers[$key]??"\0")==="\xff"){$report['existing_hole_cells_skipped']++;continue;}
                    $candidates[$key]="\1";$report['inspected_cells']++;
                    $cell=self::cutCell(self::corners($key,$raster,$origin,$heightRange),$triangles,true);
                    $report['maximum_native_edge_gap_m']=max($report['maximum_native_edge_gap_m'],$cell['maximum_gap']);
                    foreach($cell['reasons'] as $reason)$report['selection_reasons'][$reason]++;
                    if($cell['reasons']){$required[$key]="\1";$report['required_cells']++;}
                }
            }
            unset($cell,$triangles);
            $selected=self::selection($required,$candidates,$n,max(1,(int)ceil($blend/$step)),$progress);
            $selectedCount=substr_count($selected,"\1");$report['transition_cells']=$selectedCount-$report['required_cells'];
            $report['native_cells_retained']=$report['inspected_cells']-$selectedCount;
            unset($required,$candidates);
            if(!$selectedCount)return ['files'=>[],'materials'=>[],'layers'=>$layers,'report'=>$report];
            $batch=0;
            $flush=static function()use(&$vertices,&$faces,&$files,&$materials,&$part,&$batch,&$report,$classification,$raster,$style,$prefix,$path,$put,$progress):void{
                if(!$faces)return;
                $report['minimum_face_batch']=min($report['minimum_face_batch']??count($faces),count($faces));$report['maximum_face_batch']=max($report['maximum_face_batch'],count($faces));
                $input=['vertices'=>$vertices,'faces'=>$faces];$vertices=[];$faces=[];
                $ground=BeamGroundMaterials::prepare($input,$classification,$raster,$style,$prefix,$path,$progress);unset($input);
                $files=array_merge($files,BeamGroundMaterials::write($ground,'art/terrains/road-ground-'.$part++.'.dae',$put));
                $materials+=$ground['materials'];unset($ground);$batch=self::faceBatch();
            };
            $at=0;
            foreach(array_keys($tiles) as $tileKey){
                $tileIds=$tiles[$tileKey];unset($tiles[$tileKey]);
                foreach(self::cells($geometry,$tileIds,$tileKey,$tileColumns,$n,$step,$blend,$selected) as $key=>$triangles){
                    if(++$at%100===0)$progress(null);
                    $pieces=self::cutCell(self::corners($key,$raster,$origin,$heightRange),$triangles,false)['pieces'];
                    $height=static function(array $p)use($triangles,$raster,$origin,$heightRange,$blend,$selected,$step,$n):float{
                        $native=BeamMedianSurface::nativeHeight($raster,$p[0],$p[1],$origin,$heightRange);
                        // Every boundary with retained native/tunnel/median ground
                        // matches its quantized height; core cells retain exact contact.
                        if(self::boundary($p,$selected,$n,$step))return $native;
                        $selectionWeight=self::weight($p,$selected,$n,$step);
                        $nearest=INF;$sum=0.;$weights=0.;$edgeTarget=null;
                        foreach($triangles as $tri){
                            $best=INF;$z=0.;
                            foreach([0,1,2] as $i){
                                $a=$tri[$i];$b=$tri[($i+1)%3];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$len=$dx*$dx+$dy*$dy;if($len<1e-12)continue;
                                $t=max(0.,min(1.,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$len));$d=hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy);
                                if($d<$best){$best=$d;$z=$a[2]+$t*($b[2]-$a[2])-.05;}
                            }
                            if($best<=self::PAVEMENT_EDGE_EPSILON_M){$edgeTarget=$edgeTarget===null?$z:min($edgeTarget,$z);continue;}
                            if($best>$blend)continue;$w=(1-$best/$blend)**2/max(.0001,$best*$best);$sum+=$w*$z;$weights+=$w;$nearest=min($nearest,$best);
                        }
                        // Only the actual outer boundary is pinned to native ground.
                        // Fading pavement contact through the whole adjacent cell
                        // would reopen a gap beside a retained/structure-owned cell.
                        if($edgeTarget!==null)return $edgeTarget;
                        if(!$weights)return $native;$fade=max(0.,1-$nearest/$blend);$fade=$fade*$fade*(3-2*$fade)*$selectionWeight;
                        return $native*(1-$fade)+$sum/$weights*$fade;
                    };
                    foreach($pieces as $piece)for($i=1;$i<count($piece)-1;$i++){
                        $tri=[$piece[0],$piece[$i],$piece[$i+1]];if(abs(BeamPolygon::cross(...$tri))<1e-8)continue;
                        if($batch===0)$batch=self::faceBatch();
                        $face=[];foreach($tri as $p){$p[2]=$height($p);$face[]=count($vertices);$vertices[]=$p;}$faces[]=$face;$report['triangles']++;
                        if(count($faces)>=$batch)$flush();
                    }
                    $layers[$key]="\xff";$report['replacement_cells']++;
                }
                unset($tileIds,$triangles,$height,$pieces,$piece,$tri,$face);$flush();
            }
            $flush();return ['files'=>$files,'materials'=>$materials,'layers'=>$layers,'report'=>$report];
        }finally{fclose($geometry);}
    }
    /** Read one compact tile at a time; the selection pass never retains whole-map geometry. */
    private static function cells($geometry,string $tileIds,int $tileKey,int $tileColumns,int $n,float $step,float $blend,?string $selected=null): \Generator
    {
        $tx=$tileKey%$tileColumns;$ty=intdiv($tileKey,$tileColumns);$cells=[];$packed='';$local=0;
        for($offset=0;$offset<strlen($tileIds);$offset+=4){
            $id=unpack('V',substr($tileIds,$offset,4))[1];
            if(fseek($geometry,$id*72)!==0||strlen($bytes=fread($geometry,72))!==72)throw new \RuntimeException('Cannot read terrain refinement workspace.');
            $v=array_values(unpack('d*',$bytes));$packed.=$bytes;$xs=[$v[0],$v[3],$v[6]];$ys=[$v[1],$v[4],$v[7]];$margin=$blend+$step;
            $loX=max($tx*self::TILE_CELLS,(int)floor((min($xs)-$margin)/$step));$hiX=min($n-2,($tx+1)*self::TILE_CELLS-1,(int)floor((max($xs)+$margin)/$step));
            $loY=max($ty*self::TILE_CELLS,(int)floor((min($ys)-$margin)/$step));$hiY=min($n-2,($ty+1)*self::TILE_CELLS-1,(int)floor((max($ys)+$margin)/$step));
            $entry=pack('V',$local++);
            for($y=$loY;$y<=$hiY;$y++)for($x=$loX;$x<=$hiX;$x++){
                $key=$y*$n+$x;if($selected!==null&&$selected[$key]!=="\1")continue;$cells[$key]=($cells[$key]??'').$entry;
            }
        }
        ksort($cells);
        foreach(array_keys($cells) as $key){
            $ids=$cells[$key];unset($cells[$key]);$triangles=[];
            foreach(unpack('V*',$ids) as $id){$v=array_values(unpack('d*',substr($packed,$id*72,72)));$triangles[]=[array_slice($v,0,3),array_slice($v,3,3),array_slice($v,6,3)];}
            yield $key=>$triangles;
        }
    }
    private static function corners(int $key,BeamRaster $raster,float $origin,float $heightRange): array
    {
        $n=$raster->size;$step=$raster->side/$n;$x=$key%$n;$y=intdiv($key,$n);$corners=[];
        foreach([[0,0],[1,0],[1,1],[0,1]] as [$dx,$dy])$corners[]=[($x+$dx)*$step,($y+$dy)*$step,BeamMedianSurface::nativeCell($raster,$x+$dx,$y+$dy,$origin,$heightRange)];
        return $corners;
    }
    private static function nativeAt(array $p,array $corners): float
    {
        [$a,$b,$c,$d]=$corners;$tx=($p[0]-$a[0])/($b[0]-$a[0]);$ty=($p[1]-$a[1])/($d[1]-$a[1]);
        return $tx>=$ty?(1-$tx)*$a[2]+($tx-$ty)*$b[2]+$ty*$c[2]:(1-$ty)*$a[2]+$tx*$c[2]+($ty-$tx)*$d[2];
    }
    private static function cutCell(array $corners,array $triangles,bool $inspect): array
    {
        $pieces=[[$corners[0],$corners[1],$corners[2]],[$corners[0],$corners[2],$corners[3]]];$intrusion=false;$gap=false;$maximum=0.;
        foreach($triangles as $cut){
            if(max(array_column($cut,0))<$corners[0][0]||min(array_column($cut,0))>$corners[2][0]||max(array_column($cut,1))<$corners[0][1]||min(array_column($cut,1))>$corners[2][1])continue;
            if(BeamPolygon::cross(...$cut)<0)$cut=array_reverse($cut);
            $next=[];foreach($pieces as $piece){
                $intersection=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$piece),$cut);
                if(abs(BeamPolygon::area($intersection))>1e-8){
                    if($inspect&&!$intrusion)foreach($intersection as $p){
                        $z=0.;foreach(BeamTerrainContact::weights($p,$cut) as $k=>$w)$z+=$w*$cut[$k][2];
                        if($z-self::nativeAt($p,$corners)<self::MIN_NATIVE_CLEARANCE_M-1e-6){$intrusion=true;break;}
                    }
                    array_push($next,...BeamJunctionBoundary::subtract($piece,$cut));
                }else $next[]=$piece;
            }$pieces=$next;
        }
        if($inspect)foreach($pieces as $piece){
            if(abs(BeamPolygon::area($piece))<1e-8)continue;
            foreach($piece as $p){
                $edge=null;
                foreach($triangles as $tri)foreach([0,1,2] as $i){
                    $a=$tri[$i];$b=$tri[($i+1)%3];[$distance,$dx,$dy]=BeamLanes::distance($p,$a,$b);
                    if($distance>self::PAVEMENT_EDGE_EPSILON_M)continue;$length2=$dx*$dx+$dy*$dy;
                    $t=$length2>1e-12?max(0.,min(1.,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$length2)):0.;
                    $z=$a[2]+$t*($b[2]-$a[2]);$edge=$edge===null?$z:min($edge,$z);
                }
                if($edge===null)continue;$clearance=$edge-self::nativeAt($p,$corners);$maximum=max($maximum,$clearance);
                if($clearance>self::MAX_NATIVE_EDGE_GAP_M+1e-6)$gap=true;
                if($clearance<self::MIN_NATIVE_CLEARANCE_M-1e-6)$intrusion=true;
            }
        }
        return ['pieces'=>$pieces,'maximum_gap'=>$maximum,'reasons'=>array_keys(array_filter(['edge_gap'=>$gap,'terrain_intrusion'=>$intrusion]))];
    }
    private static function selection(string $required,string $candidates,int $n,int $rings,callable $progress): string
    {
        $selected=$required;
        // Packed masks bound memory independently of the number of road samples.
        for($y=0;$y<$n-1;$y++){
            if($y%32===0)$progress(null);
            for($x=0;$x<$n-1;$x++)if($required[$y*$n+$x]==="\1"){
                for($iy=max(0,$y-$rings);$iy<=min($n-2,$y+$rings);$iy++)for($ix=max(0,$x-$rings);$ix<=min($n-2,$x+$rings);$ix++){
                    $key=$iy*$n+$ix;if($candidates[$key]==="\1")$selected[$key]="\1";
                }
            }
        }
        return $selected;
    }
    private static function weight(array $p,string $selected,int $n,float $step): float
    {
        $gx=max(0.,min($n-1.,$p[0]/$step));$gy=max(0.,min($n-1.,$p[1]/$step));$x=min($n-2,(int)floor($gx));$y=min($n-2,(int)floor($gy));$weights=[];
        foreach([[0,0],[1,0],[1,1],[0,1]] as [$dx,$dy]){
            $inside=true;
            for($iy=$y+$dy-1;$iy<=$y+$dy;$iy++)for($ix=$x+$dx-1;$ix<=$x+$dx;$ix++)
                if($ix>=0&&$iy>=0&&$ix<$n-1&&$iy<$n-1&&$selected[$iy*$n+$ix]!=="\1")$inside=false;
            $weights[]=$inside?1.:0.;
        }
        $tx=$gx-$x;$ty=$gy-$y;$w=(1-$ty)*((1-$tx)*$weights[0]+$tx*$weights[1])+$ty*((1-$tx)*$weights[3]+$tx*$weights[2]);
        return $w*$w*(3-2*$w);
    }
    /** Pin shared grid edges, not the entire interior of a cell whose corners are pinned. */
    private static function boundary(array $p,string $selected,int $n,float $step): bool
    {
        $gx=$p[0]/$step;$gy=$p[1]/$step;
        if(abs($gx-round($gx))>1e-7&&abs($gy-round($gy))>1e-7)return false;
        foreach([(int)floor($gx-1e-7),(int)floor($gx+1e-7)] as $x)foreach([(int)floor($gy-1e-7),(int)floor($gy+1e-7)] as $y)
            if($x>=0&&$y>=0&&$x<$n-1&&$y<$n-1&&$selected[$y*$n+$x]!=="\1")return true;
        return false;
    }
    private static function faceBatch(): int
    {
        gc_mem_caches();
        // Account for material preparation as well as serialization before either allocates.
        return max(32,min(2000,intdiv(BeamGroundMaterials::batchSize(),4)));
    }
}
