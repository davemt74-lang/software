<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/agent-worker-cloud-v1910.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function vp3_media_library_image_review_v1(string $secret, int $owner, string $request, array $plan, int $expires): string
{
    $message = json_encode([$owner, $request, $plan, $expires], JSON_THROW_ON_ERROR);
    return $expires . '.' . hash_hmac('sha256', 'media-library-image-v1:' . $message, $secret);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new DomainException('Use POST for image requests.', 405);
    }
    $user = current_user();
    if (!$user) {
        throw new DomainException('Sign in to use the private Media Library.', 401);
    }
    if (!has_permission('chat.access', $user)) {
        throw new DomainException('Media Library access is unavailable.', 403);
    }
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) {
        throw new DomainException('The image request is too large.', 413);
    }
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) {
        throw new InvalidArgumentException('Choose an image operation.');
    }
    if (!is_string($body['csrf_token'] ?? null) || !hash_equals(csrf_token(), $body['csrf_token'])) {
        throw new DomainException('Refresh Media Library to restore this session.', 419);
    }
    $owner = (int)$user['id'];
    if (($body['owner_key'] ?? null) !== (string)$owner) {
        throw new DomainException('The signed-in account changed. Reopen Media Library.', 409);
    }
    $action = $body['action'] ?? '';
    $planFields = ['prompt', 'title', 'size', 'quality', 'background'];
    $fields = match ($action) {
        'status' => [],
        'preview' => $planFields,
        'submit' => [...$planFields, 'request_key', 'review_token', 'confirmed'],
        'get', 'tick' => ['generation_id'],
        'cancel' => ['generation_id', 'confirmed'],
        default => throw new InvalidArgumentException('Choose an image operation.'),
    };
    if (array_diff(array_keys($body), ['action', 'csrf_token', 'owner_key', ...$fields])) {
        throw new InvalidArgumentException('Unsupported image request fields.');
    }
    $pdo = db();
    if (!$pdo) {
        throw new DomainException('Image request storage is unavailable.', 503);
    }
    $user = vp3_image_workspace_owner_v1($pdo, $user);
    $secret = $_SESSION['media_library_image_review_v1'] ?? '';
    if ($action === 'preview' && $secret === '') {
        $secret = $_SESSION['media_library_image_review_v1'] = bin2hex(random_bytes(32));
    }
    if ($secret !== '') {
        $secret = hash_hmac('sha256', session_id(), $secret);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $result = [];
    if ($action === 'status') {
        $message = '';
        try {
            vp3_image_queue_ready_v1($pdo, $user);
        } catch (RuntimeException $error) {
            if ($error instanceof PDOException || !in_array($error->getCode(), [403, 503], true)) {
                throw $error;
            }
            $message = $error->getMessage();
        }
        $history = [];
        if (table_exists('agent_image_generations_v1') && table_exists('agent_workflow_runs')) {
            $stmt = $pdo->prepare("SELECT g.* FROM agent_image_generations_v1 g
                JOIN agent_workflow_runs r ON r.id=g.run_id AND r.owner_user_id=g.owner_user_id
                WHERE g.owner_user_id=? AND g.conversation_id=0 AND g.agent_id=0 AND g.user_message_id IS NULL
                    AND r.origin='media_library'
                ORDER BY CASE WHEN r.status IN ('approved','queued','executing','paused') THEN 0 ELSE 1 END,
                    r.id DESC LIMIT 12");
            $stmt->execute([$owner]);
            $history = array_map(static fn(array $row) => vp3_image_view_v1($pdo, $user, $row), $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $result = ['can_generate' => $message === '', 'message' => $message, 'generations' => $history];
    } elseif ($action === 'preview' || $action === 'submit') {
        $plan = vp3_image_text_plan_v1($body);
        if ($action === 'preview') {
            vp3_image_queue_ready_v1($pdo, $user);
            $request = bin2hex(random_bytes(16));
            $result = ['plan' => $plan, 'request_key' => $request,
                'review_token' => vp3_media_library_image_review_v1($secret, $owner, $request, $plan, time() + 600)];
        } else {
            $request = $body['request_key'] ?? null;
            $token = $body['review_token'] ?? null;
            if (($body['confirmed'] ?? null) !== true || !is_string($request) || !preg_match('/\A[a-f0-9]{32}\z/', $request)
                || !is_string($token) || !preg_match('/\A([0-9]{1,12})\.[a-f0-9]{64}\z/', $token, $matches)) {
                throw new InvalidArgumentException('Review and confirm the image prompt before generating it.');
            }
            $expires = (int)$matches[1];
            $now = time();
            if ($secret === '' || $expires <= $now || $expires > $now + 600
                || !hash_equals(vp3_media_library_image_review_v1($secret, $owner, $request, $plan, $expires), $token)) {
                throw new DomainException('The prompt changed or the review expired. Review the image request again.', 409);
            }
            $result = ['generation' => vp3_image_queue_workspace_v1($pdo, $user, $plan, $request)];
        }
    } else {
        $id = $body['generation_id'] ?? null;
        if (!is_string($id)) {
            throw new InvalidArgumentException('Choose an image request.');
        }
        if (!table_exists('agent_image_generations_v1')) {
            throw new DomainException('Image request not found.', 404);
        }
        $row = vp3_image_row_v1($pdo, $user, $id);
        if (!vp3_image_workspace_row_v1($row)) {
            throw new DomainException('Image request not found in Media Library.', 404);
        }
        if ($action === 'cancel') {
            if (($body['confirmed'] ?? null) !== true) {
                throw new InvalidArgumentException('Confirm the image cancellation.');
            }
            $run = vp3_image_run_v1($pdo, $user, $row);
            $closed = ['completed', 'cancelled', 'failed'];
            if (!in_array($run['status'], $closed, true)) {
                try {
                    agent_job_cancel_v1900($pdo, $user, (int)$run['id']);
                } catch (RuntimeException $error) {
                    // The worker can finish while cancellation acquires the run lock.
                    if ($error instanceof PDOException || !in_array(vp3_image_run_v1($pdo, $user, $row)['status'], $closed, true)) {
                        throw $error;
                    }
                }
                $row = vp3_image_row_v1($pdo, $user, $id);
            }
        }
        $result = ['generation' => $action === 'tick'
            ? vp3_image_tick_v1($pdo, $user, $row)
            : vp3_image_view_v1($pdo, $user, $row)];
    }
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $allowed = [401, 403, 404, 405, 409, 413, 419, 503];
    $code = $error->getCode();
    $status = $error instanceof InvalidArgumentException || $error instanceof JsonException ? 422
        : (!$error instanceof PDOException && in_array($code, $allowed, true) ? $code : 503);
    $message = match (true) {
        $error instanceof JsonException => 'Invalid image request.',
        $error instanceof InvalidArgumentException, $error instanceof DomainException => $error->getMessage(),
        !$error instanceof PDOException && in_array($code, [403, 404, 409, 503], true) => $error->getMessage(),
        default => 'The image request could not be verified. Refresh image history before creating another request.',
    };
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_INVALID_UTF8_SUBSTITUTE);
}
