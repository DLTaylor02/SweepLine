<?php
declare(strict_types=1);
namespace SweepLine;
/** File-backed bounded visual meshes; no new collision surfaces. */
final class BeamContextMesh {
    private array $v=[],$t=[],$m=[],$objects=[];
    private int $parts=0,$primitives=0;
    public bool $limited=false;
    public function __construct(private string $prefix,private string $path,private \Closure $put){}
    private function add(array $vertices,array $faces,string $material):void{
        if($this->primitives>=12000){$this->limited=true;return;}$this->primitives++;
        $base=count($this->v);array_push($this->v,...$vertices);
        foreach($faces as $face){$this->t[]=array_map(static fn($i)=>$base+$i,$face);$this->m[]=$this->prefix.'_'.$material;}
        if(count($this->t)>=2000)$this->flush();
    }
    public function box(array $p,float $w,float $d,float $h,string $material,float $yaw=0):void{
        $c=cos($yaw);$s=sin($yaw);$v=[];
        foreach([[0,0,0],[1,0,0],[1,1,0],[0,1,0],[0,0,1],[1,0,1],[1,1,1],[0,1,1]] as [$x,$y,$z]){
            $x=($x-.5)*$w;$y=($y-.5)*$d;$v[]=[$p[0]+$c*$x-$s*$y,$p[1]+$s*$x+$c*$y,$p[2]+$z*$h];
        }
        $this->add($v,[[0,2,1],[0,3,2],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]],$material);
    }
    public function cylinder(array $p,float $r,float $h,string $material):void{
        $v=[];$faces=[];for($level=0;$level<2;$level++)for($i=0;$i<8;$i++){$a=$i*M_PI/4;$radius=$r*($level?.8:1);$v[]=[$p[0]+cos($a)*$radius,$p[1]+sin($a)*$radius,$p[2]+$level*$h];}
        for($i=1;$i<7;$i++){$faces[]=[0,$i+1,$i];$faces[]=[8,8+$i,8+$i+1];}
        for($i=0;$i<8;$i++){$j=($i+1)%8;$faces[]=[$i,$j,8+$j];$faces[]=[$i,8+$j,8+$i];}
        $this->add($v,$faces,$material);
    }
    public function beam(array $a,array $b,float $w,float $d,string $material):void{
        $axis=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$length=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$axis)));if($length<.001)return;
        $axis=array_map(static fn($v)=>$v/$length,$axis);$u=hypot($axis[0],$axis[1])>.001?[-$axis[1],$axis[0],0.]:[1.,0.,0.];$l=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$u)));$u=array_map(static fn($v)=>$v/$l,$u);
        $v=[$axis[1]*$u[2]-$axis[2]*$u[1],$axis[2]*$u[0]-$axis[0]*$u[2],$axis[0]*$u[1]-$axis[1]*$u[0]];$vertices=[];
        foreach([$a,$b] as $p)foreach([[-1,-1],[1,-1],[1,1],[-1,1]] as [$x,$y])$vertices[]=[$p[0]+$u[0]*$x*$w/2+$v[0]*$y*$d/2,$p[1]+$u[1]*$x*$w/2+$v[1]*$y*$d/2,$p[2]+$u[2]*$x*$w/2+$v[2]*$y*$d/2];
        $this->add($vertices,[[0,2,1],[0,3,2],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]],$material);
    }
    public function triangle(array $points,string $material):void{$this->add($points,[[0,1,2]],$material);}
    public function flush():void{
        if(!$this->t)return;$name='mapped_context_'.($this->parts++);$file='art/context/'.$name.'.dae';
        $uv=[];foreach($this->t as $face)$uv[]=array_map(fn($id)=>[$this->v[$id][0]/48,$this->v[$id][1]/48],$face);
        ($this->put)($file,BeamAssets::mesh($this->v,$this->t,$this->m[0],$uv,$this->m));
        $this->objects[]=['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$this->path.$file,'position'=>[0,0,0],'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>true];
        $this->v=[];$this->t=[];$this->m=[];
    }
    public function finish():array{$this->flush();return ['objects'=>$this->objects,'mesh_parts'=>$this->parts,'primitives'=>$this->primitives,'limited'=>$this->limited];}
}
