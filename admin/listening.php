<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('listening.view');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
if (!$pdo) {
    flash('error','Database unavailable.');
    redirect(url('/admin/index.php'));
}

$range = (string)($_GET['range'] ?? '30');
$allowedRanges = ['7','30','90','all'];
if (!in_array($range,$allowedRanges,true)) $range = '30';

$where = '';
$params = [];
if ($range !== 'all') {
    $where = ' WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = (int)$range;
}

$summarySql = "
SELECT
  COUNT(*) AS starts,
  SUM(CASE WHEN s.qualified_play=1 THEN 1 ELSE 0 END) AS qualified_plays,
  SUM(s.listened_seconds) AS listened_seconds,
  AVG(s.listened_seconds) AS avg_listen_seconds,
  AVG(s.completion_percent) AS avg_completion,
  SUM(CASE WHEN s.completed=1 THEN 1 ELSE 0 END) AS completions,
  COUNT(DISTINCT CONCAT(CASE WHEN s.user_id IS NULL THEN 'a:' ELSE 'u:' END, COALESCE(CAST(s.user_id AS CHAR),s.listener_hash))) AS unique_listeners
FROM track_play_sessions s{$where}";
$stmt = $pdo->prepare($summarySql);
$stmt->execute($params);
$summary = $stmt->fetch() ?: [];

$perTrackSql = "
SELECT
  t.id,t.title,t.album,
  COUNT(s.id) AS starts,
  SUM(CASE WHEN s.qualified_play=1 THEN 1 ELSE 0 END) AS qualified_plays,
  SUM(s.listened_seconds) AS listened_seconds,
  AVG(s.listened_seconds) AS avg_listen_seconds,
  AVG(s.completion_percent) AS avg_completion,
  SUM(CASE WHEN s.completed=1 THEN 1 ELSE 0 END) AS completions,
  COUNT(DISTINCT CONCAT(CASE WHEN s.user_id IS NULL THEN 'a:' ELSE 'u:' END, COALESCE(CAST(s.user_id AS CHAR),s.listener_hash))) AS unique_listeners
FROM tracks t
LEFT JOIN track_play_sessions s ON s.track_id=t.id" .
($range !== 'all' ? " AND s.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)" : '') . "
GROUP BY t.id,t.title,t.album
ORDER BY qualified_plays DESC, listened_seconds DESC, t.sort_order ASC";
$stmt = $pdo->prepare($perTrackSql);
$stmt->execute($params);
$perTracks = $stmt->fetchAll();

$deviceSql = "
SELECT device_type,COUNT(*) AS sessions,SUM(listened_seconds) AS listened_seconds
FROM track_play_sessions s{$where}
GROUP BY device_type ORDER BY sessions DESC";
$stmt = $pdo->prepare($deviceSql);
$stmt->execute($params);
$devices = $stmt->fetchAll();


$sourceSql = "
SELECT source_context,COUNT(*) AS sessions,SUM(listened_seconds) AS listened_seconds
FROM track_play_sessions s{$where}
GROUP BY source_context ORDER BY sessions DESC";
$stmt = $pdo->prepare($sourceSql);
$stmt->execute($params);
$sources = $stmt->fetchAll();

$recentSql = "
SELECT s.*,t.title,u.display_name,u.email,u.role
FROM track_play_sessions s
JOIN tracks t ON t.id=s.track_id
LEFT JOIN users u ON u.id=s.user_id" .
($range !== 'all' ? " WHERE s.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)" : '') . "
ORDER BY s.started_at DESC LIMIT 100";
$stmt = $pdo->prepare($recentSql);
$stmt->execute($params);
$recent = $stmt->fetchAll();

function sf_time_label(float $seconds): string {
    if ($seconds < 60) return number_format($seconds,0) . ' sec';
    if ($seconds < 3600) return number_format($seconds/60,1) . ' min';
    return number_format($seconds/3600,2) . ' hr';
}
function sf_pct(mixed $value): string {
    return number_format((float)$value,1) . '%';
}

$adminTitle = 'Listening Analytics';
$adminActive = 'listening';
require __DIR__ . '/_header.php';
?>
<div class="analytics-range">
  <span>Range</span>
  <?php foreach (['7'=>'7 Days','30'=>'30 Days','90'=>'90 Days','all'=>'All Time'] as $value=>$label): ?>
    <a class="<?= $range===$value ? 'active' : '' ?>" href="<?= e(url('/admin/listening.php?range='.$value)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="grid listening-metrics">
  <div class="metric"><strong><?= number_format((int)($summary['starts'] ?? 0)) ?></strong><span>Play Starts</span></div>
  <div class="metric"><strong><?= number_format((int)($summary['qualified_plays'] ?? 0)) ?></strong><span>10s+ Plays</span></div>
  <div class="metric"><strong><?= number_format((int)($summary['unique_listeners'] ?? 0)) ?></strong><span>Unique Listeners</span></div>
  <div class="metric"><strong><?= e(sf_time_label((float)($summary['listened_seconds'] ?? 0))) ?></strong><span>Listening Time</span></div>
  <div class="metric"><strong><?= e(sf_time_label((float)($summary['avg_listen_seconds'] ?? 0))) ?></strong><span>Avg Listen</span></div>
  <div class="metric"><strong><?= e(sf_pct($summary['avg_completion'] ?? 0)) ?></strong><span>Avg Completion</span></div>
  <div class="metric"><strong><?= number_format((int)($summary['completions'] ?? 0)) ?></strong><span>Completions</span></div>
</div>

<div class="panel">
  <h2>Track Performance</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Track</th><th>Starts</th><th>10s+ Plays</th><th>Listeners</th><th>Total Listen</th><th>Avg Listen</th><th>Avg Completion</th><th>Completed</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($perTracks as $row): ?>
        <tr>
          <td><strong><?= e($row['title']) ?></strong><br><span class="muted"><?= e($row['album']) ?></span></td>
          <td><?= number_format((int)$row['starts']) ?></td>
          <td><?= number_format((int)$row['qualified_plays']) ?></td>
          <td><?= number_format((int)$row['unique_listeners']) ?></td>
          <td><?= e(sf_time_label((float)$row['listened_seconds'])) ?></td>
          <td><?= e(sf_time_label((float)$row['avg_listen_seconds'])) ?></td>
          <td><?= e(sf_pct($row['avg_completion'])) ?></td>
          <td><?= number_format((int)$row['completions']) ?></td>
          <td><a class="btn" href="<?= e(url('/admin/track.php?id='.(int)$row['id'])) ?>">Song Details</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="analytics-three-col">
  <div class="panel">
    <h2>Devices</h2>
    <div class="analytics-device-list">
      <?php foreach ($devices as $device): ?>
        <div><strong><?= e(ucfirst((string)$device['device_type'])) ?></strong><span><?= number_format((int)$device['sessions']) ?> sessions · <?= e(sf_time_label((float)$device['listened_seconds'])) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$devices): ?><p class="muted">No listening data yet.</p><?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2>Listening Source</h2>
    <div class="analytics-device-list">
      <?php foreach ($sources as $source): ?>
        <div><strong><?= e($source['source_context']==='agent_chat' ? 'Agent Chat' : 'Player') ?></strong><span><?= number_format((int)$source['sessions']) ?> sessions · <?= e(sf_time_label((float)$source['listened_seconds'])) ?></span></div>
      <?php endforeach; ?>
      <?php if (!$sources): ?><p class="muted">No listening data yet.</p><?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h2>How a “Play” is Counted</h2>
    <p class="muted">Every playback start is recorded. A qualified play is a session with at least 10 seconds of actual listening. Completion is recorded when the track ends naturally or the listener accumulates at least 80% of the track duration.</p>
  </div>
</div>

<div class="panel">
  <h2>Recent Listening Sessions</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Started</th><th>Track</th><th>Listener</th><th>Source</th><th>Device</th><th>Listened</th><th>Max Position</th><th>Completion</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $row): ?>
        <tr>
          <td><?= e(date('M j, Y g:i A',strtotime((string)$row['started_at']))) ?></td>
          <td><?= e($row['title']) ?></td>
          <td>
            <?php if (!empty($row['user_id'])): ?>
              <strong><?= e($row['display_name'] ?: $row['email']) ?></strong><br><span class="muted"><?= e(role_label((string)$row['role'])) ?></span>
            <?php else: ?>
              Anonymous
            <?php endif; ?>
          </td>
          <td><?= e($row['source_context']==='agent_chat' ? 'Agent Chat' : 'Player') ?></td>
          <td><?= e(ucfirst((string)$row['device_type'])) ?></td>
          <td><?= e(sf_time_label((float)$row['listened_seconds'])) ?></td>
          <td><?= e(sf_time_label((float)$row['max_position_seconds'])) ?></td>
          <td><?= e(sf_pct($row['completion_percent'])) ?></td>
          <td><?= (int)$row['completed'] ? 'Completed' : ((int)$row['qualified_play'] ? 'Qualified' : 'Short') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
