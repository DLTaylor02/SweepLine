<?php
declare(strict_types=1);
namespace SweepLine;

/** Stock, unpainted asphalt detail beneath our lane-aware generated paint. */
final class BeamRoadMaterials
{
    public const TEXTURES='/assets/materials/decalroad/asphalt/AsphaltRoad_variation_01/t_asphalt_variation_01';
    public static function material(string $name,string $texture,array $profile): array
    {
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1.5,'groundType'=>'ASPHALT','Stages'=>[[
            'baseColorMap'=>$texture,'baseColorFactor'=>[1,1,1,1],
            'detailMap'=>self::TEXTURES.'_b.color.dds','detailBaseColorMapStrength'=>0.22,
            'normalMap'=>dirname(dirname($texture)).'/terrains/flat.normal.png',
            'detailNormalMap'=>self::TEXTURES.'_nm.normal.dds','detailNormalMapStrength'=>0.35,
            'roughnessDetailMap'=>self::TEXTURES.'_r.data.dds','detailRoughnessMapStrength'=>0.3,
            'roughnessFactor'=>0.85,'metallicFactor'=>0,
            'detailScale'=>[max(1,$profile['width_m']/3),BeamPng::ROAD_TEXTURE_LENGTH_M/3],
            'useAnisotropic'=>true
        ]]];
    }
}
