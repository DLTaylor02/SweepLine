<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamBuildings
{
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array
    {
        $progress("Fetching mapped roadside building footprints");
        try{
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_ExteriorRing(ST_SimplifyPreserveTopology(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90,'quad_segs=2'),3)),4326),6)");
            $q->execute([json_encode(["type"=>"LineString","coordinates"=>$plan["points"]],JSON_THROW_ON_ERROR),$plan["epsg"]]);$ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)["coordinates"];
            $poly=implode(" ",array_map(static fn($p)=>$p[1]." ".$p[0],$ring));
            if(strlen($poly)>100000)throw new \RuntimeException("Building query corridor is too complex.");
            $url=getenv("OSM_OVERPASS_URL")?:"https://overpass-api.de/api/interpreter";$file=$dir."/buildings.json";
            ($download??[Download::class,'file'])($url,$file,16777216,http_build_query(["data"=>'[out:json][timeout:90][maxsize:16777216];way[building][building!="no"](poly:"'.$poly.'");out geom 1001;']),static fn($m)=>$progress($m));
            $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if(isset($data["remark"]))throw new \RuntimeException("Building provider returned an incomplete response.");
            $features=[];$tags=[];$vertices=0;
            foreach(array_slice($data["elements"]??[],0,1000) as $element){$geometry=$element["geometry"]??[];if(count($geometry)<4||count($geometry)>100)continue;
                $coords=array_map(static fn($p)=>[$p["lon"],$p["lat"]],$geometry);if($coords[0]!==$coords[count($coords)-1])continue;
                $vertices+=count($coords);if($vertices>50000)throw new \RuntimeException("Building footprint geometry limit reached.");
                $features[]=["type"=>"LineString","coordinates"=>$coords];$tags[]=$element["tags"]??[];
            }
            if(!$features)return ["footprints"=>[],"source"=>"OpenStreetMap building ways","truncated"=>false];
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)");$q->execute([json_encode(["type"=>"GeometryCollection","geometries"=>$features],JSON_THROW_ON_ERROR),$plan["epsg"]]);
            $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)["geometries"];$out=[];
            foreach($projected as $i=>$geometry)$out[]=["points"=>array_map(static fn($p)=>[$p[0]-$plan["bounds"][0],$p[1]-$plan["bounds"][1]],$geometry["coordinates"]),"tags"=>$tags[$i]];
            return ["footprints"=>$out,"source"=>"OpenStreetMap building ways","truncated"=>count($data["elements"]??[])>1000];
        }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){return ["footprints"=>[],"warning"=>"Mapped buildings were unavailable; no guessed buildings were substituted.","source"=>"OpenStreetMap building ways"];}
    }
    public static function place(array $footprints,array $road,array $profiles,BeamRaster $raster): array
    {
        $geometry=new BeamScenery($road,$profiles);$out=[];$rejected=0;
        foreach($footprints as $footprint){$points=$footprint["points"];array_pop($points);if(count($points)<3||count($points)>50){$rejected++;continue;}
            $cx=array_sum(array_column($points,0))/count($points);$cy=array_sum(array_column($points,1))/count($points);$radius=0.0;$area=0.0;$sign=0;$convex=true;
            foreach($points as $i=>$p){$b=$points[($i+1)%count($points)];$c=$points[($i+2)%count($points)];$cross=($b[0]-$p[0])*($c[1]-$b[1])-($b[1]-$p[1])*($c[0]-$b[0]);if(abs($cross)>0.001){$s=$cross>0?1:-1;if($sign&&$s!==$sign)$convex=false;$sign=$s;}$area+=$p[0]*$b[1]-$b[0]*$p[1];$radius=max($radius,hypot($p[0]-$cx,$p[1]-$cy));}
            $area=abs($area)/2;
            if(!$convex||$area<12||$area>1500||$radius>25||!$geometry->allows($cx,$cy,$radius)||$cx-$radius<0||$cy-$radius<0||$cx+$radius>=$raster->side||$cy+$radius>=$raster->side){$rejected++;continue;}
            $ground=array_map(static fn($p)=>$raster->height($p[0],$p[1]),$points);if(max($ground)-min($ground)>1.5){$rejected++;continue;}
            $overlap=false;foreach($out as $existing)if(hypot($cx-$existing["position"][0],$cy-$existing["position"][1])<$radius+$existing["radius"])$overlap=true;if($overlap){$rejected++;continue;}
            $tags=$footprint["tags"];$height=6.0;
            if(preg_match("/^([0-9]+(?:\\.[0-9]+)?)(?: m)?$/",(string)($tags["height"]??""),$m))$height=(float)$m[1];elseif(is_numeric($tags["building:levels"]??null))$height=(float)$tags["building:levels"]*3;
            $height=max(3,min(30,$height));
            $out[]=["kind"=>"building","position"=>[$cx,$cy,min($ground)-0.2],"points"=>array_map(static fn($p)=>[$p[0]-$cx,$p[1]-$cy],$points),"radius"=>$radius,"height"=>$height+(max($ground)-min($ground)),"mapped"=>true];
        }
        return ["objects"=>$out,"rejected"=>$rejected];
    }
}
