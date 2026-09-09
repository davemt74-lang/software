<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!function_exists('public_platform_v159_user_has_type')) {
    function public_platform_v159_user_has_type(int $userId, string $role): bool
    {
        $userId = max(0, $userId);
        $role = strtolower(trim($role));
        if ($userId < 1 || $role === '') return false;
        $pdo = db();
        if (!$pdo) return false;
        try {
            if (table_exists('user_account_types')) {
                $stmt = $pdo->prepare('SELECT 1 FROM user_account_types WHERE user_id=? AND role=? LIMIT 1');
                $stmt->execute([$userId, $role]);
                if ($stmt->fetchColumn()) return true;
            }
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
            $stmt->execute([$userId]);
            return strtolower(trim((string)$stmt->fetchColumn())) === $role;
        } catch (Throwable $e) {
            return false;
        }
    }
}

require_once dirname(__DIR__) . '/includes/artist-listening.php';
require_once dirname(__DIR__) . '/includes/homeserver-approvals-v028.php';
require_once dirname(__DIR__) . '/includes/homeserver-knowledge-backup-v029.php';

function artist_listening_hs_v029_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode(['ok'=>$ok] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function artist_listening_hs_v029_permissions(): array
{
    return array_values(array_unique(array_merge(
        homeserver_approvals_v028_permissions(),
        ['knowledge.write']
    )));
}

function artist_listening_hs_v029_begin_permission_upgrade(int $userId): array
{
    $support = homeserver_knowledge_v029_support($userId, true);
    if (empty($support['paired']) || empty($support['connected'])) {
        throw new RuntimeException('Pair and connect HomeServer before enabling Knowledge backup.');
    }
    if (empty($support['supported'])) {
        throw new RuntimeException('Update HomeServer before enabling Knowledge backup.');
    }

    $row = homeserver_vp3_connection($userId);
    if (!$row) throw new RuntimeException('HomeServer connection was not found.');
    $pendingRequestId = trim((string)($row['pending_request_id'] ?? ''));
    $pendingCode = trim((string)($row['pending_code'] ?? ''));
    if ($pendingRequestId !== '' && $pendingCode !== '') {
        return ['pending'=>true,'approval_code'=>$pendingCode,'request_id'=>$pendingRequestId];
    }

    $relay = homeserver_vp3_decrypt((string)($row['relay_token_enc'] ?? ''));
    if (strlen($relay) < 20) throw new RuntimeException('HomeServer relay authorization is unavailable.');
    $pairing = homeserver_vp3_remote_operation($relay, 'pair.request', [
        'app_key'=>'vp3',
        'app_name'=>'VP3',
        'permissions'=>artist_listening_hs_v029_permissions(),
    ]);
    $requestId = trim((string)($pairing['request_id'] ?? ''));
    $claimToken = trim((string)($pairing['claim_token'] ?? ''));
    $approvalCode = trim((string)($pairing['code'] ?? ''));
    if ($requestId === '' || strlen($claimToken) < 20 || $approvalCode === '') {
        throw new RuntimeException('HomeServer returned an unsupported permission-upgrade response.');
    }

    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    $stmt = $pdo->prepare(
        "UPDATE homeserver_connections
         SET pending_request_id=?,pending_claim_token_enc=?,pending_code=?,last_error=''
         WHERE user_id=?"
    );
    $stmt->execute([$requestId, homeserver_vp3_encrypt($claimToken), $approvalCode, $userId]);
    return [
        'pending'=>true,
        'approval_code'=>$approvalCode,
        'request_id'=>$requestId,
        'expires_at'=>(string)($pairing['expires_at'] ?? ''),
    ];
}

function artist_listening_hs_v029_check_permission_upgrade(int $userId): array
{
    $row = homeserver_vp3_connection($userId);
    if (!$row) throw new RuntimeException('HomeServer connection was not found.');
    if (trim((string)($row['pending_request_id'] ?? '')) !== '') {
        $pairing = homeserver_vp3_check_pairing($userId);
        if (($pairing['status'] ?? '') !== 'paired') {
            return ['ready'=>false,'pairing'=>$pairing];
        }
    }
    return ['ready'=>true,'support'=>homeserver_knowledge_v029_support($userId, true)];
}

function artist_listening_hs_v029_recover_stale_upload(PDO $pdo, array $user, int $sessionId): void
{
    $session = artist_listening_v172_session($pdo, $user, $sessionId);
    $state = homeserver_knowledge_v029_session_state($session);
    $assets = is_array($state['assets'] ?? null) ? $state['assets'] : [];
    $changed = false;
    foreach ($assets as $key => $asset) {
        if (!is_array($asset) || empty($asset['upload_id'])) continue;
        $error = mb_strtolower((string)($asset['last_error'] ?? ''));
        if ($error === '') continue;
        if ((str_contains($error, 'upload') || str_contains($error, 'transfer'))
            && (str_contains($error, 'expired') || str_contains($error, 'not found') || str_contains($error, 'missing'))) {
            $asset['upload_id'] = '';
            $asset['offset'] = 0;
            $asset['state'] = 'pending';
            $asset['last_error'] = '';
            $assets[$key] = $asset;
            $changed = true;
        }
    }
    if ($changed) {
        $state['assets'] = $assets;
        $state['state'] = 'pending';
        $state['last_error'] = '';
        homeserver_knowledge_v029_save_state($pdo, $user, $sessionId, $state);
    }
}

function artist_listening_hs_v029_pump(array $user, int $sessionId, int $steps = 8): array
{
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
    artist_listening_hs_v029_recover_stale_upload($pdo, $user, $sessionId);
    $steps = max(1, min(8, $steps));
    $result = homeserver_knowledge_v029_status($user, $sessionId);
    for ($i = 0; $i < $steps; $i++) {
        $backup = is_array($result['backup'] ?? null) ? $result['backup'] : [];
        $state = (string)($backup['state'] ?? 'idle');
        $complete = !empty($backup['text_synced'])
            && (int)($backup['recording_synced'] ?? 0) >= (int)($backup['recording_total'] ?? 0);
        if ($complete && $state === 'synced') break;
        if (in_array($state, ['unsupported','permission_required'], true)) break;
        $result = homeserver_knowledge_v029_sync_step($user, $sessionId);
    }
    return $result;
}

$user = current_user();
if (!$user) artist_listening_hs_v029_json(false, ['error'=>'Sign in to use HomeServer backup.'], 401);
if (!has_permission('artist_listening.access', $user)) {
    artist_listening_hs_v029_json(false, ['error'=>'Artist Listening permission is required.'], 403);
}
if (!artist_listening_v172_schema_ready()) {
    artist_listening_hs_v029_json(false, ['error'=>'Artist Listening is not ready.'], 503);
}

$userId = (int)$user['id'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = [];
if ($method === 'POST') {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) {
        artist_listening_hs_v029_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);
    }
}
$action = trim((string)(($method === 'POST' ? ($input['action'] ?? '') : ($_GET['action'] ?? 'status'))));
$sessionId = max(0, (int)(($method === 'POST' ? ($input['session_id'] ?? 0) : ($_GET['session_id'] ?? 0))));

try {
    if ($method === 'GET') {
        if ($action !== 'status' || $sessionId < 1) {
            artist_listening_hs_v029_json(false, ['error'=>'A transcription session is required.'], 422);
        }
        artist_listening_hs_v029_json(true, homeserver_knowledge_v029_status($user, $sessionId));
    }
    if ($method !== 'POST') {
        artist_listening_hs_v029_json(false, ['error'=>'Unsupported request method.'], 405);
    }

    if ($action === 'upgrade_permission') {
        artist_listening_hs_v029_json(true, ['permission_upgrade'=>artist_listening_hs_v029_begin_permission_upgrade($userId)]);
    }
    if ($action === 'check_permission') {
        artist_listening_hs_v029_json(true, ['permission_upgrade'=>artist_listening_hs_v029_check_permission_upgrade($userId)]);
    }
    if ($sessionId < 1) {
        artist_listening_hs_v029_json(false, ['error'=>'A transcription session is required.'], 422);
    }
    if ($action === 'save_direct' || $action === 'save_cloud') {
        $mode = $action === 'save_cloud' ? 'cloud' : 'direct';
        $result = homeserver_knowledge_v029_prepare($user, $sessionId, $mode);
        $backup = is_array($result['backup'] ?? null) ? $result['backup'] : [];
        if (!empty($backup['text_synced'])) {
            $result = artist_listening_hs_v029_pump($user, $sessionId, 8);
        }
        artist_listening_hs_v029_json(true, $result);
    }
    if ($action === 'sync') {
        artist_listening_hs_v029_json(true, artist_listening_hs_v029_pump($user, $sessionId, 8));
    }

    artist_listening_hs_v029_json(false, ['error'=>'Unsupported HomeServer backup action.'], 422);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException
        ? trim($e->getMessage())
        : 'HomeServer backup could not complete that request.';
    if ($message === '' || preg_match('/(?:credential key|decrypt|database|sql|openssl|curl)/i', $message)) {
        $message = 'HomeServer backup could not complete that request.';
    }
    artist_listening_hs_v029_json(false, ['error'=>mb_substr($message, 0, 500)], $e instanceof RuntimeException ? 422 : 500);
}
