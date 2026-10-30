<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/phone-cloud-v100.php';
require_once dirname(__DIR__).'/includes/phone-sms-v110.php';

function vp3_sms_member_reply_v110(bool $ok,array $data=[],int $status=200): never
{
    http_response_code($status);header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok]+$data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
$user=current_user();$pdo=db();
if (!$user||!has_permission('account.access',$user)||!has_permission('chat.access',$user))
    vp3_sms_member_reply_v110(false,['error'=>'Not authorized.'],403);
if (!$pdo||!vp3_phone_sms_schema_ready_v110())
    vp3_sms_member_reply_v110(false,['error'=>'SMS inbox requires the Cloud upgrade.'],503);
$ownerId=(int)$user['id'];
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') {
    try {
        $threadId=max(0,(int)($_GET['thread_id']??0));
        $payload=['threads'=>vp3_phone_sms_threads_v110($pdo,$ownerId)];
        if ($threadId>0) $payload['messages']=vp3_phone_sms_messages_v110($pdo,$ownerId,$threadId);
        vp3_sms_member_reply_v110(true,$payload);
    } catch(Throwable) {vp3_sms_member_reply_v110(false,['error'=>'Could not load this conversation.'],404);}
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') vp3_sms_member_reply_v110(false,['error'=>'Method not allowed.'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($input)||!hash_equals(csrf_token(),(string)($input['csrf_token']??'')))
    vp3_sms_member_reply_v110(false,['error'=>'Session expired.'],419);
if (($input['action']??'')!=='mark_read') vp3_sms_member_reply_v110(false,['error'=>'Unknown action.'],422);
try {
    vp3_phone_sms_mark_read_v110($pdo,$ownerId,(int)($input['thread_id']??0));
    vp3_sms_member_reply_v110(true,['marked_read'=>true]);
} catch(Throwable) {vp3_sms_member_reply_v110(false,['error'=>'Conversation unavailable.'],404);}
