<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamComputeTask
{
    public static function run(array $shared,array $task): string
    {
        $raster=isset($shared['raster'])?BeamRaster::restore($shared['raster']):null;
        $noop=static fn($m=null)=>null;
        return match($task['kind']){
            'classify_rows'=>BeamTerrain::classifyRows($raster,$shared['center'],$task['start'],$task['end'],$noop),
            'filter_rows'=>BeamTerrain::filterRows($shared['grid'],$shared['size'],$task['start'],$task['end'],$noop),
            'tint_rows'=>BeamTerrain::tintRows($raster,$task['start'],$task['end'],$noop),
            'palette_png'=>BeamPng::rgb(1024,1024,static function($x,$y)use($shared,$task){$i=($y*1024+$x)*3;return BeamTerrain::blendedColor($task['id'],[ord($shared['tints'][$i]),ord($shared['tints'][$i+1]),ord($shared['tints'][$i+2])]);}),
            'aerial_png'=>BeamPng::rgb($task['size'],$task['size'],static fn($x,$y)=>BeamRaster::saturated($raster->rgb((int)($x/$task['size']*$raster->size),$raster->size-1-(int)($y/$task['size']*$raster->size)))),
            'constant_png'=>BeamPng::rgb($task['size'],$task['size'],static fn()=>$task['rgb']),
            'detail_png'=>BeamPng::rgb(64,64,static fn($x,$y)=>array_fill(0,3,128+(($x*17+$y*23)%17)-8)),
            default=>throw new \InvalidArgumentException('Unknown parallel terrain task.')
        };
    }
}
