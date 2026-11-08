<?php
declare(strict_types=1);
namespace SweepLine;

/** Native bounded water surfaces and modest estimated beds, never road elevation edits. */
final class BeamWater
{
    public const MAX_OBJECTS=1024;
    public static function downhill(array $values): array
    {
        $blocks=[];foreach($values as $i=>$z){$blocks[]=['sum'=>$z,'count'=>1,'start'=>$i];
            while(count($blocks)>1){$n=count($blocks);$a=$blocks[$n-2];$b=$blocks[$n-1];if($a['sum']/$a['count']>=$b['sum']/$b['count'])break;array_pop($blocks);$blocks[$n-2]=['sum'=>$a['sum']+$b['sum'],'count'=>$a['count']+$b['count'],'start'=>$a['start']];}
        }
        $out=[];foreach($blocks as $b)for($i=0;$i<$b['count'];$i++)$out[]=$b['sum']/$b['count'];return $out;
    }
    public static function level(array $model,array $point): float
    {
        if(isset($model['level']))return $model['level'];
        if(isset($model['index'])){$hit=$model['index']->nearest($point,64)??$model['index']->nearest($point,256)??$model['index']->nearest($point,2000);if($hit===null)throw new \RuntimeException('River elevation profile does not cover this surface.');$p=$hit['line']['points'];$i=$hit['segment'];return $p[$i-1][2]+($p[$i][2]-$p[$i-1][2])*$hit['fraction'];}$best=INF;$z=null;$p=$model['points'];
        foreach($model['paths']??[$p] as $p)for($i=1;$i<count($p);$i++){$a=$p[$i-1];$b=$p[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l=$dx*$dx+$dy*$dy;if($l<1e-8)continue;$t=max(0,min(1,(($point[0]-$a[0])*$dx+($point[1]-$a[1])*$dy)/$l));$d=hypot($point[0]-$a[0]-$t*$dx,$point[1]-$a[1]-$t*$dy);
            if($d<$best){$best=$d;$z=$a[2]+$t*($b[2]-$a[2]);}}
        if($z===null||$best>2000)throw new \RuntimeException('River elevation profile does not cover this surface.');return $z;
    }
    private static function model(array $feature,array $lines,BeamRaster $raster,array $patches): array
    {
        $samples=[];$rgb=[0,0,0];$positions=[];
        foreach($patches as $quad){$p=[array_sum(array_column($quad,0))/4,array_sum(array_column($quad,1))/4];$positions[]=$p;if(count($positions)>=64)break;}
        [$x0,$y0,$x1,$y1]=BeamWaterGeometry::bounds($feature['rings']);
        for($y=0;$y<12;$y++)for($x=0;$x<12;$x++){$p=[$x0+($x+0.5)/12*($x1-$x0),$y0+($y+0.5)/12*($y1-$y0)];if(BeamWaterGeometry::contains($p,$feature['rings']))$positions[]=$p;}
        foreach($positions as $p){$samples[]=$raster->height(...$p);$color=$raster->rgb((int)($p[0]/$raster->side*$raster->size),(int)($p[1]/$raster->side*$raster->size));foreach($rgb as $i=>$_)$rgb[$i]+=$color[$i];}
        if(count($samples)<1)throw new \RuntimeException('No elevation samples inside water boundary.');
        $color=array_map(static fn($c)=>max(35,min(155,(int)round($c/count($samples)))),$rgb);sort($samples);$q=static fn($fraction)=>$samples[(int)floor((count($samples)-1)*$fraction)];
        $spread=$q(0.9)-$q(0.1);$base=['color'=>[...$color,255],'sample_count'=>count($samples),'elevation_spread_m'=>$spread,'confidence'=>$spread<0.5?'moderate':'low','estimated'=>true];
        if(in_array($feature['kind'],['river','canal'],true)){
            $candidates=[];
            foreach($lines as $line){$match=($line['body_key']??'')!==''&&($line['body_key']??'')===($feature['source_key']??'');$inside=0;$length=0.;foreach($line['points'] as $i=>$p){if(BeamWaterGeometry::contains($p,$feature['rings']))$inside++;if($i){$a=$line['points'][$i-1];$length+=hypot($p[0]-$a[0],$p[1]-$a[1]);if(BeamWaterGeometry::contains([($p[0]+$a[0])/2,($p[1]+$a[1])/2],$feature['rings']))$inside++;}}
                if(($match||$inside>=max(1,count($line['points'])/2))&&$length>=10)$candidates[]=$line['points'];
            }
            $pathLength=static function($points){$length=0.;for($i=1;$i<count($points);$i++)$length+=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);return $length;};
            usort($candidates,static fn($a,$b)=>$pathLength($b)<=>$pathLength($a));
            if($candidates){$paths=[];$rejectedProfiles=0;
                while($candidates){$path=array_shift($candidates);
                for($pass=0;$pass<256;$pass++){$matches=[];foreach($candidates as $i=>$p)foreach([false,true] as $reverse){$v=$reverse?array_reverse($p):$p;if(hypot(end($path)[0]-$v[0][0],end($path)[1]-$v[0][1])<1)$matches[]=[$i,$reverse,false];if(hypot($path[0][0]-end($v)[0],$path[0][1]-end($v)[1])<1)$matches[]=[$i,$reverse,true];}
                    $ends=[[],[]];foreach($matches as $match)$ends[$match[2]?1:0][]=$match;
                    $choice=count($ends[0])===1?$ends[0][0]:(count($ends[1])===1?$ends[1][0]:null);if($choice===null)break;[$i,$reverse,$prepend]=$choice;$p=$reverse?array_reverse($candidates[$i]):$candidates[$i];array_splice($candidates,$i,1);$path=$prepend?array_merge(array_slice($p,0,-1),$path):array_merge($path,array_slice($p,1));
                }
                $chain=[0.];foreach($path as $i=>$p)if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$path[$i-1][0],$p[1]-$path[$i-1][1]);$length=end($chain);$step=max(8,$length/1024);$points=[];$heights=[];$atIndex=1;
                for($at=0;$at<=$length+$step;$at+=$step){$s=min($length,$at);while($atIndex<count($path)-1&&$chain[$atIndex]<$s)$atIndex++;$t=($s-$chain[$atIndex-1])/max(1e-8,$chain[$atIndex]-$chain[$atIndex-1]);$p=[$path[$atIndex-1][0]+$t*($path[$atIndex][0]-$path[$atIndex-1][0]),$path[$atIndex-1][1]+$t*($path[$atIndex][1]-$path[$atIndex-1][1])];$points[]=$p;$heights[]=$raster->height(...$p);if($s===$length)break;}
                if($heights[0]<end($heights)){$points=array_reverse($points);$heights=array_reverse($heights);}
                $raw=$heights;$radius=min(40,max(8,$length/10));
                foreach($heights as $i=>$_){$near=[];$window=(int)floor($radius/$step);for($j=max(0,$i-$window);$j<=min(count($raw)-1,$i+$window);$j++)$near[]=$raw[$j];sort($near);$heights[$i]=$near[(int)floor((count($near)-1)*0.25)];}
                $heights=self::downhill($heights);
                $validProfile=true;foreach($points as $i=>&$p){$p[]=$heights[$i]+0.12;if($i&&abs($p[2]-$points[$i-1][2])/max(1,hypot($p[0]-$points[$i-1][0],$p[1]-$points[$i-1][1]))>0.05){$validProfile=false;break;}}unset($p);
                if($validProfile)$paths[]=$points;else $rejectedProfiles++;
                }
                if(!$paths)throw new \RuntimeException('Mapped river profiles exceed the supported grade after robust filtering.');
                $index=new BeamRoadIndex(array_map(static fn($p)=>['points'=>$p],$paths));
                return $base+['rejected_profiles'=>$rejectedProfiles,'index'=>$index,'points'=>$paths[0],'paths'=>$paths,'method'=>'Robust locally filtered downhill profiles along mapped USGS river/waterbody flowlines; flow speed not measured'];
            }
            if($spread>1)throw new \RuntimeException('Sloping river has no usable mapped elevation profile.');
        }
        if($q(0.25)-$q(0.1)>1)throw new \RuntimeException('Water elevation is not sufficiently consistent.');
        return $base+['level'=>$q(0.2)+0.12,'method'=>'Robust low-interior DEM estimate; no surveyed water level or bathymetry'];
    }
    private static function roadConflict(array $feature,array $model,array $tracks,callable $progress): bool
    {
        $bounds=BeamWaterGeometry::bounds($feature['rings']);
        foreach($tracks as $track){$road=$track['road'];$profiles=$track['profiles'];$chain=0.;
            for($i=1;$i<count($road);$i++){if($i%128===0)$progress(null);$a=$road[$i-1];$b=$road[$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);$half=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2;
                $start=$chain;$chain+=$length;if(max($a[0],$b[0])+$half<$bounds[0]||min($a[0],$b[0])-$half>$bounds[2]||max($a[1],$b[1])+$half<$bounds[1]||min($a[1],$b[1])-$half>$bounds[3])continue;
                $steps=max(1,(int)ceil($length/4));for($j=0;$j<=$steps;$j++){$t=$j/$steps;$p=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t];if(!BeamWaterGeometry::contains($p,$feature['rings'])&&BeamWaterGeometry::boundaryDistance($p,$feature['rings'])>$half+0.25)continue;
                    $at=$start+$length*$t;$tunnel=false;$bridge=false;foreach($track['tunnels']??[] as $span)if($at>=$span['start_m']-2&&$at<=$span['end_m']+2)$tunnel=true;
                    foreach($track['bridges']??[] as $span)if($at>=$span['start_m']&&$at<=$span['end_m'])$bridge=true;
                    if($tunnel||self::level($model,$p)>=$a[2]+($b[2]-$a[2])*$t-($bridge?0.5:0.25))return true;
                }
            }
        }return false;
    }
    private static function object(array $quad,array $model,float $origin,int $number,bool $moving): array
    {
        [$a,$b,$c,$d]=$quad;$z=[];foreach($quad as $p)$z[]=self::level($model,$p)-$origin;
        $lo=[($a[0]+$b[0])/2,$a[1],($z[0]+$z[1])/2];$hi=[($c[0]+$d[0])/2,$c[1],($z[2]+$z[3])/2];$width0=$b[0]-$a[0];$width1=$c[0]-$d[0];
        $gx0=$width0>0.01?($z[1]-$z[0])/$width0:0.;$gx1=$width1>0.01?($z[2]-$z[3])/$width1:0.;$gy=($hi[2]-$lo[2]-($gx0+$gx1)/2*($hi[0]-$lo[0]))/max(0.01,$hi[1]-$lo[1]);
        if(max(abs($gx0),abs($gx1),abs($gy))>0.08)throw new \RuntimeException('Local water surface slope is unsupported.');
        $node=static function($p,$width,$gx)use($gy){$normal=[-$gx,-$gy,1.];$n=sqrt($gx*$gx+$gy*$gy+1);$normal=array_map(static fn($v)=>$v/$n,$normal);return [...$p,max(0.01,$width)*sqrt(1+$gx*$gx),1.5,...$normal];};
        return ['class'=>'River','name'=>'mapped_water_'.$number,'__parent'=>'MissionGroup','position'=>$lo,'nodes'=>[$node($lo,$width0,$gx0),$node($hi,$width1,$gx1)],'segmentLength'=>max(4,($hi[1]-$lo[1])/8),'subdivideLength'=>max(4,max($width0,$width1)/16),'lowLODDistance'=>5000,'density'=>1000,'viscosity'=>0.001,'liquidType'=>'Water','flowMagnitudePhysics'=>0,'baseColor'=>$model['color'],'underwaterColor'=>[45,65,65,235],'rippleTex'=>'core/art/water/ripple.dds','foamTex'=>'core/art/water/foam.dds','depthGradientTex'=>'core/art/water/depthcolor_ramp.png','fullReflect'=>false,'overallWaveMagnitude'=>0,'overallRippleMagnitude'=>$moving?0.2:0.12,'overallFoamOpacity'=>0,'specularPower'=>120,'specularColor'=>[0.9,0.95,1,1],'waterFogDensity'=>0.12,'waterFogDensityOffset'=>0,'wetDepth'=>0.2,'wetDarkening'=>0.3,'Ripples (texture animation)'=>[['rippleDir'=>[0,1],'rippleSpeed'=>0.04,'rippleMagnitude'=>0.2,'rippleTexScale'=>[8,8]],['rippleDir'=>[0.7,0.7],'rippleSpeed'=>0.02,'rippleMagnitude'=>0.15,'rippleTexScale'=>[12,12]]]];
    }
    public static function build(array $data,BeamRaster $source,BeamRaster $terrain,float $origin,array $tracks,callable $progress): array
    {
        $objects=[];$areas=[];$rejections=[];$omitted=[];$features=[];$changed=0;$budget=0;
        $roads=[];foreach(($data['features']??[])?$tracks:[] as $t)$roads[]=['points'=>$t['road'],'profiles'=>$t['profiles'],'bridges'=>$t['bridges']??[],'tunnels'=>$t['tunnels']??[]];
        $roadIndex=$roads?new BeamRoadIndex($roads):null;$step=$terrain->side/$terrain->size;
        $ordered=$data['features']??[];
        $area=static function($feature){$rings=$feature['rings'];$area=abs(BeamPolygon::area($rings[0]));foreach(array_slice($rings,1) as $hole)$area-=abs(BeamPolygon::area($hole));return $area;};
        usort($ordered,static fn($a,$b)=>$area($b)<=>$area($a));
        foreach($ordered as $feature){
            $progress('Preparing mapped water surfaces');
            try{
                $rings=$feature['rings'];$bounds=BeamWaterGeometry::bounds($rings);
                foreach($bounds as $v)if(!is_finite($v)||$v< -0.01||$v>$terrain->side+0.01)throw new \RuntimeException('Water boundary lies outside the exported square.');
                $moving=in_array($feature['kind'],['river','canal'],true);
                if($moving){
                    $samplePatches=[];for($i=0;$i<128;$i++)foreach(BeamWaterGeometry::intervals($rings,$bounds[1]+($i+0.5)/128*($bounds[3]-$bounds[1])) as [$left,$right]){$p=[($left+$right)/2,$bounds[1]+($i+0.5)/128*($bounds[3]-$bounds[1])];$samplePatches[]=[$p,$p,$p,$p];}
                    $model=self::model($feature,$data['flowlines']??[],$source,$samplePatches);
                    $ribbons=isset($model['points'])?BeamRiver::ribbons($feature,$model):[];
                    if(isset($model['points'])&&!$ribbons)throw new \RuntimeException('Mapped flowlines could not form bank-constrained river surfaces.');
                    $coverage=$ribbons?['patches'=>[],'shoreline_cell_m'=>null,'covered_area_m2'=>0.]:BeamWaterGeometry::cover($rings,max(1,min(4,$terrain->side/2048)),self::MAX_OBJECTS-count($objects),true,$progress);
                }else{$ribbons=[];$coverage=BeamWaterGeometry::cover($rings,max(1,min(4,$terrain->side/2048)),self::MAX_OBJECTS-count($objects),false,$progress);$model=self::model($feature,$data['flowlines']??[],$source,$coverage['patches']);}
                $patches=$coverage['patches'];
                $localTracks=[];foreach($tracks as $t){$copy=$t;foreach($copy['road'] as &$p)$p[2]+=$origin;unset($p);$localTracks[]=$copy;}

                if(self::roadConflict($feature,$model,$localTracks,$progress))throw new \RuntimeException('Water level or boundary conflicts with protected road or tunnel geometry.');
                $new=[];foreach($patches as $quad)$new[]=self::object($quad,$model,$origin,count($objects)+count($new),isset($model['points']));
                foreach($ribbons as $ribbon){
                    if(count($objects)+count($new)>=self::MAX_OBJECTS)throw new \RuntimeException('Water coverage patch budget reached.');
                    $p=$ribbon[0]['center'];$flat=['level'=>$ribbon[0]['z'],'color'=>$model['color']];
                    $object=self::object([[$p[0]-1,$p[1]],[$p[0]+1,$p[1]],[$p[0]+1,$p[1]+1],[$p[0]-1,$p[1]+1]],$flat,$origin,count($objects)+count($new),true);
                    $object['nodes']=array_map(static fn($b)=>[$b['center'][0],$b['center'][1],$b['z']-$origin,$b['width'],1.5,0,0,1],$ribbon);$object['position']=array_slice($object['nodes'][0],0,3);$object['segmentLength']=4;$object['subdivideLength']=8;$new[]=$object;
                    for($i=1;$i<count($ribbon);$i++)$coverage['covered_area_m2']+=abs(BeamPolygon::area([$ribbon[$i-1]['left'],$ribbon[$i-1]['right'],$ribbon[$i]['right'],$ribbon[$i]['left']]));
                }
                if(!$new)continue;
                // Validate profile coverage over the whole feature before mutating its bed.
                if(!$ribbons)foreach($rings as $ring)foreach($ring as $p)self::level($model,$p);
                $ribbonIndex=$ribbons?new BeamRoadIndex(array_map(static fn($run)=>['points'=>array_map(static fn($b)=>$b['center'],$run),'widths'=>array_column($run,'width')],$ribbons)):null;
                $bedTerrain=clone $terrain;
                $featureChanged=0;$shoreBlend=$ribbons?max(2,$step*1.5):0.;$shapeBounds=[$bounds[0]-$shoreBlend,$bounds[1]-$shoreBlend,$bounds[2]+$shoreBlend,$bounds[3]+$shoreBlend];$shoreLines=[];foreach($rings as $ring)$shoreLines[]=['points'=>array_merge($ring,[$ring[0]])];$shoreIndex=new BeamRoadIndex($shoreLines);
                for($y=max(0,(int)floor($shapeBounds[1]/$step));$y<=min($terrain->size-1,(int)ceil($shapeBounds[3]/$step));$y++){
                    if($y%16===0)$progress(null);$intervals=BeamWaterGeometry::intervals($rings,$y*$step);$interval=0;
                    for($x=max(0,(int)floor($shapeBounds[0]/$step));$x<=min($terrain->size-1,(int)ceil($shapeBounds[2]/$step));$x++){
                        if(++$budget>8000000)break 2;$p=[$x*$step,$y*$step];while(isset($intervals[$interval])&&$p[0]>=$intervals[$interval][1])$interval++;$inside=isset($intervals[$interval])&&$p[0]>$intervals[$interval][0];$shore=$shoreIndex->nearest($p,max(2,$step,$shoreBlend));if(!$inside&&(!$ribbons||$shore===null||$shore['distance']>$shoreBlend))continue;
                        if($ribbonIndex){$waterHit=$ribbonIndex->nearest($p,64)??$ribbonIndex->nearest($p,256)??$ribbonIndex->nearest($p,1000+$shoreBlend);if(!$waterHit)continue;$j=$waterHit['segment'];$half=max($waterHit['line']['widths'][$j-1],$waterHit['line']['widths'][$j])/2;if($waterHit['distance']>$half+$shoreBlend)continue;}
                        $hit=$roadIndex?->nearest($p,40+2*$step);if($hit){
                            $t=$hit['line'];$j=$hit['segment'];$at=$hit['along'];$half=max($t['profiles'][$j-1]['width_m'],$t['profiles'][$j]['width_m'])/2;
                            $bridge=false;$tunnel=false;foreach($t['bridges'] as $s)if($at>$s['start_m']+2&&$at<$s['end_m']-2)$bridge=true;foreach($t['tunnels'] as $s)if($at>=$s['start_m']-10&&$at<=$s['end_m']+10)$tunnel=true;
                            if(($tunnel||!$bridge)&&$hit['distance']<$half+sqrt(2)*$step)continue;
                        }
                        $old=$bedTerrain->cell($x,$y);$level=self::level($model,$p);$bed=$level-1.5;$fade=max(0,min(1,($shoreIndex->nearest($p,max(2,$step))['distance']??max(2,$step))/max(2,$step)));$fade=$fade*$fade*(3-2*$fade);
                        $target=$old-max(0,min(3,$old-$bed))*$fade;
                        if($ribbons){$t=max(0,min(1,($shore['distance']??$shoreBlend)/$shoreBlend));$t=$t*$t*(3-2*$t);$target=$inside?$level+0.05-(1.55*$t):$level+0.05+($old-$level-0.05)*$t;$target=max($old-8,min($old+3,$target));}
                        if(abs($old-$target)>0.0001){$bedTerrain->put($x,$y,$target);$featureChanged++;}
                    }
                }
                $terrain->heights=$bedTerrain->heights;unset($bedTerrain);array_push($objects,...$new);$areas[]=['rings'=>$rings,'bounds'=>$bounds];
                $changed+=$featureChanged;$metadata=array_intersect_key($feature,array_flip(['id','name','source','source_key','kind','source_date']));
                $features[]=$metadata+['patches'=>count($new),'object_names'=>array_column($new,'name'),'rejected_profile_components'=>$model['rejected_profiles']??0,'shoreline_cell_m'=>$coverage['shoreline_cell_m'],'surface_method'=>$ribbons?'mapped-bank ribbons':'polygon grid','covered_area_m2'=>$coverage['covered_area_m2'],'level_method'=>$model['method'],'confidence'=>$model['confidence'],'estimated_depth_m'=>1.5,'elevation_spread_m'=>$model['elevation_spread_m'],'level_m'=>isset($model['level'])?$model['level']:null,'profile_points'=>$model['points']??null,'profile_paths'=>$model['paths']??null,'bed_vertices_changed'=>$featureChanged,'shore_blend_m'=>$shoreBlend];
            }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){unset($bedTerrain);$reason=$e instanceof \RuntimeException?$e->getMessage():'Water geometry processing failed.';$rejections[$reason]=($rejections[$reason]??0)+1;$omitted[]=['id'=>$feature['id']??null,'name'=>$feature['name']??'','reason'=>$reason];}
        }
        $warning=trim((string)($data['warning']??''));if($rejections)$warning=trim($warning.' Some mapped water was omitted; see water_data.rejections in build.log.');if($budget>8000000)$warning=trim($warning.' Water-bed processing reached its bounded raster budget.');
        return ['objects'=>$objects,'exclusions'=>$areas,'report'=>['providers'=>$data['providers']??[],'input_features'=>count($data['features']??[]),'rendered_features'=>count($features),'native_water_objects'=>count($objects),'features'=>$features,'rejections'=>$rejections,'rejected_features'=>$omitted,'duplicates_skipped'=>$data['duplicates_skipped']??0,'source_rejections'=>$data['source_rejections']??0,'truncated'=>$data['truncated']??false,'bed_vertices_changed'=>$changed,'warning'=>$warning,'attribution'=>$data['attribution']??'USGS 3DHP/NHD; OpenStreetMap contributors (ODbL)','method'=>'Coalesced inscribed native River rectangles preserve islands; adaptive shoreline cell size is recorded per feature; water elevations and shallow beds are estimates; no measured bathymetry or flow speed']];
    }
}
