<?php
declare(strict_types=1);
namespace SweepLine;

/** Native lightly weathered asphalt, with independent lane paint over its intact surface detail. */
final class BeamRoadMaterials
{
    public const TEXTURES='/assets/materials/decalroad/asphalt/AsphaltRoad_variation_01/t_asphalt_variation_01';
    public static function maskPath(string $texture): string {return preg_replace('/\.color\.png$/D','.paint.data.png',$texture);}
    public static function material(string $name,string $texture,array $profile): array
    {
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1.5,'activeLayers'=>2,'groundType'=>'ASPHALT','Stages'=>[
            ['baseColorMap'=>self::TEXTURES.'_b.color.dds','baseColorFactor'=>[0.9,0.9,0.9,1],
             'normalMap'=>self::TEXTURES.'_nm.normal.dds','roughnessMap'=>self::TEXTURES.'_r.data.dds',
             'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true],
            ['baseColorMap'=>$texture,'baseColorFactor'=>[1,1,1,1],
             'opacityMap'=>self::maskPath($texture),'opacityFactor'=>1,'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true]
        ]];
    }
}
