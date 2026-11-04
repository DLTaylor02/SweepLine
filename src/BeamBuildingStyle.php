<?php
declare(strict_types=1);
namespace SweepLine;

/** Appearance hints from mapped use, never a claim of legal zoning or exact facade reconstruction. */
final class BeamBuildingStyle
{
    public const TEXTURES=[
        'residential'=>'/assets/materials/tileable/wood/eca_bld_wood/eca_bld_wood_d.dds',
        'commercial'=>'/assets/materials/tileable/brick/eca_bld_bricks/eca_bld_bricks_d.dds',
        'industrial'=>'/assets/materials/tileable/metal/moderncorrugated_tin/eca_bld_moderncorrugated_tin_d.dds',
        'generic'=>'/assets/materials/tileable/plaster/eca_bld_modernwall/eca_bld_modernwall_d.dds'
    ];
    private static function category(string $value): ?string
    {
        if(in_array($value,['residential','house','apartments','detached','terrace','bungalow','cabin','dormitory'],true))return 'residential';
        if(in_array($value,['commercial','retail','office','supermarket','hotel','shop'],true))return 'commercial';
        if(in_array($value,['industrial','warehouse','manufacture','hangar','farm_auxiliary','barn','farmyard','stable'],true))return 'industrial';
        return null;
    }
    public static function classify(array $tags,array $footprint=[],array $landuse=[]): array
    {
        foreach(['building:use','building'] as $key)if($category=self::category(strtolower((string)($tags[$key]??''))))return ['category'=>$category,'evidence'=>$key,'confidence'=>'mapped_building_tag'];
        foreach(['shop','office'] as $key)if(isset($tags[$key])&&!in_array($tags[$key],['no','vacant',''],true))return ['category'=>'commercial','evidence'=>$key,'confidence'=>'mapped_building_tag'];
        $choices=[];
        foreach($landuse as $area){
            $category=self::category($area['landuse']);if(!$category||!$footprint)continue;
            $inside=true;foreach($footprint as $p)if(!BeamPolygon::inside($p,$area['points'])){$inside=false;break;}
            if($inside)$choices[$category]=true;
        }
        if(count($choices)===1)return ['category'=>array_key_first($choices),'evidence'=>'containing_landuse','confidence'=>'landuse_estimate'];
        return ['category'=>'generic','evidence'=>$choices?'conflicting_landuse':'unknown','confidence'=>'unknown'];
    }
    public static function mesh(array $footprint,float $height,string $wall,string $roof): string
    {
        $ring=BeamPolygon::clean($footprint);$n=count($ring);$vertices=[];$triangles=[];$uv=[];$materials=[];
        foreach([0,$height] as $z)foreach($ring as $p)$vertices[]=[$p[0],$p[1],$z];
        foreach(BeamPolygon::triangles($ring) as [$a,$b,$c]){
            $triangles[]=[$a+$n,$b+$n,$c+$n];$uv[]=array_map(static fn($i)=>[$ring[$i][0]/4,$ring[$i][1]/4],[$a,$b,$c]);$materials[]=$roof;
            $triangles[]=[$a,$c,$b];$uv[]=array_map(static fn($i)=>[$ring[$i][0]/4,$ring[$i][1]/4],[$a,$c,$b]);$materials[]=$roof;
        }
        foreach($ring as $i=>$p){
            $j=($i+1)%$n;$length=hypot($ring[$j][0]-$p[0],$ring[$j][1]-$p[1]);$w=$length/4;$h=$height/3;
            $triangles[]=[$i,$j,$n+$j];$uv[]=[[0,0],[$w,0],[$w,$h]];$materials[]=$wall;
            $triangles[]=[$i,$n+$j,$n+$i];$uv[]=[[0,0],[$w,$h],[0,$h]];$materials[]=$wall;
        }
        return BeamAssets::mesh($vertices,$triangles,$wall,$uv,$materials);
    }
}
