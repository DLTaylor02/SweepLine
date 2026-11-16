<?php
declare(strict_types=1);
namespace SweepLine;

/** Bounded OSM measurements; every fallback remains distinguishable from mapped evidence. */
final class BeamBuildingAttributes
{
    public static function metres(mixed $value): ?float
    {
        if(!is_scalar($value)||!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(m|metres?|meters?|ft|feet|foot|\x27)?\s*$/i',(string)$value,$m))return null;
        $n=(float)$m[1]*(in_array(strtolower($m[2]??''),['ft','feet','foot',"'"],true)?0.3048:1);
        return $n<=600?$n:null;
    }
    public static function color(mixed $value): ?array
    {
        if(!is_string($value))return null;
        $names=['white'=>'ffffff','black'=>'202020','grey'=>'808080','gray'=>'808080','red'=>'b04035','brown'=>'805a40','beige'=>'d8c5a0','yellow'=>'e5ce75','green'=>'628457','blue'=>'547caa','orange'=>'ce8844','cream'=>'eee1bd','tan'=>'c7aa7e'];
        $v=strtolower(trim($value));$v=$names[$v]??ltrim($v,'#');
        if(preg_match('/^[a-f0-9]{3}$/',$v))$v=$v[0].$v[0].$v[1].$v[1].$v[2].$v[2];
        return preg_match('/^[a-f0-9]{6}$/',$v)?array_map(static fn($n)=>hexdec($n)/255,str_split($v,2)):null;
    }
    public static function read(array $tags): array
    {
        $sources=[];$warnings=[];$levels=is_numeric($tags['building:levels']??null)?max(1,min(200,(float)$tags['building:levels'])):null;
        $height=self::metres($tags['height']??null);$mappedHeight=$height!==null;
        if(isset($tags['height'])&&!$mappedHeight)$warnings[]='Invalid or out-of-range height; fallback used.';
        $sources['height']=$height!==null?'height':($levels!==null?'building:levels × estimated 3 m storey':'estimated 6 m');
        $height??=$levels!==null?$levels*3:6.0;
        $minimum=self::metres($tags['min_height']??null);
        $sources['min_height']=$minimum!==null?'min_height':(is_numeric($tags['building:min_level']??null)?'building:min_level × estimated 3 m storey':'ground');
        $minimum??=is_numeric($tags['building:min_level']??null)?max(0,(float)$tags['building:min_level']*3):0.0;
        if($minimum>=$height){$warnings[]='Invalid minimum height; ground-level fallback.';$minimum=0.0;$sources['min_height']='ground fallback: invalid minimum';}
        $shape=strtolower((string)($tags['roof:shape']??'flat'));
        if(!in_array($shape,['flat','gabled','hipped','pyramidal','skillion'],true)){$warnings[]='Unsupported roof shape '.$shape.'; flat fallback.';$shape='flat';}
        $sources['roof_shape']=isset($tags['roof:shape'])?'roof:shape':'estimated flat';
        $roof=self::metres($tags['roof:height']??null);
        $sources['roof_height']=$roof!==null?'roof:height':(is_numeric($tags['roof:levels']??null)?'roof:levels × estimated 3 m storey':'estimated');
        $roof??=is_numeric($tags['roof:levels']??null)?max(0,(float)$tags['roof:levels']*3):min(3,($height-$minimum)*0.25);
        $roof=$shape==='flat'?0:min($roof,max(0,($height-$minimum)*0.8));
        if(!$mappedHeight&&$levels!==null)$height=min(600,$height+$roof);
        return ['height_m'=>$height,'min_height_m'=>$minimum,'levels'=>$levels??max(1,round(($height-$roof-$minimum)/3)),
            'roof_shape'=>$shape,'roof_height_m'=>$roof,'tags'=>$tags,'sources'=>$sources,'warnings'=>$warnings];
    }
}
