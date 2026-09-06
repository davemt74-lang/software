<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$pdo = db();

if (!$pdo || !table_exists('notifications')) {
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

        if ($target !== '' && str_starts_with($target, '/')) {
            redirect($target);
        }
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

$pageTitle = 'Stonefellow | Notifications';
$pageDescription = 'Stonefellow notifications.';
$activePage = '';
require __DIR__ . '/includes/header.php';
?>
<main class="notification-page">
  <section class="notification-page-hero">
    <div class="wrap notification-page-head">
      <div>
        <p class="section-kicker">Account</p>
        <h1>Notifications</h1>
      </div>

      <?php if (notification_unread_count($user) > 0): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="all_read">
          <button class="btn" type="submit">Mark All Read</button>
        </form>
      <?php endif; ?>
    </div>
  </section>

  <section class="notification-page-content">
    <div class="wrap">
      <?php if ($notice): ?><div class="account-alert success"><?= e($notice) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="account-alert error"><?= e($error) ?></div><?php endif; ?>

      <div class="notification-list">
        <?php foreach ($notifications as $notification): ?>
          <a
            class="notification-row <?= !(int)$notification['is_read'] ? 'unread' : '' ?>"
            href="<?= e(url('/notifications.php?open=' . (int)$notification['id'])) ?>"
          >
            <span class="notification-row-icon" aria-hidden="true">•</span>
            <span class="notification-row-copy">
              <strong><?= e($notification['title']) ?></strong>
              <?php if ((string)$notification['body'] !== ''): ?>
                <span><?= e($notification['body']) ?></span>
              <?php endif; ?>
            </span>
            <time><?= e(date('M j, Y g:i A', strtotime((string)$notification['created_at']))) ?></time>
          </a>
        <?php endforeach; ?>

        <?php if (!$notifications): ?>
          <div class="notification-empty">
            <h2>No notifications yet.</h2>
            <p>New Stonefellow activity for your account will appear here.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
