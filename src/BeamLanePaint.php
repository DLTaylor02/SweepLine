<?php
declare(strict_types=1);
namespace SweepLine;

/** Continuous lane-boundary paths across the existing 100-foot-each-side width tapers. */
final class BeamLanePaint
{
    private static function counts(array $p): array
    {
        $n=$p['lanes'];$oneway=$p['oneway']??false;
        return [$p['forward']??($oneway?$n:(int)ceil($n/2)),$p['backward']??($oneway?0:(int)floor($n/2)),$oneway];
    }
    private static function state(array $p,array $ids,bool $reverse=false): array
    {
        [$f,$b,$oneway]=self::counts($p);if($reverse&&!$oneway)[$f,$b]=[$b,$f];$n=max(1,$f+$b);$out=[];
        foreach($ids as $id){
            if($id==='center'){$u=$oneway?0:$b/$n;$visible=$oneway||($f>0&&$b>0);}
            else{$forward=$id[0]==='f';$rank=(int)substr($id,1);$count=$forward?$f:$b;
                $u=($b+($forward?1:-1)*min($rank,max(0,$count)))/$n;$visible=$rank<$count;
            }
            if($reverse)$u=1-$u;
            $out[$id]=['u'=>$u,'visible'=>(float)$visible,'yellow'=>$id==='center'];
        }
        return $out;
    }
    public static function profiles(array $chain,array $profiles,array $original): array
    {
        if(!array_filter($profiles,static fn($p)=>!empty($p['transition'])))return $profiles;
        $reverse=false;foreach($original as $p)if(!empty($p['oneway'])){$reverse=!($p['legal_forward']??true);break;}
        $maxF=0;$maxB=0;foreach($original as $p){[$f,$b,$oneway]=self::counts($p);if($reverse&&!$oneway)[$f,$b]=[$b,$f];$maxF=max($maxF,$f);$maxB=max($maxB,$b);}
        $ids=['center'];for($i=1;$i<$maxF;$i++)$ids[]='f'.$i;for($i=1;$i<$maxB;$i++)$ids[]='b'.$i;
        $changes=[];$previous=self::state($original[0],$ids,$reverse);
        for($i=1;$i<count($original);$i++){$current=self::state($original[$i],$ids,$reverse);if($current!==$previous)$changes[]=[$chain[$i],$previous,$current,($original[$i]['oneway']??false)!==($original[$i-1]['oneway']??false),$original[$i]['oneway']??false];$previous=$current;}
        $base=self::state($original[0],$ids,$reverse);
        foreach($profiles as $i=>&$p){
            $paint=$base;
            foreach($changes as [$at,$before,$after,$split,$afterOneWay]){
                $t=BeamRoadSurface::transitionProgress($chain[$i],$at,$split?$afterOneWay:null);
                foreach($ids as $id)foreach(['u','visible'] as $key)$paint[$id][$key]+=($after[$id][$key]-$before[$id][$key])*$t;
            }
            // At a split, opposing lanes belong to the other carriageway; do not
            // sweep their dividers through this road as if driving lanes were dropped.
            foreach($changes as [$at,$before,$after,$split])if($split&&abs($chain[$i]-$at)<=30.48){
                $raw=self::state($original[$i],$ids,$reverse);foreach($paint as $id=>&$line)if(str_starts_with($id,'b'))$line=$raw[$id];unset($line);
            }
            foreach($paint as &$line){$line['u']=max(0,min(1,$line['u']));$line['visible']=max(0,min(1,$line['visible']));}unset($line);
            $p['lane_paint']=$paint;$p['lane_paint_segment']=!empty($p['transition'])||($i>0&&!empty($profiles[$i-1]['transition']));
        }unset($p);
        return $profiles;
    }
    public static function materials(string $prefix): array
    {
        $out=[];foreach(['white'=>[0.88,0.88,0.84,1],'yellow'=>[0.92,0.75,0.14,1]] as $key=>$color){
            $name=$prefix.'_paint_'.$key;$out['paint_'.$key]=['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1,'Stages'=>[['diffuseColor'=>$color,'specularPower'=>1]]];
        }return $out;
    }
    public static function build(array $branch,array $sections,array $main,array $mainProfiles,string $prefix,array $obstacles=[]): ?array
    {
        $points=$branch['points'];$profiles=$branch['profiles'];$vertices=[];$triangles=[];$along=0.;
        $surface=static function($ringA,$ringB,$u,$t){
            $a=$ringA[0];$b=$ringA[1];$c=$ringB[1];$d=$ringB[0];$left=[];$right=[];
            foreach([0,1,2] as $k){$left[$k]=$a[$k]*(1-$t)+$d[$k]*$t;$right[$k]=$b[$k]*(1-$t)+$c[$k]*$t;}
            $p=[];foreach([0,1,2] as $k)$p[$k]=$left[$k]*(1-$u)+$right[$k]*$u;
            foreach([[$a,$b,$c],[$a,$c,$d]] as $triangle){if(BeamPolygon::cross(...$triangle)<=1e-8)continue;$w=BeamTerrainContact::weights($p,$triangle);if($w&&min($w)>=-1e-7){$p[2]=0.;foreach($w as $j=>$f)$p[2]+=$f*$triangle[$j][2];break;}}return $p;
        };
        for($i=1;$i<count($points);$i++){
            $length=hypot($points[$i][0]-$points[$i-1][0],$points[$i][1]-$points[$i-1][1]);$start=$along;$along+=$length;
            if($length<1e-5||empty($profiles[$i]['lane_paint_segment'])||!empty($profiles[$i]['unmarked']))continue;
            $cuts=[0.,1.];$steps=max(1,(int)ceil($length));for($s=1;$s<$steps;$s++)$cuts[]=$s/$steps;
            for($cycle=(int)floor($start/12);$cycle<=ceil($along/12);$cycle++)foreach([0,3] as $offset){$t=($cycle*12+$offset-$start)/$length;if($t>1e-8&&$t<1-1e-8)$cuts[]=$t;}
            sort($cuts);$cuts=array_values(array_unique($cuts));$seen=[];
            foreach($profiles[$i]['lane_paint']??[] as $id=>$end){
                $begin=$profiles[$i-1]['lane_paint'][$id]??$end;if(max($begin['visible'],$end['visible'])<0.001)continue;
                $signature=sprintf('%.6f:%.6f:%d',$begin['u'],$end['u'],$end['yellow']);if(isset($seen[$signature]))continue;$seen[$signature]=true;
                for($j=1;$j<count($cuts);$j++){
                    $lo=$cuts[$j-1];$hi=$cuts[$j];$mid=($lo+$hi)/2;
                    if($begin['visible']*(1-$mid)+$end['visible']*$mid<0.5)continue;
                    if(!$end['yellow']&&fmod($start+$mid*$length,12)>=3)continue;
                    $quad=[];
                    foreach([$lo,$hi] as $t){
                        $width=$profiles[$i-1]['width_m']*(1-$t)+$profiles[$i]['width_m']*$t;
                        $shoulder=($profiles[$i-1]['shoulder_m']??0)*(1-$t)+($profiles[$i]['shoulder_m']??0)*$t;$margin=$shoulder/$width;
                        $u=$margin+(1-2*$margin)*($begin['u']*(1-$t)+$end['u']*$t);
                        $quad[]=$surface($sections[$i-1],$sections[$i],max(0,$u-0.06/$width),$t);
                        $quad[]=$surface($sections[$i-1],$sections[$i],min(1,$u+0.06/$width),$t);
                    }
                    $base=count($vertices);array_push($vertices,...$quad);$material=$prefix.'_paint_'.($end['yellow']?'yellow':'white');
                    foreach([[0,1,3],[0,3,2]] as $face)$triangles[]=['v'=>array_map(static fn($v)=>$base+$v,$face),'uv'=>[[0,0],[1,0],[1,1]],'material'=>$material];
                    if(count($triangles)>200000)throw new \RuntimeException('Lane-paint geometry exceeds the export limit.');
                }
            }
        }
        if(!$triangles)return null;
        if(isset($branch['join']))[$vertices,$triangles]=BeamJunctionBoundary::trim($vertices,$triangles,$main,$mainProfiles);
        foreach($obstacles as $other)[$vertices,$triangles]=BeamJunctionBoundary::trim($vertices,$triangles,$other['road'],$other['profiles'],$other['sections']??null,$other['weld_boundary']??true);
        if(!$triangles)return null;
        foreach($vertices as &$v)$v[2]+=0.006;unset($v);
        return ['xml'=>BeamPavement::collada($vertices,$triangles,$points[0]),'position'=>$points[0],'triangles'=>count($triangles),'collision'=>'None','method'=>'The outermost right-hand driving lane opens/closes against the paved outer edge; surviving lane boundaries remain continuous.'];
    }
}
