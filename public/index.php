<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if (!is_string($path)) { http_response_code(400); exit('Invalid request'); }
// PHP built-in development server may serve public assets; production uses NGINX.
if (PHP_SAPI==='cli-server' && preg_match('#^/assets/[a-zA-Z0-9_.-]+$#',$path) && is_file(__DIR__.$path)) { return false; }
if (!str_starts_with($path,'/api/')) {
    require dirname(__DIR__).'/src/bootstrap.php';
    $pageDb=SweepLine\Database::connect();SweepLine\Auth::start($pageDb);
    $pageUser=(new SweepLine\Auth($pageDb))->user();
    if (($pageUser['must_change_password']??false) && $path!=='/password') {header('Location: /password',true,303);exit;}
    if($path==='/regions'&&($pageUser['role']??null)!=='superadmin'){http_response_code(403);exit('SuperAdmin access required.');}
    session_write_close();
    if ($path==='/jobs') {header('Location: /manage',true,302);exit;}
    if (!in_array($path,['/','/users','/manage','/history','/favorites','/password','/regions'],true)) { http_response_code(404); exit('Not found'); }
    header('Cache-Control: no-store');
    require __DIR__.'/home.php'; exit;
}
require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($path==='/api/setup') { throw new OutOfBoundsException('Endpoint not found'); }
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    $postPaths=['/api/admin/regions/action','/api/elevations','/api/custom-roads','/api/search','/api/search-diagnostics','/api/login','/api/logout','/api/admin/users','/api/favorites','/api/password/change','/api/admin/password-policy','/api/admin/session-settings','/api/beamng'];
    $expected=in_array($path,$postPaths,true)?'POST':'GET';
    if(preg_match('#^/api/admin/search-jobs/[1-9]\\d{0,17}/cancel$#',$path))$expected='POST';
    if ($path==='/api/admin/storage' && $method==='PATCH') {$expected='PATCH';}
    if ($path==='/api/admin/session-settings' && $method==='GET') {$expected='GET';}
    if ($path==='/api/admin/users' && $method==='GET') { $expected='GET'; }
    if (preg_match('#^/api/admin/users/[1-9]\\d{0,17}$#',$path)) { $expected='POST'; }
    if (preg_match('#^/api/admin/tasks/[1-9]\\d{0,17}/(?:retry|cancel)$#',$path)) { $expected='POST'; }
    if (preg_match('#^/api/admin/imports/[1-9]\\d{0,17}/cancel$#',$path)) { $expected='POST'; }
    if (preg_match('#^/api/search-history/[1-9]\\d{0,17}$#',$path)) { $expected=$method==='DELETE'?'DELETE':'GET'; }
    if ($path==='/api/favorites' && $method==='GET') { $expected='GET'; }
    if (preg_match('#^/api/favorites/[1-9]\\d{0,17}$#',$path)) { $expected=$method==='DELETE'?'DELETE':'GET'; }
    if(preg_match('#^/api/favorites/[1-9]\\d{0,17}/preferences$#',$path))$expected='POST';
    if(preg_match('#^/api/(?:admin/)?beamng/[a-f0-9]{32}/cancel$#',$path))$expected='POST';
    if ($method!==$expected) { header('Allow: '.$expected); throw new SweepLine\HttpException(405,'Method not allowed'); }
    $input=[];
    if (in_array($method,['POST','PATCH','DELETE'],true)) {
        $body=file_get_contents('php://input',false,null,0,8193);
        if (strlen($body)>8192) { throw new InvalidArgumentException('Request too large'); }
        try { $input=json_decode($body?:'{}',true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('Invalid JSON'); }
        if (!is_array($input) || ($input!==[] && array_is_list($input))) { throw new InvalidArgumentException('Expected a JSON object'); }
    }
    $db=SweepLine\Database::connect(); $api=new SweepLine\Api($db);
    if ($path==='/api/health') {
        $schema=$db->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1')->fetchColumn();
        $files=glob(SWEEPLINE_ROOT.'/database/migrations/*.sql'); $expectedSchema=basename(end($files));
        if ($schema!==$expectedSchema) { throw new RuntimeException('Pending database migrations'); }
        $result=['status'=>'ok','database'=>true,'postgis'=>$db->query('SELECT postgis_lib_version()')->fetchColumn(),'schema'=>$schema];
    } else {
        SweepLine\Auth::start($db); $auth=new SweepLine\Auth($db);
        if (in_array($method,['POST','PATCH','DELETE'],true)) { SweepLine\Auth::csrf($_SERVER['HTTP_X_CSRF_TOKEN']??null); }
        if ($path==='/api/session') { $result=$auth->status(); }
        elseif ($path==='/api/login') {
            if ($auth->user()['must_change_password']??false) {throw new SweepLine\HttpException(403,'Password change required.');}
            $result=$auth->login($input);
        }
        elseif ($path==='/api/logout') { $result=$auth->logout(); }
        else {
            $user=$auth->requireUser(preg_match('#^/api/admin/users(?:/|$)#',$path)?'admin':str_starts_with($path,'/api/admin/'));
            // Release the session lock before spatial queries or polling.
            if ($path!=='/api/password/change') {session_write_close();}
            if($path==='/api/elevations'){$result=SweepLine\EndpointElevation::load($db,$input);}
            elseif($path==='/api/beamng'){$result=(new SweepLine\BeamJobs($db))->create((int)$user['id'],$input);}
            elseif(preg_match('#^/api/beamng/([a-f0-9]{32})(?:/(download|cancel))?$#',$path,$m)) {
                $exports=new SweepLine\BeamJobs($db);$action=$m[2]??'';
                if($action==='download')$exports->download((int)$user['id'],$m[1]);
                $result=$action==='cancel'?$exports->cancel($m[1],(int)$user['id']):$exports->status((int)$user['id'],$m[1]);
            }
            elseif(preg_match('#^/api/admin/beamng/([a-f0-9]{32})/cancel$#',$path,$m)){$result=(new SweepLine\BeamJobs($db))->cancel($m[1]);}
            elseif ($path==='/api/profiles') {$result=SweepLine\Scorer::profiles();}
            elseif ($path==='/api/password-policy') {$result=(new SweepLine\PasswordPolicy($db))->read();}
            elseif ($path==='/api/admin/session-settings') {$settings=new SweepLine\SessionSettings($db);$result=$method==='GET'?$settings->read():$settings->save($input);}
            elseif ($path==='/api/admin/password-policy') {$result=(new SweepLine\PasswordPolicy($db))->save($input);}
            elseif ($path==='/api/password/change') {$result=$auth->changePassword($input);}
            elseif ($path==='/api/favorites') {
                $favorites=new SweepLine\Favorites($db);$result=$method==='GET'?$favorites->recent((int)$user['id']):$favorites->save((int)$user['id'],$input);
            } elseif (preg_match('#^/api/favorites/([1-9]\\d{0,17})$#',$path,$m)) {
                $favorites=new SweepLine\Favorites($db);$result=$method==='DELETE'?$favorites->delete((int)$user['id'],(int)$m[1]):$favorites->detail((int)$user['id'],(int)$m[1]);
            } elseif (preg_match('#^/api/favorites/([1-9]\\d{0,17})/thumbnail$#',$path,$m)) {
                $favorite=(new SweepLine\Favorites($db))->detail((int)$user['id'],(int)$m[1]);
                if (!isset($favorite['road']['basemap'])) {$favorite['road']['basemap']=SweepLine\ExportMap::load($db,$favorite['road']);}
                header('Content-Type: image/svg+xml; charset=utf-8');header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
                echo SweepLine\FavoriteThumbnail::render($favorite['road']);exit;
            } elseif (preg_match('#^/api/favorites/([1-9]\\d{0,17})/preferences$#',$path,$m)) {
                $result=(new SweepLine\Favorites($db))->preferences((int)$user['id'],(int)$m[1],$input);
            } elseif (preg_match('#^/api/favorites/([1-9]\\d{0,17})/export$#',$path,$m)) {
                $format=$_GET['format']??'';if (!is_string($format)) {throw new InvalidArgumentException('Invalid export format');}
                $favorite=(new SweepLine\Favorites($db))->detail((int)$user['id'],(int)$m[1]);
                if ($format==='pdf' && !isset($favorite['road']['basemap'])) {$favorite['road']['basemap']=SweepLine\ExportMap::load($db,$favorite['road']);}
                SweepLine\RoadExport::download($favorite['road'],$format,SweepLine\RoadExport::orientation($_GET['orientation']??($favorite['road']['preferred_orientation']??'forward')),$_GET['preferred_name']??null);
            }
            elseif ($path==='/api/search-history') { $result=(new SweepLine\SearchHistory($db))->recent((int)$user['id']); }
            elseif (preg_match('#^/api/search-history/([1-9]\\d{0,17})$#',$path,$m)) { $history=new SweepLine\SearchHistory($db);$result=$method==='DELETE'?$history->delete((int)$user['id'],(int)$m[1]):$history->detail((int)$user['id'],(int)$m[1]); }
            elseif ($path==='/api/zips/nearest') {
                // Validate even while the postal dataset is being initialized.
                SweepLine\Validation::number($_GET,'lon',NAN,-180,180);SweepLine\Validation::number($_GET,'lat',NAN,-90,90);
                if (!$db->query('SELECT EXISTS(SELECT 1 FROM zip_codes)')->fetchColumn()) {
                    (new SweepLine\DataQueue($db))->ensure('us-zips','zips',[],true);
                    http_response_code(202);$result=['state'=>'preparing','message'=>'ZIP coordinates are being prepared. Try picking again shortly.'];
                } else { $result=$api->nearestZip($_GET); }
            }
            elseif ($path==='/api/coverage') { $result=$api->coverage(); }
            elseif($path==='/api/custom-roads'){
                $result=(new SweepLine\CustomRoads($db))->create((int)$user['id'],$input);
                if(!isset($result['road']))http_response_code(202);
            }
            elseif(preg_match('#^/api/custom-roads/([1-9][0-9]{0,14})(/export)?$#',$path,$m)){
                $road=(new SweepLine\CustomRoads($db))->detail((int)$user['id'],(int)$m[1],isset($m[2])&&($_GET['format']??'')==='pdf');
                if(isset($m[2])){
                    $format=$_GET['format']??'';
                    if(!is_string($format)||!in_array($format,['gpx','kmz','pdf'],true))throw new InvalidArgumentException('Invalid export format');
                    SweepLine\RoadExport::download($road,$format,SweepLine\RoadExport::orientation($_GET['orientation']??$road['preferred_orientation']),$_GET['preferred_name']??null);
                }else $result=$road;
            }

            elseif ($path==='/api/search-diagnostics') { $result=(new SweepLine\RoadDiagnostics($db))->run($input); }
            elseif(preg_match('#^/api/search-jobs/([1-9]\\d{0,17})$#',$path,$m)){$result=(new SweepLine\SearchJobs($db))->status((int)$user['id'],(int)$m[1]);}
            elseif(preg_match('#^/api/admin/search-jobs/([1-9]\\d{0,17})/cancel$#',$path,$m)){$result=(new SweepLine\SearchJobs($db))->cancel((int)$m[1]);}
            elseif($path==='/api/search'&&isset($input['background'])&&!is_bool($input['background'])){throw new InvalidArgumentException('background must be a JSON boolean');}
            elseif($path==='/api/search'&&($input['background']??false)===true){
                if(isset($input['record_history'])&&!is_bool($input['record_history']))throw new InvalidArgumentException('record_history must be a JSON boolean');
                $result=(new SweepLine\SearchJobs($db))->enqueue((int)$user['id'],$input);http_response_code(202);
            }
            elseif ($path==='/api/search') {
                if (isset($input['record_history']) && !is_bool($input['record_history'])) { throw new InvalidArgumentException('record_history must be a JSON boolean'); }
                $p=SweepLine\Validation::search($input); $queue=new SweepLine\DataQueue($db); $preparation=$queue->prepare($p);
                if ($preparation['state']==='ready') { $result=$api->search($p); $result['preparation']=$preparation; }
                else {
                    http_response_code(202);
                    try { $result=$api->search($p); } catch (OutOfBoundsException $e) { $result=['results'=>[]]; }
                    $result['preparation']=$preparation;
                }
                $history=new SweepLine\SearchHistory($db);$historyId=null;
                if($input['record_history']??false)$historyId=$history->record((int)$user['id'],$p);
                elseif(isset($input['history_id'])){
                    $value=SweepLine\Validation::number($input,'history_id',NAN,1,9007199254740991);
                    if(floor($value)!==$value)throw new InvalidArgumentException('History ID must be a whole number.');
                    $historyId=(int)$value;
                }
                if($historyId!==null){$history->capture((int)$user['id'],$historyId,$p,$result);$result['history_id']=$historyId;}
            } elseif (preg_match('#^/api/zips/(.*)$#',$path,$m)) {
                SweepLine\Validation::zip($m[1]);
                try { $result=$api->zip($m[1]); }
                catch (OutOfBoundsException $e) {
                    $task=(new SweepLine\DataQueue($db))->ensure('us-zips','zips',[],true);
                    if ($task['status']==='complete') { throw $e; }
                    http_response_code(202); $result=['state'=>$task['status'],'message'=>'Preparing US ZIP coordinates'];
                }
            } elseif (preg_match('#^/api/roads/([1-9]\\d{0,17})$#',$path,$m)) {
                $profile=$_GET['profile']??'twisty'; if (!is_string($profile)) { throw new InvalidArgumentException('Unknown profile'); }
                $result=$api->detail((int)$m[1],$profile,SweepLine\Api::memberIds($_GET['members']??null,(int)$m[1]));
                $result['favorite_id']=(new SweepLine\Favorites($db))->find((int)$user['id'],$result['geometry_key'],$profile);
                if($result['favorite_id']){
                    $savedRoad=(new SweepLine\Favorites($db))->detail((int)$user['id'],$result['favorite_id'])['road'];
                    $knownNames=array_merge([$result['name']],$result['alternate_names']??[]);
                    if(in_array($savedRoad['name'],$knownNames,true))$result['preferred_name']=$savedRoad['name'];
                    $result['preferred_orientation']=$savedRoad['preferred_orientation']??'forward';
                }

            } elseif (preg_match('#^/api/roads/([1-9]\\d{0,17})/export$#',$path,$m)) {
                $format=$_GET['format']??'';$profile=$_GET['profile']??'twisty';
                if (!is_string($format) || !in_array($format,['gpx','kmz','pdf'],true) || !is_string($profile)) { throw new InvalidArgumentException('Invalid export format or profile'); }
                SweepLine\RoadExport::download($api->exportRoad((int)$m[1],$profile,$format==='pdf',SweepLine\Api::memberIds($_GET['members']??null,(int)$m[1])),$format,SweepLine\RoadExport::orientation($_GET['orientation']??'forward'),$_GET['preferred_name']??null);
            } elseif ($path==='/api/admin/users') {
                $result=$method==='GET'?$auth->users():$auth->create($input);
            } elseif (preg_match('#^/api/admin/users/([1-9]\\d{0,17})$#',$path,$m)) { $result=$auth->update((int)$m[1],$input); }
            elseif ($path==='/api/admin/storage') { $settings=new SweepLine\StorageSettings($db);$result=$method==='PATCH'?$settings->save($input):$settings->read(); }
            elseif($path==='/api/admin/regions'){$regions=new SweepLine\RegionData($db);$result=isset($_GET['key'])?$regions->detail(SweepLine\RegionData::key($_GET['key'])):$regions->map();}
            elseif($path==='/api/admin/regions/action'){$result=(new SweepLine\RegionData($db))->action((int)$user['id'],SweepLine\RegionData::key($input['key']??null),is_string($input['action']??null)?$input['action']:'');http_response_code(202);}
            elseif ($path==='/api/admin/data') { $result=(new SweepLine\DataQueue($db))->overview(); }
            elseif (preg_match('#^/api/admin/tasks/([1-9]\\d{0,17})/(retry|cancel)$#',$path,$m)) {
                $queue=new SweepLine\DataQueue($db);
                $result=$m[2]==='cancel'?$queue->cancel((int)$m[1]):$queue->retry((int)$m[1]);
            } elseif (preg_match('#^/api/admin/imports/([1-9]\\d{0,17})/cancel$#',$path,$m)) {
                $result=(new SweepLine\DataQueue($db))->cancel((int)$m[1],true);
            } else { throw new OutOfBoundsException('Endpoint not found'); }
        }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    $status=$e instanceof SweepLine\HttpException?$e->status:($e instanceof InvalidArgumentException?422:($e instanceof OutOfBoundsException?404:503));
    http_response_code($status);
    if ($status===429) { header('Retry-After: 900'); }
    if ($status===503) {
        $context=' at '.basename($e->getFile()).':'.$e->getLine();
        if($e instanceof PDOException){
            $state=(string)($e->errorInfo[0]??$e->getCode());
            $context.=' SQLSTATE='.(preg_match('/^[A-Z0-9]{5}$/D',$state)?$state:'unknown');
        }
        // Do not log exception messages, SQL, parameters, passwords or request bodies.
        error_log('SweepLine API failure: '.get_class($e).$context);
    }
    echo json_encode(['redirect'=>$e->getMessage()==='Password change required.'?'/password':null,'error'=>$status===503?'Service unavailable. Please try again shortly.':$e->getMessage()]);
}
