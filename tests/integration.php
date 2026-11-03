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

$sourceId=$data['results'][0]['id'];
// Exercise native PDO parameter typing against the real PostGIS overloads.
$beamPlan=SweepLine\BeamPlan::project($db,$api->exportRoad($data['results'][0]['id'],'open'),SweepLine\BeamPlan::options([]));
check($beamPlan['epsg']===32611 && $beamPlan['points'][0][0]>100000 && $beamPlan['points'][0][0]<900000,'BeamNG native prepared projection uses numeric UTM SRID');
check(abs(($beamPlan['bounds'][2]-$beamPlan['bounds'][0])-($beamPlan['bounds'][3]-$beamPlan['bounds'][1]))<0.001,'Database-projected BeamNG footprint remains square');
$buildingTestDir=sys_get_temp_dir().'/sl-buildings-'.bin2hex(random_bytes(6));mkdir($buildingTestDir,0700);
try{
    $buildingDownload=static function($url,$file){file_put_contents($file,json_encode(['elements'=>[['type'=>'way','tags'=>['building'=>'house'],'geometry'=>[['lon'=>-119.8001,'lat'=>39.5201],['lon'=>-119.8000,'lat'=>39.5201],['lon'=>-119.8000,'lat'=>39.5202],['lon'=>-119.8001,'lat'=>39.5202],['lon'=>-119.8001,'lat'=>39.5201]]]]],JSON_THROW_ON_ERROR));};
    $buildingResult=SweepLine\BeamBuildings::fetch($db,$beamPlan,$buildingTestDir,fn($m)=>null,$buildingDownload);
    check(count($buildingResult['footprints'])===1 && !isset($buildingResult['warning']),'Building corridor query and footprint projection work with native PDO/PostGIS');
    $buildingFailure=SweepLine\BeamBuildings::fetch($db,$beamPlan,$buildingTestDir,fn($m)=>null,static function(){throw new RuntimeException('Synthetic provider failure');});
    check($buildingFailure['footprints']===[] && isset($buildingFailure['warning']),'Unavailable building provider is reported without fabricated buildings');
}finally{if(is_file($buildingTestDir.'/buildings.json'))unlink($buildingTestDir.'/buildings.json');rmdir($buildingTestDir);}


$clone=$db->prepare("INSERT INTO road_candidates(job_id,name,ref,highway,surface_known_paved,length_m,mandatory_stops,uncertain_controls,speed_mph,metrics,geom,geometry_key,surface_status,alternate_names)
    SELECT job_id,'Synthetic Alternate Road',ref,highway,surface_known_paved,length_m,mandatory_stops,uncertain_controls,speed_mph,metrics,geom,geometry_key,surface_status,'[]'::jsonb FROM road_candidates WHERE id=? RETURNING id");
$clone->execute([$sourceId]);$cloneId=(int)$clone->fetchColumn();
$db->prepare('INSERT INTO road_scores SELECT ?,profile,score,confidence,dimensions FROM road_scores WHERE candidate_id=?')->execute([$cloneId,$sourceId]);
$db->prepare('INSERT INTO candidate_segments SELECT ?,segment_id,position,reversed FROM candidate_segments WHERE candidate_id=?')->execute([$cloneId,$sourceId]);
try {
    $overlapResults=$api->search(['profile'=>'open','zip'=>'12345','max_stops'=>5])['results'];
    check(count($overlapResults)===2,'Duplicate named geometry becomes one API result');
    $combined=array_values(array_filter($overlapResults,static fn($r)=>count($r['member_ids']??[])>1))[0];
    check(in_array('Synthetic Alternate Road',$combined['alternate_names'],true),'Combined API result exposes alternate name');
    $detail=$api->detail($combined['id'],'open',$combined['member_ids']);
    check(abs($detail['length_m']-$data['results'][0]['length_m'])<1,'Combined detail retains one physical road length');
    check($detail['mandatory_stops']===1,'Combined mapped stops are deduplicated by physical node');
    check($detail['geometry_key']===$api->detail($sourceId,'open')['geometry_key'],'Contained grouping preserves stable favorite geometry identity');
    $exported=$api->exportRoad($combined['id'],'open',false,$combined['member_ids']);
    check(str_contains(SweepLine\RoadExport::gpx(SweepLine\RoadExport::prepare($exported)),'Synthetic Alternate Road'),'Combined API export retains aliases');
} finally {$db->prepare('DELETE FROM road_candidates WHERE id=?')->execute([$cloneId]);}

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
try {
    $duplicate=$db->prepare("INSERT INTO app_users(username,name,password_hash,role) VALUES ('another-root','Another root',?,'superadmin')");
    $duplicate->execute([$administrator['password_hash']]);
    check(false,'Database rejects a second SuperAdmin');
} catch (PDOException $e) {
    check($e->getCode()==='23505','Database unique constraint rejects second SuperAdmin');
}
check((int)$db->query("SELECT count(*) FROM app_users WHERE role='superadmin'")->fetchColumn()===1,'Exactly one installer SuperAdmin remains');

check($db->query("SELECT password_hash FROM app_users WHERE role='superadmin'")->fetchColumn()===$administrator['password_hash'],'Existing password is not reset');

$db->exec("UPDATE app_users SET active=false WHERE role='superadmin'");
$beforeReset=(int)$db->query("SELECT session_version FROM app_users WHERE role='superadmin'")->fetchColumn();
check(SweepLine\SuperAdmin::reset($db,'recovered-password-123')==='superadmin','Explicit operator recovery resets the existing account');
$recovered=$db->query("SELECT * FROM app_users WHERE role='superadmin'")->fetch(PDO::FETCH_ASSOC);
check($recovered['id']===$administrator['id'] && $recovered['active'],'Recovery preserves identity and re-enables account');
check((int)$recovered['session_version']===$beforeReset+1,'Recovery invalidates old web sessions');
check(password_verify('recovered-password-123',$recovered['password_hash']) && !password_verify('test-password-123',$recovered['password_hash']),'New password replaces the lost credential');
check(!SweepLine\SuperAdmin::ensure($db,'unused-installer-password'),'Installer still preserves explicitly reset password');
check(rejects(fn()=>SweepLine\SuperAdmin::reset($db,'')),'Invalid recovery password rejected');
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
$policies=new SweepLine\PasswordPolicy($db,new SweepLine\PwnedPasswords(static function(){throw new RuntimeException('offline');}));
$originalPolicy=$policies->read();$policies->save(SweepLine\PasswordPolicy::DEFAULTS);
check($policies->validate('Offline-test1!','member')===SweepLine\PasswordPolicy::WARNING,'Breach failure accepts valid password with warning');
$breached=new SweepLine\PasswordPolicy($db,new SweepLine\PwnedPasswords(static fn($prefix)=>substr(strtoupper(sha1('Offline-test1!')),5).":5"));
check(rejects(fn()=>$breached->validate('Offline-test1!','member')),'Policy rejects breached password');
check($breached->validate('x','superadmin')===null,'SuperAdmin skips screening and configured requirements');
$policies->save($originalPolicy);
$sessionSettings=new SweepLine\SessionSettings($db);
check($sessionSettings->read()['session_timeout_days']===7,'Migration seeds seven-day lifetime');
$sessionSettings->save(['session_timeout_days'=>14]);
check($sessionSettings->read()['session_timeout_days']===14,'Session lifetime setting persists');
foreach ([0,31,1.5,'invalid'] as $value) {check(rejects(fn()=>$sessionSettings->save(['session_timeout_days'=>$value])),'Session lifetime bounds enforced');}
$sessionSettings->save(['session_timeout_days'=>7]);

$beforeCustomTasks=(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn();
$customCoverage=(new SweepLine\DataQueue($db))->prepareCustom([-119.8,39.503],[-119.8,39.509]);
check($customCoverage['state']==='ready'&&(int)$db->query('SELECT count(*) FROM data_tasks')->fetchColumn()===$beforeCustomTasks,'Custom extraction reuses existing coverage without queuing another download');
$customService=new SweepLine\CustomRoads($db);$customUser=(int)$administrator['id'];
$customSelection=$customService->create($customUser,['start'=>['lon'=>-119.8,'lat'=>39.503],'finish'=>['lon'=>-119.8,'lat'=>39.509],'profile'=>'twisty']);
$custom=$customSelection['road'];
check($custom['custom_id']>0&&$custom['length_m']>600&&$custom['length_m']<750,'Custom selection clips the continuous candidate to the clicked stretch');
check($custom['mandatory_stops']===0&&$custom['uncertain_controls']===0,'Custom clipping excludes controls outside the stretch and does not invent endpoint stops');
check($custom['score']>=0&&$custom['score']<=100&&isset($custom['dimensions']['curvature']),'Custom road recalculates profile scores even below discovery thresholds');
check(count($custom['parts'])===1&&$custom['parts'][0]['geometry']===$custom['geometry'],'Custom export track contains only clipped geometry');
$customReversed=$customService->create($customUser,['start'=>['lon'=>-119.8,'lat'=>39.509],'finish'=>['lon'=>-119.8,'lat'=>39.503],'profile'=>'twisty'])['road'];
check($customReversed['preferred_orientation']==='reverse','Custom click order sets preferred start and finish');
try{$customService->create($customUser,['start'=>['lon'=>-119.8,'lat'=>39.503],'finish'=>['lon'=>-119.792,'lat'=>39.52]]);check(false,'Different-road selection rejected');}
catch(SweepLine\HttpException $e){check($e->getCode()!==0||str_contains($e->getMessage(),'continuous road'),'Different-road selection rejected');}
try{$customService->detail(999999,$custom['custom_id']);check(false,'Custom road owner isolation');}catch(OutOfBoundsException $e){check(true,'Custom road owner isolation');}
$customFavorite=(new SweepLine\Favorites($db))->save($customUser,['road_id'=>$custom['id'],'custom_id'=>$custom['custom_id'],'profile'=>'twisty','search'=>$customSelection['parameters']]);
$customSaved=(new SweepLine\Favorites($db))->detail($customUser,$customFavorite['id'])['road'];
check($customSaved['geometry']===$custom['geometry'],'Custom favorite preserves only selected geometry');
$db->prepare("UPDATE custom_roads SET created_at=now()-interval '8 days' WHERE id=?")->execute([$custom['custom_id']]);
try{$customService->detail($customUser,$custom['custom_id']);check(false,'Custom selection expiry');}catch(OutOfBoundsException $e){check(true,'Custom selection expiry');}
check((new SweepLine\Favorites($db))->detail($customUser,$customFavorite['id'])['road']['geometry']===$custom['geometry'],'Custom favorite survives selection expiry independently');

 // A worker batch can hold its import row while cancellation updates the independent task.
$cancelQueue=new SweepLine\DataQueue($db);
$q=$db->prepare("INSERT INTO import_jobs(region_id,status,source_path,coverage) VALUES (?,'running',?,ST_MakeEnvelope(-121,38,-118,41,4326)) RETURNING id");
$q->execute([$region,__FILE__]);$lockedImport=(int)$q->fetchColumn();
$cancelTask=$cancelQueue->ensure('test-cancel-locked-import','roads',[]);
$db->prepare("UPDATE data_tasks SET status='running',import_job_id=? WHERE id=?")->execute([$lockedImport,$cancelTask['id']]);
$holder=SweepLine\Database::connect();$holder->beginTransaction();
$holder->prepare('SELECT id FROM import_jobs WHERE id=? FOR UPDATE')->execute([$lockedImport]);
try{
    $db->exec("SET lock_timeout='100ms'");
    $cancelResult=$cancelQueue->cancel((int)$cancelTask['id']);
    check($cancelResult['cancel_requested']===true,'Region cancellation succeeds while worker import row is locked');
    $flag=$db->prepare('SELECT cancel_requested FROM data_tasks WHERE id=?');$flag->execute([$cancelTask['id']]);
    check((bool)$flag->fetchColumn(),'Worker can observe task cancellation before cleanup');
    $cancelQueue->purgeCancelled();
    check(true,'Manage cleanup skips locked worker import without waiting');
    $replacement=$cancelQueue->ensure('test-cancel-locked-import','roads',[]);
    check((int)$replacement['id']!==(int)$cancelTask['id'],'Cancelling running work immediately frees its area key for a new search');
}finally{
    if($holder->inTransaction())$holder->rollBack();
    $db->exec("SET lock_timeout='5s'");
}
$db->prepare("UPDATE import_jobs SET status='failed' WHERE id=?")->execute([$lockedImport]);
$db->prepare("UPDATE data_tasks SET status='cancelled' WHERE id=?")->execute([$cancelTask['id']]);
$cancelQueue->purgeCancelled();
$remaining=$db->prepare('SELECT count(*) FROM import_jobs WHERE id=?');$remaining->execute([$lockedImport]);
check((int)$remaining->fetchColumn()===0,'Stopped cancelled import is removed after worker releases it');

$db->exec("INSERT INTO endpoint_elevations(point_key,elevation_m,expires_at) VALUES ('-119.800000,39.500000',304.8,now()+interval '1 day'),('-119.700000,39.600000',NULL,now()+interval '5 minutes') ON CONFLICT(point_key) DO UPDATE SET elevation_m=excluded.elevation_m,expires_at=excluded.expires_at");
$endpointCache=SweepLine\EndpointElevation::load($db,['points'=>[[-119.8,39.5],[-119.7,39.6]]]);
check($endpointCache['elevations_m']===[304.8,null],'Elevation cache returns successful and unavailable samples without provider calls');

$intersectionPlan=SweepLine\BeamPlan::project($db,$api->exportRoad((int)$sourceId,'twisty'),SweepLine\BeamPlan::options([]));
$intersectionContext=SweepLine\BeamIntersections::fetch($db,$intersectionPlan,static fn($m)=>null);
check(count($intersectionContext['branches'])>=1,'Shared-node local topology supplies the synthetic side road to BeamNG context');
foreach($intersectionContext['branches'] as $arm){$length=0;for($i=1;$i<count($arm['points']);$i++)$length+=hypot($arm['points'][$i][0]-$arm['points'][$i-1][0],$arm['points'][$i][1]-$arm['points'][$i-1][1]);check($length<=182.88001,'Database context branches stop at 600 feet');}
