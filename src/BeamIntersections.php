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
        ), junctions AS (
            SELECT DISTINCT c.job_id,c.node_id
            FROM main m JOIN road_connectivity c ON c.segment_id=m.id
            JOIN osm_nodes n ON n.job_id=c.job_id AND n.osm_id=c.node_id CROSS JOIN route p
            WHERE ST_DWithin(ST_Transform(ST_SetSRID(ST_MakePoint(n.lon,n.lat),4326),ST_SRID(p.g)),p.g,0.5)
        )
        SELECT DISTINCT s.osm_id,s.tags,s.highway,s.direction,c.point_index,ST_AsGeoJSON(s.projected,6) geometry
        FROM junctions j JOIN road_connectivity c ON c.job_id=j.job_id AND c.node_id=j.node_id
        JOIN nearby s ON s.id=c.segment_id
        WHERE NOT EXISTS(SELECT 1 FROM main m WHERE m.id=s.id)
        AND ST_NPoints(s.projected)<=2000
        AND s.highway IN ('motorway','motorway_link','trunk','trunk_link','primary','primary_link','secondary','secondary_link','tertiary','tertiary_link','unclassified','residential','living_street')
        AND COALESCE(s.tags->>'access','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motor_vehicle','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'motorcar','') NOT IN ('no','private')
        AND COALESCE(s.tags->>'bridge','no') IN ('no','false','0')
        AND COALESCE(s.tags->>'tunnel','no') IN ('no','false','0')
        ORDER BY s.osm_id,c.point_index LIMIT 1001");
        $q->execute([json_encode(['type'=>'LineString','coordinates'=>$plan['points']],JSON_THROW_ON_ERROR),$plan['epsg']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$out=[];$seen=[];$truncated=count($rows)>1000;
        foreach(array_slice($rows,0,1000) as $row){
            $progress(null);$points=json_decode($row['geometry'],true,512,JSON_THROW_ON_ERROR)['coordinates'];$at=(int)$row['point_index'];
            $tags=json_decode($row['tags'],true,512,JSON_THROW_ON_ERROR);
            foreach([array_reverse(array_slice($points,0,$at+1)),array_slice($points,$at)] as $armSide=>$arm){
                $arm=self::clip($arm);if(!$arm)continue;
                $key=hash('sha256',json_encode(array_map(static fn($p)=>[round($p[0],2),round($p[1],2)],$arm)));
                if(isset($seen[$key]))continue;$seen[$key]=true;
                if(count($out)>=256){$truncated=true;break 2;}
                $profile=BeamLanes::infer($tags,$row['highway'],(int)$row['direction'],$armSide===1);
                $out[]=['points'=>$arm,'width_m'=>$profile['width_m'],'profile'=>$profile,'osm_id'=>(int)$row['osm_id']];
            }
        }
        return ['branches'=>$out,'truncated'=>$truncated,'source'=>'Local active OSM shared-node connectivity','limit_ft'=>600];
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
        $out=['roads'=>[],'aprons'=>[],'junctions'=>[],'skipped'=>0];
        foreach($data['branches']??[] as $index=>$branch){
            $progress(null);$arm=self::clip($branch['points']);if(!$arm)continue;
            $local=array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],$arm);
            $join=self::nearest($local[0],$road,$profiles);if(!$join||$join['distance']>2){$out['skipped']++;continue;}
            $bridge=false;foreach($bridges as $span)if($join['along']>=$span['start_m']-1&&$join['along']<=$span['end_m']+1)$bridge=true;
            $dx=$local[1][0]-$local[0][0];$dy=$local[1][1]-$local[0][1];$length=hypot($dx,$dy);if($length<0.001)continue;
            $sine=abs($dx/$length*$join['dy']-$dy/$length*$join['dx']);$cosine=abs($dx/$length*$join['dx']+$dy/$length*$join['dy']);
            if($bridge||$sine<0.2){$out['skipped']++;continue;}
            $profile=$branch['profile']??BeamLanes::infer(['width'=>(string)$branch['width_m']],'residential',0);
            $profile['width_m']=$branch['width_m'];$profile=BeamRoadSurface::shoulders([$profile])[0];
            $trim=($join['half_width']+$profile['width_m']/2*$cosine)/$sine+max(8,$profile['width_m']/2);
            $surface=self::after($local,$trim);if(count($surface)<2){$out['skipped']++;continue;}
            $sampled=BeamPlan::sample(BeamCurve::smooth($surface,[$surface[0],end($surface)]),2,0.001);$raw=[];$chain=[0.0];
            foreach($sampled as $i=>$p){$raw[]=$raster->roadHeight($p[0],$p[1]);if($i)$chain[]=$chain[$i-1]+hypot($p[0]-$sampled[$i-1][0],$p[1]-$sampled[$i-1][1]);}
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
                $z=$anchor*(1-$fade)+$z*$fade;
                $nearMain=self::nearest($p,$road,$profiles);
                if($i){$hx=$p[0]-$sampled[$i-1][0];$hy=$p[1]-$sampled[$i-1][1];$hl=hypot($hx,$hy)?:1;$across=abs(($hx*$nearMain['dx']+$hy*$nearMain['dy'])/$hl);
                    if($nearMain['distance']<$nearMain['half_width']+$profile['width_m']/2*$across+0.02)break;}
                if($i&&abs($z-$nodes[$i-1][2])/max(0.01,$chain[$i]-$chain[$i-1])>0.35)break;
                $nodes[]=[$p[0],$p[1],$z];$branchProfiles[]=$profile+['unmarked'=>false];
            }
            if(count($nodes)<2){$out['skipped']++;continue;}
            $connector=array_map(static fn($p)=>[$p[0],$p[1],$join['z']+(($p[0]-$join['x'])*$join['dx']+($p[1]-$join['y'])*$join['dy'])*$join['grade']],BeamPlan::sample(self::clip($local,$trim)));
            $out['roads'][]=['name'=>'intersection_context_'.$index,'points'=>$nodes,'profiles'=>$branchProfiles,'join'=>$join,'connector'=>$connector];
            $apron=self::clip($local,$trim+1);
            $apronSurface=array_map(static fn($p)=>[$p[0],$p[1],$join['z']+(($p[0]-$join['x'])*$join['dx']+($p[1]-$join['y'])*$join['dy'])*$join['grade']],BeamPlan::sample($apron));
            $out['aprons'][]=['points'=>$apron,'width_m'=>$profile['width_m'],'surface'=>$apronSurface];
            $out['junctions'][]=$join+['radius'=>$profile['width_m']/2/$sine];
        }
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
        if(!$junctions)return ['points'=>$road,'profiles'=>$profiles];
        $cuts=[];foreach($junctions as $j){$cuts[]=max(0,$j['along']-$j['radius']);$cuts[]=$j['along']+$j['radius'];}sort($cuts);
        $points=[$road[0]];$out=[$profiles[0]];$along=0.0;$previous=0.0;
        for($i=1;$i<count($road);$i++){
            $a=$road[$i-1];$b=$road[$i];$length=hypot($b[0]-$a[0],$b[1]-$a[1]);if($length<0.001)continue;
            $at=[];foreach($cuts as $cut)if($cut>$along+0.00001&&$cut<$along+$length-0.00001)$at[]=$cut;$at[]=$along+$length;$at=array_unique($at);
            foreach($at as $cut){
                $t=($cut-$along)/$length;$points[]=[$a[0]+($b[0]-$a[0])*$t,$a[1]+($b[1]-$a[1])*$t,$a[2]+($b[2]-$a[2])*$t];
                $p=$profiles[$i];$p['width_m']=$profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t;$p['unmarked']=false;$mid=($previous+$cut)/2;
                foreach($junctions as $join)if(abs($mid-$join['along'])<$join['radius']){$p['unmarked']=true;break;}
                $out[]=$p;$previous=$cut;
            }
            $along+=$length;
        }
        $out[0]['unmarked']=$out[1]['unmarked']??false;
        return ['points'=>$points,'profiles'=>$out];
    }

    public static function shape(array $prepared,array $main,array $profiles,BeamRaster $raster,float $origin,string $bridgeMask,callable $progress): array
    {
        $mask=$bridgeMask;$n=$raster->size;$step=$raster->side/$n;
        // Protect existing main-road shoulders and every bridge interior.
        for($i=1;$i<count($main);$i++){
            $a=$main[$i-1];$b=$main[$i];$radius=max($profiles[$i-1]['width_m'],$profiles[$i]['width_m'])/2+0.05;
            for($y=max(0,(int)floor((min($a[1],$b[1])-$radius)/$step));$y<=min($n-1,(int)ceil((max($a[1],$b[1])+$radius)/$step));$y++)
                for($x=max(0,(int)floor((min($a[0],$b[0])-$radius)/$step));$x<=min($n-1,(int)ceil((max($a[0],$b[0])+$radius)/$step));$x++)
                    if(BeamLanes::distance([$x*$step,$y*$step],$a,$b)[0]<=$radius)$mask[$y*$n+$x]="\1";
        }
        $reports=[];
        $surfaces=$prepared['roads'];
        foreach($prepared['aprons'] as $apron)$surfaces[]=['points'=>$apron['surface'],'profiles'=>$apron['profiles']??array_fill(0,count($apron['surface']),['width_m'=>$apron['width_m']])];
        foreach($surfaces as $branch){
            $progress(null);
            $reports[]=['shoulders'=>BeamRoadSurface::blendTerrain($branch['points'],$branch['profiles'],$raster,$origin,$mask,$progress),
                'clearance'=>BeamRoadSurface::clearTerrain($branch['points'],$branch['profiles'],$raster,$origin,$progress,$mask,true)];
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
        $mask=$bridgeMask?:str_repeat("\0",$raster->size*$raster->size);$reports=[];$lowered=0;$maxCut=0.0;
        // Lower final contact terrain rather than lifting road surfaces away from their shoulders.
        $surfaces=$prepared['roads'];foreach($prepared['aprons']??[] as $a)$surfaces[]=['points'=>$a['surface'],'profiles'=>$a['profiles']??array_fill(0,count($a['surface']),['width_m'=>$a['width_m']])];
        for($pass=0;$pass<2;$pass++){
            foreach($surfaces as $branch){
                $progress(null);$report=BeamRoadSurface::clearTerrain($branch['points'],$branch['profiles'],$raster,$origin,$progress,$mask,true);
                $reports[]=$report;$lowered+=$report['lowered_vertices'];$maxCut=max($maxCut,$report['maximum_cut_m']);
            }
            if($main){$report=BeamRoadSurface::clearTerrain($main,$mainProfiles,$raster,$origin,$progress,$mask,true);$lowered+=$report['lowered_vertices'];$maxCut=max($maxCut,$report['maximum_cut_m']);}
        }
        $prepared['final_clearance']=['raised_samples'=>0,'maximum_raise_m'=>0.0,'lowered_vertices'=>$lowered,'maximum_cut_m'=>$maxCut,
            'method'=>'Final terrain contact correction; main and side road surfaces remain unchanged.'];
        return $prepared;
    }

    public static function exclusions(array $data,array $bounds): array
    {
        return array_map(static fn($b)=>['points'=>array_map(static fn($p)=>[$p[0]-$bounds[0],$p[1]-$bounds[1]],self::clip($b['points'])),'width_m'=>$b['width_m']],$data['branches']??[]);
    }
}
