<?php
// Explicit offline OSM/terrain fixtures; no network or game installation required.
use SweepLine\{BeamGuardrails,BeamOsm,BeamRaster,BeamLanes,DownloadFailure,JobCancelled};
$raw=['elements'=>[
    ['type'=>'way','id'=>1,'tags'=>['barrier'=>'guard_rail'],'geometry'=>[['lon'=>-84.1,'lat'=>41.1],['lon'=>-84.0,'lat'=>41.1]]],
    ['type'=>'way','id'=>1,'tags'=>['barrier'=>'guard_rail'],'geometry'=>[['lon'=>-84.1,'lat'=>41.1],['lon'=>-84.0,'lat'=>41.1]]],
    ['type'=>'way','id'=>2,'tags'=>['building'=>'yes'],'geometry'=>[['lon'=>-84.1,'lat'=>41.1],['lon'=>-84.0,'lat'=>41.1]]],
    ['type'=>'way','id'=>3,'tags'=>['barrier'=>'guard_rail'],'geometry'=>[['lon'=>999,'lat'=>41],['lon'=>-84,'lat'=>41]]]
]];
$parsed=BeamGuardrails::parse($raw);
check(count($parsed['lines'])===1&&$parsed['invalid_ways']===1,'Guardrail parser accepts only mapped guardrail ways, rejects invalid coordinates and removes duplicate IDs');
$line=static fn($points,$id=1)=>['points'=>$points,'osm_ids'=>[$id],'tags'=>['barrier'=>'guard_rail']];
$joined=BeamGuardrails::join([$line([[20,106],[40,106]],1),$line([[60,106],[40,106]],2),$line([[60,106],[80,106]],3)]);
check(count($joined)===1&&count($joined[0]['osm_ids'])===3&&count($joined[0]['points'])===4,'Shared OSM way endpoints form one rail with terminals only at physical chain ends');
check(count(BeamGuardrails::join([$line([[20,106],[40,106]]),$line([[40,106],[20,106]],2)]))===1,'Reversed duplicate guardrail geometry is not rendered twice');
$fork=BeamGuardrails::join([$line([[20,106],[40,106]],1),$line([[40,106],[60,106]],2),$line([[40,106],[40,120]],3)]);
check(count($fork)===3,'Ambiguous three-way guardrail junctions are not arbitrarily merged');
$road=[[10,100,20],[390,100,20]];$profiles=array_fill(0,2,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
$raster=new BeamRaster(128,400,str_repeat(pack('g',100),16384),str_repeat(chr(100),49152));
$make=static fn($lines,$exclusions=[],$bridges=[])=>BeamGuardrails::prepare(['lines'=>$lines],$road,$profiles,$raster,80,$exclusions,$bridges,static fn($m)=>null);
$placed=$make([$line([[20,106],[80,106]])]);
check($placed['report']['placed_runs']===1&&$placed['report']['terminals']===2&&abs($placed['report']['length_m']-60)<0.001,'Mapped roadside geometry becomes one 60-metre rail with two rigid terminals');
$horizontal=true;$height=true;foreach($placed['runs'][0]['points'] as $p){$horizontal=$horizontal&&abs($p[1]-106)<1e-8;$height=$height&&abs($p[2]-20.75)<1e-7&&abs($p[3]-20)<1e-7;}
check($horizontal&&$height,'Guardrail horizontal coordinates are retained and height follows shaped terrain');
$onRoad=$make([$line([[20,102],[80,102]])]);
check($onRoad['report']['placed_runs']===0&&isset($onRoad['report']['rejection_reasons']['pavement_or_range']),'Guardrails mapped within pavement are skipped rather than shifted or placed in traffic lanes');
$far=$make([$line([[20,175],[80,175]])]);
check($far['report']['placed_runs']===0,'Mapped guardrails beyond the 200-foot roadside corridor are omitted');
$side=$make([$line([[90,106],[110,106]])],[['points'=>[[100,100],[100,180]],'width_m'=>8]]);
$safe=true;foreach($side['runs'] as $run)foreach($run['points'] as $p)if(abs($p[0]-100)<4.25)$safe=false;
check($safe,'Guardrail segments are split around intersecting pavement instead of blocking side-road entrances');
$bridge=$make([$line([[40,106],[60,106]])],[],[['start_m'=>25,'end_m'=>55]]);
check($bridge['report']['placed_runs']===0&&isset($bridge['report']['rejection_reasons']['existing_bridge_rail']),'Existing generic bridge railings are retained without adding duplicate OSM barriers');
$cancelled=false;try{BeamGuardrails::prepare(['lines'=>[$line([[20,106],[80,106]])]],$road,$profiles,$raster,80,[],[],static function(){throw new JobCancelled('Test');});}catch(JobCancelled){$cancelled=true;}
check($cancelled,'Guardrail processing remains cancellable');
$mesh=BeamGuardrails::mesh($placed['runs'][0]['points'],'fixture_metal');$xml=simplexml_load_string($mesh['xml'],SimpleXMLElement::class,LIBXML_NONET);
check($xml!==false&&str_contains($mesh['xml'],'fixture_metal'),'Guardrails export as valid COLLADA with the assigned metal material');
$coordinates=array_map('floatval',preg_split('/\s+/',trim((string)$xml->xpath('//*[local-name()="source"][@id="positions"]/*[local-name()="float_array"]')[0])));
$positions=array_chunk($coordinates,3);$xs=array_column($positions,0);$ys=array_column($positions,1);$zs=array_column($positions,2);
check(min($xs)+$mesh['position'][0]<=19.901&&max($xs)+$mesh['position'][0]>=80.099&&min($ys)<=-0.109&&max($ys)>=0.109,'Terminal blocks cap both physical ends of the guardrail beam');
check(min($zs)<-0.85&&max($zs)>0.039,'Metal posts extend into terrain and generic capped terminals cover the rail ends');
$file=tempnam(sys_get_temp_dir(),'sl-guardrail-retry-');
try{
    $attempts=0;$messages=[];
    BeamOsm::download('https://example.invalid',$file,'query',static function($m)use(&$messages){$messages[]=$m;},static function($url,$path)use(&$attempts){if(++$attempts<2)throw new DownloadFailure('Fixture 504',true);file_put_contents($path,'{"elements":[]}');},static fn()=>null,'guardrails');
    check($attempts===2&&count(array_filter($messages,static fn($m)=>is_string($m)&&str_contains($m,'Guardrail provider')))>0,'Guardrail download uses bounded transient retries with guardrail-specific progress');
}finally{unlink($file);}
$budget=$make([$line([[-30000,106],[100,106]])]);
check($budget['report']['truncated']&&isset($budget['report']['warning']),'Long source geometry is streamed with an explicit sample-budget warning instead of unbounded allocation');

// SQL/projection contract mock: exercises fetch orchestration without pretending to run PostGIS.
final class GuardrailFixtureStatement extends PDOStatement
{
    public array $parameters=[];
    public function __construct(private mixed $value){}
    public function execute(?array $params=null): bool {$this->parameters=$params??[];return true;}
    public function fetchColumn(int $column=0): mixed {return $this->value;}
}
final class GuardrailFixtureDatabase extends PDO
{
    public array $statements=[];
    public function __construct(private array $responses){}
    public function prepare(string $query,array $options=[]): PDOStatement|false {$statement=new GuardrailFixtureStatement(array_shift($this->responses));$this->statements[]=[$query,$statement];return $statement;}
}
$directory=sys_get_temp_dir().'/sl-guardrail-fetch-'.bin2hex(random_bytes(4));mkdir($directory,0700);
try{
    $db=new GuardrailFixtureDatabase([
        '{"type":"LineString","coordinates":[[-84,41],[-84,41.01],[-84.01,41],[-84,41]]}',
        '{"type":"GeometryCollection","geometries":[{"type":"LineString","coordinates":[[1020,2106],[1080,2106]]}]}'
    ]);
    $post='';
    $fetched=BeamGuardrails::fetch($db,['points'=>[[1000,2000],[1100,2100]],'epsg'=>32616,'bounds'=>[1000,2000,1400,2400]],$directory,static fn($m)=>null,
        static function($url,$file,$limit,$query)use(&$post,$raw){$post=urldecode($query);file_put_contents($file,json_encode($raw));});
    check(count($fetched['lines'])===1&&$fetched['lines'][0]['points'][0]===[20,106],'Guardrail fetch parses provider geometry and converts projected world positions into local map coordinates');
    check(str_contains($post,'way[barrier=guard_rail](poly:')&&str_contains($post,'out geom 1001'),'Guardrail query requests only mapped rails in a bounded corridor with an explicit result cap');
    check(count($db->statements)===2&&$db->statements[1][1]->parameters[1]===32616,'Guardrail projection uses parameterized PostGIS statements and the selected local CRS');
    $failed=BeamGuardrails::fetch(new GuardrailFixtureDatabase(['{"coordinates":[[-84,41],[-84,42],[-85,41],[-84,41]]}']),
        ['points'=>[[0,0],[100,0]],'epsg'=>32616,'bounds'=>[0,0,400,400]],$directory,static fn($m)=>null,static function(){throw new DownloadFailure('Fixture permanent failure',false);});
    check($failed['lines']===[]&&isset($failed['warning']),'Optional guardrail provider failure leaves an explanatory warning and never fabricates barriers');
}finally{foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory);}

$volume=static function($start,$count)use($positions){
    $sum=0;for($i=$start;$i<$start+$count;$i++){[$a,$b,$c]=array_slice($positions,$i*3,3);$sum+=($a[0]*($b[1]*$c[2]-$b[2]*$c[1])+$a[1]*($b[2]*$c[0]-$b[0]*$c[2])+$a[2]*($b[0]*$c[1]-$b[1]*$c[0]))/6;}return $sum;
};
$beamTriangles=8*(count($placed['runs'][0]['points'])-1)+4;
check($volume(0,$beamTriangles)>0&&$volume($beamTriangles,12)>0&&$volume($beamTriangles+12,12)>0,'Beam, posts and terminal blocks have closed outward-facing surfaces for rendering and rigid collision');
