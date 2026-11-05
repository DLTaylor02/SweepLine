<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use SweepLine\{Geometry,RoadGraph,Scorer,Controls,Validation,Importer};
$failures=0; $checks=0;
function check(bool $ok,string $message): void {
    global $failures,$checks; $checks++;
    echo ($ok?'PASS ':'FAIL ').$message."\n"; if (!$ok) { $failures++; }
}
function rejects(callable $fn): bool { try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; } }
check(Validation::zip('12345')==='12345','Synthetic ZIP validation');
foreach (['1234','123451','12345\n',12345,[],null] as $bad) { check(rejects(fn()=>Validation::zip($bad)),'Reject invalid ZIP '.json_encode($bad)); }
check(rejects(fn()=>Validation::search(['zip'=>'12345','radius_miles'=>51])),'Radius resource cap');
check(rejects(fn()=>Validation::search(['zip'=>'12345','radius_miles'=>25.5])),'Fractional radius rejected');
check(rejects(fn()=>Validation::search(['zip'=>'12345','radius_miles'=>0])),'Radius must be positive');
check(Validation::search(['zip'=>'12345','radius_miles'=>1])['radius_miles']===1.0,'Minimum radius accepted');
check(Validation::search(['zip'=>'12345','radius_miles'=>50])['radius_miles']===50.0,'Maximum radius accepted');
check(rejects(fn()=>Validation::search(['zip'=>'12345','paved_only'=>'false'])),'Reject string boolean');
check(rejects(fn()=>Validation::search(['zip'=>'12345','profile'=>[]])),'Reject profile array');
check(rejects(fn()=>Validation::search(['zip'=>'12345','max_stops'=>1.5])),'Stops must be whole numbers');
check(rejects(fn()=>Validation::search(['zip'=>'12345','radius_miles'=>INF])),'Reject nonfinite radius');
check(abs(Geometry::distance([-119.8,39.5],[-119.8,39.51])-1111.95)<2,'Geographic distance at a non-equatorial latitude');
check(abs(Geometry::distance([-119.8,39.5],[-119.79,39.5])-858)<3,'Longitude distances account for latitude');
check(abs(Geometry::turn(179,-179)-2)<0.001,'Heading wraps around north');
$straight=[[-119.8,39.5],[-119.8,39.55],[-119.8,39.6]];
$metrics=Geometry::metrics($straight);
check($metrics['curvature']<0.01 && $metrics['straightness']>0.99,'Straight road metrics');
$curve=[];
for ($i=0;$i<=100;$i++) { $a=$i/100*pi()*1.5; $curve[]=[-119.8+cos($a)*0.006,39.5+sin($a)*0.0046]; }
$winding=Geometry::metrics($curve);
check($winding['curvature']>0.3 && $winding['curve_density']>5,'Sustained moderate curve');
$dense=[]; for($i=0;$i<=1000;$i++){ $dense[]=[-119.8,39.5+$i*0.0001]; }
check(abs(Geometry::metrics($dense)['straightness']-$metrics['straightness'])<0.01,'Sampling density independence');
check(Geometry::metrics([[-119.8,39.5],[-119.7999,39.5001],[-119.8,39.5002]])['curvature']<0.1,'Tiny zigzag does not dominate');
function road(int $id,array $nodes,array $points,?string $name='Test Road',int $direction=0): array {
    return compact('id','nodes','points','name','direction')+['ref'=>null,'highway'=>'secondary'];
}
$a=road(1,[1,2],[[-119.8,39.5],[-119.8,39.51]]); $b=road(2,[2,3],[[-119.8,39.51],[-119.8,39.52]]);
$joined=iterator_to_array(RoadGraph::candidates([$a,$b]));
check(count($joined)===1 && count($joined[0]['parts'])===2,'Merge continuous OSM way identifiers');
check(Controls::analyze($joined[0]['nodes'],[],$joined[0]['uses'])['intersections']===0,'OSM way boundary is not an intersection');
$side=road(3,[2,4],[[-119.8,39.51],[-119.79,39.51]],'Side Road');
$joined=iterator_to_array(RoadGraph::candidates([$a,$b,$side]));
check(count($joined)===2,'Intersection preserves main-road continuity without merging side road');
check(Controls::analyze([1,2,3],[],$joined[0]['uses'])['mandatory_stops']===0,'Intersection alone does not imply stop');
$control=Controls::analyze([1,2,3],[4=>['highway'=>'stop']],$joined[0]['uses']);
check($control['mandatory_stops']===0 && $control['uncertain_controls']===0,'Stop on side approach does not stop main road');
$control=Controls::analyze([1,2,3],[2=>['highway'=>'stop']],$joined[0]['uses']);
check($control['mandatory_stops']===0 && $control['uncertain_controls']===1,'Ambiguous junction stop stays uncertain');
check(Controls::analyze([2],[2=>['highway'=>'stop','stop'=>'all']],[])['mandatory_stops']===1,'Explicit all-way stop');
$b['direction']=1;
check(count(iterator_to_array(RoadGraph::candidates([$a,$b])))===2,'Direction changes break candidates');
$m=$winding+['highway'=>'secondary','intersections'=>1,'mandatory_stops'=>0,'uncertain_controls'=>0,'speed_mph'=>45,'paved'=>true];
$good=Scorer::score($m,'twisty'); $bad=$m; $bad['intersections']=30; $bad['mandatory_stops']=10;
check($good['score']>Scorer::score($bad,'twisty')['score'],'Dense interruptions lower score');
$bad=$m; $bad['speed_mph']=null; $bad['paved']=false;
check(Scorer::score($bad,'open')['score']<Scorer::score($m,'open')['score'],'Missing attributes do not improve score');
foreach (Scorer::profiles() as $slug=>$profile) { check(abs(array_sum($profile['weights'])-1)<0.00001,"$slug weights normalized"); check(Scorer::score($m,$slug)['score']<=100,"$slug score bounded"); }
check(abs(Importer::speed('80 km/h')-49.71)<0.1 && Importer::speed('signals')===null,'OSM speed normalization');
check(Validation::search(['zip'=>'12345'])['min_length_miles']===0.0,'Omitted length has no user minimum');
check(Validation::search(['zip'=>'12345','min_length_miles'=>null])['min_length_miles']===0.0,'Null length has no user minimum');
check(Validation::search(['zip'=>'12345','min_length_miles'=>5.5])['min_length_miles']===5.5,'Optional fractional length accepted');
check(rejects(fn()=>Validation::search(['zip'=>'12345','min_length_miles'=>-1])),'Negative minimum length rejected');
check(rejects(fn()=>Validation::search(['zip'=>'12345','min_length_miles'=>31])),'Minimum length cap enforced');
check(Validation::search(['zip'=>'12345'])['max_length_miles']===null,'Omitted maximum has no upper bound');
check(Validation::search(['zip'=>'12345','max_length_miles'=>null])['max_length_miles']===null,'Null maximum has no upper bound');
check(Validation::search(['zip'=>'12345','max_length_miles'=>8.5])['max_length_miles']===8.5,'Optional fractional maximum accepted');
check(Validation::search(['zip'=>'12345','min_length_miles'=>2,'max_length_miles'=>10])['max_length_miles']===10.0,'Valid length range accepted');
check(rejects(fn()=>Validation::search(['zip'=>'12345','min_length_miles'=>10,'max_length_miles'=>5])),'Reversed length range rejected');
check(rejects(fn()=>Validation::search(['zip'=>'12345','min_length_miles'=>5,'max_length_miles'=>5])),'Equal bounds rejected for strict minimum');
foreach ([-1,0,151,INF,[], 'invalid'] as $invalidMaximum) {
    check(rejects(fn()=>Validation::search(['zip'=>'12345','max_length_miles'=>$invalidMaximum])),'Invalid maximum rejected '.json_encode($invalidMaximum,JSON_PARTIAL_OUTPUT_ON_ERROR));
}

check(rejects(fn()=>SweepLine\Auth::credentials(['username'=>'bad user','password'=>'long-password-123'])),'Invalid username rejected');
check(rejects(fn()=>SweepLine\PasswordPolicy::requirements('short',SweepLine\PasswordPolicy::DEFAULTS)),'Short account password rejected by policy');
check(SweepLine\Auth::credentials(['username'=>'MEMBER','email'=>'TEST@example.test','password'=>'long-password-123'])['email']==='test@example.test','Account email normalized');
$tiles=SweepLine\CoveragePlanner::tiles(-119.8,39.52,25);
check(count($tiles)>0 && count($tiles)<100,'Coverage planning bounded and nonempty');
check(count(array_unique(array_column($tiles,'key')))===count($tiles),'Reusable area keys are unique');
check($tiles===SweepLine\CoveragePlanner::tiles(-119.8,39.52,25),'Same search reuses geographic areas');
$children=SweepLine\CoveragePlanner::children($tiles[0]);
check(count($children)===4 && abs(($children[0]['bounds'][2]-$children[0]['bounds'][0])-0.25)<0.000001,'Dense area subdivision');
foreach (SweepLine\CoveragePlanner::tiles(179.8,52,50) as $tile) {
    check($tile['bounds'][0]>=-180 && $tile['bounds'][2]<=180,'Dateline planning stays in longitude bounds');
}


check(SweepLine\Auth::credentials(['username'=>'member','password'=>'long-password-123'])['email']===null,'Email is optional');
check(SweepLine\Auth::credentials(['username'=>'MEMBER','password'=>'long-password-123'])['username']==='member','Username normalized');
check(rejects(fn()=>SweepLine\Auth::credentials(['username'=>'member','email'=>'invalid','password'=>'long-password-123'])),'Optional email validated when supplied');
check(rejects(fn()=>SweepLine\Auth::validatePassword("long-password\0-invalid")),'Null bytes rejected in passwords');

check(Validation::search(['zip'=>'12345'])['radius_miles']===5.0,'Default search radius is five miles');

$diskReport="Filesystem Type 1B-blocks Avail Mounted on\n/dev/sda1 ext4 1000 400 /\n/dev/sda1 ext4 1000 400 /bind\ntmpfs tmpfs 900 800 /run\n/dev/loop0 squashfs 500 0 /snap/test\n/dev/sdb1 xfs 2000 1000 /srv\n";
$volumes=SweepLine\StorageUsage::volumes($diskReport);
check(count($volumes)===2,'Storage excludes memory/image mounts and duplicate devices');
$usage=SweepLine\StorageUsage::summarize(100,$volumes);
check($usage['available'] && $usage['total_bytes']===3000.0 && $usage['free_bytes']===1400.0 && $usage['other_bytes']===1500.0,'Storage categories reconcile to local capacity');
check(!SweepLine\StorageUsage::summarize(5000,$volumes)['available'],'Inconsistent database/disk totals do not produce a pie');
check(!SweepLine\StorageUsage::summarize(100,[])['available'],'Missing volumes are explicitly unavailable');
check(SweepLine\StorageUsage::summarize(0,$volumes)['available'],'Empty database size is supported');

check(Validation::search(['zip'=>'12345'])['max_stops']===1,'Default stop limit is one');

check(rejects(fn()=>Validation::search(['zip'=>'12345','max_stops'=>0])),'Stop limit below one rejected');
check(Validation::search(['zip'=>'12345','max_stops'=>1])['max_stops']===1,'Minimum stop limit accepted');

$exportRoad=['id'=>99,'name'=>'Road <Test> & Friends (west)','ref'=>null,'length_m'=>2000,'mandatory_stops'=>1,'uncertain_controls'=>0,
    'geometry'=>['type'=>'LineString','coordinates'=>[[-120,39],[-119.99,39.01],[-119.98,39.015]]]];
$export=SweepLine\RoadExport::prepare($exportRoad);
$gpx=SweepLine\RoadExport::gpx($export);
$gpxXml=simplexml_load_string($gpx);
check($gpxXml!==false && count($gpxXml->trk->trkseg->trkpt)===3,'GPX preserves all geometry points');
check(str_contains($gpx,'Road &lt;Test&gt; &amp; Friends'),'GPX escapes road names');
$kml=simplexml_load_string(SweepLine\RoadExport::kml($export));
check($kml!==false && str_contains((string)$kml->Document->Placemark->LineString->coordinates,'-120.000000000,39.000000000,0'),'KML uses longitude latitude ordering');
$kmz=SweepLine\RoadExport::kmz($export);$archivePath=tempnam(sys_get_temp_dir(),'export-test-');file_put_contents($archivePath,$kmz);
try {$archive=new ZipArchive();check($archive->open($archivePath)===true,'KMZ is a readable archive');check($archive->getFromName('doc.kml')===SweepLine\RoadExport::kml($export),'KMZ contains standalone doc.kml');$archive->close();}
finally {unlink($archivePath);}
$pdf=SweepLine\RoadExport::pdf($export);
check(str_starts_with($pdf,'%PDF-1.4') && str_contains($pdf,'Road-shape notes'),'PDF includes directions and a real PDF header');
preg_match('/startxref\n(\d+)\n%%EOF/',$pdf,$xref);
check(isset($xref[1]) && substr($pdf,(int)$xref[1],4)==='xref','PDF cross-reference offset is correct');
$longExport=$export;$longExport['notes']=array_fill(0,150,['distance_m'=>500,'distance_since_previous_m'=>100,'angle_degrees'=>65,'description'=>'Right bend']);
preg_match('/\/Type \/Pages \/Count (\d+)/',SweepLine\RoadExport::pdf($longExport),$pageCount);
check(isset($pageCount[1]) && (int)$pageCount[1]>10,'Long bend-note lists paginate into separate roadbook pages');
$oneWay=$exportRoad;$oneWay['parts']=[['name'=>'One way','ref'=>null,'direction'=>-1,'reversed'=>false,'geometry'=>$exportRoad['geometry']]];
$oriented=SweepLine\RoadExport::prepare($oneWay);
check($oriented['geometry']['coordinates'][0]===end($exportRoad['geometry']['coordinates']),'Exports orient one-way roads legally');
check(str_contains($oriented['instructions'][0],'39.015000, -119.980000'),'Directions match exported track orientation');

// Shape-note mileage is based on the track, even when full source ways repeat.
$repeated=$exportRoad;$repeated['parts']=array_fill(0,9,['name'=>'Repeated full way','ref'=>null,'direction'=>0,'reversed'=>false,'geometry'=>$exportRoad['geometry']]);
$fixed=SweepLine\RoadExport::prepare($repeated);
check(abs($fixed['length_m']-SweepLine\Geometry::length($exportRoad['geometry']['coordinates']))<0.01,'Repeated OSM member ways never multiply export mileage');
check(count($fixed['instructions'])<12,'Repeated member ways never duplicate instructions');
$xy=static fn($x,$y)=>[-120+$x/(111195*cos(deg2rad(39))),39+$y/111195];
$right=SweepLine\RoadNotes::analyze([$xy(0,0),$xy(500,0),$xy(500,-500)]);
$left=SweepLine\RoadNotes::analyze([$xy(0,0),$xy(500,0),$xy(500,500)]);
check(count($right['notes'])===1 && $right['notes'][0]['direction']==='right' && abs($right['notes'][0]['angle_degrees']-90)<=5,'Right-angle corner produces an approximate right 90 note');
check(count($left['notes'])===1 && $left['notes'][0]['direction']==='left' && abs($left['notes'][0]['angle_degrees']-90)<=5,'Left-angle corner produces an approximate left 90 note');
$sBend=SweepLine\RoadNotes::analyze([$xy(0,0),$xy(500,0),$xy(500,500),$xy(1000,500)]);
check(count($sBend['notes'])===2 && $sBend['notes'][0]['direction']==='left' && $sBend['notes'][1]['direction']==='right','Opposing bends are separate notes');
$previousDistance=0;
foreach ($sBend['notes'] as $note) {
    check($note['distance_m']>=$previousDistance && $note['end_distance_m']<=$sBend['length_m'],'Note mileages are monotonic and bounded by road length');
    $previousDistance=$note['distance_m'];
}
check(SweepLine\RoadNotes::analyze([$xy(0,0),$xy(1000,0)])['notes']===[],'Straight road has no fabricated turns');
$noisy=[];for($i=0;$i<=300;$i++) {$noisy[]=$xy($i*3,$i%2?0.15:-0.15);}
check(SweepLine\RoadNotes::analyze($noisy)['notes']===[],'Tiny mapping zigzags do not create bend notes');
$arc=[];$denseArc=[];
for($i=0;$i<=90;$i++) {$point=$xy(200*sin(deg2rad($i)),200*(1-cos(deg2rad($i))));$denseArc[]=$point;if($i%5===0)$arc[]=$point;}
$sparseNotes=SweepLine\RoadNotes::analyze($arc);$denseNotes=SweepLine\RoadNotes::analyze($denseArc);
check(count($sparseNotes['notes'])===1 && count($denseNotes['notes'])===1 &&
    abs($sparseNotes['notes'][0]['angle_degrees']-$denseNotes['notes'][0]['angle_degrees'])<=5,'Uneven geometry sampling does not materially change bend angle');
check(str_contains(SweepLine\RoadNotes::instructions([$xy(0,0),$xy(500,0),$xy(500,-500)],$right)[1],'degrees'),'Note text contains mileage and turn angle');

check(str_contains($pdf,'/BaseFont /Helvetica-Bold') && str_contains($pdf,'/BaseFont /Helvetica-Oblique'),'PDF includes bold and supporting font styles');
check(str_contains($pdf,'ROAD OVERVIEW') && str_contains($pdf,'CUMULATIVE MI') && str_contains($pdf,'READING YOUR ROADBOOK'),'PDF groups overview, metrics and bend notes into clear sections');
$mapped=$export;$mapped['basemap']=['roads'=>[['name'=>'Sample cross road','highway'=>'secondary',
    'geometry'=>['type'=>'LineString','coordinates'=>[[-120,39.005],[-119.98,39.005]]]]],'truncated'=>false];
$mapPdf=SweepLine\RoadExport::pdf($mapped);
check(str_contains($mapPdf,'Local OSM street map') && str_contains($mapPdf,'Sample cross road'),'PDF renders a labeled surrounding-road basemap');
$projected=SweepLine\ExportMap::project([-120,39]);$mapBounds=SweepLine\ExportMap::bounds($export['geometry']['coordinates']);
check($projected[0]>=$mapBounds[0] && $projected[0]<=$mapBounds[2] && $projected[1]>=$mapBounds[1] && $projected[1]<=$mapBounds[3],'PDF projection places road endpoints inside map bounds');
check(abs(($mapBounds[2]-$mapBounds[0])/($mapBounds[3]-$mapBounds[1])-532/280)<0.0001,'Basemap viewport preserves map panel aspect ratio');
check(str_contains($pdf,'Surrounding roads unavailable'),'Missing local context is clearly labeled');

$thumb=SweepLine\FavoriteThumbnail::render($mapped);
check(simplexml_load_string($thumb)!==false && str_contains($thumb,'Sample cross road'),'Favorite thumbnail contains a valid SVG street basemap');
check(str_contains($thumb,'#2563a6') && str_contains($thumb,'OpenStreetMap contributors'),'Thumbnail highlights route above basemap with attribution');
$escapedThumb=$mapped;$escapedThumb['name']='<script>alert("bad")</script>';
check(!str_contains(SweepLine\FavoriteThumbnail::render($escapedThumb),'<script>'),'Thumbnail escapes saved road names');

check(!Scorer::qualifies($metrics,'twisty'),'Straight geometry is not eligible for Twisty Roads');
check(Scorer::qualifies($winding,'twisty'),'Sustained winding geometry remains eligible');
$motorway=$m;$motorway['highway']='motorway';
check(Scorer::qualifies($motorway,'twisty'),'Motorways remain eligible when their geometry qualifies');
check(Scorer::qualifies($metrics,'open'),'Open Highway eligibility is unchanged for straight roads');
check(!Scorer::qualifies([],'twisty'),'Missing curvature metrics cannot qualify');
$isolated=['curvature'=>0.3,'curve_density'=>2,'sustained_curve_m'=>30];
check(!Scorer::qualifies($isolated,'twisty'),'An isolated short bend cannot qualify a mostly straight road');
check(Scorer::qualifies(Scorer::profiles()['twisty']['eligibility'],'twisty'),'Exact configured qualification boundaries are inclusive');
$longStraight=$m;$longStraight['length_m']=50000;$longStraight['curvature']=0;
$longStraight['intersections']=0;$longStraight['mandatory_stops']=0;$longStraight['uncertain_controls']=0;$longStraight['highway']='motorway';
$shortWinding=$m;$shortWinding['curvature']=0.8;$shortWinding['length_m']=4000;
check(Scorer::score($shortWinding,'twisty')['score']>Scorer::score($longStraight,'twisty')['score'],'Strong curvature outweighs straight-road length and interruption advantages');

$diagnosticParameters=Validation::search(['zip'=>'12345','min_length_miles'=>5,'max_stops'=>1]);
$excludedRoad=['length_m'=>3000,'mandatory_stops'=>2,'uncertain_controls'=>0,'surface_known_paved'=>false,'metrics'=>['curvature'=>0,'curve_density'=>0,'sustained_curve_m'=>0]];
$reasons=SweepLine\RoadDiagnostics::reasons($excludedRoad,$diagnosticParameters);
check(count($reasons)===5,'Missing-road reasons allow unknown surface by default while reporting other failures');
$eligibleRoad=['length_m'=>12000,'mandatory_stops'=>0,'uncertain_controls'=>1,'surface_known_paved'=>true,'metrics'=>['curvature'=>0.5,'curve_density'=>20,'sustained_curve_m'=>300]];
check(SweepLine\RoadDiagnostics::reasons($eligibleRoad,$diagnosticParameters)===[],'Qualifying candidate has no false rejection reasons');
$unknownMetric=$eligibleRoad;unset($unknownMetric['metrics']['curvature']);
check(count(SweepLine\RoadDiagnostics::reasons($unknownMetric,$diagnosticParameters))===1,'Missing geometry metric is reported explicitly');

check(Validation::search(['zip'=>'12345'])['min_curve_density_score']===2.2,'Curve density defaults to normalized 2.2');
foreach ([0,7.5,Scorer::MAX_CURVE_DENSITY] as $density) {
    check(abs(Validation::search(['zip'=>'12345','min_curve_density'=>$density])['min_curve_density']-(float)$density)<0.000001,'Valid fractional/boundary curve density accepted');
}
foreach ([-1,54,INF,[], 'invalid'] as $density) {
    check(rejects(fn()=>Validation::search(['zip'=>'12345','min_curve_density'=>$density])),'Invalid curve density rejected');
}
$chosen=Validation::search(['zip'=>'12345','min_curve_density'=>7.5]);
check(abs(Scorer::eligibility($chosen)['curve_density']-7.5)<0.000001,'Search-specific density overrides configured gate');
check(Scorer::eligibility(Validation::search(['zip'=>'12345','profile'=>'open','min_curve_density'=>50]))===[],'Open Highways ignores curve-density filter');
$strict=$diagnosticParameters;$strict['surface_filter']='known_paved';
$unknownSurface=$eligibleRoad;$unknownSurface['surface_known_paved']=false;$unknownSurface['surface_status']='unknown';$unknownSurface['surface_values']=[null,'asphalt'];
check(SweepLine\RoadDiagnostics::reasons($unknownSurface,$diagnosticParameters)===[],'Unknown surface is permitted by default');
check(count(SweepLine\RoadDiagnostics::reasons($unknownSurface,$strict))>0,'Confirmed paved policy excludes unknown surfaces');
check(SweepLine\Surface::classify(['asphalt',null])==='unknown','Missing surface is unknown');
check(SweepLine\Surface::classify(['chipseal','paving_stones'])==='paved','Additional paved finishes recognized');
check(SweepLine\Surface::classify(['asphalt','gravel',null])==='unpaved','Any known unpaved piece takes precedence');
check(Validation::search(['zip'=>'12345'])['surface_filter']==='exclude_unpaved','Default surface policy excludes known unpaved only');
check(Validation::search(['zip'=>'12345','paved_only'=>true])['surface_filter']==='known_paved','Legacy paved-only search remains strict');
check(Scorer::densityScore(0)===1.0 && Scorer::densityScore(Scorer::MAX_CURVE_DENSITY)===10.0,'Density scale endpoints');
foreach ([0,11,INF] as $badDensity) {check(rejects(fn()=>Validation::search(['zip'=>'12345','min_curve_density_score'=>$badDensity])),'Normalized density validates range');}
$custom=Validation::search(['zip'=>'12345','min_curve_density_score'=>4,'min_curvature_quality'=>0.3,'min_sustained_curve_m'=>250]);
check(abs(Scorer::eligibility($custom)['curvature']-0.3)<0.000001 && abs(Scorer::eligibility($custom)['sustained_curve_m']-250)<0.000001,'Additional geometry filters are wired');


foreach ([['min_curvature_quality'=>1.1],['min_sustained_curve_m'=>-1],['surface_filter'=>'bogus']] as $badFilter) {
    check(rejects(fn()=>Validation::search(['zip'=>'12345']+$badFilter)),'Invalid advanced filter rejected');
}


check(Scorer::qualityScore(0)===1.0 && Scorer::qualityScore(1)===10.0,'Quality normalized endpoints');
check(abs(Scorer::rawQuality(2.08)-0.12)<0.000001,'Quality default preserves raw threshold');
$imperial=Validation::search(['zip'=>'12345']);
check($imperial['min_sustained_curve_ft']==150 && abs($imperial['min_sustained_curve_m']-45.72)<0.001,'Sustained default uses feet');
foreach ([0,10.1] as $invalidQuality) {check(rejects(fn()=>Validation::search(['zip'=>'12345','min_curvature_quality_score'=>$invalidQuality])),'Invalid normalized quality rejected');}
$tight=[];for($i=0;$i<40;$i++) {$tight[]=[-119+($i%2)*0.00018,39+$i*0.00018];}
check(Geometry::metrics($tight)['curve_density']>0,'Tight turns contribute to curvature');


// Tightness uses local radius: a broad hairpin is different from a tight one.
$tightArc=[];$wideArc=[];
for ($i=0;$i<=90;$i++) {
    $a=M_PI*$i/90;
    $tightArc[]=$xy(25*cos($a),25*sin($a));
    $wideArc[]=$xy(250*cos($a),250*sin($a));
}
$tightNotes=SweepLine\RoadNotes::analyze($tightArc);
$wideNotes=SweepLine\RoadNotes::analyze($wideArc);
check(count($tightNotes['notes'])===1 && $tightNotes['notes'][0]['extremely_tight'],'Small-radius hairpin explicitly flagged');
check(str_contains($tightNotes['notes'][0]['description'],'Extremely tight'),'Tight corner description is explicit');
check(count($wideNotes['notes'])===1 && !$wideNotes['notes'][0]['extremely_tight'],'Broad hairpin is not labeled extremely tight');
$reverseTight=SweepLine\RoadNotes::analyze(array_reverse($tightArc));
check($reverseTight['notes'][0]['extremely_tight'] && $reverseTight['notes'][0]['direction']!==$tightNotes['notes'][0]['direction'],'Tight callout follows travel direction');
$tightExport=$export;$tightExport['notes']=$tightNotes['notes'];
check(str_contains(SweepLine\RoadExport::pdf($tightExport),'Extremely tight'),'PDF includes explicit tight-corner callout');

$denseTile=['key'=>'dense','bounds'=>[-83,39.875,-82.875,40]];
check(SweepLine\CoveragePlanner::canSplit($denseTile),'Dense cells subdivide beyond former cutoff');
check(SweepLine\CoveragePlanner::oversized('Road limit exceeded; use a smaller extract (30k ways max)'),'Road count error triggers subdivision');
check(!SweepLine\CoveragePlanner::oversized('Provider unavailable'),'Ordinary failures retain bounded retries');
$smallTile=$denseTile;
while (SweepLine\CoveragePlanner::canSplit($smallTile)) {$smallTile=SweepLine\CoveragePlanner::children($smallTile)[0];}
check(abs(($smallTile['bounds'][2]-$smallTile['bounds'][0])-0.015625)<0.000001,'Dense subdivision remains bounded');
check(SweepLine\CoveragePlanner::halo($smallTile)<0.006,'Dense cell overlap shrinks with cell size');


$basic=['minimum_length'=>12,'strength'=>'basic','screen_compromised'=>false];
$standard=$basic;$standard['strength']='standard';$strong=$basic;$strong['strength']='strong';
check(SweepLine\PasswordPolicy::requirements('abcdefghijkl',$basic)==='abcdefghijkl','Basic uses length only');
check(rejects(fn()=>SweepLine\PasswordPolicy::requirements('abcdefghijk',$basic)),'Minimum length enforced');
foreach (['Abcdefghijk1','abcdefghij1!','ABCDEFGHIJ1!','Abcdefghijk!'] as $value) {check(SweepLine\PasswordPolicy::requirements($value,$standard)===$value,'Standard accepts any three character types');}
foreach (['abcdefghijkl','Abcdefghijkl','1234567890!!'] as $value) {check(rejects(fn()=>SweepLine\PasswordPolicy::requirements($value,$standard)),'Standard rejects fewer than three types');}
check(SweepLine\PasswordPolicy::requirements('Abcdefghij1!',$strong)==='Abcdefghij1!','Strong accepts all four types');
foreach (['abcdefghij1!','ABCDEFGHIJ1!','Abcdefghijk!','Abcdefghijk1'] as $value) {check(rejects(fn()=>SweepLine\PasswordPolicy::requirements($value,$strong)),'Strong rejects each missing character type');}
check(SweepLine\PasswordPolicy::requirements('x',$strong,true)==='x','SuperAdmin exemption');
check(rejects(fn()=>SweepLine\PasswordPolicy::requirements(str_repeat('a',73),$basic)),'Safe byte limit retained');
$prefixSeen=null;$secret='Offline-test1!';
$screen=new SweepLine\PwnedPasswords(static function($prefix) use (&$prefixSeen,$secret) {$prefixSeen=$prefix;return substr(strtoupper(sha1($secret)),5).":42\r\n";});
check($screen->compromised($secret)===true,'Compromised suffix detected locally');
check($prefixSeen===substr(strtoupper(sha1($secret)),0,5) && strlen($prefixSeen)===5,'Only five hash characters leave checker');
$screen=new SweepLine\PwnedPasswords(static fn()=>substr(strtoupper(sha1($secret)),5).":0");
check($screen->compromised($secret)===false,'Zero-count padding is not a breach');
check((new SweepLine\PwnedPasswords(static fn()=>str_repeat('A',35).":1"))->compromised($secret)===false,'Nonmatching suffix accepted');
check((new SweepLine\PwnedPasswords(static function(){throw new RuntimeException('offline');}))->compromised($secret)===null,'Service failure returns unknown');
check((new SweepLine\PwnedPasswords(static fn()=>'malformed'))->compromised($secret)===null,'Malformed response treated as unavailable');


final class PolicyTestStatement extends PDOStatement {
    public function __construct(private string $value) {}
    public function fetchColumn(int $column=0): mixed {return $this->value;}
}
final class PolicyTestDatabase extends PDO {
    public function __construct() {}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        return new PolicyTestStatement(json_encode(SweepLine\PasswordPolicy::DEFAULTS));
    }
}
$policyDb=new PolicyTestDatabase();
$policyOffline=new SweepLine\PasswordPolicy($policyDb,new SweepLine\PwnedPasswords(static function(){throw new RuntimeException('offline');}));
check($policyOffline->validate('Offline-test1!','member')===SweepLine\PasswordPolicy::WARNING,'Unavailable screening accepts valid password with informational warning');
$policyBreached=new SweepLine\PasswordPolicy($policyDb,new SweepLine\PwnedPasswords(static fn()=>substr(strtoupper(sha1('Offline-test1!')),5).":5"));
check(rejects(fn()=>$policyBreached->validate('Offline-test1!','member')),'Shared policy rejects compromised password');
check($policyBreached->validate('x','superadmin')===null,'Shared policy exempts SuperAdmin from breach screening');

$policyMigration=file_get_contents(SWEEPLINE_ROOT.'/database/migrations/011_password_policy.sql');
check((bool)preg_match('/DO (\\$[A-Za-z_][A-Za-z0-9_]*\\$|\\$\\$)\\s+BEGIN[\\s\\S]*END;\\s*\\1;/',$policyMigration),'Password migration retains matching PostgreSQL dollar quotes and block terminator');

check(SweepLine\SessionSettings::DEFAULT_DAYS===7,'Session defaults to seven days');
$loginTime=1700000000;
check(SweepLine\SessionSettings::valid($loginTime,$loginTime+6*86400,7),'Six inactive days retain valid session');
check(!SweepLine\SessionSettings::valid($loginTime,$loginTime+7*86400,7),'Seven-day deadline requires reauthentication');
check(!SweepLine\SessionSettings::valid($loginTime,$loginTime+6*86400,1),'Shorter setting expires existing session');
check(SweepLine\SessionSettings::valid($loginTime,$loginTime+8*86400,14),'Longer setting extends existing live session');
check(!SweepLine\SessionSettings::valid(0,$loginTime,7),'Missing login time rejected');
check(!SweepLine\SessionSettings::valid($loginTime+1,$loginTime,7),'Future login time rejected');

$overlapRoad=static function($id,$name,$points,$direction=0) {return ['id'=>$id,'name'=>$name,'ref'=>null,'alternate_names'=>[],'length_m'=>Geometry::length($points),'travel_direction'=>$direction,'geometry'=>['type'=>'LineString','coordinates'=>$points]];};
$line=[];for($i=0;$i<=12;$i++)$line[]=$xy($i*200,0);
$whole=$overlapRoad(101,'Main Highway',$line);
$section=$overlapRoad(102,'Other Highway Name',array_slice($line,3,6));
$groups=SweepLine\RoadOverlap::groups([$section,$whole]);
check(count($groups)===1 && count($groups[0]['members'])===2,'Contained road section consolidates with full road');
check(abs(Geometry::length($groups[0]['points'])-Geometry::length($line))<1,'Overlap never doubles length');
$leftPart=$overlapRoad(103,'Western name',array_slice($line,0,9));
$rightPart=$overlapRoad(104,'Eastern name',array_slice($line,5));
$groups=SweepLine\RoadOverlap::groups([$leftPart,$rightPart]);
check(count($groups)===1 && abs(Geometry::length($groups[0]['points'])-Geometry::length($line))<1,'Partially overlapping continuous roads form full union');
$branch=$overlapRoad(105,'Branch',array_merge(array_slice($line,0,5),[$xy(800,400),$xy(800,800)]));
check(count(SweepLine\RoadOverlap::groups([$whole,$branch]))===2,'Shared approach with a fork remains separate');
$parallel=$overlapRoad(106,'Main Highway',array_map(static fn($p)=>[$p[0],$p[1]+0.0001],$line));
check(count(SweepLine\RoadOverlap::groups([$whole,$parallel]))===2,'Parallel carriageways are not merged by name or proximity');
$crossing=$overlapRoad(107,'Crossing',[$xy(1200,-1000),$xy(1200,0),$xy(1200,1000)]);
check(count(SweepLine\RoadOverlap::groups([$whole,$crossing]))===2,'Crossing at one point does not merge roads');
$oneway=$overlapRoad(108,'Forward',$line,1);$opposite=$overlapRoad(109,'Opposite',$line,-1);
check(count(SweepLine\RoadOverlap::groups([$oneway,$opposite]))===2,'Conflicting legal travel directions remain separate');
$reverse=$overlapRoad(110,'Reverse geometry',array_reverse($line),-1);
check(count(SweepLine\RoadOverlap::groups([$oneway,$reverse]))===1,'Equivalent one-way orientations consolidate');
$names=SweepLine\RoadNames::collect([['name'=>'Main Highway','ref'=>'SR 431','alternate_names'=>['Other Name'],'tags'=>['alt_name'=>'Other Name;Historic Name']]],'Main Highway');
check($names===['SR 431','Other Name','Historic Name'],'Alternate names retain refs and OSM aliases without duplicates');
check(SweepLine\Api::memberIds('104,103',103)===[103,104],'Composite member IDs normalize');
check(rejects(fn()=>SweepLine\Api::memberIds('104,invalid',103)),'Invalid composite member IDs rejected');
check(rejects(fn()=>SweepLine\Api::memberIds('104',103)),'Composite must contain requested road');
$aliasedExport=$export;$aliasedExport['alternate_names']=['Other Name','SR 431'];
check(str_contains(SweepLine\RoadExport::gpx($aliasedExport),'Other Name'),'GPX includes alternate names');
check(str_contains(SweepLine\RoadExport::kml($aliasedExport),'Other Name'),'KML/KMZ includes alternate names');
check(str_contains(SweepLine\RoadExport::pdf($aliasedExport),'Other Name'),'PDF includes alternate names');

$reverseExport=SweepLine\RoadExport::prepare($export,'reverse');
check($reverseExport['geometry']['coordinates']===array_reverse($export['geometry']['coordinates']),'Selected reverse direction swaps export endpoints');
check(SweepLine\RoadExport::prepare($reverseExport,'reverse')['geometry']===$reverseExport['geometry'],'Repeated preparation preserves selected direction');
check(SweepLine\RoadExport::prepare($reverseExport,'forward')['geometry']===$export['geometry'],'Switching orientation back restores original track');
$reverseXml=simplexml_load_string(SweepLine\RoadExport::gpx($reverseExport));$reverseXml->registerXPathNamespace('g','http://www.topografix.com/GPX/1/1');$reverseStart=$reverseXml->xpath('//g:wpt')[0];
check(abs((float)$reverseStart['lon']-$reverseExport['geometry']['coordinates'][0][0])<0.000001,'GPX waypoint follows selected Start');
check(str_contains(SweepLine\RoadExport::kml($reverseExport),'<name>Start</name>'),'Reversed KMZ/KML retains endpoint labels');
check(str_starts_with(SweepLine\RoadExport::pdf($reverseExport),'%PDF-'),'Reversed rally notes render as PDF');
foreach([null,[],true,'sideways'] as $badDirection)check(rejects(fn()=>SweepLine\RoadExport::orientation($badDirection)),'Invalid export orientation rejected');
check(SweepLine\BeamPlan::options(['orientation'=>'reverse'])['orientation']==='reverse','BeamNG accepts selected endpoint orientation');
$preferred=SweepLine\RoadNames::prefer($aliasedExport,'Other Name');
check($preferred['name']==='Other Name' && in_array($aliasedExport['name'],$preferred['alternate_names'],true),'Preferred mapped alias becomes primary and preserves former primary');
foreach(['Arbitrary name',[],true] as $badName)check(rejects(fn()=>SweepLine\RoadNames::prefer($aliasedExport,$badName)),'Unmapped preferred name rejected');
$preferred=SweepLine\RoadExport::prepare($preferred,'reverse');
check(str_contains(SweepLine\RoadExport::gpx($preferred),'<name>Other Name</name>') && str_contains(SweepLine\RoadExport::kml($preferred),'<name>Other Name</name>'),'GPX and KMZ use preferred primary name');
check(str_contains(SweepLine\RoadExport::pdf($preferred),'Other Name'),'PDF title uses preferred primary name');
check(str_starts_with(SweepLine\RoadExport::filename($preferred['name'],'pdf'),'Other-Name-'),'Download filename follows preferred name');

$customInput=['start'=>['lon'=>-119.8,'lat'=>39.503],'finish'=>['lon'=>-119.8,'lat'=>39.509],'profile'=>'twisty'];
check(SweepLine\CustomRoads::parameters($customInput)['profile']==='twisty','Custom road validates two separated points and active profile');
foreach([
    [],
    array_replace($customInput,['start'=>['lon'=>181,'lat'=>39]]),
    array_replace($customInput,['finish'=>$customInput['start']]),
    array_replace($customInput,['finish'=>['lon'=>-100,'lat'=>39]]),
    array_replace($customInput,['profile'=>'invalid']),
    array_replace($customInput,['start'=>['lon'=>[], 'lat'=>39]])
] as $bad)check(rejects(fn()=>SweepLine\CustomRoads::parameters($bad)),'Custom road rejects invalid coordinates, range or profile');
check(SweepLine\CustomRoads::identifier(['custom_id'=>12])===12,'Custom road accepts integer identifiers');
foreach([0,1.5,[],null] as $bad)check(rejects(fn()=>SweepLine\CustomRoads::identifier(['custom_id'=>$bad])),'Custom road rejects invalid identifiers');


foreach([-1,0,1] as $direction)foreach([false,true] as $reverse){
    $points=[[-119.8,39.503],[-119.8,39.509]];
    $orientation=SweepLine\CustomRoads::orientation($reverse?0.8:0.2,$reverse?0.2:0.8,$direction);
    $prepared=SweepLine\RoadExport::prepare(['name'=>'Selected test road','geometry'=>['type'=>'LineString','coordinates'=>$points],'parts'=>[['direction'=>$direction,'reversed'=>false]]],$orientation);
    check($prepared['geometry']['coordinates'][0]===$points[$reverse?1:0],'Custom export honors clicked start for direction '.$direction.' reverse '.(int)$reverse);
}


$areaTasks=[];
foreach(['queued','running','complete','retry','failed'] as $state)$areaTasks[]=['kind'=>'roads','status'=>$state,'payload'=>json_encode(['bounds'=>[-120,39,-119.5,39.5],'private'=>'not returned'])];
$areas=SweepLine\DataQueue::areaFeatures($areaTasks);
check(count($areas['features'])===5&&array_column(array_column($areas['features'],'properties'),'status')===['queued','running','complete','queued','failed'],'Preparation map reports real tile states and queued retries');
check($areas['features'][0]['geometry']['coordinates'][0][0]=== $areas['features'][0]['geometry']['coordinates'][0][4],'Preparation tile polygons have closed rings');
check(!str_contains(json_encode($areas),'private'),'Preparation map never exposes provider payload fields');
check(SweepLine\DataQueue::areaFeatures([['kind'=>'zips','payload'=>[]],['kind'=>'roads','payload'=>['bounds'=>[0,0,0,1]]],['kind'=>'roads','payload'=>['bounds'=>['bad',0,1,1]]]])['features']===[],'Preparation map skips non-geographic and invalid tasks');


$customA=[-119.8,39.503];$customB=[-119.8,39.509];
$customArea=SweepLine\CoveragePlanner::customArea($customA,$customB);[$cw,$cs,$ce,$cn]=$customArea['bounds'];
check($cw<$customA[0]&&$ce>$customB[0]&&$cs<$customA[1]&&$cn>$customB[1],'Custom extract tightly encloses both clicks with a continuity buffer');
check(($ce-$cw)*($cn-$cs)<0.01,'Short custom selection remains much smaller than a half-degree tile');
check(Geometry::distance([$customA[0],$cs],$customA)>=1609.344,'Short custom selections include at least one mile of winding-road padding');
check(SweepLine\CoveragePlanner::customArea($customB,$customA)===$customArea,'Reversing custom click order reuses the same geographic extract');
check(SweepLine\CoveragePlanner::halo($customArea)===0.002,'Custom extract uses a small halo instead of broad radius-search overlap');
foreach(SweepLine\CoveragePlanner::children($customArea) as $child)check(SweepLine\CoveragePlanner::halo($child)===0.002,'Dense custom child retains bounded import halo');
check(SweepLine\CoveragePlanner::canSplit(['bounds'=>[0,0,0.01,0.5]]),'Long narrow custom extracts can split when too dense');
check(rejects(fn()=>SweepLine\CoveragePlanner::customArea([179.9,52],[-179.9,52])),'Date-line custom selection cannot accidentally request a world-wide rectangle');

require __DIR__.'/elevations.php';
require __DIR__.'/buildings.php';
require __DIR__.'/guardrails.php';
require __DIR__.'/beamng-enhancements.php';
require __DIR__.'/shadows.php';
require __DIR__.'/intersections.php';
require __DIR__.'/pavement.php';
require __DIR__.'/compute.php';
require __DIR__.'/beamng.php';
if (in_array('--integration',$argv,true)) { require __DIR__.'/integration.php'; }
echo "$checks checks, $failures failures\n"; exit($failures?1:0);
