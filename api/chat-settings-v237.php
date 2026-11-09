<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function chat_settings_json_v237(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_settings_input_v237(): array
{
    $raw = json_decode((string)file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function chat_settings_require_csrf_v237(array $input): void
{
    $token = (string)($input['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        chat_settings_json_v237(['ok'=>false,'error'=>'Session expired. Refresh and try again.'], 419);
    }
}

function chat_settings_profile_payload_v237(PDO $pdo, array $user): ?array
{
    if (!has_permission('account.access', $user) || !profile_agent_schema_ready($pdo)) {
        return null;
    }

    $state = profile_runtime_owner_state($pdo, $user);
    return [
        'profile' => is_array($state['profile'] ?? null) ? $state['profile'] : [],
        'agents' => is_array($state['agents'] ?? null) ? $state['agents'] : [],
        'public_agent_status' => is_array($state['public_agent_status'] ?? null) ? $state['public_agent_status'] : [],
    ];
}

$user = current_user();
if (!$user) {
    chat_settings_json_v237(['ok'=>false,'error'=>'login_required'], 401);
}
if (!has_permission('chat.access', $user)) {
    chat_settings_json_v237(['ok'=>false,'error'=>'forbidden'], 403);
}

$pdo = db();
if (!$pdo) {
    chat_settings_json_v237(['ok'=>false,'error'=>'database_unavailable'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? chat_settings_input_v237() : $_GET;
$action = trim((string)($input['action'] ?? 'state'));

try {
    if ($action === 'state') {
        chat_settings_json_v237([
            'ok' => true,
            'agent_voice_allowed' => chat_settings_agent_voice_allowed_v237($user),
            'agent_auto_greet_allowed' => chat_settings_agent_auto_greet_allowed_v237($user),
            'agent_auto_greet_ready' => chat_settings_agent_auto_greet_schema_ready_v237($pdo),
            'agent_assisted_phone_chat_ready' => chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo),
            'agent_assisted_phone_chat_allowed' => chat_settings_agent_assisted_phone_chat_allowed_v237($pdo, $user),
            'agent_orb_shape_ready' => chat_settings_agent_orb_shape_schema_ready_v237($pdo),
            'chat' => chat_settings_get_v237($pdo, (int)$user['id']),
            'profile_agent' => chat_settings_profile_payload_v237($pdo, $user),
        ]);
    }

    if ($method !== 'POST') {
        chat_settings_json_v237(['ok'=>false,'error'=>'POST is required.'], 405);
    }
    chat_settings_require_csrf_v237($input);

    if ($action === 'save_chat') {
        if(!empty($input['agent_voice_enabled'])&&!chat_settings_agent_voice_allowed_v237($user))throw new RuntimeException('Agent Voice is not included for this account.');
        $settings = chat_settings_save_v237($pdo, $user, $input);
        chat_settings_json_v237(['ok'=>true,'agent_voice_allowed'=>chat_settings_agent_voice_allowed_v237($user),'chat'=>$settings]);
    }

    if ($action === 'save_agent_voice') {
        $enabled = !empty($input['agent_voice_enabled']);
        $settings = chat_settings_save_agent_voice_v237($pdo, $user, $enabled);
        chat_settings_json_v237(['ok'=>true,'agent_voice_allowed'=>chat_settings_agent_voice_allowed_v237($user),'chat'=>$settings]);
    }

    if ($action === 'save_auto_greet') {
        if (!is_bool($input['auto_greet_enabled'] ?? null)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Choose whether to enable Auto Greet.'], 422);
        }
        if (!chat_settings_agent_auto_greet_allowed_v237($user)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Auto Greet is unavailable for this account.'], 403);
        }
        if (!chat_settings_agent_auto_greet_schema_ready_v237($pdo)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Auto Greet requires Database Upgrade.'], 503);
        }
        $settings = chat_settings_save_auto_greet_v237($pdo, $user, $input['auto_greet_enabled']);
        chat_settings_json_v237(['ok'=>true,'agent_auto_greet_allowed'=>true,'agent_auto_greet_ready'=>true,'chat'=>$settings]);
    }

    if ($action === 'save_agent_orb_shape') {
        $shape = $input['agent_orb_shape'] ?? null;
        if (!is_string($shape) || !in_array($shape, ['orbit', 'particle_face'], true)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Choose an available Agent orb style.'], 422);
        }
        if (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Agent appearance is unavailable for this account.'], 403);
        }
        if (!chat_settings_agent_orb_shape_schema_ready_v237($pdo)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Agent appearance requires Database Upgrade.'], 503);
        }
        $settings = chat_settings_save_agent_orb_shape_v237($pdo, $user, $shape);
        chat_settings_json_v237(['ok'=>true,'chat'=>$settings]);
    }

    if ($action === 'save_profile_agent') {
        if (!has_permission('account.access', $user)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Profile Agent settings are not available to this account.'], 403);
        }
        if (!profile_agent_schema_ready($pdo)) {
            chat_settings_json_v237(['ok'=>false,'error'=>'Profile Agent is not ready. Run /upgrade.php.'], 503);
        }

        profile_configure_agent($pdo, $user, $input);
        chat_settings_json_v237([
            'ok' => true,
            'profile_agent' => chat_settings_profile_payload_v237($pdo, $user),
        ]);
    }

    chat_settings_json_v237(['ok'=>false,'error'=>'unknown_action'], 400);
} catch (Throwable $e) {
    error_log('Stonefellow chat settings v237 error: ' . $e->getMessage());
    chat_settings_json_v237(['ok'=>false,'error'=>$e->getMessage()], 400);
}
