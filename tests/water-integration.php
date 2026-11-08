<?php
// Disposable PostGIS database only, invoked by integration.php after migrations.
$waterPlan=['epsg'=>32617,'bounds'=>[500000,4500000,500200,4500200],'resolution'=>2048];
$polygon=json_decode($db->query("SELECT ST_AsGeoJSON(ST_Transform(ST_MakePolygon(ST_ExteriorRing(ST_MakeEnvelope(499950,4499950,500250,4500250,32617)),ARRAY[ST_ExteriorRing(ST_MakeEnvelope(500080,4500080,500120,4500120,32617))]),4326),9)")->fetchColumn(),true);
$flow=json_decode($db->query("SELECT ST_AsGeoJSON(ST_Transform(ST_SetSRID(ST_MakeLine(ST_MakePoint(499950,4500050),ST_MakePoint(500250,4500050)),32617),4326),9)")->fetchColumn(),true);
$waterRecord=['id'=>'test:usgs','source'=>'USGS 3DHP/NHD','source_key'=>'body1','kind'=>'lake','geometry'=>$polygon];
$other=$waterRecord;$other['id']='test:osm';$other['source']='OpenStreetMap';
$lineRecord=['id'=>'test:flow','kind'=>'flowline','body_key'=>'body1','geometry'=>$flow];
$normalized=SweepLine\BeamWaterSources::normalize($db,[$waterRecord,$other,$lineRecord],$waterPlan,static fn($m)=>null);
check(count($normalized['features'])===1&&$normalized['duplicates_skipped']===1&&count($normalized['flowlines'])===1,'PostGIS hydrography clips source polygons and deduplicates OSM against USGS');
$waterRings=$normalized['features'][0]['rings'];
check(count($waterRings)===2&&!SweepLine\BeamWaterGeometry::contains([100,100],$waterRings)&&SweepLine\BeamWaterGeometry::contains([10,10],$waterRings),'Enclosing water polygon clips to the complete square and preserves its dry island');
$waterBounds=SweepLine\BeamWaterGeometry::bounds($waterRings);
check(max(array_map('abs',array_map(static fn($a,$b)=>$a-$b,$waterBounds,[0,0,200,200])))<0.01,'Hydrography projection uses map-local metres rather than latitude and longitude');
$waterDir=sys_get_temp_dir().'/sl-water-'.bin2hex(random_bytes(4));mkdir($waterDir,0700);$requests=[];
try{
 $fetched=SweepLine\BeamWaterSources::fetch($db,$waterPlan,$waterDir,static fn($m)=>null,static function($url,$path,$limit,$post)use(&$requests,$polygon,$flow){
  $requests[]=$url;
  $body=str_contains($url,'/60/query')?['type'=>'FeatureCollection','features'=>[['type'=>'Feature','properties'=>['id3dhp'=>'body1','featuretype'=>3,'gnisidlabel'=>'Synthetic lake'],'geometry'=>$polygon]]]:
   (str_contains($url,'/50/query')?['type'=>'FeatureCollection','features'=>[['type'=>'Feature','properties'=>['id3dhp'=>'flow1','waterbodyid3dhp'=>'body1','featuretype'=>1],'geometry'=>$flow]]]:['elements'=>[]]);
  file_put_contents($path,json_encode($body,JSON_THROW_ON_ERROR));
 },static fn()=>null);
 check(count($requests)===3&&count($fetched['features'])===1&&$fetched['providers']['USGS-60']['status']==='complete','Worker fetch combines bounded USGS and OSM requests through native PDO normalization');
 check(str_contains($requests[0],'spatialRel=esriSpatialRelIntersects')&&str_contains($requests[0],'inSR=32617'),'Water requests cover the exact projected export square, including enclosing lakes');

 parse_str(parse_url($requests[1],PHP_URL_QUERY),$hydroArgs);
 check(str_contains($hydroArgs['where'],'5')&&str_contains($hydroArgs['orderByFields'],'featuretype DESC'),'Hydrography fetch includes and prioritizes mapped waterbody connectors for river profiles');
}finally{foreach(glob($waterDir.'/*') as $file)unlink($file);rmdir($waterDir);}
