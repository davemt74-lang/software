<?php
declare(strict_types=1);

/** Saved call reminders reuse native Calendar series. They never authorize dialing. */
require_once __DIR__.'/phone-contact-management-v1.php';
require_once __DIR__.'/agent-operator-calendar-v1.php';

function vp3_phone_contact_schedule_ready_v1(PDO $pdo): bool
{
    if(!vp3_operator_calendar_ready_v1($pdo))return false;
    try{$pdo->query('SELECT id,owner_user_id,created_by_user_id,created_by_agent_id,title,description,location,start_at_utc,end_at_utc,timezone,all_day,recurrence,agent_notifications,source,source_reference,status,cancelled_at,created_at,updated_at FROM user_calendar_events WHERE 1=0');return true;}
    catch(PDOException){return false;}
}

function vp3_phone_contact_schedule_bound_v1(array $event,array $scope,int $contact): bool
{
    if((int)$event['owner_user_id']!==$scope['owner_user_id']||$event['source']!=='user'
        ||!preg_match('/^operator-call-v1:([1-9][0-9]{0,14}):([1-9][0-9]{0,14}):[a-f0-9]{64}$/D',(string)$event['source_reference'],$parts))return false;
    return (int)$parts[1]===$contact&&($scope['agent_id']===0||(int)$parts[2]===$scope['agent_id']);
}
function vp3_phone_contact_schedule_public_v1(array $event,int $now): array
{
    $zone=new DateTimeZone((string)$event['timezone']);
    $start=user_calendar_validate_utc_v1300((string)$event['start_at_utc']);$end=user_calendar_validate_utc_v1300((string)$event['end_at_utc']);
    $next=user_calendar_occurrences_v1340($event,gmdate('Y-m-d H:i:s',$now),gmdate('Y-m-d H:i:s',$now+370*86400));
    $upcoming=$next[0]??null;$date=$start->setTimezone($zone);
    $duration=max(1,(int)(($end->getTimestamp()-$start->getTimestamp())/60));
    $editable=empty($event['all_day'])&&$duration<=60&&mb_strlen((string)($event['description']??''))<=2000
        &&in_array($zone->getName(),DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true);
    return ['event_id'=>(int)$event['id'],'revision'=>section12_revision($event),'title'=>(string)$event['title'],
        'date'=>$date->format('Y-m-d'),'time'=>$date->format('H:i'),'timezone'=>$zone->getName(),
        'recurrence'=>(string)($event['recurrence']??'none'),'duration_minutes'=>$duration,'editable'=>$editable,
        'objectives'=>(string)($event['description']??''),'status'=>(string)$event['status'],
        'next_at'=>$upcoming?user_calendar_validate_utc_v1300($upcoming['start_at_utc'])->format(DATE_ATOM):'',
        'next_label'=>$upcoming?user_calendar_validate_utc_v1300($upcoming['start_at_utc'])->setTimezone($zone)->format('D, M j, g:i A T'):'',
        'url'=>url('/calendar-event.php?id='.(int)$event['id']),'execution_enabled'=>false];
}
function vp3_phone_contact_schedule_book_v1(PDO $pdo,array $scope,int $contact,int $now): array
{
    $empty=['available'=>false,'execution_enabled'=>false,'items'=>[]];
    if(!vp3_phone_contact_schedule_ready_v1($pdo))return $empty;
    $q=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='user' AND source_reference LIKE ?
        AND status='active' AND (end_at_utc>? OR recurrence IN ('daily','weekly')) ORDER BY start_at_utc,id LIMIT 51");
    $prefix=VP3_OPERATOR_CALENDAR_PREFIX_V1.$contact.':'.($scope['agent_id']>0?$scope['agent_id'].':':'').'%';
    $q->execute([$scope['owner_user_id'],$prefix,gmdate('Y-m-d H:i:s',$now)]);$items=[];
    $assignments=[];
    $history=[];
    $assignable=vp3_operator_schedule_assignment_ready_v1($pdo)&&$scope['conversation_id']>0;
    if($assignable){
        $a=$pdo->prepare('SELECT event_id,revision,event_revision,enabled,destination_hash FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=? AND contact_id=? AND event_id IN (SELECT id FROM user_calendar_events WHERE owner_user_id=? AND status=\'active\')');
        $a->execute([$scope['owner_user_id'],$contact,$scope['owner_user_id']]);
        $assignments=array_column($a->fetchAll(PDO::FETCH_ASSOC),null,'event_id');
        $receipts=$pdo->prepare('SELECT o.event_id,o.start_epoch,o.state,o.reason FROM phone_operator_occurrences_v1 o
            WHERE o.owner_user_id=? AND o.contact_id=? AND o.conversation_id=? AND o.chat_agent_id=?
            ORDER BY o.start_epoch DESC,o.event_id DESC LIMIT 20');
        $receipts->execute([$scope['owner_user_id'],$contact,$scope['conversation_id'],$scope['agent_id']]);
        $history=$receipts->fetchAll(PDO::FETCH_ASSOC);
        $recent=[];
        foreach($history as $receipt)$recent[$receipt['event_id']]??=$receipt;
        $person=vp3_phone_contact_row_v1($pdo,$scope,$contact);
        $destinationHash=hash('sha256',vp3_phone_caller_contacts_e164_v1((string)$person['phone']));
    }
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $event){
        if(!vp3_phone_contact_schedule_bound_v1($event,$scope,$contact))continue;
        $item=vp3_phone_contact_schedule_public_v1($event,$now);
        if($assignable){
            $a=$assignments[$event['id']]??null;
            if(isset($recent[$event['id']]))$item['last_occurrence']=$recent[$event['id']];
            $item['assignment_revision']=(int)($a['revision']??0);
            $item['operator_assignment']=empty($a['enabled'])?'off':($a['event_revision']===$item['revision']&&$a['destination_hash']===$destinationHash?'assigned':'review');
        }
        $items[]=$item;
    }
    usort($items,static fn(array $a,array $b):int=>strcmp($a['next_at'],$b['next_at'])?:($a['event_id']<=>$b['event_id']));
    return ['available'=>true,'execution_enabled'=>false,'items'=>array_slice($items,0,20),'limited'=>count($items)>20]+($assignable?['history'=>$history]:[]);
}
function vp3_phone_contact_schedule_event_v1(PDO $pdo,array $scope,int $contact,int $id): array
{
    $event=user_calendar_event_v1300($pdo,$scope['owner_user_id'],$id);
    if(!$event||!vp3_phone_contact_schedule_bound_v1($event,$scope,$contact))
        vp3_phone_workspace_error_v1('phone_contact_schedule_unavailable',404,'This scheduled call is unavailable.');
    return $event;
}
function vp3_phone_contact_schedule_save_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    $allowed=['action','csrf_token','agent_id','conversation_id','contact_id','contact_revision','number_id','event_id','expected_revision','mutation_id','date','time','timezone','recurrence','duration_minutes','objectives','confirmed'];
    if(array_diff(array_keys($input),$allowed)||($input['confirmed']??null)!==true)
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Review and save the scheduled call.');
    $contact=vp3_phone_workspace_id_v1($input['contact_id']??null);$id=vp3_phone_workspace_id_v1($input['event_id']??0,true);
    $number=vp3_phone_workspace_id_v1($input['number_id']??null);$revision=$input['contact_revision']??null;$expected=$input['expected_revision']??'';
    if(!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)||!is_string($expected)
        ||($id>0&&!preg_match('/^[a-f0-9]{64}$/D',$expected))||($id===0&&$expected!==''))
        vp3_phone_workspace_error_v1('phone_contact_changed',409,'Reload the contact and schedule before saving.');
    $mutation=$input['mutation_id']??null;
    if(!is_string($mutation)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$mutation))
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Open a fresh schedule form.');
    $date=$input['date']??null;$time=$input['time']??null;$timezone=$input['timezone']??null;$recurrence=$input['recurrence']??null;$duration=$input['duration_minutes']??null;
    if(!is_string($date)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)||!is_string($time)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time)
        ||!is_string($timezone)||!in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)
        ||!in_array($recurrence,['none','daily','weekly'],true)||!is_int($duration)||$duration<1||$duration>60)
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Choose a date, time, time zone, repeat option and call length from 1 to 60 minutes.');
    $objectives=vp3_phone_contact_text_v1($input['objectives']??'','call notes and objectives',2000,false,true);
    if(!vp3_phone_contact_schedule_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_contact_schedule_upgrade',503,'Run the Cloud Calendar upgrade to save scheduled calls.');
    try{$range=user_calendar_local_range_v1300(['date'=>$date,'start_time'=>$time,'timezone'=>$timezone,'duration_minutes'=>$duration]);}
    catch(RuntimeException){vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Choose a valid date and time in this time zone. That local time may not exist.');}
    // The Calendar helper defaults short events to five minutes; preserve the explicitly reviewed call length.
    $start=user_calendar_validate_utc_v1300($range['start_at_utc'])->getTimestamp();
    $range['end_at_utc']=gmdate('Y-m-d H:i:s',$start+$duration*60);
    $endLocal=(new DateTimeImmutable('@'.($start+$duration*60)))->setTimezone(new DateTimeZone($timezone));
    $local=['date'=>$date,'start_time'=>$time,'end_date'=>$endLocal->format('Y-m-d'),'end_time'=>$endLocal->format('H:i'),'timezone'=>$timezone,'expected_revision'=>$expected];
    try{$roundTrip=user_calendar_local_range_v1300($local);}
    catch(RuntimeException){vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'This call crosses a daylight-saving time change. Choose a time outside that change.');}
    if($roundTrip['start_at_utc']!==$range['start_at_utc']||$roundTrip['end_at_utc']!==$range['end_at_utc'])
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'This call crosses a daylight-saving time change. Choose a time outside that change.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$contact,$id,$number,$revision,$expected,$mutation,$range,$recurrence,$duration,$objectives,$now,$start,$local):array{
        $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);$person=vp3_phone_contact_row_v1($pdo,$scope,$contact);
        if(!hash_equals(section12_revision($person),$revision))vp3_phone_workspace_error_v1('phone_contact_changed',409,'This contact changed. Reload it before saving a schedule.');
        $line=vp3_phone_workspace_number_v1($pdo,$scope,$number);
        $old=$id>0?vp3_phone_contact_schedule_event_v1($pdo,$scope,$contact,$id):null;
        if($old&&($old['status']!=='active'||!hash_equals(section12_revision($old),$expected)))vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'This schedule changed. Reload it before saving.');
        $source=VP3_OPERATOR_CALENDAR_PREFIX_V1.$contact.':'.(int)$line['agent_id'].':'.hash('sha256','contact-schedule:'.$person['public_id'].':'.$mutation);
        $title='Call '.mb_substr((string)$person['name'],0,120);
        $values=$range+['title'=>$title,'description'=>$objectives,'location'=>'','all_day'=>false,'recurrence'=>$recurrence,'agent_notifications'=>true];
        if(!$old){
            $q=$pdo->prepare("SELECT * FROM user_calendar_events WHERE owner_user_id=? AND source='user' AND source_reference=? LIMIT 1");$q->execute([$scope['owner_user_id'],$source]);$replay=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if($replay){
                foreach(['title','description','start_at_utc','end_at_utc','timezone','recurrence'] as $key)if((string)$replay[$key]!==$values[$key])vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'This schedule request was already saved with different details. Reload the schedules.');
                if($replay['status']!=='active')vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'This schedule was canceled. Create a new schedule.');
                return ['schedule'=>vp3_phone_contact_schedule_public_v1($replay,$now),'created'=>false,'execution_enabled'=>false];
            }
            $q=$pdo->prepare("SELECT COUNT(*) FROM user_calendar_events WHERE owner_user_id=? AND source='user' AND source_reference LIKE ? AND status='active' AND (end_at_utc>? OR recurrence IN ('daily','weekly'))");
            $q->execute([$scope['owner_user_id'],VP3_OPERATOR_CALENDAR_PREFIX_V1.$contact.':%',gmdate('Y-m-d H:i:s',$now)]);
            if((int)$q->fetchColumn()>=20)vp3_phone_workspace_error_v1('phone_contact_schedule_limit',422,'Keep up to 20 active schedules per contact. Cancel one before adding another.');
        }
        // An existing repeating series may keep its historical anchor when its notes or local time change.
        $keepsAnchor=$old&&in_array($old['recurrence'],['daily','weekly'],true)&&$recurrence!=='none'
            &&user_calendar_validate_utc_v1300((string)$old['start_at_utc'])->setTimezone(new DateTimeZone((string)$old['timezone']))->format('Y-m-d')===$input['date'];
        if(($start<$now+60&&!$keepsAnchor)||$start>$now+365*86400)
            vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Choose a future first call within the next year.');
        // All reminders use the canonical recurrence projection and Calendar/bookings conflict check.
        $fromEpoch=max($now,$start);$fromDate=gmdate('Y-m-d H:i:s',$fromEpoch);
        $until=$recurrence==='none'?$start+$duration*60:$fromEpoch+90*86400;
        $candidate=['id'=>$id,'status'=>'active','start_at_utc'=>$range['start_at_utc'],'end_at_utc'=>$range['end_at_utc'],'timezone'=>$range['timezone'],'recurrence'=>$recurrence];
        $occurrences=user_calendar_occurrences_v1340($candidate,$fromDate,gmdate('Y-m-d H:i:s',$until));
        $events=user_calendar_events_v1300($pdo,$scope['user'],$fromDate,gmdate('Y-m-d H:i:s',$until),false);
        foreach($events as $event){
            if($id>0&&$event['kind']==='event'&&(int)$event['id']===$id)continue;
            $from=user_calendar_validate_utc_v1300($event['start_at_utc'])->getTimestamp();$to=user_calendar_validate_utc_v1300($event['end_at_utc'])->getTimestamp();
            foreach($occurrences as $occurrence){$a=user_calendar_validate_utc_v1300($occurrence['start_at_utc'])->getTimestamp();$b=user_calendar_validate_utc_v1300($occurrence['end_at_utc'])->getTimestamp();
                if($a<$to&&$b>$from)vp3_phone_workspace_error_v1('phone_contact_schedule_conflict',409,'A scheduled time conflicts with your Cloud Calendar. Choose another time.');}
        }
        if($old){
            $event=user_calendar_update_event_locked_v1300($pdo,$scope['user'],$id,$values+$local);
        }else $event=user_calendar_create_event_locked_v1300($pdo,$scope['user'],$values,'user',null,$source);
        return ['schedule'=>vp3_phone_contact_schedule_public_v1($event,$now),'created'=>$old===null,'execution_enabled'=>false];
    });
}
function vp3_phone_contact_schedule_cancel_v1(PDO $pdo,array $scope,array $input,int $now): array
{
    if(array_diff(array_keys($input),['action','csrf_token','agent_id','conversation_id','contact_id','event_id','expected_revision','confirmed'])||($input['confirmed']??null)!==true)
        vp3_phone_workspace_error_v1('phone_contact_schedule_invalid',422,'Confirm canceling this scheduled call.');
    $contact=vp3_phone_workspace_id_v1($input['contact_id']??null);$id=vp3_phone_workspace_id_v1($input['event_id']??null);$expected=$input['expected_revision']??null;
    if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected))vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'Reload this schedule before canceling it.');
    if(!vp3_phone_contact_schedule_ready_v1($pdo))vp3_phone_workspace_error_v1('phone_contact_schedule_upgrade',503,'Run the Cloud Calendar upgrade to manage scheduled calls.');
    return section12_owner_transaction($pdo,$scope['owner_user_id'],function()use($pdo,$scope,$input,$contact,$id,$expected,$now):array{
        $scope=vp3_phone_contact_private_v1($pdo,$scope,$input);vp3_phone_contact_row_v1($pdo,$scope,$contact);
        $event=vp3_phone_contact_schedule_event_v1($pdo,$scope,$contact,$id);
        if(!hash_equals(section12_revision($event),$expected))vp3_phone_workspace_error_v1('phone_contact_schedule_changed',409,'This schedule changed. Reload it before canceling.');
        user_calendar_cancel_event_locked_v1300($pdo,$scope['user'],$id,$expected);
        return ['event_id'=>$id,'cancelled'=>true,'execution_enabled'=>false];
    });
}
