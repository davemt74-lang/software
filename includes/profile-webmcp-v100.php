<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_V100 = 'profile-webmcp-v100-20260928';
const VP3_PROFILE_WEBMCP_MANIFEST_V100 = 'vp3.profile.webmcp.v1';
const VP3_PROFILE_WEBMCP_MAX_BODY_V100 = 16384;
const VP3_PROFILE_WEBMCP_RATE_LIMIT_V100 = 90;
const VP3_PROFILE_WEBMCP_RATE_WINDOW_V100 = 60;

function vp3_profile_webmcp_tool_catalog_v100(): array
{
    static $catalog = null;
    if ($catalog !== null) return $catalog;
    $catalog = [
        'vp3.profile.capabilities.get' => [
            'title'=>'Get profile capabilities',
            'description'=>'Return the currently available VP3 capabilities for this public profile and visitor.',
            'capability'=>'profile',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.profile.get' => [
            'title'=>'Get public profile',
            'description'=>'Return the public VP3 profile projection approved for agent use.',
            'capability'=>'profile',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.get' => [
            'title'=>'Get Profile Agent',
            'description'=>'Return the public Profile Agent identity and greeting available to this visitor.',
            'capability'=>'profile_agent',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.chat.start' => [
            'title'=>'Start Profile Agent chat',
            'description'=>'Start or resume a visitor conversation with this Profile Agent.',
            'capability'=>'profile_agent',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.conversation.get' => [
            'title'=>'Get Profile Agent conversation',
            'description'=>'Return one conversation bound to this exact profile, Profile Agent, and visitor session.',
            'capability'=>'profile_agent',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>['conversation_id'=>['type'=>'integer','minimum'=>1]],
                'required'=>['conversation_id'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.message.send' => [
            'title'=>'Send message to Profile Agent',
            'description'=>'Send a visitor message to this Profile Agent using the canonical conversation and privacy boundary.',
            'capability'=>'profile_agent',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'conversation_id'=>['type'=>'integer','minimum'=>1],
                    'message'=>['type'=>'string','minLength'=>1,'maxLength'=>2000],
                ],
                'required'=>['message'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.owner_handoff.request' => [
            'title'=>'Request profile owner assistance',
            'description'=>'Ask the profile owner for assistance with this exact visitor conversation.',
            'capability'=>'profile_agent',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'conversation_id'=>['type'=>'integer','minimum'=>1],
                    'reason'=>['type'=>'string','maxLength'=>1000],
                ],
                'required'=>['conversation_id'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.options.list' => [
            'title'=>'List public appointment types',
            'description'=>'List only appointment types currently open for public booking on this profile.',
            'capability'=>'booking',
            'input_schema'=>['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.availability.list' => [
            'title'=>'List public booking availability',
            'description'=>'Return public bookable slots for one appointment type and date without exposing private calendar details.',
            'capability'=>'booking',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'event_type_id'=>['type'=>'integer','minimum'=>1],
                    'event_slug'=>['type'=>'string','maxLength'=>80],
                    'date'=>['type'=>'string','pattern'=>'^\\d{4}-\\d{2}-\\d{2}$'],
                    'timezone'=>['type'=>'string','maxLength'=>80],
                ],
                'required'=>['date'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.prepare' => [
            'title'=>'Prepare public booking',
            'description'=>'Validate and preview a public appointment booking without creating it.',
            'capability'=>'booking',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'event_type_id'=>['type'=>'integer','minimum'=>1],
                    'event_slug'=>['type'=>'string','maxLength'=>80],
                    'start_at_utc'=>['type'=>'string','maxLength'=>40],
                    'guest_timezone'=>['type'=>'string','maxLength'=>80],
                    'guest_name'=>['type'=>'string','minLength'=>1,'maxLength'=>190],
                    'guest_email'=>['type'=>'string','minLength'=>3,'maxLength'=>190],
                    'guest_phone'=>['type'=>'string','maxLength'=>80],
                    'guest_notes'=>['type'=>'string','maxLength'=>2000],
                    'intake'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['start_at_utc','guest_name','guest_email'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.confirm' => [
            'title'=>'Confirm public booking',
            'description'=>'Create the exact prepared booking after explicit confirmation and idempotency validation.',
            'capability'=>'booking',
            'input_schema'=>vp3_profile_webmcp_confirmation_schema_v150(),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.booking.get' => [
            'title'=>'Get public booking',
            'description'=>'Return one booking using its opaque public booking token without exposing its management token.',
            'capability'=>'booking',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>['public_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],
                'required'=>['public_token'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.reschedule.prepare' => [
            'title'=>'Prepare booking reschedule',
            'description'=>'Validate and preview a new time for a booking using its opaque manage token.',
            'capability'=>'booking',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>[
                    'manage_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    'start_at_utc'=>['type'=>'string','maxLength'=>40],
                    'guest_timezone'=>['type'=>'string','maxLength'=>80],
                ],
                'required'=>['manage_token','start_at_utc'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.reschedule.confirm' => [
            'title'=>'Confirm booking reschedule',
            'description'=>'Apply the exact prepared reschedule after explicit confirmation and idempotency validation.',
            'capability'=>'booking',
            'input_schema'=>vp3_profile_webmcp_confirmation_schema_v150(),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.booking.cancel.prepare' => [
            'title'=>'Prepare booking cancellation',
            'description'=>'Validate and preview cancellation of a booking using its opaque manage token.',
            'capability'=>'booking',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>['manage_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$']],
                'required'=>['manage_token'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.cancel.confirm' => [
            'title'=>'Confirm booking cancellation',
            'description'=>'Cancel the exact prepared booking after explicit confirmation and idempotency validation.',
            'capability'=>'booking',
            'input_schema'=>vp3_profile_webmcp_confirmation_schema_v150(),
            'annotations'=>['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.intent.resolve' => [
            'title'=>'Resolve profile intent',
            'description'=>'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
            'capability'=>'profile',
            'input_schema'=>[
                'type'=>'object',
                'properties'=>['goal'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],
                'required'=>['goal'],
                'additionalProperties'=>false,
            ],
            'annotations'=>['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
    return $catalog;
}

function vp3_profile_webmcp_confirmation_schema_v150(): array
{
    return [
        'type'=>'object',
        'properties'=>[
            'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
            'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
            'intent'=>['type'=>'object','additionalProperties'=>true],
        ],
        'required'=>['confirmation_token','idempotency_key','intent'],
        'additionalProperties'=>false,
    ];
}

function vp3_profile_webmcp_session_proof_v100(int $ownerUserId): string
{
    if ($ownerUserId < 1) throw new RuntimeException('Profile owner is invalid.');
    if (!isset($_SESSION['vp3_profile_webmcp_proofs']) || !is_array($_SESSION['vp3_profile_webmcp_proofs'])) {
        $_SESSION['vp3_profile_webmcp_proofs'] = [];
    }
    $key = (string)$ownerUserId;
    if (empty($_SESSION['vp3_profile_webmcp_proofs'][$key])) {
        $_SESSION['vp3_profile_webmcp_proofs'][$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['vp3_profile_webmcp_proofs'][$key];
}

function vp3_profile_webmcp_session_proof_valid_v100(int $ownerUserId, string $proof): bool
{
    return $ownerUserId > 0 && $proof !== '' && hash_equals(vp3_profile_webmcp_session_proof_v100($ownerUserId), $proof);
}

function vp3_profile_webmcp_rate_limit_v100(int $ownerUserId): bool
{
    if (!isset($_SESSION['vp3_profile_webmcp_rate']) || !is_array($_SESSION['vp3_profile_webmcp_rate'])) {
        $_SESSION['vp3_profile_webmcp_rate'] = [];
    }
    $now = time();
    $key = (string)$ownerUserId;
    $row = $_SESSION['vp3_profile_webmcp_rate'][$key] ?? ['started_at'=>$now,'count'=>0];
    if (!is_array($row) || $now - (int)($row['started_at'] ?? 0) >= VP3_PROFILE_WEBMCP_RATE_WINDOW_V100) {
        $row = ['started_at'=>$now,'count'=>0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    $_SESSION['vp3_profile_webmcp_rate'][$key] = $row;
    return $row['count'] <= VP3_PROFILE_WEBMCP_RATE_LIMIT_V100;
}

function vp3_profile_webmcp_normalize_host_v100(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = preg_replace('/:\\d+$/', '', $value) ?? $value;
    return rtrim(strtolower($host), '.');
}

function vp3_profile_webmcp_native_origin_allowed_v100(string $origin, string $requestHost): bool
{
    if (trim($origin) === '') return true;
    $originHost = vp3_profile_webmcp_normalize_host_v100($origin);
    $requestHost = vp3_profile_webmcp_normalize_host_v100($requestHost);
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function vp3_profile_webmcp_public_profile_v100(array $profile): array
{
    $safeUrl = static function(string $value): string {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    };
    $links = [];
    foreach ([
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
        'tidal'=>'tidal_url','facebook'=>'facebook_url'
    ] as $label => $field) {
        $value = $safeUrl((string)($profile[$field] ?? ''));
        if ($value !== '') $links[$label] = $value;
    }
    $username = (string)($profile['username'] ?? '');
    return [
        'username' => $username,
        'display_name' => trim((string)($profile['display_name'] ?? '')),
        'bio' => trim((string)($profile['bio'] ?? '')),
        'tagline' => trim((string)($profile['tagline'] ?? '')),
        'role' => (string)($profile['role'] ?? ''),
        'public_url' => function_exists('profile_public_url') ? profile_public_url($username) : ('/' . rawurlencode($username)),
        'links' => $links,
    ];
}

function vp3_profile_webmcp_owner_user_v100(PDO $pdo, array $profile): ?array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    if ($ownerUserId < 1) return null;
    if (function_exists('profile_user_row')) return profile_user_row($pdo, $ownerUserId);
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_profile_webmcp_capabilities_v100(PDO $pdo, array $profile, ?array $viewer): array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    $ownerUser = vp3_profile_webmcp_owner_user_v100($pdo, $profile);

    $profileAgent = false;
    try {
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $entitled = !$ownerUser || !function_exists('personal_capability_has_v242')
            ? true
            : (
                personal_capability_has_v242('profile_agent.access', $ownerUser)
                && personal_capability_has_v242('profile_chat.access', $ownerUser)
            );
        $profileAgent = (bool)$agent && $entitled && ($viewerId<1 || $viewerId!==$ownerUserId);
    } catch (Throwable $e) {
        $profileAgent = false;
    }

    $booking = false;
    try {
        if (function_exists('agent_scheduling_public_schedule_v450') && function_exists('agent_scheduling_public_events_v450')) {
            $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
            $booking = is_array($schedule) && count(agent_scheduling_public_events_v450($pdo, (int)$schedule['id'])) > 0;
        }
    } catch (Throwable $e) {
        $booking = false;
    }

    $commerce = false;
    try {
        if (function_exists('profile_commerce_products_for_profile_v900')) {
            $commerce = count(profile_commerce_products_for_profile_v900($pdo, $profile, true, 1)) > 0;
        }
    } catch (Throwable $e) {
        $commerce = false;
    }

    $campaigns = false;
    try {
        if (function_exists('campaigns_rewards_schema_ready_v100') && campaigns_rewards_schema_ready_v100($pdo)
            && function_exists('campaigns_rewards_profile_campaigns_v100')) {
            $campaigns = count(campaigns_rewards_profile_campaigns_v100($pdo, $ownerUserId, 1)) > 0;
        }
    } catch (Throwable $e) {
        $campaigns = false;
    }

    $rewards = $viewerId > 0 && function_exists('campaigns_rewards_reward_tray_v110');
    $social = false;
    $messaging = false;
    if ($viewerId > 0 && $viewerId !== $ownerUserId && function_exists('vp3_social_schema_ready_v320')) {
        try {
            $social = vp3_social_schema_ready_v320($pdo);
            if ($social && function_exists('vp3_social_dm_route_v320')) {
                $messaging = vp3_social_dm_route_v320($pdo, $viewerId, $ownerUserId) !== 'blocked';
            }
        } catch (Throwable $e) {
            $social = false;
            $messaging = false;
        }
    }

    return [
        'profile' => true,
        'profile_agent' => $profileAgent,
        'booking' => $booking,
        'commerce' => $commerce,
        'campaigns' => $campaigns,
        'rewards' => $rewards,
        'social' => $social,
        'messaging' => $messaging,
    ];
}

function vp3_profile_webmcp_manifest_v100(PDO $pdo, array $profile, ?array $viewer, array $context = []): array
{
    $surface = (string)($context['surface'] ?? 'native_profile');
    if ($surface !== 'native_profile') throw new RuntimeException('Section 1 supports only the native profile surface.');
    $capabilities = vp3_profile_webmcp_capabilities_v100($pdo, $profile, $viewer);
    $catalog = vp3_profile_webmcp_tool_catalog_v100();
    $allowed = [];
    foreach ($catalog as $name => $tool) {
        $capability = (string)($tool['capability'] ?? '');
        if (($capabilities[$capability] ?? false) === true) $allowed[] = $name;
    }
    sort($allowed);
    $authenticated = (int)($viewer['id'] ?? 0) > 0;
    $identityDisclosed = false;
    if ($authenticated && function_exists('profile_visitor_discloses_identity')) {
        try { $identityDisclosed = profile_visitor_discloses_identity($pdo, $viewer); }
        catch (Throwable $e) { $identityDisclosed = false; }
    }
    return [
        'manifest_version' => VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface' => 'native_profile',
        'profile_username' => (string)($profile['username'] ?? ''),
        'capabilities' => $capabilities,
        'allowed_tools' => $allowed,
        'session' => [
            'authenticated' => $authenticated,
            'visitor_profile_known' => $identityDisclosed,
        ],
    ];
}

function vp3_profile_webmcp_resolve_intent_v100(string $goal, array $manifest): array
{
    $goal = mb_strtolower(trim($goal));
    if ($goal === '') throw new RuntimeException('A goal is required.');
    if (mb_strlen($goal) > 1000) throw new RuntimeException('Goal is too long.');

    $groups = [
        'booking' => ['book','booking','appointment','schedule','availability','available time','reservation'],
        'commerce' => ['buy','purchase','product','price','checkout','order'],
        'campaigns' => ['campaign','offer','promotion','promo','deal','event','rsvp'],
        'rewards' => ['reward','loyalty','points','wallet','claim','redeem'],
        'messaging' => ['message','contact','dm','send a message'],
        'social' => ['follow','friend','connect'],
        'profile_agent' => ['ask','agent','chat','question'],
        'profile' => ['profile','bio','about','link','website'],
    ];
    $matches = [];
    foreach ($groups as $capability => $needles) {
        if (empty($manifest['capabilities'][$capability])) continue;
        foreach ($needles as $needle) {
            if (str_contains($goal, $needle)) {
                $matches[] = $capability;
                break;
            }
        }
    }
    if (!$matches) $matches[] = !empty($manifest['capabilities']['profile_agent']) ? 'profile_agent' : 'profile';
    return [
        'goal' => $goal,
        'recommended_capabilities' => array_values(array_unique($matches)),
        'registered_tools' => array_values($manifest['allowed_tools'] ?? []),
        'execution_performed' => false,
        'requires_domain_adapter' => array_values(array_filter(
            array_unique($matches),
            static fn(string $capability): bool => !in_array($capability, ['profile','profile_agent','booking'], true)
        )),
    ];
}
],
                    'timezone'=>['type'=>'string','maxLength'=>80],
                ],
                'required'=>['date'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.prepare' => [
            'title' => 'Prepare public booking',
            'description' => 'Validate and preview a public appointment booking without creating it.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>[
                    'event_type_id'=>['type'=>'integer','minimum'=>1],
                    'event_slug'=>['type'=>'string','maxLength'=>80],
                    'start_at_utc'=>['type'=>'string','maxLength'=>40],
                    'guest_timezone'=>['type'=>'string','maxLength'=>80],
                    'guest_name'=>['type'=>'string','minLength'=>1,'maxLength'=>190],
                    'guest_email'=>['type'=>'string','minLength'=>3,'maxLength'=>190],
                    'guest_phone'=>['type'=>'string','maxLength'=>80],
                    'guest_notes'=>['type'=>'string','maxLength'=>2000],
                    'intake'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['start_at_utc','guest_name','guest_email'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.confirm' => [
            'title' => 'Confirm public booking',
            'description' => 'Create the exact prepared booking after explicit confirmation and idempotency validation.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>[
                    'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
                    'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
                    'intent'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['confirmation_token','idempotency_key','intent'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.booking.get' => [
            'title' => 'Get public booking',
            'description' => 'Return one booking using its opaque public booking token without exposing its management token.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['public_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}
            'title' => 'Resolve profile intent',
            'description' => 'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
            'capability' => 'profile',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['goal'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],
                'required'=>['goal'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
    return $catalog;
}

function vp3_profile_webmcp_session_proof_v100(int $ownerUserId): string
{
    if ($ownerUserId < 1) throw new RuntimeException('Profile owner is invalid.');
    if (!isset($_SESSION['vp3_profile_webmcp_proofs']) || !is_array($_SESSION['vp3_profile_webmcp_proofs'])) {
        $_SESSION['vp3_profile_webmcp_proofs'] = [];
    }
    $key = (string)$ownerUserId;
    if (empty($_SESSION['vp3_profile_webmcp_proofs'][$key])) {
        $_SESSION['vp3_profile_webmcp_proofs'][$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['vp3_profile_webmcp_proofs'][$key];
}

function vp3_profile_webmcp_session_proof_valid_v100(int $ownerUserId, string $proof): bool
{
    return $ownerUserId > 0 && $proof !== '' && hash_equals(vp3_profile_webmcp_session_proof_v100($ownerUserId), $proof);
}

function vp3_profile_webmcp_rate_limit_v100(int $ownerUserId): bool
{
    if (!isset($_SESSION['vp3_profile_webmcp_rate']) || !is_array($_SESSION['vp3_profile_webmcp_rate'])) {
        $_SESSION['vp3_profile_webmcp_rate'] = [];
    }
    $now = time();
    $key = (string)$ownerUserId;
    $row = $_SESSION['vp3_profile_webmcp_rate'][$key] ?? ['started_at'=>$now,'count'=>0];
    if (!is_array($row) || $now - (int)($row['started_at'] ?? 0) >= VP3_PROFILE_WEBMCP_RATE_WINDOW_V100) {
        $row = ['started_at'=>$now,'count'=>0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    $_SESSION['vp3_profile_webmcp_rate'][$key] = $row;
    return $row['count'] <= VP3_PROFILE_WEBMCP_RATE_LIMIT_V100;
}

function vp3_profile_webmcp_normalize_host_v100(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = preg_replace('/:\\d+$/', '', $value) ?? $value;
    return rtrim(strtolower($host), '.');
}

function vp3_profile_webmcp_native_origin_allowed_v100(string $origin, string $requestHost): bool
{
    if (trim($origin) === '') return true;
    $originHost = vp3_profile_webmcp_normalize_host_v100($origin);
    $requestHost = vp3_profile_webmcp_normalize_host_v100($requestHost);
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function vp3_profile_webmcp_public_profile_v100(array $profile): array
{
    $safeUrl = static function(string $value): string {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    };
    $links = [];
    foreach ([
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
        'tidal'=>'tidal_url','facebook'=>'facebook_url'
    ] as $label => $field) {
        $value = $safeUrl((string)($profile[$field] ?? ''));
        if ($value !== '') $links[$label] = $value;
    }
    $username = (string)($profile['username'] ?? '');
    return [
        'username' => $username,
        'display_name' => trim((string)($profile['display_name'] ?? '')),
        'bio' => trim((string)($profile['bio'] ?? '')),
        'tagline' => trim((string)($profile['tagline'] ?? '')),
        'role' => (string)($profile['role'] ?? ''),
        'public_url' => function_exists('profile_public_url') ? profile_public_url($username) : ('/' . rawurlencode($username)),
        'links' => $links,
    ];
}

function vp3_profile_webmcp_owner_user_v100(PDO $pdo, array $profile): ?array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    if ($ownerUserId < 1) return null;
    if (function_exists('profile_user_row')) return profile_user_row($pdo, $ownerUserId);
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_profile_webmcp_capabilities_v100(PDO $pdo, array $profile, ?array $viewer): array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    $ownerUser = vp3_profile_webmcp_owner_user_v100($pdo, $profile);

    $profileAgent = false;
    try {
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $entitled = !$ownerUser || !function_exists('personal_capability_has_v242')
            ? true
            : (
                personal_capability_has_v242('profile_agent.access', $ownerUser)
                && personal_capability_has_v242('profile_chat.access', $ownerUser)
            );
        $profileAgent = (bool)$agent && $entitled && ($viewerId<1 || $viewerId!==$ownerUserId);
    } catch (Throwable $e) {
        $profileAgent = false;
    }

    $booking = false;
    try {
        if (function_exists('agent_scheduling_public_schedule_v450') && function_exists('agent_scheduling_public_events_v450')) {
            $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
            $booking = is_array($schedule) && count(agent_scheduling_public_events_v450($pdo, (int)$schedule['id'])) > 0;
        }
    } catch (Throwable $e) {
        $booking = false;
    }

    $commerce = false;
    try {
        if (function_exists('profile_commerce_products_for_profile_v900')) {
            $commerce = count(profile_commerce_products_for_profile_v900($pdo, $profile, true, 1)) > 0;
        }
    } catch (Throwable $e) {
        $commerce = false;
    }

    $campaigns = false;
    try {
        if (function_exists('campaigns_rewards_schema_ready_v100') && campaigns_rewards_schema_ready_v100($pdo)
            && function_exists('campaigns_rewards_profile_campaigns_v100')) {
            $campaigns = count(campaigns_rewards_profile_campaigns_v100($pdo, $ownerUserId, 1)) > 0;
        }
    } catch (Throwable $e) {
        $campaigns = false;
    }

    $rewards = $viewerId > 0 && function_exists('campaigns_rewards_reward_tray_v110');
    $social = false;
    $messaging = false;
    if ($viewerId > 0 && $viewerId !== $ownerUserId && function_exists('vp3_social_schema_ready_v320')) {
        try {
            $social = vp3_social_schema_ready_v320($pdo);
            if ($social && function_exists('vp3_social_dm_route_v320')) {
                $messaging = vp3_social_dm_route_v320($pdo, $viewerId, $ownerUserId) !== 'blocked';
            }
        } catch (Throwable $e) {
            $social = false;
            $messaging = false;
        }
    }

    return [
        'profile' => true,
        'profile_agent' => $profileAgent,
        'booking' => $booking,
        'commerce' => $commerce,
        'campaigns' => $campaigns,
        'rewards' => $rewards,
        'social' => $social,
        'messaging' => $messaging,
    ];
}

function vp3_profile_webmcp_manifest_v100(PDO $pdo, array $profile, ?array $viewer, array $context = []): array
{
    $surface = (string)($context['surface'] ?? 'native_profile');
    if ($surface !== 'native_profile') throw new RuntimeException('Section 1 supports only the native profile surface.');
    $capabilities = vp3_profile_webmcp_capabilities_v100($pdo, $profile, $viewer);
    $catalog = vp3_profile_webmcp_tool_catalog_v100();
    $allowed = [];
    foreach ($catalog as $name => $tool) {
        $capability = (string)($tool['capability'] ?? '');
        if (($capabilities[$capability] ?? false) === true) $allowed[] = $name;
    }
    sort($allowed);
    $authenticated = (int)($viewer['id'] ?? 0) > 0;
    $identityDisclosed = false;
    if ($authenticated && function_exists('profile_visitor_discloses_identity')) {
        try { $identityDisclosed = profile_visitor_discloses_identity($pdo, $viewer); }
        catch (Throwable $e) { $identityDisclosed = false; }
    }
    return [
        'manifest_version' => VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface' => 'native_profile',
        'profile_username' => (string)($profile['username'] ?? ''),
        'capabilities' => $capabilities,
        'allowed_tools' => $allowed,
        'session' => [
            'authenticated' => $authenticated,
            'visitor_profile_known' => $identityDisclosed,
        ],
    ];
}

function vp3_profile_webmcp_resolve_intent_v100(string $goal, array $manifest): array
{
    $goal = mb_strtolower(trim($goal));
    if ($goal === '') throw new RuntimeException('A goal is required.');
    if (mb_strlen($goal) > 1000) throw new RuntimeException('Goal is too long.');

    $groups = [
        'booking' => ['book','booking','appointment','schedule','availability','available time','reservation'],
        'commerce' => ['buy','purchase','product','price','checkout','order'],
        'campaigns' => ['campaign','offer','promotion','promo','deal','event','rsvp'],
        'rewards' => ['reward','loyalty','points','wallet','claim','redeem'],
        'messaging' => ['message','contact','dm','send a message'],
        'social' => ['follow','friend','connect'],
        'profile_agent' => ['ask','agent','chat','question'],
        'profile' => ['profile','bio','about','link','website'],
    ];
    $matches = [];
    foreach ($groups as $capability => $needles) {
        if (empty($manifest['capabilities'][$capability])) continue;
        foreach ($needles as $needle) {
            if (str_contains($goal, $needle)) {
                $matches[] = $capability;
                break;
            }
        }
    }
    if (!$matches) $matches[] = !empty($manifest['capabilities']['profile_agent']) ? 'profile_agent' : 'profile';
    return [
        'goal' => $goal,
        'recommended_capabilities' => array_values(array_unique($matches)),
        'registered_tools' => array_values($manifest['allowed_tools'] ?? []),
        'execution_performed' => false,
        'requires_domain_adapter' => array_values(array_filter(
            array_unique($matches),
            static fn(string $capability): bool => !in_array($capability, ['profile','profile_agent'], true)
        )),
    ];
}
]],
                'required'=>['public_token'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.reschedule.prepare' => [
            'title' => 'Prepare booking reschedule',
            'description' => 'Validate and preview a new time for a booking using its opaque manage token.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>[
                    'manage_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}
            'title' => 'Resolve profile intent',
            'description' => 'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
            'capability' => 'profile',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['goal'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],
                'required'=>['goal'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
    return $catalog;
}

function vp3_profile_webmcp_session_proof_v100(int $ownerUserId): string
{
    if ($ownerUserId < 1) throw new RuntimeException('Profile owner is invalid.');
    if (!isset($_SESSION['vp3_profile_webmcp_proofs']) || !is_array($_SESSION['vp3_profile_webmcp_proofs'])) {
        $_SESSION['vp3_profile_webmcp_proofs'] = [];
    }
    $key = (string)$ownerUserId;
    if (empty($_SESSION['vp3_profile_webmcp_proofs'][$key])) {
        $_SESSION['vp3_profile_webmcp_proofs'][$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['vp3_profile_webmcp_proofs'][$key];
}

function vp3_profile_webmcp_session_proof_valid_v100(int $ownerUserId, string $proof): bool
{
    return $ownerUserId > 0 && $proof !== '' && hash_equals(vp3_profile_webmcp_session_proof_v100($ownerUserId), $proof);
}

function vp3_profile_webmcp_rate_limit_v100(int $ownerUserId): bool
{
    if (!isset($_SESSION['vp3_profile_webmcp_rate']) || !is_array($_SESSION['vp3_profile_webmcp_rate'])) {
        $_SESSION['vp3_profile_webmcp_rate'] = [];
    }
    $now = time();
    $key = (string)$ownerUserId;
    $row = $_SESSION['vp3_profile_webmcp_rate'][$key] ?? ['started_at'=>$now,'count'=>0];
    if (!is_array($row) || $now - (int)($row['started_at'] ?? 0) >= VP3_PROFILE_WEBMCP_RATE_WINDOW_V100) {
        $row = ['started_at'=>$now,'count'=>0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    $_SESSION['vp3_profile_webmcp_rate'][$key] = $row;
    return $row['count'] <= VP3_PROFILE_WEBMCP_RATE_LIMIT_V100;
}

function vp3_profile_webmcp_normalize_host_v100(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = preg_replace('/:\\d+$/', '', $value) ?? $value;
    return rtrim(strtolower($host), '.');
}

function vp3_profile_webmcp_native_origin_allowed_v100(string $origin, string $requestHost): bool
{
    if (trim($origin) === '') return true;
    $originHost = vp3_profile_webmcp_normalize_host_v100($origin);
    $requestHost = vp3_profile_webmcp_normalize_host_v100($requestHost);
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function vp3_profile_webmcp_public_profile_v100(array $profile): array
{
    $safeUrl = static function(string $value): string {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    };
    $links = [];
    foreach ([
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
        'tidal'=>'tidal_url','facebook'=>'facebook_url'
    ] as $label => $field) {
        $value = $safeUrl((string)($profile[$field] ?? ''));
        if ($value !== '') $links[$label] = $value;
    }
    $username = (string)($profile['username'] ?? '');
    return [
        'username' => $username,
        'display_name' => trim((string)($profile['display_name'] ?? '')),
        'bio' => trim((string)($profile['bio'] ?? '')),
        'tagline' => trim((string)($profile['tagline'] ?? '')),
        'role' => (string)($profile['role'] ?? ''),
        'public_url' => function_exists('profile_public_url') ? profile_public_url($username) : ('/' . rawurlencode($username)),
        'links' => $links,
    ];
}

function vp3_profile_webmcp_owner_user_v100(PDO $pdo, array $profile): ?array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    if ($ownerUserId < 1) return null;
    if (function_exists('profile_user_row')) return profile_user_row($pdo, $ownerUserId);
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_profile_webmcp_capabilities_v100(PDO $pdo, array $profile, ?array $viewer): array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    $ownerUser = vp3_profile_webmcp_owner_user_v100($pdo, $profile);

    $profileAgent = false;
    try {
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $entitled = !$ownerUser || !function_exists('personal_capability_has_v242')
            ? true
            : (
                personal_capability_has_v242('profile_agent.access', $ownerUser)
                && personal_capability_has_v242('profile_chat.access', $ownerUser)
            );
        $profileAgent = (bool)$agent && $entitled && ($viewerId<1 || $viewerId!==$ownerUserId);
    } catch (Throwable $e) {
        $profileAgent = false;
    }

    $booking = false;
    try {
        if (function_exists('agent_scheduling_public_schedule_v450') && function_exists('agent_scheduling_public_events_v450')) {
            $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
            $booking = is_array($schedule) && count(agent_scheduling_public_events_v450($pdo, (int)$schedule['id'])) > 0;
        }
    } catch (Throwable $e) {
        $booking = false;
    }

    $commerce = false;
    try {
        if (function_exists('profile_commerce_products_for_profile_v900')) {
            $commerce = count(profile_commerce_products_for_profile_v900($pdo, $profile, true, 1)) > 0;
        }
    } catch (Throwable $e) {
        $commerce = false;
    }

    $campaigns = false;
    try {
        if (function_exists('campaigns_rewards_schema_ready_v100') && campaigns_rewards_schema_ready_v100($pdo)
            && function_exists('campaigns_rewards_profile_campaigns_v100')) {
            $campaigns = count(campaigns_rewards_profile_campaigns_v100($pdo, $ownerUserId, 1)) > 0;
        }
    } catch (Throwable $e) {
        $campaigns = false;
    }

    $rewards = $viewerId > 0 && function_exists('campaigns_rewards_reward_tray_v110');
    $social = false;
    $messaging = false;
    if ($viewerId > 0 && $viewerId !== $ownerUserId && function_exists('vp3_social_schema_ready_v320')) {
        try {
            $social = vp3_social_schema_ready_v320($pdo);
            if ($social && function_exists('vp3_social_dm_route_v320')) {
                $messaging = vp3_social_dm_route_v320($pdo, $viewerId, $ownerUserId) !== 'blocked';
            }
        } catch (Throwable $e) {
            $social = false;
            $messaging = false;
        }
    }

    return [
        'profile' => true,
        'profile_agent' => $profileAgent,
        'booking' => $booking,
        'commerce' => $commerce,
        'campaigns' => $campaigns,
        'rewards' => $rewards,
        'social' => $social,
        'messaging' => $messaging,
    ];
}

function vp3_profile_webmcp_manifest_v100(PDO $pdo, array $profile, ?array $viewer, array $context = []): array
{
    $surface = (string)($context['surface'] ?? 'native_profile');
    if ($surface !== 'native_profile') throw new RuntimeException('Section 1 supports only the native profile surface.');
    $capabilities = vp3_profile_webmcp_capabilities_v100($pdo, $profile, $viewer);
    $catalog = vp3_profile_webmcp_tool_catalog_v100();
    $allowed = [];
    foreach ($catalog as $name => $tool) {
        $capability = (string)($tool['capability'] ?? '');
        if (($capabilities[$capability] ?? false) === true) $allowed[] = $name;
    }
    sort($allowed);
    $authenticated = (int)($viewer['id'] ?? 0) > 0;
    $identityDisclosed = false;
    if ($authenticated && function_exists('profile_visitor_discloses_identity')) {
        try { $identityDisclosed = profile_visitor_discloses_identity($pdo, $viewer); }
        catch (Throwable $e) { $identityDisclosed = false; }
    }
    return [
        'manifest_version' => VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface' => 'native_profile',
        'profile_username' => (string)($profile['username'] ?? ''),
        'capabilities' => $capabilities,
        'allowed_tools' => $allowed,
        'session' => [
            'authenticated' => $authenticated,
            'visitor_profile_known' => $identityDisclosed,
        ],
    ];
}

function vp3_profile_webmcp_resolve_intent_v100(string $goal, array $manifest): array
{
    $goal = mb_strtolower(trim($goal));
    if ($goal === '') throw new RuntimeException('A goal is required.');
    if (mb_strlen($goal) > 1000) throw new RuntimeException('Goal is too long.');

    $groups = [
        'booking' => ['book','booking','appointment','schedule','availability','available time','reservation'],
        'commerce' => ['buy','purchase','product','price','checkout','order'],
        'campaigns' => ['campaign','offer','promotion','promo','deal','event','rsvp'],
        'rewards' => ['reward','loyalty','points','wallet','claim','redeem'],
        'messaging' => ['message','contact','dm','send a message'],
        'social' => ['follow','friend','connect'],
        'profile_agent' => ['ask','agent','chat','question'],
        'profile' => ['profile','bio','about','link','website'],
    ];
    $matches = [];
    foreach ($groups as $capability => $needles) {
        if (empty($manifest['capabilities'][$capability])) continue;
        foreach ($needles as $needle) {
            if (str_contains($goal, $needle)) {
                $matches[] = $capability;
                break;
            }
        }
    }
    if (!$matches) $matches[] = !empty($manifest['capabilities']['profile_agent']) ? 'profile_agent' : 'profile';
    return [
        'goal' => $goal,
        'recommended_capabilities' => array_values(array_unique($matches)),
        'registered_tools' => array_values($manifest['allowed_tools'] ?? []),
        'execution_performed' => false,
        'requires_domain_adapter' => array_values(array_filter(
            array_unique($matches),
            static fn(string $capability): bool => !in_array($capability, ['profile','profile_agent'], true)
        )),
    ];
}
],
                    'start_at_utc'=>['type'=>'string','maxLength'=>40],
                    'guest_timezone'=>['type'=>'string','maxLength'=>80],
                ],
                'required'=>['manage_token','start_at_utc'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.reschedule.confirm' => [
            'title' => 'Confirm booking reschedule',
            'description' => 'Apply the exact prepared reschedule after explicit confirmation and idempotency validation.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>[
                    'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
                    'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
                    'intent'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['confirmation_token','idempotency_key','intent'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.booking.cancel.prepare' => [
            'title' => 'Prepare booking cancellation',
            'description' => 'Validate and preview cancellation of a booking using its opaque manage token.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['manage_token'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}
            'title' => 'Resolve profile intent',
            'description' => 'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
            'capability' => 'profile',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['goal'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],
                'required'=>['goal'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
    return $catalog;
}

function vp3_profile_webmcp_session_proof_v100(int $ownerUserId): string
{
    if ($ownerUserId < 1) throw new RuntimeException('Profile owner is invalid.');
    if (!isset($_SESSION['vp3_profile_webmcp_proofs']) || !is_array($_SESSION['vp3_profile_webmcp_proofs'])) {
        $_SESSION['vp3_profile_webmcp_proofs'] = [];
    }
    $key = (string)$ownerUserId;
    if (empty($_SESSION['vp3_profile_webmcp_proofs'][$key])) {
        $_SESSION['vp3_profile_webmcp_proofs'][$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['vp3_profile_webmcp_proofs'][$key];
}

function vp3_profile_webmcp_session_proof_valid_v100(int $ownerUserId, string $proof): bool
{
    return $ownerUserId > 0 && $proof !== '' && hash_equals(vp3_profile_webmcp_session_proof_v100($ownerUserId), $proof);
}

function vp3_profile_webmcp_rate_limit_v100(int $ownerUserId): bool
{
    if (!isset($_SESSION['vp3_profile_webmcp_rate']) || !is_array($_SESSION['vp3_profile_webmcp_rate'])) {
        $_SESSION['vp3_profile_webmcp_rate'] = [];
    }
    $now = time();
    $key = (string)$ownerUserId;
    $row = $_SESSION['vp3_profile_webmcp_rate'][$key] ?? ['started_at'=>$now,'count'=>0];
    if (!is_array($row) || $now - (int)($row['started_at'] ?? 0) >= VP3_PROFILE_WEBMCP_RATE_WINDOW_V100) {
        $row = ['started_at'=>$now,'count'=>0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    $_SESSION['vp3_profile_webmcp_rate'][$key] = $row;
    return $row['count'] <= VP3_PROFILE_WEBMCP_RATE_LIMIT_V100;
}

function vp3_profile_webmcp_normalize_host_v100(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = preg_replace('/:\\d+$/', '', $value) ?? $value;
    return rtrim(strtolower($host), '.');
}

function vp3_profile_webmcp_native_origin_allowed_v100(string $origin, string $requestHost): bool
{
    if (trim($origin) === '') return true;
    $originHost = vp3_profile_webmcp_normalize_host_v100($origin);
    $requestHost = vp3_profile_webmcp_normalize_host_v100($requestHost);
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function vp3_profile_webmcp_public_profile_v100(array $profile): array
{
    $safeUrl = static function(string $value): string {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    };
    $links = [];
    foreach ([
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
        'tidal'=>'tidal_url','facebook'=>'facebook_url'
    ] as $label => $field) {
        $value = $safeUrl((string)($profile[$field] ?? ''));
        if ($value !== '') $links[$label] = $value;
    }
    $username = (string)($profile['username'] ?? '');
    return [
        'username' => $username,
        'display_name' => trim((string)($profile['display_name'] ?? '')),
        'bio' => trim((string)($profile['bio'] ?? '')),
        'tagline' => trim((string)($profile['tagline'] ?? '')),
        'role' => (string)($profile['role'] ?? ''),
        'public_url' => function_exists('profile_public_url') ? profile_public_url($username) : ('/' . rawurlencode($username)),
        'links' => $links,
    ];
}

function vp3_profile_webmcp_owner_user_v100(PDO $pdo, array $profile): ?array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    if ($ownerUserId < 1) return null;
    if (function_exists('profile_user_row')) return profile_user_row($pdo, $ownerUserId);
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_profile_webmcp_capabilities_v100(PDO $pdo, array $profile, ?array $viewer): array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    $ownerUser = vp3_profile_webmcp_owner_user_v100($pdo, $profile);

    $profileAgent = false;
    try {
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $entitled = !$ownerUser || !function_exists('personal_capability_has_v242')
            ? true
            : (
                personal_capability_has_v242('profile_agent.access', $ownerUser)
                && personal_capability_has_v242('profile_chat.access', $ownerUser)
            );
        $profileAgent = (bool)$agent && $entitled && ($viewerId<1 || $viewerId!==$ownerUserId);
    } catch (Throwable $e) {
        $profileAgent = false;
    }

    $booking = false;
    try {
        if (function_exists('agent_scheduling_public_schedule_v450') && function_exists('agent_scheduling_public_events_v450')) {
            $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
            $booking = is_array($schedule) && count(agent_scheduling_public_events_v450($pdo, (int)$schedule['id'])) > 0;
        }
    } catch (Throwable $e) {
        $booking = false;
    }

    $commerce = false;
    try {
        if (function_exists('profile_commerce_products_for_profile_v900')) {
            $commerce = count(profile_commerce_products_for_profile_v900($pdo, $profile, true, 1)) > 0;
        }
    } catch (Throwable $e) {
        $commerce = false;
    }

    $campaigns = false;
    try {
        if (function_exists('campaigns_rewards_schema_ready_v100') && campaigns_rewards_schema_ready_v100($pdo)
            && function_exists('campaigns_rewards_profile_campaigns_v100')) {
            $campaigns = count(campaigns_rewards_profile_campaigns_v100($pdo, $ownerUserId, 1)) > 0;
        }
    } catch (Throwable $e) {
        $campaigns = false;
    }

    $rewards = $viewerId > 0 && function_exists('campaigns_rewards_reward_tray_v110');
    $social = false;
    $messaging = false;
    if ($viewerId > 0 && $viewerId !== $ownerUserId && function_exists('vp3_social_schema_ready_v320')) {
        try {
            $social = vp3_social_schema_ready_v320($pdo);
            if ($social && function_exists('vp3_social_dm_route_v320')) {
                $messaging = vp3_social_dm_route_v320($pdo, $viewerId, $ownerUserId) !== 'blocked';
            }
        } catch (Throwable $e) {
            $social = false;
            $messaging = false;
        }
    }

    return [
        'profile' => true,
        'profile_agent' => $profileAgent,
        'booking' => $booking,
        'commerce' => $commerce,
        'campaigns' => $campaigns,
        'rewards' => $rewards,
        'social' => $social,
        'messaging' => $messaging,
    ];
}

function vp3_profile_webmcp_manifest_v100(PDO $pdo, array $profile, ?array $viewer, array $context = []): array
{
    $surface = (string)($context['surface'] ?? 'native_profile');
    if ($surface !== 'native_profile') throw new RuntimeException('Section 1 supports only the native profile surface.');
    $capabilities = vp3_profile_webmcp_capabilities_v100($pdo, $profile, $viewer);
    $catalog = vp3_profile_webmcp_tool_catalog_v100();
    $allowed = [];
    foreach ($catalog as $name => $tool) {
        $capability = (string)($tool['capability'] ?? '');
        if (($capabilities[$capability] ?? false) === true) $allowed[] = $name;
    }
    sort($allowed);
    $authenticated = (int)($viewer['id'] ?? 0) > 0;
    $identityDisclosed = false;
    if ($authenticated && function_exists('profile_visitor_discloses_identity')) {
        try { $identityDisclosed = profile_visitor_discloses_identity($pdo, $viewer); }
        catch (Throwable $e) { $identityDisclosed = false; }
    }
    return [
        'manifest_version' => VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface' => 'native_profile',
        'profile_username' => (string)($profile['username'] ?? ''),
        'capabilities' => $capabilities,
        'allowed_tools' => $allowed,
        'session' => [
            'authenticated' => $authenticated,
            'visitor_profile_known' => $identityDisclosed,
        ],
    ];
}

function vp3_profile_webmcp_resolve_intent_v100(string $goal, array $manifest): array
{
    $goal = mb_strtolower(trim($goal));
    if ($goal === '') throw new RuntimeException('A goal is required.');
    if (mb_strlen($goal) > 1000) throw new RuntimeException('Goal is too long.');

    $groups = [
        'booking' => ['book','booking','appointment','schedule','availability','available time','reservation'],
        'commerce' => ['buy','purchase','product','price','checkout','order'],
        'campaigns' => ['campaign','offer','promotion','promo','deal','event','rsvp'],
        'rewards' => ['reward','loyalty','points','wallet','claim','redeem'],
        'messaging' => ['message','contact','dm','send a message'],
        'social' => ['follow','friend','connect'],
        'profile_agent' => ['ask','agent','chat','question'],
        'profile' => ['profile','bio','about','link','website'],
    ];
    $matches = [];
    foreach ($groups as $capability => $needles) {
        if (empty($manifest['capabilities'][$capability])) continue;
        foreach ($needles as $needle) {
            if (str_contains($goal, $needle)) {
                $matches[] = $capability;
                break;
            }
        }
    }
    if (!$matches) $matches[] = !empty($manifest['capabilities']['profile_agent']) ? 'profile_agent' : 'profile';
    return [
        'goal' => $goal,
        'recommended_capabilities' => array_values(array_unique($matches)),
        'registered_tools' => array_values($manifest['allowed_tools'] ?? []),
        'execution_performed' => false,
        'requires_domain_adapter' => array_values(array_filter(
            array_unique($matches),
            static fn(string $capability): bool => !in_array($capability, ['profile','profile_agent'], true)
        )),
    ];
}
]],
                'required'=>['manage_token'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.booking.cancel.confirm' => [
            'title' => 'Confirm booking cancellation',
            'description' => 'Cancel the exact prepared booking after explicit confirmation and idempotency validation.',
            'capability' => 'booking',
            'input_schema' => [
                'type'=>'object',
                'properties'=>[
                    'confirmation_token'=>['type'=>'string','minLength'=>20,'maxLength'=>2048],
                    'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>96],
                    'intent'=>['type'=>'object','additionalProperties'=>true],
                ],
                'required'=>['confirmation_token','idempotency_key','intent'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>false,'untrustedContentHint'=>true,'consequentialHint'=>true,'debugging'=>false],
        ],
        'vp3.intent.resolve' => [
            'title' => 'Resolve profile intent',
            'description' => 'Identify which currently available VP3 profile capabilities can help with a user goal without executing an action.',
            'capability' => 'profile',
            'input_schema' => [
                'type'=>'object',
                'properties'=>['goal'=>['type'=>'string','minLength'=>1,'maxLength'=>1000]],
                'required'=>['goal'],
                'additionalProperties'=>false,
            ],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
    return $catalog;
}

function vp3_profile_webmcp_session_proof_v100(int $ownerUserId): string
{
    if ($ownerUserId < 1) throw new RuntimeException('Profile owner is invalid.');
    if (!isset($_SESSION['vp3_profile_webmcp_proofs']) || !is_array($_SESSION['vp3_profile_webmcp_proofs'])) {
        $_SESSION['vp3_profile_webmcp_proofs'] = [];
    }
    $key = (string)$ownerUserId;
    if (empty($_SESSION['vp3_profile_webmcp_proofs'][$key])) {
        $_SESSION['vp3_profile_webmcp_proofs'][$key] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['vp3_profile_webmcp_proofs'][$key];
}

function vp3_profile_webmcp_session_proof_valid_v100(int $ownerUserId, string $proof): bool
{
    return $ownerUserId > 0 && $proof !== '' && hash_equals(vp3_profile_webmcp_session_proof_v100($ownerUserId), $proof);
}

function vp3_profile_webmcp_rate_limit_v100(int $ownerUserId): bool
{
    if (!isset($_SESSION['vp3_profile_webmcp_rate']) || !is_array($_SESSION['vp3_profile_webmcp_rate'])) {
        $_SESSION['vp3_profile_webmcp_rate'] = [];
    }
    $now = time();
    $key = (string)$ownerUserId;
    $row = $_SESSION['vp3_profile_webmcp_rate'][$key] ?? ['started_at'=>$now,'count'=>0];
    if (!is_array($row) || $now - (int)($row['started_at'] ?? 0) >= VP3_PROFILE_WEBMCP_RATE_WINDOW_V100) {
        $row = ['started_at'=>$now,'count'=>0];
    }
    $row['count'] = (int)($row['count'] ?? 0) + 1;
    $_SESSION['vp3_profile_webmcp_rate'][$key] = $row;
    return $row['count'] <= VP3_PROFILE_WEBMCP_RATE_LIMIT_V100;
}

function vp3_profile_webmcp_normalize_host_v100(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') return '';
    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') $host = preg_replace('/:\\d+$/', '', $value) ?? $value;
    return rtrim(strtolower($host), '.');
}

function vp3_profile_webmcp_native_origin_allowed_v100(string $origin, string $requestHost): bool
{
    if (trim($origin) === '') return true;
    $originHost = vp3_profile_webmcp_normalize_host_v100($origin);
    $requestHost = vp3_profile_webmcp_normalize_host_v100($requestHost);
    return $originHost !== '' && $requestHost !== '' && hash_equals($requestHost, $originHost);
}

function vp3_profile_webmcp_public_profile_v100(array $profile): array
{
    $safeUrl = static function(string $value): string {
        $value = trim($value);
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $value : '';
    };
    $links = [];
    foreach ([
        'website'=>'website_url','instagram'=>'instagram_url','tiktok'=>'tiktok_url',
        'youtube'=>'youtube_url','spotify'=>'spotify_url','apple_music'=>'apple_music_url',
        'tidal'=>'tidal_url','facebook'=>'facebook_url'
    ] as $label => $field) {
        $value = $safeUrl((string)($profile[$field] ?? ''));
        if ($value !== '') $links[$label] = $value;
    }
    $username = (string)($profile['username'] ?? '');
    return [
        'username' => $username,
        'display_name' => trim((string)($profile['display_name'] ?? '')),
        'bio' => trim((string)($profile['bio'] ?? '')),
        'tagline' => trim((string)($profile['tagline'] ?? '')),
        'role' => (string)($profile['role'] ?? ''),
        'public_url' => function_exists('profile_public_url') ? profile_public_url($username) : ('/' . rawurlencode($username)),
        'links' => $links,
    ];
}

function vp3_profile_webmcp_owner_user_v100(PDO $pdo, array $profile): ?array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    if ($ownerUserId < 1) return null;
    if (function_exists('profile_user_row')) return profile_user_row($pdo, $ownerUserId);
    $stmt = $pdo->prepare('SELECT id,display_name,email,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$ownerUserId]);
    return $stmt->fetch() ?: null;
}

function vp3_profile_webmcp_capabilities_v100(PDO $pdo, array $profile, ?array $viewer): array
{
    $ownerUserId = (int)($profile['user_id'] ?? 0);
    $viewerId = (int)($viewer['id'] ?? 0);
    $ownerUser = vp3_profile_webmcp_owner_user_v100($pdo, $profile);

    $profileAgent = false;
    try {
        $agent = function_exists('profile_active_agent') ? profile_active_agent($pdo, $profile) : null;
        $entitled = !$ownerUser || !function_exists('personal_capability_has_v242')
            ? true
            : (
                personal_capability_has_v242('profile_agent.access', $ownerUser)
                && personal_capability_has_v242('profile_chat.access', $ownerUser)
            );
        $profileAgent = (bool)$agent && $entitled && ($viewerId<1 || $viewerId!==$ownerUserId);
    } catch (Throwable $e) {
        $profileAgent = false;
    }

    $booking = false;
    try {
        if (function_exists('agent_scheduling_public_schedule_v450') && function_exists('agent_scheduling_public_events_v450')) {
            $schedule = agent_scheduling_public_schedule_v450($pdo, $ownerUserId);
            $booking = is_array($schedule) && count(agent_scheduling_public_events_v450($pdo, (int)$schedule['id'])) > 0;
        }
    } catch (Throwable $e) {
        $booking = false;
    }

    $commerce = false;
    try {
        if (function_exists('profile_commerce_products_for_profile_v900')) {
            $commerce = count(profile_commerce_products_for_profile_v900($pdo, $profile, true, 1)) > 0;
        }
    } catch (Throwable $e) {
        $commerce = false;
    }

    $campaigns = false;
    try {
        if (function_exists('campaigns_rewards_schema_ready_v100') && campaigns_rewards_schema_ready_v100($pdo)
            && function_exists('campaigns_rewards_profile_campaigns_v100')) {
            $campaigns = count(campaigns_rewards_profile_campaigns_v100($pdo, $ownerUserId, 1)) > 0;
        }
    } catch (Throwable $e) {
        $campaigns = false;
    }

    $rewards = $viewerId > 0 && function_exists('campaigns_rewards_reward_tray_v110');
    $social = false;
    $messaging = false;
    if ($viewerId > 0 && $viewerId !== $ownerUserId && function_exists('vp3_social_schema_ready_v320')) {
        try {
            $social = vp3_social_schema_ready_v320($pdo);
            if ($social && function_exists('vp3_social_dm_route_v320')) {
                $messaging = vp3_social_dm_route_v320($pdo, $viewerId, $ownerUserId) !== 'blocked';
            }
        } catch (Throwable $e) {
            $social = false;
            $messaging = false;
        }
    }

    return [
        'profile' => true,
        'profile_agent' => $profileAgent,
        'booking' => $booking,
        'commerce' => $commerce,
        'campaigns' => $campaigns,
        'rewards' => $rewards,
        'social' => $social,
        'messaging' => $messaging,
    ];
}

function vp3_profile_webmcp_manifest_v100(PDO $pdo, array $profile, ?array $viewer, array $context = []): array
{
    $surface = (string)($context['surface'] ?? 'native_profile');
    if ($surface !== 'native_profile') throw new RuntimeException('Section 1 supports only the native profile surface.');
    $capabilities = vp3_profile_webmcp_capabilities_v100($pdo, $profile, $viewer);
    $catalog = vp3_profile_webmcp_tool_catalog_v100();
    $allowed = [];
    foreach ($catalog as $name => $tool) {
        $capability = (string)($tool['capability'] ?? '');
        if (($capabilities[$capability] ?? false) === true) $allowed[] = $name;
    }
    sort($allowed);
    $authenticated = (int)($viewer['id'] ?? 0) > 0;
    $identityDisclosed = false;
    if ($authenticated && function_exists('profile_visitor_discloses_identity')) {
        try { $identityDisclosed = profile_visitor_discloses_identity($pdo, $viewer); }
        catch (Throwable $e) { $identityDisclosed = false; }
    }
    return [
        'manifest_version' => VP3_PROFILE_WEBMCP_MANIFEST_V100,
        'surface' => 'native_profile',
        'profile_username' => (string)($profile['username'] ?? ''),
        'capabilities' => $capabilities,
        'allowed_tools' => $allowed,
        'session' => [
            'authenticated' => $authenticated,
            'visitor_profile_known' => $identityDisclosed,
        ],
    ];
}

function vp3_profile_webmcp_resolve_intent_v100(string $goal, array $manifest): array
{
    $goal = mb_strtolower(trim($goal));
    if ($goal === '') throw new RuntimeException('A goal is required.');
    if (mb_strlen($goal) > 1000) throw new RuntimeException('Goal is too long.');

    $groups = [
        'booking' => ['book','booking','appointment','schedule','availability','available time','reservation'],
        'commerce' => ['buy','purchase','product','price','checkout','order'],
        'campaigns' => ['campaign','offer','promotion','promo','deal','event','rsvp'],
        'rewards' => ['reward','loyalty','points','wallet','claim','redeem'],
        'messaging' => ['message','contact','dm','send a message'],
        'social' => ['follow','friend','connect'],
        'profile_agent' => ['ask','agent','chat','question'],
        'profile' => ['profile','bio','about','link','website'],
    ];
    $matches = [];
    foreach ($groups as $capability => $needles) {
        if (empty($manifest['capabilities'][$capability])) continue;
        foreach ($needles as $needle) {
            if (str_contains($goal, $needle)) {
                $matches[] = $capability;
                break;
            }
        }
    }
    if (!$matches) $matches[] = !empty($manifest['capabilities']['profile_agent']) ? 'profile_agent' : 'profile';
    return [
        'goal' => $goal,
        'recommended_capabilities' => array_values(array_unique($matches)),
        'registered_tools' => array_values($manifest['allowed_tools'] ?? []),
        'execution_performed' => false,
        'requires_domain_adapter' => array_values(array_filter(
            array_unique($matches),
            static fn(string $capability): bool => !in_array($capability, ['profile','profile_agent'], true)
        )),
    ];
}
