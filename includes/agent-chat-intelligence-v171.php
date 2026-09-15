<?php
declare(strict_types=1);

const VP3_AGENT_CHAT_INTELLIGENCE_V171 = 'agent-chat-intelligence-v171-20260914';

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

function vp3_agent_chat_intelligence_render_v171(array $model): string
{
    $suggestions = is_array($model['suggestions'] ?? null) ? $model['suggestions'] : [];
    $primary = $suggestions[0] ?? [];
    $calendar = is_array($model['calendar'] ?? null) ? $model['calendar'] : [];
    $notifications = is_array($model['notifications'] ?? null) ? $model['notifications'] : [];
    $timezone = (string)($model['timezone'] ?? 'UTC');
    $activity = is_array($model['activity'] ?? null) ? $model['activity'] : [];
    $homeserver = is_array($model['homeserver'] ?? null) ? $model['homeserver'] : [];

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
