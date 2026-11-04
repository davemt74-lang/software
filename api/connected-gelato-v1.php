<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/connected-gelato-v1.php';

function vp3_gelato_api_v1(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = current_user();
if (!$user || !has_permission('account.access', $user) || !has_permission('chat.access', $user)) {
    vp3_gelato_api_v1(403, ['ok'=>false,'error'=>'Sign in to your VP3 account to manage Gelato connections.']);
}
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = db();
$available = $pdo && vp3_gelato_schema_ready_v1($pdo);
if ($method === 'GET') {
    try {
        $connections = $available ? vp3_gelato_connections_v1($pdo, $userId) : [];
    } catch (Throwable) {
        vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Gelato connections are currently unavailable.']);
    }
    vp3_gelato_api_v1(200, ['ok'=>true,'available'=>$available,'connections'=>$connections]);
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    vp3_gelato_api_v1(405, ['ok'=>false,'error'=>'GET or POST required.']);
}
if (!$available) {
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Gelato connections are unavailable. Run the VP3 database upgrade.']);
}

$raw = file_get_contents('php://input', false, null, 0, 4097);
if (!is_string($raw) || strlen($raw) > 4096) {
    vp3_gelato_api_v1(413, ['ok'=>false,'error'=>'Connection request is too large.']);
}
$input = json_decode($raw, true, 12);
if (!is_array($input)) vp3_gelato_api_v1(400, ['ok'=>false,'error'=>'Enter a valid connection request.']);
$csrf = $input['csrf_token'] ?? null;
if (!is_string($csrf) || $csrf === '' || !hash_equals(csrf_token(), $csrf)) {
    vp3_gelato_api_v1(419, ['ok'=>false,'error'=>'Session expired. Refresh and try again.']);
}

try {
    if (($input['action'] ?? null) === 'connect'
        && !array_diff(array_keys($input), ['action','csrf_token','origin','code','label'])
        && is_string($input['origin'] ?? null) && is_string($input['code'] ?? null)
        && is_string($input['label'] ?? '')) {
        $connection = vp3_gelato_connect_v1($pdo, $userId, $input['origin'], $input['code'], $input['label'] ?? '');
        vp3_gelato_api_v1(201, ['ok'=>true,'connection'=>$connection]);
    }
    if (($input['action'] ?? null) === 'disconnect'
        && !array_diff(array_keys($input), ['action','csrf_token','connectionId'])
        && is_string($input['connectionId'] ?? null)) {
        $revoked = vp3_gelato_disconnect_v1($pdo, $userId, $input['connectionId']);
        vp3_gelato_api_v1(200, ['ok'=>true,'revokedRemotely'=>$revoked]);
    }
    vp3_gelato_api_v1(400, ['ok'=>false,'error'=>'Unsupported Gelato connection request.']);
} catch (InvalidArgumentException $e) {
    vp3_gelato_api_v1(422, ['ok'=>false,'error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    $status = $e->getCode();
    if (!$e instanceof PDOException && in_array($status, [403,404,409,422,502,503], true)) {
        vp3_gelato_api_v1($status, ['ok'=>false,'error'=>$e->getMessage()]);
    }
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Gelato connections are currently unavailable.']);
} catch (Throwable) {
    vp3_gelato_api_v1(503, ['ok'=>false,'error'=>'Gelato connections are currently unavailable.']);
}
