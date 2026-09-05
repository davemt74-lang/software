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
$teamChatCssBuild = 'light-ui-20260904';
$teamChatJsBuild = 'mobile-rail-v115-20260825';
?>
<link rel="stylesheet" href="<?= e(url('/team-chat-v109.css?v=' . $teamChatCssBuild)) ?>">
<aside class="sf-online-rail-v109" id="sfOnlineRailV109" aria-label="Stonefellow team chat">
  <div class="sf-online-users-v109" id="sfOnlineUsersV109"></div>
</aside>

<div
  class="sf-team-chat-windows-v109"
  id="sfTeamChatWindowsV109"
  aria-live="polite"
  aria-label="Direct message chats"
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
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/team-chat-v109.js?v=' . $teamChatJsBuild)) ?>"></script>
<script>document.body.classList.add('sf-team-rail-active');</script>
