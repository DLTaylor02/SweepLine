<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamVegetation
{
    public const SHAPES=["tree"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_tree_01.dae","bush"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_bush_small_01.dae"];
    public const MATERIALS="/levels/Utah/art/shapes/trees/Juniper/main.materials.json";
    public const CATALOG=[
        "juniper_tree"=>["kind"=>"tree","map"=>"Utah","shape"=>self::SHAPES["tree"],"materials"=>self::MATERIALS,"radius"=>7.0],
        "juniper_bush"=>["kind"=>"bush","map"=>"Utah","shape"=>self::SHAPES["bush"],"materials"=>self::MATERIALS,"radius"=>1.2],
        "coastal_pine"=>["kind"=>"tree","map"=>"west_coast_usa","shape"=>"/levels/west_coast_usa/art/shapes/trees/trees_conifer/pine_radiata_small.dae","materials"=>"/levels/west_coast_usa/art/shapes/trees/trees_conifer/main.materials.json","radius"=>7.0],
        "beech_b"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_small_b.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>7.0],
        "beech_c"=>["kind"=>"tree","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/tree_beech_small_c.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/trees_beech/main.materials.json","radius"=>7.0],
        "eastern_bush"=>["kind"=>"bush","map"=>"east_coast_usa","shape"=>"/levels/east_coast_usa/art/shapes/trees/ECA_coast_bush/generibush_small.dae","materials"=>"/levels/east_coast_usa/art/shapes/trees/ECA_coast_bush/main.materials.json","radius"=>2.2],
        "desert_shrub"=>["kind"=>"bush","map"=>"johnson_valley","shape"=>"/levels/johnson_valley/art/shapes/trees/trees_shrub/shrub_a.dae","materials"=>"/levels/johnson_valley/art/shapes/trees/trees_shrub/main.materials.json","radius"=>4.6],
    ];
    public static function choose(string $kind,array $center,float $elevation,int $cover,float $variation): ?string
    {
        if(!isset(self::SHAPES[$kind]))return null;
        $east=($center[0]??-119)>-103;
        if($east)$pool=$kind==="tree"?["beech_b","beech_c"]:["eastern_bush"];
        elseif($kind==="tree")$pool=$elevation>1200||($center[1]??39)>38?["coastal_pine","coastal_pine","juniper_tree"]:["juniper_tree"];
        else $pool=$cover===0?["juniper_bush"]:["desert_shrub","juniper_bush"];
        return $pool[min(count($pool)-1,(int)floor(max(0,min(0.999999,$variation))*count($pool)))];
    }
    public static function used(array $placements): array
    {
        $used=[];foreach($placements as $p){$id=$p["asset"]??match($p["kind"]){"tree"=>"juniper_tree","bush"=>"juniper_bush",default=>null};if($id!==null)$used[$id]=self::CATALOG[$id];}return $used;
    }
    public static function write(array $placements,string $prefix,float $origin,callable $put): int
    {
        $definitions=[];$count=0;$used=self::used($placements);$materials=[];
        foreach($used as $id=>$asset){
            $type=$prefix."_".$id;$materials[$asset["materials"]]=true;
            $definitions[$type]=["name"=>$type,"internalName"=>$type,"class"=>"TSForestItemData","shapeFile"=>$asset["shape"],"windScale"=>0.4];$lines="";
            foreach($placements as $i=>$object){$objectAsset=$object["asset"]??match($object["kind"]){"tree"=>"juniper_tree","bush"=>"juniper_bush",default=>null};if($objectAsset!==$id)continue;
                $p=$object["position"];$angle=fmod($i*2.399963,2*M_PI);$c=cos($angle);$s=sin($angle);
                $lines.=json_encode(["ctxid"=>0,"type"=>$type,"pos"=>[$p[0],$p[1],$p[2]-$origin],"rotationMatrix"=>[$c,$s,0,-$s,$c,0,0,0,1],"scale"=>$object["scale"]],JSON_THROW_ON_ERROR)."\n";$count++;}
            $put("forest/".$type.".forest4.json",$lines);
        }
        $put("art/forest/managedItemData.json",json_encode((object)$definitions,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        $paths=implode(",\n",array_map(static fn($s)=>json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),array_keys($materials)));
        $put("mainLevel.lua","local M = {}\nfunction M.onClientPreStartMission()\n  local sources = {".$paths."}\n  for _, materials in ipairs(sources) do\n    if FS:fileExists(materials) then\n      loadJsonMaterialsFile(materials)\n    else\n      log(\"E\", \"SweepLine\", \"Stock vegetation materials are missing: \" .. materials)\n    end\n  end\nend\nreturn M\n");
        return $count;
    }
}
