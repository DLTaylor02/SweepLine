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
expect(callApi($anonymous,'GET','/api/coverage'),401,'Coverage requires login');
$session=expect(callApi($admin,'GET','/api/session'),200,'Get session and CSRF');
if (!$session['setup_required']) { throw new RuntimeException('HTTP tests require a fresh initial setup'); }
$csrf=$session['csrf'];
expect(callApi($admin,'POST','/api/setup',['email'=>'owner@example.test','name'=>'Owner','password'=>'test-password-123','setup_code'=>'bad'],$csrf),403,'Wrong setup code rejected');
expect(callApi($admin,'POST','/api/setup',[]),403,'CSRF required for setup');
$code=trim(file_get_contents(SWEEPLINE_ROOT.'/var/setup-code'));
$session=expect(callApi($admin,'POST','/api/setup',['email'=>'owner@example.test','name'=>'Owner','password'=>'test-password-123','setup_code'=>$code],$csrf),200,'Protected SuperAdmin setup');
$csrf=$session['csrf'];
if ($session['user']['role']!=='superadmin' || is_file(SWEEPLINE_ROOT.'/var/setup-code')) { throw new RuntimeException('Setup did not consume code or create correct role'); }
expect(callApi($admin,'POST','/api/setup',['email'=>'other@example.test','password'=>'test-password-123','setup_code'=>$code],$csrf),409,'Setup cannot run twice');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345']),403,'Search requires CSRF');
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>999],$csrf),422,'Search validation');
$data=expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25],$csrf),200,'Cached search is immediate');
if (count($data['results'])!==2) { throw new RuntimeException('Cached fixture roads not returned'); }
expect(callApi($admin,'POST','/api/search',['zip'=>'12345','radius_miles'=>25,'max_length_miles'=>2],$csrf),200,'Maximum length via HTTP');
expect(callApi($admin,'GET','/api/admin/data'),200,'SuperAdmin sees preparation status');
$created=expect(callApi($admin,'POST','/api/admin/users',['email'=>'member@example.test','name'=>'Member','password'=>'member-password-123'],$csrf),200,'SuperAdmin creates user');
$id=(int)$created['id'];
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Member pre-login session');
$memberSession=expect(callApi($member,'POST','/api/login',['email'=>'member@example.test','password'=>'member-password-123'],$memberSession['csrf']),200,'Member login');
expect(callApi($member,'GET','/api/admin/users'),403,'Members cannot manage users');
expect(callApi($admin,'PATCH','/api/admin/users/'.$session['user']['id'],['active'=>false],$csrf),422,'SuperAdmin cannot disable own account');
expect(callApi($admin,'PATCH','/api/admin/users/'.$id,['active'=>false],$csrf),200,'Disable member');
expect(callApi($member,'GET','/api/coverage'),401,'Disabled member session invalidated');
expect(callApi($admin,'PATCH','/api/admin/users/'.$id,['active'=>true,'password'=>'new-member-password-123'],$csrf),200,'Enable and reset password');
expect(callApi($member,'POST','/api/login',['email'=>'member@example.test','password'=>'member-password-123'],$memberSession['csrf']),401,'Old password rejected');
$memberSession=expect(callApi($member,'GET','/api/session'),200,'Reload member CSRF');
expect(callApi($member,'POST','/api/login',['email'=>'member@example.test','password'=>'new-member-password-123'],$memberSession['csrf']),200,'New password accepted');
expect(callApi($admin,'POST','/api/logout',[],$csrf),200,'Logout');
expect(callApi($admin,'GET','/api/admin/users'),401,'Logout ends administrative access');
echo "$checks HTTP checks passed\n";
