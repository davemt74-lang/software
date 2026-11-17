<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
require_once __DIR__.'/agent-email-triage-v1.php';
require_once __DIR__.'/agent-email-running-report-v1.php';

/** An action is attributable only to an item actually visible in this saved private digest. */
function agent_connection_gmail_digest_item_scope_v1(PDO $pdo,array $user,int $agentId,int $conversationId,
    int $chatMessageId,string $itemRef,bool $lock=false): array
{
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,$lock);
    if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null
        || !agent_connection_gmail_inbox_batches_ready_v1($pdo)
        || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId,$lock)) {
        throw new RuntimeException('A private Gmail digest is required.',403);
    }
    $read = $pdo->prepare("SELECT context_json FROM chat_messages
        WHERE id=? AND conversation_id=? AND user_id IS NULL AND role='assistant'"
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $read->execute([$chatMessageId,$conversationId]);
    $context = json_decode((string)$read->fetchColumn(),true,12);
    if (($context['gmail_inbox_update_v1']['routine_digest'] ?? false) !== true) {
        throw new RuntimeException('The Gmail digest is unavailable.',404);
    }
    $view = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,$agentId,$conversationId,$context,$chatMessageId);
    foreach ($view['review']['items'] ?? [] as $item) {
        if (($item['item_ref'] ?? null) === $itemRef) return $scope;
    }
    throw new RuntimeException('The Gmail digest item is unavailable.',404);
}

function agent_connection_gmail_digest_prioritize_v1(PDO $pdo,array $user,int $agentId,int $conversationId,
    int $chatMessageId,string $itemRef): ?string
{
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$agentId,$conversationId,$chatMessageId,$itemRef): ?string {
        $scope = agent_connection_gmail_digest_item_scope_v1($pdo,$user,$agentId,$conversationId,$chatMessageId,$itemRef,true);
        $policy = $scope['policy'];
        $bound = agent_email_triage_scope_v1($scope);
        if ($bound === null || !agent_email_triage_ready_v1($pdo)) return null;

        $item = agent_connection_gmail_inbox_item_v1($pdo,$user,$itemRef);
        $triageId = agent_email_matters_source_key_v1($policy,'triage',$item['message_id']);
        $triage = agent_email_triage_find_v1($pdo,$bound,$triageId,true);
        if ($triage === null || agent_connection_gmail_inbox_ignored_v1(
            ['from'=>$triage['sender_address'],'subject'=>$triage['subject']],$policy['rules'])) return null;
        if ($triage['attention_key'] === 'act_soon') return 'already_high';

        $pdo->prepare("UPDATE agent_email_triage_v1 SET attention_key='act_soon', group_key='active',
            reason_key='owner_digest_action',revision=revision+1 WHERE triage_id=? AND owner_user_id=? AND connection_id=? AND grant_key=?")
            ->execute([$triageId,...$bound]);
        if (agent_email_running_report_enabled_v1($policy) && agent_email_running_report_ready_v1($pdo)) {
            $thread = agent_connection_gmail_id_v1(ai_decrypt_secret((string)($triage['thread_id_enc'] ?? '')));
            if ($thread !== '') {
                agent_email_running_report_prioritize_v1($pdo,$policy,$bound,$thread,$triageId,$triage['received_ms']);
                require_once __DIR__.'/agent-coordination-source-change-v1.php';
                vp3_coordination_source_changed_v1($pdo,$scope['owner']);
            }
        }
        return 'high';
    });
}
