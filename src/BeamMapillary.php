<?php
declare(strict_types=1);
namespace SweepLine;

/** Optional Graph v4 map-feature provider. No images, arbitrary URLs or tokens enter exports. */
final class BeamMapillary {
    public const FIELDS='id,geometry,object_type,object_value,first_seen_at,last_seen_at';
    public static function normalize(array $rows):array {
        $signs=[];$features=[];$seen=[];$skipped=0;
        $kinds=['object--support--utility-pole'=>'power_pole','object--street-light'=>'streetlight','object--bench'=>'bench','object--bollard'=>'bollard'];
        foreach(array_slice($rows,0,2000) as $r){
            $id=(string)($r['id']??'');$p=$r['geometry']['coordinates']??[];$value=$r['object_value']??'';
            if(!preg_match('/^[0-9]{1,24}$/D',$id)||isset($seen[$id])||($r['geometry']['type']??'')!=='Point'||count($p)!==2||!is_numeric($p[0])||!is_numeric($p[1])||!is_finite((float)$p[0])||!is_finite((float)$p[1])||abs((float)$p[0])>180||abs((float)$p[1])>90||!is_string($value)||strlen($value)>200){$skipped++;continue;}
            $seen[$id]=true;$base=['id'=>'mly_'.$id,'provider_id'=>$id,'source'=>'Mapillary','source_class'=>$value,'timestamp'=>$r['last_seen_at']??null,'first_seen_at'=>$r['first_seen_at']??null,'location_accuracy'=>'computer-vision estimated map feature'];
            if(($r['object_type']??'')==='trafficsign'||preg_match('/^(regulatory|warning|information|complementary)--/',$value)){
                $code=match(true){
                    (bool)preg_match('/^regulatory--stop--/',$value)=>'R1-1',
                    (bool)preg_match('/^regulatory--(?:yield|give-way)--/',$value)=>'R1-2',
                    (bool)preg_match('/^warning--(?:curve|turn)-left--/',$value)=>'W1-2L',
                    (bool)preg_match('/^warning--(?:curve|turn)-right--/',$value)=>'W1-2R',
                    default=>$value
                };
                // Numeric speed classes do not establish units; preserve the label.
                $signs[]=$base+['code'=>$code,'original_tag'=>$value,'bearing'=>null,'grid_bearing'=>null,'panel_index'=>0,'speed'=>null,'lon'=>(float)$p[0],'lat'=>(float)$p[1]];
            }elseif(isset($kinds[$value])){
                $kind=$kinds[$value];$tags=match($kind){'power_pole'=>['power'=>'pole'],'streetlight'=>['highway'=>'street_lamp'],'bench'=>['amenity'=>'bench'],'bollard'=>['barrier'=>'bollard']};
                $features[]=$base+['kind'=>$kind,'tags'=>$tags,'points'=>[[(float)$p[0],(float)$p[1]]],'node_ids'=>[]];
            }else{$skipped++;}
        }
        return ['signs'=>$signs,'features'=>$features,'unsupported_or_invalid'=>$skipped];
    }
    /** OAuth header only; responses are scrubbed before caching to remove paging URLs. */
    public static function request(array $params,string $token,callable $progress):array {
        if($token===''||preg_match('/[\r\n]/',$token))throw new DownloadFailure('Mapillary token is invalid.',false);
        $url='https://graph.mapillary.com/map_features?'.http_build_query($params);
        $curl=curl_init($url);$body='';$oversized=false;$last=0;
        curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$token,'Accept: application/json'],CURLOPT_USERAGENT=>'SweepLine Mapillary integration',
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body,&$oversized){if(strlen($body)+strlen($chunk)>4194304){$oversized=true;return 0;}$body.=$chunk;return strlen($chunk);},
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function()use($progress,&$last){if(time()-$last>=5){$last=time();$progress(null);}return 0;}]);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            if(!$ok||$status!==200)throw new DownloadFailure($oversized?'Mapillary response exceeds size limit.':'Mapillary HTTP '.$status,!$oversized&&($status===0||in_array($status,[408,429,500,502,503,504],true)));
            try{$data=json_decode($body,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new DownloadFailure('Mapillary invalid JSON response.',true);}
            return self::sanitize($data);
        }finally{curl_close($curl);}
    }
    public static function sanitize(array $data):array {
        if(isset($data['error'])||!isset($data['data'])||!is_array($data['data']))throw new DownloadFailure('Mapillary rejected the feature request.',false);
        $rows=[];foreach(array_slice($data['data'],0,500) as $r)if(is_array($r))$rows[]=array_intersect_key($r,array_flip(['id','geometry','object_type','object_value','first_seen_at','last_seen_at']));
        $cursor=$data['paging']['cursors']['after']??null;
        if(!is_string($cursor)||strlen($cursor)>2048)$cursor=null;
        return ['data'=>$rows,'has_next'=>!empty($data['paging']['next'])||!empty($data['has_next']),'after'=>$cursor??($data['after']??null)];
    }
    public static function collect(array $boxes,string $dir,string $token,callable $progress,?callable $transport=null,?callable $sleep=null,?BeamResourceCache $cache=null):array {
        $rows=[];$ids=[];$requests=[];$truncated=count($boxes)>64;$deadline=microtime(true)+180;$pages=0;$failed=0;
        $cache??=new BeamResourceCache();$transport??=[self::class,'request'];$sleep??=static fn()=>usleep(1000000);
        foreach(array_slice($boxes,0,64) as $box){
            $after=null;$cursors=[];
            do{
                if(++$pages>64||count($rows)>=2000||microtime(true)>$deadline){$truncated=true;break 2;}
                $params=['bbox'=>implode(',',$box),'fields'=>self::FIELDS,'limit'=>500];if($after!==null)$params['after']=$after;
                $file=$dir.'/mapillary-'.substr(hash('sha256',json_encode($params)),0,16).'.json';
                try{
                    $cache->fetch(BeamResourceCache::key('mapillary-v1',hash('sha256',$token),json_encode($params)),$file,
                        static function($path)use($params,$token,$progress,$transport,$sleep){
                            for($attempt=1;$attempt<=3;$attempt++){
                                $progress('Fetching Mapillary map features (attempt '.$attempt.'/3)');
                                try{$data=self::sanitize($transport($params,$token,$progress));file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR));return;}
                                catch(JobCancelled $e){throw $e;}
                                catch(DownloadFailure $e){if(!$e->retryable||$attempt===3)throw $e;for($i=0;$i<$attempt*2;$i++){$progress(null);$sleep();}}
                            }
                        },
                        static function($path){$data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($data['data']??null)||(isset($data['paging'])||isset($data['error'])))throw new \RuntimeException('Invalid Mapillary cache.');},$progress);
                    $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                    $requests[]=['status'=>'complete','features'=>count($data['data']),'bbox'=>$box];
                    foreach($data['data'] as $r){$id=(string)($r['id']??'');if(!isset($ids[$id])){$ids[$id]=true;$rows[]=$r;if(count($rows)>=2000){$truncated=true;break;}}}
                    $next=$data['after']??null;
                    if(!empty($data['has_next'])&&(!$next||isset($cursors[$next]))){$truncated=true;break;}
                    if($next)$cursors[$next]=true;$after=!empty($data['has_next'])?$next:null;
                }catch(JobCancelled $e){throw $e;}
                catch(\Throwable $e){$failed++;$reason=BeamOsm::failureReason($e);$requests[]=['status'=>'unavailable','reason'=>$reason,'bbox'=>$box];$progress('Mapillary map features unavailable: '.$reason);if($e instanceof DownloadFailure&&!$e->retryable)break 2;if($failed>=3)break 2;break;}
            }while($after!==null);
        }
        $out=self::normalize($rows);$out['status']=$failed?($rows?'partial':'unavailable'):'complete';$out['truncated']=$truncated;
        $out['requests']=$requests;$out['warning']=$failed?'Mapillary coverage is incomplete; see provider diagnostics.':($truncated?'Mapillary reached its request or feature limit; coverage may be incomplete.':null);
        return $out;
    }
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $transport=null,?BeamResourceCache $cache=null):array {
        $token=(new MapillarySettings($db))->token();
        if($token==='')return ['signs'=>[],'features'=>[],'status'=>'not_configured','requests'=>[],'truncated'=>false,'warning'=>null];
        try{
            $q=$db->prepare("WITH corridor AS (SELECT ST_Transform(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90),4326) g)
                SELECT ST_XMin(t.geom) w,ST_YMin(t.geom) s,ST_XMax(t.geom) e,ST_YMax(t.geom) n FROM corridor c CROSS JOIN LATERAL ST_SquareGrid(0.02,c.g) t WHERE ST_Intersects(t.geom,c.g) ORDER BY t.i,t.j LIMIT 65");
            $lines=[$plan['points']];foreach($plan['carriageways']??[] as $track)$lines[]=$track['points'];
            $q->execute([json_encode(['type'=>'MultiLineString','coordinates'=>$lines],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $boxes=array_map(static fn($r)=>array_map('floatval',[$r['w'],$r['s'],$r['e'],$r['n']]),$q->fetchAll(\PDO::FETCH_ASSOC));
            $result=self::collect($boxes,$dir,$token,$progress,$transport,null,$cache);
            $all=array_merge(array_map(static fn($s)=>[$s['lon'],$s['lat']],$result['signs']),array_map(static fn($f)=>$f['points'][0],$result['features']));
            if($all){
                $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)');
                $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>$all],JSON_THROW_ON_ERROR),$plan['epsg']]);$p=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];$i=0;
                foreach($result['signs'] as &$s){$s['point']=[$p[$i][0]-$plan['bounds'][0],$p[$i][1]-$plan['bounds'][1]];$i++;}unset($s);
                foreach($result['features'] as &$f){$f['points']=[[$p[$i][0]-$plan['bounds'][0],$p[$i][1]-$plan['bounds'][1]]];$i++;}unset($f);
            }
            return $result;
        }catch(JobCancelled $e){throw $e;}
        catch(\Throwable $e){return ['signs'=>[],'features'=>[],'status'=>'unavailable','requests'=>[],'truncated'=>false,'warning'=>'Mapillary map features could not be prepared.'];}
    }
    /** Prefer existing OSM geometry. Deduplicate matching classes within four metres. */
    public static function append(array $existing,array $extra,bool $signs):array {
        $grid=[];$out=[];$duplicates=0;
        foreach(array_merge($existing,$extra) as $r){
            $p=$signs?($r['point']??null):($r['points'][0]??null);
            if(!$p){$out[]=$r;continue;}$type=$signs?$r['code']:$r['kind'];$x=(int)floor($p[0]/4);$y=(int)floor($p[1]/4);$duplicate=false;
            for($dx=-1;$dx<=1;$dx++)for($dy=-1;$dy<=1;$dy++)foreach($grid[$type.':'.($x+$dx).':'.($y+$dy)]??[] as $v){
                if($v['source']!==$r['source']&&hypot($p[0]-$v['p'][0],$p[1]-$v['p'][1])<4)$duplicate=true;
                if($v['source']===$r['source']&&$v['id']===$r['id'])$duplicate=true;
            }
            if($duplicate){$duplicates++;continue;}
            $out[]=$r;$grid[$type.':'.$x.':'.$y][]=['source'=>$r['source'],'id'=>$r['id'],'p'=>$p];
        }
        return ['items'=>$out,'duplicates'=>$duplicates];
    }
    public static function apply(array &$plan,array $data):void {
        $signs=self::append($plan['traffic_sign_data']['signs']??[],$data['signs'],true);
        $features=self::append($plan['roadside_data']['features']??[],$data['features'],false);
        $plan['traffic_sign_data']['signs']=array_slice($signs['items'],0,1000);$plan['roadside_data']['features']=array_slice($features['items'],0,2000);
        $report=array_diff_key($data,array_flip(['signs','features']));
        $report+=['signs'=>count($data['signs']),'point_features'=>count($data['features']),'duplicates'=>$signs['duplicates']+$features['duplicates'],'attribution'=>'Mapillary','location_method'=>'Computer-vision estimated map features; source dates retained.'];
        $plan['mapillary_data']=$report;
        if($data['signs']){$plan['traffic_sign_data']['source']='OpenStreetMap and Mapillary map features';if(($plan['traffic_sign_data']['fetch_status']??'')!=='complete')$plan['traffic_sign_data']['fetch_status']='partial';}
        if($data['features']&&($plan['roadside_data']['fetch_status']??'')!=='complete')$plan['roadside_data']['fetch_status']='partial';
        if(count($signs['items'])>1000||count($features['items'])>2000)$plan['mapillary_data']['truncated']=true;
    }
}
