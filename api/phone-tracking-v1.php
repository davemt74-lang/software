<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';

vp3_phone_http_headers_v1();
try {
    vp3_phone_http_transport_v1();
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in.', 401);
    if (!has_permission('account.access', $user) || !has_permission('chat.access', $user)
        || !hash_equals(vp3_catch_up_page_binding_v1($user),
        (string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? ''))) throw new RuntimeException('Session changed.', 403);

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET' && ($_GET['action'] ?? 'state') === 'state') {
        $action = 'state';
        $input = [];
    } elseif ($method === 'POST') {
        $input = vp3_phone_http_json_v1();
        if (!is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(), $input['csrf_token'])) {
            throw new RuntimeException('Session expired.', 419);
        }
        $action = $input['action'] ?? null;
        if (!in_array($action, ['invite','approve','deny','request_start','stop','revoke','set_preferences'], true)) {
            throw new RuntimeException('Unknown action.', 422);
        }
        unset($input['action'], $input['csrf_token']);
    } else {
        header('Allow: GET, POST');
        throw new RuntimeException('Method not allowed.', 405);
    }
    $pdo = db();
    if (!$pdo || !vp3_phone_schema_ready_v1($pdo)) throw new RuntimeException('Upgrade required.', 503);
    $result = $action === 'request_start' && isset($input['chat_origin'])
        ? vp3_phone_chat_map_start_v1($pdo, $user, $input)
        : vp3_phone_owner_v1($pdo, (int)$user['id'], $action, $input);
    if ($action === 'invite' && !empty($result['invite'])) {
        $result['phone_url'] = url('/phone-location.php').'#invite='.rawurlencode($result['invite']);
    }
    vp3_phone_http_send_v1($result);
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
