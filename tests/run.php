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
check(rejects(fn()=>SweepLine\Auth::credentials(['username'=>'member','password'=>'short'])),'Short account password rejected');
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

if (in_array('--integration',$argv,true)) { require __DIR__.'/integration.php'; }
echo "$checks checks, $failures failures\n"; exit($failures?1:0);
