<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Conservative identification of opposite mapped carriageways, never inferred median links. */
final class BeamCarriageways
{
    public const RANGE_M=200;
    public const MAX_TRACKS=4;
    public const MIN_LENGTH_M=50;
    public static function identities(array $plan): array
    {
        $out=[];
        foreach($plan['sources'] as $s){$tags=$s['tags']??[];$id=RoadGraph::identity(['ref'=>$tags['ref']??null,'name'=>$tags['name']??$plan['name']]);if($id!=='')$out[$id]=true;}
        return array_keys($out);
    }
    public static function match(array $plan,array $candidates,callable $progress): array
    {
        $main=new BeamRoadIndex([['points'=>$plan['points']]]);
        $legal=new BeamRoadIndex(array_values(array_filter($plan['sources'],static fn($s)=>(int)($s['direction']??0)!==0)));
        $identities=self::identities($plan);$matches=[];$pointsBudget=0;$rejected=[];
        foreach($candidates as $candidate){
            $progress(null);$direction=(int)$candidate['direction'];
            if(!$direction||!in_array(RoadGraph::identity($candidate),$identities,true)){$rejected[]=['candidate_id'=>$candidate['id'],'name'=>$candidate['name'],'reason'=>!$direction?'Not explicitly one-way':'Road identity differs'];continue;}
            $beforeMatches=count($matches);
            $raw=$direction<0?array_reverse($candidate['points']):$candidate['points'];
            if(($pointsBudget+=count($raw))>60000)throw new \RuntimeException('Carriageway matching geometry budget exceeded.');
            $sample=BeamPlan::sample($raw,20,0.001);$run=[];$distances=[];$along=[];
            $flush=static function()use(&$run,&$distances,&$along,&$matches,$candidate){
                $length=0.;for($i=1;$i<count($run);$i++)$length+=hypot($run[$i][0]-$run[$i-1][0],$run[$i][1]-$run[$i-1][1]);
                if($length>=self::MIN_LENGTH_M)$matches[]=['points'=>$run,'candidate_id'=>(int)$candidate['id'],'road_name'=>$candidate['name'],'road_ref'=>$candidate['ref'],'highway'=>$candidate['highway'],'mean_separation_m'=>array_sum($distances)/count($distances),'main_range'=>[min($along),max($along)],'length_m'=>$length,'candidate_points'=>$candidate['direction']<0?array_reverse($candidate['points']):$candidate['points']];
                $run=[];$distances=[];$along=[];
            };
            foreach($sample as $i=>$p){
                $near=$main->nearest($p,self::RANGE_M);$source=null;
                if($near){$a=$plan['points'][$near['segment']-1];$b=$plan['points'][$near['segment']];$t=$near['fraction'];$source=$legal->nearest([$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t],2);}
                $a=$sample[max(0,$i-1)];$b=$sample[min(count($sample)-1,$i+1)];$len=hypot($b[0]-$a[0],$b[1]-$a[1])?:1;
                $ok=$near&&$source&&!($near['segment']===1&&$near['raw']<0)&&!($near['segment']===count($plan['points'])-1&&$near['raw']>1)&&$near['distance']>=6;
                if($ok){$sign=(int)$source['line']['direction'];$dot=(($b[0]-$a[0])/$len*$source['dx']+($b[1]-$a[1])/$len*$source['dy'])*$sign;$ok=$dot<-0.8&&$source['line']['highway']===$candidate['highway'];}
                if(!$ok){$flush();continue;}$run[]=$p;$distances[]=$near['distance'];$along[]=$near['along'];
            }$flush();
            if(count($matches)===$beforeMatches)$rejected[]=['candidate_id'=>$candidate['id'],'name'=>$candidate['name'],'reason'=>'No sufficiently long opposite-heading, same-class, separated run within the selected corridor'];
        }
        // Prefer the closest continuous match; reject competing similar-distance paths.
        usort($matches,static fn($a,$b)=>($a['mean_separation_m']<=>$b['mean_separation_m'])?:($b['length_m']<=>$a['length_m']));
        $out=[];$ambiguous=0;
        foreach($matches as $match){
            $duplicate=false;foreach($out as $prior){
                $overlap=min($match['main_range'][1],$prior['main_range'][1])-max($match['main_range'][0],$prior['main_range'][0]);
                if($overlap>min($match['length_m'],$prior['length_m'])*0.5){$duplicate=true;break;}
            }
            if($duplicate){$rejected[]=['candidate_id'=>$match['candidate_id'],'reason'=>'Duplicate or overlapping already-selected counterpart'];continue;}
            foreach($matches as $other)if($other['candidate_id']!==$match['candidate_id']){
                $overlap=min($match['main_range'][1],$other['main_range'][1])-max($match['main_range'][0],$other['main_range'][0]);
                if($overlap>min($match['length_m'],$other['length_m'])*0.5&&abs($match['mean_separation_m']-$other['mean_separation_m'])<2){
                    $a=new BeamRoadIndex([['points'=>$other['points']]]);$mid=$match['points'][intdiv(count($match['points']),2)];
                    if(($a->nearest($mid,5)['distance']??INF)>1){$duplicate=true;$ambiguous++;break;}
                }
            }
            if($duplicate)$rejected[]=['candidate_id'=>$match['candidate_id'],'reason'=>'Ambiguous competing counterpart'];
            if(!$duplicate)$out[]=$match;if(count($out)>=self::MAX_TRACKS)break;
        }
        return ['tracks'=>$out,'matching_cap_reached'=>count($out)>=self::MAX_TRACKS,'ambiguous_matches'=>$ambiguous,'rejected_candidates'=>$rejected,'source'=>'Local OSM identity, classification, opposite legal heading and parallel proximity'];
    }
    public static function fetch(PDO $db,array $plan,callable $progress): array
    {
        if(!array_filter($plan['sources'],static fn($s)=>(int)($s['direction']??0)!==0))return ['tracks'=>[],'source'=>'No one-way source sections'];
        $progress('Matching the opposite carriageway from local road coverage');
        $q=$db->prepare("WITH route AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)) g)
            SELECT c.id,c.name,c.ref,c.highway,ST_NPoints(c.geom::geometry) point_count,ST_AsGeoJSON(ST_Transform(c.geom::geometry,ST_SRID(p.g)),6) geometry,
            COALESCE((SELECT s.direction*CASE WHEN m.reversed THEN -1 ELSE 1 END FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=c.id AND s.direction<>0 ORDER BY m.position LIMIT 1),0) direction
            FROM road_candidates c JOIN regions r ON r.active_job_id=c.job_id CROSS JOIN route p
            WHERE ST_DWithin(c.geom,ST_Transform(p.g,4326)::geography,CAST(? AS double precision))
              AND lower(trim(COALESCE(NULLIF(c.ref,''),c.name))) IN (SELECT value FROM jsonb_array_elements_text(?::jsonb))
              AND ST_NPoints(c.geom::geometry)<=20000 ORDER BY c.id LIMIT 65");
        $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg'],self::RANGE_M,json_encode(self::identities($plan),JSON_THROW_ON_ERROR)]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>64)throw new \RuntimeException('Too many possible opposite carriageways; select a shorter road.');
        if(array_sum(array_column($rows,'point_count'))>60000)throw new \RuntimeException('Carriageway matching geometry budget exceeded. Select a shorter road.');
        foreach($rows as &$row)$row['points']=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];unset($row);
        $data=self::match($plan,$rows,$progress);$resolved=[];$sourcePointCount=0;
        foreach($data['tracks'] as $i=>&$track){
            $q=$db->prepare('SELECT DISTINCT s.osm_id,s.highway,s.direction,s.tags,ST_AsGeoJSON(ST_Transform(s.geom,CAST(? AS integer)),6) geometry FROM candidate_segments m JOIN road_segments s ON s.id=m.segment_id WHERE m.candidate_id=? LIMIT 501');
            $q->execute([$plan['epsg'],$track['candidate_id']]);$track['sources']=[];
            while($s=$q->fetch(PDO::FETCH_ASSOC))$track['sources'][]=['points'=>json_decode($s['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'],'tags'=>json_decode($s['tags'],true,512,JSON_THROW_ON_ERROR),'direction'=>(int)$s['direction'],'highway'=>$s['highway'],'osm_id'=>(int)$s['osm_id']];
            foreach($track['sources'] as $source)$sourcePointCount+=count($source['points']);
            if($sourcePointCount>120000)throw new \RuntimeException('Opposite-carriageway source geometry exceeds the export budget. Select a shorter road.');
            if(count($track['sources'])>500)throw new \RuntimeException('Opposite carriageway source budget exceeded.');
            // A candidate can include two-way approaches: retain only its
            // explicitly one-way pieces rather than inventing an opposite lane.
            $runs=self::verifiedRuns($plan,$track);
            if(!$runs)$data['rejected_candidates'][]=['candidate_id'=>$track['candidate_id'],'reason'=>'Source verification failed: one-way coverage, legal heading or pavement separation'];
            foreach($runs as $points){
                $length=0.;for($j=1;$j<count($points);$j++)$length+=hypot($points[$j][0]-$points[$j-1][0],$points[$j][1]-$points[$j-1][1]);
                if($length<self::MIN_LENGTH_M||count($resolved)>=self::MAX_TRACKS)continue;
                $copy=$track;$points=self::mergeApproaches($plan,$track,$points);$copy['points']=$points;unset($copy['candidate_points']);$copy['merge_nodes']=[];foreach([$points[0],end($points)] as $endpoint)foreach($plan['points'] as $node)if(hypot($endpoint[0]-$node[0],$endpoint[1]-$node[1])<0.05){$copy['merge_nodes'][]=$node;break;}$copy['length_m']=0.;for($k=1;$k<count($points);$k++)$copy['length_m']+=hypot($points[$k][0]-$points[$k-1][0],$points[$k][1]-$points[$k-1][1]);$copy['name']='carriageway_'.count($resolved);$resolved[]=$copy;
            }
        }unset($track);$data['tracks']=$resolved;
        $warnings=[];if($data['ambiguous_matches'])$warnings[]='Ambiguous opposite-carriageway matches were omitted rather than guessed.';
        if($data['matching_cap_reached'])$warnings[]='Opposite-carriageway matching reached the four-stretch export cap.';
        if($warnings)$data['warning']=implode(' ',$warnings);
        return $data;
    }
    public static function verifiedRuns(array $plan,array $track): array
    {
        $own=new BeamRoadIndex($track['sources']);$main=new BeamRoadIndex([['points'=>$plan['points']]]);$attributes=new BeamRoadIndex($plan['sources']);$runs=[];$run=[];
        foreach($track['points'] as $i=>$p){
            $source=$own->nearest($p,2);$near=$main->nearest($p,self::RANGE_M);$ok=$source&&$near&&(int)$source['line']['direction']!==0;
            if($ok){
                $a=$plan['points'][$near['segment']-1];$b=$plan['points'][$near['segment']];$t=$near['fraction'];$s=$attributes->nearest([$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t],2);
                $x=$track['points'][max(0,$i-1)];$y=$track['points'][min(count($track['points'])-1,$i+1)];$length=hypot($y[0]-$x[0],$y[1]-$x[1])?:1;
                $dot=(($y[0]-$x[0])/$length*$source['dx']+($y[1]-$x[1])/$length*$source['dy'])*(int)$source['line']['direction'];
                $ok=$s&&(int)$s['line']['direction']!==0&&$dot>0.8;
                if($ok){$a=$s['line'];$b=$source['line'];$w1=BeamLanes::infer($a['tags'],$a['highway'],(int)$a['direction'])['width_m'];$w2=BeamLanes::infer($b['tags'],$b['highway'],(int)$b['direction'])['width_m'];
                    $ok=$near['distance']>=($w1+$w2)/2+2*BeamRoadSurface::PAVED_SHOULDER_M+0.25;}
            }
            if($ok){$run[]=$p;continue;}if(count($run)>1)$runs[]=$run;$run=[];
        }
        if(count($run)>1)$runs[]=$run;return $runs;
    }
    /** Retain mapped approaches to an actual shared node; never synthesize a median crossing. */
    public static function mergeApproaches(array $plan,array $track,array $run): array
    {
        $raw=BeamPlan::sample($track['candidate_points']??$track['points'],20,0.001);
        $shared=[];foreach($plan['points'] as $p)$shared[sprintf('%.2F:%.2F',$p[0],$p[1])]=true;
        $own=new BeamRoadIndex(array_values(array_filter($track['sources'],static fn($s)=>(int)$s['direction']!==0)));$main=new BeamRoadIndex([['points'=>$plan['points']]]);
        foreach([false,true] as $end){
            $p=$end?end($run):$run[0];$best=INF;$at=0;
            foreach($raw as $i=>$v){$d=hypot($v[0]-$p[0],$v[1]-$p[1]);if($d<$best){$best=$d;$at=$i;}}
            if($best>0.1)continue;$direction=$end?1:-1;$distance=0.;$extension=[];$joined=false;
            for($i=$at+$direction;isset($raw[$i]);$i+=$direction){
                $v=$raw[$i];$last=$raw[$i-$direction];$distance+=hypot($v[0]-$last[0],$v[1]-$last[1]);if($distance>300)break;
                $s=$own->nearest($v,2);if(!$s||(int)$s['line']['direction']===0)break;
                $extension[]=$v;
                if(isset($shared[sprintf('%.2F:%.2F',$v[0],$v[1])])&&($main->nearest($v,0.05)['distance']??INF)<0.05){$joined=true;break;}
            }
            if($joined)$run=$end?array_merge($run,$extension):array_merge(array_reverse($extension),$run);
        }
        return $run;
    }
    /** Match merge elevations to the selected road without moving its geometry. */
    public static function conformMerges(array $track,array $main,array $profiles,array $bounds): array
    {
        $index=new BeamRoadIndex([['points'=>$main]]);$sections=BeamJunctionBoundary::sections($main,$profiles);
        $chain=$track['chain'];$total=end($chain);$mergeEndpoints=[];
        foreach([0,count($track['road'])-1] as $endpoint){
            $p=$track['road'][$endpoint];$world=[$p[0]+$bounds[0],$p[1]+$bounds[1]];
            $mapped=false;foreach($track['merge_nodes']??[] as $node)if(hypot($world[0]-$node[0],$world[1]-$node[1])<0.1)$mapped=true;
            if(!$mapped)continue;$mergeEndpoints[]=$endpoint;
            foreach($track['road'] as $i=>&$v){
                $distance=$endpoint===0?$chain[$i]:$total-$chain[$i];if($distance>100)continue;
                foreach(array_merge($track['bridges']??[],$track['tunnels']??[]) as $span)if($chain[$i]>=$span['start_m']-10&&$chain[$i]<=$span['end_m']+10)continue 2;
                $near=$index->nearest($v,80);if(!$near)continue;$j=$near['segment'];$t=$near['fraction'];
                $target=$main[$j-1][2]*(1-$t)+$main[$j][2]*$t;
                $half=($profiles[$j-1]['width_m']*(1-$t)+$profiles[$j]['width_m']*$t+$track['profiles'][$i]['width_m'])/2;
                $fade=max(0,min(1,($near['distance']-$half)/20));$fade=$fade*$fade*(3-2*$fade);
                $fraction=$distance/100;$weight=(1-$fade)*(1-3*$fraction**2+2*$fraction**3);
                [$a,$b]=$sections[$j-1];[$d,$c]=$sections[$j];
                foreach([[$a,$b,$c],[$a,$c,$d]] as $triangle){
                    $w=BeamTerrainContact::weights($v,$triangle);
                    if($w&&min($w)>=-0.000001){$target=0.;foreach($w as $k=>$f)$target+=$f*$triangle[$k][2];break;}
                }
                $v[2]+=$weight*($target-$v[2]);
            }unset($v);
        }
        $track['merge_junctions']=[];foreach($mergeEndpoints as $i){$p=$track['road'][$i];$track['merge_junctions'][]=['x'=>$p[0],'y'=>$p[1],'z'=>$p[2]];}
        return $track;
    }
    /** Link networks only at mapped, prepared at-grade junctions, never by median proximity. */
    public static function connectNavigation(array $objects,array $tracks,array &$segments): int
    {
        if(!$tracks)return 0;
        $grid=[];$nodes=[];
        foreach($objects as $o)if(($o['class']??'')==='BeamNGWaypoint'){
            $id=count($nodes);$nodes[]=$o;$p=$o['position'];$grid[(int)floor($p[0]/4).':'.(int)floor($p[1]/4)][]=$id;
        }
        $count=0;$seen=[];
        foreach($tracks as $track)foreach(array_merge($track['context']['junctions'],$track['merge_junctions']??[]) as $join){
            $cx=(int)floor($join['x']/4);$cy=(int)floor($join['y']/4);$own=null;$other=null;$dOwn=INF;$dOther=INF;
            for($x=$cx-1;$x<=$cx+1;$x++)for($y=$cy-1;$y<=$cy+1;$y++)foreach($grid[$x.':'.$y]??[] as $id){
                $o=$nodes[$id];$p=$o['position'];$d=hypot($p[0]-$join['x'],$p[1]-$join['y']);
                if($d>1||abs($p[2]-$join['z'])>0.5)continue;
                if(str_starts_with($o['name'],$track['name'].'_')){if($d<$dOwn){$own=$o;$dOwn=$d;}}
                elseif($d<$dOther){$other=$o;$dOther=$d;}
            }
            if(!$own||!$other||abs($own['position'][2]-$other['position'][2])>0.5||hypot($own['position'][0]-$other['position'][0],$own['position'][1]-$other['position'][1])>2)continue;
            $pair=[$own['name'],$other['name']];sort($pair);$key=implode('|',$pair);if(isset($seen[$key]))continue;$seen[$key]=true;
            $segments['carriageway_junction_'.$count]=['nodes'=>[$other['name'],$own['name']],'drivability'=>1,'oneWay'=>false,'autoLanes'=>false,'lanesLeft'=>1,'lanesRight'=>1,'autoJunction'=>false,'hiddenInNavi'=>false];$count++;
        }return $count;
    }
    public static function owner(array $point,array $geometries): int
    {
        $best=INF;$owner=0;foreach($geometries as $i=>$g){$d=$g->edgeDistance($point[0],$point[1]);if($d<$best){$best=$d;$owner=$i;}}return $owner;
    }
    public static function filterContext(array $context,array $tracks): array
    {
        $ids=[];foreach($tracks as $track)foreach($track['sources'] as $s)if(isset($s['osm_id']))$ids[$s['osm_id']]=true;
        $context['branches']=array_values(array_filter($context['branches']??[],static fn($b)=>!isset($ids[$b['osm_id']??0])));return $context;
    }
}
