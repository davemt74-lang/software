<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/studio-participants.php';
require_once __DIR__ . '/includes/studio-voice-profile.php';
require_permission('account.access');
$user=current_user();$pdo=db();
if(!$user||!$pdo||!personal_capability_has_v242('voice_profile.access',$user)){
    flash('account_error','Voice Profile access is unavailable for this account.');redirect(url('/account.php'));
}
try{if(!studio_participants_schema_ready())studio_participants_ensure_schema();if(!studio_voice_profile_schema_ready())studio_voice_profile_ensure_schema();$self=studio_voice_profile_self($pdo,$user);}catch(Throwable $e){redirect(url('/upgrade.php'));}
$voiceProfilePublicUrl=member_navigation_profile_url($user);$asset='studio-voice-profile-ui-20260903';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#0d0d0d"><title><?= e(system_agent_name()) ?> | Voice Profile</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>"><link rel="stylesheet" href="<?= e(url('/voice-profile.css?v='.$asset)) ?>">
</head>
<body>
<div class="chat-app voice-profile-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='voice_profile';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main voice-profile-main">
    <header class="chat-topbar voice-profile-topbar"><button class="chat-icon-button mobile-only" id="openChatSidebar" type="button" aria-label="Open menu">☰</button><div class="chat-topbar-title"><strong>Voice Profile</strong><span>Your voice identity, clone and recognition privacy</span></div><div class="chat-topbar-actions voice-profile-top-actions"><?php if($voiceProfilePublicUrl!==''):?><a href="<?= e($voiceProfilePublicUrl) ?>">View Profile</a><?php endif;?><a href="<?= e(url('/account.php')) ?>">My Account</a><a class="chat-top-avatar voice-profile-avatar-link" href="<?= e(url('/account.php')) ?>" aria-label="My Account"><?php if(user_avatar_url($user)!==''):?><img src="<?= e(user_avatar_url($user)) ?>" alt=""><?php else:?><span><?= e(user_initials($user)) ?></span><?php endif;?></a></div></header>
    <section class="voice-profile-canvas"><div class="voice-profile-wrap">
      <section class="voice-profile-hero"><div class="voice-profile-identity"><div class="voice-profile-avatar"><?php if(user_avatar_url($user)!==''):?><img src="<?= e(user_avatar_url($user)) ?>" alt="<?= e((string)$user['display_name']) ?>"><?php else:?><span><?= e(user_initials($user)) ?></span><?php endif;?></div><div><small>Stonefellow Voice Identity</small><h1><?= e((string)$user['display_name']) ?></h1><p>Record or upload your own voice, create a private voice clone, and decide where voice recognition may identify you.</p></div></div><div class="voice-profile-status-row"><span class="voice-status-pill" id="cloneStatus">Clone · Checking</span><span class="voice-status-pill" id="recognitionStatus">Recognition · Checking</span></div></section>
      <div class="voice-profile-notice" id="voiceProfileNotice" role="status" aria-live="polite" hidden></div>
      <div class="voice-profile-grid"><div class="voice-profile-primary">
        <section class="voice-profile-card"><div class="voice-profile-card-head"><div><small>01 · Voice sample</small><h2>Record or upload your voice</h2></div></div><p>Use a clean recording with only your voice. The sample stays attached to your account and is not made public.</p><div class="voice-record-controls"><button type="button" id="voiceRecordButton">Start recording</button><button type="button" id="voiceStopButton" disabled>Stop</button><span id="voiceRecordStatus">Ready</span></div><audio id="voiceRecordingPreview" controls hidden></audio><label class="voice-profile-upload"><span>Or choose an audio file</span><input type="file" id="voiceFileInput" accept="audio/*"></label><div class="voice-profile-actions"><button type="button" class="primary" id="voiceUploadButton">Save voice sample</button></div></section>
        <section class="voice-profile-card"><div class="voice-profile-card-head"><div><small>02 · Private clone</small><h2>Create your voice clone</h2></div></div><p>When a voice sample is ready, create or refresh the private ElevenLabs voice clone used by your authorized Stonefellow experiences.</p><div class="voice-profile-actions"><button type="button" class="primary" id="voiceCloneButton">Create / refresh clone</button><button type="button" id="voiceDeleteCloneButton">Delete clone</button></div><div class="voice-profile-detail" id="voiceCloneDetail"></div></section>
      </div><aside class="voice-profile-secondary"><section class="voice-profile-card"><div class="voice-profile-card-head"><div><small>Privacy</small><h2>Voice recognition</h2></div></div><label class="voice-profile-switch"><input type="checkbox" id="voiceRecognitionEnabled"><span><strong>Allow Stonefellow to recognize my voice</strong><small>Recognition can identify you only in authorized signed-in or shared Studio contexts.</small></span></label><label class="voice-profile-field"><span>Recognition scope</span><select id="voiceRecognitionScope"><option value="private">Private to me</option><option value="collaborators">My collaborators</option></select></label><div class="voice-profile-actions"><button type="button" class="primary" id="voicePrivacySave">Save privacy</button></div></section><section class="voice-profile-card"><div class="voice-profile-card-head"><div><small>Status</small><h2>Voice identity</h2></div></div><div class="voice-profile-detail" id="voiceIdentityDetail"></div></section></aside></div>
    </div></section>
  </main>
</div>
<script>window.STONEFELLOW_VOICE_PROFILE=<?= json_encode(['endpoint'=>url('/api/studio-voice-profile.php'),'csrf'=>csrf_token(),'userId'=>(int)$user['id']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script><script src="<?= e(url('/member-shell-v77.js?v=profile-owner-v242-20260905')) ?>"></script><script src="<?= e(url('/voice-profile.js?v='.$asset)) ?>"></script>
</body></html>