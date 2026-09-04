<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('tracks.manage');
artist_workspace_v181_guard_legacy_admin('tracks');

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
        redirect(url('/admin/tracks.php'));
    }

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && table_exists('track_projects')) {
            stem_delete_track_package($id);
        }
        $stmt = $pdo->prepare('DELETE FROM tracks WHERE id = ?');
        $stmt->execute([$id]);
        flash('notice', 'Track deleted.');
        redirect(url('/admin/tracks.php'));
    }

    if ($action === 'new_studio_project') {
        $userId = (int)(current_user()['id'] ?? 0);

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tracks
                 (
                    owner_user_id,
                    title,
                    album,
                    duration,
                    lyrics,
                    description,
                    genre,
                    mood,
                    energy,
                    tempo_bpm,
                    keywords,
                    audio_path,
                    cover_path,
                    sort_order,
                    is_published,
                    visibility
                 )
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            $stmt->execute([
                $userId ?: null,
                'Untitled Project',
                'Stonefellow Studio',
                '',
                '',
                '',
                '',
                '',
                '',
                120,
                '',
                '',
                '/images/stonefellow-studio.png',
                0,
                0,
                'admin',
            ]);

            $newTrackId = (int)$pdo->lastInsertId();

            $projectStmt = $pdo->prepare(
                'INSERT INTO track_projects
                 (
                    track_id,
                    project_name,
                    tempo_bpm,
                    time_signature,
                    imported_by_user_id,
                    imported_at
                 )
                 VALUES (?,?,?,?,?,NOW())'
            );

            $projectStmt->execute([
                $newTrackId,
                'Untitled Project',
                120,
                '4/4',
                $userId ?: null,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash('error', $e->getMessage());
            redirect(url('/admin/tracks.php'));
        }

        redirect(
            url('/admin/stems.php?track=' . $newTrackId)
        );
    }

    try {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $albumId = max(
            0,
            (int)($_POST['album_id'] ?? 0)
        );
        $album = trim((string)($_POST['album'] ?? 'Stonefellow'));
        $duration = trim((string)($_POST['duration'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$credits = trim((string)($_POST['credits'] ?? ''));
$genre = trim((string)($_POST['genre'] ?? ''));
$mood = trim((string)($_POST['mood'] ?? ''));
$energy = trim((string)($_POST['energy'] ?? ''));
$tempoBpm = (int)($_POST['tempo_bpm'] ?? 0);
$keywords = trim((string)($_POST['keywords'] ?? ''));
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $published = isset($_POST['is_published']) ? 1 : 0;
        $visibility = trim((string)($_POST['visibility'] ?? 'public'));
        $producerUserId = max(
            0,
            (int)($_POST['producer_user_id'] ?? 0)
        );
        $audioPath = trim((string)($_POST['existing_audio_path'] ?? ''));
        $coverPath = trim((string)($_POST['existing_cover_path'] ?? '/images/stonefellow-studio.png'));

        if ($title === '') {
            throw new RuntimeException('Track title is required.');
        }

        if (!valid_visibility($visibility)) {
            throw new RuntimeException('Select a valid viewer group.');
        }

        if ($albumId > 0) {
            $albumCheck = $pdo->prepare(
                'SELECT title
                 FROM albums
                 WHERE id=?
                 LIMIT 1'
            );
            $albumCheck->execute([$albumId]);
            $albumTitle = trim(
                (string)($albumCheck->fetchColumn() ?: '')
            );

            if ($albumTitle === '') {
                throw new RuntimeException(
                    'Choose a valid album.'
                );
            }

            $album = $albumTitle;
        } elseif ($album === '') {
            $album = 'Stonefellow';
        }

        $producerDisplayName = '';

        if ($producerUserId > 0) {
            $producerCheck = $pdo->prepare(
                "SELECT u.id,u.display_name
                 FROM users u
                 WHERE u.id=?
                   AND u.is_active=1
                   AND (
                     u.role='producer'
                     OR EXISTS (
                       SELECT 1
                       FROM user_account_types uat
                       WHERE uat.user_id=u.id
                         AND uat.role='producer'
                     )
                   )
                 LIMIT 1"
            );
            $producerCheck->execute([$producerUserId]);
            $producerAccount = $producerCheck->fetch();

            if (!$producerAccount) {
                throw new RuntimeException(
                    'Choose an active Producer account.'
                );
            }

            $producerDisplayName = trim(
                (string)($producerAccount['display_name'] ?? '')
            );
        }

        global $config;
        $audioUpload = upload_file(
            $_FILES['audio_file'] ?? [],
            ['mp3','m4a','wav','ogg'],
            ['audio/mpeg','audio/mp4','audio/x-m4a','audio/wav','audio/x-wav','audio/ogg','application/octet-stream'],
            (int)($config['uploads']['max_audio_bytes'] ?? 26214400),
            'audio'
        );
        if ($audioUpload) {
            $audioPath = $audioUpload;
        }

        $coverUpload = upload_file(
            $_FILES['cover_file'] ?? [],
            ['jpg','jpeg','png','webp'],
            ['image/jpeg','image/png','image/webp'],
            (int)($config['uploads']['max_image_bytes'] ?? 5242880),
            'covers'
        );
        if ($coverUpload) {
            $coverPath = $coverUpload;
        }

        if ($audioPath === '') {
            throw new RuntimeException('Upload an audio file or keep an existing audio path.');
        }

        if ($id > 0) {
            $priorProducerStmt = $pdo->prepare(
                'SELECT producer_user_id,owner_user_id,is_published
                 FROM tracks
                 WHERE id=?
                 LIMIT 1'
            );
            $priorProducerStmt->execute([$id]);
            $priorTrackShare = $priorProducerStmt->fetch() ?: [];
            $priorProducerUserId = (int)(
                $priorTrackShare['producer_user_id'] ?? 0
            );
            $ownerUserId = (int)(
                $priorTrackShare['owner_user_id'] ?? 0
            );
            $wasPublished = (int)(
                $priorTrackShare['is_published'] ?? 0
            );

            $stmt = $pdo->prepare(
                'UPDATE tracks
                 SET title=?, album=?, album_id=?, duration=?, description=?, credits=?, genre=?, mood=?, energy=?, tempo_bpm=?, keywords=?, audio_path=?, cover_path=?, sort_order=?, is_published=?, visibility=?, producer_user_id=?
                 WHERE id=?'
            );
            $stmt->execute([
                $title,
                $album,
                $albumId ?: null,
                $duration,
                $description,
                $credits,
                $genre,
                $mood,
                $energy,
                $tempoBpm ?: null,
                $keywords,
                $audioPath,
                $coverPath,
                $sortOrder,
                $published,
                $visibility,
                $producerUserId ?: null,
                $id
            ]);

            if (
                $producerUserId > 0 &&
                $producerUserId !== $priorProducerUserId
            ) {
                create_notification(
                    $producerUserId,
                    'producer_track_share',
                    'Track shared with you',
                    $title . ' is now available in your Producer Workspace.',
                    url('/admin/stems.php?track=' . $id)
                );
            }

            if ($producerUserId !== $priorProducerUserId) {
                $actorUserId = (int)(current_user()['id'] ?? 0);
                $chatRecipients = array_values(
                    array_unique(
                        array_filter(
                            [$actorUserId, $ownerUserId],
                            static fn(int $value): bool => $value > 0
                        )
                    )
                );

                if ($producerUserId > 0) {
                    $activityTitle = 'Track shared with Producer';
                    $activityBody =
                        $title
                        . ' was shared with '
                        . ($producerDisplayName !== ''
                            ? $producerDisplayName
                            : 'a Producer')
                        . '.';
                } else {
                    $activityTitle = 'Producer access removed';
                    $activityBody =
                        'Producer access was removed from '
                        . $title
                        . '.';
                }

                foreach ($chatRecipients as $recipientId) {
                    create_notification(
                        (int)$recipientId,
                        'agent_track_share',
                        $activityTitle,
                        $activityBody,
                        url('/admin/track.php?id=' . $id)
                    );
                }
            }

            if (
                $published === 1 &&
                $wasPublished !== 1
            ) {
                player_notify_new_release(
                    'track',
                    $id,
                    $title,
                    url('/chat.php?view=player')
                );
            }

            flash(
                'notice',
                $producerUserId > 0
                    ? 'Track updated and shared with the selected producer.'
                    : 'Track updated.'
            );
        } else {
            $ownerUserId = (int)(current_user()['id'] ?? 0);

            $stmt = $pdo->prepare(
                'INSERT INTO tracks
                 (owner_user_id,producer_user_id,album_id,title,album,duration,lyrics,credits,description,genre,mood,energy,tempo_bpm,keywords,audio_path,cover_path,sort_order,is_published,visibility)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $ownerUserId ?: null,
                $producerUserId ?: null,
                $albumId ?: null,
                $title,
                $album,
                $duration,
                '',
                $credits,
                $description,
                $genre,
                $mood,
                $energy,
                $tempoBpm ?: null,
                $keywords,
                $audioPath,
                $coverPath,
                $sortOrder,
                $published,
                $visibility
            ]);

            $newId = (int)$pdo->lastInsertId();

            if ($producerUserId > 0) {
                create_notification(
                    $producerUserId,
                    'producer_track_share',
                    'Track shared with you',
                    $title . ' is now available in your Producer Workspace.',
                    url('/admin/stems.php?track=' . $newId)
                );

                if ($ownerUserId > 0) {
                    create_notification(
                        $ownerUserId,
                        'agent_track_share',
                        'Track shared with Producer',
                        $title
                        . ' was shared with '
                        . ($producerDisplayName !== ''
                            ? $producerDisplayName
                            : 'a Producer')
                        . '.',
                        url('/admin/track.php?id=' . $newId)
                    );
                }
            }

            if ($published === 1) {
                player_notify_new_release(
                    'track',
                    $newId,
                    $title,
                    url('/chat.php?view=player')
                );
            }

            flash('notice', 'Track added.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/tracks.php'));
}

if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$producers = $pdo->query(
    "SELECT DISTINCT u.id,u.display_name,u.email,u.is_active
     FROM users u
     LEFT JOIN user_account_types uat
       ON uat.user_id=u.id AND uat.role='producer'
     WHERE u.role='producer' OR uat.role='producer'
     ORDER BY u.is_active DESC,u.display_name ASC,u.id ASC"
)->fetchAll();

$albums = $pdo->query(
    'SELECT id,title,is_published
     FROM albums
     ORDER BY sort_order,title,id'
)->fetchAll();

$stemProject = $editing ? stem_project_for_track($editId) : null;
$tracks = $pdo->query(
    'SELECT
        t.*,
        u.display_name AS owner_name,
        producer.display_name AS producer_name,
        album_record.title AS album_title
     FROM tracks t
     LEFT JOIN users u
       ON u.id=t.owner_user_id
     LEFT JOIN users producer
       ON producer.id=t.producer_user_id
     LEFT JOIN albums album_record
       ON album_record.id=t.album_id
     ORDER BY t.sort_order ASC,t.id ASC'
)->fetchAll();

$adminTitle = 'Tracks';
$adminActive = 'tracks';
require __DIR__ . '/_header.php';
?>
<div class="panel">
  <div class="track-library-heading">
    <div>
      <span class="status">Music Library</span>
      <h2>Track / Media Library</h2>
      <p class="muted">Manage Stonefellow songs, access, media files, lyrics, knowledge and listening data.</p>
    </div>
    <div class="actions">
      <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="new_studio_project">
        <button class="btn primary desktop-studio-only" type="submit">+ New Studio Project</button>
      </form>
      <a class="btn" href="<?= e(url('/admin/tracks.php?new=1#track-form')) ?>">+ Add Catalog Track</a>
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead><tr><th>Order</th><th>Track</th><th>Owner</th><th>Producer</th><th>Album</th><th>Mood / Genre</th><th>Who Can View</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tracks as $track): ?>
        <tr>
          <td><?= (int)$track['sort_order'] ?></td>
          <td><?= e($track['title']) ?></td>
          <td><span class="muted"><?= e((string)($track['owner_name'] ?: 'Shared Catalog')) ?></span></td>
          <td><span class="muted"><?= e((string)($track['producer_name'] ?: '—')) ?></span></td>
          <td><?= e((string)($track['album_title'] ?: $track['album'])) ?></td>
          <td><span class="muted"><?= e(trim((string)$track['mood'] . ((string)$track['genre'] !== '' ? ' · ' . (string)$track['genre'] : '')) ?: '—') ?></span></td>
          <td><?= e(visibility_options()[$track['visibility']] ?? 'Public — Everyone') ?></td>
          <td><span class="status"><?= (int)$track['is_published'] ? 'Published' : 'Draft' ?></span></td>
          <td class="actions">
            <a class="btn primary" href="<?= e(url('/admin/track.php?id=' . (int)$track['id'])) ?>">Song Details</a>
            <a class="btn desktop-studio-only" href="<?= e(url('/admin/stems.php?track=' . (int)$track['id'])) ?>">Stem Studio</a>
            <a class="btn" href="<?= e(url('/admin/tracks.php?edit=' . (int)$track['id'] . '#track-form')) ?>">Edit Media</a>
            <form class="inline-form" method="post" onsubmit="return confirm('Delete this track?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$track['id'] ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$tracks): ?>
        <tr>
          <td colspan="9" class="muted">No tracks have been added yet.</td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($showForm): ?>
<div class="panel" id="track-form">
  <div class="track-form-heading">
    <div>
      <span class="status"><?= $editing ? 'Edit Media' : 'New Track' ?></span>
      <h2><?= $editing ? 'Edit Track / Media' : 'Add Track / Media' ?></h2>
    </div>
    <a class="btn" href="<?= e(url('/admin/tracks.php')) ?>">Close</a>
  </div>

  <?php if ($editing): ?>
    <p class="muted">Lyrics, supervisor notes, song knowledge and detailed listening stats are available in <a href="<?= e(url('/admin/track.php?id='.(int)$editing['id'])) ?>">Song Details</a>.</p>
  <?php endif; ?>

  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <input type="hidden" name="existing_audio_path" value="<?= e($editing['audio_path'] ?? '') ?>">
    <input type="hidden" name="existing_cover_path" value="<?= e($editing['cover_path'] ?? '/images/stonefellow-studio.png') ?>">

    <div class="field"><label>Title</label><input name="title" required value="<?= e($editing['title'] ?? '') ?>"></div>
    <div class="field">
      <label>Album</label>
      <select name="album_id">
        <option value="0">No managed album</option>
        <?php foreach ($albums as $albumOption): ?>
          <option
            value="<?= (int)$albumOption['id'] ?>"
            <?= (int)($editing['album_id'] ?? 0) === (int)$albumOption['id'] ? 'selected' : '' ?>
          >
            <?= e((string)$albumOption['title']) ?><?= (int)$albumOption['is_published'] ? '' : ' · Draft' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input
        type="hidden"
        name="album"
        value="<?= e((string)($editing['album'] ?? 'Stonefellow')) ?>"
      >
      <small>
        Create and assign albums under <a href="<?= e(url('/admin/albums.php')) ?>">Albums</a>.
      </small>
    </div>
    <div class="field"><label>Duration</label><input name="duration" placeholder="3:58" value="<?= e($editing['duration'] ?? '') ?>"></div>
    <div class="field"><label>Sort Order</label><input name="sort_order" type="number" value="<?= e($editing['sort_order'] ?? 0) ?>"></div>

    <div class="field full">
      <label>Song Description</label>
      <textarea name="description" style="min-height:90px" placeholder="Short description used by Agent Chat and recommendations."><?= e($editing['description'] ?? '') ?></textarea>
    </div>

    <div class="field full">
      <label>Credits</label>
      <textarea name="credits" style="min-height:72px" placeholder="Songwriter, producer, performers, engineers, guests, acknowledgements."><?= e((string)($editing['credits'] ?? '')) ?></textarea>
    </div>

    <div class="field">
      <label>Genre</label>
      <input name="genre" placeholder="Americana, rock, acoustic" value="<?= e($editing['genre'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Mood</label>
      <input name="mood" placeholder="reflective, dark, uplifting, chill" value="<?= e($editing['mood'] ?? '') ?>">
    </div>

    <div class="field">
      <label>Energy</label>
      <select name="energy">
        <?php foreach ([''=>'Not set','low'=>'Low','medium'=>'Medium','high'=>'High'] as $value=>$label): ?>
          <option value="<?= e($value) ?>" <?= (($editing['energy'] ?? '') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Tempo BPM</label>
      <input name="tempo_bpm" type="number" min="1" max="300" placeholder="92" value="<?= e($editing['tempo_bpm'] ?? '') ?>">
    </div>

    <div class="field full">
      <label>Recommendation Keywords</label>
      <input name="keywords" maxlength="500" placeholder="late night, road trip, heartbreak, acoustic guitar" value="<?= e($editing['keywords'] ?? '') ?>">
      <small>These fields are searched by Agent Chat when building mood playlists or suggesting what to play next.</small>
    </div>

    <div class="field full">
      <label>Who can view?</label>
      <select name="visibility" required>
        <?php foreach (visibility_options() as $value => $label): ?>
          <option value="<?= e($value) ?>" <?= (($editing['visibility'] ?? 'public') === $value) ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Admin accounts can view all restricted media. Each role-only choice is limited to that account type plus Admin.</small>
    </div>

    <?php if ($editing): ?>
      <div class="field full">
        <label for="producer_user_id">Share with Producer</label>
        <select id="producer_user_id" name="producer_user_id">
          <option value="0">Not shared with a producer</option>
          <?php foreach ($producers as $producer): ?>
            <option
              value="<?= (int)$producer['id'] ?>"
              <?= (int)($editing['producer_user_id'] ?? 0) === (int)$producer['id'] ? 'selected' : '' ?>
              <?= (int)$producer['is_active'] === 1 ? '' : 'disabled' ?>
            >
              <?= e((string)$producer['display_name']) ?> · <?= e((string)$producer['email']) ?><?= (int)$producer['is_active'] === 1 ? '' : ' · Disabled' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small>
          The selected Producer receives access to this track in their Producer Workspace and Stem Studio.
          <?= !$producers ? 'No Producer accounts exist yet. Add Producer under Admin → Users → Account Types.' : '' ?>
        </small>
      </div>
    <?php endif; ?>

    <div class="field full">
      <label>Audio File</label>
      <input name="audio_file" type="file" accept=".mp3,.m4a,.wav,.ogg,audio/*">
      <?php if ($editing): ?><small>Current: <?= e($editing['audio_path']) ?></small><?php endif; ?>
    </div>

    <div class="field full">
      <label>Cover Image</label>
      <input name="cover_file" type="file" accept=".jpg,.jpeg,.png,.webp,image/*">
      <?php if ($editing): ?><small>Current: <?= e($editing['cover_path']) ?></small><?php endif; ?>
    </div>

    <?php if ($editing): ?>
      <div class="field full">
        <label>REAPER / Online Stem Studio</label>

        <div class="stem-upload-box reaper-zip-primary" id="stemUploadBox">
          <div class="stem-upload-copy">
            <strong><?= $stemProject ? 'Replace REAPER production package' : 'Upload REAPER production package' ?></strong>
            <p>
              <span class="status">Stem Importer v79 · Native Plugin Mapping</span><br>
              Choose the REAPER ZIP once. Stonefellow reads and extracts it <strong>in your browser</strong>, automatically finds
              the <code>.rpp</code> project and the MP3/WAV stems, parses production metadata, and opens a review panel before anything is committed to the active library.
              <strong>The ZIP itself is never uploaded to or opened by the server.</strong>
            </p>
          </div>

          <?php if ($stemProject): ?>
            <div class="stem-upload-current">
              <span><?= (int)$stemProject['stem_count'] ?> stems</span>
              <?php if ($stemProject['tempo_bpm']): ?><span><?= e(rtrim(rtrim(number_format((float)$stemProject['tempo_bpm'],2), '0'), '.')) ?> BPM</span><?php endif; ?>
              <?php if ($stemProject['media_sample_rate']): ?><span><?= number_format((int)$stemProject['media_sample_rate']) ?> Hz media</span><?php endif; ?>
              <a class="btn desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.(int)$editing['id'])) ?>">Open Stem Studio</a>
            </div>
          <?php endif; ?>

          <input id="stemZipInput" type="file" accept=".zip,application/zip">

          <div class="stem-upload-actions">
            <button class="btn primary" id="stemUploadButton" type="button">
              <?= $stemProject ? 'Upload / Replace REAPER ZIP' : 'Upload REAPER ZIP' ?>
            </button>
            <button class="btn" id="stemUploadCancel" type="button" hidden>Cancel Upload</button>
            <a class="btn" href="<?= e(url('/admin/stem-diagnostics.php')) ?>">Server Diagnostics</a>
          </div>

          <div class="stem-upload-progress" id="stemUploadProgress" hidden>
            <div><span id="stemUploadProgressBar"></span></div>
            <strong id="stemUploadProgressText">Preparing REAPER ZIP…</strong>
          </div>

          <div class="stem-upload-status" id="stemUploadStatus" aria-live="polite"></div>

          <small>
            Stonefellow prefers MP3 stems inside the ZIP, then consolidated WAV stems if MP3s are not present.
            This bypasses HostGator ZIP handling entirely; only the extracted RPP and audio stems are sent to the server.
          </small>
        </div>

        <details class="legacy-stem-zip">
          <summary>Alternative: Upload MP3 stems and RPP separately</summary>

          <div class="stem-upload-box direct-stem-upload-box">
            <div class="stem-upload-copy">
              <strong>Direct production-file upload</strong>
              <p>
                Use this fallback if the hosting server cannot process a ZIP. Select synchronized MP3/WAV stems directly and,
                optionally, the REAPER <code>.rpp</code> file.
              </p>
            </div>

            <div class="direct-stem-fields">
              <div>
                <label for="directStemFiles">MP3 / WAV Stem Files</label>
                <input id="directStemFiles" type="file" accept=".mp3,.wav,audio/mpeg,audio/wav,audio/x-wav" multiple>
                <small>Select synchronized MP3/WAV stems. Parsed metadata can be edited before final save.</small>
              </div>

              <div>
                <label for="directRppFile">REAPER Project (.rpp) — Optional</label>
                <input id="directRppFile" type="file" accept=".rpp,.RPP,.rpp-bak,.RPP-BAK,text/plain">
                <small>The RPP may also be uploaded by itself and MP3 stems added later.</small>
              </div>
            </div>

            <div class="stem-upload-actions">
              <button class="btn" id="directStemUploadButton" type="button">Upload Production Files</button>
              <button class="btn" id="directStemUploadCancel" type="button" hidden>Cancel Upload</button>
            </div>

            <div class="stem-upload-progress" id="directStemProgress" hidden>
              <div><span id="directStemProgressBar"></span></div>
              <strong id="directStemProgressText">Preparing production files…</strong>
            </div>

            <div class="stem-upload-status" id="directStemStatus" aria-live="polite"></div>
          </div>
        </details>
      </div>
    <?php endif; ?>

    <div class="field full">
      <label class="admin-inline-check">
        <input name="is_published" type="checkbox" <?= !isset($editing['is_published']) || (int)$editing['is_published'] === 1 ? 'checked' : '' ?>>
        Published
      </label>
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit"><?= $editing ? 'Save Track' : 'Add Track' ?></button>
      <a class="btn" href="<?= e(url('/admin/tracks.php')) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($editing): ?>
<script>
window.STONEFELLOW_DIRECT_STEM_UPLOAD = <?= json_encode([
  'endpoint'=>url('/api/stem-direct-v79.php'),
  'trackId'=>(int)$editing['id'],
  'csrf'=>csrf_token(),
  'chunkBytes'=>stem_chunk_bytes(),
  'maxBytes'=>stem_max_package_bytes(),
  'track'=>[
    'title'=>(string)($editing['title'] ?? ''),
    'description'=>(string)($editing['description'] ?? ''),
    'genre'=>(string)($editing['genre'] ?? ''),
    'mood'=>(string)($editing['mood'] ?? ''),
    'energy'=>(string)($editing['energy'] ?? ''),
    'tempo_bpm'=>(int)($editing['tempo_bpm'] ?? 0),
    'keywords'=>(string)($editing['keywords'] ?? ''),
  ],
  'hasExisting'=>(bool)$stemProject,
], JSON_UNESCAPED_SLASHES) ?>;
window.STONEFELLOW_BROWSER_ZIP = <?= json_encode([
  'endpoint'=>url('/api/stem-direct-v79.php'),
  'trackId'=>(int)$editing['id'],
  'csrf'=>csrf_token(),
  'chunkBytes'=>stem_chunk_bytes(),
  'maxBytes'=>stem_max_package_bytes(),
  'track'=>[
    'title'=>(string)($editing['title'] ?? ''),
    'description'=>(string)($editing['description'] ?? ''),
    'genre'=>(string)($editing['genre'] ?? ''),
    'mood'=>(string)($editing['mood'] ?? ''),
    'energy'=>(string)($editing['energy'] ?? ''),
    'tempo_bpm'=>(int)($editing['tempo_bpm'] ?? 0),
    'keywords'=>(string)($editing['keywords'] ?? ''),
  ],
  'hasExisting'=>(bool)$stemProject,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= e(url('/admin/stem-import-review-v79.js')) ?>"></script>
<script src="<?= e(url('/admin/direct-stem-upload-v79.js')) ?>"></script>
<script src="<?= e(url('/admin/stem-browser-zip-v79.js')) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
