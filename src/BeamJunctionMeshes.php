<?php
declare(strict_types=1);
namespace SweepLine;

/** Downstream ribbons meet the already clipped collision top of their actual owner. */
final class BeamJunctionMeshes
{
    public static function prepare(array $prepared,?callable $progress=null): array
    {
        $primary=$prepared['main_surfaces'][0]??null;if(!$primary)return $prepared;
        $obstacles=[];foreach($prepared['main_surfaces'] as $s)if($s['name']!==$primary['name'])$obstacles[]=['road'=>$s['points'],'profiles'=>$s['profiles']];
        $tops=[];$triangles=0;
        foreach($prepared['roads'] as $i=>&$r){
            if(empty($r['network']))continue;
            if($progress)$progress(null);
            if(isset($tops[$r['parent_name']??'']))$r['junction_parent_top']=$tops[$r['parent_name']];
            foreach($r['junction_obstacles'] as &$o)if(isset($tops[$o['name']??'']))$o['collision_top']=$tops[$o['name']];unset($o);
            $end=$r['end_owner']['road_index']??null;
            if($end!==null&&isset($prepared['roads'][$end])&&isset($tops[$prepared['roads'][$end]['name']]))
                $r['end_owner']['surface']['collision_top']=$tops[$prepared['roads'][$end]['name']];
            $mesh=BeamPavement::build($r,$primary['points'],$primary['profiles'],'contact',null,$obstacles,true);
            $r['collision_top']=$tops[$r['name']]=BeamJunctionBoundary::encode($mesh);$triangles+=count($mesh['triangles']);
        }unset($r);
        $prepared['junction_collision_surfaces']=['roads'=>count($tops),'triangles'=>$triangles,'method'=>'Shared final clipped collision tops, compactly encoded; one owner per overlap'];
        return $prepared;
    }
}
