<?php
declare(strict_types=1);
namespace SweepLine;

/** Reconcile approach-estimated underground profiles with the actual overlying pavement. */
final class BeamTunnelProfile
{
    public const SHELL_M=.35;
    public const COVER_M=.25;
    public const MAX_LOWERING_M=2.;
    public const TAPER_M=50.;

    /** Surfaces use road/profiles/spans; only explicitly estimated tunnel floors may move. */
    public static function fit(array $surfaces,float $grid,callable $progress): array
    {
        $hasTunnel=false;
        foreach($surfaces as $s)foreach($s['spans']??[] as $span)if(isset($span['clearance_m']))$hasTunnel=true;
        if(!$hasTunnel)return ['surfaces'=>$surfaces,'adjustments'=>[]];
        // Retain only pavement near bores, not an extra full interchange mesh.
        $windows=[];
        foreach($surfaces as $surfaceKey=>$s){
            $at=(float)($s['chainage_offset']??0.);
            for($i=1;$i<count($s['road']);$i++){
                $a=$s['road'][$i-1];$b=$s['road'][$i];$end=$at+hypot($b[0]-$a[0],$b[1]-$a[1]);
                foreach($s['spans']??[] as $spanKey=>$span)if(isset($span['clearance_m'])&&$span['start_m']<=$end&&$span['end_m']>=$at){
                    $pad=max($s['profiles'][$i-1]['width_m'],$s['profiles'][$i]['width_m'])/2+1.5*$grid;
                    $id=$surfaceKey.':'.$spanKey;$previous=$windows[$id]??[INF,INF,-INF,-INF];
                    $windows[$id]=[min($previous[0],$a[0]-$pad,$b[0]-$pad),min($previous[1],$a[1]-$pad,$b[1]-$pad),max($previous[2],$a[0]+$pad,$b[0]+$pad),max($previous[3],$a[1]+$pad,$b[1]+$pad)];
                }$at=$end;
            }
        }
        $upper=[];$checks=0;
        foreach($surfaces as $key=>$s){
            $tunnels=array_values(array_filter($s['spans']??[],static fn($p)=>isset($p['clearance_m'])));
            foreach($tunnels as &$p){$p['start_m']-=($s['chainage_offset']??0.);$p['end_m']-=($s['chainage_offset']??0.);}unset($p);
            $upper[$key]=[];
            foreach(BeamTerrainContact::ordinaryTriangles($s['road'],$s['profiles'],$tunnels) as $triangle){
                if(++$checks%1000===0)$progress(null);
                $xs=array_column($triangle,0);$ys=array_column($triangle,1);
                foreach($windows as [$w,$south,$e,$north])if(max($xs)>=$w&&min($xs)<=$e&&max($ys)>=$south&&min($ys)<=$north){$upper[$key][]=$triangle;break;}
            }
        }
        $reports=[];
        foreach($surfaces as $key=>&$surface){
            $chain=[(float)($surface['chainage_offset']??0.)];$road=$surface['road'];
            for($i=1;$i<count($road);$i++)$chain[]=$chain[$i-1]+hypot($road[$i][0]-$road[$i-1][0],$road[$i][1]-$road[$i-1][1]);
            foreach($surface['spans']??[] as $span){
                if(!isset($span['clearance_m']))continue;
                $progress(null);$required=0.;
                // Include nearby raster influence, not only an exact centerline crossing.
                for($i=1;$i<count($road);$i++){
                    $lo=max($chain[$i-1],$span['start_m']);$hi=min($chain[$i],$span['end_m']);
                    if($hi<=$lo||$chain[$i]-$chain[$i-1]<1e-6)continue;
                    $a=$road[$i-1];$b=$road[$i];$length=$chain[$i]-$chain[$i-1];$dx=($b[0]-$a[0])/$length;$dy=($b[1]-$a[1])/$length;
                    $t0=($lo-$chain[$i-1])/$length;$t1=($hi-$chain[$i-1])/$length;
                    $c=[$a[0]+$dx*$length*$t0,$a[1]+$dy*$length*$t0];$d=[$a[0]+$dx*$length*$t1,$a[1]+$dy*$length*$t1];
                    $half=max($surface['profiles'][$i-1]['width_m'],$surface['profiles'][$i]['width_m'])/2+1.5*$grid;
                    $box=[[$c[0]-$dy*$half,$c[1]+$dx*$half],[$c[0]+$dy*$half,$c[1]-$dx*$half],[$d[0]+$dy*$half,$d[1]-$dx*$half],[$d[0]-$dy*$half,$d[1]+$dx*$half]];
                    $xs=array_column($box,0);$ys=array_column($box,1);
                    foreach($upper as $other=>$triangles){
                        if($other===$key)continue;
                        foreach($triangles as $triangle){
                            if(max(array_column($triangle,0))<min($xs)||min(array_column($triangle,0))>max($xs)||max(array_column($triangle,1))<min($ys)||min(array_column($triangle,1))>max($ys))continue;
                            foreach([[$box[0],$box[1],$box[2]],[$box[0],$box[2],$box[3]]] as $cut){
                                foreach(BeamTerrainContact::clip($triangle,$cut) as $p){
                                    $weights=BeamTerrainContact::weights($p,$triangle);if(!$weights)continue;
                                    $z=0.;foreach($weights as $j=>$w)$z+=$w*$triangle[$j][2];
                                    $t=max(0.,min(1.,(($p[0]-$a[0])*$dx+($p[1]-$a[1])*$dy)/$length));
                                    $floor=$a[2]+($b[2]-$a[2])*$t;
                                    // Same-level junctions and roads below this bore are not overburden.
                                    if($z-$floor<2.4)continue;
                                    $required=max($required,$floor+$span['clearance_m']+self::SHELL_M+self::COVER_M-$z);
                                }
                            }
                        }
                    }
                }
                if($required<.0001)continue;
                $required+=.02;
                if(empty($span['estimated'])||$required>self::MAX_LOWERING_M)
                    throw new \RuntimeException('An overlying road conflicts with the tunnel roof; the required underground profile adjustment exceeds the supported estimate. Surveyed elevations are required.');
                $candidate=$surface['road'];
                foreach($chain as $i=>$at){
                    $distance=max($span['start_m']-$at,$at-$span['end_m'],0.);
                    $weight=$distance>=self::TAPER_M?0.:.5+.5*cos(M_PI*$distance/self::TAPER_M);
                    $candidate[$i][2]-=$required*$weight;
                }
                for($i=1;$i<count($candidate);$i++)if($chain[$i]>$span['start_m']-self::TAPER_M&&$chain[$i-1]<$span['end_m']+self::TAPER_M
                    &&abs($candidate[$i][2]-$candidate[$i-1][2])/max(.001,$chain[$i]-$chain[$i-1])>.12)
                    throw new \RuntimeException('Fitting the estimated tunnel beneath an overlying road would exceed the supported 12% approach grade.');
                $surface['road']=$candidate;$road=$candidate;
                $reports[]=['surface'=>$surface['name'],'start_m'=>$span['start_m'],'end_m'=>$span['end_m'],'lowering_m'=>$required,
                    'clearance_m'=>$span['clearance_m'],'taper_m'=>self::TAPER_M,'method'=>'Estimated floor lowered beneath overlying pavement; clearance, XY and distant approach anchors retained'];
            }
        }unset($surface);
        return ['surfaces'=>$surfaces,'adjustments'=>$reports];
    }
}
