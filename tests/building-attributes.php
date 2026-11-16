<?php
use SweepLine\{BeamBuildingAttributes as BA,BeamBuildingGeometry as BG,BeamBuildingRoof as BR,BeamBuildingStyle as BS,BeamPolygon as BP,BeamBuildings,BeamRaster,BeamLanes};
check(abs(BA::metres('100 ft')-30.48)<1e-8&&BA::metres('42 m')===42.0,'Building measurements support mapped metres and feet');
check(BA::metres('9999')===null&&BA::metres('12;15')===null,'Invalid and excessive building measurements retain bounded fallbacks');
$a=BA::read(['height'=>'85','building:levels'=>'24','min_height'=>'6','roof:height'=>'5','roof:shape'=>'gabled']);
check($a['height_m']===85.0&&$a['min_height_m']===6.0&&$a['levels']===24.0&&$a['roof_height_m']===5.0,'Mapped tall buildings and elevated parts are not capped at thirty metres');
$b=BA::read(['building:levels'=>'3','roof:shape'=>'hipped','roof:height'=>'2']);
check($b['height_m']===11.0&&$b['sources']['height']!=='height','Floor-count facade estimates add separately mapped roof height');
check(BA::read([])['height_m']===6.0&&BA::read(['height'=>'5','min_height'=>'9'])['min_height_m']===0.0,'Missing and contradictory heights use documented fallbacks');
check(BA::read(['roof:shape'=>'onion'])['warnings']!==[],'Unsupported roof shapes are explicitly reported');
check(BA::color('#f80')==[1,136/255,0]&&BA::color('not a colour')===null,'Facade colors accept bounded named/hex values without unsafe material text');
$appearance=BS::appearance('residential','fixture',['building:material'=>'brick','building:colour'=>'#ff0000','roof:colour'=>'#00ff00','roof:material'=>'metal']);
check($appearance['texture']===BS::TEXTURES['commercial']&&$appearance['color']===[1,0,0]&&$appearance['roof_color']===[0,1,0]&&$appearance['roof_texture']===BS::TEXTURES['industrial'],'Explicit facade and roof colors/materials override generic category variants');
check($appearance['key']!==BS::appearance('residential','fixture',['building:colour'=>'blue'])['key'],'Different mapped appearances cannot overwrite each other in the material registry');

$ring=[[0,0],[20,0],[20,10],[0,10]];$base=[];
foreach(BP::triangles($ring) as $ids)$base[]=array_map(static fn($i)=>$ring[$i],$ids);
foreach(['flat','gabled','hipped','pyramidal','skillion'] as $shape){
    $attr=BA::read(['height'=>'12','roof:height'=>'3','roof:shape'=>$shape]);$roof=BR::prepare($ring,$attr);
    $facets=BR::facets($base,$roof);$area=array_sum(array_map(static fn($t)=>abs(BP::area($t)),$facets));
    check(abs($area-200)<1e-6,'Roof '.$shape.' facets exactly cover the mapped footprint without overlaps');
    $xml=BS::mesh($ring,12,'walls','roofs',['attributes'=>$attr]);$doc=simplexml_load_string($xml,SimpleXMLElement::class,LIBXML_NONET);
    $positions=array_chunk(array_map('floatval',preg_split('/\s+/',trim((string)$doc->xpath('//*[local-name()="source"][@id="positions"]/*[local-name()="float_array"]')[0]))),3);
    check(abs(max(array_column($positions,2))-12)<1e-6&&min(array_column($positions,2))===0.0,'Roof '.$shape.' mesh preserves mapped total height and base');
}
$directed=BR::prepare($ring,BA::read(['height'=>'12','roof:height'=>'3','roof:shape'=>'skillion','roof:direction'=>'90']));
check(BR::rise([0,5],$directed)>BR::rise([20,5],$directed),'Roof direction uses OSM downslope bearing rather than ridge bearing');
$courtyardRoof=BR::prepare($ring,BA::read(['roof:shape'=>'gabled']),true);
check($courtyardRoof['shape']==='flat'&&$courtyardRoof['warnings'],'Complex pitched courtyard roof records its flat fallback');

$geom=static fn($p)=>array_map(static fn($xy)=>['lon'=>$xy[0],'lat'=>$xy[1]],$p);
$outer=[[-84.,41.],[-83.999,41.],[-83.999,41.001],[-84.,41.001],[-84.,41.]];
$inner=[[-83.9998,41.0002],[-83.9992,41.0002],[-83.9992,41.0008],[-83.9998,41.0008],[-83.9998,41.0002]];
$relation=['type'=>'relation','id'=>10,'tags'=>['type'=>'multipolygon','building'=>'yes'],'members'=>[
    ['type'=>'way','ref'=>11,'role'=>'outer','geometry'=>$geom(array_slice($outer,0,3))],
    ['type'=>'way','ref'=>12,'role'=>'outer','geometry'=>$geom(array_reverse(array_slice($outer,2)))],
    ['type'=>'way','ref'=>13,'role'=>'inner','geometry'=>$geom($inner)]
]];
$parsed=BG::parse([$relation,['type'=>'way','id'=>11,'tags'=>['building'=>'yes'],'geometry'=>$geom($outer)]]);
check(count($parsed['features'])===1&&count($parsed['features'][0]['rings'])===2,'Multipolygon courtyard assembles reversed member ways and suppresses duplicate outer outlines');
$incomplete=$relation;array_pop($incomplete['members']);$incomplete['members'][0]['geometry']=array_slice($incomplete['members'][0]['geometry'],0,2);
check(BG::parse([$incomplete])['invalid']===1,'Incomplete multipolygon is rejected instead of silently filling missing boundaries');
check(BG::parse([['type'=>'way','id'=>2,'tags'=>['building:part'=>'yes','height'=>'20'],'geometry'=>$geom($outer)]])['features'][0]['part'],'Standalone mapped building parts are acquired');

$terrain=new BeamRaster(128,200,str_repeat(pack('g',10),16384),str_repeat(chr(100),49152));
$road=[[0,100,10],[190,100,10]];$profiles=array_fill(0,2,BeamLanes::infer(['lanes'=>'2','width'=>'8'],'secondary',0));
$placed=BeamBuildings::place([['points'=>[[20,110],[40,110],[40,130],[20,130]],'tags'=>['height'=>'85','min_height'=>'6'],'osm_id'=>'way/99']],$road,$profiles,$terrain);
check(count($placed['objects'])===1&&$placed['objects'][0]['attributes']['height_m']===85.0&&$placed['objects'][0]['osm_id']==='way/99','Mapped height and source identity reach actual building placement');

$donutOuter=[[0,0],[20,0],[20,20],[0,20]];$donutHole=[[5,5],[15,5],[15,15],[5,15]];
$donut=[];
foreach([[[0,0],[20,0],[15,5],[5,5]],[[20,0],[20,20],[15,15],[15,5]],[[20,20],[0,20],[5,15],[15,15]],[[0,20],[0,0],[5,5],[5,15]]] as $quad)
    foreach(BP::triangles($quad) as $ids)$donut[]=array_map(static fn($i)=>$quad[$i],$ids);
$doc=simplexml_load_string(BS::mesh($donutOuter,12,'wall','roof',['holes'=>[$donutHole],'roof_triangles'=>$donut]),SimpleXMLElement::class,LIBXML_NONET);
$xyz=array_chunk(array_map('floatval',preg_split('/\s+/',trim((string)$doc->xpath('//*[local-name()="source"][@id="positions"]/*[local-name()="float_array"]')[0]))),3);
$roofFaces=$doc->xpath('//*[local-name()="triangles"][@material="roof"]');$solidArea=0.;
foreach($roofFaces as $faces){
    $stride=count($faces->xpath('./*[local-name()="input"]'));$idx=array_map('intval',preg_split('/\s+/',trim((string)$faces->xpath('./*[local-name()="p"]')[0])));
    for($i=0;$i<count($idx);$i+=3*$stride){$tri=[$xyz[$idx[$i]],$xyz[$idx[$i+$stride]],$xyz[$idx[$i+2*$stride]]];if($tri[0][2]===12.0)$solidArea+=abs(BP::area($tri));}
}
check(abs($solidArea-300)<1e-6,'Actual Collada roof excludes courtyard area rather than covering it with a collider');

final class CourtyardProbeStatement extends PDOStatement {
    public function __construct(private string $value){}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
final class CourtyardProbeDatabase extends PDO {
    public function __construct(private string $value,private bool $unsupported=false){}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        if($this->unsupported)throw new PDOException('Fixture GEOS operation unsupported');
        return new CourtyardProbeStatement($this->value);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {throw new PDOException('Fixture triangulation failure');}
}
check(SweepLine\BeamBuildingGeometry::supportsCourtyards(new CourtyardProbeDatabase('{"geometries":[{"type":"Polygon"}]}')),'Courtyard capability requires a successful triangulation operation');
check(!SweepLine\BeamBuildingGeometry::supportsCourtyards(new CourtyardProbeDatabase('{}',true)),'Existing but unsupported GEOS operation is treated as unavailable');
check(!SweepLine\BeamBuildingGeometry::supportsCourtyards(new CourtyardProbeDatabase('{"geometries":[]}')),'Empty triangulation does not count as supported courtyard geometry');
$failedCourtyard=['osm_id'=>'relation/fixture','part'=>false,'points'=>[[0,0],[20,0],[20,20],[0,20]],'holes'=>[[[5,5],[15,5],[15,15],[5,15]]],'tags'=>[]];
$rejectedCourtyard=SweepLine\BeamBuildingGeometry::topology(new CourtyardProbeDatabase('{}'),[$failedCourtyard]);
check($rejectedCourtyard[0]['geometry_rejected']&&$rejectedCourtyard[0]['failure_stage']==='courtyard triangulation','Production rejection retains a safe diagnostic stage instead of inventing a solid courtyard');
$strictFailure=false;
try{SweepLine\BeamBuildingGeometry::topology(new CourtyardProbeDatabase('{}'),[$failedCourtyard],true);}
catch(RuntimeException $e){$strictFailure=$e->getPrevious() instanceof PDOException&&str_contains($e->getMessage(),'courtyard triangulation');}
check($strictFailure,'Native integration mode preserves the original database error before result-field assertions');
