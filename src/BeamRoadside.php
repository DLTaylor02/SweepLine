<?php
declare(strict_types=1);
namespace SweepLine;
/** Generic visual models at mapped points/paths; no collision or invented feature locations. */
final class BeamRoadside {
    public static function height(array $tags,float $fallback):float{
        $v=$tags['height']??null;if(!is_string($v)||!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(m|ft|feet)?$/D',trim($v),$m))return $fallback;
        $n=(float)$m[1]*(in_array($m[2]??'',['ft','feet'],true)?.3048:1);return $n>=.3&&$n<=80?$n:$fallback;
    }
    public static function label(string $text,bool $bus):string{
        $im=imagecreatetruecolor(128,128);$bg=imagecolorallocate($im,...($bus?[28,73,130]:[225,230,216]));$fg=imagecolorallocate($im,...($bus?[245,245,240]:[20,45,30]));imagefill($im,0,0,$bg);
        $text=substr(preg_replace('/[^A-Za-z0-9 .-]/','',$text),0,16);$font=strlen($text)>12?3:5;
        $small=imagecreatetruecolor(max(1,imagefontwidth($font)*strlen($text)),imagefontheight($font));imagefill($small,0,0,$bg);imagestring($small,$font,0,0,$text,$fg);
        $width=min(112,imagesx($small)*3);$height=$width/imagesx($small)*imagesy($small);imagecopyresampled($im,$small,(int)((128-$width)/2),(int)((128-$height)/2),0,0,(int)$width,(int)$height,imagesx($small),imagesy($small));
        ob_start();imagepng($im);$png=ob_get_clean();imagedestroy($small);imagedestroy($im);return $png;
    }
    public static function write(array $data,array $lines,BeamRaster $raster,float $origin,string $prefix,string $path,callable $put,callable $progress):array{
        $features=$data['features']??[];
        usort($features,static fn($a,$b)=>(($a['kind']==='parking'?3:(count($a['points'])===1?0:1))<=>(($b['kind']==='parking'?3:(count($b['points'])===1?0:1))))?:strcmp($a['id'],$b['id']));
        $index=$features?new BeamRoadIndex($lines):null;
        $mesh=new BeamContextMesh($prefix,$path,\Closure::fromCallable($put));$objects=[];$counts=[];$skipped=[];$records=[];$libraries=[];$supports=[];$samples=0;$lights=0;$hedges=0;$crossings=[];$labels=[];$stockCounts=[];$stockInstances=0;$modelPolicy=[];
        $railLines=[];foreach($features as $f)if($f['kind']==='rail')$railLines[]=['points'=>$f['points']];$railIndex=$railLines?new BeamRoadIndex($railLines):null;
        $skip=static function(string $why)use(&$skipped):void{$skipped[$why]=($skipped[$why]??0)+1;};
        $near=static function(array $p,float $clear=0.)use($index):?array{
            $hit=$index?->nearest($p,90);if(!$hit||$hit['raw']<0||$hit['raw']>1)return null;
            $profile=$hit['line']['profiles'][$hit['segment']]??$hit['line']['profiles'][0];$edge=$hit['distance']-$profile['width_m']/2;
            return $edge<=60.96-max(0.,$clear)&&$edge>=$clear?$hit:null;
        };
        $ground=static fn(array $p)=>$raster->height($p[0],$p[1])-$origin;
        $surface=static function(array $p)use($index,$ground):float{
            $z=$ground($p);$hit=$index?->nearest($p,20);
            if($hit){$profile=$hit['line']['profiles'][$hit['segment']]??$hit['line']['profiles'][0];$a=$hit['line']['points'][$hit['segment']-1];$b=$hit['line']['points'][$hit['segment']];
                $road=$a[2]+($b[2]-$a[2])*$hit['fraction'];if($hit['distance']<=$profile['width_m']/2+.2&&abs($road-$z)<1)$z=max($z,$road);
            }return $z;
        };
        foreach($features as $f)if(in_array($f['kind'],['power_pole','power_tower'],true)&&count($f['points'])===1)$supports[$f['osm_id']]=['point'=>$f['points'][0],'height'=>self::height($f['tags'],$f['kind']==='power_tower'?20:8)];
        foreach($features as $i=>$f){
            if($i%25===0)$progress(null);if($mesh->limited||$samples>=24000){$skip('geometry_budget');break;}
            $kind=$f['kind'];$p=$f['points'];$tags=$f['tags'];$made=0;
            if($kind==='power_line'){
                for($j=1;$j<count($p);$j++){
                    $a=$supports[(string)($f['node_ids'][$j-1]??'')]??null;$b=$supports[(string)($f['node_ids'][$j]??'')]??null;
                    if(!$a||!$b){$skip('wire_span_missing_mapped_support');continue;}
                    $length=hypot($p[$j][0]-$p[$j-1][0],$p[$j][1]-$p[$j-1][1]);
                    if($length>1000){$skip('wire_span_too_long');continue;}
                    $n=max(1,(int)ceil($length/4));$previous=null;
                    for($k=0;$k<=$n;$k++){
                        if(++$samples>24000)break;
                        $t=$k/$n;$point=[$p[$j-1][0]+($p[$j][0]-$p[$j-1][0])*$t,$p[$j-1][1]+($p[$j][1]-$p[$j-1][1])*$t];
                        $z=(1-$t)*($ground($a['point'])+$a['height'])+$t*($ground($b['point'])+$b['height'])-4*min(3,$length*.02)*$t*(1-$t);
                        $current=[$point[0],$point[1],$z];$valid=$near($point,-100)!==null&&$z-$surface($point)>=6;
                        if($valid&&$previous){$mesh->beam($previous,$current,.035,.035,'steel');$made++;}
                        $previous=$valid?$current:null;
                    }
                }
            }elseif($kind==='parking'){
                $ring=BeamPolygon::clean($p);
                if(count($ring)<3||count($ring)>500){$skip('parking_footprint_complex');continue;}
                $pending=[];foreach(BeamPolygon::triangles($ring) as $ids)$pending[]=array_map(static fn($id)=>$ring[$id],$ids);
                while($pending){
                    if(++$samples>24000||$mesh->limited)break;$tri=array_pop($pending);
                    $mids=[];$longest=0.;foreach([[0,1],[1,2],[2,0]] as [$u,$v]){$mids[]=[($tri[$u][0]+$tri[$v][0])/2,($tri[$u][1]+$tri[$v][1])/2];$longest=max($longest,hypot($tri[$v][0]-$tri[$u][0],$tri[$v][1]-$tri[$u][1]));}
                    if($longest>6){array_push($pending,[$tri[0],$mids[0],$mids[2]],[$mids[0],$tri[1],$mids[1]],[$mids[2],$mids[1],$tri[2]],[$mids[0],$mids[1],$mids[2]]);continue;}
                    $center=[array_sum(array_column($tri,0))/3,array_sum(array_column($tri,1))/3];
                    if(!$near($center,.3)||!$near($tri[0],.3)||!$near($tri[1],.3)||!$near($tri[2],.3)||!$near($mids[0],.3)||!$near($mids[1],.3)||!$near($mids[2],.3)){continue;}
                    $out=[];foreach($tri as $point)$out[]=[$point[0],$point[1],$ground($point)+.025];$mesh->triangle($out,'parking');$made++;
                }
            }elseif(in_array($kind,['fence','wall','retaining_wall','hedge','rail','gate'],true)&&count($p)>1){
                for($j=1;$j<count($p);$j++){
                    $dx=$p[$j][0]-$p[$j-1][0];$dy=$p[$j][1]-$p[$j-1][1];$length=hypot($dx,$dy);if($length<.01)continue;
                    if($length>1200){$skip('long_unsampled_segment');continue;}
                    $step=$kind==='hedge'?2.:($kind==='rail'?1.2:3.);$n=max(1,(int)ceil($length/$step));$yaw=atan2($dy,$dx);
                    for($k=0;$k<$n;$k++){
                        if(++$samples>24000||$mesh->limited)break;
                        $a=[$p[$j-1][0]+$dx*$k/$n,$p[$j-1][1]+$dy*$k/$n];$b=[$p[$j-1][0]+$dx*($k+1)/$n,$p[$j-1][1]+$dy*($k+1)/$n];
                        $clear=$kind==='rail'?-100:($kind==='hedge'?1.8:.5);
                        if(!$near($a,$clear)||!$near($b,$clear))continue;
                        $za=$kind==='rail'?$surface($a):$ground($a);$zb=$kind==='rail'?$surface($b):$ground($b);$h=self::height($tags,in_array($kind,['wall','retaining_wall'],true)?1.5:1.2);
                        if($kind==='fence'&&($asset=BeamRoadsideAssets::fence($tags))!==null&&$stockInstances<1500&&abs($zb-$za)<.15){
                            $object=BeamRoadsideAssets::object($asset,'mapped_fence_'.$stockInstances,[($a[0]+$b[0])/2,($a[1]+$b[1])/2,($za+$zb)/2],$h,$yaw,hypot($b[0]-$a[0],$b[1]-$a[1]));
                            if($object){$objects[]=$object;$stockInstances++;$stockCounts[$asset]=($stockCounts[$asset]??0)+1;foreach(BeamRoadsideAssets::libraries($asset) as $library)$libraries[$library]=true;$made++;continue;}
                        }
                        if($kind==='hedge'){
                            if($hedges>=500){$skip('hedge_instance_budget');continue;}$asset=BeamVegetation::CATALOG['eastern_bush'];$hedges++;$libraries[$asset['materials']]=true;
                            $objects[]=['class'=>'TSStatic','name'=>'mapped_hedge_'.$hedges,'__parent'=>'MissionGroup','shapeName'=>$asset['shape'],'position'=>[$a[0],$a[1],$za],'rotationMatrix'=>[cos($yaw),sin($yaw),0,-sin($yaw),cos($yaw),0,0,0,1],'scale'=>[.35,.35,.35],'collisionType'=>'None','castShadows'=>true];
                        }elseif($kind==='rail'){
                            $gauge=isset($tags['gauge'])&&is_numeric($tags['gauge'])?max(.6,min(2.,(float)$tags['gauge']/1000)):1.435;
                            foreach([-1,1] as $side){$x=-$dy/$length*$gauge/2*$side;$y=$dx/$length*$gauge/2*$side;$mesh->beam([$a[0]+$x,$a[1]+$y,$za+.025],[$b[0]+$x,$b[1]+$y,$zb+.025],.07,.05,'steel');}
                            $mesh->box([$a[0],$a[1],$za-.05],.22,2.5,.07,'wood',$yaw);
                        }elseif(in_array($kind,['wall','retaining_wall'],true)){
                            $mesh->beam([$a[0],$a[1],$za+$h/2],[$b[0],$b[1],$zb+$h/2],.3,$h,'concrete');
                        }else{
                            $fenceMaterial=($tags['material']??'')==='wood'?'wood':'steel';$mesh->box([$a[0],$a[1],$za],.07,.07,$h,$fenceMaterial);
                            foreach([.3,.65,1.] as $part)$mesh->beam([$a[0],$a[1],$za+$part*$h],[$b[0],$b[1],$zb+$part*$h],.035,.035,$fenceMaterial);
                        }$made++;
                    }
                }
            }elseif(count($p)===1){
                $point=$p[0];
                if($kind==='level_crossing'){
                    $rail=$railIndex?->nearest($point,2);$roadHit=$near($point,-100);$placed=false;
                    if($rail&&$roadHit){
                        $angle=abs($rail['dx']*$roadHit['dy']-$rail['dy']*$roadHit['dx']);
                        if($angle>=.3){$profile=$roadHit['line']['profiles'][$roadHit['segment']]??$roadHit['line']['profiles'][0];$length=min(30,($profile['width_m']+1)/$angle);
                            $mesh->box([$point[0],$point[1],$surface($point)+.005],$length,2.8,.012,'roof',atan2($rail['dy'],$rail['dx']));$placed=true;$counts['level_crossing']=($counts['level_crossing']??0)+1;}
                    }
                    $crossings[]=['id'=>$f['id'],'point'=>$point,'timestamp'=>$f['timestamp'],'surface_placed'=>$placed,'treatment'=>'Mapped crossing surface and rail geometry only. Gate/light/crossbuck locations are not invented.'];continue;
                }
                $clear=match($kind){'power_tower'=>3.,'shelter'=>2.,'bench'=>1.2,'gate'=>4.,default=>.5};
                if(!$near($point,$clear)){$skip('outside_corridor_or_on_pavement');continue;}
                $z=$ground($point);$hit=$near($point,$clear);$a=$hit['line']['points'][$hit['segment']-1];$b=$hit['line']['points'][$hit['segment']];$roadZ=$a[2]+($b[2]-$a[2])*$hit['fraction'];
                if(abs($roadZ-$z)>4){$skip('mounting_elevation_unresolved');continue;}
                $bearing=BeamSigns::bearing($tags['direction']??null);$toward=atan2($a[1]+($b[1]-$a[1])*$hit['fraction']-$point[1],$a[0]+($b[0]-$a[0])*$hit['fraction']-$point[0]);
                $yaw=$bearing===null?$toward+M_PI/2:deg2rad(180-$bearing);
                $asset=BeamRoadsideAssets::point($kind,$tags);
                if($asset!==null&&$stockInstances<1500&&!(($tags['lamp_mount']??'')==='wall')){
                    $default=match($kind){'power_pole'=>8.,'power_tower'=>20.,'streetlight'=>7.,'bench'=>.85,'bollard'=>.9,'bus_stop'=>2.2,default=>2.4};
                    $height=self::height($tags,$default);$spec=BeamRoadsideAssets::CATALOG[$asset];$radius=$spec['radius']*$height/$spec['height'];
                    $profile=$hit['line']['profiles'][$hit['segment']]??$hit['line']['profiles'][0];$edge=$hit['distance']-$profile['width_m']/2;
                    $overhead=in_array($kind,['power_pole','streetlight'],true);$orientation=$kind==='streetlight'&&$bearing===null?$toward:$yaw;
                    $object=BeamRoadsideAssets::object($asset,'mapped_stock_'.$stockInstances,[$point[0],$point[1],$z],$height,$orientation);
                    if($object&&$edge+$radius<=60.96&&($overhead||$edge>=$radius+.2)){
                        $objects[]=$object;$stockInstances++;$stockCounts[$asset]=($stockCounts[$asset]??0)+1;foreach(BeamRoadsideAssets::libraries($asset) as $library)$libraries[$library]=true;
                        if(($kind==='streetlight'||($tags['highway']??'')==='street_lamp')&&$lights<64)$objects[]=['class'=>'PointLight','name'=>'mapped_streetlight_'.($lights++),'__parent'=>'MissionGroup','position'=>[$point[0],$point[1],$z+$height-.2],'radius'=>12,'brightness'=>.7,'color'=>[1,.9,.75,1],'castShadows'=>false];
                        $counts[$kind]=($counts[$kind]??0)+1;$records[]=['id'=>$f['id'],'kind'=>$kind,'timestamp'=>$f['timestamp'],'source'=>'OpenStreetMap','asset'=>$asset,'shape'=>$spec['shape'],'height_m'=>$height,'model_dimensions_estimated'=>true,'orientation_estimated'=>true];
                        continue;
                    }
                    $modelPolicy['stock_did_not_fit']=($modelPolicy['stock_did_not_fit']??0)+1;
                }
                if($kind==='power_tower'){
                    $h=self::height($tags,20);
                    foreach([[-1,-1],[1,-1],[1,1],[-1,1]] as [$x,$y])$mesh->beam([$point[0]+2*$x,$point[1]+2*$y,$z],[$point[0]+.5*$x,$point[1]+.5*$y,$z+$h],.14,.14,'steel');
                    for($j=1;$j<=4;$j++){$level=$j*$h/5;$w=2-1.5*$j/5;
                        foreach([-1,1] as $side){$mesh->beam([$point[0]-$w,$point[1]+$w*$side,$z+$level],[$point[0]+$w,$point[1]+$w*$side,$z+$level+$h/5],.07,.07,'steel');$mesh->beam([$point[0]+$w*$side,$point[1]-$w,$z+$level],[$point[0]+$w*$side,$point[1]+$w,$z+$level+$h/5],.07,.07,'steel');}}
                    $mesh->box([$point[0],$point[1],$z+$h-.2],8,.3,.2,'steel');
                }elseif(in_array($kind,['power_pole','streetlight'],true)){
                    if(($tags['lamp_mount']??'')==='wall'){$skip('wall_mounted_lamp');continue;}
                    $h=self::height($tags,$kind==='power_pole'?8:7);
                    $mesh->cylinder([$point[0],$point[1],$z],.12,$h,$kind==='power_pole'?(($tags['material']??'')==='concrete'?'concrete':'wood'):'steel');
                    if($kind==='power_pole')$mesh->box([$point[0],$point[1],$z+$h-.25],2,.13,.15,'wood');
                    if($kind==='streetlight'||($tags['highway']??'')==='street_lamp'){
                        $mesh->box([$point[0],$point[1],$z+$h],.65,.4,.15,'lamp');
                        if($lights<64)$objects[]=['class'=>'PointLight','name'=>'mapped_streetlight_'.($lights++),'__parent'=>'MissionGroup','position'=>[$point[0],$point[1],$z+$h-.2],'radius'=>12,'brightness'=>.7,'color'=>[1,.9,.75,1],'castShadows'=>false];
                    }
                }elseif($kind==='bench'){
                    $benchMaterial=in_array($tags['material']??'',['metal','steel'],true)?'steel':(in_array($tags['material']??'',['concrete','stone'],true)?'concrete':'wood');
                    $mesh->box([$point[0],$point[1],$z+.45],1.8,.5,.1,$benchMaterial,$yaw);
                    foreach([-1,1] as $side)$mesh->box([$point[0]+cos($yaw)*.65*$side,$point[1]+sin($yaw)*.65*$side,$z],.12,.35,.45,'steel',$yaw);
                    $mesh->box([$point[0]-sin($yaw)*.2,$point[1]+cos($yaw)*.2,$z+.55],1.8,.08,.45,$benchMaterial,$yaw);
                }elseif($kind==='shelter'){
                    foreach([-1,1] as $x)foreach([-1,1] as $y)$mesh->box([$point[0]+1.4*$x,$point[1]+.7*$y,$z],.09,.09,2.4,'steel');
                    $mesh->box([$point[0],$point[1],$z+2.4],3.2,1.8,.15,'roof');
                }elseif($kind==='bollard')$mesh->cylinder([$point[0],$point[1],$z],.08,self::height($tags,.9),'steel');
                elseif(in_array($kind,['bus_stop','mile_marker'],true)){
                    $mesh->box([$point[0],$point[1],$z],.08,.08,$kind==='bus_stop'?2.2:1.1,'steel');
                    $text=$kind==='bus_stop'?'BUS':(string)($tags['ref']??$tags['distance']??'');
                    if($text!==''){
                        $key=$prefix.'_label_'.substr(hash('sha256',$kind.':'.$text),0,12);
                        if(!isset($labels[$key])){
                            $file='art/context/'.$key;$put($file.'.color.png',self::label($text,$kind==='bus_stop'));$put($file.'.dae',BeamSignFace::mesh($key,'R2-1'));
                            $labels[$key]=['file'=>$file,'text'=>$text];
                        }
                        $theta=$bearing===null?atan2($a[1]+($b[1]-$a[1])*$hit['fraction']-$point[1],$a[0]+($b[0]-$a[0])*$hit['fraction']-$point[0])+M_PI/2:deg2rad(180-$bearing);
                        $objects[]=['class'=>'TSStatic','name'=>'mapped_marker_'.$i,'__parent'=>'MissionGroup','shapeName'=>$path.$labels[$key]['file'].'.dae','position'=>[$point[0],$point[1],$z+($kind==='bus_stop'?2.:.9)],'rotationMatrix'=>[cos($theta),sin($theta),0,-sin($theta),cos($theta),0,0,0,1],'scale'=>[.65,1,.5],'collisionType'=>'None','castShadows'=>true];
                    }
                }elseif($kind==='gate'){
                    if($bearing===null){$skip('gate_orientation_missing');continue;}
                    $width=isset($tags['width'])&&is_numeric($tags['width'])?min(8,max(1,(float)$tags['width'])):3;
                    $mesh->box([$point[0],$point[1],$z],.12,.12,1.2,'steel');$mesh->box([$point[0],$point[1],$z+.95],$width,.08,.1,'steel',$yaw);
                }else{$skip('unsupported_geometry');continue;}
                $made=1;
            }
            if($made){$counts[$kind]=($counts[$kind]??0)+1;$records[]=['id'=>$f['id'],'kind'=>$kind,'timestamp'=>$f['timestamp'],'source'=>'OpenStreetMap','model_dimensions_estimated'=>true,'ref'=>$tags['ref']??null,'orientation_estimated'=>!isset($tags['direction'])];}
            else $skip('no_renderable_corridor_geometry');
        }
        $result=$mesh->finish();$objects=array_merge($result['objects'],$objects);
        $colors=['wood'=>[.32,.25,.17,1],'steel'=>[.45,.47,.48,1],'concrete'=>[.5,.49,.46,1],'roof'=>[.23,.25,.26,1],'lamp'=>[.85,.83,.7,1],'bus'=>[.1,.28,.55,1],'marker'=>[.85,.88,.79,1],'parking'=>[.24,.24,.25,1]];$materials=[];
        foreach($colors as $name=>$color){$key=$prefix.'_'.$name;$materials[$key]=['class'=>'Material','name'=>$key,'mapTo'=>$key,'version'=>1.5,'doubleSided'=>true,'Stages'=>[['baseColorFactor'=>$color,'roughnessFactor'=>.9,'metallicFactor'=>$name==='steel'?.4:0]]];}
        $materials[$prefix.'_parking']['Stages'][0]=['baseColorMap'=>BeamRoadMaterials::TEXTURES.'_b.color.dds','normalMap'=>BeamRoadMaterials::TEXTURES.'_nm.normal.dds','roughnessMap'=>BeamRoadMaterials::TEXTURES.'_r.data.dds','roughnessFactor'=>.9,'metallicFactor'=>0];
        foreach($labels as $key=>$label){$materials[$key]=['class'=>'Material','name'=>$key,'mapTo'=>$key,'version'=>1.5,'Stages'=>[['baseColorMap'=>$path.$label['file'].'.color.png','roughnessFactor'=>.9]]];$materials[$key.'_back']=['class'=>'Material','name'=>$key.'_back','mapTo'=>$key.'_back','version'=>1,'Stages'=>[['diffuseColor'=>[.5,.5,.5,1]]]];}
        if($result['mesh_parts']||$labels)$put('art/context/main.materials.json',json_encode($materials,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return ['objects'=>$objects,'material_libraries'=>array_merge(($result['mesh_parts']||$labels)?[$path.'art/context/main.materials.json']:[],array_keys($libraries)),'report'=>['fetch_status'=>$data['fetch_status']??'not_requested','warning'=>$data['warning']??null,'source'=>'OpenStreetMap mapped roadside infrastructure','placed_features'=>$counts,'records'=>$records,'crossings'=>$crossings,'skipped'=>$skipped,'skipped_parse'=>$data['skipped']??[],'truncated'=>($data['truncated']??false)||$result['limited']||$samples>=24000,'mesh_parts'=>$result['mesh_parts'],'primitives'=>$result['primitives'],'lights'=>$lights,'hedge_instances'=>$hedges,'stock_assets'=>$stockCounts,'stock_instances'=>$stockInstances,'stock_policy'=>$modelPolicy,'model_policy'=>'Stock models first when the mapped type, footprint, dimensions and slope fit; generated geometry for unsupported models or exact paths.','collision'=>'Visual-only; existing road/terrain collision unchanged.','limits'=>'200 ft from road edges; 2,000 features, 40,000 source points, 24,000 samples, 12,000 mesh primitives, 500 hedge instances, 1,500 stock instances and 64 lights.','estimates'=>'Dimensions, materials, foundations, orientation where absent, cable sag and railway gauge/sleeper spacing may be estimated. No new feature locations or crossings are invented.']];
    }
}
