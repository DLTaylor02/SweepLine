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
            'alphaTest'=>true,'alphaRef'=>8,'translucent'=>true,'translucentBlendOp'=>'LerpAlpha','translucentZWrite'=>true,'castShadows'=>false,
            'Stages'=>[['diffuseMap'=>$texture,'diffuseColor'=>[1,1,1,1],'specularPower'=>1,'useAnisotropic'=>true]]];
    }
    /** Identical paint shares one DDS; logical PNG references follow BeamNG's resolver. */
    public static function writeTexture(array $profile,callable $put,array &$cache): string
    {
        $pixels=BeamPng::paintPixels($profile);$hash=hash('sha256',$pixels);
        if(isset($cache[$hash]))return $cache[$hash];
        $file='art/roads/paint-'.substr($hash,0,32).'.color.png';
        $put(substr($file,0,-4).'.dds',BeamDds::rgba(256,BeamPng::ROAD_TEXTURE_HEIGHT,$pixels));
        return $cache[$hash]=$file;
    }
    public static function writePaint(array $mesh,string $prefix,string $file,string $path,callable $put,string $name,float $offset=.004): ?array
    {
        $xml=array_key_exists('paint_xml',$mesh)?$mesh['paint_xml']:$mesh['xml'];if($xml===null)return null;
        $put($file,str_replace($prefix.'_road_',$prefix.'_road_paint_',$xml));
        $position=$mesh['position'];$position[2]+=$offset;
        return ['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$path.$file,'position'=>$position,
            'rotationMatrix'=>[1,0,0,0,1,0,0,0,1],'scale'=>[1,1,1],'collisionType'=>'None','decalType'=>'None','castShadows'=>false];
    }
}
