<?php
declare(strict_types=1);
namespace SweepLine;

/** Open top surface: no ribbon side walls, underside, or material-change end caps. */
final class BeamShoulderMesh
{
    public static function orientNodes(array $nodes): array
    {
        foreach($nodes as $i=>$p){
            $a=$nodes[max(0,$i-1)];$b=$nodes[min(count($nodes)-1,$i+1)];
            $t=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$n=array_slice($p,5,3);
            $v=[$t[1]*$n[2]-$t[2]*$n[1],$t[2]*$n[0]-$t[0]*$n[2],$t[0]*$n[1]-$t[1]*$n[0]];
            $length=sqrt(array_sum(array_map(static fn($x)=>$x*$x,$v)));
            if($length<0.000001)throw new \RuntimeException('Degenerate shoulder surface.');
            $nodes[$i]=array_merge(array_slice($p,0,8),array_map(static fn($x)=>$x/$length,$v));
        }
        return $nodes;
    }

    public static function build(string $material,array $nodes): string
    {
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$material))throw new \InvalidArgumentException('Invalid shoulder material.');
        $rails=[];$chain=0.0;
        foreach($nodes as $i=>$p){
            $a=$nodes[max(0,$i-1)];$b=$nodes[min(count($nodes)-1,$i+1)];
            $t=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$n=array_slice($p,5,3);
            $right=[$t[1]*$n[2]-$t[2]*$n[1],$t[2]*$n[0]-$t[0]*$n[2],$t[0]*$n[1]-$t[1]*$n[0]];
            if(count($p)>=11)$right=array_slice($p,8,3);
            $length=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$right)));
            if($length<0.000001)throw new \RuntimeException('Degenerate shoulder surface. Select a different stretch.');
            if($i)$chain+=hypot($p[0]-$nodes[$i-1][0],$p[1]-$nodes[$i-1][1]);
            $pair=[];foreach([-1,1] as $side){
                $pair[]=[[$p[0]+$side*$right[0]/$length*$p[3]/2,$p[1]+$side*$right[1]/$length*$p[3]/2,$p[2]+$side*$right[2]/$length*$p[3]/2],[$side<0?0:1,$chain/4]];
            }$rails[]=$pair;
        }
        $positions=[];$normals=[];$uv=[];$indices=[];$vertex=0;$triangles=0;
        for($i=1;$i<count($rails);$i++){
            foreach([[$rails[$i-1][0],$rails[$i-1][1],$rails[$i][1]],[$rails[$i-1][0],$rails[$i][1],$rails[$i][0]]] as $face){
                [$a,$b,$c]=array_column($face,0);
                $u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
                $normal=[$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];
                $length=sqrt(array_sum(array_map(static fn($x)=>$x*$x,$normal)));
                if($length<0.000001)continue;
                if($normal[2]<0){$face=array_reverse($face);$normal=array_map(static fn($x)=>-$x,$normal);}
                foreach($face as [$p,$tex]){
                    array_push($positions,...$p);foreach($normal as $value)$normals[]=$value/$length;array_push($uv,...$tex);
                    array_push($indices,$vertex,$vertex,$vertex);$vertex++;
                }$triangles++;
            }
        }
        if(!$triangles)throw new \RuntimeException('Shoulder contains no usable surface triangles.');
        $source=static function(string $name,array $values,int $stride,array $axes)use($vertex): string{
            $params='';foreach($axes as $axis)$params.='<param name="'.$axis.'" type="float"/>';
            return '<source id="'.$name.'"><float_array id="'.$name.'-array" count="'.count($values).'">'.implode(' ',$values).'</float_array><technique_common><accessor source="#'.$name.'-array" count="'.$vertex.'" stride="'.$stride.'">'.$params.'</accessor></technique_common></source>';
        };
        return '<?xml version="1.0" encoding="UTF-8"?>'.
            '<COLLADA xmlns="http://www.collada.org/2005/11/COLLADASchema" version="1.4.1"><asset><unit name="meter" meter="1"/><up_axis>Z_UP</up_axis></asset>'.
            '<library_effects><effect id="surface-fx"><profile_COMMON><technique sid="common"><lambert><diffuse><color>1 1 1 1</color></diffuse></lambert></technique></profile_COMMON></effect></library_effects>'.
            '<library_materials><material id="'.$material.'" name="'.$material.'"><instance_effect url="#surface-fx"/></material></library_materials>'.
            '<library_geometries><geometry id="mesh"><mesh>'.
            $source('positions',$positions,3,['X','Y','Z']).$source('normals',$normals,3,['X','Y','Z']).$source('uv',$uv,2,['S','T']).
            '<vertices id="vertices"><input semantic="POSITION" source="#positions"/></vertices><triangles count="'.$triangles.'" material="surface"><input semantic="VERTEX" source="#vertices" offset="0"/><input semantic="NORMAL" source="#normals" offset="1"/><input semantic="TEXCOORD" source="#uv" offset="2" set="0"/><p>'.implode(' ',$indices).'</p></triangles></mesh></geometry></library_geometries>'.
            '<library_visual_scenes><visual_scene id="scene"><node id="object"><instance_geometry url="#mesh"><bind_material><technique_common><instance_material symbol="surface" target="#'.$material.'"/></technique_common></bind_material></instance_geometry></node></visual_scene></library_visual_scenes><scene><instance_visual_scene url="#scene"/></scene></COLLADA>';
    }
}
