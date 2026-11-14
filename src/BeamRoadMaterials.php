<?php
declare(strict_types=1);
namespace SweepLine;

/** Native lightly weathered asphalt, with independent lane paint over its intact surface detail. */
final class BeamRoadMaterials
{
    public const VARIATION_LENGTH_M=733;
    public const VARIATION_STRENGTH=0.14;
    /** Seamless restrained color variation; stock pavement grain and paint remain independent. */
    public static function variation(): string {
        $patches=[];
        for($i=0;$i<24;$i++)$patches[]=[(($i*37+11)%101)/101,(($i*67+19)%127)/127,0.014+($i%4)*0.006,(6+($i%7)*2)/self::VARIATION_LENGTH_M,$i%5===0?18:-42-($i%4)*6];
        return BeamPng::rgb(128,512,static function(int $x,int $y)use($patches):array{
            $u=2*M_PI*$x/127;$v=2*M_PI*$y/511;
            $value=128+18*sin(2*$v+0.45*sin($u))+8*sin(5*$v-2*$u)+4*sin(11*$v+$u);
            foreach($patches as [$cx,$cy,$rx,$ry,$tone]){
                $dx=abs($x/127-$cx);$dy=abs($y/511-$cy);$dx=min($dx,1-$dx);$dy=min($dy,1-$dy);
                if($dx>3*$rx||$dy>3*$ry)continue;
                $radius=($dx/$rx)**2+($dy/$ry)**2;
                if($radius<9)$value+=$tone*exp(-0.5*$radius)*(0.9+0.1*sin(7*$u+13*$v));
            }
            $value=(int)round(max(56,min(170,$value)));
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
             'detailMap'=>dirname($texture).'/variation.color.png','detailBaseColorMapStrength'=>self::VARIATION_STRENGTH,
             'detailScale'=>[0.35,BeamPng::ROAD_TEXTURE_LENGTH_M/self::VARIATION_LENGTH_M],'detailMapUseUV'=>0],
            ['baseColorMap'=>$texture,'baseColorFactor'=>[1,1,1,1],
             'opacityMap'=>self::maskPath($texture),'opacityFactor'=>1,'roughnessFactor'=>0.85,'metallicFactor'=>0,'useAnisotropic'=>true]
        ]];
    }
}
