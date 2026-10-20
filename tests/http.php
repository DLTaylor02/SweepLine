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
expect(callApi($admin,'POST','/api/search',['zip'=>'12345']),403,'Search requires CSRF');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>51],$csrf),422,'Search validation');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25.5],$csrf),422,'Fractional radius rejected via API');
$data=expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'max_stops'=>5,'record_history'=>true],$csrf),200,'Cached search is immediate');
if (count($data['results'])!==2) { throw new RuntimeException('Cached fixture roads not returned'); }
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'max_length_miles'=>2],$csrf),200,'Maximum length via HTTP');
$jobs=expect(callApi($admin,'GET','/api/admin/data'),200,'SuperAdmin sees preparation status');
if (!isset($jobs['storage']['database_bytes']) || !array_key_exists('available',$jobs['storage'])) { throw new RuntimeException('Storage report missing from Jobs API'); }
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
expect(callApi($member,'GET','/api/admin/users'),403,'Members cannot manage users');
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
