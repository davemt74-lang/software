<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

$pdo = db();
$metrics = ['tracks'=>0,'albums'=>0,'shows'=>0,'photos'=>0,'merch'=>0,'posts'=>0,'messages'=>0,'unread'=>0,'users'=>0,'knowledge'=>0,'chats'=>0,'plays'=>0,'listen_seconds'=>0];
if ($pdo) {
    try {
        $metrics['tracks'] = (int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
        if (table_exists('albums')) $metrics['albums'] = (int)$pdo->query('SELECT COUNT(*) FROM albums')->fetchColumn();
        $metrics['shows'] = (int)$pdo->query('SELECT COUNT(*) FROM shows WHERE show_date >= CURRENT_DATE()')->fetchColumn();
        if (table_exists('photos')) $metrics['photos'] = (int)$pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
        if (table_exists('merch_items')) $metrics['merch'] = (int)$pdo->query('SELECT COUNT(*) FROM merch_items')->fetchColumn();
        if (table_exists('artist_posts')) $metrics['posts'] = (int)$pdo->query('SELECT COUNT(*) FROM artist_posts')->fetchColumn();
        $metrics['messages'] = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
        $metrics['unread'] = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = 0')->fetchColumn();
        $metrics['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if (table_exists('knowledge_items')) $metrics['knowledge'] = (int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn();
        if (table_exists('chat_conversations')) $metrics['chats'] = (int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn();
        if (table_exists('track_play_sessions')) {
            $metrics['plays'] = (int)$pdo->query('SELECT COUNT(*) FROM track_play_sessions WHERE qualified_play=1')->fetchColumn();
            $metrics['listen_seconds'] = (float)$pdo->query('SELECT COALESCE(SUM(listened_seconds),0) FROM track_play_sessions')->fetchColumn();
        }
    } catch (Throwable $e) {}
}

$adminTitle = 'Dashboard';
$adminActive = 'dashboard';
require __DIR__ . '/_header.php';
?>
<?php if (!access_schema_ready() && has_permission('users.manage')): ?>
<div class="notice error">The user-role and media-visibility upgrade has not been installed yet. <a href="<?= e(url('/upgrade.php')) ?>">Run the upgrade →</a></div>
<?php endif; ?>

<div class="grid">
  <div class="metric"><strong><?= $metrics['tracks'] ?></strong><span>Tracks</span></div>
  <?php if (has_permission('albums.manage')): ?><div class="metric"><strong><?= $metrics['albums'] ?></strong><span>Albums</span></div><?php endif; ?>
  <div class="metric"><strong><?= $metrics['shows'] ?></strong><span>Upcoming Shows</span></div>
  <?php if (has_permission('photos.manage')): ?><div class="metric"><strong><?= $metrics['photos'] ?></strong><span>Photos</span></div><?php endif; ?>
  <?php if (has_permission('merch.manage')): ?><div class="metric"><strong><?= $metrics['merch'] ?></strong><span>Merch</span></div><?php endif; ?>
  <?php if (has_permission('posts.manage')): ?><div class="metric"><strong><?= $metrics['posts'] ?></strong><span>Posts</span></div><?php endif; ?>
  <div class="metric"><strong><?= $metrics['messages'] ?></strong><span>Messages</span></div>
  <div class="metric"><strong><?= $metrics['unread'] ?></strong><span>Unread</span></div>
  <?php if (has_permission('users.manage')): ?><div class="metric"><strong><?= $metrics['users'] ?></strong><span>Users</span></div><?php endif; ?>
<?php if (has_permission('knowledge.manage')): ?><div class="metric"><strong><?= $metrics['knowledge'] ?></strong><span>Knowledge</span></div><?php endif; ?>
<?php if (has_permission('chat.access')): ?><div class="metric"><strong><?= $metrics['chats'] ?></strong><span>Chats</span></div><?php endif; ?>
<?php if (has_permission('listening.view')): ?>
  <div class="metric"><strong><?= number_format($metrics['plays']) ?></strong><span>10s+ Plays</span></div>
  <div class="metric"><strong><?= number_format($metrics['listen_seconds']/3600,1) ?></strong><span>Listening Hours</span></div>
<?php endif; ?>
</div>

<div class="panel">
  <h2>Backend</h2>
  <p class="muted">Manage music, shows, photos, merch, artist content, the knowledge base, contact messages, users, chat access and role permissions from this dashboard.</p>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
