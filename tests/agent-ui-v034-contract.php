<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sidebar = (string)file_get_contents($root . '/includes/main-sidebar.php');
$rename = (string)file_get_contents($root . '/api/chat-conversation-rename-v034.php');
$runtime = (string)file_get_contents($root . '/api/agent-runtime-status-v034.php');
$memory = (string)file_get_contents($root . '/memory.php');
$js = (string)file_get_contents($root . '/agent-ui-v034.js');
$css = (string)file_get_contents($root . '/agent-ui-v034.css');

$failures = [];
$check = static function(bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$primaryStart = strpos($sidebar, 'data-agent-primary-nav');
$primaryEnd = $primaryStart === false ? false : strpos($sidebar, '</nav>', $primaryStart);
$primary = ($primaryStart !== false && $primaryEnd !== false) ? substr($sidebar, $primaryStart, $primaryEnd - $primaryStart) : '';
foreach (['New Chat','Approvals','Knowledge','Memory','Contacts'] as $label) {
    $check(str_contains($primary, '>' . $label . '<'), 'Primary Agent navigation is missing ' . $label . '.');
}
foreach (['Profile Agent','My Transcriptions','My Team','Plan &amp; Usage','Buy AI Tokens'] as $label) {
    $check(!str_contains($primary, $label), 'Primary Agent navigation still contains secondary item ' . $label . '.');
}
$check(str_contains($sidebar, 'data-agent-user-footer'), 'Bottom user menu is missing.');
$check(str_contains($sidebar, 'vp3AgentRuntimeStrip'), 'Primary runtime strip is missing.');
$check(str_contains($sidebar, 'vp3AgentRuntimeSource'), 'Execution-source indicator is missing.');
$check(str_contains($sidebar, 'vp3AgentRuntimeModel'), 'Brain/model indicator is missing.');
$check(str_contains($sidebar, 'vp3AgentRuntimeUsage'), 'Usage indicator is missing.');
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
$check(str_contains($memory, "workspaceSidebarActive='memory'"), 'Memory does not activate the canonical sidebar route.');
$check(str_contains($memory, 'Forget'), 'Memory lacks an explicit user forget control.');

$check(str_contains($js, 'MutationObserver(decorateHistory)'), 'Dynamic history rows are not decorated after refresh.');
$check(str_contains($js, 'data-rename-conversation') || str_contains($js, 'dataset.renameConversation'), 'Dynamic rename control wiring is missing.');
$check(str_contains($js, 'setInterval(refreshRuntime, 15000)'), 'Runtime status is not refreshed during long Agent sessions.');
$check(str_contains($css, '.agent-sidebar-footer'), 'Bottom user-menu layout is missing.');
$check(str_contains($css, '.chat-history-rename-input'), 'Inline rename editing is not styled.');

if ($failures) {
    fwrite(STDERR, "Agent UI v0.34 contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Agent UI v0.34 contract: PASS\n";
