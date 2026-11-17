<?php
declare(strict_types=1);
namespace SweepLine;

/** Small, private, allowlisted snapshots for deterministic local terrain-clearance replay. */
final class BeamTerrainDiagnostic
{
    public static function capture(array $prepared,BeamRaster $raster,float $origin,array $hit,array $repair): array
    {
        $n=$raster->size;$step=$raster->side/$n;$gx=(int)floor($hit['point'][0]/$step);$gy=(int)floor($hit['point'][1]/$step);
        $x0=max(0,$gx-9);$x1=min($n-1,$gx+9);$y0=max(0,$gy-9);$y1=min($n-1,$gy+9);
        $surfaces=[];foreach($prepared['main_surfaces']??[] as $s)$surfaces[]=['name'=>$s['name'],'road'=>$s['points'],'profiles'=>$s['profiles'],'spans'=>$s['spans']??[]];
        foreach($prepared['roads'] as $s)$surfaces[]=['name'=>$s['name'],'osm_id'=>$s['osm_id']??null,'road'=>$s['points'],'profiles'=>$s['profiles'],'spans'=>array_merge($s['bridges']??[],$s['tunnels']??[])];
        $exact=BeamTerrainContact::structureMask($surfaces,$raster,static fn($m)=>null);$broad=$prepared['structure_mask']??'';
        $heights=[];$flags=[];$broadFlags=[];
        for($y=$y0;$y<=$y1;$y++){$h=[];$f=[];$bf=[];for($x=$x0;$x<=$x1;$x++){$h[]=$raster->cell($x,$y);$f[]=(int)(($exact[$y*$n+$x]??"\0")!=="\0");$bf[]=(int)(($broad[$y*$n+$x]??"\0")!=="\0");}$heights[]=$h;$flags[]=$f;$broadFlags[]=$bf;}
        $bounds=[($x0+1)*$step,($y0+1)*$step,($x1-1)*$step,($y1-1)*$step];[$w,$s,$e,$north]=$bounds;
        $rectangle=[[[$w,$s],[$e,$s],[$e,$north]],[[$w,$s],[$e,$north],[$w,$north]]];$triangles=[];$truncated=false;
        foreach($prepared['roads'] as $b)if($b['name']===$hit['name']){
            foreach(BeamPavement::drivingTriangles($b,$prepared['main_surfaces']??[]) as $tri){
                if(max(array_column($tri,0))<$w||min(array_column($tri,0))>$e||max(array_column($tri,1))<$s||min(array_column($tri,1))>$north)continue;
                foreach($rectangle as $cut){$poly=BeamTerrainContact::clip($tri,$cut);$poly=array_map(static function($p)use($tri){$w=BeamTerrainContact::weights($p,$tri);$z=0.;foreach($w as $k=>$v)$z+=$v*$tri[$k][2];return [$p[0],$p[1],$z];},$poly);for($i=1;$i<count($poly)-1;$i++){$triangles[]=[$poly[0],$poly[$i],$poly[$i+1]];if(count($triangles)>=5000){$truncated=true;break 4;}}}
            }
        }
        $structures=[];$segments=0;
        foreach($surfaces as $surface){$along=0.;$near=[];
            foreach($surface['road'] as $i=>$p){if(!$i)continue;$a=$surface['road'][$i-1];$start=$along;$along+=hypot($p[0]-$a[0],$p[1]-$a[1]);
                if(max($a[0],$p[0])<$x0*$step||min($a[0],$p[0])>$x1*$step||max($a[1],$p[1])<$y0*$step||min($a[1],$p[1])>$y1*$step)continue;
                if(++$segments>5000){$truncated=true;break;}
                $near[]=['a'=>$a,'b'=>$p,'start_m'=>$start,'end_m'=>$along,'width_a_m'=>$surface['profiles'][$i-1]['width_m'],'width_b_m'=>$surface['profiles'][$i]['width_m']];
            }
            if($near)$structures[]=['name'=>$surface['name'],'osm_id'=>$surface['osm_id']??null,'spans'=>$surface['spans'],'segments'=>$near];
        }
        return ['schema'=>1,'application_version'=>Version::number(),'captured_at'=>gmdate('c'),'hit'=>$hit,'origin_m'=>$origin,
            'raster'=>['size'=>$n,'side_m'=>$raster->side,'spacing_m'=>$step,'x0'=>$x0,'y0'=>$y0,'heights_m'=>$heights,'exact_protected'=>$flags,'broad_protected'=>$broadFlags],
            'visible_pavement_triangles'=>$triangles,'nearby_surfaces'=>$structures,'contact_report'=>$repair,'truncated'=>$truncated];
    }
    public static function write(string $path,array $capture,array $plan,string $message): void
    {
        $capture['selection']=['name'=>$plan['name']??null,'epsg'=>$plan['epsg']??null,'bounds'=>$plan['bounds']??null,'orientation'=>$plan['orientation']??null,
            'start'=>$plan['points'][0]??null,'finish'=>!empty($plan['points'])?end($plan['points']):null];
        $capture['error']=$message;
        $json=json_encode($capture,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if(strlen($json)>8388608)throw new \RuntimeException('Terrain diagnostic exceeds its size limit.');
        $zip=new \ZipArchive();if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('Cannot create terrain diagnostic.');
        try{
            if(!$zip->addFromString('diagnostic.json',$json)||!$zip->addFromString('README.txt',"SweepLine terrain-clearance diagnostic\nContains the selected road location, local pavement/terrain geometry, protection flags and nearby structure spans. No application configuration or access tokens.\nReplay: php bin/replay-terrain-diagnostic.php this-file.zip\n"))throw new \RuntimeException('Cannot write terrain diagnostic.');
        }finally{$zip->close();}
        chmod($path,0600);
    }
    public static function replay(array $data): ?array
    {
        if(($data['schema']??null)!==1||!empty($data['truncated']))throw new \InvalidArgumentException('Unsupported or truncated diagnostic.');
        $r=$data['raster'];$n=(int)$r['size'];if($n<2||$n>2048||!is_finite((float)$r['side_m'])||$r['side_m']<=0)throw new \InvalidArgumentException('Invalid diagnostic raster size.');
        if($r['x0']<0||$r['y0']<0||count($r['heights_m'])>32||$r['y0']+count($r['heights_m'])>$n||count($data['visible_pavement_triangles'])>5000)throw new \InvalidArgumentException('Invalid diagnostic window.');
        foreach($r['heights_m'] as $row)if(count($row)>32||$r['x0']+count($row)>$n)throw new \InvalidArgumentException('Invalid diagnostic window.');
        $flat=str_repeat(pack('g',0),$n*$n);$raster=new BeamRaster($n,(float)$r['side_m'],$flat,str_repeat("\0",$n*$n*3));
        foreach($r['heights_m'] as $y=>$row)foreach($row as $x=>$height)$raster->put($r['x0']+$x,$r['y0']+$y,(float)$height);
        return BeamTerrainContact::obstruction($data['visible_pavement_triangles'],$raster,(float)$data['origin_m']);
    }
}
