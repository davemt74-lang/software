<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/chrome-extension-releases.php';
require_login();
require_permission('users.manage');

$pdo = db();
if (!$pdo) throw new RuntimeException('Database connection is unavailable.');
homeserver_vp3_ensure_schema($pdo);
chrome_extension_releases_ensure_schema($pdo);
client_release_rollouts_ensure_schema_v110($pdo);
$adminUser=current_user();
$adminUserId=(int)($adminUser['id']??0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');

            if ($action === 'rollout_update') {
                $product=(string)($_POST['product']??'');
                $releaseId=max(0,(int)($_POST['release_id']??0));
                client_release_rollout_update_v110($pdo,$product,$releaseId,$_POST,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice','Controlled rollout updated.');
                redirect(url('/admin/homeserver.php#controlled-rollouts'));
            }

            if ($action === 'chrome_create') {
                $user = current_user();
                $id = chrome_extension_release_create($_POST, $_FILES, (int)($user['id'] ?? 0));
                $initialState=!empty($_POST['is_latest'])?'general_availability':(!empty($_POST['is_published'])?'testing':'draft');
                client_release_rollout_update_v110($pdo,'browser_companion',$id,['lifecycle_state'=>$initialState,'rollout_percent'=>$initialState==='general_availability'?100:0],$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', 'Chrome Extension release uploaded and verified.');
                redirect(url('/admin/homeserver.php#chrome-release-' . $id));
            }
            if (in_array($action, ['chrome_publish','chrome_unpublish','chrome_latest'], true)) {
                $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
                $stateAction = substr($action, 7);
                chrome_extension_release_set_state($releaseId, $stateAction);
                client_release_rollout_sync_legacy_action_v110($pdo,'browser_companion',$releaseId,$stateAction,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', $stateAction === 'latest' ? 'Chrome Extension release is now current for its channel.' : 'Chrome Extension release updated.');
                redirect(url('/admin/homeserver.php#chrome-extension-releases'));
            }
            if ($action === 'chrome_delete') {
                $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
                client_release_rollout_delete_v110($pdo,'browser_companion',$releaseId,$adminUserId);
                chrome_extension_release_delete($releaseId);
                flash('notice', 'Chrome Extension release and stored ZIP deleted.');
                redirect(url('/admin/homeserver.php#chrome-extension-releases'));
            }

            if ($action === 'create') {
                $user = current_user();
                $id = homeserver_vp3_create_release($_POST, $_FILES, (int)($user['id'] ?? 0));
                $initialState=!empty($_POST['is_latest'])?'general_availability':(!empty($_POST['is_published'])?'testing':'draft');
                client_release_rollout_update_v110($pdo,'homeserver',$id,['lifecycle_state'=>$initialState,'rollout_percent'=>$initialState==='general_availability'?100:0],$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', 'HomeServer release uploaded and verified.');
                redirect(url('/admin/homeserver.php#homeserver-release-' . $id));
            }
            $releaseId = max(0, (int)($_POST['release_id'] ?? 0));
            if (in_array($action, ['publish','unpublish','latest'], true)) {
                homeserver_vp3_set_release_state($releaseId, $action);
                client_release_rollout_sync_legacy_action_v110($pdo,'homeserver',$releaseId,$action,$adminUserId);
                if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
                flash('notice', $action === 'latest' ? 'HomeServer release is now the current release.' : 'HomeServer release updated.');
                redirect(url('/admin/homeserver.php#homeserver-releases'));
            }
            if ($action === 'delete') {
                client_release_rollout_delete_v110($pdo,'homeserver',$releaseId,$adminUserId);
                homeserver_vp3_delete_release($releaseId);
                flash('notice', 'HomeServer release and stored binaries deleted.');
                redirect(url('/admin/homeserver.php#homeserver-releases'));
            }
            throw new RuntimeException('Unsupported client release action.');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$chromeReleases = chrome_extension_releases();
$chromeLatest = chrome_extension_latest_release('stable');
$selectedChromeChannel = strtolower((string)($_POST['channel'] ?? 'stable'));
if (!chrome_extension_release_channel_valid($selectedChromeChannel)) $selectedChromeChannel = 'stable';

$releases = homeserver_vp3_releases();
$latest = homeserver_vp3_latest_release('stable');
$relayUrl = homeserver_vp3_relay_base_url();
$selectedChannel = strtolower((string)($_POST['channel'] ?? 'stable'));
if (!homeserver_vp3_channel_valid($selectedChannel)) $selectedChannel = 'stable';

$phpUploadMax = (string)ini_get('upload_max_filesize');
$phpPostMax = (string)ini_get('post_max_size');
$releaseIntelligence = function_exists('client_release_intelligence_admin_summary_v100')
    ? client_release_intelligence_admin_summary_v100($pdo)
    : ['browser_companion'=>[],'homeserver'=>[]];
$rolloutAdoption=client_release_adoption_summary_v110($pdo);
$rolloutAudit=client_release_audit_recent_v110($pdo,20);
$adminTitle = 'Client Releases';
$adminActive = 'homeserver';
require __DIR__ . '/_header.php';
?>
<div class="admin-section-heading">
  <div>
    <span class="eyebrow">Client Distribution</span>
    <h2>Chrome Extension + HomeServer</h2>
    <p>Manage the downloadable VP3 Browser Companion package and Windows HomeServer releases from one controlled release surface.</p>
  </div>
  <div class="form-actions">
    <a class="button" href="<?= e(url('/chrome-extension-download.php')) ?>" target="_blank" rel="noopener">Download Chrome Extension</a>
    <a class="button" href="<?= e(url('/api/homeserver-release.php')) ?>" target="_blank" rel="noopener">HomeServer Release API</a>
  </div>
</div>

<?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<?php $browserIntel=(array)($releaseIntelligence['browser_companion']??[]);$homeIntel=(array)($releaseIntelligence['homeserver']??[]); ?>
<section class="admin-card" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Release Intelligence</h3><p>Current stable adoption across connected VP3 clients. Version telemetry is account-scoped and contains no browsing history or HomeServer content.</p></div><span class="eyebrow">v1.00</span></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Client</th><th>Current stable</th><th>Tracked clients</th><th>Current / ahead</th><th>Update available</th><th>Unknown</th><th>Channel</th></tr></thead>
    <tbody>
      <tr><td><strong>Browser Companion</strong></td><td><?= ($browserIntel['latest_version']??'')!==''?'v'.e((string)$browserIntel['latest_version']):'Not published' ?></td><td><?= (int)($browserIntel['active_clients']??0) ?></td><td><?= (int)($browserIntel['current_clients']??0) ?></td><td><?= (int)($browserIntel['outdated_clients']??0) ?></td><td><?= (int)($browserIntel['unknown_clients']??0) ?></td><td><?= e((string)($browserIntel['channel']??'stable')) ?></td></tr>
      <tr><td><strong>HomeServer</strong></td><td><?= ($homeIntel['latest_version']??'')!==''?'v'.e((string)$homeIntel['latest_version']):'Not published' ?></td><td><?= (int)($homeIntel['paired_clients']??0) ?></td><td><?= (int)($homeIntel['current_clients']??0) ?></td><td><?= (int)($homeIntel['outdated_clients']??0) ?></td><td><?= (int)($homeIntel['unknown_clients']??0) ?></td><td><?= e((string)($homeIntel['channel']??'stable')) ?></td></tr>
    </tbody>
  </table></div>
</section>

<section class="admin-card" id="controlled-rollouts" style="margin-bottom:24px">
  <div class="admin-card-head"><div><h3>Controlled Rollouts</h3><p>Move each client release through Draft → Testing → Canary/Limited → General Availability. Paused, superseded, and withdrawn releases are never offered to new client cohorts.</p></div><span class="eyebrow">v1.10</span></div>
  <div class="admin-table-wrap"><table class="admin-table">
    <thead><tr><th>Client release</th><th>Adoption</th><th>Lifecycle</th><th>Release guidance</th></tr></thead>
    <tbody>
    <?php foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$productLabel): ?>
      <?php foreach((array)($rolloutAdoption[$product]??[]) as $roll): $rid=(int)$roll['release_id']; $releaseRow=client_release_release_row_v110($pdo,$product,$rid); $meta=$releaseRow?client_release_rollout_for_v110($pdo,$product,$rid,$releaseRow):[]; ?>
      <tr>
        <td><strong><?= e($productLabel) ?> v<?= e((string)$roll['version']) ?></strong><br><small><?= e((string)$roll['channel']) ?> · release #<?= $rid ?></small></td>
        <td><strong><?= (int)$roll['installed_clients'] ?></strong> installed<br><small><?= e(ucwords(str_replace('_',' ',(string)$roll['lifecycle_state']))) ?> · <?= (int)$roll['rollout_percent'] ?>%</small></td>
        <td>
          <form method="post" class="admin-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rollout_update">
            <input type="hidden" name="product" value="<?= e($product) ?>">
            <input type="hidden" name="release_id" value="<?= $rid ?>">
            <div class="form-row">
              <label>State<select name="lifecycle_state">
                <?php foreach(client_release_lifecycle_states_v110() as $state): ?><option value="<?= e($state) ?>" <?= (string)($meta['lifecycle_state']??'draft')===$state?'selected':'' ?>><?= e(ucwords(str_replace('_',' ',$state))) ?></option><?php endforeach; ?>
              </select></label>
              <label>Rollout %<input type="number" name="rollout_percent" min="0" max="100" value="<?= (int)($meta['rollout_percent']??0) ?>"></label>
            </div>
            <label>Summary<input name="summary" maxlength="500" value="<?= e((string)($meta['summary']??'')) ?>" placeholder="What changed in this release"></label>
            <label>Known issues<textarea name="known_issues" rows="2" maxlength="10000"><?= e((string)($meta['known_issues']??'')) ?></textarea></label>
            <label>Compatibility notes<input name="compatibility_notes" maxlength="1000" value="<?= e((string)($meta['compatibility_notes']??'')) ?>" placeholder="OS, architecture, or prerequisite notes"></label>
            <div class="form-actions"><button class="button button-small" type="submit">Update rollout</button></div>
          </form>
        </td>
        <td><small>Canary/Limited cohorts are deterministic per account/client. Choosing General Availability makes this the channel's current public release and supersedes the prior GA release. Selecting an older release as General Availability performs a controlled rollback.</small></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if(empty($rolloutAdoption['browser_companion'])&&empty($rolloutAdoption['homeserver'])): ?><tr><td colspan="4">Upload a client release to begin a controlled rollout.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if($rolloutAudit): ?>
  <div class="admin-card-head" style="margin-top:18px"><div><h3>Release Audit Ledger</h3><p>Recent lifecycle, channel, download, defer, and rollback events.</p></div></div>
  <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>When</th><th>Client</th><th>Release</th><th>Action</th><th>Transition</th></tr></thead><tbody>
    <?php foreach($rolloutAudit as $event): ?><tr><td><?= e((string)$event['created_at']) ?></td><td><?= e((string)$event['product']) ?></td><td><?= (int)($event['release_id']??0)>0?'#'.(int)$event['release_id']:'—' ?></td><td><?= e(ucwords(str_replace('_',' ',(string)$event['action']))) ?></td><td><?= e((string)$event['from_state']) ?><?= (string)$event['to_state']!==''?' → '.e((string)$event['to_state']):'' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section id="chrome-extension-releases">
  <div class="admin-section-heading">
    <div>
      <span class="eyebrow">Browser Companion</span>
      <h2>Chrome Extension Releases</h2>
      <p>Upload verified Manifest V3 ZIP packages, publish release channels, and choose the version served by the public Chrome Extension download button.</p>
    </div>
  </div>

  <div class="admin-grid admin-grid-2">
    <section class="admin-card">
      <div class="admin-card-head"><div><h3>Extension Release Service</h3><p>VP3-managed Browser Companion distribution.</p></div></div>
      <div class="admin-table-wrap"><table class="admin-table"><tbody>
        <tr><td>Current stable</td><td><?= $chromeLatest ? '<strong>v'.e((string)$chromeLatest['version']).'</strong>' : '<strong>Repository fallback</strong><br><small>No managed stable release has been published yet.</small>' ?></td></tr>
        <tr><td>Public endpoint</td><td><a href="<?= e(url('/chrome-extension-download.php')) ?>" target="_blank" rel="noopener">chrome-extension-download.php</a><br><small>The endpoint serves the current managed stable ZIP and falls back to the repository package until one is published.</small></td></tr>
        <tr><td>Storage</td><td><strong>Private</strong><br><small>Uploaded ZIP packages live outside the public web surface and are served only through the managed download endpoint.</small></td></tr>
        <tr><td>Validation</td><td><strong>Manifest V3 + root package contract + SHA256</strong><br><small>Packages must contain manifest.json and the Browser Companion runtime files at ZIP root. Unsafe ZIP paths are rejected.</small></td></tr>
        <tr><td>Upload limit</td><td><strong>64 MB application limit</strong><br><small>PHP/server limits currently report <?= e($phpUploadMax) ?> per file and <?= e($phpPostMax) ?> per POST.</small></td></tr>
      </tbody></table></div>
    </section>

    <section class="admin-card">
      <div class="admin-card-head"><div><h3>Upload Chrome Extension</h3><p>The version is read directly from the package manifest.</p></div></div>
      <form method="post" enctype="multipart/form-data" class="admin-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="chrome_create">
        <div class="form-row">
          <label>Channel<select name="channel"><option value="stable" <?= $selectedChromeChannel==='stable'?'selected':'' ?>>Stable</option><option value="beta" <?= $selectedChromeChannel==='beta'?'selected':'' ?>>Beta</option><option value="dev" <?= $selectedChromeChannel==='dev'?'selected':'' ?>>Development</option></select></label>
          <label>Browser Companion ZIP<input type="file" name="chrome_zip" accept=".zip,application/zip" required></label>
        </div>
        <label>Release notes<textarea name="release_notes" rows="5" maxlength="10000"><?= e((string)($_POST['action'] ?? '') === 'chrome_create' ? (string)($_POST['release_notes'] ?? '') : '') ?></textarea></label>
        <div class="admin-check-grid">
          <label><input type="checkbox" name="is_published" value="1" <?= (string)($_POST['action'] ?? '') !== 'chrome_create' || !empty($_POST['is_published'])?'checked':'' ?>> Publish immediately</label>
          <label><input type="checkbox" name="is_latest" value="1" <?= (string)($_POST['action'] ?? '') !== 'chrome_create' || !empty($_POST['is_latest'])?'checked':'' ?>> Make current for channel</label>
        </div>
        <div class="form-actions"><button class="button primary" type="submit">Upload &amp; Verify Extension</button></div>
      </form>
    </section>
  </div>

  <section class="admin-card" style="margin-top:18px">
    <div class="admin-card-head"><div><h3>Chrome Extension History</h3><p><?= count($chromeReleases) ?> managed release<?= count($chromeReleases) === 1 ? '' : 's' ?></p></div></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Release</th><th>Package</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($chromeReleases as $release): $id=(int)$release['id']; $published=(int)$release['is_published']===1; ?>
        <tr id="chrome-release-<?= $id ?>">
          <td><strong>v<?= e((string)$release['version']) ?></strong><br><small><?= e((string)$release['channel']) ?> · <?= e((string)$release['created_at']) ?></small><?php if(trim((string)$release['release_notes'])!==''): ?><br><small><?= e(mb_strimwidth((string)$release['release_notes'],0,140,'…')) ?></small><?php endif; ?></td>
          <td><?= $published?'<a href="'.e(url('/chrome-extension-download.php')).'">Download current endpoint</a>':'<strong>Stored draft</strong>' ?><br><small><?= e((string)$release['package_name']) ?><br><?= number_format((int)$release['package_size']) ?> bytes</small></td>
          <td><strong>Manifest V<?= (int)$release['manifest_version'] ?></strong><br><small>SHA256 <?= e(substr((string)$release['package_sha256'],0,16)) ?>…</small></td>
          <td><?= $published?'Published':'Draft' ?><?= (int)$release['is_latest']===1?' · Current':'' ?></td>
          <td>
            <div class="form-actions">
              <?php if(!$published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_publish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Publish</button></form><?php endif; ?>
              <?php if((int)$release['is_latest']!==1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_latest"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Make Current</button></form><?php endif; ?>
              <?php if($published): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_unpublish"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Unpublish</button></form><?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this Chrome Extension release and stored ZIP package?');"><?= csrf_field() ?><input type="hidden" name="action" value="chrome_delete"><input type="hidden" name="release_id" value="<?= $id ?>"><button class="button button-small" type="submit">Delete</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$chromeReleases): ?><tr><td colspan="5">No managed Chrome Extension releases uploaded yet. The public endpoint is still using the repository Browser Companion package.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</section>

<section id="homeserver-releases" style="margin-top:32px">
  <div class="admin-section-heading">
    <div>
      <span class="eyebrow">HomeServer</span>
      <h2>Windows HomeServer Releases</h2>
      <p>Upload verified Windows builds, publish release channels, and control which HomeServer version VP3 offers to connected users.</p>
    </div>
  </div>

  <div class="admin-grid admin-grid-2">
    <section class="admin-card">
      <div class="admin-card-head"><div><h3>HomeServer Release Service</h3><p>VP3-managed HomeServer distribution.</p></div></div>
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
          <label>Version<input name="version" maxlength="64" required placeholder="0.17.1" value="<?= e((string)($_POST['action'] ?? '') === 'create' ? (string)($_POST['version'] ?? '') : '') ?>"></label>
          <label>Channel<select name="channel"><option value="stable" <?= $selectedChannel==='stable'?'selected':'' ?>>Stable</option><option value="beta" <?= $selectedChannel==='beta'?'selected':'' ?>>Beta</option><option value="dev" <?= $selectedChannel==='dev'?'selected':'' ?>>Development</option></select></label>
        </div>
        <label>Portable EXE<input type="file" name="portable_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
        <label>Installer EXE<input type="file" name="installer_exe" accept=".exe,application/vnd.microsoft.portable-executable"></label>
        <label>Release notes<textarea name="release_notes" rows="5" maxlength="10000"><?= e((string)($_POST['action'] ?? '') === 'create' ? (string)($_POST['release_notes'] ?? '') : '') ?></textarea></label>
        <div class="admin-check-grid">
          <label><input type="checkbox" name="is_published" value="1" <?= (string)($_POST['action'] ?? '') !== 'create' || !empty($_POST['is_published'])?'checked':'' ?>> Publish immediately</label>
          <label><input type="checkbox" name="is_latest" value="1" <?= (string)($_POST['action'] ?? '') !== 'create' || !empty($_POST['is_latest'])?'checked':'' ?>> Make current for channel</label>
        </div>
        <div class="form-actions"><button class="button primary" type="submit">Upload &amp; Verify HomeServer</button></div>
      </form>
    </section>
  </div>

  <section class="admin-card" style="margin-top:18px">
    <div class="admin-card-head"><div><h3>HomeServer History</h3><p><?= count($releases) ?> managed release<?= count($releases) === 1 ? '' : 's' ?></p></div></div>
    <div class="admin-table-wrap"><table class="admin-table">
      <thead><tr><th>Release</th><th>Portable</th><th>Installer</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($releases as $release): $id=(int)$release['id']; $published=(int)$release['is_published']===1; ?>
        <tr id="homeserver-release-<?= $id ?>">
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
</section>
<?php require __DIR__ . '/_footer.php'; ?>
