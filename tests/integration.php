<?php
// Requires a dedicated EMPTY test database, never an application database.
use SweepLine\{Database,Api};
if (getenv('SWEEPLINE_TEST_DATABASE')!=='1') { throw new RuntimeException('Set SWEEPLINE_TEST_DATABASE=1 and configure a disposable PostGIS DB'); }
$db=Database::connect();
if ($db->query("SELECT to_regclass('public.regions')")->fetchColumn()) { throw new RuntimeException('Integration database must be empty; refusing existing schema'); }
Database::migrate($db); Database::migrate($db);
check((int)$db->query('SELECT count(*) FROM schema_migrations')->fetchColumn()===count(glob(SWEEPLINE_ROOT.'/database/migrations/*.sql')),'Migrations apply once');
$db->exec("INSERT INTO zip_codes(zip,place,state,location) VALUES ('12345','Synthetic place','ZZ',ST_GeogFromText('SRID=4326;POINT(-119.80 39.52)'))");
$api=new Api($db); check(abs((float)$api->zip('12345')['lat']-39.52)<0.00001,'Database ZIP coordinate lookup');
try { $api->zip('00000'); check(false,'Missing ZIP returns not found'); } catch (OutOfBoundsException $e) { check(true,'Missing ZIP returns not found'); }
$region=$db->query("INSERT INTO regions(slug,name) VALUES ('test','Synthetic test') RETURNING id")->fetchColumn();
$q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'running',?,ST_MakeEnvelope(-121,38,-118,41,4326)) RETURNING *");
$q->execute([$region,__DIR__.'/fixture.osm']); $job=$q->fetch(PDO::FETCH_ASSOC);
(new SweepLine\Importer($db))->run($job);
$data=$api->search(['zip'=>'12345','radius_miles'=>25,'min_length_miles'=>0.25,'max_stops'=>5]);
check(count($data['results'])===2,'Import, score and search real database');
check($data['results'][0]['mandatory_stops']===1,'Merged road counts all-way stop once');
check($api->detail($data['results'][0]['id'],'twisty')['name']==='Synthetic Main','Road detail endpoint service');
check(count($api->search(['zip'=>'12345','max_stops'=>0])['results'])===0,'Stop filter includes mapped stops');
$q->execute([$region,__DIR__.'/fixture.osm']); $badJob=$q->fetch(PDO::FETCH_ASSOC); $badJob['source_path']=__DIR__.'/missing.osm';
try { (new SweepLine\Importer($db))->run($badJob); } catch (Throwable $e) { /* expected */ }
check((int)$db->query('SELECT active_job_id FROM regions')->fetchColumn()===(int)$job['id'],'Failed replacement preserves active data');
$outside=$db->query("SELECT ST_DWithin(ST_GeogFromText('POINT(-119.80 39.52)'),ST_GeogFromText('POINT(-119.80 40.52)'),1609.344)")->fetchColumn();
check(!$outside,'PostGIS radius excludes distant coordinates');

$longestMiles=(float)$db->query('SELECT max(length_m)/1609.344 FROM road_candidates')->fetchColumn();
check(count($api->search(['zip'=>'12345','min_length_miles'=>$longestMiles+0.01])['results'])===0,'Length threshold excludes shorter candidates');
check(count($api->search(['zip'=>'12345','min_length_miles'=>2])['results'])===1,'Length filter retains longer road and excludes short side road');
check(count($api->search(['zip'=>'12345'])['results'])===2,'Omitted length includes short road candidates');
check(count($api->search(['zip'=>'12345','max_length_miles'=>2])['results'])===1,'Maximum length excludes long road and retains short side road');
check(count($api->search(['zip'=>'12345','min_length_miles'=>0.25,'max_length_miles'=>2])['results'])===1,'Combined length bounds filter candidates');
check(count($api->search(['zip'=>'12345','max_length_miles'=>0.3])['results'])===0,'Maximum below all candidate lengths returns no results');
check(count($api->search(['zip'=>'12345','max_length_miles'=>null])['results'])===2,'Null maximum leaves discovery unbounded');
require __DIR__.'/automation.php';
