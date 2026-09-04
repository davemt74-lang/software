<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

$adminTitle = $adminTitle ?? 'Dashboard';
$adminActive = $adminActive ?? '';
$adminCanvasMode = !empty($adminCanvasMode);
$user = current_user();
$adminShellLabel = user_has_role('admin', $user)
    ? 'Stonefellow Admin'
    : (user_has_role('artist', $user) ? 'Stonefellow Artist' : 'Stonefellow Admin');
$adminRoleSummary = implode(' · ', user_role_labels($user));
$notice = flash('notice');
$errorNotice = flash('error');
$adminNotificationCount = notification_unread_count($user);
$adminNotifications = notification_recent($user, 6);
$adminUnreadMessages = 0;
if (has_permission('messages.manage') && db()) {
    try { $adminUnreadMessages = (int)db()->query('SELECT COUNT(*) FROM contact_messages WHERE is_read=0')->fetchColumn(); } catch (Throwable $e) {}
}
$adminCrmNew = 0;
$adminCrmVisible = function_exists('crm_v180_can_manage') && crm_v180_can_manage($user);
if ($adminCrmVisible && function_exists('crm_v180_schema_ready') && crm_v180_schema_ready() && db()) {
    try { $adminCrmNew = (int)db()->query("SELECT COUNT(*) FROM crm_leads WHERE stage='new'")->fetchColumn(); } catch (Throwable $e) {}
}
$isArtistAdmin = user_has_role('artist', $user);
$adminArtistProfileUrl = artist_workspace_v181_profile_url_for_user($user);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7">
<title><?= e($adminTitle) ?> | <?= e($adminShellLabel) ?></title>
<link rel="stylesheet" href="<?= e(url('/admin/admin.css?v=77')) ?>">
<link data-admin-tech-theme rel="stylesheet" href="<?= e(url('/admin/admin-tech.css?v=admin-tech-20260903')) ?>">
</head>
<body class="<?= $adminCanvasMode ? 'admin-canvas-mode' : '' ?>">

<?php if (!$adminCanvasMode): ?>
<header class="admin-mobile-bar">
  <button class="admin-mobile-menu" id="adminMenuToggle" type="button" aria-label="Open admin navigation" aria-expanded="false">☰</button>
  <a class="admin-mobile-brand" href="<?= e(url('/index.php')) ?>">Stonefellow</a>
  <button class="admin-mobile-user" id="adminMobileUserButton" type="button" aria-label="Open user menu">
    <?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?>
  </button>
</header>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<?php endif; ?>

<div class="admin-layout<?= $adminCanvasMode ? ' admin-layout-canvas' : '' ?>">
  <?php if (!$adminCanvasMode): ?>
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="admin-sidebar-head"><a class="admin-brand" href="<?= e(url('/index.php')) ?>">Stonefellow</a><button class="admin-sidebar-close" id="adminSidebarClose" type="button" aria-label="Close navigation">×</button></div>
    <div class="admin-user-summary"><span class="admin-avatar admin-avatar-md"><?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?></span><div><strong><?= e($user['display_name'] ?? '') ?></strong><span><?= e($adminRoleSummary) ?></span></div></div>
    <?php if (has_permission('chat.access')): ?><a class="admin-agent-button" href="<?= e(url('/chat.php')) ?>"><span class="admin-agent-icon">✦</span><span><strong>Agent Chat</strong><small>Database + knowledge</small></span><span class="admin-agent-arrow">↗</span></a><?php endif; ?>
    <div class="admin-nav-label">Management</div>
    <nav class="admin-navigation" aria-label="Admin navigation">
      <?php if (has_permission('admin.access')): ?><a class="<?= $adminActive === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('/admin/index.php')) ?>"><span>Dashboard</span></a><?php endif; ?>
      <?php if ($isArtistAdmin && has_permission('admin.access')): ?><a class="<?= $adminActive === 'artist-workspace' ? 'active' : '' ?>" href="<?= e(url('/admin/artist.php')) ?>"><span>Artist Workspace</span></a><?php endif; ?>
      <?php if (has_permission('tracks.manage')): ?><a class="<?= $adminActive === 'tracks' ? 'active' : '' ?>" href="<?= e(url('/admin/tracks.php')) ?>"><span>Tracks</span></a><?php endif; ?>
      <?php if (has_permission('albums.manage')): ?><a class="<?= $adminActive === 'albums' ? 'active' : '' ?>" href="<?= e(url('/admin/albums.php')) ?>"><span>Albums</span></a><?php endif; ?>
      <?php if (permission_v105_has('release.manage')): ?><a class="<?= $adminActive === 'releases' ? 'active' : '' ?>" href="<?= e(url('/admin/releases.php')) ?>"><span>Release Calendar</span></a><?php endif; ?>
      <?php if (permission_v105_has('credits.manage') || has_permission('producer.access') || has_permission('tracks.manage')): ?><a class="<?= $adminActive === 'credits' ? 'active' : '' ?>" href="<?= e(url('/admin/credits.php')) ?>"><span>Credits Graph</span></a><?php endif; ?>
      <?php if (has_permission('producer.access')): ?><a class="<?= $adminActive === 'producer-tracks' ? 'active' : '' ?>" href="<?= e(url('/admin/producer-tracks.php')) ?>"><span>Shared Tracks</span></a><?php endif; ?>
      <?php if (has_permission('listening.view')): ?><a class="<?= $adminActive === 'listening' ? 'active' : '' ?>" href="<?= e(url('/admin/listening.php')) ?>"><span>Listening Analytics</span></a><?php endif; ?>
      <?php if (has_permission('shows.manage')): ?><a class="<?= $adminActive === 'shows' ? 'active' : '' ?>" href="<?= e(url($isArtistAdmin?'/admin/artist-shows.php':'/admin/shows.php')) ?>"><span>Shows</span></a><?php endif; ?>
      <?php if (has_permission('photos.manage')): ?><a class="<?= $adminActive === 'photos' ? 'active' : '' ?>" href="<?= e(url($isArtistAdmin?'/admin/artist-media.php':'/admin/photos.php')) ?>"><span>Photos</span></a><?php endif; ?>
      <?php if (has_permission('merch.manage')): ?><a class="<?= $adminActive === 'merch' ? 'active' : '' ?>" href="<?= e(url('/admin/merch.php')) ?>"><span>Merch</span></a><?php endif; ?>
      <?php if (has_permission('posts.manage')): ?><a class="<?= $adminActive === 'posts' ? 'active' : '' ?>" href="<?= e(url($isArtistAdmin?'/admin/artist-posts.php':'/admin/posts.php')) ?>"><span>Posts</span></a><?php endif; ?>
      <?php if (has_permission('listening.view')): ?><a class="<?= $adminActive === 'analytics' ? 'active' : '' ?>" href="<?= e(url('/admin/analytics.php')) ?>"><span>Music Analytics</span></a><?php endif; ?>
      <?php if (has_permission('knowledge.manage')): ?><a class="<?= $adminActive === 'knowledge' ? 'active' : '' ?>" href="<?= e(url('/admin/knowledge.php')) ?>"><span>Knowledge</span></a><?php endif; ?>
      <?php if ($adminCrmVisible): ?><a class="<?= $adminActive === 'crm' ? 'active' : '' ?>" href="<?= e(url('/admin/crm.php')) ?>"><span>CRM</span><?php if ($adminCrmNew > 0): ?><span class="admin-nav-count"><?= $adminCrmNew > 99 ? '99+' : (int)$adminCrmNew ?></span><?php endif; ?></a><?php endif; ?>
      <?php if (has_permission('messages.manage')): ?><a class="<?= $adminActive === 'messages' ? 'active' : '' ?>" href="<?= e(url('/admin/messages.php')) ?>"><span>Messages</span><?php if ($adminUnreadMessages > 0): ?><span class="admin-nav-count"><?= $adminUnreadMessages > 99 ? '99+' : (int)$adminUnreadMessages ?></span><?php endif; ?></a><?php endif; ?>
      <?php if (has_permission('profile.manage')): ?><a class="<?= $adminActive === 'profile' ? 'active' : '' ?>" href="<?= e(url('/admin/profile.php')) ?>"><span>Artist / Links</span></a><?php endif; ?>
      <?php if ($isArtistAdmin && has_permission('team.manage', $user)): ?><a class="<?= $adminActive === 'team' ? 'active' : '' ?>" href="<?= e(url('/admin/team.php')) ?>"><span>Team</span></a><?php endif; ?>
      <?php if (has_permission('users.manage')): ?><a class="<?= $adminActive === 'users' ? 'active' : '' ?>" href="<?= e(url('/admin/users.php')) ?>"><span>Users</span></a><?php endif; ?>
      <?php if (has_permission('ai.manage')): ?><a class="<?= $adminActive === 'ai' ? 'active' : '' ?>" href="<?= e(url('/admin/ai.php')) ?>"><span>AI / API</span></a><a class="<?= $adminActive === 'ai-data-usage' ? 'active' : '' ?>" href="<?= e(url('/admin/ai-data-usage-v236.php')) ?>"><span>AI Data Usage</span></a><?php endif; ?>
      <?php if (has_permission('permissions.manage')): ?><a class="<?= $adminActive === 'permissions' ? 'active' : '' ?>" href="<?= e(url('/admin/permissions.php')) ?>"><span>Permissions</span></a><?php endif; ?>
    </nav>
    <div class="admin-sidebar-bottom"><?php if (has_permission('chat.access', $user)): ?><a href="<?= e(url('/chat.php?view=player')) ?>">Player</a><?php endif; ?><a href="<?= e(url('/index.php')) ?>">View Website</a></div>
  </aside>
  <?php endif; ?>

  <main class="admin-content<?= $adminCanvasMode ? ' admin-content-canvas' : '' ?>">
    <?php if (!$adminCanvasMode): ?>
    <div class="admin-page-top">
      <div class="admin-page-title"><span><?= e($adminShellLabel) ?></span><h1><?= e($adminTitle) ?></h1></div>
      <div class="admin-notification-menu" id="adminNotificationMenu">
        <button class="admin-notification-button" id="adminNotificationButton" type="button" aria-expanded="false" aria-controls="adminNotificationDropdown" aria-label="Notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if ($adminNotificationCount > 0): ?><span class="admin-notification-badge"><?= $adminNotificationCount > 99 ? '99+' : (int)$adminNotificationCount ?></span><?php endif; ?></button>
        <div class="admin-notification-dropdown" id="adminNotificationDropdown" hidden><div class="admin-notification-head"><strong>Notifications</strong><?php if ($adminNotificationCount > 0): ?><span><?= (int)$adminNotificationCount ?> unread</span><?php endif; ?></div><div class="admin-notification-list"><?php foreach ($adminNotifications as $notification): ?><a class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>" href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>"><span class="admin-notification-dot"></span><span><strong><?= e($notification['title']) ?></strong><small><?= e($notification['body']) ?></small></span></a><?php endforeach; ?><?php if (!$adminNotifications): ?><div class="admin-notification-empty">No notifications yet.</div><?php endif; ?></div><a class="admin-notification-all" href="<?= e(url('/notifications.php')) ?>">View all notifications →</a></div>
      </div>
      <div class="admin-user-menu" id="adminUserMenu">
        <button class="admin-user-menu-button" id="adminUserMenuButton" type="button" aria-expanded="false" aria-controls="adminUserDropdown"><span class="admin-avatar admin-avatar-sm"><?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?></span><span class="admin-user-menu-copy"><strong><?= e($user['display_name'] ?? '') ?></strong><small><?= e($adminRoleSummary) ?></small></span><span class="admin-user-chevron">⌄</span></button>
        <div class="admin-user-dropdown" id="adminUserDropdown" hidden><div class="admin-user-dropdown-head"><span class="admin-avatar admin-avatar-lg"><?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?></span><div><strong><?= e($user['display_name'] ?? '') ?></strong><span><?= e($user['email'] ?? '') ?></span></div></div><nav class="admin-user-dropdown-links"><?php if (has_permission('account.access')): ?><a href="<?= e(url('/account.php')) ?>"><span>My Account</span><span>↗</span></a><?php endif; ?><?php if (has_permission('chat.access')): ?><a href="<?= e(url('/chat.php')) ?>"><span>Agent Chat</span><span>↗</span></a><?php endif; ?><?php if (has_permission('chat.access')): ?><a href="<?= e(url('/chat.php?view=player')) ?>"><span>Player</span><span>↗</span></a><?php endif; ?><?php if ($adminArtistProfileUrl !== ''): ?><a href="<?= e($adminArtistProfileUrl) ?>"><span>View Artist Profile</span><span>↗</span></a><?php endif; ?><a href="<?= e(url('/index.php')) ?>"><span>View Website</span><span>↗</span></a><a class="admin-user-logout" href="<?= e(url('/logout.php')) ?>"><span>Log Out</span><span>↗</span></a></nav></div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($notice): ?><div class="notice notice-auto-dismiss" data-auto-dismiss="2600" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($errorNotice): ?><div class="notice error"><?= e($errorNotice) ?></div><?php endif; ?>