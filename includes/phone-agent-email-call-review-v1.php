<?php
declare(strict_types=1);

require_once __DIR__.'/phone-agent-email-calls-settings-v1.php';
require_once __DIR__.'/agent-operator-owner-email-v1.php';

/** The carrier receives no owner session, Gmail review token, or send capability. */
function vp3_phone_agent_email_call_review_session_v1(array $scope): string
{
    $key=$_SESSION['agent_connection_email_review_v1']??null;
    vp3_phone_agent_email_calls_check_v1(is_string($key)&&preg_match('/^[a-f0-9]{64}$/D',$key)===1,'review_changed');
    return hash_hmac('sha256','email-call-review-v1:'.$scope['scope_revision'].':'.$key,vp3_phone_agent_workspace_key_v1());
}

function vp3_phone_agent_email_call_review_row_v1(PDO $pdo,int $owner,string $id,bool $lock=true): ?array
{
    vp3_phone_agent_email_calls_check_v1(preg_match('/^[a-f0-9]{32}$/D',$id)===1,'invalid_request',422);
    return vp3_phone_sql_v1($pdo,'SELECT * FROM phone_agent_email_call_reviews_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_lock_v1($pdo):''),[$owner,$id])->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_phone_agent_email_call_review_bound_v1(array $scope,array $r,int $now): void
{
    vp3_phone_agent_email_calls_check_v1(hash_equals($r['device_id'],$scope['device']['id'])
        &&hash_equals($r['grant_id'],$scope['enrollment']['grant_id'])
        &&(int)$r['enrollment_revision']===(int)$scope['enrollment']['revision']
        &&hash_equals($r['scope_binding'],$scope['scope_revision'])
        &&hash_equals($r['session_binding'],vp3_phone_agent_email_call_review_session_v1($scope)),'review_changed');
    vp3_phone_agent_email_calls_check_v1($now<(int)$r['expires_epoch'],'voice_review_expired');
}

/** Native call authority without requiring the draft to stay 'prepared' during its own send. */
function vp3_phone_agent_email_call_review_call_v1(PDO $pdo,array $r,int $now): array
{
    $owner=(int)$r['owner_user_id'];$c=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,$pdo->inTransaction());
    vp3_phone_agent_email_calls_check_v1(hash_equals($r['authority_hash'],vp3_operator_owner_email_authority_v1($c)),'policy_changed');
    $q=$pdo->prepare("SELECT r.*,a.provider_sid,a.recording_sid,a.recording_state,c.public_id,c.state AS call_state,c.ended_epoch,
        c.peer_e164,c.phone_e164,c.phone_number_id,c.conversation_id AS call_conversation,c.chat_agent_id AS call_agent,
        o.reason AS occurrence_reason
        FROM phone_agent_email_call_requests_v1 r
        JOIN phone_operator_carrier_attempts_v1 a ON a.owner_user_id=r.owner_user_id AND a.event_id=r.event_id AND a.start_epoch=r.start_epoch
        JOIN phone_operator_occurrences_v1 o ON o.owner_user_id=r.owner_user_id AND o.event_id=r.event_id AND o.start_epoch=r.start_epoch
        JOIN phone_browser_calls_v1 c ON c.owner_user_id=r.owner_user_id AND c.public_id=a.call_public_id AND c.provider_call_sid=a.provider_sid
        WHERE r.owner_user_id=? AND r.id=? AND r.state='submitted' AND a.state='submitted' AND o.state='submitted'
        AND c.transport_kind='operator_scheduled'".($pdo->inTransaction()?vp3_phone_lock_v1($pdo):''));
    $q->execute([$owner,$r['request_id']]);$call=$q->fetch(PDO::FETCH_ASSOC);
    vp3_phone_agent_email_calls_check_v1($call!==false&&$call['recording_state']==='started'&&is_string($call['recording_sid'])
        &&$call['call_state']==='answered'&&$call['ended_epoch']===null&&$now<(int)$call['end_epoch']
        &&in_array($call['occurrence_reason'],['answer_consented','conversation_active'],true)
        &&hash_equals($call['provider_sid'],$r['provider_sid'])&&hash_equals($call['public_id'],$r['call_id'])
        &&hash_equals($call['authority_hash'],$r['authority_hash'])&&hash_equals($call['item_ref'],$r['item_ref'])
        &&hash_equals($call['source_id'],$r['draft_id'])&&$call['source_kind']==='gmail_draft'
        &&hash_equals($call['source_revision'],$r['source_revision'])
        &&(int)$call['call_conversation']===(int)$call['conversation_id']&&(int)$call['call_agent']===(int)$call['selected_agent_id']
        &&(int)$call['phone_number_id']===(int)$c['policy']['number_id']
        &&hash_equals($call['peer_e164'],$c['preferences']['phone_e164']),'call_changed');
    $control=vp3_operator_owner_email_control_v1($pdo,$c,$call,$now,$r['provider_sid']);
    vp3_phone_agent_email_calls_check_v1(hash_equals($control['call']['public_id'],$r['call_id'])
        &&hash_equals($control['request']['source_revision'],$r['source_revision']),'call_changed');
    return ['context'=>$control['context'],'request'=>$control['request']];
}

function vp3_phone_agent_email_call_review_draft_v1(PDO $pdo,array $r,array $request,bool $sending=false): array
{
    $draft=agent_connection_email_chat_draft_v1($pdo,(int)$r['owner_user_id'],(int)$request['selected_agent_id'],
        (int)$request['conversation_id'],$r['draft_id'],$pdo->inTransaction());
    vp3_phone_agent_email_calls_check_v1(in_array($draft['status'],$sending?['prepared','sending']:['prepared'],true)
        &&($draft['status']!=='sending'||(bool)$draft['watch_replies']===(bool)$r['watch_replies'])
        &&hash_equals($r['review_hash'],agent_connection_email_chat_review_hash_v1($draft,(bool)$r['watch_replies'])),'review_changed');
    return $draft;
}

function vp3_phone_agent_email_call_review_public_v1(array $r): array
{return ['id'=>$r['id'],'state'=>$r['state'],'revision'=>(int)$r['revision'],'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['expires_epoch'])];}

/** A later manual send of an edited draft is not the outcome of this exact review. */
function vp3_phone_agent_email_call_review_sent_v1(PDO $pdo,array $r,array $draft): bool
{
    if($draft['status']!=='sent'||(bool)$draft['watch_replies']!==(bool)$r['watch_replies']
        ||!hash_equals($r['review_hash'],agent_connection_email_chat_review_hash_v1($draft,(bool)$r['watch_replies'])))return false;
    $payload=agent_connection_email_chat_payload_v1($draft);
    // Match the canonical send ledger's payload, including immutable reply and attachment bindings.
    $message=[$draft['recipient'],$payload['subject'],$payload['body']];
    if(isset($payload['reply']))$message[]=agent_connection_gmail_reply_binding_v1($payload['reply']);
    if(isset($payload['outbound_followup']))$message[]=['outbound_followup'=>agent_email_followthrough_binding_v1($payload['outbound_followup'])];
    if(!empty($payload['attachments']))$message[]=['attachments'=>agent_email_attachments_manifest_v1($payload['attachments'])];
    $digest=hash('sha256',json_encode($message,JSON_THROW_ON_ERROR));
    $q=$pdo->prepare('SELECT * FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=? AND operation_key=?'
        .($pdo->inTransaction()?vp3_phone_lock_v1($pdo):''));
    $q->execute([(int)$draft['owner_user_id'],(int)$draft['connection_id'],agent_connection_email_chat_operation_key_v1($draft['id'])]);
    $receipt=$q->fetch(PDO::FETCH_ASSOC);$thread=$payload['reply']['thread_id']??$payload['outbound_followup']['thread_id']??null;
    return $receipt!==false&&$receipt['status']==='sent'&&(int)$receipt['sent_at']>0
        &&is_string($draft['sent_message_id'])&&$draft['sent_message_id']!==''
        &&is_string($receipt['provider_message_id'])&&hash_equals($receipt['provider_message_id'],$draft['sent_message_id'])
        &&is_string($receipt['provider_thread_id'])&&$receipt['provider_thread_id']!==''
        &&hash_equals($receipt['authorization_key'],$draft['authorization_key'])&&hash_equals($receipt['payload_digest'],$digest)
        &&($thread===null||hash_equals($receipt['provider_thread_id'],$thread));
}

/** Runs only in the real owner/CSRF/exact-phone transaction. No provider or Gmail send here. */
function vp3_phone_agent_email_call_review_request_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    $owner=(int)$scope['user']['id'];$action=$input['action'];
    if($action==='voice_arm'){
        vp3_phone_agent_email_calls_check_v1(($input['confirmed']??null)===true&&is_bool($input['watch_replies']??null)
            &&is_string($input['review_token']??null)&&strlen($input['review_token'])<=90
            &&is_string($input['request_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$input['request_id'])===1
            &&is_string($input['draft_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$input['draft_id'])===1,'invalid_request',422);
        $context=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,true);
        $active=vp3_operator_owner_email_active_v1($pdo,$context,$input['request_id'],$now);
        vp3_phone_agent_email_calls_check_v1($active!==null,'voice_review_unavailable');$request=$active['request'];
        vp3_phone_agent_email_calls_check_v1($request['source_kind']==='gmail_draft'&&$request['source_id']===$input['draft_id']
            &&$request['device_id']===$scope['device']['id']
            &&(int)$request['selected_agent_id']===$input['agent_id']&&(int)$request['conversation_id']===$input['conversation_id'],'review_changed');
        $draft=agent_connection_email_chat_draft_v1($pdo,$owner,$input['agent_id'],$input['conversation_id'],$input['draft_id'],true);
        $key=$_SESSION['agent_connection_email_review_v1']??'';$watch=$input['watch_replies'];
        $reviewHash=agent_connection_email_chat_review_hash_v1($draft,$watch);
        try{initiative_workbench_confirm_v1($input,$key,$reviewHash,$now);}
        catch(DomainException){throw new VP3PhoneAgentEmailCallException('review_changed');}
        $public=agent_connection_email_chat_public_draft_v1($draft,$key,$now);
        vp3_phone_agent_email_calls_check_v1($public['status']==='prepared'&&!empty($public['can_send'])
            &&empty($public['connection_changed'])&&empty($public['followthrough_unavailable'])
            &&(!$watch||isset($public['watch_review_token'])),'review_changed');
        // A fresh explicit arm replaces an older unclaimed challenge, never resumes a send.
        vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='cancelled',revision=revision+1,updated_epoch=?
            WHERE owner_user_id=? AND request_id=? AND state IN ('armed','requested')",[$now,$owner,$request['id']]);
        $id=bin2hex(random_bytes(16));$challenge=(string)random_int(100000,999999);
        $expires=min($now+120,(int)$request['end_epoch'],(int)explode('.',$input['review_token'])[0]);
        vp3_phone_agent_email_calls_check_v1($expires>$now+10,'voice_review_expired');
        $r=['id'=>$id,'owner_user_id'=>$owner,'request_id'=>$request['id'],'device_id'=>$scope['device']['id'],
            'grant_id'=>$scope['enrollment']['grant_id'],'enrollment_revision'=>(int)$scope['enrollment']['revision'],
            'scope_binding'=>$scope['scope_revision'],'session_binding'=>vp3_phone_agent_email_call_review_session_v1($scope),
            'authority_hash'=>vp3_operator_owner_email_authority_v1($context),'provider_sid'=>$active['sid'],
            'call_id'=>$active['call']['public_id'],'item_ref'=>$request['item_ref'],'draft_id'=>$draft['id'],
            'source_revision'=>$request['source_revision'],'review_hash'=>$reviewHash,
            'review_token_hash'=>hash('sha256',$input['review_token']),'watch_replies'=>(int)$watch,
            'challenge_hash'=>hash('sha256',$id.':'.$challenge),'state'=>'armed','revision'=>1,
            'created_epoch'=>$now,'expires_epoch'=>$expires,'heartbeat_epoch'=>$now,'updated_epoch'=>$now];
        $fields=array_keys($r);vp3_phone_sql_v1($pdo,'INSERT INTO phone_agent_email_call_reviews_v1('.implode(',',$fields).') VALUES('
            .implode(',',array_fill(0,count($fields),'?')).')',array_values($r));
        return ['ok'=>true,'review'=>vp3_phone_agent_email_call_review_public_v1($r),'challenge'=>'send email '.implode(' ',str_split($challenge))];
    }
    vp3_phone_agent_email_calls_check_v1(is_string($input['review_id']??null),'invalid_request',422);
    $r=vp3_phone_agent_email_call_review_row_v1($pdo,$owner,$input['review_id']);
    vp3_phone_agent_email_calls_check_v1($r!==null,'voice_review_unavailable',404);
    // Cancellation does not require an active call or an unexpired token, but still binds the real session.
    if($action==='voice_cancel'){
        vp3_phone_agent_email_call_review_bound_v1($scope,$r,min($now,(int)$r['expires_epoch']-1));
        if(in_array($r['state'],['armed','requested'],true)){
            vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='cancelled',revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND id=?",[$now,$owner,$r['id']]);
            $r['state']='cancelled';$r['revision']++;
        }
        return ['ok'=>true,'review'=>vp3_phone_agent_email_call_review_public_v1($r)];
    }
    vp3_phone_agent_email_call_review_bound_v1($scope,$r,$now);
    if(in_array($r['state'],['claimed','completed'],true)){
        // A native sent row and matching immutable receipt must prove this exact reviewed version.
        $request=vp3_operator_owner_email_record_v1($pdo,$owner,$r['request_id'],true);
        vp3_phone_agent_email_calls_check_v1($request!==null,'call_changed');
        $draft=agent_connection_email_chat_draft_v1($pdo,$owner,(int)$request['selected_agent_id'],(int)$request['conversation_id'],$r['draft_id'],true);
        vp3_phone_agent_email_calls_check_v1(hash_equals($r['review_hash'],agent_connection_email_chat_review_hash_v1($draft,(bool)$r['watch_replies'])),'review_changed');
        if($draft['status']==='sent'){
            vp3_phone_agent_email_calls_check_v1(vp3_phone_agent_email_call_review_sent_v1($pdo,$r,$draft),'review_changed');
            if($r['state']==='claimed'){
                $r['state']='completed';$r['revision']++;
                vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='completed',revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND id=? AND state='claimed'",[$now,$owner,$r['id']]);
            }
        }else{
            vp3_phone_agent_email_calls_check_v1($r['state']==='claimed','review_changed');
        }
        vp3_phone_agent_email_calls_check_v1($action==='voice_poll','review_changed');
        return ['ok'=>true,'review'=>vp3_phone_agent_email_call_review_public_v1($r)];
    }
    if(in_array($r['state'],['cancelled','expired'],true)){
        vp3_phone_agent_email_calls_check_v1($action==='voice_poll','review_changed');
        return ['ok'=>true,'review'=>vp3_phone_agent_email_call_review_public_v1($r)];
    }
    $call=vp3_phone_agent_email_call_review_call_v1($pdo,$r,$now);
    vp3_phone_agent_email_call_review_draft_v1($pdo,$r,$call['request']);
    vp3_phone_agent_email_calls_check_v1($now-(int)$r['heartbeat_epoch']<=20,'voice_review_expired');
    if($action==='voice_claim'){
        vp3_phone_agent_email_calls_check_v1($r['state']==='requested'&&is_string($input['review_token']??null)
            &&hash_equals($r['review_token_hash'],hash('sha256',$input['review_token'])),'review_changed');
        $r['state']='claimed';$r['claimed_epoch']=$now;$r['revision']++;
        vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='claimed',claimed_epoch=?,heartbeat_epoch=?,revision=revision+1,updated_epoch=?
            WHERE owner_user_id=? AND id=? AND state='requested'",[$now,$now,$now,$owner,$r['id']]);
    }else vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_reviews_v1 SET heartbeat_epoch=?,updated_epoch=? WHERE owner_user_id=? AND id=?',[$now,$now,$owner,$r['id']]);
    return ['ok'=>true,'review'=>vp3_phone_agent_email_call_review_public_v1($r)];
}

/** The normal native Gmail confirm runs this again adjacent to its actual send. */
function vp3_phone_agent_email_call_review_execution_v1(PDO $pdo,array $native,string $reviewId,array $input,int $now): void
{
    $scope=$native['scope'];$r=vp3_phone_agent_email_call_review_row_v1($pdo,(int)$scope['user']['id'],$reviewId,$pdo->inTransaction());
    vp3_phone_agent_email_calls_check_v1($r!==null&&$r['state']==='claimed'&&(int)$r['claimed_epoch']>0&&$now-(int)$r['claimed_epoch']<=30,'review_changed');
    vp3_phone_agent_email_call_review_bound_v1($scope,$r,$now);
    vp3_phone_agent_email_calls_check_v1(($input['action']??null)==='confirm'&&($input['confirmed']??null)===true
        &&($input['draft_id']??null)===$r['draft_id']&&($input['watch_replies']??null)===(bool)$r['watch_replies']
        &&is_string($input['review_token']??null)&&hash_equals($r['review_token_hash'],hash('sha256',$input['review_token']))
        &&$native['resolved']['item']['item_ref']===$r['item_ref'],'review_changed');
    $call=vp3_phone_agent_email_call_review_call_v1($pdo,$r,$now);
    vp3_phone_agent_email_call_review_draft_v1($pdo,$r,$call['request'],true);
}

/**
 * A concurrent carrier turn may report only generic progress during this exact
 * authenticated send. It must not close the call just because native confirm
 * changed its own draft to 'sending'. This grants no new approval or transport.
 */
function vp3_phone_agent_email_call_sending_current_v1(PDO $pdo,array $context,array $request,string $sid,int $now): bool
{
    try{
        $owner=(int)$context['user']['id'];
        $q=$pdo->prepare('SELECT * FROM phone_agent_email_call_reviews_v1 WHERE owner_user_id=? AND request_id=? AND provider_sid=?
            ORDER BY sequence_id DESC LIMIT 1'.($pdo->inTransaction()?vp3_phone_lock_v1($pdo):''));
        $q->execute([$owner,$request['id'],$sid]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r||$r['state']!=='claimed'||(int)$r['claimed_epoch']<1||$now-(int)$r['claimed_epoch']>30
            ||$now>=(int)$r['expires_epoch']||$now-(int)$r['heartbeat_epoch']>30
            ||$request['source_kind']!=='gmail_draft'||!hash_equals($r['draft_id'],$request['source_id'])
            ||!hash_equals($r['source_revision'],$request['source_revision'])||!hash_equals($r['item_ref'],$request['item_ref'])
            ||!hash_equals($r['device_id'],$context['enrollment']['device_id'])
            ||!hash_equals($r['grant_id'],$context['enrollment']['grant_id'])
            ||(int)$r['enrollment_revision']!==(int)$context['enrollment']['revision']
            ||!hash_equals($r['authority_hash'],vp3_operator_owner_email_authority_v1($context)))return false;
        $draft=vp3_phone_agent_email_call_review_draft_v1($pdo,$r,$request,true);
        if($draft['status']!=='sending')return false;
        $connection=agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],'reply',$draft['authorization_key']);
        if(!hash_equals($connection['account_email'],$draft['account_email'])||!agent_connection_gmail_ready_v1())return false;
        $control=vp3_operator_owner_email_control_v1($pdo,$context,$request,$now,$sid);
        return hash_equals($control['call']['public_id'],$r['call_id']);
    }catch(PDOException $error){throw $error;}
    catch(RuntimeException|DomainException){return false;}
}

/** Strict challenge grammar. Extra commands or a generic yes never authorize a send. */
function vp3_phone_agent_email_call_challenge_v1(string $speech): ?string
{
    if(strlen($speech)>120)return null;
    $speech=strtolower(trim($speech));$speech=rtrim($speech,'.!');
    if(preg_match('/^send email\s+([0-9]{6})$/D',$speech,$m)===1)return $m[1];
    if(preg_match('/^send email\s+((?:(?:zero|one|two|three|four|five|six|seven|eight|nine|[0-9])\s+){5}(?:zero|one|two|three|four|five|six|seven|eight|nine|[0-9]))$/D',$speech,$m)!==1)return null;
    $digits=array_flip(['zero','one','two','three','four','five','six','seven','eight','nine']);
    return implode('',array_map(static fn(string $v):string=>(string)($digits[$v]??$v),preg_split('/\s+/',$m[1])));
}

/** Signed exact-call callback: record intent only. The open authenticated review performs the send. */
function vp3_phone_agent_email_call_voice_turn_v1(PDO $pdo,array $current,string $sid,int $step,string $speech,int $now): ?string
{
    $request=$current['request'];$owner=(int)$request['owner_user_id'];
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$request,$current,$sid,$speech,$now):?string{
        $q=$pdo->prepare('SELECT * FROM phone_agent_email_call_reviews_v1 WHERE owner_user_id=? AND request_id=? AND provider_sid=? ORDER BY sequence_id DESC LIMIT 1'.vp3_phone_lock_v1($pdo));
        $q->execute([$owner,$request['id'],$sid]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        if(!hash_equals($r['authority_hash'],vp3_operator_owner_email_authority_v1($current['context'])))return null;
        if($r['state']==='claimed'||$r['state']==='completed'){
            $draft=agent_connection_email_chat_draft_v1($pdo,$owner,(int)$request['selected_agent_id'],(int)$request['conversation_id'],$r['draft_id'],true);
            return vp3_phone_agent_email_call_review_sent_v1($pdo,$r,$draft)?'Gmail confirmed that your reviewed email was sent.'
                :'Check the send status in your phone review. I will not start another send.';
        }
        if(!in_array($r['state'],['armed','requested'],true)||$now>=(int)$r['expires_epoch']||$now-(int)$r['heartbeat_epoch']>20)
            return 'Voice approval is no longer active. Open the exact email in AGENT to review it again.';
        try{$call=vp3_phone_agent_email_call_review_call_v1($pdo,$r,$now);vp3_phone_agent_email_call_review_draft_v1($pdo,$r,$call['request']);}
        catch(RuntimeException|DomainException){return 'The email or call changed. Please review the current email in AGENT.';}
        if($r['state']==='requested')return 'Your confirmation is ready for the open review on your phone. Keep that review open to see the send result.';
        $challenge=vp3_phone_agent_email_call_challenge_v1($speech);
        if($challenge===null)return 'To send the reviewed email, say the exact confirmation phrase shown in your phone review. You can also use Send there.';
        if(!hash_equals($r['challenge_hash'],hash('sha256',$r['id'].':'.$challenge))){
            $fails=(int)$r['failed_challenges']+1;
            vp3_phone_sql_v1($pdo,'UPDATE phone_agent_email_call_reviews_v1 SET failed_challenges=?,state=?,revision=revision+1,updated_epoch=? WHERE owner_user_id=? AND id=?',
                [$fails,$fails>=3?'cancelled':'armed',$now,$owner,$r['id']]);
            return $fails>=3?'Voice approval was cancelled. Review the email in AGENT before trying again.':'That phrase did not match the current review. Please check the phrase on your phone.';
        }
        vp3_phone_sql_v1($pdo,"UPDATE phone_agent_email_call_reviews_v1 SET state='requested',requested_epoch=?,revision=revision+1,updated_epoch=?
            WHERE owner_user_id=? AND id=? AND state='armed'",[$now,$now,$owner,$r['id']]);
        return 'Your confirmation is ready for the open review on your phone. Keep that review open to see the send result.';
    });
}
