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
<link rel="stylesheet" href="<?= e(url('/homeserver-vp3.css?v=20260907')) ?>">
<aside class="chat-sidebar workspace-main-sidebar" id="chatSidebar">
  <div class="chat-sidebar-top">
    <a class="chat-brand" href="<?= e(url('/')) ?>" aria-label="VP3">VP3</a>
    <div class="vp3-homeserver-head-actions">
      <button class="vp3-homeserver-status" id="vp3HomeServerStatus" type="button" data-state="unpaired" data-status-url="<?= e(url('/api/homeserver-status.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" aria-haspopup="dialog" aria-controls="vp3HomeServerModal" title="HomeServer status">
        <span class="vp3-homeserver-dot" aria-hidden="true"></span><span class="vp3-homeserver-status-label">HomeServer</span>
      </button>
      <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button>
    </div>
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

        <?php if (has_permission('account.access', $mainSidebarUser)): ?>
          <a class="chat-sidebar-nav-link <?= $mainSidebarActive === 'approvals' ? 'active' : '' ?>" href="<?= e(url('/approvals.php')) ?>">
            <span>✓</span><strong>Approvals</strong>
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

<div class="vp3-homeserver-modal" id="vp3HomeServerModal" hidden>
  <div class="vp3-homeserver-backdrop" data-homeserver-close></div>
  <section class="vp3-homeserver-dialog" role="dialog" aria-modal="true" aria-labelledby="vp3HomeServerModalTitle">
    <header class="vp3-homeserver-modal-head"><div><small>Private Compute</small><h2 id="vp3HomeServerModalTitle">HomeServer Connection</h2></div><button class="vp3-homeserver-close" id="vp3HomeServerClose" type="button" aria-label="Close HomeServer connection">×</button></header>
    <div class="vp3-homeserver-body">
      <div class="vp3-homeserver-summary" id="vp3HomeServerSummary" data-state="unpaired"><span class="vp3-homeserver-dot" aria-hidden="true"></span><div><strong id="vp3HomeServerSummaryTitle">Checking HomeServer…</strong><span id="vp3HomeServerSummaryDetail">Loading connection status.</span></div></div>
      <div class="vp3-homeserver-error" id="vp3HomeServerError" hidden></div>
      <div class="vp3-homeserver-grid">
        <div class="vp3-homeserver-field"><small>Remote Bridge</small><strong id="vp3HomeServerRelay">—</strong></div>
        <div class="vp3-homeserver-field"><small>Last Seen</small><strong id="vp3HomeServerLastSeen">—</strong></div>
        <div class="vp3-homeserver-field"><small>Installed</small><strong id="vp3HomeServerInstalled">—</strong></div>
        <div class="vp3-homeserver-field"><small>Latest VP3 Release</small><strong id="vp3HomeServerLatest">—</strong></div>
        <div class="vp3-homeserver-field"><small>Update Status</small><strong id="vp3HomeServerUpdate">—</strong></div>
        <div class="vp3-homeserver-field"><small>Agent Brain</small><strong id="vp3HomeServerBrain">—</strong></div>
        <div class="vp3-homeserver-field"><small>Compute Source</small><strong id="vp3HomeServerCompute">—</strong></div>
        <div class="vp3-homeserver-field"><small>Provider</small><strong id="vp3HomeServerProvider">—</strong></div>
        <div class="vp3-homeserver-field"><small>Model</small><strong id="vp3HomeServerModel">—</strong></div>
      </div>
      <div class="vp3-homeserver-panel" id="vp3HomeServerConnectPanel">
        <h3>Connect this VP3 account</h3><p>Enable Remote Bridge in HomeServer, then enter the one-time relay claim code. VP3 will ask HomeServer to approve its scoped app permissions separately.</p>
        <form class="vp3-homeserver-form" id="vp3HomeServerClaimForm"><input name="claim_code" maxlength="40" autocomplete="off" spellcheck="false" placeholder="Remote Bridge claim code" aria-label="HomeServer Remote Bridge claim code"><button class="vp3-homeserver-button primary" type="submit">Connect</button></form>
        <div id="vp3HomeServerApprovalPanel" hidden><p>Approve the VP3 pairing code in the local HomeServer Control Center:</p><span class="vp3-homeserver-approval-code" id="vp3HomeServerApprovalCode">—</span><div><button class="vp3-homeserver-button primary" id="vp3HomeServerCheckPairing" type="button">Check Approval</button></div></div>
      </div>
      <div class="vp3-homeserver-panel"><h3>Capabilities</h3><p>Only capabilities reported by the connected HomeServer are shown. VP3 does not receive other apps’ raw private history.</p><div class="vp3-homeserver-capabilities" id="vp3HomeServerCapabilities"><span class="vp3-homeserver-capability">Checking…</span></div></div>
      <div class="vp3-homeserver-actions"><button class="vp3-homeserver-button" id="vp3HomeServerRefresh" type="button">Refresh Status</button><a class="vp3-homeserver-download primary" id="vp3HomeServerDownload" href="#" hidden>Download HomeServer</a><button class="vp3-homeserver-button danger" id="vp3HomeServerDisconnect" type="button" hidden>Disconnect</button></div>
    </div>
  </section>
</div>
<script src="<?= e(url('/homeserver-vp3.js?v=20260907')) ?>" defer></script>
