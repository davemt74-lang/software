<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('permissions.manage');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}
if (!subscription_schema_ready($pdo)) subscription_ensure_schema($pdo);

seed_permission_catalog();
personal_capability_seed_v242();
$catalog = personal_capability_admin_catalog_v242(permission_v105_catalog_for_admin());
$packages = subscription_packages(false);

// These capabilities are internal platform authority, never something a paid
// customer package can grant. Admin accounts always receive them implicitly.
$adminOnly = [
    'admin.access' => true,
    'users.manage' => true,
    'permissions.manage' => true,
    'ai.manage' => true,
];

// Keep extension permissions synchronized so package entitlements can use the
// same stable permission keys as the rest of the application.
$catalogUpsert = $pdo->prepare(
    'INSERT INTO permissions (permission_key,label,description,category,sort_order)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)'
);
foreach ($catalog as $key => $permission) {
    $catalogUpsert->execute([$key,$permission['label'],$permission['description'],$permission['category'],$permission['sort_order']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/permissions.php'));
    }

    try {
        $selected = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];
        $pdo->beginTransaction();
        $upsert = $pdo->prepare(
            'INSERT INTO package_entitlements (package_id,capability_key,is_enabled)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),updated_at=NOW()'
        );

        foreach ($packages as $package) {
            $packageId = (int)$package['id'];
            if ($packageId < 1) continue;
            $packageSelection = is_array($selected[$packageId] ?? null) ? $selected[$packageId] : [];

            foreach ($catalog as $permissionKey => $permission) {
                if (isset($adminOnly[$permissionKey])) continue;
                $entitlementKey = subscription_permission_key((string)$permissionKey);
                $enabled = array_key_exists((string)$permissionKey, $packageSelection) ? 1 : 0;
                $upsert->execute([$packageId,$entitlementKey,$enabled]);
            }
        }

        $pdo->commit();
        flash('notice', 'Package permissions updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/permissions.php'));
}

$assigned = [];
if ($packages) {
    $packageIds = array_values(array_filter(array_map(static fn(array $row): int => (int)$row['id'], $packages)));
    if ($packageIds) {
        $placeholders = implode(',', array_fill(0, count($packageIds), '?'));
        $stmt = $pdo->prepare("SELECT package_id,capability_key,is_enabled FROM package_entitlements WHERE package_id IN ({$placeholders})");
        $stmt->execute($packageIds);
        foreach ($stmt->fetchAll() as $row) {
            $assigned[(int)$row['package_id']][(string)$row['capability_key']] = (int)$row['is_enabled'] === 1;
        }
    }
}

$adminTitle = 'Permissions';
$adminActive = 'permissions';
require __DIR__ . '/_header.php';
?>
<style>
.permission-model{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:16px}.permission-model-card{border:1px solid var(--admin-line);border-radius:10px;background:#fff;padding:15px}.permission-model-card small{display:block;color:#7c8590;font-size:.59rem;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.permission-model-card strong{display:block;margin-top:6px;font-size:.95rem;color:#16191d}.permission-model-card p{margin:6px 0 0;color:#6f7882;font-size:.63rem;line-height:1.5}.permission-table th:not(:first-child),.permission-table td:not(:first-child){text-align:center;min-width:110px}.permission-table td:first-child{min-width:320px}.permission-category th{background:#f7f8f9!important;color:#68717b!important;font-size:.59rem!important;text-transform:uppercase;letter-spacing:.06em}.permission-key{display:block;margin-top:3px;color:#8a929a;font-size:.55rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.permission-admin-only{display:inline-flex;padding:3px 7px;border-radius:999px;background:#f0f2f4;color:#59616a;font-size:.57rem;font-weight:800}.permission-check{width:17px;height:17px}.permission-note{margin:10px 0 0;color:#717a84;font-size:.63rem;line-height:1.55}.permission-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}@media(max-width:900px){.permission-model{grid-template-columns:1fr}.permission-table td:first-child{min-width:240px}}
</style>

<div class="content-library-heading">
  <div>
    <span class="status">Package access</span>
    <h2>Permissions</h2>
    <p class="muted">Customer permissions are assigned by subscription package. Global account authority is limited to Customer and Admin; Artist, Manager and Producer access comes from workspace relationships.</p>
  </div>
  <div class="actions"><a class="btn" href="<?= e(url('/admin/users.php')) ?>">Users</a><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Packages</a></div>
</div>

<div class="permission-model">
  <section class="permission-model-card"><small>Customer</small><strong>Package-driven access</strong><p>The customer account itself carries no feature-role matrix. Its active package determines available capabilities.</p></section>
  <section class="permission-model-card"><small>Admin</small><strong>Full internal authority</strong><p>Admin is the only privileged global account type and always retains internal administration permissions.</p></section>
  <section class="permission-model-card"><small>Team relationships</small><strong>Context, not account type</strong><p>Artist ownership and Manager/Producer delegation belong to the applicable workspace relationship, not the global user record.</p></section>
</div>

<section class="admin-card">
  <div class="admin-card-head"><div><h3>Package permission matrix</h3><p>Changes here update the same package entitlements used by Packages. Limits and non-permission capabilities remain managed on the package itself.</p></div><span class="muted"><?= number_format(count($packages)) ?> package<?= count($packages)===1?'':'s' ?></span></div>
  <?php if (!$packages): ?>
    <div style="padding:18px"><p class="muted">No subscription packages are configured yet.</p><a class="btn primary" href="<?= e(url('/admin/packages.php?new=1')) ?>">Create Package</a></div>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="admin-table-wrap">
      <table class="admin-table permission-table">
        <thead><tr><th>Permission</th><?php foreach ($packages as $package): ?><th><?= e((string)$package['name']) ?><br><small><?= (int)$package['is_active']===1?'Active':'Inactive' ?></small></th><?php endforeach; ?><th>Admin</th></tr></thead>
        <tbody>
        <?php $lastCategory=''; foreach ($catalog as $key => $permission): $category=(string)($permission['category']??'General'); $isAdminOnly=isset($adminOnly[$key]); if($category!==$lastCategory):$lastCategory=$category; ?>
          <tr class="permission-category"><th colspan="<?= count($packages)+2 ?>"><?= e($category) ?></th></tr>
        <?php endif; ?>
          <tr>
            <td><strong><?= e((string)$permission['label']) ?></strong><br><span class="muted"><?= e((string)$permission['description']) ?></span><span class="permission-key"><?= e((string)$key) ?></span></td>
            <?php foreach ($packages as $package): $packageId=(int)$package['id']; $entitlementKey=subscription_permission_key((string)$key); ?>
              <td><?php if($isAdminOnly): ?><span class="permission-admin-only">Admin only</span><?php else: ?><input class="permission-check" type="checkbox" name="permissions[<?= $packageId ?>][<?= e((string)$key) ?>]" value="1" <?= !empty($assigned[$packageId][$entitlementKey])?'checked':'' ?> aria-label="<?= e((string)$package['name'].' · '.(string)$permission['label']) ?>"><?php endif; ?></td>
            <?php endforeach; ?>
            <td><input class="permission-check" type="checkbox" checked disabled aria-label="Admin always allowed"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="permission-note">Admin-only controls cannot be enabled by a package. Package permissions govern customer feature access; workspace relationship checks still apply where a feature acts on Artist/Manager/Producer-owned resources.</p>
    <div class="permission-actions"><a class="btn" href="<?= e(url('/admin/packages.php')) ?>">Package limits &amp; pricing</a><button class="btn primary" type="submit">Save Package Permissions</button></div>
  </form>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/_footer.php'; ?>