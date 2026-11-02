<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamAssets
{
    public static function shape(string $material,string $kind): string
    {
        $vertices=[];$triangles=[];
        if($kind==='tree') {
            $vertices=[[0,0,10],[-3,-3,3],[3,-3,3],[3,3,3],[-3,3,3],[0,0,0]];
            $triangles=[[0,1,2],[0,2,3],[0,3,4],[0,4,1],[5,2,1],[5,3,2],[5,4,3],[5,1,4]];
        } elseif($kind==='rock') {
            $vertices=[[0,0,2],[0,0,0],[-2,0,0.7],[2,0,0.7],[0,-1.5,0.7],[0,1.5,0.7]];
            $triangles=[[0,2,4],[0,4,3],[0,3,5],[0,5,2],[1,4,2],[1,3,4],[1,5,3],[1,2,5]];
        } else {
            $vertices=[[-4,-5,0],[4,-5,0],[4,5,0],[-4,5,0],[-4,-5,6],[4,-5,6],[4,5,6],[-4,5,6]];
            $triangles=[[0,2,1],[0,3,2],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]];
        }
        $positions=[];$normals=[];$indices=[];$id=0;
        foreach($triangles as $triangle) {
            [$a,$b,$c]=array_map(static fn($i)=>$vertices[$i],$triangle);
            $u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
            $normal=[$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];$len=sqrt(array_sum(array_map(static fn($x)=>$x*$x,$normal)))?:1;
            foreach([$a,$b,$c] as $p) {$positions=array_merge($positions,$p);$normals=array_merge($normals,array_map(static fn($x)=>$x/$len,$normal));$indices[]=$id;$indices[]=$id;$id++;}
        }
        $count=$id*3;$tri=count($triangles);
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<COLLADA xmlns="http://www.collada.org/2005/11/COLLADASchema" version="1.4.1"><asset><contributor><author>SweepLine</author></contributor><created>2026-01-01T00:00:00Z</created><modified>2026-01-01T00:00:00Z</modified><unit name="meter" meter="1"/><up_axis>Z_UP</up_axis></asset>'.
            '<library_effects><effect id="surface-fx"><profile_COMMON><technique sid="common"><lambert><diffuse><color>0.5 0.5 0.5 1</color></diffuse></lambert></technique></profile_COMMON></effect></library_effects>'.
            '<library_materials><material id="'.$material.'" name="'.$material.'"><instance_effect url="#surface-fx"/></material></library_materials>'.
            '<library_geometries><geometry id="mesh"><mesh><source id="positions"><float_array id="positions-array" count="'.$count.'">'.implode(' ',$positions).'</float_array><technique_common><accessor source="#positions-array" count="'.$id.'" stride="3"><param name="X" type="float"/><param name="Y" type="float"/><param name="Z" type="float"/></accessor></technique_common></source>'.
            '<source id="normals"><float_array id="normals-array" count="'.$count.'">'.implode(' ',$normals).'</float_array><technique_common><accessor source="#normals-array" count="'.$id.'" stride="3"><param name="X" type="float"/><param name="Y" type="float"/><param name="Z" type="float"/></accessor></technique_common></source>'.
            '<vertices id="vertices"><input semantic="POSITION" source="#positions"/></vertices><triangles count="'.$tri.'" material="surface"><input semantic="VERTEX" source="#vertices" offset="0"/><input semantic="NORMAL" source="#normals" offset="1"/><p>'.implode(' ',$indices).'</p></triangles></mesh></geometry></library_geometries>'.
            '<library_visual_scenes><visual_scene id="scene"><node id="object2" name="object2"><instance_geometry url="#mesh"><bind_material><technique_common><instance_material symbol="surface" target="#'.$material.'"/></technique_common></bind_material></instance_geometry></node></visual_scene></library_visual_scenes><scene><instance_visual_scene url="#scene"/></scene></COLLADA>';
    }
}

