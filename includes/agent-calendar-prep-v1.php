<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-receipts-v1.php';

function vp3_calendar_prep_text_v1(mixed $value, int $limit): string
{
    $text = is_string($value) ? trim(preg_replace('/[\s\x00-\x1f\x7f]+/u', ' ', $value) ?? '') : '';
    return mb_strimwidth($text, 0, $limit, '…');
}

/** The Calendar only supplies evidence for an exact owner-linked meeting. */
function vp3_calendar_prep_details_v1(PDO $pdo, array $user, array $principal, array $event): array
{
    $owner = (int)$user['id'];
    $id = (int)$event['id'];
    $booking = ($event['kind'] ?? 'event') === 'booking';
    $reviewUrl = $booking ? url('/scheduling.php?schedule='.(int)$event['schedule_id'].'&tab=bookings') : url('/calendar-event.php?id='.$id);
    $location = trim((string)($event['location'] ?? ''));
    $locationUrl = filter_var($location, FILTER_VALIDATE_URL) && str_starts_with($location, 'https://')
        && parse_url($location, PHP_URL_USER) === null && parse_url($location, PHP_URL_PASS) === null
        ? $location : '';
    $result = ['review_url'=>$reviewUrl, 'meeting_url'=>$locationUrl, 'agenda_status'=>'unknown',
        'open_items'=>[], 'completed_items'=>[], 'unknown_items'=>[], 'items_truncated'=>false];

    if (!table_exists('video_meetings') || ($event['recurrence'] ?? 'none') === 'weekly') return $result;
    require_once __DIR__.'/video-meetings-v1800.php';
    $meeting = $booking ? video_meeting_for_booking_v1800($pdo, $id) : video_meeting_for_calendar_event_v1800($pdo, $id);
    if (!$meeting || (int)$meeting['owner_user_id'] !== $owner || !in_array($meeting['status'], ['scheduled','ready','live'], true)
        || (string)$meeting['start_at_utc'] !== (string)$event['start_at_utc']
        || !preg_match('/\A[a-f0-9]{32}\z/', (string)$meeting['public_id'])) return $result;

    $result['meeting_url'] = $result['review_url'] = url('/meeting.php?meeting='.$meeting['public_id']);
    require_once __DIR__.'/video-meetings-agenda-v18140.php';
    if (video_meeting_agenda_schema_ready_v18140($pdo)) {
        $agenda = video_meeting_agenda_items_v18140($pdo, $meeting, $owner);
        $result['agenda_status'] = $agenda ? 'available' : 'empty';
        $actions = [];
        $actionsReady = false;
        if ($agenda && table_exists('video_meeting_action_executions')) {
            require_once __DIR__.'/video-meetings-actions-v18150-part1.php';
            $actionsReady = video_meeting_action_schema_ready_v18150($pdo);
            if ($actionsReady) {
                $deliveryFields = column_exists('video_meeting_action_executions','result_json') && column_exists('video_meeting_action_executions','executed_at')
                    ? ',result_json,executed_at' : '';
                $stmt = $pdo->prepare('SELECT agenda_item_id,action_kind,status,workflow_run_id'.$deliveryFields.' FROM video_meeting_action_executions
                    WHERE meeting_id=? AND owner_user_id=? ORDER BY id LIMIT '.VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140);
                $stmt->execute([(int)$meeting['id'],$owner]);
                $actions = array_column($stmt->fetchAll(PDO::FETCH_ASSOC),null,'agenda_item_id');
            }
        }
        foreach ($agenda as $item) {
            $text = vp3_calendar_prep_text_v1($item['item_text'] ?? '', 200);
            if ($text === '' || ($item['status'] ?? '') === 'skipped') continue;
            $status = (string)($item['status'] ?? '');
            $kind = (string)($item['action_kind'] ?? '');
            if ($kind === '') {
                if ($status === 'open') $result['open_items'][] = 'Agenda · '.$text;
                continue;
            }
            $execution = $actions[(int)$item['id']] ?? null;
            if (!$actionsReady || ($execution && $execution['action_kind'] !== $kind)) {
                $result['unknown_items'][] = $text;
                continue;
            }
            $executionStatus = $execution['status'] ?? '';
            if ($executionStatus === 'executed' && $kind === 'task' && function_exists('agent_workflow_row_v1400')) {
                $run = agent_workflow_row_v1400($pdo, $owner, (int)($execution['workflow_run_id'] ?? 0));
                if (($run['status'] ?? '') === 'completed') $executionStatus = 'completed';
            }
            if ($executionStatus === 'executed' && $kind === 'email' && !empty($execution['executed_at'])) {
                $delivery = json_decode((string)($execution['result_json'] ?? ''),true);
                if (($delivery['delivery'] ?? '') === 'accepted_by_mail_transport') {
                    $result['completed_items'][] = $text.' (mail transport accepted)';
                    continue;
                }
            }
            $bucket = match ($executionStatus) {
                'completed' => 'completed_items',
                'needs_review', 'approved', 'executing' => 'open_items',
                '' => in_array($status, ['open','follow_up'], true) ? 'open_items' : 'unknown_items',
                default => 'unknown_items',
            };
            $result[$bucket][] = $text;
        }
        $result['items_truncated'] = count($agenda) >= VP3_VIDEO_MEETINGS_AGENDA_ITEM_LIMIT_V18140;
    }

    try {
        vp3_calendar_prep_linked_tasks_v1($pdo, $user, $principal, (int)$meeting['id'], $result);
    } catch (PDOException $error) {
        $result['items_truncated'] = true;
    }
    foreach (['open_items','completed_items','unknown_items'] as $key) {
        $items = array_values(array_unique($result[$key]));
        $result['items_truncated'] = $result['items_truncated'] || count($items) > 4;
        $result[$key] = array_slice($items, 0, 4);
    }
    return $result;
}

/** Read only explicit Meeting Intelligence task references and their saved status. */
function vp3_calendar_prep_linked_tasks_v1(PDO $pdo, array $user, array $principal, int $meetingId, array &$result): void
{
    if (!function_exists('personal_capability_has_v242') || !personal_capability_has_v242('agent_brain.access',$user)
        || !table_exists('agent_memory_items') || !column_exists('agent_memory_items','metadata_json')
        || !column_exists('agent_memory_items','user_agent_id')) return;
    require_once __DIR__.'/agent-task-lists-v1.php';
    $agentId = vp3_task_list_agent_v1($principal);
    if ($agentId > 0 && !function_exists('user_data_policy_can_use_v236')) return;
    $scope = $agentId > 0 ? 'user_agent_id=?' : 'user_agent_id IS NULL';
    $params = [(int)$user['id'], (string)$meetingId];
    if ($agentId > 0) $params[] = $agentId;
    $stmt = $pdo->prepare("SELECT id,subject,metadata_json FROM agent_memory_items WHERE user_id=? AND is_active=1
        AND memory_type IN ('task','commitment') AND CAST(CASE WHEN JSON_VALID(metadata_json)
        THEN JSON_EXTRACT(metadata_json,'$.video_meeting_id') END AS CHAR)=? AND {$scope} ORDER BY id LIMIT 21");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result['items_truncated'] = $result['items_truncated'] || count($rows) > 20;
    foreach (array_slice($rows, 0, 20) as $task) {
        $metadata = json_decode((string)$task['metadata_json'], true);
        if (($metadata['source_kind'] ?? '') !== 'video_meeting_intelligence' || ($metadata['review_state'] ?? '') !== 'approved'
            || (int)($metadata['video_meeting_id'] ?? 0) !== $meetingId
            || !in_array((int)($metadata['assigned_user_id'] ?? 0), [0,(int)$user['id']], true)
            || !vp3_task_list_task_allowed_v1($pdo,$user,$principal,(int)$task['id'])) continue;
        $title = vp3_calendar_prep_text_v1($task['subject'], 200);
        if ($title === '') continue;
        $bucket = match ($metadata['task_status'] ?? '') {
            'completed' => 'completed_items',
            'open', 'in_progress', 'waiting' => 'open_items',
            'cancelled' => null,
            default => 'unknown_items',
        };
        if ($bucket !== null) $result[$bucket][] = $title;
    }
}

/** Upcoming Cloud Calendar occurrences in the owner's current calendar day. */
function vp3_calendar_prep_today_v1(PDO $pdo, array $user, int $agentId, int $conversationId, ?DateTimeImmutable $now = null, ?callable $authority = null): array
{
    if ($agentId < 0 || $conversationId < 1 || (int)($user['id'] ?? 0) < 1
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('Calendar prep is available in private Agent Chat.', 403);
    }
    require_once __DIR__.'/agent-chat-boundary-v380.php';
    $agent = vp3_agent_chat_resolve_agent_v380($pdo, $user, $agentId);
    $principal = vp3_agent_chat_principal_v380($user, $agent);
    $user = vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
    if (!has_permission('account.access',$user)) throw new RuntimeException('Calendar access changed.',403);
    if (!function_exists('user_calendar_events_v1300') || !user_calendar_schema_ready_v1300($pdo)) {
        throw new RuntimeException('Calendar storage is unavailable.',503);
    }

    $zone = new DateTimeZone(user_calendar_default_timezone_v1300($pdo,$user));
    $utc = new DateTimeZone('UTC');
    $now = ($now ?? new DateTimeImmutable('now',$utc))->setTimezone($utc);
    $end = $now->setTimezone($zone)->setTime(0,0)->modify('+1 day')->setTimezone($utc);
    $events = user_calendar_events_v1300($pdo,$user,$now->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),false);
    usort($events,static fn(array $a,array $b): int => (int)!empty($a['all_day']) <=> (int)!empty($b['all_day']));
    $rows = [];
    foreach ($events as $event) {
        $start = user_calendar_validate_utc_v1300($event['start_at_utc']);
        if ($start < $now && empty($event['all_day'])) continue;
        $details = vp3_calendar_prep_details_v1($pdo,$user,$principal,$event);
        $rows[] = ['id'=>$event['id'], 'kind'=>$event['kind'],
            'occurrence_id'=>$event['occurrence_id'] ?? 'booking:'.$event['id'],
            'title'=>vp3_calendar_prep_text_v1($event['title'],190), 'starts_at'=>$start->format(DATE_ATOM),
            'all_day'=>!empty($event['all_day']),
            'starts_label'=>!empty($event['all_day']) ? 'All day' : $start->setTimezone($zone)->format('g:i A T'),
        ] + $details;
        if (count($rows) === 3) break;
    }
    $live = vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],$authority);
    if (!has_permission('account.access',$live)) throw new RuntimeException('Calendar access changed.',403);
    return $rows;
}

function vp3_calendar_prep_chat_intent_v1(string $query): bool
{
    $text = mb_strtolower(trim(str_replace('’', "'", $query)));
    if (preg_match('/\b(?:tomorrow|yesterday|next week|next month)\b/u',$text)) return false;
    return preg_match('/^(?:please\s+|(?:can|could|would) you\s+)?(?:what|which|how|have i|am i|do i|show(?: me)?|check|review|help me|calendar prep)\b/u',$text)
        && preg_match('/\b(?:today|today\'s|calendar prep)\b/u',$text)
        && preg_match('/\b(?:calendar|event|meeting|call|appointment)\b/u',$text)
        && preg_match('/\b(?:prep(?:are|aration)?|checklist|agenda|ready|readiness|left|done|completed|remaining|need to do)\b/u',$text);
}

function vp3_calendar_prep_chat_v1(PDO $pdo, array $user, int $agentId, int $conversationId, ?callable $authority = null): array
{
    $result = ['handled'=>true, 'answer'=>'', 'actions'=>[], 'sources'=>[]];
    try {
        $events = vp3_calendar_prep_today_v1($pdo,$user,$agentId,$conversationId,null,$authority);
    } catch (Throwable $error) {
        $blocked = $error->getCode() === 403;
        $result['answer'] = $blocked ? 'Open your private Agent Chat to review calendar prep.' : 'I couldn’t verify calendar prep right now.';
        $result['tool_receipt'] = vp3_tool_receipt_v1('calendar.read','list',$blocked?'blocked':'failed',$blocked?'permission_denied':'check_failed');
        return $result;
    }
    $lines = [];
    foreach ($events as $event) {
        $lines[] = $event['starts_label'].' — '.$event['title'];
        if ($event['meeting_url'] !== '') $lines[] = 'A meeting link is saved.';
        if ($event['agenda_status'] === 'unknown') $lines[] = 'I couldn’t verify a linked agenda.';
        if ($event['agenda_status'] === 'empty') $lines[] = 'The linked meeting agenda has no saved items.';
        foreach (['open_items'=>'Open', 'completed_items'=>'Completed', 'unknown_items'=>'Completion unverified'] as $key=>$label) {
            if ($event[$key]) $lines[] = $label."\n- ".implode("\n- ",$event[$key]);
        }
        if (!$event['completed_items'] && !$event['unknown_items']) $lines[] = 'I couldn’t verify any completed prep from the linked records.';
        if ($event['items_truncated']) $lines[] = 'Open the source to review more items.';
        $result['sources'][] = ['source'=>'calendar:'.$event['occurrence_id'],'title'=>$event['title'],'url'=>$event['review_url']];
        $result['actions'][] = ['type'=>'open_url','label'=>'Review '.$event['title'],'url'=>$event['review_url']];
    }
    $result['answer'] = $lines ? implode("\n",$lines) : 'I found no upcoming events in your saved VP3 calendar for today.';
    $result['tool_receipt'] = vp3_tool_receipt_v1('calendar.read','list',$events?'read_complete':'no_matches','',0,count($events));
    return $result;
}
