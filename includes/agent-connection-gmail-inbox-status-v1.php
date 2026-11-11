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

/** Give the normally scheduled worker two minutes, or wait for an existing lease to expire. */
function agent_connection_gmail_inbox_due_overdue_v1(?DateTimeImmutable $due, bool $claimed, DateTimeImmutable $now): bool
{
    return $due !== null && $due->getTimestamp() + ($claimed ? 0 : 120) <= $now->getTimestamp();
}

function agent_connection_gmail_inbox_overdue_ref_v1(array $policy): string
{
    return agent_connection_gmail_inbox_key_v1($policy,'overdue-check-v1:'.$policy['revision'].':'.$policy['next_check_at'].':'.$policy['claim_key']);
}

/** Check only this owner's overdue review. Stale requests cannot advance a different checkpoint. */
function agent_connection_gmail_inbox_check_overdue_v1(PDO $pdo, array $user, string $checkRef, ?callable $http=null): void
{
    if ($pdo->inTransaction()) return;
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready' || !$scope['provider_ready'] || $scope['generation'] === null) return;
    $policy = $scope['policy'];
    if ($policy['last_status'] === 'restart_required'
        || !agent_connection_gmail_inbox_due_overdue_v1(agent_connection_gmail_inbox_status_time_v1($policy['next_check_at']),
            !empty($policy['claim_key']),new DateTimeImmutable('now',new DateTimeZone('UTC')))
        || !hash_equals(agent_connection_gmail_inbox_overdue_ref_v1($policy),$checkRef)
        || !agent_connection_gmail_inbox_receipts_ready_v1($pdo) || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return;

    agent_connection_gmail_inbox_run_due_v1($pdo,$policy,$http);
}

/** Read-only projection of the current owner's recurring review, independent of first review. */
function agent_connection_gmail_inbox_background_status_v1(
    PDO $pdo, array $user, ?int $agentId=null, ?DateTimeImmutable $now=null, bool $includeResults=true
): ?array {
    $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user);
    if ($scope['state'] !== 'ready') return null;
    return agent_connection_gmail_inbox_background_view_v1($pdo,$scope,$agentId,$now,$includeResults);
}

function agent_connection_gmail_inbox_background_view_v1(
    PDO $pdo, array $scope, ?int $agentId=null, ?DateTimeImmutable $now=null, bool $includeResults=true, bool $includeCheckRef=false
): array {
    $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $policy = $scope['policy'];
    $lastChecked = agent_connection_gmail_inbox_status_time_v1($policy['last_checked_at']);
    $due = agent_connection_gmail_inbox_status_time_v1($policy['next_check_at']);
    $claimed = !empty($policy['claim_key']);
    $working = $claimed && $due !== null && $due > $now;
    $canReadBatches = $scope['provider_ready'] && $scope['generation'] !== null
        && agent_connection_gmail_inbox_batches_ready_v1($pdo);
    $ready = $canReadBatches && agent_connection_gmail_inbox_receipts_ready_v1($pdo);

    $state = match (true) {
        !$ready => 'unavailable',
        $policy['last_status'] === 'restart_required' => 'restart_required',
        $due === null => 'unavailable',
        $working => 'working',
        agent_connection_gmail_inbox_due_overdue_v1($due,$claimed,$now) => 'overdue',
        $policy['last_status'] === 'retrying' => 'retrying',
        $policy['last_status'] === 'checked' && $lastChecked !== null => 'checked',
        $policy['last_status'] === 'waiting' => 'scheduled',
        default => 'unavailable',
    };
    $latest = $includeResults && $canReadBatches && $agentId !== null && $agentId >= 0
        ? agent_connection_gmail_inbox_update_latest_v1($pdo,$scope,$agentId) : null;

    $view = [
        'state'=>$state,
        'last_checked_at'=>$lastChecked?->format('Y-m-d\TH:i:s\Z'),
        'next_check_at'=>$claimed ? null : $due?->format('Y-m-d\TH:i:s\Z'),
        'lease_until'=>$ready && $working ? $due->format('Y-m-d\TH:i:s\Z') : null,
        'pending'=>$includeResults && $canReadBatches && agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope),
        'latest'=>$latest === null ? null : array_intersect_key($latest,array_flip(['agent_id','conversation_id','message_id'])),
    ];
    if ($includeCheckRef && $state === 'overdue') $view['check_ref'] = agent_connection_gmail_inbox_overdue_ref_v1($policy);
    return $view;
}
