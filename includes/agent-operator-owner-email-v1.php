<?php
declare(strict_types=1);

// Loaded only for the explicit owner-email source. Ordinary contact calls retain
// their existing dependencies and authorization path.
require_once __DIR__.'/phone-agent-email-calls-settings-v1.php';
require_once __DIR__.'/phone-agent-email-call-sources-v1.php';
require_once __DIR__.'/agent-operator-dispatch-preview-v1.php';

const VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1='operator-owner-email:';
const VP3_OPERATOR_OWNER_EMAIL_OBJECTIVE_V1='Ask the owner to open the exact pending Gmail review in the private AGENT workspace. Do not disclose email content or act on spoken approval.';
const VP3_OPERATOR_OWNER_EMAIL_OPENING_V1='Please open AGENT on your connected phone to review the pending item. Email details and sending stay in your signed-in review.';

function vp3_operator_owner_email_reason_v1(mixed $reason): string
{
    return is_string($reason)&&preg_match('/\A[a-z][a-z0-9_]{0,47}\z/D',$reason)===1?$reason:'source_unavailable';
}

function vp3_operator_owner_email_record_v1(PDO $pdo,int $owner,string $id,bool $lock=false): ?array
{
    if($owner<1||preg_match('/\A[a-f0-9]{32}\z/D',$id)!==1)return null;
    $q=$pdo->prepare('SELECT * FROM phone_agent_email_call_requests_v1 WHERE owner_user_id=? AND id=?'
        .($lock?vp3_phone_workspace_lock_v1($pdo):''));
    $q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}

/** Nested review transactions may have an older consistent snapshot; compare the current event. */
function vp3_operator_owner_email_event_v1(PDO $pdo,int $owner,int $event): ?array
{
    if(!$pdo->inTransaction())return user_calendar_event_v1300($pdo,$owner,$event);
    $q=$pdo->prepare('SELECT id FROM users WHERE id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([$owner]);$found=$q->fetchColumn();$q->closeCursor();if($found===false)return null;
    $q=$pdo->prepare('SELECT * FROM user_calendar_events WHERE owner_user_id=? AND id=?'.vp3_phone_workspace_lock_v1($pdo));
    $q->execute([$owner,$event]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $row?:null;
}

/** Control revisions, not a phone role or caller number, fence this private channel. */
function vp3_operator_owner_email_authority_v1(array $context): string
{
    $p=$context['policy'];$e=$context['enrollment'];$h=$context['holder'];$f=$context['preferences'];
    return hash('sha256',json_encode([(int)$p['owner_user_id'],(int)$p['revision'],
        $p['device_id'],$e['grant_id'],(int)$e['revision'],$p['credential_hash'],$p['owner_fingerprint'],
        (int)$p['selected_agent_id'],(int)$p['conversation_id'],(int)$p['number_id'],$p['line_binding'],
        (int)$f['revision'],(int)$f['phone_revision'],$p['destination_hash'],
        $h['profile_id'],(int)$h['revision'],(int)$h['phone_revision']],JSON_THROW_ON_ERROR));
}

function vp3_operator_owner_email_scope_v1(PDO $pdo,array $context,int $conversation): array
{
    return vp3_phone_workspace_scope_v1($pdo,$context['user'],[
        'agent_id'=>(int)$context['policy']['selected_agent_id'],'conversation_id'=>$conversation]);
}

/** Only a real, current exact review claim may preserve generic speech during its own send. */
function vp3_operator_owner_email_sending_v1(PDO $pdo,array $context,array $request,string $sid,int $now): bool
{
    if(($request['source_kind']??null)!=='gmail_draft'||($request['state']??null)!=='submitted')return false;
    require_once __DIR__.'/phone-agent-email-call-review-v1.php';
    return function_exists('vp3_phone_agent_email_call_sending_current_v1')
        &&vp3_phone_agent_email_call_sending_current_v1($pdo,$context,$request,$sid,$now);
}

/** Shared route, hours, frequency, idle and Calendar checks; no Gmail/provider read. */
function vp3_operator_owner_email_gates_v1(PDO $pdo,array $context,array $scope,int $now,int $seconds,
    ?array $assigned=null,?string $answeredSid=null,?array $excludeAttempt=null): array
{
    if($seconds<1||!vp3_operator_schedule_assignment_ready_v1($pdo)||!vp3_phone_recording_schema_ready_v1($pdo)
        ||!vp3_operator_calendar_ready_v1($pdo))
        return ['reason'=>'operator_upgrade_required'];
    $line=vp3_phone_workspace_number_v1($pdo,$scope,(int)$context['policy']['number_id'],true,$pdo->inTransaction());
    if(!hash_equals((string)$context['policy']['line_binding'],hash('sha256',$line['provider_sid'].'|'.$line['phone_e164'])))
        return ['reason'=>'line_changed'];
    if($pdo->inTransaction()){
        // Native policy projections may use a prior consistent snapshot. The final
        // call/review fence reads these actual permission rows under current locks.
        $q=$pdo->prepare('SELECT browser_enabled,recording_enabled FROM phone_browser_numbers_v1
            WHERE phone_number_id=? AND owner_user_id=? AND agent_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([(int)$line['id'],(int)$scope['owner_user_id'],(int)$line['agent_id']]);$route=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        $q=$pdo->prepare('SELECT enabled,retain_audio,transcribe FROM phone_recording_policies_v1
            WHERE owner_user_id=? AND agent_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([(int)$scope['owner_user_id'],(int)$line['agent_id']]);$recording=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$route||empty($route['browser_enabled'])||empty($route['recording_enabled'])
            ||!$recording||empty($recording['enabled'])||empty($recording['retain_audio'])||empty($recording['transcribe']))
            return ['reason'=>'route_or_recording_changed'];
    }
    if(vp3_operator_auto_call_prerequisites_v1($pdo,$scope,$line)!=='')return ['reason'=>'route_or_recording_changed'];
    $destination=(string)$context['preferences']['phone_e164'];
    if(preg_match('/\A\+[1-9][0-9]{7,14}\z/D',$destination)!==1||$destination===$line['phone_e164']
        ||!hash_equals((string)$context['policy']['destination_hash'],hash('sha256',$destination)))return ['reason'=>'owner_phone_changed'];
    if(vp3_operator_recipient_opted_out_v1($pdo,(int)$scope['owner_user_id'],hash('sha256',$destination),$pdo->inTransaction()))
        return ['reason'=>'recipient_opted_out'];
    // The history helper needs only a destination. This does not create or authorize a CRM contact.
    $bound=['contact'=>['destination_e164'=>$destination]];
    foreach([$context['preferences'],$context['holder']] as $settings){
        $settings['max_duration_seconds']=$seconds;
        if(!vp3_operator_admission_window_v1($settings,$now))return ['reason'=>'calling_window'];
        $history=vp3_operator_admission_history_v1($pdo,$scope,$bound,$settings,$now,$answeredSid,$excludeAttempt);
        if(!$history['within_limit']||!$history['gap_ok'])return ['reason'=>'call_frequency'];
    }
    if(!vp3_operator_admission_idle_v1($pdo,(int)$scope['owner_user_id'],$now,$answeredSid,$excludeAttempt))return ['reason'=>'phone_busy'];
    $ranges=vp3_operator_calendar_conflicts_v1($pdo,$scope,$now,$now+$seconds,$assigned);
    if(!vp3_operator_calendar_slot_free_v1($now,$seconds,$ranges))return ['reason'=>'calendar_conflict'];
    return ['reason'=>'eligible','line'=>$line,'destination'=>$destination];
}

function vp3_operator_owner_email_readiness_v1(PDO $pdo,array $context,int $now): array
{
    try{
        $current=vp3_phone_agent_email_calls_context_v1($pdo,(int)$context['policy']['owner_user_id'],$now,$pdo->inTransaction());
        $seconds=min((int)$current['preferences']['max_duration_seconds'],(int)$current['holder']['max_duration_seconds']);
        $gates=vp3_operator_owner_email_gates_v1($pdo,$current,$current['scope'],$now,$seconds);
        return ['reason'=>$gates['reason']];
    }catch(VP3PhoneAgentEmailCallException $e){return ['reason'=>vp3_operator_owner_email_reason_v1($e->reason)];}
    catch(VP3PhoneWorkspaceException){return ['reason'=>'phone_unavailable'];}
}

/** One owner-wide call episode. Initial source refresh happens before this transaction. */
function vp3_operator_owner_email_prepare_v1(PDO $pdo,array $context,array $candidate,int $now): array
{
    if($pdo->inTransaction())throw new LogicException('Prepare an owner email call outside a transaction.');
    $owner=(int)($context['policy']['owner_user_id']??0);
    vp3_phone_agent_email_calls_check_v1($owner>0&&$now>0,'owner_unavailable');
    foreach(['work_key','semantic_revision','source_revision'] as $field)
        vp3_phone_agent_email_calls_check_v1(is_string($candidate[$field]??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$candidate[$field])===1,'source_changed');
    try{
        return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$context,$candidate,$now):array{
            $live=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,true);
            vp3_phone_agent_email_calls_check_v1(hash_equals(vp3_operator_owner_email_authority_v1($live),
                vp3_operator_owner_email_authority_v1($context)),'policy_changed');
            $q=$pdo->prepare('SELECT id,state,reason FROM phone_agent_email_call_requests_v1 WHERE owner_user_id=? AND work_key=?'.vp3_phone_workspace_lock_v1($pdo));
            $q->execute([$owner,$candidate['work_key']]);$existing=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($existing)return ['state'=>$existing['state'],'reason'=>$existing['reason'],'request_id'=>$existing['id']];
            $check=vp3_phone_agent_email_call_source_current_v1($pdo,$live,$candidate,$now,'admit');
            vp3_phone_agent_email_calls_check_v1(($check['reason']??null)==='eligible',vp3_operator_owner_email_reason_v1($check['reason']??null));
            $source=$check['source']??null;
            vp3_phone_agent_email_calls_check_v1(is_array($source)&&$source['work_key']===$candidate['work_key']
                &&$source['semantic_revision']===$candidate['semantic_revision']&&$source['source_revision']===$candidate['source_revision']
                &&(int)$source['selected_agent_id']===(int)$live['policy']['selected_agent_id'],'source_changed');
            vp3_phone_agent_email_calls_check_v1(vp3_phone_agent_email_call_decision_current_v1($pdo,$live,$source,$now),'decision_required');
            $scope=vp3_operator_owner_email_scope_v1($pdo,$live,(int)$source['conversation_id']);
            $seconds=min((int)$live['preferences']['max_duration_seconds'],(int)$live['holder']['max_duration_seconds']);
            $gates=vp3_operator_owner_email_gates_v1($pdo,$live,$scope,$now,$seconds);
            vp3_phone_agent_email_calls_check_v1($gates['reason']==='eligible',$gates['reason']);
            $id=bin2hex(random_bytes(16));$end=$now+$seconds;$p=$live['policy'];$e=$live['enrollment'];
            $record=['id'=>$id,'owner_user_id'=>$owner,'work_key'=>$source['work_key'],'semantic_revision'=>$source['semantic_revision'],
                'source_kind'=>$source['source_kind'],'source_id'=>$source['source_id'],'source_revision'=>$source['source_revision'],
                'item_ref'=>$source['item_ref'],'selected_agent_id'=>(int)$source['selected_agent_id'],'conversation_id'=>(int)$source['conversation_id'],
                'policy_revision'=>(int)$p['revision'],'preferences_revision'=>(int)$live['preferences']['revision'],
                'phone_revision'=>(int)$live['preferences']['phone_revision'],'device_id'=>$p['device_id'],'grant_id'=>$e['grant_id'],
                'enrollment_revision'=>(int)$e['revision'],'credential_hash'=>$p['credential_hash'],'owner_fingerprint'=>$p['owner_fingerprint'],
                'number_id'=>(int)$p['number_id'],'line_binding'=>$p['line_binding'],'destination_hash'=>$p['destination_hash'],
                'holder_revision'=>(int)$live['holder']['revision'],'authority_hash'=>vp3_operator_owner_email_authority_v1($live),
                'source_updated_epoch'=>(int)$source['source_updated_epoch'],'start_epoch'=>$now,'end_epoch'=>$end,
                'state'=>'prepared','reason'=>'awaiting_dispatch_checks','revision'=>1,'created_epoch'=>$now,'updated_epoch'=>$now];
            $columns=array_keys($record);
            $pdo->prepare('INSERT INTO phone_agent_email_call_requests_v1('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')
                ->execute(array_values($record));
            $event=user_calendar_create_event_locked_v1300($pdo,$live['user'],['title'=>'Gmail review phone call',
                'description'=>VP3_OPERATOR_OWNER_EMAIL_OBJECTIVE_V1,'start_at_utc'=>gmdate('Y-m-d H:i:s',$now),
                'end_at_utc'=>gmdate('Y-m-d H:i:s',$end),'timezone'=>$live['preferences']['timezone']],
                'agent',(int)$gates['line']['agent_id'],VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1.$id);
            $eventId=(int)$event['id'];
            $pdo->prepare('UPDATE phone_agent_email_call_requests_v1 SET event_id=? WHERE id=? AND owner_user_id=?')->execute([$eventId,$id,$owner]);
            $pdo->prepare('INSERT INTO phone_operator_scheduled_assignments_v1(owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision,next_due_epoch)
                VALUES(?,?,0,?,?,?,?,?,?,?,1,1,0)')->execute([$owner,$eventId,(int)$gates['line']['agent_id'],(int)$p['number_id'],
                    $scope['conversation_id'],$scope['agent_id'],section12_revision($event),$p['destination_hash'],$p['line_binding']]);
            $pdo->prepare("INSERT INTO phone_operator_occurrences_v1(owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id)
                VALUES(?,?,?,?,1,'prepared','awaiting_dispatch_checks',?,0,?,?)")
                ->execute([$owner,$eventId,$now,$end,$now,$scope['conversation_id'],$scope['agent_id']]);
            $occurrence=['owner_user_id'=>$owner,'event_id'=>$eventId,'start_epoch'=>$now,'end_epoch'=>$end,
                'assignment_revision'=>1,'state'=>'prepared','contact_id'=>0,'conversation_id'=>$scope['conversation_id'],'chat_agent_id'=>$scope['agent_id']];
            $plan=vp3_operator_owner_email_plan_v1($pdo,$occurrence,$now);
            vp3_phone_agent_email_calls_check_v1(($plan['reason']??null)==='eligible',vp3_operator_owner_email_reason_v1($plan['reason']??null));
            return ['state'=>'prepared','reason'=>'awaiting_dispatch_checks','request_id'=>$id];
        });
    }catch(VP3PhoneAgentEmailCallException $e){return ['state'=>'blocked','reason'=>vp3_operator_owner_email_reason_v1($e->reason),'request_id'=>null];}
}

/** Null means a different native source, never permission to fall through for an invalid owner source. */
function vp3_operator_owner_email_plan_v1(PDO $pdo,array $occurrence,int $now,?string $answeredSid=null): ?array
{
    $owner=(int)$occurrence['owner_user_id'];$eventId=(int)$occurrence['event_id'];
    try{
        $event=vp3_operator_owner_email_event_v1($pdo,$owner,$eventId);
        if(!$event||$event['source']!=='agent'||!str_starts_with((string)$event['source_reference'],VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1))return null;
        $id=substr($event['source_reference'],strlen(VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1));
        $lock=$pdo->inTransaction();$row=vp3_operator_owner_email_record_v1($pdo,$owner,$id,$lock);
        vp3_phone_agent_email_calls_check_v1($row!==null&&(int)$row['event_id']===$eventId
            &&(int)$row['start_epoch']===(int)$occurrence['start_epoch']&&(int)$row['end_epoch']===(int)$occurrence['end_epoch']
            &&(int)($occurrence['contact_id']??-1)===0
            &&(int)$row['conversation_id']===(int)$occurrence['conversation_id']
            &&(int)$row['selected_agent_id']===(int)$occurrence['chat_agent_id'],'source_changed');
        vp3_phone_agent_email_calls_check_v1($now>=(int)$row['start_epoch']&&$now<(int)$row['end_epoch'],'outside_occurrence');
        vp3_phone_agent_email_calls_check_v1(in_array($row['state'],['prepared','submitting','submitted'],true),'request_closed');
        $context=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,$lock);
        vp3_phone_agent_email_calls_check_v1(is_string($row['authority_hash'])
            &&hash_equals($row['authority_hash'],vp3_operator_owner_email_authority_v1($context))
            &&(int)$row['holder_revision']===(int)$context['holder']['revision'],'policy_changed');
        $q=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?'.($lock?vp3_phone_workspace_lock_v1($pdo):''));
        $q->execute([$owner,$eventId]);$assignment=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($assignment!==false&&(int)$assignment['contact_id']===0&&(bool)$assignment['enabled']
            &&(int)$assignment['revision']===(int)$occurrence['assignment_revision']&&$event['status']==='active'
            &&hash_equals($assignment['event_revision'],section12_revision($event))
            &&(int)$assignment['conversation_id']===(int)$row['conversation_id']&&(int)$assignment['chat_agent_id']===(int)$row['selected_agent_id']
            &&(int)$assignment['number_id']===(int)$row['number_id']&&hash_equals($assignment['line_binding'],$row['line_binding'])
            &&hash_equals($assignment['destination_hash'],$row['destination_hash']),'assignment_changed');
        if($answeredSid!==null){
            $q=$pdo->prepare("SELECT provider_sid FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'".($lock?vp3_phone_workspace_lock_v1($pdo):''));
            $q->execute([$owner,$eventId,(int)$row['start_epoch']]);$sid=$q->fetchColumn();$q->closeCursor();
            vp3_phone_agent_email_calls_check_v1($row['state']==='submitted'&&$occurrence['state']==='submitted'
                &&is_string($sid)&&hash_equals($sid,$answeredSid),'call_changed');
        }
        $check=vp3_phone_agent_email_call_source_current_v1($pdo,$context,$row,$now,$answeredSid===null?'submit':'active');
        $sameSend=$answeredSid!==null&&($check['reason']??null)!=='eligible'
            &&vp3_operator_owner_email_sending_v1($pdo,$context,$row,$answeredSid,$now);
        vp3_phone_agent_email_calls_check_v1(($check['reason']??null)==='eligible'||$sameSend,vp3_operator_owner_email_reason_v1($check['reason']??null));
        $scope=vp3_operator_owner_email_scope_v1($pdo,$context,(int)$row['conversation_id']);
        $seconds=min((int)$row['end_epoch']-$now,(int)$context['preferences']['max_duration_seconds'],(int)$context['holder']['max_duration_seconds']);
        $gates=vp3_operator_owner_email_gates_v1($pdo,$context,$scope,$now,$seconds,
            ['id'=>$eventId,'start'=>(int)$row['start_epoch'],'end'=>(int)$row['end_epoch']],$answeredSid);
        vp3_phone_agent_email_calls_check_v1($gates['reason']==='eligible',$gates['reason']);
        vp3_phone_agent_email_calls_check_v1((int)$assignment['agent_id']===(int)$gates['line']['agent_id'],'line_changed');
        return ['reason'=>'eligible','fields'=>['From'=>$gates['line']['phone_e164'],'To'=>$gates['destination'],'TimeLimit'=>$seconds],
            'objectives'=>VP3_OPERATOR_OWNER_EMAIL_OBJECTIVE_V1,'opening'=>VP3_OPERATOR_OWNER_EMAIL_OPENING_V1,
            'conversation_id'=>$scope['conversation_id'],'chat_agent_id'=>$scope['agent_id'],'agent_id'=>(int)$gates['line']['agent_id'],
            'number_id'=>(int)$gates['line']['id'],'contact_id'=>0,'owner_user_id'=>$owner,
            'owner_email_request_id'=>$id,'owner_email_item_ref'=>$row['item_ref'],'owner_email_event_id'=>$eventId,
            'owner_email_source_revision'=>$row['source_revision'],'owner_email_authority_hash'=>$row['authority_hash']];
    }catch(VP3PhoneAgentEmailCallException $e){return ['reason'=>vp3_operator_owner_email_reason_v1($e->reason)];}
    catch(VP3PhoneWorkspaceException){return ['reason'=>'phone_unavailable'];}
}

/** A called recipient can withdraw future calls; this conveys no email or owner-account authority. */
function vp3_operator_owner_email_withdraw_v1(PDO $pdo,array $context,string $speech,int $now): bool
{
    if(!isset($context['current']['owner_email_request_id'])
        ||preg_match('/\A\s*(?:stop calling(?: me)?|do not call(?: me)?(?: again)?|don[’\x27]t call(?: me)?(?: again)?|no more calls)[.! ]*\z/iuD',$speech)!==1)return false;
    $owner=(int)$context['row']['owner_user_id'];$hash=hash('sha256',$context['current']['fields']['To']);
    if(!vp3_operator_recipient_opted_out_v1($pdo,$owner,$hash,$pdo->inTransaction()))
        $pdo->prepare('INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(?,?,?)')
            ->execute([$owner,$hash,$now]);
    $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET reason='conversation_opted_out' WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'")
        ->execute([$owner,(int)$context['row']['event_id'],(int)$context['row']['start_epoch']]);
    vp3_operator_owner_email_sync_v1($pdo,$owner,(int)$context['row']['event_id'],(int)$context['row']['start_epoch'],$now);
    return true;
}

/** Final known-before-transport veto remains distinct from an uncertain carrier attempt. */
function vp3_operator_owner_email_transport_current_v1(PDO $pdo,array $plan,?int $clock=null,?callable $http=null): array
{
    if($pdo->inTransaction())throw new LogicException('Refresh owner email call evidence outside a transaction.');
    $now=$clock??time();$owner=(int)$plan['owner_user_id'];
    $row=vp3_operator_owner_email_record_v1($pdo,$owner,$plan['owner_email_request_id']);
    vp3_phone_agent_email_calls_check_v1($row!==null&&$row['state']==='submitting','call_changed');
    $context=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now);
    $fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$row,$now,$http,'submit');
    vp3_phone_agent_email_calls_check_v1(($fresh['reason']??null)==='eligible',vp3_operator_owner_email_reason_v1($fresh['reason']??null));
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$row,$plan,$clock):array{
        $now=$clock??time();
        $q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);$occurrence=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($occurrence!==false&&$occurrence['state']==='submitting','call_changed');
        // Its own committed reservation must not count as a competing call. The
        // ordinary gates are run immediately before that reservation as well.
        $q=$pdo->prepare("SELECT * FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitting'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);$attempt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($attempt!==false&&$attempt['provider_sid']===null,'call_changed');
        $current=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,true);
        $record=vp3_operator_owner_email_record_v1($pdo,$owner,$row['id'],true);
        vp3_phone_agent_email_calls_check_v1($record!==null&&$record['state']==='submitting'
            &&hash_equals($record['authority_hash'],$plan['owner_email_authority_hash'])
            &&hash_equals($record['authority_hash'],vp3_operator_owner_email_authority_v1($current))
            &&hash_equals($record['source_revision'],$plan['owner_email_source_revision']),'policy_changed');
        $source=vp3_phone_agent_email_call_source_current_v1($pdo,$current,$record,$now,'submit');
        vp3_phone_agent_email_calls_check_v1(($source['reason']??null)==='eligible',vp3_operator_owner_email_reason_v1($source['reason']??null));
        $event=vp3_operator_owner_email_event_v1($pdo,$owner,(int)$record['event_id']);
        $q=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$record['event_id']]);$assignment=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($event!==null&&$event['status']==='active'&&$assignment!==false
            &&(bool)$assignment['enabled']&&(int)$assignment['contact_id']===0
            &&$event['source']==='agent'&&$event['source_reference']===VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1.$record['id']
            &&hash_equals($assignment['event_revision'],section12_revision($event))
            &&(int)$assignment['revision']===(int)$occurrence['assignment_revision']
            &&(int)$assignment['conversation_id']===(int)$record['conversation_id']
            &&(int)$assignment['chat_agent_id']===(int)$record['selected_agent_id']
            &&(int)$assignment['number_id']===(int)$record['number_id']
            &&hash_equals($assignment['line_binding'],$record['line_binding'])
            &&hash_equals($assignment['destination_hash'],$record['destination_hash'])
            &&(int)$occurrence['contact_id']===0&&(int)$occurrence['conversation_id']===(int)$record['conversation_id']
            &&(int)$occurrence['chat_agent_id']===(int)$record['selected_agent_id']
            &&(int)$occurrence['end_epoch']===(int)$record['end_epoch'],'assignment_changed');
        $scope=vp3_operator_owner_email_scope_v1($pdo,$current,(int)$record['conversation_id']);
        $seconds=min((int)$record['end_epoch']-$now,(int)$plan['fields']['TimeLimit']);
        vp3_phone_agent_email_calls_check_v1($seconds>0,'outside_occurrence');
        $gates=vp3_operator_owner_email_gates_v1($pdo,$current,$scope,$now,$seconds,
            ['id'=>(int)$record['event_id'],'start'=>(int)$record['start_epoch'],'end'=>(int)$record['end_epoch']],null,
            [$owner,(int)$record['event_id'],(int)$record['start_epoch']]);
        vp3_phone_agent_email_calls_check_v1($gates['reason']==='eligible',$gates['reason']);
        vp3_phone_agent_email_calls_check_v1($gates['line']['phone_e164']===$plan['fields']['From']
            &&$gates['destination']===$plan['fields']['To']&&(int)$assignment['agent_id']===(int)$gates['line']['agent_id'],'line_changed');
        $carrier=vp3_phone_twilio_state_v100();
        vp3_phone_agent_email_calls_check_v1(!empty($carrier['configured'])&&$carrier['account_sid']===$plan['account_sid']
            &&vp3_phone_browser_callback_v1('operator_answer',['event'=>'answer'])===$plan['fields']['Url']
            &&vp3_phone_browser_callback_v1('operator_answer',['event'=>'status'])===$plan['fields']['StatusCallback'],'carrier_changed');
        $plan['fields']['TimeLimit']=$seconds;return $plan;
    });
}

/** Receipt projection never needs a live Gmail grant and never restarts an episode. */
function vp3_operator_owner_email_sync_v1(PDO $pdo,int $owner,int $event,int $start,int $now): void
{
    $q=$pdo->prepare('SELECT state,reason FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?');
    $q->execute([$owner,$event,$start]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$row)return;
    $state=in_array($row['state'],['prepared','submitting','submitted','uncertain','ended','missed','blocked'],true)?$row['state']:'blocked';
    $reason=vp3_operator_owner_email_reason_v1($row['reason']);
    $pdo->prepare('UPDATE phone_agent_email_call_requests_v1 SET state=?,reason=?,updated_epoch=?,revision=revision+1
        WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND (state<>? OR reason<>?)')
        ->execute([$state,$reason,$now,$owner,$event,$start,$state,$reason]);
}

/**
 * Current native call controls for an already armed exact review. This does not
 * authorize mail: the caller must still prove the real session, claim, exact
 * draft/token and native Gmail permissions. A draft may be in its own sending
 * transition, so this helper deliberately performs no Gmail source/provider read.
 */
function vp3_operator_owner_email_control_v1(PDO $pdo,array $context,array $request,int $now,string $sid): array
{
    $owner=(int)$context['policy']['owner_user_id'];
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$context,$request,$now,$sid):array{
        $live=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,true);
        vp3_phone_agent_email_calls_check_v1(hash_equals(vp3_operator_owner_email_authority_v1($context),
            vp3_operator_owner_email_authority_v1($live)),'policy_changed');
        $row=vp3_operator_owner_email_record_v1($pdo,$owner,(string)($request['id']??''),true);
        vp3_phone_agent_email_calls_check_v1($row!==null&&$row['state']==='submitted'&&$row['source_kind']==='gmail_draft'
            &&(int)$row['start_epoch']<=$now&&$now<(int)$row['end_epoch']
            &&(int)$row['selected_agent_id']===(int)$live['policy']['selected_agent_id']
            &&(int)$row['number_id']===(int)$live['policy']['number_id']
            &&hash_equals($row['line_binding'],$live['policy']['line_binding'])
            &&hash_equals($row['destination_hash'],$live['policy']['destination_hash'])
            &&hash_equals($row['authority_hash'],vp3_operator_owner_email_authority_v1($live))
            &&(int)$row['holder_revision']===(int)$live['holder']['revision'],'call_changed');
        foreach(['authority_hash','source_id','source_revision','item_ref'] as $field)
            vp3_phone_agent_email_calls_check_v1(is_string($request[$field]??null)&&hash_equals($row[$field],$request[$field]),'call_changed');
        $key=[$owner,(int)$row['event_id'],(int)$row['start_epoch']];
        $q=$pdo->prepare("SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?".vp3_phone_workspace_lock_v1($pdo));
        $q->execute($key);$occurrence=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($occurrence!==false&&$occurrence['state']==='submitted'&&(int)$occurrence['contact_id']===0
            &&in_array($occurrence['reason'],['answer_consented','conversation_active'],true)
            &&(int)$occurrence['end_epoch']===(int)$row['end_epoch']&&(int)$occurrence['conversation_id']===(int)$row['conversation_id']
            &&(int)$occurrence['chat_agent_id']===(int)$row['selected_agent_id'],'call_changed');
        $event=vp3_operator_owner_email_event_v1($pdo,$owner,(int)$row['event_id']);
        $q=$pdo->prepare('SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND event_id=?'.vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$row['event_id']]);$assignment=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($event!==null&&$event['source']==='agent'
            &&$event['source_reference']===VP3_OPERATOR_OWNER_EMAIL_PREFIX_V1.$row['id']&&$event['status']==='active'
            &&$assignment!==false&&(bool)$assignment['enabled']&&(int)$assignment['contact_id']===0
            &&hash_equals($assignment['event_revision'],section12_revision($event))
            &&(int)$assignment['revision']===(int)$occurrence['assignment_revision']
            &&(int)$assignment['conversation_id']===(int)$row['conversation_id']&&(int)$assignment['chat_agent_id']===(int)$row['selected_agent_id']
            &&(int)$assignment['number_id']===(int)$row['number_id']&&hash_equals($assignment['line_binding'],$row['line_binding'])
            &&hash_equals($assignment['destination_hash'],$row['destination_hash']),'assignment_changed');
        $q=$pdo->prepare("SELECT * FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?".vp3_phone_workspace_lock_v1($pdo));
        $q->execute($key);$attempt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($attempt!==false&&$attempt['state']==='submitted'&&$attempt['recording_state']==='started'
            &&is_string($attempt['provider_sid'])&&hash_equals($attempt['provider_sid'],$sid)
            &&is_string($attempt['recording_sid'])&&(int)$attempt['recording_started_epoch']>0,'call_changed');
        $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND public_id=? AND provider_call_sid=?
            AND transport_kind='operator_scheduled'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$attempt['call_public_id'],$sid]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        vp3_phone_agent_email_calls_check_v1($call!==false&&$call['state']==='answered'&&$call['ended_epoch']===null
            &&(int)$call['conversation_id']===(int)$row['conversation_id']&&(int)$call['chat_agent_id']===(int)$row['selected_agent_id']
            &&(int)$call['phone_number_id']===(int)$row['number_id']&&(int)$call['agent_id']===(int)$assignment['agent_id'],'call_changed');
        $scope=vp3_operator_owner_email_scope_v1($pdo,$live,(int)$row['conversation_id']);
        $seconds=min((int)$row['end_epoch']-$now,(int)$live['preferences']['max_duration_seconds'],(int)$live['holder']['max_duration_seconds']);
        $gates=vp3_operator_owner_email_gates_v1($pdo,$live,$scope,$now,$seconds,
            ['id'=>(int)$row['event_id'],'start'=>(int)$row['start_epoch'],'end'=>(int)$row['end_epoch']],$sid);
        vp3_phone_agent_email_calls_check_v1($gates['reason']==='eligible',$gates['reason']);
        vp3_phone_agent_email_calls_check_v1((int)$assignment['agent_id']===(int)$gates['line']['agent_id']
            &&$call['phone_e164']===$gates['line']['phone_e164']&&$call['peer_e164']===$gates['destination'],'line_changed');
        $plan=['reason'=>'eligible','fields'=>['From'=>$gates['line']['phone_e164'],'To'=>$gates['destination'],'TimeLimit'=>$seconds],
            'owner_user_id'=>$owner,'contact_id'=>0,'agent_id'=>(int)$gates['line']['agent_id'],'number_id'=>(int)$gates['line']['id'],
            'conversation_id'=>$scope['conversation_id'],'chat_agent_id'=>$scope['agent_id'],'owner_email_request_id'=>$row['id'],
            'owner_email_item_ref'=>$row['item_ref'],'owner_email_source_revision'=>$row['source_revision']];
        return ['request'=>$row,'context'=>$live,'call'=>$call,'attempt'=>$attempt,'occurrence'=>$occurrence,'sid'=>$sid,'plan'=>$plan];
    });
}

/** The authenticated review can bind only to this exact consented, recorded active call. */
function vp3_operator_owner_email_active_v1(PDO $pdo,array $context,string $requestId,int $now): ?array
{
    $owner=(int)$context['policy']['owner_user_id'];
    return section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$context,$requestId,$now):?array{
        $row=vp3_operator_owner_email_record_v1($pdo,$owner,$requestId,true);
        if(!$row||$row['state']!=='submitted')return null;
        $live=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now,true);
        if(!hash_equals(vp3_operator_owner_email_authority_v1($context),vp3_operator_owner_email_authority_v1($live)))return null;
        $q=$pdo->prepare("SELECT * FROM phone_operator_carrier_attempts_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted' AND recording_state='started'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);$attempt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$attempt||!is_string($attempt['provider_sid'])||!is_string($attempt['recording_sid']))return null;
        $q=$pdo->prepare("SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);$occurrence=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$occurrence||!in_array($occurrence['reason'],['answer_consented','conversation_active'],true))return null;
        $q=$pdo->prepare("SELECT * FROM phone_browser_calls_v1 WHERE owner_user_id=? AND public_id=? AND provider_call_sid=? AND transport_kind='operator_scheduled'".vp3_phone_workspace_lock_v1($pdo));
        $q->execute([$owner,$attempt['call_public_id'],$attempt['provider_sid']]);$call=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$call||$call['state']!=='answered'||$call['ended_epoch']!==null||(int)$call['conversation_id']!==(int)$row['conversation_id']
            ||(int)$call['chat_agent_id']!==(int)$row['selected_agent_id']||(int)$call['phone_number_id']!==(int)$row['number_id'])return null;
        $plan=vp3_operator_owner_email_plan_v1($pdo,$occurrence,$now,$attempt['provider_sid']);
        if(($plan['reason']??null)!=='eligible'||$call['peer_e164']!==$plan['fields']['To']||$call['phone_e164']!==$plan['fields']['From'])return null;
        return ['request'=>$row,'context'=>$live,'call'=>$call,'sid'=>$attempt['provider_sid'],'attempt'=>$attempt,'occurrence'=>$occurrence,'plan'=>$plan];
    });
}

/** Refresh only an expiring proof, outside locks. Terminal receipts never call this. */
function vp3_operator_owner_email_refresh_call_v1(PDO $pdo,int $owner,string $sid,int $now,?callable $http=null): bool
{
    if($pdo->inTransaction())throw new LogicException('Refresh an active email call outside a transaction.');
    $q=$pdo->prepare("SELECT r.* FROM phone_agent_email_call_requests_v1 r JOIN phone_operator_carrier_attempts_v1 a
        ON a.owner_user_id=r.owner_user_id AND a.event_id=r.event_id AND a.start_epoch=r.start_epoch
        WHERE r.owner_user_id=? AND a.provider_sid=? AND a.state='submitted' AND r.state='submitted'");
    $q->execute([$owner,$sid]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$row)return false;
    try{
        $context=vp3_phone_agent_email_calls_context_v1($pdo,$owner,$now);
        $source=vp3_phone_agent_email_call_source_current_v1($pdo,$context,$row,$now,'active');
        if(($source['reason']??null)==='freshness_required')$source=vp3_phone_agent_email_call_refresh_v1($pdo,$context,$row,$now,$http,'active');
        if(($source['reason']??null)==='eligible'||vp3_operator_owner_email_sending_v1($pdo,$context,$row,$sid,$now)){
            $eligible=section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$row,$sid,$now):bool{
                $q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?'.vp3_phone_workspace_lock_v1($pdo));
                $q->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);$occurrence=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
                return $occurrence!==false&&(vp3_operator_owner_email_plan_v1($pdo,$occurrence,$now,$sid)['reason']??null)==='eligible';
            });
            if($eligible)return true;
        }
    }catch(VP3PhoneAgentEmailCallException|VP3PhoneWorkspaceException){}
    section12_owner_transaction($pdo,$owner,static function()use($pdo,$owner,$row,$now):void{
        $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET reason=CASE WHEN reason IN ('answer_consented','conversation_active') THEN 'conversation_reply_withheld' ELSE 'answer_permission_changed' END
            WHERE owner_user_id=? AND event_id=? AND start_epoch=? AND state='submitted'")
            ->execute([$owner,(int)$row['event_id'],(int)$row['start_epoch']]);
        vp3_operator_owner_email_sync_v1($pdo,$owner,(int)$row['event_id'],(int)$row['start_epoch'],$now);
    });
    return false;
}

/** No caller-selected source, private model prompt, or carrier-side Gmail action. */
function vp3_operator_owner_email_reply_v1(PDO $pdo,array $plan,string $sid,int $step,string $speech,int $now): array
{
    $context=vp3_phone_agent_email_calls_context_v1($pdo,(int)$plan['owner_user_id'],$now);
    $active=vp3_operator_owner_email_active_v1($pdo,$context,$plan['owner_email_request_id'],$now);
    if(!$active||!hash_equals($active['sid'],$sid))return ['ok'=>false];
    $reply=null;
    require_once __DIR__.'/phone-agent-email-call-review-v1.php';
    if(function_exists('vp3_phone_agent_email_call_voice_turn_v1'))$reply=vp3_phone_agent_email_call_voice_turn_v1($pdo,
        ['request'=>$active['request'],'context'=>$active['context'],'plan'=>$active['plan']],$sid,$step,$speech,$now);
    return ['ok'=>true,'answer'=>is_string($reply)&&$reply!==''?$reply:VP3_OPERATOR_OWNER_EMAIL_OPENING_V1];
}
