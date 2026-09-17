<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/browser-share-chat-feed-v2020.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function vp3_browser_share_api_json_v2020(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode(['build'=>VP3_BROWSER_SHARE_CHAT_FEED_V2020]+$payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
}

$user=current_user();
if(!$user)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'login_required'],401);
$pdo=db();
if(!$pdo)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'database_unavailable'],503);
try{vp3_browser_share_chat_feed_require_ready_v2020($pdo);}catch(Throwable $e){vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'upgrade_required','message'=>$e->getMessage()],503);}
$uid=(int)$user['id'];
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
$input=[];
if($method==='POST'){
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    $csrf=trim((string)($input['csrf_token']??''));
    if($csrf===''||!hash_equals(csrf_token(),$csrf))vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'csrf'],419);
}
$action=(string)($input['action']??$_GET['action']??'feed');
try{
    if($action==='feed'){
        if($method!=='GET')vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'method_not_allowed'],405);
        $limit=max(1,min(VP3_BROWSER_SHARE_FEED_LIMIT_MAX_V2020,(int)($_GET['limit']??30)));
        $before=max(0,(int)($_GET['before_message_id']??0));
        vp3_browser_share_api_json_v2020(['ok'=>true,'feed'=>vp3_browser_share_feed_v2020($pdo,$uid,$limit,$before)]);
    }
    if($action==='get'){
        if($method!=='GET')vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'method_not_allowed'],405);
        $share=vp3_browser_share_resolve_v2020($pdo,(string)($_GET['browser_share_id']??''),$uid);
        if(!$share)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'not_found'],404);
        vp3_browser_share_api_json_v2020(['ok'=>true,'browser_share'=>$share]);
    }
    if($action==='message'){
        if($method!=='GET')vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'method_not_allowed'],405);
        $messageId=max(0,(int)($_GET['message_id']??0));
        if($messageId<1)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'message_required'],422);
        $share=vp3_browser_share_for_message_public_v2020($pdo,$messageId,$uid);
        if(!$share)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'not_found'],404);
        vp3_browser_share_api_json_v2020(['ok'=>true,'browser_share'=>$share]);
    }
    if($method!=='POST')vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'method_not_allowed'],405);
    $publicId=trim((string)($input['browser_share_id']??''));
    if($publicId==='')vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'browser_share_required'],422);
    if($action==='ask_agent'){
        $share=vp3_browser_share_resolve_v2020($pdo,$publicId,$uid);
        if(!$share)vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'not_found'],404);
        $_SESSION['vp3_browser_share_agent_context_v2020']=[
            'browser_share_id'=>(string)$share['id'],
            'conversation_id'=>0,
            'created_at'=>time(),
        ];
        vp3_browser_share_api_json_v2020(['ok'=>true,'chat_url'=>url('/chat.php?browser_share_id='.rawurlencode((string)$share['id'])),'browser_share'=>$share]);
    }
    if($action==='save_knowledge'){
        $result=vp3_browser_share_save_knowledge_v2020($pdo,$user,$publicId,max(0,(int)($input['folder_id']??0)));
        vp3_browser_share_api_json_v2020(['ok'=>true]+$result);
    }
    if($action==='create_task'){
        $run=vp3_browser_share_create_task_v2020($pdo,$user,$publicId);
        vp3_browser_share_api_json_v2020(['ok'=>true,'workflow'=>$run]);
    }
    vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'unknown_action'],404);
}catch(RuntimeException $e){
    $message=$e->getMessage();
    $status=str_contains(mb_strtolower($message),'no longer available')?404:422;
    vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'validation','message'=>$message],$status);
}catch(Throwable $e){
    error_log('VP3 Browser Share v2020 API: '.$e->getMessage());
    vp3_browser_share_api_json_v2020(['ok'=>false,'error'=>'server_error'],500);
}
