<?php
declare(strict_types=1);
namespace SweepLine;

/** OSM ring assembly and database-backed courtyard/part topology. */
final class BeamBuildingGeometry
{
    public static function parse(array $elements): array
    {
        $out=[];$owned=[];$seen=[];$rejected=0;
        foreach($elements as $e)if(($e['type']??'')==='relation'&&self::building($e['tags']??[]))
            foreach($e['members']??[] as $member)if(($member['role']??'')==='outer')$owned[$member['ref']??'']=true;
        foreach($elements as $index=>$e){
            $tags=$e['tags']??[];if(!self::building($tags))continue;
            $type=$e['type']??'way';$id=$type.'/'.($e['id']??'fixture-'.$index);
            if(isset($seen[$id]))continue;$seen[$id]=true;
            if($type==='way'&&isset($owned[$e['id']??'']))continue;
            $rings=['outer'=>[],'inner'=>[]];
            if($type==='way')$rings['outer']=self::join([self::coordinates($e['geometry']??[])]);
            elseif($type==='relation'){
                $pieces=['outer'=>[],'inner'=>[]];
                foreach($e['members']??[] as $m){$role=$m['role']??'outer';if(!isset($pieces[$role]))continue;$coords=self::coordinates($m['geometry']??[]);if(count($coords)<2){$pieces[$role][]=[];}else $pieces[$role][]=$coords;}
                $complete=true;
                foreach($pieces as $role=>$lines){
                    $rings[$role]=self::join($lines);
                    if(in_array([], $lines,true)||array_sum(array_map(static fn($p)=>max(0,count($p)-1),$lines))!==array_sum(array_map(static fn($p)=>count($p)-1,$rings[$role])))$complete=false;
                }
                if(!$complete){$rejected++;continue;}
            }
            if(!$rings['outer']){$rejected++;continue;}
            $unassigned=false;foreach($rings['inner'] as $inner){
                $found=false;foreach($rings['outer'] as $outer)if(self::contains($inner[0],$outer)){$found=true;break;}
                if(!$found)$unassigned=true;
            }
            if($unassigned){$rejected++;continue;}
            foreach($rings['outer'] as $outer){
                $holes=[];foreach($rings['inner'] as $inner)if(self::contains($inner[0],$outer))$holes[]=$inner;
                $out[]=['id'=>$id,'tags'=>$tags,'part'=>isset($tags['building:part'])&&$tags['building:part']!=='no','rings'=>array_merge([$outer],$holes)];
            }
        }
        return ['features'=>$out,'invalid'=>$rejected];
    }
    private static function contains(array $p,array $ring): bool
    {
        $inside=false;for($i=0,$j=count($ring)-1;$i<count($ring);$j=$i++){
            $a=$ring[$i];$b=$ring[$j];
            if(($a[1]>$p[1])!==($b[1]>$p[1])&&$p[0]<($b[0]-$a[0])*($p[1]-$a[1])/($b[1]-$a[1])+$a[0])$inside=!$inside;
        }return $inside;
    }
    private static function building(array $t): bool {return (isset($t['building'])&&$t['building']!=='no')||(isset($t['building:part'])&&$t['building:part']!=='no');}
    private static function coordinates(array $g): array
    {
        $out=[];foreach($g as $p){if(!is_numeric($p['lon']??null)||!is_numeric($p['lat']??null)||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90)return [];$out[]=[(float)$p['lon'],(float)$p['lat']];}
        return $out;
    }
    public static function join(array $lines): array
    {
        $rings=[];$lines=array_values(array_filter($lines,static fn($p)=>count($p)>=2&&count($p)<=500));
        while($lines){$ring=array_shift($lines);$changed=true;
            while($ring[0]!==end($ring)&&$changed){$changed=false;foreach($lines as $i=>$line){
                if(end($ring)===$line[0])$tail=$line;
                elseif(end($ring)===end($line))$tail=array_reverse($line);
                else continue;
                $ring=array_merge($ring,array_slice($tail,1));unset($lines[$i]);$changed=true;break;
            }if(count($ring)>500)break;}
            if(count($ring)>=4&&count($ring)<=500&&$ring[0]===end($ring))$rings[]=$ring;
        }
        return $rings;
    }
    /** Part footprints replace only the covered area of their parent outline. */
    public static function topology(\PDO $db,array $features): array
    {
        $parts=array_filter($features,static fn($f)=>$f['part']??false);$out=[];$parents=[];
        foreach($parts as $part){
            $bestArea=INF;
            foreach($features as $candidate)if(!($candidate['part']??false)){
                $area=abs(BeamPolygon::area($candidate['points']));
                if($area<$bestArea&&self::covers($db,$candidate,$part)){$parents[$part['osm_id']]=$candidate;$bestArea=$area;}
            }
        }
        foreach($features as $f){
            try {
            $parent=null;$cuts=[];
            if($f['part']??false){
                $parent=$parents[$f['osm_id']]??null;
                if($parent){
                    $f['assembly']=$parent['osm_id'];$inherit=array_diff_key($parent['tags'],$f['tags']);
                    // An explicit part measurement outranks an equivalent inherited parent measurement.
                    foreach([['building:levels','height'],['building:min_level','min_height'],['roof:levels','roof:height'],['height','building:levels']] as [$own,$other])
                        if(isset($f['tags'][$own]))unset($inherit[$other]);
                    $f['inherited_tags']=$inherit;$f['tags']=array_replace($inherit,$f['tags']);
                }
            }else{
                foreach($parts as $part)if(($parents[$part['osm_id']]['osm_id']??null)===$f['osm_id'])$cuts[]=['type'=>'Polygon','coordinates'=>array_merge([self::close($part['points'])],array_map(self::close(...),$part['holes']??[]))];
            }
            $f['assembly']??=$f['osm_id'];
            if(!$cuts&&empty($f['holes'])){$out[]=$f;continue;}
            $polygon=['type'=>'Polygon','coordinates'=>array_merge([self::close($f['points'])],array_map(self::close(...),$f['holes']??[]))];
            $sql="WITH a AS (SELECT ST_GeomFromGeoJSON(?) g), b AS (SELECT ST_UnaryUnion(ST_GeomFromGeoJSON(?)) g),
                c AS (SELECT ST_CollectionExtract(ST_Difference(a.g,b.g),3) g FROM a,b)
                SELECT ST_AsGeoJSON((d).geom) FROM (SELECT ST_Dump(g) d FROM c) s";
            $q=$db->prepare($sql);$q->execute([json_encode($polygon,JSON_THROW_ON_ERROR),json_encode(['type'=>'GeometryCollection','geometries'=>$cuts],JSON_THROW_ON_ERROR)]);
            foreach($q->fetchAll(\PDO::FETCH_COLUMN) as $json){
                $rings=json_decode($json,true,512,JSON_THROW_ON_ERROR)['coordinates'];
                $copy=$f;$copy['points']=BeamPolygon::clean($rings[0]);$copy['holes']=array_map(BeamPolygon::clean(...),array_slice($rings,1));
                if($copy['holes']){
                    $t=$db->prepare('SELECT ST_AsGeoJSON(ST_TriangulatePolygon(ST_GeomFromGeoJSON(?)))');
                    $t->execute([$json]);$tri=json_decode($t->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
                    if(empty($tri['geometries']))throw new \RuntimeException('Courtyard triangulation produced no faces.');
                    $copy['roof_triangles']=array_map(static fn($g)=>array_slice($g['coordinates'][0],0,3),$tri['geometries']??[]);
                }
                $out[]=$copy;
            }
            }catch(\RuntimeException $e){$out[]=['geometry_rejected'=>true,'osm_id'=>$f['osm_id'],'reason'=>'Invalid or unsupported courtyard/part topology.'];}
        }
        return $out;
    }
    private static function close(array $p): array {if($p[0]!==end($p))$p[]=$p[0];return $p;}
    private static function covers(\PDO $db,array $parent,array $part): bool
    {
        foreach([0,1] as $axis)if(min(array_column($part['points'],$axis))<min(array_column($parent['points'],$axis))-.001||max(array_column($part['points'],$axis))>max(array_column($parent['points'],$axis))+.001)return false;
        $polygon=static fn($f)=>json_encode(['type'=>'Polygon','coordinates'=>array_merge([self::close($f['points'])],array_map(self::close(...),$f['holes']??[]))],JSON_THROW_ON_ERROR);
        try{
            $q=$db->prepare('SELECT ST_Covers(ST_GeomFromGeoJSON(?),ST_GeomFromGeoJSON(?))');
            $q->execute([$polygon($parent),$polygon($part)]);
            return in_array($q->fetchColumn(),[true,1,'1','t'],true);
        }catch(\PDOException $e){return false;}
    }
}
