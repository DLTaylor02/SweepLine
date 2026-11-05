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
    public const SIDING_VARIANTS=[
        '/assets/materials/tileable/wood/eca_bld_woodcladding_new/eca_bld_woodcladding_new_d.dds',
        '/assets/materials/tileable/wood/eca_bld_woodcladding_old/eca_bld_woodcladding_01_d.dds',
        '/assets/materials/tileable/wood/eca_bld_wood/eca_bld_wood_d.dds'
    ];
    public static function appearance(string $category,string $identity): array
    {
        $variant=(int)hexdec(substr(hash('sha256',$identity),0,6))%15;
        $texture=$category==='residential'?self::SIDING_VARIANTS[$variant%3]:self::TEXTURES[$category];
        $colors=$category==='residential'?[[1,0.98,0.93],[0.87,0.94,1],[0.90,0.98,0.91],[0.96,0.92,0.87],[1,1,1]]:[[1,1,1],[0.94,0.96,1],[1,0.97,0.92],[0.94,0.94,0.94],[0.98,0.96,0.94]];
        return ['key'=>$category.'_v'.$variant,'texture'=>$texture,'normal'=>str_replace('_d.dds','_n.dds',$texture),'specular'=>str_replace('_d.dds','_s.dds',$texture),
            'color'=>$colors[intdiv($variant,3)],'roof'=>$variant%3,'roof_color'=>[[0.22,0.24,0.26],[0.32,0.28,0.24],[0.35,0.36,0.35]][$variant%3]];
    }
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
    public static function mesh(array $footprint,float $height,string $wall,string $roof,array $options=[]): string
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
        if(!empty($options['details']))self::details($ring,$height,$wall,$options,$vertices,$triangles,$uv,$materials);
        return BeamAssets::mesh($vertices,$triangles,$wall,$uv,$materials);
    }
    /** Decorative estimates on existing walls, not measured windows or entrances. */
    private static function details(array $ring,float $height,string $wall,array $options,array &$vertices,array &$triangles,array &$uv,array &$materials): void
    {
        $category=$options['category']??'generic';$trim=$options['trim'];$glass=$options['glass'];$door=$options['door'];
        $foundation=max(0,(float)($options['foundation']??0));$floor=$foundation+0.2;
        $levels=max(1,min(10,(int)($options['levels']??round(($height-$floor)/3))));$storey=($height-$floor)/$levels;
        $front=$options['front']??[0,-100];$best=-INF;$frontEdge=0;
        foreach($ring as $i=>$p){$next=$ring[($i+1)%count($ring)];$dx=$next[0]-$p[0];$dy=$next[1]-$p[1];$length=hypot($dx,$dy);if($length<1)continue;
            $vx=$front[0]-($p[0]+$next[0])/2;$vy=$front[1]-($p[1]+$next[1])/2;$distance=hypot($vx,$vy)?:1;$score=($dy*$vx-$dx*$vy)/$length/$distance;
            if($score>$best){$best=$score;$frontEdge=$i;}
        }
        $openings=0;
        foreach($ring as $i=>$p){
            $next=$ring[($i+1)%count($ring)];$dx=$next[0]-$p[0];$dy=$next[1]-$p[1];$length=hypot($dx,$dy);if($length<0.1)continue;$dx/=$length;$dy/=$length;
            $quad=static function($along,$z,$width,$tall,$offset,$material)use($p,$dx,$dy,&$vertices,&$triangles,&$uv,&$materials){
                $base=count($vertices);foreach([[-0.5,0],[0.5,0],[0.5,1],[-0.5,1]] as [$x,$y]){$at=$along+$x*$width;$vertices[]=[$p[0]+$at*$dx+$dy*$offset,$p[1]+$at*$dy-$dx*$offset,$z+$y*$tall];}
                $triangles[]=[$base,$base+1,$base+2];$uv[]=[[0,0],[1,0],[1,1]];$materials[]=$material;
                $triangles[]=[$base,$base+2,$base+3];$uv[]=[[0,0],[1,1],[0,1]];$materials[]=$material;
            };
            $quad($length/2,$height-0.16,$length,0.16,0.012,$trim);
            if($floor+0.15<$height)$quad($length/2,$floor,$length,0.13,0.012,$trim);
            $doorWidth=$category==='industrial'?min(3,$length-0.8):0.95;$doorHeight=$category==='industrial'?min(3,$height-$floor-0.25):min(2.1,$height-$floor-0.25);
            $hasDoor=$i===$frontEdge&&$length>$doorWidth+0.8&&$doorHeight>1.8;
            if($hasDoor){
                $quad($length/2,$floor,$doorWidth+0.16,$doorHeight+0.08,0.018,$trim);
                $quad($length/2,$floor,$doorWidth,$doorHeight,0.025,$category==='commercial'?$glass:$door);
            }
            if($length<2.5||$storey<2.3)continue;
            $bays=max(1,min(10,(int)floor($length/($category==='industrial'?6:3.6))));$rows=$category==='industrial'?1:$levels;
            for($row=0;$row<$rows;$row++)for($bay=0;$bay<$bays;$bay++){
                if($openings>=96)break 2;$along=($bay+0.5)*$length/$bays;
                $width=$category==='commercial'&&$row===0?min(2.6,$length/$bays-0.8):min(1.25,$length/$bays-0.8);
                $tall=$category==='commercial'&&$row===0?min(2.1,$storey-0.8):min(1.3,$storey-1.2);
                $z=$floor+$row*$storey+0.85;
                if($category==='industrial'){$z=max($floor+1.8,$height-1.4);$tall=0.7;}
                if($width<0.6||$tall<0.6||$z+$tall>$height-0.2||($hasDoor&&$row===0&&abs($along-$length/2)<($width+$doorWidth)/2+0.25))continue;
                $quad($along,$z-0.07,$width+0.14,$tall+0.14,0.018,$trim);$quad($along,$z,$width,$tall,0.025,$glass);
                if($category!=='industrial'){$quad($along,$z,0.035,$tall,0.032,$trim);$quad($along,$z+$tall/2-0.018,$width,0.035,0.032,$trim);}
                $openings++;
            }
        }
    }
}
