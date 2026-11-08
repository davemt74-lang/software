<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-development-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new DomainException('Use a runner POST request.', 405);
    }
    $token = vp3_dev_runner_token_v1();
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (empty($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS']) === 'off' || $token === ''
        || !preg_match('/\ABearer ([A-Za-z0-9_-]{43,128})\z/D', $auth, $match) || !hash_equals($token, $match[1])) {
        throw new DomainException('Runner authorization is required.', 401);
    }
    $pdo = db();
    if (!$pdo || !vp3_dev_schema_ready_v1($pdo) || !vp3_dev_runner_ready_v1()) {
        throw new DomainException('The isolated development runner is unavailable.', 503);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16001);
    if (!is_string($raw) || strlen($raw) > 16000) throw new DomainException('The runner request is too large.', 413);
    $input = json_decode($raw, true, 16);
    if (!is_array($input)) throw new DomainException('Invalid runner request.', 400);
    $action = $input['action'] ?? '';
    if ($action === 'claim') {
        echo json_encode(['ok'=>true,'job'=>vp3_dev_claim_v1($pdo)], JSON_THROW_ON_ERROR);
    } elseif (in_array($action, ['finish','fail'], true) && is_string($input['id'] ?? null) && is_string($input['lease'] ?? null)
        && ($action === 'fail' || is_array($input['result'] ?? null))) {
        vp3_dev_finish_v1($pdo, $input['id'], $input['lease'], $action === 'fail' ? null : $input['result']);
        echo '{"ok":true}';
    } else {
        throw new DomainException('Invalid runner action.', 400);
    }
} catch (DomainException $error) {
    http_response_code($error->getCode());
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);
} catch (Throwable) {
    http_response_code(500);
    error_log('Agent Development runner request failed.');
    echo '{"ok":false,"error":"The development runner could not complete this request."}';
}
