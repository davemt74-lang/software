<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/homeserver-knowledge-v062.php';

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$user = current_user();
$userId = (int)($user['id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required.', 'code' => 'authentication']);
    exit;
}
if (!personal_capability_has_v242('personal_knowledge.access', $user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Personal Knowledge access is unavailable for this account.', 'code' => 'account_access']);
    exit;
}
$canManage = personal_capability_has_v242('personal_knowledge.manage', $user);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $force = (string)($_GET['refresh'] ?? '') === '1';
        echo json_encode(['ok' => true, 'snapshot' => homeserver_knowledge_v062_snapshot($userId, $force)], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['ok' => false, 'error' => 'Unsupported request method.', 'code' => 'method']);
        exit;
    }
    if (!verify_csrf()) {
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.', 'code' => 'csrf']);
        exit;
    }
    if (!$canManage) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This account can view Local Knowledge but cannot change it.', 'code' => 'read_only']);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $result = null;
    if ($action === 'map_folder') {
        if (function_exists('set_time_limit')) @set_time_limit(VP3_HOMESERVER_KNOWLEDGE_MAP_TIMEOUT + 30);
        $excludesRaw = trim((string)($_POST['excludes'] ?? ''));
        $excludes = [];
        if ($excludesRaw !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $excludesRaw) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && count($excludes) < 40) $excludes[] = $line;
            }
        }
        $result = homeserver_knowledge_v062_map_folder($userId, [
            'collection_key' => (string)($_POST['collection_key'] ?? ''),
            'label' => (string)($_POST['label'] ?? ''),
            'recursive' => isset($_POST['recursive']) ? '1' : '0',
            'scan_interval_seconds' => (int)($_POST['scan_interval_seconds'] ?? 120),
            'excludes' => $excludes,
        ]);
    } elseif ($action === 'unmap_folder') {
        $result = homeserver_knowledge_v062_unmap_folder($userId, (string)($_POST['mapping_id'] ?? ''));
    } elseif ($action === 'write_item') {
        $result = homeserver_knowledge_v062_write_item($userId, [
            'collection_key' => (string)($_POST['collection_key'] ?? ''),
            'title' => (string)($_POST['title'] ?? ''),
            'content' => (string)($_POST['content'] ?? ''),
            'kind' => (string)($_POST['kind'] ?? 'summary'),
        ]);
    } elseif ($action === 'request_write_permission') {
        $result = homeserver_knowledge_v062_request_write_permission($userId);
    } elseif ($action === 'check_write_permission') {
        $result = homeserver_knowledge_v062_check_write_permission($userId);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported Local Knowledge action.', 'code' => 'unsupported_action']);
        exit;
    }

    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_SLASHES);
} catch (HomeServerKnowledgeV062Exception $e) {
    http_response_code($e->httpStatus);
    echo json_encode([
        'ok' => false,
        'error' => $e->publicMessage,
        'code' => $e->publicCode,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Local Knowledge could not complete that request.',
        'code' => 'unexpected',
    ], JSON_UNESCAPED_SLASHES);
}
