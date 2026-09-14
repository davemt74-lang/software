<?php
declare(strict_types=1);

$mainSidebarUser = $mainSidebarUser ?? $workspaceSidebarUser ?? current_user();
$mainSidebarActive = trim((string)($mainSidebarActive ?? $workspaceSidebarActive ?? ''));
if ($mainSidebarActive === '' && function_exists('member_navigation_active_key')) {
    $mainSidebarActive = member_navigation_active_key();
}
$mainSidebarUseNewChatButton = !empty($mainSidebarUseNewChatButton);
$mainSidebarHistoryRows = isset($mainSidebarHistoryRows) && is_array($mainSidebarHistoryRows) ? $mainSidebarHistoryRows : [];
$mainSidebarMenuLinks = $mainSidebarUser ? member_navigation_menu_links($mainSidebarUser) : [];
$mainSidebarScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$mainSidebarIsChat = $mainSidebarActive === 'chat' || $mainSidebarScript === 'chat.php';
$mainSidebarCanChat = $mainSidebarUser && has_permission('chat.access', $mainSidebarUser);
$mainSidebarCurrentSection = function_exists('member_navigation_section_label') ? member_navigation_section_label($mainSidebarActive) : 'Workspace';

$mainSidebarPrimaryOrder = ['chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team'];
$mainSidebarPrimaryKeys = array_fill_keys($mainSidebarPrimaryOrder, true);
$mainSidebarPrimaryLabels = [
    'chat'=>'Agent Chat',
    'profile_agent'=>'Profile Agent',
    'messages'=>'Messages',
    'contacts'=>'Contacts',
    'knowledge'=>'Knowledge',
    'transcriptions'=>'Transcriptions',
    'calendar'=>'Calendar',
    'scheduling'=>'Scheduling',
    'profile_commerce'=>'Products',
    'team'=>'Team',
];
$mainSidebarPrimaryIcons = [
    'chat'=>'✦',
    'profile_agent'=>'◉',
    'messages'=>'✉',
    'contacts'=>'●',
    'knowledge'=>'◇',
    'transcriptions'=>'▤',
    'calendar'=>'▣',
    'scheduling'=>'◷',
    'profile_commerce'=>'▦',
    'team'=>'◎',
];
$mainSidebarPrimarySections = [
    'chat'=>'Agent',
    'profile_agent'=>'Agent',
    'messages'=>'Workspace',
    'contacts'=>'Workspace',
    'knowledge'=>'Workspace',
    'transcriptions'=>'Workspace',
    'calendar'=>'Plan & Sell',
    'scheduling'=>'Plan & Sell',
    'profile_commerce'=>'Plan & Sell',
    'team'=>'Team',
];
$mainSidebarLinkIndex = [];
foreach ($mainSidebarMenuLinks as $link) {
    $key = (string)($link['key'] ?? '');
    if ($key !== '') $mainSidebarLinkIndex[$key] = $link;
}
$mainSidebarPrimaryLinks = [];
foreach ($mainSidebarPrimaryOrder as $key) {
    if (isset($mainSidebarLinkIndex[$key])) $mainSidebarPrimaryLinks[] = $mainSidebarLinkIndex[$key];
}
$mainSidebarFooterLinks = array_values(array_filter(
    $mainSidebarMenuLinks,
    static fn(array $link): bool => !isset($mainSidebarPrimaryKeys[(string)($link['key'] ?? '')])
));
$mainSidebarRoleSummary = $mainSidebarUser ? implode(' · ', user_role_labels($mainSidebarUser)) : '';
$mainSidebarRenderAgentVoiceAssets = empty($GLOBALS['VP3_MEMBER_AGENT_VOICE_MENU_ASSETS_RENDERED']);
if ($mainSidebarRenderAgentVoiceAssets) $GLOBALS['VP3_MEMBER_AGENT_VOICE_MENU_ASSETS_RENDERED'] = true;
?>
<link rel="stylesheet" data-workspace-header-ui href="<?= e(url('/chat-header-ui.css?v=white-tech-20260904')) ?>">
<link rel="stylesheet" href="<?= e(url('/site-branding.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('/homeserver-vp3.css?v=20260910-1')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-policy-v035.css?v=agent-policy-v035-20260909')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-ui-v034.css?v=authenticated-shell-phase2-20260914')) ?>">
<?php if ($mainSidebarIsChat): ?><link rel="stylesheet" data-chat-rail-controls-v132 href="<?= e(url('/chat-rail-controls-v132.css?v=20260911-1')) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('/profile-commerce-products-shell-v1310.css?v=1310')) ?>">
<?php if ($mainSidebarRenderAgentVoiceAssets): ?><link rel="stylesheet" data-member-agent-voice-menu href="<?= e(url('/member-agent-voice-menu.css?v=agent-voice-menu-20260913')) ?>"><?php endif; ?>
<aside
  class="chat-sidebar workspace-main-sidebar"
  id="chatSidebar"
  data-shell-active="<?= e($mainSidebarActive) ?>"
  data-shell-section="<?= e($mainSidebarCurrentSection) ?>"
  data-runtime-url="<?= e(url('/api/agent-runtime-status-v034.php')) ?>"
  data-rename-url="<?= e(url('/api/chat-conversation-rename-v034.php')) ?>"
  data-agent-endpoint="<?= e(url('/api/user-agent-system-v236.php')) ?>"
  data-voice-endpoint="<?= e(url('/api/studio-voice-profile.php')) ?>"
  data-chat-settings-endpoint="<?= e(url('/api/chat-settings-v237.php')) ?>"
  data-voice-profile-url="<?= e(url('/voice-profile.php')) ?>"
  data-profile-agent-url="<?= e(url('/profile-agent.php')) ?>"
  data-account-agents-url="<?= e(url('/account.php#agents-data')) ?>"
  data-csrf="<?= e(csrf_token()) ?>"
>
  <div class="chat-sidebar-top">
    <a class="chat-brand" href="<?= e(url('/chat.php')) ?>" aria-label="VP3 Agent">VP3</a>
    <div class="vp3-homeserver-head-actions">
      <button class="vp3-homeserver-status" id="vp3HomeServerStatus" type="button" data-state="unpaired" data-status-url="<?= e(url('/api/homeserver-status.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" aria-haspopup="dialog" aria-controls="vp3HomeServerModal" title="HomeServer status">
        <span class="vp3-homeserver-dot" aria-hidden="true"></span><span class="vp3-homeserver-status-label">HomeServer</span>
      </button>
      <button class="chat-icon-button mobile-only" id="closeChatSidebar" type="button" aria-label="Close menu">×</button>
    </div>
  </div>

  <div class="chat-sidebar-sections">
    <section class="chat-sidebar-nav-section" aria-label="VP3 workspace navigation">
      <nav class="chat-sidebar-nav agent-primary-nav" data-agent-primary-nav>
        <?php $lastPrimarySection=''; foreach ($mainSidebarPrimaryLinks as $link):
          $key=(string)($link['key']??'');
          $section=(string)($mainSidebarPrimarySections[$key]??'Workspace');
          $isActive=$mainSidebarActive===$key;
        ?>
          <?php if ($section !== $lastPrimarySection): ?><div class="agent-nav-group-label"><?= e($section) ?></div><?php endif; ?>
          <a class="chat-sidebar-nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e((string)$link['url']) ?>" data-vp3-nav-key="<?= e($key) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><span><?= e((string)($mainSidebarPrimaryIcons[$key]??'•')) ?></span><strong><?= e((string)($mainSidebarPrimaryLabels[$key]??$link['label']??$key)) ?></strong></a>
        <?php $lastPrimarySection=$section; endforeach; ?>
      </nav>
    </section>

    <?php if ($mainSidebarCanChat || $mainSidebarHistoryRows): ?>
      <section class="chat-sidebar-history-section" aria-label="Recent chats">
        <div class="chat-history-heading">
          <div class="chat-history-label">Chats</div>
          <?php if ($mainSidebarCanChat): ?>
            <?php if ($mainSidebarUseNewChatButton): ?>
              <button class="chat-history-new" id="newChatButton" type="button" data-chat-view-target="chat" aria-label="New chat" title="New chat">+</button>
            <?php else: ?>
              <a class="chat-history-new" href="<?= e(url('/chat.php')) ?>" aria-label="New chat" title="New chat">+</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <nav class="chat-history" id="chatHistory">
          <?php foreach ($mainSidebarHistoryRows as $conversation): $conversationId=(int)($conversation['id'] ?? 0); $conversationTitle=(string)($conversation['title'] ?? 'Untitled chat'); ?>
            <div class="chat-history-row" data-conversation-row="<?= $conversationId ?>">
              <button class="chat-history-item" type="button" data-conversation-id="<?= $conversationId ?>">
                <span><?= e($conversationTitle) ?></span>
                <small><?= !empty($conversation['updated_at']) ? e(date('M j', strtotime((string)$conversation['updated_at']))) : '' ?></small>
              </button>
              <button class="chat-history-rename" type="button" data-rename-conversation="<?= $conversationId ?>" aria-label="Rename <?= e($conversationTitle) ?>" title="Rename chat">⋯</button>
              <button class="chat-history-delete" type="button" data-delete-conversation="<?= $conversationId ?>" aria-label="Delete <?= e($conversationTitle) ?>" title="Delete chat">×</button>
            </div>
          <?php endforeach; ?>
        </nav>
      </section>
    <?php endif; ?>
  </div>

  <?php if ($mainSidebarUser): ?>
    <footer class="agent-sidebar-footer" data-agent-user-footer>
      <button class="agent-sidebar-user-button" id="vp3AgentUserMenuButton" type="button" aria-expanded="false" aria-controls="vp3AgentUserMenu">
        <span class="agent-sidebar-user-copy"><strong><?= e((string)($mainSidebarUser['display_name'] ?? 'Account')) ?></strong><?php if ($mainSidebarRoleSummary !== ''): ?><small><?= e($mainSidebarRoleSummary) ?></small><?php endif; ?></span>
        <span class="agent-sidebar-user-chevron" aria-hidden="true">⌃</span>
      </button>
      <div class="agent-runtime-strip agent-sidebar-runtime" id="vp3AgentRuntimeStrip" aria-label="Agent runtime status">
        <div class="agent-runtime-line"><small>Run</small><strong id="vp3AgentRuntimeSource">Checking…</strong></div>
        <div class="agent-runtime-line"><small>Brain / model</small><strong id="vp3AgentRuntimeModel">Agent</strong></div>
        <div class="agent-runtime-line"><small>This month</small><a id="vp3AgentRuntimeUsage" href="<?= e(url('/ai-usage.php')) ?>">AI Usage</a></div>
      </div>
      <nav class="agent-sidebar-user-menu" id="vp3AgentUserMenu" aria-label="User menu" hidden>
        <?php $lastGroup=''; foreach ($mainSidebarFooterLinks as $link): $group=(string)($link['group'] ?? '');$footerKey=(string)($link['key']??'');$footerActive=$mainSidebarActive===$footerKey; ?>
          <?php if ($group !== $lastGroup): ?>
            <?php if ($lastGroup !== ''): ?><div class="agent-menu-divider" aria-hidden="true"></div><?php endif; ?>
            <div class="agent-menu-group-label"><?= e(function_exists('member_navigation_group_label') ? member_navigation_group_label($group) : ucfirst($group)) ?></div>
          <?php endif; ?>
          <a class="agent-menu-link<?= $footerActive ? ' active' : '' ?><?= !empty($link['danger']) ? ' logout' : '' ?>" data-vp3-nav-key="<?= e($footerKey) ?>" href="<?= e((string)$link['url']) ?>"<?= $footerActive ? ' aria-current="page"' : '' ?>><span><?= e((string)$link['label']) ?></span><span>↗</span></a>
        <?php $lastGroup=$group; endforeach; ?>
      </nav>
    </footer>
  <?php endif; ?>
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
      <section class="vp3-homeserver-panel agent-policy-panel" id="vp3HomeServerPolicy" aria-labelledby="vp3HomeServerPolicyTitle">
        <div class="agent-policy-heading"><div><h3 id="vp3HomeServerPolicyTitle">Agent permissions</h3><p>HomeServer decides what this VP3 Agent may run automatically, what requires approval, and what stays local-only.</p></div><span class="agent-policy-owner-badge">Managed on HomeServer</span></div>
        <div class="agent-policy-summary" id="vp3HomeServerPolicySummary" aria-live="polite"><span>Checking effective policy…</span></div>
        <div class="agent-policy-list" id="vp3HomeServerPolicies"><div class="agent-policy-empty">Open this panel while HomeServer is connected to view effective tool permissions.</div></div>
      </section>
      <div class="vp3-homeserver-actions"><button class="vp3-homeserver-button" id="vp3HomeServerRefresh" type="button">Refresh Status</button><a class="vp3-homeserver-download primary" id="vp3HomeServerDownload" href="#" hidden>Download HomeServer</a><button class="vp3-homeserver-button danger" id="vp3HomeServerDisconnect" type="button" hidden>Disconnect</button></div>
    </div>
  </section>
</div>
<script src="<?= e(url('/homeserver-vp3.js?v=agent-policy-v035-20260909')) ?>" defer></script>
<script src="<?= e(url('/agent-ui-v034.js?v=agent-ui-v034-20260913-sidebar-footer')) ?>" defer></script>
<?php if ($mainSidebarIsChat): ?><script data-chat-rail-controls-v132 src="<?= e(url('/chat-rail-controls-v132.js?v=20260911-1')) ?>" defer></script><?php endif; ?>
<?php if ($mainSidebarRenderAgentVoiceAssets): ?><script data-member-agent-voice-menu src="<?= e(url('/member-agent-voice-menu.js?v=agent-voice-menu-20260913')) ?>" defer></script><?php endif; ?>
