<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sidebar = (string)file_get_contents($root . '/includes/main-sidebar.php');
$navigation = (string)file_get_contents($root . '/includes/member-navigation.php');
$rename = (string)file_get_contents($root . '/api/chat-conversation-rename-v034.php');
$runtime = (string)file_get_contents($root . '/api/agent-runtime-status-v034.php');
$memory = (string)file_get_contents($root . '/memory.php');
$js = (string)file_get_contents($root . '/agent-ui-v034.js');
$css = (string)file_get_contents($root . '/agent-ui-v034.css');

$failures = [];
$check = static function(bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

// The consolidated authenticated shell renders primary navigation from canonical
// keyed member-navigation entries rather than hard-coded anchors in this template.
$primaryOrder = "['home','chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team']";
$check(str_contains($sidebar, '$mainSidebarPrimaryOrder = ' . $primaryOrder), 'Canonical primary Agent navigation order is missing.');
foreach ([
    'home' => 'Home',
    'chat' => 'Agent Chat',
    'profile_agent' => 'Profile Agent',
    'messages' => 'Messages',
    'contacts' => 'Contacts',
    'knowledge' => 'Knowledge',
    'transcriptions' => 'Transcriptions',
    'calendar' => 'Calendar',
] as $key => $label) {
    $check(str_contains($sidebar, "'{$key}'=>'{$label}'"), 'Primary Agent navigation is missing canonical ' . $label . ' metadata.');
}
$check(str_contains($sidebar, 'member_navigation_menu_links($mainSidebarUser)'), 'Sidebar must consume canonical member navigation.');
$check(str_contains($sidebar, '$mainSidebarPrimaryKeys = array_fill_keys($mainSidebarPrimaryOrder, true)'), 'Primary Agent destinations are not represented by the canonical key set.');
$check(str_contains($sidebar, 'data-vp3-nav-key='), 'Primary Agent navigation is missing canonical keyed rows.');
$check(str_contains($sidebar, 'aria-current="page"'), 'Primary Agent navigation is missing accessible active state.');
$check(!str_contains($sidebar, '$mainSidebarCalendarActive') && !str_contains($sidebar, '$mainSidebarTranscriptionsActive'), 'Legacy page-specific active-state booleans returned.');
$check(!str_contains($primaryOrder, 'memory') && !str_contains($primaryOrder, 'approvals'), 'Account-level Memory or Approvals leaked into primary navigation.');

$check(str_contains($sidebar, 'class="chat-history-heading"'), 'Chats section heading is missing.');
$check(str_contains($sidebar, 'class="chat-history-new" id="newChatButton"'), 'Chats heading is missing the canonical New Chat plus action.');
$check(str_contains($sidebar, 'aria-label="New chat"'), 'Chats heading plus action is missing its accessible label.');

$check(str_contains($navigation, "'home','Home',url('/home.php'),'primary'"), 'Canonical navigation is missing Agent Home.');
$check(str_contains($navigation, "'knowledge','My Knowledge',url('/knowledge.php'),'identity'"), 'Canonical navigation is missing My Knowledge.');
$check(str_contains($navigation, "'transcriptions','My Transcriptions',url('/artist-listening.php'),'identity'"), 'Canonical navigation is missing My Transcriptions.');
$check(str_contains($navigation, "'memory','My Memory',url('/memory.php'),'identity'"), 'Canonical navigation is missing My Memory.');
$check(str_contains($navigation, "'calendar','My Calendar',url('/calendar.php'),'agent'"), 'Canonical navigation is missing My Calendar.');
$check(str_contains($navigation, "'profile_agent','Profile Agent',url('/profile-agent.php'),'identity'"), 'Canonical navigation is missing Profile Agent.');

$check(str_contains($sidebar, 'data-agent-user-footer'), 'Bottom user menu is missing.');
$check(!str_contains($sidebar, 'class="agent-sidebar-avatar"'), 'Bottom user menu must not render the user avatar.');
$footerStart = strpos($sidebar, '<footer class="agent-sidebar-footer"');
$footerEnd = $footerStart === false ? false : strpos($sidebar, '</footer>', $footerStart);
$footer = ($footerStart !== false && $footerEnd !== false) ? substr($sidebar, $footerStart, $footerEnd - $footerStart) : '';
$check(str_contains($footer, 'vp3AgentRuntimeStrip'), 'Runtime stats must be integrated into the bottom user footer.');
$check(str_contains($footer, 'vp3AgentRuntimeSource'), 'Execution-source indicator is missing from the combined footer.');
$check(str_contains($footer, 'vp3AgentRuntimeModel'), 'Brain/model indicator is missing from the combined footer.');
$check(str_contains($footer, 'vp3AgentRuntimeUsage'), 'Usage indicator is missing from the combined footer.');
$check(str_contains($sidebar, 'data-rename-conversation'), 'Server-rendered chat rename control is missing.');
$check(str_contains($sidebar, 'id="chatHistory"'), 'Canonical chat history container is missing.');

$check(str_contains($rename, "has_permission('chat.access'"), 'Rename endpoint is not permission gated.');
$check(str_contains($rename, 'hash_equals(csrf_token(), $csrf)'), 'Rename endpoint is not CSRF protected.');
$check(str_contains($rename, 'WHERE id=? AND user_id=?'), 'Rename endpoint is not owner scoped.');
$check(str_contains($rename, 'SET title=? WHERE'), 'Rename must preserve conversation recency instead of touching updated_at.');
$check(!str_contains($rename, 'SET title=?,updated_at'), 'Rename unexpectedly changes conversation recency.');

foreach (['homeserver_local','user_provider','vp3_cloud','vp3_tool','vp3_retrieval'] as $source) {
    $check(str_contains($runtime, "'{$source}'"), 'Runtime endpoint does not normalize ' . $source . '.');
}
$check(str_contains($runtime, "created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')"), 'Runtime usage indicator is not month scoped.');
$check(str_contains($runtime, 'cloud_tokens_charged'), 'Runtime usage indicator does not use canonical cloud debit accounting.');

$check(str_contains($memory, "WHERE user_id=? AND is_active=1"), 'Memory list is not owner scoped.');
$check(str_contains($memory, 'SET is_active=0 WHERE id=? AND user_id=?'), 'Forget action is not owner scoped.');
$check(str_contains($memory, "workspaceSidebarActive='memory'"), 'Memory does not activate the canonical member-shell route.');
$check(str_contains($memory, 'Forget'), 'Memory lacks an explicit user forget control.');

$check(str_contains($js, 'MutationObserver(decorateHistory)'), 'Dynamic history rows are not decorated after refresh.');
$check(str_contains($js, 'data-rename-conversation') || str_contains($js, 'dataset.renameConversation'), 'Dynamic rename control wiring is missing.');
$check(str_contains($js, 'setInterval(refreshRuntime, 15000)'), 'Runtime status is not refreshed during long Agent sessions.');
$check(str_contains($css, '.agent-sidebar-footer'), 'Bottom user-menu layout is missing.');
$check(str_contains($css, '.agent-sidebar-footer .agent-sidebar-runtime'), 'Combined footer runtime layout is missing.');
$check(str_contains($css, '.chat-history-new'), 'Chats heading New Chat plus is not styled.');
$check(str_contains($css, '.chat-history-rename-input'), 'Inline rename editing is not styled.');

if ($failures) {
    fwrite(STDERR, "Agent UI v0.34 contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Agent UI v0.34 contract: PASS\n";
