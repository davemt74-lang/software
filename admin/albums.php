<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('albums.manage');
artist_workspace_v181_guard_legacy_admin('albums');

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
        redirect(url('/admin/albums.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $pdo->prepare(
            'SELECT title,cover_path
             FROM albums
             WHERE id=?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $album = $stmt->fetch();

        if ($album) {
            $pdo->beginTransaction();

            try {
                $unassign = $pdo->prepare(
                    "UPDATE tracks
                     SET
                        album_id=NULL,
                        album=CASE
                          WHEN album=? THEN 'Stonefellow'
                          ELSE album
                        END
                     WHERE album_id=?"
                );
                $unassign->execute([
                    (string)$album['title'],
                    $id,
                ]);

                $pdo->prepare(
                    'DELETE FROM albums WHERE id=?'
                )->execute([$id]);

                $pdo->commit();
                delete_local_upload(
                    (string)$album['cover_path']
                );
                flash('notice', 'Album deleted.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                flash('error', $e->getMessage());
            }
        }

        redirect(url('/admin/albums.php'));
    }

    try {
        $title = trim((string)($_POST['title'] ?? ''));
        $releaseDate = trim((string)($_POST['release_date'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $visibility = trim((string)($_POST['visibility'] ?? 'members'));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $published = isset($_POST['is_published']) ? 1 : 0;
        $coverPath = trim((string)($_POST['existing_cover_path'] ?? ''));
        $trackIds = array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        (array)($_POST['track_ids'] ?? [])
                    ),
                    static fn(int $trackId): bool => $trackId > 0
                )
            )
        );

        if ($title === '') {
            throw new RuntimeException('Album title is required.');
        }

        if ($trackIds) {
            $allowedTrackIds = [];

            foreach ($trackIds as $trackId) {
                $track = get_track_by_id($trackId);

                if ($track && can_manage_track_production($track)) {
                    $allowedTrackIds[] = $trackId;
                }
            }

            $trackIds = array_values(
                array_unique($allowedTrackIds)
            );
        }

        if (!valid_visibility($visibility)) {
            throw new RuntimeException('Select a valid viewer group.');
        }

        if ($releaseDate !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $releaseDate);

            if (!$date || $date->format('Y-m-d') !== $releaseDate) {
                throw new RuntimeException('Enter a valid release date.');
            }
        }

        global $config;
        $coverUpload = upload_file(
            $_FILES['cover_file'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'albums'
        );

        $oldCoverPath = '';

        if ($coverUpload) {
            $oldCoverPath = $coverPath;
            $coverPath = $coverUpload;
        }

        $pdo->beginTransaction();
        $wasPublished = 0;

        if ($id > 0) {
            $priorStmt = $pdo->prepare(
                'SELECT title,is_published
                 FROM albums
                 WHERE id=?
                 LIMIT 1'
            );
            $priorStmt->execute([$id]);
            $priorAlbum = $priorStmt->fetch() ?: [];
            $priorTitle = (string)($priorAlbum['title'] ?? '');
            $wasPublished = (int)($priorAlbum['is_published'] ?? 0);

            if ($priorTitle === '') {
                throw new RuntimeException('Album not found.');
            }

            $stmt = $pdo->prepare(
                'UPDATE albums
                 SET
                    title=?,
                    release_date=?,
                    description=?,
                    cover_path=?,
                    visibility=?,
                    sort_order=?,
                    is_published=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                $releaseDate !== '' ? $releaseDate : null,
                $description,
                $coverPath,
                $visibility,
                $sortOrder,
                $published,
                $id,
            ]);

            $pdo->prepare(
                "UPDATE tracks
                 SET
                    album_id=NULL,
                    album=CASE
                      WHEN album=? THEN 'Stonefellow'
                      ELSE album
                    END
                 WHERE album_id=?"
            )->execute([
                $priorTitle,
                $id,
            ]);
        } else {
            $creatorId = (int)(current_user()['id'] ?? 0);

            $stmt = $pdo->prepare(
                'INSERT INTO albums
                 (
                    title,
                    release_date,
                    description,
                    cover_path,
                    visibility,
                    sort_order,
                    is_published,
                    created_by_user_id
                 )
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $title,
                $releaseDate !== '' ? $releaseDate : null,
                $description,
                $coverPath,
                $visibility,
                $sortOrder,
                $published,
                $creatorId ?: null,
            ]);

            $id = (int)$pdo->lastInsertId();
        }

        if ($trackIds) {
            $placeholders = implode(',', array_fill(0, count($trackIds), '?'));
            $params = array_merge(
                [$id, $title],
                $trackIds
            );

            $assign = $pdo->prepare(
                "UPDATE tracks
                 SET album_id=?,album=?
                 WHERE id IN ({$placeholders})"
            );
            $assign->execute($params);
        }

        $pdo->commit();

        if ($oldCoverPath !== '' && $oldCoverPath !== $coverPath) {
            delete_local_upload($oldCoverPath);
        }

        if (
            $published === 1 &&
            $wasPublished !== 1
        ) {
            player_notify_new_release(
                'album',
                $id,
                $title,
                url('/chat.php?view=player')
            );
        }

        flash(
            'notice',
            $trackIds
                ? 'Album saved and tracks assigned.'
                : 'Album saved.'
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('error', $e->getMessage());
    }

    redirect(url('/admin/albums.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT * FROM albums WHERE id=? LIMIT 1'
    );
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$albums = $pdo->query(
    'SELECT
        a.*,
        u.display_name AS creator_name,
        (
          SELECT COUNT(*)
          FROM tracks t
          WHERE t.album_id=a.id
        ) AS track_count
     FROM albums a
     LEFT JOIN users u
       ON u.id=a.created_by_user_id
     ORDER BY a.sort_order,a.id DESC'
)->fetchAll();

$tracks = [];

foreach (
    $pdo->query(
        'SELECT id,title,album_id,album,is_published,owner_user_id,producer_user_id
         FROM tracks
         ORDER BY sort_order,title,id'
    )->fetchAll()
    as $track
) {
    if (can_manage_track_production($track)) {
        $tracks[] = $track;
    }
}

$assignedTrackIds = [];

if ($editing) {
    foreach ($tracks as $track) {
        if ((int)($track['album_id'] ?? 0) === (int)$editing['id']) {
            $assignedTrackIds[] = (int)$track['id'];
        }
    }
}

$adminTitle = 'Albums';
$adminActive = 'albums';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Music Collections</span>
      <h2>Albums</h2>
      <p class="muted">
        Create albums, upload cover art and assign Stonefellow tracks to each album.
      </p>
    </div>

    <a class="btn primary" href="<?= e(url('/admin/albums.php?new=1#album-form')) ?>">+ Add Album</a>
  </div>

  <div class="admin-photo-grid admin-album-grid">
    <?php foreach ($albums as $album): ?>
      <article class="admin-photo-card admin-album-card">
        <?php if (trim((string)$album['cover_path']) !== ''): ?>
          <img
            src="<?= e(url('/content-image.php?type=album&id='.(int)$album['id'])) ?>"
            alt=""
          >
        <?php else: ?>
          <div class="admin-album-placeholder">A</div>
        <?php endif; ?>

        <div>
          <span class="status"><?= (int)$album['is_published'] ? 'Published' : 'Draft' ?></span>
          <strong><?= e((string)$album['title']) ?></strong>
          <p><?= e((string)$album['description']) ?></p>
          <small>
            <?= (int)$album['track_count'] ?> track<?= (int)$album['track_count'] === 1 ? '' : 's' ?>
            <?php if ((string)$album['release_date'] !== ''): ?>
              · <?= e(date('M j, Y', strtotime((string)$album['release_date']))) ?>
            <?php endif; ?>
          </small>

          <div class="actions">
            <a class="btn" href="<?= e(url('/admin/albums.php?edit='.(int)$album['id'].'#album-form')) ?>">Edit / Assign Tracks</a>

            <form class="inline-form" method="post" onsubmit="return confirm('Delete this album? Tracks will remain available but become unassigned.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$album['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$albums): ?>
      <div class="muted">No albums have been created yet.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="album-form">
  <div class="content-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Album' : 'New Album' ?></span>
      <h2><?= $editing ? 'Edit Album & Tracks' : 'Add Album' ?></h2>
    </div>

    <a class="btn" href="<?= e(url('/admin/albums.php')) ?>">Close</a>
  </div>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_cover_path" value="<?= e((string)($editing['cover_path'] ?? '')) ?>">

    <div class="field">
      <label>Album Title</label>
      <input
        name="title"
        maxlength="190"
        required
        value="<?= e((string)($editing['title'] ?? '')) ?>"
      >
    </div>

    <div class="field">
      <label>Release Date</label>
      <input
        name="release_date"
        type="date"
        value="<?= e((string)($editing['release_date'] ?? '')) ?>"
      >
    </div>

    <div class="field full">
      <label>Description</label>
      <textarea name="description"><?= e((string)($editing['description'] ?? '')) ?></textarea>
    </div>

    <div class="field">
      <label>Cover Image</label>
      <input
        name="cover_file"
        type="file"
        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
      >
    </div>

    <div class="field">
      <label>Sort Order</label>
      <input
        name="sort_order"
        type="number"
        value="<?= (int)($editing['sort_order'] ?? 0) ?>"
      >
    </div>

    <div class="field full">
      <label>Who can view?</label>
      <select name="visibility" required>
        <?php foreach (visibility_options() as $value=>$label): ?>
          <option
            value="<?= e($value) ?>"
            <?= (($editing['visibility'] ?? 'members') === $value) ? 'selected' : '' ?>
          ><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field full">
      <label>Assign Tracks</label>

      <div class="admin-album-track-picker">
        <?php foreach ($tracks as $track): ?>
          <label>
            <input
              type="checkbox"
              name="track_ids[]"
              value="<?= (int)$track['id'] ?>"
              <?= in_array((int)$track['id'], $assignedTrackIds, true) ? 'checked' : '' ?>
            >
            <span>
              <strong><?= e((string)$track['title']) ?></strong>
              <small>
                <?= (int)$track['is_published'] ? 'Published' : 'Draft' ?>
                <?php if ((int)($track['album_id'] ?? 0) > 0 && !in_array((int)$track['id'], $assignedTrackIds, true)): ?>
                  · Currently assigned to another album
                <?php endif; ?>
              </small>
            </span>
          </label>
        <?php endforeach; ?>

        <?php if (!$tracks): ?>
          <div class="muted">Create tracks first, then assign them here.</div>
        <?php endif; ?>
      </div>

      <small>
        Selecting a track moves it into this album. Tracks not selected remain in their current album unless they were previously assigned to this album.
      </small>
    </div>

    <div class="field full">
      <label class="admin-inline-check">
        <input
          name="is_published"
          type="checkbox"
          <?= !isset($editing['is_published']) || (int)$editing['is_published'] === 1 ? 'checked' : '' ?>
        >
        Published
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save Album & Tracks' : 'Add Album' ?></button>
      <a class="btn" href="<?= e(url('/admin/albums.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
