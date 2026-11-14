<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamTerrain
{
    public const TEXTURE_SIZE=1024;
    public const PALETTE=[
        ["name"=>"grass","prefix"=>"/assets/materials/terrain/grass/t_grass_02/t_grass_02","ground"=>"GRASS","color"=>[99,126,63]],
        ["name"=>"forest_floor","prefix"=>"/assets/materials/terrain/grass/ind_grass/t_ind_grass","ground"=>"GRASS","color"=>[90,111,60]],
        ["name"=>"dry_soil","prefix"=>"/assets/materials/terrain/soil/t_dirt_sandy/t_dirt_sandy","ground"=>"DIRT","color"=>[178,148,106]],
        ["name"=>"gravel","prefix"=>"/assets/materials/terrain/soil/t_gravel/t_gravel","ground"=>"GRAVEL","color"=>[157,150,132]],
        ["name"=>"rock","prefix"=>"/assets/materials/terrain/rock/terrain_dirt_rocky_large/t_dirt_rocks_large","ground"=>"ROCK","color"=>[159,153,140]],
        ["name"=>"aerial_fallback","prefix"=>null,"ground"=>"DIRT"],
        ["name"=>"stone","prefix"=>"/assets/materials/terrain/rock/t_pebbles/t_pebbles","ground"=>"ROCK","color"=>[177,171,158]],
        ["name"=>"sand","prefix"=>"/assets/materials/terrain/sand/t_sand/t_sand","ground"=>"SAND","color"=>[204,185,141]],
    ];
    /** Bounded aerial color variation, independent of material classification and collision. */
    public static function blendedColor(int $id,array $rgb): array
    {
        $base=self::PALETTE[$id]["color"];
        [$r,$g,$b]=$rgb;$light=($r+$g+$b)/3;
        if($light<15||($light>225&&max($rgb)-min($rgb)<20)||($b>$r*1.2&&$b>$g*1.15))return $base;
        // Limit exposure influence: photographed shadows must not become black ground.
        $exposure=max(-25,min(25,($light-128)*0.32));$out=[];
        foreach($base as $channel=>$value){
            $tint=max(-36,min(36,$rgb[$channel]-$light))*0.65;
            $out[]=max((int)round($value*0.85),min(255,(int)round($value+$exposure+$tint)));
        }
        if($id<2)$out[1]=max($out[1],$out[0]+16,$out[2]+16);
        // Meet the unboosted aerial hue/chroma halfway, retaining the bounded
        // exposure above so photographed shadows do not make black terrain.
        $mean=array_sum($out)/3;
        foreach($out as $channel=>$value){
            $photo=max(0,min(255,$rgb[$channel]+$mean-$light));
            $out[$channel]=(int)round(($value+$photo)/2);
        }
        return $out;
    }
    /** Retain local ground color while softening photographed illumination over broad areas. */
    public static function shadeSample(array $local,array $broad): array {
        $light=array_sum($local)/3;$wide=array_sum($broad)/3;
        $shadow=$light<0.65*$wide&&$light<80;
        $weight=$shadow?0.6:0.25;
        $target=$light+max(-24,min(24,($wide-$light)*$weight));
        $out=[];
        foreach($local as $i=>$value){
            $chroma=$value-$light;
            if($shadow)$chroma=0.4*$chroma+0.6*($broad[$i]-$wide);
            $out[]=max(0,min(255,(int)round($target+$chroma)));
        }
        return $out;
    }
    /** North-up, multi-scale imagery shading shared by all material base textures. */
    public static function tintPixels(BeamRaster $raster,callable $progress,?BeamCompute $compute=null): string
    {
        if($compute&&$compute->workers()>1){
            $tasks=[];foreach($compute->rows(self::TEXTURE_SIZE) as $row)$tasks[]=$row+['kind'=>'tint_rows'];
            return implode('',$compute->map($tasks,['raster'=>$raster->snapshot()],static fn($task)=>self::tintRows($raster,$task['start'],$task['end'],$progress)));
        }
        return self::tintRows($raster,0,self::TEXTURE_SIZE,$progress);
    }
    public static function tintRows(BeamRaster $raster,int $start,int $end,callable $progress): string
    {
        $size=self::TEXTURE_SIZE;$pixels="";
        $broadRadius=max(1,(int)round(35/max(0.01,$raster->side/$raster->size)));
        for($y=$start;$y<$end;$y++){
            if($y%16===0)$progress(null);
            for($x=0;$x<$size;$x++){
                $sx=(int)($x/$size*$raster->size);$sy=$raster->size-1-(int)($y/$size*$raster->size);$rgb=[0,0,0];
                foreach([[0,0],[-1,0],[1,0],[0,-1],[0,1]] as [$dx,$dy]){
                    $sample=$raster->rgb($sx+$dx,$sy+$dy);foreach($sample as $channel=>$value)$rgb[$channel]+=$value;
                }
                $local=array_map(static fn($value)=>$value/5,$rgb);$broad=[0.,0.,0.];
                foreach([[-$broadRadius,0],[$broadRadius,0],[0,-$broadRadius],[0,$broadRadius]] as [$dx,$dy]){
                    $sample=$raster->rgb($sx+$dx,$sy+$dy);foreach($sample as $channel=>$value)$broad[$channel]+=$value/4;
                }
                foreach(self::shadeSample($local,$broad) as $value)$pixels.=chr($value);
            }
        }
        return $pixels;
    }
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
        // Vegetation evidence takes priority over slope: forested hills are not bare rock.
        $greenVotes=0;$canopyVotes=0;
        foreach($pixels as [$pr,$pg,$pb]){
            if($pg>$pr*1.015&&$pg>$pb*1.01)$greenVotes++;
            if(($pr+$pg+$pb)/3<100&&$pg>$pr*1.015&&$pb<$pg*1.35)$canopyVotes++;
        }
        if($canopyVotes/$n>=0.6&&($biome["montane"]>0.35||$brightness<65)&&$biome["tree_probability"]>0.55)return 1;
        if($greenVotes/$n>=0.6)return $brightness<100&&($biome["montane"]>0.35||$brightness<65)&&$biome["tree_probability"]>0.4?1:0;
        if($slope>0.5)return 4;
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
    public static function classify(BeamRaster $raster,array $center,array $buildings,callable $progress,?BeamCompute $compute=null): array
    {
        $size=$raster->size;$spacing=$raster->side/$size;
        if($compute&&$compute->workers()>1&&$size>=256){
            $tasks=[];foreach($compute->rows($size) as $row)$tasks[]=$row+['kind'=>'classify_rows'];
            $grid=implode('',$compute->map($tasks,['raster'=>$raster->snapshot(),'center'=>$center],static fn($task)=>self::classifyRows($raster,$center,$task['start'],$task['end'],$progress)));
        }else $grid=self::classifyRows($raster,$center,0,$size,$progress);
        if($compute&&$compute->workers()>1&&$size>=256){
            $tasks=[];foreach($compute->rows($size) as $row)$tasks[]=$row+['kind'=>'filter_rows'];
            $smooth=implode('',$compute->map($tasks,['grid'=>$grid,'size'=>$size],static fn($task)=>self::filterRows($grid,$size,$task['start'],$task['end'],$progress)));
        }else $smooth=self::filterRows($grid,$size,0,$size,$progress);
        // Mapped roofs are not terrain/stone evidence. Keep imagery underneath them.
        foreach($buildings as $building){$xs=array_column($building["points"],0);$ys=array_column($building["points"],1);if(!$xs)continue;
            for($y=max(0,(int)floor(min($ys)/$spacing));$y<=min($size-1,(int)floor(max($ys)/$spacing));$y++)for($x=max(0,(int)floor(min($xs)/$spacing));$x<=min($size-1,(int)floor(max($xs)/$spacing));$x++)$smooth[$y*$size+$x]=chr(5);
        }
        $counts=[];foreach(self::PALETTE as $id=>$entry)$counts[$entry["name"]]=substr_count($smooth,chr($id));
        return ["layers"=>$smooth,"counts"=>$counts,"analysis_resolution"=>$size,"cell_spacing_m"=>$spacing,"filter"=>"Full-resolution classification; isolated speckles only; no coarse-grid enlargement."];
    }
    public static function classifyRows(BeamRaster $raster,array $center,int $start,int $end,callable $progress): string
    {
        $size=$raster->size;$grid="";$spacing=$raster->side/$size;
        for($y=$start;$y<$end;$y++){
            if($y%8===0)$progress(null);
            for($x=0;$x<$size;$x++){
                $wx=($x+0.5)*$spacing;$wy=($y+0.5)*$spacing;$pixels=[];
                foreach([[0,0],[-1,0],[1,0],[0,-1],[0,1]] as [$dx,$dy])$pixels[]=$raster->rgb($x+$dx,$y+$dy);
                $h=$raster->roadHeight($wx,$wy);$slope=max(abs($raster->roadHeight(min($raster->side-1,$wx+$spacing),$wy)-$h),abs($raster->roadHeight($wx,min($raster->side-1,$wy+$spacing))-$h))/$spacing;
                $grid.=chr(self::category($pixels,$slope,$center,$h));
            }
        }
        return $grid;
    }
    /** Full input grid supplies the neighbor halo for every independently filtered row strip. */
    public static function filterRows(string $grid,int $size,int $start,int $end,callable $progress): string
    {
        $smooth=substr($grid,$start*$size,($end-$start)*$size);
        for($y=$start;$y<$end;$y++){
            if($y%8===0)$progress(null);
            for($x=0;$x<$size;$x++){
                $votes=array_fill(0,count(self::PALETTE),0);
                for($dy=-1;$dy<=1;$dy++)for($dx=-1;$dx<=1;$dx++)$votes[ord($grid[max(0,min($size-1,$y+$dy))*$size+max(0,min($size-1,$x+$dx))])]++;
                $current=ord($grid[$y*$size+$x]);$winner=array_search(max($votes),$votes,true);
                $smooth[($y-$start)*$size+$x]=chr($current!==5&&$votes[$current]<3&&$votes[$winner]>=7?$winner:$current);
            }
        }
        return $smooth;
    }
    public static function material(string $name,int $id,string $path,int $gridSize,float $step): array
    {
        $entry=self::PALETTE[$id];$fallback=$entry["prefix"]===null;$tile=max(1,(int)round(8/$step));
        $material=["class"=>"TerrainMaterial","name"=>$name,"internalName"=>$name,"groundmodelName"=>$entry["ground"],
            "detailDistances"=>[0,0,100,250],"macroDistances"=>[0,0,100,1000]];
        foreach(["baseColor"=>"_b.png","normal"=>"_nm.png","roughness"=>"_r.png","ao"=>"_ao.png","height"=>"_h.png"] as $slot=>$suffix){
            $file=match($slot){"baseColor"=>$fallback?"fallback.color.png":"palette-".$id.".color.png","normal"=>"flat.normal.png","ao"=>"ao.data.png",default=>"roughness.data.png"};
            $material[$slot."BaseTex"]=$path."art/terrains/".$file;
            $material[$slot."BaseTexSize"]=$gridSize;
            $neutral=match($slot){"baseColor"=>"neutral.color.png","normal"=>"neutral.normal.png",default=>"neutral.data.png"};
            $material[$slot."MacroTex"]=$path."art/terrains/".$neutral;$material[$slot."MacroTexSize"]=4;$material[$slot."MacroStrength"]=[0,0];
            $material[$slot."DetailTex"]=$fallback&&$slot==='normal'?self::PALETTE[2]['prefix'].'_nm.png':($fallback?$path."art/terrains/".($slot==="baseColor"?"detail.color.png":$file):$entry["prefix"].$suffix);
            $material[$slot."DetailTexSize"]=$tile;
            // Vegetation must retain its green base at full close-up detail strength.
            // Height detail must not change category blending as the camera approaches.
            $vegetation=$id<2;
            $strength=$fallback?match($slot){'baseColor'=>0.08,'normal'=>0.25,default=>0}:match($slot){"baseColor"=>$vegetation?0.02:0.18,"normal"=>$vegetation?0.25:0.65,"roughness"=>0.25,"ao"=>$vegetation?0.04:0.12,default=>0.0};
            $material[$slot."DetailStrength"]=[$strength,$strength];
        }
        return $material;
    }
}
