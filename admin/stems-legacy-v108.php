<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_permission('chat.access');

if (!headers_sent()) {
    header('Permissions-Policy: microphone=(self)');
}

if (!access_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$pdo = db();
$trackId = (int)($_GET['track'] ?? $_POST['track_id'] ?? 0);

$studioReturnFallback = url('/chat.php');
$studioReturnSessionKey = 'stonefellow_studio_return';
$studioResolveReturn = static function (string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') return '';
    $parts = parse_url($candidate);
    if ($parts === false) return '';

    $candidateHost = strtolower((string)($parts['host'] ?? ''));
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHost = preg_replace('/:\d+$/', '', $requestHost) ?? $requestHost;
    if ($candidateHost !== '' && $candidateHost !== $requestHost) return '';

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== '' && !in_array($scheme, ['http','https'], true)) return '';

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || !str_starts_with($path, '/')) return '';
    if (str_ends_with($path, '/admin/stems.php')) return '';

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
    }

    // Camera/media workspaces and Agent handoffs are transient UI state. They
    // must never become the persistent Exit Studio destination because doing
    // so simply reopens the popup/canvas the user already left.
    if (str_ends_with($path, '/chat.php')) {
        foreach (['media','media_mode','camera','camera_index','capture','agent_prompt','agent_source'] as $key) {
            unset($query[$key]);
        }
    }

    $result = $path;
    if ($query) $result .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if (!empty($parts['fragment'])) $result .= '#' . $parts['fragment'];
    return $result;
};

$explicitStudioReturn = $studioResolveReturn((string)($_GET['return'] ?? $_POST['return'] ?? ''));
$referrerStudioReturn = $studioResolveReturn((string)($_SERVER['HTTP_REFERER'] ?? ''));
$studioReturnUrl = $explicitStudioReturn !== ''
    ? $explicitStudioReturn
    : ($referrerStudioReturn !== '' ? $referrerStudioReturn : '');

if ($studioReturnUrl !== '') {
    $_SESSION[$studioReturnSessionKey] = $studioReturnUrl;
} elseif (!empty($_SESSION[$studioReturnSessionKey])) {
    $studioReturnUrl = $studioResolveReturn((string)$_SESSION[$studioReturnSessionKey]);
}

if ($studioReturnUrl === '') {
    $studioReturnUrl = $studioReturnFallback;
}

$stmt = $pdo->prepare('SELECT * FROM tracks WHERE id=? LIMIT 1');
$stmt->execute([$trackId]);
$track = $stmt->fetch();

if (!$track) {
    http_response_code(404);
    exit('Track not found.');
}

$currentStudioUser = current_user();
$teamChatEnabled = team_chat_role_allowed($currentStudioUser);
$currentStudioUserId = (int)($currentStudioUser['id'] ?? 0);
$canGlobalTrackManage = has_permission(
    'tracks.manage',
    $currentStudioUser
);
$canProduceTrack = can_manage_track_production(
    $track,
    $currentStudioUser
);
$fanPrivateMix = user_has_role('fan', $currentStudioUser) && !$canProduceTrack;

if (!$canProduceTrack && !$fanPrivateMix) {
    http_response_code(403);
    exit('This track has not been shared with your account.');
}

$project = stem_project_for_track($trackId);
$stems = stems_for_track($trackId);
$regionNotes = [];
if (column_exists('track_notes','region_start_seconds') && column_exists('track_notes','region_end_seconds')) {
    try {
        $regionNoteStmt=$pdo->prepare(
            'SELECT n.id,n.note,n.region_start_seconds,n.region_end_seconds,n.created_at,u.display_name
             FROM track_notes n INNER JOIN users u ON u.id=n.user_id
             WHERE n.track_id=? AND n.region_start_seconds IS NOT NULL AND n.region_end_seconds IS NOT NULL
             ORDER BY n.id DESC LIMIT 300'
        );
        $regionNoteStmt->execute([$trackId]);
        foreach(array_reverse($regionNoteStmt->fetchAll()) as $row){$regionNotes[]=['id'=>(int)$row['id'],'start'=>(float)$row['region_start_seconds'],'end'=>(float)$row['region_end_seconds'],'label'=>mb_strimwidth((string)$row['note'],0,80,'…'),'note'=>(string)$row['note'],'author'=>(string)$row['display_name'],'created_at'=>(string)$row['created_at'],'shared'=>true];}
    } catch (Throwable $e) {}
}

$canSaveMix =
    $currentStudioUserId > 0 &&
    (
        $fanPrivateMix ||
        has_permission('track_notes.manage') ||
        $canProduceTrack ||
        (int)($track['owner_user_id'] ?? 0) === $currentStudioUserId
    );
$ownedProjects = [];

if ($currentStudioUserId > 0) {
    try {
        $ownedStmt = $pdo->prepare(
            'SELECT
                t.id,
                t.title,
                t.album,
                t.cover_path,
                t.duration,
                t.updated_at,
                p.project_name,
                COALESCE(
                    NULLIF(p.tempo_bpm,0),
                    NULLIF(t.tempo_bpm,0),
                    120
                ) AS project_tempo,
                COALESCE(
                    NULLIF(p.time_signature,\'\'),
                    \'4/4\'
                ) AS time_signature,
                (
                    SELECT COUNT(*)
                    FROM track_stems s
                    WHERE s.track_id=t.id
                      AND s.is_active=1
                ) AS stem_count
             FROM tracks t
             LEFT JOIN track_projects p
               ON p.track_id=t.id
             WHERE
                t.owner_user_id=?
                OR t.producer_user_id=?
             ORDER BY t.updated_at DESC
             LIMIT 120'
        );
        $ownedStmt->execute([
            $currentStudioUserId,
            $currentStudioUserId
        ]);
        $ownedProjects = $ownedStmt->fetchAll();
    } catch (Throwable $e) {
        $ownedProjects = [];
    }
}

/*
 * v47 treats every track opened in Stem Studio as a project. Older catalog
 * tracks may not have track_projects metadata yet, so create the lightweight
 * project row automatically instead of sending the user back to Edit Media.
 */
if (!$project && !$fanPrivateMix) {
    try {
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
            $trackId,
            (string)$track['title'],
            !empty($track['tempo_bpm'])
                ? (float)$track['tempo_bpm']
                : 120.0,
            '4/4',
            $currentStudioUserId ?: null,
        ]);

        $project = stem_project_for_track($trackId);
    } catch (Throwable $e) {
        // A concurrent request may have created the unique track project.
        $project = stem_project_for_track($trackId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        flash('error', 'Session expired.');
        redirect(url('/admin/stems.php?track=' . $trackId));
    }

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_stem') {
            $stemId = (int)($_POST['stem_id'] ?? 0);
            $name = trim((string)($_POST['stem_name'] ?? ''));
            $role = trim((string)($_POST['stem_role'] ?? 'Other'));

            if ($stemId < 1 || $name === '') {
                throw new RuntimeException('Stem name is required.');
            }

            $allowedRoles = ['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'];
            if (!in_array($role, $allowedRoles, true)) {
                $role = 'Other';
            }

            $stmt = $pdo->prepare(
                'UPDATE track_stems
                 SET stem_name=?,stem_role=?
                 WHERE id=? AND track_id=?'
            );
            $stmt->execute([$name,$role,$stemId,$trackId]);
            flash('notice', 'Stem updated.');
        }

        if ($action === 'delete_package') {
            stem_delete_track_package($trackId);
            flash('notice', 'REAPER stem package removed.');
            redirect(url('/admin/tracks.php?edit=' . $trackId . '#track-form'));
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(url('/admin/stems.php?track=' . $trackId));
}

$project = stem_project_for_track($trackId);
$stems = stems_for_track($trackId);

$sourceTempo = 120.0;

if (
    $project &&
    !empty($project['tempo_bpm']) &&
    (float)$project['tempo_bpm'] > 0
) {
    $sourceTempo = (float)$project['tempo_bpm'];
} elseif (
    !empty($track['tempo_bpm']) &&
    (float)$track['tempo_bpm'] > 0
) {
    $sourceTempo = (float)$track['tempo_bpm'];
}

$sourceTempo = max(40.0, min(300.0, $sourceTempo));

$libraryStems = [];
$libraryRoles = [];

$studioTrackScopeSql = $canGlobalTrackManage
    ? ''
    : ' AND t.producer_user_id=' . (int)$currentStudioUserId;

try {
    $libraryStmt = $pdo->query(
        "SELECT
            s.id,
            s.track_id,
            s.stem_name,
            s.stem_role,
            s.file_name,
            s.duration_seconds,
            s.start_offset_seconds,
            s.channels,
            s.sample_rate,
            s.bit_depth,
            s.rpp_fx_summary,
            t.title AS track_title,
            t.album AS track_album,
            COALESCE(
                NULLIF(p.tempo_bpm,0),
                NULLIF(t.tempo_bpm,0),
                120
            ) AS source_tempo,
            COALESCE(NULLIF(p.time_signature,''),'4/4') AS time_signature
         FROM track_stems s
         INNER JOIN tracks t
            ON t.id=s.track_id
         LEFT JOIN track_projects p
            ON p.track_id=s.track_id
         WHERE s.is_active=1"
        . $studioTrackScopeSql .
        "
         ORDER BY
            s.stem_role,
            t.title,
            s.sort_order,
            s.id
         LIMIT 600"
    );

    $libraryStems = $libraryStmt
        ? $libraryStmt->fetchAll()
        : [];
} catch (Throwable $e) {
    $libraryStems = [];
}

foreach ($libraryStems as $libraryStem) {
    $role = trim(
        (string)($libraryStem['stem_role'] ?? 'Other')
    );

    if ($role === '') {
        $role = 'Other';
    }

    $libraryRoles[stem_lower($role)] = $role;
}

natcasesort($libraryRoles);

$loadSongs = [];

try {
    $loadSongStmt = $pdo->query(
        "SELECT
            t.id,
            t.title,
            t.album,
            t.duration,
            t.genre,
            t.mood,
            t.tempo_bpm,
            t.audio_path,
            t.cover_path,
            t.updated_at,
            p.project_name,
            p.tempo_bpm AS project_tempo,
            p.time_signature,
            (
                SELECT COUNT(*)
                FROM track_stems sx
                WHERE sx.track_id=t.id
                  AND sx.is_active=1
            ) AS stem_count,
            (
                SELECT sx.id
                FROM track_stems sx
                WHERE sx.track_id=t.id
                  AND sx.is_active=1
                ORDER BY sx.sort_order ASC,sx.id ASC
                LIMIT 1
            ) AS first_stem_id
         FROM tracks t
         LEFT JOIN track_projects p
           ON p.track_id=t.id
         WHERE
           ("
        . (
            $canGlobalTrackManage
                ? "1=1"
                : "t.producer_user_id=" . (int)$currentStudioUserId
        ) .
        ")
           AND (
             TRIM(COALESCE(t.audio_path,'')) <> ''
             OR EXISTS (
                 SELECT 1
                 FROM track_stems se
                 WHERE se.track_id=t.id
                   AND se.is_active=1
             )
           )
         ORDER BY
            t.updated_at DESC,
            t.title ASC
         LIMIT 120"
    );

    $loadSongs = $loadSongStmt
        ? $loadSongStmt->fetchAll()
        : [];
} catch (Throwable $e) {
    $loadSongs = [];
}

$maxDuration = 0.0;
$payload = [];
$durationMeasures = max(
    0,
    (int)($project['duration_measures'] ?? 0)
);

foreach ($stems as $stem) {
    $stemSourceTempo = $sourceTempo;
    $initialSourceStart = 0.0;
    $initialSourceEnd = max(
        0.05,
        (float)$stem['duration_seconds']
    );

    $stemFxSummary =
        (string)($stem['rpp_fx_summary'] ?? '');
    $takeOfStemId = 0;

    if (
        preg_match(
            '/Take of stem:\s*(\d+)/i',
            $stemFxSummary,
            $takeMatch
        )
    ) {
        $takeOfStemId =
            max(
                0,
                (int)$takeMatch[1]
            );
    }

    if (
        preg_match(
            '/(?:Library|Recorded) tempo:\s*([0-9.]+)\s*BPM/i',
            $stemFxSummary,
            $tempoMatch
        )
    ) {
        $stemSourceTempo = max(
            40.0,
            min(
                300.0,
                (float)$tempoMatch[1]
            )
        );
    }

    if (
        preg_match(
            '/Library clip start:\s*([0-9.]+)/i',
            $stemFxSummary,
            $startMatch
        )
    ) {
        $initialSourceStart = max(
            0.0,
            min(
                $initialSourceEnd - 0.01,
                (float)$startMatch[1]
            )
        );
    }

    if (
        preg_match(
            '/Library clip end:\s*([0-9.]+)/i',
            $stemFxSummary,
            $endMatch
        )
    ) {
        $initialSourceEnd = max(
            $initialSourceStart + 0.01,
            min(
                max(
                    0.05,
                    (float)$stem['duration_seconds']
                ),
                (float)$endMatch[1]
            )
        );
    }

    $timelineRatio =
        $stemSourceTempo /
        max(40.0,$sourceTempo);

    $timelineEnd =
        (float)$stem['start_offset_seconds']
        +
        (
            (float)$stem['duration_seconds']
            * $timelineRatio
        );

    $maxDuration = max(
        $maxDuration,
        $timelineEnd
    );

    $payload[] = [
        'id'=>(int)$stem['id'],
        'name'=>(string)$stem['stem_name'],
        'role'=>(string)$stem['stem_role'],
        'duration'=>(float)$stem['duration_seconds'],
        'offset'=>(float)$stem['start_offset_seconds'],
        'sourceTempo'=>$stemSourceTempo,
        'initialSourceStart'=>$initialSourceStart,
        'initialSourceEnd'=>$initialSourceEnd,
        'isLibrarySample'=>preg_match(
            '/Library tempo:\s*[0-9.]+\s*BPM/i',
            $stemFxSummary
        ) === 1,
        'isEmptyRecordingTrack'=>str_contains(
            $stemFxSummary,
            'Empty recording track'
        ),
        'recordingInputLabel'=>(static function(string $summary): string {
            return preg_match('/Recording input:\s*([^·]+)/i',$summary,$match)
                ? trim((string)$match[1])
                : '';
        })($stemFxSummary),
        'recordingInputChannel'=>(static function(string $summary): int {
            return preg_match('/Recording channel:\s*(\d+)/i',$summary,$match)
                ? max(1,(int)$match[1])
                : 1;
        })($stemFxSummary),
        'takeOfStemId'=>$takeOfStemId,
        'volume'=>(float)$stem['rpp_volume'],
        'pan'=>(float)$stem['rpp_pan'],
        'pluginChain'=>(static function($json): array { $decoded=json_decode((string)$json,true); return is_array($decoded)?$decoded:[]; })($stem['plugin_chain_json'] ?? ''),
        'url'=>url('/stem-media-v34.php?id=' . (int)$stem['id']),
    ];
}

if ($maxDuration <= 0) {
    $maxDuration = 180.0;
}

$adminTitle = 'Stem Studio';
$adminActive = 'tracks';
$adminCanvasMode = true;
require __DIR__ . '/_header.php';
?>
<header class="daw-canvas-header daw-canvas-header-v92">
  <div class="daw-canvas-left daw-canvas-left-v92">
    <button
      class="daw-header-menu-button"
      id="studioMainMenuToggle"
      type="button"
      aria-haspopup="menu"
      aria-expanded="false"
      aria-controls="studioMainMenu"
    >
      <span>☰</span>
      <strong>Menu</strong>
    </button>

    <div class="daw-header-audio daw-header-audio-v92" id="studioHeaderAudio">
      <select
        id="studioAudioInput"
        aria-label="Recording input device registry"
        hidden
      >
        <option value="">Connect / scan audio inputs…</option>
      </select>

      <button
        class="daw-header-audio-connect"
        id="studioInputAccess"
        type="button"
        aria-haspopup="dialog"
        aria-controls="audioPermissionDialog"
        title="Connect or rescan USB/audio-interface inputs"
      >Connect Audio</button>

      <button
        class="daw-header-audio-monitor"
        id="studioMonitorButton"
        type="button"
        aria-pressed="false"
        title="Monitor live input through the Studio master output"
      >MON</button>

      <span
        class="daw-header-input-meter daw-header-input-meter-v92"
        id="studioInputMeter"
        aria-label="Input level"
      ><i></i></span>

      <span
        class="daw-header-record-status daw-header-record-status-v92"
        id="studioRecordStatus"
        title="Audio input status"
      >INPUT OFF</span>
    </div>

    <?php if ($canSaveMix): ?>
      <span class="daw-header-save-status daw-header-autosave-status" id="studioSaveStatus" aria-live="polite">Autosave on</span>
    <?php endif; ?>
  </div>

  <div class="daw-canvas-actions">
    <div class="daw-header-metronome">
      <button
        class="daw-header-button daw-metronome-button"
        id="studioMetronomeButton"
        type="button"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="studioMetronomeMenu"
        title="Metronome settings"
        aria-label="Metronome settings"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M9 3h6l3.1 17H5.9L9 3Z"></path>
          <path d="M12 6v8"></path>
          <path d="m12 9 4-3"></path>
        </svg>
      </button>

      <div
        class="daw-metronome-menu"
        id="studioMetronomeMenu"
        role="dialog"
        aria-label="Metronome settings"
        hidden
      >
        <div class="daw-metronome-menu-head">
          <div>
            <span>METRONOME</span>
            <strong id="studioMetronomeTempo">120 BPM · 4/4</strong>
          </div>
          <button type="button" id="studioMetronomeToggle" aria-pressed="false">OFF</button>
        </div>

        <label class="daw-metronome-count-in">
          <span>COUNT-IN</span>
          <select id="studioMetronomeCountIn">
            <option value="0">Off</option>
            <option value="1">1 Bar</option>
            <option value="2">2 Bars</option>
            <option value="4">4 Bars</option>
          </select>
        </label>

        <div class="daw-metronome-options">
          <label class="daw-metronome-option">
            <span>CLICK STYLE</span>
            <select id="studioMetronomeStyle">
              <option value="classic">Classic</option>
              <option value="wood">Wood Block</option>
              <option value="rim">Rim Click</option>
              <option value="digital">Digital</option>
              <option value="soft">Soft</option>
            </select>
          </label>
          <label class="daw-metronome-option">
            <span>ACCENT</span>
            <select id="studioMetronomeAccent">
              <option value="downbeat">Downbeat</option>
              <option value="backbeat">Backbeat · 2 &amp; 4</option>
              <option value="alternating">Alternating</option>
              <option value="none">No Accent</option>
            </select>
          </label>
          <div class="daw-metronome-free-run">
            <span>STANDALONE CLICK</span>
            <button id="studioMetronomeFreeRun" type="button" aria-pressed="false">OFF</button>
          </div>
        </div>
        <small class="v91-metronome-help">Transport mode stays locked to the Studio timeline and recordings. Standalone mode keeps clicking while transport is stopped.</small>
      </div>
    </div>

    <a
      class="daw-header-button"
      href="<?= e($studioReturnUrl) ?>"
      title="Return to the page that opened Stem Studio"
    >Exit Studio</a>

    <button
      class="daw-header-button daw-library-header-button"
      id="openTrackLibrary"
      type="button"
      aria-expanded="false"
      aria-controls="trackLibraryDrawer"
    >Track Library</button>
  </div>
</header>

<?php if ($fanPrivateMix): ?>
  <style>
    [data-studio-new-project],[data-studio-import-single],[data-studio-import-multiple],[data-studio-export-audio],
    [data-context-track-delete],[data-delete-studio-track],.daw-import-panel,.stem-project-package{display:none!important}
  </style>
  <div class="daw-studio-private-notice" role="status">
    Private fan mix — your mix edits save only to your account. Source files, sharing, and downloads are unavailable.
  </div>
<?php endif; ?>

<div
  class="daw-studio-menu daw-studio-menu-v92"
  id="studioMainMenu"
  role="menu"
  aria-label="Stem Studio menu"
  hidden
>
  <div class="daw-studio-menu-project-v92">
    <span>NOW EDITING</span>
    <strong><?= e((string)($project['project_name'] ?? $track['title'])) ?></strong>
    <small>
      <?= e((string)$track['title']) ?>
      <?php if (trim((string)($track['album'] ?? '')) !== ''): ?>
        · <?= e((string)$track['album']) ?>
      <?php endif; ?>
      · <?= e(rtrim(rtrim(number_format($sourceTempo,2),'0'),'.')) ?> BPM
      · <?= e((string)($project['time_signature'] ?? '4/4')) ?>
    </small>
  </div>

  <button type="button" data-studio-open-project class="daw-menu-primary-v92">
    <strong>Open Project</strong>
    <span><?= $canGlobalTrackManage ? 'Browse projects saved to your account' : 'Browse tracks shared with your Producer account' ?></span>
  </button>

  <?php if ($canSaveMix): ?>
    <button type="button" id="studioSaveAsButton">
      <strong>Save As</strong>
      <span>Create a named version while autosave continues in the background</span>
    </button>
  <?php endif; ?>

  <div class="daw-studio-menu-separator"></div>

  <?php if ($canGlobalTrackManage): ?>
    <button type="button" data-studio-new-project>
      <strong>New Project</strong>
      <span>Create an empty Studio project</span>
    </button>
  <?php endif; ?>

  <button type="button" data-studio-load-song>
    <strong>Load Song</strong>
    <span>Browse uploaded songs and catalog audio</span>
  </button>

  <button type="button" data-studio-song-info>
    <strong>Song Info</strong>
    <span>View project and source-song details</span>
  </button>

  <button type="button" data-studio-import-single title="New empty recording track · Alt+T">
    <strong>New Track</strong>
    <span>Create an empty track for live recording</span>
  </button>

  <button type="button" data-studio-import-multiple>
    <strong>Import Media</strong>
    <span>Import one or more WAV/MP3 files as Studio tracks</span>
  </button>

  <button type="button" data-studio-export-audio>
    <strong>Export Audio Files</strong>
    <span>Download the shared MP3/WAV source files</span>
  </button>

  <div class="daw-studio-menu-separator"></div>

  <?php if ($canGlobalTrackManage): ?>
    <button type="button" class="danger" data-studio-delete-project>
      <strong>Delete Project</strong>
      <span>Delete this project and its imported media</span>
    </button>
  <?php endif; ?>
</div>

<div
  class="daw-song-info-menu"
  id="songInfoMenu"
  role="dialog"
  aria-label="Song information"
  hidden
>
  <div class="daw-song-info-head">
    <div>
      <span>PROJECT</span>
      <strong><?= e((string)($project['project_name'] ?? $track['title'])) ?></strong>
    </div>
    <button type="button" data-close-song-info aria-label="Close Song Info">×</button>
  </div>

  <dl>
    <div><dt>Song</dt><dd><?= e($track['title']) ?></dd></div>
    <div><dt>Album</dt><dd><?= e($track['album'] ?: '—') ?></dd></div>
    <div><dt>Source Tempo</dt><dd><?= e(rtrim(rtrim(number_format($sourceTempo,2),'0'),'.')) ?> BPM</dd></div>
    <div><dt>Time Signature</dt><dd><?= e((string)($project['time_signature'] ?? '4/4')) ?></dd></div>
    <div><dt>Imported Tracks</dt><dd><?= count($stems) ?></dd></div>
    <div>
      <dt>Account</dt>
      <dd>
        <?= (int)($track['owner_user_id'] ?? 0) === $currentStudioUserId && $currentStudioUserId > 0
          ? 'Saved to ' . e((string)($currentStudioUser['display_name'] ?? 'My Account'))
          : 'Not saved to this account' ?>
      </dd>
    </div>
  </dl>

  <div class="daw-song-info-actions">
    <a href="<?= e(url('/admin/track.php?id='.$trackId)) ?>">Song Details</a>
    <?php if ($canGlobalTrackManage): ?>
      <a href="<?= e(url('/admin/tracks.php?edit='.$trackId.'#track-form')) ?>">Catalog Media</a>
    <?php endif; ?>
  </div>
</div>

<input
  type="file"
  id="studioImportSingle"
  accept=".wav,.mp3,audio/wav,audio/mpeg"
  hidden
>

<input
  type="file"
  id="studioImportMultiple"
  accept=".wav,.mp3,audio/wav,audio/mpeg"
  multiple
  hidden
>

<div class="daw-library-backdrop" id="trackLibraryBackdrop" hidden></div>

<div
  class="daw-route-popover"
  id="trackRoutePopover"
  role="menu"
  aria-label="Track routing"
  hidden
></div>

<div
  class="daw-route-popover daw-library-category-menu"
  id="trackLibraryCategoryMenu"
  role="menu"
  aria-label="Library category"
  hidden
></div>

<div
  class="daw-track-context-menu"
  id="studioTrackContextMenu"
  role="menu"
  aria-label="Track actions"
  hidden
>
  <button type="button" role="menuitem" data-context-track-settings>Track Inspector</button>
  <button type="button" role="menuitem" data-context-track-automation>Automation</button>
  <button type="button" role="menuitem" data-context-track-arm>Arm Recording</button>
  <button type="button" role="menuitem" data-context-track-mute>Mute</button>
  <button type="button" role="menuitem" data-context-track-solo>Solo</button>
  <div class="daw-track-context-separator"></div>
  <button type="button" role="menuitem" class="danger" data-context-track-delete>Delete Track</button>
</div>

<div
  class="daw-inspector-backdrop"
  id="trackInspectorBackdrop"
  hidden
></div>

<aside
  class="daw-track-inspector"
  id="trackInspector"
  aria-label="Track Inspector"
  aria-hidden="true"
>
  <header class="daw-track-inspector-head">
    <div>
      <span>TRACK INSPECTOR</span>
      <strong id="inspectorTrackName">No track selected</strong>
      <small id="inspectorTrackMeta">Select a source track</small>
    </div>

    <button
      class="daw-track-inspector-close"
      id="closeTrackInspector"
      type="button"
      aria-label="Close Track Inspector"
    >×</button>
  </header>

  <div class="daw-track-inspector-scroll">
    <section class="daw-inspector-section">
      <div class="daw-inspector-section-title">
        <strong>CHANNEL</strong>
        <span id="inspectorTrackState">—</span>
      </div>

      <label class="daw-inspector-field">
        <span>NAME</span>
        <input
          id="inspectorTrackNameInput"
          maxlength="190"
          autocomplete="off"
        >
      </label>

      <label class="daw-inspector-field">
        <span>ROLE</span>
        <select id="inspectorTrackRole">
          <?php foreach (['Vocal','Drums','Percussion','Bass','Guitar','Keys','Synth','Other'] as $role): ?>
            <option value="<?= e($role) ?>"><?= e($role) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button
        type="button"
        class="daw-inspector-save-details"
        id="inspectorSaveTrackDetails"
      >SAVE TRACK DETAILS</button>

      <label class="daw-inspector-field">
        <span>INPUT</span>
        <select id="inspectorTrackInput">
          <option value="">Connect audio input…</option>
        </select>
      </label>

      <label class="daw-inspector-field">
        <span>BUS</span>
        <select id="inspectorTrackBus">
          <option value="direct">DIRECT</option>
          <option value="vocals">VOCALS</option>
          <option value="rhythm">RHYTHM</option>
          <option value="music">MUSIC</option>
        </select>
      </label>

      <label class="daw-inspector-field daw-inspector-range">
        <span>VOLUME <output id="inspectorVolumeValue">0.0 dB</output></span>
        <input id="inspectorVolume" type="range" min="0" max="1.5" step="0.01" value="1">
      </label>

      <div class="daw-inspector-two">
        <label class="daw-inspector-field daw-inspector-range">
          <span>PAN <output id="inspectorPanValue">C</output></span>
          <input id="inspectorPan" type="range" min="-1" max="1" step="0.01" value="0">
        </label>

        <label class="daw-inspector-field daw-inspector-range">
          <span>TRIM <output id="inspectorTrimValue">0.0 dB</output></span>
          <input id="inspectorTrim" type="range" min="-12" max="12" step="0.5" value="0">
        </label>
      </div>

      <div class="daw-inspector-two">
        <label class="daw-inspector-field daw-inspector-range">
          <span>SEND A <output id="inspectorSendAValue">0%</output></span>
          <input id="inspectorSendA" type="range" min="0" max="1" step="0.01" value="0">
        </label>

        <label class="daw-inspector-field daw-inspector-range">
          <span>SEND B <output id="inspectorSendBValue">0%</output></span>
          <input id="inspectorSendB" type="range" min="0" max="1" step="0.01" value="0">
        </label>
      </div>

      <div class="daw-inspector-button-row">
        <button type="button" id="inspectorArm">ARM</button>
        <button type="button" id="inspectorMute">MUTE</button>
        <button type="button" id="inspectorSolo">SOLO</button>
        <button type="button" id="inspectorAutomation">AUTOMATION</button>
      </div>
    </section>

    <section class="daw-inspector-section">
      <div class="daw-inspector-section-title">
        <strong>PLUGINS</strong>
        <span id="inspectorPluginCount">0 inserts</span>
      </div>

      <div class="daw-inspector-plugin-list" id="inspectorPluginList">
        <span>No plugins inserted.</span>
      </div>

      <div class="daw-inspector-button-row">
        <button type="button" id="inspectorAddPlugin">+ ADD PLUGIN</button>
        <button type="button" id="inspectorOpenPluginRack">OPEN RACK</button>
      </div>
    </section>

    <section class="daw-inspector-section">
      <div class="daw-inspector-section-title">
        <strong>CLIP</strong>
        <span id="inspectorClipName">No clip selected</span>
      </div>

      <label class="daw-inspector-field daw-inspector-range">
        <span>CLIP GAIN <output id="inspectorClipGainValue">0.0 dB</output></span>
        <input id="inspectorClipGain" type="range" min="-24" max="12" step="0.5" value="0">
      </label>

      <div class="daw-inspector-two">
        <label class="daw-inspector-field daw-inspector-range">
          <span>FADE IN <output id="inspectorFadeInValue">0.00s</output></span>
          <input id="inspectorFadeIn" type="range" min="0" max="5" step="0.01" value="0">
        </label>

        <label class="daw-inspector-field daw-inspector-range">
          <span>FADE OUT <output id="inspectorFadeOutValue">0.00s</output></span>
          <input id="inspectorFadeOut" type="range" min="0" max="5" step="0.01" value="0">
        </label>
      </div>

      <div class="daw-inspector-button-row">
        <button type="button" id="inspectorClipMute">MUTE CLIP</button>
        <button type="button" id="inspectorAutoCrossfade">AUTO CROSSFADE</button>
      </div>
    </section>

    <section class="daw-inspector-section">
      <div class="daw-inspector-section-title">
        <strong>RECORDING</strong>
        <span id="inspectorRecordingState">Ready</span>
      </div>

      <label class="daw-live-recording-option">
        <input id="inspectorLiveRecording" type="checkbox">
        <span>
          <strong>LIVE RECORDING</strong>
          <small>Print this track's post-fader output as a separate synchronized WAV take.</small>
        </span>
      </label>

      <div class="daw-inspector-two">
        <label class="daw-inspector-field">
          <span>COUNT-IN</span>
          <select id="recordCountInBars">
            <option value="0">Off</option>
            <option value="1">1 Bar</option>
            <option value="2">2 Bars</option>
            <option value="4">4 Bars</option>
          </select>
        </label>

        <label class="daw-inspector-field">
          <span>METRONOME</span>
          <button type="button" id="recordMetronomeToggle" aria-pressed="false">OFF</button>
        </label>
      </div>

      <div class="daw-inspector-two">
        <label class="daw-inspector-field">
          <span>PUNCH</span>
          <button type="button" id="recordPunchToggle" aria-pressed="false">OFF</button>
        </label>

        <div class="daw-inspector-field">
          <span>PUNCH RANGE</span>
          <button type="button" id="recordPunchFromLoop">USE LOOP / SELECTION</button>
        </div>
      </div>

      <div class="daw-inspector-recording-range">
        <span id="recordPunchRange">No punch range</span>
      </div>

      <div class="daw-inspector-button-row">
        <button type="button" id="inspectorRecordButton">● RECORD</button>
        <button type="button" id="inspectorStopRecordButton" disabled>■ STOP</button>
      </div>
    </section>
  </div>
</aside>

<aside
  class="daw-track-library"
  id="trackLibraryDrawer"
  aria-label="Stem track library"
  aria-hidden="true"
>
  <header class="daw-track-library-head">
    <div>
      <span>STONEFELLOW</span>
      <h2>Track Library</h2>
      <small><?= count($libraryStems) ?> stems</small>
    </div>

    <button
      class="daw-track-library-close"
      id="closeTrackLibrary"
      type="button"
      aria-label="Close Track Library"
    >×</button>
  </header>

  <div class="daw-track-library-filters">
    <label>
      <span>SEARCH</span>
      <input
        id="trackLibrarySearch"
        type="search"
        placeholder="Stem, song, category…"
        autocomplete="off"
      >
    </label>

    <label class="daw-library-category-filter">
      <span>CATEGORY</span>

      <select
        id="trackLibraryCategory"
        class="daw-library-category-native"
        tabindex="-1"
        aria-hidden="true"
      >
        <option value="">All categories</option>
        <?php foreach ($libraryRoles as $libraryRoleKey=>$libraryRole): ?>
          <option value="<?= e((string)$libraryRoleKey) ?>"><?= e((string)$libraryRole) ?></option>
        <?php endforeach; ?>
      </select>

      <button
        id="trackLibraryCategoryButton"
        class="daw-library-category-button"
        type="button"
        aria-haspopup="menu"
        aria-expanded="false"
      >All categories <i>⌄</i></button>
    </label>
  </div>

  <div class="daw-track-library-list" id="trackLibraryList">
    <?php foreach ($libraryStems as $libraryStem): ?>
      <?php
        $libraryId = (int)$libraryStem['id'];
        $libraryName = trim((string)$libraryStem['stem_name']);
        $libraryRole = trim((string)($libraryStem['stem_role'] ?: 'Other'));
        $librarySong = trim((string)$libraryStem['track_title']);
        $libraryAlbum = trim((string)($libraryStem['track_album'] ?: 'Stonefellow'));
        $libraryDuration = max(0.0, (float)$libraryStem['duration_seconds']);
        $libraryTempo = max(40.0, min(
            300.0,
            (float)($libraryStem['source_tempo'] ?: 120)
        ));
        $librarySignature = trim((string)($libraryStem['time_signature'] ?: '4/4'));
        $librarySearch = stem_lower(
            $libraryName . ' ' .
            $libraryRole . ' ' .
            $librarySong . ' ' .
            $libraryAlbum . ' ' .
            (string)($libraryStem['rpp_fx_summary'] ?? '')
        );
        $libraryAudioUrl = url('/stem-media-v34.php?id=' . $libraryId);
      ?>
      <article
        class="daw-library-card"
        data-library-card
        data-library-stem-id="<?= $libraryId ?>"
        data-library-name="<?= e($libraryName) ?>"
        data-library-role="<?= e($libraryRole) ?>"
        data-library-song="<?= e($librarySong) ?>"
        data-library-category="<?= e(stem_lower($libraryRole)) ?>"
        data-library-search="<?= e($librarySearch) ?>"
        data-library-duration="<?= e((string)$libraryDuration) ?>"
        data-library-tempo="<?= e((string)$libraryTempo) ?>"
        data-library-signature="<?= e($librarySignature) ?>"
        data-library-url="<?= e($libraryAudioUrl) ?>"
      >
        <div class="daw-library-card-head">
          <div>
            <strong><?= e($libraryName) ?></strong>
            <span><?= e($librarySong) ?></span>
          </div>
          <em><?= e($libraryRole) ?></em>
        </div>

        <div class="daw-library-meta">
          <span><?= e(rtrim(rtrim(number_format($libraryTempo,2),'0'),'.')) ?> BPM</span>
          <span><?= e($librarySignature) ?></span>
          <span><?= e(stem_format_duration($libraryDuration)) ?></span>
          <?php if ((int)$libraryStem['channels'] > 0): ?>
            <span><?= (int)$libraryStem['channels'] === 1 ? 'Mono' : 'Stereo' ?></span>
          <?php endif; ?>
        </div>

        <audio
          class="daw-library-audio"
          controls
          preload="none"
          src="<?= e($libraryAudioUrl) ?>"
        ></audio>

        <div class="daw-library-card-actions">
          <small>Preview a section, then add a 4-bar clip.</small>
          <button
            class="daw-library-add-track"
            type="button"
            data-library-add-track
          >Add Track</button>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (!$libraryStems): ?>
      <p class="daw-library-empty">No imported stems are available yet.</p>
    <?php endif; ?>
  </div>
</aside>

<?php if (!$project): ?>
  <div class="panel">
    <h2>No Production Files</h2>
    <p class="muted">Upload MP3 stems, a REAPER .rpp project, or both from Edit Track.</p>
    <a class="btn primary" href="<?= e(url('/admin/tracks.php?edit='.$trackId.'#track-form')) ?>">Upload Production Files</a>
  </div>
<?php else: ?>

<div class="daw-studio" id="stemStudio">
  <?php if (!$stems): ?>
    <div class="daw-empty-project-hint">
      <strong>Empty Project</strong>
      <span>Open Menu → Import Media to add your first track.</span>
    </div>
  <?php endif; ?>
  <div class="daw-workspace">
    <aside class="daw-track-panel">
      <div class="daw-panel-title">
        <div>
          <span>TRACKS</span>
          <strong><?= e($project['project_name']) ?></strong>
        </div>
        <span><?= count($stems) ?></span>
      </div>

      <div class="daw-track-list" id="dawTrackList">
        <?php foreach ($stems as $stem): ?>
          <article
            class="daw-track-row"
            data-stem-id="<?= (int)$stem['id'] ?>"
            data-drag-stem="<?= (int)$stem['id'] ?>"
          >
            <button
              class="daw-track-select"
              type="button"
              data-track-select
              aria-label="Select <?= e($stem['stem_name']) ?>"
            >
              <span class="daw-track-dot"></span>
              <span>
                <strong><?= e($stem['stem_name']) ?></strong>
                <small><?= e($stem['stem_role']) ?> · <?= e(strtoupper(pathinfo((string)$stem['file_name'], PATHINFO_EXTENSION))) ?></small>
              </span>
            </button>

            <div class="daw-track-row-controls">
              <span class="daw-drag-handle" data-drag-handle draggable="true" title="Drag to reorder">⋮⋮</span>
              <button type="button" class="daw-auto-button" data-automation-toggle="<?= (int)$stem['id'] ?>" title="Automation">A</button>
              <button
                type="button"
                class="daw-ms daw-arm daw-sidebar-arm"
                data-sidebar-track-arm="<?= (int)$stem['id'] ?>"
                aria-pressed="false"
                aria-label="Arm <?= e($stem['stem_name']) ?> for recording"
                title="Arm track for recording"
              >R</button>
              <button type="button" class="daw-ms daw-mute" data-stem-mute title="Mute">M</button>
              <button type="button" class="daw-ms daw-solo" data-stem-solo title="Solo">S</button>
            </div>

            <button
              type="button"
              class="daw-track-inspector-row-button"
              data-open-track-inspector="<?= (int)$stem['id'] ?>"
              title="Track Inspector"
              aria-label="Open Track Inspector for <?= e($stem['stem_name']) ?>"
            >⚙</button>

            <audio
              class="stem-audio"
              preload="auto"
              src="<?= e(url('/stem-media-v34.php?id='.(int)$stem['id'])) ?>"
              data-stem-audio="<?= (int)$stem['id'] ?>"
            ></audio>
          </article>
        <?php endforeach; ?>
      </div>
    </aside>

    <section class="daw-arrange" id="dawArrange">
      <div
        class="daw-timeline-surface"
        id="dawTimelineSurface"
        data-duration="<?= e((string)$maxDuration) ?>"
      >
        <div class="daw-marker-lane" id="dawMarkerLane" aria-label="Song markers and regions"></div>

        <div class="daw-ruler" id="dawRuler" aria-label="Song timeline. Click to seek or drag to create a loop.">
          <div class="daw-ruler-lines" id="dawRulerLines" aria-hidden="true"></div>
        </div>

        <div class="daw-arrange-lanes" id="dawArrangeLanes">
        <?php foreach ($stems as $stem): ?>
          <?php
            $offset = (float)$stem['start_offset_seconds'];
            $duration = (float)$stem['duration_seconds'];
            $left = $maxDuration > 0 ? ($offset / $maxDuration * 100) : 0;
            $width = $maxDuration > 0 ? ($duration / $maxDuration * 100) : 100;
          ?>
          <div class="daw-arrange-row" data-arrange-stem="<?= (int)$stem['id'] ?>">
            <div class="daw-arrange-track">
              <div
                class="daw-main-clip-layer"
                data-main-clip-layer="<?= (int)$stem['id'] ?>"
                aria-label="<?= e($stem['stem_name']) ?> editable clips"
              ></div>
            </div>

            <div class="daw-automation-lane" data-automation-lane hidden>
              <div class="daw-automation-toolbar">
                <span>AUTOMATION</span>

                <select data-automation-parameter aria-label="Automation parameter">
                  <option value="volume">Volume</option>
                  <option value="pan">Pan</option>
                  <option value="auxA">Send A · Room</option>
                  <option value="auxB">Send B · Delay</option>
                </select>

                <button
                  type="button"
                  class="daw-automation-action"
                  data-automation-delete
                  disabled
                  title="Delete selected automation point"
                >Delete Point</button>

                <button
                  type="button"
                  class="daw-automation-action"
                  data-automation-clear
                  title="Clear the selected automation lane"
                >Clear Lane</button>

                <button
                  type="button"
                  class="daw-automation-action"
                  data-automation-clear-all
                  title="Clear all automation for this track"
                >Clear All</button>

                <small>Click lane to add · drag to edit · Alt-click point to delete · Ctrl+Z undo</small>
              </div>

              <svg
                class="daw-automation-graph"
                data-automation-graph
                viewBox="0 0 1000 88"
                preserveAspectRatio="none"
                aria-label="<?= e($stem['stem_name']) ?> automation editor"
              >
                <line class="daw-automation-midline" x1="0" y1="44" x2="1000" y2="44"></line>
                <path data-automation-path d=""></path>
                <g data-automation-points></g>
              </svg>
            </div>
          </div>
        <?php endforeach; ?>
        </div>

        <div class="daw-loop-selection" id="dawLoopSelection" hidden aria-hidden="true">
          <span id="dawLoopLabel"></span>
        </div>
        <div class="daw-recording-region" id="dawRecordingRegion" hidden aria-hidden="true">
          <span>REC</span>
        </div>
        <div class="daw-playhead" id="dawPlayhead" aria-hidden="true"></div>
      </div>
    </section>
  </div>

  <section class="daw-mixer" aria-label="Stem mixer">
    <div class="daw-mixer-toolbar">
      <div class="daw-transport daw-transport-compact">
        <button class="daw-play" id="stemPlayButton" type="button" aria-label="Play">▶</button>
        <button
          class="daw-record-button"
          id="studioRecordButton"
          type="button"
          aria-label="Record audio"
          aria-pressed="false"
          title="Record from the selected audio input"
        >●</button>
        <div class="daw-time daw-current-time" id="stemCurrentTime">0:00</div>
        <span class="daw-time-divider">/</span>
        <div class="daw-time" id="stemSongDuration"><?= e(stem_format_duration($maxDuration)) ?></div>
      </div>

      <div class="daw-session-meta">
        <span><?= e($project['time_signature'] ?: '—') ?></span>
        <span><?= $project['project_sample_rate'] ? number_format((int)$project['project_sample_rate']) : '—' ?> Hz</span>
      </div>

      <div class="daw-toolbar-actions">
        <label class="daw-tempo-control" title="Session tempo. All tracks follow this BPM.">
          <span>TEMPO</span>
          <input
            id="sessionTempo"
            type="number"
            min="40"
            max="300"
            step="0.1"
            value="<?= e(rtrim(rtrim(number_format($sourceTempo,2),'0'),'.')) ?>"
            aria-label="Session tempo"
          >
          <em>BPM</em>
        </label>

        <label class="daw-tempo-control" title="Authoritative song duration in measures.">
          <span>DURATION</span>
          <input
            id="songDurationMeasures"
            type="number"
            min="1"
            max="4096"
            step="1"
            value="<?= $durationMeasures > 0 ? $durationMeasures : '' ?>"
            placeholder="Auto"
            aria-label="Song duration in measures"
          >
          <em>MEASURES</em>
        </label>

        <button class="daw-small-button" id="resetSessionTempo" type="button" title="Reset to source tempo">
          SRC
        </button>

        <button
          class="daw-small-button daw-snap-toggle active"
          id="timelineSnapToggle"
          type="button"
          aria-pressed="true"
          title="Stick edits to the beat guide. Click for free edit."
        >SNAP: GRID</button>

        <button class="daw-small-button" id="timelineZoomOut" type="button" title="Zoom timeline out">−</button>
        <span class="daw-zoom-readout" id="timelineZoomValue">100%</span>
        <button class="daw-small-button" id="timelineZoomIn" type="button" title="Zoom timeline in">+</button>
        <button class="daw-small-button" id="addTimelineMarker" type="button">+ Marker</button>
        <button class="daw-small-button" id="addTimelineRegion" type="button" title="Attach a shared production note to a timeline range">+ Note</button>
        <button class="daw-small-button daw-loop-button" id="stemLoopToggle" type="button" disabled>Loop: Off</button>
        <button class="daw-small-button" id="stemLoopClear" type="button" hidden>Clear Loop</button>
        <button class="daw-small-button" id="stemResetMix" type="button">Reset</button>
        <button class="daw-small-button" id="addMixerBus" type="button">+ Add Bus</button>
      </div>
    </div>

    <button
      class="daw-plugin-rack-handle"
      id="pluginRackHandle"
      type="button"
      aria-expanded="false"
      aria-controls="dawPluginRack"
      title="Drag up or click to open channel plugins"
    >
      <span></span>
      <strong>CHANNEL PLUGINS</strong>
      <small>drag up</small>
    </button>

    <div class="daw-mixer-scroll" id="dawMixerScroll">
      <article class="daw-channel daw-master-channel" data-plugin-target="master">
        <div class="daw-channel-head">
          <strong>MASTER</strong>
          <span>OUTPUT</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="master">
          <div class="daw-track-plugin-list" data-universal-plugin-list="master"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="master">+ Plugin</button>
        </div>

        <button type="button" class="daw-master-bus-button" id="openMasterBusChannel">
          FX
        </button>

        <div class="daw-master-control-body">
          <div class="daw-master-meter" aria-label="Master output level">
            <span data-master-meter="l"><i></i></span>
            <span data-master-meter="r"><i></i></span>
          </div>

          <label class="daw-fader-wrap daw-master-fader">
            <span>MASTER</span>
            <input
              id="stemMasterVolume"
              class="daw-fader daw-master-volume-control"
              type="range"
              min="0"
              max="1.5"
              value="1"
              step="0.01"
              orient="vertical"
              aria-label="Master volume"
            >
            <output id="stemMasterValue">0.0 dB</output>
          </label>
        </div>
      </article>

      <article class="daw-channel daw-return-channel" data-return-channel="a" data-plugin-target="aux-a">
        <div class="daw-channel-head">
          <strong>AUX A</strong>
          <span>ROOM RETURN</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="aux-a">
          <div class="daw-track-plugin-list" data-universal-plugin-list="aux-a"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="aux-a">+ Plugin</button>
        </div>

        <div class="daw-return-badge">REVERB</div>
        <div class="daw-return-description">Shared room send</div>
        <label class="daw-fader-wrap daw-return-fader">
          <input
            id="auxReturnA"
            class="daw-fader daw-return-volume-control"
            type="range"
            min="0"
            max="1.5"
            value="0.8"
            step="0.01"
            orient="vertical"
            aria-label="Aux A return volume"
          >
          <output id="auxReturnAValue">-1.9 dB</output>
        </label>
        <div class="daw-channel-number">A</div>
      </article>

      <article class="daw-channel daw-return-channel" data-return-channel="b" data-plugin-target="aux-b">
        <div class="daw-channel-head">
          <strong>AUX B</strong>
          <span>DELAY RETURN</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="aux-b">
          <div class="daw-track-plugin-list" data-universal-plugin-list="aux-b"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="aux-b">+ Plugin</button>
        </div>

        <div class="daw-return-badge">DELAY</div>
        <div class="daw-return-description">Shared echo send</div>
        <label class="daw-fader-wrap daw-return-fader">
          <input
            id="auxReturnB"
            class="daw-fader daw-return-volume-control"
            type="range"
            min="0"
            max="1.5"
            value="0.7"
            step="0.01"
            orient="vertical"
            aria-label="Aux B return volume"
          >
          <output id="auxReturnBValue">-3.1 dB</output>
        </label>
        <div class="daw-channel-number">B</div>
      </article>

      <article class="daw-channel daw-group-channel" data-group-channel="vocals" data-plugin-target="group-vocals">
        <div class="daw-channel-head">
          <strong>VOCALS</strong>
          <span>GROUP</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="group-vocals">
          <div class="daw-track-plugin-list" data-universal-plugin-list="group-vocals"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="group-vocals">+ Plugin</button>
        </div>

        <button class="daw-group-mute" type="button" data-group-mute="vocals">MUTE</button>
        <div class="daw-group-meter"><span></span><span></span></div>
        <label class="daw-fader-wrap daw-group-fader">
          <input class="daw-fader daw-group-volume-control" type="range" min="0" max="1.5" value="1" step="0.01" orient="vertical" data-group-volume="vocals">
          <output data-group-volume-value="vocals">0.0 dB</output>
        </label>
        <div class="daw-channel-number">V</div>
      </article>

      <article class="daw-channel daw-group-channel" data-group-channel="rhythm" data-plugin-target="group-rhythm">
        <div class="daw-channel-head">
          <strong>RHYTHM</strong>
          <span>GROUP</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="group-rhythm">
          <div class="daw-track-plugin-list" data-universal-plugin-list="group-rhythm"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="group-rhythm">+ Plugin</button>
        </div>

        <button class="daw-group-mute" type="button" data-group-mute="rhythm">MUTE</button>
        <div class="daw-group-meter"><span></span><span></span></div>
        <label class="daw-fader-wrap daw-group-fader">
          <input class="daw-fader daw-group-volume-control" type="range" min="0" max="1.5" value="1" step="0.01" orient="vertical" data-group-volume="rhythm">
          <output data-group-volume-value="rhythm">0.0 dB</output>
        </label>
        <div class="daw-channel-number">R</div>
      </article>

      <article class="daw-channel daw-group-channel" data-group-channel="music" data-plugin-target="group-music">
        <div class="daw-channel-head">
          <strong>MUSIC</strong>
          <span>GROUP</span>
        </div>

        <div class="daw-universal-plugin-slot" data-universal-plugin-slot="group-music">
          <div class="daw-track-plugin-list" data-universal-plugin-list="group-music"></div>
          <button type="button" class="daw-add-track-plugin" data-add-universal-plugin="group-music">+ Plugin</button>
        </div>

        <button class="daw-group-mute" type="button" data-group-mute="music">MUTE</button>
        <div class="daw-group-meter"><span></span><span></span></div>
        <label class="daw-fader-wrap daw-group-fader">
          <input class="daw-fader daw-group-volume-control" type="range" min="0" max="1.5" value="1" step="0.01" orient="vertical" data-group-volume="music">
          <output data-group-volume-value="music">0.0 dB</output>
        </label>
        <div class="daw-channel-number">M</div>
      </article>

      <span id="customBusAnchor" hidden></span>

      <?php foreach ($stems as $index=>$stem): ?>
        <?php
          $initialGain = max(0,min(1.5,(float)$stem['rpp_volume']));
          $initialPan = max(-1,min(1,(float)$stem['rpp_pan']));
        ?>
        <article
          class="daw-channel daw-stem-channel"
          data-drag-stem="<?= (int)$stem['id'] ?>"
          data-mixer-stem="<?= (int)$stem['id'] ?>"
        >
          <div class="daw-channel-head">
            <span class="daw-channel-drag" data-drag-handle draggable="true" title="Drag to reorder">⋮⋮</span>
            <strong title="<?= e($stem['stem_name']) ?>"><?= e($stem['stem_name']) ?></strong>
            <span><?= e($stem['stem_role']) ?></span>
          </div>

          <div class="daw-track-plugin-slot" data-track-plugin-slot="<?= (int)$stem['id'] ?>">
            <div class="daw-channel-strip-tools daw-channel-bus-row">
              <span class="daw-bus-label">BUS</span>

              <div class="daw-group-select-wrap" title="Route track">
                <select
                  data-track-group
                  class="daw-route-native-select"
                  aria-label="<?= e($stem['stem_name']) ?> bus routing"
                  tabindex="-1"
                >
                  <option value="direct">DIRECT</option>
                  <option value="vocals">VOCALS</option>
                  <option value="rhythm">RHYTHM</option>
                  <option value="music">MUSIC</option>
                </select>

                <button
                  type="button"
                  class="daw-route-menu-button daw-route-menu-button-labeled"
                  data-track-group-menu
                  aria-label="<?= e($stem['stem_name']) ?> bus routing"
                  aria-haspopup="menu"
                  aria-expanded="false"
                  title="Route track"
                >DIRECT <i>⌄</i></button>
              </div>
            </div>

            <div class="daw-track-sends">
              <label>
                <span>SEND A</span>
                <input type="range" min="0" max="1" step="0.01" value="0" data-aux-send="a" aria-label="<?= e($stem['stem_name']) ?> send A">
                <output data-aux-send-value="a">0%</output>
              </label>
              <label>
                <span>SEND B</span>
                <input type="range" min="0" max="1" step="0.01" value="0" data-aux-send="b" aria-label="<?= e($stem['stem_name']) ?> send B">
                <output data-aux-send-value="b">0%</output>
              </label>
            </div>

            <div class="daw-track-plugin-list" data-track-plugin-list></div>
            <button
              type="button"
              class="daw-add-track-plugin"
              data-add-track-plugin="<?= (int)$stem['id'] ?>"
            >+ Plugin</button>
          </div>

          <div class="daw-channel-input-panel">
            <label class="daw-track-input-control">
              <span>INPUT</span>
              <select
                data-track-input
                aria-label="<?= e($stem['stem_name']) ?> recording input"
              >
                <option value="">CONNECT INPUT…</option>
              </select>
            </label>

            <div class="daw-knob-group daw-dual-knob-group">
              <label class="daw-knob-control">
                <span>PAN</span>
                <span
                  class="daw-knob daw-pan-knob-small"
                  data-pan-knob
                  tabindex="0"
                  role="slider"
                  aria-valuemin="-1"
                  aria-valuemax="1"
                  aria-valuenow="<?= e((string)$initialPan) ?>"
                  aria-label="<?= e($stem['stem_name']) ?> pan"
                >
                  <i></i>
                  <input
                    type="range"
                    data-stem-pan
                    min="-1"
                    max="1"
                    step="0.01"
                    value="<?= e((string)$initialPan) ?>"
                    tabindex="-1"
                    aria-hidden="true"
                  >
                </span>
                <output data-pan-value>C</output>
              </label>

              <label class="daw-knob-control">
                <span>TRIM</span>
                <span
                  class="daw-knob daw-trim-knob"
                  data-trim-knob
                  tabindex="0"
                  role="slider"
                  aria-valuemin="-12"
                  aria-valuemax="12"
                  aria-valuenow="0"
                  aria-label="<?= e($stem['stem_name']) ?> trim"
                >
                  <i></i>
                  <input
                    type="range"
                    data-track-trim
                    min="-12"
                    max="12"
                    step="0.5"
                    value="0"
                    tabindex="-1"
                    aria-hidden="true"
                  >
                </span>
                <output data-track-trim-value>0.0 dB</output>
              </label>
            </div>
          </div>

          <div class="daw-channel-ms daw-channel-record-controls">
            <button type="button" class="daw-ms daw-mute" data-stem-mute title="Mute">M</button>
            <button type="button" class="daw-ms daw-solo" data-stem-solo title="Solo">S</button>
            <button
              type="button"
              class="daw-ms daw-arm"
              data-track-arm
              aria-pressed="false"
              title="Arm this track as the recording target"
            >R</button>
          </div>

          <div class="daw-channel-control-body">
            <div
              class="daw-track-eq-output daw-track-eq-vertical daw-live-spectrum"
              data-track-eq
              aria-label="<?= e($stem['stem_name']) ?> live frequency spectrum"
              title="Live track spectrum"
            >
              <canvas
                data-track-spectrum
                width="56"
                height="224"
                aria-hidden="true"
              ></canvas>
            </div>

            <label class="daw-fader-wrap daw-stem-fader-wrap">
              <input
                class="daw-fader daw-stem-volume-control"
                type="range"
                data-stem-volume
                draggable="false"
                min="0"
                max="1.5"
                step="0.01"
                value="<?= e((string)$initialGain) ?>"
                orient="vertical"
                aria-label="<?= e($stem['stem_name']) ?> volume"
              >
              <output data-volume-value>0.0 dB</output>
            </label>
          </div>

          <div class="daw-channel-number"><?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="daw-modal" id="recordingSaveDialog" hidden>
    <div class="daw-modal-backdrop"></div>

    <section
      class="daw-modal-card daw-recording-save-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="recordingSaveTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span>RECORDING COMPLETE</span>
          <h3 id="recordingSaveTitle">Save Recording?</h3>
        </div>
      </header>

      <div class="daw-recording-save-body">
        <div class="daw-recording-save-summary">
          <strong id="recordingSaveTrack">Armed track</strong>
          <span id="recordingSaveStats">0:00 · 0 KB</span>
          <span id="recordingSaveSignal">Input captured</span>
        </div>

        <label class="daw-recording-save-name">
          <span>RECORDING NAME</span>
          <input
            id="recordingSaveName"
            type="text"
            maxlength="190"
            autocomplete="off"
            value=""
          >
        </label>

        <p>
          Save adds the captured WAV to the armed track for normal playback,
          waveform editing, fades, automation and mixing.
        </p>

        <div class="daw-recording-save-actions">
          <button
            class="daw-small-button daw-recording-discard"
            id="discardRecordingButton"
            type="button"
          >Discard</button>

          <button
            class="daw-small-button active"
            id="saveRecordingButton"
            type="button"
          >Save Recording</button>
        </div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="regionNoteDialog" hidden>
    <div class="daw-modal-backdrop" data-close-region-note></div>

    <section
      class="daw-modal-card daw-region-note-dialog-v101"
      role="dialog"
      aria-modal="true"
      aria-labelledby="regionNoteTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span>SHARED THROUGH AGENT CHAT</span>
          <h3 id="regionNoteTitle">Add REGION Note</h3>
        </div>
        <button class="daw-modal-close" id="cancelRegionNoteTop" type="button" aria-label="Cancel note">×</button>
      </header>

      <div class="daw-region-note-body-v101">
        <div class="daw-region-note-range-v101">
          <span>SELECTED RANGE</span>
          <strong id="regionNoteRange">0:00–0:00</strong>
        </div>
        <label>
          <span>PRODUCTION NOTE</span>
          <textarea id="regionNoteText" maxlength="5000" placeholder="Describe the change, issue, or next action for the production team."></textarea>
        </label>
        <p>This note is added to the timeline and delivered to authorized producers and supervisors in Agent Chat.</p>
        <div class="daw-recording-save-actions">
          <button class="daw-small-button" id="cancelRegionNote" type="button">Cancel</button>
          <button class="daw-small-button active" id="shareRegionNote" type="button">Share Note</button>
        </div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="audioPermissionDialog" hidden>
    <div class="daw-modal-backdrop" data-close-audio-permission></div>

    <section
      class="daw-modal-card daw-audio-permission-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="audioPermissionTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span>AUDIO INPUT</span>
          <h3 id="audioPermissionTitle">Allow Studio Audio Access</h3>
        </div>

        <button
          type="button"
          class="daw-modal-close"
          data-close-audio-permission
          aria-label="Close Audio Permission"
        >×</button>
      </header>

      <div class="daw-audio-permission-body">
        <strong id="audioPermissionMessage">
          Stonefellow needs microphone/audio-input permission to access USB interfaces.
        </strong>

        <p id="audioPermissionDetail">
          Your browser treats a Focusrite or other USB interface as a microphone/audio input.
        </p>

        <ol>
          <li>Use the site controls beside the browser address bar.</li>
          <li>Set <b>Microphone</b> to <b>Allow</b> for this site.</li>
          <li>Make sure the Focusrite is connected and enabled in Windows sound input settings.</li>
          <li>Return here and press <b>Retry Audio</b>.</li>
        </ol>

        <div class="daw-audio-permission-actions">
          <button
            class="daw-small-button"
            type="button"
            data-close-audio-permission
          >Close</button>

          <button
            class="daw-small-button active"
            id="retryAudioPermission"
            type="button"
          >Retry Audio</button>
        </div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="exportAudioDialog" hidden>
    <div class="daw-modal-backdrop" data-close-export-audio></div>

    <section
      class="daw-modal-card daw-export-audio-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="exportAudioTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span>PRODUCTION FILES</span>
          <h3 id="exportAudioTitle">Export MP3 / WAV Files</h3>
        </div>

        <button
          type="button"
          class="daw-modal-close"
          data-close-export-audio
          aria-label="Close Export Audio"
        >×</button>
      </header>

      <div class="daw-export-audio-tools">
        <div>
          <strong><?= e((string)$track['title']) ?></strong>
          <span>
            Source-file export for the shared project. These downloads are the
            actual MP3/WAV files, not a newly rendered mixdown.
          </span>
        </div>

        <?php if (class_exists('ZipArchive')): ?>
          <a
            class="daw-export-all"
            href="<?= e(url('/admin-audio-export-v65.php?track=' . $trackId . '&bundle=1')) ?>"
          >Download All Files</a>
        <?php endif; ?>
      </div>

      <div class="daw-export-audio-list">
        <?php
          $exportCount = 0;
          $masterExtension = strtolower(
              pathinfo(
                  (string)($track['audio_path'] ?? ''),
                  PATHINFO_EXTENSION
              )
          );
        ?>

        <?php if (
          trim((string)($track['audio_path'] ?? '')) !== '' &&
          in_array($masterExtension, ['mp3','wav'], true)
        ): ?>
          <?php $exportCount++; ?>
          <article class="daw-export-audio-row">
            <div>
              <span>MASTER SOURCE</span>
              <strong><?= e((string)$track['title']) ?></strong>
              <small><?= e(strtoupper($masterExtension)) ?> · Catalog master/source file</small>
            </div>

            <a
              href="<?= e(url('/admin-audio-export-v65.php?track=' . $trackId)) ?>"
            >Download <?= e(strtoupper($masterExtension)) ?></a>
          </article>
        <?php endif; ?>

        <?php foreach ($stems as $exportStem): ?>
          <?php
            $exportExtension = strtolower(
                pathinfo(
                    (string)($exportStem['file_name'] ?? $exportStem['file_path'] ?? ''),
                    PATHINFO_EXTENSION
                )
            );

            if (!in_array($exportExtension, ['mp3','wav'], true)) {
                continue;
            }

            $exportCount++;
          ?>
          <article class="daw-export-audio-row">
            <div>
              <span><?= e(strtoupper((string)($exportStem['stem_role'] ?: 'TRACK'))) ?></span>
              <strong><?= e((string)$exportStem['stem_name']) ?></strong>
              <small>
                <?= e(strtoupper($exportExtension)) ?>
                <?php if ((float)($exportStem['duration_seconds'] ?? 0) > 0): ?>
                  · <?= e(stem_format_duration((float)$exportStem['duration_seconds'])) ?>
                <?php endif; ?>
              </small>
            </div>

            <a
              href="<?= e(url('/admin-audio-export-v65.php?stem=' . (int)$exportStem['id'])) ?>"
            >Download <?= e(strtoupper($exportExtension)) ?></a>
          </article>
        <?php endforeach; ?>

        <?php if ($exportCount === 0): ?>
          <div class="daw-export-audio-empty">
            <strong>No MP3/WAV source files are available yet.</strong>
            <span>Import or record audio in Stem Studio, then the files will appear here.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="openProjectDialog" hidden>
    <div class="daw-modal-backdrop" data-close-open-project></div>

    <section
      class="daw-modal-card daw-open-project-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="openProjectTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span><?= $canGlobalTrackManage ? 'MY PROJECTS' : 'PRODUCER WORKSPACE' ?></span>
          <h3 id="openProjectTitle">Open Project</h3>
        </div>

        <button
          type="button"
          class="daw-modal-close"
          data-close-open-project
          aria-label="Close Open Project"
        >×</button>
      </header>

      <div class="daw-open-project-tools">
        <label>
          <span>SEARCH PROJECTS</span>
          <input
            type="search"
            id="openProjectSearch"
            placeholder="Project, song, album…"
            autocomplete="off"
          >
        </label>

        <small>
          <?= count($ownedProjects) ?>
          <?= $canGlobalTrackManage ? 'saved project' : 'shared project' ?><?= count($ownedProjects) === 1 ? '' : 's' ?>
        </small>
      </div>

      <div class="daw-open-project-grid" id="openProjectGrid">
        <?php foreach ($ownedProjects as $ownedProject): ?>
          <?php
            $ownedProjectId = (int)$ownedProject['id'];
            $ownedProjectName = trim(
                (string)(
                    $ownedProject['project_name'] ?:
                    $ownedProject['title']
                )
            );
            $ownedProjectAlbum = trim(
                (string)($ownedProject['album'] ?? '')
            );
            $ownedProjectTempo = max(
                40.0,
                min(
                    300.0,
                    (float)($ownedProject['project_tempo'] ?? 120)
                )
            );
            $ownedProjectSignature = trim(
                (string)($ownedProject['time_signature'] ?? '4/4')
            ) ?: '4/4';
            $ownedProjectCover = trim(
                (string)($ownedProject['cover_path'] ?? '')
            );
            $ownedProjectCoverUrl = $ownedProjectCover !== ''
                ? url('/admin-track-media-v49.php?track=' . $ownedProjectId . '&type=cover')
                : url('/images/stonefellow-studio.png');
            $ownedProjectSearch = stem_lower(
                $ownedProjectName . ' ' .
                (string)$ownedProject['title'] . ' ' .
                $ownedProjectAlbum
            );
          ?>
          <article
            class="daw-open-project-card <?= $ownedProjectId === $trackId ? 'current' : '' ?>"
            data-open-project-card
            data-project-id="<?= $ownedProjectId ?>"
            data-project-name="<?= e($ownedProjectName) ?>"
            data-project-updated="<?= e((string)$ownedProject['updated_at']) ?>"
            data-project-url="<?= e(url('/admin/stems.php?track=' . $ownedProjectId)) ?>"
            data-open-project-search="<?= e($ownedProjectSearch) ?>"
          >
            <div class="daw-open-project-cover-wrap">
              <img
                class="daw-open-project-cover"
                src="<?= e($ownedProjectCoverUrl) ?>"
                alt=""
                loading="lazy"
              >

              <?php if ($ownedProjectId === $trackId): ?>
                <span class="daw-open-project-current">CURRENT</span>
              <?php endif; ?>
            </div>

            <div class="daw-open-project-copy">
              <div class="daw-open-project-title">
                <strong><?= e($ownedProjectName) ?></strong>
                <span><?= e($ownedProjectAlbum !== '' ? $ownedProjectAlbum : 'Stonefellow Studio Project') ?></span>
              </div>

              <div class="daw-open-project-meta">
                <span><?= (int)$ownedProject['stem_count'] ?> TRACKS</span>
                <span><?= e(rtrim(rtrim(number_format($ownedProjectTempo,2),'0'),'.')) ?> BPM</span>
                <span><?= e($ownedProjectSignature) ?></span>
              </div>

              <small class="daw-open-project-updated">
                Updated <?= e(date('M j, Y', strtotime((string)$ownedProject['updated_at']))) ?>
              </small>
            </div>

            <a
              class="daw-open-project-action"
              href="<?= e(url('/admin/stems.php?track=' . $ownedProjectId)) ?>"
            ><?= $ownedProjectId === $trackId ? 'Reload Project' : 'Open Project' ?></a>
          </article>
        <?php endforeach; ?>

        <?php if (!$ownedProjects): ?>
          <div class="daw-open-project-empty">
            <strong><?= $canGlobalTrackManage ? 'No saved projects yet.' : 'No shared projects yet.' ?></strong>
            <span>
              <?= $canGlobalTrackManage
                ? 'Projects owned by or assigned to your account will appear here.'
                : 'A track manager can share a track with your Producer account from Edit Track.' ?>
            </span>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="loadSongDialog" hidden>
    <div class="daw-modal-backdrop" data-close-load-song></div>

    <section
      class="daw-modal-card daw-load-song-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="loadSongTitle"
    >
      <header class="daw-modal-head">
        <div>
          <span>STEM STUDIO</span>
          <h3 id="loadSongTitle">Load Song</h3>
        </div>

        <button
          type="button"
          class="daw-modal-close"
          data-close-load-song
          aria-label="Close Load Song"
        >×</button>
      </header>

      <div class="daw-load-song-tools">
        <label>
          <span>SEARCH UPLOADED SONGS</span>
          <input
            id="loadSongSearch"
            type="search"
            placeholder="Song, album, genre…"
            autocomplete="off"
          >
        </label>

        <small><?= count($loadSongs) ?> available</small>
      </div>

      <div class="daw-load-song-list" id="loadSongList">
        <?php foreach ($loadSongs as $loadSong): ?>
          <?php
            $loadSongId = (int)$loadSong['id'];
            $firstStemId = (int)($loadSong['first_stem_id'] ?? 0);
            $hasMasterAudio = trim((string)($loadSong['audio_path'] ?? '')) !== '';

            $sampleUrl = $hasMasterAudio
                ? url('/admin-track-media-v49.php?track=' . $loadSongId . '&type=audio')
                : (
                    $firstStemId > 0
                        ? url('/stem-media-v34.php?id=' . $firstStemId)
                        : ''
                );

            $coverUrl = trim((string)($loadSong['cover_path'] ?? '')) !== ''
                ? url('/admin-track-media-v49.php?track=' . $loadSongId . '&type=cover')
                : url('/images/stonefellow-studio.png');

            $searchText = stem_lower(
                (string)$loadSong['title'] . ' ' .
                (string)$loadSong['album'] . ' ' .
                (string)$loadSong['genre'] . ' ' .
                (string)$loadSong['mood'] . ' ' .
                (string)($loadSong['project_name'] ?? '')
            );

            $songTempo = (float)(
                $loadSong['project_tempo']
                ?: $loadSong['tempo_bpm']
                ?: 120
            );
          ?>

          <article
            class="daw-load-song-card<?= $loadSongId === $trackId ? ' current' : '' ?>"
            data-load-song-card
            data-load-song-search="<?= e($searchText) ?>"
          >
            <img
              class="daw-load-song-cover"
              src="<?= e($coverUrl) ?>"
              alt=""
              loading="lazy"
              onerror="this.src='<?= e(url('/images/stonefellow-studio.png')) ?>'"
            >

            <div class="daw-load-song-copy">
              <div class="daw-load-song-title">
                <div>
                  <strong><?= e((string)$loadSong['title']) ?></strong>
                  <span><?= e((string)($loadSong['album'] ?: 'Stonefellow')) ?></span>
                </div>

                <?php if ($loadSongId === $trackId): ?>
                  <em>CURRENT</em>
                <?php endif; ?>
              </div>

              <div class="daw-load-song-meta">
                <span><?= e(rtrim(rtrim(number_format($songTempo,1),'0'),'.')) ?> BPM</span>
                <span><?= e((string)($loadSong['time_signature'] ?: '4/4')) ?></span>
                <span><?= (int)$loadSong['stem_count'] ?> tracks</span>
                <?php if (!empty($loadSong['genre'])): ?>
                  <span><?= e((string)$loadSong['genre']) ?></span>
                <?php endif; ?>
              </div>

              <?php if ($sampleUrl !== ''): ?>
                <div class="daw-load-song-player" data-load-song-player>
                  <audio
                    preload="metadata"
                    data-load-song-sample
                    data-sample-seconds="30"
                    src="<?= e($sampleUrl) ?>"
                  ></audio>

                  <button
                    type="button"
                    class="daw-load-song-play"
                    data-load-song-play
                    aria-label="Play 30 second sample"
                    title="Play sample"
                  >▶</button>

                  <div class="daw-load-song-player-main">
                    <div class="daw-load-song-player-label">
                      <span>30 SEC SAMPLE</span>
                      <strong data-load-song-player-state>READY</strong>
                    </div>

                    <input
                      class="daw-load-song-progress"
                      type="range"
                      min="0"
                      max="30"
                      step="0.05"
                      value="0"
                      data-load-song-progress
                      aria-label="Sample position"
                    >

                    <div class="daw-load-song-player-time">
                      <span data-load-song-current>0:00</span>
                      <span data-load-song-total>0:30</span>
                    </div>
                  </div>
                </div>
              <?php else: ?>
                <div class="daw-load-song-no-sample">No preview audio available.</div>
              <?php endif; ?>
            </div>

            <a
              class="daw-load-song-action"
              href="<?= e(url('/admin/stems.php?track=' . $loadSongId)) ?>"
            ><?= $loadSongId === $trackId ? 'Reload' : 'Load Song' ?></a>
          </article>
        <?php endforeach; ?>

        <?php if (!$loadSongs): ?>
          <p class="daw-load-song-empty">No uploaded songs are available yet.</p>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="newStudioProjectDialog" hidden>
    <div class="daw-modal-backdrop" data-close-new-project></div>
    <section class="daw-modal-card daw-new-project-dialog" role="dialog" aria-modal="true" aria-labelledby="newStudioProjectTitle">
      <header class="daw-modal-head">
        <div>
          <span>STEM STUDIO</span>
          <h3 id="newStudioProjectTitle">New Project</h3>
        </div>
        <button type="button" class="daw-modal-close" data-close-new-project>×</button>
      </header>

      <div class="daw-new-project-body">
        <label>
          <span>PROJECT NAME</span>
          <input id="newStudioProjectName" type="text" maxlength="190" value="Untitled Project" autocomplete="off">
        </label>

        <div class="daw-new-project-grid">
          <label>
            <span>TEMPO</span>
            <input id="newStudioProjectTempo" type="number" min="40" max="300" step="0.1" value="120">
          </label>

          <label>
            <span>TIME SIGNATURE</span>
            <select id="newStudioProjectSignature">
              <option value="4/4">4/4</option>
              <option value="3/4">3/4</option>
              <option value="6/8">6/8</option>
              <option value="12/8">12/8</option>
              <option value="5/4">5/4</option>
              <option value="7/8">7/8</option>
            </select>
          </label>
        </div>

        <p>The new project is saved to your account as a private draft. Import WAV/MP3 media from the Studio menu.</p>

        <div class="daw-new-project-actions">
          <button type="button" class="daw-small-button" data-close-new-project>Cancel</button>
          <button type="button" class="daw-small-button active" id="createStudioProjectButton">Create Project</button>
        </div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="studioImportDialog" hidden>
    <div class="daw-modal-backdrop"></div>
    <section class="daw-modal-card daw-studio-import-dialog" role="dialog" aria-modal="true" aria-labelledby="studioImportTitle">
      <header class="daw-modal-head">
        <div>
          <span>MEDIA IMPORT</span>
          <h3 id="studioImportTitle">Importing Tracks</h3>
        </div>
      </header>

      <div class="daw-studio-import-body">
        <strong id="studioImportStatus">Preparing media…</strong>
        <span id="studioImportFileStatus"></span>

        <div class="daw-studio-import-progress">
          <i id="studioImportProgress"></i>
        </div>

        <small>WAV files retain channel, sample-rate, bit-depth and duration metadata. Each selected file becomes its own mixer track.</small>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="addBusDialog" hidden>
    <div class="daw-modal-backdrop" data-close-add-bus></div>
    <section class="daw-modal-card daw-add-bus-dialog" role="dialog" aria-modal="true" aria-labelledby="addBusTitle">
      <header class="daw-modal-head">
        <div>
          <span>ROUTING</span>
          <h3 id="addBusTitle">Add Mixer Bus</h3>
        </div>
        <button type="button" class="daw-modal-close" data-close-add-bus>×</button>
      </header>

      <div class="daw-add-bus-body">
        <label>
          <span>BUS NAME</span>
          <input
            id="newBusName"
            type="text"
            maxlength="32"
            placeholder="Example: Guitars"
            autocomplete="off"
          >
        </label>

        <p>The new bus gets its own fader, meter, mute control and plugin chain. It will also appear in every track routing dropdown.</p>

        <div class="daw-add-bus-actions">
          <button type="button" class="daw-small-button" data-close-add-bus>Cancel</button>
          <button type="button" class="daw-small-button active" id="createMixerBusButton">Create Bus</button>
        </div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="pluginDirectoryDialog" hidden>
    <div class="daw-modal-backdrop" data-close-plugin-directory></div>
    <section class="daw-modal-card daw-plugin-directory-dialog" role="dialog" aria-modal="true" aria-labelledby="pluginDirectoryTitle">
      <header class="daw-modal-head">
        <div>
          <span>TRACK INSERT</span>
          <h3 id="pluginDirectoryTitle">Plugin Directory</h3>
        </div>
        <button type="button" class="daw-modal-close" data-close-plugin-directory>×</button>
      </header>

      <div class="daw-plugin-directory-track" id="pluginDirectoryTrack"></div>

      <div class="daw-plugin-directory-grid" id="pluginDirectoryGrid">
        <button type="button" class="daw-directory-plugin" data-plugin-type="eq5">
          <span class="daw-directory-plugin-icon">EQ</span>
          <strong>5-Band EQ</strong>
          <small>80 Hz · 250 Hz · 1 kHz · 4 kHz · 12 kHz</small>
          <em>Insert on selected track</em>
        </button>

        <button type="button" class="daw-directory-plugin" data-plugin-type="delay">
          <span class="daw-directory-plugin-icon">DLY</span>
          <strong>Delay</strong>
          <small>Time · Feedback · Wet/Dry mix</small>
          <em>Insert on selected track</em>
        </button>

        <button type="button" class="daw-directory-plugin" data-plugin-type="compressor">
          <span class="daw-directory-plugin-icon">CMP</span>
          <strong>Compressor</strong>
          <small>Threshold · Ratio · Knee · Attack · Release · Makeup</small>
          <em>Insert on selected track</em>
        </button>


        <button type="button" class="daw-directory-plugin" data-plugin-type="limiter">
          <span class="daw-directory-plugin-icon">LIM</span>
          <strong>Master Limiter</strong>
          <small>Threshold · Ceiling · Release · Lookahead</small>
          <em>Insert on selected bus or track</em>
        </button>

        <button type="button" class="daw-directory-plugin" data-plugin-type="reverb">
          <span class="daw-directory-plugin-icon">RVB</span>
          <strong>Reverb</strong>
          <small>Decay · Room size · Damping · Wet/Dry mix</small>
          <em>Insert on selected track</em>
        </button>
      </div>

      <div class="daw-plugin-editor" id="pluginEditor" hidden>
        <div class="daw-plugin-editor-head">
          <div>
            <span>PLUGIN</span>
            <strong id="pluginEditorTitle"></strong>
          </div>
          <div>
            <button type="button" class="daw-small-button" id="pluginBypassButton">Bypass</button>
            <button type="button" class="daw-small-button daw-danger-button" id="pluginRemoveButton">Remove</button>
          </div>
        </div>
        <div id="pluginEditorControls"></div>
      </div>
    </section>
  </div>

  <div class="daw-modal" id="masterBusDialog" hidden>
    <div class="daw-modal-backdrop" data-close-master-bus></div>
    <section class="daw-modal-card daw-master-bus-dialog" role="dialog" aria-modal="true" aria-labelledby="masterBusTitle">
      <header class="daw-modal-head">
        <div>
          <span>MASTER</span>
          <h3 id="masterBusTitle">Master Bus / Plugins</h3>
        </div>
        <button type="button" class="daw-modal-close" data-close-master-bus>×</button>
      </header>

      <div class="daw-plugin-popup-grid">
        <button type="button" class="daw-plugin active" data-master-plugin="eq">
          <strong>EQ</strong>
          <span>3-Band</span>
          <small>Low 140 Hz · Mid 1.2 kHz · High 5.2 kHz</small>
        </button>

        <button type="button" class="daw-plugin active" data-master-plugin="compressor">
          <strong>COMP</strong>
          <span>Glue Compressor</span>
          <small>-18 dB · 2:1 · 12 ms attack</small>
        </button>

        <button type="button" class="daw-plugin" data-master-plugin="reverb">
          <strong>ROOM</strong>
          <span>Review Reverb</span>
          <small>1.1 sec room · 18% wet</small>
        </button>
      </div>

      <div class="daw-bus-modules">
        <div class="daw-bus-module">
          <strong>EQ</strong>
          <span>LOW</span><b>0.0 dB</b>
          <span>MID</span><b>0.0 dB</b>
          <span>HIGH</span><b>0.0 dB</b>
        </div>
        <div class="daw-bus-module">
          <strong>COMP</strong>
          <span>THRESH</span><b>-18 dB</b>
          <span>RATIO</span><b>2:1</b>
          <span>MAKEUP</span><b>0.0 dB</b>
        </div>
        <div class="daw-bus-module">
          <strong>ROOM</strong>
          <span>SEND</span><b>18%</b>
          <span>SIZE</span><b>1.1 sec</b>
          <span>MIX</span><b>Parallel</b>
        </div>
      </div>
    </section>
  </div>

  <?php if ($canSaveMix): ?>
    <div class="daw-modal" id="mixSaveDialog" hidden>
      <div class="daw-modal-backdrop" data-close-mix-dialog></div>
      <section class="daw-modal-card daw-mix-save-dialog" role="dialog" aria-modal="true" aria-labelledby="mixSaveTitle">
        <header class="daw-modal-head">
          <div>
            <span>STUDIO SAVE</span>
            <h3 id="mixSaveTitle">Save As / Saved Versions</h3>
          </div>
          <button type="button" class="daw-modal-close" data-close-mix-dialog>×</button>
        </header>

        <div class="daw-mix-save-form">
          <input id="stemMixName" maxlength="120" value="<?= e($track['title']) ?>" aria-label="Saved version name">
          <button class="daw-small-button active" id="saveStemMix" type="button">Save As</button>
        </div>

        <div class="daw-saved-mix-list" id="savedMixList">
          <p class="daw-modal-empty">Loading saved mixes…</p>
        </div>
      </section>
    </div>
  <?php endif; ?>
</div>

<div class="panel daw-package-panel">
  <div class="content-form-heading">
    <div>
      <span class="status">Imported Project</span>
      <h2>Package Details</h2>
    </div>
  </div>

  <div class="stem-project-details">
    <div><span>ZIP</span><strong><?= e($project['source_zip_name']) ?></strong></div>
    <div><span>RPP</span><strong><?= e($project['rpp_file_name'] ?: 'Not included') ?></strong></div>
    <div><span>Imported</span><strong><?= e(date('M j, Y g:i A',strtotime((string)$project['imported_at']))) ?></strong></div>
  </div>

  <form method="post" onsubmit="return confirm('Remove the imported REAPER project and all stem files from this track?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete_package">
    <input type="hidden" name="track_id" value="<?= $trackId ?>">
    <button class="btn danger" type="submit">Remove Stem Package</button>
  </form>
</div>

<script>
window.STONEFELLOW_STEM_STUDIO = <?= json_encode([
  'trackId'=>$trackId,
  'projectTitle'=>(string)$track['title'],
  'userId'=>(int)(current_user()['id'] ?? 0),
  'duration'=>$maxDuration,
  'durationMeasures'=>$durationMeasures,
  'sourceTempo'=>$sourceTempo,
  'timeSignature'=>(string)($project['time_signature'] ?: '4/4'),
  'projects'=>array_map(static function(array $row): array {
      $id=(int)($row['id'] ?? 0);
      return [
          'id'=>$id,
          'name'=>trim((string)(($row['project_name'] ?? '') ?: ($row['title'] ?? ''))),
          'updated_at'=>(string)($row['updated_at'] ?? ''),
          'url'=>url('/admin/stems.php?track=' . $id),
      ];
  },$ownedProjects),
  'stemMediaBase'=>url('/stem-media-v34.php?id='),
  'waveformEndpoint'=>url('/api/stem-waveform-v49.php'),
  'timeStretchWorkletUrl'=>url('/admin/stem-time-stretch-worklet-v203.js?v=203'),
  'stems'=>$payload,
  'canSaveMix'=>$canSaveMix,
  'fanPrivateMix'=>$fanPrivateMix,
  'mixEndpoint'=>$canSaveMix ? url('/api/stem-mix.php') : '',
  'projectEndpoint'=>url('/api/studio-project-v77.php'),
  'editLedgerEndpoint'=>url('/api/agent-edit-ledger-v90.php'),
  'regionNoteEndpoint'=>url('/api/stem-region-note-v101.php'),
  'regionNotes'=>$regionNotes,
  'focusRegionNoteId'=>max(0,(int)($_GET['region_note'] ?? 0)),
  'currentUserName'=>(string)($currentStudioUser['display_name'] ?? ''),
  'masterPluginChain'=>(static function($json): array { $decoded=json_decode((string)$json,true); return is_array($decoded)?$decoded:[]; })($project['master_plugin_chain_json'] ?? ''),
  'pluginImportVersion'=>substr(hash('sha256',
      (string)($project['imported_at'] ?? '') . '|' .
      (string)($project['master_plugin_chain_json'] ?? '') . '|' .
      implode('|', array_map(static fn($row)=>(string)($row['plugin_chain_json'] ?? ''), $stems))
  ),0,16),
  'ownerUserId'=>(int)($track['owner_user_id'] ?? 0),
  'csrf'=>csrf_token(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/admin/stem-master-clock-v201.js?v=201')) ?>"></script>
<script src="<?= e(url('/admin/stem-buffer-scheduler-v202.js?v=202')) ?>"></script>
<script src="<?= e(url('/admin/stem-project-loader.js?v=loader')) ?>"></script>
<script src="<?= e(url('/admin/stem-time-stretch-v203.js?v=203')) ?>"></script>
<script src="<?= e(url('/admin/stem-loop-planner-v204.js?v=204')) ?>"></script>
<script src="<?= e(url('/admin/stem-transport-v200.js?v=200')) ?>"></script>
<script src="<?= e(url('/admin/stems-v79.js?v=101')) ?>"></script>
<link rel="stylesheet" href="<?= e(url('/editor-agent-v87.css?v=89')) ?>">
<link rel="stylesheet" href="<?= e(url('/editor-agent-v91.css?v=97')) ?>">
<link rel="stylesheet" href="<?= e(url('/admin/stems-v91.css?v=101')) ?>">
<link rel="stylesheet" href="<?= e(url('/admin/stems-v92.css?v=92')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v86.css?v=86')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v91.css?v=95')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-proactive-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/video-editor-v90.css?v=90')) ?>">
<link rel="stylesheet" href="<?= e(url('/admin/stem-live-recording-v87.css?v=88')) ?>">
<script>window.STONEFELLOW_STUDIO_AGENT=<?= json_encode([
    'endpoint'=>url('/api/stem-agent-v91.php'),
    'trackId'=>$trackId,
    'trackTitle'=>(string)$track['title'],
    'userId'=>$currentStudioUserId,
    'csrf'=>csrf_token(),
    'voiceMode'=>!empty($_GET['voice']),
    'conversationId'=>max(0,(int)($_GET['conversation_id'] ?? 0)),
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<script>window.STONEFELLOW_ACTIVITY={endpoint:<?= json_encode(url('/api/agent-activity-v94.php')) ?>,csrf:<?= json_encode(csrf_token()) ?>,surface:'stem',trackId:<?= (int)$trackId ?>,projectId:<?= (int)$trackId ?>,conversationId:<?= max(0,(int)($_GET['conversation_id'] ?? 0)) ?>,taskTitle:<?= json_encode('Stem Studio · '.(string)$track['title']) ?>,taskKey:<?= json_encode('stem:'.(int)$trackId) ?>};</script>
<script src="<?= e(url('/admin/stem-live-recording-v87.js?v=88')) ?>"></script>
<script src="<?= e(url('/editor-voice-barge-v89.js?v=89')) ?>"></script>
<script>window.STONEFELLOW_CHAT={
  mediaEndpoint:<?= json_encode(url('/api/media-library-v86.php')) ?>,
  videoEditorUrl:<?= json_encode(url('/video-editor.php')) ?>,
  csrf:<?= json_encode(csrf_token()) ?>
};</script>
<script src="<?= e(url('/admin/stem-metronome-v91.js?v=97')) ?>"></script>
<script src="<?= e(url('/chat-media-v86.js?v=95')) ?>"></script>
<script src="<?= e(url('/chat-media-v93.js?v=97')) ?>"></script>
<script src="<?= e(url('/agent-activity-v94.js?v=101')) ?>"></script>
<script src="<?= e(url('/editor-media-button-v91.js?v=91')) ?>"></script>
<script src="<?= e(url('/admin/stem-agent-v91.js?v=91')) ?>"></script>
<?php endif; ?>

<?php if ($teamChatEnabled): ?>
<?php
  $teamChatPageKey = 'stem_studio';
  $teamChatContextLabel = 'Stem Studio · ' . (string)$track['title'];
  require dirname(__DIR__) . '/includes/team-chat-widget-v81.php';
?>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
