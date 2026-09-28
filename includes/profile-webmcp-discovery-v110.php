<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_DISCOVERY_V110 = 'profile-webmcp-discovery-v110-20260928';

function vp3_profile_webmcp_discovery_tools_v110(): array
{
    return [
        'vp3.profile.links.list' => [
            'title' => 'List public profile links',
            'description' => 'Return explicitly public links published on this VP3 profile.',
            'capability' => 'profile',
            'input_schema' => ['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.profile.media.get' => [
            'title' => 'Get public profile media',
            'description' => 'Return the public avatar and cover media for this VP3 profile when available.',
            'capability' => 'profile',
            'input_schema' => ['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.agent.get' => [
            'title' => 'Get Profile Agent',
            'description' => 'Return the public identity and greeting of this profile’s enabled VP3 Profile Agent.',
            'capability' => 'profile_agent',
            'input_schema' => ['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>true,'consequentialHint'=>false,'debugging'=>false],
        ],
        'vp3.profile.public_state.get' => [
            'title' => 'Get public profile state',
            'description' => 'Return which public VP3 profile experiences are currently available without returning private or transactional data.',
            'capability' => 'profile',
            'input_schema' => ['type'=>'object','properties'=>(object)[],'additionalProperties'=>false],
            'annotations' => ['readOnlyHint'=>true,'untrustedContentHint'=>false,'consequentialHint'=>false,'debugging'=>false],
        ],
    ];
}

function vp3_profile_webmcp_links_v110(array $profile): array
{
    $base = function_exists('vp3_profile_webmcp_public_profile_v100')
        ? vp3_profile_webmcp_public_profile_v100($profile)
        : ['links'=>[]];
    $links = [];
    foreach ((array)($base['links'] ?? []) as $label => $url) {
        $label = trim((string)$label);
        $url = trim((string)$url);
        if ($label === '' || $url === '') continue;
        $links[] = ['label'=>$label,'url'=>$url];
    }
    return $links;
}

function vp3_profile_webmcp_media_v110(array $profile): array
{
    $media = ['avatar_url'=>'','cover_url'=>''];
    if (function_exists('profile_public_media_url_v174')) {
        $media['avatar_url'] = profile_public_media_url_v174((string)($profile['avatar_path'] ?? ''), 'avatars');
        $media['cover_url'] = profile_public_media_url_v174((string)($profile['cover_path'] ?? ''), 'profile-covers');
    }
    return array_filter($media, static fn(string $url): bool => trim($url) !== '');
}

function vp3_profile_webmcp_agent_v110(PDO $pdo, array $profile): ?array
{
    if (!function_exists('profile_active_agent')) return null;
    $agent = profile_active_agent($pdo, $profile);
    if (!is_array($agent)) return null;
    return [
        'display_name' => trim((string)($agent['display_name'] ?? $agent['name'] ?? '')),
        'greeting' => trim((string)($profile['profile_agent_greeting'] ?? '')),
        'ai_representative' => true,
    ];
}

function vp3_profile_webmcp_public_state_v110(PDO $pdo, array $profile, ?array $viewer, array $manifest): array
{
    $capabilities = (array)($manifest['capabilities'] ?? []);
    $links = vp3_profile_webmcp_links_v110($profile);
    $media = vp3_profile_webmcp_media_v110($profile);

    return [
        'profile_agent_available' => !empty($capabilities['profile_agent']),
        'booking_available' => !empty($capabilities['booking']),
        'commerce_available' => !empty($capabilities['commerce']),
        'campaigns_available' => !empty($capabilities['campaigns']),
        'rewards_available' => !empty($capabilities['rewards']),
        'social_available' => !empty($capabilities['social']),
        'messaging_available' => !empty($capabilities['messaging']),
        'public_links_available' => count($links) > 0,
        'public_media_available' => count($media) > 0,
        'viewer_authenticated' => (int)($viewer['id'] ?? 0) > 0,
        'viewer_identity_disclosed' => !empty($manifest['session']['visitor_profile_known']),
    ];
}
