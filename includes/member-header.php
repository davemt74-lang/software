<?php
declare(strict_types=1);

$memberHeaderUser = $memberHeaderUser ?? current_user();
if (!$memberHeaderUser) return;

$memberHeaderTitle = trim((string)($memberHeaderTitle ?? ''));
$memberHeaderSubtitle = trim((string)($memberHeaderSubtitle ?? ''));
$memberHeaderActions = (string)($memberHeaderActions ?? '');
$memberHeaderClass = trim((string)($memberHeaderClass ?? ''));
$memberHeaderShowSidebarToggle = (bool)($memberHeaderShowSidebarToggle ?? true);
$memberHeaderNotifications = notification_recent($memberHeaderUser, 6);
$memberHeaderNotificationCount = notification_unread_count($memberHeaderUser);
$memberHeaderCanChat = has_permission('chat.access', $memberHeaderUser);
$memberHeaderAgentVoiceEnabled = $memberHeaderCanChat ? member_agent_voice_enabled($memberHeaderUser) : true;
?>
<header class="chat-topbar member-header<?= $memberHeaderClass !== '' ? ' ' . e($memberHeaderClass) : '' ?>" data-member-header>
  <?php if ($memberHeaderShowSidebarToggle): ?><button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button><?php endif; ?>

  <div class="chat-topbar-title member-header-title">
    <?php if ($memberHeaderTitle !== ''): ?><strong><?= e($memberHeaderTitle) ?></strong><?php endif; ?>
    <?php if ($memberHeaderSubtitle !== ''): ?><span><?= e($memberHeaderSubtitle) ?></span><?php endif; ?>
  </div>

  <div class="chat-topbar-actions member-header-actions">
    <?= $memberHeaderActions ?>

    <div class="chat-top-menu" id="chatNotificationMenu">
      <button class="chat-notification-link" id="chatNotificationButton" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="chatNotificationDropdown">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <?php if ($memberHeaderNotificationCount > 0): ?><span><?= $memberHeaderNotificationCount > 99 ? '99+' : (int)$memberHeaderNotificationCount ?></span><?php endif; ?>
      </button>

      <div class="chat-top-dropdown chat-notification-dropdown" id="chatNotificationDropdown" hidden>
        <header><strong>Notifications</strong><span><?= (int)$memberHeaderNotificationCount ?> unread</span></header>
        <div class="chat-notification-dropdown-list">
          <?php foreach ($memberHeaderNotifications as $notification): ?>
            <a class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>" href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>">
              <span class="chat-dropdown-dot"></span>
              <span><strong><?= e((string)$notification['title']) ?></strong><small><?= e((string)$notification['body']) ?></small></span>
            </a>
          <?php endforeach; ?>
          <?php if (!$memberHeaderNotifications): ?><div class="chat-dropdown-empty">No notifications yet.</div><?php endif; ?>
        </div>
        <a class="chat-dropdown-all" href="<?= e(url('/notifications.php')) ?>">View all notifications →</a>
      </div>
    </div>

    <?php $memberMenuUser = $memberHeaderUser; require __DIR__ . '/member-user-menu.php'; ?>
  </div>
</header>
<?php
if (empty($GLOBALS['STONEFELLOW_MEMBER_HEADER_RUNTIME_RENDERED'])):
    $GLOBALS['STONEFELLOW_MEMBER_HEADER_RUNTIME_RENDERED'] = true;
    $memberHeaderUiBuild = 'universal-member-header-layout-20260906';
    $memberHeaderSettingsBuild = 'chat-settings-v239-canonical-20260905';
    $memberHeaderNotificationBuild = 'chat-notifications-canvas-v240-20260905';
    $memberHeaderTranscriptionBuild = 'chat-transcription-canvas-v243-layout-20260905';
    $memberHeaderRecordingUiBuild = 'chat-recording-results-v206-20260901';
?>
<link rel="stylesheet" data-member-header-ui href="<?= e(url('/chat-header-ui.css?v=' . $memberHeaderUiBuild)) ?>">
<?php if ($memberHeaderCanChat): ?>
<link rel="stylesheet" data-chat-settings-canonical href="<?= e(url('/chat-settings-v237.css?v=' . $memberHeaderSettingsBuild)) ?>">
<script data-chat-settings-config>window.STONEFELLOW_CHAT_SETTINGS=<?= json_encode([
  'endpoint'=>url('/api/chat-settings-v237.php'),
  'csrf'=>csrf_token(),
  'build'=>$memberHeaderSettingsBuild,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<script data-chat-settings-canonical src="<?= e(url('/chat-settings-v237.js?v=' . $memberHeaderSettingsBuild)) ?>"></script>
<link rel="stylesheet" data-chat-notification-drawer href="<?= e(url('/chat-notifications-drawer-v240.css?v=' . $memberHeaderNotificationBuild)) ?>">
<script data-chat-notification-drawer-config>window.STONEFELLOW_NOTIFICATION_DRAWER=<?= json_encode([
  'endpoint'=>url('/api/chat-notifications-brain-v240.php'),
  'csrf'=>csrf_token(),
  'build'=>$memberHeaderNotificationBuild,
  'agentVoiceEnabled'=>$memberHeaderAgentVoiceEnabled,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
<script data-chat-notification-drawer src="<?= e(url('/chat-notifications-drawer-v240.js?v=' . $memberHeaderNotificationBuild)) ?>"></script>
<?php endif; ?>
<?php if (has_permission('artist_listening.access', $memberHeaderUser)): ?>
<script>window.STONEFELLOW_RECORDINGS_V198_CONFIG={endpoint:<?= json_encode(url('/api/artist-recordings-v198.php'), JSON_UNESCAPED_SLASHES) ?>,csrf:<?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>,artistListeningUrl:<?= json_encode(url('/artist-listening.php'), JSON_UNESCAPED_SLASHES) ?>,persistEndpoint:<?= json_encode(url('/api/chat-recordings-v242.php'), JSON_UNESCAPED_SLASHES) ?>};</script>
<link rel="stylesheet" data-chat-transcription-canvas href="<?= e(url('/chat-transcription-canvas.css?v=' . $memberHeaderTranscriptionBuild)) ?>">
<script data-artist-recordings-v198 data-recording-ui-build="<?= e($memberHeaderRecordingUiBuild) ?>" src="<?= e(url('/artist-listening-recordings.js?v=' . $memberHeaderRecordingUiBuild)) ?>"></script>
<script data-chat-transcription-canvas data-transcription-canvas-build="<?= e($memberHeaderTranscriptionBuild) ?>" src="<?= e(url('/chat-transcription-canvas.js?v=' . $memberHeaderTranscriptionBuild)) ?>"></script>
<?php endif; ?>
<?php endif; ?>