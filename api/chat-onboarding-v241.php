<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/studio-participants.php';
require_once dirname(__DIR__) . '/includes/studio-voice-profile.php';

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

function chat_onboarding_v241_username(PDO $pdo, array $user, array $profile): string
{
    $existing = profile_username_normalize((string)($profile['username'] ?? ''));
    if ($existing !== '') return $existing;

    $base = profile_username_normalize((string)($user['display_name'] ?? 'member'));
    if (mb_strlen($base) < 3 || !profile_username_valid($base)) $base = 'member-' . (int)$user['id'];
    $candidate = $base;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT 1 FROM user_profiles WHERE username=? AND user_id<>? LIMIT 1');
        $stmt->execute([$candidate, (int)$user['id']]);
        if (!$stmt->fetchColumn()) return $candidate;
        $suffix++;
        $candidate = mb_strimwidth($base, 0, 52, '') . '-' . $suffix;
    }
}

function chat_onboarding_v241_voice_state(PDO $pdo, array $user): array
{
    $out = [
        'available' => false,
        'clone_created' => false,
        'clone_verified' => false,
        'sample_count' => 0,
        'url' => url('/voice-profile.php'),
    ];
    try {
        if (!studio_participants_schema_ready() || !studio_voice_profile_schema_ready()) return $out;
        $state = studio_voice_profile_state($pdo, $user);
        $voice = is_array($state['voice'] ?? null) ? $state['voice'] : [];
        $samples = is_array($state['samples'] ?? null) ? $state['samples'] : [];
        $out['available'] = true;
        $out['clone_created'] = trim((string)($voice['clone_provider_voice_id'] ?? '')) !== '';
        $out['clone_verified'] = !empty($voice['clone_verified']);
        $out['sample_count'] = count($samples);
    } catch (Throwable $e) {
        // Voice Profile remains optional during onboarding. Opening the canonical
        // Voice Profile surface can initialize its own storage if needed.
    }
    return $out;
}

function chat_onboarding_v241_state(PDO $pdo, array $user): array
{
    $profileState = profile_runtime_owner_state($pdo, $user);
    $profile = is_array($profileState['profile'] ?? null) ? $profileState['profile'] : [];
    $agents = user_agents_list_v236($pdo, (int)$user['id'], true);
    $defaultAgent = null;
    foreach ($agents as $agent) {
        if (!empty($agent['is_default'])) { $defaultAgent = $agent; break; }
    }
    $defaultAgent ??= $agents[0] ?? null;
    $chat = chat_settings_get_v237($pdo, (int)$user['id']);
    $publicAgent = is_array($profileState['public_agent_status'] ?? null) ? $profileState['public_agent_status'] : [];
    $voice = chat_onboarding_v241_voice_state($pdo, $user);

    $setup = [
        'agent_named' => (bool)$defaultAgent,
        'profile_username' => trim((string)($profile['username'] ?? '')) !== '',
        'profile_public' => !empty($profile['is_public']),
        'profile_agent_enabled' => !empty($publicAgent['enabled']) && (int)($publicAgent['agent_id'] ?? 0) > 0,
        'online_chat' => (string)($chat['presence_mode'] ?? 'online') === 'online',
        'social_chat_enabled' => !empty($chat['social_chat_enabled']),
        'incoming_sound_enabled' => !empty($chat['sound_enabled']),
        'voice_clone_created' => !empty($voice['clone_created']),
        'voice_clone_verified' => !empty($voice['clone_verified']),
    ];
    $missing = [];
    foreach ($setup as $key => $ready) if (!$ready) $missing[] = $key;

    return [
        'build' => 'chat-onboarding-v241-20260905',
        'user' => [
            'id' => (int)$user['id'],
            'display_name' => (string)($user['display_name'] ?? ''),
        ],
        'system_agent_name' => system_agent_name(),
        'agent' => $defaultAgent,
        'profile' => $profile,
        'profile_url' => (string)($profileState['profile_url'] ?? ''),
        'suggested_username' => chat_onboarding_v241_username($pdo, $user, $profile),
        'public_agent_status' => $publicAgent,
        'chat' => $chat,
        'voice' => $voice,
        'setup' => $setup,
        'missing' => $missing,
        'onboarding_dismissed' => user_agent_onboarding_dismissed_v236($pdo, (int)$user['id']),
    ];
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

    $profile = profile_for_user($pdo, (int)$user['id'], true) ?: throw new RuntimeException('Profile could not be loaded.');
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
    chat_onboarding_v241_json(['ok'=>false,'error'=>$e instanceof RuntimeException ? $e->getMessage() : 'Onboarding could not be completed.'], $e instanceof RuntimeException ? 422 : 500);
}
