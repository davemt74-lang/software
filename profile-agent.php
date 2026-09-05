<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();
if (!$pdo || !$user) {
    redirect(url('/login.php'));
}
if (!profile_agent_schema_ready($pdo) || !user_agent_system_schema_ready_v236($pdo)) {
    redirect(url('/upgrade.php'));
}

$menuLinks = member_navigation_menu_links($user);
$notificationCount = notification_unread_count($user);
$notifications = notification_recent($user, 6);
$profile = profile_for_user($pdo, (int)$user['id'], true) ?: [];
if (empty($profile['username'])) {
    $profile = profile_migrate_artist_identity($pdo, $user);
}
$profileUrl = !empty($profile['username']) ? profile_public_url((string)$profile['username']) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f8fa">
<title>Profile Agent | <?= e(system_agent_name()) ?></title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-header-ui.css?v=white-tech-20260904')) ?>">
<link rel="stylesheet" href="<?= e(url('/profile-agent-portal.css?v=profile-media-20260905')) ?>">
</head>
<body>
<div class="chat-app profile-agent-app">
  <aside class="chat-sidebar profile-agent-sidebar" id="chatSidebar">
    <div class="profile-agent-sidebar-head">
      <a class="profile-agent-sidebar-brand" href="<?= e(url('/profile-agent.php')) ?>"><?= e(system_agent_name()) ?></a>
      <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close Profile Agent menu">×</button>
    </div>

    <div class="profile-agent-sidebar-service">
      <span>Profile Agent</span>
      <div class="profile-agent-service-status" id="profileAgentServiceStatus" aria-live="polite">Checking service…</div>
    </div>

    <nav class="profile-agent-sidebar-nav" aria-label="Profile Agent sections">
      <button type="button" data-pa-tab="inbox" class="active"><span>01</span><strong>Inbox</strong></button>
      <button type="button" data-pa-tab="visitors"><span>02</span><strong>Visitors</strong></button>
      <button type="button" data-pa-tab="agent"><span>03</span><strong>Agent</strong></button>
      <button type="button" data-pa-tab="knowledge"><span>04</span><strong>Knowledge Access</strong></button>
      <button type="button" data-pa-tab="profile"><span>05</span><strong>Profile Settings</strong></button>
      <button type="button" data-pa-tab="analytics"><span>06</span><strong>Analytics</strong></button>
    </nav>

    <div class="profile-agent-sidebar-footer">
      <?php if ($profileUrl !== ''): ?><a href="<?= e($profileUrl) ?>" target="_blank" rel="noopener">View Profile ↗</a><?php endif; ?>
      <a href="<?= e(url('/account.php')) ?>">My Account</a>
      <?php if (has_permission('chat.access', $user)): ?><a href="<?= e(url('/chat.php')) ?>">Agent Chat</a><?php endif; ?>
    </div>
  </aside>

  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main profile-agent-main">
    <header class="chat-topbar profile-agent-topbar">
      <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button>
      <div class="chat-topbar-title">
        <strong>Profile Agent</strong>
        <span>Visitor conversations + customer service</span>
      </div>
      <div class="chat-topbar-actions">
        <?php if ($profileUrl !== ''): ?>
          <a class="profile-agent-view-profile" href="<?= e($profileUrl) ?>" target="_blank" rel="noopener">View Profile ↗</a>
        <?php endif; ?>
        <div class="chat-top-menu" id="chatNotificationMenu">
          <button class="chat-notification-link" id="chatNotificationButton" type="button" aria-label="Notifications" aria-expanded="false" aria-controls="chatNotificationDropdown">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            <?php if ($notificationCount > 0): ?><span><?= $notificationCount > 99 ? '99+' : (int)$notificationCount ?></span><?php endif; ?>
          </button>
          <div class="chat-top-dropdown chat-notification-dropdown" id="chatNotificationDropdown" hidden>
            <header><strong>Notifications</strong><span><?= (int)$notificationCount ?> unread</span></header>
            <div class="chat-notification-dropdown-list">
              <?php foreach ($notifications as $notification): ?>
                <a class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>" href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>">
                  <span class="chat-dropdown-dot"></span><span><strong><?= e((string)$notification['title']) ?></strong><small><?= e((string)$notification['body']) ?></small></span>
                </a>
              <?php endforeach; ?>
              <?php if (!$notifications): ?><div class="chat-dropdown-empty">No notifications yet.</div><?php endif; ?>
            </div>
            <a class="chat-dropdown-all" href="<?= e(url('/notifications.php')) ?>">View all notifications →</a>
          </div>
        </div>

        <div class="chat-top-menu" id="chatProfileMenu">
          <button type="button" class="chat-top-avatar" id="chatProfileButton" aria-label="User menu" aria-expanded="false" aria-controls="chatProfileDropdown">
            <?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><?= e(user_initials($user)) ?><?php endif; ?>
          </button>
          <div class="chat-top-dropdown chat-profile-dropdown" id="chatProfileDropdown" hidden>
            <div class="chat-profile-summary">
              <span class="chat-avatar"><?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?></span>
              <div><strong><?= e((string)$user['display_name']) ?></strong><small><?= e(role_label((string)$user['role'])) ?></small></div>
            </div>
            <nav class="chat-profile-links">
              <?php foreach ($menuLinks as $menuLink): ?>
                <a<?= !empty($menuLink['danger']) ? ' class="logout"' : '' ?> href="<?= e((string)$menuLink['url']) ?>"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
              <?php endforeach; ?>
            </nav>
          </div>
        </div>
      </div>
    </header>

    <section class="profile-agent-portal" id="profileAgentPortal">

      <div class="profile-agent-metrics" id="profileAgentMetrics" aria-label="Profile Agent metrics"></div>


      <div class="profile-agent-notice" id="profileAgentNotice" role="status" aria-live="polite"></div>

      <section class="profile-agent-view active" data-pa-view="inbox">
        <div class="profile-agent-inbox-layout">
          <div class="profile-agent-inbox-list">
            <div class="profile-agent-section-head"><div><span>Inbox</span><h2>Visitor conversations</h2></div><button type="button" id="profileAgentRefresh">Refresh</button></div>
            <div id="profileAgentAttention"></div>
            <div id="profileAgentConversations"></div>
          </div>
          <aside class="profile-agent-thread" id="profileAgentThread">
            <div class="profile-agent-thread-empty"><strong>Select a conversation</strong><span>Open a visitor thread to review messages and reply as the profile owner.</span></div>
          </aside>
        </div>
      </section>

      <section class="profile-agent-view" data-pa-view="visitors">
        <div class="profile-agent-panel">
          <div class="profile-agent-section-head"><div><span>Visitors</span><h2>Recent profile activity</h2></div></div>
          <div class="profile-agent-visitor-list" id="profileAgentVisitors"></div>
        </div>
      </section>

      <section class="profile-agent-view" data-pa-view="agent">
        <div class="profile-agent-panel" id="profileAgentSettings"></div>
      </section>

      <section class="profile-agent-view" data-pa-view="knowledge">
        <div class="profile-agent-panel" id="profileAgentKnowledge"></div>
      </section>

      <section class="profile-agent-view" data-pa-view="profile">
        <div class="profile-agent-panel" id="profileAgentProfileSettings"></div>
      </section>

      <section class="profile-agent-view" data-pa-view="analytics">
        <div class="profile-agent-panel" id="profileAgentAnalytics"></div>
      </section>
    </section>
  </main>
</div>

<script>
window.PROFILE_AGENT_PORTAL = <?= json_encode([
    'endpoint' => url('/api/profile-agent.php'),
    'csrf' => csrf_token(),
    'profileUrl' => $profileUrl,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/member-shell-v77.js?v=profile-activity-20260905')) ?>"></script>
<script src="<?= e(url('/profile-agent-portal.js?v=profile-media-20260905')) ?>"></script>
</body>
</html>
