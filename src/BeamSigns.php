<?php
declare(strict_types=1);
namespace SweepLine;
/** Explicit physical sign nodes only. No curve-derived signs or guessed roadside offsets. */
final class BeamSigns {
    public static function bearing(mixed $value): ?float {
        if(!is_string($value)&&!is_numeric($value))return null;
        $directions=['N'=>0,'NE'=>45,'E'=>90,'SE'=>135,'S'=>180,'SW'=>225,'W'=>270,'NW'=>315];
        $v=strtoupper(trim((string)$value));if(isset($directions[$v]))return (float)$directions[$v];
        if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>360)return null;
        return fmod((float)$v,360.);
    }
    public static function facingPoint(float $lon,float $lat,float $bearing): array {
        $a=10/6371008.8;$p=deg2rad($lat);$b=deg2rad($bearing);
        $q=asin(sin($p)*cos($a)+cos($p)*sin($a)*cos($b));
        return [$lon+rad2deg(atan2(sin($b)*sin($a)*cos($p),cos($a)-sin($p)*sin($q))),rad2deg($q)];
    }
    public static function parse(array $data): array {
        $signs=[];$skipped=[];$seen=[];$truncated=false;
        foreach(array_slice($data['elements']??[],0,1000) as $e){
            $tags=$e['tags']??[];$raw=$tags['traffic_sign']??'';
            if(($e['type']??'')!=='node'||!isset($e['id'],$e['lat'],$e['lon'])||!is_numeric($e['id'])||$e['id']<=0||!is_numeric($e['lat'])||!is_numeric($e['lon'])||abs((float)$e['lat'])>90||abs((float)$e['lon'])>180){$skipped['invalid_location']=($skipped['invalid_location']??0)+1;continue;}
            if(!is_string($raw)||trim($raw)===''||strlen($raw)>1024){$skipped['invalid_sign_label']=($skipped['invalid_sign_label']??0)+1;continue;}
            $parts=preg_split('/[;,](?![^\[]*\])/',$raw);$parts=array_values(array_unique(array_filter(array_map('trim',$parts))));
            $bearing=self::bearing($tags['traffic_sign:direction']??$tags['direction']??null);$country='';$truncated=$truncated||count($parts)>8;
            foreach(array_slice($parts,0,8) as $panel=>$part){
                if(preg_match('/^([A-Z]{2}):(.+)$/s',$part,$m)){$country=$m[1];$code=$m[2];}
                else{$code=$part;}
                $code=match(strtolower($code)){'stop'=>'R1-1','yield','give_way'=>'R1-2',default=>$code};
                if($country!==''&&$country!=='US')$code=$country.':'.$code;
                $code=preg_replace('/[\x00-\x1f\x7f]/','',$code);if($code==='')continue;
                $key=(string)$e['id'].($panel?'_p'.$panel:'');if(isset($seen[$key]))continue;$seen[$key]=true;
                preg_match('/^R2-1\[([0-9]+)(?: mph)?\]$/D',$code,$speed);
                $signs[]=['id'=>$key,'osm_id'=>(string)$e['id'],'code'=>$code,'country'=>$country,'original_tag'=>$raw,'panel_index'=>$panel,'bearing'=>$bearing,'speed'=>isset($speed[1])?(int)$speed[1]:null,'lon'=>(float)$e['lon'],'lat'=>(float)$e['lat'],'timestamp'=>$e['timestamp']??null,'source'=>'OpenStreetMap','location_accuracy'=>'mapped assembly; side-by-side panel offsets estimated'];
                if(count($signs)>=1000)break 2;
            }
        }
        return ['signs'=>$signs,'skipped'=>$skipped,'truncated'=>count($data['elements']??[])>1000||count($signs)>=1000||$truncated];
    }
    /** First meaningful bend ahead, viewed in the approaching traffic direction. */
    public static function curveDirection(array $hit,float $facing):?string {
        $points=$hit['line']['points'];$travel=sin(deg2rad($facing))*$hit['dx']+cos(deg2rad($facing))*$hit['dy']<0?1:-1;
        $previous=[$hit['dx']*$travel,$hit['dy']*$travel];$a=$points[$hit['segment']-1];$b=$points[$hit['segment']];$fraction=$hit['fraction']??0.;$distance=hypot($b[0]-$a[0],$b[1]-$a[1])*($travel>0?1-$fraction:$fraction);$angle=0.;
        $i=$travel>0?$hit['segment']:$hit['segment']-1;
        while(isset($points[$i],$points[$i+$travel])&&$distance<300){
            $a=$points[$i];$b=$points[$i+$travel];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);$i+=$travel;if($length<.01)continue;
            $next=[$dx/$length,$dy/$length];$angle+=atan2($previous[0]*$next[1]-$previous[1]*$next[0],$previous[0]*$next[0]+$previous[1]*$next[1]);$distance+=$length;
            if(abs($angle)>=deg2rad(10))return $angle>0?'L':'R';$previous=$next;
        }
        return null;
    }
    public static function merge(array $a,array $b): array {
        $records=[];foreach(array_merge($a['signs']??[],$b['signs']??[]) as $s)$records[$s['source'].':'.$s['id']]=$s;
        $a['truncated']=($a['truncated']??false)||($b['truncated']??false)||count($records)>1000;
        $a['signs']=array_slice(array_values($records),0,1000);
        foreach($b['skipped']??[] as $key=>$count)$a['skipped'][$key]=($a['skipped'][$key]??0)+$count;
        if(!empty($b['warning'])){$a['warning']=trim(($a['warning']??'').' '.$b['warning']);$a['fetch_status']='partial';}
        return $a;
    }
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array {
        try{
            $progress('Fetching explicitly mapped roadside signs');
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_ExteriorRing(ST_SimplifyPreserveTopology(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90,'quad_segs=2'),3)),4326),6)");
            $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];$poly=implode(' ',array_map(static fn($p)=>$p[1].' '.$p[0],$ring));
            if(strlen($poly)>100000)throw new \RuntimeException('Sign corridor exceeds query budget.');
            $data=BeamOsm::download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/signs-'.substr(hash('sha256',json_encode([$plan['points'],$plan['epsg']],JSON_THROW_ON_ERROR)),0,12).'.json',http_build_query(['data'=>'[out:json][timeout:60][maxsize:16777216];node[traffic_sign](poly:"'.$poly.'");out meta 1001;']),$progress,$download,null,'traffic signs');
            $result=self::parse($data);
            if($result['signs']){
                $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)');
                $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>array_merge(...array_map(static fn($s)=>[[$s['lon'],$s['lat']],self::facingPoint($s['lon'],$s['lat'],$s['bearing']??0.)],$result['signs']))],JSON_THROW_ON_ERROR),$plan['epsg']]);
                $points=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];
                foreach($result['signs'] as $i=>&$sign){$p=$points[2*$i];$tip=$points[2*$i+1];$sign['point']=[$p[0]-$plan['bounds'][0],$p[1]-$plan['bounds'][1]];$sign['grid_bearing']=$sign['bearing']===null?null:fmod(rad2deg(atan2($tip[0]-$p[0],$tip[1]-$p[1]))+360,360.);}unset($sign);
            }
            return $result+['fetch_status'=>'complete','source'=>'OpenStreetMap physical traffic_sign nodes','coverage'=>'Partial: absence of records is not proof that no signs exist.'];
        }catch(JobCancelled $e){throw $e;}
        catch(\Throwable $e){return ['signs'=>[],'fetch_status'=>'unavailable','warning'=>'Mapped traffic signs were unavailable; no sign positions were invented.','failure_kind'=>$e instanceof DownloadFailure?'provider':'processing'];}
    }
    public static function write(array $data,array $lines,BeamRaster $raster,float $origin,string $prefix,string $path,callable $put,callable $progress): array {
        foreach($lines as $i=>&$line)$line['sign_line_id']=(string)$i;unset($line);
        $index=empty($data['signs'])?null:new BeamRoadIndex($lines);$objects=[];$placed=[];$rejected=[];$materials=[];$meshes=[];
        foreach(array_slice($data['signs']??[],0,1000) as $i=>$sign){
            if($i%50===0)$progress(null);
            $point=$sign['point']??null;if(!is_array($point)||count($point)!==2)continue;
            $hit=$index->nearest($point,90);
            $reason=null;
            if(!$hit||$hit['raw']<0||$hit['raw']>1)$reason='outside_supported_road_corridor';
            else{
                $profile=$hit['line']['profiles'][$hit['segment']]??$hit['line']['profiles'][0];
                $half=$profile['width_m']/2;
                $sign['source_point']=$point;
                if(($sign['panel_index']??0)>0&&$hit['distance']>0){
                    $a=$hit['line']['points'][$hit['segment']-1];$side=$hit['dx']*($point[1]-$a[1])-$hit['dy']*($point[0]-$a[0]);$offset=1.2*$sign['panel_index']*($side>=0?1:-1);
                    $point=[$point[0]-$hit['dy']*$offset,$point[1]+$hit['dx']*$offset];$hit=$index->nearest($point,90);
                    if(!$hit){$rejected['panel_outside_corridor']=($rejected['panel_outside_corridor']??0)+1;continue;}
                    $profile=$hit['line']['profiles'][$hit['segment']]??$hit['line']['profiles'][0];$half=$profile['width_m']/2;
                }
                $edge=$hit['distance']-$half;
                if(($sign['bearing']??null)===null){
                    $a=$hit['line']['points'][$hit['segment']-1];
                    $side=$hit['dx']*($point[1]-$a[1])-$hit['dy']*($point[0]-$a[0]);
                    $direction=$side>0?1.:-1.;
                    $sign['grid_bearing']=fmod(rad2deg(atan2($direction*$hit['dx'],$direction*$hit['dy']))+360,360.);
                    $sign['facing_source']='inferred_right_side_of_travel';
                }else{$sign['facing_source']='explicit_osm';}
                $facing=[sin(deg2rad($sign['grid_bearing']??$sign['bearing'])),cos(deg2rad($sign['grid_bearing']??$sign['bearing']))];
                $dot=$facing[0]*$hit['dx']+$facing[1]*$hit['dy'];
                if($edge<0.3)$reason='mapped_position_on_pavement';
                elseif($edge>60.96)$reason='too_far_from_road';
                elseif(abs($dot)<0.5)$reason='facing_does_not_match_road';
                elseif(!empty($profile['oneway'])&&(($profile['legal_forward']??true)?$dot> -0.5:$dot<0.5))$reason='facing_wrong_oneway_direction';
                $other=$index->nearest($point,90,$hit['line']['sign_line_id']);
                if($other){
                    $otherProfile=$other['line']['profiles'][$other['segment']]??$other['line']['profiles'][0];
                    $otherEdge=$other['distance']-$otherProfile['width_m']/2;
                    if($otherEdge<0.3)$reason='mapped_position_on_other_pavement';
                    elseif(abs($otherEdge-$edge)<2)$reason='ambiguous_road_association';
                }
                $z=$raster->height(...$point);
                $a=$hit['line']['points'][$hit['segment']-1];$b=$hit['line']['points'][$hit['segment']];$roadZ=$a[2]+($b[2]-$a[2])*$hit['fraction']+$origin;
                if(abs($roadZ-$z)>4)$reason='mounting_height_unresolved';
            }
            if($reason!==null){$rejected[$reason]=($rejected[$reason]??0)+1;continue;}
            $code=$sign['code'];
            if(preg_match('/^W1-(?:[1-5]|8)$/D',$code)){$turn=self::curveDirection($hit,$sign['grid_bearing']??$sign['bearing']);if($turn){$code.=$turn;$sign['curve_direction_source']='road_geometry';}else{$sign['curve_direction_source']='unresolved_generic_face';}}
            $sign['rendered_code']=$code;$sign['rendered_point']=$point;
            $key=preg_replace('/[^A-Za-z0-9]/','_',substr($code,0,48));if(!preg_match('/^(?:R1-[12]|R2-1\[[0-9]+(?: mph)?\]|W1-(?:[1-5]|8)[LR])$/D',$code))$key.='_'.substr(hash('sha256',$code),0,8);$material=$prefix.'_sign_'.$key;
            if(!isset($meshes[$key])){
                $file='art/signs/'.$key;
                $put($file.'.color.png',BeamSignFace::png($code));
                $put($file.'.dae',BeamSignFace::mesh($material,$code));
                $materials[$material]=['class'=>'Material','name'=>$material,'mapTo'=>$material,'version'=>1.5,'Stages'=>[['baseColorMap'=>$path.$file.'.color.png','roughnessFactor'=>0.85,'metallicFactor'=>0]]];
                $back=$material.'_back';$materials[$back]=['class'=>'Material','name'=>$back,'mapTo'=>$back,'version'=>1,'Stages'=>[['diffuseColor'=>[0.48,0.49,0.50,1]]]];
                $meshes[$key]=true;
            }
            $angle=deg2rad(180-($sign['grid_bearing']??$sign['bearing']));$c=cos($angle);$s=sin($angle);
            $objects[]=['class'=>'TSStatic','name'=>'mapped_sign_'.$sign['id'],'__parent'=>'MissionGroup','shapeName'=>$path.'art/signs/'.$key.'.dae','position'=>[$point[0],$point[1],$z-$origin+2.2],'rotationMatrix'=>[$c,$s,0,-$s,$c,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>true];
            $objects[]=['class'=>'TSStatic','name'=>'mapped_sign_post_'.$sign['id'],'__parent'=>'MissionGroup','shapeName'=>$path.'art/signs/post.dae','position'=>[$point[0],$point[1],$z-$origin],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[0.065,0.065,2.2],'collisionType'=>'None','castShadows'=>true];
            $placed[]=$sign+['mounting_height_m'=>2.2,'dimensions_estimated'=>true,'road'=>$hit['line']['name']??'selected road'];
        }
        if($objects){
            $post=$prefix.'_sign_post';$put('art/signs/post.dae',BeamAssets::shape($post,'bridge-pier'));
            $materials[$post]=['class'=>'Material','name'=>$post,'mapTo'=>$post,'version'=>1,'Stages'=>[['diffuseColor'=>[0.5,0.52,0.54,1]]]];
            $put('art/signs/main.materials.json',json_encode($materials,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        }
        return ['objects'=>$objects,'report'=>['provider'=>$data['source']??'OpenStreetMap','fetch_status'=>$data['fetch_status']??'not_requested','warning'=>$data['warning']??(($data['fetch_status']??'')==='complete'&&!$placed?'No mapped signs met the type, facing and placement requirements; no sign locations were invented.':null),'parsed'=>count($data['signs']??[]),'placed'=>count($placed),'records'=>$placed,'skipped_parse'=>$data['skipped']??[],'skipped_placement'=>$rejected,'truncated'=>$data['truncated']??false,'coverage'=>'All mapped sign labels accepted; unknown artwork uses labeled generic panels. Facing and curve handedness may be inferred; ambiguous road associations remain skipped.','geometry'=>'Generic dimensions/posts and terrain-derived mounting elevation; coordinates and face types are source-derived.']];
    }
}
