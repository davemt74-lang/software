<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('POST required.', 405);
    }

    $user = current_user();
    if (!$user) throw new RuntimeException('Sign-in required.', 401);
    if ((int)($user['id'] ?? 0) < 1 || !has_permission('account.access', $user)
        || !has_permission('chat.access', $user)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('Owner access required.', 403);
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'application/json') throw new RuntimeException('JSON required.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    if ($raw === false) throw new RuntimeException('Request could not be read.', 422);
    if (strlen($raw) > 2048) throw new RuntimeException('Request is too large.', 413);
    $body = json_decode($raw, true, 8);
    if (!is_array($body) || array_is_list($body)) throw new RuntimeException('Request object required.', 422);

    $action = $body['action'] ?? null;
    $fields = ['action', 'owner_key', 'csrf_token'];
    if (in_array($action,['status','start','advance'],true)) $fields[] = 'include_report';
    if ($action === 'start') $fields = [...$fields, 'days', 'start_token'];
    if ($action === 'advance') $fields[] = 'run_id';
    if ($action === 'deliver') $fields = [...$fields, 'run_id', 'agent_id', 'conversation_id', 'working_attention_binding'];
    if ($action === 'deliver_updates') $fields = [...$fields, 'agent_id', 'conversation_id', 'working_attention_binding'];
    if (in_array($action, ['status', 'advance'], true)) $fields[] = 'include_chat_result';
    if ($action === 'open_item') $fields[] = 'item_ref';
    if (in_array($action,['find_matches','open_match'],true)) $fields[] = 'legacy_ref';
    if ($action === 'open_match') $fields = [...$fields,'message_id','match_token'];
    if (!in_array($action, ['status', 'start', 'advance', 'open_item', 'deliver', 'deliver_updates', 'find_matches', 'open_match'], true) || array_diff(array_keys($body), $fields)
        || (array_key_exists('include_report', $body) && !is_bool($body['include_report']))
        || (array_key_exists('include_chat_result', $body) && !is_bool($body['include_chat_result']))
        || (!empty($body['include_report']) && !empty($body['include_chat_result']))) {
        throw new RuntimeException('Invalid review request.', 422);
    }
    if (!is_string($body['owner_key'] ?? null)) throw new RuntimeException('Owner key required.', 422);
    if ($body['owner_key'] !== (string)$user['id']) throw new RuntimeException('Owner changed.', 409);
    $token = $body['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || !hash_equals(csrf_token(), $token)) {
        throw new RuntimeException('Session expired.', 419);
    }

    $days = $body['days'] ?? null;
    $startToken = $body['start_token'] ?? null;
    if ($action === 'start' && (!in_array($days, [1, 3, 7, 14, 30], true)
        || !is_string($startToken) || !preg_match('/\A[a-f0-9]{64}\z/', $startToken))) {
        throw new RuntimeException('Invalid review window or start token.', 422);
    }
    $runId = $body['run_id'] ?? null;
    if (in_array($action,['advance','deliver'],true) && (!is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/D', $runId))) {
        throw new RuntimeException('Invalid review ID.', 422);
    }
    if (in_array($action,['deliver','deliver_updates'],true)) {
        $agentId = $body['agent_id'] ?? null;
        $conversationId = $body['conversation_id'] ?? null;
        $binding = $body['working_attention_binding'] ?? null;
        if (!is_int($agentId) || $agentId < 0 || $agentId > 9007199254740991
            || !is_int($conversationId) || $conversationId < 1 || $conversationId > 9007199254740991
            || !is_string($binding) || !preg_match('/\A[a-f0-9]{64}\z/D',$binding)) {
            throw new RuntimeException('Choose an exact private Agent Chat.',422);
        }
        if (!function_exists('vp3_catch_up_page_binding_v1')) require_once dirname(__DIR__).'/includes/agent-catch-up-v1.php';
        if (!hash_equals(vp3_catch_up_page_binding_v1($user),$binding)) throw new RuntimeException('Chat session changed.',403);
    }
    $itemRef = $body['item_ref'] ?? null;
    if ($action === 'open_item' && (!is_string($itemRef) || !preg_match('/\A[a-f0-9]{64}\z/D', $itemRef))) {
        throw new RuntimeException('Invalid review item.', 422);
    }
    $legacyRef = $body['legacy_ref'] ?? null;
    $messageId = $body['message_id'] ?? null;
    $matchToken = $body['match_token'] ?? null;
    if (in_array($action,['find_matches','open_match'],true) && (!is_string($legacyRef) || !preg_match('/\A[a-f0-9]{64}\z/D',$legacyRef))) {
        throw new RuntimeException('Invalid older review item.',422);
    }
    if ($action === 'open_match' && (!is_string($messageId) || !preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D',$messageId)
        || !is_string($matchToken) || !preg_match('/\A[a-f0-9]{64}\z/D',$matchToken))) {
        throw new RuntimeException('Invalid Gmail match.',422);
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Review storage unavailable.', 503);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    if ($action === 'deliver') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_chat_deliver_v1($pdo,$user,$agentId,$conversationId,$runId),
            JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'deliver_updates') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_update_deliver_v1($pdo,$user,$agentId,$conversationId),
            JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }

    if ($action === 'open_item') {
        echo json_encode(['ok'=>true,'item'=>agent_connection_gmail_inbox_open_item_v1($pdo,$user,$itemRef)],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'find_matches') {
        echo json_encode(['ok'=>true]+agent_connection_gmail_inbox_find_matches_v1($pdo,$user,$legacyRef),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'open_match') {
        echo json_encode(['ok'=>true,'item'=>agent_connection_gmail_inbox_open_match_v1($pdo,$user,$legacyRef,$messageId,$matchToken)],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        return;
    }
    if ($action === 'start') agent_connection_gmail_inbox_initial_start_v1($pdo, $user, $days, $startToken);
    if ($action === 'advance') agent_connection_gmail_inbox_initial_advance_v1($pdo, $user, $runId);
    $review = agent_connection_gmail_inbox_initial_view_v1($pdo, $user, $body['include_report'] ?? false,
        includeChatResult: $body['include_chat_result'] ?? false, includeLegacyMatches: true);
    echo json_encode(['ok'=>true, 'review'=>$review], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    if (!in_array($status, [401, 403, 404, 405, 409, 413, 415, 419, 422, 429, 503], true)) $status = 503;
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>match ($status) {
        401, 403 => 'Open Inbox Review in your signed-in account.',
        404, 409 => 'The inbox review or account changed. Refresh and try again.',
        405 => 'Use POST for Inbox Review.',
        413 => 'The review request is too large.',
        415 => 'Use a JSON review request.',
        419 => 'Your session expired. Refresh and try again.',
        422 => 'Choose a valid inbox review request.',
        429 => 'Inbox Review is busy. Try again shortly.',
        default => 'Inbox Review is temporarily unavailable.',
    }], JSON_THROW_ON_ERROR);
}
