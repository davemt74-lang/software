<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Use POST.', 405);
    }
    $user = current_user();
    if (!$user) throw new RuntimeException('Sign in to use private Canvases.', 401);
    $raw = file_get_contents('php://input', false, null, 0, 98305);
    if ($raw === false || strlen($raw) > 98304) throw new RuntimeException('This Canvas request is too large.', 413);
    $input = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    if (!$input instanceof stdClass) throw new InvalidArgumentException('Enter a valid Canvas request.');
    if (!is_string($input->csrf_token ?? null) || !hash_equals(csrf_token(), $input->csrf_token)) {
        throw new RuntimeException('Refresh the page to restore this session.', 419);
    }

    $action = $input->action ?? '';
    $aid = $input->agent_id ?? null;
    $cid = $input->conversation_id ?? null;
    if (!is_int($aid) || $aid < 0 || !is_int($cid) || $cid < 0) {
        throw new InvalidArgumentException('Choose the current Agent and conversation.');
    }
    $pdo = db();
    if (!$pdo) throw new RuntimeException('Canvas storage is unavailable.', 503);
    $user = vp3_coding_canvas_scope_v1($pdo, $user, $aid, $cid);
    vp3_coding_canvas_authority_v1($pdo, $user, $aid, in_array($action, ['build', 'repair'], true));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $canvasId = $input->canvas_id ?? null;
    $revision = $input->revision ?? null;
    if (in_array($action, ['open', 'repair', 'test', 'apply', 'save_state', 'delete'], true)
        && !is_string($canvasId)) throw new InvalidArgumentException('Choose a saved Canvas.');
    if (($revision !== null || in_array($action, ['repair', 'test', 'apply', 'save_state'], true))
        && (!is_int($revision) || $revision < 1)) throw new InvalidArgumentException('Choose an exact Canvas revision.');

    if ($action === 'build' || $action === 'repair') {
        $request = $input->request_id ?? null;
        $prompt = $input->prompt ?? null;
        $diagnostic = $action === 'repair' ? ($input->diagnostic ?? null) : null;
        if (!is_string($request) || !is_string($prompt) || trim($prompt) === '' || strlen($prompt) > 8000
            || str_contains($prompt, "\0") || ($diagnostic !== null
            && (!is_string($diagnostic) || strlen($diagnostic) > 1600 || str_contains($diagnostic, "\0")))) {
            throw new InvalidArgumentException('Enter a valid Canvas instruction within the size limit.');
        }
        $job = vp3_coding_canvas_queue_v1($pdo, $user, $aid, $cid, vp3_coding_canvas_id_v1($request), trim($prompt),
            $action === 'repair' ? vp3_coding_canvas_id_v1($canvasId) : null,
            $action === 'repair' ? $revision : null, $diagnostic);
        $result = ['job' => $job];
    } elseif ($action === 'resume') {
        if ($cid < 1) throw new InvalidArgumentException('Choose a current Chat.');
        $result = ['job' => vp3_coding_canvas_resume_v1($pdo, $user, $aid, $cid)];
    } elseif (in_array($action, ['job', 'tick', 'stop'], true)) {
        $runId = $input->run_id ?? null;
        if ($cid < 1 || !is_int($runId) || $runId < 1) throw new InvalidArgumentException('Choose an exact Coding Agent run.');
        if ($action === 'tick') {
            ignore_user_abort(true);
            @set_time_limit(150);
        }
        $result = ['job' => vp3_coding_canvas_job_v1($pdo, $user, $aid, $cid, $runId, $action)];
    } else {
        $stateRevision = $input->state_revision ?? null;
        if (in_array($action, ['test', 'save_state'], true) && (!is_string($stateRevision)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $stateRevision))) {
            throw new InvalidArgumentException('Open the current Canvas data again.');
        }
        $active = $input->expected_active_revision ?? null;
        if ($action === 'apply' && (!property_exists($input, 'expected_active_revision')
            || ($active !== null && (!is_int($active) || $active < 1)))) {
            throw new InvalidArgumentException('Open the current Canvas revision again.');
        }
        $state = $input->state ?? null;
        if ($action === 'save_state' && !$state instanceof stdClass) {
            throw new InvalidArgumentException('Canvas data must be a JSON object.');
        }
        $deleteFolder = $input->delete_folder ?? null;
        if ($action === 'delete' && !is_bool($deleteFolder)) {
            throw new InvalidArgumentException('Choose whether to keep the Knowledge Base folder.');
        }
        $result = match ($action) {
            'list' => ['canvases' => vp3_coding_canvas_list_v1($pdo, $user, $aid)],
            'open' => vp3_coding_canvas_read_v1($pdo, $user, $aid, $canvasId, $revision),
            'test' => vp3_coding_canvas_mark_tested_v1($pdo, $user, $aid, $canvasId, $revision, $stateRevision),
            'apply' => vp3_coding_canvas_activate_v1($pdo, $user, $aid, $canvasId, $revision, $active),
            'save_state' => vp3_coding_canvas_save_state_v1($pdo, $user, $aid, $canvasId, $revision, $stateRevision, $state),
            'delete' => vp3_coding_canvas_delete_v1($pdo, $user, $aid, $canvasId, $deleteFolder),
            default => throw new InvalidArgumentException('Choose a Canvas operation.'),
        };
    }
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    $code = $error->getCode();
    $status = match (true) {
        $error instanceof InvalidArgumentException, $error instanceof JsonException => 422,
        $error instanceof PDOException => 503,
        in_array($code, [401, 402, 403, 404, 405, 409, 413, 419, 422, 503], true) => $code,
        default => 503,
    };
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $status === 503
        ? 'Canvas storage or the Coding Agent is unavailable. Check Database Upgrade and try again.'
        : ($error instanceof JsonException ? 'Enter a valid Canvas request.' : $error->getMessage())],
        JSON_INVALID_UTF8_SUBSTITUTE);
}
