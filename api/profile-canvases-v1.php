<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';
require_once dirname(__DIR__).'/includes/profile-canvases-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Use POST.', 405);
    }
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to manage your Profile Canvases.', 401);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) throw new RuntimeException('This Profile Canvas request is too large.', 413);
    $input = json_decode($raw, false, 8, JSON_THROW_ON_ERROR);
    if (!$input instanceof stdClass) throw new InvalidArgumentException('Enter a valid Profile Canvas request.');
    if (!is_string($input->csrf_token ?? null) || !hash_equals(csrf_token(), $input->csrf_token)) {
        throw new RuntimeException('Refresh the page to restore this session.', 419);
    }
    $action = $input->action ?? null;
    $aid = $input->agent_id ?? null;
    $cid = $input->conversation_id ?? null;
    $id = $input->canvas_id ?? null;
    if (!in_array($action, ['status','set'], true) || !is_int($aid) || $aid < 0 || !is_int($cid) || $cid < 0 || !is_string($id)) {
        throw new InvalidArgumentException('Choose the current Canvas, Agent and conversation.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Profile Canvas storage is unavailable.', 503);
    $user = vp3_coding_canvas_scope_v1($pdo, $user, $aid, $cid);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($action === 'set') {
        if (!is_bool($input->listed ?? null) || !is_string($input->expected_revision ?? null) || !is_string($input->expected_publication ?? null)) {
            throw new InvalidArgumentException('Reopen the current Canvas publication and Profile settings.');
        }
        $result = vp3_profile_canvas_set_v1($pdo, $user, $aid, $id, $input->listed, $input->expected_revision, $input->expected_publication);
    } else {
        $result = vp3_profile_canvas_status_v1($pdo, $user, $aid, $id);
    }
    echo json_encode(['ok'=>true] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException => 422,
        $error instanceof PDOException => 503,
        in_array($error->getCode(), [401,403,404,405,409,413,419,503], true) => $error->getCode(),
        default => 503,
    };
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$status === 503
        ? 'Profile Canvases are temporarily unavailable. Check Database Upgrade and try again.'
        : ($error instanceof JsonException ? 'Enter a valid Profile Canvas request.' : $error->getMessage())], JSON_INVALID_UTF8_SUBSTITUTE);
}
