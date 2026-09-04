<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('merch.manage');
artist_workspace_v181_guard_legacy_admin('merch');

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
        redirect(url('/admin/merch.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $pdo->prepare(
            'SELECT image_path FROM merch_items WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $path = (string)($stmt->fetchColumn() ?: '');

        $pdo->prepare(
            'DELETE FROM merch_items WHERE id=?'
        )->execute([$id]);

        delete_local_upload($path);
        flash('notice', 'Merch item deleted.');
        redirect(url('/admin/merch.php'));
    }

    try {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price = trim((string)($_POST['price'] ?? '0'));
        $productUrl = trim((string)($_POST['product_url'] ?? ''));
        $albumId = max(0, (int)($_POST['album_id'] ?? 0));
        $trackId = max(0, (int)($_POST['track_id'] ?? 0));
        $visibility = trim((string)($_POST['visibility'] ?? 'members'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $published = isset($_POST['is_published']) ? 1 : 0;
        $imagePath = trim((string)($_POST['existing_image_path'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Merch title is required.');
        }

        if (!is_numeric($price) || (float)$price < 0) {
            throw new RuntimeException('Enter a valid price.');
        }

        if ($productUrl !== '' && !filter_var($productUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid product URL.');
        }

        if (!valid_visibility($visibility)) {
            throw new RuntimeException('Select a valid visibility group.');
        }

        if ($albumId > 0) {
            $check = $pdo->prepare('SELECT id FROM albums WHERE id=? LIMIT 1');
            $check->execute([$albumId]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Choose a valid album.');
            }
        }

        if ($trackId > 0) {
            $track = get_track_by_id($trackId);

            if (!$track || !can_manage_track_production($track)) {
                throw new RuntimeException('Choose a valid track.');
            }
        }

        global $config;
        $upload = upload_file(
            $_FILES['merch_image'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'merch'
        );

        if ($upload) {
            delete_local_upload($imagePath);
            $imagePath = $upload;
        }

        $priceCents = (int)round((float)$price * 100);
        $currentUser = current_user();
        $createdBy = (int)($currentUser['id'] ?? 0) ?: null;

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE merch_items
                 SET title=?,description=?,price_cents=?,product_url=?,image_path=?,album_id=?,track_id=?,visibility=?,sort_order=?,is_published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,$description,$priceCents,$productUrl,$imagePath,
                $albumId ?: null,$trackId ?: null,
                $visibility,$sortOrder,$published,$id
            ]);
            flash('notice', 'Merch item updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO merch_items
                 (title,description,price_cents,product_url,image_path,album_id,track_id,visibility,sort_order,is_published,created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,$description,$priceCents,$productUrl,$imagePath,
                $albumId ?: null,$trackId ?: null,
                $visibility,$sortOrder,$published,$createdBy
            ]);
            flash('notice', 'Merch item added.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/merch.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM merch_items WHERE id=? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$items = $pdo->query(
    'SELECT
        m.*,
        a.title AS album_title,
        t.title AS track_title
     FROM merch_items m
     LEFT JOIN albums a ON a.id=m.album_id
     LEFT JOIN tracks t ON t.id=m.track_id
     ORDER BY m.sort_order,m.id DESC'
)->fetchAll();

$albums = $pdo->query(
    'SELECT id,title FROM albums ORDER BY sort_order,title,id'
)->fetchAll();

$tracks = $pdo->query(
    'SELECT id,title FROM tracks ORDER BY sort_order,title,id'
)->fetchAll();

$adminTitle = 'Merch';
$adminActive = 'merch';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Store Content</span>
      <h2>Merch</h2>
      <p class="muted">Manage Stonefellow merchandise cards and external purchase links.</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/merch.php?new=1#merch-form')) ?>">+ Add Merch</a>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Item</th><th>Price</th><th>Visibility</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td>
            <strong><?= e((string)$item['title']) ?></strong><br>
            <span class="muted"><?= e((string)$item['description']) ?></span>
            <?php if ((string)($item['album_title'] ?? '') !== '' || (string)($item['track_title'] ?? '') !== ''): ?>
              <br><small class="muted">
                <?= (string)($item['album_title'] ?? '') !== '' ? 'Album: '.e((string)$item['album_title']) : '' ?>
                <?= (string)($item['track_title'] ?? '') !== '' ? ((string)($item['album_title'] ?? '') !== '' ? ' · ' : '').'Track: '.e((string)$item['track_title']) : '' ?>
              </small>
            <?php endif; ?>
          </td>
          <td>$<?= number_format(((int)$item['price_cents'])/100,2) ?></td>
          <td><?= e(visibility_options()[$item['visibility']] ?? (string)$item['visibility']) ?></td>
          <td><span class="status"><?= (int)$item['is_published'] ? 'Published' : 'Draft' ?></span></td>
          <td class="actions">
            <?php if ((string)$item['product_url'] !== ''): ?>
              <a class="btn" href="<?= e((string)$item['product_url']) ?>" target="_blank" rel="noopener">Open</a>
            <?php endif; ?>
            <a class="btn" href="<?= e(url('/admin/merch.php?edit='.(int)$item['id'].'#merch-form')) ?>">Edit</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this merch item?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$items): ?>
        <tr><td colspan="5" class="muted">No merch items have been added yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="merch-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Merch' : 'New Merch' ?></span>
      <h2><?= $editing ? 'Edit Merch Item' : 'Add Merch' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/merch.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_image_path" value="<?= e((string)($editing['image_path'] ?? '')) ?>">

    <div class="field">
      <label>Item Name</label>
      <input name="title" maxlength="190" required value="<?= e((string)($editing['title'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Price</label>
      <input name="price" type="number" min="0" step=".01" value="<?= isset($editing['price_cents']) ? e(number_format(((int)$editing['price_cents'])/100,2,'.','')) : '0.00' ?>">
    </div>

    <div class="field full">
      <label>Description</label>
      <textarea name="description"><?= e((string)($editing['description'] ?? '')) ?></textarea>
    </div>

    <div class="field full">
      <label>Product / Checkout URL</label>
      <input name="product_url" type="url" value="<?= e((string)($editing['product_url'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Merch Image</label>
      <input name="merch_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
    </div>

    <div class="field">
      <label>Sort Order</label>
      <input name="sort_order" type="number" value="<?= (int)($editing['sort_order'] ?? 0) ?>">
    </div>

    <div class="field">
      <label>Related Album</label>
      <select name="album_id">
        <option value="0">No album association</option>
        <?php foreach ($albums as $albumOption): ?>
          <option value="<?= (int)$albumOption['id'] ?>" <?= (int)($editing['album_id'] ?? 0) === (int)$albumOption['id'] ? 'selected' : '' ?>><?= e((string)$albumOption['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Related Track</label>
      <select name="track_id">
        <option value="0">No track association</option>
        <?php foreach ($tracks as $trackOption): ?>
          <option value="<?= (int)$trackOption['id'] ?>" <?= (int)($editing['track_id'] ?? 0) === (int)$trackOption['id'] ? 'selected' : '' ?>><?= e((string)$trackOption['title']) ?></option>
        <?php endforeach; ?>
      </select>
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
      <button class="btn primary" type="submit"><?= $editing ? 'Save Merch' : 'Add Merch' ?></button>
      <a class="btn" href="<?= e(url('/admin/merch.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
