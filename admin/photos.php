<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('photos.manage');
artist_workspace_v181_guard_legacy_admin('photos');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

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
        redirect(url('/admin/photos.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $pdo->prepare(
            'SELECT image_path FROM photos WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $path = (string)($stmt->fetchColumn() ?: '');

        $pdo->prepare(
            'DELETE FROM photos WHERE id=?'
        )->execute([$id]);

        delete_local_upload($path);
        flash('notice', 'Photo deleted.');
        redirect(url('/admin/photos.php'));
    }

    try {
        $title = trim((string)($_POST['title'] ?? ''));
        $caption = trim((string)($_POST['caption'] ?? ''));
        $altText = trim((string)($_POST['alt_text'] ?? ''));
        $visibility = trim((string)($_POST['visibility'] ?? 'members'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $published = isset($_POST['is_published']) ? 1 : 0;
        $imagePath = trim((string)($_POST['existing_image_path'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Photo title is required.');
        }

        if (!valid_visibility($visibility)) {
            throw new RuntimeException('Select a valid visibility group.');
        }

        global $config;
        $upload = upload_file(
            $_FILES['photo_file'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'photos'
        );

        if ($upload) {
            delete_local_upload($imagePath);
            $imagePath = $upload;
        }

        if ($imagePath === '') {
            throw new RuntimeException('Choose a JPG, PNG or WEBP image.');
        }

        $currentUser = current_user();
        $createdBy = (int)($currentUser['id'] ?? 0) ?: null;

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE photos
                 SET title=?,caption=?,alt_text=?,image_path=?,visibility=?,sort_order=?,is_published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                $caption,
                $altText,
                $imagePath,
                $visibility,
                $sortOrder,
                $published,
                $id,
            ]);
            flash('notice', 'Photo updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO photos
                 (title,caption,alt_text,image_path,visibility,sort_order,is_published,created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,
                $caption,
                $altText,
                $imagePath,
                $visibility,
                $sortOrder,
                $published,
                $createdBy,
            ]);
            flash('notice', 'Photo added.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/photos.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM photos WHERE id=? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$photos = $pdo->query(
    'SELECT p.*,u.display_name AS creator_name
     FROM photos p
     LEFT JOIN users u ON u.id=p.created_by_user_id
     ORDER BY p.sort_order,p.id DESC'
)->fetchAll();

$adminTitle = 'Photos';
$adminActive = 'photos';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Visual Library</span>
      <h2>Photos</h2>
      <p class="muted">Upload and manage Stonefellow images used inside Agent Chat and future gallery surfaces.</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/photos.php?new=1#photo-form')) ?>">+ Add Photo</a>
  </div>

  <div class="admin-photo-grid">
    <?php foreach ($photos as $photo): ?>
      <article class="admin-photo-card">
        <img
          src="<?= e(url('/content-image.php?type=photo&id='.(int)$photo['id'])) ?>"
          alt="<?= e((string)$photo['alt_text']) ?>"
        >
        <div>
          <span class="status"><?= (int)$photo['is_published'] ? 'Published' : 'Draft' ?></span>
          <strong><?= e((string)$photo['title']) ?></strong>
          <p><?= e((string)$photo['caption']) ?></p>
          <small>
            <?= e(visibility_options()[$photo['visibility']] ?? (string)$photo['visibility']) ?>
            · Order <?= (int)$photo['sort_order'] ?>
          </small>
          <div class="actions">
            <a class="btn" href="<?= e(url('/admin/photos.php?edit='.(int)$photo['id'].'#photo-form')) ?>">Edit</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this photo?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$photo['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$photos): ?>
      <div class="muted">No photos have been added yet.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="photo-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Photo' : 'New Photo' ?></span>
      <h2><?= $editing ? 'Edit Photo' : 'Add Photo' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/photos.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_image_path" value="<?= e((string)($editing['image_path'] ?? '')) ?>">

    <div class="field">
      <label>Photo Title</label>
      <input name="title" maxlength="190" required value="<?= e((string)($editing['title'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Alt Text</label>
      <input name="alt_text" maxlength="255" value="<?= e((string)($editing['alt_text'] ?? '')) ?>">
    </div>

    <div class="field full">
      <label>Caption</label>
      <textarea name="caption"><?= e((string)($editing['caption'] ?? '')) ?></textarea>
    </div>

    <div class="field">
      <label>Image <?= $editing ? '(leave empty to keep current)' : '' ?></label>
      <input
        name="photo_file"
        type="file"
        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
        <?= $editing ? '' : 'required' ?>
      >
    </div>

    <div class="field">
      <label>Sort Order</label>
      <input name="sort_order" type="number" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
    </div>

    <div class="field full">
      <label>Who can view?</label>
      <select name="visibility" required>
        <?php foreach (visibility_options() as $value=>$label): ?>
          <option value="<?= e($value) ?>" <?= (($editing['visibility'] ?? 'members') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field full">
      <label class="admin-inline-check">
        <input name="is_published" type="checkbox" <?= !isset($editing['is_published']) || (int)$editing['is_published'] === 1 ? 'checked' : '' ?>>
        Published
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save Photo' : 'Add Photo' ?></button>
      <a class="btn" href="<?= e(url('/admin/photos.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
