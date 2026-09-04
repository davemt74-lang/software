<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_permission('chat.access');

$conversationBuild='conversation-integration-v131-20260826';
$voiceBuild='voice-three-of-three-v157-20260829';
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Stonefellow-Runtime: '.$conversationBuild);
    header('X-Stonefellow-Conversation: '.$conversationBuild);
    header('Permissions-Policy: microphone=(self), camera=(self)');
}

if (!media_studio_schema_ready()) {
    redirect(url('/upgrade.php'));
}

$user = current_user();
$pdo = db();
$assets = media_studio_assets($user,250);
$tracks = media_studio_visible_tracks($user);
$savedTrackIds = [];

if ($pdo && table_exists('track_favorites')) {
    try {
        $stmt = $pdo->prepare('SELECT track_id FROM track_favorites WHERE user_id=?');
        $stmt->execute([(int)$user['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $trackId) {
            $savedTrackIds[(int)$trackId] = true;
        }
    } catch (Throwable $e) {}
}
foreach ($tracks as &$track) {
    $track['saved'] = isset($savedTrackIds[(int)$track['id']]);
}
unset($track);
usort($tracks,static function(array $a,array $b): int {
    $saved = ((int)!empty($b['saved'])) <=> ((int)!empty($a['saved']));
    return $saved !== 0 ? $saved : strcasecmp((string)$a['title'],(string)$b['title']);
});

$projectId = max(0,(int)($_GET['project'] ?? 0));
$project = null;
if ($pdo && $projectId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM video_editor_projects WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$projectId,(int)$user['id']]);
    $project = $stmt->fetch() ?: null;
}
$projects = [];
if ($pdo) {
    $stmt = $pdo->prepare(
        'SELECT id,title,updated_at FROM video_editor_projects
         WHERE user_id=? ORDER BY updated_at DESC,id DESC LIMIT 30'
    );
    $stmt->execute([(int)$user['id']]);
    $projects = $stmt->fetchAll();
}

$timeline = $project ? json_decode((string)$project['timeline_json'],true) : [];
$settings = $project ? json_decode((string)$project['settings_json'],true) : [];
$timeline = is_array($timeline) ? $timeline : [];
$settings = is_array($settings) ? $settings : [];
$timelineAssetIds=[];foreach($timeline as $item){if(is_array($item)&&($item['source_kind']??'')==='asset'&&(int)($item['source_id']??0)>0)$timelineAssetIds[]=(int)$item['source_id'];}
if($timelineAssetIds){$byId=[];foreach($assets as $asset)$byId[(int)$asset['id']]=$asset;foreach(media_studio_assets_by_ids($timelineAssetIds,$user) as $asset)$byId[(int)$asset['id']]=$asset;$assets=array_values($byId);}
$availableAssetIds=array_fill_keys(array_map(static fn(array $a):int=>(int)$a['id'],$assets),true);$missingAssetIds=[];foreach(array_unique($timelineAssetIds) as $assetId)if(!isset($availableAssetIds[$assetId]))$missingAssetIds[]=$assetId;
$autoAssetId = max(0,(int)($_GET['asset'] ?? 0));

$videoReturnFallback = url('/chat.php');
$videoReturnSessionKey = 'stonefellow_video_editor_return';
$videoResolveReturn = static function (string $candidate): string {
    $candidate = trim($candidate);
    if ($candidate === '') return '';
    $parts = parse_url($candidate);
    if ($parts === false) return '';
    $candidateHost = strtolower((string)($parts['host'] ?? ''));
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $requestHost = preg_replace('/:\\d+$/', '', $requestHost) ?? $requestHost;
    if ($candidateHost !== '' && $candidateHost !== $requestHost) return '';
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== '' && !in_array($scheme,['http','https'],true)) return '';
    $path = (string)($parts['path'] ?? '');
    if ($path === '' || !str_starts_with($path,'/')) return '';
    if (str_ends_with($path,'/video-editor.php')) return '';
    $result = $path;
    if (!empty($parts['query'])) $result .= '?' . $parts['query'];
    if (!empty($parts['fragment'])) $result .= '#' . $parts['fragment'];
    return $result;
};
$explicitVideoReturn = $videoResolveReturn((string)($_GET['return'] ?? ''));
$referrerVideoReturn = $videoResolveReturn((string)($_SERVER['HTTP_REFERER'] ?? ''));
$videoReturnUrl = $explicitVideoReturn !== '' ? $explicitVideoReturn : ($referrerVideoReturn !== '' ? $referrerVideoReturn : '');
if ($videoReturnUrl !== '') $_SESSION[$videoReturnSessionKey] = $videoReturnUrl;
elseif (!empty($_SESSION[$videoReturnSessionKey])) $videoReturnUrl = $videoResolveReturn((string)$_SESSION[$videoReturnSessionKey]);
if ($videoReturnUrl === '') $videoReturnUrl = $videoReturnFallback;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0b0a09">
<title>Video Editor · Stonefellow</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=86')) ?>">
<link rel="stylesheet" href="<?= e(url('/video-editor-v86.css?v=89')) ?>">
<link rel="stylesheet" href="<?= e(url('/video-editor-v90.css?v=90')) ?>">
<link rel="stylesheet" href="<?= e(url('/editor-agent-v87.css?v=89')) ?>">
<link rel="stylesheet" href="<?= e(url('/editor-agent-v91.css?v=97')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v86.css?v=86')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v91.css?v=95')) ?>">
<link rel="stylesheet" href="<?= e(url('/chat-media-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-proactive-v93.css?v=93')) ?>">
<link rel="stylesheet" href="<?= e(url('/video-editor-v92.css?v=98')) ?>">
<link rel="stylesheet" href="<?= e(url('/video-editor-v100.css?v=100')) ?>">
</head>
<body class="video-editor-body">
<div class="video-editor-shell">
  <main class="video-editor-main">
    <header class="video-editor-topbar video-editor-topbar-v92 video-editor-topbar-v100">
      <div class="video-editor-header-left video-editor-header-left-v92">
        <button class="video-header-button video-menu-button" id="videoMainMenuToggle" type="button" aria-expanded="false">☰ <strong>MENU</strong></button>
        <div class="video-device-registry-v92 video-device-registry-v100" id="videoDeviceRegistry" aria-label="Recording device status">
          <button type="button" class="video-device-chip-v92 audio video-audio-status-v100" id="videoAudioDeviceStatus" title="Audio input" aria-label="Audio input status"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3Zm-6 9a6 6 0 0 0 12 0h-2a4 4 0 0 1-8 0H6Zm5 6v3h2v-3h-2Z"/></svg><span class="video-audio-meter-v100" aria-hidden="true"><i id="videoAudioMeterFill"></i></span></button>
          <div class="video-camera-device-list-v92 video-camera-device-list-v100" id="videoCameraDeviceList" aria-label="Camera status"></div>
        </div>
      </div>
      <div class="video-editor-top-actions">
        <span class="video-autosave-status-v92" id="videoAutosaveStatus" aria-live="polite">Autosave on</span>
        <button id="videoSaveProject" type="button" hidden aria-hidden="true" tabindex="-1">Save</button>
        <a id="videoExitStudio" class="video-header-button" href="<?= e($videoReturnUrl) ?>" title="Return to the page that opened Video Editor">Exit Studio</a>
        <button id="videoAgentButton" class="video-header-button" type="button" aria-expanded="false">AI</button>
      </div>
    </header>

    <div class="video-main-menu video-main-menu-v92" id="videoMainMenu" hidden>
      <div class="video-menu-project-v92">
        <span>NOW EDITING</span>
        <input id="videoProjectTitle" maxlength="190" value="<?= e((string)($project['title'] ?? 'Untitled Video')) ?>" aria-label="Project title">
        <small><?= $project ? 'Project #' . (int)$project['id'] : 'New video project' ?> · autosaves while you work</small>
      </div>

      <button id="videoOpenProjectMenu" type="button" class="video-menu-primary-v92">Open Project</button>
      <label class="video-project-picker-v92"><span>Recent Projects</span><select id="videoProjectPicker" aria-label="Recent video projects"><option value="">Choose project…</option><?php foreach ($projects as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e((string)$item['title']) ?></option><?php endforeach; ?></select></label>
      <button id="videoSaveAsProject" type="button">Save As</button>

      <div class="video-menu-divider-v92"></div>
      <label class="video-upload-button">+ Upload Media<input id="videoMenuMediaUpload" type="file" accept="image/*,video/*,audio/*" multiple hidden></label>
      <button id="videoMenuLibraryToggle" type="button">Media Library</button>
      <button id="videoMenuInspectorToggle" type="button">Inspector</button>
      <button id="videoOpenCameraMenu" type="button">Camera / Recorder</button>
    </div>

    <section class="video-editor-workspace">
      <aside class="video-editor-library">
        <header>
          <div>
            <span>Source Library</span>
            <h2>Media</h2>
          </div>
          <label class="video-upload-button">
            + Upload
            <input id="videoMediaUpload" type="file" accept="image/*,video/*,audio/*" multiple hidden>
          </label>
        </header>

        <div class="video-library-tabs" role="tablist">
          <button type="button" class="active" data-library-tab="media">My Media</button>
          <button type="button" data-library-tab="music">Music Library</button>
        </div>

        <div id="videoMediaLibrary" class="video-library-list" data-library-panel="media"></div>
        <div id="videoMusicLibrary" class="video-library-list" data-library-panel="music" hidden></div>

        <footer>
          <button id="videoOpenCamera" type="button">Open Camera in Agent Chat</button>
          <p>USB cameras and HDMI capture devices appear here when the browser/OS exposes them as camera inputs.</p>
        </footer>
      </aside>

      <section class="video-editor-center">
        <div class="video-preview-shell">
          <div class="video-preview-stage" id="videoPreviewStage">
            <video id="videoPreviewVideo" playsinline preload="metadata" hidden></video>
            <img id="videoPreviewImage" alt="" hidden>
            <div id="videoPreviewEmpty" class="video-preview-empty">
              <strong>Video Editor</strong>
              <span>Add a photo or video to the visual timeline.</span>
            </div>
          </div>

          <div class="video-preview-controls">
            <button id="videoTimelinePlay" type="button">▶ Preview</button>
            <button id="videoTimelineStop" type="button">■ Stop</button>
            <span id="videoTimelineClock">0:00 / 0:00</span>
          </div>
        </div>

        <section class="video-timeline-panel">
          <header>
            <div>
              <span>Timeline</span>
              <h2>Sequence</h2>
            </div>
            <button id="videoClearTimeline" type="button">Clear</button>
          </header>

          <div class="video-timeline-ruler"><div id="videoTimelinePlayhead"></div></div>

          <div class="video-timeline-lane">
            <strong>VIDEO / PHOTO</strong>
            <div id="videoVisualTimeline" class="video-timeline-track" data-lane="visual"></div>
          </div>

          <div class="video-timeline-lane">
            <strong>AUDIO</strong>
            <div id="videoAudioTimeline" class="video-timeline-track" data-lane="audio"></div>
          </div>
        </section>
      </section>

      <aside class="video-editor-inspector">
        <header>
          <span>Inspector</span>
          <h2 id="videoInspectorTitle">No clip selected</h2>
        </header>

        <div id="videoInspectorEmpty" class="video-inspector-empty">
          Select a timeline item to adjust timing and audio.
        </div>

        <form id="videoInspectorForm" hidden>
          <label>
            <span>Start (seconds)</span>
            <input id="videoItemStart" type="number" min="0" step="0.1">
          </label>
          <label>
            <span>Duration</span>
            <input id="videoItemDuration" type="number" min="0.1" step="0.1">
          </label>
          <label>
            <span>Trim In</span>
            <input id="videoItemTrimStart" type="number" min="0" step="0.1">
          </label>
          <label>
            <span>Trim Out</span>
            <input id="videoItemTrimEnd" type="number" min="0" step="0.1">
          </label>
          <label id="videoVolumeLabel">
            <span>Volume</span>
            <input id="videoItemVolume" type="range" min="0" max="1" step="0.01">
          </label>
          <label id="videoMuteLabel" class="video-inspector-check">
            <input id="videoItemMuted" type="checkbox">
            <span>Muted</span>
          </label>
          <button id="videoRemoveItem" type="button" class="danger">Remove from Timeline</button>
        </form>

        <section class="video-project-settings">
          <h3>Project</h3>
          <label><span>Width</span><input id="videoProjectWidth" type="number" min="320" max="3840" value="<?= (int)($settings['width'] ?? 1920) ?>"></label>
          <label><span>Height</span><input id="videoProjectHeight" type="number" min="240" max="2160" value="<?= (int)($settings['height'] ?? 1080) ?>"></label>
          <label><span>FPS</span><input id="videoProjectFps" type="number" min="12" max="60" value="<?= (int)($settings['fps'] ?? 30) ?>"></label>
        </section>

        <div id="videoEditorStatus" class="video-editor-status" hidden aria-live="polite"></div>
      </aside>
    </section>
  </main>
</div>

<script>
window.STONEFELLOW_VIDEO_EDITOR = {
  csrfToken: <?= json_encode(csrf_token()) ?>,
  projectId: <?= (int)($project['id'] ?? 0) ?>,
  saveEndpoint: <?= json_encode(url('/api/video-editor-v86.php')) ?>,
  mediaEndpoint: <?= json_encode(url('/api/media-library-v86.php')) ?>,
  agentChatUrl: <?= json_encode(url('/chat.php?media=camera')) ?>,
  assets: <?= json_encode($assets,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
  tracks: <?= json_encode($tracks,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
  timeline: <?= json_encode($timeline,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
  missingAssetIds: <?= json_encode($missingAssetIds) ?>,
  autoAssetId: <?= $autoAssetId ?>,
  chatEndpoint: <?= json_encode(url('/api/chat.php')) ?>,
  agentEndpoint: <?= json_encode(url('/api/video-agent-v90.php')) ?>,
  ledgerEndpoint: <?= json_encode(url('/api/agent-edit-ledger-v90.php')) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  userId: <?= (int)$user['id'] ?>,
  voiceMode: <?= !empty($_GET['voice']) ? 'true' : 'false' ?>,
  conversationId: <?= max(0,(int)($_GET['conversation_id'] ?? 0)) ?>,
  returnUrl: <?= json_encode($videoReturnUrl) ?>
};
window.STONEFELLOW_AGENT_CONTEXT={
  userId:<?= (int)$user['id'] ?>,
  surface:'video',
  trackId:0,
  projectId:<?= (int)($project['id'] ?? 0) ?>,
  conversationId:<?= max(0,(int)($_GET['conversation_id'] ?? 0)) ?>,
  taskTitle:<?= json_encode('Video Editor · '.(string)($project['title'] ?? 'Untitled Video')) ?>,
  taskKey:<?= json_encode('video:'.(int)($project['id'] ?? 0)) ?>,
  csrf:<?= json_encode(csrf_token()) ?>,
  proactiveEndpoint:<?= json_encode(url('/api/agent-proactive-v93.php')) ?>
};
</script>
<script>window.STONEFELLOW_CHAT={
  mediaEndpoint:<?= json_encode(url('/api/media-library-v86.php')) ?>,
  videoEditorUrl:<?= json_encode(url('/video-editor.php')) ?>,
  csrf:<?= json_encode(csrf_token()) ?>
};</script>
<script>window.STONEFELLOW_ACTIVITY={endpoint:<?= json_encode(url('/api/agent-activity-v94.php')) ?>,csrf:<?= json_encode(csrf_token()) ?>,surface:'video',trackId:0,projectId:<?= (int)($project['id'] ?? 0) ?>,conversationId:<?= max(0,(int)($_GET['conversation_id'] ?? 0)) ?>,taskTitle:<?= json_encode('Video Editor · '.(string)($project['title'] ?? 'Untitled Video')) ?>,taskKey:<?= json_encode('video:'.(int)($project['id'] ?? 0)) ?>};</script>
<script src="<?= e(url('/video-editor.js?v=' . substr((string) hash_file('sha256', __DIR__ . '/video-editor.js'), 0, 12))) ?>"></script>
<script src="<?= e(url('/chat-media-v86.js?v=95')) ?>"></script>
<script src="<?= e(url('/chat-media-v93.js?v=97')) ?>"></script>
<script src="<?= e(url('/agent-activity-v94.js?v=' . $conversationBuild)) ?>"></script>
<script src="<?= e(url('/editor-media-button-v91.js?v=91')) ?>"></script>
<script src="<?= e(url('/video-header-v92.js?v=100')) ?>"></script>
<script src="<?= e(url('/voice-lease-v122.js?v=' . $voiceBuild)) ?>"></script>
<script src="<?= e(url('/premium-voice-v117.js?v=' . $voiceBuild)) ?>"></script>
<script src="<?= e(url('/conversation-voice-v122.js?v=' . $voiceBuild)) ?>"></script>
<script src="<?= e(url('/editor-voice-barge-v117.js?v=' . $voiceBuild)) ?>"></script>
<script src="<?= e(url('/agent-context-v131.js?v=' . $conversationBuild)) ?>"></script>
<script src="<?= e(url('/editor-agent-v131.js?v=' . $conversationBuild)) ?>"></script>
<span data-stonefellow-build="conversation-integration-v131-20260826" hidden></span>
</body>
</html>
