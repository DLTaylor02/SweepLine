<?php
declare(strict_types=1);
namespace SweepLine;

/** Native lightly weathered asphalt, with independent lane paint over its intact surface detail. */
final class BeamRoadMaterials
{
    public const VARIATION_LENGTH_M=733;
    /** Seamless restrained color variation; stock pavement grain and paint remain independent. */
    public static function variation(): string {
        return BeamPng::rgb(128,512,static function(int $x,int $y):array{
            $u=2*M_PI*$x/127;$v=2*M_PI*$y/511;
            $value=(int)round(128+18*sin(2*$v+0.45*sin($u))+8*sin(5*$v-2*$u)+4*sin(11*$v+$u));
            return [$value,$value,$value];
        });
    }
    public const TEXTURES='/assets/materials/decalroad/asphalt/AsphaltRoad_variation_01/t_asphalt_variation_01';
    public static function maskPath(string $texture): string {return preg_replace('/\.color\.png$/D','.paint.data.png',$texture);}
    public static function material(string $name,string $texture,array $profile): array
    {
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1.5,'activeLayers'=>2,'groundType'=>'ASPHALT','Stages'=>[
            ['baseColorMap'=>self::TEXTURES.'_b.color.dds','baseColorFactor'=>[0.9,0.9,0.9,1],
             'normalMap'=>self::TEXTURES.'_nm.normal.dds','roughnessMap'=>self::TEXTURES.'_r.data.dds',
             'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true,
             'detailMap'=>dirname($texture).'/variation.color.png','detailBaseColorMapStrength'=>0.22,
             'detailScale'=>[0.35,BeamPng::ROAD_TEXTURE_LENGTH_M/self::VARIATION_LENGTH_M],'detailMapUseUV'=>0],
            ['baseColorMap'=>$texture,'baseColorFactor'=>[1,1,1,1],
             'opacityMap'=>self::maskPath($texture),'opacityFactor'=>1,'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true]
        ]];
    }
}
