<?php
use SweepLine\{BeamSigns,BeamSignFace,BeamRaster};
(function():void{
    $node=static fn($id,$type,$direction='270')=>['type'=>'node','id'=>$id,'lon'=>-100.,'lat'=>40.,'timestamp'=>'2026-01-01T00:00:00Z','tags'=>['traffic_sign'=>$type,'direction'=>$direction]];
    $data=BeamSigns::parse(['elements'=>[$node(1,'US:W1-2L'),$node(1,'US:W1-2L'),$node(2,'US:R2-1[45]'),$node(3,'US:W1-2'),$node(4,'US:W1-1R','forward'),$node(5,'maxspeed'),$node(6,'US:R1-1;US:R2-1[35]')]]);
    check(count($data['signs'])===2&&$data['signs'][1]['speed']===45,'Explicit typed signs and values parse; physical IDs deduplicate');
    check(($data['skipped']['unsupported_or_ambiguous_sign_type']??0)===3&&($data['skipped']['missing_absolute_facing']??0)===1,'Unknown arrow types, compounds and relative directions are skipped');
    check(BeamSigns::bearing('NW')===315.&&BeamSigns::bearing('360')===0.&&BeamSigns::bearing('both')===null,'Cardinal and numeric face bearings are explicit');
    $north=BeamSigns::facingPoint(-100,40,0);$east=BeamSigns::facingPoint(-100,40,90);
    check($north[1]>40&&$east[0]> -100,'Face direction projection preserves geographic north/east');
    $merged=BeamSigns::merge($data,$data);check(count($merged['signs'])===2,'Overlapping carriageway corridors do not duplicate signs');
    foreach(['R1-1','R1-2','R2-1[45]','W1-1L','W1-2R','W1-3L','W1-4R','W1-5L','W1-8R'] as $code){
        $im=imagecreatefromstring(BeamSignFace::png($code));check(imagesx($im)===64&&imagesy($im)===64,'Generated face is a real PNG for '.$code);imagedestroy($im);
        $xml=BeamSignFace::mesh('test_sign',$code);check(simplexml_load_string($xml)!==false&&str_contains($xml,'test_sign_back'),'Sign mesh includes front, back and thickness for '.$code);
    }
    $raster=new BeamRaster(64,640,str_repeat(pack('g',10),4096),str_repeat(chr(100),12288));
    $lines=[['name'=>'test road','points'=>[[0,100,10],[600,100,10]],'profiles'=>array_fill(0,2,['width_m'=>8,'oneway'=>false])]];
    $record=$data['signs'][0]+['point'=>[200,109]];
    $assets=[];$put=static function($path,$bytes)use(&$assets){$assets[$path]=$bytes;};
    $result=BeamSigns::write(['signs'=>[$record],'fetch_status'=>'complete'],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
    check($result['report']['placed']===1&&count($result['objects'])===2,'Recorded off-road sign creates a sign and post at its source location');
    $o=$result['objects'][0];check($o['position'][0]===200&&$o['position'][1]===109&&abs($o['rotationMatrix'][3]-1)<1e-8,'Recorded sign location and west-facing front are retained');
    check(isset($assets['art/signs/main.materials.json'],$assets['art/signs/W1_2L.dae'],$assets['art/signs/W1_2L.color.png'],$assets['art/signs/post.dae']),'All generated sign assets are packaged');
    foreach([[200,100],[200,250]] as $p){
        $rejected=BeamSigns::write(['signs'=>[array_replace($record,['point'=>$p])]],$lines,$raster,0,'test','/levels/test/',$put,static function($m){});
        check($rejected['report']['placed']===0,'On-road and distant records are not shifted into invented positions');
    }
    $ambiguous=$lines;$ambiguous[]=['name'=>'parallel road','points'=>[[0,118,10],[600,118,10]],'profiles'=>$lines[0]['profiles']];
    $r=BeamSigns::write(['signs'=>[$record]],$ambiguous,$raster,0,'test','/levels/test/',$put,static function($m){});
    check(($r['report']['skipped_placement']['ambiguous_road_association']??0)===1,'Signs equally associated with two roads are skipped');
})();
