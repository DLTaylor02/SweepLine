<?php
declare(strict_types=1);
namespace SweepLine;

/** A map-local visual correction, not a geology estimate. */
final class BeamRockPalette
{
    public static function stockAsset(array $rgb): string
    {
        return self::factor([$rgb])===[1,1,1]?'utah_rock':'neutral_rock';
    }
    public static function factor(array $colors): array
    {
        if(!$colors)return [1,1,1];
        $rgb=[0,0,0];foreach($colors as $color)foreach($color as $i=>$v)$rgb[$i]+=$v/count($colors);
        [$r,$g,$b]=$rgb;
        if($r>$g*1.16&&$g>$b*1.12)return [1,1,1]; // Evidence supports warm red rock.
        $target=$r>$g*1.07?[151,140,122]:[142,143,139];
        return array_map(static fn($v,$reference)=>round(max(0.6,min(1.7,$v/$reference)),3),$target,[190,120,85]);
    }
    public static function lua(array $factor): string
    {
        $color=implode(' ',array_merge($factor,[1]));
        return "local savedRockFactor = nil\n"
            ."local function restoreRockPalette()\n  local mat = scenetree.findObject('utah_rocks_b')\n  if mat and savedRockFactor then mat:setField('baseColorFactor', 0, savedRockFactor); mat:reload() end\n  savedRockFactor = nil\nend\n"
            ."function M.onClientEndMission() restoreRockPalette() end\n"
            ."function M.onClientPostStartMission()\n  local mat = scenetree.findObject('utah_rocks_b')\n  if mat then\n    if not savedRockFactor then savedRockFactor = mat:getField('baseColorFactor', 0) end\n    mat:setField('baseColorFactor', 0, '".$color."'); mat:reload()\n  end\nend\n";
    }
}
