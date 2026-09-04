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

seed_permission_catalog();
$catalog = permission_v105_catalog_for_admin();
$roles = user_roles();

// v105 extends the legacy PHP catalog without rewriting its mature permission
// subsystem. Keep the DB permission catalog synchronized so Admin can edit the
// new Agent Operations permissions just like every existing permission.
$catalogUpsert = $pdo->prepare(
    'INSERT INTO permissions (permission_key,label,description,category,sort_order)
     VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),category=VALUES(category),sort_order=VALUES(sort_order)'
);
foreach (permission_v105_catalog() as $key=>$permission) {
    $catalogUpsert->execute([$key,$permission['label'],$permission['description'],$permission['category'],$permission['sort_order']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/permissions.php'));
    }

    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM role_permissions WHERE role = ?');
        $insert = $pdo->prepare('INSERT INTO role_permissions (role, permission_key) VALUES (?, ?)');

        foreach (array_keys($roles) as $role) {
            if ($role === 'admin') {
                continue;
            }

            $delete->execute([$role]);
            $selected = $_POST['permissions'][$role] ?? [];
            if (!is_array($selected)) {
                $selected = [];
            }

            foreach ($selected as $permission) {
                $permission = (string)$permission;
                if (isset($catalog[$permission])) {
                    $insert->execute([$role, $permission]);
                }
            }
        }

        // Admin always retains every permission, including v105 extensions.
        $delete->execute(['admin']);
        foreach (array_keys($catalog) as $permission) {
            $insert->execute(['admin', $permission]);
        }

        $pdo->commit();
        flash('notice', 'Permissions updated.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/permissions.php'));
}

$assigned = [];
$stmt = $pdo->query('SELECT role, permission_key FROM role_permissions');
foreach ($stmt->fetchAll() as $row) {
    $assigned[(string)$row['role']][(string)$row['permission_key']] = true;
}

$adminTitle = 'Permissions';
$adminActive = 'permissions';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <h2>Account Type Permissions</h2>
  <p class="muted">Choose which backend features each account type can access. Admin permissions are always enabled and cannot be removed.</p>

  <form method="post">
    <?= csrf_field() ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Permission</th>
            <?php foreach ($roles as $role => $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($catalog as $key => $permission): ?>
          <tr>
            <td>
              <strong><?= e($permission['label']) ?></strong><br>
              <span class="muted"><?= e($permission['description']) ?></span>
            </td>
            <?php foreach ($roles as $role => $label): ?>
              <td>
                <?php if ($role === 'admin'): ?>
                  <input type="checkbox" checked disabled aria-label="Admin always allowed">
                <?php else: ?>
                  <input type="checkbox" name="permissions[<?= e($role) ?>][]" value="<?= e($key) ?>" <?= isset($assigned[$role][$key]) ? 'checked' : '' ?>>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="actions" style="margin-top:18px">
      <button class="btn primary" type="submit">Save Permissions</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
