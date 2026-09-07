<?php
declare(strict_types=1);

$mainSidebarUser = $mainSidebarUser ?? $workspaceSidebarUser ?? current_user();
$mainSidebarActive = $mainSidebarActive ?? $workspaceSidebarActive ?? '';
$mainSidebarTokenCommerceReady = function_exists('token_pack_schema_ready') && token_pack_schema_ready();
$mainSidebarTeamState = function_exists('team_subscription_state') ? team_subscription_state($mainSidebarUser) : ['authorized'=>false];
$mainSidebarUseNewChatButton = !empty($mainSidebarUseNewChatButton);
$mainSidebarHistoryRows = isset($mainSidebarHistoryRows) && is_array($mainSidebarHistoryRows) ? $mainSidebarHistoryRows : [];
?>
<link rel="stylesheet" data-workspace-header-ui href="<?= e(url('/chat-header-ui.css?v=white-tech-20260904')) ?>">
<link rel="stylesheet" href="<?= e(url('/site-branding.css?v=1')) ?>">
<aside class="chat-sidebar workspace-main-sidebar" id="chatSidebar">
  <div class="chat-sidebar-top">
    <a class="chat-brand" href="<?= e(url('/')) ?>" aria-label="VP3">VP3</a>
    <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button>
  </div>

  <div class="chat-sidebar-sections">
    <section class="chat-sidebar-nav-section" aria-label="VP3 workspace">
      <div class="chat-history-label">Explore</div>
      <nav class="chat-sidebar-nav">
        <?php if (has_permission('chat.access', $mainSidebarUser)): ?>
          <?php if ($mainSidebarUseNewChatButton): ?>
            <button class="chat-sidebar-nav-link <?= $mainSidebarActive === 'chat' ? 'active' : '' ?>" id="newChatButton" type="button" data-chat-view-target="chat">
              <span>＋</span><strong>New Chat</strong>
            </button>
          <?php else: ?>
            <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'chat' ? 'active' : '' ?>" href="<?= e(url('/chat.php')) ?>">
              <span>＋</span><strong>New Chat</strong>
            </a>
          <?php endif; ?>
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

        <?php if (!empty($mainSidebarTeamState['authorized'])): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'team' ? 'active' : '' ?>" href="<?= e(url('/team.php')) ?>" data-main-sidebar-team>
            <span>◎</span><strong>My Team</strong>
          </a>
        <?php endif; ?>
      </nav>
    </section>

    <?php if ($mainSidebarHistoryRows): ?>
      <section class="chat-sidebar-history-section" aria-label="Recent chats">
        <div class="chat-history-label">Chats</div>
        <nav class="chat-history" id="chatHistory">
          <?php foreach ($mainSidebarHistoryRows as $conversation): ?>
            <div class="chat-history-row" data-conversation-row="<?= (int)($conversation['id'] ?? 0) ?>">
              <button class="chat-history-item" type="button" data-conversation-id="<?= (int)($conversation['id'] ?? 0) ?>">
                <span><?= e((string)($conversation['title'] ?? 'Untitled chat')) ?></span>
                <small><?= !empty($conversation['updated_at']) ? e(date('M j', strtotime((string)$conversation['updated_at']))) : '' ?></small>
              </button>
              <button class="chat-history-delete" type="button" data-delete-conversation="<?= (int)($conversation['id'] ?? 0) ?>" aria-label="Delete <?= e((string)($conversation['title'] ?? 'chat')) ?>" title="Delete chat">×</button>
            </div>
          <?php endforeach; ?>
        </nav>
      </section>
    <?php endif; ?>

    <?php if (has_permission('account.access', $mainSidebarUser)): ?>
      <section class="chat-sidebar-nav-section" aria-label="Account and plan">
        <div class="chat-history-label">Account</div>
        <nav class="chat-sidebar-nav">
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'subscription' ? 'active' : '' ?>" href="<?= e(url('/subscription.php')) ?>">
            <span>◫</span><strong>Plan &amp; Usage</strong>
          </a>
          <?php if ($mainSidebarTokenCommerceReady): ?>
            <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'token-packs' ? 'active' : '' ?>" href="<?= e(url('/token-packs.php')) ?>">
              <span>＋</span><strong>Buy AI Tokens</strong>
            </a>
          <?php endif; ?>
        </nav>
      </section>
    <?php endif; ?>
  </div>
</aside>