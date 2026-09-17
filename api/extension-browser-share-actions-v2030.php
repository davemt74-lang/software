<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/extension-device-auth-v2001.php';
require_once dirname(__DIR__).'/includes/browser-share-chat-feed-v2020.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
vp3_extension_apply_cors_v2001();
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-VP3-Extension-Version, X-VP3-Contract-Version');
header('Access-Control-Allow-Methods: POST, OPTIONS');

function vp3_extension_share_action_json_v2030(int $status,array $payload=[]): never
{
    http_response_code($status);
    if($status!==204)echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

function vp3_extension_share_action_user_v2030(PDO $pdo,int $userId): ?array
{
    if($userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$userId]);
    $user=$stmt->fetch();
    if(!is_array($user))return null;
    $primaryRole=(string)($user['role']??'');
    $user['roles']=user_account_types_for_user_id($userId,$primaryRole);
    return $user;
}

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if($method==='OPTIONS')vp3_extension_share_action_json_v2030(204);
if($method!=='POST')vp3_extension_share_action_json_v2030(405,['ok'=>false,'error'=>['code'=>'method_not_allowed','message'=>'Method not allowed.']]);
if(trim((string)($_SERVER['HTTP_X_VP3_CONTRACT_VERSION']??''))!=='1'){
    vp3_extension_share_action_json_v2030(422,['ok'=>false,'error'=>['code'=>'unsupported_contract','message'=>'Unsupported extension contract version.']]);
}

$pdo=db();
if(!$pdo||!vp3_browser_share_chat_feed_ready_v2020($pdo)){
    vp3_extension_share_action_json_v2030(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Share actions are not ready. Run the database upgrade.']]);
}

$raw=(string)file_get_contents('php://input');
if(strlen($raw)>8192)vp3_extension_share_action_json_v2030(413,['ok'=>false,'error'=>['code'=>'payload_too_large','message'=>'Payload too large.']]);
$input=json_decode($raw,true);
if(!is_array($input))vp3_extension_share_action_json_v2030(400,['ok'=>false,'error'=>['code'=>'invalid_request','message'=>'A JSON request body is required.']]);

try{
    $session=vp3_extension_session_authenticate_v2001($pdo);
    if(!$session)vp3_extension_share_action_json_v2030(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'Browser Companion authentication is required.']]);
    $user=vp3_extension_share_action_user_v2030($pdo,(int)$session['user_id']);
    if(!$user)vp3_extension_share_action_json_v2030(401,['ok'=>false,'error'=>['code'=>'authentication_required','message'=>'The connected VP3 account is unavailable.']]);

    $action=trim((string)($input['action']??''));
    $publicId=trim((string)($input['browser_share_id']??''));
    $share=vp3_browser_share_resolve_v2020($pdo,$publicId,(int)$session['user_id']);
    if(!$share)vp3_extension_share_action_json_v2030(404,['ok'=>false,'error'=>['code'=>'not_found','message'=>'This Browser Share is no longer available.']]);

    if($action==='ask_agent'){
        if(!vp3_extension_session_has_capability_v2001($session,'agent.message')){
            vp3_extension_share_action_json_v2030(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection cannot hand shares to Agent Chat.']]);
        }
        $handoff=vp3_extension_absolute_url_v2000('/browser-share-agent-handoff.php?browser_share_id='.rawurlencode((string)$share['id']));
        vp3_extension_share_action_json_v2030(200,['ok'=>true,'action'=>'ask_agent','browser_share_id'=>$share['id'],'handoff_url'=>$handoff]);
    }

    if($action==='save_knowledge'){
        if(!vp3_extension_session_has_capability_v2001($session,'knowledge.write')){
            vp3_extension_share_action_json_v2030(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection cannot write to Knowledge.']]);
        }
        $result=vp3_browser_share_save_knowledge_v2020($pdo,$user,$publicId,max(0,(int)($input['folder_id']??0)));
        vp3_extension_share_action_json_v2030(200,['ok'=>true,'action'=>'save_knowledge']+$result);
    }

    if($action==='create_task'){
        if(!vp3_extension_session_has_capability_v2001($session,'task.propose')){
            vp3_extension_share_action_json_v2030(403,['ok'=>false,'error'=>['code'=>'capability_denied','message'=>'This browser connection cannot propose Agent tasks.']]);
        }
        $workflow=vp3_browser_share_create_task_v2020($pdo,$user,$publicId);
        vp3_extension_share_action_json_v2030(200,['ok'=>true,'action'=>'create_task','workflow'=>$workflow]);
    }

    vp3_extension_share_action_json_v2030(404,['ok'=>false,'error'=>['code'=>'unknown_action','message'=>'Unknown Browser Share action.']]);
}catch(VP3ExtensionSecurityExceptionV2001 $e){
    vp3_extension_share_action_json_v2030($e->httpStatus,['ok'=>false,'error'=>['code'=>$e->apiCode,'message'=>$e->getMessage()]]);
}catch(RuntimeException $e){
    $message=$e->getMessage();
    $status=str_contains(mb_strtolower($message),'no longer available')?404:422;
    vp3_extension_share_action_json_v2030($status,['ok'=>false,'error'=>['code'=>'action_unavailable','message'=>$message]]);
}catch(Throwable $e){
    error_log('VP3 extension Browser Share action failed: '.$e->getMessage());
    vp3_extension_share_action_json_v2030(503,['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Browser Share action could not be completed.']]);
}
