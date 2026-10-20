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

if (in_array('--integration',$argv,true)) { require __DIR__.'/integration.php'; }
echo "$checks checks, $failures failures\n"; exit($failures?1:0);
