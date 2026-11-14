<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamRoadsideData {
    public static function kind(array $t):?string{
        if(in_array($t['power']??'', ['pole','tower','line','minor_line'],true))return ['pole'=>'power_pole','tower'=>'power_tower','line'=>'power_line','minor_line'=>'power_line'][$t['power']];
        if(($t['highway']??'')==='street_lamp')return 'streetlight';
        if(in_array($t['barrier']??'',['fence','wall','retaining_wall','hedge','gate','lift_gate','bollard'],true))return $t['barrier']==='lift_gate'?'gate':$t['barrier'];
        if(($t['railway']??'')==='rail')return 'rail';
        if(($t['railway']??'')==='level_crossing')return 'level_crossing';
        if(($t['highway']??'')==='milestone')return 'mile_marker';
        if(($t['highway']??'')==='bus_stop')return 'bus_stop';
        if(in_array($t['amenity']??'',['shelter','bench','parking'],true))return $t['amenity'];
        return null;
    }
    public static function parse(array $data):array{
        $features=[];$skipped=[];$points=0;$seen=[];$truncated=count($data['elements']??[])>2000;
        foreach(array_slice($data['elements']??[],0,2000) as $e){
            $t=$e['tags']??[];if(!is_array($t))continue;$kind=self::kind($t);$type=$e['type']??'';$id=$e['id']??null;
            if(!$kind||!in_array($type,['node','way'],true)||!is_numeric($id)||$id<=0){$skipped['unsupported_record']=($skipped['unsupported_record']??0)+1;continue;}
            $key=$type.':'.$id;if(isset($seen[$key]))continue;$seen[$key]=true;
            if(($t['location']??'')==='underground'||!in_array($t['tunnel']??'no',['no','false','0'],true)||($kind==='rail'&&!in_array($t['bridge']??'no',['no','false','0'],true))||($kind==='parking'&&in_array($t['parking']??'',['underground','multi-storey','rooftop'],true))){$skipped['unsupported_vertical_structure']=($skipped['unsupported_vertical_structure']??0)+1;continue;}
            $geometry=$type==='node'?[['lon'=>$e['lon']??null,'lat'=>$e['lat']??null]]:($e['geometry']??[]);
            $line=[];foreach($geometry as $p){if(!is_numeric($p['lon']??null)||!is_numeric($p['lat']??null)||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90){$line=[];break;}$line[]=[(float)$p['lon'],(float)$p['lat']];}
            if(!$line||count($line)>2000||($type==='way'&&count($line)<2)||($kind==='parking'&&($type!=='way'||count($line)<4||$line[0]!==end($line)))){$skipped['missing_or_complex_geometry']=($skipped['missing_or_complex_geometry']??0)+1;continue;}
            $points+=count($line);if($points>40000){$truncated=true;break;}
            $features[]=['id'=>$key,'osm_id'=>(string)$id,'kind'=>$kind,'tags'=>$t,'points'=>$line,'node_ids'=>$e['nodes']??[],'timestamp'=>$e['timestamp']??null,'source'=>'OpenStreetMap'];
        }
        return ['features'=>$features,'skipped'=>$skipped,'truncated'=>$truncated];
    }
    public static function merge(array $a,array $b):array{
        $items=[];$points=0;foreach(array_merge($a['features']??[],$b['features']??[]) as $f)$items[$f['id']]=$f;
        $a['features']=[];foreach($items as $f){$points+=count($f['points']);if($points>40000||count($a['features'])>=2000){$a['truncated']=true;break;}$a['features'][]=$f;}
        $a['truncated']=($a['truncated']??false)||($b['truncated']??false);
        foreach($b['skipped']??[] as $key=>$n)$a['skipped'][$key]=($a['skipped'][$key]??0)+$n;
        if(isset($b['warning'])){$a['warning']=trim(($a['warning']??'').' '.$b['warning']);$a['fetch_status']='partial';}
        return $a;
    }
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null):array{
        try{
            $progress('Fetching mapped roadside infrastructure');
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_ExteriorRing(ST_SimplifyPreserveTopology(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90,'quad_segs=2'),3)),4326),6)");
            $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];$poly=implode(' ',array_map(static fn($p)=>$p[1].' '.$p[0],$ring));
            if(strlen($poly)>100000)throw new \RuntimeException('Roadside corridor exceeds budget.');$area='(poly:"'.$poly.'")';
            $query='[out:json][timeout:60][maxsize:16777216];way[power~"^(line|minor_line)$"]'.$area.'->.power;(.power;node(w.power)[power~"^(pole|tower)$"];node[power~"^(pole|tower)$"]'.$area.';node[highway=street_lamp]'.$area.';nwr[barrier~"^(fence|wall|retaining_wall|hedge|gate|lift_gate|bollard)$"]'.$area.';way[railway=rail]'.$area.';node[railway=level_crossing]'.$area.';node[highway=milestone]'.$area.';node[highway=bus_stop]'.$area.';nwr[amenity~"^(shelter|bench|parking)$"]'.$area.');out meta geom 3001;';
            $file=$dir.'/roadside-'.substr(hash('sha256',$query),0,12).'.json';
            $result=self::parse(BeamOsm::download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$file,http_build_query(['data'=>$query]),$progress,$download,null,'roadside infrastructure'));
            if($result['features']){
                $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)');
                $q->execute([json_encode(['type'=>'GeometryCollection','geometries'=>array_map(static fn($f)=>['type'=>count($f['points'])===1?'Point':'LineString','coordinates'=>count($f['points'])===1?$f['points'][0]:$f['points']],$result['features'])],JSON_THROW_ON_ERROR),$plan['epsg']]);
                $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['geometries'];
                foreach($projected as $i=>$g){$line=$g['type']==='Point'?[$g['coordinates']]:$g['coordinates'];$result['features'][$i]['points']=array_map(static fn($p)=>[$p[0]-$plan['bounds'][0],$p[1]-$plan['bounds'][1]],$line);}
            }
            return $result+['fetch_status'=>'complete','source'=>'OpenStreetMap explicit roadside features'];
        }catch(JobCancelled $e){throw $e;}
        catch(\Throwable $e){return ['features'=>[],'fetch_status'=>'unavailable','warning'=>'Mapped roadside infrastructure was unavailable; no feature locations were invented.','failure_kind'=>$e instanceof DownloadFailure?'provider':'processing'];}
    }
}
