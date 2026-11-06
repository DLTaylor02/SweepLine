<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
if (getenv('SWEEPLINE_TEST_DATABASE')!=='1') { exit("Refusing HTTP fixture mutations without SWEEPLINE_TEST_DATABASE=1\n"); }
$base=$argv[1]??'http://127.0.0.1:8099';
if (!preg_match('#^http://(127\\.0\\.0\\.1|localhost):[0-9]+$#D',$base)) { exit("HTTP tests require a loopback-only server\n"); }
function client() { $c=curl_init(); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20]); return $c; }
$admin=client();$member=client();$anonymous=client();$checks=0;
function callApi($client,string $method,string $path,?array $body=null,?string $csrf=null): array {
    global $base;
    curl_setopt_array($client,[CURLOPT_URL=>$base.$path,CURLOPT_POSTFIELDS=>$body===null?null:json_encode((object)$body),
        CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_filter(['Content-Type: application/json',$csrf?'X-CSRF-Token: '.$csrf:null])]);
    $raw=curl_exec($client);
    if ($raw===false) { throw new RuntimeException(curl_error($client)); }
    return ['code'=>(int)curl_getinfo($client,CURLINFO_HTTP_CODE),'data'=>json_decode($raw,true,512,JSON_THROW_ON_ERROR)];
}
function expect(array $response,int $code,string $message): array {
    global $checks;$checks++;
    if ($response['code']!==$code) { throw new RuntimeException($message.': expected '.$code.', got '.json_encode($response)); }
    echo "PASS $message\n";return $response['data'];
}
expect(callApi($anonymous,'GET','/api/health'),200,'Public application health');
expect(callApi($anonymous,'GET','/api/search-history'),401,'Search history requires login');
expect(callApi($anonymous,'GET','/api/zips/nearest?lon=-119.8&lat=39.52'),401,'Map ZIP lookup requires sign-in');
expect(callApi($anonymous,'POST','/api/search-diagnostics',['zip'=>'12345','road'=>'Synthetic']),403,'Road diagnosis requires CSRF before authentication');
expect(callApi($anonymous,'GET','/api/coverage'),401,'Coverage requires login');
expect(callApi($anonymous,'POST','/api/elevations',['points'=>[[0,0],[1,1]]]),403,'Endpoint elevation requires CSRF');
expect(callApi($anonymous,'GET','/api/custom-roads/1'),401,'Custom road snapshots require sign-in');
expect(callApi($anonymous,'POST','/api/custom-roads',[]),403,'Custom road selection requires CSRF');
$session=expect(callApi($admin,'GET','/api/session'),200,'Get session and CSRF');
if (!$session['admin_ready']) { throw new RuntimeException('HTTP tests require terminal-provisioned SuperAdmin fixture'); }
$csrf=$session['csrf'];
expect(callApi($admin,'POST','/api/admin/password-policy',['minimum_length'=>12,'strength'=>'basic','screen_compromised'=>false],$csrf),401,'Anonymous session cannot configure password policy');
expect(callApi($admin,'POST','/api/setup',[],$csrf),404,'Browser account claiming removed');
expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'test-password-123']),403,'Login requires CSRF');
expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'wrong-password'],$csrf),401,'Wrong password rejected');
$session=expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'test-password-123'],$csrf),200,'Installer-created SuperAdmin login without email');
$csrf=$session['csrf'];
if ($session['user']['role']!=='superadmin' || $session['user']['email']!==null) { throw new RuntimeException('SuperAdmin role or optional email incorrect'); }
$sessionCookieExpiry=0;
foreach(curl_getinfo($admin,CURLINFO_COOKIELIST) as $cookieLine) {
    $fields=explode("\t",$cookieLine);
    if(count($fields)===7 && $fields[5]==='SweepLineSession') {$sessionCookieExpiry=(int)$fields[4];}
}
if($sessionCookieExpiry<time()+6*86400 || $sessionCookieExpiry>time()+7*86400+60) {
    throw new RuntimeException('Sign-in did not issue a persistent seven-day cookie');
}

expect(callApi($admin,'POST','/api/admin/password-policy',['minimum_length'=>12,'strength'=>'basic','screen_compromised'=>false],$csrf),200,'Configure offline test password policy');
$picked=expect(callApi($admin,'GET','/api/zips/nearest?lon=-119.8&lat=39.52'),200,'Map ZIP lookup');
if($picked['zip']!=='12345') {throw new RuntimeException('Nearest ZIP incorrect');}
expect(callApi($admin,'GET','/api/zips/nearest?lon=0&lat=91'),422,'Map ZIP coordinate validation');
expect(callApi($admin,'GET','/api/zips/nearest?lon=0&lat=0'),404,'Map ZIP distant point rejected');
expect(callApi($admin,'POST','/api/search-diagnostics',['zip'=>'12345','road'=>'x'],$csrf),422,'Road diagnosis validates query');
$diagnosis=expect(callApi($admin,'POST','/api/search-diagnostics',['zip'=>'12345','radius_miles'=>25,'road'=>'Synthetic'],$csrf),200,'Road diagnosis includes excluded candidates');
if(count($diagnosis['candidates'])!==2) {throw new RuntimeException('Road diagnosis hid excluded fixture roads');}
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','min_curve_density'=>54],$csrf),422,'Curve density upper bound via API');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','min_curve_density'=>7.5],$csrf),200,'Fractional curve density via API');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345']),403,'Search requires CSRF');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>51],$csrf),422,'Search validation');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25.5],$csrf),422,'Fractional radius rejected via API');
$data=expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'max_stops'=>5,'profile'=>'open','record_history'=>true],$csrf),200,'Cached search is immediate');
if (count($data['results'])!==2) { throw new RuntimeException('Cached fixture roads not returned'); }
$exportId=$data['results'][0]['id'];
foreach (['gpx'=>'application/gpx+xml','kmz'=>'application/vnd.google-earth.kmz','pdf'=>'application/pdf'] as $format=>$mime) {
    curl_setopt_array($admin,[CURLOPT_URL=>$base.'/api/roads/'.$exportId.'/export?format='.$format,CURLOPT_POSTFIELDS=>null,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>[]]);
    $bytes=curl_exec($admin);
    if (curl_getinfo($admin,CURLINFO_HTTP_CODE)!==200 || !str_starts_with(curl_getinfo($admin,CURLINFO_CONTENT_TYPE),$mime) || !$bytes) {throw new RuntimeException('Road export failed: '.$format);}
    if ($format==='pdf' && (!str_contains($bytes,'Local OSM street map') || !str_contains($bytes,'/Helvetica-Bold'))) {throw new RuntimeException('Styled PDF basemap or fonts missing');}
    if (($format==='pdf' && !str_starts_with($bytes,'%PDF-')) || ($format==='kmz' && !str_starts_with($bytes,'PK')) || ($format==='gpx' && simplexml_load_string($bytes)===false)) {throw new RuntimeException('Invalid road export: '.$format);}
}
expect(callApi($anonymous,'GET','/api/roads/'.$exportId.'/export?format=gpx'),401,'Exports require authentication');
expect(callApi($admin,'GET','/api/roads/'.$exportId.'/export?format=exe'),422,'Export format validated');
expect(callApi($admin,'GET','/api/roads/'.$exportId.'/export?format=gpx&orientation=sideways'),422,'Export orientation validated');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'profile'=>'open','max_length_miles'=>2],$csrf),200,'Maximum length via HTTP');
$jobs=expect(callApi($admin,'GET','/api/admin/data'),200,'SuperAdmin sees preparation status');
if (!isset($jobs['storage']['database_bytes']) || !array_key_exists('available',$jobs['storage'])) { throw new RuntimeException('Storage report missing from Jobs API'); }
expect(callApi($admin,'GET','/api/admin/session-settings'),200,'SuperAdmin reads session lifetime');
expect(callApi($admin,'POST','/api/admin/session-settings',['session_timeout_days'=>14],$csrf),200,'SuperAdmin changes session lifetime');
expect(callApi($admin,'POST','/api/admin/session-settings',['session_timeout_days'=>0],$csrf),422,'Invalid lifetime rejected');
expect(callApi($admin,'POST','/api/admin/session-settings',['session_timeout_days'=>7]),403,'Session setting requires CSRF');
expect(callApi($admin,'POST','/api/admin/session-settings',['session_timeout_days'=>7],$csrf),200,'Restore default lifetime');
$budget=expect(callApi($admin,'GET','/api/admin/storage'),200,'SuperAdmin reads budget');
expect(callApi($admin,'PATCH','/api/admin/storage',['database_budget_mb'=>0],$csrf),422,'Invalid budget rejected');
expect(callApi($admin,'PATCH','/api/admin/storage',['database_budget_mb'=>10240]),403,'Storage update requires CSRF');
$created=expect(callApi($admin,'POST','/api/admin/users',['username'=>'member','name'=>'Member','password'=>'member-password-123'],$csrf),200,'SuperAdmin creates user without email');
$id=(int)$created['id'];
expect(callApi($admin,'POST','/api/admin/users',['username'=>'another-root','password'=>'test-password-123','role'=>'superadmin'],$csrf),422,'Cannot create another SuperAdmin through API');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['role'=>'superadmin'],$csrf),422,'Cannot promote another account to SuperAdmin');
expect(callApi($admin,'POST','/api/admin/users/'.$session['user']['id'],['role'=>'admin'],$csrf),422,'Installer SuperAdmin cannot be demoted');

$memberSession=expect(callApi($member,'GET','/api/session'),200,'Member pre-login session');
$memberSession=expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),200,'Member login');
$history=expect(callApi($admin,'GET','/api/search-history'),200,'SuperAdmin views own history');
if (count($history)!==1 || $history[0]['parameters']['radius_miles']!=25) { throw new RuntimeException('Search history missing or polluted by polling'); }
if($history[0]['city']!=='Synthetic place' || $history[0]['state']!=='ZZ')throw new RuntimeException('ZIP city missing from History');
$historyId=(int)$history[0]['id'];
expect(callApi($member,'DELETE','/api/search-history/'.$historyId,null,$memberSession['csrf']),404,'History deletion cannot access another user');
expect(callApi($admin,'DELETE','/api/search-history/'.$historyId),403,'History deletion requires CSRF');
expect(callApi($admin,'DELETE','/api/search-history/'.$historyId,null,$csrf),200,'Owner deletes search');
expect(callApi($admin,'DELETE','/api/search-history/'.$historyId,null,$csrf),404,'Deleted search is gone');
if(expect(callApi($admin,'GET','/api/search-history'),200,'Reload deleted history')!==[]) { throw new RuntimeException('Deleted history retained'); }
$memberHistory=expect(callApi($member,'GET','/api/search-history'),200,'Member views own history');
if ($memberHistory!==[]) { throw new RuntimeException('Another user history leaked'); }
$historyDb=SweepLine\Database::connect();$store=new SweepLine\SearchHistory($historyDb);
for($n=0;$n<105;$n++) { $store->record((int)$created['id'],SweepLine\Validation::search(['zip'=>str_pad((string)$n,5,'0',STR_PAD_LEFT)])); }
if(count($store->recent((int)$created['id']))!==100) { throw new RuntimeException('History retention not bounded'); }
$favoriteBody=['road_id'=>$exportId,'profile'=>'twisty','search'=>['zip'=>'12345','radius_miles'=>25,'max_stops'=>5]];
expect(callApi($admin,'POST','/api/favorites',$favoriteBody),403,'Favoriting requires CSRF');
$saved=expect(callApi($admin,'POST','/api/favorites',$favoriteBody,$csrf),200,'Save favorite road');
expect(callApi($admin,'POST','/api/favorites/'.$saved['id'].'/preferences',['orientation'=>'reverse']),403,'Favorite preferences require CSRF');
expect(callApi($admin,'POST','/api/favorites/'.$saved['id'].'/preferences',['orientation'=>'reverse'],$csrf),200,'Owner can save Favorite orientation');
$preferredFavorite=expect(callApi($admin,'GET','/api/favorites/'.$saved['id']),200,'Read Favorite preferences');
if(($preferredFavorite['road']['preferred_orientation']??null)!=='reverse')throw new RuntimeException('Favorite orientation lost');
expect(callApi($admin,'POST','/api/favorites/'.$saved['id'].'/preferences',['preferred_name'=>'Invented road name'],$csrf),422,'Favorite rejects unmapped names');
$savedAgain=expect(callApi($admin,'POST','/api/favorites',$favoriteBody,$csrf),200,'Saving favorite is idempotent');
if($saved['id']!==$savedAgain['id']) {throw new RuntimeException('Duplicate favorite created');}
$favorites=expect(callApi($admin,'GET','/api/favorites'),200,'List favorite roads');
if(count($favorites)!==1 || !isset($favorites[0]['road']['geometry']['coordinates'])) {throw new RuntimeException('Favorite thumbnail geometry missing');}
expect(callApi($member,'GET','/api/favorites/'.$saved['id'].'/thumbnail'),404,'Other account cannot read thumbnail');
expect(callApi($member,'GET','/api/favorites/'.$saved['id']),404,'Other account cannot read favorite');
expect(callApi($member,'DELETE','/api/favorites/'.$saved['id'],null,$memberSession['csrf']),404,'Other account cannot delete favorite');
curl_setopt_array($admin,[CURLOPT_URL=>$base.'/api/favorites/'.$saved['id'].'/thumbnail',CURLOPT_POSTFIELDS=>null,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>[]]);
$thumbnail=curl_exec($admin);
if(curl_getinfo($admin,CURLINFO_HTTP_CODE)!==200 || simplexml_load_string($thumbnail)===false || !str_contains(curl_getinfo($admin,CURLINFO_CONTENT_TYPE),'image/svg+xml')) {throw new RuntimeException('Favorite geographic thumbnail failed');}
$favoriteDb=SweepLine\Database::connect();
$jobQuery=$favoriteDb->prepare('SELECT job_id FROM road_candidates WHERE id=?');$jobQuery->execute([$exportId]);$snapshotJob=(int)$jobQuery->fetchColumn();
$favoriteDb->exec('UPDATE regions SET active_job_id=null WHERE active_job_id='.$snapshotJob);
try {
    $snapshot=expect(callApi($admin,'GET','/api/favorites/'.$saved['id']),200,'Favorite survives inactive source dataset');
    if($snapshot['road']['id']!=$exportId || $snapshot['road']['favorite_id']!=$saved['id']) {throw new RuntimeException('Favorite snapshot identity lost');}
    curl_setopt_array($admin,[CURLOPT_URL=>$base.'/api/favorites/'.$saved['id'].'/export?format=gpx',CURLOPT_POSTFIELDS=>null,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>[]]);
    $savedGpx=curl_exec($admin);
    if(curl_getinfo($admin,CURLINFO_HTTP_CODE)!==200 || simplexml_load_string($savedGpx)===false) {throw new RuntimeException('Inactive favorite export failed');}
} finally {
    $favoriteDb->exec('UPDATE regions SET active_job_id='.$snapshotJob.' WHERE id=(SELECT region_id FROM import_jobs WHERE id='.$snapshotJob.')');
}
expect(callApi($admin,'DELETE','/api/favorites/'.$saved['id'],null,$csrf),200,'Owner removes favorite');
expect(callApi($admin,'GET','/api/favorites/'.$saved['id']),404,'Removed favorite is gone');
expect(callApi($member,'GET','/api/admin/users'),403,'Members cannot manage users');
expect(callApi($member,'GET','/api/admin/storage'),403,'Members cannot read storage settings');
expect(callApi($member,'POST','/api/admin/session-settings',['session_timeout_days'=>14],$memberSession['csrf']),403,'User cannot alter session lifetime');
expect(callApi($member,'GET','/api/admin/data'),403,'Members cannot view job management');
expect(callApi($member,'POST','/api/admin/tasks/1/cancel',[],$memberSession['csrf']),403,'Members cannot cancel jobs');

expect(callApi($admin,'POST','/api/admin/users/'.$id,['role'=>'admin'],$csrf),200,'SuperAdmin promotes Admin');
expect(callApi($member,'GET','/api/admin/users'),401,'Role change invalidates session');
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Admin session token');
$memberSession=expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),200,'Promoted Admin login');
expect(callApi($member,'GET','/api/admin/users'),200,'Admin accesses Users');
expect(callApi($member,'GET','/api/admin/data'),403,'Admin cannot access Manage jobs');
expect(callApi($member,'GET','/api/admin/storage'),403,'Admin cannot access Manage settings');
expect(callApi($member,'POST','/api/admin/session-settings',['session_timeout_days'=>14],$memberSession['csrf']),403,'Admin cannot alter session lifetime');
expect(callApi($member,'POST','/api/admin/tasks/1/cancel',[],$memberSession['csrf']),403,'Admin cannot cancel jobs');
expect(callApi($member,'POST','/api/admin/users/'.$session['user']['id'],['password'=>'changed-password-123'],$memberSession['csrf']),403,'Admin cannot reset SuperAdmin');
expect(callApi($member,'POST','/api/admin/users/'.$id,['role'=>'member'],$memberSession['csrf']),403,'Admin cannot assign roles');
expect(callApi($member,'POST','/api/admin/users',['username'=>'operator','password'=>'operator-password-123','role'=>'admin'],$memberSession['csrf']),403,'Admin cannot create privileged accounts');
$ordinary=expect(callApi($member,'POST','/api/admin/users',['username'=>'ordinary','password'=>'ordinary-password-123'],$memberSession['csrf']),200,'Admin creates User');
expect(callApi($member,'POST','/api/admin/users/'.$ordinary['id'],['active'=>false],$memberSession['csrf']),200,'Admin manages User');
$private=expect(callApi($admin,'POST','/api/favorites',$favoriteBody,$csrf),200,'SuperAdmin saves private road');
expect(callApi($member,'GET','/api/favorites/'.$private['id']),404,'Admin cannot access another owner favorite');
expect(callApi($member,'POST','/api/favorites/'.$private['id'].'/preferences',['orientation'=>'reverse'],$memberSession['csrf']),404,'Favorite preferences remain owner-only');
expect(callApi($member,'GET','/api/favorites/'.$private['id'].'/thumbnail'),404,'Admin cannot access another owner thumbnail');
expect(callApi($member,'GET','/api/favorites/'.$private['id'].'/export?format=gpx'),404,'Admin cannot export another owner favorite');
expect(callApi($admin,'DELETE','/api/favorites/'.$private['id'],null,$csrf),200,'Owner removes private road');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['role'=>'member'],$csrf),200,'SuperAdmin revokes Admin');
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Demoted User token');
$memberSession=expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),200,'Demoted User login');
expect(callApi($admin,'POST','/api/admin/tasks/1/cancel',[]),403,'Job cancellation requires CSRF');
expect(callApi($admin,'GET','/api/admin/tasks/1/cancel'),405,'Job cancellation requires POST');
$db=SweepLine\Database::connect();
$job=(new SweepLine\DataQueue($db))->ensure('auto-http-cancel','roads',['key'=>'auto-http-cancel','bounds'=>[-120,39,-119.5,39.5]]);
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),200,'SuperAdmin cancels queued job');
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),409,'Stale cancellation returns conflict');
$removed=expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/retry',[],$csrf),200,'Removed job retry is a no-op');
if ($removed['queued']) { throw new RuntimeException('Cancelled job resurrected'); }
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),409,'Removed cancelled job stays absent');
expect(callApi($admin,'POST','/api/admin/users/'.$session['user']['id'],['active'=>false],$csrf),422,'SuperAdmin cannot disable own account');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['active'=>false],$csrf),200,'Disable member');
expect(callApi($member,'GET','/api/coverage'),401,'Disabled member session invalidated');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['active'=>true,'password'=>'new-member-password-123'],$csrf),200,'Enable and reset password');
expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),401,'Old password rejected');
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Reload member CSRF');
expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'new-member-password-123'],$memberSession['csrf']),200,'New password accepted');

$db->exec('DELETE FROM auth_attempts');
expect(callApi($member,'POST','/api/admin/password-policy',['minimum_length'=>1,'strength'=>'basic','screen_compromised'=>false],$memberSession['csrf']),403,'User cannot change password policy');
expect(callApi($admin,'POST','/api/admin/password-policy',['minimum_length'=>12,'strength'=>'strong','screen_compromised'=>false],$csrf),200,'SuperAdmin selects Strong policy');
expect(callApi($admin,'POST','/api/admin/users',['username'=>'weak-user','password'=>'abcdefghijkl'],$csrf),422,'Creation enforces active policy');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['password'=>'abcdefghijkl'],$csrf),422,'Admin reset enforces active policy');
expect(callApi($admin,'POST','/api/admin/users/'.$session['user']['id'],['must_change_password'=>true],$csrf),422,'SuperAdmin cannot require own password change');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['must_change_password'=>true],$csrf),200,'SuperAdmin flags User without logging them out');
$blocked=expect(callApi($member,'GET','/api/search-history'),403,'Flag blocks authenticated discovery APIs');
if(($blocked['redirect']??null)!=='/password')throw new RuntimeException('Missing password redirect');
expect(callApi($member,'GET','/api/profiles'),403,'Flag blocks profile API');
$restricted=expect(callApi($member,'GET','/api/session'),200,'Flagged user remains signed in');
if(empty($restricted['user']['must_change_password']))throw new RuntimeException('Flag not visible in session');
curl_setopt_array($member,[CURLOPT_URL=>$base.'/history',CURLOPT_POSTFIELDS=>null,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>[]]);
curl_exec($member);
if(curl_getinfo($member,CURLINFO_HTTP_CODE)!==303)throw new RuntimeException('Flagged page did not redirect');
curl_setopt_array($member,[CURLOPT_URL=>$base.'/password',CURLOPT_POSTFIELDS=>null,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>[]]);
curl_exec($member);
if(curl_getinfo($member,CURLINFO_HTTP_CODE)!==200)throw new RuntimeException('Password page redirect loop');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['must_change_password'=>false],$csrf),200,'Admin clears requirement');
expect(callApi($member,'GET','/api/search-history'),200,'Cleared requirement restores access without logout');
expect(callApi($admin,'POST','/api/admin/users/'.$id,['must_change_password'=>true],$csrf),200,'Flag again for own change');
$change=['current_password'=>'new-member-password-123','new_password'=>'Updated-pass123!','confirmation'=>'Updated-pass123!'];
expect(callApi($member,'POST','/api/password/change',$change),403,'Own password change requires CSRF');
expect(callApi($member,'POST','/api/password/change',array_replace($change,['current_password'=>'incorrect']),$memberSession['csrf']),422,'Wrong current password rejected');
expect(callApi($member,'POST','/api/password/change',array_replace($change,['confirmation'=>'different']),$memberSession['csrf']),422,'Confirmation mismatch rejected');
expect(callApi($member,'POST','/api/password/change',array_replace($change,['new_password'=>'new-member-password-123','confirmation'=>'new-member-password-123']),$memberSession['csrf']),422,'Current password reuse rejected');
expect(callApi($member,'POST','/api/password/change',array_replace($change,['new_password'=>'abcdefghijkl','confirmation'=>'abcdefghijkl']),$memberSession['csrf']),422,'Own change enforces Strong policy');
$beforeCookies=curl_getinfo($member,CURLINFO_COOKIELIST);
expect(callApi($member,'POST','/api/password/change',$change,$memberSession['csrf']),200,'Own password change succeeds');
if($beforeCookies===curl_getinfo($member,CURLINFO_COOKIELIST))throw new RuntimeException('Session identifier was not rotated');
$changed=expect(callApi($member,'GET','/api/session'),200,'Changed session remains authenticated');
if(!$changed['user'] || $changed['user']['must_change_password'] || $changed['csrf']===$memberSession['csrf'] || empty($changed['notice']))throw new RuntimeException('Change failed to clear flag, rotate session token, or flash success');
expect(callApi($member,'GET','/api/search-history'),200,'Successful change restores access');
expect(callApi($admin,'POST','/api/password/change',['current_password'=>'test-password-123','new_password'=>'x','confirmation'=>'x'],$csrf),200,'SuperAdmin self-change is policy exempt');
$session=expect(callApi($admin,'GET','/api/session'),200,'SuperAdmin retained after change');$csrf=$session['csrf'];
expect(callApi($admin,'POST','/api/password/change',['current_password'=>'x','new_password'=>'test-password-123','confirmation'=>'test-password-123'],$csrf),200,'Restore SuperAdmin password');
$session=expect(callApi($admin,'GET','/api/session'),200,'SuperAdmin refreshed token');$csrf=$session['csrf'];

expect(callApi($admin,'POST','/api/logout',[],$csrf),200,'Logout');
expect(callApi($admin,'GET','/api/admin/users'),401,'Logout ends administrative access');
echo "$checks HTTP checks passed\n";