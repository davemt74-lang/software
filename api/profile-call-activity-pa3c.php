<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$respond=static function(int $status,array $payload): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$method=(string)($_SERVER['REQUEST_METHOD']??'');
if (!in_array($method,['GET','POST'],true)) $respond(405,['ok'=>false,'error'=>'GET or POST required.']);
$owner=current_user();
if (!$owner) $respond(401,['ok'=>false,'error'=>'Sign in to access Profile calls.']);
if (empty($owner['is_active'])||!has_permission('chat.access',$owner)||!has_permission('artist_listening.access',$owner)) {
    $respond(403,['ok'=>false,'error'=>'Profile call activity is unavailable.']);
}
$pdo=db();
if (!$pdo||!vp3_profile_call_activity_schema_ready_pa3c($pdo)) $respond(503,['ok'=>false,'error'=>'Profile call activity is unavailable.']);
$input=$_GET;
if ($method==='POST') {
    $raw=file_get_contents('php://input',false,null,0,6001);
    $input=is_string($raw)&&strlen($raw)<=6000?json_decode($raw,true):null;
    if (!is_array($input)) $respond(400,['ok'=>false,'error'=>'Valid JSON required.']);
    $csrf=$input['csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if (!is_string($csrf)||$csrf===''||!hash_equals(csrf_token(),$csrf)) $respond(419,['ok'=>false,'error'=>'Session expired. Refresh and try again.']);
}
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
try {
    $id=is_string($input['call_id']??null)?$input['call_id']:'';
    $call=vp3_profile_call_activity_owner_pa3c($pdo,(int)$owner['id'],$id);
    if ($method==='GET') $respond(200,['ok'=>true]+vp3_profile_call_activity_view_pa3c($pdo,$call,max(0,(int)($input['after']??0))));
    if (($input['action']??null)==='set_assistance'&&is_bool($input['enabled']??null)
        &&is_int($input['assistance_revision']??null)&&$input['assistance_revision']>0) {
        $respond(200,['ok'=>true]+vp3_profile_call_assistance_toggle_pa3c($pdo,(int)$owner['id'],$id,$input['enabled'],$input['assistance_revision']));
    }
    if (($input['action']??null)==='research'&&is_string($input['request_id']??null)&&is_string($input['query']??null)) {
        $respond(200,['ok'=>true]+vp3_profile_call_research_pa3c($pdo,$owner,$id,$input['request_id'],$input['query']));
    }
    $respond(400,['ok'=>false,'error'=>'Unknown call activity action.']);
} catch (InvalidArgumentException $error) {
    $respond(400,['ok'=>false,'error'=>$error->getMessage()]);
} catch (Throwable $error) {
    $code=(int)$error->getCode();
    if (get_class($error)===RuntimeException::class&&in_array($code,[404,409,429,503],true)) $respond($code,['ok'=>false,'error'=>$error->getMessage()]);
    error_log('VP3 Profile call activity request failed ('.get_class($error).').');
    $respond(503,['ok'=>false,'error'=>'Profile call activity is unavailable.']);
}
