<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/agent-initiative-canvas-policy-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to see Auto Build settings.', 401);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Auto Build settings are unavailable.', 503);
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET','POST'], true)) {
        header('Allow: GET, POST');
        throw new RuntimeException('Use GET or POST.', 405);
    }
    $input = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true, 8, JSON_THROW_ON_ERROR) : $_GET;
    if (!is_array($input) || array_is_list($input)) throw new InvalidArgumentException('Choose an exact Agent.');
    $rawAgent = $input['agent_id'] ?? null;
    if ((!is_string($rawAgent) && !is_int($rawAgent)) || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', (string)$rawAgent)
        || (int)$rawAgent > 9007199254740991) throw new InvalidArgumentException('Choose an exact Agent.');
    if ($method === 'POST') {
        if (!is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(), $input['csrf_token'])) {
            throw new RuntimeException('Session expired. Refresh and try again.', 419);
        }
        if (array_diff(array_keys($input), ['agent_id','enabled','revision','csrf_token'])
            || !is_bool($input['enabled'] ?? null) || !is_string($input['revision'] ?? null)
            || !preg_match('/\A[a-f0-9]{32}\z/D', $input['revision'])) {
            throw new InvalidArgumentException('Choose an Auto Build setting.');
        }
    } elseif (array_diff(array_keys($input), ['agent_id'])) throw new InvalidArgumentException('Choose an exact Agent.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $state = vp3_initiative_canvas_setting_v1($pdo, $user, (int)$rawAgent,
        $method === 'POST' ? $input['enabled'] : null, $method === 'POST' ? $input['revision'] : null);
    echo json_encode(['ok'=>true, 'state'=>$state], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = $error instanceof InvalidArgumentException || $error instanceof JsonException ? 422
        : (in_array($error->getCode(), [401,403,405,409,419,503], true) ? (int)$error->getCode() : 503);
    http_response_code($status);
    echo json_encode(['ok'=>false, 'error'=>$status === 503 ? 'Auto Build settings are temporarily unavailable.' : $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
