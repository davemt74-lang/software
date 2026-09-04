<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? 'Stonefellow';
$pageDescription = $pageDescription ?? 'Stonefellow — music, stories, connection.';
$activePage = $activePage ?? '';
$headerUser = current_user();
$headerNotificationCount = $headerUser ? notification_unread_count($headerUser) : 0;
$headerNotifications = $headerUser ? notification_recent($headerUser, 6) : [];
$headerArtistProfileUrl = artist_workspace_v181_profile_url_for_user($headerUser);

// An existing Artist workspace is authoritative for profile navigation. The
// account-type resolver may legitimately hide an unverified secondary Artist
// role, but that must not make an already-owned public profile unreachable.
if ($headerArtistProfileUrl === '' && $headerUser && (int)($headerUser['id'] ?? 0) > 0) {
    $headerPdo = db();
    if ($headerPdo && function_exists('artist_workspace_v181_schema_ready') && artist_workspace_v181_schema_ready($headerPdo)) {
        try {
            $headerWorkspace = artist_workspace_v181_lookup_public($headerPdo, '', (int)$headerUser['id']);
            if ($headerWorkspace) {
                $headerArtistProfileUrl = artist_workspace_v181_profile_url($headerWorkspace);
            }
        } catch (Throwable $e) {
            // Keep the menu usable if profile lookup fails; the normal account
            // links below remain available.
        }
    }
}

$headerCanManageArtist = $headerUser
    && user_has_role('artist', $headerUser)
    && has_any_permission(['admin.access','tracks.manage','albums.manage','shows.manage','photos.manage','merch.manage','posts.manage','profile.manage'], $headerUser);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#080705">
<meta name="description" content="<?= e($pageDescription) ?>">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= e(url('/styles.css?v=13')) ?>">
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a class="logo" href="<?= e(url($headerUser && has_permission('chat.access', $headerUser) ? '/chat.php' : '/index.php')) ?>" aria-label="Stonefellow home">Stonefellow</a>

    <div class="header-right">
      <nav class="desktop-nav" aria-label="Primary navigation">
        <?php if ($headerUser): ?>
          <?php if (has_permission('chat.access', $headerUser)): ?>
            <a href="<?= e(url('/chat.php')) ?>">Agent Chat</a>
            <a href="<?= e(url('/chat.php?view=player')) ?>">Player</a>
          <?php endif; ?>
          <?php if (has_permission('account.access', $headerUser) && function_exists('artist_workspace_v181_schema_ready') && artist_workspace_v181_schema_ready()): ?><a class="<?= $activePage === 'library' ? 'active' : '' ?>" href="<?= e(url('/my-library.php')) ?>">My Library</a><?php endif; ?>
          <?php if (has_permission('account.access', $headerUser)): ?><a class="<?= $activePage === 'account' ? 'active' : '' ?>" href="<?= e(url('/account.php')) ?>">My Account</a><?php endif; ?>
        <?php else: ?>
          <a class="<?= $activePage === 'home' ? 'active' : '' ?>" href="<?= e(url('/index.php')) ?>">Home</a>
          <a class="<?= $activePage === 'about' ? 'active' : '' ?>" href="<?= e(url('/about.php')) ?>">Artist Bio</a>
          <a class="<?= $activePage === 'shows' ? 'active' : '' ?>" href="<?= e(url('/shows.php')) ?>">Shows</a>
          <a class="<?= $activePage === 'contact' ? 'active' : '' ?>" href="<?= e(url('/contact.php')) ?>">Contact</a>
        <?php endif; ?>
      </nav>

      <?php if ($headerUser && has_permission('account.access', $headerUser)): ?>
        <div class="notification-menu" id="notificationMenu">
          <button class="notification-button" id="notificationButton" type="button" aria-expanded="false" aria-controls="notificationDropdown" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
            </svg>
            <?php if ($headerNotificationCount > 0): ?>
              <span class="notification-badge"><?= $headerNotificationCount > 99 ? '99+' : (int)$headerNotificationCount ?></span>
            <?php endif; ?>
          </button>

          <div class="notification-dropdown" id="notificationDropdown" hidden>
            <div class="notification-dropdown-head">
              <strong>Notifications</strong>
              <?php if ($headerNotificationCount > 0): ?><span><?= (int)$headerNotificationCount ?> unread</span><?php endif; ?>
            </div>

            <div class="notification-dropdown-list">
              <?php foreach ($headerNotifications as $notification): ?>
                <a class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>" href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>">
                  <span class="notification-dot"></span>
                  <span>
                    <strong><?= e($notification['title']) ?></strong>
                    <small><?= e($notification['body']) ?></small>
                  </span>
                </a>
              <?php endforeach; ?>

              <?php if (!$headerNotifications): ?>
                <div class="notification-dropdown-empty">No notifications yet.</div>
              <?php endif; ?>
            </div>

            <a class="notification-dropdown-all" href="<?= e(url('/notifications.php')) ?>">View all notifications →</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($headerUser): ?>
        <div class="user-menu" id="userMenu">
          <button class="user-menu-button" id="userMenuButton" type="button" aria-expanded="false" aria-controls="userMenuDropdown">
            <span class="user-avatar user-avatar-sm">
              <?php if (user_avatar_url($headerUser) !== ''): ?>
                <img src="<?= e(user_avatar_url($headerUser)) ?>" alt="">
              <?php else: ?>
                <span><?= e(user_initials($headerUser)) ?></span>
              <?php endif; ?>
            </span>
            <span class="user-menu-copy">
              <strong><?= e($headerUser['display_name']) ?></strong>
              <small><?= e(role_label((string)$headerUser['role'])) ?></small>
            </span>
            <span class="user-menu-chevron">⌄</span>
          </button>

          <div class="user-menu-dropdown" id="userMenuDropdown" hidden>
            <div class="user-menu-summary">
              <span class="user-avatar">
                <?php if (user_avatar_url($headerUser) !== ''): ?>
                  <img src="<?= e(user_avatar_url($headerUser)) ?>" alt="">
                <?php else: ?>
                  <span><?= e(user_initials($headerUser)) ?></span>
                <?php endif; ?>
              </span>
              <div>
                <strong><?= e($headerUser['display_name']) ?></strong>
                <span><?= e($headerUser['email']) ?></span>
              </div>
            </div>

            <div class="user-menu-links">
              <?php if (has_permission('chat.access')): ?>
                <a href="<?= e(url('/chat.php')) ?>"><span>Stonefellow Chat</span><span>↗</span></a>
              <?php endif; ?>
              <?php if (has_permission('account.access')): ?>
                <a href="<?= e(url('/account.php')) ?>"><span>My Account</span><span>↗</span></a>
                <?php if (function_exists('artist_workspace_v181_schema_ready') && artist_workspace_v181_schema_ready()): ?><a href="<?= e(url('/my-library.php')) ?>"><span>My Library</span><span>↗</span></a><?php endif; ?>
              <?php endif; ?>
              <?php if ($headerCanManageArtist): ?>
                <a href="<?= e(url('/admin/artist.php')) ?>"><span>Artist Admin</span><span>↗</span></a>
              <?php endif; ?>
              <?php if ($headerArtistProfileUrl !== ''): ?>
                <a href="<?= e($headerArtistProfileUrl) ?>"><span>View Artist Profile</span><span>↗</span></a>
              <?php endif; ?>
              <?php if (has_permission('admin.access')): ?>
                <a href="<?= e(url('/admin/index.php')) ?>"><span>Admin Dashboard</span><span>↗</span></a>
              <?php endif; ?>
              <a class="user-menu-logout" href="<?= e(url('/logout.php')) ?>"><span>Log Out</span><span>↗</span></a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <a class="header-login" href="<?= e(url('/login.php')) ?>">Login</a>
      <?php endif; ?>

      <button class="menu-toggle" id="menuToggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mobileNav">
        <span class="menu-lines" aria-hidden="true"></span>
      </button>
    </div>
  </div>
</header>

<div class="mobile-backdrop" id="mobileBackdrop"></div>
<nav class="mobile-nav" id="mobileNav" aria-label="Mobile navigation">
  <?php if ($headerUser): ?>
    <div class="mobile-user-card">
      <span class="user-avatar">
        <?php if (user_avatar_url($headerUser) !== ''): ?>
          <img src="<?= e(user_avatar_url($headerUser)) ?>" alt="">
        <?php else: ?>
          <span><?= e(user_initials($headerUser)) ?></span>
        <?php endif; ?>
      </span>
      <div>
        <strong><?= e($headerUser['display_name']) ?></strong>
        <span><?= e(role_label((string)$headerUser['role'])) ?></span>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($headerUser): ?>
    <?php if (has_permission('chat.access', $headerUser)): ?><a href="<?= e(url('/chat.php')) ?>"><span>Agent Chat</span><span>↗</span></a><a href="<?= e(url('/chat.php?view=player')) ?>"><span>Player</span><span>↗</span></a><?php endif; ?>
    <?php if (has_permission('account.access', $headerUser)): ?><a href="<?= e(url('/account.php')) ?>"><span>My Account</span><span>↗</span></a><?php if (function_exists('artist_workspace_v181_schema_ready') && artist_workspace_v181_schema_ready()): ?><a href="<?= e(url('/my-library.php')) ?>"><span>My Library</span><span>↗</span></a><?php endif; ?><a href="<?= e(url('/notifications.php')) ?>"><span>Notifications<?= $headerNotificationCount > 0 ? ' (' . (int)$headerNotificationCount . ')' : '' ?></span><span>↗</span></a><?php endif; ?>
    <?php if ($headerCanManageArtist): ?><a href="<?= e(url('/admin/artist.php')) ?>"><span>Artist Admin</span><span>↗</span></a><?php endif; ?>
    <?php if ($headerArtistProfileUrl !== ''): ?><a href="<?= e($headerArtistProfileUrl) ?>"><span>View Artist Profile</span><span>↗</span></a><?php endif; ?>
    <?php if (has_permission('producer.access')): ?><a href="<?= e(url('/admin/producer-tracks.php')) ?>"><span>Producer Workspace</span><span>↗</span></a><?php endif; ?>
    <?php if (has_permission('investor.access')): ?><a href="<?= e(url('/investor.php')) ?>"><span>Investor Area</span><span>↗</span></a><?php endif; ?>
    <?php if (has_permission('admin.access')): ?><a href="<?= e(url('/admin/index.php')) ?>"><span>Admin</span><span>↗</span></a><?php endif; ?>
    <a href="<?= e(url('/logout.php')) ?>"><span>Log Out</span><span>↗</span></a>
  <?php else: ?>
    <a href="<?= e(url('/index.php')) ?>"><span>Home</span><span>↗</span></a>
    <a href="<?= e(url('/about.php')) ?>"><span>Artist Bio</span><span>↗</span></a>
    <a href="<?= e(url('/shows.php')) ?>"><span>Shows</span><span>↗</span></a>
    <a href="<?= e(url('/contact.php')) ?>"><span>Contact</span><span>↗</span></a>
    <a href="<?= e(url('/login.php')) ?>"><span>Login</span><span>↗</span></a>
  <?php endif; ?>
</nav>
