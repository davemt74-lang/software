<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-matters-v1.php';
require_once __DIR__.'/agent-email-triage-v1.php';
require_once __DIR__.'/agent-email-triage-assessment-v1.php';

/** Collect bounded source evidence before the inbox transaction takes locks. */
function agent_email_triage_enrich_inbox_v1(
    PDO $pdo,array $policy,array $scan,string $access,?callable $http,string $claim
): array {
    if ($pdo->inTransaction()) throw new LogicException('Email assessment must run outside the inbox transaction.');
    if (($policy['rules']['triage_enabled'] ?? false) !== true
        || (($scan['status'] ?? null) !== 'checked' && ($scan['historical'] ?? false) !== true) || empty($scan['messages'])
        || !agent_email_triage_ready_v1($pdo) || !agent_email_matters_ready_v1($pdo)) return $scan;
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($bound === null) return $scan;
    $current = static fn(): bool => agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim);
    if (!$current()) return ['status'=>'retrying'];

    $candidates = [];
    foreach ($scan['messages'] as $key=>$message) {
        if (!is_array($message) || agent_connection_gmail_inbox_ignored_v1($message,$policy['rules'])) continue;
        $thread = agent_connection_gmail_id_v1($message['thread_id'] ?? null);
        if ($thread === '') continue;
        $matterId = agent_email_matters_source_key_v1($policy,'thread',$thread);
        $matter = agent_email_matters_find_v1($pdo,$bound,$matterId);
        $open = $matter !== null && !in_array($matter['stage'],['dismissed','resolved','actioned'],true);
        $priority = agent_email_triage_assessment_priority_v1($message,$open);
        if ($priority > 0) $candidates[$key] = ['priority'=>$priority,'open'=>$open];
    }
    if (!$candidates) return $scan;
    uasort($candidates,static fn(array $a,array $b): int => $b['priority'] <=> $a['priority']);
    $budget = agent_email_triage_assessment_budget_v1();
    $zone = null;
    $owner = agent_connection_gmail_inbox_user_v1($pdo,(int)$policy['owner_user_id']);
    $name = $owner['timezone'] ?? '';
    if (is_string($name) && $name !== '') {
        try { $zone = new DateTimeZone($name); } catch (Exception) {}
    }
    foreach ($candidates as $key=>$candidate) {
        $assessment = agent_email_triage_assess_v1($access,$scan['messages'][$key],$candidate['open'],$current,$budget,$http,$zone);
        if (($assessment['status'] ?? null) === 'revoked') return ['status'=>'retrying'];
        $scan['messages'][$key]['assessment'] = $assessment;
        if ($candidate['open'] && ($assessment['thread_status'] ?? null) === 'verified'
            && is_string($assessment['reply_change'] ?? null)) {
            $scan['messages'][$key]['verified_transition'] = true;
        }
    }
    if (!empty($budget['provider_blocked'])) $scan['activity_deferred'] = true;
    return $current() ? $scan : ['status'=>'retrying'];
}
