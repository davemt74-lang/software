<?php
declare(strict_types=1);
require_once __DIR__.'/phone-contact-management-v1.php';
require_once __DIR__.'/agent-operator-dispatch-preview-v1.php';

function vp3_operator_agenda_key_v1(array $scope,int $contact):array
{
    return [(int)$scope['owner_user_id'],$contact,(int)$scope['conversation_id'],(int)($scope['agent_id']??$scope['chat_agent_id'])];
}

function vp3_operator_agenda_row_v1(PDO $pdo,array $key):?array
{
    $q=$pdo->prepare('SELECT * FROM phone_operator_agendas_v1 WHERE owner_user_id=? AND contact_id=? AND conversation_id=? AND chat_agent_id=?');
    $q->execute($key);
    return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_operator_agenda_view_v1(?array $row):array
{
    return ['topics'=>(string)($row['topics']??''),'questions'=>(string)($row['questions']??''),
        'review_missed'=>(bool)($row['review_missed']??false),'enabled'=>(bool)($row['enabled']??false),
        'revision'=>(int)($row['revision']??0),'number_id'=>(int)($row['number_id']??0)];
}

function vp3_operator_agenda_save_v1(PDO $pdo,array $scope,array $input):array
{
    $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);
    $contact=vp3_phone_workspace_id_v1($input['contact_id']??null);
    $number=vp3_phone_workspace_id_v1($input['number_id']??null);
    $topics=vp3_phone_contact_text_v1($input['topics']??null,'conversation topics',700,false,true);
    $questions=vp3_phone_contact_text_v1($input['questions']??null,'conversation questions',700,false,true);
    if(!$scope['conversation_id']||!is_int($input['expected_revision']??null)||!is_bool($input['enabled']??null)
        ||!is_bool($input['review_missed']??null)||mb_strwidth($topics.$questions)>950)
        vp3_phone_workspace_error_v1('operator_agenda_invalid',422,'Open a saved Chat and keep the combined call agenda under 950 characters.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$contact,$number,$topics,$questions){
        $person=vp3_phone_contact_row_v1($pdo,$scope,$contact);
        $key=vp3_operator_agenda_key_v1($scope,$contact);
        $row=vp3_operator_agenda_row_v1($pdo,$key);
        if((int)($row['revision']??0)!==$input['expected_revision'])
            vp3_phone_workspace_error_v1('operator_agenda_changed',409,'Reload this contact to see its current call agenda.');
        if($input['enabled']||!$row||(int)$row['number_id']!==$number)vp3_phone_workspace_number_v1($pdo,$scope,$number);
        $destination=vp3_phone_caller_contacts_e164_v1((string)$person['phone']);
        $values=[$number,hash('sha256',$destination),$topics,$questions,(int)$input['review_missed'],(int)$input['enabled']];
        if($row){
            $pdo->prepare('UPDATE phone_operator_agendas_v1 SET number_id=?,destination_hash=?,topics=?,questions=?,review_missed=?,enabled=?,revision=revision+1,checked_epoch=0
                WHERE owner_user_id=? AND contact_id=? AND conversation_id=? AND chat_agent_id=?')->execute([...$values,...$key]);
        }else{
            $pdo->prepare('INSERT INTO phone_operator_agendas_v1(number_id,destination_hash,topics,questions,review_missed,enabled,owner_user_id,contact_id,conversation_id,chat_agent_id,revision,checked_epoch)
                VALUES(?,?,?,?,?,?,?,?,?,?,1,0)')->execute([...$values,...$key]);
        }
        return ['agenda'=>vp3_operator_agenda_view_v1(vp3_operator_agenda_row_v1($pdo,$key))];
    });
}

/** Select one contact-specific reason and preserve the owner's questions in the voice briefing. */
function vp3_operator_outreach_reason_v1(PDO $pdo,array $agenda,string $purpose,int $now,?string $answeredSid=null):?array
{
    if(!in_array($purpose,['follow_up','requested_check_in'],true))return null;
    $brief=[];
    $source='';
    $missedEvent=0;
    if($agenda['review_missed']&&$purpose==='follow_up'){
        $q=$pdo->prepare("SELECT o.event_id,o.start_epoch,o.state,o.reason,e.recurrence,e.status,a.enabled,a.event_revision FROM phone_operator_occurrences_v1 o
            JOIN user_calendar_events e ON e.owner_user_id=o.owner_user_id AND e.id=o.event_id
            JOIN phone_operator_scheduled_assignments_v1 a ON a.owner_user_id=o.owner_user_id AND a.event_id=o.event_id
            WHERE o.owner_user_id=? AND o.contact_id=? AND o.conversation_id=? AND o.chat_agent_id=?
            AND a.contact_id=o.contact_id AND a.conversation_id=o.conversation_id AND a.chat_agent_id=o.chat_agent_id
            AND a.destination_hash=? AND a.number_id=? AND o.start_epoch BETWEEN ? AND ? AND e.source='user' ORDER BY o.start_epoch DESC,o.event_id DESC LIMIT 1");
        $q->execute([...vp3_operator_agenda_key_v1($agenda,(int)$agenda['contact_id']),$agenda['destination_hash'],$agenda['number_id'],$now-7*86400,$now]);
        $miss=$q->fetch(PDO::FETCH_ASSOC);
        if($miss&&$miss['state']==='missed'&&in_array($miss['reason'],['window_ended','dispatch_window_expired'],true)
            &&$miss['enabled']&&$miss['status']==='active'&&$miss['recurrence']==='none'){
            $event=user_calendar_event_v1300($pdo,(int)$agenda['owner_user_id'],(int)$miss['event_id']);
            $person=vp3_phone_contact_row_v1($pdo,$agenda,(int)$agenda['contact_id']);
            $newer=$pdo->prepare("SELECT 1 FROM phone_browser_calls_v1 WHERE owner_user_id=? AND peer_e164=?
                AND created_epoch>? AND created_epoch<=? AND state IN ('answered','active','completed')
                AND (provider_call_sid IS NULL OR provider_call_sid<>?) LIMIT 1");
            $newer->execute([$agenda['owner_user_id'],vp3_phone_caller_contacts_e164_v1((string)$person['phone']),$miss['start_epoch'],$now,$answeredSid??'']);
            if(!$newer->fetchColumn()&&$event&&hash_equals($miss['event_revision'],section12_revision($event))){
                $missedEvent=(int)$miss['event_id'];
                $source='missed:'.$miss['event_id'].':'.$miss['start_epoch'];
                $brief[]='Follow up on the missed scheduled call. Ask whether this is a good time or whether to arrange another time.';
            }
        }
    }
    if($agenda['topics']!=='')$brief[]="Topics to discuss\n".$agenda['topics'];
    if($agenda['questions']!=='')$brief[]="Questions from the owner\n".$agenda['questions'];
    if(!$brief)return null;
    $objective=implode("\n\n",$brief);
    return ['objective'=>$objective,'fingerprint'=>hash('sha256',($agenda['topics']!==''||$agenda['questions']!=='')?json_encode([$agenda['topics'],$agenda['questions']],JSON_THROW_ON_ERROR):$source),'account_reason'=>$source!=='','missed_event_id'=>$missedEvent];
}

function vp3_operator_outreach_prepare_v1(PDO $pdo,array $agenda,int $now):string
{
    $owner=(int)$agenda['owner_user_id'];
    $contact=(int)$agenda['contact_id'];
    $scope=vp3_phone_workspace_scope_v1($pdo,['id'=>$owner],['conversation_id'=>$agenda['conversation_id'],'agent_id'=>$agenda['chat_agent_id']]);
    $number=vp3_phone_workspace_number_v1($pdo,$scope,(int)$agenda['number_id']);
    $policy=vp3_operator_auto_call_row_v1($pdo,$scope,$contact,(int)$number['agent_id']);
    if(!$policy||!$policy['enabled'])return 'permission';
    $bound=vp3_operator_admission_context_v1($pdo,$scope,['contact_id'=>$contact,'number_id'=>(int)$number['id'],'expected_revision'=>(int)$policy['revision']]);
    if(!hash_equals($agenda['destination_hash'],$bound['destination_hash']))return 'contact_changed';
    $review=vp3_operator_consent_row_v1($pdo,$scope,$bound);
    $withdrawn=vp3_operator_recipient_opted_out_v1($pdo,$owner,$bound['destination_hash']);
    if(vp3_operator_consent_state_v1($review,$bound,$withdrawn,$now)!=='owner_attested'
        ||!vp3_operator_holder_modes_v1($pdo,$owner,$contact,$bound['contact']['destination_e164'],$now)['spontaneous']
        ||vp3_operator_admission_policy_block_v1($pdo,$scope,$bound)!=='')return 'permission';
    $reason=vp3_operator_outreach_reason_v1($pdo,$agenda,$review['purpose'],$now);
    if(!$reason)return 'no_reason';
    $hash=$reason['fingerprint'];
    $used=$pdo->prepare('SELECT 1 FROM phone_operator_outreach_uses_v1 WHERE owner_user_id=? AND contact_id=? AND reason_hash=?');
    $used->execute([$owner,$contact,$hash]);
    if($used->fetchColumn())return 'already_planned';
    $end=$now+(int)$policy['max_duration_seconds'];
    $event=user_calendar_create_event_locked_v1300($pdo,$scope['user'],['title'=>'Operator call with '.vp3_phone_contact_row_v1($pdo,$scope,$contact)['name'],
        'description'=>$reason['objective'],'start_at_utc'=>gmdate('Y-m-d H:i:s',$now),'end_at_utc'=>gmdate('Y-m-d H:i:s',$end),'timezone'=>$policy['timezone']],
        'agent',(int)$number['agent_id'],'operator-outreach:'.$contact.':'.$hash);
    $id=(int)$event['id'];
    $line=$bound['line'];
    $pdo->prepare('INSERT INTO phone_operator_scheduled_assignments_v1(owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision)
        VALUES(?,?,?,?,?,?,?,?,?,?,1,1)')->execute([$owner,$id,$contact,$line['agent_id'],$line['id'],$scope['conversation_id'],$scope['agent_id'],section12_revision($event),$bound['destination_hash'],hash('sha256',$line['provider_sid'].'|'.$line['phone_e164'])]);
    $pdo->prepare('INSERT INTO phone_operator_outreach_uses_v1(owner_user_id,contact_id,reason_hash,event_id,agenda_revision,purpose,missed_event_id)
        VALUES(?,?,?,?,?,?,?)')->execute([$owner,$contact,$hash,$id,$agenda['revision'],$review['purpose'],$reason['missed_event_id']]);
    $occurrence=['owner_user_id'=>$owner,'event_id'=>$id,'start_epoch'=>$now,'end_epoch'=>$end,'assignment_revision'=>1];
    $plan=vp3_operator_current_request_v1($pdo,$occurrence,$now);
    if($plan['reason']!=='eligible')throw new VP3OperatorOutreachDeferred($plan['reason']);
    $pdo->prepare("INSERT INTO phone_operator_occurrences_v1(owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id)
        VALUES(?,?,?,?,1,'prepared','awaiting_dispatch_checks',?,?,?,?)")->execute([$owner,$id,$now,$end,$now,$contact,$scope['conversation_id'],$scope['agent_id']]);
    return 'prepared';
}

final class VP3OperatorOutreachDeferred extends RuntimeException {}

/** Persist an objective once, inside the owner lock. Failed admission rolls back the Calendar event too. */
function vp3_operator_outreach_tick_v1(PDO $pdo,int $now):array
{
    $q=$pdo->prepare('SELECT owner_user_id,contact_id,conversation_id,chat_agent_id,revision FROM phone_operator_agendas_v1
        WHERE enabled=1 AND checked_epoch<? ORDER BY checked_epoch,owner_user_id,contact_id,conversation_id,chat_agent_id LIMIT 40');
    $q->execute([$now-60]);
    $counts=['prepared'=>0,'deferred'=>0,'errors'=>0];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
        $key=vp3_operator_agenda_key_v1($candidate,(int)$candidate['contact_id']);
        $claim=$pdo->prepare('UPDATE phone_operator_agendas_v1 SET checked_epoch=? WHERE owner_user_id=? AND contact_id=? AND conversation_id=? AND chat_agent_id=? AND revision=? AND enabled=1 AND checked_epoch<?');
        $claim->execute([$now,...$key,$candidate['revision'],$now-60]);
        if(!$claim->rowCount())continue;
        try{
            $state=section12_owner_transaction($pdo,$key[0],function()use($pdo,$key,$now,$candidate){
                $agenda=vp3_operator_agenda_row_v1($pdo,$key);
                if(!$agenda||!$agenda['enabled']||(int)$agenda['revision']!==(int)$candidate['revision'])return 'changed';
                return vp3_operator_outreach_prepare_v1($pdo,$agenda,$now);
            });
            $counts[$state==='prepared'?'prepared':'deferred']++;
        }catch(VP3OperatorOutreachDeferred|VP3PhoneWorkspaceException){$counts['deferred']++;}
        catch(Throwable){$counts['errors']++;}
    }
    return $counts;
}
