<?php
declare(strict_types=1);
namespace SweepLine;

/** The same native layer IDs, palette tint and stock detail on replacement terrain. */
final class BeamGroundMaterials
{
    public static function material(string $name,int $layer,string $path,string $style,float $tile): array
    {
        $entry=BeamTerrain::PALETTE[$layer]??BeamTerrain::PALETTE[5];$stock=$style==='stock'&&$entry['prefix']!==null;$vegetation=$layer<2;
        $base=$style==='stock'&&$stock?'palette-'.$layer.'.color.png':($style==='stock'?'fallback.color.png':'aerial.color.png');
        $stage=['baseColorMap'=>$path.'art/terrains/'.$base,'baseColorFactor'=>[1,1,1,1],'normalMap'=>$path.'art/terrains/flat.normal.png','roughnessMap'=>$path.'art/terrains/roughness.data.png','roughnessFactor'=>1,'ambientOcclusionMap'=>$path.'art/terrains/ao.data.png','metallicFactor'=>0,'useAnisotropic'=>true];
        if($stock)$stage+=['detailMap'=>$entry['prefix'].'_b.png','detailBaseColorMapStrength'=>$vegetation?0.02:0.18,
            'detailNormalMap'=>$entry['prefix'].'_nm.png','detailNormalMapStrength'=>$vegetation?0.25:0.65,
            'roughnessDetailMap'=>$entry['prefix'].'_r.png','detailRoughnessMapStrength'=>0.25,
            'ambientOcclusionDetailMap'=>$entry['prefix'].'_ao.png','detailAoMapStrength'=>$vegetation?0.04:0.12,
            'detailScale'=>[1/$tile,1/$tile],'detailMapUV'=>1,'normalDetailMapUV'=>1];
        return ['class'=>'Material','name'=>$name,'mapTo'=>$name,'version'=>1.5,'doubleSided'=>true,'groundType'=>$style==='stock'?$entry['ground']:([0=>'GRASS',1=>'DIRT',2=>'ROCK',3=>'CONCRETE',4=>'DIRT'][$layer]??'DIRT'),'Stages'=>[$stage]];
    }
    public static function prepare(array $surface,string $layers,BeamRaster $raster,string $style,string $prefix,string $path,callable $progress): array
    {
        $step=$raster->side/$raster->size;$n=$raster->size;$vertices=[];$faces=[];$uv=[];$detail=[];$names=[];$materials=[];$lookup=[];$baseUv=[];$detailUv=[];
        $emit=static function(array $tri,int $layer)use(&$vertices,&$faces,&$uv,&$detail,&$names,&$materials,&$lookup,&$baseUv,&$detailUv,$prefix,$path,$style,$step,$raster){
            $faceIds=[];foreach($tri as $p){$key=implode(',',array_map(static fn($v)=>sprintf('%.7F',$v),$p));if(!isset($lookup[$key])){$lookup[$key]=count($vertices);$vertices[]=$p;}$faceIds[]=$lookup[$key];}$faces[]=$faceIds;
            $a=$tri[0];$b=$tri[1];$c=$tri[2];$u=[$b[0]-$a[0],$b[1]-$a[1],$b[2]-$a[2]];$v=[$c[0]-$a[0],$c[1]-$a[1],$c[2]-$a[2]];
            $normal=[abs($u[1]*$v[2]-$u[2]*$v[1]),abs($u[2]*$v[0]-$u[0]*$v[2]),abs($u[0]*$v[1]-$u[1]*$v[0])];$axis=array_search(max($normal),$normal,true);
            $faceUv=[];$faceDetail=[];
            foreach($faceIds as $id){$p=$vertices[$id];$baseUv[$id]??=[$p[0]/$raster->side,$p[1]/$raster->side];$detailUv[$axis][$id]??=match($axis){0=>[$p[1],$p[2]],1=>[$p[0],$p[2]],default=>[$p[0],$p[1]]};$faceUv[]=$baseUv[$id];$faceDetail[]=$detailUv[$axis][$id];}
            $uv[]=$faceUv;$detail[]=$faceDetail;
            $name=$prefix.'_'.$layer;$names[]=$name;$materials[$name]??=self::material($name,$layer,$path,$style,max(1,round(8/$step))*$step);
        };
        foreach($surface['faces'] as $i=>$face){
            if($i%100===0)$progress(null);$tri=array_map(static fn($id)=>$surface['vertices'][$id],$face);
            if(abs(BeamPolygon::cross(...$tri))<1e-9){
                $x=max(0,min($n-1,(int)floor(array_sum(array_column($tri,0))/3/$step)));$y=max(0,min($n-1,(int)floor(array_sum(array_column($tri,1))/3/$step)));
                $layer=ord($layers[$y*$n+$x]??chr(5));$emit($tri,$layer<count(BeamTerrain::PALETTE)?$layer:5);continue;
            }
            $cx=max(0,min($n-1,(int)floor(array_sum(array_column($tri,0))/3/$step)));$cy=max(0,min($n-1,(int)floor(array_sum(array_column($tri,1))/3/$step)));
            if(min(array_column($tri,0))>=$cx*$step-1e-6&&max(array_column($tri,0))<=($cx+1)*$step+1e-6&&min(array_column($tri,1))>=$cy*$step-1e-6&&max(array_column($tri,1))<=($cy+1)*$step+1e-6){
                $layer=ord($layers[$cy*$n+$cx]??chr(5));$emit($tri,$layer<count(BeamTerrain::PALETTE)?$layer:5);continue;
            }
            for($y=max(0,(int)floor(min(array_column($tri,1))/$step));$y<=min($n-2,(int)floor(max(array_column($tri,1))/$step));$y++)
            for($x=max(0,(int)floor(min(array_column($tri,0))/$step));$x<=min($n-2,(int)floor(max(array_column($tri,0))/$step));$x++){
                $cell=[[$x*$step,$y*$step],[($x+1)*$step,$y*$step],[($x+1)*$step,($y+1)*$step],[$x*$step,($y+1)*$step]];
                $layer=ord($layers[$y*$n+$x]??chr(5));if($layer>=count(BeamTerrain::PALETTE))$layer=5;
                foreach([[0,1,2],[0,2,3]] as $ids){
                    $poly=BeamTerrainContact::clip(array_map(static fn($p)=>array_slice($p,0,2),$tri),array_map(static fn($id)=>$cell[$id],$ids));
                    for($j=1;$j<count($poly)-1;$j++){
                        $out=[];foreach([$poly[0],$poly[$j],$poly[$j+1]] as $p){$w=BeamTerrainContact::weights($p,$tri);$z=0.;foreach($w as $k=>$weight)$z+=$weight*$tri[$k][2];$out[]=[$p[0],$p[1],$z];}
                        if(abs(BeamPolygon::cross(...$out))<1e-9)continue;
                        if(count($faces)>250000)throw new \RuntimeException('Replacement terrain material refinement exceeds its triangle budget.');
                        $emit($out,$layer);
                    }
                }
            }
        }
        return ['vertices'=>$vertices,'faces'=>$faces,'uv'=>$uv,'detail_uv'=>$detail,'face_materials'=>$names,'materials'=>$materials,
            'report'=>['triangles'=>count($faces),'layers'=>array_keys($materials),'method'=>'Native classification grid, shared palette/aerial tint, shared stock PBR detail with world-scale UVs']];
    }
    public static function write(array $surface,string $file,callable $put): array
    {
        $files=[];$part=0;
        foreach(array_chunk($surface['faces'],10000,true) as $faces){
            $name=$part?substr($file,0,-4).'-'.$part.'.dae':$file;
            $put($name,BeamAssets::mesh($surface['vertices'],$faces,array_key_first($surface['materials'])??'terrain',$surface['uv'],$surface['face_materials'],$surface['detail_uv']));
            $files[]=$name;$part++;
        }return $files;
    }
    public static function xml(array $surface): string
    {
        return BeamAssets::mesh($surface['vertices'],$surface['faces'],array_key_first($surface['materials'])??'terrain',$surface['uv'],$surface['face_materials'],$surface['detail_uv']);
    }
}
