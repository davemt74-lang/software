<?php
declare(strict_types=1);

$teamChatUser = current_user();

if (!$teamChatUser || !team_chat_role_allowed($teamChatUser)) {
    return;
}

$teamChatPageKey = isset($teamChatPageKey)
    ? preg_replace('/[^a-z0-9_-]/i', '', (string)$teamChatPageKey)
    : 'workspace';
$teamChatContextLabel = isset($teamChatContextLabel)
    ? trim((string)$teamChatContextLabel)
    : '';

$teamChatPrimaryRole = (string)($teamChatUser['role'] ?? '');
$teamChatUiRole = in_array(
    $teamChatPrimaryRole,
    ['manager', 'producer', 'supervisor'],
    true
) ? $teamChatPrimaryRole : 'manager';
$teamChatAssetBuild = 'team-chat-light-v117-20260905';
$teamChatPdo = db();
$teamChatSettings = $teamChatPdo
    ? chat_settings_get_v237($teamChatPdo, (int)$teamChatUser['id'])
    : chat_settings_defaults_v237();
$teamChatSocialEnabled = !empty($teamChatSettings['social_chat_enabled']);
?>
<link rel="stylesheet" href="<?= e(url('/team-chat-v109.css?v=' . $teamChatAssetBuild)) ?>">
<aside class="sf-online-rail-v109" id="sfOnlineRailV109" aria-label="Stonefellow team chat"<?= $teamChatSocialEnabled ? '' : ' hidden' ?>>
  <div class="sf-online-users-v109" id="sfOnlineUsersV109"></div>
</aside>

<div
  class="sf-team-chat-windows-v109"
  id="sfTeamChatWindowsV109"
  aria-live="polite"
  aria-label="Direct message chats"
  <?= $teamChatSocialEnabled ? '' : 'hidden' ?>
></div>

<script>
window.STONEFELLOW_TEAM_CHAT = <?= json_encode([
    'endpoint'=>url('/api/team-chat-v109.php'),
    'csrf'=>csrf_token(),
    'userId'=>(int)$teamChatUser['id'],
    'role'=>$teamChatUiRole,
    'pageKey'=>$teamChatPageKey,
    'contextLabel'=>$teamChatContextLabel,
    'pollMs'=>3000,
    'soundEnabled'=>!empty($teamChatSettings['sound_enabled']),
    'socialChatEnabled'=>$teamChatSocialEnabled,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/team-chat-v109.js?v=' . $teamChatAssetBuild)) ?>"></script>
<?php if ($teamChatSocialEnabled): ?>
<script>document.body.classList.add('sf-team-rail-active');</script>
<?php endif; ?>
