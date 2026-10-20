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
$data=$api->search(['max_stops'=>5,'zip'=>'12345','radius_miles'=>25,'min_length_miles'=>0.25]);
check(count($data['results'])===2,'Import, score and search real database');
check($data['results'][0]['mandatory_stops']===1,'Merged road counts all-way stop once');
check($api->detail($data['results'][0]['id'],'twisty')['name']==='Synthetic Main','Road detail endpoint service');
foreach ($api->search(['zip'=>'12345','max_stops'=>1])['results'] as $road) { check($road['mandatory_stops']+$road['uncertain_controls']<=1,'Stop limit includes uncertain controls'); }
$q->execute([$region,__DIR__.'/fixture.osm']); $badJob=$q->fetch(PDO::FETCH_ASSOC); $badJob['source_path']=__DIR__.'/missing.osm';
try { (new SweepLine\Importer($db))->run($badJob); } catch (Throwable $e) { /* expected */ }
check((int)$db->query('SELECT active_job_id FROM regions')->fetchColumn()===(int)$job['id'],'Failed replacement preserves active data');
$outside=$db->query("SELECT ST_DWithin(ST_GeogFromText('POINT(-119.80 39.52)'),ST_GeogFromText('POINT(-119.80 40.52)'),1609.344)")->fetchColumn();
check(!$outside,'PostGIS radius excludes distant coordinates');

$longestMiles=(float)$db->query('SELECT max(length_m)/1609.344 FROM road_candidates')->fetchColumn();
check(count($api->search(['max_stops'=>5,'zip'=>'12345','min_length_miles'=>$longestMiles+0.01])['results'])===0,'Length threshold excludes shorter candidates');
check(count($api->search(['max_stops'=>5,'zip'=>'12345','min_length_miles'=>2])['results'])===1,'Length filter retains longer road and excludes short side road');
check($api->search(['zip'=>'12345'])['results']===$api->search(['zip'=>'12345','max_stops'=>1])['results'],'Default stop filter allows at most one control');
check(count($api->search(['max_stops'=>5,'zip'=>'12345'])['results'])===2,'Omitted length includes short road candidates');
check(count($api->search(['max_stops'=>5,'zip'=>'12345','max_length_miles'=>2])['results'])===1,'Maximum length excludes long road and retains short side road');
check(count($api->search(['max_stops'=>5,'zip'=>'12345','min_length_miles'=>0.25,'max_length_miles'=>2])['results'])===1,'Combined length bounds filter candidates');
check(count($api->search(['max_stops'=>5,'zip'=>'12345','max_length_miles'=>0.3])['results'])===0,'Maximum below all candidate lengths returns no results');
check(count($api->search(['max_stops'=>5,'zip'=>'12345','max_length_miles'=>null])['results'])===2,'Null maximum leaves discovery unbounded');
require __DIR__.'/automation.php';

check(!SweepLine\SuperAdmin::exists($db),'Fresh installation has no SuperAdmin');
check(SweepLine\SuperAdmin::ensure($db,'test-password-123'),'Terminal provisioning creates SuperAdmin');
$administrator=$db->query("SELECT * FROM app_users WHERE role='superadmin'")->fetch(PDO::FETCH_ASSOC);
check($administrator['username']==='superadmin' && $administrator['email']===null,'SuperAdmin needs no email');
check(password_verify('test-password-123',$administrator['password_hash']),'SuperAdmin password stored as a hash');
check(!SweepLine\SuperAdmin::ensure($db,'different-password-123'),'Repeated provisioning preserves existing administrator');
check($db->query("SELECT password_hash FROM app_users WHERE role='superadmin'")->fetchColumn()===$administrator['password_hash'],'Existing password is not reset');

$db->exec("UPDATE app_users SET active=false WHERE role='superadmin'");
$beforeReset=(int)$db->query("SELECT session_version FROM app_users WHERE role='superadmin'")->fetchColumn();
check(SweepLine\SuperAdmin::reset($db,'recovered-password-123')==='superadmin','Explicit operator recovery resets the existing account');
$recovered=$db->query("SELECT * FROM app_users WHERE role='superadmin'")->fetch(PDO::FETCH_ASSOC);
check($recovered['id']===$administrator['id'] && $recovered['active'],'Recovery preserves identity and re-enables account');
check((int)$recovered['session_version']===$beforeReset+1,'Recovery invalidates old web sessions');
check(password_verify('recovered-password-123',$recovered['password_hash']) && !password_verify('test-password-123',$recovered['password_hash']),'New password replaces the lost credential');
check(!SweepLine\SuperAdmin::ensure($db,'unused-installer-password'),'Installer still preserves explicitly reset password');
check(rejects(fn()=>SweepLine\SuperAdmin::reset($db,'short')),'Invalid recovery password rejected');
// Restore the explicit fixture password expected by the separate HTTP suite.
SweepLine\SuperAdmin::reset($db,'test-password-123');

$nearest=$api->nearestZip(['lon'=>-119.80,'lat'=>39.52]);
check($nearest['zip']==='12345' && (float)$nearest['distance_m']<1,'Nearest ZIP uses real geographic distance');
check(rejects(fn()=>$api->nearestZip(['lon'=>-181,'lat'=>39])),'Nearest ZIP validates coordinates');
try {$api->nearestZip(['lon'=>0,'lat'=>0]);check(false,'Distant point has no ZIP');} catch (OutOfBoundsException $e) {check(true,'Distant point has no nearby ZIP');}
