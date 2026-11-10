<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/bootstrap.php';
require_once dirname(__DIR__).'/includes/coding-canvas-model-v1.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function vp3_coding_settings_json_v1(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function vp3_coding_settings_balance_v1(PDO $pdo, array $user, string $funding): ?array
{
    if ($funding === 'own_key') {
        if (!ai_usage_accounting_v032_schema_ready($pdo)) return null;
        $query = $pdo->prepare("SELECT COALESCE(SUM(total_tokens),0) FROM ai_execution_ledger
            WHERE user_id=? AND source='user_provider' AND created_at>=?");
        $query->execute([(int)$user['id'], gmdate('Y-m-01 00:00:00')]);
        return ['tracked_month_tokens' => (int)$query->fetchColumn()];
    }

    $balance = subscription_ai_balance($user, $pdo);
    if (!empty($balance['compatibility'])) return null;
    if ($balance['unlimited']) return ['unlimited' => true];
    $end = $balance['period']['end'] ?? null;
    $subscription = $balance['subscription'] ?? [];
    $renews = $end && empty($subscription['is_trial']) && ($subscription['assignment_source'] ?? '') !== 'stripe'
        && (empty($subscription['ends_at']) || $subscription['ends_at'] > $end);
    return [
        'unlimited' => false,
        'package_allowance' => (int)$balance['package_allowance'],
        'package_remaining' => (int)$balance['package_remaining'],
        'credits_remaining' => (int)$balance['credits_remaining'],
        'reserved' => (int)$balance['reserved'],
        'period_end' => $end ? substr((string)$end, 0, 10) : null,
        'renews' => (bool)$renews,
    ];
}

$user = current_user();
if (!$user) vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Sign in to see Coding Agent settings.'], 401);
if (!has_permission('chat.access', $user)) {
    vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Chat access is unavailable for this account.'], 403);
}
$pdo = db();
if (!$pdo) vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Coding Agent settings are unavailable.'], 503);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Use GET or POST.'], 405);
}

try {
    if ($method === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true, 5, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !is_string($input['csrf_token'] ?? null) || !hash_equals(csrf_token(), $input['csrf_token'])) {
            vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Session expired. Refresh and try again.'], 419);
        }
        if (!array_key_exists('model', $input) || ($input['model'] !== null && !is_array($input['model']))) {
            throw new InvalidArgumentException('Select a provider and exact model.');
        }
        if (!subscription_has_entitlement($user, 'main_ai.access')) {
            vp3_coding_settings_json_v1(['ok' => false, 'error' => 'AI access is unavailable for this account.'], 403);
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $state = $method === 'POST'
        ? vp3_coding_canvas_model_save_v1($pdo, $user, $input['model'])
        : vp3_coding_canvas_model_state_v1($pdo, $user);
    if (!subscription_has_entitlement($user, 'main_ai.access')) {
        $state = array_replace($state, [
            'default' => null, 'effective' => null, 'options' => [],
            'unavailable' => 'AI access is unavailable for this account.',
        ]);
    }
    try {
        $balance = vp3_coding_settings_balance_v1($pdo, $user, $state['funding']);
    } catch (PDOException) {
        $balance = null;
    }
    vp3_coding_settings_json_v1(['ok' => true, 'state' => $state, 'balance' => $balance]);
} catch (InvalidArgumentException | JsonException) {
    vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Select a provider and exact model.'], 422);
} catch (RuntimeException $error) {
    $status = $error->getCode();
    if (in_array($status, [402, 403, 409], true)) {
        vp3_coding_settings_json_v1(['ok' => false, 'error' => $error->getMessage()], $status);
    }
    vp3_coding_settings_json_v1(['ok' => false, 'error' => 'Coding Agent settings are temporarily unavailable.'], 503);
}
