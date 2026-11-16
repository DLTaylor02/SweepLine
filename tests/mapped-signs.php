<?php
use SweepLine\{BeamSigns,BeamSignFace,BeamRaster};
(function():void{
    $node=static fn($id,$type,$direction='270')=>['type'=>'node','id'=>$id,'lon'=>-100.,'lat'=>40.,'timestamp'=>'2026-01-01T00:00:00Z','tags'=>['traffic_sign'=>$type,'direction'=>$direction]];
    $data=BeamSigns::parse(['elements'=>[$node(1,'US:W1-2L'),$node(1,'US:W1-2L'),$node(2,'US:R2-1[45]'),$node(3,'US:W1-2'),$node(4,'US:W1-1R','forward'),$node(5,'maxspeed'),$node(6,'US:R1-1;US:R2-1[35]')]]);
    check(count($data['signs'])===7&&$data['signs'][1]['speed']===45,'All mapped sign types and compound panels parse; physical panel IDs deduplicate');
    check($data['skipped']===[],'Unknown artwork and relative-facing records are retained for fallback treatment');
    check(BeamSigns::bearing('NW')===315.&&BeamSigns::bearing('360')===0.&&BeamSigns::bearing('both')===null,'Cardinal and numeric face bearings are explicit');
    $generic=imagecreatefromstring(BeamSignFace::png('CUSTOM:parking'));check($generic!==false,'Unknown sign types receive a valid generic labeled face');imagedestroy($generic);
    $hit=['dx'=>1.,'dy'=>0.,'segment'=>1,'line'=>['points'=>[[0,0],[100,0],[200,100]]]];
    check(BeamSigns::curveDirection($hit,270)==='L','Unsigned curve face follows the first left bend ahead');
    $hit['line']['points'][2]=[200,-100];check(BeamSigns::curveDirection($hit,270)==='R','Unsigned curve face follows a right bend ahead');
    $hit['line']['points'][2]=[200,0];check(BeamSigns::curveDirection($hit,270)===null,'Straight geometry does not invent a curve arrow');
    $north=BeamSigns::facingPoint(-100,40,0);$east=BeamSigns::facingPoint(-100,40,90);
    check($north[1]>40&&$east[0]> -100,'Face direction projection preserves geographic north/east');
    $merged=BeamSigns::merge($data,$data);check(count($merged['signs'])===7,'Overlapping carriageway corridors do not duplicate signs');
    foreach(['R1-1','R1-2','R2-1[45]','W1-1L','W1-2R','W1-3L','W1-4R','W1-5L','W1-8R'] as $code){
        $im=imagecreatefromstring(BeamSignFace::png($code));check(imagesx($im)===512&&imagesy($im)===512,'Generated face is a real PNG for '.$code);imagedestroy($im);
        $xml=BeamSignFace::mesh('test_sign',$code);check(simplexml_load_string($xml)!==false&&str_contains($xml,'test_sign_back'),'Sign mesh includes front, back and thickness for '.$code);
    }
    $xml=BeamSignFace::mesh('orientation_test','CUSTOM');$doc=simplexml_load_string($xml);
    $uvNodes=$doc->xpath('//*[local-name()="source"][@id="uv"]/*[local-name()="float_array"]');
    $values=array_map('floatval',preg_split('/\s+/',trim((string)$uvNodes[0])));
    check(abs($values[3]-1.)<1e-8&&abs($values[5])<1e-8,'Sign Collada UVs put upper lettering at V=1 and lower lettering at V=0');
    check(abs($values[2])<1e-8&&abs($values[4])<1e-8,'Left edge lettering keeps its horizontal UV orientation');
    $raster=new BeamRaster(64,640,str_repeat(pack('g',10),4096),str_repeat(chr(100),12288));
    $lines=[['name'=>'test road','points'=>[[0,100,10],[600,100,10]],'profiles'=>array_fill(0,2,['width_m'=>8,'oneway'=>false])]];
    $record=$data['signs'][0]+['point'=>[200,109]];
    $assets=[];$put=static function($path,$bytes)use(&$assets){$assets[$path]=$bytes;};
    $result=BeamSigns::write(['signs'=>[$record],'fetch_status'=>'complete'],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
    check($result['report']['placed']===1&&count($result['objects'])===2,'Recorded off-road sign creates a sign and post at its source location');
    check($result['objects'][0]['collisionType']==='Visible Mesh Final'&&$result['objects'][1]['collisionType']==='Visible Mesh Final','Generated road sign panel and post are collidable');
    $o=$result['objects'][0];check($o['position'][0]===200&&$o['position'][1]===109&&abs($o['rotationMatrix'][3]-1)<1e-8,'Recorded sign location and west-facing front are retained');
    check(isset($assets['art/signs/main.materials.json'],$assets['art/signs/W1_2L.dae'],$assets['art/signs/W1_2L.color.png'],$assets['art/signs/post.dae']),'All generated sign assets are packaged');
    foreach([[200,100],[200,250]] as $p){
        $rejected=BeamSigns::write(['signs'=>[array_replace($record,['point'=>$p])]],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
        check($rejected['report']['placed']===0,'On-road and distant records are not shifted into invented positions');
    }
    $missing=$node(90,'US:R1-1');unset($missing['tags']['direction']);
    $parsed=BeamSigns::parse(['elements'=>[$missing]]);
    check(count($parsed['signs'])===1&&$parsed['signs'][0]['bearing']===null,'Missing sign facing is retained for roadside inference');
    foreach([[109,90.],[91,270.]] as [$y,$expected]){
        $s=$parsed['signs'][0]+['point'=>[200,$y]];
        $inferred=BeamSigns::write(['signs'=>[$s]],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
        check($inferred['report']['placed']===1&&abs($inferred['report']['records'][0]['grid_bearing']-$expected)<1e-7&&$inferred['report']['records'][0]['facing_source']==='inferred_right_side_of_travel','Sign face serves traffic with sign on its right, on either roadside');
        $reverse=$lines;$reverse[0]['points']=array_reverse($reverse[0]['points']);
        $again=BeamSigns::write(['signs'=>[$s]],$reverse,$raster,0,'test','/levels/test/',$put,static function($m){});
        check($again['report']['placed']===1&&abs($again['report']['records'][0]['grid_bearing']-$expected)<1e-7,'Reversing road geometry does not reverse inferred sign facing');
    }
    check($result['report']['records'][0]['bearing']===270.&&$result['report']['records'][0]['facing_source']==='explicit_osm','Explicit facing overrides roadside inference');
    $panels=array_map(static fn($s)=>$s+['point'=>[200,109]],array_values(array_filter($data['signs'],static fn($s)=>$s['osm_id']==='6')));
    $assembly=BeamSigns::write(['signs'=>$panels],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
    check($assembly['report']['placed']===2&&abs($assembly['objects'][2]['position'][1]-$assembly['objects'][0]['position'][1]-1.2)<1e-7,'Compound sign faces are placed side by side outward from their mapped assembly');
    $ambiguous=$lines;$ambiguous[]=['name'=>'parallel road','points'=>[[0,118,10],[600,118,10]],'profiles'=>$lines[0]['profiles']];
    $r=BeamSigns::write(['signs'=>[$record]],$ambiguous,$raster,0,'test','/levels/test/',$put,static function($m){});
    check(($r['report']['skipped_placement']['ambiguous_road_association']??0)===1,'Signs equally associated with two roads are skipped');
})();

(function():void{
    $cases=[['warning--slippery-road--g1','warning',[244,194,28]],['information--rest-area--g1','services',[24,76,151]],['information--highway-interchange--g1','guide',[20,105,61]],['information--picnic-site--g1','recreation',[110,65,36]],['warning--roadworks--g1','construction',[240,132,28]]];
    foreach($cases as [$code,$family,$color]){
        $style=\SweepLine\BeamSignStyle::describe($code);check($style['family']===$family&&$style['bg']===$color,'Source semantics select '.$family.' sign colors');
        $im=imagecreatefromstring(\SweepLine\BeamSignFace::png($code));$rgb=imagecolorat($im,0,0);check([($rgb>>16)&255,($rgb>>8)&255,$rgb&255]===$color,'Rendered '.$family.' sign uses its selected background');imagedestroy($im);
    }
    $speed=\SweepLine\BeamSignStyle::describe('regulatory--maximum-speed-limit-45--g3');check($speed['number']==='45'&&$speed['heading']==='SPEED LIMIT','Speed sign separates its large numeric legend from its heading');
    $exit=\SweepLine\BeamSignStyle::describe('information--highway-interchange--g1');check($exit['label']==='INTERCHANGE','Guide styling does not invent an exit number or destination');
    foreach(['regulatory--no-entry--g1','regulatory--no-parking--g1','complementary--maximum-speed-limit-30--g1'] as $code){$im=imagecreatefromstring(\SweepLine\BeamSignFace::png($code));check(imagesx($im)===512,'Styled prohibitions and advisory values retain high-resolution output');imagedestroy($im);}
})();
