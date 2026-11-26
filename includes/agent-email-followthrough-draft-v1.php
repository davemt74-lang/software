<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-email-chat-v1.php';
require_once __DIR__.'/agent-email-followthrough-source-v1.php';
require_once __DIR__.'/agent-email-followthrough-status-v1.php';
require_once __DIR__.'/agent-initiative-model-v1.php';
require_once __DIR__.'/agent-email-followthrough-notice-v1.php';

/** One private draft belongs to one actual owner deadline request, across pause/resume controls. */
function agent_email_followthrough_draft_id_v1(array $row): string
{
    return substr(hash('sha256','vp3-gmail-followthrough-draft-v1:'.$row['id'].':'.(int)$row['source_revision'].':'.(int)$row['user_message_id']),0,32);
}

/** This setting authorizes private preparation only; it never authorizes sending. */
function agent_email_followthrough_draft_eligible_v1(array $row): bool
{
    return ($row['outcome'] ?? null)==='overdue' && empty($row['paused']) && empty($row['awaiting_new_deadline']) && !empty($row['prepare_if_overdue'])
        && ($row['check_state'] ?? null)==='verified' && ($row['check_result'] ?? null)==='no_reply'
        && is_string($row['checked_at'] ?? null) && $row['checked_at'] >= $row['deadline_at'];
}

function agent_email_followthrough_draft_attempt_v1(PDO $pdo,array $expected,string $watchClaim,string $attempt,
    bool $lock=false): array
{
    $scope=agent_email_followthrough_current_v1($pdo,$expected,$watchClaim,$lock);$row=$scope['row'];
    if(!agent_email_followthrough_draft_eligible_v1($row)
        || (int)($row['draft_attempt_revision'] ?? 0)!==(int)$expected['source_revision']
        || !hash_equals($attempt,(string)($row['draft_attempt_key'] ?? ''))
        || !in_array($row['draft_attempt_state'] ?? null,['preparing','dispatched'],true)
        || !is_string($row['draft_attempt_lease'] ?? null) || $row['draft_attempt_lease']<=agent_commerce_time_pa2()) {
        throw new DomainException('The email follow-up preparation changed.',409);
    }
    return $scope;
}

/** Reasons are fixed diagnostics. Provider errors and private source text are never recorded here. */
function agent_email_followthrough_draft_reason_v1(Throwable $error,bool $dispatched): string
{
    if($dispatched && $error->getMessage()==='dispatch_uncertain')return 'dispatch_uncertain';
    if($error instanceof AgentConnectionGmailExceptionV1 || $error instanceof AgentConnectionExceptionV1)return 'source_unavailable';
    if($error instanceof DomainException || $error instanceof Vp3InitiativeSourcesChangedV1)return 'source_changed';
    if(!$dispatched && in_array($error->getMessage(),['quota_exhausted','route_unavailable'],true))return $error->getMessage();
    return $dispatched?'inference_failed':'model_unavailable';
}

function agent_email_followthrough_draft_block_v1(PDO $pdo,array $expected,string $watchClaim,string $reason): void
{
    agent_commerce_atomic_pa2($pdo,static function()use($pdo,$expected,$watchClaim,$reason):void{
        $scope=agent_email_followthrough_current_v1($pdo,$expected,$watchClaim,true);$row=$scope['row'];
        if((int)($row['draft_attempt_revision'] ?? 0)===(int)$row['source_revision']
            && in_array($row['draft_attempt_state'] ?? null,['preparing','dispatched','ready','failed'],true))return;
        $withdraw=in_array($reason,['source_changed','source_unavailable'],true)?",outcome='waiting',check_state='unavailable',check_result=NULL,checked_at=NULL,evidence_digest=NULL":'';
        $pdo->prepare("UPDATE agent_email_followthrough_v1 SET draft_attempt_revision=?,draft_attempt_state='blocked',
            draft_attempt_reason=?,draft_attempt_key=NULL,draft_attempt_lease=NULL,state_version=state_version+1".$withdraw.' WHERE id=? AND revision=?')
            ->execute([(int)$row['source_revision'],$reason,$row['id'],(int)$row['revision']]);
    });
}

/** Compare only the immutable source envelope; the body is never copied into runtime metadata. */
function agent_email_followthrough_draft_source_matches_v1(array $expected,array $actual): bool
{
    foreach(['to','subject','outbound_followup'] as $key)if(($expected[$key] ?? null)!==($actual[$key] ?? null))return false;
    return true;
}

/** Worker-only preparation. The owner request already exists; no synthetic user turns are created.
 * A claimed attempt permits one external inference dispatch, including when a transport offers fallback.
 * An optional inference boundary is used by native acceptance fixtures, with the same dispatch callback. */
function agent_email_followthrough_prepare_v1(PDO $pdo,array $row,array $watch,array $evidence,string $watchClaim,
    ?callable $http=null,?callable $inference=null): void
{
    if($pdo->inTransaction())throw new LogicException('Email follow-up preparation must run outside a transaction.');
    $scope=agent_email_followthrough_current_v1($pdo,$row,$watchClaim);$row=$scope['row'];$watch=$scope['watch'];
    if(!agent_email_followthrough_draft_eligible_v1($row))return;
    if((int)($row['generated_revision'] ?? 0)===(int)$row['source_revision'])return;
    if((int)($row['draft_attempt_revision'] ?? 0)===(int)$row['source_revision']
        && in_array($row['draft_attempt_state'] ?? null,['preparing','dispatched','ready','failed'],true))return;
    $policy=agent_email_followthrough_draft_policy_v1($pdo,$scope);
    if(!$policy['ready']){agent_email_followthrough_draft_block_v1($pdo,$row,$watchClaim,$policy['reason']);return;}
    if(!table_exists('agent_connection_email_drafts_v1')){
        agent_email_followthrough_draft_block_v1($pdo,$row,$watchClaim,'upgrade_required');return;
    }
    $attempt=bin2hex(random_bytes(16));$claimed=false;$dispatched=false;
    try{
        $source=agent_email_followthrough_source_v1($pdo,$row,$watch,$http,true);
        $request=$pdo->prepare("SELECT message FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
        $request->execute([(int)$row['user_message_id'],(int)$row['conversation_id'],(int)$row['owner_user_id']]);
        $ownerRequest=$request->fetchColumn();
        if(!is_string($ownerRequest) || trim($ownerRequest)==='')throw new DomainException('The owner deadline request is unavailable.',409);
        require_once __DIR__.'/agent-brain-soul-v1.php';
        $soul=agent_brain_soul_profile_v1($scope['user'],'email');
        $claimed=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$row,$watchClaim,$attempt,$policy):bool{
            $current=agent_email_followthrough_current_v1($pdo,$row,$watchClaim,true);$saved=$current['row'];
            if(!agent_email_followthrough_draft_eligible_v1($saved)
                || (int)($saved['generated_revision'] ?? 0)===(int)$saved['source_revision'])return false;
            if((int)($saved['draft_attempt_revision'] ?? 0)===(int)$saved['source_revision']
                && in_array($saved['draft_attempt_state'] ?? null,['preparing','dispatched','ready','failed'],true))return false;
            if(agent_email_followthrough_draft_policy_v1($pdo,$current)!==$policy)throw new DomainException('The Agent preparation policy changed.',409);
            // Source checks, metered inference and the final check share this bounded native watch lease.
            $extended=agent_email_followthrough_extend_claim_v1($pdo,$saved,$watchClaim,240);
            $lease=$extended['watch']['next_check_at'];
            $pdo->prepare("UPDATE agent_email_followthrough_v1 SET draft_attempt_key=?,draft_attempt_revision=?,
                draft_attempt_state='preparing',draft_attempt_reason=NULL,draft_attempt_lease=?,state_version=state_version+1 WHERE id=? AND revision=?")
                ->execute([$attempt,(int)$saved['source_revision'],$lease,$saved['id'],(int)$saved['revision']]);
            return true;
        });
        if(!$claimed)return;
        $beforeInference=static function()use($pdo,$row,$watchClaim,$attempt,$source,$policy,$http,&$dispatched):void{
            if($dispatched)throw new RuntimeException('dispatch_uncertain',409);
            $current=agent_email_followthrough_draft_attempt_v1($pdo,$row,$watchClaim,$attempt);
            if(agent_email_followthrough_draft_policy_v1($pdo,$current)!==$policy)throw new DomainException('The Agent preparation policy changed.',409);
            $fresh=agent_email_followthrough_source_v1($pdo,$current['row'],$current['watch'],$http,false);
            if(!agent_email_followthrough_draft_source_matches_v1($source,$fresh))throw new DomainException('The original sent email changed.',409);
            agent_commerce_atomic_pa2($pdo,static function()use($pdo,$row,$watchClaim,$attempt,$policy):void{
                $current=agent_email_followthrough_draft_attempt_v1($pdo,$row,$watchClaim,$attempt,true);
                if(agent_email_followthrough_draft_policy_v1($pdo,$current)!==$policy)throw new DomainException('The Agent preparation policy changed.',409);
                if($current['row']['draft_attempt_state']!=='preparing')throw new DomainException('This draft attempt already dispatched.',409);
                $pdo->prepare("UPDATE agent_email_followthrough_v1 SET draft_attempt_state='dispatched',state_version=state_version+1
                    WHERE id=? AND revision=? AND draft_attempt_key=? AND draft_attempt_state='preparing'")
                    ->execute([$row['id'],(int)$row['revision'],$attempt]);
            });
            $dispatched=true;
        };
        $prompt=json_encode(['response_kind'=>'gmail_followthrough_draft',
            'owner_instruction'=>mb_substr($ownerRequest,0,1200),
            'owner_settings'=>['response_deadline'=>$row['deadline_at'],'timezone'=>$row['time_zone'],
                'priority'=>$row['priority'],'prepare_if_overdue'=>true],
            'verified_check'=>['result'=>'no_reply','checked_at'=>$row['checked_at'],'coverage'=>'exact_sent_thread'],
            'email_soul'=>$soul['content'],'original_sent_message'=>['subject'=>$source['subject'],'excerpt'=>$source['body'] ?? '']],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $job=['background'=>true,'gmail_followthrough_draft'=>true,'conversation_id'=>(int)$row['conversation_id'],
            'job_key'=>'gmail-followthrough:'.$row['id'].':'.$row['source_revision']];
        $protocol='vp3.initiative-draft.v1';
        $answer=$inference!==null?$inference($prompt,$protocol,$beforeInference,$job,true):
            vp3_initiative_infer_v1($pdo,$scope['user'],(int)$row['agent_id'],$prompt,$protocol,true,$beforeInference,$job);
        if(!$dispatched)throw new DomainException('The email draft inference was not authorized.',409);
        try{
            $value=vp3_tool_json_object_v1($answer,['draft'],30000);
            $body=agent_connection_email_chat_text_v1($value['draft'] ?? null,6000,true);
            if(trim($body)==='')throw new UnexpectedValueException('The follow-up draft was empty.');
        }catch(Throwable $invalid){throw new UnexpectedValueException('The follow-up draft response was invalid.',0,$invalid);}
        $current=agent_email_followthrough_draft_attempt_v1($pdo,$row,$watchClaim,$attempt);
        $fresh=agent_email_followthrough_source_v1($pdo,$current['row'],$current['watch'],$http,false);
        if(!agent_email_followthrough_draft_source_matches_v1($source,$fresh))throw new DomainException('The original sent email changed.',409);
        $id=agent_email_followthrough_draft_id_v1($row);$created=time();
        $metadata=agent_email_review_queue_source_metadata_v1([
            'reason'=>'Your response deadline passed. The Gmail check at '.str_replace(' ','T',$row['checked_at']).'Z found no matching reply in the original thread.',
            'priority'=>$row['priority'],'deadline'=>str_replace(' ','T',$row['deadline_at']).'Z',
        ]);
        $payload=['subject'=>$source['subject'],'body'=>$body,'outbound_followup'=>$source['outbound_followup'],
            'soul_version'=>$soul['version'],'soul_revision'=>$soul['revision'],
            'composer'=>['version'=>3,'saved_at'=>$created,'created_at'=>$created,'origin'=>'outbound_followup','source'=>$metadata]];
        agent_email_composer_validate_v1(['expires_at'=>0],$payload);
        agent_commerce_atomic_pa2($pdo,static function()use($pdo,$row,$watchClaim,$attempt,$policy,$source,$payload,$id):void{
            $current=agent_email_followthrough_draft_attempt_v1($pdo,$row,$watchClaim,$attempt,true);$saved=$current['row'];
            if(agent_email_followthrough_draft_policy_v1($pdo,$current)!==$policy)throw new DomainException('The Agent preparation policy changed.',409);
            $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1
                (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,watch_replies)
                VALUES (?,?,?,?,?,?,?,?,?,?,'prepared',0,1)")
                ->execute([$id,(int)$saved['owner_user_id'],(int)$saved['agent_id'],(int)$saved['conversation_id'],(int)$saved['user_message_id'],
                    (int)$saved['connection_id'],$saved['authorization_key'],$saved['account_email'],$source['to'],ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))]);
            $pdo->prepare("UPDATE agent_email_followthrough_v1 SET generated_draft_id=?,generated_revision=?,draft_attempt_state='ready',
                draft_attempt_reason=NULL,draft_attempt_lease=NULL,state_version=state_version+1 WHERE id=? AND revision=? AND draft_attempt_key=?")
                ->execute([$id,(int)$saved['source_revision'],$saved['id'],(int)$saved['revision'],$attempt]);
        });
    }catch(Throwable $error){
        $reason=agent_email_followthrough_draft_reason_v1($error,$dispatched);
        try{
            if(!$claimed){agent_email_followthrough_draft_block_v1($pdo,$row,$watchClaim,$reason);return;}
            agent_commerce_atomic_pa2($pdo,static function()use($pdo,$row,$watchClaim,$attempt,$dispatched,$reason):void{
                agent_email_followthrough_draft_attempt_v1($pdo,$row,$watchClaim,$attempt,true);
                $withdraw=in_array($reason,['source_changed','source_unavailable'],true)?",outcome='waiting',check_state='unavailable',check_result=NULL,checked_at=NULL,evidence_digest=NULL":'';
                $pdo->prepare('UPDATE agent_email_followthrough_v1 SET draft_attempt_state=?,draft_attempt_reason=?,draft_attempt_lease=NULL,state_version=state_version+1
                    '.$withdraw.' WHERE id=? AND revision=? AND draft_attempt_key=?')
                    ->execute([$dispatched?'failed':'blocked',$reason,$row['id'],(int)$row['revision'],$attempt]);
            });
        }catch(DomainException|RuntimeException $changed){
            if($changed instanceof PDOException)throw $changed;
            // A newer owner decision or lost claim owns the record. Do not alter it.
        }
    }
}
