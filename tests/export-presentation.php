<?php
// Presentation and placement regressions; no changes to driving/collision geometry.
(function():void{
    $profile=SweepLine\BeamRoadSurface::shoulders([SweepLine\BeamLanes::infer(['lanes'=>'2'],'secondary',0)])[0];
    $owner=['name'=>'primary','points'=>[[10,100,10],[210,100,10]],'profiles'=>array_fill(0,2,$profile)];
    $branch=['name'=>'ramp','kind'=>'ramp','points'=>[[110,100,10],[110,160,10]],'profiles'=>array_fill(0,2,$profile),'connector'=>[]];
    $join=['along'=>100.,'radius'=>200.,'branch_name'=>'ramp','preserve_through_lanes'=>true,'barrier_openings'=>['left'=>[[95.,105.]],'right'=>[]]];
    $mask=SweepLine\BeamJunctionPaint::openings($owner,[$join],['ramp'=>$branch]);
    $surface=static function(?string $xml,array $origin):array{
        if($xml===null)return [];
        preg_match('/<float_array id="positions-array"[^>]*>([^<]*)</',$xml,$m);$values=array_map('floatval',preg_split('/\s+/',trim($m[1])));$vertices=array_chunk($values,3);$out=[];
        foreach($vertices as &$v)foreach([0,1,2] as $i)$v[$i]+=$origin[$i];unset($v);
        preg_match_all('/<triangles[^>]*>.*?<p>([^<]*)<\/p>/s',$xml,$groups);
        foreach($groups[1] as $group)foreach(array_chunk(array_map('intval',preg_split('/\s+/',trim($group))),9) as $f)$out[]=[$vertices[$f[0]],$vertices[$f[3]],$vertices[$f[6]]];
        return $out;
    };
    $contains=static function($triangles,$point):bool{foreach($triangles as $t){$w=SweepLine\BeamTerrainContact::weights($point,$t);if($w&&min($w)>=-1e-7)return true;}return false;};
    $original=SweepLine\BeamPavement::build($owner,$owner['points'],$owner['profiles'],'test');
    $marked=$owner+['paint_exclusions'=>$mask];$clipped=SweepLine\BeamPavement::build($marked,$owner['points'],$owner['profiles'],'test');
    $paint=$surface($clipped['paint_xml'],$clipped['position']);
    check($original['xml']===$clipped['xml']&&$original['fascia_xml']===$clipped['fascia_xml'],'Intersection paint suppression leaves collision pavement and road edges byte-identical');
    check(!$contains($paint,[110,100])&&!$contains($paint,[110,103])&&$contains($paint,[125,100]),'Paint is removed inside the actual ramp opening, not across its oversized estimated junction radius');
    check($contains($paint,[110,100-$profile['width_m']/2+$profile['shoulder_m']]),'The far white edge of a T junction remains outside the paint opening');
    $continuation=$branch;$continuation['kind']='receiving_road';
    check(SweepLine\BeamJunctionPaint::openings($owner,[$join],['ramp'=>$continuation])===''&&SweepLine\BeamJunctionPaint::openings($owner,[array_replace($join,['barrier_openings'=>[]])],['ramp'=>$branch])==='','Collinear road continuations and vertically separated crossings do not erase paint');

    $taper=$marked;
    foreach($taper['profiles'] as &$p){$p['lane_paint_segment']=true;$p['lane_paint']=['center'=>['u'=>.5,'visible'=>1.,'yellow'=>true]];}unset($p);
    $lines=SweepLine\BeamLanePaint::build($taper,SweepLine\BeamJunctionBoundary::sections($owner['points'],$owner['profiles']),$owner['points'],$owner['profiles'],'test');
    $lineTriangles=$surface($lines['xml'],$lines['position']);
    check(!$contains($lineTriangles,[110,100])&&$contains($lineTriangles,[125,100]),'Geometry-based lane taper paint follows the same junction opening as textured paint');
    $return=$branch;$return['end_owner']=['surface'=>$owner];$return['points']=[[110,160,10],[110,100,10]];
    $context=SweepLine\BeamJunctionPaint::prepare(['roads'=>[$return],'main_surfaces'=>[$owner],'junctions'=>[]]);
    check($context['paint_exclusions']!==''&&$context['roads'][0]['points']===$return['points'],'A ramp reconnection at its finish also suppresses paint on the receiving road');
    $full=$owner+['paint_exclusions'=>SweepLine\BeamJunctionBoundary::encode(SweepLine\BeamPavement::build($owner,$owner['points'],$owner['profiles'],'test',null,[],true))];
    $empty=SweepLine\BeamPavement::build($full,$owner['points'],$owner['profiles'],'test');$written=[];
    $put=static function($p,$b)use(&$written){$written[$p]=$b;};
    check($empty['paint_triangles']===0&&SweepLine\BeamRoadMaterials::writePaint($empty,'test','empty.dae','/',$put,'empty')===null&&$written===[],'Fully unpainted intersections omit the overlay instead of exporting an empty invalid shape');

    $raster=new SweepLine\BeamRaster(256,256,str_repeat(pack('g',10.),65536),str_repeat(chr(100),65536*3));
    $ramp=['name'=>'ramp','kind'=>'ramp','points'=>[[20,110,10.05],[220,110,10.05]],'profiles'=>array_fill(0,2,['width_m'=>4.]),
        'junction_fill'=>[[[80,112,10.05],[120,112,10.05],[100,124,10.05]]]];
    $roads=SweepLine\BeamRoadClearance::prepare([$ramp]);
    $move=SweepLine\BeamRoadClearance::relocateSign([100,118],$roads,$raster,0.);
    check(SweepLine\BeamRoadClearance::hit([100,118],$roads,9.,13.)!==null&&$move!==null&&$move['shift_m']>0&&SweepLine\BeamRoadClearance::hit($move['point'],$roads,9.,13.,.75)===null,'Signs on paved merge aprons outside the centerline ribbon move completely clear of pavement');
    check(SweepLine\BeamRoadClearance::prepare($roads)===$roads&&!SweepLine\BeamRoadClearance::hit([100,118],$roads,20.,25.),'Pavement clearance caches are reused and remain aware of elevation');
    $features=['features'=>[['id'=>'fence','kind'=>'fence','points'=>[[96,118],[104,118]],'tags'=>[],'timestamp'=>null]]];
    $context=SweepLine\BeamRoadside::write($features,$roads,$raster,0.,'test','/levels/test/',$put,static fn($m)=>null,$roads);
    check(empty($context['report']['placed_features']['fence'])&&($context['report']['skipped']['linear_segment_pavement_or_corridor_overlap']??0)>0,'Mapped fence segments on a merge apron are omitted instead of crossing the ramp');
    $connector=['name'=>'connector','kind'=>'ramp','network'=>true,'points'=>[[100,140,10],[100,210,10]],'profiles'=>array_fill(0,2,$profile),
        'connector'=>[[100,100,10],[100,140,10]],'join'=>SweepLine\BeamIntersections::nearest([100,100],$owner['points'],$owner['profiles']),'junction_parent'=>$owner];
    check(SweepLine\BeamRoadClearance::hit([100,120],SweepLine\BeamRoadClearance::prepare([$connector]),9.,13.)!==null,'Roadside clearance includes connector pavement before the branch ribbon starts');
    $offset=['name'=>'offset','points'=>[[20,100,10],[220,100,10]],'profiles'=>[
        ['width_m'=>4.,'section_override'=>[[20,112,12],[20,108,10]]],['width_m'=>4.,'section_override'=>[[220,112,12],[220,108,10]]]]];
    $offsetRoad=SweepLine\BeamRoadClearance::prepare([$offset]);
    check(SweepLine\BeamRoadClearance::hit([100,110],$offsetRoad,10.9,11.1)!==null&&SweepLine\BeamRoadClearance::hit([100,100],$offsetRoad,9.,13.)===null,'Overridden road edges and their actual interpolated height override nominal centerline buffers');
    $top=['vertices'=>[[80,115,11],[120,115,11],[100,125,11]],'triangles'=>[['v'=>[0,1,2]]]];
    $cached=SweepLine\BeamRoadClearance::prepare([['collision_top'=>SweepLine\BeamJunctionBoundary::encode($top)]+$ramp]);
    check(SweepLine\BeamRoadClearance::hit([100,118],$cached,10.9,11.1)!==null&&SweepLine\BeamRoadClearance::hit([30,110],$cached,9.,13.)===null,'Final clipped collision tops are authoritative over obsolete raw road ribbons');

    // Decode the published block format independently, including its alpha indices.
    $decode=static function(string $dds,int $level=0):array{
        $header=unpack('V31',substr($dds,4,124));$w=$header[4];$h=$header[3];$at=148;
        for($i=0;$i<$level;$i++){$at+=max(1,(int)ceil($w/4))*max(1,(int)ceil($h/4))*16;$w=max(1,intdiv($w,2));$h=max(1,intdiv($h,2));}
        $out=str_repeat("\0",$w*$h*4);
        for($y=0;$y<$h;$y+=4)for($x=0;$x<$w;$x+=4){
            $block=substr($dds,$at,16);$at+=16;$a=[ord($block[0]),ord($block[1])];
            if($a[0]>$a[1]){for($j=2;$j<8;$j++)$a[]=((8-$j)*$a[0]+($j-1)*$a[1])/7;}else{for($j=2;$j<6;$j++)$a[]=((6-$j)*$a[0]+($j-1)*$a[1])/5;array_push($a,0,255);}
            $alpha=0;for($j=0;$j<6;$j++)$alpha|=ord($block[$j+2])<<($j*8);
            $colors=[];$c=unpack('v2',substr($block,8,4));foreach($c as $v){$r=($v>>11)&31;$g=($v>>5)&63;$b=$v&31;$colors[]=[($r<<3)|($r>>2),($g<<2)|($g>>4),($b<<3)|($b>>2)];}
            $colors[]=[0,0,0];$colors[]=[0,0,0];for($j=0;$j<3;$j++){$colors[2][$j]=(2*$colors[0][$j]+$colors[1][$j])/3;$colors[3][$j]=($colors[0][$j]+2*$colors[1][$j])/3;}
            $indices=unpack('V',substr($block,12,4))[1];
            for($j=0;$j<16;$j++){$px=$x+$j%4;$py=$y+intdiv($j,4);if($px>=$w||$py>=$h)continue;$c=$colors[($indices>>($j*2))&3];$c[]=$a[($alpha>>($j*3))&7];foreach($c as $k=>$v)$out[($py*$w+$px)*4+$k]=chr((int)round($v));}
        }return [$w,$h,$out];
    };
    $pixels=SweepLine\BeamPng::paintPixels($profile);$dds=SweepLine\BeamDds::rgba(256,1024,$pixels);$header=unpack('V31',substr($dds,4,124));$dx=unpack('V5',substr($dds,128,20));
    check(substr($dds,0,4)==='DDS '&&$header[1]===124&&$header[7]===11&&$dx[1]===78&&$dx[5]===1&&strlen($dds)===349732,'Ready-to-load paint is a complete 256x1024 BC3 sRGB DDS with straight alpha and eleven mip levels');
    [$w,$h,$decoded]=$decode($dds);$alpha=true;$error=0;
    for($i=0;$i<strlen($pixels);$i+=4){if($pixels[$i+3]!==$decoded[$i+3])$alpha=false;if(ord($pixels[$i+3]))foreach([0,1,2] as $k)$error=max($error,abs(ord($pixels[$i+$k])-ord($decoded[$i+$k])));}
    check($w===256&&$h===1024&&$alpha&&$error<=8,'Independent DDS decoding retains marking coverage and white/yellow colors within RGB565 precision');
    [$mw,$mh,$mip]=$decode($dds,3);$visible=0;$fringe=true;
    for($i=0;$i<strlen($mip);$i+=4){if(ord($mip[$i+3])>=128)$visible++;if(ord($mip[$i+3])&&ord($mip[$i])<150)$fringe=false;}
    check($mw===32&&$mh===128&&$visible>0&&$visible<$mw*$mh/4&&$fringe,'Far marking mips retain bounded cutout coverage without dark asphalt fringes');
    $written=[];$cache=[];$file=SweepLine\BeamRoadMaterials::writeTexture($profile,$put,$cache);$same=SweepLine\BeamRoadMaterials::writeTexture($profile+['estimated_width'=>true],$put,$cache);
    check($file===$same&&count($written)===1&&isset($written[substr($file,0,-4).'.dds'])&&!isset($written[$file]),'Equivalent road profiles share a single DDS and omit source PNGs that would trigger game-side conversion');
    check(rejects(static fn()=>SweepLine\BeamDds::rgba(3,4,str_repeat("\0",48)))&&rejects(static fn()=>SweepLine\BeamDds::rgba(4,4,'')),'Invalid DDS dimensions or pixel buffers are rejected before encoding');
})();