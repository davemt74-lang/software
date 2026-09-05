<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function chat_onboarding_v241_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_onboarding_v241_input(): array
{
    $raw = json_decode((string)file_get_contents('php://input'), true);
    return is_array($raw) ? $raw : $_POST;
}

function chat_onboarding_v241_require_csrf(array $input): void
{
    $token = (string)($input['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        chat_onboarding_v241_json(['ok'=>false,'error'=>'Session expired. Refresh and try again.'], 419);
    }
}

$user = current_user();
if (!$user || !has_permission('account.access', $user) || !has_permission('chat.access', $user)) {
    chat_onboarding_v241_json(['ok'=>false,'error'=>'Agent onboarding is unavailable for this account.'], 403);
}
$pdo = db();
if (!$pdo) chat_onboarding_v241_json(['ok'=>false,'error'=>'Database unavailable.'], 503);

try {
    if (!user_agent_system_schema_ready_v236($pdo)) user_agent_system_ensure_schema_v236($pdo);
    if (!profile_agent_schema_ready($pdo)) profile_agent_ensure_schema($pdo);
    if (!chat_settings_schema_ready_v237($pdo)) chat_settings_ensure_schema_v237($pdo);
} catch (Throwable $e) {
    chat_onboarding_v241_json(['ok'=>false,'error'=>'Onboarding storage is not ready. Run the database upgrade.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    try {
        chat_onboarding_v241_json(['ok'=>true,'state'=>chat_onboarding_v241_state($pdo, $user)]);
    } catch (Throwable $e) {
        chat_onboarding_v241_json(['ok'=>false,'error'=>'Onboarding state could not be loaded.'], 500);
    }
}
if ($method !== 'POST') chat_onboarding_v241_json(['ok'=>false,'error'=>'GET or POST is required.'], 405);

$input = chat_onboarding_v241_input();
chat_onboarding_v241_require_csrf($input);
$action = trim((string)($input['action'] ?? ''));

try {
    if ($action === 'state') {
        chat_onboarding_v241_json(['ok'=>true,'state'=>chat_onboarding_v241_state($pdo, $user)]);
    }

    if ($action !== 'finish') {
        chat_onboarding_v241_json(['ok'=>false,'error'=>'Unknown onboarding action.'], 422);
    }

    $agentName = trim(preg_replace('/\s+/u', ' ', (string)($input['agent_name'] ?? '')) ?? '');
    if ($agentName === '') throw new RuntimeException('Choose a name for your agent.');
    $agentName = mb_strimwidth($agentName, 0, 190, '');

    $profile = profile_for_user($pdo, (int)$user['id'], true)
        ?: throw new RuntimeException('Profile could not be loaded.');
    $username = profile_username_normalize((string)($input['username'] ?? ''));
    if ($username === '' || !profile_username_valid($username)) {
        throw new RuntimeException('Choose a username using 3–60 letters, numbers, dots, dashes or underscores.');
    }

    $presenceMode = strtolower(trim((string)($input['presence_mode'] ?? 'online')));
    if (!in_array($presenceMode, ['online','offline'], true)) $presenceMode = 'online';
    $enableProfileAgent = !empty($input['profile_agent_enabled']);
    $profilePublic = !empty($input['profile_public']);
    $socialChat = !empty($input['social_chat_enabled']);
    $sound = !empty($input['sound_enabled']);
    $greeting = mb_strimwidth(trim((string)($input['profile_agent_greeting'] ?? '')), 0, 500, '…');
    if ($greeting === '') {
        $ownerName = trim((string)($user['display_name'] ?? 'this member'));
        $greeting = 'Hi — I’m ' . $agentName . ', ' . $ownerName . '’s profile agent. What would you like to know?';
    }

    $pdo->beginTransaction();
    try {
        $agents = user_agents_list_v236($pdo, (int)$user['id'], true);
        $agent = $agents[0] ?? null;
        if (!$agent) {
            $agent = user_agent_create_v236($pdo, $user, [
                'display_name' => $agentName,
                'agent_role' => 'personal',
                'is_default' => 1,
                'voice_enabled' => 1,
            ]);
        } elseif ((string)$agent['display_name'] !== $agentName) {
            $agent = user_agent_update_v236($pdo, $user, [
                'id' => (int)$agent['id'],
                'display_name' => $agentName,
                'agent_role' => (string)($agent['agent_role'] ?? 'personal'),
                'instructions' => (string)($agent['instructions'] ?? ''),
                'is_default' => 1,
                'is_profile_agent' => $enableProfileAgent ? 1 : (int)($agent['is_profile_agent'] ?? 0),
                'is_active' => 1,
                'voice_enabled' => 1,
            ]);
        }

        profile_save($pdo, $user, [
            'username' => $username,
            'bio' => (string)($profile['bio'] ?? ''),
            'website_url' => (string)($profile['website_url'] ?? ''),
            'instagram_url' => (string)($profile['instagram_url'] ?? ''),
            'tiktok_url' => (string)($profile['tiktok_url'] ?? ''),
            'youtube_url' => (string)($profile['youtube_url'] ?? ''),
            'spotify_url' => (string)($profile['spotify_url'] ?? ''),
            'apple_music_url' => (string)($profile['apple_music_url'] ?? ''),
            'is_public' => $profilePublic ? 1 : 0,
            'share_visit_identity' => !empty($profile['share_visit_identity']) ? 1 : 0,
        ]);

        chat_settings_save_v237($pdo, $user, [
            'presence_mode' => $presenceMode,
            'social_chat_enabled' => $socialChat ? 1 : 0,
            'sound_enabled' => $sound ? 1 : 0,
        ]);

        profile_configure_agent($pdo, $user, [
            'profile_agent_id' => (int)$agent['id'],
            'profile_agent_enabled' => $enableProfileAgent ? 1 : 0,
            'profile_agent_greeting' => $greeting,
            'profile_agent_instructions' => (string)($profile['profile_agent_instructions'] ?? ''),
        ]);

        user_agent_dismiss_onboarding_v236($pdo, (int)$user['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $state = chat_onboarding_v241_state($pdo, $user);
    chat_onboarding_v241_json([
        'ok' => true,
        'state' => $state,
        'agent_id' => (int)($state['agent']['id'] ?? 0),
        'chat_url' => url('/chat.php') . '?agent=' . (int)($state['agent']['id'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('Stonefellow chat onboarding v241 error: ' . $e->getMessage());
    chat_onboarding_v241_json(
        ['ok'=>false,'error'=>$e instanceof RuntimeException ? $e->getMessage() : 'Onboarding could not be completed.'],
        $e instanceof RuntimeException ? 422 : 500
    );
}
