<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('admin.access');

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
$trackId = (int)($_GET['id'] ?? $_POST['track_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$stmt->execute([$trackId]);
$track = $stmt->fetch();

if (!$track) {
    http_response_code(404);
    exit('Track not found.');
}

$canProduceTrack = can_manage_track_production($track);

if (!$canProduceTrack) {
    http_response_code(403);
    exit('This track has not been shared with your account.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error','Session expired.');
        redirect(url('/admin/track.php?id='.$trackId));
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_production_details') {
            if (!$canProduceTrack) {
                throw new RuntimeException(
                    'This track has not been shared with your account.'
                );
            }

            $title = trim(
                (string)($_POST['title'] ?? '')
            );
            $album = trim(
                (string)($_POST['album'] ?? '')
            );
            $description = trim(
                (string)($_POST['description'] ?? '')
            );
            $genre = trim(
                (string)($_POST['genre'] ?? '')
            );
            $mood = trim(
                (string)($_POST['mood'] ?? '')
            );
            $energy = trim(
                (string)($_POST['energy'] ?? '')
            );
            $tempoBpm = max(
                0,
                min(
                    300,
                    (int)($_POST['tempo_bpm'] ?? 0)
                )
            );
            $keywords = trim(
                (string)($_POST['keywords'] ?? '')
            );

            if ($title === '') {
                throw new RuntimeException(
                    'Track title is required.'
                );
            }

            if (
                !in_array(
                    $energy,
                    ['', 'low', 'medium', 'high'],
                    true
                )
            ) {
                $energy = '';
            }

            if (strlen($keywords) > 500) {
                $keywords = substr(
                    $keywords,
                    0,
                    500
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE tracks
                 SET
                    title=?,
                    album=?,
                    description=?,
                    genre=?,
                    mood=?,
                    energy=?,
                    tempo_bpm=?,
                    keywords=?
                 WHERE id=?'
            );

            $stmt->execute([
                $title,
                $album,
                $description,
                $genre,
                $mood,
                $energy,
                $tempoBpm ?: null,
                $keywords,
                $trackId,
            ]);

            if (
                $tempoBpm > 0 &&
                table_exists('track_projects')
            ) {
                $projectTempo = $pdo->prepare(
                    'UPDATE track_projects
                     SET tempo_bpm=?
                     WHERE track_id=?'
                );
                $projectTempo->execute([
                    $tempoBpm,
                    $trackId,
                ]);
            }

            flash(
                'notice',
                'Production details updated.'
            );
        }

        if ($action === 'save_lyrics') {
            require_permission('tracks.manage');
            $lyrics = trim((string)($_POST['lyrics'] ?? ''));
            $stmt = $pdo->prepare('UPDATE tracks SET lyrics=? WHERE id=?');
            $stmt->execute([$lyrics,$trackId]);
            flash('notice','Lyrics updated.');
        }

        if ($action === 'add_note') {
            if (!$canProduceTrack) throw new RuntimeException('This track has not been shared with your account.');
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '') throw new RuntimeException('Enter a production note.');
            if (mb_strlen($note) > 5000) throw new RuntimeException('Note is too long.');
            $user = current_user();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO track_notes (track_id,user_id,note) VALUES (?,?,?)');
            $stmt->execute([$trackId,(int)$user['id'],$note]);
            $noteId=(int)$pdo->lastInsertId();$author=trim((string)($user['display_name']??''))?:'A teammate';$target=url('/admin/track.php?id='.$trackId.'#production-notes');
            $message=$author.' shared a production note on “'.(string)$track['title'].":\n\n".$note;
            $context=['sources'=>[['source'=>'database:track_notes','title'=>(string)$track['title']]],'media'=>[],'stem_media'=>[],'actions'=>[['label'=>'Open production note','url'=>$target]],'playlist_title'=>'','track_note'=>['id'=>$noteId,'track_id'=>$trackId,'author'=>$author]];
            foreach(agent_chat_v101_note_recipients($track,$user) as $recipient){$recipientId=(int)$recipient['id'];agent_chat_v101_append_ecosystem_message($recipient,$message,$context);if($recipientId!==(int)$user['id'])create_notification($recipientId,'production_note',(string)$track['title'].' · Production note',$author.' shared a note: '.mb_strimwidth($note,0,300,'…'),$target,'track_note',$noteId);}
            $pdo->commit();
            flash('notice','Production note shared in Agent Chat.');
        }

        if ($action === 'delete_note') {
            require_permission('track_notes.manage');
            $noteId = (int)($_POST['note_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM track_notes WHERE id=? AND track_id=?');
            $stmt->execute([$noteId,$trackId]);
            flash('notice','Production note deleted. The Agent Chat message remains in the collaboration history.');
        }

        if ($action === 'add_knowledge') {
            require_permission('knowledge.manage');
            $title = trim((string)($_POST['knowledge_title'] ?? ''));
            $description = trim((string)($_POST['knowledge_description'] ?? ''));
            $content = trim((string)($_POST['knowledge_text'] ?? ''));
            $visibility = trim((string)($_POST['knowledge_visibility'] ?? $track['visibility']));

            if ($title === '') throw new RuntimeException('Knowledge title is required.');
            if (!valid_visibility($visibility)) throw new RuntimeException('Invalid knowledge visibility.');

            $fileName = '';
            $filePath = '';
            $fileType = 'text';
            $mimeType = '';
            $fileSize = 0;
            $extracted = '';

            $upload = $_FILES['knowledge_file'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('File upload failed.');
                if ((int)$upload['size'] > 50*1024*1024) throw new RuntimeException('Knowledge files are limited to 50 MB.');

                $extension = strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));
                $allowed = ['txt','md','csv','json','html','htm','xml','doc','docx','pdf','mp3','m4a','wav','ogg'];
                if (!in_array($extension,$allowed,true)) throw new RuntimeException('Unsupported file type.');

                $dir = STONEFELLOW_ROOT.'/uploads/knowledge';
                if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Could not create upload folder.');

                $saved = bin2hex(random_bytes(16)).'.'.$extension;
                $absolute = $dir.'/'.$saved;
                if (!move_uploaded_file((string)$upload['tmp_name'],$absolute)) throw new RuntimeException('Could not save file.');

                $fileName = basename((string)$upload['name']);
                $filePath = '/uploads/knowledge/'.$saved;
                $fileType = $extension;
                $fileSize = (int)$upload['size'];

                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $detected = finfo_file($finfo,$absolute);
                        if (is_string($detected)) $mimeType = $detected;
                        finfo_close($finfo);
                    }
                }

                $extracted = knowledge_extract_file_text($absolute,$extension);
            }

            if ($extracted !== '') $content = trim($content."\n\n".$extracted);

            $user = current_user();
            $stmt = $pdo->prepare(
                'INSERT INTO knowledge_items
                 (track_id,title,description,file_name,file_path,file_type,mime_type,file_size,content_text,visibility,is_published,created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,1,?)'
            );
            $stmt->execute([
                $trackId,$title,$description,$fileName,$filePath,$fileType,$mimeType,$fileSize,$content,$visibility,(int)$user['id']
            ]);
            $knowledgeId = (int)$pdo->lastInsertId();
            reindex_knowledge_item($knowledgeId,$content);
            flash('notice','Song knowledge added and indexed.');
        }

        if ($action === 'delete_knowledge') {
            require_permission('knowledge.manage');
            $knowledgeId = (int)($_POST['knowledge_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM knowledge_items WHERE id=? AND track_id=? LIMIT 1');
            $stmt->execute([$knowledgeId,$trackId]);
            $item = $stmt->fetch();
            if ($item) {
                delete_local_upload((string)$item['file_path']);
                $pdo->prepare('DELETE FROM knowledge_items WHERE id=?')->execute([$knowledgeId]);
            }
            flash('notice','Song knowledge deleted.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error',$e->getMessage());
    }

    redirect(url('/admin/track.php?id='.$trackId));
}

$stmt = $pdo->prepare(
    'SELECT n.*,u.display_name,u.role
     FROM track_notes n
     JOIN users u ON u.id=n.user_id
     WHERE n.track_id=?
     ORDER BY n.created_at DESC'
);
$stmt->execute([$trackId]);
$notes = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT k.*,
       (SELECT COUNT(*) FROM knowledge_chunks c WHERE c.knowledge_id=k.id) AS chunk_count
     FROM knowledge_items k WHERE k.track_id=? ORDER BY k.updated_at DESC'
);
$stmt->execute([$trackId]);
$knowledge = $stmt->fetchAll();

$stemProject = stem_project_for_track($trackId);
$stats = [];
if (has_permission('listening.view')) {
    $stmt = $pdo->prepare(
        'SELECT
           COUNT(*) AS starts,
           SUM(CASE WHEN qualified_play=1 THEN 1 ELSE 0 END) AS qualified_plays,
           COUNT(DISTINCT CONCAT(CASE WHEN user_id IS NULL THEN "a:" ELSE "u:" END, COALESCE(CAST(user_id AS CHAR),listener_hash))) AS unique_listeners,
           SUM(listened_seconds) AS listened_seconds,
           AVG(listened_seconds) AS avg_listen_seconds,
           AVG(completion_percent) AS avg_completion,
           SUM(CASE WHEN completed=1 THEN 1 ELSE 0 END) AS completions
         FROM track_play_sessions WHERE track_id=?'
    );
    $stmt->execute([$trackId]);
    $stats = $stmt->fetch() ?: [];
}

function track_admin_time(float $seconds): string {
    if ($seconds < 60) return number_format($seconds,0).' sec';
    if ($seconds < 3600) return number_format($seconds/60,1).' min';
    return number_format($seconds/3600,2).' hr';
}

$adminTitle = (string)$track['title'];
$adminActive = 'tracks';
require __DIR__ . '/_header.php';
?>
<div class="track-admin-head panel">
  <div class="track-admin-identity">
    <img src="<?= e(url('/media.php?track='.$trackId.'&type=cover')) ?>" alt="">
    <div>
      <span class="status"><?= e(visibility_options()[$track['visibility']] ?? $track['visibility']) ?></span>
      <h2><?= e($track['title']) ?></h2>
      <p class="muted"><?= e($track['album']) ?><?= $track['duration'] ? ' · '.e($track['duration']) : '' ?></p>
    </div>
  </div>
  <div class="actions">
    <a class="btn" href="<?= e(url('/track.php?id='.$trackId)) ?>" target="_blank">Public Song Page</a>
    <?php if ($canProduceTrack): ?><a class="btn primary desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.$trackId)) ?>">Stem Studio</a><?php endif; ?>
    <?php if ($canProduceTrack): ?><a class="btn desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.$trackId.'&export=1')) ?>">Export MP3/WAV</a><?php endif; ?>
    <?php if (has_permission('tracks.manage')): ?><a class="btn" href="<?= e(url('/admin/tracks.php?edit='.$trackId)) ?>">Edit Media</a><?php endif; ?>
  </div>
</div>

<?php if (
  has_permission('producer.access') &&
  !has_permission('tracks.manage')
): ?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">Producer Edit</span>
      <h2>Track Details</h2>
      <p class="muted">
        Edit the production metadata for this shared track. Publishing,
        visibility, ownership and Producer assignment remain controlled by
        Stonefellow track management.
      </p>
    </div>

    <a
      class="btn primary"
      href="<?= e(url('/admin/stems.php?track='.$trackId)) ?>"
    >Edit Audio in Stem Studio</a>
  </div>

  <form class="grid-form" method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_production_details">
    <input type="hidden" name="track_id" value="<?= $trackId ?>">

    <div class="field">
      <label>Track Title</label>
      <input
        name="title"
        maxlength="190"
        required
        value="<?= e((string)$track['title']) ?>"
      >
    </div>

    <div class="field">
      <label>Album / Collection</label>
      <input
        name="album"
        maxlength="190"
        value="<?= e((string)$track['album']) ?>"
      >
    </div>

    <div class="field full">
      <label>Description</label>
      <textarea
        name="description"
        style="min-height:90px"
      ><?= e((string)$track['description']) ?></textarea>
    </div>

    <div class="field">
      <label>Genre</label>
      <input
        name="genre"
        value="<?= e((string)$track['genre']) ?>"
      >
    </div>

    <div class="field">
      <label>Mood</label>
      <input
        name="mood"
        value="<?= e((string)$track['mood']) ?>"
      >
    </div>

    <div class="field">
      <label>Energy</label>
      <select name="energy">
        <?php foreach (
          [
            ''=>'Not set',
            'low'=>'Low',
            'medium'=>'Medium',
            'high'=>'High'
          ] as $value=>$label
        ): ?>
          <option
            value="<?= e($value) ?>"
            <?= (string)$track['energy'] === $value ? 'selected' : '' ?>
          ><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label>Tempo BPM</label>
      <input
        name="tempo_bpm"
        type="number"
        min="1"
        max="300"
        value="<?= e((string)($track['tempo_bpm'] ?? '')) ?>"
      >
    </div>

    <div class="field full">
      <label>Keywords</label>
      <input
        name="keywords"
        maxlength="500"
        value="<?= e((string)$track['keywords']) ?>"
      >
    </div>

    <div class="field full actions">
      <button class="btn primary" type="submit">
        Save Track Details
      </button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (has_permission('listening.view')): ?>
<div class="grid listening-metrics">
  <div class="metric"><strong><?= number_format((int)($stats['starts'] ?? 0)) ?></strong><span>Starts</span></div>
  <div class="metric"><strong><?= number_format((int)($stats['qualified_plays'] ?? 0)) ?></strong><span>10s+ Plays</span></div>
  <div class="metric"><strong><?= number_format((int)($stats['unique_listeners'] ?? 0)) ?></strong><span>Listeners</span></div>
  <div class="metric"><strong><?= e(track_admin_time((float)($stats['listened_seconds'] ?? 0))) ?></strong><span>Total Listen</span></div>
  <div class="metric"><strong><?= e(track_admin_time((float)($stats['avg_listen_seconds'] ?? 0))) ?></strong><span>Avg Listen</span></div>
  <div class="metric"><strong><?= number_format((float)($stats['avg_completion'] ?? 0),1) ?>%</strong><span>Avg Completion</span></div>
</div>
<?php endif; ?>


<?php if ($canProduceTrack): ?>
<div class="panel">
  <div class="content-library-heading">
    <div>
      <span class="status">REAPER / Production</span>
      <h2>Stem Package</h2>
      <p class="muted">
        <?= $stemProject
          ? ((int)$stemProject['stem_count'] > 0
              ? (int)$stemProject['stem_count'] . ' synchronized web stems are available.'
              : 'A REAPER project is attached. Add MP3 stems when you are ready.')
          : 'No REAPER project or synchronized stem package has been imported for this song yet.' ?>
      </p>
    </div>

    <?php if ($stemProject || !has_permission('tracks.manage')): ?>
      <a class="btn primary desktop-studio-only" href="<?= e(url('/admin/stems.php?track='.$trackId)) ?>">Open Stem Studio</a>
    <?php else: ?>
      <a class="btn" href="<?= e(url('/admin/tracks.php?edit='.$trackId.'#track-form')) ?>">Upload Stem Package</a>
    <?php endif; ?>
  </div>

  <?php if ($stemProject): ?>
    <div class="stem-summary-row">
      <?php if ($stemProject['tempo_bpm']): ?><span><?= e(rtrim(rtrim(number_format((float)$stemProject['tempo_bpm'],2),'0'),'.')) ?> BPM</span><?php endif; ?>
      <?php if ($stemProject['time_signature']): ?><span><?= e($stemProject['time_signature']) ?></span><?php endif; ?>
      <?php if ($stemProject['project_sample_rate']): ?><span>Project <?= number_format((int)$stemProject['project_sample_rate']) ?> Hz</span><?php endif; ?>
      <?php if ($stemProject['media_sample_rate']): ?><span>Stems <?= number_format((int)$stemProject['media_sample_rate']) ?> Hz</span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (has_permission('tracks.manage')): ?>
<div class="panel">
  <h2>Lyrics</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_lyrics"><input type="hidden" name="track_id" value="<?= $trackId ?>">
    <div class="field"><textarea name="lyrics" style="min-height:360px" placeholder="Add the song lyrics..."><?= e($track['lyrics'] ?? '') ?></textarea></div>
    <div class="actions" style="margin-top:12px"><button class="btn primary" type="submit">Save Lyrics</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($canProduceTrack): ?>
<div class="panel" id="production-notes">
  <h2>Shared Production Notes</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_note"><input type="hidden" name="track_id" value="<?= $trackId ?>">
    <div class="field"><textarea name="note" style="min-height:110px" placeholder="Share a production note with authorized producers and supervisors in Agent Chat..." required></textarea></div>
    <div class="actions" style="margin-top:12px"><button class="btn primary" type="submit">Share Note</button></div>
  </form>

  <div class="track-note-list">
    <?php foreach ($notes as $note): ?>
      <article class="track-note">
        <div><strong><?= e($note['display_name']) ?></strong><span><?= e(role_label((string)$note['role'])) ?> · <?= e(date('M j, Y g:i A',strtotime((string)$note['created_at']))) ?><?php if (($note['region_start_seconds'] ?? null) !== null): ?> · <?= e(agent_chat_v101_format_time((float)$note['region_start_seconds']).'–'.agent_chat_v101_format_time((float)$note['region_end_seconds'])) ?><?php endif; ?></span></div>
        <p><?= nl2br(e($note['note'])) ?></p>
        <?php if (has_permission('track_notes.manage')): ?>
        <form method="post" onsubmit="return confirm('Delete this production note? The Agent Chat message will remain in the collaboration history.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete_note"><input type="hidden" name="track_id" value="<?= $trackId ?>"><input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
          <button class="btn danger" type="submit">Delete</button>
        </form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if (!$notes): ?><p class="muted">No production notes yet.</p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (has_permission('knowledge.manage')): ?>
<div class="panel">
  <h2>Song Knowledge Base</h2>
  <form class="grid-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_knowledge"><input type="hidden" name="track_id" value="<?= $trackId ?>">
    <div class="field"><label>Title</label><input name="knowledge_title" required></div>
    <div class="field">
      <label>Who Can View / Use in Chat?</label>
      <select name="knowledge_visibility">
        <?php foreach (visibility_options() as $value=>$label): ?>
          <option value="<?= e($value) ?>" <?= $value===$track['visibility'] ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field full"><label>Description</label><textarea name="knowledge_description" style="min-height:80px"></textarea></div>
    <div class="field full"><label>File</label><input type="file" name="knowledge_file" accept=".txt,.md,.csv,.json,.html,.htm,.xml,.doc,.docx,.pdf,.mp3,.m4a,.wav,.ogg"><small>Documents, PDFs and music/audio supported.</small></div>
    <div class="field full"><label>Knowledge Text / Transcript / Credits / Notes</label><textarea name="knowledge_text" style="min-height:180px"></textarea></div>
    <div class="field full actions"><button class="btn primary" type="submit">Add Song Knowledge</button></div>
  </form>

  <div class="track-knowledge-admin-list">
    <?php foreach ($knowledge as $item): ?>
      <article class="track-knowledge-admin-item">
        <div>
          <span class="status"><?= e(strtoupper((string)$item['file_type'])) ?> · <?= e(visibility_options()[$item['visibility']] ?? $item['visibility']) ?></span>
          <strong><?= e($item['title']) ?></strong>
          <p><?= e($item['description']) ?></p>
          <small><?= (int)$item['chunk_count'] ?> indexed chunks<?= $item['file_name'] ? ' · '.e($item['file_name']) : '' ?></small>
        </div>
        <div class="actions">
          <?php if ($item['file_path']): ?><a class="btn" href="<?= e(url('/knowledge-file.php?id='.(int)$item['id'])) ?>" target="_blank">Open</a><?php endif; ?>
          <form method="post" onsubmit="return confirm('Delete this song knowledge item?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_knowledge"><input type="hidden" name="track_id" value="<?= $trackId ?>"><input type="hidden" name="knowledge_id" value="<?= (int)$item['id'] ?>">
            <button class="btn danger" type="submit">Delete</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$knowledge): ?><p class="muted">No knowledge items attached to this song yet.</p><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
