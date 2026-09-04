<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
midi_v217_require_manage();

$pdo = db();
if (!$pdo) {
    flash('error','Database unavailable.');
    redirect(url('/admin/index.php'));
}

$schemaReady = midi_v217_schema_ready($pdo);
$permissionsReady = (string)setting('midi_permissions_seed_v217','') === '1';
$setupReady = $schemaReady && $permissionsReady;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error','Session expired. Try again.');
        redirect(url('/admin/midi.php'));
    }
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'toggle') {
        $enable = isset($_POST['enabled']);
        if ($enable && !$setupReady) {
            flash('error','Run the Stonefellow database upgrade before enabling MIDI Studio.');
            redirect(url('/upgrade.php'));
        }
        save_setting('midi_feature_enabled_v217',$enable ? '1' : '0');
        flash('notice',$enable ? 'MIDI Studio enabled for permitted users.' : 'MIDI Studio disabled for all users.');
    }
    redirect(url('/admin/midi.php'));
}

$enabled = midi_v217_feature_enabled();
$roles = user_roles();
$roleAccess = [];
if (permissions_schema_ready()) {
    try {
        $stmt = $pdo->prepare('SELECT role FROM role_permissions WHERE permission_key=? ORDER BY role');
        $stmt->execute(['midi.access']);
        $roleAccess = array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
}
$projectCount = 0;
if ($schemaReady) {
    try { $projectCount = (int)$pdo->query('SELECT COUNT(*) FROM stem_midi_projects_v217')->fetchColumn(); } catch (Throwable $e) {}
}

$adminTitle = 'MIDI';
$adminActive = 'midi';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div style="display:flex;gap:24px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap">
    <div style="max-width:720px">
      <p class="muted" style="margin-top:0">Studio Feature</p>
      <h2 style="margin:0 0 10px">MIDI Studio</h2>
      <p class="muted" style="line-height:1.65">MIDI is protected by two gates. This switch controls the feature globally. <strong>MIDI Studio</strong> permission controls which account types can use it. Both must be enabled before MIDI assets or MIDI APIs become available to a user.</p>
    </div>
    <div style="min-width:220px;padding:18px;border:1px solid rgba(255,255,255,.12);border-radius:16px">
      <span class="muted">Current status</span><br>
      <strong style="font-size:22px"><?= $enabled ? 'ENABLED' : 'DISABLED' ?></strong>
    </div>
  </div>

  <?php if (!$setupReady): ?>
    <div style="margin-top:20px;padding:16px;border:1px solid rgba(255,190,95,.35);border-radius:14px">
      <strong>Database upgrade required</strong>
      <p class="muted" style="margin:8px 0 14px">MIDI remains unavailable until its project table and permission defaults are installed. Opening this page does not modify the database.</p>
      <a class="btn" href="<?= e(url('/upgrade.php')) ?>">Run Stonefellow Upgrade</a>
    </div>
  <?php endif; ?>

  <form method="post" style="margin-top:24px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle">
    <label style="display:flex;gap:14px;align-items:center;padding:18px;border:1px solid rgba(255,255,255,.12);border-radius:14px;max-width:620px">
      <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:22px;height:22px">
      <span><strong>Enable MIDI for permitted users</strong><br><span class="muted">Turning this off immediately blocks MIDI UI and API access. Enabling requires the MIDI database upgrade.</span></span>
    </label>
    <div class="actions" style="margin-top:16px"><button class="btn primary" type="submit">Save MIDI Feature</button></div>
  </form>
</div>

<div class="panel">
  <h2>Role Access</h2>
  <p class="muted">MIDI access is managed by the existing account-type permission matrix. Admin always retains management access.</p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0">
    <?php foreach ($roles as $role=>$label): ?>
      <?php $allowed = $role === 'admin' || in_array($role,$roleAccess,true); ?>
      <span style="padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.12);opacity:<?= $allowed ? '1' : '.45' ?>"><?= e($label) ?> · <?= $allowed ? 'ON' : 'OFF' ?></span>
    <?php endforeach; ?>
  </div>
  <a class="btn" href="<?= e(url('/admin/permissions.php')) ?>">Manage Role Permissions</a>
</div>

<div class="panel">
  <h2>v217 MIDI Foundation</h2>
  <div class="table-wrap"><table><tbody>
    <tr><td><strong>Project storage</strong></td><td><?= $schemaReady ? 'Ready' : 'Upgrade required' ?></td></tr>
    <tr><td><strong>Permission defaults</strong></td><td><?= $permissionsReady ? 'Ready' : 'Upgrade required' ?></td></tr>
    <tr><td><strong>MIDI projects stored</strong></td><td><?= (int)$projectCount ?></td></tr>
    <tr><td><strong>Timing resolution</strong></td><td>960 PPQ</td></tr>
    <tr><td><strong>Hardware input</strong></td><td>Web MIDI where supported; on-screen keyboard remains available</td></tr>
    <tr><td><strong>Initial instruments</strong></td><td>Poly Synth + Drum Rack foundation</td></tr>
  </tbody></table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
