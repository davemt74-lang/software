<?php
declare(strict_types=1);
$workspaceSidebarUser = $workspaceSidebarUser ?? current_user();
$workspaceSidebarActive = $workspaceSidebarActive ?? '';
$workspaceSavedCount = 0;
$workspaceProfileUrl = '';

if ($workspaceSidebarUser && table_exists('track_favorites')) {
    try {
        $workspaceStmt = db()?->prepare('SELECT COUNT(*) FROM track_favorites WHERE user_id=?');
        if ($workspaceStmt) {
            $workspaceStmt->execute([(int)$workspaceSidebarUser['id']]);
            $workspaceSavedCount = (int)$workspaceStmt->fetchColumn();
        }
    } catch (Throwable $e) {}
}

/* Canonical user profile link. The profile schema may not exist until upgrade.php
   has run, so this remains safely optional on older deployments. */
if ($workspaceSidebarUser && function_exists('profile_agent_schema_ready')) {
    try {
        $workspacePdo = db();
        if ($workspacePdo && profile_agent_schema_ready($workspacePdo)) {
            $workspaceProfile = profile_migrate_artist_identity($workspacePdo, $workspaceSidebarUser);
            $workspaceUsername = trim((string)($workspaceProfile['username'] ?? ''));
            if ($workspaceUsername !== '') $workspaceProfileUrl = profile_public_url($workspaceUsername);
        }
    } catch (Throwable $e) {}
}
?>
<!-- Canonical authenticated UI. chat-header-ui.css imports stonefellow-ui.css,
     so every page using this shared workspace shell inherits the same theme. -->
<link rel="stylesheet" data-workspace-header-ui href="<?= e(url('/chat-header-ui.css?v=white-tech-20260904')) ?>">

<aside class="chat-sidebar workspace-main-sidebar" id="chatSidebar">
  <div class="chat-sidebar-top">
    <a class="chat-brand" href="<?= e(url(has_permission('chat.access', $workspaceSidebarUser) ? '/chat.php' : '/index.php')) ?>">Stonefellow</a>
    <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button>
  </div>

  <div class="chat-sidebar-sections">
    <section class="chat-sidebar-nav-section" aria-label="Stonefellow workspace">
      <div class="chat-history-label">Explore</div>
      <nav class="chat-sidebar-nav">
        <?php if (has_permission('chat.access', $workspaceSidebarUser)): ?>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php')) ?>"><span>＋</span><strong>New Chat</strong></a>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=player')) ?>"><span>▶</span><strong>Player</strong></a>
        <?php if ($workspaceSavedCount > 0): ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=saved')) ?>"><span>♥</span><strong>Saved Songs</strong></a>
        <?php endif; ?>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=playlists')) ?>"><span>P</span><strong>Playlists</strong></a>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=shows')) ?>"><span>★</span><strong>Shows</strong></a>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=photos')) ?>"><span>▣</span><strong>Photos</strong></a>
        <a class="chat-sidebar-nav-link" href="<?= e(url('/chat.php?view=merch')) ?>"><span>M</span><strong>Merch</strong></a>
        <?php endif; ?>
      </nav>
    </section>

    <section class="chat-sidebar-nav-section" aria-label="Account workspace">
      <div class="chat-history-label">Workspace</div>
      <nav class="chat-sidebar-nav">
        <?php if (has_permission('account.access', $workspaceSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $workspaceSidebarActive === 'account' ? 'active' : '' ?>" href="<?= e(url('/account.php')) ?>"><span>◉</span><strong>My Account</strong></a>
          <?php if ($workspaceProfileUrl !== ''): ?>
            <a class="chat-sidebar-nav-link" href="<?= e($workspaceProfileUrl) ?>"><span>◎</span><strong>My Profile</strong></a>
          <?php endif; ?>
          <?php if (has_permission('chat.access', $workspaceSidebarUser)): ?>
            <a class="chat-sidebar-nav-link" href="<?= e(url('/account.php#agents-data')) ?>"><span>◇</span><strong>My Agent</strong></a>
            <a class="chat-sidebar-nav-link" href="<?= e(url('/account.php#profile-agent')) ?>"><span>◈</span><strong>Profile Agent</strong></a>
            <a class="chat-sidebar-nav-link <?= $workspaceSidebarActive === 'voice_profile' ? 'active' : '' ?>" href="<?= e(url('/voice-profile.php')) ?>"><span>◌</span><strong>Voice Profile</strong></a>
          <?php endif; ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/notifications.php')) ?>"><span>●</span><strong>Notifications</strong></a>
        <?php endif; ?>
        <?php if (has_permission('tracks.manage', $workspaceSidebarUser) || has_permission('track_notes.manage', $workspaceSidebarUser) || has_permission('producer.access', $workspaceSidebarUser)): ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/admin/stems.php')) ?>"><span>≋</span><strong>Stem Studio</strong></a>
        <?php endif; ?>
        <?php if (has_permission('chat.access', $workspaceSidebarUser)): ?><a class="chat-sidebar-nav-link <?= $workspaceSidebarActive === 'video_editor' ? 'active' : '' ?>" href="<?= e(url('/video-editor.php')) ?>"><span>▤</span><strong>Video Editor</strong></a><?php endif; ?>
        <?php if (has_permission('admin.access', $workspaceSidebarUser)): ?>
          <a class="chat-sidebar-nav-link" href="<?= e(url('/admin/index.php')) ?>"><span>◇</span><strong>Admin Dashboard</strong></a>
        <?php endif; ?>
      </nav>
    </section>
  </div>

  <div class="chat-sidebar-footer">
    <a href="<?= e(url('/logout.php')) ?>">Log Out</a>
  </div>
</aside>

<script data-workspace-account-live-wiring>
(function(){
  'use strict';
  function loadAccountAgentUi(){
    if(!document.querySelector('.account-canvas-content')) return;
    if(document.querySelector('[data-account-agent-settings-loader]')) return;
    var s=document.createElement('script');
    s.src=<?= json_encode(url('/account-agent-settings-loader-v236.js?v=white-tech-20260904'), JSON_UNESCAPED_SLASHES) ?>;
    s.dataset.accountAgentSettingsLoader='server';
    document.body.appendChild(s);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',loadAccountAgentUi,{once:true});
  else loadAccountAgentUi();
})();
</script>