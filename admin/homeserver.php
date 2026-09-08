<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();
require_permission('users.manage');

$pdo = db();
if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
homeserver_vp3_ensure_schema($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'create') {
                $user = current_user();
                $id = homeserver_vp3_create_release($_POST, $_FILES, (int)($user['id'] ?? 0));
                flash('notice', 'HomeServer release uploaded and verified.');
                redirect(url('/admin/homeserver.php#release-' . $id));
            }
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            if (in_array($action, ['publish','unpublish','latest'], true)) {
                homeserver_vp3_set_release_state($releaseId, $action);
                flash('notice', $action === 'latest' ? 'HomeServer release is now the current release.' : 'HomeServer release updated.');
                redirect(url('/admin/homeserver.php'));
            }
            if ($action === 'delete') {
                homeserver_vp3_delete_release($releaseId);
                flash('notice', 'HomeServer release and stored binaries deleted.');
                redirect(url('/admin/homeserver.php'));
            }
            throw new RuntimeException('Unsupported HomeServer release action.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$releases = homeserver_vp3_releases();
$latest = homeserver_vp3_latest_release('stable');
$relayUrl = homeserver_vp3_relay_base_url();
$selectedChannel = strtolower((string)($_POST['channel'] ?? 'stable'));
if (!homeserver_vp3_channel_valid($selectedChannel)) $selectedChannel = 'stable';
$phpUploadMax = (string)ini_get('upload_max_filesize');
$phpPostMax = (string)ini_get('post_max_size');
$adminTitle = 'HomeServer Releases';
$adminActive = 'homeserver';
require __DIR__ . '/_header.php';
?>
<div class="admin-section-heading">
  <div>
    <span class="eyebrow">HomeServer</span>
    <h2>Desktop App Releases</h2>
    <p>Upload verified Windows builds, publish release channels, and control which HomeServer version VP3 offers to connected users.</p>
  </div>
  <a class="button" href="<?= e(url('/api/homeserver-release.php')) ?>" target="_blank" rel="noopener">Release API</a>
</div>

<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<div class="admin-grid admin-grid-2">
  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Release Service</h3><p>VP3-managed HomeServer distribution.</p></div></div>
    <div class="admin-table-wrap"><table class="admin-table"><tbody>
      <tr><td>Relay</td><td><?= $relayUrl !== '' ? '<strong>Configured</strong><br><small>'.e($relayUrl).'</small>' : '<strong>Not configured</strong><br><small>Set homeserver.relay_base_url or VP3_HOMESERVER_RELAY_URL.</small>' ?></td></tr>
      <tr><td>Current stable</td><td><?= $latest ? '<strong>v'.e((string)$latest['version']).'</strong>' : 'No published release' ?></td></tr>
      <tr><td>Storage</td><td><strong>Private</strong><br><small>Executable files are outside the public web surface and served only through the managed download endpoint.</small></td></tr>
      <tr><td>Validation</td><td><strong>Windows PE + SHA256</strong><br><small>Files must contain valid MZ and PE signatures and are never executed by VP3.</small></td></tr>
      <tr><td>PHP upload limits</td><td><strong><?= e($phpUploadMax) ?> per file</strong><br><small>POST limit <?= e($phpPostMax) ?>. Server limits must also allow the selected executable size.</small></td></tr>
    </tbody></table></div>
  </section>

  <section class="admin-card">
    <div class="admin-card-head"><div><h3>Upload HomeServer</h3><p>VP3 accepts up to 512 MB per executable; PHP/server limits may be lower.</p></div></div>
    <form method="post" enctype="multipart/form-data" class="admin-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <label>Version<input name="version" maxlength="64" required placeholder="0.17.1" value="<?= e((string)($_POST['version'] ?? '')) ?>"></label>
        <label>Channel<select name="channel"><option value="stable" <?= $selectedChannel==='stable'?'selected':'' ?>>Stable</option><option value="beta" <?= $selectedChannel==='beta'?'selected':'' ?>>Beta</option><option value="dev" <?= $selectedChannel==='dev'?'selected':'' ?>>Development</option></select></label>
      </div>
      <label>Portable EXE<input type="file" name="portable_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
      <label>Installer EXE<input type="file" name="installer_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
      <label>Release notes<textarea name="release_notes" rows="5" maxlength="10000"><?= e((string)($_POST['release_notes'] ?? '')) ?></textarea></label>
      <div class="admin-check-grid">
        <label><input type="checkbox" name="is_published" value="1" <?= !isset($_POST['action']) || !empty($_POST['is_published'])?'checked':'' ?>> Publish immediately</label>
        <label><input type="checkbox" name="is_latest" value="1" <?= !isset($_POST['action']) || !empty($_POST['is_latest'])?'checked':'' ?>> Make current for channel</label>
      </div>
      <div class="form-actions"><button class="button primary" type="submit">Upload &amp; Verify Release</button></div>
    </form>
  </section>
</div>

<section class="admin-card" style="margin-top:18px">
  <div class="admin-card-head"><div><h3>Release History</h3><p><?= count($releases) ?> managed release<?= count($releases) === 1 ? '' : 's' ?></p></div></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Release</th><th>Portable</th><th>Installer</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($releases as $release): $id=(int)$release['id']; $published=(int)$release['is_published']===1; ?>
      <tr id="release-<?= $id ?>">
        <td><strong>v<?= e((string)$release['version']) ?></strong><br><small><?= e((string)$release['channel']) ?> · <?= e((string)$release['created_at']) ?></small><?php if(trim((string)$release['release_notes'])!==''): ?><br><small><?= e(mb_strimwidth((string)$release['release_notes'],0,140,'…')) ?></small><?php endif; ?></td>
        <td><?php if((string)$release['portable_path']!==''): ?><?= $published?'<a href="'.e(url('/homeserver-download.php?id='.$id.'&type=portable')).'">Download</a>':'<strong>Stored draft</strong>' ?><br><small><?= number_format((int)$release['portable_size']) ?> bytes<br><?= e(substr((string)$release['portable_sha256'],0,16)) ?>…</small><?php else: ?>—<?php endif; ?></td>
        <td><?php if((string)$release['installer_path']!==''): ?><?= $published?'<a href="'.e(url('/homeserver-download.php?id='.$id.'&type=installer')).'">Download</a>':'<strong>Stored draft</strong>' ?><br><small><?= number_format((int)$release['installer_size']) ?> bytes<br><?= e(substr((string)$release['installer_sha256'],0,16)) ?>…</small><?php else: ?>—<?php endif; ?></td>
        <td><?= $published?'Published':'Draft' ?><?= (int)$release['is_latest']===1?' · Current':'' ?></td>
        <td>
          <div class="form-actions">
            <?php if(!$published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Publish</button></form><?php endif; ?>
            <?php if((int)$release['is_latest']!==1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="latest"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Make Current</button></form><?php endif; ?>
            <?php if($published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Unpublish</button></form><?php endif; ?>
            <form method="post" onsubmit="return confirm('Delete this HomeServer release and both stored executable files?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Delete</button></form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$releases): ?><tr><td colspan="5">No HomeServer releases uploaded yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>
<?php require __DIR__ . '/_footer.php'; ?>
