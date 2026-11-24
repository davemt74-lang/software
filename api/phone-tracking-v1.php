<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-http-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

vp3_phone_http_headers_v1();
try {
    vp3_phone_http_transport_v1();
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in.', 401);
    if (!has_permission('account.access', $user) || !has_permission('chat.access', $user)
        || !hash_equals(vp3_catch_up_page_binding_v1($user),
        (string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] ?? ''))) throw new RuntimeException('Session changed.', 403);

    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'state';
        if (!in_array($action,['state','history','places'],true)) throw new RuntimeException('Unknown action.',422);
        $input = [];
        if ($action === 'history') {
            $deviceId = $_GET['device_id'] ?? null;
            if (!is_string($deviceId) || !preg_match('/^[0-9a-f]{32}$/D',$deviceId)) throw new RuntimeException('Choose a phone.',422);
            $before = $_GET['before'] ?? null;
            if ($before !== null && (!is_string($before) || !preg_match('/^[1-9][0-9]{0,11}$/D',$before))) {
                throw new RuntimeException('Invalid history page.',422);
            }
            $input = ['device_id'=>$deviceId,'before'=>$before === null ? null : (int)$before];
        }
    } elseif ($method === 'POST') {
        $input = vp3_phone_http_json_v1();
        if (!is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(), $input['csrf_token'])) {
            throw new RuntimeException('Session expired.', 419);
        }
        $action = $input['action'] ?? null;
        if (!in_array($action, ['invite','approve','deny','request_start','stop','revoke','set_preferences',
            'set_holder_place_access','request_history','stop_history','delete_history','save_place','delete_place','save_communication_grants'], true)) {
            throw new RuntimeException('Unknown action.', 422);
        }
        unset($input['action'], $input['csrf_token']);
    } else {
        header('Allow: GET, POST');
        throw new RuntimeException('Method not allowed.', 405);
    }
    $pdo = db();
    if (!$pdo || !vp3_phone_schema_ready_v1($pdo)) throw new RuntimeException('Upgrade required.', 503);
    $owner = (int)$user['id'];
    $result = match ($action) {
        'save_communication_grants' => vp3_phone_communication_owner_v1($pdo,$owner,$input),
        'history' => vp3_phone_history_read_v1($pdo,$owner,$input['device_id'],$input['before']),
        'places','save_place','delete_place' => vp3_phone_places_owner_v1($pdo,$owner,$action,$input),
        'approve','request_start' => isset($input['chat_origin']) ? vp3_phone_chat_map_command_v1($pdo,$user,$action,$input)
            : vp3_phone_owner_v1($pdo,$owner,$action,$input),
        default => vp3_phone_owner_v1($pdo,$owner,$action,$input)
    };
    if ($action === 'state') {
        $result['devices']=vp3_paired_phone_owner_links_v1($pdo,$owner,$result['devices']);
        if (vp3_participant_scope_v1()===null) {
            $result['devices']=vp3_phone_camera_map_devices_v1($pdo,$owner,$result['devices']);
            $result['devices']=vp3_phone_communication_map_v1($pdo,$owner,$result['devices'],time());
        }
    }
    if ($action === 'invite' && !empty($result['invite'])) {
        $result['phone_url'] = url('/phone-location.php').'#invite='.rawurlencode($result['invite']);
    }
    vp3_phone_http_send_v1($result);
} catch (Throwable $error) {
    vp3_phone_http_error_v1($error);
}
