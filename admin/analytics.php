<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('listening.view');

$pdo = db();

if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$summary = [
    'sessions'=>0,
    'qualified'=>0,
    'completed'=>0,
    'hours'=>0.0,
    'favorites'=>0,
    'playlist_adds'=>0,
    'repeat_listeners'=>0,
];

$tracks = [];
$albums = [];

try {
    $row = $pdo->query(
        'SELECT
            COUNT(*) AS sessions,
            SUM(qualified_play) AS qualified,
            SUM(completed) AS completed,
            COALESCE(SUM(listened_seconds),0) AS seconds
         FROM track_play_sessions'
    )->fetch();

    if ($row) {
        $summary['sessions'] = (int)$row['sessions'];
        $summary['qualified'] = (int)$row['qualified'];
        $summary['completed'] = (int)$row['completed'];
        $summary['hours'] = (float)$row['seconds'] / 3600;
    }

    if (table_exists('track_favorites')) {
        $summary['favorites'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM track_favorites'
        )->fetchColumn();
    }

    if (table_exists('playlist_tracks')) {
        $summary['playlist_adds'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM playlist_tracks'
        )->fetchColumn();
    }

    $summary['repeat_listeners'] = (int)$pdo->query(
        'SELECT COUNT(*)
         FROM (
           SELECT listener_hash
           FROM track_play_sessions
           GROUP BY listener_hash
           HAVING COUNT(*) > 1
         ) repeaters'
    )->fetchColumn();

    $tracks = $pdo->query(
        'SELECT
            t.id,
            t.title,
            t.album,
            COUNT(s.id) AS sessions,
            COALESCE(SUM(s.qualified_play),0) AS qualified,
            COALESCE(SUM(s.completed),0) AS completed,
            COALESCE(SUM(s.listened_seconds),0) AS seconds,
            COALESCE(AVG(s.completion_percent),0) AS avg_completion,
            SUM(CASE WHEN s.listened_seconds < 10 THEN 1 ELSE 0 END) AS skips,
            (
              SELECT COUNT(*)
              FROM track_favorites f
              WHERE f.track_id=t.id
            ) AS favorites,
            (
              SELECT COUNT(*)
              FROM playlist_tracks pt
              WHERE pt.track_id=t.id
            ) AS playlist_adds,
            (
              SELECT COUNT(*)
              FROM stem_mix_saves sms
              WHERE sms.track_id=t.id
            ) AS studio_saves
         FROM tracks t
         LEFT JOIN track_play_sessions s ON s.track_id=t.id
         GROUP BY t.id
         ORDER BY qualified DESC,sessions DESC,seconds DESC,t.id DESC'
    )->fetchAll();

    if (table_exists('albums')) {
        $albums = $pdo->query(
            'SELECT
                a.id,
                a.title,
                COUNT(DISTINCT t.id) AS tracks,
                COUNT(s.id) AS sessions,
                COALESCE(SUM(s.qualified_play),0) AS qualified,
                COALESCE(SUM(s.listened_seconds),0) AS seconds,
                (
                  SELECT COUNT(*)
                  FROM album_favorites af
                  WHERE af.album_id=a.id
                ) AS favorites
             FROM albums a
             LEFT JOIN tracks t ON t.album_id=a.id
             LEFT JOIN track_play_sessions s ON s.track_id=t.id
             GROUP BY a.id
             ORDER BY qualified DESC,sessions DESC,seconds DESC,a.id DESC'
        )->fetchAll();
    }
} catch (Throwable $e) {
    flash('error', $e->getMessage());
}

$adminTitle = 'Music Analytics';
$adminActive = 'analytics';
require __DIR__ . '/_header.php';
?>
<div class="grid">
  <div class="metric"><strong><?= number_format($summary['sessions']) ?></strong><span>Playback Sessions</span></div>
  <div class="metric"><strong><?= number_format($summary['qualified']) ?></strong><span>10s+ Plays</span></div>
  <div class="metric"><strong><?= number_format($summary['completed']) ?></strong><span>Completed Plays</span></div>
  <div class="metric"><strong><?= number_format($summary['hours'],1) ?></strong><span>Listening Hours</span></div>
  <div class="metric"><strong><?= number_format($summary['favorites']) ?></strong><span>Track Favorites</span></div>
  <div class="metric"><strong><?= number_format($summary['playlist_adds']) ?></strong><span>Playlist Adds</span></div>
  <div class="metric"><strong><?= number_format($summary['repeat_listeners']) ?></strong><span>Repeat Listeners</span></div>
</div>

<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Engagement</span>
      <h2>Track Performance</h2>
      <p class="muted">Plays, completion, skips, favorites, playlist adds and Stem Studio saves.</p>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Track</th>
          <th>Sessions</th>
          <th>10s+</th>
          <th>Completion</th>
          <th>Skips</th>
          <th>Favorites</th>
          <th>Playlist Adds</th>
          <th>Studio Saves</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tracks as $track): ?>
        <tr>
          <td><strong><?= e((string)$track['title']) ?></strong><br><small class="muted"><?= e((string)$track['album']) ?></small></td>
          <td><?= number_format((int)$track['sessions']) ?></td>
          <td><?= number_format((int)$track['qualified']) ?></td>
          <td><?= number_format((float)$track['avg_completion'],1) ?>%</td>
          <td><?= number_format((int)$track['skips']) ?></td>
          <td><?= number_format((int)$track['favorites']) ?></td>
          <td><?= number_format((int)$track['playlist_adds']) ?></td>
          <td><?= number_format((int)$track['studio_saves']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Collections</span>
      <h2>Album Engagement</h2>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Album</th><th>Tracks</th><th>Sessions</th><th>10s+</th><th>Hours</th><th>Favorites</th></tr></thead>
      <tbody>
      <?php foreach ($albums as $album): ?>
        <tr>
          <td><strong><?= e((string)$album['title']) ?></strong></td>
          <td><?= number_format((int)$album['tracks']) ?></td>
          <td><?= number_format((int)$album['sessions']) ?></td>
          <td><?= number_format((int)$album['qualified']) ?></td>
          <td><?= number_format(((float)$album['seconds'])/3600,1) ?></td>
          <td><?= number_format((int)$album['favorites']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
