<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/homeserver-model-inventory-read-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function vp3_inventory_api_v1(bool $ok,array $data=[],int $status=200): never
{
    http_response_code($status);
    echo json_encode(['ok'=>$ok]+$data,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$user=current_user();
if(!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user)||(int)($user['id']??0)<1)
    vp3_inventory_api_v1(false,['error'=>'Model inventory access is unavailable.'],403);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){
    header('Allow: POST');
    vp3_inventory_api_v1(false,['error'=>'POST required.'],405);
}
if(array_diff(array_keys($_POST),['csrf_token'])!==[]||$_GET!==[])
    vp3_inventory_api_v1(false,['error'=>'Invalid inventory request.'],422);
$csrf=$_POST['csrf_token']??null;
if(!is_string($csrf)||$csrf===''||!hash_equals(csrf_token(),$csrf))
    vp3_inventory_api_v1(false,['error'=>'Session expired. Refresh the page and try again.'],419);

// Bound explicit refreshes per signed-in session. Never poll from this endpoint.
$owner=(int)$user['id'];
$sessionId=session_id();
if(session_status()!==PHP_SESSION_ACTIVE||$sessionId==='')
    vp3_inventory_api_v1(false,['error'=>'Session unavailable. Refresh the page.'],403);
$last=$_SESSION['vp3_inventory_refresh_v1']??[];
$now=time();
if(is_array($last)&&($last['owner']??null)===$owner&&$now-(int)($last['at']??0)<15){
    header('Retry-After: 15');
    vp3_inventory_api_v1(false,['error'=>'Please wait a few seconds before refreshing again.'],429);
}
$_SESSION['vp3_inventory_refresh_v1']=['owner'=>$owner,'at'=>$now];
session_write_close();

try{
    $view=vp3_homeserver_inventory_read_v1($owner);
    // The relay wait releases the browser session lock. Reopen the original
    // session without cookies before releasing private metadata: another tab
    // may have logged out, switched accounts or invalidated its credentials.
    $_SESSION=[];session_id($sessionId);
    $opened=session_start(['read_and_close'=>true,'use_cookies'=>false,'use_trans_sid'=>false,'use_strict_mode'=>true,'cache_limiter'=>'']);
    if(!$opened||!hash_equals($sessionId,session_id())){
        $_SESSION=[];reset_current_user_cache();
        throw new RuntimeException('Model inventory authorization changed.',403);
    }
    reset_current_user_cache();$fresh=current_user();
    if(!$fresh||(int)$fresh['id']!==$owner||!has_permission('account.access',$fresh)||!has_permission('chat.access',$fresh)
        ||!hash_equals(csrf_token(),$csrf))
        throw new RuntimeException('Model inventory authorization changed.',403);
    vp3_inventory_api_v1(true,['inventory'=>$view,'checked_at'=>gmdate(DATE_ATOM)]);
}catch(Throwable $error){
    $status=in_array($error->getCode(),[403,409],true)?(int)$error->getCode():503;
    $message=match($status){
        403=>'Model inventory authorization changed.',
        409=>'HomeServer pairing is unavailable or changed.',
        default=>'Model inventory could not be refreshed. Check the HomeServer connection and try again.',
    };
    vp3_inventory_api_v1(false,['error'=>$message],$status);
}
