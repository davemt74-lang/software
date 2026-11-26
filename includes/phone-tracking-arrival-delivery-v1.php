<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-tracking-local-offers-v1.php';

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
    vp3_phone_local_offers_schema_v1($pdo, $options);
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
function vp3_phone_arrival_tasks_v1(PDO $pdo, array $user, array $principal, int $cid, string $topic, ?array $originalIds=null, string $item=''): ?array
{
    if (!function_exists('vp3_task_list_authorize_v1')) require_once __DIR__.'/agent-task-lists-v1.php';
    if (!function_exists('vp3_agent_memory_scope_sql_v410')) require_once __DIR__.'/agent-memory-scope-v410.php';
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
    $columns = $item === '' ? 't.id,t.subject' : 't.id,t.subject,t.memory_text';
    $sql = "SELECT $columns FROM agent_memory_items t
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
    }
    if ($topic !== '') {
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
    // Search across all scoped open tasks before choosing the five shown in the briefing.
    // Match the item as a whole phrase, allowing a simple terminal English plural, e.g. cable/cables.
    $itemPattern = null;
    if ($item !== '') {
        $stem = preg_replace('/(?<=[a-z])(?<!s)s$/i','',$item);
        $term = '%'.strtr(mb_strtolower($stem),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
        $sql .= " AND (LOWER(t.subject) LIKE ? ESCAPE '!' OR LOWER(t.memory_text) LIKE ? ESCAPE '!')";
        $params[] = $term;
        $params[] = $term;
        $itemPattern = '(?<![\\p{L}\\p{N}])'.preg_quote($stem,'/').'s?(?![\\p{L}\\p{N}])';
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
            if ($itemPattern !== null && !vp3_phone_local_task_needs_item_v1((string)$row['subject'],
                (string)$row['memory_text'],$topic,$itemPattern)) continue;
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
    if (!vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Arrival delivery requires the private Chat transaction.');
    $uid = (int)$user['id'];
    $aid = (int)($principal['agent_id'] ?? 0);
    $events = vp3_phone_sql_v1($pdo,'SELECT e.id,e.arrived_at,e.visit_number,r.id AS rule_id,r.visit_number AS current_visit,r.version,r.zone_state,
        r.task_query,r.state,r.generation,r.accepted_at,i.item_term,i.merchant_category,i.open_today,
        d.id AS device_id,d.device_name,d.generation AS live_generation,d.expires_at,d.device_status,
        d.location_state,d.allow_location,d.fix_at,p.name AS place_name
        FROM vp3_phone_arrival_rules_v1 r JOIN vp3_phone_arrival_events_v1 e ON e.rule_id=r.id
        LEFT JOIN vp3_phone_arrival_search_intents_v1 i ON i.rule_id=r.id
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
        // Claim under the account lock. A later failure, including writing the receipt, rolls this deletion back.
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_arrival_events_v1 WHERE id=?',[$event['id']]);
        if (!$eligible) continue;
        $conditional = $event['item_term'] !== null;
        if ($conditional && ($event['zone_state'] !== 'inside' || (int)$event['visit_number'] !== (int)$event['current_visit']
            || (int)$event['fix_at'] <= time()-VP3_PHONE_FRESH_SECONDS_V1 || (int)$event['arrived_at'] <= time()-86400)) continue;
        $tasks = vp3_phone_arrival_tasks_v1($pdo,$user,$principal,$cid,$event['task_query'],null,$event['item_term'] ?? '');
        if ($conditional && !$tasks) continue;
        $phone = vp3_phone_chat_label_v1(['name'=>$event['device_name']]);
        $place = vp3_phone_arrival_display_text_v1((string)$event['place_name'],'your saved place');
        $when = gmdate('Y-m-d H:i \U\T\C',(int)$event['arrived_at']);
        $arrival = "Location Specialist: $phone arrived at $place ($when).";
        $link = $conditional ? 'local_offer' : 'arrival';
        $row = vp3_phone_chat_store_v1($pdo,$uid,$aid,$cid,'',$arrival,$link,null,null,$event['device_id']);
        $messageId = (int)$row['assistant_message_id'];
        $taskIds = $tasks === null ? null : json_encode(array_keys($tasks),JSON_THROW_ON_ERROR);
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_arrival_briefings_v1(assistant_message_id,event_id,task_ids_json)
            VALUES(?,?,?)',[$messageId,$event['id'],$taskIds]);
        $row['answer'] = vp3_phone_arrival_answer_v1($arrival,$tasks);
        if ($conditional) {
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_local_offers_v1
                (assistant_message_id,rule_id,rule_version,generation,visit_number,created_at)
                VALUES(?,?,?,?,?,?)',[$messageId,$event['rule_id'],$event['version'],$event['generation'],$event['visit_number'],$event['arrived_at']]);
            $row['answer'] .= "\n".vp3_phone_local_offer_invitation_v1($event,$messageId);
        }
        $updates[] = vp3_phone_chat_announcement_v1($row);
    }
    return $updates;
}

/** Ordinary poll replay and model history never receive task titles. Authorized history resolves only original task IDs. */
function vp3_phone_arrival_history_answer_v1(PDO $pdo, array $user, int $aid, int $cid, array $turn): string
{
    $base = $turn['answer'];
    if (!in_array($turn['map_link'],['arrival','local_offer'],true) || (int)$turn['owner_user_id'] !== (int)$user['id']
        || (int)$turn['agent_id'] !== $aid || (int)$turn['conversation_id'] !== $cid) return $base;
    $id = (int)$turn['assistant_message_id'];
    $agent = vp3_agent_chat_resolve_agent_v380($pdo,$user,$aid);
    $principal = vp3_agent_chat_principal_v380($user,$agent);
    if ($turn['map_link'] === 'local_offer') {
        // Edited tasks must still satisfy the original need before even their new titles can appear.
        $tasks = null;
        $offer = vp3_phone_local_offer_v1($pdo,$user,$principal,$cid,$id,$tasks);
        if (!$offer) return $base;
        $answer = vp3_phone_arrival_answer_v1($base,$tasks);
        if (!vp3_phone_local_offer_consumed_v1($pdo,$id)) $answer .= "\n".vp3_phone_local_offer_invitation_v1($offer,$id);
        return $answer;
    }
    $receipt = vp3_phone_sql_v1($pdo,'SELECT task_ids_json FROM vp3_phone_arrival_briefings_v1
        WHERE assistant_message_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if (!$receipt || $receipt['task_ids_json'] === null) return vp3_phone_arrival_answer_v1($base,null);
    $ids = json_decode($receipt['task_ids_json'],true,32,JSON_THROW_ON_ERROR);
    $tasks = vp3_phone_arrival_tasks_v1($pdo,$user,$principal,$cid,'',$ids);
    return vp3_phone_arrival_answer_v1($base,$tasks);
}
