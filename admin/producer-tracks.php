<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('producer.access');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

if (!$pdo || $userId < 1) {
    flash('error', 'Producer workspace is unavailable.');
    redirect(url('/account.php'));
}

$stmt = $pdo->prepare(
    'SELECT
        t.*,
        owner.display_name AS owner_name,
        p.project_name,
        p.tempo_bpm AS project_tempo,
        p.time_signature,
        (
            SELECT COUNT(*)
            FROM track_stems s
            WHERE s.track_id=t.id
              AND s.is_active=1
        ) AS stem_count
     FROM tracks t
     LEFT JOIN users owner
       ON owner.id=t.owner_user_id
     LEFT JOIN track_projects p
       ON p.track_id=t.id
     WHERE t.producer_user_id=?
     ORDER BY t.updated_at DESC,t.title ASC'
);
$stmt->execute([$userId]);
$tracks = $stmt->fetchAll();

$adminTitle = 'Producer Workspace';
$adminActive = 'producer-tracks';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Producer Workspace</span>
      <h2>Shared Tracks</h2>
      <p class="muted">
        Tracks specifically shared with your Producer account appear here.
        Access is scoped to these assignments only.
      </p>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Track</th>
          <th>Owner</th>
          <th>Album</th>
          <th>Production</th>
          <th>Updated</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tracks as $track): ?>
        <tr>
          <td>
            <strong><?= e((string)$track['title']) ?></strong>
            <?php if (!(int)$track['is_published']): ?>
              <span class="status">Draft</span>
            <?php endif; ?>
          </td>
          <td><?= e((string)($track['owner_name'] ?: 'Stonefellow')) ?></td>
          <td><?= e((string)$track['album']) ?></td>
          <td>
            <span class="muted">
              <?= (int)$track['stem_count'] ?> tracks
              ·
              <?= e(
                  rtrim(
                      rtrim(
                          number_format(
                              (float)(
                                  $track['project_tempo']
                                  ?: $track['tempo_bpm']
                                  ?: 120
                              ),
                              2
                          ),
                          '0'
                      ),
                      '.'
                  )
              ) ?> BPM
              ·
              <?= e((string)($track['time_signature'] ?: '4/4')) ?>
            </span>
          </td>
          <td><?= e(date('M j, Y g:i A', strtotime((string)$track['updated_at']))) ?></td>
          <td class="actions">
            <a class="btn" href="<?= e(url('/admin/track.php?id='.(int)$track['id'])) ?>">Song Details</a>
            <a class="btn primary desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.(int)$track['id'])) ?>">Edit in Stem Studio</a>
            <a class="btn desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.(int)$track['id'].'&export=1')) ?>">Export MP3/WAV</a>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$tracks): ?>
        <tr>
          <td colspan="6" class="muted">
            No tracks have been shared with this Producer account yet.
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
