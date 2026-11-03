<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamTerrain
{
    public const TEXTURE_SIZE=1024;
    public const PALETTE=[
        ["name"=>"grass","prefix"=>"/assets/materials/terrain/grass/t_grass_02/t_grass_02","ground"=>"GRASS"],
        ["name"=>"forest_floor","prefix"=>"/assets/materials/terrain/soil/t_dirt_dry_grassy/t_dirt_dry_grassy","ground"=>"GRASS"],
        ["name"=>"dry_soil","prefix"=>"/assets/materials/terrain/soil/dirt_crumbly/t_dirt_crumbly","ground"=>"DIRT"],
        ["name"=>"gravel","prefix"=>"/assets/materials/terrain/soil/t_gravel/t_gravel","ground"=>"GRAVEL"],
        ["name"=>"rock","prefix"=>"/assets/materials/terrain/rock/t_dirt_rocky/t_dirt_rocky","ground"=>"ROCK"],
        ["name"=>"aerial_fallback","prefix"=>null,"ground"=>"DIRT"],
        ["name"=>"stone","prefix"=>"/assets/materials/terrain/rock/t_pebbles/t_pebbles","ground"=>"ROCK"],
        ["name"=>"sand","prefix"=>"/assets/materials/terrain/sand/t_sand/t_sand","ground"=>"SAND"],
    ];
    public static function paths(): array
    {
        $paths=[];foreach(self::PALETTE as $entry)if($entry["prefix"]!==null)foreach(["_b.png","_nm.png","_r.png","_ao.png","_h.png"] as $suffix)$paths[]=$entry["prefix"].$suffix;return $paths;
    }
    public static function category(array $pixels,float $slope,array $center,float $elevation): int
    {
        $r=0;$g=0;$b=0;foreach($pixels as $p){$r+=$p[0];$g+=$p[1];$b+=$p[2];}$n=max(1,count($pixels));$r/=$n;$g/=$n;$b/=$n;$brightness=($r+$g+$b)/3;
        $chroma=max($r,$g,$b)-min($r,$g,$b);
        if(($b>$r*1.2&&$b>$g*1.15)||($brightness>225&&$chroma<18)||$brightness<12)return 5;
        $biome=BeamBiome::context($center,$elevation,$pixels);
        if($slope>0.5)return 4;
        if($g>$r*1.04&&$g>$b*1.02)return $brightness<100&&($biome["montane"]>0.35||$brightness<65)&&$biome["tree_probability"]>0.4?1:0;
        if($brightness<85&&$g>$r*1.01&&$g>$b*0.98&&$biome["tree_probability"]>0.55)return 1;
        // Warm pale ground is a sand candidate, not automatically white/snow fallback.
        if($brightness>=130&&$r>$b*1.10&&$g>$b*1.04&&$r>=$g*0.98&&$slope<0.3)return 7;
        if($r>$g*1.08&&$g>$b*1.05)return $slope>0.3?4:2;
        $luma=array_map(static fn($p)=>array_sum($p)/3,$pixels);$texture=max($luma)-min($luma);
        if($chroma<45&&$brightness>40){
            if($slope>0.25||($brightness>165&&$slope>0.04))return 6;
            if($slope>0.04||($texture>22&&$brightness>90))return 3;
        }
        return 5;
    }
    public static function classify(BeamRaster $raster,array $center,array $buildings,callable $progress): array
    {
        $size=$raster->size;$grid="";$spacing=$raster->side/$size;
        for($y=0;$y<$size;$y++){
            if($y%8===0)$progress(null);
            for($x=0;$x<$size;$x++){$wx=($x+0.5)*$spacing;$wy=($y+0.5)*$spacing;$pixels=[];
                foreach([[0,0],[-1,0],[1,0],[0,-1],[0,1]] as [$dx,$dy])$pixels[]=$raster->rgb($x+$dx,$y+$dy);
                $h=$raster->roadHeight($wx,$wy);$slope=max(abs($raster->roadHeight(min($raster->side-1,$wx+$spacing),$wy)-$h),abs($raster->roadHeight($wx,min($raster->side-1,$wy+$spacing))-$h))/$spacing;
                $grid.=chr(self::category($pixels,$slope,$center,$h));
            }
        }
        $smooth=$grid;
        for($y=0;$y<$size;$y++){
            if($y%8===0)$progress(null);
            for($x=0;$x<$size;$x++){
            $votes=array_fill(0,count(self::PALETTE),0);for($dy=-1;$dy<=1;$dy++)for($dx=-1;$dx<=1;$dx++)$votes[ord($grid[max(0,min($size-1,$y+$dy))*$size+max(0,min($size-1,$x+$dx))])]++;
            $current=ord($grid[$y*$size+$x]);$winner=array_search(max($votes),$votes,true);
            $smooth[$y*$size+$x]=chr($current!==5&&$votes[$current]<3&&$votes[$winner]>=7?$winner:$current);
        }
        }
        // Mapped roofs are not terrain/stone evidence. Keep imagery underneath them.
        foreach($buildings as $building){$xs=array_column($building["points"],0);$ys=array_column($building["points"],1);if(!$xs)continue;
            for($y=max(0,(int)floor(min($ys)/$spacing));$y<=min($size-1,(int)floor(max($ys)/$spacing));$y++)for($x=max(0,(int)floor(min($xs)/$spacing));$x<=min($size-1,(int)floor(max($xs)/$spacing));$x++)$smooth[$y*$size+$x]=chr(5);
        }
        $counts=[];foreach(self::PALETTE as $id=>$entry)$counts[$entry["name"]]=substr_count($smooth,chr($id));
        return ["layers"=>$smooth,"counts"=>$counts,"analysis_resolution"=>$size,"cell_spacing_m"=>$spacing,"filter"=>"Full-resolution classification; isolated speckles only; no coarse-grid enlargement."];
    }
    public static function material(string $name,int $id,string $path,int $gridSize,float $step): array
    {
        $entry=self::PALETTE[$id];$fallback=$entry["prefix"]===null;$tile=max(1,(int)round(8/$step));
        $material=["class"=>"TerrainMaterial","name"=>$name,"internalName"=>$name,"groundmodelName"=>$entry["ground"]];
        foreach(["baseColor"=>"_b.png","normal"=>"_nm.png","roughness"=>"_r.png","ao"=>"_ao.png","height"=>"_h.png"] as $slot=>$suffix){
            $file=$fallback?match($slot){"baseColor"=>"fallback.color.png","normal"=>"flat.normal.png","ao"=>"ao.data.png",default=>"roughness.data.png"}:null;
            $material[$slot."BaseTex"]=$fallback?$path."art/terrains/".$file:$entry["prefix"].$suffix;
            $material[$slot."BaseTexSize"]=$fallback?$gridSize:$tile;
            $neutral=match($slot){"baseColor"=>"neutral.color.png","normal"=>"neutral.normal.png",default=>"neutral.data.png"};
            foreach(["Macro","Detail"] as $level){$material[$slot.$level."Tex"]=$path."art/terrains/".$neutral;$material[$slot.$level."TexSize"]=4;$material[$slot.$level."Strength"]=[0,0];}

        }
        return $material;
    }
}
