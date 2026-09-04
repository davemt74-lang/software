<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$user = current_user();
if (!$user) {
    flash('error', 'Please sign in to continue.');
    redirect(url('/login.php'));
}

if (!artist_workspace_v104_is_artist($user)) {
    require_permission('admin.access');
    flash('error', 'Team account management is available to Artist accounts.');
    redirect(url('/admin/index.php'));
}

artist_workspace_v104_ensure_schema();
artist_workspace_v104_seed_artist_permissions();
require_permission('admin.access');
require_permission('team.manage');

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$artistUserId = (int)($user['id'] ?? 0);
$teamLimit = artist_workspace_v104_team_limit();
$teamRoles = artist_workspace_v104_team_roles();
$editId = max(0, (int)($_GET['edit'] ?? 0));
$showNewForm = isset($_GET['new']);
$editing = $editId > 0
    ? artist_workspace_v104_team_member($pdo, $artistUserId, $editId)
    : null;

if ($editId > 0 && !$editing) {
    flash('error', 'That team account is not part of your Artist workspace.');
    redirect(url('/admin/team.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/team.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $memberId = max(0, (int)($_POST['id'] ?? 0));

    try {
        if ($action === 'delete') {
            $member = artist_workspace_v104_team_member($pdo, $artistUserId, $memberId);
            if (!$member) {
                throw new RuntimeException('That team account is not available.');
            }

            delete_local_upload((string)($member['avatar_path'] ?? ''));
            $stmt = $pdo->prepare(
                'DELETE FROM users
                 WHERE id=?
                   AND id IN (
                     SELECT member_user_id
                     FROM artist_team_members
                     WHERE artist_user_id=?
                   )'
            );
            $stmt->execute([$memberId, $artistUserId]);

            flash('notice', 'Team account deleted.');
            redirect(url('/admin/team.php'));
        }

        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $teamRole = trim((string)($_POST['team_role'] ?? 'producer'));
        $password = (string)($_POST['password'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($displayName === '') {
            throw new RuntimeException('Display name is required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid email address.');
        }
        if (!artist_workspace_v104_valid_team_role($teamRole)) {
            throw new RuntimeException('Team accounts can only be Manager or Producer.');
        }

        $emailCheck = $pdo->prepare(
            'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1'
        );
        $emailCheck->execute([$email, $memberId]);
        if ($emailCheck->fetch()) {
            throw new RuntimeException('That email address is already in use.');
        }

        if ($memberId > 0) {
            $member = artist_workspace_v104_team_member($pdo, $artistUserId, $memberId);
            if (!$member) {
                throw new RuntimeException('That team account is not available.');
            }
            if ($password !== '' && strlen($password) < 12) {
                throw new RuntimeException('New passwords must contain at least 12 characters.');
            }

            $pdo->beginTransaction();
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET display_name=?,email=?,is_active=?,password_hash=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $displayName,
                        $email,
                        $isActive,
                        password_hash($password, PASSWORD_DEFAULT),
                        $memberId,
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE users
                         SET display_name=?,email=?,is_active=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $displayName,
                        $email,
                        $isActive,
                        $memberId,
                    ]);
                }

                // Artist-created seats remain a single delegated type. Global
                // Admin can later promote them into broader multi-role users;
                // that promotion unlinks the Artist ownership in Admin Users.
                sync_user_account_types(
                    $pdo,
                    $memberId,
                    [$teamRole],
                    $teamRole
                );

                $membership = $pdo->prepare(
                    'UPDATE artist_team_members
                     SET team_role=?
                     WHERE artist_user_id=? AND member_user_id=?'
                );
                $membership->execute([$teamRole, $artistUserId, $memberId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            flash('notice', 'Team account updated.');
        } else {
            if (strlen($password) < 12) {
                throw new RuntimeException('New accounts require a password with at least 12 characters.');
            }

            $pdo->beginTransaction();
            try {
                // Serialize seat creation per Artist. Without this lock, two
                // simultaneous requests could both observe an open seat and
                // exceed the two-account limit before either insert commits.
                $artistLock = $pdo->prepare(
                    'SELECT id FROM users WHERE id=? FOR UPDATE'
                );
                $artistLock->execute([$artistUserId]);
                if (!(int)$artistLock->fetchColumn()) {
                    throw new RuntimeException('Artist account is unavailable.');
                }

                $teamCount = artist_workspace_v104_team_count($pdo, $artistUserId);
                if ($teamCount >= $teamLimit) {
                    throw new RuntimeException(
                        'Your Artist workspace already has the maximum of ' . $teamLimit . ' team accounts.'
                    );
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO users
                     (email,password_hash,display_name,role,avatar_path,is_active)
                     VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $displayName,
                    $teamRole,
                    '',
                    $isActive,
                ]);
                $newMemberId = (int)$pdo->lastInsertId();

                sync_user_account_types(
                    $pdo,
                    $newMemberId,
                    [$teamRole],
                    $teamRole
                );

                $membership = $pdo->prepare(
                    'INSERT INTO artist_team_members
                     (artist_user_id,member_user_id,team_role)
                     VALUES (?,?,?)'
                );
                $membership->execute([$artistUserId, $newMemberId, $teamRole]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            flash('notice', 'Team account created.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/team.php'));
}

$teamMembers = artist_workspace_v104_team_members($pdo, $artistUserId);
$teamCount = count($teamMembers);
$showForm = $showNewForm || $editing !== null;

$adminTitle = 'Team';
$adminActive = 'team';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Artist Workspace</span>
      <h2>Your Team</h2>
      <p class="muted">Create up to <?= (int)$teamLimit ?> team accounts and assign each person as a Manager or Producer.</p>
    </div>
    <div class="actions">
      <span class="status">Team Accounts: <?= (int)$teamCount ?> / <?= (int)$teamLimit ?></span>
      <?php if ($teamCount < $teamLimit): ?>
        <a class="btn primary" href="<?= e(url('/admin/team.php?new=1#team-form')) ?>">+ Add Team Member</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Team Member</th>
          <th>Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($teamMembers as $member): ?>
        <tr>
          <td>
            <div class="admin-user-cell">
              <span class="admin-user-avatar admin-user-avatar-sm">
                <?php if (!empty($member['avatar_path'])): ?>
                  <img src="<?= e(user_avatar_url($member)) ?>" alt="">
                <?php else: ?>
                  <span><?= e(user_initials($member)) ?></span>
                <?php endif; ?>
              </span>
              <div>
                <strong><?= e((string)$member['display_name']) ?></strong><br>
                <span class="muted"><?= e((string)$member['email']) ?></span>
              </div>
            </div>
          </td>
          <td><?= e($teamRoles[(string)$member['team_role']] ?? ucfirst((string)$member['team_role'])) ?></td>
          <td><span class="status"><?= (int)$member['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
          <td><?= !empty($member['last_login_at']) ? e(date('M j, Y g:i A', strtotime((string)$member['last_login_at']))) : 'Never' ?></td>
          <td class="actions">
            <a class="btn" href="<?= e(url('/admin/team.php?edit=' . (int)$member['id'] . '#team-form')) ?>">Edit</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this team account?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$member['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$teamMembers): ?>
        <tr>
          <td colspan="5" class="muted">No team accounts yet. Add a Manager or Producer when you are ready to collaborate.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="team-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Team Member' : 'New Team Member' ?></span>
      <h2><?= $editing ? 'Edit Team Account' : 'Add Team Account' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/team.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

    <div class="field">
      <label>Display Name</label>
      <input name="display_name" maxlength="120" required value="<?= e((string)($editing['display_name'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Email</label>
      <input name="email" type="email" maxlength="190" required value="<?= e((string)($editing['email'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Role</label>
      <select name="team_role" required>
        <?php foreach ($teamRoles as $role => $label): ?>
          <option value="<?= e($role) ?>" <?= (($editing['team_role'] ?? 'producer') === $role) ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Artists can assign only Manager or Producer access.</small>
    </div>

    <div class="field">
      <label><?= $editing ? 'New Password (optional)' : 'Password' ?></label>
      <input name="password" type="password" minlength="12" <?= $editing ? '' : 'required' ?> autocomplete="new-password">
      <small><?= $editing ? 'Leave blank to keep the current password.' : 'Minimum 12 characters.' ?></small>
    </div>

    <div class="field full">
      <label class="admin-inline-check">
        <input name="is_active" type="checkbox" <?= !isset($editing['is_active']) || (int)$editing['is_active'] === 1 ? 'checked' : '' ?>>
        Active account
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save Team Member' : 'Create Team Account' ?></button>
      <a class="btn" href="<?= e(url('/admin/team.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
