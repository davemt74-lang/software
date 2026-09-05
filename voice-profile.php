<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/studio-participants.php';
require_once __DIR__ . '/includes/studio-voice-profile.php';
require_permission('account.access');
$user = current_user();
$pdo = db();
if (!$user || !$pdo || !has_permission('chat.access',$user)) {
    flash('account_error','Voice Profile access is unavailable for this account.');
    redirect(url('/account.php'));
}
try {
    if (!studio_participants_schema_ready()) studio_participants_ensure_schema();
    if (!studio_voice_profile_schema_ready()) studio_voice_profile_ensure_schema();
    $self = studio_voice_profile_self($pdo,$user);
} catch (Throwable $e) {
    redirect(url('/upgrade.php'));
}
$voiceProfilePublicUrl = member_navigation_profile_url($user);
$asset = 'studio-voice-profile-ui-20260903';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0d0d0d">
<title><?= e(system_agent_name()) ?> | Voice Profile</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/voice-profile.css?v='.$asset)) ?>">
</head>
<body>
<div class="chat-app voice-profile-app">
  <?php
    $workspaceSidebarUser = $user;
    $workspaceSidebarActive = 'voice_profile';
    require __DIR__ . '/includes/workspace-sidebar-v82.php';
  ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>

  <main class="chat-main voice-profile-main">
    <header class="chat-topbar voice-profile-topbar">
      <button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button>
      <div class="chat-topbar-title">
        <strong>Voice Profile</strong>
        <span>Your voice identity, clone and recognition privacy</span>
      </div>
      <div class="chat-topbar-actions voice-profile-top-actions">
        <?php if ($voiceProfilePublicUrl !== ''): ?><a href="<?= e($voiceProfilePublicUrl) ?>">View Profile</a><?php endif; ?>
        <a href="<?= e(url('/account.php')) ?>">My Account</a>
        <a class="chat-top-avatar voice-profile-avatar-link" href="<?= e(url('/account.php')) ?>" aria-label="My Account">
          <?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?>
        </a>
      </div>
    </header>

    <section class="voice-profile-canvas">
      <div class="voice-profile-wrap">
        <section class="voice-profile-hero">
          <div class="voice-profile-identity">
            <div class="voice-profile-avatar">
              <?php if (user_avatar_url($user) !== ''): ?><img src="<?= e(user_avatar_url($user)) ?>" alt="<?= e((string)$user['display_name']) ?>"><?php else: ?><span><?= e(user_initials($user)) ?></span><?php endif; ?>
            </div>
            <div>
              <small>Stonefellow Voice Identity</small>
              <h1><?= e((string)$user['display_name']) ?></h1>
              <p>Record or upload your own voice, create a private voice clone, and decide where voice recognition may identify you.</p>
            </div>
          </div>
          <div class="voice-profile-status-row">
            <span class="voice-status-pill" id="cloneStatus">Clone · Checking</span>
            <span class="voice-status-pill" id="recognitionStatus">Recognition · Checking</span>
          </div>
        </section>

        <div class="voice-profile-notice" id="voiceProfileNotice" role="status" aria-live="polite" hidden></div>

        <div class="voice-profile-grid">
          <div class="voice-profile-primary">
            <section class="voice-card voice-capture-card">
              <header class="voice-card-head">
                <div><small>01 · Voice Sample</small><h2>Record your voice</h2></div>
                <span class="voice-secure-label">Private storage</span>
              </header>
              <p class="voice-card-copy">Use a quiet room and speak naturally for 20–60 seconds. You can also upload an existing sample.</p>

              <div class="voice-recorder" id="voiceRecorder">
                <div class="voice-meter" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                <div class="voice-recorder-state">
                  <strong id="recordingState">Ready to record</strong>
                  <span id="recordingTimer">00:00</span>
                </div>
                <div class="voice-recorder-actions">
                  <button type="button" class="voice-primary-button" id="startRecording">Start Recording</button>
                  <button type="button" class="voice-secondary-button" id="stopRecording" disabled>Stop & Save</button>
                  <label class="voice-secondary-button voice-upload-button" for="voiceUpload">Upload Sample</label>
                  <input id="voiceUpload" type="file" accept="audio/*,.webm,.ogg,.m4a,.mp4,.mp3,.wav" hidden>
                </div>
              </div>

              <div class="voice-prompt-box">
                <small>Suggested reading</small>
                <p>“I’m creating my <?= e(system_agent_name()) ?> voice profile. This recording contains my natural speaking voice, pace, tone and pronunciation.”</p>
              </div>
            </section>

            <section class="voice-card">
              <header class="voice-card-head">
                <div><small>02 · Samples</small><h2>Your voice samples</h2></div>
                <span id="sampleCount">0 samples</span>
              </header>
              <div class="voice-sample-list" id="voiceSampleList">
                <div class="voice-empty-state">No voice samples yet.</div>
              </div>
            </section>

            <section class="voice-card">
              <header class="voice-card-head">
                <div><small>03 · Voice Clone</small><h2>Create & preview your clone</h2></div>
                <span id="cloneVerifiedBadge">Not created</span>
              </header>
              <p class="voice-card-copy">Select one of your saved samples. <?= e(system_agent_name()) ?> only allows this account to create a clone for its own linked identity.</p>
              <div class="voice-selected-sample" id="selectedSampleBox">
                <span>No sample selected</span>
                <strong>Select a sample above</strong>
              </div>
              <div class="voice-clone-actions">
                <button type="button" class="voice-primary-button" id="createClone" disabled>Create My Voice Clone</button>
                <button type="button" class="voice-danger-button" id="revokeClone" disabled>Revoke Voice Clone</button>
              </div>
              <div class="voice-preview-panel">
                <label for="previewText">Preview phrase</label>
                <div class="voice-preview-row">
                  <input id="previewText" maxlength="360" value="This is my <?= e(system_agent_name()) ?> voice profile.">
                  <button type="button" class="voice-secondary-button" id="previewClone" disabled>Preview Voice</button>
                </div>
                <audio id="clonePreviewPlayer" controls hidden></audio>
              </div>
            </section>
          </div>

          <aside class="voice-profile-secondary">
            <section class="voice-card voice-privacy-card">
              <header class="voice-card-head"><div><small>Privacy</small><h2>Voice permissions</h2></div></header>
              <label class="voice-toggle-row">
                <span><strong>Voice recognition</strong><small>Allow Stonefellow to associate a provider voice match with your conversational identity.</small></span>
                <input type="checkbox" id="recognitionConsent"><i></i>
              </label>
              <label class="voice-toggle-row">
                <span><strong>Voice cloning</strong><small>Allow this account to create its own ElevenLabs voice clone.</small></span>
                <input type="checkbox" id="cloningConsent"><i></i>
              </label>

              <div class="voice-privacy-field">
                <label for="recognitionScope">Who can recognize me?</label>
                <select id="recognitionScope">
                  <option value="private">Only my private workspace</option>
                  <option value="contacts">My Stonefellow contacts</option>
                  <option value="collaborators">My collaborators</option>
                </select>
              </div>
              <button type="button" class="voice-primary-button full" id="savePrivacy">Save Voice Privacy</button>
              <p class="voice-privacy-note">Voice recognition is conversational context only. It is never used as authentication or account verification.</p>
            </section>

            <section class="voice-card voice-info-card">
              <small>Recognition state</small>
              <h3 id="recognitionDetail">Not enrolled</h3>
              <p id="recognitionCopy">Recognition consent can be configured now. Provider speaker enrollment remains a separate identity step.</p>
            </section>

            <section class="voice-card voice-info-card">
              <small>Clone provider</small>
              <h3>ElevenLabs</h3>
              <p>Your raw Voice Profile samples remain private on this server. A selected sample is sent to ElevenLabs only when you explicitly create your clone.</p>
            </section>
          </aside>
        </div>
      </div>
    </section>
  </main>
</div>
<script>
window.STONEFELLOW_VOICE_PROFILE={
  userId:<?= (int)$user['id'] ?>,
  participantId:<?= (int)$self['id'] ?>,
  endpoint:<?= json_encode(url('/api/studio-voice-profile.php')) ?>,
  csrf:<?= json_encode(csrf_token()) ?>,
  systemName:<?= json_encode(system_agent_name()) ?>
};
</script>
<script src="<?= e(url('/voice-profile.js?v='.$asset)) ?>"></script>
</body>
</html>
