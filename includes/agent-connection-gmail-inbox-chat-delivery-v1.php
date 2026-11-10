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
        $view = agent_connection_gmail_inbox_initial_view_v1($pdo,$scope['user'],includeChatResult:true);
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

/** Add live report detail only to the exact durable marker returned to the private owner. */
function agent_connection_gmail_inbox_chat_history_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $messages): array
{
    $candidates = [];
    foreach ($messages as $index=>$message) {
        $raw = $message['context_json'] ?? null;
        if (!is_string($raw) || (!str_contains($raw,'gmail_first_review_v1') && !str_contains($raw,'gmail_inbox_review_v1'))) continue;
        $context = json_decode($raw,true,24);
        if (!is_array($context)) continue;
        if (array_key_exists('gmail_inbox_review_v1',$context)) {
            unset($context['gmail_inbox_review_v1']);
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        if (($message['role'] ?? null) === 'assistant' && isset($context['gmail_first_review_v1'])) $candidates[$index] = $context;
    }
    if (!$candidates || $agentId < 0 || $conversationId < 1) return $messages;
    try {
        $binding = agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$user);
        if ($binding === null || $binding['agent_id'] !== $agentId || $binding['conversation_id'] !== $conversationId) return $messages;
        foreach ($candidates as $index=>$context) {
            if ((int)($messages[$index]['id'] ?? 0) !== $binding['message_id'] || !agent_connection_gmail_inbox_chat_marker_matches_v1($context,$binding)) continue;
            $view = agent_connection_gmail_inbox_initial_view_v1($pdo,$user,includeChatResult:true);
            if (($view['run_id'] ?? null) !== $binding['run_id'] || !is_array($view['chat_result'] ?? null)) break;
            $context['gmail_inbox_review_v1'] = $binding + ['review'=>$view];
            $messages[$index]['context_json'] = json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;
        }
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) agent_runtime_v125_trace('gmail_first_review.chat_unavailable',['error_class'=>get_class($error)]);
    }
    return $messages;
}
