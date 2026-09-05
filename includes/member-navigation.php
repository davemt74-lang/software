<?php
declare(strict_types=1);

/**
 * Return the signed-in member's canonical public-profile destination.
 *
 * The Profile Agent profile is the primary identity surface. Owners of an
 * unpublished profile receive the existing visitor-preview URL so the menu
 * never points them at a 404. Older/partially upgraded installs may fall back
 * to the Artist Workspace profile URL.
 */
function member_navigation_profile_url(?array $user = null): string
{
    $user ??= current_user();
    if (!$user || (int)($user['id'] ?? 0) < 1) {
        return '';
    }

    $pdo = db();
    if ($pdo && function_exists('profile_agent_schema_ready') && profile_agent_schema_ready($pdo)) {
        try {
            $profile = profile_migrate_artist_identity($pdo, $user);
            $username = trim((string)($profile['username'] ?? ''));
            if ($username !== '' && !empty($profile['is_active'])) {
                $profileUrl = profile_public_url($username);
                if (empty($profile['is_public'])) {
                    $profileUrl .= (str_contains($profileUrl, '?') ? '&' : '?') . 'preview=1';
                }
                return $profileUrl;
            }
        } catch (Throwable $e) {
            // Keep navigation usable while a partial deployment is upgraded.
        }
    }

    if (function_exists('artist_workspace_v181_profile_url_for_user')) {
        try {
            return artist_workspace_v181_profile_url_for_user($user);
        } catch (Throwable $e) {
            return '';
        }
    }

    return '';
}

/**
 * One canonical account/avatar-menu definition for authenticated surfaces.
 *
 * Dropdown entries are destinations, not settings tabs. Profile Agent is now
 * a first-class customer-service workspace rather than a My Account section.
 */
function member_navigation_menu_links(?array $user = null): array
{
    $user ??= current_user();
    if (!$user) {
        return [];
    }

    $links = [];
    $add = static function (
        array &$target,
        string $key,
        string $label,
        string $href,
        string $group,
        bool $danger = false
    ): void {
        if ($href === '') {
            return;
        }
        $target[] = [
            'key' => $key,
            'label' => $label,
            'url' => $href,
            'group' => $group,
            'danger' => $danger,
        ];
    };

    $profileUrl = member_navigation_profile_url($user);
    if ($profileUrl !== '') {
        $add($links, 'profile', 'View Profile', $profileUrl, 'identity');
    }

    if (has_permission('account.access', $user)) {
        $add($links, 'account', 'My Account', url('/account.php'), 'identity');
        if (has_permission('chat.access', $user)) {
            $add($links, 'profile_agent', 'Profile Agent', url('/profile-agent.php'), 'identity');
        }
    }
    if (has_permission('knowledge.manage', $user)) {
        $add($links, 'knowledge', 'My Knowledge', url('/knowledge.php'), 'identity');
    }
    if (has_permission('artist_listening.access', $user)) {
        $add($links, 'transcriptions', 'My Transcriptions', url('/artist-listening.php'), 'identity');
    }

    if (has_permission('chat.access', $user)) {
        $add($links, 'chat', 'Agent Chat', url('/chat.php'), 'agent');
    }
    if (has_permission('account.access', $user) && has_permission('chat.access', $user)) {
        $add($links, 'voice_profile', 'Voice Profile', url('/voice-profile.php'), 'agent');
    }

    $artistWorkspaceAllowed = user_has_role('artist', $user)
        && (
            has_any_permission(
                ['tracks.manage','albums.manage','shows.manage','photos.manage','merch.manage','posts.manage'],
                $user
            )
            || permission_v105_has('release.manage', $user)
        );
    if ($artistWorkspaceAllowed) {
        $add($links, 'artist_workspace', 'Artist Workspace', url('/admin/artist.php'), 'creator');
    }

    if (
        has_permission('tracks.manage', $user)
        || has_permission('track_notes.manage', $user)
        || has_permission('producer.access', $user)
    ) {
        $add($links, 'stem_studio', 'Stem Studio', url('/admin/stems.php'), 'creator');
    }
    if (has_permission('chat.access', $user)) {
        $add($links, 'video_editor', 'Video Editor', url('/video-editor.php'), 'creator');
    }

    if (has_permission('admin.access', $user)) {
        $add($links, 'admin', 'Admin Dashboard', url('/admin/index.php'), 'admin');
    }

    $add($links, 'logout', 'Log Out', url('/logout.php'), 'session', true);
    return $links;
}
