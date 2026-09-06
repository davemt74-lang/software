<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

if (!$pdo || $userId < 1) {
    flash('error', 'Production workspace is unavailable.');
    redirect(url('/account.php'));
}

$memberships = function_exists('artist_workspace_v104_memberships_for_user')
    ? artist_workspace_v104_memberships_for_user($pdo, $userId)
    : [];
$producerMemberships = array_values(array_filter(
    $memberships,
    static fn(array $membership): bool => (string)($membership['team_role'] ?? '') === 'producer'
));
$assignedCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tracks WHERE producer_user_id=?');
$assignedCountStmt->execute([$userId]);
$assignedCount = (int)$assignedCountStmt->fetchColumn();

// Producer is now a contextual Team relationship/direct track assignment. The
// old global producer account type is intentionally not required here.
if (!subscription_is_internal_admin($user) && !$producerMemberships && $assignedCount < 1) {
    http_response_code(403);
    exit('No Artist workspace or track has granted Producer access to this account.');
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

$stemEntitled = subscription_is_internal_admin($user)
    || !subscription_schema_ready()
    || (($subscription = subscription_current($user)) && subscription_has_entitlement($user, 'legacy.permissions'))
    || subscription_has_entitlement($user, 'stem_editor.access');

$adminTitle = 'Production Workspace';
$adminActive = 'producer-tracks';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Team Production</span>
      <h2>Shared Tracks</h2>
      <p class="muted">
        Only tracks explicitly assigned to your account appear here. Producer
        access is scoped to those assignments and does not grant global Admin access.
      </p>
    </div>
    <?php if ($memberships): ?>
      <div class="actions"><a class="btn" href="<?= e(url('/admin/team-workspaces.php')) ?>">Team Workspaces</a></div>
    <?php endif; ?>
  </div>

  <?php if (!$stemEntitled && $tracks): ?>
    <div class="notice">Your Artist relationships are active, but Stem Editor is not included in your current package. Track details remain available.</div>
  <?php endif; ?>

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
            <?php if (!(int)$track['is_published']): ?><span class="status">Draft</span><?php endif; ?>
          </td>
          <td><?= e((string)($track['owner_name'] ?: 'Stonefellow')) ?></td>
          <td><?= e((string)$track['album']) ?></td>
          <td>
            <span class="muted">
              <?= (int)$track['stem_count'] ?> tracks ·
              <?= e(rtrim(rtrim(number_format((float)($track['project_tempo'] ?: $track['tempo_bpm'] ?: 120), 2), '0'), '.')) ?> BPM ·
              <?= e((string)($track['time_signature'] ?: '4/4')) ?>
            </span>
          </td>
          <td><?= e(date('M j, Y g:i A', strtotime((string)$track['updated_at']))) ?></td>
          <td class="actions">
            <a class="btn" href="<?= e(url('/admin/track.php?id='.(int)$track['id'])) ?>">Song Details</a>
            <?php if ($stemEntitled): ?>
              <a class="btn primary desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.(int)$track['id'])) ?>">Edit in Stem Studio</a>
              <a class="btn desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.(int)$track['id'].'&export=1')) ?>">Export MP3/WAV</a>
            <?php else: ?>
              <a class="btn" href="<?= e(url('/subscription.php')) ?>">Stem Editor Upgrade</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$tracks): ?>
        <tr><td colspan="6" class="muted">No tracks have been explicitly shared with this Producer account yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>