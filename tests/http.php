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
$session=expect(callApi($admin,'GET','/api/session'),200,'Get session and CSRF');
if (!$session['admin_ready']) { throw new RuntimeException('HTTP tests require terminal-provisioned SuperAdmin fixture'); }
$csrf=$session['csrf'];
expect(callApi($admin,'POST','/api/setup',[],$csrf),404,'Browser account claiming removed');
expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'test-password-123']),403,'Login requires CSRF');
expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'wrong-password'],$csrf),401,'Wrong password rejected');
$session=expect(callApi($admin,'POST','/api/login',['username'=>'superadmin','password'=>'test-password-123'],$csrf),200,'Installer-created SuperAdmin login without email');
$csrf=$session['csrf'];
if ($session['user']['role']!=='superadmin' || $session['user']['email']!==null) { throw new RuntimeException('SuperAdmin role or optional email incorrect'); }
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
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'profile'=>'open','max_length_miles'=>2],$csrf),200,'Maximum length via HTTP');
$jobs=expect(callApi($admin,'GET','/api/admin/data'),200,'SuperAdmin sees preparation status');
if (!isset($jobs['storage']['database_bytes']) || !array_key_exists('available',$jobs['storage'])) { throw new RuntimeException('Storage report missing from Jobs API'); }
$budget=expect(callApi($admin,'GET','/api/admin/storage'),200,'SuperAdmin reads budget');
expect(callApi($admin,'PATCH','/api/admin/storage',['database_budget_mb'=>0],$csrf),422,'Invalid budget rejected');
expect(callApi($admin,'PATCH','/api/admin/storage',['database_budget_mb'=>10240]),403,'Storage update requires CSRF');
$created=expect(callApi($admin,'POST','/api/admin/users',['username'=>'member','name'=>'Member','password'=>'member-password-123'],$csrf),200,'SuperAdmin creates user without email');
$id=(int)$created['id'];
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Member pre-login session');
$memberSession=expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),200,'Member login');
$history=expect(callApi($admin,'GET','/api/search-history'),200,'SuperAdmin views own history');
if (count($history)!==1 || $history[0]['parameters']['radius_miles']!=25) { throw new RuntimeException('Search history missing or polluted by polling'); }
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
expect(callApi($member,'GET','/api/admin/data'),403,'Members cannot view job management');
expect(callApi($member,'POST','/api/admin/tasks/1/cancel',[],$memberSession['csrf']),403,'Members cannot cancel jobs');
expect(callApi($admin,'POST','/api/admin/tasks/1/cancel',[]),403,'Job cancellation requires CSRF');
expect(callApi($admin,'GET','/api/admin/tasks/1/cancel'),405,'Job cancellation requires POST');
$db=SweepLine\Database::connect();
$job=(new SweepLine\DataQueue($db))->ensure('auto-http-cancel','roads',['key'=>'auto-http-cancel','bounds'=>[-120,39,-119.5,39.5]]);
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),200,'SuperAdmin cancels queued job');
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),409,'Stale cancellation returns conflict');
$removed=expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/retry',[],$csrf),200,'Removed job retry is a no-op');
if ($removed['queued']) { throw new RuntimeException('Cancelled job resurrected'); }
expect(callApi($admin,'POST','/api/admin/tasks/'.$job['id'].'/cancel',[],$csrf),409,'Removed cancelled job stays absent');
expect(callApi($admin,'PATCH','/api/admin/users/'.$session['user']['id'],['active'=>false],$csrf),422,'SuperAdmin cannot disable own account');
expect(callApi($admin,'PATCH','/api/admin/users/'.$id,['active'=>false],$csrf),200,'Disable member');
expect(callApi($member,'GET','/api/coverage'),401,'Disabled member session invalidated');
expect(callApi($admin,'PATCH','/api/admin/users/'.$id,['active'=>true,'password'=>'new-member-password-123'],$csrf),200,'Enable and reset password');
expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'member-password-123'],$memberSession['csrf']),401,'Old password rejected');
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Reload member CSRF');
expect(callApi($member,'POST','/api/login',['username'=>'member','password'=>'new-member-password-123'],$memberSession['csrf']),200,'New password accepted');
expect(callApi($admin,'POST','/api/logout',[],$csrf),200,'Logout');
expect(callApi($admin,'GET','/api/admin/users'),401,'Logout ends administrative access');
echo "$checks HTTP checks passed\n";