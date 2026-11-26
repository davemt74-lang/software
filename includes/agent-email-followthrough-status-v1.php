<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-followthrough-v1.php';

/** Reuse the existing selected-Agent Initiative switch and exact private Chat binding. */
function agent_email_followthrough_draft_policy_v1(PDO $pdo,array $scope): array
{
    require_once __DIR__.'/agent-initiative-v1.php';
    $row=$scope['row'];$policy=vp3_initiative_policy_v1($scope['user'],(int)$row['agent_id']);
    if(($policy['enabled'] ?? false)!==true)return ['ready'=>false,'reason'=>'initiative_paused'];
    if(($policy['conversation_id'] ?? null)!==(int)$row['conversation_id'])return ['ready'=>false,'reason'=>'linked_elsewhere'];
    vp3_initiative_access_v1($pdo,$scope['user'],$policy);
    return ['ready'=>true,'binding'=>$policy];
}

/** A saved target is current only when its exact deadline marker and immutable source match. */
function agent_email_followthrough_saved_draft_v1(PDO $pdo,array $row): ?array
{
    $id=$row['generated_draft_id'] ?? null;
    if(!is_string($id) || (int)($row['generated_revision'] ?? 0)!==(int)$row['source_revision'])return null;
    try{
        $draft=agent_connection_email_chat_draft_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],$id);
        if(!in_array($draft['status'],['prepared','write_failed'],true))return null;
        $payload=agent_connection_email_chat_payload_v1($draft);$binding=$payload['outbound_followup'] ?? null;
        if(!is_array($binding) || ($binding['followthrough_id'] ?? null)!==$row['id']
            || ($binding['revision'] ?? null)!==(int)$row['source_revision'] || (int)$draft['user_message_id']!==(int)$row['user_message_id']
            || (int)$draft['connection_id']!==(int)$row['connection_id'] || $draft['authorization_key']!==$row['authorization_key']
            || $draft['account_email']!==$row['account_email']
            || ($binding['operation_key'] ?? null)!==($row['active_operation_key'] ?? $row['operation_key'])
            || trim((string)($payload['body'] ?? ''))==='')return null;
        return $draft;
    }catch(DomainException){return null;}
}

/** Provider-free readiness. Uncertain dispatch is never presented as still working or retried. */
function agent_email_followthrough_preparation_v1(PDO $pdo,array $row,?int $now=null): array
{
    $now??=time();$none=static fn(string $state,?string $reason=null):array=>['state'=>$state,'reason'=>$reason];
    $scope=agent_email_followthrough_binding_current_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],
        ['followthrough_id'=>$row['id']],false,false);$row=$scope['row'];
    if(empty($row['prepare_if_overdue']))return $none('not_requested');
    if($row['outcome']==='resolved')return $none('not_due','tracking_resolved');
    if($row['outcome']==='replied')return $none('not_due','reply_observed');
    if($row['paused'])return $none('paused');
    if($row['awaiting_new_deadline'])return $none('waiting_for_deadline');
    if((int)($row['generated_revision'] ?? 0)===(int)$row['source_revision']){
        if(agent_email_followthrough_saved_draft_v1($pdo,$row)!==null)return $none('ready');
        try{
            $draft=agent_connection_email_chat_draft_v1($pdo,(int)$row['owner_user_id'],(int)$row['agent_id'],(int)$row['conversation_id'],(string)$row['generated_draft_id']);
            if($draft['status']==='discarded' && (int)$draft['user_message_id']===(int)$row['user_message_id'])return $none('unavailable','draft_discarded');
        }catch(DomainException){}
        return $none('unavailable','draft_unavailable');
    }
    if((int)($row['draft_attempt_revision'] ?? 0)===(int)$row['source_revision']){
        $attempt=$row['draft_attempt_state'] ?? null;
        if(in_array($attempt,['preparing','dispatched'],true)){
            $live=is_string($row['draft_attempt_lease'] ?? null) && $row['draft_attempt_lease']>gmdate('Y-m-d H:i:s',$now)
                && is_string($scope['watch']['claim_key'] ?? null) && $scope['watch']['claim_key']!==''
                && $scope['watch']['next_check_at']>gmdate('Y-m-d H:i:s',$now);
            return $live?$none('preparing'):$none('unavailable','dispatch_uncertain');
        }
        if($attempt==='failed')return $none('failed',in_array($row['draft_attempt_reason'],['source_changed','source_unavailable','inference_failed','dispatch_uncertain'],true)
            ?$row['draft_attempt_reason']:'dispatch_uncertain');
    }
    $policy=agent_email_followthrough_draft_policy_v1($pdo,$scope);
    if(!$policy['ready'])return $none('blocked',$policy['reason']);
    if(!table_exists('agent_connection_email_drafts_v1'))return $none('blocked','upgrade_required');
    if((int)($row['draft_attempt_revision'] ?? 0)===(int)$row['source_revision'] && $row['draft_attempt_state']==='blocked'
        && in_array($row['draft_attempt_reason'],['source_changed','source_unavailable','model_unavailable','quota_exhausted','route_unavailable'],true))
        return $none('blocked',$row['draft_attempt_reason']);
    if($row['outcome']!=='overdue' || $row['check_state']!=='verified' || $row['check_result']!=='no_reply'
        || !is_string($row['checked_at']) || $row['checked_at']<$row['deadline_at'])return $none('not_due');
    return $none('not_due','awaiting_preparation');
}
