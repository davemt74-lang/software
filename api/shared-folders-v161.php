<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/shared-folders-v161.php';

const VP3_SHARED_FOLDERS_V161 = 'vp3-shared-folders-v161-20260913';

function shared_folders_v161_api_json(bool $ok, array $data = [], int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok,'build'=>VP3_SHARED_FOLDERS_V161] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) shared_folders_v161_api_json(false, ['error'=>'Sign in to use shared folders.'], 401);

$canKnowledge = function_exists('personal_capability_has_v242') && personal_capability_has_v242('personal_knowledge.access', $user);
$canTranscriptions = function_exists('has_permission') && has_permission('artist_listening.access', $user);
if (!$canKnowledge && !$canTranscriptions) shared_folders_v161_api_json(false, ['error'=>'Shared folders are unavailable for this account.'], 403);

$pdo = db();
if (!$pdo) shared_folders_v161_api_json(false, ['error'=>'Database unavailable.'], 503);
if (!shared_folders_v161_ready()) shared_folders_v161_api_json(false, ['error'=>'Run the latest database upgrade before using shared folders.'], 503);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
try {
    if ($method === 'GET') {
        shared_folders_v161_api_json(true, ['folders'=>shared_folders_v161_list($pdo, $user)]);
    }
    if ($method !== 'POST') shared_folders_v161_api_json(false, ['error'=>'POST is required.'], 405);

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $csrf = trim((string)($input['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals(csrf_token(), $csrf)) shared_folders_v161_api_json(false, ['error'=>'Session expired. Refresh and try again.'], 419);

    $canManageKnowledge = function_exists('personal_capability_has_v242') && personal_capability_has_v242('personal_knowledge.manage', $user);
    if (!$canManageKnowledge && !$canTranscriptions) shared_folders_v161_api_json(false, ['error'=>'This account cannot create folders.'], 403);

    $folder = shared_folders_v161_create($pdo, $user, (string)($input['name'] ?? ''));
    shared_folders_v161_api_json(true, ['folder'=>$folder,'folders'=>shared_folders_v161_list($pdo, $user)]);
} catch (Throwable $e) {
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Shared folders could not complete that request.';
    shared_folders_v161_api_json(false, ['error'=>$message], $e instanceof RuntimeException ? 422 : 500);
}
