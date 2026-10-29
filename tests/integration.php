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
$data=$api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','radius_miles'=>25,'min_length_miles'=>0.25]);
check(count($data['results'])===2,'Import, score and search real database');
check($data['results'][0]['mandatory_stops']===1,'Merged road counts all-way stop once');
check($api->detail($data['results'][0]['id'],'twisty')['name']==='Synthetic Main','Road detail endpoint service');
foreach ($api->search(['profile'=>'open','zip'=>'12345','max_stops'=>1])['results'] as $road) { check($road['mandatory_stops']+$road['uncertain_controls']<=1,'Stop limit includes uncertain controls'); }
$q->execute([$region,__DIR__.'/fixture.osm']); $badJob=$q->fetch(PDO::FETCH_ASSOC); $badJob['source_path']=__DIR__.'/missing.osm';
try { (new SweepLine\Importer($db))->run($badJob); } catch (Throwable $e) { /* expected */ }
check((int)$db->query('SELECT active_job_id FROM regions')->fetchColumn()===(int)$job['id'],'Failed replacement preserves active data');
$outside=$db->query("SELECT ST_DWithin(ST_GeogFromText('POINT(-119.80 39.52)'),ST_GeogFromText('POINT(-119.80 40.52)'),1609.344)")->fetchColumn();
check(!$outside,'PostGIS radius excludes distant coordinates');

$longestMiles=(float)$db->query('SELECT max(length_m)/1609.344 FROM road_candidates')->fetchColumn();
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','min_length_miles'=>$longestMiles+0.01])['results'])===0,'Length threshold excludes shorter candidates');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','min_length_miles'=>2])['results'])===1,'Length filter retains longer road and excludes short side road');
check($api->search(['profile'=>'open','zip'=>'12345'])['results']===$api->search(['profile'=>'open','zip'=>'12345','max_stops'=>1])['results'],'Default stop filter allows at most one control');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345'])['results'])===2,'Omitted length includes short road candidates');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','max_length_miles'=>2])['results'])===1,'Maximum length excludes long road and retains short side road');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','min_length_miles'=>0.25,'max_length_miles'=>2])['results'])===1,'Combined length bounds filter candidates');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','max_length_miles'=>0.3])['results'])===0,'Maximum below all candidate lengths returns no results');
check(count($api->search(['profile'=>'open','max_stops'=>5,'zip'=>'12345','max_length_miles'=>null])['results'])===2,'Null maximum leaves discovery unbounded');

// Qualification applies before ranking, independently of the generic Open fixtures.
$mainId=(int)$data['results'][0]['id'];
$original=$api->detail($mainId,'twisty');
$db->exec("UPDATE driving_profiles SET definition='{}'::jsonb WHERE slug='twisty'");
$db->exec("UPDATE road_scores SET score=0 WHERE profile='twisty'");
SweepLine\Scorer::refreshScores($db);
$refreshed=$api->detail($mainId,'twisty');
check(abs($refreshed['score']-SweepLine\Scorer::score($refreshed['metrics'],'twisty')['score'])<=0.1,'Existing cached scores refresh from centralized weights');
$before=$db->query('SELECT id,metrics,highway FROM road_candidates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
try {
    $db->exec("UPDATE road_candidates SET metrics=metrics||'{\"curvature\":0,\"curve_density\":0,\"sustained_curve_m\":0}'::jsonb");
    check($api->search(['zip'=>'12345','profile'=>'twisty','max_stops'=>5])['results']===[],'Straight candidates are removed even with favorable other dimensions');
    $db->exec("UPDATE road_candidates SET highway='motorway',metrics=metrics||'{\"curvature\":0.4,\"curve_density\":20,\"sustained_curve_m\":180}'::jsonb WHERE id=".$mainId);
    $eligible=$api->search(['zip'=>'12345','profile'=>'twisty','max_stops'=>5])['results'];
    check(count($eligible)===1 && $eligible[0]['id']===$mainId,'Qualifying geometry passes regardless of motorway classification');
    $db->exec("UPDATE road_candidates SET metrics=metrics||'{\"curve_density\":7.5}'::jsonb WHERE id=".$mainId);
    check(count($api->search(['zip'=>'12345','profile'=>'twisty','max_stops'=>5])['results'])===1,'Default density seven admits qualifying 7.5 sample density');
    check($api->search(['zip'=>'12345','profile'=>'twisty','max_stops'=>5,'min_curve_density'=>8])['results']===[],'User-selected stricter density is applied in SQL');
    $densityDiagnosis=(new SweepLine\RoadDiagnostics($db))->run(['zip'=>'12345','max_stops'=>5,'road'=>'Synthetic','min_curve_density'=>8]);
    check(str_contains(json_encode($densityDiagnosis['candidates']),'requires at least 2.34/10'),'Diagnosis uses the selected density threshold');
    check(count($api->search(['zip'=>'12345','profile'=>'open','max_stops'=>5])['results'])===2,'Twisty qualification does not restrict Open Highway results');
} finally {
    $restore=$db->prepare('UPDATE road_candidates SET metrics=?::jsonb,highway=? WHERE id=?');
    foreach ($before as $row) {$restore->execute([$row['metrics'],$row['highway'],$row['id']]);}
}

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

$diagnostic=(new SweepLine\RoadDiagnostics($db))->run(['zip'=>'12345','radius_miles'=>25,'road'=>'Synthetic']);
check(count($diagnostic['ways'])>0 && count($diagnostic['candidates'])===2,'Missing-road diagnosis finds imported ways and excluded candidates');
check($diagnostic['candidates'][0]['reasons']!==[],'Missing-road diagnosis explains candidates failing twisty eligibility');

$surfaceId=(int)$db->query('SELECT min(id) FROM road_candidates')->fetchColumn();
$oldSurface=$db->query('SELECT surface_status FROM road_candidates WHERE id='.$surfaceId)->fetchColumn();
try {
 $db->exec("UPDATE road_candidates SET surface_status='unknown' WHERE id=".$surfaceId);
 check(count($api->search(['profile'=>'open','zip'=>'12345','max_stops'=>5])['results'])===2,'Default surface policy admits unknown candidate');
 check(count($api->search(['profile'=>'open','zip'=>'12345','max_stops'=>5,'surface_filter'=>'known_paved'])['results'])===1,'Confirmed paved policy excludes unknown candidate');
 $db->exec("UPDATE road_candidates SET surface_status='unpaved' WHERE id=".$surfaceId);
 check(count($api->search(['profile'=>'open','zip'=>'12345','max_stops'=>5])['results'])===1,'Default surface policy excludes known unpaved');
 check(count($api->search(['profile'=>'open','zip'=>'12345','max_stops'=>5,'surface_filter'=>'any'])['results'])===2,'All-surface policy admits known unpaved');
} finally {$db->prepare('UPDATE road_candidates SET surface_status=? WHERE id=?')->execute([$oldSurface,$surfaceId]);}
