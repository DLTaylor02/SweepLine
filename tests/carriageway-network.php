<?php
// Projected synthetic OSM network, deliberately split and duplicated across import regions.
use SweepLine\{BeamCarriageways,BeamPlan};
$networkPlan=['name'=>'Generated Highway','points'=>[[0,0],[18000,0]],'sources'=>[['points'=>[[0,0],[18000,0]],'direction'=>1,'highway'=>'motorway','tags'=>['ref'=>'TEST 9','oneway'=>'yes','lanes'=>'2']]]];
$networkWays=[];
for($i=0;$i<6;$i++){
    $a=18000-$i*3000;$b=$a-3000;
    $networkWays[]=['id'=>$i+1,'osm_id'=>100+$i,'name'=>'Generated Highway','ref'=>'TEST 9','highway'=>'motorway','direction'=>1,'tags'=>['ref'=>'TEST 9','oneway'=>'yes','lanes'=>'2'],'nodes'=>[1000+$i,1001+$i],'points'=>[[$a,30],[$b,30]]];
}
$regionalDuplicate=$networkWays[2];$regionalDuplicate['id']=999;$networkWays[]=$regionalDuplicate;
$networkPaths=BeamCarriageways::networkCandidates($networkWays,static function($m){});
check(count($networkPaths)===1&&count($networkPaths[0]['sources'])===6,'Opposite source ways join across regions and duplicate OSM ways do not create forks');
$networkMatch=BeamCarriageways::match($networkPlan,$networkPaths,static function($m){});
check(count($networkMatch['tracks'])===1&&$networkMatch['tracks'][0]['length_m']>17990,'Connected opposite carriageway covers the full selected highway instead of its last regional discovery');
$sourcePlan=$networkMatch['tracks'][0];$sourcePlan['sources']=$networkPaths[$sourcePlan['candidate_id']-1]['sources'];
$verified=BeamCarriageways::verifiedRuns($networkPlan,$sourcePlan);
check(count($verified)===1&&count($verified[0])>800,'Full cross-region counterpart retains one-way, width and separation verification');
$backwardWays=$networkWays;
foreach($backwardWays as &$w){$w['points']=array_reverse($w['points']);$w['nodes']=array_reverse($w['nodes']);$w['direction']=-1;$w['tags']['oneway']='-1';}unset($w);
$negativePaths=BeamCarriageways::networkCandidates($backwardWays,static function($m){});
check(count(BeamCarriageways::match($networkPlan,$negativePaths,static function($m){})['tracks'])===1,'Cross-region matching retains reverse-encoded OSM legal direction');
$reverseSelection=$networkPlan;$reverseSelection['points']=array_reverse($reverseSelection['points']);
check(count(BeamCarriageways::match($reverseSelection,$networkPaths,static function($m){})['tracks'])===1,'Cross-region matching is independent of the selected export orientation');
$gapWays=$networkWays;array_splice($gapWays,2,1);array_pop($gapWays);
$gapPaths=BeamCarriageways::networkCandidates($gapWays,static function($m){});
check(count($gapPaths)===2,'A genuine missing OSM connection remains a gap instead of an invented road');
$forkWays=array_slice($networkWays,0,6);$fork=$forkWays[3];$fork['id']=40;$fork['osm_id']=400;$fork['nodes'][1]=9000;$fork['points'][1][1]=45;$forkWays[]=$fork;
$forkPaths=BeamCarriageways::networkCandidates($forkWays,static function($m){});
check(count($forkPaths)>=3,'Ambiguous same-identity motorway forks are kept separate');
$turnWays=array_slice($networkWays,0,2);$turnWays[1]['points'][1]=[15000,3030];
check(count(BeamCarriageways::networkCandidates($turnWays,static function($m){}))===2,'Projected continuity uses actual metre-space headings and rejects a right-angle continuation');


// Exercise the complete fetch/normalization/matching pipeline with generated PDO rows.
class CarriagewayNetworkStatement extends PDOStatement
{
    public array $parameters=[];
    public function __construct(private array $rows){}
    public function execute(?array $params=null):bool {$this->parameters=$params??[];return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $this->rows;}
}
class CarriagewayNetworkDatabase extends PDO
{
    public string $sql='';
    public ?CarriagewayNetworkStatement $statement=null;
    public function __construct(private array $rows){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {$this->sql=$query;return $this->statement=new CarriagewayNetworkStatement($this->rows);}
}
$databaseRows=[];
foreach($networkWays as $way)$databaseRows[$way['osm_id']]=[
 'id'=>$way['id'],'osm_id'=>$way['osm_id'],'name'=>$way['name'],'ref'=>$way['ref'],'highway'=>$way['highway'],'direction'=>(string)$way['direction'],
 'tags'=>json_encode($way['tags']),'node_ids'=>json_encode($way['nodes']),'point_count'=>count($way['points']),'geometry'=>json_encode(['type'=>'LineString','coordinates'=>$way['points']])
];
$networkDatabase=new CarriagewayNetworkDatabase(array_values($databaseRows));$databasePlan=$networkPlan+['epsg'=>32611];
$fetchedNetwork=BeamCarriageways::fetch($networkDatabase,$databasePlan,static function($m){});
check(count($fetchedNetwork['tracks'])===1&&$fetchedNetwork['tracks'][0]['length_m']>17990,'Database fetch pipeline returns a complete cross-region opposite road');
check(str_contains($networkDatabase->sql,'FROM road_segments')&&!str_contains($networkDatabase->sql,'road_candidates')&&count($networkDatabase->statement->parameters)===5,'Fetch uses parameterized raw source geometry instead of discovery candidates');
check($fetchedNetwork['tracks'][0]['candidate_id']===null&&count($fetchedNetwork['tracks'][0]['source_way_ids'])===6,'Fetched network records real source OSM IDs and a separate export-local path');
check($fetchedNetwork['coverage']['matched_selected_span_m']>17990&&!isset($fetchedNetwork['warning']),'Complete local geometry reports full counterpart coverage');
$missingNetwork=BeamCarriageways::fetch(new CarriagewayNetworkDatabase([]),$databasePlan,static function($m){});
check($missingNetwork['tracks']===[]&&str_contains($missingNetwork['warning'],'coverage is partial')&&$missingNetwork['coverage']['unmatched_selected_span_m']===18000.,'Missing opposite geometry is explicitly reported as partial coverage');


$medianRows=$databaseRows;
foreach($medianRows as &$row){$g=json_decode($row['geometry'],true);foreach($g['coordinates'] as &$p)$p[1]=12;unset($p);$row['geometry']=json_encode($g);$tags=json_decode($row['tags'],true);$tags['lanes']='4';$row['tags']=json_encode($tags);}unset($row);
$medianPlan=$databasePlan;$medianPlan['sources'][0]['tags']['lanes']='4';
$medianPair=BeamCarriageways::fetch(new CarriagewayNetworkDatabase(array_values($medianRows)),$medianPlan,static function($m){});
check(count($medianPair['tracks'])===1&&$medianPair['tracks'][0]['length_m']>17990,'Narrow medians do not punch missing sections into a mapped continuous opposite road');
check($medianPair['inferred_width_overlap_samples']>0&&str_contains($medianPair['warning'],'widths overlap'),'Overlapping inferred pavement widths are disclosed for primary-priority clipping');
