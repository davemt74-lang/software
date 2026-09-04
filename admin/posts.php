<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('posts.manage');
artist_workspace_v181_guard_legacy_admin('posts');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();

if (!$pdo) {
    flash('error', 'Database unavailable.');
    redirect(url('/admin/index.php'));
}

$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0;
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired. Try again.');
        redirect(url('/admin/posts.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $pdo->prepare(
            'SELECT image_path FROM artist_posts WHERE id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $path = (string)($stmt->fetchColumn() ?: '');

        $pdo->prepare(
            'DELETE FROM artist_posts WHERE id=?'
        )->execute([$id]);

        delete_local_upload($path);
        flash('notice', 'Post deleted.');
        redirect(url('/admin/posts.php'));
    }

    try {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $postType = trim((string)($_POST['post_type'] ?? 'update'));
        $mediaUrl = trim((string)($_POST['media_url'] ?? ''));
        $visibility = trim((string)($_POST['visibility'] ?? 'members'));
        $published = isset($_POST['is_published']) ? 1 : 0;
        $imagePath = trim((string)($_POST['existing_image_path'] ?? ''));

        if ($title === '' || $body === '') {
            throw new RuntimeException('Post title and body are required.');
        }

        if (!in_array($postType, ['update','studio','release','show','photo','video'], true)) {
            throw new RuntimeException('Select a valid post type.');
        }

        if (!valid_visibility($visibility)) {
            throw new RuntimeException('Select a valid viewer group.');
        }

        if ($mediaUrl !== '' && !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter a valid media URL.');
        }

        global $config;
        $upload = upload_file(
            $_FILES['post_image'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'posts'
        );

        if ($upload) {
            delete_local_upload($imagePath);
            $imagePath = $upload;
        }

        $creatorId = (int)(current_user()['id'] ?? 0) ?: null;
        $publishedAt = $published ? date('Y-m-d H:i:s') : null;

        if ($id > 0) {
            $prior = $pdo->prepare(
                'SELECT is_published,published_at FROM artist_posts WHERE id=? LIMIT 1'
            );
            $prior->execute([$id]);
            $priorRow = $prior->fetch();

            if (!$priorRow) {
                throw new RuntimeException('Post not found.');
            }

            if ($published && (int)$priorRow['is_published'] === 1 && (string)$priorRow['published_at'] !== '') {
                $publishedAt = (string)$priorRow['published_at'];
            }

            $stmt = $pdo->prepare(
                'UPDATE artist_posts
                 SET title=?,body=?,post_type=?,image_path=?,media_url=?,visibility=?,is_published=?,published_at=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,$body,$postType,$imagePath,$mediaUrl,
                $visibility,$published,$publishedAt,$id
            ]);

            if (
                $published === 1 &&
                (int)$priorRow['is_published'] !== 1
            ) {
                player_notify_artist_post(
                    $id,
                    $title
                );
            }

            flash('notice', 'Post updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO artist_posts
                 (title,body,post_type,image_path,media_url,visibility,is_published,published_at,created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,$body,$postType,$imagePath,$mediaUrl,
                $visibility,$published,$publishedAt,$creatorId
            ]);

            $id = (int)$pdo->lastInsertId();

            if ($published === 1) {
                player_notify_artist_post(
                    $id,
                    $title
                );
            }

            flash('notice', 'Post created.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/posts.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM artist_posts WHERE id=? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$posts = $pdo->query(
    'SELECT p.*,u.display_name AS creator_name
     FROM artist_posts p
     LEFT JOIN users u ON u.id=p.created_by_user_id
     ORDER BY COALESCE(p.published_at,p.created_at) DESC,p.id DESC'
)->fetchAll();

$adminTitle = 'Posts';
$adminActive = 'posts';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Artist Updates</span>
      <h2>Posts</h2>
      <p class="muted">Publish studio updates, release notes, show announcements, photos and videos into the authenticated Player.</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/posts.php?new=1#post-form')) ?>">+ Add Post</a>
  </div>

  <div class="admin-post-grid">
    <?php foreach ($posts as $post): ?>
      <article class="admin-post-card">
        <?php if (trim((string)$post['image_path']) !== ''): ?>
          <img src="<?= e(url('/content-image.php?type=post&id='.(int)$post['id'])) ?>" alt="">
        <?php endif; ?>
        <div>
          <span class="status"><?= e(ucfirst((string)$post['post_type'])) ?> · <?= (int)$post['is_published'] ? 'Published' : 'Draft' ?></span>
          <strong><?= e((string)$post['title']) ?></strong>
          <p><?= e((string)$post['body']) ?></p>
          <small><?= e((string)($post['creator_name'] ?: 'Stonefellow')) ?></small>
          <div class="actions">
            <a class="btn" href="<?= e(url('/admin/posts.php?edit='.(int)$post['id'].'#post-form')) ?>">Edit</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this post?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$posts): ?>
      <div class="muted">No artist posts have been created yet.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="post-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Post' : 'New Post' ?></span>
      <h2><?= $editing ? 'Edit Artist Post' : 'Add Artist Post' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/posts.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_image_path" value="<?= e((string)($editing['image_path'] ?? '')) ?>">

    <div class="field">
      <label>Title</label>
      <input name="title" maxlength="190" required value="<?= e((string)($editing['title'] ?? '')) ?>">
    </div>

    <div class="field">
      <label>Post Type</label>
      <select name="post_type">
        <?php foreach (['update'=>'Update','studio'=>'Studio','release'=>'Release','show'=>'Show','photo'=>'Photo','video'=>'Video'] as $value=>$label): ?>
          <option value="<?= e($value) ?>" <?= (($editing['post_type'] ?? 'update') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field full">
      <label>Post</label>
      <textarea name="body" rows="8" required><?= e((string)($editing['body'] ?? '')) ?></textarea>
    </div>

    <div class="field">
      <label>Image</label>
      <input name="post_image" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
    </div>

    <div class="field">
      <label>Video / Media URL</label>
      <input name="media_url" type="url" value="<?= e((string)($editing['media_url'] ?? '')) ?>">
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
      <button class="btn primary" type="submit"><?= $editing ? 'Save Post' : 'Add Post' ?></button>
      <a class="btn" href="<?= e(url('/admin/posts.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
