<?php
declare(strict_types=1);

/** An offer is anchored to one delivered private Chat turn and dies with its rule. */
function vp3_phone_local_offers_schema_v1(PDO $pdo, string $options): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_local_offers_v1 (
        assistant_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        rule_id CHAR(32) NOT NULL, rule_version BIGINT NOT NULL, generation BIGINT NOT NULL,
        visit_number BIGINT NOT NULL, created_at BIGINT NOT NULL,
        UNIQUE (rule_id,visit_number),
        FOREIGN KEY (assistant_message_id) REFERENCES vp3_phone_arrival_briefings_v1(assistant_message_id) ON DELETE CASCADE,
        FOREIGN KEY (rule_id) REFERENCES vp3_phone_arrival_rules_v1(id) ON DELETE CASCADE
    )".$options);
}

/** The location remains private. The owner supplies a public search area in a separate turn. */
function vp3_phone_local_offer_invitation_v1(array $terms, int $messageId): string
{
    $hours = $terms['open_today'] ? ' open today' : '';
    return 'I can look for nearby '.$terms['merchant_category'].$hours.' and check for '.$terms['item_term']
        .'. To review the search, reply “Search area for offer #'.$messageId.': [city or neighborhood]”.';
}

/** Resolve a single current offer. An unnumbered reply is ambiguous if multiple offers qualify. */
function vp3_phone_local_offer_v1(PDO $pdo, array $user, array $principal, int $cid, ?int $offerId=null): ?array
{
    $owner = (int)$user['id'];
    $agent = (int)($principal['agent_id'] ?? 0);
    if ($owner < 1 || $cid < 1 || vp3_participant_scope_v1() !== null
        || !knowledge_retrieval_v162_owner_session($user,$principal)) return null;
    $now = time();
    $params = [$owner,$agent,$cid,$now,$now-86400];
    $where = '';
    if ($offerId !== null) {
        $where = ' AND o.assistant_message_id=?';
        $params[] = $offerId;
    }
    $rows = vp3_phone_sql_v1($pdo,"SELECT o.assistant_message_id,o.rule_id,o.visit_number,b.task_ids_json,
        r.task_query,i.item_term,i.merchant_category,i.open_today
        FROM vp3_phone_local_offers_v1 o
        JOIN vp3_phone_arrival_briefings_v1 b ON b.assistant_message_id=o.assistant_message_id
        JOIN vp3_phone_chat_turns_v1 t ON t.assistant_message_id=o.assistant_message_id
        JOIN vp3_phone_arrival_rules_v1 r ON r.id=o.rule_id AND r.owner_user_id=t.owner_user_id
            AND r.agent_id=t.agent_id AND r.conversation_id=t.conversation_id AND r.version=o.rule_version
            AND r.generation=o.generation AND r.visit_number=o.visit_number
        JOIN vp3_phone_arrival_search_intents_v1 i ON i.rule_id=r.id
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=r.owner_user_id AND d.id=r.device_id AND d.generation=r.generation
        JOIN vp3_phone_places_v1 p ON p.owner_user_id=r.owner_user_id AND p.id=r.place_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND r.zone_state='inside'
            AND r.state='active' AND r.accepted_at IS NOT NULL AND r.accepted_at<=o.created_at
            AND d.device_status='approved' AND d.allow_location=1 AND d.location_state='live' AND d.expires_at>?
            AND o.created_at>?".$where.' ORDER BY o.assistant_message_id DESC',$params)->fetchAll(PDO::FETCH_ASSOC);
    $found = null;
    foreach ($rows as $row) {
        $id = (int)$row['assistant_message_id'];
        if ($offerId === null && vp3_phone_local_offer_consumed_v1($pdo,$id)) continue;
        $ids = json_decode($row['task_ids_json'],true,32,JSON_THROW_ON_ERROR);
        if (!vp3_phone_arrival_tasks_v1($pdo,$user,$principal,$cid,$row['task_query'],$ids,$row['item_term'])) continue;
        if ($found !== null) return null;
        $found = ['id'=>$id,'assistant_message_id'=>$id,'rule_id'=>$row['rule_id'],'visit_number'=>(int)$row['visit_number'],
            'item_term'=>$row['item_term'],'merchant_category'=>$row['merchant_category'],'open_today'=>(bool)$row['open_today']];
    }
    return $found;
}
