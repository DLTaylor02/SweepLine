<?php
// Offline endpoint elevation contracts: USGS wire values, missing data and validation.
use SweepLine\EndpointElevation;
check(EndpointElevation::points(['points'=>[[-119.8,39.5],[-119.7,39.6]]])[0] === [-119.8,39.5],'Endpoint coordinates retain geographic order');
foreach([[],['points'=>[]],['points'=>[[0,0]]],['points'=>[[181,0],[0,0]]],['points'=>[[0,86],[0,0]]],['points'=>[['bad',0],[0,0]]],['points'=>[[0,0,5],[0,0]]]] as $bad)
    check(rejects(fn()=>EndpointElevation::points($bad)),'Invalid elevation request is rejected');
$url='';
check(EndpointElevation::sample([-119.8,39.5],static function($value)use(&$url){$url=$value;return '{"value":"1888.5"}';})===1888.5,'USGS numeric-string elevations decode in metres');
check(str_contains($url,'units=Meters')&&str_contains($url,'wkid=4326'),'USGS request declares metre units and geographic coordinates');
check(EndpointElevation::sample([0,0],static fn()=> '{"value":0}')===0.0,'Sea-level zero is a real elevation');
check(EndpointElevation::sample([0,0],static fn()=> '{"value":-20}')===-20.0,'Below-sea-level elevations are supported');
foreach(['{"value":-1000000}','{"value":null}','{"error":"unavailable"}','not json'] as $bad)
    check(EndpointElevation::sample([0,0],static fn()=>$bad)===null,'Invalid or unavailable provider elevation stays unknown');
check(EndpointElevation::sample([0,0],static function(){throw new RuntimeException('Synthetic timeout');})===null,'Provider failure does not invent elevation');
