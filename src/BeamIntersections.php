<?php
declare(strict_types=1);
namespace SweepLine;
use PDO;

/** Context-only roadside branches from genuine shared OSM junction nodes. */
final class BeamIntersections
{
    public const LENGTH_M=182.88;
    public static function clip(array $points,float $limit=self::LENGTH_M): array
    {
        if(count($points)<2)return [];
        $out=[$points[0]];$remaining=$limit;
        for($i=1;$i<count($points)&&$remaining>0.001;$i++){
            $a=$points[$i-1];$b=$points[$i];$d=hypot($b[0]-$a[0],$b[1]-$a[1]);if($d<0.001)continue;
            $t=min(1,$remaining/$d);$out[]=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t];$remaining-=$d;
        }
        return count($out)>1?$out:[];
    }
    public static function fetch(PDO $db,array $plan,callable $progress): array
    {
        $progress('Preparing intersecting road context from local OSM junctions');
        // Spatially restrict first, then require a shared OSM node on the selected line.
        $q=$db->prepare("WITH route AS (
            SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),CAST(? AS integer)) g
        ), nearby AS MATERIALIZED (
            SELECT s.*,ST_Transform(s.geom,ST_SRID(p.g)) projected
            FROM road_segments s JOIN regions r ON r.active_job_id=s.job_id CROSS JOIN route p
            WHERE s.geom && ST_Transform(ST_Expand(p.g,2),4326)
        ), main AS (
            SELECT s.id,s.job_id FROM nearby s CROSS JOIN route p
            WHERE ST_Length(ST_Intersection(s.projected,ST_Buffer(p.g,0.5)))>LEAST(10,ST_Length(s.projected)*0.6)
            AND (s.highway NOT LIKE '%_link' OR s.osm_id IN (SELECT value::bigint FROM jsonb_array_elements_text(?::jsonb)))
        ), junctions AS (
            SELECT DISTINCT c.job_id,c.node_id
            FROM main m JOIN road_connectivity c ON c.segment_id=m.id
            JOIN osm_nodes n ON n.job_id=c.job_id AND n.osm_id=c.node_id CROSS JOIN route p
            WHERE ST_DWithin(ST_Transform(ST_SetSRID(ST_MakePoint(n.lon,n.lat),4326),ST_SRID(p.g)),p.g,0.5)
        )
        SELECT DISTINCT s.osm_id,s.tags,s.highway,s.direction,s.node_ids,c.point_index,ST_AsGeoJSON(s.projected,6) geometry
        FROM junctions j JOIN road_connectivity c ON c.job_id=j.job_id AND c.node_id=j.node_id
        JOIN nearby s ON s.id=c.segment_id
        WHERE NOT EXISTS(SELECT 1 FROM main m WHERE m.id=s.id)
        AND ST_NPoints(s.projected)<=2000
        AND s.highway IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary','secondary_link','tertiary','tertiary_link','unclassified','residential','living_street')
        AND COALESCE(s.tags->>'access','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motor_vehicle','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motorcar','') NOT IN ('no','private')
        ORDER BY s.osm_id,c.point_index LIMIT 1001");
        $geometry=isset($plan['context_lines'])?['type'=>'MultiLineString','coordinates'=>$plan['context_lines']]:['type'=>'LineString','coordinates'=>$plan['points']];
        $selectedIds=array_values(array_unique(array_filter(array_column($plan['sources']??[],'osm_id'),static fn($id)=>$id!==null)));
        $q->execute([json_encode($geometry,JSON_THROW_ON_ERROR),$plan['epsg'],json_encode($selectedIds,JSON_THROW_ON_ERROR)]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$out=[];$seen=[];$truncated=count($rows)>1000;if($truncated)throw new BeamInterchangeLimit('Interchange attachment query exceeded 1,000 junction records. Select a shorter road.');
        foreach(array_slice($rows,0,1000) as $row){
            $progress(null);$points=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];$at=(int)$row['point_index'];
            $tags=json_decode($row['tags'],true,512,JSON_THROW_ON_ERROR);
            foreach([array_reverse(array_slice($points,0,$at+1)),array_slice($points,$at)] as $armSide=>$arm){
                $arm=self::clip($arm);if(!$arm)continue;
                $key=hash('sha256',json_encode(array_map(static fn($p)=>[round($p[0],2),round($p[1],2)],$arm)));
                if(isset($seen[$key]))continue;$seen[$key]=true;

                $profile=BeamLanes::infer($tags,$row['highway'],(int)$row['direction'],$armSide===1);
                $profile['legal_forward']=(int)$row['direction']===0||(((int)$row['direction']>0)===($armSide===1));
                $nodes=json_decode($row['node_ids']??'[]',true,512,JSON_THROW_ON_ERROR);
                $out[]=['points'=>$arm,'full_points'=>$armSide===0?array_reverse(array_slice($points,0,$at+1)):array_slice($points,$at),
                    'arm_nodes'=>$armSide===0?array_reverse(array_slice($nodes,0,$at+1)):array_slice($nodes,$at),
                    'highway'=>$row['highway'],'direction'=>(int)$row['direction'],'tags'=>$tags,'width_m'=>$profile['width_m'],'profile'=>$profile,'osm_id'=>(int)$row['osm_id']];
            }
        }
        $network=BeamRampNetwork::expand($db,$plan,$out,$progress);
        return ['branches'=>$network['branches'],'graph'=>$network['graph'],'ramp_network'=>$network['ramp_network'],'warning'=>$network['ramp_network']['reasons']?'Some ramp context is limited; see ramp_network reasons in the build report.':null,'truncated'=>$truncated||$network['truncated'],'source'=>'Local active OSM shared-node connectivity','limit_ft'=>600];
    }
    /** Native terrain decals add context without overlapping road collision meshes. */
    public static function objects(array $data,BeamRaster $raster,array $bounds,float $origin,string $material): array
    {
        $out=[];
        foreach($data['branches']??[] as $i=>$branch){
            $nodes=[];
            $arm=self::clip($branch['points']);if(!$arm)continue;
            foreach(BeamPlan::sample($arm) as $p){
                $x=$p[0]-$bounds[0];$y=$p[1]-$bounds[1];
                if($x<0||$y<0||$x>=$raster->side||$y>=$raster->side)break;
                $nodes[]=[$x,$y,$raster->height($x,$y)-$origin,$branch['width_m']];
            }
            if(count($nodes)<2)continue;
            $out[]=['class'=>'DecalRoad','name'=>'intersection_context_'.$i,'__parent'=>'MissionGroup','position'=>array_slice($nodes[0],0,3),
                'material'=>$material,'nodes'=>$nodes,'textureLength'=>BeamPng::ROAD_TEXTURE_LENGTH_M,'overObjects'=>false,'improvedSpline'=>false,
                'drivability'=>0,'hiddenInNavi'=>true,'lanesLeft'=>0,'lanesRight'=>0];
        }
        return $out;
    }

    public static function nearest(array $point,array $road,array $profiles): array
    {
        $best=INF;$along=0.0;$out=[];
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy);if($length<0.001)continue;
            $t=max(0,min(1,(($point[0]-$a[0])*$dx+($point[1]-$a[1])*$dy)/($length*$length)));
            $distance=hypot($point[0]-$a[0]-$t*$dx,$point[1]-$a[1]-$t*$dy);
            $vx=$dx/$length;$vy=$dy/$length;
            if($t>0.999&&isset($road[$i+1])){
                $nx=$road[$i+1][0]-$b[0];$ny=$road[$i+1][1]-$b[1];$nl=hypot($nx,$ny);
                if($nl>0.001){$vx+=$nx/$nl;$vy+=$ny/$nl;$vl=hypot($vx,$vy);if($vl>0.001){$vx/=$vl;$vy/=$vl;}}
            }
            if($distance<$best){$best=$distance;$out=['distance'=>$distance,'z'=>$a[2]+($b[2]-$a[2])*$t,'along'=>$along+$length*$t,
                'x'=>$a[0]+$t*$dx,'y'=>$a[1]+$t*$dy,'segment'=>$i,'grade'=>($b[2]-$a[2])/$length,'dx'=>$vx,'dy'=>$vy,'half_width'=>($profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t)/2];}
            $along+=$length;
        }
        return $out;
    }
    public static function after(array $points,float $distance): array
    {
        for($i=1;$i<count($points);$i++){
            $a=$points[$i-1];$b=$points[$i];$d=hypot($b[0]-$a[0],$b[1]-$a[1]);
            if($d<0.001)continue;
            if($distance<$d){$t=$distance/$d;return array_merge([[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t]],array_slice($points,$i));}
            $distance-=$d;
        }
        return [];
    }
    /** Match main-road elevation and keep collision meshes outside its footprint. */
    public static function prepare(array $data,array $road,array $profiles,BeamRaster $raster,array $bounds,float $origin,array $bridges,callable $progress): array
    {
        $out=['roads'=>[],'aprons'=>[],'junctions'=>[],'skipped'=>0];$parents=[];$primaryRoad=$road;$primaryProfiles=$profiles;$childJunctions=[];
        $mainSurfaces=$data['main_surfaces']??[['name'=>'primary','points'=>$road,'profiles'=>$profiles,'spans'=>$bridges]];
        $anchors=[];$sourceIndexes=[];
        foreach($data['graph']['attachments']??[] as $node=>$p){
            $local=[$p[0]-$bounds[0],$p[1]-$bounds[1]];$best=null;
            foreach($mainSurfaces as $surface){$n=self::nearest($local,$surface['points'],$surface['profiles']);
                $score=$n['distance']??INF;
                if(isset($surface['source_points'])){$sourceIndex=$sourceIndexes[$surface['name']]??=new BeamRoadIndex([['points'=>$surface['source_points']]]);$score=$sourceIndex->nearest($p,25)['distance']??INF;}
                if($n&&(!$best||$score<$best['score']))$best=['join'=>$n,'surface'=>$surface,'score'=>$score];}
            if($best)$anchors[(string)$node]=['point'=>[$best['join']['x'],$best['join']['y'],$best['join']['z']],'surface'=>$best['surface']];
        }
        $out['main_surfaces']=$mainSurfaces;$out['external_junctions']=[];$out['network']=(bool)array_filter($data['branches']??[],static fn($b)=>!empty($b['network']));$out['graph']=$data['graph']??null;
        foreach($data['branches']??[] as $index=>$branch){
            $road=$primaryRoad;$profiles=$primaryProfiles;$parent=null;$external=null;$network=!empty($branch['network']);$spans=$bridges;
            if(isset($branch['parent_context_id'])&&isset($parents[$branch['parent_context_id']])){
                $parent=$parents[$branch['parent_context_id']];$road=$out['roads'][$parent]['points'];$profiles=$out['roads'][$parent]['profiles'];$spans=array_merge($out['roads'][$parent]['bridges']??[],$out['roads'][$parent]['tunnels']??[]);
            }elseif($network&&isset($anchors[$branch['start_node']])){
                $owner=$anchors[$branch['start_node']];
                if(isset($owner['road_index'])){$parent=$owner['road_index'];$road=$out['roads'][$parent]['points'];$profiles=$out['roads'][$parent]['profiles'];$spans=array_merge($out['roads'][$parent]['bridges']??[],$out['roads'][$parent]['tunnels']??[]);}
                elseif(isset($owner['surface'])){$road=$owner['surface']['points'];$profiles=$owner['surface']['profiles'];$spans=$owner['surface']['spans']??[];$external=$owner['surface']['name']==='primary'?null:$owner['surface']['name'];}
            }elseif(isset($branch['parent_context_id'])){throw new BeamInterchangeLimit('A discovered interchange edge lost its parent before geometry preparation.');}
            elseif(count($mainSurfaces)>1){
                $p=$branch['points'][0];$p=[$p[0]-$bounds[0],$p[1]-$bounds[1]];$best=INF;
                foreach($mainSurfaces as $surface){$n=self::nearest($p,$surface['points'],$surface['profiles']);if($n&&$n['distance']<$best){$best=$n['distance'];$road=$surface['points'];$profiles=$surface['profiles'];$spans=$surface['spans']??[];$external=$surface['name']==='primary'?null:$surface['name'];}}
            }
            $progress(null);$arm=self::clip($branch['points'],$branch['limit_m']??self::LENGTH_M);if(!$arm)continue;
            $local=array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],$arm);
            if($network&&isset($anchors[$branch['start_node']]))$local[0]=array_slice($anchors[$branch['start_node']]['point'],0,2);
            if($network&&isset($anchors[$branch['end_node']]))$local[count($local)-1]=array_slice($anchors[$branch['end_node']]['point'],0,2);
            if(!$network&&$parent!==null&&hypot($local[0][0]-end($road)[0],$local[0][1]-end($road)[1])>.2){$out['skipped']++;continue;}
            $join=self::nearest($local[0],$road,$profiles);
            if(!$join||$join['distance']>($network?25:2)){if($network)throw new BeamInterchangeLimit('A mapped interchange attachment cannot be aligned to its exported parent road.');$out['skipped']++;continue;}
            if($network)$local[0]=[$join['x'],$join['y']];
            if($parent!==null)$join['endpoint_junction']=true;
            $bridge=false;foreach($spans as $span)if($join['along']>=$span['start_m']-1&&$join['along']<=$span['end_m']+1){
                if($network&&isset($span['clearance_m'])&&$join['along']>$span['start_m']+2&&$join['along']<$span['end_m']-2)
                    throw new \RuntimeException('Mapped interchange junction is inside a tunnel; branching tunnel interiors cannot currently be estimated.');
                $bridge=true;
            }
            $dx=$local[1][0]-$local[0][0];$dy=$local[1][1]-$local[0][1];$length=hypot($dx,$dy);if($length<0.001)continue;
            $sine=abs($dx/$length*$join['dy']-$dy/$length*$join['dx']);$cosine=abs($dx/$length*$join['dx']+$dy/$length*$join['dy']);
            $ramp=($branch['kind']??'')==='ramp';
            if(($bridge&&!$network)||($sine<0.2&&!$ramp&&!$network)){$out['skipped']++;continue;}
            $profile=$branch['profile']??BeamLanes::infer(['width'=>(string)$branch['width_m']],'residential',0);
            $profile['width_m']=$branch['width_m'];$profile=BeamRoadSurface::shoulders([$profile])[0];
            $trim=($join['half_width']+$profile['width_m']/2*$cosine)/max(.01,$sine)+max(8,$profile['width_m']/2);
            if($ramp){
                $fine=BeamPlan::sample($local,2,0.001);$trim=0.;$found=false;
                foreach($fine as $i=>$v){if($i)$trim+=hypot($v[0]-$fine[$i-1][0],$v[1]-$fine[$i-1][1]);$n=self::nearest($v,$road,$profiles);
                    if($n['distance']>$n['half_width']+$profile['width_m']/2+2){$found=true;break;}}
                if(!$found){if(!$network){$out['skipped']++;continue;}$trim=0.;}
                $sine=max(.05,$sine);
            }
            if($network&&($parent!==null||BeamNetworkStructures::segments($branch['source_parts']??[],$bounds)))$trim=0.;
            $sine=max(.05,$sine);
            $surface=$trim<.001?$local:self::after($local,$trim);if(count($surface)<2){$out['skipped']++;continue;}
            $sampled=BeamPlan::sample(BeamCurve::smooth($surface,[$surface[0],end($surface)],BeamNetworkStructures::segments($branch['source_parts']??[],$bounds)),2,0.001);$raw=[];$chain=[0.0];
            foreach($sampled as $i=>$p){$raw[]=$raster->roadHeight($p[0],$p[1]);if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$sampled[$i-1][0],$p[1]-$sampled[$i-1][1]);}
            $connectedApproaches=[];
            $finishOwner=$network?($anchors[$branch['end_node']]??null):null;
            if(isset($finishOwner['surface']))$connectedApproaches[]=$finishOwner['surface']['points'];
            elseif(isset($finishOwner['road_index']))$connectedApproaches[]=$out['roads'][$finishOwner['road_index']]['points'];
            $structure=BeamNetworkStructures::sample($sampled,$branch,$data['branches']??[],$road,$bounds,$raster,$connectedApproaches);$raw=$structure['raw'];
            $edgeProfiles=[];
            if($network&&!empty($branch['source_parts'])){
                $worldSampled=array_map(static fn($p)=>[$p[0]+$bounds[0],$p[1]+$bounds[1]],$sampled);
                $edgeProfiles=BeamRoadSurface::shoulders(BeamRoadSurface::taper($worldSampled,BeamLanes::profiles($worldSampled,$branch['source_parts'],$bounds)));
            }
            $nodes=[];$branchProfiles=[];
            foreach($sampled as $i=>$p){
                if($p[0]<0||$p[1]<0||$p[0]>=$raster->side||$p[1]>=$raster->side)break;
                $sum=0.0;$weights=0.0;
                $left=$i;while($left>0&&$chain[$i]-$chain[$left-1]<40)$left--;
                for($j=$left;$j<count($sampled)&&$chain[$j]-$chain[$i]<40;$j++){
                    if(abs($chain[$j]-$chain[$i])>=40)continue;
                    $weight=exp(-0.5*(($chain[$j]-$chain[$i])/12)**2);$sum+=$raw[$j]*$weight;$weights+=$weight;
                }
                $z=$sum/$weights-$origin+BeamMap::ROAD_ELEVATION_OFFSET_M;
                $near=self::nearest($p,$road,$profiles);$fade=min(1,$chain[$i]/30.48);$fade=$fade*$fade*(3-2*$fade);
                $anchor=$join['z']+(($p[0]-$join['x'])*$join['dx']+($p[1]-$join['y'])*$join['dy'])*$join['grade'];
                if($ramp)$anchor=$near['z'];
                $z=$anchor*(1-$fade)+$z*$fade;
                $nearMain=self::nearest($p,$road,$profiles);
                if($i){$hx=$p[0]-$sampled[$i-1][0];$hy=$p[1]-$sampled[$i-1][1];$hl=hypot($hx,$hy)?:1;$across=abs(($hx*$nearMain['dx']+$hy*$nearMain['dy'])/$hl);
                    if(!$network&&$nearMain['distance']<$nearMain['half_width']+$profile['width_m']/2*$across+0.02)break;}
                if($i&&abs($z-$nodes[$i-1][2])/max(0.01,$chain[$i]-$chain[$i-1])>0.35){
                    if(!$network)break;
                    $d=($chain[$i]-$chain[$i-1])*.35;$z=max($nodes[$i-1][2]-$d,min($nodes[$i-1][2]+$d,$z));
                }
                $nodes[]=[$p[0],$p[1],$z];$branchProfiles[]=($edgeProfiles[$i]??$profile)+['unmarked'=>false];
            }
            if(count($nodes)<2){$out['skipped']++;continue;}
            $connector=$trim<.001?[]:array_map(static fn($p)=>[$p[0],$p[1],$join['z']+(($p[0]-$join['x'])*$join['dx']+($p[1]-$join['y'])*$join['dy'])*$join['grade']],BeamPlan::sample(self::clip($local,$trim)));
            $endOwner=$network?($anchors[$branch['end_node']]??null):null;
            if($endOwner){
                $total=end($chain);$target=$endOwner['point'];$last=count($nodes)-1;
                if(hypot($nodes[$last][0]-$target[0],$nodes[$last][1]-$target[1])>.25)throw new BeamInterchangeLimit('An interchange endpoint no longer reaches its shared OSM node.');
                if(isset($endOwner['road_index']))$endOwner['surface']=['points'=>$out['roads'][$endOwner['road_index']]['points'],'profiles'=>$out['roads'][$endOwner['road_index']]['profiles']];
                $delta=$target[2]-$nodes[$last][2];
                foreach($nodes as $n=>&$v){$weight=max(0,1-($total-$chain[$n])/min(60,max(1,$total)));$weight=$weight*$weight*(3-2*$weight);$v[2]+=$delta*$weight;}unset($v);
            }
            $parents[$branch['context_id']??'branch_'.$index]=count($out['roads']);
            if($ramp&&$trim>.001)$connector=array_map(static function($p)use($road,$profiles){$n=self::nearest($p,$road,$profiles);return [$p[0],$p[1],$n['z']];},BeamPlan::sample(self::clip($local,$trim)));
            $junctionObstacles=[];
            if($network)foreach($out['roads'] as $otherIndex=>$other){
                if(empty($other['network'])||$otherIndex===$parent)continue;
                if(array_intersect([$branch['start_node'],$branch['end_node']],[$other['start_node'],$other['end_node']]))
                    $junctionObstacles[]=['road'=>$other['points'],'profiles'=>$other['profiles']];
            }
            $out['roads'][]=['junction_obstacles'=>$junctionObstacles,'parent_name'=>$parent===null?null:$out['roads'][$parent]['name'],'junction_parent'=>$parent===null&&$external===null?null:['points'=>$road,'profiles'=>$profiles],
                'network'=>$network,'external_main'=>$external,'start_node'=>$branch['start_node']??null,'end_node'=>$branch['end_node']??null,'end_owner'=>$endOwner,
                'bridges'=>$structure['bridges'],'tunnels'=>$structure['tunnels'],'structure_approaches'=>array_intersect_key($structure,array_flip(['approach_before_m','approach_after_m'])),'kind'=>$branch['kind']??'crossroad','context_id'=>$branch['context_id']??null,'name'=>'intersection_context_'.$index,'osm_id'=>$branch['osm_id']??null,'points'=>$nodes,'profiles'=>$branchProfiles,'join'=>$join,'connector'=>$connector];
            $apron=self::clip($local,$trim+1);
            $apronSurface=array_map(static fn($p)=>[$p[0],$p[1],$join['z']+(($p[0]-$join['x'])*$join['dx']+($p[1]-$join['y'])*$join['dy'])*$join['grade']],BeamPlan::sample($apron));
            $out['aprons'][]=['points'=>$apron,'width_m'=>$profile['width_m'],'surface'=>$apronSurface];
            $junction=$join+['radius'=>$profile['width_m']/2/$sine,'branch_name'=>'intersection_context_'.$index,'side'=>($join['dx']*$dy-$join['dy']*$dx)>0?'left':'right'];
            if($network){
                $owner=['road_index'=>count($out['roads'])-1,'point'=>end($nodes)];
                $anchors[$branch['end_node']]??=$owner;
            }
            if($parent===null&&$external===null)$out['junctions'][]=$junction;
            elseif($external!==null)$out['external_junctions'][$external][]=$junction;
            else {
                $childJunctions[$parent][]=$junction;
            }
        }
        foreach($childJunctions as $index=>$joins){
            $out['roads'][$index]['junctions']=$joins;
            $marked=self::markingSections($out['roads'][$index]['points'],$out['roads'][$index]['profiles'],$joins);
            $out['roads'][$index]['points']=$marked['points'];$out['roads'][$index]['profiles']=$marked['profiles'];
        }
        $expected=array_column(array_values(array_filter($data['branches']??[],static fn($b)=>!empty($b['network']))),'context_id');
        $preparedIds=array_column(array_values(array_filter($out['roads'],static fn($b)=>!empty($b['network']))),'context_id');
        $missing=array_values(array_diff($expected,$preparedIds));
        if($missing)throw new BeamInterchangeLimit('Interchange edge '.$missing[0].' could not be prepared (structure attachment or unsupported geometry). No connected edge was silently omitted.');
        $out['graph']['network_edge_count']=count($expected);
        $out['network_manifest']=['discovered'=>count($expected),'prepared'=>count($preparedIds),'missing_context_ids'=>$missing,'acquisition_rejections'=>$data['graph']['rejections']??[]];
        $byName=[];foreach($out['roads'] as $branch)$byName[$branch['name']]=$branch;
        foreach($out['roads'] as &$branch)if(isset($branch['parent_name'])){
            $p=$byName[$branch['parent_name']];$branch['junction_parent']=['points'=>$p['points'],'profiles'=>$p['profiles']];
        }unset($branch);
        return $out;
    }
    public static function markJunctions(array $road,array $profiles,array $junctions): array
    {
        $along=0.0;
        foreach($road as $i=>$p){
            if($i)$along+=hypot($p[0]-$road[$i-1][0],$p[1]-$road[$i-1][1]);
            foreach($junctions as $join)if(abs($along-$join['along'])<=$join['radius']){$profiles[$i]['unmarked']=true;break;}
        }
        return $profiles;
    }

    /** Split only at marking boundaries; all new points lie on the original centreline. */
    public static function markingSections(array $road,array $profiles,array $junctions): array
    {
        if(!$junctions){foreach($profiles as &$profile){$profile['unmarked']=false;$profile['keep_edge_left']=false;$profile['keep_edge_right']=false;}unset($profile);return ['points'=>$road,'profiles'=>$profiles];}
        $cuts=[];foreach($junctions as $j){$cuts[]=max(0,$j['along']-$j['radius']);$cuts[]=$j['along']+$j['radius'];}sort($cuts);
        $points=[$road[0]];$out=[$profiles[0]];$along=0.0;$previous=0.0;
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);if($length<0.001)continue;
            $at=[];foreach($cuts as $cut)if($cut>$along+0.00001&&$cut<$along+$length-0.00001)$at[]=$cut;$at[]=$along+$length;$at=array_unique($at);
            foreach($at as $cut){
                $t=($cut-$along)/$length;$points[]=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t,$a[2]+($b[2]-$a[2])*$t];
                $p=$profiles[$i];$p['width_m']=$profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t;$p['unmarked']=false;if(isset($profiles[$i-1]['lane_paint'],$profiles[$i]['lane_paint']))foreach($p['lane_paint'] as $paintId=>&$line)foreach(['u','visible'] as $paintKey)$line[$paintKey]=$profiles[$i-1]['lane_paint'][$paintId][$paintKey]*(1-$t)+$profiles[$i]['lane_paint'][$paintId][$paintKey]*$t;unset($line);$p['keep_edge_left']=false;$p['keep_edge_right']=false;$mid=($previous+$cut)/2;
                $openLeft=false;$openRight=false;
                foreach($junctions as $join)if(abs($mid-$join['along'])<$join['radius']){$p['unmarked']=true;if(($join['side']??'')!=='right')$openLeft=true;if(($join['side']??'')!=='left')$openRight=true;}
                if($p['unmarked']){$p['keep_edge_left']=!$openLeft;$p['keep_edge_right']=!$openRight;}
                $out[]=$p;$previous=$cut;
            }
            $along+=$length;
        }
        $out[0]['unmarked']=$out[1]['unmarked']??false;$out[0]['keep_edge_left']=$out[1]['keep_edge_left']??false;$out[0]['keep_edge_right']=$out[1]['keep_edge_right']??false;
        return ['points'=>$points,'profiles'=>$out];
    }

    /** Group a branch, its connector and turnaround as one continuous terrain corridor. */
    private static function surfaces(array $prepared,BeamRaster $raster): array
    {
        $surfaces=[];
        foreach($prepared['roads'] as $i=>$branch){$branch['owner']=$branch['turnaround_for']??$branch['name']??'road_'.$i;$branch['bridges']=array_merge($branch['bridges']??[],$branch['tunnels']??[]);$surfaces[]=$branch;}
        foreach($prepared['aprons']??[] as $i=>$apron){
            $owner=$apron['owner']??null;
            if($owner===null){$last=end($apron['surface']);foreach($prepared['roads'] as $j=>$branch){$first=$branch['points'][0];if(hypot($last[0]-$first[0],$last[1]-$first[1])<0.0001){$owner=$branch['name']??'road_'.$j;break;}}}
            $surfaces[]=['owner'=>$owner??'apron_'.$i,'points'=>$apron['surface'],'profiles'=>$apron['profiles']??array_fill(0,count($apron['surface']),['width_m'=>$apron['width_m']])];
        }
        foreach($surfaces as &$surface){
            $margin=max(array_column($surface['profiles'],'width_m'))/2+0.25+sqrt(2)*$raster->side/$raster->size;
            $xs=array_column($surface['points'],0);$ys=array_column($surface['points'],1);$surface['bounds']=[min($xs)-$margin,min($ys)-$margin,max($xs)+$margin,max($ys)+$margin];
        }unset($surface);
        usort($surfaces,static fn($a,$b)=>strcmp($a['owner'],$b['owner']));
        return $surfaces;
    }
    private static function secondaryMask(array $surface,array $surfaces,BeamRaster $raster,string $primaryMask): string
    {
        $mask=$primaryMask;$a=$surface['bounds'];
        foreach($surfaces as $other){
            if($other['owner']===$surface['owner'])continue;$b=$other['bounds'];
            if($a[2]<$b[0]||$a[0]>$b[2]||$a[3]<$b[1]||$a[1]>$b[3])continue;
            $mask=BeamRoadSurface::primaryContactMask($other['points'],$other['profiles'],$raster,$mask);
        }
        return $mask;
    }

    public static function shape(array $prepared,array $main,array $profiles,BeamRaster $raster,float $origin,string $bridgeMask,callable $progress): array
    {
        $mask=BeamRoadSurface::primaryContactMask($main,$profiles,$raster,$bridgeMask);
        $surfaces=self::surfaces($prepared,$raster);$reports=[];
        if(!empty($prepared['network'])){
            $mask=$prepared['structure_mask']??str_repeat("\0",$raster->size*$raster->size);$all=$surfaces;
            foreach($prepared['main_surfaces']??[] as $surface)$all[]=['points'=>$surface['points'],'profiles'=>$surface['profiles'],'bridges'=>$surface['spans']??[]];
            $blend=BeamRoadSurface::blendTerrainSet($all,$raster,$origin,$mask,$progress);
            foreach($surfaces as $surface)$reports[]=['owner'=>$surface['owner'],'shared_blend'=>true,'shoulders'=>$blend,
                'clearance'=>BeamRoadSurface::clearTerrain($surface['points'],$surface['profiles'],$raster,$origin,$progress,$mask,true)];
            return $reports;
        }
        $blend=BeamRoadSurface::blendTerrainSet($surfaces,$raster,$origin,$mask,$progress);
        foreach($surfaces as $surface){
            $progress(null);$ownMask=self::secondaryMask($surface,$surfaces,$raster,$mask);
            $reports[]=['owner'=>$surface['owner'],'shared_blend'=>true,'shoulders'=>$blend,'clearance'=>BeamRoadSurface::clearTerrain($surface['points'],$surface['profiles'],$raster,$origin,$progress,$ownMask,true,0.5)];
        }
        return $reports;
    }

    public static function terrainTop(BeamRaster $raster,float $x,float $y): float
    {
        $step=$raster->side/$raster->size;$gx=max(0,min($raster->size-1,$x/$step));$gy=max(0,min($raster->size-1,$y/$step));
        $ix=(int)$gx;$iy=(int)$gy;$u=$gx-$ix;$v=$gy-$iy;
        $a=$raster->cell($ix,$iy);$b=$raster->cell($ix+1,$iy);$c=$raster->cell($ix,$iy+1);$d=$raster->cell($ix+1,$iy+1);
        $one=$u>=$v?$a*(1-$u)+$b*($u-$v)+$d*$v:$a*(1-$v)+$c*($v-$u)+$d*$u;
        $two=$u+$v<=1?$a*(1-$u-$v)+$b*$u+$c*$v:$d*($u+$v-1)+$b*(1-$v)+$c*(1-$u);
        return max($raster->height($x,$y),$one,$two);
    }
    /** Final clearance audit occurs after every terrain/apron pass; main road is immutable. */
    public static function conform(array $prepared,BeamRaster $raster,float $origin,callable $progress,array $main=[],array $mainProfiles=[],string $bridgeMask=""): array
    {
        if(!empty($prepared['network']))return self::conformNetwork($prepared,$raster,$origin,$progress);
        $mask=$bridgeMask?:str_repeat("\0",$raster->size*$raster->size);$branchMask=$main?BeamRoadSurface::primaryContactMask($main,$mainProfiles,$raster,$mask):$mask;$reports=[];$lowered=0;$maxCut=0.0;$clamped=0;
        // Lower final contact terrain rather than lifting road surfaces away from their shoulders.
        $surfaces=self::surfaces($prepared,$raster);
        for($pass=0;$pass<2;$pass++){
            foreach($surfaces as $branch){
                $progress(null);$ownMask=self::secondaryMask($branch,$surfaces,$raster,$branchMask);$report=BeamRoadSurface::clearTerrain($branch['points'],$branch['profiles'],$raster,$origin,$progress,$ownMask,true,0.5);
                $reports[]=$report;$clamped+=$report['clamped_vertices'];$lowered+=$report['lowered_vertices'];$maxCut=max($maxCut,$report['maximum_cut_m']);
            }
            if($main){$report=BeamRoadSurface::clearTerrain($main,$mainProfiles,$raster,$origin,$progress,$mask,true);$lowered+=$report['lowered_vertices'];$maxCut=max($maxCut,$report['maximum_cut_m']);}
        }
        $limited=[];
        if($main&&$clamped){
            $kept=[];
            foreach($prepared['roads'] as $branch){
                if(isset($branch['turnaround_for'])){$kept[]=$branch;continue;}
                $bad=null;foreach($branch['points'] as $i=>$p){
                    foreach(BeamPavement::section($branch['points'],$branch['profiles'],$i) as $edge)
                        if(self::terrainTop($raster,$edge[0],$edge[1])-$origin>$edge[2]+0.15){$bad=$i;break 2;}
                }
                if($bad===null){$kept[]=$branch;continue;}
                $limited[$branch['name']]=true;
                if($bad>=2){$branch['points']=array_slice($branch['points'],0,$bad);$branch['profiles']=array_slice($branch['profiles'],0,$bad);$kept[]=$branch;}
                else $prepared['skipped']=($prepared['skipped']??0)+1;
            }
            $before=count($kept);$kept=array_values(array_filter($kept,static fn($b)=>!isset($b['turnaround_for'])||!isset($limited[$b['turnaround_for']])));
            if(isset($prepared['turnarounds'])){$removed=$before-count($kept);$prepared['turnarounds']['created']=max(0,$prepared['turnarounds']['created']-$removed);$prepared['turnarounds']['skipped']+=$removed;}
            $byName=[];foreach($kept as $b)$byName[$b['name']]=$b;
            $kept=array_values(array_filter($kept,static function($b)use($byName){
                if(!isset($b['parent_name']))return true;
                $p=$byName[$b['parent_name']]??null;if(!$p)return false;$end=end($p['points']);
                return hypot($end[0]-$b['join']['x'],$end[1]-$b['join']['y'])<.2;
            }));
            $prepared['roads']=$kept;$prepared['aprons']=[];
            foreach($kept as $branch){$contact=BeamPavement::contact($branch,$main,$mainProfiles);if($contact){$contact['owner']=$branch['name'];$prepared['aprons'][]=$contact;}}
            $active=array_fill_keys(array_column($kept,'name'),true);
            $prepared['junctions']=array_values(array_filter($prepared['junctions']??[],static fn($j)=>!isset($j['branch_name'])||isset($active[$j['branch_name']])));
        }
        $prepared['final_clearance']=['limited_branches'=>count($limited),'clamped_vertices'=>$clamped,'secondary_cell_cut_limit_m'=>0.5,'raised_samples'=>0,'maximum_raise_m'=>0.0,'lowered_vertices'=>$lowered,'maximum_cut_m'=>$maxCut,
            'method'=>'Final terrain contact correction with primary supporting cells protected from branch cuts; pavement surfaces unchanged.'];
        return $prepared;
    }

    /** Network geometry is immutable: lower native terrain, never shorten connected edges. */
    private static function conformNetwork(array $prepared,BeamRaster $raster,float $origin,callable $progress): array
    {
        $mask=$prepared['structure_mask']??str_repeat("\0",$raster->size*$raster->size);$lowered=0;$cut=0.;
        foreach(self::surfaces($prepared,$raster) as $surface){
            $report=BeamRoadSurface::clearTerrain($surface['points'],$surface['profiles'],$raster,$origin,$progress,$mask,true);
            $exact=BeamTerrainContact::clear($surface['points'],$surface['profiles'],$raster,$origin,$mask,$progress);
            $lowered+=$report['lowered_vertices'];$cut=max($cut,$report['maximum_cut_m']);
        }
        $blocked=self::networkObstructions($prepared,$raster,$origin,$progress);$contactRepair=null;$initial=$blocked;
        if($blocked){
            // Broad raster masks include several cells beyond the actual structure.
            // Reuse the exact corridor pass before rejecting a legitimate adjacent road.
            $surfaces=[];
            foreach($prepared['main_surfaces']??[] as $surface)$surfaces[]=['name'=>$surface['name'],'road'=>$surface['points'],'profiles'=>$surface['profiles'],'spans'=>$surface['spans']??[]];
            foreach($prepared['roads'] as $branch)$surfaces[]=['name'=>$branch['name'],'road'=>$branch['points'],'profiles'=>$branch['profiles'],'spans'=>array_merge($branch['bridges']??[],$branch['tunnels']??[]),'contact_triangles'=>static fn()=>BeamPavement::drivingTriangles($branch,$prepared['main_surfaces']??[],max(2.,2*$raster->side/$raster->size))];
            $progress('Checking exact interchange clearance near structure approaches');
            $contactRepair=BeamTerrainContact::repairCorridors($surfaces,$raster,$origin,$progress);
            foreach($contactRepair['roads'] as $report){$lowered+=$report['lowered_vertices'];$cut=max($cut,$report['maximum_cut_m']);}
            $blocked=self::networkObstructions($prepared,$raster,$origin,$progress);
        }
        if($blocked){$b=$blocked[0];$message=sprintf(
            'Terrain cannot clear interchange edge %s (OSM way %s) at %.0f ft along the edge: terrain is %.2f ft above pavement after exact structure-footprint repair. Protected structure ground was retained; no connected road was shortened.',
            $b['name'],$b['osm_id']??'unknown',$b['along_m']/.3048,$b['overlap_m']/.3048);
            try{$diagnostic=BeamTerrainDiagnostic::capture($prepared,$raster,$origin,$b,$contactRepair??[]);}
            catch(\Throwable $captureError){throw new BeamInterchangeLimit($message.' Diagnostic capture was unavailable.',0,$captureError);}
            throw new BeamTerrainConflict($message,$diagnostic);
        }
        $prepared['final_clearance']=['limited_branches'=>0,'clamped_vertices'=>0,'lowered_vertices'=>$lowered,'maximum_cut_m'=>$cut,
            'exact_structure_contact'=>$contactRepair,'initial_structure_contacts'=>$initial,'method'=>'Road-priority native terrain blend and exact triangle clearance; complete connected edges retained; mapped structure support protected.'];
        $prepared['network_verification']=['discovered_edges'=>(int)($prepared['graph']['network_edge_count']??0),
            'prepared_edges'=>count(array_filter($prepared['roads'],static fn($r)=>!empty($r['network']))),'terrain_removed_edges'=>0];
        return $prepared;
    }

    private static function networkObstructions(array $prepared,BeamRaster $raster,float $origin,callable $progress): array
    {
        $blocked=[];
        foreach($prepared['roads'] as $branch){
            $progress(null);
            $triangles=BeamPavement::drivingTriangles($branch,$prepared['main_surfaces']??[]);
            $hit=BeamTerrainContact::obstruction($triangles,$raster,$origin);
            if($hit){$index=new BeamRoadIndex([['points'=>$branch['points']]]);$near=$index->nearest($hit['point'],200);
                $blocked[]=$hit+['name'=>$branch['name'],'context_id'=>$branch['context_id']??null,'osm_id'=>$branch['osm_id']??null,'along_m'=>$near['along']??0.];
            }
        }
        return $blocked;
    }

    public static function exclusions(array $data,array $bounds): array
    {
        return array_map(static fn($b)=>['points'=>array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],self::clip($b['points'],$b['limit_m']??self::LENGTH_M)),'width_m'=>$b['width_m']],$data['branches']??[]);
    }
}
