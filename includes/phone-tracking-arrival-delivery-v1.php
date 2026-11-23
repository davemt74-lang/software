<?php
declare(strict_types=1);

require_once __DIR__.'/agent-task-lists-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';

/** Store the original task references privately. Titles are read under the current Agent's permissions. */
function vp3_phone_arrival_receipts_schema_v1(PDO $pdo): void
{
    $options = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_arrival_briefings_v1 (
        assistant_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        event_id CHAR(32) NOT NULL UNIQUE,
        task_ids_json TEXT NULL,
        FOREIGN KEY (assistant_message_id) REFERENCES vp3_phone_chat_turns_v1(assistant_message_id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_arrival_pending_v1(PDO $pdo, int $uid, int $aid, int $cid): bool
{
    $query = vp3_phone_sql_v1($pdo,'SELECT 1 FROM vp3_phone_arrival_rules_v1 r
        JOIN vp3_phone_arrival_events_v1 e ON e.rule_id=r.id
        WHERE r.owner_user_id=? AND r.agent_id=? AND r.conversation_id=? LIMIT 1',[$uid,$aid,$cid]);
    return (bool)$query->fetchColumn();
}

function vp3_phone_arrival_display_text_v1(string $value, string $fallback): string
{
    $text = trim(preg_replace('/[\p{C}<>\s]+/u', ' ', $value) ?? '');
    if ($text === '' || preg_match('/[-+]?\d{1,3}\.\d+\s*[,; ]\s*[-+]?\d{1,3}\.\d+/u', $text)) return $fallback;
    return mb_substr($text, 0, 160);
}

/** Null means source access is unavailable; an empty array means an authorized read found no open matches. */
function vp3_phone_arrival_tasks_v1(PDO $pdo, array $user, array $principal, int $cid, string $topic, ?array $originalIds=null): ?array
{
    $aid = vp3_task_list_agent_v1($principal);
    if (!function_exists('personal_capability_has_v242') || !table_exists('agent_memory_items')
        || ($aid > 0 && !function_exists('user_data_policy_can_use_v236'))) return null;
    try {
        vp3_task_list_authorize_v1($pdo, $user, $principal, $cid);
    } catch (RuntimeException $error) {
        if (in_array($error->getCode(), [403,404], true)) return null;
        throw $error;
    }
    if ($originalIds === []) return [];
    [$scope, $params] = vp3_agent_memory_scope_sql_v410($aid, 't');
    $params = [(int)$user['id'], ...$params];
    $sql = "SELECT t.id,t.subject FROM agent_memory_items t
        WHERE t.user_id=? AND $scope AND t.memory_scope_version>=410 AND t.is_active=1
        AND t.memory_type IN ('task','commitment')";
    // The task lifecycle stores status in metadata. Older tasks with no status are open.
    $meta = "CASE WHEN t.metadata_json IS NULL OR t.metadata_json='' THEN '{}' WHEN JSON_VALID(t.metadata_json) THEN t.metadata_json ELSE '{\"task_status\":\"invalid\"}' END";
    $status = "JSON_EXTRACT($meta,'$.task_status')";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $status = "JSON_UNQUOTE($status)";
    $sql .= " AND COALESCE($status,'open') IN ('open','in_progress','waiting')";
    if ($originalIds !== null) {
        $ids = array_values(array_filter($originalIds, static fn($id) => is_int($id) && $id > 0));
        if (!$ids || count($ids) > 5) return [];
        $sql .= ' AND t.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $params = [...$params,...$ids];
    } elseif ($topic !== '') {
        $term = '%'.strtr(mb_strtolower($topic),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $sql .= " AND (LOWER(t.subject) LIKE ? ESCAPE '!' OR LOWER(t.memory_text) LIKE ? ESCAPE '!'";
        $params[] = $term;
        $params[] = $term;
        if (table_exists('agent_task_lists_v1') && table_exists('agent_task_list_items_v1')) {
            $list = vp3_task_list_find_v1($pdo,$user,$principal,$topic);
            if ($list) {
                $sql .= ' OR EXISTS (SELECT 1 FROM agent_task_list_items_v1 i WHERE i.task_id=t.id AND i.list_id=?)';
                $params[] = (int)$list['id'];
            }
        }
        $sql .= ')';
    }
    $tasks = [];
    $before = null;
    do {
        $args = $params;
        $page = $sql;
        if ($before !== null) {
            $page .= ' AND t.id<?';
            $args[] = $before;
        }
        $rows = vp3_phone_sql_v1($pdo,$page.' ORDER BY t.id DESC LIMIT 64',$args)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $before = (int)$row['id'];
            if (!vp3_task_list_task_allowed_v1($pdo,$user,$principal,$before)) continue;
            $tasks[$before] = vp3_phone_arrival_display_text_v1((string)$row['subject'],'Untitled task');
            if (count($tasks) === 5) break 2;
        }
    } while (count($rows) === 64);
    return $tasks;
}

function vp3_phone_arrival_answer_v1(string $arrival, ?array $tasks): string
{
    if ($tasks === null) return $arrival."\nTask access is unavailable.";
    if (!$tasks) return $arrival."\nNo matching open tasks.";
    return $arrival."\nOpen tasks:\n• ".implode("\n• ",$tasks);
}

/** The private Chat poll owns the account lock. A committed turn, its receipt and outbox removal are atomic. */
function vp3_phone_arrival_deliver_v1(PDO $pdo, array $user, array $principal, array $input, int $cid): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Arrival delivery requires the private Chat transaction.');
    $uid = (int)$user['id'];
    $aid = (int)($principal['agent_id'] ?? 0);
    $events = vp3_phone_sql_v1($pdo,'SELECT e.id,e.arrived_at,r.task_query,r.state,r.generation,r.accepted_at,
        d.id AS device_id,d.device_name,d.generation AS live_generation,d.expires_at,d.device_status,
        d.location_state,d.allow_location,p.name AS place_name
        FROM vp3_phone_arrival_rules_v1 r JOIN vp3_phone_arrival_events_v1 e ON e.rule_id=r.id
        LEFT JOIN vp3_phone_devices_v1 d ON d.owner_user_id=r.owner_user_id AND d.id=r.device_id
        LEFT JOIN vp3_phone_places_v1 p ON p.owner_user_id=r.owner_user_id AND p.id=r.place_id
        WHERE r.owner_user_id=? AND r.agent_id=? AND r.conversation_id=? ORDER BY e.arrived_at,e.id LIMIT 8',
        [$uid,$aid,$cid])->fetchAll(PDO::FETCH_ASSOC);
    if (!$events) return [];
    vp3_phone_chat_authorize_v1($pdo,$user,$principal,$input,$cid);
    $updates = [];
    foreach ($events as $event) {
        $eligible = $event['state'] === 'active' && $event['accepted_at'] !== null && $event['place_name'] !== null
            && (int)$event['arrived_at'] >= (int)$event['accepted_at'] && $event['device_status'] === 'approved'
            && $event['allow_location'] && $event['location_state'] === 'live' && (int)$event['expires_at'] > time()
            && (int)$event['generation'] === (int)$event['live_generation'];
        if ($eligible) {
            $phone = vp3_phone_chat_label_v1(['name'=>$event['device_name']]);
            $place = vp3_phone_arrival_display_text_v1((string)$event['place_name'],'your saved place');
            $when = gmdate('Y-m-d H:i \U\T\C',(int)$event['arrived_at']);
            $arrival = "Location Specialist: $phone arrived at $place ($when).";
            $tasks = vp3_phone_arrival_tasks_v1($pdo,$user,$principal,$cid,$event['task_query']);
            $row = vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'',$arrival,'arrival',null,null,$event['device_id']);
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_arrival_briefings_v1(assistant_message_id,event_id,task_ids_json)
                VALUES(?,?,?)',[$row['assistant_message_id'],$event['id'],$tasks === null ? null : json_encode(array_keys($tasks),JSON_THROW_ON_ERROR)]);
            $row['answer'] = vp3_phone_arrival_answer_v1($arrival,$tasks);
            $updates[] = vp3_phone_chat_announcement_v1($row);
        }
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_arrival_events_v1 WHERE id=?',[$event['id']]);
    }
    return $updates;
}

/** Ordinary poll replay and model history never receive task titles. Authorized history resolves only original task IDs. */
function vp3_phone_arrival_history_answer_v1(PDO $pdo, array $user, int $aid, int $cid, array $turn): string
{
    $base = $turn['answer'];
    if ($turn['map_link'] !== 'arrival' || (int)$turn['owner_user_id'] !== (int)$user['id']
        || (int)$turn['agent_id'] !== $aid || (int)$turn['conversation_id'] !== $cid) return $base;
    $receipt = vp3_phone_sql_v1($pdo,'SELECT task_ids_json FROM vp3_phone_arrival_briefings_v1
        WHERE assistant_message_id=?',[$turn['assistant_message_id']])->fetch(PDO::FETCH_ASSOC);
    if (!$receipt || $receipt['task_ids_json'] === null) return vp3_phone_arrival_answer_v1($base,null);
    $ids = json_decode($receipt['task_ids_json'],true,32,JSON_THROW_ON_ERROR);
    $agent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);
    $tasks = vp3_phone_arrival_tasks_v1($pdo,$user,vp3_agent_chat_principal_v380($user,$agent),$cid,'',$ids);
    return vp3_phone_arrival_answer_v1($base,$tasks);
}
