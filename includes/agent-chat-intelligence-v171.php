<?php
declare(strict_types=1);

const VP3_AGENT_CHAT_INTELLIGENCE_V171 = 'agent-chat-intelligence-v171-20260914';
const VP3_AGENT_WORK_QUEUE_V172 = 'agent-work-queue-v172-20260914';

function vp3_agent_chat_intelligence_text_v171(string $value, int $max = 160): string
{
    $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? ''));
    if ($value === '') return '';
    return mb_strimwidth($value, 0, $max, '...');
}

function vp3_agent_chat_intelligence_date_v171(string $value, string $timezone = 'UTC'): string
{
    if (trim($value) === '') return '';
    try {
        $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $date->setTimezone(new DateTimeZone($timezone))->format('D M j · g:i A');
    } catch (Throwable $e) {
        return '';
    }
}

function vp3_agent_work_queue_lane_v172(array $row): string
{
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $approval = strtolower(trim((string)($row['approval_status'] ?? '')));
    $nextAttempt = trim((string)($row['next_attempt_at'] ?? ''));
    $nextAttemptTs = $nextAttempt !== '' ? (strtotime($nextAttempt) ?: 0) : 0;
    $future = $nextAttemptTs > time() + 5;
    $retrySignal = trim((string)($row['last_error_class'] ?? '')) !== ''
        || str_contains(strtolower((string)($row['progress_message'] ?? '')), 'retry');

    if ($status === 'completed') return 'completed';
    if ($status === 'approval_pending' || $approval === 'pending') return 'approval';
    if ($status === 'failed') return 'failed_retry';
    if ($future && $retrySignal) return 'failed_retry';
    if ($future) return 'scheduled';
    if (in_array($status, ['queued', 'planning', 'approved', 'executing'], true)) return 'active';
    return 'active';
}

function vp3_agent_work_queue_item_v172(array $row, string $lane, string $timezone): array
{
    $id = max(0, (int)($row['id'] ?? 0));
    $title = vp3_agent_chat_intelligence_text_v171((string)($row['title'] ?? 'Agent work'), 96);
    if ($title === '') $title = 'Agent work #' . $id;
    $status = strtolower(trim((string)($row['status'] ?? 'queued'))) ?: 'queued';
    $progress = max(0, min(100, (int)($row['progress_percent'] ?? 0)));
    $message = vp3_agent_chat_intelligence_text_v171((string)($row['progress_message'] ?? ''), 120);
    $next = vp3_agent_chat_intelligence_date_v171((string)($row['next_attempt_at'] ?? ''), $timezone);
    $updated = vp3_agent_chat_intelligence_date_v171((string)($row['updated_at'] ?? ''), $timezone);
    $attempts = max(0, (int)($row['attempt_count'] ?? 0));
    $target = strtolower(trim((string)($row['execution_target'] ?? 'cloud'))) ?: 'cloud';
    $error = vp3_agent_chat_intelligence_text_v171((string)($row['last_error_class'] ?? ''), 64);

    $detail = match ($lane) {
        'approval' => 'Waiting for your approval',
        'scheduled' => $next !== '' ? 'Scheduled · ' . $next : 'Scheduled',
        'failed_retry' => $status === 'failed'
            ? ('Failed' . ($error !== '' ? ' · ' . $error : ''))
            : ($next !== '' ? 'Retry · ' . $next : 'Retry scheduled'),
        'completed' => $updated !== '' ? 'Completed · ' . $updated : 'Completed',
        default => $status === 'executing'
            ? ($progress > 0 ? 'Working · ' . $progress . '%' : 'Working now')
            : ucfirst($status),
    };
    if ($message !== '' && $lane === 'active') $detail = $message . ($progress > 0 ? ' · ' . $progress . '%' : '');

    $prompt = match ($lane) {
        'approval' => 'Review pending workflow #' . $id . ' (' . $title . '). Explain the requested action, risk, permissions and expected result, then ask me whether I want to approve or cancel it. Do not execute before my explicit approval.',
        'scheduled' => 'Show me the plan and timing for scheduled workflow #' . $id . ' (' . $title . '). Tell me what will happen, when it will run, and whether anything needs my attention first.',
        'failed_retry' => 'Review failed or retrying workflow #' . $id . ' (' . $title . '). Diagnose the latest failure, tell me whether a retry is already scheduled, and recommend the safest next action.',
        'completed' => 'Summarize completed workflow #' . $id . ' (' . $title . '), including the outcome, receipts or result evidence available, and any useful follow-up.',
        default => 'Give me the live status of workflow #' . $id . ' (' . $title . '), including current step, progress, execution target and anything blocking completion.',
    };

    return [
        'id'=>$id,
        'title'=>$title,
        'status'=>$status,
        'detail'=>$detail,
        'progress'=>$progress,
        'attempts'=>$attempts,
        'target'=>$target,
        'prompt'=>$prompt,
        'updated_at'=>$updated,
        'next_attempt_at'=>$next,
    ];
}

function vp3_agent_work_queue_model_v172(PDO $pdo, array $user, string $timezone = 'UTC'): array
{
    $uid = (int)($user['id'] ?? 0);
    $empty = [
        'available'=>false,
        'build'=>VP3_AGENT_WORK_QUEUE_V172,
        'counts'=>['active'=>0,'approval'=>0,'scheduled'=>0,'failed_retry'=>0,'completed'=>0],
        'lanes'=>['active'=>[],'approval'=>[],'scheduled'=>[],'failed_retry'=>[],'completed'=>[]],
    ];
    if ($uid < 1 || !table_exists('agent_workflow_runs') || !column_exists('agent_workflow_runs', 'owner_user_id')) return $empty;

    $hasNextAttempt = column_exists('agent_workflow_runs', 'next_attempt_at');
    $hasProgress = column_exists('agent_workflow_runs', 'progress_message');
    $retryExpr = $hasNextAttempt
        ? "(status='failed' OR (status='approved' AND approval_status<>'pending' AND next_attempt_at>UTC_TIMESTAMP() AND (COALESCE(last_error_class,'')<>''" . ($hasProgress ? " OR LOWER(COALESCE(progress_message,'')) LIKE '%retry%'" : '') . ")))"
        : "status='failed'";
    $scheduledExpr = $hasNextAttempt
        ? "(status='approved' AND approval_status<>'pending' AND next_attempt_at>UTC_TIMESTAMP() AND COALESCE(last_error_class,'')=''" . ($hasProgress ? " AND LOWER(COALESCE(progress_message,'')) NOT LIKE '%retry%'" : '') . ")"
        : '0';
    $activeExpr = $hasNextAttempt
        ? "(approval_status<>'pending' AND (status IN ('queued','planning','executing') OR (status='approved' AND (next_attempt_at IS NULL OR next_attempt_at<=UTC_TIMESTAMP()))))"
        : "(approval_status<>'pending' AND status IN ('queued','planning','approved','executing'))";

    try {
        $countSql = "SELECT "
            . "SUM(CASE WHEN {$activeExpr} THEN 1 ELSE 0 END) active_count,"
            . "SUM(CASE WHEN status='approval_pending' OR approval_status='pending' THEN 1 ELSE 0 END) approval_count,"
            . "SUM(CASE WHEN {$scheduledExpr} THEN 1 ELSE 0 END) scheduled_count,"
            . "SUM(CASE WHEN {$retryExpr} THEN 1 ELSE 0 END) failed_retry_count,"
            . "SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed_count "
            . "FROM agent_workflow_runs WHERE owner_user_id=? AND status<>'cancelled'";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([$uid]);
        $counts = $countStmt->fetch() ?: [];
        $empty['counts'] = [
            'active'=>max(0, (int)($counts['active_count'] ?? 0)),
            'approval'=>max(0, (int)($counts['approval_count'] ?? 0)),
            'scheduled'=>max(0, (int)($counts['scheduled_count'] ?? 0)),
            'failed_retry'=>max(0, (int)($counts['failed_retry_count'] ?? 0)),
            'completed'=>max(0, (int)($counts['completed_count'] ?? 0)),
        ];

        $rowsStmt = $pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND status<>'cancelled' ORDER BY CASE status WHEN 'approval_pending' THEN 1 WHEN 'failed' THEN 2 WHEN 'executing' THEN 3 WHEN 'approved' THEN 4 WHEN 'planning' THEN 5 WHEN 'queued' THEN 6 WHEN 'completed' THEN 7 ELSE 8 END, updated_at DESC,id DESC LIMIT 200");
        $rowsStmt->execute([$uid]);
        foreach ($rowsStmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) continue;
            $lane = vp3_agent_work_queue_lane_v172($row);
            if (!isset($empty['lanes'][$lane]) || count($empty['lanes'][$lane]) >= 6) continue;
            $empty['lanes'][$lane][] = vp3_agent_work_queue_item_v172($row, $lane, $timezone);
        }
        $empty['available'] = true;
    } catch (Throwable $e) {
        return $empty;
    }
    return $empty;
}

function vp3_agent_chat_intelligence_model_v171(
    PDO $pdo,
    array $user,
    ?array $activeAgent = null,
    string $agentName = 'VP3 Agent'
): array {
    $uid = (int)($user['id'] ?? 0);
    $model = [
        'agent_name'=>$agentName,
        'activity'=>['state'=>'idle','label'=>'Idle','task_title'=>'','interruptible'=>true],
        'suggestions'=>[],
        'calendar'=>[],
        'timezone'=>'UTC',
        'notifications'=>[],
        'unread_notifications'=>0,
        'workflow_approvals'=>0,
        'workflow_failures'=>0,
        'knowledge_count'=>0,
        'homeserver'=>['state'=>'unpaired','label'=>'Not paired','paired'=>false,'connected'=>false],
        'attention_count'=>0,
        'work_queue'=>[],
    ];
    if ($uid < 1) return $model;

    try {
        if (function_exists('agent_proactive_v123_suggestions')) {
            $bundle = agent_proactive_v123_suggestions(
                $user,
                'chat',
                ['agent_id'=>(int)($activeAgent['id'] ?? 0),'surface'=>'chat']
            );
            if (is_array($bundle)) {
                if (is_array($bundle['activity'] ?? null)) {
                    $model['activity'] = array_merge($model['activity'], $bundle['activity']);
                }
                if (is_array($bundle['suggestions'] ?? null)) {
                    $model['suggestions'] = array_slice($bundle['suggestions'], 0, 4);
                }
            }
        }
    } catch (Throwable $e) {}

    try {
        if (function_exists('user_calendar_schema_ready_v1300') && user_calendar_schema_ready_v1300($pdo)) {
            $model['timezone'] = user_calendar_default_timezone_v1300($pdo, $user);
            $from = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $to = $from->modify('+7 days');
            $rows = user_calendar_events_v1300(
                $pdo,
                $user,
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s')
            );
            if (is_array($rows)) $model['calendar'] = array_slice($rows, 0, 3);
        }
    } catch (Throwable $e) {}

    try {
        $rows = notification_recent($user, 4);
        if (is_array($rows)) $model['notifications'] = $rows;
        $model['unread_notifications'] = max(0, (int)notification_unread_count($user));
    } catch (Throwable $e) {}

    try {
        if (table_exists('agent_workflow_runs') && column_exists('agent_workflow_runs', 'owner_user_id')) {
            $stmt = $pdo->prepare("SELECT status,COUNT(*) c FROM agent_workflow_runs WHERE owner_user_id=? AND status IN ('approval_pending','failed') GROUP BY status");
            $stmt->execute([$uid]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $status = (string)($row['status'] ?? '');
                if ($status === 'approval_pending') $model['workflow_approvals'] = (int)$row['c'];
                if ($status === 'failed') $model['workflow_failures'] = (int)$row['c'];
            }
        }
    } catch (Throwable $e) {}

    try {
        if (table_exists('knowledge_items')) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM knowledge_items WHERE created_by_user_id=? AND knowledge_scope='personal'");
            $stmt->execute([$uid]);
            $model['knowledge_count'] = max(0, (int)$stmt->fetchColumn());
        }
    } catch (Throwable $e) {}

    try {
        if (function_exists('homeserver_vp3_connection')) {
            $row = homeserver_vp3_connection($uid);
            if (is_array($row) && $row) {
                $state = strtolower(trim((string)($row['status'] ?? 'offline'))) ?: 'offline';
                $paired = !empty($row['homeserver_token_enc']) || in_array($state, ['paired','connected','offline','error'], true);
                $connected = in_array($state, ['paired','connected'], true) && !empty($row['last_seen_at']);
                $model['homeserver'] = [
                    'state'=>$state,
                    'paired'=>$paired,
                    'connected'=>$connected,
                    'label'=>$connected ? 'Connected' : ($paired ? 'Paired · offline' : 'Not paired'),
                ];
            }
        }
    } catch (Throwable $e) {}

    $model['work_queue'] = vp3_agent_work_queue_model_v172($pdo, $user, (string)$model['timezone']);
    $queueCounts = is_array($model['work_queue']['counts'] ?? null) ? $model['work_queue']['counts'] : [];
    $model['workflow_approvals'] = max((int)$model['workflow_approvals'], (int)($queueCounts['approval'] ?? 0));

    $model['attention_count'] =
        (int)$model['unread_notifications']
        + (int)$model['workflow_approvals']
        + (int)$model['workflow_failures'];

    if (!$model['suggestions']) {
        $model['suggestions'][] = [
            'title'=>'Ask your Agent for the best next move',
            'reason'=>'No higher-confidence recommendation is waiting right now.',
            'prompt'=>'Look across my current VP3 tasks, schedule, notifications, Knowledge and recent activity and give me the single best next action.',
            'url'=>'',
            'priority'=>0,
        ];
    }

    return $model;
}

function vp3_agent_work_queue_render_v172(array $queue): string
{
    if (empty($queue['available'])) return '';
    $counts = is_array($queue['counts'] ?? null) ? $queue['counts'] : [];
    $lanes = is_array($queue['lanes'] ?? null) ? $queue['lanes'] : [];
    $meta = [
        'active'=>['label'=>'In progress','empty'=>'No work is running right now.'],
        'approval'=>['label'=>'Waiting approval','empty'=>'No approvals are waiting.'],
        'scheduled'=>['label'=>'Scheduled','empty'=>'No future work is scheduled.'],
        'failed_retry'=>['label'=>'Failed / retry','empty'=>'No failed or retrying work.'],
        'completed'=>['label'=>'Completed','empty'=>'No completed work yet.'],
    ];
    ob_start();
    ?>
    <style data-agent-work-queue-v172>
      .chat-agent-work-queue{display:grid;gap:9px;padding:11px;border:1px solid #e5e7eb;border-radius:12px;background:#fbfcfd}.chat-agent-work-queue-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.chat-agent-work-queue-title{display:grid;gap:2px}.chat-agent-work-queue-title small{color:#8a94a3;font-size:9px;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.chat-agent-work-queue-title strong{font-size:12px}.chat-agent-work-queue-counts{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}.chat-agent-work-queue-counts span{padding:4px 7px;border:1px solid #e2e5e9;border-radius:999px;background:#fff;color:#667085;font-size:8.8px;font-weight:800}.chat-agent-work-queue-counts .warn{border-color:#f4c7c3;background:#fff5f4;color:#b42318}.chat-agent-work-queue-counts .attention{border-color:#f4d6a2;background:#fff9ee;color:#9a5a00}.chat-agent-work-queue-lanes{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px}.chat-agent-work-lane{min-width:0;padding:9px;border:1px solid #e8eaee;border-radius:9px;background:#fff}.chat-agent-work-lane>header{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:6px}.chat-agent-work-lane>header strong{font-size:9.6px}.chat-agent-work-lane>header span{display:grid;min-width:18px;height:18px;place-items:center;border-radius:999px;background:#f1f3f5;color:#667085;font-size:8.5px;font-weight:850}.chat-agent-work-item{appearance:none;display:grid;width:100%;gap:3px;padding:7px 0;border:0;border-top:1px solid #f0f1f3;background:none;color:inherit;text-align:left;cursor:pointer}.chat-agent-work-item:first-of-type{border-top:0}.chat-agent-work-item:hover,.chat-agent-work-item:focus-visible{background:#f8f9fa;outline:0}.chat-agent-work-item strong{overflow:hidden;font-size:9.5px;text-overflow:ellipsis;white-space:nowrap}.chat-agent-work-item small{overflow:hidden;color:#8a94a3;font-size:8.4px;line-height:1.3;text-overflow:ellipsis;white-space:nowrap}.chat-agent-work-empty{margin:0;color:#98a2b3;font-size:8.8px;line-height:1.4}.chat-agent-work-more{margin-top:5px;color:#667085;font-size:8.3px;font-weight:800}.chat-agent-work-queue-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#8a94a3;font-size:8.8px}.chat-agent-work-queue-foot a{color:#667085;font-weight:800;text-decoration:none}@media(max-width:1080px){.chat-agent-work-queue-lanes{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.chat-agent-work-queue-head{align-items:flex-start;flex-direction:column}.chat-agent-work-queue-counts{justify-content:flex-start}.chat-agent-work-queue-lanes{grid-template-columns:1fr}.chat-agent-work-lane{padding:10px}}
    </style>
    <section class="chat-agent-work-queue" data-agent-work-queue="<?= e(VP3_AGENT_WORK_QUEUE_V172) ?>" aria-label="Agent work queue">
      <header class="chat-agent-work-queue-head">
        <div class="chat-agent-work-queue-title"><small>Agent Work Queue</small><strong>What your Agent is doing, waiting on, and finishing</strong></div>
        <div class="chat-agent-work-queue-counts" aria-label="Work queue counts">
          <span><?= (int)($counts['active'] ?? 0) ?> active</span>
          <span class="<?= (int)($counts['approval'] ?? 0) > 0 ? 'attention' : '' ?>"><?= (int)($counts['approval'] ?? 0) ?> approval</span>
          <span><?= (int)($counts['scheduled'] ?? 0) ?> scheduled</span>
          <span class="<?= (int)($counts['failed_retry'] ?? 0) > 0 ? 'warn' : '' ?>"><?= (int)($counts['failed_retry'] ?? 0) ?> failed/retry</span>
          <span><?= (int)($counts['completed'] ?? 0) ?> completed</span>
        </div>
      </header>
      <div class="chat-agent-work-queue-lanes">
        <?php foreach ($meta as $key=>$laneMeta):
            $items = is_array($lanes[$key] ?? null) ? $lanes[$key] : [];
            $count = max(0, (int)($counts[$key] ?? 0));
        ?>
          <article class="chat-agent-work-lane" data-work-lane="<?= e($key) ?>">
            <header><strong><?= e($laneMeta['label']) ?></strong><span><?= $count ?></span></header>
            <?php if ($items): ?>
              <?php foreach (array_slice($items, 0, 2) as $item): ?>
                <button type="button" class="chat-agent-work-item" data-agent-intelligence-prompt="<?= e((string)($item['prompt'] ?? '')) ?>">
                  <strong><?= e((string)($item['title'] ?? 'Agent work')) ?></strong>
                  <small><?= e((string)($item['detail'] ?? '')) ?></small>
                </button>
              <?php endforeach; ?>
              <?php if ($count > 2): ?><div class="chat-agent-work-more">+<?= $count - 2 ?> more</div><?php endif; ?>
            <?php else: ?><p class="chat-agent-work-empty"><?= e($laneMeta['empty']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <footer class="chat-agent-work-queue-foot">
        <span>Owner-scoped durable workflow state · no second job system</span>
        <a href="<?= e(url('/agent-workflows.php')) ?>">Workflow history</a>
      </footer>
    </section>
    <?php
    return (string)ob_get_clean();
}

function vp3_agent_chat_intelligence_render_v171(array $model): string
{
    $suggestions = is_array($model['suggestions'] ?? null) ? $model['suggestions'] : [];
    $primary = $suggestions[0] ?? [];
    $calendar = is_array($model['calendar'] ?? null) ? $model['calendar'] : [];
    $notifications = is_array($model['notifications'] ?? null) ? $model['notifications'] : [];
    $timezone = (string)($model['timezone'] ?? 'UTC');
    $activity = is_array($model['activity'] ?? null) ? $model['activity'] : [];
    $homeserver = is_array($model['homeserver'] ?? null) ? $model['homeserver'] : [];
    $workQueue = is_array($model['work_queue'] ?? null) ? $model['work_queue'] : [];

    $primaryTitle = vp3_agent_chat_intelligence_text_v171((string)($primary['title'] ?? 'Best next move'), 110);
    $primaryReason = vp3_agent_chat_intelligence_text_v171((string)($primary['reason'] ?? ''), 180);
    $primaryPrompt = trim((string)($primary['prompt'] ?? ''));
    $primaryUrl = trim((string)($primary['url'] ?? ''));
    $activityTitle = vp3_agent_chat_intelligence_text_v171((string)($activity['task_title'] ?? ''), 90);
    $activityState = strtolower(trim((string)($activity['state'] ?? 'idle'))) ?: 'idle';

    ob_start();
    ?>
    <section class="chat-agent-intelligence" id="chatAgentIntelligence" data-agent-intelligence data-collapsed="false" aria-label="Agent intelligence">
      <header class="chat-agent-intelligence-head">
        <button class="chat-agent-intelligence-toggle" type="button" data-agent-intelligence-toggle aria-expanded="true" aria-controls="chatAgentIntelligenceBody">
          <span class="chat-agent-intelligence-mark" aria-hidden="true">✦</span>
          <span class="chat-agent-intelligence-heading"><small>Agent Brief</small><strong><?= e($primaryTitle) ?></strong></span>
          <span class="chat-agent-intelligence-chevron" aria-hidden="true">⌃</span>
        </button>
        <div class="chat-agent-intelligence-pulse" aria-label="Workspace status">
          <span class="<?= (int)($model['attention_count'] ?? 0) > 0 ? 'needs-attention' : '' ?>"><?= (int)($model['attention_count'] ?? 0) ?> attention</span>
          <span><?= count($calendar) ?> scheduled</span>
          <span data-intelligence-homeserver-state="<?= e((string)($homeserver['state'] ?? 'unpaired')) ?>"><?= e((string)($homeserver['label'] ?? 'HomeServer')) ?></span>
        </div>
      </header>

      <div class="chat-agent-intelligence-body" id="chatAgentIntelligenceBody">
        <article class="chat-agent-intelligence-primary">
          <div>
            <span>Recommended next move</span>
            <h2><?= e($primaryTitle) ?></h2>
            <p><?= e($primaryReason !== '' ? $primaryReason : 'Your Agent can review the live VP3 context and decide what is most useful next.') ?></p>
          </div>
          <div class="chat-agent-intelligence-primary-actions">
            <?php if ($primaryPrompt !== ''): ?><button type="button" data-agent-intelligence-prompt="<?= e($primaryPrompt) ?>">Ask <?= e((string)($model['agent_name'] ?? 'Agent')) ?></button><?php endif; ?>
            <?php if ($primaryUrl !== ''): ?><a href="<?= e($primaryUrl) ?>">Open source</a><?php endif; ?>
          </div>
        </article>

        <?php if (count($suggestions) > 1): ?>
        <div class="chat-agent-intelligence-actions" aria-label="Recommended actions">
          <?php foreach (array_slice($suggestions, 1, 3) as $suggestion):
            $title = vp3_agent_chat_intelligence_text_v171((string)($suggestion['title'] ?? 'Next action'), 78);
            $reason = vp3_agent_chat_intelligence_text_v171((string)($suggestion['reason'] ?? ''), 105);
            $prompt = trim((string)($suggestion['prompt'] ?? ''));
          ?>
            <button type="button" <?= $prompt !== '' ? 'data-agent-intelligence-prompt="'.e($prompt).'"' : 'disabled' ?>><strong><?= e($title) ?></strong><small><?= e($reason) ?></small></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?= vp3_agent_work_queue_render_v172($workQueue) ?>

        <div class="chat-agent-intelligence-grid">
          <article>
            <header><span>Now</span><a href="<?= e(url('/agent-workflows.php')) ?>">Work queue</a></header>
            <strong><?= e($activityTitle !== '' ? $activityTitle : 'Agent ready') ?></strong>
            <p><?= $activityState === 'working' ? 'Your Agent is actively working now.' : 'Your Agent is available for the next instruction.' ?></p>
            <div class="chat-agent-intelligence-metrics">
              <?php if ((int)($model['workflow_approvals'] ?? 0) > 0): ?><a href="<?= e(url('/agent-workflows.php')) ?>"><?= (int)$model['workflow_approvals'] ?> approval<?= (int)$model['workflow_approvals'] === 1 ? '' : 's' ?></a><?php endif; ?>
              <?php if ((int)($model['workflow_failures'] ?? 0) > 0): ?><a class="warn" href="<?= e(url('/agent-workflows.php')) ?>"><?= (int)$model['workflow_failures'] ?> failed</a><?php endif; ?>
            </div>
          </article>

          <article>
            <header><span>Schedule</span><a href="<?= e(url('/calendar.php')) ?>">Calendar</a></header>
            <?php if ($calendar): ?>
              <?php foreach ($calendar as $event): ?>
                <button type="button" class="chat-agent-intelligence-row" data-agent-intelligence-prompt="<?= e('Help me prepare for this calendar item: '.(string)($event['title'] ?? 'Upcoming calendar item').'.') ?>">
                  <strong><?= e(vp3_agent_chat_intelligence_text_v171((string)($event['title'] ?? 'Calendar item'), 72)) ?></strong>
                  <small><?= e(vp3_agent_chat_intelligence_date_v171((string)($event['start_at_utc'] ?? ''), $timezone)) ?></small>
                </button>
              <?php endforeach; ?>
            <?php else: ?><p>No scheduled items in the next seven days.</p><?php endif; ?>
          </article>

          <article>
            <header><span>Attention</span><a href="<?= e(url('/notifications.php')) ?>">Notifications</a></header>
            <?php if ((int)($model['attention_count'] ?? 0) > 0): ?>
              <strong><?= (int)$model['attention_count'] ?> item<?= (int)$model['attention_count'] === 1 ? '' : 's' ?> need review</strong>
              <p><?= (int)($model['unread_notifications'] ?? 0) ?> unread · <?= (int)($model['workflow_approvals'] ?? 0) ?> approvals · <?= (int)($model['workflow_failures'] ?? 0) ?> failed workflows</p>
              <button type="button" class="chat-agent-intelligence-inline" data-agent-intelligence-prompt="Review what needs my attention across unread notifications, pending workflow approvals and failed workflows. Prioritize the most important item first.">Prioritize for me</button>
            <?php elseif ($notifications): ?>
              <strong>No urgent items</strong><p>Recent notifications are available, but none are currently counted as unread or blocked work.</p>
            <?php else: ?><p>Nothing currently needs your attention.</p><?php endif; ?>
          </article>

          <article>
            <header><span>Context</span><a href="<?= e(url('/knowledge.php')) ?>">Knowledge</a></header>
            <strong><?= (int)($model['knowledge_count'] ?? 0) ?> personal Knowledge item<?= (int)($model['knowledge_count'] ?? 0) === 1 ? '' : 's' ?></strong>
            <p data-intelligence-homeserver-copy><?= e((string)($homeserver['label'] ?? 'HomeServer')) ?> · private capabilities remain HomeServer-authoritative.</p>
            <div class="chat-agent-intelligence-metrics">
              <button type="button" data-agent-intelligence-prompt="Use my available VP3 Knowledge and current task context to improve the recommended next action.">Use my Knowledge</button>
              <?php if (empty($homeserver['connected'])): ?><button type="button" data-agent-intelligence-prompt="Check my HomeServer state and help me restore or finish the connection without weakening any local approval boundary.">Check HomeServer</button><?php endif; ?>
            </div>
          </article>
        </div>
      </div>
    </section>
    <?php
    return (string)ob_get_clean();
}
