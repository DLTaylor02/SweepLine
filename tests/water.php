<?php
// Synthetic offline hydrography fixtures; no provider or game installation required.
use SweepLine\{BeamWaterGeometry,BeamWaterSources,BeamWater,BeamRaster,BeamLanes,BeamScenery,BeamPolygon,DownloadFailure,JobCancelled};
$rings=[[[40,40],[160,40],[160,160],[40,160]],[[80,80],[120,80],[120,120],[80,120]]];
$patches=BeamWaterGeometry::patches($rings);
$area=array_sum(array_map(static fn($p)=>abs(BeamPolygon::area($p)),$patches));
check(abs($area-12800)<1e-6&&!BeamWaterGeometry::contains([100,100],$rings)&&BeamWaterGeometry::contains([60,100],$rings),'Water polygon decomposition preserves island area and dry holes');
$skew=[[[10,10],[40,10],[90,100],[60,100]]];
$rects=BeamWaterGeometry::rectangles(BeamWaterGeometry::patches($skew));
$inside=true;foreach($rects as $r)foreach([0.01,0.5,0.99] as $t){$p=[$r[0][0]+($r[1][0]-$r[0][0])*$t,($r[0][1]+$r[2][1])/2];$inside=$inside&&BeamWaterGeometry::contains($p,$skew);}
check($inside&&count($rects)>1,'Skew shorelines use inscribed strips rather than rotated widths spilling onto land');
try{BeamWaterGeometry::patches($rings,1,1,2);check(false,'Water geometry budget enforced');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'budget'),'Water geometry budget enforced');}
$geom=static fn($p)=>array_map(static fn($v)=>['lon'=>$v[0],'lat'=>$v[1]],$p);
$relation=['type'=>'relation','id'=>9,'tags'=>['natural'=>'water','type'=>'multipolygon'],'members'=>[
 ['type'=>'way','ref'=>1,'role'=>'outer','geometry'=>$geom([[0,0],[2,0],[2,2]])],
 ['type'=>'way','ref'=>2,'role'=>'outer','geometry'=>$geom([[0,0],[0,2],[2,2]])],
 ['type'=>'way','ref'=>3,'role'=>'inner','geometry'=>$geom([[0.5,0.5],[1.5,0.5],[1.5,1.5],[0.5,1.5],[0.5,0.5]])]]];
$osm=BeamWaterSources::osm(['elements'=>[$relation]]);
check(count($osm['features'])===1&&count($osm['features'][0]['geometry']['coordinates'][0])===2,'OSM multipolygon joins reversed members and retains islands');
$broken=$relation;array_pop($broken['members']);array_pop($broken['members']);
$seasonal=$relation;$seasonal['tags']['seasonal']='yes';
check(BeamWaterSources::osm(['elements'=>[$broken,$seasonal]])['rejected']===2,'Broken and seasonal OSM water is rejected without fabricated closing edges');
$flat=static fn()=>new BeamRaster(64,200,str_repeat(pack('g',100),4096),str_repeat(chr(85),12288));
$feature=['id'=>'fixture:lake','source'=>'test','kind'=>'lake','rings'=>$rings];
$source=$flat();$terrain=$flat();$build=static fn($f,$r,$tracks=[],$lines=[])=>BeamWater::build(['features'=>[$f],'flowlines'=>$lines],$source,$r,80,$tracks,static fn($m)=>null);
$water=$build($feature,$terrain);
check($water['report']['rendered_features']===1&&$water['report']['native_water_objects']===4,'Mapped lake becomes native bounded water objects');
check(abs($water['objects'][0]['nodes'][0][2]-20.12)<0.001&&$terrain->height(60,100)<99&&abs($terrain->height(100,100)-100)<0.001,'Estimated water level uses absolute DEM, shallow bed carving preserves island terrain');
check($source->height(60,100)===100.&&$terrain->height(10,10)===100.,'Water bed edits leave source DEM and surrounding dry terrain untouched');
$profile=array_fill(0,2,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
$track=['road'=>[[20,60,20],[180,60,20]],'profiles'=>$profile,'bridges'=>[],'tunnels'=>[]];
$blocked=$build($feature,$flat(),[$track]);
check($blocked['objects']===[]&&count($blocked['report']['rejections'])===1,'Water at ordinary pavement height is rejected with a diagnostic');
$bridge=$track;$bridge['road']=[[20,60,25],[180,60,25]];$bridge['bridges']=[['start_m'=>0,'end_m'=>160]];
$wetBridge=$build($feature,$flat(),[$bridge]);
check($wetBridge['report']['rendered_features']===1&&$bridge['road'][0][2]===25,'Water under a sufficiently elevated bridge does not modify its deck');
$bridge['tunnels']=[['start_m'=>0,'end_m'=>160]];
check($build($feature,$flat(),[$bridge])['objects']===[],'Tunnel overlap omits uncertain water instead of flooding the portal');
$scenery=new BeamScenery([[20,20,20],[180,20,20]],$profile,$water['exclusions']);
check(!$scenery->allows(60,60,1)&&$scenery->allows(100,82,1),'Scenery excludes mapped water while dry island space remains eligible');
check($scenery->polygonReason([[50,50],[55,50],[55,55],[50,55]])==='mapped_water_overlap','Building footprints cannot occupy mapped water');
check(BeamWater::downhill([100,99,101,98])==[100.,100.,100.,98.],'River elevation fitting removes uphill DEM noise without inventing flow speed');
$river=$feature;$river['kind']='river';$river['rings']=[[[40,40],[160,40],[160,70],[40,70]]];
$line=['points'=>[[40,55],[160,55]]];
check($build($river,$flat(),[],[$line])['report']['rendered_features']===1,'Mapped river boundary can use a terrain-derived flowline profile');
$tmp=tempnam(sys_get_temp_dir(),'sl-water-');$attempt=0;
try{
 $request=BeamWaterSources::request('https://example.invalid/water',$tmp,null,static fn($m)=>null,static function($url,$path)use(&$attempt){$attempt++;file_put_contents($path,$attempt<3?'{"error":{"code":504}}':'{"type":"FeatureCollection","features":[]}');},static fn()=>null);
 check($attempt===3&&$request['data']['features']===[],'Transient hydrography errors retry then accept a validated empty response');
 $cancelled=false;try{BeamWaterSources::request('https://example.invalid/water',$tmp,null,static fn($m)=>null,static function(){throw new JobCancelled('Cancelled');},static fn()=>null);}catch(JobCancelled $e){$cancelled=true;}
 check($cancelled,'Hydrography cancellation propagates instead of becoming an optional-provider warning');
}finally{if(is_file($tmp))unlink($tmp);}

// Invalid member coordinates and nested multipolygons must never erase an island.
$nested=$relation;$nested['members'][]=['type'=>'relation','role'=>'inner','ref'=>88];
$badCoords=['type'=>'way','id'=>77,'tags'=>['natural'=>'water'],'geometry'=>$geom([[999,0],[1,0],[1,1],[999,0]])];
check(BeamWaterSources::osm(['elements'=>[$nested,$badCoords]])['rejected']===2,'Unsupported nested relations and out-of-range OSM coordinates are rejected');

$gradedHeights='';for($y=0;$y<64;$y++)for($x=0;$x<64;$x++)$gradedHeights.=pack('g',100-$x*0.01);
$graded=new BeamRaster(64,200,$gradedHeights,str_repeat(chr(85),12288));$target=clone $graded;
$flowFragments=[['points'=>[[80,55],[120,55]]],['points'=>[[40,55],[80,55]]],['points'=>[[120,55],[160,55]]]];
$gradedWater=BeamWater::build(['features'=>[$river],'flowlines'=>$flowFragments],$graded,$target,80,[],static fn($m)=>null);
$profilePoints=$gradedWater['report']['features'][0]['profile_points']??[];
check(count($profilePoints)>=7&&abs($profilePoints[0][0]-40)<0.001&&abs(end($profilePoints)[0]-160)<0.001&&$profilePoints[0][2]>end($profilePoints)[2],'River profiles join both unique ends of fragmented flowlines and descend with DEM elevation');

$dense=[];for($i=0;$i<400;$i++){$angle=2*M_PI*$i/400;$dense[]=[500+300*cos($angle),500+300*sin($angle)];}
$coverage=BeamWaterGeometry::cover([$dense],2,128);
$area=abs(BeamPolygon::area($dense));
check(count($coverage['patches'])<=128&&$coverage['covered_area_m2']/$area>0.85&&$coverage['shoreline_cell_m']>2,'Dense mapped shorelines adapt within a bounded patch count instead of dropping an entire lake');
$withIsland=BeamWaterGeometry::cover($rings,1,64);
$noFlood=true;foreach($withIsland['patches'] as $quad){$center=[($quad[0][0]+$quad[2][0])/2,($quad[0][1]+$quad[2][1])/2];$noFlood=$noFlood&&BeamWaterGeometry::contains($center,$rings);}
check($noFlood&&abs($withIsland['covered_area_m2']-12800)<0.001,'Coalesced coverage retains exact axis-aligned island holes');

$small=['id'=>'fixture:small','source'=>'test','kind'=>'lake','rings'=>[[[5,5],[15,5],[15,15],[5,15]]]];
$ordered=BeamWater::build(['features'=>[$small,$feature]],$flat(),$flat(),80,[],static fn($m)=>null);
check($ordered['report']['features'][0]['id']===$feature['id'],'Larger mapped water bodies receive coverage before small ponds regardless of provider order');

$banks=SweepLine\BeamRiver::banks([100,55],[1,0],$river['rings']);
check($banks!==null&&abs($banks['width']-30)<1e-6&&abs($banks['center'][1]-55)<1e-6,'River cross-sections use mapped banks rather than arbitrary grid widths');
$riverTerrain=$flat();$ribbonWater=BeamWater::build(['features'=>[$river],'flowlines'=>[$line]],$flat(),$riverTerrain,80,[],static fn($m)=>null);
check($ribbonWater['report']['features'][0]['surface_method']==='mapped-bank ribbons'&&count($ribbonWater['objects'])===1&&count($ribbonWater['objects'][0]['nodes'])>10,'Mapped river flowline becomes a continuous native ribbon rather than rectangular cells');
check(abs($riverTerrain->height(100,40)-100.17)<0.15&&$riverTerrain->height(100,55)<99.5,'River-bank terrain reaches the water surface while the channel bed stays submerged');
$bankRoad=['road'=>[[40,35,21],[160,35,21]],'profiles'=>$profile,'bridges'=>[],'tunnels'=>[]];
$protected=$flat();BeamWater::build(['features'=>[$river],'flowlines'=>[$line]],$flat(),$protected,80,[$bankRoad],static fn($m)=>null);
check(abs($protected->height(100,35)-100)<0.001,'River-bank blending preserves nearby ordinary road support');
$splitLines=[['points'=>[[40,55],[85,55]]],['points'=>[[115,55],[160,55]]]];
$split=BeamWater::build(['features'=>[$river],'flowlines'=>$splitLines],$flat(),$flat(),80,[],static fn($m)=>null);
check(count($split['report']['features'][0]['profile_paths'])===2&&count($split['objects'])===2,'Disconnected mapped profile components render independently without inventing a connecting channel');

$fitPoints=[[0,0],[10,0],[20,0],[30,0]];
$fit=BeamWater::fitProfile($fitPoints,[100,100,94,94]);
$valid=$fit!==null;for($i=1;$i<count($fit??[]);$i++)$valid=$valid&&$fit[$i-1]>=$fit[$i]&&$fit[$i-1]-$fit[$i]<=2.001;
check($valid,'Local DEM steps receive bounded downhill slope fitting instead of dropping the river component');
check(BeamWater::fitProfile([[0,0],[1,0]],[1000,0])===null,'Unrecoverable profile errors still exceed the explicit correction bound');
check($ribbonWater['objects'][0]['nodes'][0][4]===3.0&&$ribbonWater['report']['features'][0]['estimated_depth_m']===3.0,'River physics and reports agree on the deeper estimated three-metre channel');
check(SweepLine\BeamStages::index('Preparing mapped water surfaces')===7&&SweepLine\BeamStages::index('Building bridges, tunnels and median terrain')===8&&SweepLine\BeamStages::index('Fetching mapped water',9)===9,'Worker stage mapping follows water and structure ordering without regressing on repeated context work');
