<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamTextures
{
    public static function tasks(string $style,int $size): array
    {
        $base=$style==='stock'?1024:$size;$tasks=[];
        foreach(['flat.normal'=>[128,128,255],'roughness.data'=>[190,190,190],'ao.data'=>[255,255,255]] as $name=>$rgb)$tasks[$name.'.png']=['kind'=>'constant_png','size'=>$base,'rgb'=>$rgb];
        $tasks['detail.color.png']=['kind'=>'detail_png'];$tasks['aerial.color.png']=['kind'=>'aerial_png','size'=>$size];
        if($style==='stock'){
            foreach(['detail-neutral.color'=>[128,128,128],'neutral.color'=>[128,128,128],'neutral.normal'=>[128,128,255],'neutral.data'=>[128,128,128]] as $name=>$rgb)$tasks[$name.'.png']=['kind'=>'constant_png','size'=>$name==='detail-neutral.color'?1024:64,'rgb'=>$rgb];
            foreach(BeamTerrain::PALETTE as $id=>$layer)if(isset($layer['color']))$tasks['palette-'.$id.'.color.png']=['kind'=>'palette_png','id'=>$id];
            $tasks['fallback.color.png']=['kind'=>'aerial_png','size'=>1024];
        }
        return $tasks;
    }
    public static function write(BeamRaster $raster,string $style,BeamCompute $compute,callable $put,callable $progress): void
    {
        $shared=['raster'=>$raster->snapshot()];
        if($style==='stock'){$progress('Blending bounded aerial colors into stock terrain materials');$shared['tints']=BeamTerrain::tintPixels($raster,$progress,$compute);}
        $tasks=self::tasks($style,$raster->size);
        $progress('Writing terrain materials with '.$compute->workers().' CPU worker'.($compute->workers()===1?'':'s'));
        foreach($compute->map($tasks,$shared,static fn($task)=>BeamComputeTask::run($shared,$task)) as $file=>$bytes)$put('art/terrains/'.$file,$bytes);
    }
}
