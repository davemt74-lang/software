<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: GET, OPTIONS');

function vp3_extension_me_json_v2100(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_me_json_v2100(204);
if($method!=='GET')vp3_extension_me_json_v2100(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_me_json_v2100(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

$pdo=db();
if(!$pdo||!vp3_extension_device_token_schema_ready_v2100($pdo)){
    vp3_extension_me_json_v2100(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Companion is unavailable.']]);
}

$session=vp3_extension_session_authenticate_v2001($pdo);
if(!$session)vp3_extension_me_json_v2100(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);

$user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
if(!$user)vp3_extension_me_json_v2100(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'This VP3 account is not active.']]);

$releaseLatest=function_exists('client_release_browser_latest_v100')?client_release_browser_latest_v100():null;
$installedVersion=trim((string)($session['extension_version']??($_SERVER['HTTP_X_VP3_EXTENSION_VERSION']??'')));
$releaseState=function_exists('client_release_version_state_v100')
    ?client_release_version_state_v100($installedVersion,(string)($releaseLatest['version']??''))
    :'unknown';

vp3_extension_me_json_v2100(200,[
    'ok'=>true,
    'contract_version'=>1,
    'connected'=>true,
    'device_id'=>(string)$session['device_id'],
    'auth_type'=>(string)($session['auth_type']??'legacy_session'),
    'user'=>[
        'id'=>(int)$user['id'],
        'display_name'=>(string)$user['display_name'],
        'role'=>(string)($user['role']??''),
    ],
    'capabilities'=>array_values($session['capabilities']??[]),
    'release'=>[
        'installed_version'=>$installedVersion,
        'latest_version'=>(string)($releaseLatest['version']??''),
        'channel'=>(string)($releaseLatest['channel']??'stable'),
        'version_state'=>$releaseState,
        'update_available'=>$releaseState==='update_available',
        'download_url'=>(string)($releaseLatest['download_url']??url('/chrome-extension-download.php')),
    ],
]);
