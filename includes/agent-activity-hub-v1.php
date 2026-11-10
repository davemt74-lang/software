<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-reminders-v1.php';
require_once __DIR__.'/agent-initiative-office-records-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-scheduling-v430.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/coding-canvas-store-v1.php';

function vp3_agent_activity_text_v1(mixed $value, int $limit = 240): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]|\s+/u', ' ', strip_tags(is_scalar($value) ? (string)$value : '')) ?? ''), 0, $limit);
}

function vp3_agent_activity_scope_v1(PDO $pdo, array $user, int $agentId, int $conversationId): array
{
    if ($agentId < 0 || $conversationId < 0 || (int)($user['id'] ?? 0) < 1 || vp3_participant_scope_v1() !== null) {
        throw new RuntimeException('Private Agent Chat access is required.', 403);
    }
    try {
        $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
    } catch (PDOException $error) {
        throw $error;
    } catch (RuntimeException) {
        throw new RuntimeException('This Agent is unavailable.', 403);
    }
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    $live = vp3_capability_live_user_v1($pdo, $user, $principal, ['conversation_id'=>$conversationId], null);
    if (!has_permission('account.access', $live) || !knowledge_retrieval_v162_owner_session($live, $principal)) {
        throw new RuntimeException('Private Agent Chat access changed.', 403);
    }
    return ['user'=>$live, 'principal'=>$principal, 'agent'=>$agent, 'agent_id'=>$agentId, 'conversation_id'=>$conversationId];
}

function vp3_agent_activity_chat_href_v1(int $agentId, int $conversationId = 0): string
{
    return url('/chat.php?agent='.($agentId > 0 ? $agentId : 'system').($conversationId > 0 ? '&conversation_id='.$conversationId : ''));
}

function vp3_agent_activity_card_v1(string $id, string $kind, mixed $title, mixed $detail, array $actions): array
{
    return ['id'=>$id, 'kind'=>$kind, 'title'=>vp3_agent_activity_text_v1($title, 190),
        'detail'=>vp3_agent_activity_text_v1($detail, 300), 'actions'=>$actions];
}

function vp3_agent_activity_reminders_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    if (!reminder_ready_v1($pdo)) throw new RuntimeException('Reminder storage is unavailable.', 503);
    $user = $scope['user'];
    $zone = user_calendar_default_timezone_v1300($pdo, $user);
    $rows = reminder_list_rows_v1($pdo, (int)$user['id'], 'open', $zone, $now);
    usort($rows, static fn(array $a, array $b): int => strcmp(
        (string)($a['list_at'] ?? '9999'), (string)($b['list_at'] ?? '9999')
    ) ?: ((int)$b['id'] <=> (int)$a['id']));
    $items = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        if (!vp3_participant_resource_v1($pdo, $user, $scope['principal'], 'reminder', $id)
            || !reminder_initiative_agent_visible_v1($pdo, $row, $scope['principal'])) continue;
        $href = vp3_agent_activity_chat_href_v1($scope['agent_id']);
        $cid = (int)($row['conversation_id'] ?? 0);
        if ($cid > 0 && vp3_agent_chat_conversation_v380($pdo, $cid, (int)$user['id'], $scope['agent'])) {
            $href = vp3_agent_activity_chat_href_v1($scope['agent_id'], $cid);
        }
        $items[] = vp3_agent_activity_card_v1('reminder:'.$id, 'reminder', $row['title'], reminder_list_label_v1($row), [
            ['kind'=>'task_notes', 'label'=>'Open reminders', 'reminder_id'=>$id, 'view'=>'open', 'timezone'=>$zone, 'href'=>$href],
        ]);
        if (count($items) === 3) break;
    }
    return $items;
}

function vp3_agent_activity_tasks_v1(PDO $pdo, array $scope): array
{
    vp3_task_list_authorize_v1($pdo, $scope['user'], $scope['principal'], 0);
    if (!vp3_agent_memory_scope_schema_ready_v410($pdo)) throw new RuntimeException('Task storage is unavailable.', 503);
    [$where, $params] = vp3_agent_memory_scope_sql_v410($scope['agent_id']);
    $query = $pdo->prepare("SELECT id FROM agent_memory_items WHERE user_id=? AND $where
        AND is_active=1 AND memory_type IN ('task','commitment') ORDER BY last_seen_at DESC,id DESC LIMIT 60");
    $query->execute([(int)$scope['user']['id'], ...$params]);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $task = vp3_initiative_office_task_state_v1($pdo, $scope, (int)$id);
        if (!$task) continue;
        $action = ['kind'=>'url', 'label'=>'Review task', 'href'=>url('/account.php#agent-brain')];
        if (vp3_task_lists_ready_v1($pdo)) {
            $parent = $pdo->prepare('SELECT l.id FROM agent_task_lists_v1 l
                JOIN agent_task_list_items_v1 i ON i.list_id=l.id
                WHERE i.task_id=? AND l.owner_user_id=? AND l.agent_scope_id=? ORDER BY l.id DESC LIMIT 1');
            $parent->execute([(int)$id, (int)$scope['user']['id'], $scope['agent_id']]);
            $listId = (int)$parent->fetchColumn();
            if ($listId > 0) {
                $action = ['kind'=>'task_notes', 'label'=>'Review list', 'list_id'=>$listId, 'task_id'=>(int)$id,
                    'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'])];
            }
        }
        $detail = match ($task['status']) {
            'in_progress'=>'In progress',
            'waiting'=>'Waiting',
            default=>'Open task',
        };
        if ($task['due_at'] !== '') $detail .= ' · Due '.$task['due_at'];
        $items[] = vp3_agent_activity_card_v1('task:'.$id, 'task', $task['title'], $detail, [$action]);
        if (count($items) === 3) break;
    }
    return $items;
}

function vp3_agent_activity_lists_v1(PDO $pdo, array $scope): array
{
    vp3_task_list_authorize_v1($pdo, $scope['user'], $scope['principal'], 0);
    if (!vp3_task_lists_ready_v1($pdo)) throw new RuntimeException('Task list storage is unavailable.', 503);
    $query = $pdo->prepare('SELECT id FROM agent_task_lists_v1 WHERE owner_user_id=? AND agent_scope_id=? ORDER BY id DESC LIMIT 3');
    $query->execute([(int)$scope['user']['id'], $scope['agent_id']]);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $note = vp3_task_list_note_v1($pdo, $scope['user'], $scope['principal'], (int)$id, 200);
        $open = count(array_filter($note['items'], static fn(array $task): bool => !$task['done']));
        $detail = $open.' open '.($open === 1 ? 'task' : 'tasks');
        if ($note['truncated']) $detail = 'At least '.$detail;
        $items[] = vp3_agent_activity_card_v1('list:'.$id, 'task_list', $note['title'], $detail, [
            ['kind'=>'task_notes', 'label'=>'Open list', 'list_id'=>(int)$id,
                'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'])],
        ]);
        if (count($items) === 3) break;
    }
    return $items;
}

function vp3_agent_activity_history_v1(PDO $pdo, array $scope): array
{
    [$where, $params] = vp3_agent_chat_scope_sql_v380($scope['agent'], 'c');
    $query = $pdo->prepare("SELECT c.id,c.title,c.updated_at,
        (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id=c.id) AS last_message
        FROM chat_conversations c WHERE c.user_id=? AND $where
        AND EXISTS (SELECT 1 FROM chat_messages m WHERE m.conversation_id=c.id AND m.role IN ('user','assistant') AND TRIM(m.message)<>'')
        ORDER BY last_message DESC,c.updated_at DESC,c.id DESC LIMIT 3");
    $query->execute([(int)$scope['user']['id'], ...$params]);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $items[] = vp3_agent_activity_card_v1('chat:'.$id, 'chat', $row['title'], 'Recent conversation', [
            ['kind'=>'chat', 'label'=>'Continue Chat', 'conversation_id'=>$id,
                'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'], $id)],
        ]);
    }
    return $items;
}

function vp3_agent_activity_calendar_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    if (!user_calendar_schema_ready_v1300($pdo)) throw new RuntimeException('Calendar storage is unavailable.', 503);
    $zone = new DateTimeZone(user_calendar_default_timezone_v1300($pdo, $scope['user']));
    $events = user_calendar_events_v1300($pdo, $scope['user'], $now->format('Y-m-d H:i:s'),
        $now->modify('+7 days')->format('Y-m-d H:i:s'), false);
    $items = [];
    foreach ($events as $event) {
        $start = user_calendar_validate_utc_v1300($event['start_at_utc']);
        $label = !empty($event['all_day']) ? $start->setTimezone($zone)->format('D, M j').' · All day'
            : $start->setTimezone($zone)->format('D, M j · g:i A T');
        $month = $start->setTimezone($zone)->format('Y-m');
        $id = (string)($event['occurrence_id'] ?? $event['kind'].':'.$event['id']);
        $items[] = vp3_agent_activity_card_v1('calendar:'.$id, 'calendar', $event['title'], $label, [
            ['kind'=>'url', 'label'=>'Open calendar', 'href'=>url('/calendar.php?month='.$month)],
        ]);
        if (count($items) === 3) break;
    }
    return $items;
}

function vp3_agent_activity_missions_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    [$coverage, $rows] = vp3_catch_up_missions_v1($pdo, $scope['user'], $scope['principal'], $now, true);
    if (!in_array($coverage, ['ready','limited'], true)) throw new RuntimeException('Mission storage is unavailable.', $coverage === 'forbidden' ? 403 : 503);
    $items = [];
    foreach (array_slice($rows, 0, 3) as $row) {
        $id = substr($row['id'], strlen('mission:'));
        $items[] = vp3_agent_activity_card_v1($row['id'], 'mission', $row['title'], $row['reason'], [
            ['kind'=>'mission', 'label'=>'Open Mission', 'mission_id'=>$id, 'href'=>$row['url']],
        ]);
    }
    return ['items'=>$items, 'coverage'=>$coverage];
}

function vp3_agent_activity_coding_jobs_v1(PDO $pdo, array $scope): array
{
    require_once __DIR__.'/coding-canvas-jobs-v1.php';
    $agentWhere = $scope['agent_id'] > 0 ? 'agent_id=?' : '(agent_id IS NULL OR agent_id=0)';
    $query = $pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND $agentWhere
        AND source_kind='coding_canvas' AND execution_target='cloud' AND capability_key IN ('coding.build','coding.repair')
        AND status IN ('queued','approved','executing','paused') ORDER BY id DESC LIMIT 12");
    $query->execute($scope['agent_id'] > 0 ? [(int)$scope['user']['id'], $scope['agent_id']] : [(int)$scope['user']['id']]);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            $goal = json_decode((string)$row['goal'], true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($goal) || !is_int($goal['conversation_id'] ?? null) || $goal['conversation_id'] < 1
                || !is_string($goal['canvas_id'] ?? null) || !preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $goal['canvas_id'])
                || !is_array($goal['model'] ?? null)) continue;
            $view = vp3_coding_canvas_job_view_v1($pdo, $row);
            $cid = $view['conversation_id'];
            if ($cid < 1 || !vp3_agent_chat_conversation_v380($pdo, $cid, (int)$scope['user']['id'], $scope['agent'])) continue;
            $detail = match ($row['status']) {
                'executing'=>'Building a private draft.',
                'paused'=>'Build is paused.',
                default=>'A private draft is queued.',
            };
            $model = vp3_agent_activity_text_v1($view['model']['model'] ?? '', 100);
            if ($model !== '') $detail .= ' · '.$model;
            $items[] = vp3_agent_activity_card_v1('coding:'.$view['run_id'], 'coding_job', 'Coding Agent', $detail, [
                ['kind'=>'chat', 'label'=>'Open build Chat', 'conversation_id'=>$cid,
                    'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'], $cid)],
            ]);
            if (count($items) === 3) break;
        } catch (JsonException | InvalidArgumentException) {
            // A malformed saved run is not a usable Chat or Canvas action.
        }
    }
    vp3_coding_canvas_authority_v1($pdo, $scope['user'], $scope['agent_id']);
    return $items;
}

function vp3_agent_activity_canvases_v1(PDO $pdo, array $scope): array
{
    vp3_coding_canvas_authority_v1($pdo, $scope['user'], $scope['agent_id']);
    $items = vp3_agent_activity_coding_jobs_v1($pdo, $scope);
    $query = $pdo->prepare("SELECT c.id,c.title,c.conversation_id,c.draft_revision FROM vp3_coding_canvases_v1 c
        JOIN artist_transcript_folders_v177 f ON f.id=c.folder_id AND f.created_by_user_id=c.owner_user_id
        JOIN vp3_coding_canvas_versions_v1 v ON v.canvas_id=c.id AND v.revision=c.draft_revision
        JOIN knowledge_items k ON k.id=v.code_item_id AND k.folder_id=c.folder_id AND k.created_by_user_id=c.owner_user_id
            AND k.knowledge_scope='personal' AND k.visibility='private' AND k.is_published=0
        WHERE c.owner_user_id=? AND c.agent_id=? AND c.draft_revision IS NOT NULL
        ORDER BY c.updated_at DESC,c.id ASC LIMIT 3");
    $query->execute([(int)$scope['user']['id'], $scope['agent_id']]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (count($items) === 3) break;
        $cid = (int)$row['conversation_id'];
        if (!vp3_agent_chat_conversation_v380($pdo, $cid, (int)$scope['user']['id'], $scope['agent'])) {
            $cid = $scope['conversation_id'];
        }
        $items[] = vp3_agent_activity_card_v1('canvas:'.$row['id'], 'canvas', $row['title'],
            'Draft version '.(int)$row['draft_revision'].' is ready for review.', [
                ['kind'=>'canvas', 'label'=>'Review draft', 'canvas_id'=>$row['id'], 'revision'=>(int)$row['draft_revision'],
                    'conversation_id'=>$cid, 'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'], $cid)
                        .'&canvas_id='.rawurlencode($row['id']).'&canvas_revision='.(int)$row['draft_revision']],
            ]);
    }
    vp3_coding_canvas_authority_v1($pdo, $scope['user'], $scope['agent_id']);
    return $items;
}

function vp3_agent_activity_suggestions_v1(PDO $pdo, array $scope): array
{
    require_once __DIR__.'/agent-brain-v122.php';
    require_once __DIR__.'/agent-top-of-mind-v1.php';
    // The saved policy chooses the source Chat. A different foreground Chat
    // does not hide work already verified for this owner and selected Agent.
    $top = vp3_top_of_mind_v1($pdo, $scope['user'], $scope['agent_id'], 0);
    if (($top['owner_user_id'] ?? null) !== (int)$scope['user']['id'] || ($top['agent_id'] ?? null) !== $scope['agent_id']) {
        throw new RuntimeException('Agent suggestion scope changed.', 403);
    }
    $coverage = match ($top['state'] ?? '') {
        'unavailable'=>'unavailable',
        'paused'=>'disabled',
        default=>($top['coverage']['initiative'] ?? 'ready'),
    };
    $cid = (int)($top['conversation_id'] ?? 0);
    if ($cid < 1 || ($top['state'] ?? '') !== 'ready') return ['items'=>[], 'coverage'=>$coverage];
    $expectedHref = url('/chat.php?agent='.$scope['agent_id'].'&conversation_id='.$cid);
    $href = vp3_agent_activity_chat_href_v1($scope['agent_id'], $cid);
    $items = [];
    foreach ($top['items'] as $item) {
        $state = $item['state'] ?? '';
        $ownerState = $item['owner_state'] ?? '';
        $runId = $item['run_id'] ?? null;
        if (($item['kind'] ?? '') !== 'preparation' || !is_int($runId) || $runId < 1
            || ($item['result_url'] ?? '') !== $expectedHref || !in_array($ownerState, ['', 'open', 'queued'], true)
            || !in_array($state, ['waiting_owner', 'ready_to_report', 'completed'], true)
            || ($state === 'completed' && !in_array($ownerState, ['open', 'queued'], true))) continue;
        $detail = match (true) {
            $state === 'waiting_owner'=>'Waiting for your review.',
            $ownerState === 'queued'=>'A next step is queued.',
            default=>'Prepared result is ready to review.',
        };
        $card = vp3_agent_activity_card_v1('initiative:'.$runId, 'suggestion', $item['objective'], $detail, [
            ['kind'=>'chat', 'label'=>'Review suggestion', 'conversation_id'=>$cid, 'run_id'=>$runId, 'href'=>$href
                .(!empty($item['message_id']) ? '&message_id='.(int)$item['message_id'] : ''),
                ...(!empty($item['message_id']) ? ['message_id'=>(int)$item['message_id']] : [])],
        ]);
        $items[] = $card + ['state'=>$state, 'owner_state'=>$ownerState];
        if (count($items) === 3) break;
    }
    return ['items'=>$items, 'coverage'=>$coverage];
}

/** Bounded owner projections only. Reading this feed never starts or advances work. */
function vp3_agent_activity_hub_v1(PDO $pdo, array $user, int $agentId, int $conversationId = 0): array
{
    $scope = vp3_agent_activity_scope_v1($pdo, $user, $agentId, $conversationId);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $readers = [
        'reminders'=>['Reminders', static fn() => vp3_agent_activity_reminders_v1($pdo, $scope, $now)],
        'tasks'=>['Tasks', static fn() => vp3_agent_activity_tasks_v1($pdo, $scope)],
        'lists'=>['Task lists', static fn() => vp3_agent_activity_lists_v1($pdo, $scope)],
        'history'=>['Recent Chats', static fn() => vp3_agent_activity_history_v1($pdo, $scope)],
        'calendar'=>['Upcoming events', static fn() => vp3_agent_activity_calendar_v1($pdo, $scope, $now)],
        'missions'=>['Missions', static fn() => vp3_agent_activity_missions_v1($pdo, $scope, $now)],
        'canvases'=>['Canvas work', static fn() => vp3_agent_activity_canvases_v1($pdo, $scope)],
        'suggestions'=>['Agent suggestions', static fn() => vp3_agent_activity_suggestions_v1($pdo, $scope)],
    ];
    $sections = [];
    $coverage = [];
    foreach ($readers as $id => [$label, $read]) {
        try {
            $result = $read();
            $hasCoverage = in_array($id, ['missions','suggestions'], true);
            $items = $hasCoverage ? $result['items'] : $result;
            $coverage[$id] = $hasCoverage ? $result['coverage'] : 'ready';
        } catch (Throwable $error) {
            $items = [];
            $coverage[$id] = $error->getCode() === 403 ? 'forbidden' : 'unavailable';
        }
        $sections[] = ['id'=>$id, 'label'=>$label, 'items'=>array_slice($items, 0, 3)];
    }
    require_once __DIR__.'/agent-activity-media-v1.php';
    $media = vp3_agent_activity_media_v1($pdo, $scope['user'], $agentId);
    foreach ($media['sections'] as $section) {
        $section['items'] = array_slice($section['items'], 0, 3);
        $sections[] = $section;
    }
    $coverage += $media['coverage'];
    $fresh = vp3_agent_activity_scope_v1($pdo, $scope['user'], $agentId, $conversationId);
    $currentChat = $conversationId > 0
        ? vp3_agent_chat_conversation_v380($pdo, $conversationId, (int)$fresh['user']['id'], $fresh['agent']) : null;
    if ($conversationId > 0 && !$currentChat) throw new RuntimeException('Conversation access changed.', 403);
    return [
        'contract'=>'vp3.agent.activity.v1', 'owner_user_id'=>(int)$scope['user']['id'],
        'agent_id'=>$agentId, 'agent_name'=>vp3_agent_activity_text_v1($fresh['principal']['display_name'] ?? 'Agent', 120),
        'conversation_id'=>$conversationId, 'current_chat'=>$currentChat
            ? ['id'=>$conversationId, 'title'=>vp3_agent_activity_text_v1($currentChat['title'] ?? 'Chat', 190)] : null,
        'working_attention_binding'=>vp3_catch_up_page_binding_v1($scope['user']),
        'observed_at'=>$now->format(DATE_ATOM), 'sections'=>$sections, 'coverage'=>$coverage,
    ];
}

function vp3_agent_activity_hub_request_v1(PDO $pdo, array $user, string $method, mixed $agentId, mixed $conversationId, string $binding): array
{
    if ($method !== 'GET') throw new RuntimeException('Use GET to read Agent activity.', 405);
    if ((int)($user['id'] ?? 0) < 1 || $binding === '' || !hash_equals(vp3_catch_up_page_binding_v1($user), $binding)) {
        throw new RuntimeException('Your Agent session changed.', 403);
    }
    foreach ([$agentId, $conversationId] as $id) {
        if ((!is_string($id) && !is_int($id)) || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', (string)$id)
            || (int)$id > 9007199254740991) {
            throw new RuntimeException('Choose an exact Agent and conversation.', 422);
        }
    }
    return vp3_agent_activity_hub_v1($pdo, $user, (int)$agentId, (int)$conversationId);
}
