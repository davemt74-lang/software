<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('account.access');
require_once __DIR__.'/includes/agent-operator-owner-phone-v1.php';
header('Cache-Control: no-store, private');

$pdo = db();
$user = current_user();

if (!$pdo || !$user) {
    flash('error', 'Your account could not be loaded.');
    redirect(url('/login.php'));
}

if (!column_exists('users', 'avatar_path')) {
    redirect(url('/upgrade.php'));
}

$accountUserMenuLinks = member_navigation_menu_links($user);
$accountSoulRoles = agent_brain_soul_roles_v1();
if (!has_permission('chat.access', $user)) {
    unset($accountSoulRoles['email']);
}
$accountSoulUrl = static function (string $role, array $query = []): string {
    return url('/account.php?' . http_build_query(['soul_role' => $role] + $query) . '#agent-brain');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('account_error', 'Your session expired. Please try again.');
        redirect(url('/account.php'));
    }

    try {
        $action = (string)($_POST['action'] ?? 'profile');

        if (in_array($action, ['operator_owner_phone_number','operator_owner_phone_preferences'], true)) {
            try {
                $result=$action==='operator_owner_phone_number'
                    ?VP3OperatorOwnerPhoneV1::saveNumber($pdo,$user,$_POST,time())
                    :VP3OperatorOwnerPhoneV1::savePreferences($pdo,$user,$_POST,time());
                flash('account_notice',$action==='operator_owner_phone_number'
                    ?'Personal phone number saved. You can test it from private Agent Chat.'
                    :'Agent calling preferences saved. Autonomous calling remains off until the calling service is ready.');
            } catch (DomainException $error) {
                flash('account_error',$error->getMessage());
            } catch (Throwable) {
                flash('account_error','Phone settings could not be saved. Please try again.');
            }
            redirect(url('/account.php').'#agent-phone');
        }

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

        if (in_array($action, ['soul_save', 'soul_reset', 'soul_restore'], true)) {
            $role = $_POST['soul_role'] ?? null;
            $revision = $_POST['soul_revision'] ?? null;
            if (!is_string($role) || !isset($accountSoulRoles[$role]) || !is_string($revision)) {
                throw new InvalidArgumentException('Open the Agent Soul editor before saving.');
            }
            $value = match ($action) {
                'soul_save' => is_string($_POST['soul_content'] ?? null) ? $_POST['soul_content'] : null,
                'soul_restore' => filter_var($_POST['soul_version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]),
                default => null,
            };
            $version = agent_brain_soul_update_v1($user, $role, $revision, substr($action, 5), $value === false ? null : $value);
            flash('account_notice', $accountSoulRoles[$role] . ' Soul is active at version ' . $version . '.');
            redirect($accountSoulUrl($role));
        }

        if ($action === 'password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if (strlen($newPassword) < 12 || strlen($newPassword)>4096) {
                throw new RuntimeException('The new password must contain between 12 and 4096 characters.');
            }
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('The new passwords do not match.');
            }
            if(!auth_change_password($pdo,(int)$user['id'],$currentPassword,$newPassword)){
                throw new RuntimeException('Your current password is incorrect or the account changed. Sign in again and retry.');
            }
            flash('account_notice', 'Password updated.');
        }
    } catch (Throwable $e) {
        flash('account_error', $e->getMessage());
        if (isset($role) && is_string($role) && isset($accountSoulRoles[$role])) {
            redirect($accountSoulUrl($role));
        }
    }

    redirect(url('/account.php'));
}


$user = current_user();
$notice = flash('account_notice');
$error = flash('account_error');
$ownerPhone=null;
try {$ownerPhone=VP3OperatorOwnerPhoneV1::read($pdo,$user);}catch(Throwable){}



$accountNotificationCount = notification_unread_count($user);
$accountNotifications = notification_recent($user, 6);
$accountSoulRole = is_string($_GET['soul_role'] ?? null) && isset($accountSoulRoles[$_GET['soul_role']])
    ? $_GET['soul_role'] : 'main';
$accountSoul = null;
$accountSoulError = '';
$accountSoulPreview = null;
try {
    $accountSoul = agent_brain_soul_profile_v1($user, $accountSoulRole);
    $accountSoulHistory = agent_brain_soul_history_v1($user, $accountSoulRole);
    $accountSoulPageCount = max(1, (int)ceil(count($accountSoulHistory) / 10));
    $accountSoulPage = max(1, min($accountSoulPageCount, (int)filter_var($_GET['soul_page'] ?? 1, FILTER_VALIDATE_INT)));
    $accountSoulVersions = array_slice($accountSoulHistory, ($accountSoulPage - 1) * 10, 10);
    $accountSoulVersion = filter_var($_GET['soul_version'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($accountSoulVersion !== false && in_array($accountSoulVersion, $accountSoulHistory, true)) {
        $accountSoulPreview = agent_brain_soul_saved_version_v1($user, $accountSoulRole, $accountSoulVersion);
    }
} catch (RuntimeException $e) {
    $accountSoul = null;
    $accountSoulError = $e->getMessage();
}
$accountBrain = agent_brain_summary($user);
if (agent_brain_schema_ready() && (int)$accountBrain['archive_count'] === 0) {
    agent_brain_backfill_user($user, 500);
    $accountBrain = agent_brain_summary($user);
}
$accountBrainTools = agent_brain_tools($user);
vp3_connected_sites_ensure_schema_v100($pdo);
$accountConnectedSites = vp3_connected_sites_for_user_v100($pdo, (int)$user['id']);
$accountActiveConnectedSites = count(array_filter($accountConnectedSites, static fn($row)=>(string)($row['status']??'')==='active'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f4f5f7">
<title><?= e(system_agent_name()) ?> | My Account</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/account.css?v=account-light-20260904')) ?>">
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
              <?php foreach ($accountUserMenuLinks as $menuLink): ?>
                <a<?= !empty($menuLink['danger']) ? ' class="logout"' : '' ?> href="<?= e((string)$menuLink['url']) ?>"><span><?= e((string)$menuLink['label']) ?></span><span>↗</span></a>
              <?php endforeach; ?>
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
            <a href="#agent-phone">Phone &amp; Agent Calls</a>
            <a href="#security">Security</a>
            <a href="#agent-brain">Agent Brain</a>
            <a href="#access">Your Access</a>
            <a href="#connected-sites">Connected Sites</a>
          </nav>

          <div class="account-canvas-content">
            <section class="account-panel" id="profile">
              <div class="account-panel-head">
                <span>Profile</span>
                <h2>Profile & Photo</h2>
                <p>Update how your <?= e(system_agent_name()) ?> account appears inside the authenticated workspace.</p>
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

            <section class="account-panel" id="agent-phone">
              <div class="account-panel-head">
                <span>Private account contact</span>
                <h2>Phone &amp; Agent Calls</h2>
                <p>Let your Agent call you with Chat Canvas updates or a friendly check-in. This is your personal callback number, not your assigned VP3 phone line or public profile number.</p>
              </div>
              <?php if ($ownerPhone===null): ?>
                <p>Phone preferences are unavailable. Install the latest Cloud database upgrade or sign in again.</p>
              <?php else: ?>
                <form method="post" class="account-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="operator_owner_phone_number">
                  <input type="hidden" name="expected_revision" value="<?= (int)$ownerPhone['revision'] ?>">
                  <div class="account-fields">
                    <div class="account-field full">
                      <label for="owner_phone_e164">Your phone number</label>
                      <input id="owner_phone_e164" name="phone_e164" type="tel" autocomplete="tel" inputmode="tel" maxlength="64"
                        placeholder="+1 602 555 0100" value="<?= e($ownerPhone['phone_e164']) ?>">
                      <small>Private. Include your country code. Clear this field and save to remove the number.</small>
                    </div>
                  </div>
                  <p><?= $ownerPhone['phone_e164']!==''?'Saved number — test the connection in private Agent Chat.':'No personal number saved.' ?></p>
                  <button class="account-submit" type="submit">Save phone number</button>
                </form>
                <form method="post" class="account-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="operator_owner_phone_preferences">
                  <input type="hidden" name="expected_revision" value="<?= (int)$ownerPhone['revision'] ?>">
                  <fieldset <?= $ownerPhone['phone_e164']===''?'disabled':'' ?>>
                    <legend>When may your Agent call you?</legend>
                    <label class="account-remove-photo">
                      <input type="checkbox" name="updates_requested" value="1" <?= $ownerPhone['updates_requested']?'checked':'' ?>>
                      Call me with Chat Canvas updates
                    </label>
                    <label class="account-remove-photo">
                      <input type="checkbox" name="hello_requested" value="1" <?= $ownerPhone['hello_requested']?'checked':'' ?>>
                      Call just to say hello or check in
                    </label>
                    <div class="account-fields">
                      <div class="account-field">
                        <label for="owner_call_update_scope">Which updates?</label>
                        <select id="owner_call_update_scope" name="update_scope">
                          <option value="important" <?= $ownerPhone['update_scope']==='important'?'selected':'' ?>>Important updates and decisions</option>
                          <option value="eligible" <?= $ownerPhone['update_scope']==='eligible'?'selected':'' ?>>Any eligible updates (grouped)</option>
                        </select>
                      </div>
                      <div class="account-field">
                        <label for="owner_call_timezone">Time zone</label>
                        <select id="owner_call_timezone" name="timezone">
                          <?php foreach (array_unique(array_merge(['UTC'],DateTimeZone::listIdentifiers())) as $zone): ?>
                            <option value="<?= e($zone) ?>" <?= $ownerPhone['timezone']===$zone?'selected':'' ?>><?= e($zone) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="account-field">
                        <label for="owner_call_start">Earliest call</label>
                        <input type="time" id="owner_call_start" name="window_start" required value="<?= e($ownerPhone['window_start']) ?>">
                      </div>
                      <div class="account-field">
                        <label for="owner_call_end">Latest call</label>
                        <input type="time" id="owner_call_end" name="window_end" required value="<?= e($ownerPhone['window_end']) ?>">
                      </div>
                      <div class="account-field">
                        <label for="owner_call_max">Maximum calls per day</label>
                        <input type="number" id="owner_call_max" name="max_calls_per_day" min="1" max="3" required value="<?= (int)$ownerPhone['max_calls_per_day'] ?>">
                      </div>
                      <div class="account-field">
                        <label for="owner_call_gap">Minimum minutes between calls</label>
                        <input type="number" id="owner_call_gap" name="min_gap_minutes" min="120" max="10080" required value="<?= (int)$ownerPhone['min_gap_minutes'] ?>">
                      </div>
                      <div class="account-field">
                        <label for="owner_call_duration">Maximum call duration</label>
                        <select id="owner_call_duration" name="max_duration_seconds">
                          <?php foreach ([60,120,180,240,300] as $seconds): ?>
                            <option value="<?= $seconds ?>" <?= $ownerPhone['max_duration_seconds']===$seconds?'selected':'' ?>><?= (int)($seconds/60) ?> min</option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>
                    <button class="account-submit" type="submit">Save calling preferences</button>
                  </fieldset>
                  <small>These are requested preferences only. A successful connection test is not identity verification. Autonomous calling remains disabled until the calling service and permission checks are ready. Calls outside your window, automatic redial, and recording are not authorized by these settings. Changing or removing your number clears both call preferences.</small>
                </form>
              <?php endif; ?>
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

            <section class="account-panel" id="connected-sites">
              <div class="account-panel-head">
                <span>Connected Sites</span>
                <h2><?= (int)$accountActiveConnectedSites ?> Active Connection<?= $accountActiveConnectedSites===1?'':'s' ?></h2>
                <p>External sites you explicitly authorized to access selected VP3 account data.</p>
              </div>
              <?php if ($accountConnectedSites): ?>
                <div class="account-access-grid">
                  <?php foreach (array_slice($accountConnectedSites,0,4) as $site): ?>
                    <article class="account-access-card">
                      <small><?= e(ucfirst((string)$site['status'])) ?></small>
                      <strong><?= e((string)$site['app_label']) ?></strong>
                      <p><?= e(count((array)$site['scopes'])) ?> permission<?= count((array)$site['scopes'])===1?'':'s' ?> · Connected <?= e((string)$site['connected_at']) ?><?= !empty($site['last_used_at'])?' · Last used '.e((string)$site['last_used_at']):'' ?></p>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?><p>No external sites are connected to this account.</p><?php endif; ?>
              <p style="margin-top:16px"><a class="account-shell-button" href="<?= e(url('/connected-sites.php')) ?>">Manage Connected Sites</a></p>
            </section>

            <section class="account-panel agent-brain-panel" id="agent-brain">
              <div class="account-panel-head">
                <span>Agent Brain</span>
                <h2>Your Agent Souls & Memory</h2>
                <p>Set the communication style and working preferences for each agent in your account.</p>
              </div>

              <div class="agent-brain-stats">
                <article><strong><?= number_format((int)$accountBrain['archive_count']) ?></strong><span>Archived messages</span></article>
                <article><strong><?= number_format((int)$accountBrain['memory_count']) ?></strong><span>Memory items</span></article>
                <article><strong><?= number_format(count($accountBrainTools)) ?></strong><span>Available tools</span></article>
              </div>

              <nav class="agent-soul-actions" aria-label="Agent Souls">
                <?php foreach ($accountSoulRoles as $role => $label): ?>
                  <a class="account-shell-button<?= $role === $accountSoulRole ? ' primary' : '' ?>" href="<?= e($accountSoulUrl($role)) ?>"<?= $role === $accountSoulRole ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
              </nav>

              <?php if ($accountSoul === null): ?>
                <p class="account-alert" role="alert"><?= e($accountSoulError) ?></p>
              <?php else: ?>
                <form method="post" class="account-form agent-soul-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="soul_save">
                  <input type="hidden" name="soul_role" value="<?= e($accountSoulRole) ?>">
                  <input type="hidden" name="soul_revision" value="<?= e($accountSoul['revision']) ?>">
                  <div class="account-field full">
                    <label for="soul_content"><?= e($accountSoulRoles[$accountSoulRole]) ?> SOUL.md</label>
                    <textarea id="soul_content" name="soul_content" rows="24" maxlength="24000" spellcheck="true" required><?= e($accountSoul['content']) ?></textarea>
                    <small>Active version <?= $accountSoul['version'] ?>. VP3 enforces access and approvals.</small>
                  </div>
                  <div class="agent-soul-actions">
                    <button class="account-submit" type="submit">Save SOUL.md</button>
                  </div>
                </form>

                <form method="post" class="agent-soul-reset" onsubmit="return confirm('Reset this Agent Soul to its default?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="soul_reset">
                  <input type="hidden" name="soul_role" value="<?= e($accountSoulRole) ?>">
                  <input type="hidden" name="soul_revision" value="<?= e($accountSoul['revision']) ?>">
                  <button type="submit">Reset to default</button>
                </form>

                <?php if ($accountSoulVersions): ?>
                  <details<?= $accountSoulPreview !== null || isset($_GET['soul_page']) ? ' open' : '' ?>>
                    <summary>Soul version history</summary>
                    <ul>
                      <?php foreach ($accountSoulVersions as $version): ?>
                        <li>
                          <a href="<?= e($accountSoulUrl($accountSoulRole, ['soul_page'=>$accountSoulPage,'soul_version'=>$version])) ?>">Version <?= $version ?></a>
                          <?= $version === $accountSoul['version'] ? ' (active)' : '' ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                    <?php if ($accountSoulPageCount > 1): ?>
                      <nav class="agent-soul-actions" aria-label="Soul history pages">
                        <?php if ($accountSoulPage > 1): ?>
                          <a class="account-shell-button" href="<?= e($accountSoulUrl($accountSoulRole, ['soul_page'=>$accountSoulPage - 1])) ?>">Newer versions</a>
                        <?php endif; ?>
                        <?php if ($accountSoulPage < $accountSoulPageCount): ?>
                          <a class="account-shell-button" href="<?= e($accountSoulUrl($accountSoulRole, ['soul_page'=>$accountSoulPage + 1])) ?>">Older versions</a>
                        <?php endif; ?>
                      </nav>
                    <?php endif; ?>
                    <?php if ($accountSoulPreview !== null): ?>
                      <form method="post" class="account-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="soul_restore">
                        <input type="hidden" name="soul_role" value="<?= e($accountSoulRole) ?>">
                        <input type="hidden" name="soul_revision" value="<?= e($accountSoul['revision']) ?>">
                        <input type="hidden" name="soul_version" value="<?= $accountSoulVersion ?>">
                        <div class="account-field full">
                          <label for="soul_preview">Version <?= $accountSoulVersion ?></label>
                          <textarea id="soul_preview" rows="10" readonly><?= e($accountSoulPreview) ?></textarea>
                        </div>
                        <?php if ($accountSoulVersion !== $accountSoul['version']): ?>
                          <button class="account-submit" type="submit">Restore as a new version</button>
                        <?php endif; ?>
                      </form>
                    <?php endif; ?>
                  </details>
                <?php endif; ?>
              <?php endif; ?>

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
                  <p>Your default signed-in <?= e(system_agent_name()) ?> workspace.</p>
                  <span>Open Agent Chat ↗</span>
                </a>

                <a class="account-access-card" href="<?= e(url('/budget-governance.php')) ?>">
                  <small>Agent Governance</small>
                  <strong>AI Budget Governance</strong>
                  <p>Set explicit AI cost and Cloud-token guardrails, review projected burn, and approve bounded overrides.</p>
                  <span>Manage Budgets ↗</span>
                </a>

                <a class="account-access-card" href="<?= e(url('/outcome-value.php')) ?>">
                  <small>Portfolio Intelligence</small>
                  <strong>Outcome Value & ROI</strong>
                  <p>Define explicit outcome value, verify realized results, and review AI-cost ROI and value at risk.</p>
                  <span>Manage Value ↗</span>
                </a>

                <a class="account-access-card" href="<?= e(url('/decision-calibration.php')) ?>">
                  <small>Portfolio Learning</small>
                  <strong>Decision Calibration</strong>
                  <p>Compare raw and calibrated forecasts, cost/token projections, value reliability, and verified outcomes.</p>
                  <span>Review Calibration ↗</span>
                </a>

                <a class="account-access-card" href="<?= e(url('/chat.php?view=player')) ?>">
                  <small>Music</small>
                  <strong><?= e(system_agent_name()) ?> Player</strong>
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
                    <p>Open private <?= e(system_agent_name()) ?> investor information.</p>
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
