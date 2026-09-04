<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('users.manage');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$current = current_user();
$roleOptions = user_roles();
$editId = (int)($_GET['edit'] ?? 0);
$showNewForm = isset($_GET['new']);
$showForm = $showNewForm || $editId > 0;
$editing = null;
$editingRoles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/users.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        if ($id === (int)($current['id'] ?? 0)) {
            flash('error', 'You cannot delete your own account.');
            redirect(url('/admin/users.php'));
        }

        $targetStmt = $pdo->prepare(
            'SELECT id,role,is_active,avatar_path FROM users WHERE id=? LIMIT 1'
        );
        $targetStmt->execute([$id]);
        $target = $targetStmt->fetch();

        if ($target) {
            $targetRoles = user_account_types_for_user_id(
                (int)$target['id'],
                (string)$target['role']
            );
            if (
                in_array('admin', $targetRoles, true) &&
                (int)$target['is_active'] === 1 &&
                active_admin_user_count($pdo) <= 1
            ) {
                flash('error', 'You cannot delete the last active Admin account.');
                redirect(url('/admin/users.php'));
            }

            delete_local_upload((string)($target['avatar_path'] ?? ''));
        }

        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
        flash('notice', 'User deleted.');
        redirect(url('/admin/users.php'));
    }

    try {
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $avatarPath = trim((string)($_POST['existing_avatar_path'] ?? ''));
        $selectedRolesInput = $_POST['roles'] ?? [];
        $selectedRoles = [];

        if (!is_array($selectedRolesInput)) {
            $selectedRolesInput = [];
        }
        foreach ($selectedRolesInput as $selectedRole) {
            $selectedRole = trim((string)$selectedRole);
            if (valid_role($selectedRole)) {
                $selectedRoles[] = $selectedRole;
            }
        }
        $selectedRoles = array_values(array_unique($selectedRoles));
        $primaryRole = trim((string)($_POST['primary_role'] ?? ''));

        if ($displayName === '') {
            throw new RuntimeException('Display name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (!$selectedRoles) {
            throw new RuntimeException('Select at least one account type.');
        }
        if (!valid_role($primaryRole) || !in_array($primaryRole, $selectedRoles, true)) {
            throw new RuntimeException('Primary account type must be one of the selected account types.');
        }
        if ($id === (int)($current['id'] ?? 0) && $isActive !== 1) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        $existingUser = null;
        $existingRoles = [];
        if ($id > 0) {
            $existingStmt = $pdo->prepare(
                'SELECT id,role,is_active FROM users WHERE id=? LIMIT 1'
            );
            $existingStmt->execute([$id]);
            $existingUser = $existingStmt->fetch();
            if (!$existingUser) {
                throw new RuntimeException('User account not found.');
            }

            $existingRoles = user_account_types_for_user_id(
                (int)$existingUser['id'],
                (string)$existingUser['role']
            );
            if (
                in_array('admin', $existingRoles, true) &&
                (int)$existingUser['is_active'] === 1 &&
                (!in_array('admin', $selectedRoles, true) || $isActive !== 1) &&
                active_admin_user_count($pdo, $id) < 1
            ) {
                throw new RuntimeException(
                    'Create another active Admin before removing Admin access or deactivating the last Admin.'
                );
            }
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
        $check->execute([$email, $id]);
        if ($check->fetch()) {
            throw new RuntimeException('That email address is already in use.');
        }

        if (!empty($_POST['remove_avatar'])) {
            delete_local_upload($avatarPath);
            $avatarPath = '';
        }

        global $config;
        $avatarUpload = upload_file(
            $_FILES['avatar_file'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'avatars'
        );

        if ($avatarUpload) {
            delete_local_upload($avatarPath);
            $avatarPath = $avatarUpload;
        }

        if ($id > 0) {
            if ($password !== '' && strlen($password) < 12) {
                throw new RuntimeException('New passwords must contain at least 12 characters.');
            }

            $pdo->beginTransaction();
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET display_name=?,email=?,avatar_path=?,is_active=?,password_hash=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $displayName,
                        $email,
                        $avatarPath,
                        $isActive,
                        password_hash($password, PASSWORD_DEFAULT),
                        $id,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET display_name=?,email=?,avatar_path=?,is_active=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $displayName,
                        $email,
                        $avatarPath,
                        $isActive,
                        $id,
                    ]);
                }

                sync_user_account_types($pdo, $id, $selectedRoles, $primaryRole);

                if (table_exists('artist_team_members')) {
                    $membership = $pdo->prepare(
                        'SELECT artist_user_id
                         FROM artist_team_members
                         WHERE member_user_id=?
                         LIMIT 1'
                    );
                    $membership->execute([$id]);
                    if ($membership->fetch()) {
                        $delegatedRoles = array_values(array_intersect(
                            $selectedRoles,
                            ['manager', 'producer']
                        ));
                        $nonDelegatedRoles = array_values(array_diff(
                            $selectedRoles,
                            ['manager', 'producer']
                        ));

                        if ($nonDelegatedRoles || count($delegatedRoles) !== 1) {
                            $pdo->prepare(
                                'DELETE FROM artist_team_members WHERE member_user_id=?'
                            )->execute([$id]);
                        } else {
                            $pdo->prepare(
                                'UPDATE artist_team_members
                                 SET team_role=?
                                 WHERE member_user_id=?'
                            )->execute([$delegatedRoles[0], $id]);
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (in_array('artist', $selectedRoles, true)) {
                artist_workspace_v104_ensure_schema();
                artist_workspace_v104_seed_artist_permissions();
            }

            if ($id === (int)($current['id'] ?? 0)) {
                reset_current_user_cache();
            }
            flash('notice', 'User updated.');
        } else {
            if (strlen($password) < 12) {
                throw new RuntimeException('New accounts require a password with at least 12 characters.');
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users
                     (email,password_hash,display_name,role,avatar_path,is_active)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $displayName,
                    $primaryRole,
                    $avatarPath,
                    $isActive,
                ]);
                $newUserId = (int)$pdo->lastInsertId();
                sync_user_account_types(
                    $pdo,
                    $newUserId,
                    $selectedRoles,
                    $primaryRole
                );
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (in_array('artist', $selectedRoles, true)) {
                artist_workspace_v104_ensure_schema();
                artist_workspace_v104_seed_artist_permissions();
            }

            flash('notice', 'User created.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/users.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT id,email,display_name,role,avatar_path,is_active,last_login_at,created_at
         FROM users WHERE id=? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $editingRoles = user_account_types_for_user_id(
            (int)$editing['id'],
            (string)$editing['role']
        );
    }
}

$users = $pdo->query(
    'SELECT id,email,display_name,role,avatar_path,is_active,last_login_at,created_at
     FROM users ORDER BY display_name ASC,id ASC'
)->fetchAll();
foreach ($users as &$userRow) {
    $userRow['roles'] = user_account_types_for_user_id(
        (int)$userRow['id'],
        (string)$userRow['role']
    );
}
unset($userRow);

$adminTitle = 'Users';
$adminActive = 'users';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Account Management</span>
      <h2>Users</h2>
      <p class="muted">Assign one or more account types to each Stonefellow user. Permissions are combined across every assigned type.</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/users.php?new=1#user-form')) ?>">+ Add User</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>User</th>
          <th>Account Types</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $userRow): ?>
        <tr>
          <td>
            <div class="admin-user-cell">
              <span class="admin-user-avatar admin-user-avatar-sm">
                <?php if (!empty($userRow['avatar_path'])): ?>
                  <img src="<?= e(user_avatar_url($userRow)) ?>" alt="">
                <?php else: ?>
                  <span><?= e(user_initials($userRow)) ?></span>
                <?php endif; ?>
              </span>
              <div>
                <strong><?= e($userRow['display_name']) ?></strong><br>
                <span class="muted"><?= e($userRow['email']) ?></span>
              </div>
            </div>
          </td>
          <td>
            <strong><?= e(implode(' · ', user_role_labels($userRow))) ?></strong><br>
            <span class="muted">Primary: <?= e(role_label((string)$userRow['role'])) ?></span>
          </td>
          <td><span class="status"><?= (int)$userRow['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
          <td><?= $userRow['last_login_at'] ? e(date('M j, Y g:i A', strtotime((string)$userRow['last_login_at']))) : 'Never' ?></td>
          <td><?= !empty($userRow['created_at']) ? e(date('M j, Y', strtotime((string)$userRow['created_at']))) : '—' ?></td>
          <td class="actions">
            <a class="btn" href="<?= e(url('/admin/users.php?edit=' . (int)$userRow['id'] . '#user-form')) ?>">Edit</a>
            <?php if ((int)$userRow['id'] !== (int)($current['id'] ?? 0)): ?>
              <form class="inline-form" method="post" onsubmit="return confirm('Delete this user account?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$userRow['id'] ?>">
                <button class="btn danger" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$users): ?>
        <tr>
          <td colspan="6" class="muted">No user accounts have been added yet.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<?php
$formRoles = $editingRoles ?: ['fan'];
$formPrimaryRole = (string)($editing['role'] ?? 'fan');
?>
<div class="panel" id="user-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit User' : 'New User' ?></span>
      <h2><?= $editing ? 'Edit User' : 'Add User' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/users.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_avatar_path" value="<?= e($editing['avatar_path'] ?? '') ?>">

    <div class="field">
      <label>Display Name</label>
      <input name="display_name" maxlength="120" required value="<?= e($editing['display_name'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Email</label>
      <input name="email" type="email" maxlength="190" required value="<?= e($editing['email'] ?? '') ?>">
    </div>

    <div class="field full">
      <label>Account Types</label>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 16px;margin-top:8px">
        <?php foreach ($roleOptions as $role => $label): ?>
          <label class="admin-inline-check">
            <input
              type="checkbox"
              name="roles[]"
              value="<?= e($role) ?>"
              <?= in_array($role, $formRoles, true) ? 'checked' : '' ?>
            >
            <?= e($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <small>Select every account type this user should have. Their permissions are combined.</small>
    </div>

    <div class="field">
      <label>Primary Account Type</label>
      <select name="primary_role" required>
        <?php foreach ($roleOptions as $role => $label): ?>
          <option value="<?= e($role) ?>" <?= $formPrimaryRole === $role ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Used for legacy labels and role-specific lists. It must also be checked above.</small>
    </div>

    <div class="field">
      <label><?= $editing ? 'New Password (optional)' : 'Password' ?></label>
      <input name="password" type="password" minlength="12" <?= $editing ? '' : 'required' ?> autocomplete="new-password">
      <small><?= $editing ? 'Leave blank to keep the current password.' : 'Minimum 12 characters.' ?></small>
    </div>

    <div class="field full">
      <label>User Photo</label>
      <div class="admin-avatar-editor">
        <span class="admin-user-avatar">
          <?php if (!empty($editing['avatar_path'])): ?>
            <img src="<?= e(user_avatar_url($editing)) ?>" alt="">
          <?php else: ?>
            <span><?= e($editing ? user_initials($editing) : '+') ?></span>
          <?php endif; ?>
        </span>

        <div>
          <input name="avatar_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
          <small>JPG, PNG or WEBP, up to 5 MB.</small>

          <?php if (!empty($editing['avatar_path'])): ?>
            <label class="admin-inline-check" style="margin-top:8px">
              <input type="checkbox" name="remove_avatar" value="1">
              Remove current photo
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="field full">
      <label class="admin-inline-check">
        <input name="is_active" type="checkbox" <?= !isset($editing['is_active']) || (int)$editing['is_active'] === 1 ? 'checked' : '' ?>>
        Active account
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save User' : 'Add User' ?></button>
      <a class="btn" href="<?= e(url('/admin/users.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
