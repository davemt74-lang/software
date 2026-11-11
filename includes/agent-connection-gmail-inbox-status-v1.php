<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-chat-delivery-v1.php';

function agent_connection_gmail_inbox_status_time_v1(mixed $value): ?DateTimeImmutable
{
    if (!is_string($value)) return null;
    $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
    return $time && $time->format('Y-m-d H:i:s') === $value ? $time : null;
}

/** Read-only projection of the current owner's recurring review, independent of first review. */
function agent_connection_gmail_inbox_background_status_v1(
    PDO $pdo, array $user, ?int $agentId=null, ?DateTimeImmutable $now=null, bool $includeResults=true
): ?array {
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready') return null;

    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $policy = $scope['policy'];
    $lastChecked = agent_connection_gmail_inbox_status_time_v1($policy['last_checked_at']);
    $due = agent_connection_gmail_inbox_status_time_v1($policy['next_check_at']);
    $claimed = !empty($policy['claim_key']);
    $working = $claimed && $due !== null && $due > $now;
    $canReadBatches = $scope['provider_ready'] && $scope['generation'] !== null
        && agent_connection_gmail_inbox_batches_ready_v1($pdo);
    $ready = $canReadBatches && agent_connection_gmail_inbox_receipts_ready_v1($pdo);

    // Shared hosting runs the worker once a minute. A scheduled check can be briefly due between invocations.
    $state = match (true) {
        !$ready => 'unavailable',
        $policy['last_status'] === 'restart_required' => 'restart_required',
        $due === null => 'unavailable',
        $working => 'working',
        $claimed || $due->getTimestamp() + 120 <= $now->getTimestamp() => 'overdue',
        $policy['last_status'] === 'retrying' => 'retrying',
        $policy['last_status'] === 'checked' && $lastChecked !== null => 'checked',
        $policy['last_status'] === 'waiting' => 'scheduled',
        default => 'unavailable',
    };
    $latest = $includeResults && $canReadBatches && $agentId !== null && $agentId >= 0
        ? agent_connection_gmail_inbox_update_latest_v1($pdo,$scope,$agentId) : null;

    return [
        'state'=>$state,
        'last_checked_at'=>$lastChecked?->format('Y-m-d\TH:i:s\Z'),
        'next_check_at'=>$claimed ? null : $due?->format('Y-m-d\TH:i:s\Z'),
        'lease_until'=>$ready && $working ? $due->format('Y-m-d\TH:i:s\Z') : null,
        'pending'=>$includeResults && $canReadBatches && agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope),
        'latest'=>$latest === null ? null : array_intersect_key($latest,array_flip(['agent_id','conversation_id','message_id'])),
    ];
}
