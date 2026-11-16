<?php
declare(strict_types=1);
namespace SweepLine;

/** Optional photo-informed estimates, never photographic facade reconstruction. */
final class BeamBuildingPhotos
{
    public const FIELDS='id,geometry,computed_geometry,compass_angle,computed_compass_angle,camera_type,thumb_1024_url,captured_at,creator';
    public const MAX_BUILDINGS=100;
    public const MAX_IMAGES_PER_AREA=300;

    public static function thumbnailUrl(mixed $url): string
    {
        if(!is_string($url)||strlen($url)>8192)throw new \InvalidArgumentException('Invalid imagery URL.');
        $p=parse_url($url);$host=strtolower($p['host']??'');
        if(($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||($p['port']??443)!==443||
            !preg_match('/(?:^|\\.)(?:fbcdn\\.net|mapillary\\.com|fbsbx\\.com)$/D',$host))
            throw new \InvalidArgumentException('Unsupported imagery host.');
        return $url;
    }
    private static function creator(string $name): string {preg_match('/^.{0,120}/us',$name,$m);return $m[0]??'Unknown contributor';}
    public static function metadata(array $data): array
    {
        if(!is_array($data['data']??null)||isset($data['error']))throw new DownloadFailure('Mapillary imagery metadata unavailable.',false);
        $out=[];
        foreach(array_slice($data['data'],0,self::MAX_IMAGES_PER_AREA) as $r){
            if(!is_array($r)||!preg_match('/^[0-9]{1,24}$/D',(string)($r['id']??'')))continue;
            $p=$r['computed_geometry']['coordinates']??$r['geometry']['coordinates']??[];
            if(count($p)!==2||!is_numeric($p[0])||!is_numeric($p[1])||!is_finite((float)$p[0])||!is_finite((float)$p[1])||abs((float)$p[0])>180||abs((float)$p[1])>90)continue;
            try{$url=self::thumbnailUrl($r['thumb_1024_url']??null);}catch(\InvalidArgumentException $e){continue;}
            $heading=$r['computed_compass_angle']??$r['compass_angle']??null;
            if(!is_numeric($heading)||!is_finite((float)$heading))continue;
            $creator=$r['creator']['username']??$r['creator']['id']??'Unknown contributor';
            $out[]=['id'=>(string)$r['id'],'location'=>array_map('floatval',$p),'heading'=>(float)$heading,
                'camera_type'=>$r['camera_type']??'unknown','url'=>$url,'captured_at'=>is_numeric($r['captured_at']??null)?(int)$r['captured_at']:null,
                'creator'=>is_scalar($creator)?self::creator((string)$creator):'Unknown contributor'];
        }
        $after=$data['paging']['cursors']['after']??$data['after']??null;
        return ['data'=>$out,'has_next'=>!empty($data['paging']['next'])||!empty($data['has_next']),
            'after'=>is_string($after)&&strlen($after)<=2048?$after:null];
    }
    private static function retry(callable $work,callable $progress,?callable $sleep=null): mixed
    {
        $sleep??=static fn()=>usleep(1000000);
        for($attempt=1;$attempt<=3;$attempt++){
            $progress(null);
            try{return $work();}
            catch(DownloadFailure $e){
                if(!$e->retryable||$attempt===3)throw $e;
                for($i=0;$i<$attempt;$i++){$progress(null);$sleep();}
            }
        }throw new \LogicException('Unreachable retry state.');
    }
    /** Cache private imagery inputs for the same seven days as other BeamNG resources. */
    public static function fetch(\PDO $db,array &$plan,BeamRaster $raster,string $dir,callable $progress,?callable $graph=null,?callable $download=null,?BeamResourceCache $cache=null): void
    {
        $report=['status'=>'not_configured','eligible'=>0,'attempted'=>0,'matched'=>0,'reasons'=>[],'image_sources'=>[],
            'method'=>'Two spatially separated panorama views; geometric facade sampling and conservative pixel heuristics. Estimated, not surveyed.',
            'limits'=>['buildings'=>self::MAX_BUILDINGS,'metadata_images_per_area'=>self::MAX_IMAGES_PER_AREA,'images_per_building'=>4],
            'projection'=>'No photo textures are embedded. Perspective/fisheye cameras and direct facade projection are not supported in this pass.'];
        $record=static function(string $reason)use(&$report){$report['reasons'][$reason]=($report['reasons'][$reason]??0)+1;};
        $token=(new MapillarySettings($db))->token();
        if($token===''){$plan['building_data']['photo_analysis']=$report;return;}
        $graph??=[BeamMapillary::class,'graph'];$download??=[Download::class,'file'];$cache??=new BeamResourceCache();
        $roads=[$plan['points']];foreach($plan['carriageways']??[] as $track)$roads[]=$track['points'];
        $corridors=array_map(static fn($road)=>new BeamScenery(array_map(static fn($p)=>[$p[0]-$plan['bounds'][0],$p[1]-$plan['bounds'][1]],$road),array_fill(0,count($road),['width_m'=>20.])), $roads);
        $images=[];$metadataByBox=[];$report['status']='complete';
        foreach($plan['building_data']['footprints']??[] as $index=>$f){
            $needed=!isset($f['tags']['building:material'])||!isset($f['tags']['building:colour']);
            if(!$needed){$record('explicit_osm_appearance');continue;}
            $near=false;foreach($corridors as $corridor)if($corridor->polygonInRange($f['points'])){$near=true;break;}
            if(!$near){$record('outside_eligible_corridor');continue;}
            $report['eligible']++;
            if($report['attempted']>=self::MAX_BUILDINGS){$record('building_budget');$report['status']='partial';continue;}
            $report['attempted']++;$progress('Matching building appearance from Mapillary photos '.$report['attempted']);
            try{
                $p=$f['points'];$cx=array_sum(array_column($p,0))/count($p);$cy=array_sum(array_column($p,1))/count($p);
                // Shared 100 m cells plus 160 m padding contain supported footprints and nearby cameras.
                $cell=[floor($cx/100)*100,floor($cy/100)*100];$boxKey=implode(':',$cell);
                if(!isset($metadataByBox[$boxKey])){
                    $q=$db->prepare('SELECT ST_XMin(g) w,ST_YMin(g) s,ST_XMax(g) e,ST_YMax(g) n FROM (SELECT ST_Transform(ST_MakeEnvelope(?,?,?,?,CAST(? AS integer)),4326) g) b');
                    $q->execute([$cell[0]-160+$plan['bounds'][0],$cell[1]-160+$plan['bounds'][1],$cell[0]+260+$plan['bounds'][0],$cell[1]+260+$plan['bounds'][1],$plan['epsg']]);
                    $box=$q->fetch(\PDO::FETCH_ASSOC);$bbox=implode(',',array_map('floatval',[$box['w'],$box['s'],$box['e'],$box['n']]));$rows=[];
                    // Image bbox searches are bounded collections, not cursor-paginated feature searches.
                    $params=['bbox'=>$bbox,'fields'=>self::FIELDS,'limit'=>self::MAX_IMAGES_PER_AREA,'is_pano'=>'true'];
                    $key=BeamResourceCache::key('building-photo-metadata-v1',hash('sha256',$token),json_encode($params,JSON_THROW_ON_ERROR));
                    $file=$dir.'/building-photos-'.$key.'.json';
                    $cache->fetch($key,$file,static function($path)use($graph,$params,$token,$progress){
                        $data=self::retry(static fn()=>self::metadata($graph('images',$params,$token,$progress)),$progress);
                        if(file_put_contents($path,json_encode($data,JSON_THROW_ON_ERROR))===false)throw new \RuntimeException('Cannot store imagery metadata.');
                    },static function($path){$v=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if(!is_array($v['data']??null))throw new \RuntimeException('Invalid imagery metadata cache.');},$progress);
                    $data=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                    foreach($data['data'] as $row){if($row['camera_type']!=='spherical'){$record('unsupported_camera');continue;}$rows[$row['id']]=$row;}
                    if(count($data['data'])>=self::MAX_IMAGES_PER_AREA||$data['has_next']){$record('metadata_image_budget');$report['status']='partial';}
                    if($rows){
                        $q=$db->prepare('SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(?),4326),CAST(? AS integer)))');
                        $q->execute([json_encode(['type'=>'MultiPoint','coordinates'=>array_column(array_values($rows),'location')],JSON_THROW_ON_ERROR),$plan['epsg']]);
                        $g=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);$coords=$g['coordinates'];$i=0;
                        foreach($rows as &$row){$xy=$coords[$i++];$row['point']=[$xy[0]-$plan['bounds'][0],$xy[1]-$plan['bounds'][1]];}unset($row);
                    }
                    $metadataByBox[$boxKey]=$rows;
                }
                $candidates=[];foreach($metadataByBox[$boxKey] as $row){
                    if($row['point'][0]<0||$row['point'][1]<0||$row['point'][0]>=$raster->side||$row['point'][1]>=$raster->side)continue;
                    $face=self::face($f['points'],$row['point']);if(!$face)continue;
                    if(self::blocked($row['point'],$face['center'],$plan['building_data']['footprints'],$f['osm_id']??''))continue;
                    $row['face']=$face;$candidates[]=$row;
                }
                usort($candidates,static fn($a,$b)=>$a['face']['distance']<=>$b['face']['distance']);
                $views=[];$lastPoint=null;$matched=null;
                $imageAttempts=0;
                foreach($candidates as $row){
                    if($lastPoint&&hypot($row['point'][0]-$lastPoint[0],$row['point'][1]-$lastPoint[1])<5)continue;
                    if($imageAttempts++>=4)break;
                    $id=$row['id'];$file=$dir.'/building-photo-'.$id.'.jpg';
                    try{
                        if(!isset($images[$id])){
                            $key=BeamResourceCache::key('building-photo-image-v1',$id);
                            $cache->fetch($key,$file,static function($path)use(&$row,$graph,$token,$download,$progress){
                                try{self::retry(static fn()=>$download(self::thumbnailUrl($row['url']),$path,6291456,null,$progress,30),$progress);}
                                catch(DownloadFailure $e){
                                    if($e->retryable)throw $e;
                                    // Signed thumbnail URLs may expire before the one-week metadata cache.
                                    $fresh=self::retry(static fn()=>$graph($row['id'],['fields'=>'thumb_1024_url'],$token,$progress),$progress);
                                    $url=self::thumbnailUrl($fresh['thumb_1024_url']??null);
                                    self::retry(static fn()=>$download($url,$path,6291456,null,$progress,30),$progress);
                                }
                            },[self::class,'validateImage'],$progress);$images[$id]=$file;
                        }
                        $attributes=BeamBuildingAttributes::read($f['tags']);
                        $sample=self::sample($images[$id],$row,$attributes,$raster);
                        if(!$sample){$record('insufficient_facade_pixels');continue;}
                        $sample['image_id']=$id;$sample['creator']=$row['creator'];$sample['captured_at']=$row['captured_at'];$sample['point']=$row['point'];$sample['face_index']=$row['face']['index'];
                        $views[]=$sample;$lastPoint=$row['point'];$matched=self::consensus($views);
                        if($matched)break;
                    }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$record('image_unavailable');$report['status']='partial';}
                }
                if($matched){
                    $plan['building_data']['footprints'][$index]['photo_match']=$matched;$report['matched']++;
                    foreach($matched['sources'] as $source)$report['image_sources'][$source['image_id']]=$source;
                }else $record($candidates?'no_consistent_views':'no_suitable_panorama');
            }catch(JobCancelled $e){throw $e;}catch(\Throwable $e){$record('provider_or_processing_unavailable');$report['status']='partial';if(isset($boxKey))$metadataByBox[$boxKey]=[];if(preg_match('/Mapillary HTTP ([0-9]{3})/',$e->getMessage(),$m))$record('mapillary_http_'.$m[1]);if($e instanceof DownloadFailure&&!$e->retryable)break;}
        }
        $report['image_sources']=array_values($report['image_sources']);$plan['building_data']['photo_analysis']=$report;
    }
    public static function validateImage(string $path): void
    {
        $size=@getimagesize($path);
        if(!$size||!in_array($size[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG],true)||$size[0]<512||$size[0]>2048||$size[1]>1024||abs($size[0]/$size[1]-2)>.05)
            throw new \RuntimeException('Unsupported panorama dimensions.');
    }
    public static function face(array $points,array $camera): ?array
    {
        $ring=BeamPolygon::clean($points);if(BeamPolygon::inside($camera,$ring))return null;$best=null;
        foreach($ring as $i=>$a){$b=$ring[($i+1)%count($ring)];$dx=$b[0]-$a[0];$dy=$b[1]-$a[1];$width=hypot($dx,$dy);if($width<4)continue;
            $mid=[($a[0]+$b[0])/2,($a[1]+$b[1])/2];$vx=$camera[0]-$mid[0];$vy=$camera[1]-$mid[1];$d=hypot($vx,$vy);
            if($d<8||$d>80||($dy*$vx-$dx*$vy)/($width*$d)<.65)continue;
            if($best===null||$d<$best['distance'])$best=['a'=>$a,'b'=>$b,'center'=>$mid,'distance'=>$d,'index'=>$i];
        }return $best;
    }
    private static function blocked(array $camera,array $target,array $footprints,string $own): bool
    {
        foreach($footprints as $f){if(($f['osm_id']??'')===$own)continue;$ring=$f['points'];
            if(BeamPolygon::inside($camera,$ring))return true;
            foreach($ring as $i=>$a){$b=$ring[($i+1)%count($ring)];
                if(BeamPolygon::cross($camera,$target,$a)*BeamPolygon::cross($camera,$target,$b)<0&&BeamPolygon::cross($a,$b,$camera)*BeamPolygon::cross($a,$b,$target)<0)return true;
            }
        }return false;
    }
    private static function sample(string $file,array $image,array $attributes,BeamRaster $raster): ?array
    {
        self::validateImage($file);$gd=@imagecreatefromstring(file_get_contents($file));if(!$gd)return null;
        try{
            $width=imagesx($gd);$height=imagesy($gd);$face=$image['face'];$camera=$image['point'];$center=$face['center'];
            $ground=$raster->height($center[0],$center[1]);$eye=$raster->height($camera[0],$camera[1])+1.8;
            $wall=max(1,$attributes['height_m']-$attributes['roof_height_m']-$attributes['min_height_m']);
            $bottom=$ground+$attributes['min_height_m'];$grid=[];$minX=INF;$maxX=-INF;$minY=INF;$maxY=-INF;
            for($j=0;$j<24;$j++)for($i=0;$i<32;$i++){
                $u=.18+.64*$i/31;$v=.25+.5*$j/23;
                $x=$face['a'][0]+($face['b'][0]-$face['a'][0])*$u;$y=$face['a'][1]+($face['b'][1]-$face['a'][1])*$u;
                $bearing=rad2deg(atan2($x-$camera[0],$y-$camera[1]));$angle=fmod($bearing-$image['heading']+540,360)-180;
                $px=($angle/360+.5)*$width;$py=(.5-atan2($bottom+$v*$wall-$eye,hypot($x-$camera[0],$y-$camera[1]))/M_PI)*$height;
                $minX=min($minX,$px);$maxX=max($maxX,$px);$minY=min($minY,$py);$maxY=max($maxY,$py);
                $c=imagecolorat($gd,max(0,min($width-1,(int)$px)),max(0,min($height-1,(int)$py)));
                $grid[$j][$i]=[($c>>16)&255,($c>>8)&255,$c&255];
            }
            // Seam-spanning samples and too-small facades do not provide trustworthy appearance evidence.
            if($maxX-$minX<18||$maxX-$minX>$width/2||$maxY-$minY<10)return null;
            return self::analyze($grid);
        }finally{imagedestroy($gd);}
    }
    /** Robust dominant facade color; texture suggestions require actual spatial pattern evidence. */
    public static function analyze(array $grid): ?array
    {
        $pixels=[];$total=0;$bins=[];$horizontal=0.;$vertical=0.;$hn=0;$vn=0;
        foreach($grid as $j=>$row)foreach($row as $i=>$c){
            $total++;[$r,$g,$b]=$c;$max=max($c);$min=min($c);
            if($max<35||$min>240||($g>$r*1.12&&$g>$b*1.08)||($b>$r*1.2&&$b>$g*1.12))continue;
            $pixels[]=$c;$key=(int)floor($r/32).':'.(int)floor($g/32).':'.(int)floor($b/32);$bins[$key]=($bins[$key]??0)+1;
            $lum=($r+$g+$b)/3;
            if(isset($row[$i-1])){$horizontal+=abs($lum-array_sum($row[$i-1])/3);$hn++;}
            if(isset($grid[$j-1][$i])){$vertical+=abs($lum-array_sum($grid[$j-1][$i])/3);$vn++;}
        }
        if(count($pixels)<$total*.55||count($pixels)<128)return null;
        arsort($bins);$dominant=explode(':',(string)array_key_first($bins));$cluster=[];
        foreach($pixels as $c)if(abs($c[0]/32-$dominant[0]-.5)<1.1&&abs($c[1]/32-$dominant[1]-.5)<1.1&&abs($c[2]/32-$dominant[2]-.5)<1.1)$cluster[]=$c;
        if(count($cluster)<count($pixels)*.5)return null;
        $median=static function($values){sort($values);return $values[intdiv(count($values),2)];};
        $color=[$median(array_column($cluster,0)),$median(array_column($cluster,1)),$median(array_column($cluster,2))];
        [$r,$g,$b]=$color;$h=$horizontal/max(1,$hn);$v=$vertical/max(1,$vn);$material=null;
        if($r>$g*1.18&&$g>$b*1.08&&$h>7&&$v>7&&max($h,$v)/max(1,min($h,$v))<3)$material='brick';
        elseif($r>$b*1.15&&$v>10&&$v>2.5*max(1,$h))$material='wood';
        elseif($h<3&&$v<3&&max($color)-min($color)<65)$material='plaster';
        return ['color'=>array_map(static fn($n)=>max(.12,min(.95,$n/255)),$color),'material'=>$material,'usable_fraction'=>count($pixels)/$total,'dominant_fraction'=>count($cluster)/count($pixels)];
    }
    public static function consensus(array $views): ?array
    {
        foreach($views as $i=>$a)foreach($views as $j=>$b)if($j>$i&&$a['image_id']!==$b['image_id']&&$a['face_index']===$b['face_index']&&hypot($a['point'][0]-$b['point'][0],$a['point'][1]-$b['point'][1])>=5){
            $delta=sqrt(array_sum(array_map(static fn($x,$y)=>($x-$y)**2,$a['color'],$b['color'])));
            if($delta>.16)continue;
            $sources=[];foreach([$a,$b] as $view)$sources[]=['image_id'=>$view['image_id'],'creator'=>$view['creator'],'captured_at'=>$view['captured_at'],'url'=>'https://www.mapillary.com/app/?pKey='.$view['image_id']];
            return ['color'=>array_map(static fn($x,$y)=>($x+$y)/2,$a['color'],$b['color']),
                'material'=>$a['material']!==null&&$a['material']===$b['material']?$a['material']:null,
                'confidence'=>'two_view_heuristic','sources'=>$sources,'method'=>'Geometry-projected panorama wall samples; occlusions filtered heuristically, not semantic facade segmentation.'];
        }return null;
    }
}
