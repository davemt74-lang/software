<?php
declare(strict_types=1);

$mainSidebarUser = $mainSidebarUser ?? $workspaceSidebarUser ?? current_user();
$mainSidebarActive = $mainSidebarActive ?? $workspaceSidebarActive ?? '';
$mainSidebarSavedCount = 0;

if ($mainSidebarUser && table_exists('track_favorites')) {
    try {
        $mainSidebarStmt = db()?->prepare('SELECT COUNT(*) FROM track_favorites WHERE user_id=?');
        if ($mainSidebarStmt) {
            $mainSidebarStmt->execute([(int)$mainSidebarUser['id']]);
            $mainSidebarSavedCount = (int)$mainSidebarStmt->fetchColumn();
        }
    } catch (Throwable $e) {}
}
?>
<link rel="stylesheet" data-workspace-header-ui href="<?= e(url('/chat-header-ui.css?v=white-tech-20260904')) ?>">
<link rel="stylesheet" href="<?= e(url('/site-branding.css?v=1')) ?>">
<aside class="chat-sidebar workspace-main-sidebar" id="chatSidebar">
  <div class="chat-sidebar-top">
    <a class="chat-brand" href="<?= e(url('/')) ?>"><?= e(site_brand_name()) ?></a>
    <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button>
  </div>

  <div class="chat-sidebar-sections">
    <section class="chat-sidebar-nav-section" aria-label="<?= e(site_brand_name()) ?> workspace">
      <div class="chat-history-label">Explore</div>
      <nav class="chat-sidebar-nav">
        <?php if (has_permission('chat.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'chat' ? 'active' : '' ?>" href="<?= e(url('/chat.php')) ?>">
            <span>＋</span><strong>New Chat</strong>
          </a>
        <?php endif; ?>

        <?php if (personal_capability_has_v242('profile_agent.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'profile_agent' ? 'active' : '' ?>" href="<?= e(url('/profile-agent.php')) ?>">
            <span>◎</span><strong>Profile Agent</strong>
          </a>
        <?php endif; ?>

        <?php if (has_permission('account.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'contacts' ? 'active' : '' ?>" href="<?= e(url('/contacts.php')) ?>">
            <span>●</span><strong>My Contacts</strong>
          </a>
        <?php endif; ?>

        <?php if (has_permission('artist_listening.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link chat-sidebar-recordings-link <?= $mainSidebarActive === 'transcriptions' ? 'active' : '' ?>" href="<?= e(url('/artist-listening.php')) ?>">
            <span>●</span><strong>My Transcriptions</strong>
          </a>
        <?php endif; ?>

        <?php if (personal_capability_has_v242('personal_knowledge.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'knowledge' ? 'active' : '' ?>" href="<?= e(url('/knowledge.php')) ?>">
            <span>◆</span><strong>My Knowledge</strong>
          </a>
        <?php endif; ?>

        <?php if (has_permission('chat.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'player' ? 'active' : '' ?>" href="<?= e(url('/chat.php?view=player')) ?>">
            <span>▶</span><strong>Player</strong>
          </a>

          <?php if ($mainSidebarSavedCount > 0): ?>
            <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'saved' ? 'active' : '' ?>" href="<?= e(url('/chat.php?view=saved')) ?>">
              <span>♥</span><strong>Saved Songs</strong>
            </a>
          <?php endif; ?>

          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'playlists' ? 'active' : '' ?>" href="<?= e(url('/chat.php?view=playlists')) ?>">
            <span>P</span><strong>My Playlists</strong>
          </a>
        <?php endif; ?>
      </nav>
    </section>

    <?php if (has_permission('account.access', $mainSidebarUser)): ?>
      <section class="chat-sidebar-nav-section" aria-label="Account and plan">
        <div class="chat-history-label">Account</div>
        <nav class="chat-sidebar-nav">
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'subscription' ? 'active' : '' ?>" href="<?= e(url('/subscription.php')) ?>">
            <span>◫</span><strong>Plan &amp; Usage</strong>
          </a>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'token-packs' ? 'active' : '' ?>" href="<?= e(url('/token-packs.php')) ?>">
            <span>＋</span><strong>Buy AI Tokens</strong>
          </a>
        </nav>
      </section>
    <?php endif; ?>
  </div>
</aside>