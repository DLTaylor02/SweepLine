<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;
final class BeamPlan
{
    public const MAX_SIDE=32768;
    public static function options(array $input): array
    {
        $padding=Validation::number($input,'padding_miles',0.5,0.1,5);
        $resolution=Validation::number($input,'resolution',2048,1024,2048);
        if(!in_array($resolution,[1024.0,2048.0],true))throw new \InvalidArgumentException('Terrain resolution must be 1024 or 2048.');
        $style=$input['terrain_style']??'stock';if(!is_string($style)||!in_array($style,['stock','aerial'],true))throw new \InvalidArgumentException('Terrain style must be stock or aerial.');
        return ['terrain_style'=>$style,'padding_miles'=>$padding,'resolution'=>(int)$resolution,'orientation'=>RoadExport::orientation($input['orientation']??'forward')];
    }
    public static function square(array $points,float $padding): array
    {
        if(count($points)<2)throw new \InvalidArgumentException('Road geometry is empty.');
        $xs=array_column($points,0);$ys=array_column($points,1);
        $side=max(max($xs)-min($xs),max($ys)-min($ys))+2*$padding;
        if($side>self::MAX_SIDE)throw new \InvalidArgumentException('This road needs a map larger than 20.36 miles per side. Choose a shorter road or less padding.');
        $cx=(min($xs)+max($xs))/2;$cy=(min($ys)+max($ys))/2;
        return [$cx-$side/2,$cy-$side/2,$cx+$side/2,$cy+$side/2];
    }
    public static function project(PDO $db,array $road,array $options): array
    {
        $road=RoadExport::prepare($road,$options['orientation']??'forward');$geo=$road['geometry']['coordinates'];
        $lon=array_sum(array_column($geo,0))/count($geo);$lat=array_sum(array_column($geo,1))/count($geo);
        if($lon< -125 || $lon> -66 || $lat<24 || $lat>50)throw new \InvalidArgumentException('This exporter currently requires continental US elevation and NAIP imagery coverage.');
        $epsg=32600+max(1,min(60,(int)floor(($lon+180)/6)+1));
        $parts=$road['source_parts']??$road['parts']??[];$unique=[];
        foreach($parts as $part){$key=hash('sha256',json_encode([$part['geometry'],$part['tags']??[],(int)($part['direction']??0)],JSON_THROW_ON_ERROR));$unique[$key]=$part;}
        $parts=array_values($unique);
        if(count($parts)>500)throw new \InvalidArgumentException('This road has too many source parts for BeamNG export.');
        $geometries=[$road['geometry']];foreach($parts as $part)$geometries[]=$part['geometry'];
        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),9)');
        $q->execute([json_encode(['type'=>'GeometryCollection','geometries'=>$geometries],JSON_THROW_ON_ERROR),$epsg]);
        $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['geometries'];
        $points=$projected[0]['coordinates'];$bounds=self::square($points,$options['padding_miles']*1609.344);
        $sources=[];
        foreach($parts as $i=>$part)$sources[]=['points'=>$projected[$i+1]['coordinates'],'tags'=>$part['tags']??[],'highway'=>$part['highway']??$road['highway']??'secondary','direction'=>(int)($part['direction']??0),'osm_id'=>$part['osm_id']??null];
        $requestedGeo=$road['geometry'];$extension=['start_m'=>0.,'finish_m'=>0.];
        $spans=BeamStructures::spans($points,$sources,true,true);
        [$before,$after]=BeamApproaches::needed($points,$spans);
        if($before>0||$after>0){
            $names=array_values(array_unique(array_filter(array_merge([$road['name'],$road['ref']??null],$road['alternate_names']??[],array_column($parts,'name'),array_column($parts,'ref')),static fn($v)=>is_string($v)&&$v!=='')));
            $names=array_slice($names,0,100);$namesJson=json_encode($names,JSON_THROW_ON_ERROR);
            foreach([[$before,$points[0]],[$after,$points[count($points)-1]]] as [$need,$endpoint]){
                if($need<=0)continue;
                $extra=$db->prepare("SELECT s.osm_id,s.highway,s.direction,s.tags,ST_AsGeoJSON(ST_Transform(s.geom,CAST(? AS integer)),9) AS geometry
                    FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id
                    WHERE s.geom && ST_Transform(ST_MakeEnvelope(?,?,?,?,?),4326)
                    AND (s.name IN (SELECT jsonb_array_elements_text(?::jsonb)) OR s.ref IN (SELECT jsonb_array_elements_text(?::jsonb)))
                    AND s.highway IN ('motorway','trunk','primary','secondary','tertiary','unclassified','residential','living_street')
                    AND ST_NPoints(s.geom)<=2000 ORDER BY s.id LIMIT 51");
                $extra->execute([$epsg,$endpoint[0]-350,$endpoint[1]-350,$endpoint[0]+350,$endpoint[1]+350,$epsg,$namesJson,$namesJson]);
                $nearby=$extra->fetchAll(PDO::FETCH_ASSOC);
                if(count($nearby)>50)throw new \InvalidArgumentException('Bridge approach area has too many matching road pieces. Select a longer stretch explicitly.');
                foreach($nearby as $part)$sources[]=['points'=>json_decode($part['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'],'tags'=>json_decode($part['tags'],true,512,JSON_THROW_ON_ERROR),'highway'=>$part['highway'],'direction'=>(int)$part['direction'],'osm_id'=>$part['osm_id']];
            }
            for($attempt=0;$attempt<3&&($before>0||$after>0);$attempt++){
                if($extension['start_m']+$before>300||$extension['finish_m']+$after>300)throw new \InvalidArgumentException('Bridge approach extension exceeds the 984 ft per-end limit. Select a longer stretch explicitly.');
                $points=BeamApproaches::extend($points,$sources,$before,$after);
                $extension['start_m']+=$before;$extension['finish_m']+=$after;
                $spans=BeamStructures::spans($points,$sources,true,true);[$before,$after]=BeamApproaches::needed($points,$spans);
            }
            $bounds=self::square($points,$options['padding_miles']*1609.344);
            $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),?),4326),9)');
            $q->execute([json_encode(['type'=>'LineString','coordinates'=>$points],JSON_THROW_ON_ERROR),$epsg]);
            $road['geometry']=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$geo=$road['geometry']['coordinates'];
        }
        $bridges=BeamStructures::spans($points,$sources,true);$tunnels=BeamTunnels::spans($points,$sources);
        $oneway=false;foreach($road['parts']??[] as $part)if((int)$part['direction']!==0)$oneway=true;
        return $options+['name'=>$road['name'],'alternate_names'=>$road['alternate_names']??[],'epsg'=>$epsg,'bounds'=>$bounds,'points'=>$points,'sources'=>$sources,'bridges'=>$bridges,'tunnels'=>$tunnels,'oneway'=>$oneway,'center'=>[$lon,$lat],'selection'=>['original'=>$road['selection']??null,'requested_geometry'=>($extension['start_m']>0||$extension['finish_m']>0?$requestedGeo:null),'bridge_approach_extension'=>$extension,'exported_geometry'=>$road['geometry'],'exported_start'=>$geo[0],'exported_finish'=>$geo[count($geo)-1],'candidate_id'=>$road['id']??null]];
    }
    /** Keep original vertices while adding short supports, avoiding cuts across sharp corners. */
    public static function sample(array $points,float $spacing=8,float $minimumSegment=0.5): array
    {
        $out=[$points[0]];
        for($i=1;$i<count($points);$i++) {
            $a=$points[$i-1];$b=$points[$i];$distance=hypot($b[0]-$a[0],$b[1]-$a[1]);if($distance<$minimumSegment)continue;$steps=max(1,(int)ceil($distance/$spacing));
            for($j=1;$j<=$steps;$j++)$out[]=[$a[0]+($b[0]-$a[0])*$j/$steps,$a[1]+($b[1]-$a[1])*$j/$steps];
            if(count($out)>20000)throw new \RuntimeException('Road geometry exceeds the BeamNG processing limit; choose a shorter stretch.');
        }
        return $out;
    }
}

