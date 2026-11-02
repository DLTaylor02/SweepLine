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
        $resolution=Validation::number($input,'resolution',1024,1024,2048);
        if(!in_array($resolution,[1024.0,2048.0],true))throw new \InvalidArgumentException('Terrain resolution must be 1024 or 2048.');
        return ['padding_miles'=>$padding,'resolution'=>(int)$resolution];
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
        $road=RoadExport::prepare($road);$geo=$road['geometry']['coordinates'];
        $lon=array_sum(array_column($geo,0))/count($geo);$lat=array_sum(array_column($geo,1))/count($geo);
        if($lon< -125 || $lon> -66 || $lat<24 || $lat>50)throw new \InvalidArgumentException('This exporter currently requires continental US elevation and NAIP imagery coverage.');
        $epsg=32600+max(1,min(60,(int)floor(($lon+180)/6)+1));
        $parts=$road['source_parts']??$road['parts']??[];$unique=[];
        foreach($parts as $part){$key=hash('sha256',json_encode([$part['geometry'],$part['tags']??[],(int)($part['direction']??0)],JSON_THROW_ON_ERROR));$unique[$key]=$part;}
        $parts=array_values($unique);
        if(count($parts)>500)throw new \InvalidArgumentException('This road has too many source parts for BeamNG export.');
        foreach($parts as $part) {
            $tags=$part['tags']??[];
            if((isset($tags['bridge']) && !in_array($tags['bridge'],['no','false','0'],true)) ||
               (isset($tags['tunnel']) && !in_array($tags['tunnel'],['no','false','0'],true))) {
                throw new \InvalidArgumentException('This stretch contains a mapped bridge or tunnel. Accurate structure elevations are not yet supported by BeamNG export.');
            }
        }
        $geometries=[$road['geometry']];foreach($parts as $part)$geometries[]=$part['geometry'];
        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),?),9)');
        $q->execute([json_encode(['type'=>'GeometryCollection','geometries'=>$geometries],JSON_THROW_ON_ERROR),$epsg]);
        $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['geometries'];
        $points=$projected[0]['coordinates'];$bounds=self::square($points,$options['padding_miles']*1609.344);
        $sources=[];
        foreach($parts as $i=>$part)$sources[]=['points'=>$projected[$i+1]['coordinates'],'tags'=>$part['tags']??[],'highway'=>$part['highway']??$road['highway']??'secondary','direction'=>(int)($part['direction']??0)];
        $oneway=false;foreach($road['parts']??[] as $part)if((int)$part['direction']!==0)$oneway=true;
        return $options+['name'=>$road['name'],'alternate_names'=>$road['alternate_names']??[],'epsg'=>$epsg,'bounds'=>$bounds,'points'=>$points,'sources'=>$sources,'oneway'=>$oneway,'center'=>[$lon,$lat]];
    }
    /** Keep original vertices while adding short supports, avoiding cuts across sharp corners. */
    public static function sample(array $points,float $spacing=8): array
    {
        $out=[$points[0]];
        for($i=1;$i<count($points);$i++) {
            $a=$points[$i-1];$b=$points[$i];$distance=hypot($b[0]-$a[0],$b[1]-$a[1]);if($distance<0.5)continue;$steps=max(1,(int)ceil($distance/$spacing));
            for($j=1;$j<=$steps;$j++)$out[]=[$a[0]+($b[0]-$a[0])*$j/$steps,$a[1]+($b[1]-$a[1])*$j/$steps];
            if(count($out)>20000)throw new \RuntimeException('Road geometry exceeds the BeamNG processing limit; choose a shorter stretch.');
        }
        return $out;
    }
}

