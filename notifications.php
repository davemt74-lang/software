<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$pdo = db();
if (!$user || !$pdo || !table_exists('notifications')) {
    flash('error', 'Notifications are not ready yet.');
    redirect(url('/account.php'));
}

$openId = (int)($_GET['open'] ?? 0);
if ($openId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM notifications WHERE id=? AND user_id=? AND ' . notification_system_sql_predicate() . ' LIMIT 1'
    );
    $stmt->execute([$openId, (int)$user['id']]);
    $notification = $stmt->fetch();
    if ($notification) {
        mark_notification_read($openId, (int)$user['id']);
        $target = trim((string)$notification['target_url']);
        if ($target !== '' && str_starts_with($target, '/')) redirect($target);
    }
    redirect(url('/notifications.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('notification_error', 'Session expired.');
        redirect(url('/notifications.php'));
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'all_read') {
        mark_all_notifications_read((int)$user['id']);
        flash('notification_notice', 'Notifications marked as read.');
    }
    redirect(url('/notifications.php'));
}

$stmt = $pdo->prepare(
    'SELECT * FROM notifications
     WHERE user_id=? AND ' . notification_system_sql_predicate() . '
     ORDER BY created_at DESC,id DESC
     LIMIT 200'
);
$stmt->execute([(int)$user['id']]);
$notifications = $stmt->fetchAll();

$notice = flash('notification_notice');
$error = flash('notification_error');
$unreadCount = notification_unread_count($user);
$memberHeaderUser = $user;
$memberHeaderTitle = 'Notifications';
$memberHeaderSubtitle = 'Account activity and agent alerts';
$memberHeaderActions = '';
if ($unreadCount > 0) {
    $memberHeaderActions = '<form method="post" class="notification-header-form">'
        . csrf_field()
        . '<input type="hidden" name="action" value="all_read">'
        . '<button class="notification-button" type="submit">Mark all read</button>'
        . '</form>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f7f8fa">
<title>VP3 | Notifications</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<style>
.notifications-main{min-width:0;background:#f7f8fa;color:#111827}.notification-member-canvas{height:100%;min-height:0;overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:28px 24px 72px}.notification-member-wrap{width:min(980px,100%);margin:0 auto;display:grid;gap:16px}.notification-summary{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;padding:8px 2px 2px}.notification-summary small{display:block;color:#8b94a0;font-size:10px;font-weight:850;letter-spacing:.1em;text-transform:uppercase}.notification-summary h1{margin:4px 0 0;font-size:clamp(28px,4vw,42px);letter-spacing:-.04em}.notification-summary p{max-width:470px;margin:0;color:#6b7280;font-size:13px;line-height:1.55}.notification-list{overflow:hidden;border:1px solid #e1e5ea;border-radius:16px;background:#fff;box-shadow:0 8px 28px rgba(15,23,42,.035)}.notification-row{display:grid;grid-template-columns:10px minmax(0,1fr) auto;gap:13px;align-items:start;padding:16px 18px;border-bottom:1px solid #edf0f3;color:#374151;text-decoration:none;background:#fff}.notification-row:last-child{border-bottom:0}.notification-row:hover{background:#fafbfc}.notification-row.unread{background:#f7f9fc}.notification-row-icon{width:8px;height:8px;margin-top:5px;border-radius:50%;background:#d1d5db;font-size:0}.notification-row.unread .notification-row-icon{background:#111827}.notification-row-copy{min-width:0;display:grid;gap:4px}.notification-row-copy strong{color:#111827;font-size:13px}.notification-row-copy span{color:#6b7280;font-size:12px;line-height:1.5}.notification-row time{color:#98a1ad;font-size:10px;white-space:nowrap}.notification-empty{padding:56px 24px;text-align:center}.notification-empty h2{margin:0;color:#111827;font-size:20px}.notification-empty p{margin:8px 0 0;color:#6b7280;font-size:13px}.notification-alert{padding:12px 14px;border:1px solid #dbe3ec;border-radius:11px;background:#fff;color:#475467;font-size:12px}.notification-alert.success{border-color:#b9dfc6;background:#f4fbf6;color:#166534}.notification-alert.error{border-color:#efc5c2;background:#fff7f6;color:#a61b1b}.notification-button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 11px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;font:inherit;font-size:11px;font-weight:800;cursor:pointer}.notification-button:hover,.notification-button:focus-visible{background:#f3f4f6;outline:0}.notification-header-form{margin:0}@media(max-width:680px){.notification-member-canvas{padding:18px 14px 60px}.notification-summary{display:grid}.notification-row{grid-template-columns:10px minmax(0,1fr)}.notification-row time{grid-column:2}.notification-summary p{max-width:none}}
</style>
</head>
<body>
<div class="chat-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='notifications';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main notifications-main">
    <?php require __DIR__.'/includes/member-header.php'; ?>
    <section class="notification-member-canvas">
      <div class="notification-member-wrap">
        <section class="notification-summary">
          <div><small>Activity center</small><h1><?= $unreadCount > 0 ? number_format($unreadCount).' unread' : 'You’re caught up.' ?></h1></div>
          <p>Profile visitors, agent activity, Team events, billing changes, HomeServer notices, and other VP3 account activity appear here.</p>
        </section>

        <?php if ($notice): ?><div class="notification-alert success" role="status"><?= e($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notification-alert error" role="alert"><?= e($error) ?></div><?php endif; ?>

        <div class="notification-list">
          <?php foreach ($notifications as $notification): ?>
            <a class="notification-row <?= !(int)$notification['is_read'] ? 'unread' : '' ?>" href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>">
              <span class="notification-row-icon" aria-hidden="true"></span>
              <span class="notification-row-copy">
                <strong><?= e((string)$notification['title']) ?></strong>
                <?php if ((string)$notification['body'] !== ''): ?><span><?= e((string)$notification['body']) ?></span><?php endif; ?>
              </span>
              <time datetime="<?= e((string)$notification['created_at']) ?>"><?= e(date('M j, Y g:i A', strtotime((string)$notification['created_at']))) ?></time>
            </a>
          <?php endforeach; ?>
          <?php if (!$notifications): ?>
            <div class="notification-empty"><h2>No notifications yet.</h2><p>New VP3 activity for your account will appear here.</p></div>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>
</div>
<script src="<?= e(url('/workspace-shell-v82.js?v=82')) ?>" defer></script>
</body>
</html>
