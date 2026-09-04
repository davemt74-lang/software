<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('shows.manage');
$user = current_user();
if ($user && user_has_role('artist', $user) && artist_workspace_v181_schema_ready()) {
    redirect(url('/admin/artist-shows.php'));
}
artist_workspace_v181_guard_legacy_admin('shows');

$pdo = db();
if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$editId = (int)($_GET['edit'] ?? 0);
$showNewForm = isset($_GET['new']);
$showForm = $showNewForm || $editId > 0;
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/shows.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM shows WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        flash('notice', 'Show deleted.');
        redirect(url('/admin/shows.php'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $showDate = trim((string)($_POST['show_date'] ?? ''));
    $venue = trim((string)($_POST['venue'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $region = trim((string)($_POST['region'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $ticketUrl = trim((string)($_POST['ticket_url'] ?? ''));
    $published = isset($_POST['is_published']) ? 1 : 0;

    if ($showDate === '' || $venue === '') {
        flash('error', 'Date and venue are required.');
        redirect(url('/admin/shows.php' . ($id ? '?edit=' . $id : '')));
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE shows SET show_date=?,venue=?,city=?,region=?,notes=?,ticket_url=?,is_published=? WHERE id=?');
        $stmt->execute([$showDate,$venue,$city,$region,$notes,$ticketUrl,$published,$id]);
        flash('notice', 'Show updated.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO shows (owner_user_id,show_date,venue,city,region,notes,ticket_url,is_published) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)(current_user()['id'] ?? 0) ?: null,$showDate,$venue,$city,$region,$notes,$ticketUrl,$published]);
        flash('notice', 'Show added.');
    }
    redirect(url('/admin/shows.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM shows WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$shows = $pdo->query('SELECT * FROM shows ORDER BY show_date DESC')->fetchAll();

$adminTitle = 'Shows';
$adminActive = 'shows';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Live Schedule</span>
      <h2>Shows</h2>
      <p class="muted">Manage Stonefellow performance dates, venues, ticket links and publishing status.</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/shows.php?new=1#show-form')) ?>">+ Add Show</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Venue</th><th>Location</th><th>Ticket</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($shows as $show): ?>
        <tr>
          <td><?= e(date('M j, Y g:i A', strtotime((string)$show['show_date']))) ?></td>
          <td><strong><?= e($show['venue']) ?></strong></td>
          <td><?= e(trim($show['city'] . ($show['region'] ? ', ' . $show['region'] : ''))) ?></td>
          <td>
            <?php if (!empty($show['ticket_url'])): ?>
              <a href="<?= e($show['ticket_url']) ?>" target="_blank" rel="noopener">Open ↗</a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="status"><?= (int)$show['is_published'] ? 'Published' : 'Draft' ?></span></td>
          <td class="actions">
            <a class="btn" href="<?= e(url('/admin/shows.php?edit=' . (int)$show['id'] . '#show-form')) ?>">Edit</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this show?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$show['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$shows): ?>
        <tr><td colspan="6" class="muted">No shows have been added yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="show-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Show' : 'New Show' ?></span>
      <h2><?= $editing ? 'Edit Show' : 'Add Show' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/shows.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

    <div class="field">
      <label>Date & Time</label>
      <input name="show_date" type="datetime-local" required value="<?= !empty($editing['show_date']) ? e(date('Y-m-d\TH:i', strtotime((string)$editing['show_date']))) : '' ?>">
    </div>

    <div class="field">
      <label>Venue</label>
      <input name="venue" required value="<?= e($editing['venue'] ?? '') ?>">
    </div>

    <div class="field">
      <label>City</label>
      <input name="city" value="<?= e($editing['city'] ?? '') ?>">
    </div>

    <div class="field">
      <label>State / Region</label>
      <input name="region" value="<?= e($editing['region'] ?? '') ?>">
    </div>

    <div class="field full">
      <label>Ticket URL</label>
      <input name="ticket_url" type="url" value="<?= e($editing['ticket_url'] ?? '') ?>">
    </div>

    <div class="field full">
      <label>Notes</label>
      <textarea name="notes"><?= e($editing['notes'] ?? '') ?></textarea>
    </div>

    <div class="field full">
      <label class="admin-inline-check">
        <input name="is_published" type="checkbox" <?= !isset($editing['is_published']) || (int)$editing['is_published'] === 1 ? 'checked' : '' ?>>
        Published
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save Show' : 'Add Show' ?></button>
      <a class="btn" href="<?= e(url('/admin/shows.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
