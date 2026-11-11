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
    $report = json_decode((string)$row['report_json'],true,12,JSON_THROW_ON_ERROR);
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

function agent_connection_gmail_inbox_update_latest_v1(PDO $pdo,array $scope,?int $agentId=null): ?array
{
    $query = $pdo->prepare('SELECT batch_id,agent_id,conversation_id,message_id FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id IS NOT NULL
        '.($agentId === null ? '' : 'AND agent_id=?').' ORDER BY message_id DESC LIMIT 1');
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
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id IS NULL ORDER BY id LIMIT 10');
    $query->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation']]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function agent_connection_gmail_inbox_update_has_pending_v1(PDO $pdo,array $scope): bool
{
    $raw = (string)($scope['policy']['last_review_json'] ?? '');
    if (agent_connection_gmail_inbox_batch_report_v1($raw) !== null
        && agent_connection_gmail_inbox_batch_v1($pdo,$scope,agent_connection_gmail_inbox_batch_id_v1($scope['policy'],$raw)) === null) return true;
    $query = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_batches_v1
        WHERE owner_user_id=? AND connection_id=? AND generation_id=? AND message_id IS NULL LIMIT 1');
    $query->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['generation']]);
    return $query->fetchColumn() !== false;
}

function agent_connection_gmail_inbox_update_state_v1(PDO $pdo,array $scope): array
{
    if (!agent_connection_gmail_inbox_batches_ready_v1($pdo)) return [];
    return ['inbox_updates'=>['scope_ref'=>agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        'pending'=>agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope),
        'latest'=>agent_connection_gmail_inbox_update_latest_v1($pdo,$scope)]];
}

/** Run inside the same private Inbox transaction that owns these unposted batches. */
function agent_connection_gmail_inbox_update_post_v1(PDO $pdo,array $scope,int $agentId,int $conversationId): int
{
    if (!agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId,true)) return 0;
    $posted = 0;
    $now = agent_commerce_time_pa2();
    $insert = $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant','Your Gmail inbox update is ready.',?,?)");
    $attach = $pdo->prepare('UPDATE agent_connection_gmail_inbox_batches_v1 SET agent_id=?,conversation_id=?,message_id=?
        WHERE id=? AND owner_user_id=? AND message_id IS NULL');
    foreach (agent_connection_gmail_inbox_update_pending_v1($pdo,$scope) as $row) {
        $binding = agent_connection_gmail_inbox_update_binding_v1($scope,$row);
        $context = ['source'=>'agent_connection_gmail_inbox_update','skip_brain_archive'=>true,
            'tool_receipt'=>vp3_tool_receipt_v1('email.read','review_status','read_complete'),
            'gmail_inbox_update_v1'=>array_intersect_key($binding,array_flip(['run_id','report_ref']))];
        $insert->execute([$conversationId,json_encode($context,JSON_THROW_ON_ERROR),$now]);
        $message = (int)$pdo->lastInsertId();
        if ($message < 1) throw new RuntimeException('Gmail update post could not be verified.',503);
        $attach->execute([$agentId,$conversationId,$message,$row['id'],$scope['owner']]);
        if ($attach->rowCount() !== 1) throw new RuntimeException('Gmail update changed.',409);
        if (!empty($row['notification_id']) && table_exists('notifications')) {
            $target = url('/chat.php?agent='.($agentId ?: 'system').'&conversation_id='.$conversationId.'&message_id='.$message);
            $pdo->prepare("UPDATE notifications SET target_url=? WHERE id=? AND user_id=? AND type='agent_email_inbox_review'
                AND source_type='agent_connection_gmail_inbox' AND source_id=?")
                ->execute([$target,$row['notification_id'],$scope['owner'],$scope['generation']]);
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
        ?? agent_connection_gmail_inbox_update_latest_v1($pdo,$scope);
}

function agent_connection_gmail_inbox_update_deliver_v1(PDO $pdo,array $user,int $agentId,int $conversationId): array
{
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$agentId,$conversationId): array {
        $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user,true);
        if ($scope['state'] !== 'ready' || !agent_connection_gmail_inbox_batches_ready_v1($pdo)
            || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId)) {
            throw new RuntimeException('A private owner Chat is required.',403);
        }
        // Upgrade a saved latest batch in place without Gmail or AI calls.
        agent_connection_gmail_inbox_batch_save_v1($pdo,$scope['policy'],(string)($scope['policy']['last_review_json'] ?? ''));
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
    $legacy = agent_connection_gmail_inbox_legacy_window_v1($scope,$report) !== null;
    return $binding + ['scope_ref'=>agent_connection_gmail_inbox_update_scope_ref_v1($scope),
        'review'=>['state'=>'ready','run_id'=>$id,'total'=>(int)$report['total'],
        'at'=>str_replace(' ','T',$report['at']).'Z','important'=>(int)($report['important'] ?? 0),'replies'=>(int)($report['replies'] ?? 0),
        'items'=>agent_connection_gmail_inbox_review_items_v1($report,$scope,'batch:'.$id,$legacy)]];
}

/** Add live report detail only to the exact durable marker returned to the private owner. */
function agent_connection_gmail_inbox_chat_history_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $messages): array
{
    $candidates = [];
    foreach ($messages as $index=>$message) {
        $raw = $message['context_json'] ?? null;
        if (!is_string($raw) || (!str_contains($raw,'gmail_first_review_v1') && !str_contains($raw,'gmail_inbox_update_v1')
            && !str_contains($raw,'gmail_inbox_review_v1'))) continue;
        $context = json_decode($raw,true,24);
        if (!is_array($context)) continue;
        if (array_key_exists('gmail_inbox_review_v1',$context)) {
            unset($context['gmail_inbox_review_v1']);
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if (($message['role'] ?? null) === 'assistant'
            && (isset($context['gmail_first_review_v1']) || isset($context['gmail_inbox_update_v1']))) $candidates[$index] = $context;
    }
    if (!$candidates || $agentId < 0 || $conversationId < 1) return $messages;
    $markers = $messages;
    try {
        $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
        if ($scope['state'] !== 'ready' || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId)) return $messages;
        $binding = agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$user);
        foreach ($candidates as $index=>$context) {
            $messageId = (int)($messages[$index]['id'] ?? 0);
            if (isset($context['gmail_inbox_update_v1'])) {
                $view = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agentId,$conversationId,$context,$messageId);
                if ($view === null) continue;
                $context['gmail_inbox_review_v1'] = $view;
            } else {
                if ($binding === null || $binding['agent_id'] !== $agentId || $binding['conversation_id'] !== $conversationId
                    || $messageId !== $binding['message_id'] || !agent_connection_gmail_inbox_chat_marker_matches_v1($context,$binding)) continue;
                $view = agent_connection_gmail_inbox_initial_view_v1($pdo,$user,includeChatResult:true,includeLegacyMatches:true);
                if (($view['run_id'] ?? null) !== $binding['run_id'] || !is_array($view['chat_result'] ?? null)) continue;
                $context['gmail_inbox_review_v1'] = $binding + ['review'=>$view];
            }
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        $live = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
        if ($live['state'] !== 'ready' || $live['generation'] !== $scope['generation']
            || $live['policy']['revision'] !== $scope['policy']['revision']) return $markers;
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_first_review.chat_unavailable',['error_class'=>get_class($error)]);
        return $markers;
    }
    return $messages;
}
