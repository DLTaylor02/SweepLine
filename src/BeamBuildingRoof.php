<?php
declare(strict_types=1);
namespace SweepLine;

/** Piecewise-planar roofs retain footprint boundaries and courtyard openings. */
final class BeamBuildingRoof
{
    public static function prepare(array $ring,array $attributes,bool $hasHoles=false): array
    {
        $shape=$attributes['roof_shape'];$rise=$attributes['roof_height_m'];$tags=$attributes['tags'];$warnings=[];
        if($hasHoles&&$shape!=='flat'){$warnings[]='Pitched courtyard roof uses a flat fallback.';$shape='flat';$rise=0;}
        $axis=[1.,0.];$long=0;
        foreach($ring as $i=>$p){$q=$ring[($i+1)%count($ring)];$d=hypot($q[0]-$p[0],$q[1]-$p[1]);if($d>$long){$long=$d;$axis=[($q[0]-$p[0])/$d,($q[1]-$p[1])/$d];}}
        $orientation='estimated longest footprint edge';
        // OSM direction is the downslope bearing, clockwise from north; the ridge is perpendicular.
        if(is_numeric($tags['roof:direction']??null)){
            $angle=deg2rad(fmod((float)$tags['roof:direction'],360));$axis=[cos($angle),-sin($angle)];$orientation='roof:direction';
        }elseif(($tags['roof:orientation']??'')==='across'){$axis=[-$axis[1],$axis[0]];$orientation='roof:orientation across';}
        elseif(($tags['roof:orientation']??'')==='along')$orientation='roof:orientation along';
        $cross=[-$axis[1],$axis[0]];$u=[];$v=[];
        foreach($ring as $p){$u[]=$p[0]*$axis[0]+$p[1]*$axis[1];$v[]=$p[0]*$cross[0]+$p[1]*$cross[1];}
        $umin=min($u);$umax=max($u);$vmin=min($v);$vmax=max($v);$du=max(.01,$umax-$umin);$dv=max(.01,$vmax-$vmin);
        $plane=static fn($a,$b,$c)=>[$a*$axis[0]+$b*$cross[0],$a*$axis[1]+$b*$cross[1],$c];
        $planes=[[0,0,0]];
        if($rise>0&&$shape==='skillion')$planes=[$plane(0,-$rise/$dv,$rise*$vmax/$dv)];
        elseif($rise>0&&$shape!=='flat'){
            $planes=[$plane(0,2*$rise/$dv,-2*$rise*$vmin/$dv),$plane(0,-2*$rise/$dv,2*$rise*$vmax/$dv)];
            if(in_array($shape,['hipped','pyramidal'],true)){
                $slope=$shape==='pyramidal'?2*$rise/$du:2*$rise/$dv;
                $planes[]=$plane($slope,0,-$slope*$umin);$planes[]=$plane(-$slope,0,$slope*$umax);
            }
        }
        return ['shape'=>$shape,'rise'=>$rise,'planes'=>$planes,'orientation_source'=>$orientation,'warnings'=>$warnings];
    }
    public static function rise(array $p,array $roof): float
    {
        $z=INF;foreach($roof['planes'] as [$a,$b,$c])$z=min($z,$a*$p[0]+$b*$p[1]+$c);
        return max(0,$z);
    }
    public static function facets(array $triangles,array $roof): array
    {
        $out=[];
        foreach($triangles as $triangle)foreach($roof['planes'] as $i=>$plane){
            $poly=$triangle;
            foreach($roof['planes'] as $j=>$other)if($i!==$j){
                $poly=self::clip($poly,[$plane[0]-$other[0],$plane[1]-$other[1],$plane[2]-$other[2]]);
                if(count($poly)<3)break;
            }
            for($n=1;$n<count($poly)-1;$n++)if(abs(BeamPolygon::cross($poly[0],$poly[$n],$poly[$n+1]))>1e-7)$out[]=[$poly[0],$poly[$n],$poly[$n+1]];
        }
        return $out;
    }
    private static function clip(array $poly,array $plane): array
    {
        if(!$poly)return [];$out=[];[$a,$b,$c]=$plane;
        foreach($poly as $i=>$p){$q=$poly[($i+1)%count($poly)];$s=$a*$p[0]+$b*$p[1]+$c;$t=$a*$q[0]+$b*$q[1]+$c;
            if($s<=1e-8)$out[]=$p;
            if(($s<0&&$t>0)||($s>0&&$t<0)){$f=$s/($s-$t);$out[]=[$p[0]+($q[0]-$p[0])*$f,$p[1]+($q[1]-$p[1])*$f];}
        }return $out;
    }
    public static function edge(array $p,array $q,array $roof): array
    {
        $cuts=[0.,1.];foreach($roof['planes'] as $i=>$a)foreach($roof['planes'] as $j=>$b)if($i<$j){
            $s=($a[0]-$b[0])*$p[0]+($a[1]-$b[1])*$p[1]+$a[2]-$b[2];
            $t=($a[0]-$b[0])*$q[0]+($a[1]-$b[1])*$q[1]+$a[2]-$b[2];
            if(abs($s-$t)>1e-8){$f=$s/($s-$t);if($f>1e-8&&$f<1-1e-8)$cuts[]=$f;}
        }sort($cuts);$out=[];foreach(array_unique($cuts) as $f)$out[]=[$p[0]+($q[0]-$p[0])*$f,$p[1]+($q[1]-$p[1])*$f];return $out;
    }
}
