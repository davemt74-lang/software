<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
if (!function_exists('vp3_agent_chat_resolve_agent_v380')) require_once dirname(__DIR__) . '/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__) . '/includes/initiative-workbench-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new DomainException('Use POST for Initiative work.', 405);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        throw new DomainException('Initiative request is too large.', 413);
    }
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) {
        throw new DomainException('Choose an Initiative work request.', 422);
    }
    $user = current_user();
    if (!$user || !has_permission('chat.access', $user)) {
        throw new DomainException('Sign in to use Initiative work.', 403);
    }
    $owner = (int)$user['id'];
    if (($body['owner_key'] ?? null) !== (string)$owner) {
        throw new DomainException('The Chat account changed. Refresh this page.', 409);
    }
    if (!is_string($body['csrf_token'] ?? null) || !hash_equals(csrf_token(), $body['csrf_token'])) {
        throw new DomainException('Refresh Chat before using Initiative work.', 419);
    }
    $agentId = $body['agent_id'] ?? null;
    $conversationId = $body['conversation_id'] ?? null;
    $tool = $body['tool'] ?? '';
    $action = $body['action'] ?? '';
    $creativeAction = $tool === 'marketing' && in_array($action, ['creative_open','creative_prepare','creative_generate',
        'creative_status','creative_tick','creative_cancel','creative_save','creative_publish_preview','creative_publish','creative_pause'],true);
    if (!$creativeAction && strlen($raw) > 4096) throw new DomainException('Initiative request is too large.',413);
    if (!is_int($agentId) || $agentId < 0 || !is_int($conversationId) || $conversationId < 1
        || !in_array($tool, ['product', 'screen', 'mission', 'marketing'], true)
        || (!$creativeAction && !in_array($action, ['sources', 'preview', 'create'], true))) {
        throw new DomainException('Choose a tool in the current saved Agent Chat.', 422);
    }
    $pdo = db();
    if (!$pdo) {
        throw new DomainException('Initiative storage is unavailable.', 503);
    }
    try {
        $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
    } catch (RuntimeException $error) {
        if ($error instanceof PDOException) {
            throw $error;
        }
        throw new DomainException('Agent access changed. Reopen Chat.', 403);
    }
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    $user = vp3_image_scope_v1($pdo, $user, $principal, ['conversation_id' => $conversationId]);
    $scope = ['agent_id' => $agentId, 'conversation_id' => $conversationId, 'user' => $user, 'principal' => $principal];
    $key = $_SESSION['initiative_workbench_review_key_v1'] ??= bin2hex(random_bytes(32));
    session_write_close();

    require_once dirname(__DIR__) . '/includes/initiative-work-' . $tool . '-v1.php';
    $response = ['ok' => true, 'owner_key' => (string)$owner, 'tool' => $tool,
        'agent_id' => $agentId, 'conversation_id' => $conversationId];
    $handler = 'initiative_work_' . $tool;
    if ($creativeAction) {
        require_once dirname(__DIR__).'/includes/initiative-work-marketing-promotion-v1.php';
        $response += initiative_work_marketing_promotion_action_v1($pdo,$owner,$scope,$body,$key);
    } elseif ($action === 'sources') {
        $response['sources'] = ($handler . '_sources_v1')($pdo, $owner, $scope);
        if ($tool === 'marketing') $response['products'] = initiative_work_marketing_products_v1($pdo, $owner, $scope);
    } else {
        $input = initiative_workbench_input_v1($body);
        $preview = ($handler . '_preview_v1')($pdo, $owner, $scope, $input + ['show_image' => $action === 'preview']);
        $request = initiative_workbench_request_v1($owner, $scope, $tool, $input, $preview);
        if ($action === 'preview') {
            $response['preview'] = $preview;
            $response['review_token'] = initiative_workbench_review_token_v1($key, $request, time() + 600);
        } else {
            initiative_workbench_confirm_v1($body, $key, $request, time());
            $response['output'] = ($handler . '_create_v1')($pdo, $owner, $scope, $input + ['request_id' => $request], $preview);
        }
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $allowed = [400, 403, 404, 405, 409, 413, 419, 422, 429, 502, 503];
    $code = (int)$error->getCode();
    $status = $error instanceof InvalidArgumentException || $error instanceof JsonException
        ? 422 : (in_array($code, $allowed, true) && !($error instanceof PDOException) ? $code : 503);
    $message = match (true) {
        $error instanceof DomainException => $error->getMessage(),
        $error instanceof JsonException => 'Invalid Initiative request.',
        $error instanceof InvalidArgumentException => $error->getMessage(),
        in_array($status, [403, 404, 409], true) => 'The Chat or source changed. Reopen the workbench and review the current source.',
        default => 'Initiative work could not be verified. Reopen the source to check before trying again.',
    };
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
}
