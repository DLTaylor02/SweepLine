<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Optional full-square hydrography; all network work stays in the export worker. */
final class BeamWaterSources
{
    public const USGS='https://hydro.nationalmap.gov/arcgis/rest/services/3DHP_all/MapServer/';
    public static function request(string $url,string $file,?string $body,callable $progress,?callable $download=null,?callable $sleep=null,string $format='geojson'): array
    {
        $validate=static function($path)use($format){
            $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            if(isset($data['error']))throw new DownloadFailure('Hydrography provider returned an error.',in_array((int)($data['error']['code']??0),[429,500,502,503,504],true));
            if($format==='osm'&&isset($data['remark']))throw new DownloadFailure('Hydrography provider returned incomplete data.',true);
            if($format==='osm'){$ok=isset($data['elements'])&&is_array($data['elements'])&&!isset($data['remark']);}
            else $ok=($data['type']??'')==='FeatureCollection'&&isset($data['features'])&&is_array($data['features']);
            if(!$ok)throw new \RuntimeException('Hydrography provider returned incomplete data.');
        };
        $produce=static function($path)use($url,$body,$progress,$download,$sleep,$validate){
            for($attempt=1;$attempt<=3;$attempt++){
                $progress('Fetching mapped water (attempt '.$attempt.'/3)');
                try{($download??[Download::class,'file'])($url,$path,16777216,$body,$progress);$validate($path);return;}
                catch(JobCancelled $e){throw $e;}
                catch(DownloadFailure $e){if(!$e->retryable||$attempt===3)throw $e;for($i=0;$i<$attempt*3;$i++){$progress(null);($sleep??static fn()=>usleep(1000000))();}}
            }
        };
        if($download){$produce($file);$meta=['cache_hit'=>false,'fetched_utc'=>gmdate('c'),'sha256'=>hash_file('sha256',$file)];}
        else $meta=(new BeamResourceCache())->fetch(BeamResourceCache::key('water-'.$format,$url,$body),$file,$produce,$validate,$progress);
        return ['data'=>json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR),'provenance'=>array_intersect_key($meta,array_flip(['cache_hit','fetched_utc','sha256','bytes']))];
    }
    /** Join genuine member-way endpoints; never invent closing edges for broken relations. */
    public static function osm(array $data): array
    {
        $features=[];$used=[];$rejected=0;
        foreach($data['elements']??[] as $e)if(($e['type']??'')==='relation')foreach($e['members']??[] as $m)if(($m['type']??'')==='way')$used[(string)($m['ref']??'')]=true;
        $coords=static function($g):array{$out=[];foreach($g as $p){if(!isset($p['lon'],$p['lat'])||!is_numeric($p['lon'])||!is_numeric($p['lat'])||!is_finite((float)$p['lon'])||!is_finite((float)$p['lat'])||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90)return [];$out[]=[(float)$p['lon'],(float)$p['lat']];}return $out;};
        foreach($data['elements']??[] as $e){
            $tags=$e['tags']??[];
            if(($tags['natural']??'')!=='water'&&($tags['landuse']??'')!=='reservoir'&&($tags['waterway']??'')!=='riverbank')continue;
            if(in_array($tags['intermittent']??'',['yes','true','1'],true)||isset($tags['seasonal'])&&$tags['seasonal']!=='no'){$rejected++;continue;}
            $kind=$tags['water']??(($tags['waterway']??'')==='riverbank'?'river':(($tags['landuse']??'')==='reservoir'?'reservoir':'lake'));
            $polygons=[];
            if(($e['type']??'')==='way'){
                if(isset($used[(string)$e['id']]))continue;$ring=$coords($e['geometry']??[]);
                if(count($ring)<4||$ring[0]!==end($ring)){$rejected++;continue;}$polygons=[[$ring]];
            }elseif(($e['type']??'')==='relation'){
                $groups=['outer'=>[],'inner'=>[]];$bad=false;
                foreach($e['members']??[] as $m){if(($m['type']??'')==='relation'){$bad=true;break;}if(($m['type']??'')!=='way')continue;$role=$m['role']??'outer';if($role==='')$role='outer';if(!isset($groups[$role]))continue;$part=$coords($m['geometry']??[]);if(count($part)<2){$bad=true;break;}$groups[$role][]=$part;}
                $closed=[];
                foreach($groups as $role=>$parts){$closed[$role]=[];
                    while($parts){$ring=array_shift($parts);$attempts=0;
                        while($ring[0]!==end($ring)&&$attempts++<2000){$matches=[];foreach($parts as $i=>$p){if(end($ring)===$p[0])$matches[]=[$i,false];elseif(end($ring)===end($p))$matches[]=[$i,true];}
                            if(count($matches)!==1){$bad=true;break;}[$i,$reverse]=$matches[0];$part=$reverse?array_reverse($parts[$i]):$parts[$i];array_splice($parts,$i,1);array_push($ring,...array_slice($part,1));
                        }
                        if($bad||count($ring)<4||$ring[0]!==end($ring)){$bad=true;break;}$closed[$role][]=$ring;
                    }
                }
                foreach($closed['outer']??[] as $ring)$polygons[]=[$ring];
                foreach($closed['inner']??[] as $hole){$found=false;foreach($polygons as &$polygon)if(BeamPolygon::inside($hole[0],$polygon[0])){$polygon[]=$hole;$found=true;break;}unset($polygon);if(!$found)$bad=true;}
                if($bad||!$polygons){$rejected++;continue;}
            }else continue;
            $features[]=['id'=>'osm:'.$e['type'].':'.$e['id'],'source'=>'OpenStreetMap','kind'=>in_array($kind,['river','canal'],true)?$kind:'lake','name'=>(string)($tags['name']??''),'source_key'=>(string)$e['id'],'geometry'=>['type'=>'MultiPolygon','coordinates'=>$polygons]];
        }
        return ['features'=>$features,'rejected'=>$rejected];
    }
    public static function fetch(PDO $db,array $plan,string $dir,callable $progress,?callable $download=null,?callable $sleep=null): array
    {
        $records=[];$providers=[];$warnings=[];$truncated=false;$osmRejected=0;
        $geometry=implode(',',array_map(static fn($v)=>sprintf('%.3F',$v),$plan['bounds']));
        foreach([60=>129,50=>257] as $layer=>$limit){
            try{
                $fields=$layer===60?'id3dhp,gnisidlabel,featuretype,featuredate':'id3dhp,waterbodyid3dhp,featuretype,flowdirection';
                $args=['f'=>'geojson','where'=>$layer===60?'featuretype IN (1,2,3,4)':'featuretype IN (1,2)','geometry'=>$geometry,'geometryType'=>'esriGeometryEnvelope','inSR'=>$plan['epsg'],'spatialRel'=>'esriSpatialRelIntersects','outSR'=>4326,'outFields'=>$fields,'returnGeometry'=>'true','returnZ'=>'false','returnM'=>'false','geometryPrecision'=>7,'maxAllowableOffset'=>0.00001,'resultRecordCount'=>$limit,'orderByFields'=>$layer===60?'areasqkm DESC':'lengthkm DESC'];
                $url=self::USGS.$layer.'/query?'.http_build_query($args);$result=self::request($url,$dir.'/water-usgs-'.$layer.'.json',null,$progress,$download,$sleep);
                $features=$result['data']['features'];$partial=!empty($result['data']['exceededTransferLimit'])||count($features)>=$limit;$truncated=$truncated||$partial;
                $providers['USGS-'.$layer]=['status'=>'complete','features'=>count($features),'truncated'=>$partial]+$result['provenance'];
                foreach(array_slice($features,0,$limit-1) as $feature){$p=$feature['properties']??[];$g=$feature['geometry']??null;if(!$g)continue;
                    $records[]=['id'=>'usgs:'.$layer.':'.($p['id3dhp']??count($records)),'source'=>'USGS 3DHP/NHD','source_key'=>(string)($p['id3dhp']??''),'body_key'=>(string)($p['waterbodyid3dhp']??''),'kind'=>$layer===50?'flowline':match((int)($p['featuretype']??3)){1=>'river',2=>'canal',default=>'lake'},'name'=>(string)($p['gnisidlabel']??''),'source_date'=>$p['featuredate']??null,'geometry'=>$g];
                }
            }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$providers['USGS-'.$layer]=['status'=>'unavailable'];$warnings[]='USGS '.($layer===60?'water bodies':'river profiles').' were unavailable.';}
        }
        try{
            $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_MakeEnvelope(?,?,?,?,CAST(? AS integer)),4326),7)');$q->execute([...$plan['bounds'],$plan['epsg']]);
            $ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'][0];
            $south=min(array_column($ring,1))-0.002;$north=max(array_column($ring,1))+0.002;$west=min(array_column($ring,0))-0.002;$east=max(array_column($ring,0))+0.002;
            $bbox=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),[$south,$west,$north,$east]));$lat=($south+$north)/2;$lon=($west+$east)/2;
            $query='[out:json][timeout:60][maxsize:16777216];(';
            foreach(['["natural"="water"]','["landuse"="reservoir"]','["waterway"="riverbank"]'] as $filter)$query.='way'.$filter.'('.$bbox.');rel'.$filter.'('.$bbox.');';
            $query.=');out geom;is_in('.sprintf('%.7F,%.7F',$lat,$lon).')->.cover;(';
            foreach(['["natural"="water"]','["landuse"="reservoir"]'] as $filter)$query.='way(pivot.cover)'.$filter.';rel(pivot.cover)'.$filter.';';
            $query.=');out geom;';
            $result=self::request(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/water-osm.json',http_build_query(['data'=>$query]),$progress,$download,$sleep,'osm');
            $seen=[];$unique=[];foreach($result['data']['elements'] as $element){$key=($element['type']??'').':'.($element['id']??'');if(isset($seen[$key]))continue;$seen[$key]=true;$unique[]=$element;}
            $parsed=self::osm(['elements'=>$unique]);$osmRejected=$parsed['rejected'];$truncated=$truncated||count($parsed['features'])>128;
            array_push($records,...array_slice($parsed['features'],0,128));$providers['OpenStreetMap']=['status'=>'complete','features'=>count($parsed['features'])]+$result['provenance'];
        }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$providers['OpenStreetMap']=['status'=>'unavailable'];$warnings[]='OpenStreetMap water boundaries were unavailable.';}
        try{$normalized=self::normalize($db,$records,$plan,$progress);}
        catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$normalized=['features'=>[],'flowlines'=>[],'duplicates_skipped'=>0,'truncated'=>false];$warnings[]='Water geometry could not be normalized; no replacement water was invented.';}
        $truncated=$truncated||$normalized['truncated'];$normalized['truncated']=$truncated;if($truncated)$warnings[]='Water data reached a feature or geometry budget; coverage may be incomplete.';
        return $normalized+['providers'=>$providers,'source_rejections'=>$osmRejected,'warning'=>implode(' ',$warnings),'coverage'=>'Full exported square; USGS baseline with nonduplicate OSM additions','attribution'=>'USGS 3DHP/NHD; OpenStreetMap contributors (ODbL)'];
    }
    public static function normalize(PDO $db,array $records,array $plan,callable $progress): array
    {
        $points=0;$valid=[];
        $count=static function($coords)use(&$count):int{if(!is_array($coords)||!$coords)return 0;if(is_numeric($coords[0]??null))return 1;$n=0;foreach($coords as $p)$n+=$count($p);return $n;};
        foreach($records as $r){$n=$count($r['geometry']['coordinates']??[]);if(!$n)continue;if($points+$n>100000)break;$points+=$n;$valid[]=$r;}
        if(!$valid)return ['features'=>[],'flowlines'=>[],'duplicates_skipped'=>0,'truncated'=>false];
        $progress('Preparing water boundaries and islands');
        $sql="WITH params AS (SELECT CAST(? AS integer) AS srid,ST_MakeEnvelope(?,?,?,?,CAST(? AS integer)) AS box),
            raw AS (SELECT item,ord,ST_Transform(ST_Force2D(ST_SetSRID(ST_GeomFromGeoJSON((item->'geometry')::text),4326)),p.srid) AS g,p.box
                FROM jsonb_array_elements(CAST(? AS jsonb)) WITH ORDINALITY r(item,ord) CROSS JOIN params p),
            parts AS (SELECT item,ord,(ST_Dump(ST_CollectionExtract(ST_Intersection(ST_MakeValid(g),box),CASE WHEN item->>'kind'='flowline' THEN 2 ELSE 3 END))).geom AS g FROM raw),
            ranked AS (SELECT item,ord,ST_SimplifyPreserveTopology(g,?) AS g,row_number() OVER(ORDER BY ord,ST_Area(g) DESC) AS rank FROM parts WHERE NOT ST_IsEmpty(g)),
            decided AS (SELECT a.*,CASE WHEN item->>'kind'='flowline' THEN false ELSE EXISTS(SELECT 1 FROM ranked b
                WHERE b.rank<a.rank AND b.item->>'kind'<>'flowline' AND ST_Intersects(a.g,b.g) AND ST_Area(ST_Intersection(a.g,b.g))>0.5*LEAST(ST_Area(a.g),ST_Area(b.g))) END AS duplicate FROM ranked a),
            cut AS (SELECT a.item,a.rank,a.duplicate,(ST_Dump(ST_CollectionExtract(
                CASE WHEN a.duplicate OR a.item->>'kind'='flowline' THEN a.g ELSE ST_Difference(a.g,
                    COALESCE((SELECT ST_UnaryUnion(ST_Collect(b.g)) FROM ranked b WHERE b.rank<a.rank AND b.item->>'kind'<>'flowline' AND ST_Intersects(a.g,b.g)),ST_GeomFromText('POLYGON EMPTY',ST_SRID(a.g)))) END,
                CASE WHEN a.item->>'kind'='flowline' THEN 2 ELSE 3 END))).geom AS g FROM decided a)
            SELECT (item-'geometry')::text AS metadata,duplicate,CASE WHEN duplicate THEN NULL ELSE ST_AsGeoJSON(ST_Translate(g,?,?),6,0) END AS geometry
            FROM cut WHERE (item->>'kind'='flowline' AND ST_Length(g)>=5) OR (item->>'kind'<>'flowline' AND ST_Area(g)>=10) ORDER BY rank LIMIT 641";
        $q=$db->prepare($sql);$q->execute([$plan['epsg'],...$plan['bounds'],$plan['epsg'],json_encode($valid,JSON_THROW_ON_ERROR),max(0.5,min(2,($plan['bounds'][2]-$plan['bounds'][0])/$plan['resolution']/4)),-$plan['bounds'][0],-$plan['bounds'][1]]);
        $features=[];$lines=[];$duplicates=0;$vertices=0;$truncated=count($valid)<count($records);$rows=0;
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$rows>640){$truncated=true;break;}if(in_array($row['duplicate'],[true,1,'1','t'],true)){$duplicates++;continue;}
            $r=json_decode($row['metadata'],true,512,JSON_THROW_ON_ERROR);$g=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR);$n=$count($g['coordinates']);
            if($vertices+$n>20000||$n>4096){$truncated=true;continue;}$vertices+=$n;
            if($r['kind']==='flowline'){$r['points']=$g['coordinates'];$lines[]=$r;}
            else {$r['rings']=array_map(static function($ring){if(count($ring)>1&&$ring[0]===end($ring))array_pop($ring);return $ring;},$g['coordinates']);$features[]=$r;}
        }
        return ['features'=>$features,'flowlines'=>$lines,'duplicates_skipped'=>$duplicates,'truncated'=>$truncated];
    }
}
