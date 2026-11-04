<?php
declare(strict_types=1);
namespace SweepLine;

/** One welded top surface per side road, including its junction connector. */
final class BeamPavement
{
    public static function key(array $p): string {return $p['lanes'].'_'.$p['backward'].'_'.(int)$p['oneway'].(!empty($p['unmarked'])?'_junction':'').(isset($p['shoulder_m'])?'_s'.(int)round(255*$p['shoulder_m']/$p['width_m']):'');}
    public static function section(array $points,array $profiles,int $i): array
    {
        $a=$points[max(0,$i-1)];$b=$points[min(count($points)-1,$i+1)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$length=hypot($dx,$dy)?:1;$half=$profiles[$i]['width_m']/2;$p=$points[$i];
        return [[$p[0]-$dy/$length*$half,$p[1]+$dx/$length*$half,$p[2]],[$p[0]+$dy/$length*$half,$p[1]-$dx/$length*$half,$p[2]]];
    }
    /** Find the existing main-road boundary along a ray from the branch face. */
    public static function boundary(array $end,array $direction,array $road,array $profiles,array $join): ?array
    {
        return BeamJunctionBoundary::hit($end,$direction,$road,$profiles,$join);
    }
    public static function build(array $branch,array $main,array $mainProfiles,string $prefix,?array $surfaceSections=null): array
    {
        $points=$branch['points'];$profiles=$branch['profiles'];$origin=$points[0];$vertices=[];$lookup=[];$triangles=[];$along=0.0;
        $vertex=static function($p)use(&$vertices,&$lookup){$key=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p));if(isset($lookup[$key]))return $lookup[$key];$id=count($vertices);$vertices[]=$p;$lookup[$key]=$id;return $id;};
        $quad=static function($a,$b,$c,$d,$material,$uv)use(&$triangles,$vertex){
            $ids=array_map($vertex,[$a,$b,$c,$d]);
            foreach([[0,1,2],[0,2,3]] as $t){$x=$ids[$t[0]];$y=$ids[$t[1]];$z=$ids[$t[2]];if($x===$y||$y===$z||$z===$x||abs(BeamPolygon::cross([$a,$b,$c,$d][$t[0]],[$a,$b,$c,$d][$t[1]],[$a,$b,$c,$d][$t[2]]))<0.00000001)continue;$triangles[]=['v'=>[$x,$y,$z],'uv'=>[$uv[$t[0]],$uv[$t[1]],$uv[$t[2]]],'material'=>$material];}
        };
        $sections=$surfaceSections??BeamJunctionBoundary::sections($points,$profiles);
        for($i=1;$i<count($points);$i++){
            $next=$along+hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);$material=$prefix.'_road_'.self::key($profiles[$i]);
            $quad($sections[$i-1][0],$sections[$i-1][1],$sections[$i][1],$sections[$i][0],$material,[[0,$along/12],[1,$along/12],[1,$next/12],[0,$next/12]]);$along=$next;
        }

        $rings=self::connectorRings($branch,$main,$mainProfiles);$connector=(bool)$rings;
        foreach($rings as $i=>$r)if($i){
            $a=$rings[$i-1][0];$b=$rings[$i-1][1];$c=$r[1];$d=$r[0];
            $v0=($i-1-(count($rings)-1))/12;$v1=($i-(count($rings)-1))/12;
            $quad($a,$b,$c,$d,$prefix.'_road_'.self::key($profiles[0]),[[0,$v0],[1,$v0],[1,$v1],[0,$v1]]);
        }
        if(isset($branch['join']))[$vertices,$triangles]=BeamJunctionBoundary::trim($vertices,$triangles,$main,$mainProfiles);
        return ['xml'=>self::collada($vertices,$triangles,$origin),'position'=>$origin,'vertices'=>count($vertices),'triangles'=>count($triangles),'connector'=>$connector,
            'connector_end_seam_m'=>($connector?0.0:null),'method'=>'Exact ribbon boundary; overlapping side pavement removed; contact heights shared with primary surface.'];
    }

    public static function connectorRings(array $branch,array $main,array $mainProfiles): array
    {
        if(!isset($branch['join']))return [];
        $points=$branch['points'];$profiles=$branch['profiles'];$ends=self::section($points,$profiles,0);
        $dx=$points[1][0]-$points[0][0];$dy=$points[1][1]-$points[0][1];$length=hypot($dx,$dy)?:1;$direction=[$dx/$length,$dy/$length];
        $left=self::boundary($ends[0],$direction,$main,$mainProfiles,$branch['join']);$right=self::boundary($ends[1],$direction,$main,$mainProfiles,$branch['join']);
        if($left===null||$right===null)return [];
        $steps=max(1,(int)ceil(max(hypot($ends[0][0]-$left[0],$ends[0][1]-$left[1]),hypot($ends[1][0]-$right[0],$ends[1][1]-$right[1]))));
        $rings=[];$endGrade=($points[1][2]-$points[0][2])/$length;$join=$branch['join'];$startGrade=$join['grade']*($join['dx']*$direction[0]+$join['dy']*$direction[1]);
        for($i=0;$i<=$steps;$i++){
            $t=$i/$steps;$h00=2*$t**3-3*$t**2+1;$h10=$t**3-2*$t**2+$t;$h01=-2*$t**3+3*$t**2;$h11=$t**3-$t**2;$ring=[];
            foreach([$left,$right] as $side=>$start){$end=$ends[$side];$d=hypot($end[0]-$start[0],$end[1]-$start[1]);$ring[]=[$start[0]+($end[0]-$start[0])*$t,$start[1]+($end[1]-$start[1])*$t,$h00*$start[2]+$h10*$d*$startGrade+$h01*$end[2]+$h11*$d*$endGrade];}
            $rings[]=$ring;
        }
        return $rings;
    }
    public static function contact(array $branch,array $main,array $mainProfiles): ?array
    {
        $rings=self::connectorRings($branch,$main,$mainProfiles);if(!$rings)return null;
        $surface=[];$profiles=[];$points=[];
        foreach($rings as [$left,$right]){
            $p=[($left[0]+$right[0])/2,($left[1]+$right[1])/2,($left[2]+$right[2])/2];$width=hypot($right[0]-$left[0],$right[1]-$left[1]);
            $surface[]=$p;$points[]=array_slice($p,0,2);$profiles[]=['width_m'=>$width,'cross_grade'=>($right[2]-$left[2])/max(0.01,$width)];
        }
        return ['points'=>$points,'surface'=>$surface,'profiles'=>$profiles,'width_m'=>max(array_column($profiles,'width_m'))];
    }

    private static function collada(array $vertices,array $triangles,array $origin): string
    {
        $normals=array_fill(0,count($vertices),[0.0,0.0,0.0]);$groups=[];$uv=[];
        foreach($triangles as $triangle){[$ia,$ib,$ic]=$triangle['v'];$a=$vertices[$ia];$b=$vertices[$ib];$c=$vertices[$ic];
            $u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
            $n=[$u[1]*$v[2]-$u[2]*$v[1],$u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]];
            if($n[2]<0){[$triangle['v'][1],$triangle['v'][2]]=[$triangle['v'][2],$triangle['v'][1]];[$triangle['uv'][1],$triangle['uv'][2]]=[$triangle['uv'][2],$triangle['uv'][1]];$n=array_map(static fn($x)=>-$x,$n);}
            foreach($triangle['v'] as $i=>$id){foreach($n as $j=>$value)$normals[$id][$j]+=$value;$ui=count($uv);$uv[]=$triangle['uv'][$i];$groups[$triangle['material']][]=[$id,$id,$ui];}
        }
        $flat=[];foreach($vertices as $p)foreach($p as $i=>$v)$flat[]=$v-$origin[$i];
        $normal=[];foreach($normals as $n){$length=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$n)));if($length<0.000000001){$n=[0,0,1];$length=1;}foreach($n as $v)$normal[]=$v/$length;}
        $tex=[];foreach($uv as $p)array_push($tex,...$p);
        $source=static function($id,$values,$stride){$params=$stride===2?'<param name="S" type="float"/><param name="T" type="float"/>':'<param name="X" type="float"/><param name="Y" type="float"/><param name="Z" type="float"/>';return '<source id="'.$id.'"><float_array id="'.$id.'-array" count="'.count($values).'">'.implode(' ',$values).'</float_array><technique_common><accessor source="#'.$id.'-array" count="'.(count($values)/$stride).'" stride="'.$stride.'">'.$params.'</accessor></technique_common></source>';};
        $materials='';$bindings='';$faces='';
        foreach($groups as $material=>$corners){$materials.='<material id="'.$material.'" name="'.$material.'"><instance_effect url="#surface-fx"/></material>';$bindings.='<instance_material symbol="'.$material.'" target="#'.$material.'"><bind_vertex_input semantic="UVSET0" input_semantic="TEXCOORD" input_set="0"/></instance_material>';$indices=[];foreach($corners as $p)array_push($indices,...$p);$faces.='<triangles count="'.(count($corners)/3).'" material="'.$material.'"><input semantic="VERTEX" source="#vertices" offset="0"/><input semantic="NORMAL" source="#normals" offset="1"/><input semantic="TEXCOORD" source="#uv" offset="2" set="0"/><p>'.implode(' ',$indices).'</p></triangles>';}
        return '<?xml version="1.0" encoding="UTF-8"?><COLLADA xmlns="http://www.collada.org/2005/11/COLLADASchema" version="1.4.1"><asset><contributor><author>SweepLine</author></contributor><created>2026-01-01T00:00:00Z</created><modified>2026-01-01T00:00:00Z</modified><unit name="meter" meter="1"/><up_axis>Z_UP</up_axis></asset><library_effects><effect id="surface-fx"><profile_COMMON><technique sid="common"><lambert><diffuse><color>0.25 0.25 0.25 1</color></diffuse></lambert></technique></profile_COMMON></effect></library_effects><library_materials>'.$materials.'</library_materials><library_geometries><geometry id="pavement"><mesh>'.$source('positions',$flat,3).$source('normals',$normal,3).$source('uv',$tex,2).'<vertices id="vertices"><input semantic="POSITION" source="#positions"/></vertices>'.$faces.'</mesh></geometry></library_geometries><library_visual_scenes><visual_scene id="Scene"><node id="surface"><instance_geometry url="#pavement"><bind_material><technique_common>'.$bindings.'</technique_common></bind_material></instance_geometry></node></visual_scene></library_visual_scenes><scene><instance_visual_scene url="#Scene"/></scene></COLLADA>';
    }
}
