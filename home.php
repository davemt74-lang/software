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
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? ''));
    if ($value === '') return '';
    return mb_strimwidth($value, 0, $max, '...');
}

function vp3_home_date_label(string $value, string $timezone = 'UTC'): string
{
    if ($value === '') return '';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($timezone))->format('D, M j - g:i A');
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
            if (!empty($agent['is_default'])) {
                $activeAgent = $agent;
                break;
            }
        }
        if (!$activeAgent && $agents) $activeAgent = $agents[0];
    }
} catch (Throwable $e) {
    $agents = [];
    $activeAgent = null;
}

$agentName = vp3_home_trim((string)($activeAgent['display_name'] ?? system_agent_name()), 80);
if ($agentName === '') $agentName = 'VP3 Agent';
$agentRole = vp3_home_trim((string)($activeAgent['agent_role'] ?? 'personal'), 80);
$agentChatUrl = url('/chat.php' . ($activeAgent ? '?agent=' . (int)$activeAgent['id'] : ''));

$activity = ['state'=>'idle','label'=>'Idle','task_title'=>'Agent ready','surface'=>'chat'];
if ($canChat && function_exists('agent_activity_v94_snapshot')) {
    try {
        $snapshot = agent_activity_v94_snapshot($user);
        if (is_array($snapshot)) $activity = array_merge($activity, $snapshot);
    } catch (Throwable $e) {}
}

$tasks = [];
if ($canChat && function_exists('agent_memory_v123_tasks')) {
    try {
        $taskRows = agent_memory_v123_tasks($user, false);
        if (is_array($taskRows)) $tasks = array_slice($taskRows, 0, 4);
    } catch (Throwable $e) {
        $tasks = [];
    }
}

$notifications = [];
$unreadNotifications = 0;
try {
    $notifications = notification_recent($user, 4);
    if (!is_array($notifications)) $notifications = [];
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
        $rows = user_calendar_events_v1300(
            $pdo,
            $user,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s')
        );
        if (is_array($rows)) $calendarEvents = array_slice($rows, 0, 4);
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

        $recentStmt = $pdo->prepare(
            "SELECT id,title,description,file_type,updated_at
             FROM knowledge_items
             WHERE created_by_user_id=? AND knowledge_scope='personal'
             ORDER BY updated_at DESC,id DESC
             LIMIT 4"
        );
        $recentStmt->execute([$uid]);
        $knowledgeRecent = $recentStmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $knowledgeCount = 0;
        $knowledgeRecent = [];
    }
}

$brainActivity = [];
if ($canChat && function_exists('notification_agent_brain_activity_after')) {
    try {
        $rows = notification_agent_brain_activity_after($user, 0, 4);
        if (is_array($rows)) $brainActivity = array_slice($rows, 0, 4);
    } catch (Throwable $e) {
        $brainActivity = [];
    }
}

$homeServer = [
    'state'=>'unpaired',
    'paired'=>false,
    'connected'=>false,
    'installed_version'=>'',
    'last_seen_at'=>null,
];
if ($canAccount && function_exists('homeserver_vp3_connection')) {
    try {
        $row = homeserver_vp3_connection($uid);
        if (is_array($row) && $row) {
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
if ($nextTitle === '' && $calendarEvents) {
    $nextTitle = vp3_home_trim((string)($calendarEvents[0]['title'] ?? 'Upcoming calendar item'), 120);
}
if ($nextTitle === '') {
    $nextTitle = $unreadNotifications > 0 ? 'Review what needs your attention' : 'Ask your Agent what to do next';
}

$memberHeaderUser = $user;
$memberHeaderActiveKey = 'home';
$memberHeaderTitle = 'Agent Home';
$memberHeaderSubtitle = 'Your command center across VP3';
$memberHeaderActionParts = [];
if ($canChat) $memberHeaderActionParts[] = '<a class="primary" href="' . e($agentChatUrl) . '">Open Agent Chat</a>';
if ($canManageKnowledge) $memberHeaderActionParts[] = '<a href="' . e(url('/knowledge.php#knowledge-form')) . '">Add Knowledge</a>';
$memberHeaderActions = implode('', $memberHeaderActionParts);

$homeServerLabel = $homeServer['connected'] ? 'Connected' : ($homeServer['paired'] ? 'Paired - offline' : 'Not paired');
$homeServerDetail = $homeServer['connected']
    ? 'Private HomeServer capabilities are available to this VP3 account.'
    : ($homeServer['paired'] ? 'HomeServer is paired but not currently reachable.' : 'Connect HomeServer for private Knowledge, models, tools and capabilities.');
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
            <div class="agent-home-agent-top"><span class="agent-home-agent-mark" aria-hidden="true">*</span><span class="agent-home-state <?= e((string)($activity['state'] ?? 'idle')) ?>"><?= e((string)($activity['label'] ?? 'Idle')) ?></span></div>
            <strong><?= e($agentName) ?></strong>
            <span><?= e(ucwords(str_replace('_',' ', $agentRole))) ?> Agent<?= count($agents)>1 ? ' - '.count($agents).' active agents' : '' ?></span>
            <small><?= e($nowTitle) ?></small>
          </aside>
        </section>

        <section class="agent-home-now-grid" aria-label="Current priorities">
          <article class="agent-home-focus-card">
            <span class="agent-home-card-kicker">Now</span>
            <h2><?= e($nowTitle) ?></h2>
            <p><?= ($activity['state'] ?? 'idle') === 'working' ? 'Your Agent is actively working in ' . e(ucwords((string)($activity['surface'] ?? 'VP3'))) . '.' : 'Your Agent is available and ready for the next instruction.' ?></p>
            <?php if ($canChat): ?><a href="<?= e($agentChatUrl) ?>">Continue in Agent Chat &rarr;</a><?php endif; ?>
          </article>
          <article class="agent-home-focus-card next">
            <span class="agent-home-card-kicker">Next</span>
            <h2><?= e($nextTitle) ?></h2>
            <?php if ($nextTask): ?>
              <p><?= e(vp3_home_trim((string)($nextTask['text'] ?? $nextTask['description'] ?? ''), 180)) ?></p>
              <a href="<?= e(url('/memory.php')) ?>">Open Agent memory &rarr;</a>
            <?php elseif ($calendarEvents): ?>
              <p>Your next calendar item is already in VP3 and ready for the Agent to use as context.</p>
              <a href="<?= e(url('/calendar.php')) ?>">Open Calendar &rarr;</a>
            <?php elseif ($unreadNotifications > 0): ?>
              <p>You have <?= (int)$unreadNotifications ?> unread notification<?= $unreadNotifications === 1 ? '' : 's' ?> requiring review.</p>
              <a href="<?= e(url('/chat.php')) ?>">Review attention items &rarr;</a>
            <?php else: ?>
              <p>Nothing urgent is queued. Start with Agent Chat or add Knowledge for the next task.</p>
              <?php if ($canChat): ?><a href="<?= e($agentChatUrl) ?>">Ask the Agent &rarr;</a><?php endif; ?>
            <?php endif; ?>
          </article>
        </section>

        <div class="agent-home-section-head">
          <div><span>Workspace pulse</span><h2>What is moving across VP3</h2></div>
          <p>Live product data from the systems you already use, summarized without creating another source of truth.</p>
        </div>

        <section class="agent-home-grid">
          <article class="agent-home-panel">
            <header><div><span>Calendar</span><h3>Coming up</h3></div><?php if ($canAccount): ?><a href="<?= e(url('/calendar.php')) ?>">Open Calendar</a><?php endif; ?></header>
            <?php if ($calendarEvents): ?><div class="agent-home-list">
              <?php foreach ($calendarEvents as $event):
                $eventStart = (string)($event['start_at'] ?? $event['starts_at'] ?? $event['start_time'] ?? '');
                $eventTitle = vp3_home_trim((string)($event['title'] ?? 'Calendar item'), 100);
              ?>
                <a class="agent-home-list-row" href="<?= e(url('/calendar.php')) ?>"><span><strong><?= e($eventTitle) ?></strong><small><?= e(vp3_home_date_label($eventStart, $timezone)) ?></small></span><em>Calendar</em></a>
              <?php endforeach; ?>
            </div><?php else: ?><div class="agent-home-empty"><strong>No upcoming calendar items</strong><span>Your next seven days are clear in VP3.</span></div><?php endif; ?>
          </article>

          <article class="agent-home-panel">
            <header><div><span>Attention</span><h3>Messages and notifications</h3></div><b><?= (int)$unreadNotifications ?> unread</b></header>
            <?php if ($notifications): ?><div class="agent-home-list">
              <?php foreach ($notifications as $notification):
                $notificationTitle = vp3_home_trim((string)($notification['title'] ?? $notification['message'] ?? 'VP3 notification'), 105);
                $notificationText = vp3_home_trim((string)($notification['message'] ?? $notification['body'] ?? ''), 130);
                $notificationDate = vp3_home_date_label((string)($notification['created_at'] ?? ''), $timezone);
                $unread = empty($notification['read_at']) && empty($notification['is_read']);
              ?>
                <a class="agent-home-list-row<?= $unread ? ' unread' : '' ?>" href="<?= e(url('/messages.php')) ?>"><span><strong><?= e($notificationTitle) ?></strong><small><?= e($notificationText) ?></small></span><em><?= e($notificationDate) ?></em></a>
              <?php endforeach; ?>
            </div><?php else: ?><div class="agent-home-empty"><strong>No attention items</strong><span>There are no recent VP3 notifications to review.</span></div><?php endif; ?>
          </article>

          <article class="agent-home-panel">
            <header><div><span>Knowledge</span><h3>Recently available to your Agent</h3></div><?php if ($canKnowledge): ?><a href="<?= e(url('/knowledge.php')) ?>"><?= (int)$knowledgeCount ?> items</a><?php endif; ?></header>
            <?php if ($canKnowledge && $knowledgeRecent): ?><div class="agent-home-list">
              <?php foreach ($knowledgeRecent as $item): ?>
                <a class="agent-home-list-row" href="<?= e(url('/knowledge.php?edit=' . (int)$item['id'])) ?>"><span><strong><?= e(vp3_home_trim((string)($item['title'] ?? 'Knowledge item'), 105)) ?></strong><small><?= e(vp3_home_trim((string)($item['description'] ?? ''), 125)) ?></small></span><em><?= e(strtoupper((string)($item['file_type'] ?? 'note'))) ?></em></a>
              <?php endforeach; ?>
            </div><?php else: ?><div class="agent-home-empty"><strong><?= $canKnowledge ? 'No personal Knowledge yet' : 'Knowledge is not enabled for this account' ?></strong><span><?= $canKnowledge ? 'Add notes or files in My Knowledge to give your Agent durable context.' : 'Your current package does not expose personal Knowledge.' ?></span></div><?php endif; ?>
          </article>

          <article class="agent-home-panel agent-home-homeserver" data-agent-home-homeserver data-state="<?= e((string)$homeServer['state']) ?>">
            <header><div><span>Private compute</span><h3>HomeServer</h3></div><span class="agent-home-status-dot" aria-hidden="true"></span></header>
            <div class="agent-home-homeserver-body">
              <strong data-home-status-label><?= e($homeServerLabel) ?></strong>
              <p data-home-status-detail><?= e($homeServerDetail) ?></p>
              <div class="agent-home-homeserver-meta" data-home-capabilities>
                <?php if ($homeServer['installed_version'] !== ''): ?>Installed version <?= e((string)$homeServer['installed_version']) ?>.<?php elseif ($homeServer['connected']): ?>Connected; capability registry available from HomeServer.<?php else: ?>Capabilities load from HomeServer when connected.<?php endif; ?>
              </div>
            </div>
            <footer><?php if ($canAccount): ?><a href="<?= e(url('/settings-homeserver.php')) ?>">Manage HomeServer &rarr;</a><?php endif; ?></footer>
          </article>
        </section>

        <section class="agent-home-quick" aria-labelledby="agent-home-quick-title">
          <div><span>Quick actions</span><h2 id="agent-home-quick-title">Move without hunting for the right screen.</h2></div>
          <div class="agent-home-quick-grid">
            <?php if ($canChat): ?><a href="<?= e($agentChatUrl) ?>"><b>A</b><span><strong>Agent Chat</strong><small>Ask, plan or continue a conversation</small></span></a><?php endif; ?>
            <?php if ($canManageKnowledge): ?><a href="<?= e(url('/knowledge.php#knowledge-form')) ?>"><b>K</b><span><strong>Add Knowledge</strong><small>Give the Agent durable private context</small></span></a><?php endif; ?>
            <?php if ($canAccount): ?><a href="<?= e(url('/calendar.php')) ?>"><b>C</b><span><strong>Calendar</strong><small>Review schedule and upcoming commitments</small></span></a><?php endif; ?>
            <a href="<?= e(url('/messages.php')) ?>"><b>M</b><span><strong>Messages</strong><small>Review people and attention items</small></span></a>
          </div>
        </section>

        <?php if ($brainActivity): ?>
          <article class="agent-home-panel">
            <header><div><span>Recent activity</span><h3>What your Agent has been doing</h3></div><a href="<?= e(url('/chat.php')) ?>">Open Agent Chat</a></header>
            <div class="agent-home-list">
              <?php foreach ($brainActivity as $entry):
                $entryTitle = vp3_home_trim((string)($entry['title'] ?? $entry['event_type'] ?? $entry['message'] ?? 'Agent activity'), 105);
                $entryText = vp3_home_trim((string)($entry['message'] ?? $entry['detail'] ?? ''), 130);
                $entryDate = vp3_home_date_label((string)($entry['created_at'] ?? ''), $timezone);
              ?>
                <div class="agent-home-list-row"><span><strong><?= e($entryTitle) ?></strong><small><?= e($entryText) ?></small></span><em><?= e($entryDate) ?></em></div>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endif; ?>
      </div>
    </section>
  </main>
</div>
<script>
window.VP3_AGENT_HOME = <?= json_encode([
    'homeserverEndpoint'=>url('/api/homeserver-status.php'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= e(url('/agent-home-v194.js?v=agent-home-command-center-20260914')) ?>"></script>
</body>
</html>
