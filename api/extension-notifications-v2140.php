<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/extension-notifications-v2140.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_notifications_json_v2140(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode(['build'=>VP3_EXTENSION_NOTIFICATIONS_V2140]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_notifications_input_v2140(): array
{
    $raw=(string)file_get_contents('php://input');
    if(strlen($raw)>32768)vp3_extension_notifications_json_v2140(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
    if(trim($raw)==='')return [];
    $input=json_decode($raw,true);
    if(!is_array($input))vp3_extension_notifications_json_v2140(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);
    return $input;
}

function vp3_extension_notification_event_key_v2140(mixed $value): string
{
    $key=trim((string)$value);
    if(!preg_match('/^(?:notification:[1-9][0-9]{0,18}|cognitive:[a-f0-9]{64})$/',$key)){
        throw new InvalidArgumentException('Notification identity is invalid.');
    }
    return $key;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'POST'));
if($method==='OPTIONS')vp3_extension_notifications_json_v2140(204);
if($method!=='POST')vp3_extension_notifications_json_v2140(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'POST required.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_notifications_json_v2140(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

try{
    $pdo=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!vp3_extension_notifications_schema_ready_v2140($pdo)){
        vp3_extension_notifications_json_v2140(503,['ok'=>false,'error'=>['code'=>'upgrade_required','message'=>'Run the VP3 database upgrade to enable proactive Browser notifications.']]);
    }

    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_notifications_json_v2140(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    if(!vp3_extension_session_has_capability_v2001($session,'notifications.read')){
        vp3_extension_notifications_json_v2140(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'Browser notifications are not enabled for this VP3 account.']]);
    }

    $user=vp3_extension_user_for_permission_v2001($pdo,(int)$session['user_id']);
    if($user)$user['roles']=user_account_types_for_user_id((int)$user['id'],(string)($user['role']??''));
    if(!$user)vp3_extension_notifications_json_v2140(403,['ok'=>false,'error'=>['code'=>'forbidden','message'=>'VP3 account access is unavailable.']]);

    $namespace=vp3_cognitive_agent_namespace_v500($pdo,$user,0);
    $input=vp3_extension_notifications_input_v2140();
    $action=trim((string)($input['action']??'poll'));

    if($action==='poll'){
        $context=is_array($input['current_context']??null)?$input['current_context']:[];
        $visual=vp3_extension_notification_claim_next_v2140($pdo,$session,$user,$namespace,$context);
        $voice=vp3_extension_notification_voice_pending_v2140($pdo,$session,$user,$namespace,$context);
        vp3_extension_notifications_json_v2140(200,[
            'ok'=>true,
            'visual'=>$visual,
            'voice'=>$voice,
            'voice_enabled'=>vp3_extension_notification_voice_enabled_v2140($pdo,$user),
            'poll_seconds'=>60,
            'delivery_authority'=>'server',
        ]);
    }

    $eventKey=vp3_extension_notification_event_key_v2140($input['event_key']??'');

    if($action==='visual_delivered'){
        $claimToken=strtolower(trim((string)($input['claim_token']??'')));
        $ok=vp3_extension_notification_visual_delivered_v2140($pdo,$session,$eventKey,$claimToken);
        if(!$ok)vp3_extension_notifications_json_v2140(409,['ok'=>false,'error'=>['code'=>'claim_expired','message'=>'Notification delivery claim expired.']]);
        vp3_extension_notifications_json_v2140(200,['ok'=>true]);
    }
    if($action==='release'){
        vp3_extension_notification_release_v2140($pdo,$session,$eventKey,strtolower(trim((string)($input['claim_token']??''))));
        vp3_extension_notifications_json_v2140(200,['ok'=>true]);
    }
    if($action==='voice_delivered'||$action==='voice_failed'){
        vp3_extension_notification_voice_result_v2140($pdo,$session,$user,$namespace,$eventKey,$action==='voice_delivered');
        vp3_extension_notifications_json_v2140(200,['ok'=>true]);
    }
    if($action==='open'){
        $url=vp3_extension_notification_open_v2140($pdo,$session,$eventKey);
        if($url===null)vp3_extension_notifications_json_v2140(404,['ok'=>false,'error'=>['code'=>'notification_unavailable','message'=>'This notification is no longer available.']]);
        vp3_extension_notifications_json_v2140(200,['ok'=>true,'target_url'=>$url]);
    }
    if($action==='dismiss'){
        vp3_extension_notification_dismiss_v2140($pdo,$session,$eventKey);
        vp3_extension_notifications_json_v2140(200,['ok'=>true]);
    }
    if($action==='snooze'){
        vp3_extension_notification_snooze_v2140($pdo,$session,$eventKey);
        vp3_extension_notifications_json_v2140(200,['ok'=>true,'snooze_minutes'=>VP3_EXTENSION_NOTIFICATION_SNOOZE_MINUTES_V2140]);
    }

    vp3_extension_notifications_json_v2140(422,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser notification action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_notifications_json_v2140($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(InvalidArgumentException $e){
    vp3_extension_notifications_json_v2140(422,['ok'=>false,'error'=>['code'=>'invalid_action','message'=>$e->getMessage()]]);
}catch(Throwable $e){
    error_log('VP3 Browser Companion proactive notifications v21.40 failed: '.$e->getMessage());
    vp3_extension_notifications_json_v2140(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Proactive Browser notifications are temporarily unavailable.']]);
}
