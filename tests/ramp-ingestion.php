<?php
(function():void{
    $way=['type'=>'way','id'=>101,'nodes'=>[1,2],'tags'=>['highway'=>'motorway_link','oneway'=>'yes'],'geometry'=>[['lon'=>-119.8,'lat'=>39.52],['lon'=>-119.799,'lat'=>39.521]]];
    $parsed=SweepLine\BeamRampBackfill::parse(['elements'=>[$way]]);
    check(count($parsed)===1&&$parsed[0]['direction']===1&&$parsed[0]['nodes']===[1,2],'Supplement parses real link-road geometry, node identities and direction');
    foreach(['motorway_link','trunk_link','primary_link','secondary_link','tertiary_link'] as $class){$w=$way;$w['tags']['highway']=$class;check(count(SweepLine\BeamRampBackfill::parse(['elements'=>[$w]]))===1,'All supported link-road classes survive supplemental ingestion');}
    $bad=$way;$bad['geometry'][0]['lon']=300;check(rejectsRuntime(fn()=>SweepLine\BeamRampBackfill::parse(['elements'=>[$bad]])),'Invalid supplemental coordinates fail validation before writes');
    $bad=$way;array_pop($bad['nodes']);check(rejectsRuntime(fn()=>SweepLine\BeamRampBackfill::parse(['elements'=>[$bad]])),'Incomplete ramp node geometry cannot mark legacy data complete');
    $private=$way;$private['tags']['access']='private';check(SweepLine\BeamRampBackfill::parse(['elements'=>[$private]])===[],'Restricted ramps remain excluded by ingestion');
})();
function rejectsRuntime(callable $f):bool {try{$f();return false;}catch(RuntimeException $e){return true;}}
