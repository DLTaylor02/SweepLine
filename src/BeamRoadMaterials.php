<?php
declare(strict_types=1);
namespace SweepLine;

/** Stock asphalt retains its PBR/decal surface; lane paint is an independent cutout overlay. */
final class BeamRoadMaterials
{
    public const TEXTURES='/assets/materials/decalroad/asphalt/AsphaltRoad_variation_01/t_asphalt_variation_01';
    public static function maskPath(string $texture): string {return preg_replace('/\.color\.png$/D','.paint.data.png',$texture);}
    public static function material(string $name,string $texture,array $profile): array
    {
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1.5,'activeLayers'=>1,'groundType'=>'ASPHALT','Stages'=>[
            ['baseColorMap'=>self::TEXTURES.'_b.color.dds','baseColorFactor'=>[0.9,0.9,0.9,1],
             'normalMap'=>self::TEXTURES.'_nm.normal.dds','roughnessMap'=>self::TEXTURES.'_r.data.dds',
             'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true]
        ]];
    }
    public static function paintMaterial(string $name,string $texture): array
    {
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1,'groundType'=>'ASPHALT',
            'alphaTest'=>true,'alphaRef'=>128,'translucent'=>false,'castShadows'=>false,
            'Stages'=>[['diffuseMap'=>$texture,'diffuseColor'=>[1,1,1,1],'specularPower'=>1,'useAnisotropic'=>true]]];
    }
    public static function writePaint(array $mesh,string $prefix,string $file,string $path,callable $put,string $name,float $offset=.004): array
    {
        $put($file,str_replace($prefix.'_road_',$prefix.'_road_paint_',$mesh['xml']));
        $position=$mesh['position'];$position[2]+=$offset;
        return ['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$position,
            'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>false];
    }
}
