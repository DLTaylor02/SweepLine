<?php
declare(strict_types=1);
namespace SweepLine;

/** Small self-contained surface/fixture assets; no redistributed game textures. */
final class BeamTunnelFinish
{
    public static function texture(): string
    {
        return BeamPng::rgb(256,256,static function($x,$y){
            $grain=(($x*73+$y*151+($x*$y)%97)%23)-11;
            $joint=($x<2||$y<2)?-19:0;
            $shade=174+$grain+$joint;
            return [$shade+2,$shade+1,$shade];
        });
    }
    public static function materials(string $name,string $path): array
    {
        $result=[];
        foreach([''=>[0.9,0.9,0.88,1],'_roof'=>[0.64,0.65,0.65,1]] as $suffix=>$color){
            $id=$name.$suffix;$result[$id]=['class'=>'Material','name'=>$id,'mapTo'=>$id,'version'=>1,'doubleSided'=>true,'groundType'=>'CONCRETE','Stages'=>[['diffuseMap'=>$path.'art/tunnels/concrete.color.png','diffuseColor'=>$color,'specularPower'=>4,'useAnisotropic'=>true]]];
        }
        foreach(['_fixture'=>[0.15,0.17,0.19,1],'_fixture_lens'=>[0.96,0.94,0.85,1]] as $suffix=>$color){
            $id=$name.$suffix;$stage=['diffuseColor'=>$color,'specularPower'=>8];
            if($suffix==='_fixture_lens')$stage['emissive']=true;
            $result[$id]=['class'=>'Material','name'=>$id,'mapTo'=>$id,'version'=>1,'doubleSided'=>true,'Stages'=>[$stage]];
        }
        return $result;
    }
    public static function fixture(string $material): string
    {
        $v=[[-0.6,-0.14,-0.16],[0.6,-0.14,-0.16],[0.6,0.14,-0.16],[-0.6,0.14,-0.16],[-0.6,-0.14,0],[0.6,-0.14,0],[0.6,0.14,0],[-0.6,0.14,0]];
        $f=[[0,2,1],[0,3,2],[4,5,6],[4,6,7],[0,1,5],[0,5,4],[1,2,6],[1,6,5],[2,3,7],[2,7,6],[3,0,4],[3,4,7]];
        $m=array_fill(0,12,$material);$m[0]=$m[1]=$material.'_lens';
        return BeamAssets::mesh($v,$f,$material,[],$m);
    }
}
