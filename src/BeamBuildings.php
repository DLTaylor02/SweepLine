<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamBuildings
{
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array
    {
        $progress('Fetching mapped roadside buildings and land use');
        try{
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_ExteriorRing(ST_SimplifyPreserveTopology(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90,'quad_segs=2'),3)),4326),6)");
            $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];$poly=implode(' ',array_map(static fn($p)=>$p[1].' '.$p[0],$ring));
            if(strlen($poly)>100000)throw new \RuntimeException('Building query corridor is too complex.');
            $query='[out:json][timeout:90][maxsize:16777216];way[building][building!="no"](poly:"'.$poly.'");out geom 1001;';
            $data=self::downloadBuildings(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/buildings.json',http_build_query(['data'=>$query]),$progress,$download);
            $features=[];$tags=[];$vertices=0;$buildings=[];$areas=[];$seen=[];
            foreach($data['elements']??[] as $element){
                if(($element['type']??'way')!=='way')continue;$t=$element['tags']??[];$id=$element['id']??null;
                if($id!==null&&isset($seen[$id]))continue;if($id!==null)$seen[$id]=true;
                if(isset($t['building'])&&$t['building']!=='no')$buildings[]=$element;
                elseif(isset($t['landuse']))$areas[]=$element;
            }
            $landuseWarning=null;
            if($buildings)try{
                $landuseQuery='[out:json][timeout:60][maxsize:16777216];way[landuse~"^(residential|commercial|retail|industrial|farmyard)$"](poly:"'.$poly.'");out geom 501;';
                $context=BeamOsm::download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/building-landuse.json',http_build_query(['data'=>$landuseQuery]),$progress,$download,null,'building land use');
                foreach($context['elements']??[] as $element){
                    if(($element['type']??'')!=='way'||!isset($element['tags']['landuse']))continue;$id=$element['id']??null;
                    if($id!==null&&isset($seen[$id]))continue;if($id!==null)$seen[$id]=true;$areas[]=$element;
                }
            }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$landuseWarning='Mapped land-use hints were unavailable; building tags and neutral facade fallbacks were retained.';}
            foreach(array_slice($buildings,0,1000) as $element){
                $g=$element['geometry']??[];if(count($g)<4||count($g)>100)continue;
                $coords=array_map(static fn($p)=>[$p['lon'],$p['lat']],$g);if($coords[0]!==end($coords))continue;
                $vertices+=count($coords);if($vertices>50000)throw new \RuntimeException('Building footprint geometry limit reached.');
                $features[]=['type'=>'LineString','coordinates'=>$coords];$tags[]=$element['tags']??[];
            }
            $buildingCount=count($features);$areaTags=[];$areaVertices=0;$areaLimit=false;
            foreach(array_slice($areas,0,500) as $element){
                $g=$element['geometry']??[];if(count($g)<4||count($g)>2000)continue;
                $coords=array_map(static fn($p)=>[$p['lon'],$p['lat']],$g);if($coords[0]!==end($coords))continue;
                if($areaVertices+count($coords)>20000){$areaLimit=true;break;}$areaVertices+=count($coords);
                $features[]=['type'=>'LineString','coordinates'=>$coords];$areaTags[]=$element['tags']['landuse'];
            }
            if(!$buildingCount)return ['footprints'=>[],'source'=>'OpenStreetMap building ways','truncated'=>count($buildings)>1000,'landuse_areas'=>0];
            $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)');
            $q->execute([json_encode(['type'=>'GeometryCollection','geometries'=>$features],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['geometries'];$out=[];$landuse=[];
            foreach($projected as $i=>$g){
                $p=array_map(static fn($v)=>[$v[0]-$plan['bounds'][0],$v[1]-$plan['bounds'][1]],$g['coordinates']);
                if($i<$buildingCount)$out[]=['points'=>$p,'tags'=>$tags[$i]];
                else {$ring=BeamPolygon::clean($p);if(count($ring)>=3&&abs(BeamPolygon::area($ring))>=1)$landuse[]=['points'=>$ring,'landuse'=>$areaTags[$i-$buildingCount]];}
            }
            foreach($out as &$footprint)$footprint['style']=BeamBuildingStyle::classify($footprint['tags'],$footprint['points'],$landuse);unset($footprint);
            return ['footprints'=>$out,'source'=>'OpenStreetMap building ways','truncated'=>count($buildings)>1000,'landuse_areas'=>count($landuse),'landuse_truncated'=>count($areas)>500||$areaLimit,'landuse_warning'=>$landuseWarning,'facade_policy'=>'Building tags first, containing mapped land use second; generic fallback. Not legal zoning.'];
        }catch(JobCancelled $e){throw $e;}
        catch(\Throwable $e){return ['footprints'=>[],'warning'=>'Mapped buildings were unavailable; no guessed buildings were substituted. '.($e instanceof DownloadFailure?$e->getMessage():'Building data processing failed.'),'failure_kind'=>$e instanceof DownloadFailure?($e->retryable?'provider_transient':'provider_permanent'):'processing','source'=>'OpenStreetMap building ways'];}
    }

    /** Bounded provider retries; injected transport/wait keep offline tests deterministic. */
    public static function downloadBuildings(string $url,string $file,string $query,callable $progress,?callable $download=null,?callable $sleep=null): array
    {
        return BeamOsm::download($url,$file,$query,$progress,$download,$sleep);
    }

    public static function place(array $footprints,array $road,array $profiles,BeamRaster $raster,array $exclusions=[]): array
    {
        $geometry=new BeamScenery($road,$profiles,$exclusions);$out=[];$reasons=[];
        $reject=static function($reason)use(&$reasons){$reasons[$reason]=($reasons[$reason]??0)+1;};
        foreach($footprints as $footprint){
            $points=BeamPolygon::clean($footprint['points']);
            if(count($points)<3||count($points)>100||!BeamPolygon::triangles($points)){$reject('invalid_geometry');continue;}
            $cx=array_sum(array_column($points,0))/count($points);$cy=array_sum(array_column($points,1))/count($points);$radius=0;
            foreach($points as $p)$radius=max($radius,hypot($p[0]-$cx,$p[1]-$cy));
            if(BeamPolygon::area($points)<12||BeamPolygon::area($points)>5000||$radius>75){$reject('size');continue;}
            $bounds=false;foreach($points as $p)if($p[0]<0||$p[1]<0||$p[0]>=$raster->side||$p[1]>=$raster->side)$bounds=true;
            if($bounds){$reject('outside_map');continue;}
            $reason=$geometry->polygonReason($points);if($reason!==null){$reject($reason);continue;}
            $ground=array_map(static fn($p)=>$raster->height($p[0],$p[1]),$points);$variation=max($ground)-min($ground);
            if($variation>3){$reject('ground_variation');continue;}
            $overlap=false;foreach($out as $existing)if(BeamPolygon::overlaps($points,$existing['world_footprint'])){$overlap=true;break;}
            if($overlap){$reject('overlapping_building');continue;}
            $tags=$footprint['tags'];$height=6.0;
            if(preg_match('/^([0-9]+(?:\.[0-9]+)?)(?: m)?$/',(string)($tags['height']??''),$m))$height=(float)$m[1];
            elseif(is_numeric($tags['building:levels']??null))$height=(float)$tags['building:levels']*3;
            $height=max(3,min(30,$height));
            $out[]=['kind'=>'building','position'=>[$cx,$cy,min($ground)-0.2],'points'=>array_map(static fn($p)=>[$p[0]-$cx,$p[1]-$cy],$points),
                'style'=>$footprint['style']??BeamBuildingStyle::classify($tags),'world_footprint'=>$points,'radius'=>$radius,'height'=>$height+$variation,'foundation_height_m'=>$variation,'mapped'=>true];
        }
        return ['objects'=>$out,'rejected'=>array_sum($reasons),'rejection_reasons'=>$reasons];
    }
}
