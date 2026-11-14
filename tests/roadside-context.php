<?php
use SweepLine\{BeamRoadsideData,BeamRoadside,BeamRoadsideAssets,BeamRaster};
(function():void{
    $tags=[['power'=>'pole'],['power'=>'tower'],['power'=>'line'],['highway'=>'street_lamp'],['barrier'=>'fence'],['barrier'=>'wall'],['barrier'=>'hedge'],['barrier'=>'gate'],['barrier'=>'bollard'],['railway'=>'rail'],['railway'=>'level_crossing'],['highway'=>'milestone'],['highway'=>'bus_stop'],['amenity'=>'shelter'],['amenity'=>'bench'],['amenity'=>'parking']];
    foreach($tags as $t)check(BeamRoadsideData::kind($t)!==null,'Mapped category is recognized: '.json_encode($t));
    $parsed=BeamRoadsideData::parse(['elements'=>[
        ['type'=>'node','id'=>1,'lat'=>40,'lon'=>-100,'tags'=>['power'=>'pole']],
        ['type'=>'node','id'=>1,'lat'=>40,'lon'=>-100,'tags'=>['power'=>'pole']],
        ['type'=>'node','id'=>2,'lat'=>40,'lon'=>-100,'tags'=>['amenity'=>'parking']],
        ['type'=>'way','id'=>3,'tags'=>['railway'=>'rail','tunnel'=>'yes'],'geometry'=>[['lon'=>-100,'lat'=>40],['lon'=>-99.9,'lat'=>40]]],
        ['type'=>'node','id'=>4,'lat'=>400,'lon'=>-100,'tags'=>['amenity'=>'bench']],
    ]]);
    check(count($parsed['features'])===1&&count($parsed['skipped'])===2,'Duplicate, underground and invalid/footprint-free features are handled explicitly');
    check(count(BeamRoadsideData::merge($parsed,$parsed)['features'])===1,'Overlapping context queries deduplicate physical source IDs');
    $n=128;$raster=new BeamRaster($n,1280,str_repeat(pack('g',10),$n*$n),str_repeat(chr(100),$n*$n*3));
    $lines=[['points'=>[[0,100,10],[1200,100,10]],'profiles'=>array_fill(0,2,['width_m'=>8])]];
    $make=static function($id,$kind,$points,$tags=[]) {return ['id'=>'node:'.$id,'osm_id'=>(string)$id,'kind'=>$kind,'points'=>$points,'tags'=>$tags,'timestamp'=>'2026-01-01T00:00:00Z','source'=>'OpenStreetMap','node_ids'=>[]];};
    $features=[
        $make(1,'power_pole',[[50,130]]),$make(2,'power_pole',[[150,130]]),$make(3,'power_tower',[[400,130]]),
        $make(4,'streetlight',[[250,130]]),$make(5,'fence',[[500,120],[550,120]]),
        $make(6,'wall',[[550,130],[600,130]]),$make(7,'retaining_wall',[[550,140],[580,140]]),
        $make(8,'hedge',[[600,140],[620,140]]),$make(9,'gate',[[650,130]],['direction'=>'90']),
        $make(10,'bollard',[[680,130]]),$make(11,'bus_stop',[[700,130]]),$make(12,'mile_marker',[[750,130]],['ref'=>'12.5','direction'=>'180']),
        $make(13,'shelter',[[780,130]],['shelter_type'=>'public_transport']),$make(14,'bench',[[820,130]]),
        $make(15,'rail',[[850,70],[850,160]]),$make(16,'level_crossing',[[850,100]]),
        $make(17,'parking',[[900,120],[960,120],[960,150],[900,150],[900,120]]),
        $make(18,'bollard',[[1000,100]]),$make(19,'bench',[[1000,300]]),
    ];
    $wire=$make(20,'power_line',[[50,130],[150,130]]);$wire['node_ids']=[1,2];$features[]=$wire;
    $files=[];$put=static function($name,$bytes)use(&$files){$files[$name]=$bytes;};
    $result=BeamRoadside::write(['features'=>$features,'fetch_status'=>'complete'],$lines,$raster,0,'ctx','/levels/test/',$put,static function($m){});
    foreach(['power_pole','power_tower','power_line','streetlight','fence','wall','retaining_wall','hedge','gate','bollard','bus_stop','mile_marker','shelter','bench','rail','level_crossing','parking'] as $kind)
        check(($result['report']['placed_features'][$kind]??0)>0,'Mapped '.$kind.' has a rendered representation');
    check($result['report']['lights']===1&&$result['report']['stock_instances']>6,'Stock-first roadside details include an operational streetlight');
    check(($result['report']['skipped']['outside_corridor_or_on_pavement']??0)>=2,'Objects outside the corridor or on pavement are omitted rather than moved');
    check(isset($files['art/context/main.materials.json'])&&count(array_filter(array_keys($files),static fn($s)=>str_ends_with($s,'.dae')))>0,'Generated paths and fallbacks are packaged with materials');
    $none=true;foreach($result['objects'] as $o)if(($o['class']??'')==='TSStatic'&&($o['collisionType']??'')!=='None')$none=false;
    check($none,'Roadside visuals do not introduce new tire-catching collision objects');
    $bench=BeamRoadside::write(['features'=>[$make(30,'bench',[[100,130]])]],$lines,$raster,0,'ctx','/levels/test/',$put,static function($m){});
    check(($bench['report']['stock_assets']['bench']??0)===1&&$bench['report']['primitives']===0,'Fitting mapped benches use stock models instead of generated polygons');
    check(BeamRoadsideAssets::point('bench',['material'=>'steel'])===null&&BeamRoadsideAssets::fence(['fence_type'=>'barbed_wire'])===null,'Stock models are not substituted when known material/type does not fit');
    $offset=BeamRoadsideAssets::object('metal_fence','test',[50,50,0],1,0,2);
    check(abs($offset['position'][0]-49)<1e-8,'Stock fence pivot is corrected to keep the mapped segment centered');
    check(abs(BeamRoadside::height(['height'=>'10 ft'],1)-3.048)<1e-8,'Mapped object heights support feet and metres');
    $im=imagecreatefromstring(BeamRoadside::label('12.5',false));check(imagesx($im)===128,'Mapped marker values have generated readable faces');imagedestroy($im);
})();
