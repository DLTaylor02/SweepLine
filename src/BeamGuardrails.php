<?php
declare(strict_types=1);
namespace SweepLine;

/** Mapped OSM guardrails only; dimensions and rigid end treatment are generic estimates. */
final class BeamGuardrails
{
    public const MAX_SAMPLES=4000;
    public static function fetch(\PDO $db,array $plan,string $dir,callable $progress,?callable $download=null): array
    {
        $progress('Fetching mapped roadside guardrails');
        try{
            $q=$db->prepare("SELECT ST_AsGeoJSON(ST_Transform(ST_ExteriorRing(ST_SimplifyPreserveTopology(ST_Buffer(ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)),90,'quad_segs=2'),3)),4326),6)");
            $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $ring=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['coordinates'];
            $poly=implode(' ',array_map(static fn($p)=>$p[1].' '.$p[0],$ring));
            if(strlen($poly)>100000)throw new \RuntimeException('Guardrail corridor is too complex.');
            $data=BeamOsm::download(getenv('OSM_OVERPASS_URL')?:'https://overpass-api.de/api/interpreter',$dir.'/guardrails.json',
                http_build_query(['data'=>'[out:json][timeout:90][maxsize:16777216];way[barrier=guard_rail](poly:"'.$poly.'");out geom 1001;']),$progress,$download,null,'guardrails');
            $parsed=self::parse($data);
            if(!$parsed['lines'])return $parsed+['source'=>'OpenStreetMap barrier=guard_rail ways','fetch_status'=>'complete'];
            $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)),6)');
            $q->execute([json_encode(['type'=>'GeometryCollection','geometries'=>array_map(static fn($line)=>['type'=>'LineString','coordinates'=>$line['points']],$parsed['lines'])],JSON_THROW_ON_ERROR),$plan['epsg']]);
            $projected=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR)['geometries'];
            foreach($projected as $i=>$g)$parsed['lines'][$i]['points']=array_map(static fn($p)=>[$p[0]-$plan['bounds'][0],$p[1]-$plan['bounds'][1]],$g['coordinates']);
            return $parsed+['source'=>'OpenStreetMap barrier=guard_rail ways','fetch_status'=>'complete'];
        }catch(JobCancelled $e){throw $e;}
        catch(\Throwable $e){return ['lines'=>[],'source'=>'OpenStreetMap barrier=guard_rail ways','fetch_status'=>'unavailable','warning'=>'Mapped guardrails were unavailable; no inferred roadside barriers were substituted.','failure_kind'=>$e instanceof DownloadFailure?'provider':'processing'];}
    }

    public static function parse(array $data): array
    {
        $lines=[];$vertices=0;$seen=[];$invalid=0;$truncated=count($data['elements']??[])>1000;
        foreach(array_slice($data['elements']??[],0,1000) as $element){
            $id=$element['id']??null;$geometry=$element['geometry']??[];
            if(($element['type']??'')!=='way'||($element['tags']['barrier']??'')!=='guard_rail'||!is_int($id)||isset($seen[$id]))continue;
            $seen[$id]=true;$points=[];$valid=count($geometry)>=2&&count($geometry)<=2000;
            foreach($geometry as $p){
                if(!is_numeric($p['lon']??null)||!is_numeric($p['lat']??null)||!is_finite((float)$p['lon'])||!is_finite((float)$p['lat'])||abs((float)$p['lon'])>180||abs((float)$p['lat'])>90){$valid=false;break;}
                $point=[(float)$p['lon'],(float)$p['lat']];if(!$points||end($points)!==$point)$points[]=$point;
            }
            if(!$valid||count($points)<2){$invalid++;continue;}
            if($vertices+count($points)>50000){$truncated=true;break;}$vertices+=count($points);
            $lines[]=['points'=>$points,'osm_ids'=>[$id],'tags'=>$element['tags']];
        }
        return ['lines'=>$lines,'source_ways'=>count($lines),'source_timestamp'=>$data['osm3s']['timestamp_osm_base']??null,'truncated'=>$truncated,'invalid_ways'=>$invalid];
    }

    /** Join unambiguous shared endpoints so OSM way breaks do not invent terminals. */
    public static function join(array $lines): array
    {
        $key=static fn($p)=>sprintf('%.3F,%.3F',$p[0],$p[1]);$index=[];$unique=[];$seen=[];
        foreach($lines as $line){
            if(count($line['points'])<2)continue;
            $p=$line['points'];$forward=json_encode($p);$reverse=json_encode(array_reverse($p));$hash=hash('sha256',min($forward,$reverse));
            if(isset($seen[$hash]))continue;$seen[$hash]=true;$unique[]=$line;
        }
        foreach($unique as $i=>$line)foreach([$line['points'][0],end($line['points'])] as $p)$index[$key($p)][]=$i;
        $used=[];$out=[];
        foreach($unique as $i=>$line){
            if(isset($used[$i]))continue;$used[$i]=true;
            foreach([false,true] as $reverse){
                if($reverse)$line['points']=array_reverse($line['points']);
                while(true){
                    $at=$key(end($line['points']));$neighbors=$index[$at]??[];if(count($neighbors)!==2)break;
                    $next=null;foreach($neighbors as $candidate)if(!isset($used[$candidate]))$next=$candidate;
                    if($next===null||($unique[$next]['tags']['height']??'')!==($line['tags']['height']??''))break;
                    $p=$unique[$next]['points'];if($key($p[0])!==$at)$p=array_reverse($p);
                    array_push($line['points'],...array_slice($p,1));$line['osm_ids']=array_values(array_unique(array_merge($line['osm_ids'],$unique[$next]['osm_ids'])));$used[$next]=true;
                }
            }
            $out[]=$line;
        }
        return $out;
    }

    /** Stream samples so a long source way cannot allocate an unbounded point array. */
    private static function samples(array $points): \Generator
    {
        yield $points[0];
        for($i=1;$i<count($points);$i++){
            $a=$points[$i-1];$b=$points[$i];$distance=hypot($b[0]-$a[0],$b[1]-$a[1]);if($distance<0.001)continue;$steps=max(1,(int)ceil($distance/2));
            for($j=1;$j<=$steps;$j++)yield [$a[0]+($b[0]-$a[0])*$j/$steps,$a[1]+($b[1]-$a[1])*$j/$steps];
        }
    }

    public static function prepare(array $data,array $road,array $profiles,BeamRaster $raster,float $origin,array $exclusions,array $bridges,callable $progress): array
    {
        $geometry=new BeamScenery($road,$profiles,$exclusions);$out=[];$reasons=[];$samples=0;$length=0.0;$terminals=0;$limited=false;
        $reject=static function($reason)use(&$reasons){$reasons[$reason]=($reasons[$reason]??0)+1;};
        foreach(self::join($data['lines']??[]) as $line){
            $points=self::samples($line['points']);$run=[];
            $flush=static function()use(&$run,&$out,&$length,&$terminals,$line){
                if(count($run)>=2){$out[]=['points'=>$run,'osm_ids'=>$line['osm_ids']];$terminals+=2;for($i=1;$i<count($run);$i++)$length+=hypot($run[$i][0]-$run[$i-1][0],$run[$i][1]-$run[$i-1][1]);}$run=[];
            };
            $height=0.75;if(is_numeric($line['tags']['height']??null))$height=max(0.5,min(1.5,(float)$line['tags']['height']));
            foreach($points as $p){
                if(++$samples>self::MAX_SAMPLES){$limited=true;break;}if($samples%100===1)$progress(null);
                if($p[0]<0.3||$p[1]<0.3||$p[0]>=$raster->side-0.3||$p[1]>=$raster->side-0.3){$reject('outside_map');$flush();continue;}
                if(!$geometry->allows($p[0],$p[1],0.25)){$reject('pavement_or_range');$flush();continue;}
                $near=BeamIntersections::nearest($p,$road,$profiles);$onBridge=false;
                foreach($bridges as $bridge)if($near['along']>=$bridge['start_m']-2&&$near['along']<=$bridge['end_m']+2&&$near['distance']<$near['half_width']+5)$onBridge=true;
                if($onBridge){$reject('existing_bridge_rail');$flush();continue;}
                $ground=$raster->height($p[0],$p[1])-$origin;$top=$ground+$height;
                if($near['distance']<$near['half_width']+6)$top=max($top,$near['z']+0.65);
                if($top-$ground>2){$reject('unsupported_height');$flush();continue;}
                $node=[$p[0],$p[1],$top,$ground];
                if($run){
                    $last=end($run);$d=hypot($node[0]-$last[0],$node[1]-$last[1]);
                    $dx=($node[1]-$last[1])/max(0.001,$d)*0.25;$dy=-($node[0]-$last[0])/max(0.001,$d)*0.25;
                    $reason=$geometry->polygonReason([[$last[0]+$dx,$last[1]+$dy],[$last[0]-$dx,$last[1]-$dy],[$node[0]-$dx,$node[1]-$dy],[$node[0]+$dx,$node[1]+$dy]]);
                    if($reason!==null||abs($node[2]-$last[2])/max(0.001,$d)>0.6){$reject($reason??'unsupported_grade');$flush();}
                }
                $run[]=$node;
            }
            $flush();if($limited)break;
        }
        $report=$data;unset($report['lines']);$report+=['source'=>'OpenStreetMap barrier=guard_rail ways'];
        $report['fetch_status']??=isset($data['source_ways'])?'complete':'not_requested';
        if($report['fetch_status']==='complete'&&($data['source_ways']??count($data['lines']??[]))===0)$report['coverage_note']='No mapped guardrail ways were returned for the queried corridor; real barriers may still exist.';
        $report['placed_runs']=count($out);$report['length_m']=round($length,2);$report['terminals']=$terminals;$report['rejection_reasons']=$reasons;$report['truncated']=!empty($data['truncated'])||$limited;
        $report['placement']='Mapped horizontal geometry; estimated terrain/pavement-relative height. Steel rails/posts with rigid capped ends; no crash-energy model. Existing estimated bridge rails retained.';
        if($report['truncated'])$report['warning']=trim(($report['warning']??'').' Guardrail coverage was limited by the export geometry budget.');
        return ['runs'=>$out,'report'=>$report];
    }

    public static function mesh(array $points,string $material): array
    {
        $origin=array_slice($points[0],0,3);$vertices=[];$triangles=[];
        $box=static function($x,$y,$z,$width,$depth,$height,$dx,$dy)use(&$vertices,&$triangles){
            $base=count($vertices);
            foreach([[-1,-1,0],[1,-1,0],[1,1,0],[-1,1,0],[-1,-1,1],[1,-1,1],[1,1,1],[-1,1,1]] as [$a,$b,$c])$vertices[]=[$x+$a*$width/2*$dy+$b*$depth/2*$dx,$y-$a*$width/2*$dx+$b*$depth/2*$dy,$z+$c*$height];
            foreach([[0,2,1],[0,3,2],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]] as $t)$triangles[]=array_map(static fn($v)=>$v+$base,$t);
        };
        foreach($points as $i=>$p){
            $a=$points[max(0,$i-1)];$b=$points[min(count($points)-1,$i+1)];$d=hypot($b[0]-$a[0],$b[1]-$a[1])?:1;$dx=($b[0]-$a[0])/$d;$dy=($b[1]-$a[1])/$d;
            $base=count($vertices);
            foreach([[-0.07,-0.3],[0.07,-0.3],[0.07,0],[-0.07,0]] as [$across,$z])$vertices[]=[$p[0]-$dy*$across,$p[1]+$dx*$across,$p[2]+$z];
            if($i)foreach([[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]] as $t)$triangles[]=array_map(static fn($v)=>$v+$base-4,$t);
        }
        $last=(count($points)-1)*4;foreach([[0,2,1],[0,3,2],[$last,$last+1,$last+2],[$last,$last+2,$last+3]] as $t)$triangles[]=$t;
        // Ring vertices are emitted first; posts and generic terminal blocks follow.
        foreach($points as $i=>$p){
            $a=$points[max(0,$i-1)];$b=$points[min(count($points)-1,$i+1)];$d=hypot($b[0]-$a[0],$b[1]-$a[1])?:1;$dx=($b[0]-$a[0])/$d;$dy=($b[1]-$a[1])/$d;
            $box($p[0],$p[1],$p[3]-0.15,0.10,0.10,max(0.2,$p[2]-0.15-$p[3]),$dx,$dy);
            if($i===0||$i===count($points)-1)$box($p[0],$p[1],$p[2]-0.34,0.22,0.20,0.38,$dx,$dy);
        }
        $local=array_map(static fn($p)=>[$p[0]-$origin[0],$p[1]-$origin[1],$p[2]-$origin[2]],$vertices);
        return ['xml'=>BeamAssets::mesh($local,$triangles,$material),'position'=>$origin];
    }
}
