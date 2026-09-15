<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
$pdo = db();
if (!$user || !$pdo) redirect(url('/login.php'));
$uid = (int)$user['id'];

function vp3_home_trim(string $value, int $max = 150): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value === '' ? '' : mb_strimwidth($value, 0, $max, 'â€¦');
}

function vp3_home_date_label(string $value, string $timezone = 'UTC'): string
{
    if ($value === '') return '';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $date = $date->setTimezone(new DateTimeZone($timezone));
        return $date->format('D, M j Â· g:i A');
    } catch (Throwable $e) {
        return '';
    }
}

$canChat = member_navigation_package_permission($user, 'chat.access', has_permission('chat.access', $user))
    && member_navigation_entitled($user, 'main_ai.access', true);
$canAccount = member_navigation_package_permission($user, 'account.access', has_permission('account.access', $user));
$canKnowledge = member_navigation_entitled(
    $user,
    'knowledge.access',
    personal_capability_has_v242('personal_knowledge.access', $user)
);
$canManageKnowledge = $canKnowledge && personal_capability_has_v242('personal_knowledge.manage', $user);

$agents = [];
$activeAgent = null;
try {
    if (user_agent_system_schema_ready_v236($pdo)) {
        $agents = user_agents_list_v236($pdo, $uid, true);
        foreach ($agents as $agent) {
            if (!empty($agent['is_default'])) { $activeAgent = $agent; break; }
        }
        if (!$activeAgent && $agents) $activeAgent = $agents[0];
    }
} catch (Throwable $e) {
    $agents = [];
    $activeAgent = null;
}
$agentName = vp3_home_trim((string)($activeAgent['display_name'] ?? system_agent_name()), 80);
$agentRole = vp3_home_trim((string)($activeAgent['agent_role'] ?? 'personal'), 80);
$agentChatUrl = url('/chat.php' . ($activeAgent ? '?agent=' . (int)$activeAgent['id'] : ''));

$activity = ['state'=>'idle','label'=>'Idle','task_title'=>'Agent ready','surface'=>'chat'];
if ($canChat && function_exists('agent_activity_v94_snapshot')) {
    try { $activity = agent_activity_v94_snapshot($user); } catch (Throwable $e) {}
}

$tasks = [];
if ($canChat && function_exists('agent_memory_v123_tasks')) {
    try { $tasks = array_slice(agent_memory_v123_tasks($user, false), 0, 4); } catch (Throwable $e) { $tasks = []; }
}

$notifications = [];
$unreadNotifications = 0;
try {
    $notifications = notification_recent($user, 4);
    $unreadNotifications = notification_unread_count($user);
} catch (Throwable $e) {
    $notifications = [];
    $unreadNotifications = 0;
}

$timezone = 'UTC';
$calendarEvents = [];
if ($canAccount && function_exists('user_calendar_schema_ready_v1300') && user_calendar_schema_ready_v1300($pdo)) {
    try {
        $timezone = user_calendar_default_timezone_v1300($pdo, $user);
        $from = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $to = $from->modify('+7 days');
        $calendarEvents = array_slice(user_calendar_events_v1300($pdo, $user, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')), 0, 4);
    } catch (Throwable $e) {
        $calendarEvents = [];
    }
}

$knowledgeCount = 0;
$knowledgeRecent = [];
if ($canKnowledge && table_exists('knowledge_items')) {
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal'");
        $countStmt->execute([$uid]);
        $knowledgeCount = (int)$countStmt->fetchColumn();
        $recentStmt = $pdo->prepare("SELECT id,title,description,file_type,updated_at FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal' ORDER BY updated_at DESC,id DESC LIMIT 4");
        $recentStmt->execute([$uid]);
        $knowledgeRecent = $recentStmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $knowledgeCount = 0;
        $knowledgeRecent = [];
    }
}

$brainActivity = [];
if ($canChat && function_exists('notification_agent_brain_activity_after')) {
    try { $brainActivity = array_slice(notification_agent_brain_activity_after($user, 0, 4), 0, 4); } catch (Throwable $e) { $brainActivity = []; }
}

$homeServer = ['state'=>'unpaired','paired'=>false,'connected'=>false,'installed_version'=>'','last_seen_at'=>null];
if ($canAccount && function_exists('homeserver_vp3_connection')) {
    try {
        $row = homeserver_vp3_connection($uid);
        if ($row) {
            $state = trim((string)($row['status'] ?? 'offline')) ?: 'offline';
            $homeServer = [
                'state'=>$state,
                'paired'=>!empty($row['homeserver_token_enc']),
                'connected'=>in_array($state, ['paired','connected'], true) && !empty($row['last_seen_at']),
                'installed_version'=>trim((string)($row['installed_version'] ?? '')),
                'last_seen_at'=>$row['last_seen_at'] ?? null,
            ];
        }
    } catch (Throwable $e) {}
}

$nowTitle = vp3_home_trim((string)($activity['task_title'] ?? ''), 110);
if ($nowTitle === '' || $nowTitle === 'Agent Chat') {
    $nowTitle = $canChat ? $agentName . ' is ready' : 'Your VP3 workspace is ready';
}
$nextTask = $tasks[0] ?? null;
$nextTitle = $nextTask ? vp3_home_trim((string)($nextTask['title'] ?? $nextTask['text'] ?? ''), 120) : '';
if ($nextTitle === '' && $calendarEvents) $nextTitle = vp3_home_trim((string)($calendarEvents[0]['title'] ?? ''), 120);
if ($nextTitle === '') $nextTitle = $unreadNotifications > 0 ? 'Review what needs your attention' : 'Ask your Agent what to do next';

$memberHeaderUser = $user;
$memberHeaderActiveKey = 'home';
$memberHeaderTitle = 'Agent Home';
$memberHeaderSubtitle = 'Your command center across VP3';
$memberHeaderActionParts = [];
if ($canChat) $memberHeaderActionParts[] = '<a class="primary" href="' . e($agentChatUrl) . '">Open Agent Chat</a>';
if ($canManageKnowledge) $memberHeaderActionParts[] = '<a href="' . e(url('/knowledge.php#knowledge-form')) . '">Add Knowledge</a>';
$memberHeaderActions = implode('', $memberHeaderActionParts);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#f6f7f9">
<title>Agent Home | VP3</title>
<link rel="stylesheet" href="<?= e(url('/chat.css?v=82')) ?>">
<link rel="stylesheet" href="<?= e(url('/agent-home-v194.css?v=agent-home-command-center-20260914')) ?>">
</head>
<body>
<div class="chat-app agent-home-app">
  <?php $workspaceSidebarUser=$user;$workspaceSidebarActive='home';require __DIR__.'/includes/workspace-sidebar-v82.php'; ?>
  <div class="chat-sidebar-backdrop" id="chatSidebarBackdrop"></div>
  <main class="chat-main agent-home-main">
    <?php require __DIR__.'/includes/member-header.php'; ?>
    <section class="agent-home-canvas">
      <div class="agent-home-wrap">
        <section class="agent-home-hero" aria-labelledby="agent-home-title">
          <div class="agent-home-hero-copy">
            <span class="agent-home-eyebrow">VP3 Command Center</span>
            <h1 id="agent-home-title">One place to see what matters next.</h1>
            <p>Your Agent, tasks, schedule, messages, Knowledge and HomeServer status stay connected without turning each tool into a separate dashboard.</p>
            <div class="agent-home-hero-actions">
              <?php if ($canChat): ?><a class="agent-home-button primary" href="<?= e($agentChatUrl) ?>">Talk to <?= e($agentName) ?></a><?php endif; ?>
              <?php if ($canKnowledge): ?><a class="agent-home-button" href="<?= e(url('/knowledge.php')) ?>">Open My Knowledge</a><?php endif; ?>
              <?php if ($canAccount): ?><a class="agent-home-button" href="<?= e(url('/calendar.php')) ?>">Open Calendar</a><?php endif; ?>
            </div>
          </div>
          <aside class="agent-home-agent-card" aria-label="Primary Agent status">
            <div class="agent-home-agent-top"><span class="agent-home-agent-mark" aria-hidden="true">âœ¦</span><span class="agent-home-state <?= e((string)($activity['state'] ?? 'idle')) ?>"><?= e((string)($activity['label'] ?? 'Idle')) ?></span></div>
            <strong><?= e($agentName) ?></strong>
            <span><?= e(ucwords(str_replace('_',' ', $agentRole))) ?> Agent<?= count($agents)>1?' Â· '.count($agents).' active agents':'' ?></span>
            <small><?= e($nowTitle) ?></small>
          </aside>
        </section>

        <section class="agent-home-now-grid" aria-label="Current priorities">
          <article class="agent-home-focus-card">
            <span class="agent-home-card-kicker">Now</span>
            <h2><?= e($nowTitle) ?></h2>
            <p><?= ($activity['state'] ?? 'idle') === 'working' ? 'Your Agent is actively working in ' . e(ucwords((string)($activity['surface'] ?? 'VP3'))) . '.' : 'Your Agent is available and ready for the next instruction.' ?></p>
            <?php if ($canChat): ?><a href="<?= e($agentChatUrl) ?>">Continue in Agent Chat â†’</a><?php endif; ?>
          </article>
          <article class="agent-home-focus-card next">
            <span class="agent-home-card-kicker">Next</span>
            <h2><?= e($nextTitle) ?></h2>
            <?php if ($nextTask): ?><p><?= e(vp3_home_trim((string)($nextTask['text'] ?? ''), 180)) ?></p><a href="<?= e(url('/memory.php')) ?>">Open Agent memory â†’</a>
            <?php elseif ($calendmz÷G!j»-®éÜj×ready_v1300(), 'Agent Home depends only on existing systems schema gates');
assert.match(css, /@media\(max-width:760px\)/, 'Agent Home must support the member mobile breakpoint');
assert.match(css, /prefers-reduced-motion:reduce/, 'Agent Home must respect reduced motion');
assert.match(auth, /has_permission\('chat\\.access', \\$user\\) \\|\\ || has_permission\\('account\\.access', \\$user\\)\\) return url\\('\\/home\\.php'\\)/, 'normal authenticated users must land on Agent Home');
assert.match(login, /vp3_funnel_finish_auth\(login_destination\\(\\), current_user\(\\)\)/, 'login must retain funnel return-to continuity around the new destination');
assert.ok(navigation.includes("'home.php'=>'home'"), 'canonical active-key resolver must recognize Agent Home');
assert.ok(navigation.includes("$add($links,'home','Home'url('/home.php'),'primary')"), 'canonical navigation must expose Home as a primary destination');
assert.match(navigation, /'home','chat','profile_agent','voice_profile'=>'Agent'/, 'Home must belong to the Agent shell section');
assert.match(sidebar, /\\$mainSidebarPrimaryOrder = \\['home','chat','profile_agent'/, 'Home must lead the canonical primary navigation');
assert.ok(sidebar.includes("'home'=>'Home'"), 'sidebar must label the Home destination');
assert.ok(sidebar.includes("'home'=>'âŒ€'"), 'sidebar must give Home a stable icon');
assert.match(sidebar, /class="chat-brand" href="<\\?= e\(url\\('\\/home\\.php'\\)\) \\?>" aria-label="VP3 Home"/, 'VP3 sidebar brand must return to Agent Home');

console.log('Agent Home command center contract passed.');
