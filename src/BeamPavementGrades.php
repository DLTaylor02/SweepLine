<?php
declare(strict_types=1);
namespace SweepLine;

/** Continuous context-road elevation fits; selected highway geometry remains authoritative. */
final class BeamPavementGrades
{
    public const BLEND_M=30.48;
    public const MAX_ADJUSTMENT_M=2.;
    /** Grade the ribbon before clipping; avoid unrelated per-vertex snaps after triangulation. */
    public static function apply(array $prepared,?callable $progress=null): array
    {
        $main=BeamRoadClearance::prepare($prepared['main_surfaces']??[]);if(!$main)return $prepared;
        $nodes=[];foreach($prepared['roads'] as $r)if(!empty($r['network']))foreach(['start_node','end_node'] as $key){$node=(string)($r[$key]??'');$nodes[$node]=($nodes[$node]??0)+1;}
        $previous=[];$report=['roads'=>0,'contact_samples'=>0,'blended_samples'=>0,'maximum_adjustment_m'=>0.,'blend_m'=>self::BLEND_M,'adjustment_bound_m'=>self::MAX_ADJUSTMENT_M];
        foreach($prepared['roads'] as &$r){
            if(empty($r['network']))continue;if(!empty($r['tunnels'])){$previous[$r['name']]=BeamRoadClearance::prepare([$r])[0];continue;}if($progress)$progress(null);$owners=$main;
            $names=array_unique(array_filter(array_merge([$r['parent_name']??null],array_column($r['junction_obstacles']??[],'name'))));
            foreach($names as $name)if(isset($previous[$name]))$owners[]=$previous[$name];
            if(isset($r['end_owner']['surface']))$owners=array_merge($owners,BeamRoadClearance::prepare([['name'=>'finish_owner']+$r['end_owner']['surface']]));
            [$r,$metrics]=self::fit($r,$owners,[true,($nodes[(string)($r['end_node']??'')]??0)>1||!empty($r['end_owner'])]);$r['ribbon_graded']=true;$report['roads']++;
            $report['contact_samples']+=$metrics['contact_samples'];$report['blended_samples']+=$metrics['blended_samples'];$report['maximum_adjustment_m']=max($report['maximum_adjustment_m'],$metrics['maximum_adjustment_m']);
            $previous[$r['name']]=BeamRoadClearance::prepare([$r])[0];
        }unset($r);
        $prepared['pavement_grading']=$report+['method'=>'Fit continuous section heights to same-level owner pavement before clipping, with 100 ft approach blends; exact boundary-only seam welding'];
        return BeamJunctionGrades::refresh($prepared);
    }
    public static function fit(array $r,array $owners,array $anchors=[true,false]): array
    {
        $empty=['contact_samples'=>0,'blended_samples'=>0,'maximum_adjustment_m'=>0.];
        if(empty($r['network'])||!empty($r['tunnels']))return [$r,$empty];
        $offset=count($r['connector']??[]);$points=array_merge($r['connector']??[],$r['points']);$profiles=[];$cp=$r['profiles'][0];unset($cp['section_override']);
        foreach($r['connector']??[] as $i=>$p)$profiles[]=$cp+(isset($r['connector_sections'][$i])?['section_override'=>$r['connector_sections'][$i]]:[]);
        array_push($profiles,...$r['profiles']);$sections=BeamJunctionBoundary::sections($points,$profiles);$chain=[0.];
        for($i=1;$i<count($points);$i++)$chain[]=$chain[$i-1]+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);
        $prefix=$chain[$offset]??0.;$targets=[];
        foreach($points as $i=>$p){
            $station=$chain[$i]-$prefix;$bridge=false;
            foreach($r['bridges']??[] as $s)if($station>=$s['start_m']-.01&&$station<=$s['end_m']+.01)$bridge=true;
            $hit=null;
            foreach([.5,.25,.75,0.,1.] as $u){$v=[];foreach([0,1,2] as $axis)$v[]=$sections[$i][0][$axis]*(1-$u)+$sections[$i][1][$axis]*$u;
                $h=BeamRoadClearance::hit($v,$owners,$v[2]-self::MAX_ADJUSTMENT_M,$v[2]+self::MAX_ADJUSTMENT_M);if(!$h)continue;
                if($bridge){$supported=false;foreach(array_merge($h['line']['spans']??[],$h['line']['bridges']??[]) as $s)if(!isset($s['clearance_m'])&&$h['hit']['along']>=$s['start_m']-max(25.,$s['approach_m']??25.)&&$h['hit']['along']<=$s['end_m']+max(25.,$s['approach_m']??25.)){$supported=true;break;}if(!$supported)continue;}
                $hit=$h;break;
            }
            if(!$hit)continue;
            $deltas=[];
            foreach([$p,$sections[$i][0],$sections[$i][1]] as $v){$w=BeamTerrainContact::weights($v,$hit['triangle']);$z=0.;foreach($w as $j=>$weight)$z+=$weight*$hit['triangle'][$j][2];$deltas[]=$z-$v[2];}
            if(max(array_map('abs',$deltas))<=self::MAX_ADJUSTMENT_M)$targets[$i]=$deltas;
        }
        if(!$targets)return [$r,$empty];$indices=array_keys($targets);$report=$empty;$next=0;$blend=self::BLEND_M;$desired=[];$strength=[];
        foreach($points as $i=>$p){
            while($next<count($indices)&&$indices[$next]<$i)$next++;
            if(isset($targets[$i])){$delta=$targets[$i];$weight=1.;$report['contact_samples']++;}
            else{
                $sum=[0.,0.,0.];$weights=0.;$strongest=0.;
                foreach([$indices[$next-1]??null,$indices[$next]??null] as $j){if($j===null)continue;$distance=abs($chain[$j]-$chain[$i]);if($distance>=$blend)continue;$t=1-$distance/$blend;$w=$t*$t*(3-2*$t);$weights+=$w;$strongest=max($strongest,$w);foreach([0,1,2] as $k)$sum[$k]+=$targets[$j][$k]*$w;}
                if(!$weights)continue;$delta=array_map(static fn($v)=>$v/$weights,$sum);$weight=$strongest;$report['blended_samples']++;
            }
            $desired[$i]=$delta;$strength[$i]=$weight;
        }
        $total=end($chain);$left=0;$core=max(array_column($profiles,'width_m'))/2+1.;
        foreach($points as $i=>&$p){
            if(!isset($desired[$i]))continue;$sum=[0.,0.,0.];$weights=0.;
            while($left>0&&$chain[$i]-$chain[$left-1]<36.)$left--;
            while($left<$i&&$chain[$i]-$chain[$left]>36.)$left++;
            for($j=$left;$j<count($points)&&$chain[$j]-$chain[$i]<36.;$j++){
                if(!isset($desired[$j]))continue;$mass=max(.001,($chain[min(count($points)-1,$j+1)]-$chain[max(0,$j-1)])/2);$w=exp(-.5*(($chain[$j]-$chain[$i])/12.)**2)*$mass;$weights+=$w;
                foreach([0,1,2] as $k)$sum[$k]+=$desired[$j][$k]*$w;
            }
            if(!$weights)continue;$weight=$strength[$i];
            foreach([0,1] as $side)if($anchors[$side]){$distance=$side?$total-$chain[$i]:$chain[$i];$u=max(0.,min(1.,($distance-$core)/self::BLEND_M));$weight=min($weight,$u*$u*(3-2*$u));}
            $delta=$targets[$i]??array_map(static fn($v)=>$v/$weights,$sum);$p[2]+=$delta[0]*$weight;$ring=$sections[$i];foreach([0,1] as $side)$ring[$side][2]+=$delta[$side+1]*$weight;
            if($weight>1e-9){$profiles[$i]['section_override']=$ring;$profiles[$i]['cross_grade']=($ring[1][2]-$ring[0][2])/max(.01,$profiles[$i]['width_m']);}$report['maximum_adjustment_m']=max($report['maximum_adjustment_m'],max(array_map('abs',$delta))*$weight);
        }unset($p);
        $r['points']=array_slice($points,$offset);$r['profiles']=array_slice($profiles,$offset);
        if($offset){$r['connector']=array_slice($points,0,$offset);$r['connector_sections']=array_map(static fn($p)=>$p['section_override']??null,array_slice($profiles,0,$offset));foreach($r['connector_sections'] as $i=>&$s)if($s===null)$s=$sections[$i];unset($s);}
        return [$r,$report];
    }
}