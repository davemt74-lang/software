<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET','POST'], true)) {
        header('Allow: GET, POST');
        throw new RuntimeException('Use GET or POST.', 405);
    }
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to view private canvas artwork.', 401);
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Canvas artwork is unavailable.', 503);

    if ($method === 'GET') {
        $agentId = filter_var($_GET['agent_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        $canvasId = $_GET['canvas_id'] ?? null;
        $kind = $_GET['kind'] ?? null;
        $revision = isset($_GET['revision'])
            ? filter_var($_GET['revision'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : null;
        $digest = $_GET['v'] ?? '';
        if (!is_int($agentId) || !is_string($canvasId) || !is_string($kind) || !in_array($kind, ['icon','thumbnail'], true)
            || ($kind === 'thumbnail' ? !is_int($revision) : $revision !== null) || !is_string($digest)) {
            throw new InvalidArgumentException('Choose an exact canvas artwork item.', 422);
        }
        $live = vp3_coding_canvas_scope_v1($pdo, $user, $agentId, 0);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $image = vp3_coding_canvas_artwork_read_v1($pdo, $live, $agentId, $canvasId, $kind, $revision, $digest);
        header('Content-Type: '.$image['mime']);
        header('Content-Length: '.strlen($image['bytes']));
        echo $image['bytes'];
        return;
    }

    $raw = file_get_contents('php://input', false, null, 0, 102401);
    if ($raw === false || strlen($raw) > 102400) throw new RuntimeException('The canvas artwork request is too large.', 413);
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input) || ($input['action'] ?? null) !== 'save_artwork') {
        throw new InvalidArgumentException('Choose a canvas artwork operation.', 422);
    }
    if (!is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(), $input['csrf_token'])) {
        throw new RuntimeException('Refresh the page to restore this session.', 419);
    }
    $agentId = $input['agent_id'] ?? null;
    $conversationId = $input['conversation_id'] ?? null;
    $canvasId = $input['canvas_id'] ?? null;
    $revision = $input['revision'] ?? null;
    $kind = $input['kind'] ?? null;
    $image = $input['image_base64'] ?? null;
    $replaceHash = $input['replace_hash'] ?? null;
    if (!is_int($agentId) || $agentId < 0 || !is_int($conversationId) || $conversationId < 0 || !is_string($canvasId)
        || !is_int($revision) || $revision < 1 || !is_string($kind) || !in_array($kind, ['icon','thumbnail'], true)
        || !is_string($image) || strlen($image) > 98304 || ($replaceHash !== null && !is_string($replaceHash))) {
        throw new InvalidArgumentException('Choose an exact canvas version and supported artwork image.', 422);
    }
    $bytes = base64_decode($image, true);
    if ($bytes === false || base64_encode($bytes) !== $image) {
        throw new InvalidArgumentException('Choose valid canvas artwork data.', 422);
    }
    $live = vp3_coding_canvas_scope_v1($pdo, $user, $agentId, $conversationId);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $canvas = vp3_coding_canvas_artwork_save_v1($pdo, $live, $agentId, $canvasId, $kind, $revision, $bytes, $replaceHash);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>true,'canvas'=>$canvas], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $code = $error->getCode();
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException => 422,
        $error instanceof PDOException => 503,
        in_array($code, [401,403,404,405,409,413,419,422,503], true) => $code,
        default => 503,
    };
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>$status === 503 ? 'Canvas artwork is unavailable.' : $error->getMessage()],
        JSON_INVALID_UTF8_SUBSTITUTE);
}
