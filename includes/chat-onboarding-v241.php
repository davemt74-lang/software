<?php
declare(strict_types=1);

require_once __DIR__ . '/studio-participants.php';
require_once __DIR__ . '/studio-voice-profile.php';

const STONEFELLOW_CHAT_ONBOARDING_V241 = 'chat-onboarding-v241-20260905';

function chat_onboarding_v241_username(PDO $pdo, array $user, array $profile): string
{
    $existing = profile_username_normalize((string)($profile['username'] ?? ''));
    if ($existing !== '') return $existing;

    $base = profile_username_normalize((string)($user['display_name'] ?? 'member'));
    if (mb_strlen($base) < 3 || !profile_username_valid($base)) {
        $base = 'member-' . (int)($user['id'] ?? 0);
    }

    $candidate = $base;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT 1 FROM user_profiles WHERE username=? AND user_id<>? LIMIT 1');
        $stmt->execute([$candidate, (int)($user['id'] ?? 0)]);
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
        // Voice cloning is optional. The canonical Voice Profile surface owns
        // initialization and provider interaction when the user opts in.
    }

    return $out;
}

function chat_onboarding_v241_capabilities(
    array $profile,
    array $publicAgent,
    array $chat,
    array $voice,
    bool $onboardingComplete
): array {
    $username = trim((string)($profile['username'] ?? ''));
    $profileConfigured = $username !== '';
    $profilePublic = !empty($profile['is_public']);
    $profileAgentSelected = (int)($publicAgent['agent_id'] ?? 0) > 0;
    $profileAgentEnabled = !empty($publicAgent['enabled']);
    $profileAgentLive = array_key_exists('live', $publicAgent)
        ? !empty($publicAgent['live'])
        : ($profileConfigured && $profilePublic && $profileAgentSelected && $profileAgentEnabled);
    $presenceOnline = (string)($chat['presence_mode'] ?? 'online') === 'online';
    $socialChat = !empty($chat['social_chat_enabled']);
    $sound = !empty($chat['sound_enabled']);
    $cloneCreated = !empty($voice['clone_created']);

    return [
        'profile_view' => [
            'label' => 'Public profile',
            'configured' => $profileConfigured,
            'enabled' => $profilePublic,
            'available' => $profileConfigured && $profilePublic,
            'setup_url' => url('/profile-agent.php'),
        ],
        'profile_agent' => [
            'label' => 'Profile Agent',
            'configured' => $onboardingComplete || $profileAgentSelected,
            'enabled' => $profileAgentEnabled,
            'available' => $profileAgentLive,
            'setup_url' => url('/profile-agent.php'),
        ],
        'online_presence' => [
            'label' => 'Online presence',
            'configured' => $onboardingComplete,
            'enabled' => $presenceOnline,
            'available' => $presenceOnline,
            'setup_url' => url('/chat.php'),
        ],
        'social_chat' => [
            'label' => 'User-to-user chat',
            'configured' => $onboardingComplete,
            'enabled' => $socialChat,
            'available' => $presenceOnline && $socialChat,
            'setup_url' => url('/chat.php'),
        ],
        'incoming_sound' => [
            'label' => 'Incoming chat sound',
            'configured' => $onboardingComplete,
            'enabled' => $sound,
            'available' => $sound,
            'setup_url' => url('/chat.php'),
        ],
        'voice_clone' => [
            'label' => 'Voice clone',
            'configured' => $cloneCreated,
            'enabled' => $cloneCreated,
            'available' => $cloneCreated,
            'verified' => !empty($voice['clone_verified']),
            'setup_url' => (string)($voice['url'] ?? url('/voice-profile.php')),
        ],
    ];
}

function chat_onboarding_v241_state(PDO $pdo, array $user): array
{
    $profileState = profile_runtime_owner_state($pdo, $user);
    $profile = is_array($profileState['profile'] ?? null) ? $profileState['profile'] : [];
    $agents = user_agents_list_v236($pdo, (int)$user['id'], true);
    $defaultAgent = null;
    foreach ($agents as $agent) {
        if (!empty($agent['is_default'])) {
            $defaultAgent = $agent;
            break;
        }
    }
    $defaultAgent ??= $agents[0] ?? null;

    $chat = chat_settings_get_v237($pdo, (int)$user['id']);
    $publicAgent = is_array($profileState['public_agent_status'] ?? null)
        ? $profileState['public_agent_status']
        : [];
    $voice = chat_onboarding_v241_voice_state($pdo, $user);
    $onboardingComplete = user_agent_onboarding_dismissed_v236($pdo, (int)$user['id']);

    $requiredSetup = [
        'agent_named' => (bool)$defaultAgent,
        'profile_username' => trim((string)($profile['username'] ?? '')) !== '',
    ];
    $missingRequired = [];
    foreach ($requiredSetup as $key => $ready) {
        if (!$ready) $missingRequired[] = $key;
    }

    $capabilities = chat_onboarding_v241_capabilities(
        $profile,
        $publicAgent,
        $chat,
        $voice,
        $onboardingComplete
    );
    $unavailable = [];
    foreach ($capabilities as $key => $capability) {
        if (empty($capability['available'])) $unavailable[] = $key;
    }

    return [
        'build' => STONEFELLOW_CHAT_ONBOARDING_V241,
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
        'setup' => $requiredSetup,
        'capabilities' => $capabilities,
        'missing' => $missingRequired,
        'unavailable' => $unavailable,
        'required_setup_complete' => !$missingRequired,
        'onboarding_dismissed' => $onboardingComplete,
    ];
}

function chat_onboarding_v241_empty_tool_result(): array
{
    return [
        'handled' => false,
        'answer' => '',
        'stem_media' => [],
        'media' => [],
        'actions' => [],
        'sources' => [],
    ];
}

function chat_onboarding_v241_tool(string $query, array $user): array
{
    $empty = chat_onboarding_v241_empty_tool_result();
    $q = mb_strtolower(trim($query));
    if ($q === '') return $empty;

    $generalSetup = (bool)preg_match(
        '/\b(?:onboarding|setup status|set up status|what(?:\s+do\s+i|\s+am\s+i)?\s+(?:still\s+)?need(?:\s+to)?\s+set\s*up|what\s+setup\s+(?:am\s+i\s+)?missing|what(?:\'s| is)\s+missing|finish\s+(?:my\s+)?setup)\b/u',
        $q
    );
    $profileAgentIntent = str_contains($q, 'profile agent');
    $voiceCloneIntent = (bool)preg_match('/\bvoice\s+clone\b/u', $q);
    $socialChatIntent = (bool)preg_match('/\b(?:user[- ]to[- ]user|social|direct)\s+chat\b|\bcan\s+(?:people|users|members)\s+(?:message|chat with)\s+me\b/u', $q);
    $presenceIntent = (bool)preg_match('/\b(?:am i|appear|show as)\s+(?:online|offline)\b|\bonline\s+(?:status|presence)\b/u', $q);
    $profileViewIntent = (bool)preg_match('/\b(?:public|visible|show my)\s+profile\b|\bis\s+my\s+profile\s+(?:public|visible|private)\b/u', $q);
    $soundIntent = (bool)preg_match('/\b(?:incoming|chat|message)\s+(?:notification\s+)?sound\b/u', $q);

    if (!$generalSetup && !$profileAgentIntent && !$voiceCloneIntent && !$socialChatIntent
        && !$presenceIntent && !$profileViewIntent && !$soundIntent) {
        return $empty;
    }

    $pdo = db();
    if (!$pdo) return $empty;

    try {
        if (!user_agent_system_schema_ready_v236($pdo)
            || !profile_agent_schema_ready($pdo)
            || !chat_settings_schema_ready_v237($pdo)) {
            return $empty;
        }
        $state = chat_onboarding_v241_state($pdo, $user);
    } catch (Throwable $e) {
        return $empty;
    }

    $cap = $state['capabilities'] ?? [];
    $result = $empty;
    $result['handled'] = true;
    $result['sources'][] = ['source' => 'account:onboarding-state', 'title' => 'Account setup state'];

    if ($profileAgentIntent) {
        $item = $cap['profile_agent'];
        if (!empty($item['available'])) {
            $result['answer'] = 'Your Profile Agent is enabled and live on your public profile.';
        } elseif (empty($state['profile']['is_public'])) {
            $result['answer'] = 'Your Profile Agent is not publicly available because your profile is private. Open Profile Agent settings to publish the profile and enable the service when you want it live.';
        } elseif (empty($item['enabled'])) {
            $result['answer'] = 'Your Profile Agent is currently turned off. Open Profile Agent settings to enable it.';
        } else {
            $result['answer'] = 'Your Profile Agent is configured but is not live yet. Open Profile Agent settings to review the selected agent and profile status.';
        }
        $result['actions'][] = ['type'=>'open_url','label'=>'Open Profile Agent','url'=>(string)$item['setup_url']];
        return $result;
    }

    if ($voiceCloneIntent) {
        $item = $cap['voice_clone'];
        if (!empty($item['available'])) {
            $verified = !empty($item['verified']) ? ' and verified' : '';
            $result['answer'] = 'Your voice clone has been created' . $verified . '. You can manage or test it in Voice Profile.';
        } else {
            $result['answer'] = 'You do not have a voice clone set up yet. Voice cloning is optional; open Voice Profile to record or upload a sample and create it when you are ready.';
        }
        $result['actions'][] = ['type'=>'open_url','label'=>'Open Voice Profile','url'=>(string)$item['setup_url']];
        return $result;
    }

    if ($socialChatIntent) {
        $item = $cap['social_chat'];
        if (!empty($item['available'])) {
            $result['answer'] = 'User-to-user chat is enabled and you are currently set to Online.';
        } elseif (empty($item['enabled'])) {
            $result['answer'] = 'User-to-user chat is turned off. Use the Chat Settings gear at the bottom of the left rail to enable it.';
        } else {
            $result['answer'] = 'User-to-user chat is enabled, but your presence is set to Offline. Use the Chat Settings gear to switch to Online when you want to receive chats.';
        }
        return $result;
    }

    if ($presenceIntent) {
        $item = $cap['online_presence'];
        $result['answer'] = !empty($item['available'])
            ? 'Your chat presence is set to Online.'
            : 'Your chat presence is set to Offline. You can change it from the Chat Settings gear at the bottom of the left rail.';
        return $result;
    }

    if ($profileViewIntent) {
        $item = $cap['profile_view'];
        if (!empty($item['available'])) {
            $result['answer'] = 'Your profile is public and can be viewed at ' . (string)$state['profile_url'] . '.';
            if (!empty($state['profile_url'])) {
                $result['actions'][] = ['type'=>'open_url','label'=>'View Profile','url'=>(string)$state['profile_url']];
            }
        } elseif (empty($item['configured'])) {
            $result['answer'] = 'Your profile still needs a username before it can have a public profile URL. Complete Agent Chat onboarding to choose one.';
        } else {
            $result['answer'] = 'Your profile is currently private. Open Profile Agent settings when you want to make the profile visible.';
            $result['actions'][] = ['type'=>'open_url','label'=>'Open Profile Settings','url'=>(string)$item['setup_url']];
        }
        return $result;
    }

    if ($soundIntent) {
        $item = $cap['incoming_sound'];
        $result['answer'] = !empty($item['available'])
            ? 'Incoming chat notification sound is enabled.'
            : 'Incoming chat notification sound is turned off. Use the Chat Settings gear at the bottom of the left rail to enable it.';
        return $result;
    }

    $missing = is_array($state['missing'] ?? null) ? $state['missing'] : [];
    $lines = [];
    if (in_array('agent_named', $missing, true)) $lines[] = 'name your agent';
    if (in_array('profile_username', $missing, true)) $lines[] = 'choose your profile address';

    if ($lines) {
        $result['answer'] = 'Your required setup is not finished yet. Still needed: ' . implode(' and ', $lines) . '. Open Agent Chat onboarding to finish those steps.';
        $result['actions'][] = ['type'=>'open_url','label'=>'Continue Setup','url'=>url('/chat.php')];
        return $result;
    }

    $status = [];
    foreach (['profile_view','profile_agent','social_chat','incoming_sound','voice_clone'] as $key) {
        $item = $cap[$key] ?? null;
        if (!$item) continue;
        $status[] = (string)$item['label'] . ': ' . (!empty($item['available']) ? 'available' : 'off / not set up');
    }
    $result['answer'] = 'Your required Agent setup is complete. ' . implode(' · ', $status) . '. Optional features that are off are choices, not incomplete onboarding.';
    return $result;
}
