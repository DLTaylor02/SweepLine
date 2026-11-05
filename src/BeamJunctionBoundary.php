<?php
declare(strict_types=1);
namespace SweepLine;

/** Exact ribbon boundary shared by primary pavement and adjoining collision meshes. */
final class BeamJunctionBoundary
{
    public static function sections(array $road,array $profiles): array
    {
        $out=[];foreach($road as $i=>$point)$out[]=BeamPavement::section($road,$profiles,$i);return $out;
    }

    /** Insert paint boundaries without changing the existing pavement edges. */
    public static function renderSections(array $road,array $profiles,array $render): array
    {
        $sections=self::sections($road,$profiles);$chain=[0.0];
        for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
        $out=[];$along=0.0;$at=1;
        foreach($render as $i=>$p){
            if($i)$along+=hypot($p[0]-$render[$i-1][0],$p[1]-$render[$i-1][1]);
            while($at<count($road)-1&&$chain[$at]<$along-0.000001)$at++;
            $t=max(0,min(1,($along-$chain[$at-1])/max(0.000001,$chain[$at]-$chain[$at-1])));$ring=[];
            foreach([0,1] as $side){$v=[];foreach([0,1,2] as $axis)$v[]=$sections[$at-1][$side][$axis]*(1-$t)+$sections[$at][$side][$axis]*$t;$ring[]=$v;}
            $out[]=$ring;
        }
        return $out;
    }

    /** First actual edge hit, rather than distance to an estimated centreline buffer. */
    public static function hit(array $end,array $direction,array $road,array $profiles,array $join): ?array
    {
        $sections=self::sections($road,$profiles);$nearest=INF;$point=null;$chain=0.0;
        foreach($road as $i=>$p){
            if(!$i)continue;
            $length=hypot($p[0]-$road[$i-1][0],$p[1]-$road[$i-1][1]);$start=$chain;$chain+=$length;
            if($chain<$join['along']-50||$start>$join['along']+50)continue;
            foreach([0,1] as $side){
                $a=$sections[$i-1][$side];$b=$sections[$i][$side];$edge=[$b[0]-$a[0],$b[1]-$a[1]];
                $ray=[-$direction[0],-$direction[1]];$den=$ray[0]*$edge[1]-$ray[1]*$edge[0];if(abs($den)<1e-10)continue;
                $v=[$a[0]-$end[0],$a[1]-$end[1]];$t=($v[0]*$edge[1]-$v[1]*$edge[0])/$den;$u=($v[0]*$ray[1]-$v[1]*$ray[0])/$den;
                if($t>=-0.000001&&$t<=$nearest&&$t<=100&&$u>=-0.000001&&$u<=1.000001){$nearest=$t;$point=[$a[0]+$u*$edge[0],$a[1]+$u*$edge[1],$a[2]+$u*($b[2]-$a[2])];}
            }
        }
        return $point;
    }

    /** Primary triangles near a branch. Bounded boxes avoid all-pairs triangle clipping. */
    public static function cutters(array $road,array $profiles,array $vertices,?array $surfaceSections=null): array
    {
        if(!$vertices)return [];
        $xs=array_column($vertices,0);$ys=array_column($vertices,1);$bounds=[min($xs)-0.01,min($ys)-0.01,max($xs)+0.01,max($ys)+0.01];$s=$surfaceSections??self::sections($road,$profiles);$out=[];
        for($i=1;$i<count($s);$i++){
            $a=$s[$i-1][0];$b=$s[$i-1][1];$c=$s[$i][1];$d=$s[$i][0];
            foreach([[$a,$b,$c],[$a,$c,$d]] as $triangle){
                $x=array_column($triangle,0);$y=array_column($triangle,1);
                if(max($x)<$bounds[0]||min($x)>$bounds[2]||max($y)<$bounds[1]||min($y)>$bounds[3])continue;
                if(BeamPolygon::cross(...$triangle)<0)$triangle=array_reverse($triangle);
                $out[]=$triangle;
            }
        }
        return $out;
    }

    /** Subtract a convex main-road triangle, interpolating position and lane UVs. */
    public static function subtract(array $polygon,array $triangle): array
    {
        $out=[];$inside=$polygon;
        foreach([0,1,2] as $i){
            if(count($inside)<3)break;$a=$triangle[$i];$b=$triangle[($i+1)%3];$keep=[];$reject=[];
            foreach($inside as $j=>$p){
                $q=$inside[($j+1)%count($inside)];$dp=BeamPolygon::cross($a,$b,$p);$dq=BeamPolygon::cross($a,$b,$q);
                $epsilon=1e-8*max(1,hypot($b[0]-$a[0],$b[1]-$a[1]));if(abs($dp)<$epsilon)$dp=0.0;if(abs($dq)<$epsilon)$dq=0.0;
                if($dp>=0)$keep[]=$p;if($dp<=0)$reject[]=$p;
                if(($dp>0&&$dq<0)||($dp<0&&$dq>0)){$t=$dp/($dp-$dq);$v=[];foreach($p as $axis=>$value)$v[]=$value+($q[$axis]-$value)*$t;$keep[]=$v;$reject[]=$v;}
            }
            if(count($reject)>=3)$out[]=$reject;$inside=$keep;
        }
        return $out;
    }

    public static function trim(array $vertices,array $triangles,array $road,array $profiles,?array $surfaceSections=null): array
    {
        $cutters=self::cutters($road,$profiles,$vertices,$surfaceSections);$s=$surfaceSections??self::sections($road,$profiles);$result=[];$out=[];$lookup=[];$edges=[];
        $xs=array_column($vertices,0);$ys=array_column($vertices,1);if(!$xs)return [$vertices,$triangles];
        $bounds=[min($xs)-0.01,min($ys)-0.01,max($xs)+0.01,max($ys)+0.01];
        for($i=1;$i<count($s);$i++)foreach([0,1] as $side){
            $a=$s[$i-1][$side];$b=$s[$i][$side];
            if(max($a[0],$b[0])<$bounds[0]||min($a[0],$b[0])>$bounds[2]||max($a[1],$b[1])<$bounds[1]||min($a[1],$b[1])>$bounds[3])continue;
            $edges[]=[$a,$b];
        }
        $vertex=static function($p)use(&$out,&$lookup){$key=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p));if(isset($lookup[$key]))return $lookup[$key];$i=count($out);$lookup[$key]=$i;$out[]=$p;return $i;};
        foreach($triangles as $triangle){
            $pieces=[array_map(static fn($id,$uv)=>array_merge($vertices[$id],$uv),$triangle['v'],$triangle['uv'])];
            foreach($cutters as $cut){
                $next=[];
                foreach($pieces as $piece){
                    $px=array_column($piece,0);$py=array_column($piece,1);$cx=array_column($cut,0);$cy=array_column($cut,1);
                    if(max($px)<=min($cx)||min($px)>=max($cx)||max($py)<=min($cy)||min($py)>=max($cy)){$next[]=$piece;continue;}
                    array_push($next,...self::subtract($piece,$cut));
                }
                $pieces=$next;if(!$pieces)break;
            }
            foreach($pieces as $piece){
                foreach($piece as &$p){
                    // Only real external ribbon edges set contact elevation; never internal diagonals.
                    foreach($edges as [$a,$b]){$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$l=$dx*$dx+$dy*$dy;if($l<1e-12)continue;
                        $t=(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$l;
                        if($t>=-1e-8&&$t<=1+1e-8&&hypot($p[0]-$a[0]-$t*$dx,$p[1]-$a[1]-$t*$dy)<0.000001)$p[2]=$a[2]+$t*($b[2]-$a[2]);
                    }
                }unset($p);
                for($i=1;$i<count($piece)-1;$i++){
                    $p=[$piece[0],$piece[$i],$piece[$i+1]];if(abs(BeamPolygon::cross(...$p))<1e-8)continue;
                    $result[]=['v'=>array_map(static fn($v)=>$vertex(array_slice($v,0,3)),$p),'uv'=>array_map(static fn($v)=>array_slice($v,3,2),$p),'material'=>$triangle['material']];
                }
            }
        }
        return [$out,$result];
    }
}
