<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/participant-updates-v1.php';
require_once __DIR__.'/knowledge-retrieval-v162.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-reminders-v1.php';
require_once __DIR__.'/agent-initiative-office-records-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-scheduling-v430.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/coding-canvas-store-v1.php';

function vp3_agent_activity_card_v1(string $id, string $kind, mixed $title, mixed $detail, array $actions, int $detailLimit = 300): array
{
    return ['id'=>$id, 'kind'=>$kind, 'title'=>vp3_agent_activity_text_v1($title, 190),
        'detail'=>vp3_agent_activity_text_v1($detail, $detailLimit), 'actions'=>$actions];
}

function vp3_agent_activity_media_offer_v1(PDO $pdo, array $scope): ?array
{
    require_once __DIR__.'/agent-initiative-youtube-offer-v1.php';
    $user = $scope['user'];
    $agent = $scope['agent_id'];
    $conversation = $scope['conversation_id'];
    $message = agent_connection_youtube_latest_offer_message_v1($pdo, (int)$user['id'], $agent, $conversation);
    if ($message === null) return null;
    $offer = vp3_initiative_youtube_offer_post_v1($pdo, $user, $agent, $conversation, $message);
    if ($offer === null) return null;
    $title = vp3_agent_activity_text_v1($offer['video']['title'] ?? '', 160) ?: 'YouTube music';
    return vp3_agent_activity_card_v1('youtube-offer:'.$message, 'youtube_offer', $title,
        vp3_initiative_youtube_offer_message_v1($offer), [
            ['kind'=>'chat', 'label'=>'View in Chat', 'conversation_id'=>$conversation, 'message_id'=>$message,
                'href'=>vp3_agent_activity_chat_href_v1($agent, $conversation).'&message_id='.$message],
        ]);
}

/** A compact view of the same current findings used by the coordination loop. */
function vp3_agent_activity_coordination_v1(PDO $pdo, array $scope): array
{
    if ($scope['conversation_id'] < 1) return [];
    require_once __DIR__.'/agent-coordination-work-v1.php';
    $rows = vp3_coordination_visible_v1($pdo, $scope['user'], $scope['agent_id'], $scope['conversation_id']);
    usort($rows, static fn(array $a,array $b):int =>
        (($a['routing']['specialist']??null)==='casual') <=> (($b['routing']['specialist']??null)==='casual'));
    $cards = [];
    foreach ($rows as $row) {
        $id = $row['id'] ?? null;
        $state = $row['state'] ?? null;
        $result = vp3_agent_activity_text_v1($row['result'] ?? '', 600);
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D', $id) || isset($cards[$id])
            || ($row['conversation_id'] ?? null) !== $scope['conversation_id']
            || !in_array($state, ['needs_owner','agent_work','resolved','watching'], true)) continue;
        $actions = [];
        $messageId = $row['message_id'] ?? null;
        if (is_int($messageId) && $messageId > 0) {
            $actions[] = ['kind'=>'chat', 'label'=>'View in Chat', 'conversation_id'=>$scope['conversation_id'],
                'message_id'=>$messageId, 'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'], $scope['conversation_id']).'&message_id='.$messageId];
        }
        $card = vp3_agent_activity_card_v1($id, 'coordination_finding', $row['title'] ?? '', $row['summary'] ?? '', $actions, 600);
        if ($card['title'] === '' || $card['detail'] === '') continue;
        $card['state'] = $state;
        if (isset($row['routing'])) $card['routing'] = $row['routing'];
        if (isset($row['delivery'])) $card['delivery'] = $row['delivery'];
        // Only an exact posted message, source revision and run may be matched
        // to the signed follow-through receipt below. A source alone is not an action.
        if (is_int($messageId) && $messageId > 0
            && in_array($row['routing']['specialist']??null,['profile','media','marketing'],true)
            && is_string($row['source_ref']??null)
            && is_string($row['source_revision']??null)
            && preg_match('/\A[a-f0-9]{64}\z/D',$row['source_revision'])
            && is_int($row['run_id']??null) && $row['run_id'] > 0) {
            $card['message_id']=$messageId;
            $card['conversation_id']=$scope['conversation_id'];
            $card['url']=vp3_agent_activity_chat_href_v1($scope['agent_id'],$scope['conversation_id']).'&message_id='.$messageId;
            $card['source_ref']=$row['source_ref'];
            $card['source_revision']=$row['source_revision'];
            $card['run_id']=$row['run_id'];
        }
        $card['next_step'] = vp3_agent_activity_text_v1($row['next_step'] ?? '', 320);
        $card['question'] = $state === 'needs_owner' ? vp3_agent_activity_text_v1($row['question'] ?? '', 320) : '';
        if ($result !== '' && $result !== $card['detail']) $card['result'] = $result;
        $workStatus = $row['work_status'] ?? null;
        // "Owner marked resolved" is not the same as a verified executor
        // completion. Keep the older card neutral; the source-bound receipt
        // supplies the only confirmed completion label in the Brain briefing.
        if ($state === 'agent_work' && in_array($workStatus, ['queued','running','failed'], true)) $card['work_status'] = $workStatus;
        $card['sources'] = [];
        foreach (array_slice($row['sources'] ?? [], 0, 5) as $source) {
            $specialist = $source['specialist'] ?? '';
            $title = vp3_agent_activity_text_v1($source['title'] ?? '', 120);
            if ($title === '' || !in_array($specialist, ['gmail','tasks','planning','research','media','canvas','curiosity','connected-sites','casual','transcripts','profile'], true)) continue;
            $href = $source['url'] ?? '';
            $entry = ['specialist'=>$specialist, 'title'=>$title, 'url'=>is_string($href) && strlen($href) <= 1900
                && str_starts_with($href, '/') && !str_starts_with($href, '//') && !str_contains($href, '\\') ? $href : ''];
            if ($specialist === 'gmail') {
                $action = $source['source_action'] ?? [];
                if (($action['type'] ?? null) === 'gmail_triage' && is_string($action['item_ref'] ?? null)
                    && preg_match('/\A[a-f0-9]{64}\z/D', $action['item_ref'])
                    && is_int($action['generation'] ?? null) && $action['generation'] > 0) {
                    $entry['source_action'] = ['type'=>'gmail_triage', 'item_ref'=>$action['item_ref'], 'generation'=>$action['generation']];
                }
                $entry['url'] = '';
            }
            $card['sources'][] = $entry;
        }
        $cards[$id] = $card;
        if (count($cards) === 4) break;
    }
    return array_values($cards);
}

/** Summarize current specialist submissions in the same scope as the Orb. */
function vp3_agent_activity_summary_v1(PDO $pdo, array $scope, DateTimeImmutable $now, array $findings, array $specialists=[]): array
{
    $summary = ['updates'=>[]];
    $primary = array_values(array_filter($findings,
        static fn(array $finding):bool=>($finding['routing']['specialist']??null)!=='casual'));
    if ($primary) $findings = $primary;
    foreach (['needs_owner','agent_work','resolved','watching'] as $state) {
        foreach ($findings as $finding) {
            if ($finding['state'] !== $state) continue;
            $summary['overview'] = vp3_agent_activity_text_v1($finding['detail'], 480);
            break 2;
        }
    }
    if ($scope['conversation_id'] < 1) return $summary;
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    require_once __DIR__.'/agent-coordination-work-v1.php';
    require_once __DIR__.'/agent-initiative-v1.php';
    require_once __DIR__.'/agent-top-of-mind-specialists-v1.php';
    $policy = vp3_initiative_policy_v1($scope['user'],$scope['agent_id']);
    $binding = [
        'owner_user_id'=>(int)$scope['user']['id'], 'agent_id'=>$scope['agent_id'],
        'conversation_id'=>$scope['conversation_id'],
    ];
    if (($policy['enabled']??false) === true && ($policy['conversation_id']??null) === $scope['conversation_id']) {
        vp3_initiative_access_v1($pdo,$scope['user'],$policy);
        $binding = $policy;
    }
    $snapshot = vp3_coordination_sources_v1($pdo, $scope['user'], $binding, null, $now);
    if ($binding === $policy) vp3_initiative_access_v1($pdo,$scope['user'],$policy);
    require_once __DIR__.'/agent-unified-specialist-briefings-v1.php';
    require_once __DIR__.'/agent-unified-specialist-followthrough-v1.php';
    $sourceBriefings=vp3_unified_specialist_briefings_v1($snapshot);
    $summary['briefings']=vp3_unified_specialist_followthrough_current_v1(
        $pdo,$scope,$sourceBriefings,$findings);
    $settings = vp3_coordination_rules_v1($pdo,$scope['user'],$scope['agent_id']);
    $latest = [];
    foreach ($snapshot['items'] as $item) {
        $specialist = $item['specialist'];
        $title = vp3_agent_activity_text_v1($item['title'], 160);
        $detail = vp3_agent_activity_text_v1($item['summary'], 320);
        if ($title === '' || $detail === '') continue;
        $priority = match ($item['priority']) {'high'=>2, 'normal'=>1, default=>0};
        if (($latest[$specialist]['priority'] ?? -1) >= $priority) continue;
        $latest[$specialist] = ['priority'=>$priority, 'update'=>['specialist_id'=>$specialist, 'title'=>$title, 'detail'=>$detail,
            'next_step'=>vp3_agent_activity_text_v1($item['next_step']??'',240),
            'routing'=>vp3_coordination_item_route_v1($settings,$item)]];
    }
    // Surface the same verified image job in the shared Brain summary. The
    // source status is read-only and bound to the selected private Chat.
    foreach ($specialists as $row) {
        if (($row['id']??null)!=='image-generator'||(int)($row['run_id']??0)<1
            ||!in_array($row['state']??null,['completed','failed','blocked','interrupted'],true)) continue;
        $detail=vp3_agent_activity_text_v1($row['detail']??'',320);
        if ($detail==='') break;
        $latest['image-generator']=['priority'=>($row['state']==='completed'?1:2),
            'update'=>['specialist_id'=>'image-generator',
                'title'=>$row['state']==='completed'?'Image generation complete':'Image generation needs review',
                'detail'=>$detail,'next_step'=>'Open Image Studio in this Chat to review the job.']];
        break;
    }
    uasort($latest, static fn(array $a,array $b): int => $b['priority'] <=> $a['priority']);
    $summary['updates'] = array_column($latest, 'update');
    $checks = [];
    foreach ($specialists as $status) {
        $id = $status['id']??'';
        $state = $status['state']??'';
        if (isset($latest[$id]) || !in_array($state,['checked','scheduled','queued','working','failed','unavailable','blocked'],true)) continue;
        $detail = vp3_agent_activity_text_v1($status['detail']??'',240);
        $checks[$id] = ['specialist_id'=>$id,'state'=>$state,'detail'=>$detail];
    }
    foreach (vp3_coordination_discovery_summary_v1($snapshot) as $id=>$scan) {
        if (isset($latest[$id]) || isset($checks[$id])) continue;
        $status = vp3_top_of_mind_specialist_scan_v1($id,$scan);
        $checks[$id] = ['specialist_id'=>$id,'state'=>$status['state'],'detail'=>$status['detail']];
    }
    $summary['checks'] = array_values($checks);
    return $summary;
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

function vp3_agent_activity_office_reviews_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    if ($scope['conversation_id'] < 1) return [];
    require_once __DIR__.'/agent-top-of-mind-v1.php';
    require_once __DIR__.'/agent-initiative-task-reports-v1.php';
    $reviews = vp3_initiative_task_activity_reviews_v1($pdo, $scope, $now->getTimestamp());
    $research = vp3_initiative_task_activity_research_v1($pdo, $scope, $now->getTimestamp());
    $items = array_slice(array_merge(array_slice($reviews, 0, $research ? 2 : 3), $research), 0, 3);
    usort($items, static fn(array $a,array $b): int => $b['run_id'] <=> $a['run_id']);
    $cards = [];
    foreach ($items as $review) {
        $label = match ($review['report']['source_kind']) {
            'task_list'=>'List review',
            'reminder'=>'Reminder review',
            'task_research'=>'Task research',
            default=>'Task review',
        };
        $title = $review['title'] !== '' ? $label.': '.$review['title'] : $label;
        $conversation = $scope['conversation_id'];
        $messageId = $review['message_id'];
        $chat = vp3_agent_activity_chat_href_v1($scope['agent_id'],$conversation);
        $actions = [];
        if ($messageId > 0) {
            $actions[] = ['kind'=>'chat','label'=>'View in Chat','conversation_id'=>$conversation,
                'message_id'=>$messageId,'href'=>$chat.'&message_id='.$messageId];
        } elseif (preg_match('/\A(task|task-list|reminder):([1-9][0-9]{0,15})\z/D',$review['source_ref'] ?? '',$match)) {
            $id = (int)$match[2];
            $actions[] = match($match[1]) {
                'task-list'=>['kind'=>'task_notes','label'=>'Open list','list_id'=>$id,'href'=>$chat],
                'reminder'=>['kind'=>'task_notes','label'=>'Open reminders','reminder_id'=>$id,'view'=>'all','href'=>$chat],
                default=>['kind'=>'url','label'=>'Review task','href'=>url('/account.php#agent-brain')],
            };
        }
        $card = vp3_agent_activity_card_v1('office-review:'.$review['run_id'], 'office_review', $title,
            $review['report']['summary'], $actions, 500);
        foreach (['next_step','followup_question'] as $field) {
            if (isset($review[$field])) $card[$field] = vp3_agent_activity_text_v1($review[$field], 500);
        }
        $cards[] = $card;
    }
    return $cards;
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

function vp3_agent_activity_recording_v1(PDO $pdo,array $scope,DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-transcript-focus-v1.php';
    $recording=vp3_transcript_focus_view_v1($pdo,$scope['user'],$scope['principal'],$scope['conversation_id'],$now);
    if($recording===null)return [];
    return [vp3_agent_activity_card_v1('transcript:'.$recording['session_id'],'transcript',$recording['title'],
        'Current recording source for this Chat.',[['kind'=>'url','label'=>'Open recording','href'=>$recording['url']]])];
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

/** Use the same overlap and current evidence as the Planning coordinator. */
function vp3_agent_activity_planning_cards_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    require_once __DIR__.'/agent-coordination-finding-feedback-v1.php';
    $scope['verification_only'] = true;
    $user = $scope['user'];
    $agent = $scope['agent_id'];
    $binding = ['agent_id'=>$agent, 'owner_user_id'=>(int)$user['id'], 'conversation_id'=>$scope['conversation_id']];
    $source = vp3_initiative_in_scope_v1($user, $agent,
        static fn(): array => vp3_coordination_planning_sources_v1($pdo, $scope, null, $now));
    $sections = [
        'calendar'=>['items'=>[], 'coverage'=>$source['coverage_by_source']['calendar'] ?? 'unavailable'],
        'appointments'=>['items'=>[], 'coverage'=>$source['coverage_by_source']['scheduling'] ?? 'unavailable'],
    ];
    foreach ($source['items'] as $row) {
        $appointments = str_starts_with($row['ref'], 'planning:scheduling:');
        $section = $appointments ? 'appointments' : 'calendar';
        if (count($sections[$section]['items']) === 8) continue;
        $kind = $appointments ? 'appointment' : 'calendar';
        $label = $appointments ? 'Open appointment' : 'Open event';
        $card = vp3_agent_activity_card_v1($row['ref'], $kind, $row['title'], $row['summary'],
            [['kind'=>'url', 'label'=>$label, 'href'=>$row['url']]], 600);
        if ($row['next_step'] !== '') $card['next_step'] = $row['next_step'];
        if ($row['question'] !== '') $card['followup_question'] = $row['question'];
        if (isset($row['trigger']) && $binding['conversation_id'] > 0) {
            try {
                $feedback = vp3_coordination_finding_feedback_v1($pdo, $user, $binding, $row);
                if ($feedback !== null) $card['engagement'] = $feedback;
            } catch (PDOException $error) {
                if ($pdo->inTransaction()) throw $error;
            }
        }
        $sections[$section]['items'][] = $card;
    }
    return $sections;
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

/** A saved draft and a successful preview are distinct from workflow acceptance. */
function vp3_agent_activity_canvas_outcome_v1(PDO $pdo, array $scope, array $canvas): ?string
{
    $revision = (int)$canvas['draft_revision'];
    $runId = (int)$canvas['run_id'];
    if ($runId < 1) return 'Draft version '.$revision.' is ready for review.';
    $owner = (int)$scope['user']['id'];
    $agent = $scope['agent_id'];
    $query = $pdo->prepare("SELECT r.goal,a.result_json AS action_result,c.result_json AS receipt_result
        FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        JOIN agent_workflow_receipts c ON c.run_id=r.id AND c.action_id=a.id AND c.owner_user_id=r.owner_user_id
            AND c.attempt_no=a.attempt_count AND c.executor='cloud' AND c.status='completed'
        WHERE r.id=? AND r.owner_user_id=? AND COALESCE(r.agent_id,0)=?
            AND r.source_kind='coding_canvas' AND r.execution_target='cloud' AND r.status='completed'
            AND r.capability_key IN ('coding.build','coding.repair') AND a.capability_key=r.capability_key
            AND a.sequence_no=1 AND a.status='completed'
        ORDER BY c.id DESC LIMIT 1");
    $query->bindValue(1,$runId,PDO::PARAM_INT);
    $query->bindValue(2,$owner,PDO::PARAM_INT);
    $query->bindValue(3,$agent,PDO::PARAM_INT);
    $query->execute();
    $proof = $query->fetch(PDO::FETCH_ASSOC);
    if (!$proof || !is_string($proof['receipt_result']) || $proof['action_result'] !== $proof['receipt_result']) return null;
    $goal = json_decode((string)$proof['goal'],true);
    $receipt = json_decode($proof['receipt_result'],true);
    $jobChat=$goal['conversation_id']??null;
    if (($goal['canvas_id'] ?? null) !== $canvas['id'] || !is_int($jobChat) || $jobChat<1
        || ($jobChat!==(int)$canvas['conversation_id']&&!vp3_agent_chat_conversation_v380($pdo,$jobChat,$owner,$scope['agent']))
        || ($receipt['canvas_id'] ?? null) !== $canvas['id'] || (int)($receipt['canvas_revision'] ?? 0) !== $revision) return null;
    try {
        $saved = vp3_coding_canvas_read_v1($pdo,$scope['user'],$agent,$canvas['id'],$revision);
    } catch (PDOException $error) {
        throw $error;
    } catch (RuntimeException | JsonException | InvalidArgumentException) {
        return null;
    }
    if ($saved['canvas']['conversation_id'] !== (int)$canvas['conversation_id'] || $saved['canvas']['draft_revision'] !== $revision) return null;
    if (!$saved['revision']['test_passed']) return 'Draft version '.$revision.' saved. Preview and workflow checks pending.';
    $query = $pdo->prepare("SELECT metadata_json FROM agent_workflow_events
        WHERE run_id=? AND owner_user_id=? AND event_type='email_canvas_fixture_passed' AND actor_kind='user'
            AND from_status='completed' AND to_status='completed' ORDER BY id DESC LIMIT 1");
    $query->execute([$runId,$owner]);
    $fixture = json_decode((string)$query->fetchColumn(),true);
    $fixturePassed = ($fixture['canvas_id'] ?? null)===$canvas['id'] && ($fixture['revision'] ?? null)===$revision
        && ($fixture['state_revision'] ?? null)===$saved['state']['revision'];
    return 'Preview loaded for draft version '.$revision.'. '
        .($fixturePassed ? 'Sample deadline check passed.' : 'Workflow checks pending.');
}

function vp3_agent_activity_canvases_v1(PDO $pdo, array $scope): array
{
    vp3_coding_canvas_authority_v1($pdo, $scope['user'], $scope['agent_id']);
    $items = vp3_agent_activity_coding_jobs_v1($pdo, $scope);
    $query = $pdo->prepare("SELECT c.id,c.title,c.conversation_id,c.draft_revision,v.run_id FROM vp3_coding_canvases_v1 c
        JOIN artist_transcript_folders_v177 f ON f.id=c.folder_id AND f.created_by_user_id=c.owner_user_id
        JOIN vp3_coding_canvas_versions_v1 v ON v.canvas_id=c.id AND v.revision=c.draft_revision
        JOIN knowledge_items k ON k.id=v.code_item_id AND k.folder_id=c.folder_id AND k.created_by_user_id=c.owner_user_id
            AND k.knowledge_scope='personal' AND k.visibility='private' AND k.is_published=0
        WHERE c.owner_user_id=? AND c.agent_id=? AND c.draft_revision IS NOT NULL
        ORDER BY c.updated_at DESC,c.id ASC LIMIT 3");
    $query->execute([(int)$scope['user']['id'], $scope['agent_id']]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (count($items) === 3) break;
        $detail = vp3_agent_activity_canvas_outcome_v1($pdo,$scope,$row);
        if ($detail === null) continue;
        $cid = (int)$row['conversation_id'];
        if (!vp3_agent_chat_conversation_v380($pdo, $cid, (int)$scope['user']['id'], $scope['agent'])) {
            if ((int)$row['run_id'] > 0) continue;
            $cid = $scope['conversation_id'];
        }
        $items[] = vp3_agent_activity_card_v1('canvas:'.$row['id'], 'canvas', $row['title'], $detail, [
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
    $attention = $cid === $scope['conversation_id'] ? $top
        : vp3_top_of_mind_work_v1($pdo, $scope['user'], $scope['agent_id'], $scope['conversation_id'], true,
            includeEngagements:true, includeTranscriptReports:true);
    $status = ($attention['owner_user_id'] ?? null) === (int)$scope['user']['id']
        && ($attention['agent_id'] ?? null) === $scope['agent_id']
        && ($attention['conversation_id'] ?? null) === $scope['conversation_id']
        ? array_intersect_key($attention, ['specialists'=>true, 'coordination'=>true]) : [];
    if ($cid < 1 || ($top['state'] ?? '') !== 'ready') return ['items'=>[], 'coverage'=>$coverage, 'status'=>$status];
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
        if (isset($item['handoff'])) $card['handoff'] = $item['handoff'];
        $items[] = $card + ['state'=>$state, 'owner_state'=>$ownerState];
        if (count($items) === 3) break;
    }
    if(count($items)<3){
        require_once __DIR__.'/agent-initiative-opportunity-view-v1.php';
        try{$opportunities=vp3_initiative_opportunities_v1($pdo,$scope['user'],$scope['agent_id']);}
        catch(Throwable $error){$opportunities=[];$coverage='limited';}
        foreach($opportunities as $opportunity){
            $detail=match($opportunity['state']){
                'queued'=>'This assessment queued a private next step.',
                'existing'=>'This assessment matched an existing private assignment.',
                default=>'An idea from the latest assessment.',
            };
            $card=vp3_agent_activity_card_v1('opportunity:'.$opportunity['id'],'suggestion',
                $opportunity['objective'],$detail.' '.$opportunity['rationale'],[
                    ['kind'=>'chat','label'=>'Open Chat','conversation_id'=>$opportunity['conversation_id'],
                        'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'],$opportunity['conversation_id'])],
                ])+['state'=>$opportunity['state'],'owner_state'=>''];
            if(isset($opportunity['handoff']))$card['handoff']=$opportunity['handoff'];
            $items[]=$card;
            if(count($items)===3)break;
        }
    }
    return ['items'=>$items, 'coverage'=>$coverage, 'status'=>$status];
}

function vp3_agent_activity_canvas_suggestions_v1(PDO $pdo,array $scope): array
{
    require_once __DIR__.'/agent-initiative-opportunity-view-v1.php';
    $agent=$scope['agent_id'];$cid=$scope['conversation_id'];
    $review=vp3_initiative_canvas_review_v1($pdo,$scope['user'],$scope['principal'],$agent,$cid);
    $items=[];
    foreach($review['items'] as $item){
        $message=$item['message_id']??null;
        $actions=$message===null?[]:[['kind'=>'chat','label'=>$item['state']==='draft_ready'?'Open draft in Chat':'Review in Chat','conversation_id'=>$cid,
            'message_id'=>$message,'href'=>vp3_agent_activity_chat_href_v1($agent,$cid).'&message_id='.$message]];
        $items[]=vp3_agent_activity_card_v1($item['id'],'canvas_suggestion',$item['title'],$item['detail'],$actions,1200)
            +array_intersect_key($item,['state'=>true,'sources'=>true,'handoff'=>true]);
    }
    return ['items'=>$items,'coverage'=>$review['coverage']];
}

function vp3_agent_activity_gmail_review_v1(PDO $pdo, array $scope, ?DateTimeImmutable $now = null, bool $reportOnly = false): array
{
    $items = [];
    $monitor = null;
    if (table_exists('agent_connection_gmail_inbox_v1')) {
        require_once __DIR__.'/agent-connection-gmail-inbox-status-v1.php';
        $monitor = agent_connection_gmail_inbox_background_status_v1($pdo, $scope['user'], $scope['agent_id'], $now);
    }
    if ($monitor !== null) {
        $actions = [['kind'=>'url','label'=>'Gmail settings','href'=>url('/connections.php?app=gmail#gmail-inbox-review')]];
        $latest = $monitor['latest'];
        unset($monitor['latest']);
        if (!$reportOnly && $latest !== null) {
            array_unshift($actions, [
                'kind'=>'chat','label'=>'View latest update','conversation_id'=>$latest['conversation_id'],
                'message_id'=>$latest['message_id'],
                'href'=>vp3_agent_activity_chat_href_v1($latest['agent_id'],$latest['conversation_id']).'&message_id='.$latest['message_id'],
            ]);
        }
        $items[] = vp3_agent_activity_card_v1('gmail:inbox-monitor', 'gmail_inbox_monitor',
            'Gmail inbox monitoring', '', $actions) + ['monitor'=>$monitor];
    }
    if ($reportOnly || !table_exists('agent_connection_gmail_inbox_initial_v1')) return $items;
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    $view = agent_connection_gmail_inbox_initial_view_v1($pdo, $scope['user']);
    $detail = match ($view['state'] ?? '') {
        'needs_start'=>'Choose a first review window in Connections.',
        'queued'=>'Queued',
        'working'=>($view['stage'] ?? '') === 'summary' ? 'Working · Preparing the AI summary' : 'Working · Checking Gmail headers',
        'ready'=>'Ready to read in Chat',
        'failed'=>'Failed · Open Connections to retry',
        default=>'',
    };
    if ($detail === '') return $items;
    if ($view['state'] === 'ready') {
        $delivery = agent_connection_gmail_inbox_chat_delivery_current_v1($pdo, $scope['user']);
        $cid = $delivery['conversation_id'] ?? $scope['conversation_id'];
        $href = vp3_agent_activity_chat_href_v1($delivery['agent_id'] ?? $scope['agent_id'], $cid);
        $action = ['kind'=>'url', 'label'=>'View in Chat', 'href'=>$href];
        if ($delivery) $action['href'] .= '&message_id='.$delivery['message_id'];
        if ($cid > 0 && ($delivery['agent_id'] ?? $scope['agent_id']) === $scope['agent_id']) {
            $action['kind'] = 'chat';
            $action['conversation_id'] = $cid;
            if ($delivery) $action['message_id'] = $delivery['message_id'];
        }
    } else {
        $action = ['kind'=>'url', 'label'=>'Open Connections', 'href'=>url('/connections.php?app=gmail#gmail-inbox-review')];
    }
    $items[] = vp3_agent_activity_card_v1('gmail:first-review', 'gmail_inbox_review', 'Gmail Inbox Review', $detail, [$action])
        + ['review_state'=>$view['state']]
        + ($view['state'] === 'working' ? ['review_lease_until'=>$view['next_attempt_at'] ?? null] : []);
    return $items;
}

/** Live watches span the selected Agent's private Chats and the current Gmail grant. */
function vp3_agent_activity_gmail_watches_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    if (!table_exists('agent_connection_email_followups_v1') || !table_exists('agent_connections_v1')) return [];
    require_once __DIR__.'/agent-connection-email-followup-v1.php';
    $owner = (int)$scope['user']['id'];
    $connection = agent_connection_email_followup_live_connection_v1($pdo,$owner);
    if (!$connection) return [];
    [$where, $params] = vp3_agent_chat_scope_sql_v380($scope['agent'], 'c');
    $query = $pdo->prepare("SELECT f.operation_key,f.conversation_id,f.origin_user_message_id,f.recipient_email,f.sent_message_id,f.expires_at
        FROM agent_connection_email_followups_v1 f JOIN chat_conversations c ON c.id=f.conversation_id
        WHERE c.user_id=f.owner_user_id AND $where AND f.owner_user_id=? AND f.agent_id=? AND f.connection_id=?
        AND f.authorization_key=? AND f.account_email=? AND f.status='waiting' AND f.next_check_at IS NOT NULL AND f.expires_at>?
        AND (f.origin_user_message_id IS NULL OR EXISTS (SELECT 1 FROM chat_messages m WHERE m.id=f.origin_user_message_id
            AND m.conversation_id=f.conversation_id AND m.user_id=f.owner_user_id AND m.role='user'))
        ORDER BY f.expires_at ASC,f.operation_key ASC LIMIT 3");
    $query->execute([...$params,$owner,$scope['agent_id'],(int)$connection['id'],$connection['authorization_key'],
        agent_connection_gmail_address_v1($connection['account_email']),$now->format('Y-m-d H:i:s')]);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $conversation = (int)$row['conversation_id'];
        $origin = (int)$row['origin_user_message_id'];
        $sender = agent_connection_gmail_address_v1($row['recipient_email']);
        $items[] = vp3_agent_activity_card_v1('gmail:watch:'.hash('sha256',$row['operation_key']),'gmail_watch',
            $row['sent_message_id'] === '' ? 'Watching for new Gmail' : 'Waiting for a Gmail reply',
            ($sender === '' ? '' : $sender.' · ').'Until '.substr($row['expires_at'],0,10).' UTC',[
                ['kind'=>'chat','label'=>'Open watched Chat','conversation_id'=>$conversation,
                    'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'],$conversation)] + ($origin > 0 ? ['message_id'=>$origin] : []),
            ]);
    }
    return $items;
}

/** Show live watches alongside the same verified manual and automatic send receipts kept in Chat. */
function vp3_agent_activity_gmail_outcomes_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    $watches = vp3_agent_activity_gmail_watches_v1($pdo,$scope,$now);
    if (!table_exists('agent_connection_email_drafts_v1')) return $watches;
    [$where, $params] = vp3_agent_chat_scope_sql_v380($scope['agent'], 'c');
    $context = "CASE WHEN JSON_VALID(m.context_json) THEN m.context_json ELSE '{}' END";
    $source = "JSON_EXTRACT($context,'$.source')";
    $mode = "JSON_EXTRACT($context,'$.email_outcome.mode')";
    $draft = "JSON_EXTRACT($context,'$.email_outcome.draft_id')";
    $providerId = "JSON_EXTRACT($context,'$.email_outcome.message_id')";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $source = "JSON_UNQUOTE($source)";
        $mode = "JSON_UNQUOTE($mode)";
        $draft = "JSON_UNQUOTE($draft)";
        $providerId = "JSON_UNQUOTE($providerId)";
    }
    $query = $pdo->prepare("SELECT m.id,m.conversation_id FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id
        JOIN agent_connection_email_drafts_v1 d ON d.id=$draft AND d.owner_user_id=c.user_id
            AND d.agent_id=? AND d.conversation_id=c.id AND d.status='sent' AND d.sent_message_id=$providerId
        WHERE c.user_id=? AND $where AND m.role='assistant' AND m.created_at>=?
        AND $source='agent_connection_email' AND COALESCE($mode,'manual')=?
        ORDER BY m.id DESC LIMIT 3");
    $latest = [];
    $other = [];
    foreach (['manual','auto_reply'] as $kind) {
        $query->execute([$scope['agent_id'],(int)$scope['user']['id'],...$params,$now->modify('-7 days')->format('Y-m-d H:i:s'),$kind]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $index=>$row) {
            $id = (int)$row['id'];
            $conversation = (int)$row['conversation_id'];
            $card = vp3_agent_activity_card_v1('gmail:sent:'.$id,'gmail_outcome',
                $kind === 'auto_reply' ? 'Gmail reply sent' : 'Gmail email sent',
                $kind === 'auto_reply' ? 'Gmail confirmed the automatic reply.' : 'Gmail confirmed the reviewed send.', [
                    ['kind'=>'chat','label'=>'View in Chat','conversation_id'=>$conversation,'message_id'=>$id,
                        'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'],$conversation)],
                ]);
            if ($index === 0) $latest[$id] = $card;
            else $other[$id] = $card;
        }
    }
    krsort($latest,SORT_NUMERIC);
    krsort($other,SORT_NUMERIC);
    // Keep the compact feed useful when both watch activity and sends are present.
    return array_slice(array_merge(array_slice($watches,0,1),array_values($latest),array_slice($watches,1),array_values($other)),0,3);
}

/** Bounded owner projections only. Reading this feed never starts or advances work. */
function vp3_agent_activity_marketing_v1(PDO $pdo, array $scope, DateTimeImmutable $now): array
{
    if ($scope['conversation_id'] < 1) return ['items'=>[], 'coverage'=>'unconfigured'];
    require_once __DIR__.'/agent-coordination-sources-v1.php';
    require_once __DIR__.'/agent-coordination-marketing-v1.php';
    $snapshot = vp3_coordination_marketing_recommendations_v1($pdo, $scope, null, $now);
    $items = [];
    foreach ($snapshot['items'] as $item) {
        $items[] = vp3_agent_activity_card_v1($item['ref'], 'marketing_recommendation', $item['title'], $item['summary'], [
            ['kind'=>'url', 'label'=>'Review campaign', 'href'=>$item['url']],
        ], 700) + ['next_step'=>vp3_agent_activity_text_v1($item['next_step']??'',320),
            'followup_question'=>vp3_agent_activity_text_v1($item['question']??'',320)];
    }
    return ['items'=>$items, 'coverage'=>$snapshot['coverage']];
}

/** The Marketing Hub links the same exact sources used by briefs and follow-through. */
function vp3_agent_activity_product_marketing_v1(PDO $pdo,array $scope,DateTimeImmutable $now): array
{
    $result=['contract'=>'vp3.product-marketing-summary.v1','coverage'=>'unconfigured','items'=>[]];
    if($scope['conversation_id']<1)return $result;
    require_once __DIR__.'/agent-coordination-product-marketing-v1.php';
    $snapshot=vp3_coordination_product_marketing_sources_v1($pdo,$scope,null,$now);
    $result['coverage']=in_array($snapshot['coverage']??null,['ready','limited','unavailable','unconfigured'],true)
        ?$snapshot['coverage']:'unavailable';
    if(!in_array($result['coverage'],['ready','limited'],true))return $result;
    $periods=false;
    foreach($snapshot['items']??[] as $item){
        $kind=vp3_product_marketing_ref_kind_v1($item['ref']??null);
        if($kind===null||!is_string($item['revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$item['revision'])){
            $result['coverage']='limited';continue;
        }
        $expected=$kind==='plan'?vp3_product_marketing_plan_href_v1($scope['agent_id'],$scope['conversation_id'],(int)substr($item['ref'],23))
            :vp3_profile_commerce_followthrough_href_v1($scope['agent_id'],$scope['conversation_id'],$item['ref']);
        if(($item['url']??null)!==$expected){$result['coverage']='limited';continue;}
        $row=['ref'=>$item['ref'],'revision'=>$item['revision'],'kind'=>$kind,'url'=>$expected];
        foreach(['title'=>190,'summary'=>1200,'next_step'=>320,'status'=>40] as $key=>$limit)
            $row[$key]=vp3_agent_activity_text_v1($item[$key]??'',$limit);
        if($row['title']===''||$row['summary']===''){$result['coverage']='limited';continue;}
        $result['items'][]=$row;$periods=$periods||$kind==='followthrough';
        if(count($result['items'])>=18){if(count($snapshot['items'])>18)$result['coverage']='limited';break;}
    }
    // Native plan summaries remain readable without Profile permission. Any
    // period facts must retain Profile authority through the final reread.
    $own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{vp3_profile_analytics_authority_current_v1($pdo,$scope,$periods);if($own)$pdo->commit();}
    catch(Throwable $error){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $error;}
    return $result;
}

function vp3_agent_activity_hub_v1(PDO $pdo, array $user, int $agentId, int $conversationId = 0, string $view = '',
    string $collection = '', ?int $cursor = null, int $listId = 0): array
{
    $scope = vp3_agent_activity_scope_v1($pdo, $user, $agentId, $conversationId);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $gmailOnly = $view === 'gmail';
    $mediaOnly = $view === 'media';
    $curiosityOnly = $view === 'curiosity';
    $transcriptsOnly = $view === 'transcripts';
    $codingOnly = $view === 'coding';
    $marketingOnly = $view === 'marketing';
    $profileOnly = $view === 'profile';
    if ($transcriptsOnly && !has_permission('artist_listening.access',$scope['user'])) {
        throw new RuntimeException('Transcription access changed.',403);
    }
    $readers = [
        'reminders'=>['Reminders', static fn() => vp3_agent_activity_reminders_v1($pdo, $scope, $now)],
        'tasks'=>['Tasks', static fn() => vp3_agent_activity_tasks_v1($pdo, $scope)],
        'lists'=>['Task lists', static fn() => vp3_agent_activity_lists_v1($pdo, $scope)],
        'history'=>['Recent Chats', static fn() => vp3_agent_activity_history_v1($pdo, $scope)],
        'recording'=>['Recording in this Chat', static fn() => vp3_agent_activity_recording_v1($pdo, $scope, $now)],
        'calendar'=>['Upcoming events', static fn() => vp3_agent_activity_calendar_v1($pdo, $scope, $now)],
        'missions'=>['Missions', static fn() => vp3_agent_activity_missions_v1($pdo, $scope, $now)],
        'canvases'=>['Canvas work', static fn() => vp3_agent_activity_canvases_v1($pdo, $scope)],
        'canvas_suggestions'=>['App Suggestions', static fn() => vp3_agent_activity_canvas_suggestions_v1($pdo, $scope)],
        'suggestions'=>['Agent suggestions', static fn() => vp3_agent_activity_suggestions_v1($pdo, $scope)],
        'gmail_inbox'=>['Gmail Inbox Review', static fn() => vp3_agent_activity_gmail_review_v1($pdo, $scope, $now, $gmailOnly)],
        'gmail_outcomes'=>['Gmail', static fn() => vp3_agent_activity_gmail_outcomes_v1($pdo, $scope, $now)],
    ];
    if ($gmailOnly) $readers = ['gmail_inbox'=>$readers['gmail_inbox']];
    if ($mediaOnly || $curiosityOnly || $transcriptsOnly || $profileOnly || in_array($view, ['location','operator'], true)) $readers = [];
    if ($codingOnly) $readers = array_intersect_key($readers, array_flip(['canvases','canvas_suggestions']));
    if ($marketingOnly) $readers = ['marketing'=>['Campaign recommendations', static fn() => vp3_agent_activity_marketing_v1($pdo,$scope,$now)]];
    if ($view === 'planning') {
        $planning = null;
        $readers = [];
        foreach (['calendar'=>'Upcoming events','appointments'=>'Appointments'] as $id=>$label) {
            $readers[$id] = [$label, static function() use ($pdo, $scope, $now, $id, &$planning): array {
                $planning ??= vp3_agent_activity_planning_cards_v1($pdo, $scope, $now);
                return $planning[$id];
            }];
        }
    }
    $inventory = [];
    if ($view === 'tasks') {
        $reviews = ['office_reviews'=>['Completed reviews', static fn() => vp3_agent_activity_office_reviews_v1($pdo, $scope, $now)]];
        if ($collection === '') {
            $readers = $reviews + array_intersect_key($readers, array_flip(['reminders','tasks','lists']));
        } elseif ($collection === 'reviews') {
            $readers = $reviews;
        } else {
            require_once __DIR__.'/agent-activity-task-inventory-v1.php';
            $list = $listId > 0 ? vp3_agent_activity_inventory_list_v1($pdo, $scope, $listId) : null;
            $labels = ['reminders'=>'Reminders','tasks'=>'Tasks','lists'=>'Task lists','list_items'=>$list['name'] ?? ''];
            $inventory = $collection === 'all' ? ['reminders','tasks','lists'] : [$collection];
            $readers = $collection === 'all' ? $reviews : [];
            foreach ($inventory as $id) {
                $readers[$id] = [vp3_agent_activity_text_v1($labels[$id], 120),
                    static fn() => vp3_agent_activity_inventory_page_v1($pdo, $scope, $id, $cursor, $list, $now)];
            }
        }
    }
    $sections = [];
    $coverage = [];
    $attention = [];
    $pages = [];
    foreach ($readers as $id => [$label, $read]) {
        $paged = in_array($id, $inventory, true);
        if ($paged) $pages[$id] = ['next_cursor'=>null];
        try {
            $result = $read();
            $hasCoverage = $view === 'planning' || in_array($id, ['missions','suggestions','canvas_suggestions','marketing'], true);
            $items = $hasCoverage || $paged ? $result['items'] : $result;
            $coverage[$id] = $hasCoverage ? $result['coverage'] : 'ready';
            if ($paged) $pages[$id]['next_cursor'] = $result['next_cursor'];
            if ($id === 'suggestions') $attention = $result['status'];
        } catch (Throwable $error) {
            $items = [];
            $coverage[$id] = $error->getCode() === 403 ? 'forbidden' : 'unavailable';
        }
        if($id==='recording'&&!$items){unset($coverage[$id]);continue;}
        $limit = $id === 'canvas_suggestions' ? 7 : (in_array($view,['planning','marketing'],true) ? 8 : 3);
        $sections[] = ['id'=>$id, 'label'=>$label, 'items'=>$paged ? $items : array_slice($items, 0, $limit)];
    }
    $nativeListening=[];
    if ($view === '' || $mediaOnly) {
        require_once __DIR__.'/agent-activity-media-v1.php';
        $media = vp3_agent_activity_media_v1($pdo, $scope['user'], $agentId, includeYoutube:false);
        foreach ($media['sections'] as $section) {
            $section['items'] = array_slice($section['items'], 0, 3);
            $sections[] = $section;
            if($section['id']==='songs')$nativeListening=$section['items'];
        }
        $coverage += $media['coverage'];
    }
    $fresh = vp3_agent_activity_scope_v1($pdo, $scope['user'], $agentId, $conversationId);
    $currentChat = $conversationId > 0
        ? vp3_agent_chat_conversation_v380($pdo, $conversationId, (int)$fresh['user']['id'], $fresh['agent']) : null;
    if ($conversationId > 0 && !$currentChat) throw new RuntimeException('Conversation access changed.', 403);
    if ($view === 'planning' || $gmailOnly || $mediaOnly || $transcriptsOnly || $codingOnly || $marketingOnly || ($view === 'tasks' && in_array($collection,['','reviews'],true))) {
        require_once __DIR__.'/agent-top-of-mind-v1.php';
        try {
            $work = vp3_top_of_mind_work_v1($pdo, $fresh['user'], $agentId, $conversationId, true,
                includeEngagements:true, includeTranscriptReports:$transcriptsOnly);
            $attention['specialists'] = array_values(array_filter($work['specialists'], static fn(array $row): bool => $row['id'] === $view));
        } catch (Throwable $error) {
            if (in_array($error->getCode(),[403,404],true)) throw $error;
            $coverage['specialist_status'] = 'unavailable';
        }
    }
    $coordination = [];
    if ($view === 'location') {
        require_once __DIR__.'/agent-top-of-mind-specialists-v1.php';
        $location = vp3_location_specialist_status_v1($pdo,$fresh['user'],$fresh['principal'],$agentId,$conversationId,$now->getTimestamp());
        if ($location === null) throw new RuntimeException('Location Specialist is unavailable.',403);
        $attention['specialists'] = [$location];
    }
    if ($view === 'operator') {
        require_once __DIR__.'/agent-top-of-mind-specialists-v1.php';
        $operator = vp3_operator_specialist_status_v1($pdo,$fresh['user'],$fresh['principal'],$agentId,$conversationId,$now->getTimestamp());
        if ($operator === null) throw new RuntimeException('Operator Specialist is unavailable.',403);
        $attention['specialists'] = [$operator];
        require_once __DIR__.'/agent-operator-history-v1.php';
        $coordination['operator_history'] = vp3_operator_history_v1($pdo,$fresh['user'],$agentId,$conversationId);
        require_once __DIR__.'/agent-operator-followups-v1.php';
        $coordination['operator_followups'] = vp3_operator_followup_queue_v1($pdo,$fresh,$now);
        require_once __DIR__.'/agent-operator-calendar-v1.php';
        $coordination['operator_calendar'] = vp3_operator_calendar_upcoming_v1($pdo,$fresh['user'],$agentId,$conversationId,$now->getTimestamp());
    }
    if ($profileOnly) {
        require_once __DIR__.'/agent-profile-specialist-v1.php';
        $profileSnapshot = ['items'=>[], 'coverage'=>['profile'=>'unconfigured']];
        if ($conversationId > 0) {
            $binding = ['owner_user_id'=>(int)$fresh['user']['id'], 'agent_id'=>$agentId,
                'conversation_id'=>$conversationId, 'enabled'=>false];
            try {
                require_once __DIR__.'/agent-coordination-sources-v1.php';
                $profileSnapshot = vp3_coordination_sources_v1($pdo,$fresh['user'],$binding,null,$now,true,'profile');
            } catch (Throwable $error) {
                // Private Chat and Profile authority are checked again below. Source failure
                // must not turn a previously populated report into an all-clear.
                $profileSnapshot = ['items'=>[], 'coverage'=>['profile'=>'unavailable']];
            }
        }
        $coordination['profile_report'] = vp3_profile_specialist_report_v1($profileSnapshot);
        $coverage['profile_report'] = $coordination['profile_report']['coverage'];
        $profileState = $coverage['profile_report'];
        $attention['specialists'] = [['id'=>'profile', 'conversation_id'=>$conversationId,
            'state'=>match ($profileState) {
                'unavailable'=>'unavailable', 'unconfigured'=>'blocked',
                default=>$coordination['profile_report']['findings'] ? 'ready' : 'checked',
            },
            'source_coverage'=>$profileState, 'progress'=>null,
            'detail'=>match ($profileState) {
                'limited'=>'Some Profile sources could be checked. The report may be incomplete.',
                'unavailable'=>'Profile sources could not be verified.',
                'unconfigured'=>$conversationId > 0 ? 'Profile activity is not configured or available in this Chat.' : 'Save this Chat to read Profile activity.',
                default=>'Current Profile activity is available in the report.',
            }]];
        // The source transaction has ended: reread both private Chat authority
        // and the current Profile grants before releasing any saved-Chat report.
        // A permission failure must escape as 403, including empty projections.
        if ($conversationId > 0) {
            require_once __DIR__.'/agent-coordination-profile-v1.php';
            $fresh = vp3_coordination_profile_authority_v1($pdo,$fresh,$binding);
        } else {
            $fresh = vp3_agent_activity_scope_v1($pdo,$fresh['user'],$agentId,$conversationId);
        }
    }
    if ($marketingOnly) {
        try {
            require_once __DIR__.'/agent-marketing-review-v1.php';
            $coordination['marketing'] = vp3_marketing_review_v1($pdo,$fresh,$now,includeArtifacts:true,includeChoices:true);
        } catch (Throwable $error) {
            $coordination['marketing'] = ['coverage'=>'unavailable','campaigns'=>[]];
        }
        try{$coordination['product_marketing']=vp3_agent_activity_product_marketing_v1($pdo,$fresh,$now);}
        catch(Throwable $error){
            if(in_array($error->getCode(),[403,404],true))throw $error;
            $coordination['product_marketing']=['contract'=>'vp3.product-marketing-summary.v1','coverage'=>'unavailable','items'=>[]];
        }
        $coverage['product_marketing']=$coordination['product_marketing']['coverage'];
    }
    if ($view === '' || $mediaOnly) {
        try {
            $coordination['youtube_activity'] = vp3_agent_activity_youtube_playlist_v1($pdo,$fresh['user'],$agentId,
                $conversationId,$now->getTimestamp());
        } catch (RuntimeException $error) {
            $coordination['youtube_activity'] = ['status'=>'unavailable','searches'=>[],'played'=>[]];
        }
    }
    if ($view === '') {
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        try {
            $coordination['recent_listening'] = vp3_media_recent_listening_v1($pdo,$fresh,$now,$nativeListening);
        } catch (RuntimeException) {
            $coverage['recent_listening'] = 'unavailable';
        }
    }
    if ($mediaOnly) {
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        try {
            $coordination['media_report'] = vp3_initiative_media_report_v1($pdo, $fresh, $now, $nativeListening);
        } catch (Throwable $error) {
            $coverage['media_report'] = $error->getCode() === 403 ? 'forbidden' : 'unavailable';
        }
        try {
            $coordination['media_delivery'] = vp3_agent_activity_media_delivery_v1($pdo,$fresh);
        } catch (RuntimeException $error) {
            $coordination['media_delivery'] = ['gate'=>'verification_unavailable'];
        }
    }
    if ($curiosityOnly || $view === '') {
        require_once __DIR__.'/agent-top-of-mind-specialists-v1.php';
        $attention['specialists'][] = vp3_top_of_mind_curiosity_status_v1($pdo,$fresh['user'],$agentId,$conversationId);
    }
    if ($curiosityOnly) {
        try {
            require_once __DIR__.'/agent-curiosity-v1.php';
            $coordination['curiosity_report'] = vp3_curiosity_report_v1($pdo,$fresh['user'],$agentId,$conversationId,$now->getTimestamp());
            $coverage['curiosity_report'] = $coordination['curiosity_report']['source_coverage'];
        } catch (Throwable $error) {
            if (in_array($error->getCode(),[403,404],true)) throw $error;
            $coverage['curiosity_report'] = 'unavailable';
        }
        if ($conversationId>0 && isset($coordination['curiosity_report'])) {
            try {
                require_once __DIR__.'/agent-coordination-schedule-v1.php';
                $policy=vp3_initiative_policy_v1($fresh['user'],$agentId);
                if(($policy['conversation_id']??null)===$conversationId){
                    $checkpoint=vp3_coordination_scan_checkpoint_v1($fresh['user'],$policy);
                    $review=$checkpoint['specialists']['curiosity']??null;
                    if(is_array($review)){
                        $coordination['curiosity_report']['review']=['at'=>$checkpoint['at'],
                            'gate'=>$review['offer_gate']??null,'queued'=>($review['queued']??0)>0];
                    }
                }
            } catch (Throwable $error) {
                $coverage['curiosity_review'] = 'unavailable';
            }
        }
    }
    if ($view === '') {
        $findings = [];
        try {
            if ($currentChat && ($offer = vp3_agent_activity_media_offer_v1($pdo, $fresh)) !== null) {
                $coordination['media_offer'] = $offer;
            }
        } catch (Throwable) {
            $coverage['media_offer'] = 'unavailable';
        }
        try {
            if ($currentChat) $findings = vp3_agent_activity_coordination_v1($pdo, $fresh);
            if ($findings) $coordination['coordination_findings'] = $findings;
        } catch (Throwable $error) {
            $coverage['coordination'] = in_array($error->getCode(), [403, 409], true) ? 'forbidden' : 'unavailable';
        }
        try {
            $coordination['summary'] = vp3_agent_activity_summary_v1($pdo, $fresh, $now, $findings, $attention['specialists']??[]);
        } catch (Throwable $error) {
            $coverage['summary'] = in_array($error->getCode(), [403, 409], true) ? 'forbidden' : 'unavailable';
        }
    }
    if($gmailOnly){
        require_once __DIR__.'/agent-gmail-workspace-context-v1.php';
        try{$coordination['gmail_workspace']=agent_gmail_workspace_context_v1($pdo,$fresh['user'],$fresh['principal'],$conversationId,$now->getTimestamp());}
        catch(PDOException $error){$coverage['gmail_workspace']='unavailable';}
    }
    return [
        'contract'=>'vp3.agent.activity.v1', 'owner_user_id'=>(int)$scope['user']['id'],
        'agent_id'=>$agentId, 'agent_name'=>vp3_agent_activity_text_v1($fresh['principal']['display_name'] ?? 'Agent', 120),
        'conversation_id'=>$conversationId, 'current_chat'=>$currentChat
            ? ['id'=>$conversationId, 'title'=>vp3_agent_activity_text_v1($currentChat['title'] ?? 'Chat', 190)] : null,
        'working_attention_binding'=>vp3_catch_up_page_binding_v1($scope['user']),
        'observed_at'=>$now->format(DATE_ATOM), 'sections'=>$sections, 'coverage'=>$coverage,
    ] + $attention + $coordination + ($pages ? ['pages'=>$pages] : []) + ($listId > 0 ? ['list_id'=>$listId] : []);
}

function vp3_agent_activity_hub_request_v1(PDO $pdo, array $user, string $method, mixed $agentId, mixed $conversationId, string $binding,
    mixed $view = '', mixed $collection = '', mixed $cursor = '', mixed $listId = ''): array
{
    if ($method !== 'GET') throw new RuntimeException('Use GET to read Agent activity.', 405);
    if ((int)($user['id'] ?? 0) < 1 || $binding === '' || !hash_equals(vp3_catch_up_page_binding_v1($user), $binding)) {
        throw new RuntimeException('Your Agent session changed.', 403);
    }
    if (!in_array($view, ['', 'gmail', 'tasks', 'planning', 'media', 'curiosity', 'transcripts', 'coding', 'marketing', 'profile', 'location', 'operator'], true)) throw new RuntimeException('Choose a valid activity view.', 422);
    if (!in_array($collection, ['', 'reviews', 'all', 'tasks', 'lists', 'reminders', 'list_items'], true)
        || ($view !== 'tasks' && $collection !== '')
        || ($cursor !== '' && (in_array($collection, ['', 'reviews', 'all'], true) || !vp3_agent_activity_inventory_id_v1($cursor)))
        || ($collection === 'list_items' ? !vp3_agent_activity_inventory_id_v1($listId) : $listId !== '')) {
        throw new RuntimeException('Choose a valid activity inventory page.', 422);
    }
    foreach ([$agentId, $conversationId] as $id) {
        if ((!is_string($id) && !is_int($id)) || !preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', (string)$id)
            || (int)$id > 9007199254740991) {
            throw new RuntimeException('Choose an exact Agent and conversation.', 422);
        }
    }
    return vp3_agent_activity_hub_v1($pdo, $user, (int)$agentId, (int)$conversationId, $view,
        $collection, $cursor === '' ? null : (int)$cursor, (int)$listId);
}

function vp3_agent_activity_inventory_id_v1(mixed $id): bool
{
    return (is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]{0,15}\z/D', (string)$id)
        && (int)$id <= 9007199254740991;
}
