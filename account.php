<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');

$pdo = db();
$user = current_user();

if (!$pdo || !$user) {
    flash('error', 'Your account could not be loaded.');
    redirect(url('/login.php'));
}

if (!column_exists('users', 'avatar_path')) {
    redirect(url('/upgrade.php'));
}

$accountArtistProfileUrl = artist_workspace_v181_profile_url_for_user($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('account_error', 'Your session expired. Please try again.');
        redirect(url('/account.php'));
    }

    try {
        $action = (string)($_POST['action'] ?? 'profile');

        if ($action === 'profile') {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));

            if ($displayName === '') {
                throw new RuntimeException('Display name is required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid email address.');
            }

            $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, (int)$user['id']]);
            if ($duplicate->fetch()) {
                throw new RuntimeException('That email address is already being used.');
            }

            $avatarPath = (string)($user['avatar_path'] ?? '');

            if (!empty($_POST['remove_avatar'])) {
                delete_local_upload($avatarPath);
                $avatarPath = '';
            }

            global $config;
            $avatarUpload = upload_file(
                $_FILES['avatar_file'] ?? [],
                ['jpg','jpeg','png','webp'],
                ['image/jpeg','image/png','image/webp'],
                (int)($config['uploads']['max_image_bytes'] ?? 5242880),
                'avatars'
            );

            if ($avatarUpload) {
                delete_local_upload($avatarPath);
                $avatarPath = $avatarUpload;
            }

            $stmt = $pdo->prepare('UPDATE users SET display_name=?, email=?, avatar_path=? WHERE id=?');
            $stmt->execute([$displayName, $email, $avatarPath, (int)$user['id']]);
            reset_current_user_cache();
            flash('account_notice', 'Profile updated.');
        }

        if ($action === 'soul_save') {
            agent_brain_save_soul($user, (string)($_POST['soul_content'] ?? ''));
            flash('account_notice', 'Agent SOUL.md updated.');
            redirect(url('/account.php#agent-brain'));
        }

        if ($action === 'soul_reset') {
            agent_brain_reset_soul($user);
            flash('account_notice', 'Agent SOUL.md reset to the Stonefellow default.');
            redirect(url('/account.php#agent-brain'));
        }

        if ($action === 'password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
            $stmt->execute([(int)$user['id']]);
            $hash = (string)$stmt->fetchColumn();

            if (!password_verify($currentPassword, $hash)) {
                throw new RuntimeException('Your current password is incorrect.');
            }
            if (strlen($newPassword) < 12) {
                throw new RuntimeException('The new password must contain at least 12 characters.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('The new passwords do not match.');
            }

            $stmt = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
            flash('account_notice', 'Password updated.');
        }
    } catch (Throwable $e) {
        flash('account_error', $e->getMessage());
    }

    redirect(url('/account.php'));
}


$user = current_user();
$notice = flash('account_notice');
$error = flash('account_error');


$accountNotificationCount = notification_unread_count($user);
$accountNotifications = notification_recent($user, 6);
$accountSoul = agent_brain_soul($user);
$accountBrain = agent_brain_summary($user);
if (agent_brain_schema_ready() && (int)$accountBrain['archive_count'] === 0) {
    agent_brain_backfill_user($user, 500);
    $accountBrain = agent_brain_summary($user);
}
$accountBrainTools = agent_brain_tools($user);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0a09">
<title>Stonefellow | My Account</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
</head>
<body>
<div class="chat-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'account';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>

  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main account-chat-main">
    <header class="chat-topbar">
      <button
        class="chat-icon-button mobile-only"
        id="openChatSidebar"
        type="button"
        aria-label="Open account menu"
      >☰</button>

      <div class="chat-topbar-title">
        <strong>My Account</strong>
        <span>Profile + security + Agent Brain</span>
      </div>

      <div class="chat-topbar-actions">
        <div class="chat-top-menu" id="chatNotificationMenu">
          <button
            class="chat-notification-link"
            id="chatNotificationButton"
            type="button"
            aria-label="Notifications"
            aria-expanded="false"
            aria-controls="chatNotificationDropdown"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>
            </svg>
            <?php if ($accountNotificationCount > 0): ?>
              <span><?= $accountNotificationCount > 99 ? '99+' : (int)$accountNotificationCount ?></span>
            <?php endif; ?>
          </button>

          <div
            class="chat-top-dropdown chat-notification-dropdown"
            id="chatNotificationDropdown"
            hidden
          >
            <header>
              <strong>Notifications</strong>
              <span><?= (int)$accountNotificationCount ?> unread</span>
            </header>

            <div class="chat-notification-dropdown-list">
              <?php foreach ($accountNotifications as $notification): ?>
                <a
                  class="<?= !(int)$notification['is_read'] ? 'unread' : '' ?>"
                  href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>"
                >
                  <span class="chat-dropdown-dot"></span>
                  <span>
                    <strong><?= e((string)$notification['title']) ?></strong>
                    <small><?= e((string)$notification['body']) ?></small>
                  </span>
                </a>
              <?php endforeach; ?>

              <?php if (!$accountNotifications): ?>
                <div class="chat-dropdown-empty">No notifications yet.</div>
              <?php endif; ?>
            </div>

            <a class="chat-dropdown-all" href="<?= e(url('/notifications.php')) ?>">
              View all notifications →
            </a>
          </div>
        </div>

        <div class="chat-top-menu" id="chatProfileMenu">
          <button
            type="button"
            class="chat-top-avatar"
            id="chatProfileButton"
            aria-label="User menu"
            aria-expanded="false"
            aria-controls="chatProfileDropdown"
          >
            <?php if (user_avatar_url($user) !== ''): ?>
              <img src="<?= e(user_avatar_url($user)) ?>" alt="">
            <?php else: ?>
              <?= e(user_initials($user)) ?>
            <?php endif; ?>
          </button>

          <div
            class="chat-top-dropdown chat-profile-dropdown"
            id="chatProfileDropdown"
            hidden
          >
            <div class="chat-profile-summary">
              <span class="chat-avatar">
                <?php if (user_avatar_url($user) !== ''): ?>
                  <img src="<?= e(user_avatar_url($user)) ?>" alt="">
                <?php else: ?>
                  <span><?= e(user_initials($user)) ?></span>
                <?php endif; ?>
              </span>
              <div>
                <strong><?= e((string)$user['display_name']) ?></strong>
                <small><?= e(role_label((string)$user['role'])) ?></small>
              </div>
            </div>

            <nav class="chat-profile-links">
              <?php if (has_permission('chat.access', $user)): ?><a href="<?= e(url('/chat.php')) ?>"><span>Agent Chat</span><span>↗</span></a><a href="<?= e(url('/chat.php?view=player')) ?>"><span>Player</span><span>↗</span></a><?php endif; ?>
              <?php if ($accountArtistProfileUrl !== ''): ?><a href="<?= e($accountArtistProfileUrl) ?>"><span>View Artist Profile</span><span>↗</span></a><?php endif; ?>
              <?php if (has_permission('admin.access')): ?>
                <a href="<?= e(url('/admin/index.php')) ?>"><span>Admin Dashboard</span><span>↗</span></a>
              <?php endif; ?>
              <a class="logout" href="<?= e(url('/logout.php')) ?>"><span>Log Out</span><span>↗</span></a>
            </nav>
          </div>
        </div>
      </div>
    </header>

    <section class="account-canvas">
      <div class="account-canvas-inner">
        <section class="account-canvas-hero">
          <div class="account-canvas-user">
            <span class="account-canvas-avatar">
              <?php if (user_avatar_url($user) !== ''): ?>
                <img src="<?= e(user_avatar_url($user)) ?>" alt="<?= e((string)$user['display_name']) ?>">
              <?php else: ?>
                <?= e(user_initials($user)) ?>
              <?php endif; ?>
            </span>

            <div>
              <span>My Account</span>
              <h1><?= e((string)$user['display_name']) ?></h1>
              <div class="account-canvas-meta">
                <span><?= e(role_label((string)$user['role'])) ?></span>
                <span><?= e((string)$user['email']) ?></span>
              </div>
            </div>
          </div>

          <div class="account-canvas-actions">
            <?php if (has_permission('chat.access', $user)): ?><a class="account-shell-button primary" href="<?= e(url('/chat.php')) ?>">Agent Chat</a><a class="account-shell-button" href="<?= e(url('/chat.php?view=player')) ?>">Player</a><?php endif; ?>
          </div>
        </section>

        <?php if ($notice): ?>
          <div class="account-alert success"><?= e($notice) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="account-alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="account-canvas-grid">
          <nav class="account-canvas-nav" aria-label="Account sections">
            <a href="#profile">Profile & Photo</a>
            <a href="#security">Security</a>
            <a href="#agent-brain">Agent Brain</a>
            <a href="#access">Your Access</a>
          </nav>

          <div class="account-canvas-content">
            <section class="account-panel" id="profile">
              <div class="account-panel-head">
                <span>Profile</span>
                <h2>Profile & Photo</h2>
                <p>Update how your Stonefellow account appears inside the authenticated workspace.</p>
              </div>

              <form method="post" enctype="multipart/form-data" class="account-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">

                <div class="account-avatar-controls">
                  <span class="account-canvas-avatar account-avatar-small">
                    <?php if (user_avatar_url($user) !== ''): ?>
                      <img src="<?= e(user_avatar_url($user)) ?>" alt="">
                    <?php else: ?>
                      <?= e(user_initials($user)) ?>
                    <?php endif; ?>
                  </span>

                  <div>
                    <label class="account-file-button" for="avatar_file">Choose Profile Photo</label>
                    <input
                      id="avatar_file"
                      class="account-hidden-file"
                      name="avatar_file"
                      type="file"
                      accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    >
                    <small>JPG, PNG or WEBP · Maximum 5 MB</small>

                    <?php if (user_avatar_url($user) !== ''): ?>
                      <label class="account-remove-photo">
                        <input type="checkbox" name="remove_avatar" value="1">
                        Remove current photo
                      </label>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="account-fields">
                  <div class="account-field">
                    <label for="display_name">Display Name</label>
                    <input
                      id="display_name"
                      name="display_name"
                      maxlength="120"
                      required
                      value="<?= e((string)$user['display_name']) ?>"
                    >
                  </div>

                  <div class="account-field">
                    <label for="email">Email Address</label>
                    <input
                      id="email"
                      name="email"
                      type="email"
                      maxlength="190"
                      required
                      value="<?= e((string)$user['email']) ?>"
                    >
                  </div>
                </div>

                <button class="account-submit" type="submit">Save Profile</button>
              </form>
            </section>

            <section class="account-panel" id="security">
              <div class="account-panel-head">
                <span>Security</span>
                <h2>Change Password</h2>
                <p>Use at least 12 characters for your new password.</p>
              </div>

              <form method="post" class="account-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">

                <div class="account-fields">
                  <div class="account-field full">
                    <label for="current_password">Current Password</label>
                    <input
                      id="current_password"
                      name="current_password"
                      type="password"
                      autocomplete="current-password"
                      required
                    >
                  </div>

                  <div class="account-field">
                    <label for="new_password">New Password</label>
                    <input
                      id="new_password"
                      name="new_password"
                      type="password"
                      minlength="12"
                      autocomplete="new-password"
                      required
                    >
                  </div>

                  <div class="account-field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                      id="confirm_password"
                      name="confirm_password"
                      type="password"
                      minlength="12"
                      autocomplete="new-password"
                      required
                    >
                  </div>
                </div>

                <button class="account-submit" type="submit">Update Password</button>
              </form>
            </section>


            <section class="account-panel agent-brain-panel" id="agent-brain">
              <div class="account-panel-head">
                <span>Agent Brain</span>
                <h2>Your SOUL.md & Memory</h2>
                <p>Every Stonefellow account starts with the default soul. This private copy controls your agent's character, communication style and working preferences; server permissions always remain authoritative.</p>
              </div>

              <div class="agent-brain-stats">
                <article><strong><?= number_format((int)$accountBrain['archive_count']) ?></strong><span>Archived messages</span></article>
                <article><strong><?= number_format((int)$accountBrain['memory_count']) ?></strong><span>Memory items</span></article>
                <article><strong><?= number_format(count($accountBrainTools)) ?></strong><span>Available tools</span></article>
              </div>

              <form method="post" class="account-form agent-soul-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="soul_save">
                <div class="account-field full">
                  <label for="soul_content">Private SOUL.md</label>
                  <textarea id="soul_content" name="soul_content" rows="24" maxlength="24000" spellcheck="true"><?= e($accountSoul) ?></textarea>
                  <small>Character and working style only. Security, permissions and data-access rules cannot be changed here.</small>
                </div>
                <div class="agent-soul-actions">
                  <button class="account-submit" type="submit">Save SOUL.md</button>
                </div>
              </form>

              <form method="post" class="agent-soul-reset" onsubmit="return confirm('Reset your private SOUL.md to the Stonefellow default?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="soul_reset">
                <button type="submit">Reset to Stonefellow Default</button>
              </form>

              <div class="agent-brain-memory-grid">
                <section>
                  <header><strong>Recurring Themes</strong><span>Repeated topics become stronger memories.</span></header>
                  <?php foreach ($accountBrain['themes'] as $memory): ?>
                    <div class="agent-memory-row"><strong><?= e((string)$memory['subject']) ?></strong><span><?= (int)$memory['occurrence_count'] ?>×</span></div>
                  <?php endforeach; ?>
                  <?php if (!$accountBrain['themes']): ?><p class="agent-memory-empty">No recurring themes extracted yet.</p><?php endif; ?>
                </section>
                <section>
                  <header><strong>Dates & Commitments</strong><span>Dates mentioned in chat are indexed for recall.</span></header>
                  <?php foreach ($accountBrain['dates'] as $memory): ?>
                    <div class="agent-memory-row"><strong><?= e((string)$memory['subject']) ?></strong><span><?= e(date('M j', strtotime((string)$memory['last_seen_at']))) ?></span></div>
                  <?php endforeach; ?>
                  <?php if (!$accountBrain['dates']): ?><p class="agent-memory-empty">No dates extracted yet.</p><?php endif; ?>
                </section>
                <section>
                  <header><strong>Files</strong><span>File references from typed and voice transcripts.</span></header>
                  <?php foreach ($accountBrain['files'] as $memory): ?>
                    <div class="agent-memory-row"><strong><?= e((string)$memory['subject']) ?></strong><span><?= (int)$memory['occurrence_count'] ?>×</span></div>
                  <?php endforeach; ?>
                  <?php if (!$accountBrain['files']): ?><p class="agent-memory-empty">No file references extracted yet.</p><?php endif; ?>
                </section>
                <section>
                  <header><strong>Recent Memory</strong><span>Preferences, decisions and commitments.</span></header>
                  <?php foreach ($accountBrain['recent'] as $memory): ?>
                    <div class="agent-memory-detail"><span><?= e(ucfirst((string)$memory['memory_type'])) ?></span><strong><?= e((string)$memory['memory_text']) ?></strong></div>
                  <?php endforeach; ?>
                  <?php if (!$accountBrain['recent']): ?><p class="agent-memory-empty">Conversation memory will appear here as you use Agent Chat.</p><?php endif; ?>
                </section>
              </div>

              <div class="agent-brain-tools">
                <header><span>Capability Registry</span><h3>Tools available to your agent</h3></header>
                <div class="agent-brain-tool-grid">
                  <?php foreach ($accountBrainTools as $tool): ?>
                    <a href="<?= e((string)$tool['url']) ?>">
                      <small><?= e(ucfirst((string)$tool['kind'])) ?></small>
                      <strong><?= e((string)$tool['label']) ?></strong>
                      <p><?= e((string)$tool['description']) ?></p>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>

            <section class="account-panel" id="access">
              <div class="account-panel-head">
                <span>Permissions</span>
                <h2>Your Access</h2>
                <p>Authenticated tools available to this account type.</p>
              </div>

              <div class="account-access-grid">
                <?php if (has_permission('chat.access', $user)): ?><a class="account-access-card" href="<?= e(url('/chat.php')) ?>">
                  <small>Assistant</small>
                  <strong>Agent Chat</strong>
                  <p>Your default signed-in Stonefellow workspace.</p>
                  <span>Open Agent Chat ↗</span>
                </a>

                <a class="account-access-card" href="<?= e(url('/chat.php?view=player')) ?>">
                  <small>Music</small>
                  <strong>Stonefellow Player</strong>
                  <p>Listen to tracks available to your account.</p>
                  <span>Open Player ↗</span>
                </a><?php endif; ?>

                <?php if (has_permission('producer.access')): ?>
                  <a class="account-access-card" href="<?= e(url('/admin/producer-tracks.php')) ?>">
                    <small>Production</small>
                    <strong>Producer Workspace</strong>
                    <p>Edit tracks specifically shared with your Producer account.</p>
                    <span>Open Shared Tracks ↗</span>
                  </a>
                <?php endif; ?>

                <?php if (has_permission('investor.access')): ?>
                  <a class="account-access-card" href="<?= e(url('/investor.php')) ?>">
                    <small>Private</small>
                    <strong>Investor Area</strong>
                    <p>Open private Stonefellow investor information.</p>
                    <span>Investor Access ↗</span>
                  </a>
                <?php endif; ?>

                <?php if (has_permission('admin.access')): ?>
                  <a class="account-access-card" href="<?= e(url('/admin/index.php')) ?>">
                    <small>Management</small>
                    <strong>Admin Dashboard</strong>
                    <p>Open management tools enabled for your account.</p>
                    <span>Open Admin ↗</span>
                  </a>
                <?php endif; ?>
              </div>
            </section>
          </div>
        </div>
      </div>
    </section>
  </main>
</div>

<script src="<?= e(url('/member-shell-v77.js')) ?>"></script>
</body>
</html>
