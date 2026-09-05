<?php
declare(strict_types=1);

$memberHeaderUser = $memberHeaderUser ?? current_user();
if (!$memberHeaderUser) return;

$memberHeaderTitle = trim((string)($memberHeaderTitle ?? ''));
$memberHeaderSubtitle = trim((string)($memberHeaderSubtitle ?? ''));
$memberHeaderActions = (string)($memberHeaderActions ?? '');
$memberHeaderClass = trim((string)($memberHeaderClass ?? ''));
$memberHeaderNotificationCount = notification_unread_count($memberHeaderUser);
$memberHeaderNotifications = notification_recent($memberHeaderUser, 6);
?>
<header class="chat-topbar member-header<?= $memberHeaderClass !== '' ? ' ' . e($memberHeaderClass) : '' ?>" data-member-header>
  <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button>

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
