<?php
declare(strict_types=1);
namespace SweepLine;

/** Refine native ground cells at actual pavement edges, retaining one top-only collision surface. */
final class BeamRoadGround
{
    private const TILE_CELLS=32;
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
                'method'=>'Native cells split at actual pavement boundaries; classified top-only ground collision; no shoulder walls or pavement changes'];
            $originalLayers=$layers;if($layers!=="")$layers[0]=$layers[0];
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
                $tileIds=$tiles[$tileKey];unset($tiles[$tileKey]);$tx=$tileKey%$tileColumns;$ty=intdiv($tileKey,$tileColumns);
                $cells=[];$packed='';$local=0;
                for($offset=0;$offset<strlen($tileIds);$offset+=4){
                    $id=unpack('V',substr($tileIds,$offset,4))[1];
                    if(fseek($geometry,$id*72)!==0||strlen($bytes=fread($geometry,72))!==72)throw new \RuntimeException('Cannot read terrain refinement workspace.');
                    $v=array_values(unpack('d*',$bytes));$packed.=$bytes;$xs=[$v[0],$v[3],$v[6]];$ys=[$v[1],$v[4],$v[7]];$margin=$blend+$step;
                    $loX=max($tx*self::TILE_CELLS,(int)floor((min($xs)-$margin)/$step));$hiX=min($n-2,($tx+1)*self::TILE_CELLS-1,(int)floor((max($xs)+$margin)/$step));
                    $loY=max($ty*self::TILE_CELLS,(int)floor((min($ys)-$margin)/$step));$hiY=min($n-2,($ty+1)*self::TILE_CELLS-1,(int)floor((max($ys)+$margin)/$step));
                    $entry=pack('V',$local++);
                    for($y=$loY;$y<=$hiY;$y++)for($x=$loX;$x<=$hiX;$x++){$key=$y*$n+$x;$cells[$key]=($cells[$key]??'').$entry;}
                }
                unset($tileIds,$bytes,$v);ksort($cells);
                foreach(array_keys($cells) as $key){
                    if(++$at%100===0)$progress(null);$ids=$cells[$key];unset($cells[$key]);
                    if(($originalLayers[$key]??"\0")==="\xff"){$report['existing_hole_cells_skipped']++;continue;}
                    $x=$key%$n;$y=intdiv($key,$n);$triangles=[];
                    foreach(unpack('V*',$ids) as $id){$v=array_values(unpack('d*',substr($packed,$id*72,72)));$triangles[]=[array_slice($v,0,3),array_slice($v,3,3),array_slice($v,6,3)];}
                    $corners=[];foreach([[0,0],[1,0],[1,1],[0,1]] as [$dx,$dy])$corners[]=[($x+$dx)*$step,($y+$dy)*$step,BeamMedianSurface::nativeCell($raster,$x+$dx,$y+$dy,$origin,$heightRange)];
                    $pieces=[[$corners[0],$corners[1],$corners[2]],[$corners[0],$corners[2],$corners[3]]];$touch=false;
                    foreach($triangles as $cut){
                        if(max(array_column($cut,0))<$x*$step||min(array_column($cut,0))>($x+1)*$step||max(array_column($cut,1))<$y*$step||min(array_column($cut,1))>($y+1)*$step)continue;
                        if(BeamPolygon::cross(...$cut)<0)$cut=array_reverse($cut);
                        $next=[];foreach($pieces as $piece){
                            $intersection=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$piece),$cut);
                            if(abs(BeamPolygon::area($intersection))>1e-8){$touch=true;array_push($next,...BeamJunctionBoundary::subtract($piece,$cut));}
                            else $next[]=$piece;
                        }$pieces=$next;
                    }
                    $height=static function(array $p)use($triangles,$raster,$origin,$heightRange,$blend,$originalLayers,$step,$n):float{
                        $native=BeamMedianSurface::nativeHeight($raster,$p[0],$p[1],$origin,$heightRange);
                        // Replacement tunnel/median cells already own this shared boundary.
                        $gx=$p[0]/$step;$gy=$p[1]/$step;
                        if(abs($gx-round($gx))<1e-7||abs($gy-round($gy))<1e-7){
                            foreach([(int)floor($gx-1e-7),(int)floor($gx+1e-7)] as $ix)foreach([(int)floor($gy-1e-7),(int)floor($gy+1e-7)] as $iy)
                                if($ix>=0&&$iy>=0&&$ix<$n&&$iy<$n&&($originalLayers[$iy*$n+$ix]??"\0")==="\xff")return $native;
                        }
                        $nearest=INF;$sum=0.;$weights=0.;$edgeTarget=null;
                        foreach($triangles as $tri){
                            $best=INF;$z=0.;
                            foreach([0,1,2] as $i){
                                $a=$tri[$i];$b=$tri[($i+1)%3];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$len=$dx*$dx+$dy*$dy;if($len<1e-12)continue;
                                $t=max(0.,min(1.,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$len));$d=hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy);
                                if($d<$best){$best=$d;$z=$a[2]+$t*($b[2]-$a[2])-.05;}
                            }
                            if($best<1e-6){$edgeTarget=$edgeTarget===null?$z:min($edgeTarget,$z);continue;}
                            if($best>$blend)continue;$w=(1-$best/$blend)**2/max(.0001,$best*$best);$sum+=$w*$z;$weights+=$w;$nearest=min($nearest,$best);
                        }
                        if($edgeTarget!==null)return $edgeTarget;
                        if(!$weights)return $native;$fade=max(0.,1-$nearest/$blend);$fade=$fade*$fade*(3-2*$fade);
                        return $native*(1-$fade)+$sum/$weights*$fade;
                    };
                    $changed=$touch;
                    if(!$changed)foreach($corners as $p)if(abs($height($p)-$p[2])>1e-6){$changed=true;break;}
                    if(!$changed)continue;
                    foreach($pieces as $piece)for($i=1;$i<count($piece)-1;$i++){
                        $tri=[$piece[0],$piece[$i],$piece[$i+1]];if(abs(BeamPolygon::cross(...$tri))<1e-8)continue;
                        if($batch===0)$batch=self::faceBatch();
                        $face=[];foreach($tri as $p){$p[2]=$height($p);$face[]=count($vertices);$vertices[]=$p;}$faces[]=$face;$report['triangles']++;
                        if(count($faces)>=$batch)$flush();
                    }
                    $layers[$key]="\xff";$report['replacement_cells']++;
                }
                unset($cells,$packed,$triangles,$height,$pieces,$corners,$ids,$v,$next,$cut,$piece,$tri,$face);$flush();
            }
            $flush();return ['files'=>$files,'materials'=>$materials,'layers'=>$layers,'report'=>$report];
        }finally{fclose($geometry);}
    }
    private static function faceBatch(): int
    {
        gc_mem_caches();
        // Account for material preparation as well as serialization before either allocates.
        return max(32,min(2000,intdiv(BeamGroundMaterials::batchSize(),4)));
    }
}
