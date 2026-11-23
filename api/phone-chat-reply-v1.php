<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-scope-v1.php';
require_once dirname(__DIR__).'/includes/participant-chat-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';

vp3_phone_http_headers_v1();
try {
    vp3_phone_http_transport_v1();
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        header('Allow: POST');
        throw new RuntimeException('Method not allowed.',405);
    }
    $user=current_user();
    if (!$user) throw new RuntimeException('Sign in.',401);
    $input=vp3_phone_http_json_v1();
    $csrf=$_SERVER['HTTP_X_CSRF_TOKEN']??null;
    if (!is_string($csrf) || !hash_equals(csrf_token(),$csrf)) throw new RuntimeException('Session expired.',419);
    if (($input['action']??null)!=='reply' || !is_int($input['agent_id']??null) || $input['agent_id']<0
        || !is_int($input['conversation_id']??null) || $input['conversation_id']<1) throw new RuntimeException('Invalid phone reply.',422);
    unset($input['action']);
    $pdo=db();
    if (!$pdo) throw new RuntimeException('Phone Chat unavailable.',503);
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$input['agent_id'],$input['conversation_id']);
    vp3_phone_http_send_v1(vp3_paired_phone_owner_reply_v1($pdo,$user,$scope['principal'],$input));
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
