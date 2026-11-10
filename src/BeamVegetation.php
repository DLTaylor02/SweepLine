<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamVegetation
{
    public const SHAPES=["rock"=>"/levels/Utah/art/shapes/rocks/utah_boulder_1.dae","tree"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_tree_01.dae","bush"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_bush_small_01.dae"];
    public const MATERIALS="/levels/Utah/art/shapes/trees/Juniper/main.materials.json";
    public const CATALOG=[
        "green_grass"=>["kind"=>"grass","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/groundcover/3d_grass_test/s_grass_green_short_01.dae","materials"=>"/levels/east_coast_usa/art/shapes/groundcover/3d_grass_test/main.materials.json","radius"=>0.65],
        "tall_grass"=>["kind"=>"grass","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/groundcover/3d_grass_test/s_grass_green_long_01.dae","materials"=>"/levels/east_coast_usa/art/shapes/groundcover/3d_grass_test/main.materials.json","radius"=>0.7],
        "dry_grass"=>["kind"=>"grass","map"=>"west_coast_usa","shape"=>"/levels/west_coast_usa/art/shapes/trees/grassclump_large.dae","materials"=>"/levels/west_coast_usa/art/shapes/trees/main.materials.json","radius"=>1.4],
        "utah_rock"=>["kind"=>"rock","map"=>"Utah","shape"=>self::SHAPES["rock"],"materials"=>"/levels/Utah/art/shapes/rocks/main.materials.json","radius"=>1.7],
        "juniper_tree"=>["kind"=>"tree","map"=>"Utah","shape"=>self::SHAPES["tree"],"materials"=>self::MATERIALS,"radius"=>7.0],
        "juniper_bush"=>["kind"=>"bush","map"=>"Utah","shape"=>self::SHAPES["bush"],"materials"=>self::MATERIALS,"radius"=>1.2],
        "coastal_pine"=>["kind"=>"tree","map"=>"west_coast_usa","shape"=>"/levels/west_coast_usa/art/shapes/trees/trees_conifer/pine_radiata_small.dae","materials"=>"/levels/west_coast_usa/art/shapes/trees/trees_conifer/main.materials.json","radius"=>7.0],
        "beech_b"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_small_b.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>7.0],
        "beech_c"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_small_c.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>7.0],
        "beech_forest"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_forest_a.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>4.0],
        "beech_group"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_forest_group.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>7.0],
        "eastern_bush"=>["kind"=>"bush","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/ECA_coast_bush/generibush_small.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/ECA_coast_bush/main.materials.json","radius"=>2.2],
        "desert_shrub"=>["kind"=>"bush","map"=>"johnson_valley","shape"=>"/levels/johnson_valley/art/shapes/trees/trees_shrub/shrub_a.dae","materials"=>"/levels/johnson_valley/art/shapes/trees/trees_shrub/main.materials.json","radius"=>4.6],
    ];
    public static function choose(string $kind,array $center,float $elevation,int $cover,float $variation,?array $biome=null): ?string
    {
        if($kind==="rock"){
            $western=($center[0]??-119)<=-103;
            $bare=in_array($cover,[1,2,3],true);
            return $western&&$bare&&($biome===null||($biome['canopy_evidence']<0.4&&$biome['tree_probability']<0.3))?'utah_rock':null;
        }
        if(!isset(self::SHAPES[$kind]))return null;
        if($kind==="tree"&&($center[0]??-119)>-103&&$biome!==null&&$biome['canopy_evidence']>=0.65&&($biome['edge_distance_m']??0)>=20&&$biome['montane']<=0.35)return $variation<0.45?'beech_forest':'beech_group';
        if($kind==="tree" && $biome!==null && $biome["montane"]>0.35)return $variation<$biome["pine_probability"]?"coastal_pine":(($center[0]??-119)<=-103?"juniper_tree":"beech_b");
        $east=($center[0]??-119)>-103;
        if($east)$pool=$kind==="tree"?["beech_b","beech_c"]:["eastern_bush"];
        elseif($kind==="tree")$pool=$elevation>1200||($center[1]??39)>38?["coastal_pine","coastal_pine","juniper_tree"]:["juniper_tree"];
        else $pool=$cover===0?["juniper_bush"]:["desert_shrub","juniper_bush"];
        return $pool[min(count($pool)-1,(int)floor(max(0,min(0.999999,$variation))*count($pool)))];
    }
    public static function used(array $placements): array
    {
        $used=[];foreach($placements as $p){$id=$p["asset"]??match($p["kind"]){"tree"=>"juniper_tree","bush"=>"juniper_bush","rock"=>"utah_rock",default=>null};if($id!==null)$used[$id]=self::CATALOG[$id];}return $used;
    }
    public static function materialLibraries(array $assets): array
    {
        $paths=[];
        // Shared parent materials include leaves_strong/leaves_thin, which are
        // referenced by stock shapes but absent from species-specific libraries.
        foreach($assets as $asset)$paths['/levels/'.$asset['map'].'/art/shapes/trees/main.materials.json']=true;
        foreach($assets as $asset)$paths[$asset['materials']]=true;
        return array_keys($paths);
    }
    public static function write(array $placements,string $prefix,float $origin,callable $put,array $terrainTextures=[],array $libraries=[]): int
    {
        $definitions=[];$count=0;$used=self::used($placements);$materials=array_fill_keys(array_merge(self::materialLibraries($used),$libraries),true);
        foreach($used as $id=>$asset){
            $type=$prefix."_".$id;$materials[$asset["materials"]]=true;
            $definitions[$type]=["name"=>$type,"internalName"=>$type,"class"=>"TSForestItemData","shapeFile"=>$asset["shape"],"windScale"=>$asset["kind"]==="grass"?0.7:0.4,"collidable"=>$asset["kind"]!=="grass"];$lines="";
            foreach($placements as $i=>$object){$objectAsset=$object["asset"]??match($object["kind"]){"tree"=>"juniper_tree","bush"=>"juniper_bush","rock"=>"utah_rock",default=>null};if($objectAsset!==$id)continue;
                $p=$object["position"];$angle=fmod($i*2.399963,2*M_PI);$c=cos($angle);$s=sin($angle);
                $lines.=json_encode(["ctxid"=>0,"type"=>$type,"pos"=>[$p[0],$p[1],$p[2]-$origin],"rotationMatrix"=>[$c,$s,0,-$s,$c,0,0,0,1],"scale"=>$object["scale"]],JSON_THROW_ON_ERROR)."\n";$count++;}
            $put("forest/".$type.".forest4.json",$lines);
        }
        $put("art/forest/managedItemData.json",json_encode((object)$definitions,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $paths=implode(",\n",array_map(static fn($s)=>json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),array_keys($materials)));
        $shapes=implode(",\n",array_map(static fn($path)=>json_encode($path,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),array_merge(array_column(array_values($used),"shape"),$terrainTextures)));
        $rockColors=[];foreach($placements as $p)if(($p['asset']??null)==='utah_rock'&&isset($p['ground_rgb']))$rockColors[]=$p['ground_rgb'];
        $rockLua=isset($used['utah_rock'])?BeamRockPalette::lua(BeamRockPalette::factor($rockColors)):"";
        $put("mainLevel.lua","local M = {}\nfunction M.onClientPreStartMission()\n  local sources = {".$paths."}\n  local shapes = {".$shapes."}\n  local missing = false\n  for _, materials in ipairs(sources) do\n    if FS:fileExists(materials) then loadJsonMaterialsFile(materials) else missing = true; log(\"E\", \"SweepLine\", \"Missing stock materials: \" .. materials) end\n  end\n  for _, shape in ipairs(shapes) do\n    if not FS:fileExists(shape) then missing = true; log(\"E\", \"SweepLine\", \"Missing stock vegetation: \" .. shape) end\n  end\n  if missing then guihooks.trigger(\"toastrMsg\", {type=\"error\", title=\"SweepLine vegetation\", msg=\"Required stock meshes or materials are missing. Check the game log and export report.\"}) end\nend\n".$rockLua."return M\n");
        return $count;
    }
}
