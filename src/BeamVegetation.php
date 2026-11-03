<?php
declare(strict_types=1);
namespace SweepLine;
final class BeamVegetation
{
    public const SHAPES=["tree"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_tree_01.dae","bush"=>"/levels/Utah/art/shapes/trees/Juniper/Juniper_bush_small_01.dae"];
    public const MATERIALS="/levels/Utah/art/shapes/trees/Juniper/main.materials.json";
    public static function write(array $placements,string $prefix,float $origin,callable $put): int
    {
        $definitions=[];$count=0;
        foreach(self::SHAPES as $kind=>$shape){
            $type=$prefix."_".$kind;
            $definitions[$type]=["name"=>$type,"internalName"=>$type,"class"=>"TSForestItemData","shapeFile"=>$shape,"windScale"=>0.4];
            $lines="";
            foreach($placements as $i=>$object)if($object["kind"]===$kind){$p=$object["position"];$angle=fmod($i*2.399963,2*M_PI);$c=cos($angle);$s=sin($angle);
                $lines.=json_encode(["ctxid"=>0,"type"=>$type,"pos"=>[$p[0],$p[1],$p[2]-$origin],"rotationMatrix"=>[$c,$s,0,-$s,$c,0,0,0,1],"scale"=>$object["scale"]],JSON_THROW_ON_ERROR)."\n";$count++;}
            $put("forest/".$type.".forest4.json",$lines);
        }
        $put("art/forest/managedItemData.json",json_encode($definitions,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        // Load installed stock materials before mission resources; no game assets are copied.
        $put("mainLevel.lua","local M = {}\nfunction M.onClientPreStartMission()\n  local materials = \"".self::MATERIALS."\"\n  if FS:fileExists(materials) then\n    loadJsonMaterialsFile(materials)\n  else\n    log(\"E\", \"SweepLine\", \"Utah vegetation materials are missing; BeamNG.drive 0.40 stock Utah assets are required.\")\n  end\nend\nreturn M\n");
        return $count;
    }
}
