<?php
declare(strict_types=1);
namespace SweepLine;

/** Final cut against exact driving surfaces; retain terrain above the tunnel clearance. */
final class BeamTunnelClearance
{
    public static function clear(array $surface,array $pavements,callable $progress,float $clearance=4.65): array
    {
        $volumes=[];$grid=[];
        foreach($pavements as $tri){
            if(abs(BeamPolygon::cross(...$tri))<1e-9)continue;if(BeamPolygon::cross(...$tri)<0)$tri=array_reverse($tri);
            [$a,$b,$c]=$tri;$den=BeamPolygon::cross($a,$b,$c);
            $zx=(($b[2]-$a[2])*($c[1]-$a[1])-($c[2]-$a[2])*($b[1]-$a[1]))/$den;
            $zy=(($b[0]-$a[0])*($c[2]-$a[2])-($c[0]-$a[0])*($b[2]-$a[2]))/$den;$z0=$a[2]-$zx*$a[0]-$zy*$a[1];
            $planes=[];foreach($tri as $i=>$p){$end=$tri[($i+1)%3];$dx=$end[0]-$p[0];$dy=$end[1]-$p[1];$planes[]=[-$dy,$dx,0,$dy*$p[0]-$dx*$p[1]];}
            $planes[]=[$zx,$zy,-1,$z0+$clearance];$planes[]=[-$zx,-$zy,1,-$z0+0.05];
            $id=count($volumes);$volumes[]=['planes'=>$planes,'floor'=>[$zx,$zy,$z0-0.05]];
            for($y=(int)floor(min(array_column($tri,1))/25);$y<=(int)floor(max(array_column($tri,1))/25);$y++)
            for($x=(int)floor(min(array_column($tri,0))/25);$x<=(int)floor(max(array_column($tri,0))/25);$x++)$grid[$x.':'.$y][]=$id;
        }
        $vertices=[];$faces=[];$uv=[];$changed=0;
        foreach($surface['faces'] as $i=>$face){
            if($i%100===0)$progress(null);$poly=[];foreach($face as $j=>$id)$poly[]=array_merge($surface['vertices'][$id],$surface['uv'][$i][$j]);
            $pieces=[$poly];$near=[];
            for($y=(int)floor(min(array_column($poly,1))/25);$y<=(int)floor(max(array_column($poly,1))/25);$y++)
            for($x=(int)floor(min(array_column($poly,0))/25);$x<=(int)floor(max(array_column($poly,0))/25);$x++)foreach($grid[$x.':'.$y]??[] as $id)$near[$id]=true;
            foreach($near as $id=>$_){
                $next=[];foreach($pieces as $piece){
                    $removed=[];$outside=BeamTunnels::subtract($piece,$volumes[$id]['planes'],$removed);array_push($next,...$outside);
                    if($removed){$floor=$volumes[$id]['floor'];foreach($removed as &$p)$p[2]=min($p[2],$floor[0]*$p[0]+$floor[1]*$p[1]+$floor[2]);unset($p);$next[]=$removed;$changed++;}
                }$pieces=$next;
            }
            foreach($pieces as $piece)for($j=1;$j<count($piece)-1;$j++){
                $tri=[$piece[0],$piece[$j],$piece[$j+1]];
                $a=$tri[0];$b=$tri[1];$c=$tri[2];$u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
                if(hypot($u[1]*$v[2]-$u[2]*$v[1],hypot($u[2]*$v[0]-$u[0]*$v[2],$u[0]*$v[1]-$u[1]*$v[0]))<1e-9)continue;
                if(count($faces)>=150000)throw new \RuntimeException('Exact tunnel terrain clearance exceeds the mesh budget.');
                $base=count($vertices);foreach($tri as $p)$vertices[]=array_slice($p,0,3);$faces[]=[$base,$base+1,$base+2];$uv[]=array_map(static fn($p)=>array_slice($p,3,2),$tri);
            }
        }
        return ['vertices'=>$vertices,'faces'=>$faces,'uv'=>$uv,'report'=>['corrected_pieces'=>$changed,'triangles'=>count($faces),'method'=>'Exact pavement-triangle clearance; floors below pavement, tunnel overburden preserved','floor_clearance_m'=>0.05]];
    }
}
