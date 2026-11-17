<?php
declare(strict_types=1);
namespace SweepLine;

/** Discover a graph before any geometry is prepared; shared OSM node IDs define connections. */
final class BeamInterchangeGraph
{
    public const MAX_EDGES=2048;
    public const MAX_SAMPLES=20000;
    public static function build(array $seeds,callable $lookup,callable $progress,array $main=[],float $rampLimit=BeamRampNetwork::MAX_RAMP_M,?callable $junctions=null): array
    {
        $queue=[];$out=[];$seen=[];$partialSeen=[];$reasons=[];$roots=[];$attachments=[];$estimated=0;$extensions=[];
        $note=static function($reason,$root,$way=null)use(&$reasons){$reasons[]=['reason'=>$reason,'root'=>$root,'osm_id'=>$way];};
        foreach($seeds as $i=>$seed){
            if(!BeamRampNetwork::link($seed['highway']??'')){unset($seed['full_points'],$seed['arm_nodes']);$out[]=$seed;continue;}
            $points=$seed['full_points']??$seed['points'];$ids=$seed['arm_nodes']??[];
            if(count($points)!==count($ids)||count($ids)<2){$note('missing_node_identity',(string)$i,$seed['osm_id']);continue;}
            $root='root_'.hash('sha256',$seed['osm_id'].':'.$ids[0].':'.$ids[1]);$roots[$root]=true;
            $attachments[(string)$ids[0]]=$points[0];
            $at=array_search($ids[0],$seed['node_ids']??[]);
            $aligned=$at!==false?((string)($seed['node_ids'][$at+1]??'')===(string)$ids[1]):
                (((int)($seed['direction']??0))<0?!($seed['profile']['legal_forward']??false):($seed['profile']['legal_forward']??true));
            $queue[]=['row'=>$seed,'points'=>$points,'ids'=>$ids,'parent'=>null,'root'=>$root,'remaining'=>$rampLimit,'kind'=>'ramp','aligned'=>$aligned];
        }
        for($head=0;$head<count($queue);$head++){
            $progress(null);$work=$queue[$head];$queue[$head]=null;$points=[];$ids=[];$parts=[];$next=[];$left=$work['remaining'];$distance=0.;$complete=true;$row=$work['row'];$pathSeen=[];$initialAligned=$work['aligned'];$partialKeys=[];
            for($piece=0;$piece<128;$piece++){
                if(!BeamRampNetwork::allowed($row['tags']??[])){$note('access_or_construction_restriction',$work['root'],$row['osm_id']);$complete=false;break;}
                $arm=$work['points'];$nodeIds=$work['ids'];$cut=count($arm)-1;$neighbors=null;
                if($junctions){
                    $hits=$junctions($nodeIds,(int)$row['osm_id']);
                    for($j=1;$j<count($nodeIds)-1;$j++)if(!empty($hits[(string)$nodeIds[$j]])){$cut=$j;$neighbors=$hits[(string)$nodeIds[$j]];break;}
                }
                $tail=$cut<count($arm)-1?['row'=>$row,'points'=>array_slice($arm,$cut),'ids'=>array_slice($nodeIds,$cut),'aligned'=>$work['aligned']]:null;
                $arm=array_slice($arm,0,$cut+1);$nodeIds=array_slice($nodeIds,0,$cut+1);
                for($j=1;$j<count($nodeIds);$j++){
                    $atom=self::atom($nodeIds[$j-1],$nodeIds[$j]);
                    if(isset($seen[$atom])||isset($pathSeen[$atom])){
                        $arm=array_slice($arm,0,$j);$nodeIds=array_slice($nodeIds,0,$j);$complete=false;break;
                    }
                }
                if(count($arm)<2)break;
                $partLength=BeamRampNetwork::length($arm);
                // Context distance is a target, not permission to cut a mapped structure.
                // Keep the whole tagged piece and real approach road after it, across way IDs.
                if(BeamNetworkStructures::tagged($row['tags']??[])&&$left<$partLength+80.){
                    $left=$partLength+80.;$extensions[]=['root'=>$work['root'],'osm_id'=>$row['osm_id'],'approach_m'=>80.];
                }
                if($partLength>$left+.001){
                    $partialKey=hash('sha256',json_encode([$work['kind'],(string)$nodeIds[0],(string)end($nodeIds),round($left,3)]));
                    if(isset($partialSeen[$partialKey])){$complete=false;break;}
                    $partialKeys[]=$partialKey;
                    $arm=BeamIntersections::clip($arm,$left);
                    if(count($arm)<2)break;
                    $nodeIds=array_slice($nodeIds,0,count($arm));
                    $nodeIds[count($arm)-1]='limit:'.hash('sha256',json_encode(end($arm)));
                    $partLength=BeamRampNetwork::length($arm);$complete=false;$note('per_ramp_distance_limit',$work['root'],$row['osm_id']);
                }
                $parts[]=['points'=>$arm,'tags'=>$row['tags']??[],'highway'=>$row['highway'],'direction'=>(int)($row['direction']??0),'source_aligned'=>$work['aligned'],'osm_id'=>$row['osm_id']];
                if(!$points){$points=$arm;$ids=$nodeIds;}else {array_push($points,...array_slice($arm,1));array_push($ids,...array_slice($nodeIds,1));}
                for($j=1;$j<count($nodeIds);$j++)$pathSeen[self::atom($nodeIds[$j-1],$nodeIds[$j])]=true;
                $left-=$partLength;$distance+=$partLength;
                if(!$complete)break;
                $neighbors??=$lookup((int)end($nodeIds));$next=[];
                if(count($neighbors)>=65)throw new BeamInterchangeLimit('Interchange junction exceeds 64 connected road records. The network was not truncated.');
                if($tail)$next[]=$tail;
                foreach($neighbors as $candidate){
                    if((string)$candidate['osm_id']===(string)$row['osm_id']||isset($main[(string)$candidate['osm_id']]))continue;
                    $at=(int)$candidate['point_index'];
                    foreach([false,true] as $aligned){
                        $a=$aligned?array_slice($candidate['points'],$at):array_reverse(array_slice($candidate['points'],0,$at+1));
                        $n=$aligned?array_slice($candidate['node_ids'],$at):array_reverse(array_slice($candidate['node_ids'],0,$at+1));
                        if(count($a)<2||count($a)!==count($n)||(string)$n[0]!==(string)end($ids))continue;
                        if(hypot($a[0][0]-end($points)[0],$a[0][1]-end($points)[1])>.2){$note('inconsistent_node_geometry',$work['root'],$candidate['osm_id']);continue;}
                        if(self::atom($n[0],$n[1])===self::atom($ids[count($ids)-2],end($ids))||isset($seen[self::atom($n[0],$n[1])])||isset($pathSeen[self::atom($n[0],$n[1])]))continue;
                        $isLink=BeamRampNetwork::link($candidate['highway']);
                        if($work['kind']==='receiving_road'&&$isLink)continue;
                        $next[]=['row'=>$candidate,'points'=>$a,'ids'=>$n,'aligned'=>$aligned];
                    }
                }
                // Contract an unbranched ramp continuation; forks retain every mapped arm.
                if($work['kind']==='ramp'&&count($next)===1&&BeamRampNetwork::link($next[0]['row']['highway'])&&$left>.001){
                    $work['points']=$next[0]['points'];$work['ids']=$next[0]['ids'];$work['aligned']=$next[0]['aligned'];$row=$next[0]['row'];continue;
                }
                break;
            }
            if($piece===128)throw new BeamInterchangeLimit('Ramp path exceeds 128 linked pieces; the network was not silently truncated.');
            if(count($points)<2)continue;
            $id='edge_'.substr(hash('sha256',self::atom($ids[0],$ids[1]).':'.end($ids)),0,20);
            $profile=$work['row']['profile']??BeamLanes::infer($work['row']['tags']??[],$work['row']['highway'],(int)($work['row']['direction']??0),$initialAligned);
            if(!isset($work['row']['profile']))$profile['legal_forward']=(int)($work['row']['direction']??0)===0||(((int)$work['row']['direction']>0)===$initialAligned);
            foreach($partialKeys as $key)$partialSeen[$key]=true;
            foreach($ids as $i=>$node)if($i)$seen[self::atom($ids[$i-1],$node)]=$id;
            $edge=['points'=>$points,'width_m'=>$profile['width_m'],'profile'=>$profile,'tags'=>$work['row']['tags']??[],
                'highway'=>$work['row']['highway'],'osm_id'=>(int)$work['row']['osm_id'],'kind'=>$work['kind'],'network'=>true,'context_id'=>$id,
                'start_node'=>(string)$ids[0],'end_node'=>(string)end($ids),'node_ids'=>$ids,'root_id'=>$work['root'],'limit_m'=>$distance+.01,
                'source_way_ids'=>array_values(array_unique(array_column($parts,'osm_id'))),'source_parts'=>$parts,'receiving_connection'=>false];
            if($work['parent']!==null)$edge['parent_context_id']=$work['parent'];
            $out[]=$edge;$edgeIndex=count($out)-1;$estimated+=(int)ceil($distance/2)+count($points);
            if(count($out)>self::MAX_EDGES||$estimated>self::MAX_SAMPLES)throw new BeamInterchangeLimit('The complete interchange network exceeds the export geometry budget. Select a shorter highway stretch; no ramps were silently discarded.');
            if(!$complete)continue;
            if(!$next&&!isset($attachments[(string)end($ids)]))$note('mapped_dead_end_or_missing_local_data',$work['root'],$row['osm_id']);
            $nextSeen=[];
            foreach($next as $arm){
                $key=self::atom($arm['ids'][0],$arm['ids'][1]);if(isset($nextSeen[$key])||isset($seen[$key]))continue;$nextSeen[$key]=true;
                $kind=BeamRampNetwork::link($arm['row']['highway'])?'ramp':'receiving_road';
                $remaining=$kind===$work['kind']?$left:BeamIntersections::LENGTH_M;
                if($remaining<.01)continue;
                if($kind==='receiving_road')$out[$edgeIndex]['receiving_connection']=true;
                $queue[]=$arm+['parent'=>$id,'root'=>$work['root'],'remaining'=>$remaining,'kind'=>$kind];
            }
        }
        $counts=[];foreach($reasons as $r)$counts[$r['reason']]=($counts[$r['reason']]??0)+1;
        return ['branches'=>$out,'truncated'=>false,'graph'=>['attachments'=>$attachments,'edge_count'=>count($out),'estimated_samples'=>$estimated,'structure_extensions'=>$extensions,'rejections'=>$reasons],
            'ramp_network'=>['requested_ramps'=>count($roots),'ramps'=>count(array_filter($out,static fn($e)=>($e['kind']??'')==='ramp')),
                'receiving_arms'=>count(array_filter($out,static fn($e)=>($e['kind']??'')==='receiving_road')),'reasons'=>$counts,
                'ramp_limit_m'=>$rampLimit,'budget_policy'=>'Per highway attachment/path; shared map-wide geometry checked before terrain generation.','total_ramp_m'=>array_sum(array_map(static fn($e)=>($e['kind']??'')==='ramp'?BeamRampNetwork::length($e['points']):0,$out))]];
    }
    private static function atom(mixed $a,mixed $b): string {$ids=[(string)$a,(string)$b];sort($ids,SORT_STRING);return implode(':',$ids);}
}
