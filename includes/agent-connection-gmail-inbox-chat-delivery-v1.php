<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-tool-receipts-v1.php';

function agent_connection_gmail_inbox_chat_ref_v1(array $scope, string $runId): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],implode(':',[
        'first-review-chat-v1',$scope['owner'],$scope['policy']['connection_id'],$scope['generation'],$runId,
    ]));
}

function agent_connection_gmail_inbox_chat_target_v1(PDO $pdo,array $scope,int $agentId,int $conversationId,bool $lock=false): bool
{
    try { $agent = vp3_agent_chat_resolve_agent_v380($pdo,$scope['user'],$agentId); }
    catch (RuntimeException $error) {
        if ($error instanceof PDOException) throw $error;
        return false;
    }
    $chat = vp3_agent_chat_conversation_v380($pdo,$conversationId,$scope['owner'],$agent,$lock && !agent_commerce_sqlite_pa2($pdo));
    return $chat !== null;
}

/** The authoritative report stores one exact post reference, including across browsers. */
function agent_connection_gmail_inbox_chat_binding_v1(array $scope,array $row,array $report): ?array
{
    if (!array_key_exists('chat_delivery',$report)) return null;
    $saved = $report['chat_delivery'];
    $runId = $row['run_id'];
    $ref = agent_connection_gmail_inbox_chat_ref_v1($scope,$runId);
    if (!is_array($saved) || ($saved['run_id'] ?? null) !== $runId || ($saved['report_ref'] ?? null) !== $ref
        || !is_int($saved['agent_id'] ?? null) || $saved['agent_id'] < 0
        || !is_int($saved['conversation_id'] ?? null) || $saved['conversation_id'] < 1
        || !is_int($saved['message_id'] ?? null) || $saved['message_id'] < 1) {
        throw new RuntimeException('Saved Gmail review delivery could not be verified.',503);
    }
    return array_intersect_key($saved,array_flip(['run_id','report_ref','agent_id','conversation_id','message_id']));
}

function agent_connection_gmail_inbox_chat_marker_matches_v1(array $context,array $binding): bool
{
    if (($binding['kind'] ?? null) === 'batch') {
        return ($context['source'] ?? null) === 'agent_connection_gmail_inbox_update'
            && ($context['gmail_inbox_update_v1']['run_id'] ?? null) === $binding['run_id']
            && ($context['gmail_inbox_update_v1']['report_ref'] ?? null) === $binding['report_ref'];
    }
    return ($context['source'] ?? null) === 'agent_connection_gmail_first_review'
        && ($context['gmail_first_review_v1']['run_id'] ?? null) === $binding['run_id']
        && ($context['gmail_first_review_v1']['report_ref'] ?? null) === $binding['report_ref'];
}

function agent_connection_gmail_inbox_chat_marker_exists_v1(PDO $pdo,array $binding): bool
{
    $query = $pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id IS NULL AND role='assistant'");
    $query->execute([$binding['message_id'],$binding['conversation_id']]);
    $context = json_decode((string)$query->fetchColumn(),true,12);
    return is_array($context) && agent_connection_gmail_inbox_chat_marker_matches_v1($context,$binding);
}

/** Retain a content-free Chat post; the current Gmail grant controls its display details. */
function agent_connection_gmail_inbox_chat_deliver_v1(PDO $pdo,array $user,int $agentId,int $conversationId,string $runId): array
{
    if ($agentId < 0 || $conversationId < 1 || !preg_match('/\A[a-f0-9]{32}\z/D',$runId)
        || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new RuntimeException('A private owner Chat is required.',403);
    }
    return agent_commerce_atomic_pa2($pdo,static function () use($pdo,$user,$agentId,$conversationId,$runId): array {
        // Inbox settings, scan completion and delivery share the owner, Gmail grant and review lock order.
        $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user,true);
        $row = agent_connection_gmail_inbox_initial_row_v1($pdo,$scope,true);
        if (!$row || $row['phase'] !== 'ready' || $row['run_id'] !== $runId) throw new RuntimeException('Gmail review changed.',409);
        if (!agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId,true)) {
            throw new RuntimeException('A private owner Chat is required.',403);
        }
        $report = json_decode((string)$row['report_json'],true,12,JSON_THROW_ON_ERROR);
        $binding = agent_connection_gmail_inbox_chat_binding_v1($scope,$row,$report);
        $state = 'existing';
        if ($binding !== null) {
            if ($binding['agent_id'] !== $agentId || $binding['conversation_id'] !== $conversationId) {
                return ['delivery'=>['state'=>'bound_elsewhere']];
            }
            if (!agent_connection_gmail_inbox_chat_marker_exists_v1($pdo,$binding)) return ['delivery'=>['state'=>'removed']];
        } else {
            $ref = agent_connection_gmail_inbox_chat_ref_v1($scope,$runId);
            $context = json_encode([
                'source'=>'agent_connection_gmail_first_review','skip_brain_archive'=>true,
                'tool_receipt'=>vp3_tool_receipt_v1('email.read','review_status','read_complete'),
                'gmail_first_review_v1'=>['run_id'=>$runId,'report_ref'=>$ref],
            ],JSON_THROW_ON_ERROR);
            $now = agent_commerce_time_pa2();
            $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
                VALUES (?,NULL,'assistant',?,?,?)")->execute([$conversationId,'Your first Gmail inbox review is ready.',$context,$now]);
            $messageId = (int)$pdo->lastInsertId();
            if ($messageId < 1) throw new RuntimeException('Gmail review post could not be verified.',503);
            $binding = ['run_id'=>$runId,'report_ref'=>$ref,'agent_id'=>$agentId,'conversation_id'=>$conversationId,'message_id'=>$messageId];
            $report['chat_delivery'] = $binding;
            $write = $pdo->prepare("UPDATE agent_connection_gmail_inbox_initial_v1 SET report_json=?
                WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND run_id=? AND phase='ready'");
            $write->execute([json_encode($report,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$scope['owner'],
                $scope['policy']['connection_id'],$scope['generation'],$runId]);
            if ($write->rowCount() !== 1) throw new RuntimeException('Gmail review changed.',409);
            $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$conversationId,$scope['owner']]);
            if (table_exists('notifications')) {
                $target = url('/chat.php?agent='.($agentId ?: 'system').'&conversation_id='.$conversationId.'&message_id='.$messageId);
                $pdo->prepare("UPDATE notifications SET target_url=? WHERE user_id=? AND type='agent_email_inbox_review'
                    AND source_type='agent_connection_gmail_inbox' AND source_id=? AND title='First Gmail review ready'")
                    ->execute([$target,$scope['owner'],$scope['generation']]);
            }
            $state = 'posted';
        }
        $view = agent_connection_gmail_inbox_initial_view_v1($pdo,$scope['user'],includeChatResult:true,includeLegacyMatches:true);
        if (($view['run_id'] ?? null) !== $runId || !is_array($view['chat_result'] ?? null)) throw new RuntimeException('Gmail review changed.',409);
        return ['delivery'=>['state'=>$state]+$binding,'review'=>$view];
    });
}

/** Link-only owner projection for Top of Mind. A missing/deleted post cannot be guessed. */
function agent_connection_gmail_inbox_chat_delivery_current_v1(PDO $pdo,array $user): ?array
{
    $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
    $row = agent_connection_gmail_inbox_initial_row_v1($pdo,$scope);
    if (!$row || $row['phase'] !== 'ready') return null;
    $report = json_decode((string)$row['report_json'],true,12);
    if (!is_array($report)) return null;
    $binding = agent_connection_gmail_inbox_chat_binding_v1($scope,$row,$report);
    if ($binding === null || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$binding['agent_id'],$binding['conversation_id'])
        || !agent_connection_gmail_inbox_chat_marker_exists_v1($pdo,$binding)) return null;
    return $binding;
}

function agent_connection_gmail_inbox_update_binding_v1(array $scope,array $row): array
{
    $id = $row['batch_id'];
    return ['kind'=>'batch','run_id'=>$id,
        'report_ref'=>agent_connection_gmail_inbox_key_v1($scope['policy'],'update-chat-v1:'.$scope['generation'].':'.$id),
        'agent_id'=>(int)$row['agent_id'],'conversation_id'=>(int)$row['conversation_id'],'message_id'=>(int)$row['message_id']];
}

function agent_connection_gmail_inbox_update_scope_ref_v1(array $scope): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],'review-updates-scope-v1:'.$scope['generation']);
}

function agent_connection_gmail_inbox_update_latest_v1(PDO $pdo,array $scope,?int $agentId=null,bool $includeRoutine=true): ?array
{
    $query = $pdo->prepare('SELECT batch_id,agent_id,conversation_id,message_id FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id>0
        '.($agentId === null ? '' : 'AND agent_id=? ')
        .($includeRoutine ? '' : 'AND NOT ('.agent_connection_gmail_inbox_digest_sql_v1().') ').'ORDER BY message_id DESC LIMIT 1');
    $params = [$scope['owner'],$scope['policy']['connection_id'],$scope['generation']];
    if ($agentId !== null) $params[] = $agentId;
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $binding = agent_connection_gmail_inbox_update_binding_v1($scope,$row);
    return agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$binding['agent_id'],$binding['conversation_id'])
        && agent_connection_gmail_inbox_chat_marker_exists_v1($pdo,$binding) ? $binding : null;
}

function agent_connection_gmail_inbox_update_pending_v1(PDO $pdo,array $scope): array
{
    $routineOnly = agent_email_running_report_enabled_v1($scope['policy']);
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id IS NULL '
        .($routineOnly ? 'AND ('.agent_connection_gmail_inbox_digest_sql_v1().' OR '.agent_connection_gmail_inbox_priority_sql_v1().') ' : '').'ORDER BY id LIMIT 10');
    $query->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation']]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function agent_connection_gmail_inbox_update_has_pending_v1(PDO $pdo,array $scope): bool
{
    $routineOnly = agent_email_running_report_enabled_v1($scope['policy']);
    if (!$routineOnly) {
        $raw = (string)($scope['policy']['last_review_json'] ?? '');
        $report = agent_connection_gmail_inbox_batch_report_v1($raw);
        if ($report !== null && agent_connection_gmail_inbox_report_needs_alert_v1($report)
            && agent_connection_gmail_inbox_batch_v1($pdo,$scope,agent_connection_gmail_inbox_batch_id_v1($scope['policy'],$raw)) === null) return true;
    }
    $query = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id IS NULL '
        .($routineOnly ? 'AND ('.agent_connection_gmail_inbox_digest_sql_v1().' OR '.agent_connection_gmail_inbox_priority_sql_v1().') ' : '').'LIMIT 1');
    $query->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation']]);
    return $query->fetchColumn() !== false;
}

function agent_connection_gmail_inbox_update_state_v1(PDO $pdo,array $scope): array
{
    if (!agent_connection_gmail_inbox_batches_ready_v1($pdo)) return [];
    return ['inbox_updates'=>['scope_ref'=>agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        'pending'=>agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope),
        'latest'=>agent_connection_gmail_inbox_update_latest_v1($pdo,$scope),
        'preview_enabled'=>agent_email_running_report_enabled_v1($scope['policy'])]];
}

/** Only exact, sealed items can be shared with a native watch receipt. */
function agent_connection_gmail_inbox_update_alert_items_v1(array $scope,string $batch,array $report): array
{
    if (($report['triage_enabled'] ?? false) !== true) return [];
    $alerts = [];
    foreach (array_slice($report['items'],0,50) as $index=>$item) {
        if (($item['triage']['alert'] ?? false) !== true || !is_string($item['triage']['id'] ?? null)) continue;
        $ref = agent_connection_gmail_inbox_item_reference_v1($scope,'batch:'.$batch,$index,$item);
        if ($ref === null) continue;
        $providerId = agent_connection_gmail_id_v1(ai_decrypt_secret($item['message_id_enc']));
        if ($providerId === '' || !hash_equals($item['triage']['id'],agent_email_matters_source_key_v1($scope['policy'],'triage',$providerId))) continue;
        $alerts[$providerId] = ['item_ref'=>$ref,'triage_id'=>$item['triage']['id']];
    }
    return $alerts;
}

function agent_connection_gmail_inbox_update_alert_key_v1(array $scope,string $providerId): string
{
    return agent_email_matters_source_key_v1($scope['policy'],'inbox_alert',$scope['generation'].':'.$providerId);
}

/** Reuse a posted Inbox alert only when the current grant and exact alert reference still match. */
function agent_connection_gmail_inbox_update_alert_notice_v1(PDO $pdo,array $scope,int $agent,int $conversation,string $providerId,string $thread): int
{
    $bound = agent_email_matters_bound_scope_v1($scope);
    if ($bound === null || $thread === '' || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return 0;
    $matter = agent_email_matters_source_key_v1($scope['policy'],'thread',$thread);
    $lookup = $pdo->prepare("SELECT event.operation_key,event.chat_message_id,chat.context_json FROM agent_email_matter_events_v1 event
        JOIN agent_email_matters_v1 matter ON matter.matter_id=event.matter_id
        JOIN chat_messages chat ON chat.id=event.chat_message_id AND chat.conversation_id=? AND chat.user_id IS NULL AND chat.role='assistant'
        WHERE matter.owner_user_id=? AND matter.connection_id=? AND matter.grant_key=? AND matter.matter_id=?
        AND event.event_key=? AND event.event_kind='inbox_alert'");
    $lookup->execute([$conversation,...$bound,$matter,agent_connection_gmail_inbox_update_alert_key_v1($scope,$providerId)]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !is_string($row['operation_key'])) return 0;
    $batch = agent_connection_gmail_inbox_batch_v1($pdo,$scope,$row['operation_key']);
    if ($batch === null) return 0;
    $binding = agent_connection_gmail_inbox_update_binding_v1($scope,$batch);
    $context = json_decode((string)$row['context_json'],true,12);
    if ($binding['agent_id'] !== $agent || $binding['conversation_id'] !== $conversation || !is_array($context)) return 0;
    if (($context['gmail_inbox_priority_v1'] ?? null) !== null) {
        $priority = agent_connection_gmail_inbox_priority_history_v1($pdo,$scope,$agent,$conversation,$context,(int)$row['chat_message_id']);
        if ($priority === null) return 0;
        $sealed = $batch['report_json'];
        $report = agent_connection_gmail_inbox_batch_report_v1($sealed);
        $item = $report['items'][$context['gmail_inbox_priority_v1']['item_index']] ?? null;
        if (!is_array($item) || agent_connection_gmail_id_v1(ai_decrypt_secret($item['message_id_enc'])) !== $providerId) return 0;
        return (int)$row['chat_message_id'];
    }
    if ($binding['message_id'] !== (int)$row['chat_message_id']
        || !agent_connection_gmail_inbox_chat_marker_matches_v1($context,$binding)) return 0;
    $report = agent_connection_gmail_inbox_batch_report_v1($batch['report_json']);
    if ($report === null) return 0;
    $item = agent_connection_gmail_inbox_update_alert_items_v1($scope,$batch['batch_id'],$report)[$providerId] ?? null;
    $suppressed = (array)($context['gmail_inbox_update_v1']['suppressed_item_refs'] ?? []);
    return $item !== null && !in_array($item['item_ref'],$suppressed,true) ? $binding['message_id'] : 0;
}

/** Only exact current owner priority watches may produce one signed Chat post per email. */
function agent_connection_gmail_inbox_update_priority_items_v1(array $scope,array $row,array $report): array
{
    $results = [];
    foreach (array_slice((array)($report['items'] ?? []),0,50) as $index=>$item) {
        if (!is_array($item) || (($report['triage_enabled'] ?? false) === true
            && ($item['triage']['alert'] ?? false) !== true)) continue;
        $matches = agent_connection_gmail_inbox_priority_matches_v1($item,$scope['policy']['rules']);
        if (!$matches) continue;
        $itemRef = agent_connection_gmail_inbox_item_reference_v1($scope,'batch:'.$row['batch_id'],$index,$item);
        if ($itemRef === null) continue;
        $providerId = agent_connection_gmail_id_v1(ai_decrypt_secret($item['message_id_enc']));
        if ($providerId === '') continue;
        $results[$index] = ['item_ref'=>$itemRef,'provider_id'=>$providerId,'matches'=>$matches];
    }
    return $results;
}

function agent_connection_gmail_inbox_priority_chat_ref_v1(array $scope,string $batchId,string $itemRef,int $agent,int $conversation): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],implode(':',[
        'priority-inbox-chat-v1',$scope['generation'],$batchId,$itemRef,$agent,$conversation,
    ]));
}

/** Resolve the exact saved item under the current Gmail grant, never from the Chat marker itself. */
function agent_connection_gmail_inbox_priority_history_v1(PDO $pdo,array $scope,int $agent,int $conversation,array $context,int $messageId): ?array
{
    $marker = $context['gmail_inbox_priority_v1'] ?? null;
    $batchId = $marker['run_id'] ?? null;
    $index = $marker['item_index'] ?? null;
    $itemRef = $marker['item_ref'] ?? null;
    $ref = $marker['report_ref'] ?? null;
    if (($context['source'] ?? null) !== 'agent_connection_gmail_inbox_update'
        || !is_string($batchId) || !preg_match('/\A[a-f0-9]{32}\z/D',$batchId)
        || !is_int($index) || $index<0 || $index>=50 || !is_string($itemRef)
        || !preg_match('/\A[a-f0-9]{64}\z/D',$itemRef) || !is_string($ref)
        || !hash_equals(agent_connection_gmail_inbox_priority_chat_ref_v1($scope,$batchId,$itemRef,$agent,$conversation),$ref)) return null;
    $batch = agent_connection_gmail_inbox_batch_v1($pdo,$scope,$batchId);
    if (!$batch || (int)$batch['agent_id'] !== $agent || (int)$batch['conversation_id'] !== $conversation
        || $batch['message_id'] === null) return null;
    $report = agent_connection_gmail_inbox_batch_report_v1((string)$batch['report_json']);
    $item = $report['items'][$index] ?? null;
    if (!is_array($item) || !hash_equals($itemRef,(string)agent_connection_gmail_inbox_item_reference_v1($scope,'batch:'.$batchId,$index,$item))) return null;
    $matches = agent_connection_gmail_inbox_priority_matches_v1($item,$scope['policy']['rules']);
    if (!$matches) return null;
    $views = agent_connection_gmail_inbox_review_items_v1($pdo,$report,$scope,'batch:'.$batchId);
    $selected = null;
    foreach ($views as $view) if (($view['item_ref'] ?? null) === $itemRef) { $selected=$view; break; }
    if ($selected === null) return null;
    return ['kind'=>'batch','priority_item'=>true,'run_id'=>$batchId,'report_ref'=>$ref,
        'scope_ref'=>agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        'agent_id'=>$agent,'conversation_id'=>$conversation,'message_id'=>$messageId,
        'review'=>['state'=>'ready','run_id'=>$batchId,'total'=>1,'at'=>$report['at'],
            'important'=>!empty($selected['important'])?1:0,
            'replies'=>$selected['category']==='reply'?1:0,'items'=>[$selected]]];
}

/** Run inside the same private Inbox transaction that owns these unposted batches. */
function agent_connection_gmail_inbox_update_post_v1(PDO $pdo,array $scope,int $agentId,int $conversationId): int
{
    if (!agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId,true)) return 0;
    $posted = 0;
    $now = agent_commerce_time_pa2();
    $insert = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,?)");
    $attach = $pdo->prepare('UPDATE agent_connection_gmail_inbox_batches_v1 SET agent_id=?,conversation_id=?,message_id=?
        WHERE id=? AND owner_user_id=? AND message_id IS NULL');
    $bound = agent_email_matters_bound_scope_v1($scope);
    $connection = $bound === null ? null : agent_connections_find_v1($pdo,$scope['owner'],$bound[1]);
    if ($connection !== null && (empty(agent_connections_permissions_v1($connection)['followups'])
        || !hash_equals($scope['policy']['authorization_key'],$connection['authorization_key']))) $connection = null;
    foreach (agent_connection_gmail_inbox_update_pending_v1($pdo,$scope) as $row) {
        $binding = agent_connection_gmail_inbox_update_binding_v1($scope,$row);
        $report = agent_connection_gmail_inbox_batch_report_v1($row['report_json']);
        $digest = ($report['routine_digest'] ?? false) === true;
        if ($digest && !hash_equals($row['batch_id'],agent_connection_gmail_inbox_batch_id_v1($scope['policy'],$row['report_json']))) continue;
        $alerts = $bound === null || $report === null || $digest ? [] : agent_connection_gmail_inbox_update_alert_items_v1($scope,$row['batch_id'],$report);
        $covered = [];
        if ($connection !== null && $alerts) {
            require_once __DIR__.'/agent-connection-email-followup-v1.php';
            $covered = agent_connection_email_followup_posted_v1($pdo,$connection,$agentId,$conversationId,array_keys($alerts));
        }
        $priorityRows = $digest || $report===null ? [] : agent_connection_gmail_inbox_update_priority_items_v1($scope,$row,$report);
        $priorityPosts = [];
        foreach ($priorityRows as $index=>$item) {
            if (isset($covered[$item['provider_id']])) continue;
            $ref = agent_connection_gmail_inbox_priority_chat_ref_v1($scope,$row['batch_id'],$item['item_ref'],$agentId,$conversationId);
            $context = ['source'=>'agent_connection_gmail_inbox_update','skip_brain_archive'=>true,
                'tool_receipt'=>vp3_tool_receipt_v1('email.read','review_status','read_complete'),
                'gmail_inbox_priority_v1'=>['run_id'=>$row['batch_id'],'item_index'=>$index,
                    'item_ref'=>$item['item_ref'],'report_ref'=>$ref]];
            $insert->execute([$conversationId,'A high-priority Gmail message needs your attention.',json_encode($context,JSON_THROW_ON_ERROR),$now]);
            $messageId = (int)$pdo->lastInsertId();
            if ($messageId<1) throw new RuntimeException('Priority Gmail Chat post could not be verified.',503);
            $priorityPosts[$index] = $messageId;
            if ($bound !== null && isset($alerts[$item['provider_id']]) && agent_email_matters_ready_v1($pdo)) {
                require_once __DIR__.'/agent-email-triage-v1.php';
                if (agent_email_triage_ready_v1($pdo)) {
                    $triage = agent_email_triage_find_v1($pdo,$bound,$alerts[$item['provider_id']]['triage_id']);
                    $matter = $triage['matter_id'] ?? null;
                    if (is_string($matter) && agent_email_matters_find_v1($pdo,$bound,$matter) !== null) {
                        agent_email_matters_event_v1($pdo,$matter,agent_connection_gmail_inbox_update_alert_key_v1($scope,$item['provider_id']),
                            'inbox_alert',$now,$row['batch_id'],$messageId);
                    }
                }
            }
            $posted++;
        }
        $remaining = $report === null ? 0 : agent_connection_gmail_inbox_report_alert_count_v1($report)
            - count($covered) - count($priorityPosts);
        // Content-analysis handles ordinary actionable mail independently.
        // A priority watch always goes to Chat, without duplicating other triage posts.
        $normalPost = $digest || ($remaining>0 && !agent_email_running_report_enabled_v1($scope['policy']));
        if (!$normalPost) {
            // The batch is complete even when only its individual priority posts were emitted.
            $attach->execute([$agentId,$conversationId,0,$row['id'],$scope['owner']]);
            if ($attach->rowCount() !== 1) throw new RuntimeException('Gmail update changed.',409);
            if (!empty($row['notification_id']) && table_exists('notifications')) {
                if ($priorityPosts) {
                    $target = url('/chat.php?agent='.($agentId ?: 'system').'&conversation_id='.$conversationId
                        .'&message_id='.reset($priorityPosts));
                    $pdo->prepare("UPDATE notifications SET target_url=? WHERE id=? AND user_id=?
                        AND type='agent_email_inbox_review' AND source_type='agent_connection_gmail_inbox' AND source_id=?")
                        ->execute([$target,$row['notification_id'],$scope['owner'],$scope['generation']]);
                } elseif ($remaining<=0) {
                    $pdo->prepare("DELETE FROM notifications WHERE id=? AND user_id=? AND type='agent_email_inbox_review'
                        AND source_type='agent_connection_gmail_inbox' AND source_id=?")
                        ->execute([$row['notification_id'],$scope['owner'],$scope['generation']]);
                }
            }
            continue;
        }
        $context = ['source'=>'agent_connection_gmail_inbox_update','skip_brain_archive'=>true,
            'tool_receipt'=>vp3_tool_receipt_v1('email.read','review_status','read_complete'),
            'gmail_inbox_update_v1'=>array_intersect_key($binding,array_flip(['run_id','report_ref']))];
        $suppressed = array_column(array_intersect_key($alerts,$covered),'item_ref');
        if ($priorityPosts) $suppressed = array_merge($suppressed,array_map(static fn(int $index):string=>$priorityRows[$index]['item_ref'],array_keys($priorityPosts)));
        if ($suppressed) $context['gmail_inbox_update_v1']['suppressed_item_refs'] = array_values(array_unique($suppressed));
        if ($digest) $context['gmail_inbox_update_v1']['routine_digest'] = true;
        $intro = $digest ? 'I imported the latest emails.' : 'Your Gmail inbox update is ready.';
        $insert->execute([$conversationId,$intro,json_encode($context,JSON_THROW_ON_ERROR),$now]);
        $message = (int)$pdo->lastInsertId();
        if ($message < 1) throw new RuntimeException('Gmail update post could not be verified.',503);
        $attach->execute([$agentId,$conversationId,$message,$row['id'],$scope['owner']]);
        if ($attach->rowCount() !== 1) throw new RuntimeException('Gmail update changed.',409);
        if ($alerts && agent_email_matters_ready_v1($pdo)) {
            require_once __DIR__.'/agent-email-triage-v1.php';
            if (agent_email_triage_ready_v1($pdo)) foreach (array_diff_key($alerts,$covered) as $providerId=>$item) {
                if (in_array($providerId,array_column(array_intersect_key($priorityRows,$priorityPosts),'provider_id'),true)) continue;
                $triage = agent_email_triage_find_v1($pdo,$bound,$item['triage_id']);
                $matter = $triage['matter_id'] ?? null;
                if (is_string($matter) && agent_email_matters_find_v1($pdo,$bound,$matter) !== null) {
                    agent_email_matters_event_v1($pdo,$matter,agent_connection_gmail_inbox_update_alert_key_v1($scope,(string)$providerId),
                        'inbox_alert',$now,$row['batch_id'],$message);
                }
            }
        }
        if (!empty($row['notification_id']) && table_exists('notifications')) {
            $target = url('/chat.php?agent='.($agentId ?: 'system').'&conversation_id='.$conversationId.'&message_id='.$message);
            $body = $remaining === 1 ? 'One Gmail message needs your attention.' : $remaining.' Gmail messages need your attention.';
            $pdo->prepare("UPDATE notifications SET target_url=?,body=CASE WHEN ?=1 THEN ? ELSE body END
                WHERE id=? AND user_id=? AND type='agent_email_inbox_review'
                AND source_type='agent_connection_gmail_inbox' AND source_id=?")
                ->execute([$target,(int)(bool)$covered,$body,$row['notification_id'],$scope['owner'],$scope['generation']]);
        }
        $posted++;
    }
    if ($posted) $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')
        ->execute([$now,$conversationId,$scope['owner']]);
    return $posted;
}

/** Once selected, an existing inbox destination wins over unrelated open Chats. */
function agent_connection_gmail_inbox_update_destination_v1(PDO $pdo,array $scope): ?array
{
    return agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$scope['user'])
        ?? agent_connection_gmail_inbox_update_latest_v1($pdo,$scope,includeRoutine:false);
}

function agent_connection_gmail_inbox_update_deliver_v1(PDO $pdo,array $user,int $agentId,int $conversationId): array
{
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$agentId,$conversationId): array {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,true);
        if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null
            || !agent_connection_gmail_inbox_batches_ready_v1($pdo)
            || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId)) {
            throw new RuntimeException('A private owner Chat is required.',403);
        }
        if (!agent_email_running_report_enabled_v1($scope['policy'])) {
            // Upgrade a saved latest batch in place without Gmail or AI calls.
            agent_connection_gmail_inbox_batch_save_v1($pdo,$scope['policy'],(string)($scope['policy']['last_review_json'] ?? ''));
        }
        $destination = agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
        $targetAgent = $destination['agent_id'] ?? $agentId;
        $targetChat = $destination['conversation_id'] ?? $conversationId;
        agent_connection_gmail_inbox_update_post_v1($pdo,$scope,$targetAgent,$targetChat);
        return agent_connection_gmail_inbox_update_state_v1($pdo,$scope);
    });
}

function agent_connection_gmail_inbox_update_history_v1(PDO $pdo,array $scope,int $agentId,int $conversationId,array $context,int $messageId): ?array
{
    $id = $context['gmail_inbox_update_v1']['run_id'] ?? null;
    if (!is_string($id)) return null;
    $row = agent_connection_gmail_inbox_batch_v1($pdo,$scope,$id);
    if ($row === null) return null;
    $binding = agent_connection_gmail_inbox_update_binding_v1($scope,$row);
    if ($binding['agent_id'] !== $agentId || $binding['conversation_id'] !== $conversationId
        || $binding['message_id'] !== $messageId || !agent_connection_gmail_inbox_chat_marker_matches_v1($context,$binding)) return null;
    $report = agent_connection_gmail_inbox_batch_report_v1($row['report_json']);
    if ($report === null) return null;
    $digest = ($report['routine_digest'] ?? false) === true;
    if ($digest !== (($context['gmail_inbox_update_v1']['routine_digest'] ?? false) === true)
        || ($digest && !hash_equals($id,agent_connection_gmail_inbox_batch_id_v1($scope['policy'],$row['report_json'])))) return null;
    $legacy = !$digest && agent_connection_gmail_inbox_legacy_window_v1($scope,$report) !== null;
    $items = agent_connection_gmail_inbox_review_items_v1($pdo,$report,$scope,'batch:'.$id,$legacy,$digest);
    if ($digest) {
        if (!$items && (count($report['items']) !== $report['total']
            || array_filter($report['items'],static fn(mixed $item): bool => !is_array($item)
                || !agent_connection_gmail_inbox_routine_item_v1($item)
                || !agent_connection_gmail_inbox_ignored_v1($item,$scope['policy']['rules'])))) return null;
        $report['total'] = count($items);
        $report['important'] = 0;
        $report['replies'] = count(array_filter($items,static fn(array $item): bool => $item['category'] === 'reply'));
    } else {
        $suppressed = (array)($context['gmail_inbox_update_v1']['suppressed_item_refs'] ?? []);
        $triaged = ($report['triage_enabled'] ?? false) === true;
        $items = array_values(array_filter($items,static fn(array $item): bool =>
            (!$triaged || ($item['triage']['alert'] ?? false) === true)
            && !in_array($item['item_ref'] ?? null,$suppressed,true)));
        if (!$items) return null;
        $report['total'] = count($items);
        $report['important'] = count(array_filter($items,static fn(array $item): bool => !empty($item['important'])));
        $report['replies'] = count(array_filter($items,static fn(array $item): bool => $item['category'] === 'reply'));
    }
    return $binding + ['scope_ref'=>agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        'review'=>['state'=>'ready','run_id'=>$id,'total'=>(int)$report['total'],
        'at'=>str_replace(' ','T',$report['at']).'Z','important'=>(int)($report['important'] ?? 0),'replies'=>(int)($report['replies'] ?? 0),
        'items'=>$items]] + ($digest ? ['routine_digest'=>true] : []);
}

/** A conversational yes can select only one exact, priority-marked message. */
function agent_connection_gmail_inbox_urgent_offer_v1(array $view): ?array
{
    if (($view['routine_digest'] ?? false) === true) return null;
    $items = array_values(array_filter($view['review']['items'] ?? [], static function (array $item): bool {
        if (!empty($item['answered_in_gmail'])) return false;
        if (isset($item['triage'])) return is_string($item['item_ref'] ?? null) && ($item['triage']['alert'] ?? false) === true;
        return is_string($item['item_ref'] ?? null)
            && (bool)array_intersect(['urgent','action required','past due'], (array)($item['priority_signals'] ?? []));
    }));
    return count($items) === 1 ? $items[0] : null;
}

function agent_connection_gmail_inbox_urgent_offer_text_v1(array $item,array $user): string
{
    $clean = static fn(string $text,int $limit): string => trim(mb_substr(
        preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u',' ',$text) ?? '',0,$limit,'UTF-8'));
    $name = $clean((string)($user['display_name'] ?? ''),60);
    $name = explode(' ',$name,2)[0];
    $sender = $clean((string)($item['from'] ?? ''),160);
    $deadline = $item['triage']['due_at'] ?? $item['triage']['due_date'] ?? '';
    $date = is_string($deadline) && $deadline !== '' ? strtotime(strlen($deadline) === 10 ? $deadline.' UTC' : $deadline) : false;
    $timing = $date === false ? '' : ' It mentions a deadline on '
        .gmdate(strlen($deadline) > 10 ? 'M j, Y \a\t H:i \U\T\C' : 'M j, Y',$date).'.';
    return ($name === '' ? '' : $name.', ').'an email'
        .($sender === '' ? '' : ' from '.$sender).' may need your attention.'.$timing.' Want me to open it?';
}

/** Add live report detail only to the exact durable marker returned to the private owner. */
const VP3_GMAIL_CONTENT_CHAT_MARKER_V1 = 'A Gmail finding needs your attention.';

function agent_connection_gmail_content_ref_v1(array $scope,int $agentId,int $conversationId,string $threadKey,string $sealed): string
{
    return agent_connection_gmail_inbox_key_v1($scope['policy'],
        'gmail-content-chat:'.$scope['generation'].':'.$agentId.':'.$conversationId.':'.$threadKey.':'.hash('sha256',$sealed));
}

/** Older releases posted a marker for this exact sealed finding. Do not replay it on upgrade. */
function agent_connection_gmail_content_legacy_delivered_v1(PDO $pdo,array $scope,int $agentId,string $threadKey,string $sealed): bool
{
    if (agent_connection_gmail_inbox_receipts_ready_v1($pdo)) {
        // The old foreground receipt intentionally survived Chat switches and deletion.
        $receipt = agent_connection_gmail_inbox_key_v1($scope['policy'],
            'gmail-content-active-handoff:'.$scope['generation'].':'.$threadKey.':'.hash('sha256',$sealed));
        $lookup = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_receipts_v1 WHERE owner_user_id=? AND message_key=?');
        $lookup->execute([$scope['owner'],$receipt]);
        if ($lookup->fetchColumn() !== false) return true;
    }
    $agent = $agentId > 0 ? ['id'=>$agentId] : null;
    [$agentScope,$args] = vp3_agent_chat_scope_sql_v380($agent,'c');
    $query = $pdo->prepare("SELECT m.conversation_id,m.context_json FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=? AND $agentScope
        WHERE m.user_id IS NULL AND m.role='assistant' AND m.message=? AND m.context_json LIKE ?
        ORDER BY m.id DESC LIMIT 16");
    $query->execute([$scope['owner'],...$args,VP3_GMAIL_CONTENT_CHAT_MARKER_V1,'%'.$threadKey.'%']);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context = json_decode($row['context_json'],true,12);
        $marker = $context['gmail_content_chat_v1'] ?? null;
        if (($context['source'] ?? null) === 'agent_connection_gmail_content'
            && ($marker['thread_key'] ?? null) === $threadKey && is_string($marker['ref'] ?? null)
            && hash_equals(agent_connection_gmail_content_ref_v1($scope,$agentId,(int)$row['conversation_id'],$threadKey,$sealed),$marker['ref'])) {
            return true;
        }
    }
    return false;
}

function agent_connection_gmail_content_chat_finding_v1(string $sealed): ?array
{
    $finding = json_decode(ai_decrypt_secret($sealed),true,8);
    if (($finding['handoff'] ?? null) !== 'needs_attention' || ($finding['state'] ?? null) !== 'open'
        || !is_string($finding['summary'] ?? null) || trim($finding['summary']) === '') return null;
    return $finding;
}

function agent_connection_gmail_content_chat_text_v1(PDO $pdo,array $scope,int $agent,int $conversation,array $marker): ?string
{
    $key = $marker['thread_key'] ?? null;
    $ref = $marker['ref'] ?? null;
    if (!agent_email_running_report_enabled_v1($scope['policy']) || !agent_email_running_report_ready_v1($pdo)
        || !is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/D',$key) || !is_string($ref)) return null;
    $bound = agent_email_matters_policy_scope_v1($scope['policy']);
    if ($bound === null) return null;
    $query = $pdo->prepare('SELECT finding_enc FROM agent_email_running_report_v1
        WHERE thread_key=? AND owner_user_id=? AND connection_id=? AND grant_key=? AND due_at IS NULL AND failures=0');
    $query->execute([$key,...$bound]);
    $sealed = $query->fetchColumn();
    if (!is_string($sealed) || !hash_equals(agent_connection_gmail_content_ref_v1($scope,$agent,$conversation,$key,$sealed),$ref)) return null;
    $finding = agent_connection_gmail_content_chat_finding_v1($sealed);
    if ($finding === null) return null;
    $text = 'From your Gmail review: '.$finding['summary'];
    $next = agent_email_matters_metadata_v1($finding['next_step'] ?? null,320);
    $question = agent_email_matters_metadata_v1($finding['followup_question'] ?? null,320);
    if ($next !== '') $text .= "\n\nNext step: ".$next;
    if ($question !== '') $text .= "\n\nQuestion for you: ".$question;
    return $text;
}

function agent_connection_gmail_inbox_chat_history_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $messages): array
{
    $candidates = [];
    $routineText = [];
    foreach ($messages as $index=>$message) {
        $raw = $message['context_json'] ?? null;
        if (!is_string($raw) || (!str_contains($raw,'gmail_first_review_v1') && !str_contains($raw,'gmail_inbox_update_v1')
            && !str_contains($raw,'gmail_inbox_priority_v1')
            && !str_contains($raw,'gmail_inbox_review_v1') && !str_contains($raw,'gmail_content_chat_v1'))) continue;
        $context = json_decode($raw,true,24);
        if (!is_array($context)) continue;
        if (array_key_exists('gmail_inbox_review_v1',$context)) {
            unset($context['gmail_inbox_review_v1']);
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if (($message['role'] ?? null) !== 'assistant') continue;
        if (($context['source'] ?? null) === 'agent_connection_gmail_inbox_update'
            && ($context['gmail_inbox_update_v1']['routine_digest'] ?? false) === true) {
            $routineText[$index] = $message['message'] ?? 'I imported the latest emails.';
            $messages[$index]['message'] = 'Saved Gmail update details are unavailable.';
        }
        if (is_array($context['gmail_content_chat_v1'] ?? null)) {
            $messages[$index]['message'] = VP3_GMAIL_CONTENT_CHAT_MARKER_V1;
            $safeContext = $context;
            unset($safeContext['gmail_content_chat_v1']);
            $messages[$index]['context_json'] = json_encode($safeContext,JSON_THROW_ON_ERROR);
        }
        if (isset($context['gmail_first_review_v1']) || isset($context['gmail_inbox_update_v1'])
            || isset($context['gmail_inbox_priority_v1'])
            || is_array($context['gmail_content_chat_v1'] ?? null)) $candidates[$index] = $context;
    }
    if (!$candidates || $agentId < 0 || $conversationId < 1) return $messages;
    $markers = $messages;
    try {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
        if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null
            || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId)) return $messages;
        $firstReviewChecked = false;
        $firstReviewBinding = null;
        foreach ($candidates as $index=>$context) {
            $messageId = (int)($messages[$index]['id'] ?? 0);
            if (is_array($context['gmail_content_chat_v1'] ?? null)) {
                if ((int)($messages[$index]['conversation_id'] ?? $conversationId) !== $conversationId) continue;
                $text = agent_connection_gmail_content_chat_text_v1($pdo,$scope,$agentId,$conversationId,$context['gmail_content_chat_v1']);
                if ($text !== null) $messages[$index]['message'] = $text;
                continue;
            }
            if (isset($context['gmail_inbox_priority_v1'])) {
                try {
                    $view = agent_connection_gmail_inbox_priority_history_v1($pdo,$scope,$agentId,$conversationId,$context,$messageId);
                } catch (Throwable $error) {
                    if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_priority.chat_unavailable',['error_class'=>get_class($error)]);
                    continue;
                }
                if ($view === null) continue;
                $item = $view['review']['items'][0];
                $messages[$index]['message'] = 'Priority Gmail: '.mb_substr((string)$item['subject'],0,240,'UTF-8')
                    ."\nFrom: ".mb_substr((string)$item['from'],0,240,'UTF-8')
                    ."\nMatched: ".implode('; ',$item['priority_matches'] ?? [])
                    ."\nNext step: Open the message and review or reply.";
                $context['gmail_inbox_review_v1'] = $view;
                $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                continue;
            }
            if (isset($context['gmail_inbox_update_v1'])) {
                try {
                    $view = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agentId,$conversationId,$context,$messageId);
                } catch (Throwable $error) {
                    // One unreadable saved batch cannot hide another authorized batch.
                    if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_digest.chat_unavailable',['error_class'=>get_class($error)]);
                    continue;
                }
                if ($view === null) continue;
                if (isset($routineText[$index])) {
                    if ($view['review']['total'] === 0) {
                        $messages[$index]['message'] = 'No messages from this saved Gmail update match your current Inbox Review rules.';
                        continue;
                    }
                    $messages[$index]['message'] = $routineText[$index];
                }
                $context['gmail_inbox_review_v1'] = $view;
                $offer = agent_connection_gmail_inbox_urgent_offer_v1($view);
                if ($offer !== null) {
                    $messages[$index]['message'] = agent_connection_gmail_inbox_urgent_offer_text_v1($offer,$scope['user']);
                    $context['gmail_conversation_offer'] = true;
                }
            } else {
                try {
                    if (!$firstReviewChecked) {
                        $firstReviewChecked = true;
                        $firstReviewBinding = agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$user);
                    }
                    if ($firstReviewBinding === null || $firstReviewBinding['agent_id'] !== $agentId
                        || $firstReviewBinding['conversation_id'] !== $conversationId || $messageId !== $firstReviewBinding['message_id']
                        || !agent_connection_gmail_inbox_chat_marker_matches_v1($context,$firstReviewBinding)) continue;
                    $view = agent_connection_gmail_inbox_initial_view_v1($pdo,$user,includeChatResult:true,includeLegacyMatches:true);
                    if (($view['run_id'] ?? null) !== $firstReviewBinding['run_id'] || !is_array($view['chat_result'] ?? null)) continue;
                    $context['gmail_inbox_review_v1'] = $firstReviewBinding + ['review'=>$view];
                    $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                } catch (Throwable $error) {
                    // An unavailable first review cannot hide independently verified arriving mail.
                    if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_first_review.chat_unavailable',['error_class'=>get_class($error)]);
                }
                continue;
            }
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        $live = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
        if ($live['state'] !== 'ready' || !$live['provider_ready'] || $live['generation'] !== $scope['generation']
            || $live['policy']['revision'] !== $scope['policy']['revision']) return $markers;
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_first_review.chat_unavailable',['error_class'=>get_class($error)]);
        return $markers;
    }
    return $messages;
}
