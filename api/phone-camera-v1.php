<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-scope-v1.php';
require_once dirname(__DIR__).'/includes/participant-chat-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';
require_once dirname(__DIR__).'/includes/phone-camera-v1.php';
require_once dirname(__DIR__).'/includes/phone-camera-http-v1.php';

vp3_phone_http_headers_v1();
try {
    vp3_phone_http_transport_v1();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Method not allowed.',405);
    }
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in.',401);
    $input = vp3_phone_http_json_v1(['camera_answer']);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!is_string($csrf) || !hash_equals(csrf_token(),$csrf)) throw new RuntimeException('Session expired.',419);
    $wireAction = $input['action'] ?? null;
    $aid = $input['agent_id'] ?? null;
    $cid = $input['conversation_id'] ?? null;
    if (!in_array($wireAction,['camera_status','camera_answer','camera_stop'],true)
        || !is_int($aid) || $aid < 0 || !is_int($cid) || $cid < 1) {
        throw new RuntimeException('Invalid camera request.',422);
    }
    vp3_phone_camera_id_v1($input['session_id'] ?? null);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Upgrade required.',503);
    $scope = vp3_agent_activity_scope_v1($pdo,$user,$aid,$cid);
    vp3_phone_chat_authorize_v1($pdo,$user,$scope['principal'],$input,$cid);
    unset($input['action'],$input['agent_id'],$input['conversation_id'],$input['csrf_token']);
    $result = vp3_phone_camera_owner_v1($pdo,(int)$user['id'],$aid,$cid,substr($wireAction,7),$input);
    $result['ice_servers'] = vp3_phone_camera_ice_v1($result['camera'] ?? null);
    vp3_phone_http_send_v1($result);
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
