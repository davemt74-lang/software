<?php
declare(strict_types=1);

const VP3_ACTIVITY_TASK_PAGE_SIZE_V1 = 12;

function vp3_agent_activity_inventory_list_v1(PDO $pdo, array $scope, int $listId): array
{
    vp3_task_list_authorize_v1($pdo, $scope['user'], $scope['principal'], 0);
    if (!vp3_task_lists_ready_v1($pdo)) throw new RuntimeException('Task list storage is unavailable.', 503);
    $query = $pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');
    $query->execute([$listId, (int)$scope['user']['id'], $scope['agent_id']]);
    $list = $query->fetch(PDO::FETCH_ASSOC);
    if (!$list || !vp3_participant_resource_v1($pdo, $scope['user'], $scope['principal'], 'task_list', $listId)) {
        throw new RuntimeException('This task list is unavailable.', 403);
    }
    return $list;
}

function vp3_agent_activity_inventory_task_card_v1(array $scope, array $task, int $parentId, bool $inList): array
{
    $id = (int)$task['id'];
    $meta = json_decode((string)$task['metadata_json'], true);
    $status = match ($meta['task_status'] ?? 'open') {
        'in_progress'=>'In progress', 'waiting'=>'Waiting', 'completed'=>'Completed',
        'cancelled'=>'Cancelled', default=>'Open task',
    };
    $due = vp3_agent_activity_text_v1($meta['due_at'] ?? $meta['due_date'] ?? '', 100);
    if ($due !== '') $status .= ' · Due '.$due;
    $actions = [];
    if (!$inList) {
        $actions[] = $parentId > 0
            ? ['kind'=>'list_inventory', 'label'=>'View parent list', 'list_id'=>$parentId,
                'href'=>vp3_agent_activity_chat_href_v1($scope['agent_id'])]
            : ['kind'=>'url', 'label'=>'Open Agent Brain', 'href'=>url('/account.php#agent-brain')];
    }
    return vp3_agent_activity_card_v1('task:'.$id, 'task', $task['subject'], $status, $actions);
}

/** Cursor by saved ID so updating a status or deleting an earlier row cannot shift the next page. */
function vp3_agent_activity_inventory_page_v1(PDO $pdo, array $scope, string $collection, ?int $before,
    ?array $list, DateTimeImmutable $now): array
{
    $user = $scope['user'];
    $principal = $scope['principal'];
    $owner = (int)$user['id'];
    $agent = $scope['agent_id'];
    $listId = (int)($list['id'] ?? 0);
    $parent = null;
    $occurrence = null;
    if ($collection === 'reminders') {
        if (!reminder_ready_v1($pdo)) throw new RuntimeException('Reminder storage is unavailable.', 503);
        $sql = 'SELECT r.* FROM agent_reminders_v1 r WHERE r.owner_user_id=?';
        $params = [$owner];
        $zone = user_calendar_default_timezone_v1300($pdo, $user);
        $stamp = reminder_utc_v1($now);
        $occurrence = $pdo->prepare("SELECT wake_at,status FROM agent_reminder_occurrences_v1
            WHERE reminder_id=? AND owner_user_id=? AND status='pending' ORDER BY wake_at,id LIMIT 1");
    } else {
        vp3_task_list_authorize_v1($pdo, $user, $principal, 0);
        if ($collection === 'lists') {
            if (!vp3_task_lists_ready_v1($pdo)) throw new RuntimeException('Task list storage is unavailable.', 503);
            $sql = 'SELECT r.id,r.name FROM agent_task_lists_v1 r WHERE r.owner_user_id=? AND r.agent_scope_id=?';
            $params = [$owner, $agent];
        } else {
            if (!vp3_agent_memory_scope_schema_ready_v410($pdo)) throw new RuntimeException('Task storage is unavailable.', 503);
            [$agentWhere, $agentParams] = vp3_agent_memory_scope_sql_v410($agent, 'r');
            $join = $listId > 0 ? 'JOIN agent_task_list_items_v1 i ON i.task_id=r.id AND i.list_id=?' : '';
            $sql = "SELECT r.id,r.subject,r.metadata_json FROM agent_memory_items r $join
                WHERE r.user_id=? AND $agentWhere AND r.is_active=1 AND r.memory_type IN ('task','commitment')";
            $params = [...($listId > 0 ? [$listId] : []), $owner, ...$agentParams];
            if ($collection === 'tasks' && vp3_task_lists_ready_v1($pdo)) {
                $parent = $pdo->prepare('SELECT l.id FROM agent_task_list_items_v1 i JOIN agent_task_lists_v1 l ON l.id=i.list_id
                    WHERE i.task_id=? AND l.owner_user_id=? AND l.agent_scope_id=? ORDER BY l.id DESC LIMIT 1');
            }
        }
    }
    if ($before !== null) {
        $sql .= ' AND r.id<?';
        $params[] = $before;
    }
    // A page can pass over restricted records without scanning the entire inventory.
    $scanSize = 4 * VP3_ACTIVITY_TASK_PAGE_SIZE_V1;
    $query = $pdo->prepare($sql.' ORDER BY r.id DESC LIMIT '.($scanSize + 1));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $scanned = 0;
    $items = [];
    foreach (array_slice($rows, 0, $scanSize) as $row) {
        if (count($items) === VP3_ACTIVITY_TASK_PAGE_SIZE_V1) break;
        $scanned++;
        $id = (int)$row['id'];
        if ($collection === 'lists') {
            if (!vp3_participant_resource_v1($pdo, $user, $principal, 'task_list', $id)) continue;
            $items[] = vp3_agent_activity_card_v1('list:'.$id, 'task_list', $row['name'], '', [
                ['kind'=>'list_inventory', 'label'=>'Open list', 'list_id'=>$id, 'href'=>vp3_agent_activity_chat_href_v1($agent)],
            ]);
        } elseif ($collection === 'reminders') {
            if (!vp3_participant_resource_v1($pdo, $user, $principal, 'reminder', $id)
                || !reminder_initiative_agent_visible_v1($pdo, $row, $principal)) continue;
            $occurrence->execute([$id, $owner]);
            $pending = $occurrence->fetch(PDO::FETCH_ASSOC) ?: null;
            $entry = reminder_list_entry_v1($row, $pending, reminder_followup_delivered_v1($pdo, $row), $zone, $stamp);
            $cid = (int)$row['conversation_id'];
            $href = vp3_agent_activity_chat_href_v1($agent,
                $cid > 0 && vp3_agent_chat_conversation_v380($pdo, $cid, $owner, $scope['agent']) ? $cid : 0);
            $items[] = vp3_agent_activity_card_v1('reminder:'.$id, 'reminder', $row['title'], reminder_list_label_v1($entry, false), [
                ['kind'=>'task_notes', 'label'=>'Open reminders', 'reminder_id'=>$id, 'view'=>'all', 'timezone'=>$zone, 'href'=>$href],
            ]);
        } else {
            if (!vp3_task_list_task_allowed_v1($pdo, $user, $principal, $id)) continue;
            $parentId = 0;
            if ($parent) {
                $parent->execute([$id, $owner, $agent]);
                $parentId = (int)$parent->fetchColumn();
            }
            $items[] = vp3_agent_activity_inventory_task_card_v1($scope, $row, $parentId, $listId > 0);
        }
    }
    $next = $scanned < count($rows) ? (string)$rows[$scanned - 1]['id'] : null;
    require_once __DIR__.'/agent-initiative-office-entry-projection-v1.php';
    $sources = [];
    $items = vp3_initiative_office_entry_projections_v1($pdo,$scope,$items,$now,verifiedSources:$sources);
    if ($sources && $scope['conversation_id'] > 0) {
        require_once __DIR__.'/agent-coordination-sources-v1.php';
        require_once __DIR__.'/agent-coordination-finding-feedback-v1.php';
        $localNow = vp3_initiative_task_local_time_v1($pdo,$scope,$now);
        $binding = ['agent_id'=>$agent,'owner_user_id'=>$owner,'conversation_id'=>$scope['conversation_id']];
        foreach ($items as &$item) {
            $source = $sources[$item['id']] ?? null;
            if ($source === null || empty($item['office_report']['followup_question'])) continue;
            $finding = vp3_coordination_office_item_v1($scope,$source,$item['office_report'],$localNow);
            if ($finding === null) continue;
            try {
                $feedback = vp3_coordination_finding_feedback_v1($pdo,$user,$binding,$finding);
                if ($feedback !== null) $item['office_report']['engagement'] = $feedback;
            } catch (PDOException $error) {
                if ($pdo->inTransaction()) throw $error;
            }
        }
        unset($item);
    }
    return ['items'=>$items, 'next_cursor'=>$next];
}
