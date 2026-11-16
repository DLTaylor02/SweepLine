<?php
declare(strict_types=1);
namespace SweepLine;

/** Optional Graph v4 map-feature provider. No images, arbitrary URLs or tokens enter exports. */
final class BeamMapillary {
    public const FIELDS='id,geometry,object_type,object_value,first_seen_at,last_seen_at';
    public static function normalize(array $rows):array {
        $signs=[];$features=[];$seen=[];$skipped=0;
        $kinds=['object--support--utility-pole'=>'power_pole','object--street-light'=>'streetlight','object--bench'=>'bench','object--bollard'=>'bollard','construction--barrier--bollard'=>'bollard','object--bus-stop'=>'bus_stop','object--shelter'=>'shelter','object--mile-marker'=>'mile_marker','object--power-tower'=>'power_tower'];
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
                $kind=$kinds[$value];$tags=match($kind){'power_pole'=>['power'=>'pole'],'streetlight'=>['highway'=>'street_lamp'],'bench'=>['amenity'=>'bench'],'bollard'=>['barrier'=>'bollard'],'bus_stop'=>['highway'=>'bus_stop'],'shelter'=>['amenity'=>'shelter'],'mile_marker'=>['highway'=>'milestone'],'power_tower'=>['power'=>'tower']};
                $features[]=$base+['kind'=>$kind,'tags'=>$tags,'points'=>[[(float)$p[0],(float)$p[1]]],'node_ids'=>[]];
            }else{$skipped++;}
        }
        return ['signs'=>$signs,'features'=>$features,'unsupported_or_invalid'=>$skipped];
    }
    /** OAuth header only; responses are scrubbed before caching to remove paging URLs. */
    public static function request(array $params,string $token,callable $progress):array {
        return self::sanitize(self::graph('map_features',$params,$token,$progress));
    }
    /** Shared bounded, authenticated Graph transport; callers allowlist/cache their own response fields. */
    public static function graph(string $endpoint,array $params,string $token,callable $progress):array {
        if(!preg_match('/^(?:images|map_features|[0-9]{1,24})$/D',$endpoint))throw new \InvalidArgumentException('Unsupported Mapillary endpoint.');
        if($token===''||preg_match('/[\r\n]/',$token))throw new DownloadFailure('Mapillary token is invalid.',false);
        $url='https://graph.mapillary.com/'.$endpoint.'?'.http_build_query($params);
        $curl=curl_init($url);$body='';$oversized=false;$last=0;
        curl_setopt_array($curl,[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Authorization: OAuth '.$token,'Accept: application/json'],CURLOPT_USERAGENT=>'SweepLine Mapillary integration',
            CURLOPT_WRITEFUNCTION=>static function($h,$chunk)use(&$body,&$oversized){if(strlen($body)+strlen($chunk)>4194304){$oversized=true;return 0;}$body.=$chunk;return strlen($chunk);},
            CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function()use($progress,&$last){if(time()-$last>=5){$last=time();$progress(null);}return 0;}]);
        try{$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
            if(!$ok||$status!==200)throw new DownloadFailure($oversized?'Mapillary response exceeds size limit.':'Mapillary HTTP '.$status,!$oversized&&($status===0||in_array($status,[408,429,500,502,503,504],true)));
            try{$data=json_decode($body,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new DownloadFailure('Mapillary invalid JSON response.',true);}
            if(!is_array($data)||isset($data['error']))throw new DownloadFailure('Mapillary rejected the request.',false);
            return $data;
        }finally{curl_close($curl);}
    }
    public static function sanitize(array $data):array {
        if(isset($data['error'])||!isset($data['data'])||!is_array($data['data']))throw new DownloadFailure('Mapillary rejected the feature request.',false);
        $rows=[];foreach(array_slice($data['data'],0,500) as $r)if(is_array($r))$rows[]=array_intersect_key($r,array_flip(['id','geometry','object_type','object_value','first_seen_at','last_seen_at']));
        $cursor=$data['paging']['cursors']['after']??null;
        if(!is_string($cursor)||strlen($cursor)>2048)$cursor=null;
        return ['data'=>$rows,'has_next'=>!empty($data['paging']['next'])||!empty($data['has_next']),'after'=>$cursor??($data['after']??null)];
    }
    public static function collect(array $boxes,string $dir,string $token,callable $progress,?callable $transport=null,?callable $sleep=null,?BeamResourceCache $cache=null,?callable $filter=null):array {
        $items=['signs'=>[],'features'=>[]];$ids=[];$requests=[];$truncated=false;$failed=0;$unsupported=0;$classes=[];$omitted=0;$outside=0;$areas=0;
        $cache??=new BeamResourceCache();$transport??=[self::class,'request'];$sleep??=static fn()=>usleep(1000000);
        foreach($boxes as $area=>$box){
            $after=null;$cursors=[];$page=0;$areaComplete=true;
            do{
                $page++;$progress('Mapillary area '.($area+1).' of '.count($boxes).' · page '.$page);
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
                    $requests[]=['status'=>'complete','features'=>count($data['data']),'bbox'=>$box,'page'=>$page];
                    foreach($data['data'] as $r){$value=$r['object_value']??'unknown';if(is_string($value)&&strlen($value)<=200){if(isset($classes[$value])||count($classes)<4096)$classes[$value]=($classes[$value]??0)+1;}}
                    $parsed=self::normalize($data['data']);$unsupported+=$parsed['unsupported_or_invalid'];
                    if($filter){$parsed=$filter($parsed);$outside+=$parsed['outside_corridor']??0;}
                    foreach(['signs'=>1000,'features'=>2000] as $key=>$limit)foreach($parsed[$key] as $r){
                        $id=$r['id'];if(isset($ids[$id]))continue;
                        // Rendering budgets remain unchanged; keep fetching every remaining page.
                        if(count($items[$key])>=$limit){$omitted++;$truncated=true;continue;}
                        $ids[$id]=true;$items[$key][]=$r;
                    }
                    $next=$data['after']??null;
                    if(!empty($data['has_next'])&&(!is_string($next)||$next===''||isset($cursors[$next]))){$failed++;$truncated=true;$areaComplete=false;$requests[]=['status'=>'unavailable','reason'=>'missing or repeated pagination cursor','bbox'=>$box];break;}
                    if($next)$cursors[$next]=true;$after=!empty($data['has_next'])?$next:null;
                }catch(JobCancelled $e){throw $e;}
                catch(\Throwable $e){$failed++;$areaComplete=false;$reason=BeamOsm::failureReason($e);$requests[]=['status'=>'unavailable','reason'=>$reason,'bbox'=>$box];$progress('Mapillary map features unavailable: '.$reason);if($e instanceof DownloadFailure&&!$e->retryable)break 2;break;}
            }while($after!==null);
            if($areaComplete)$areas++;
        }
        return $items+['unsupported_or_invalid'=>$unsupported,'source_classes'=>$classes,'outside_corridor'=>$outside,'render_budget_omitted'=>$omitted,
            'areas_total'=>count($boxes),'areas_complete'=>$areas,'acquisition_complete'=>$failed===0&&$areas===count($boxes),
            'status'=>$failed?($items['signs']||$items['features']?'partial':'unavailable'):'complete','truncated'=>$truncated,
            'requests'=>$requests,'warning'=>$failed?'Mapillary coverage is incomplete; see provider diagnostics.':($truncated?'Mapillary acquisition completed, but some objects exceeded the unchanged rendering budget.':null)];
    }
    /** Per-page projection/filtering prevents unrelated city objects consuming render budgets. */
    public static function project(\PDO $db,array $plan,array $data,array $lines):array {
        $all=array_merge(array_map(static fn($s)=>[$s['lon'],$s['lat']],$data['signs']),array_map(static fn($f)=>$f['points'][0],$data['features']));
        if(!$all)return $data;
        $q=$db->prepare("WITH input AS (SELECT ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)) p,ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)) road)
            SELECT d.path[1] idx,ST_X(d.geom) x,ST_Y(d.geom) y,ST_DWithin(d.geom,input.road,90) keep FROM input CROSS JOIN LATERAL ST_Dump(input.p) d ORDER BY d.path");
        $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>$all],JSON_THROW_ON_ERROR),$plan['epsg'],json_encode(['type'=>'MultiLineString','coordinates'=>$lines],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $projected=$q->fetchAll(\PDO::FETCH_ASSOC);$i=0;$out=$data;$out['signs']=[];$out['features']=[];$out['outside_corridor']=0;
        foreach(['signs','features'] as $key)foreach($data[$key] as $record){
            $p=$projected[$i++];if(!in_array($p['keep'],[true,1,'t','1'],true)){$out['outside_corridor']++;continue;}
            $point=[(float)$p['x']-$plan['bounds'][0],(float)$p['y']-$plan['bounds'][1]];
            if($key==='signs')$record['point']=$point;else $record['points']=[$point];$out[$key][]=$record;
        }
        return $out;
    }
    /** Rank bends from 30 m approach/departure chords; suppress overlapping corner boxes. */
    public static function corners(array $lines):array {
        $candidates=[];
        foreach($lines as $points){
            $chain=[0.];for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
            $at=static function(float $distance)use($points,$chain):array{
                $lo=0;$hi=count($chain)-1;while($hi-$lo>1){$m=intdiv($lo+$hi,2);if($chain[$m]<$distance)$lo=$m;else $hi=$m;}
                $t=($distance-$chain[$lo])/max(.000001,$chain[$hi]-$chain[$lo]);return [$points[$lo][0]+$t*($points[$hi][0]-$points[$lo][0]),$points[$lo][1]+$t*($points[$hi][1]-$points[$lo][1])];
            };
            for($i=1;$i<count($points)-1;$i++){
                if($chain[$i]<15||end($chain)-$chain[$i]<15)continue;
                $a=$at(max(0,$chain[$i]-30));$b=$points[$i];$c=$at(min(end($chain),$chain[$i]+30));
                $u=[$b[0]-$a[0],$b[1]-$a[1]];$v=[$c[0]-$b[0],$c[1]-$b[1]];$length=hypot(...$u)*hypot(...$v);if($length<1)continue;
                $angle=rad2deg(acos(max(-1,min(1,($u[0]*$v[0]+$u[1]*$v[1])/$length))));
                if($angle>=12)$candidates[]=['point'=>[$b[0],$b[1]],'angle'=>$angle];
            }
        }
        usort($candidates,static fn($a,$b)=>$b['angle']<=>$a['angle']);$out=[];$grid=[];
        foreach($candidates as $c){$p=$c['point'];$x=(int)floor($p[0]/90);$y=(int)floor($p[1]/90);$near=false;
            for($dx=-1;$dx<=1;$dx++)for($dy=-1;$dy<=1;$dy++)foreach($grid[($x+$dx).':'.($y+$dy)]??[] as $v)if(hypot($p[0]-$v[0],$p[1]-$v[1])<90)$near=true;
            if(!$near){$out[]=$p;$grid[$x.':'.$y][]=$p;}
        }
        return $out;
    }
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $transport=null,?BeamResourceCache $cache=null):array {
        $token=(new MapillarySettings($db))->token();
        if($token==='')return ['signs'=>[],'features'=>[],'status'=>'not_configured','requests'=>[],'truncated'=>false,'warning'=>null];
        try{
            $q=$db->prepare("WITH corridor AS (SELECT ST_Transform(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90),4326) g)
                SELECT ST_XMin(piece.g) w,ST_YMin(piece.g) s,ST_XMax(piece.g) e,ST_YMax(piece.g) n FROM corridor c CROSS JOIN LATERAL ST_SquareGrid(0.005,c.g) t CROSS JOIN LATERAL (SELECT ST_Envelope(ST_Intersection(t.geom,c.g)) g) piece WHERE ST_Intersects(t.geom,c.g) AND ST_Area(piece.g)>0 ORDER BY t.i,t.j");
            $lines=[$plan['points']];foreach($plan['carriageways']??[] as $track)$lines[]=$track['points'];
            $q->execute([json_encode(['type'=>'MultiLineString','coordinates'=>$lines],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $boxes=array_map(static fn($r)=>array_map('floatval',[$r['w'],$r['s'],$r['e'],$r['n']]),$q->fetchAll(\PDO::FETCH_ASSOC));
            $corners=self::corners($lines);$cornerBoxes=[];
            if($corners){
                $q=$db->prepare('SELECT ST_XMin(b.g) w,ST_YMin(b.g) s,ST_XMax(b.g) e,ST_YMax(b.g) n FROM ST_Dump(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer))) d CROSS JOIN LATERAL (SELECT ST_Envelope(ST_Transform(ST_Buffer(d.geom,90),4326)) g) b ORDER BY d.path');
                $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>$corners],JSON_THROW_ON_ERROR),$plan['epsg']]);
                $cornerBoxes=array_map(static fn($r)=>array_map('floatval',[$r['w'],$r['s'],$r['e'],$r['n']]),$q->fetchAll(\PDO::FETCH_ASSOC));
            }
            $unique=[];$boxes=array_values(array_filter(array_merge($cornerBoxes,$boxes),static function($box)use(&$unique){$key=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$box));if(isset($unique[$key]))return false;$unique[$key]=true;return true;}));
            $result=self::collect($boxes,$dir,$token,$progress,$transport,null,$cache,static fn($page)=>self::project($db,$plan,$page,$lines));
            $result['request_strategy']='Corner boxes first, then complete corridor; 90 m centreline buffer covers 200 ft beyond supported pavement edges.';$result['corner_boxes']=count($cornerBoxes);
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
        $report+=['signs'=>count($data['signs']),'point_features'=>count($data['features']),'duplicates'=>$signs['duplicates']+$features['duplicates'],'attribution'=>'Mapillary','location_method'=>'Computer-vision estimated map features; source dates retained.','capabilities'=>['point_objects'=>'Signs, utility poles, towers, streetlights, bollards, benches, bus stops, shelters and mile markers when positioned classes are returned.','paths'=>'The Graph map-feature datasource supplies points, not continuous guardrail, wire, fence, wall, hedge or railway paths or parking polygons. Those remain sourced from OSM.']];
        $plan['mapillary_data']=$report;
        if($data['signs']){$plan['traffic_sign_data']['source']='OpenStreetMap and Mapillary map features';if(($plan['traffic_sign_data']['fetch_status']??'')!=='complete')$plan['traffic_sign_data']['fetch_status']='partial';}
        if($data['features']&&($plan['roadside_data']['fetch_status']??'')!=='complete')$plan['roadside_data']['fetch_status']='partial';
        if(count($signs['items'])>1000||count($features['items'])>2000)$plan['mapillary_data']['truncated']=true;
    }
}
