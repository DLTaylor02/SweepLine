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
    /** Build export-local continuous paths from deduplicated active OSM ways, not regional discoveries. */
    public static function networkCandidates(array $roads,callable $progress): array
    {
        $unique=[];$points=0;
        foreach($roads as $road){
            $id=(string)$road['osm_id'];if(isset($unique[$id]))continue;
            $points+=count($road['points']);if($points>120000)throw new \RuntimeException('Opposite-carriageway source geometry exceeds 120,000 points. Select a shorter stretch.');
            $unique[$id]=$road;
        }
        if(count($unique)>500)throw new \RuntimeException('Opposite carriageway exceeds the 500-source-way budget. Select a shorter stretch.');
        $out=[];
        foreach(RoadGraph::candidates(array_values($unique),static fn()=>$progress(null),true) as $path){
            if(count($out)>=64)throw new \RuntimeException('Too many possible opposite-carriageway paths; select a shorter stretch.');
            $parts=$path['parts'];$first=$parts[0];$sources=[];
            foreach($parts as $part)$sources[]=['points'=>$part['points'],'tags'=>$part['tags'],'highway'=>$part['highway'],'direction'=>(int)$part['direction'],'osm_id'=>(int)$part['osm_id']];
            $out[]=['id'=>count($out)+1,'name'=>$first['name']?:($first['ref']?:'Unnamed road'),'ref'=>$first['ref'],'highway'=>$first['highway'],'direction'=>RoadGraph::direction($parts),'points'=>$path['points'],'sources'=>$sources];
        }
        return $out;
    }
    public static function fetch(PDO $db,array $plan,callable $progress): array
    {
        if(!array_filter($plan['sources'],static fn($s)=>(int)($s['direction']??0)!==0))return ['tracks'=>[],'source'=>'No one-way source sections'];
        $progress('Matching the opposite carriageway from connected local OSM ways');
        $q=$db->prepare("WITH route AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)) g)
            SELECT DISTINCT ON (s.osm_id) s.id,s.osm_id,s.name,s.ref,s.highway,s.direction,s.tags,s.node_ids,
                ST_NPoints(s.geom) point_count,ST_AsGeoJSON(ST_Transform(s.geom,ST_SRID(p.g)),6) geometry
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id CROSS JOIN route p
            WHERE s.direction<>0 AND s.highway IN ('motorway','trunk','primary','secondary','tertiary','unclassified')
              AND s.geom && ST_Transform(ST_Expand(p.g,CAST(? AS double precision)),4326)
              AND ST_DWithin(s.geom::geography,ST_Transform(p.g,4326)::geography,CAST(? AS double precision))
              AND lower(trim(COALESCE(NULLIF(s.ref,''),s.name))) IN (SELECT value FROM jsonb_array_elements_text(?::jsonb))
            ORDER BY s.osm_id,r.imported_at DESC NULLS LAST,s.id DESC LIMIT 501");
        $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg'],self::RANGE_M,self::RANGE_M,json_encode(self::identities($plan),JSON_THROW_ON_ERROR)]);
        $roads=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($roads)>500)throw new \RuntimeException('Opposite carriageway exceeds the 500-source-way budget. Select a shorter stretch.');
        if(array_sum(array_column($roads,'point_count'))>120000)throw new \RuntimeException('Opposite-carriageway source geometry exceeds 120,000 points. Select a shorter stretch.');
        foreach($roads as &$road){
            $road['points']=json_decode($road['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];
            $road['nodes']=json_decode($road['node_ids'],true,512,JSON_THROW_ON_ERROR);$road['tags']=json_decode($road['tags'],true,512,JSON_THROW_ON_ERROR);
            $road['direction']=(int)$road['direction'];unset($road['geometry'],$road['node_ids']);
        }unset($road);
        $paths=self::networkCandidates($roads,$progress);$data=self::match($plan,$paths,$progress);
        $data['source']='Deduplicated active OSM ways joined through shared nodes, identity, class and legal direction; planar continuity';
        $data['candidate_id_namespace']='Export-local continuity path identifiers, not road_candidates database IDs';
        $data['source_ways']=count($roads);$data['network_paths']=count($paths);$resolved=[];$sourcePointCount=0;$widthOverlaps=0;
        $main=new BeamRoadIndex([['points'=>$plan['points']]]);
        foreach($data['tracks'] as $track){
            $pathId=$track['candidate_id'];$track['sources']=$paths[$pathId-1]['sources'];
            foreach($track['sources'] as $source)$sourcePointCount+=count($source['points']);
            if($sourcePointCount>120000)throw new \RuntimeException('Opposite-carriageway source geometry exceeds the export budget. Select a shorter road.');
            $runs=self::verifiedRuns($plan,$track,$verification);$track['pavement_overlap']=$verification;$widthOverlaps+=$verification['inferred_width_overlap_samples'];
            if(!$runs)$data['rejected_candidates'][]=['candidate_id'=>$pathId,'reason'=>'Source verification failed: one-way coverage or legal heading'];
            foreach($runs as $points){
                $length=0.;for($j=1;$j<count($points);$j++)$length+=hypot($points[$j][0]-$points[$j-1][0],$points[$j][1]-$points[$j-1][1]);
                if($length<self::MIN_LENGTH_M)continue;
                if(count($resolved)>=self::MAX_TRACKS){$data['matching_cap_reached']=true;break;}
                $copy=$track;$points=self::mergeApproaches($plan,$track,$points);$copy['points']=$points;unset($copy['candidate_points']);
                $copy['candidate_id']=null;$copy['network_path_id']=$pathId;$copy['source_way_ids']=array_values(array_unique(array_column($track['sources'],'osm_id')));
                $copy['merge_nodes']=[];
                foreach([$points[0],end($points)] as $endpoint)foreach($plan['points'] as $node)if(hypot($endpoint[0]-$node[0],$endpoint[1]-$node[1])<0.05){$copy['merge_nodes'][]=$node;break;}
                $copy['length_m']=0.;for($k=1;$k<count($points);$k++)$copy['length_m']+=hypot($points[$k][0]-$points[$k-1][0],$points[$k][1]-$points[$k-1][1]);
                $stations=[];foreach($points as $p){$near=$main->nearest($p,self::RANGE_M);if($near)$stations[]=$near['along'];}
                $copy['main_range']=[min($stations),max($stations)];$copy['name']='carriageway_'.count($resolved);$resolved[]=$copy;
            }
        }
        $data['tracks']=$resolved;$intervals=array_column($resolved,'main_range');usort($intervals,static fn($a,$b)=>$a[0]<=>$b[0]);$covered=0.;$end=0.;
        foreach($intervals as [$lo,$hi]){$covered+=max(0,$hi-max($lo,$end));$end=max($end,$hi);}
        $total=0.;for($i=1;$i<count($plan['points']);$i++)$total+=hypot($plan['points'][$i][0]-$plan['points'][$i-1][0],$plan['points'][$i][1]-$plan['points'][$i-1][1]);
        $data['coverage']=['selected_length_m'=>$total,'matched_selected_span_m'=>$covered,'unmatched_selected_span_m'=>max(0,$total-$covered)];
        $data['inferred_width_overlap_samples']=$widthOverlaps;$warnings=[];
        if($widthOverlaps)$warnings[]='Inferred carriageway widths overlap in places; shared pavement is clipped with the selected road taking priority.';
        if($data['ambiguous_matches'])$warnings[]='Ambiguous opposite-carriageway matches were omitted rather than guessed.';
        if($data['matching_cap_reached'])$warnings[]='Opposite-carriageway matching reached the four-stretch export cap.';
        if($total>0&&$covered/$total<0.9&&!array_filter($plan['sources'],static fn($s)=>(int)($s['direction']??0)===0))
            $warnings[]=sprintf('Opposite-carriageway coverage is partial (%.1f of %.1f miles); local OSM geometry could not confirm the remainder.',$covered/1609.344,$total/1609.344);
        if($warnings)$data['warning']=implode(' ',$warnings);
        return $data;
    }
    public static function verifiedRuns(array $plan,array $track,?array &$analysis=null): array
    {
        $own=new BeamRoadIndex($track['sources']);$main=new BeamRoadIndex([['points'=>$plan['points']]]);$attributes=new BeamRoadIndex($plan['sources']);$runs=[];$run=[];$analysis=['inferred_width_overlap_samples'=>0,'maximum_inferred_overlap_m'=>0.];
        foreach($track['points'] as $i=>$p){
            $source=$own->nearest($p,2);$near=$main->nearest($p,self::RANGE_M);$ok=$source&&$near&&(int)$source['line']['direction']!==0;
            if($ok){
                $a=$plan['points'][$near['segment']-1];$b=$plan['points'][$near['segment']];$t=$near['fraction'];$s=$attributes->nearest([$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t],2);
                $x=$track['points'][max(0,$i-1)];$y=$track['points'][min(count($track['points'])-1,$i+1)];$length=hypot($y[0]-$x[0],$y[1]-$x[1])?:1;
                $dot=(($y[0]-$x[0])/$length*$source['dx']+($y[1]-$x[1])/$length*$source['dy'])*(int)$source['line']['direction'];
                $ok=$s&&(int)$s['line']['direction']!==0&&$dot>0.8;
                if($ok){$a=$s['line'];$b=$source['line'];$w1=BeamLanes::infer($a['tags'],$a['highway'],(int)$a['direction'])['width_m'];$w2=BeamLanes::infer($b['tags'],$b['highway'],(int)$b['direction'])['width_m'];
                    $overlap=($w1+$w2)/2+2*BeamRoadSurface::PAVED_SHOULDER_M-$near['distance'];
                    if($overlap>0){$analysis['inferred_width_overlap_samples']++;$analysis['maximum_inferred_overlap_m']=max($analysis['maximum_inferred_overlap_m'],$overlap);}}
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
        $track=BeamDividedJunctions::align($track,$main,$profiles,$bounds);
        $index=new BeamRoadIndex([['points'=>$main]]);$sections=BeamJunctionBoundary::sections($main,$profiles);
        $chain=$track['chain'];$total=end($chain);$mergeEndpoints=[];
        foreach([0,count($track['road'])-1] as $endpoint){
            $p=$track['road'][$endpoint];$world=[$p[0]+$bounds[0],$p[1]+$bounds[1]];
            $mapped=isset($track['shared_junctions'][$endpoint]);foreach($track['merge_nodes']??[] as $node)if(hypot($world[0]-$node[0],$world[1]-$node[1])<0.1)$mapped=true;
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
        $track['merge_junctions']=[];foreach($mergeEndpoints as $i){$p=$track['road'][$i];$track['merge_junctions'][]=['x'=>$p[0],'y'=>$p[1],'z'=>$p[2]]+(isset($track['shared_junctions'][$i])?['primary_position'=>$track['shared_junctions'][$i]['primary_position']]:[]);}
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
            $target=$join['primary_position']??[$join['x'],$join['y'],$join['z']];$own=null;$other=null;$dOwn=INF;$dOther=INF;$candidates=[];
            foreach([[$join['x'],$join['y']],$target] as $center){$cx=(int)floor($center[0]/4);$cy=(int)floor($center[1]/4);
                for($x=$cx-1;$x<=$cx+1;$x++)for($y=$cy-1;$y<=$cy+1;$y++)foreach($grid[$x.':'.$y]??[] as $id)$candidates[$id]=true;
            }
            foreach($candidates as $id=>$_){
                $o=$nodes[$id];$p=$o['position'];$isOwn=str_starts_with($o['name'],$track['name'].'_');
                $center=$isOwn?[$join['x'],$join['y'],$join['z']]:$target;$d=hypot($p[0]-$center[0],$p[1]-$center[1]);
                if($d>1||abs($p[2]-$center[2])>0.5)continue;
                if($isOwn){if($d<$dOwn){$own=$o;$dOwn=$d;}}elseif($d<$dOther){$other=$o;$dOther=$d;}
            }
            $maxLink=isset($join['primary_position'])?BeamDividedJunctions::MAX_SHIFT_M+2:2;
            if(!$own||!$other||abs($own['position'][2]-$other['position'][2])>0.5||hypot($own['position'][0]-$other['position'][0],$own['position'][1]-$other['position'][1])>$maxLink)continue;
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
