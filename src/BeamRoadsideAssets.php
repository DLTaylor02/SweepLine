<?php
declare(strict_types=1);
namespace SweepLine;
/** Base-game references inspected in BeamNG.drive 0.40; models are never copied into ZIPs. */
final class BeamRoadsideAssets {
    public const COMMON=[
        '/art/shapes/common/common.materials.json','/art/shapes/common/materials/main.materials.json',
        '/art/shapes/common/power_lines_procedural/main.materials.json','/art/shapes/objects/main.materials.json','/art/shapes/objects/m_metal_new_01/main.materials.json',
        '/art/shapes/garage_and_dealership/main.materials.json','/art/shapes/garage_and_dealership/Clutter/main.materials.json',
        '/art/shapes/garage_and_dealership/garage/main.materials.json',
    ];
    public const CATALOG=[
        'wood_pole'=>['shape'=>'/art/shapes/common/power_lines_procedural/electric_pole_wood_old_01.dae','height'=>9.96,'radius'=>.8,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>[]],
        'metal_pole'=>['shape'=>'/art/shapes/common/power_lines_procedural/metal_pole_indus_old_01.dae','height'=>8.09,'radius'=>.7,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>[]],
        'power_tower'=>['shape'=>'/levels/west_coast_usa/art/shapes/objects/pylon.dae','height'=>29.91,'radius'=>4.3,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/west_coast_usa/art/shapes/buildings/main.materials.json','/levels/west_coast_usa/art/shapes/main.materials.json']],
        'streetlight'=>['shape'=>'/levels/east_coast_usa/art/shapes/buildings/eca_lamppost.dae','height'=>8.21,'radius'=>3.,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/east_coast_usa/art/shapes/buildings/main.materials.json']],
        'bench'=>['shape'=>'/levels/east_coast_usa/art/shapes/clutter/clutter_city_bench_wood.dae','height'=>.85,'radius'=>1.08,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/east_coast_usa/art/shapes/buildings/main.materials.json']],
        'bollard'=>['shape'=>'/art/shapes/objects/bollard_yellow.dae','height'=>1.67,'radius'=>.2,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>[]],
        'concrete_bollard'=>['shape'=>'/levels/east_coast_usa/art/shapes/clutter/clutter_city_bollard_concrete.dae','height'=>1.095,'radius'=>.39,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/east_coast_usa/art/shapes/buildings/main.materials.json']],
        'bus_stop'=>['shape'=>'/art/shapes/objects/s_sign_busstop.dae','height'=>3.60,'radius'=>.55,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>[]],
        'bus_shelter'=>['shape'=>'/art/shapes/objects/s_busstop_ecu.dae','height'=>2.70,'radius'=>1.8,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>[]],
        'shelter'=>['shape'=>'/levels/west_coast_usa/art/shapes/buildings/shelter_industrial.dae','height'=>5.10,'radius'=>6.1,'center_x'=>0.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/west_coast_usa/art/shapes/buildings/main.materials.json']],
        'metal_fence'=>['shape'=>'/levels/west_coast_usa/art/shapes/buildings/s_port_ppa_fence_bars_2m.dae','height'=>1.,'length'=>2.,'radius'=>1.01,'center_x'=>1.,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/west_coast_usa/art/shapes/buildings/main.materials.json']],
        'chainlink_fence'=>['shape'=>'/levels/Industrial/art/shapes/buildings/ind_mesh_fence_2m.dae','height'=>2.86,'length'=>2.083,'radius'=>1.1,'center_x'=>-.0047,'center_y'=>0.,'base_z'=>0.,'libraries'=>['/levels/Industrial/art/shapes/buildings/main.materials.json']],
    ];
    public static function point(string $kind,array $tags):?string{
        $material=strtolower((string)($tags['material']??''));
        return match($kind){
            'power_pole'=>in_array($material,['concrete','stone'],true)?null:(in_array($material,['metal','steel','aluminium'],true)?'metal_pole':'wood_pole'),
            'power_tower','streetlight','bus_stop'=>$kind,
            'bench'=>in_array($material,['metal','steel','concrete','stone'],true)?null:'bench',
            'bollard'=>$material==='concrete'?'concrete_bollard':'bollard',
            'shelter'=>($tags['shelter_type']??'')==='public_transport'?'bus_shelter':'shelter',
            default=>null,
        };
    }
    public static function fence(array $tags):?string{
        $type=$tags['fence_type']??'';$material=$tags['material']??'';
        if(in_array($material,['wood','stone','concrete'],true)||in_array($type,['wood','split_rail','barbed_wire','electric','wire'],true))return null;
        return in_array($type,['chain_link','chain-link'],true)?'chainlink_fence':'metal_fence';
    }
    public static function object(string $asset,string $name,array $point,float $height,float $yaw,float $length=0):?array{
        $a=self::CATALOG[$asset];$z=$height/$a['height'];$x=$length>0&&isset($a['length'])?$length/$a['length']:$z;
        if($z<.4||$z>2||$x<.4||$x>2)return null;
        $y=$length>0?1.:$z;$c=cos($yaw);$s=sin($yaw);
        return ['class'=>'TSStatic','name'=>$name,'__parent'=>'MissionGroup','shapeName'=>$a['shape'],
            'position'=>[$point[0]-$c*$a['center_x']*$x+$s*$a['center_y']*$y,$point[1]-$s*$a['center_x']*$x-$c*$a['center_y']*$y,$point[2]-$a['base_z']*$z],
            'rotationMatrix'=>[$c,$s,0,-$s,$c,0,0,0,1],'scale'=>[$x,$y,$z],'collisionType'=>'None','decalType'=>'None','castShadows'=>true];
    }
    public static function libraries(string $asset):array{return array_values(array_unique(array_merge(self::COMMON,self::CATALOG[$asset]['libraries'])));}
}
