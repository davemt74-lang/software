<?php
declare(strict_types=1);

function agent_appointment_lifecycle_reschedule_v700(
    PDO $pdo,array $booking,string $startAtUtc,string $guestTimezone='',
    string $actorType='guest',?int $actorUserId=null,?int $actorAgentId=null
): array {
    $bookingId=(int)($booking['id']??0);$ownerId=(int)($booking['owner_user_id']??0);
    if($bookingId<1||$ownerId<1||!in_array((string)($booking['status']??''),['pending','confirmed'],true))throw new RuntimeException('Only an active appointment can be rescheduled.');
    if(function_exists('agent_paid_appointments_schema_ready_v800')&&agent_paid_appointments_schema_ready_v800($pdo)){
        $paid=agent_paid_appointments_paid_booking_for_booking_v800($pdo,$bookingId);
        if($paid&&(string)$paid['payment_status']==='awaiting_payment')throw new RuntimeException('Complete or cancel the pending appointment payment before rescheduling.');
    }
    $event=agent_scheduling_event_type_v430($pdo,(int)($booking['event_type_id']??0));
    if(!$event||(int)$event['owner_user_id']!==$ownerId||(int)$event['schedule_id']!==(int)$booking['schedule_id'])throw new RuntimeException('This appointment type is no longer available.');

    try{$start=(new DateTimeImmutable($startAtUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $e){throw new RuntimeException('Choose a valid new appointment time.');}
    $duration=max(5,(int)$booking['duration_minutes']);$end=$start->modify('+'.$duration.' minutes');
    $scheduleId=(int)$booking['schedule_id'];$lockName='vp3_schedule_'.$scheduleId;
    $lock=$pdo->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('That schedule is busy. Please try rescheduling again.');

    $started=!$pdo->inTransaction();
    try{
        if($started)$pdo->beginTransaction();
        $locked=$pdo->prepare("SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? AND schedule_id=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE");
        $locked->execute([$bookingId,$ownerId,$scheduleId]);$currentBooking=$locked->fetch();
        if(!$currentBooking)throw new RuntimeException('This appointment changed before it could be rescheduled.');
        agent_appointment_lifecycle_validate_reschedule_v700($pdo,$event,$currentBooking,$start);
        $timezone=agent_scheduling_timezone_v430($guestTimezone?:((string)$currentBooking['guest_timezone']?:$event['schedule_timezone']),(string)$event['schedule_timezone']);
        $oldStart=(string)$currentBooking['start_at_utc'];$oldEnd=(string)$currentBooking['end_at_utc'];
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET start_at_utc=?,end_at_utc=?,guest_timezone=?,status='confirmed',lifecycle_status='rescheduled',rescheduled_at=NOW(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed')");
        $update->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$timezone,$bookingId,$ownerId]);
        if($update->rowCount()!==1)throw new RuntimeException('This appointment changed before it could be rescheduled.');
        $fresh=agent_appointment_lifecycle_booking_v700($pdo,$bookingId,$ownerId);
        if(!$fresh)throw new RuntimeException('Appointment could not be reloaded.');
        agent_appointment_lifecycle_event_v700($pdo,$fresh,'rescheduled',agent_appointment_lifecycle_status_v700($currentBooking),'rescheduled',$actorType,$actorUserId,$actorAgentId,['old_start_at_utc'=>$oldStart,'old_end_at_utc'=>$oldEnd,'new_start_at_utc'=>$fresh['start_at_utc'],'new_end_at_utc'=>$fresh['end_at_utc']]);
        agent_appointment_lifecycle_reset_timed_deliveries_v700($pdo,$bookingId);
        agent_appointment_lifecycle_queue_booking_v700($pdo,$fresh,false);
        agent_appointment_lifecycle_queue_notice_v700($pdo,$fresh,'rescheduled');
        if($started)$pdo->commit();
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }finally{
        try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){}
    }
    $fresh=agent_appointment_lifecycle_booking_v700($pdo,$bookingId,$ownerId)?:throw new RuntimeException('Appointment could not be reloaded.');
    // v5.00 detects the existing provider link and PUT/PATCHes the same event id.
    if(function_exists('agent_calendar_sync_booking_v500'))agent_calendar_sync_booking_v500($pdo,$fresh);
    return $fresh;
}

function agent_appointment_lifecycle_questions_v700(PDO $pdo,int $eventTypeId,bool $activeOnly=true): array
{
    $sql='SELECT * FROM agent_scheduling_intake_questions WHERE event_type_id=?';
    if($activeOnly)$sql.=' AND is_active=1';
    $sql.=' ORDER BY sort_order,label,id';
    $stmt=$pdo->prepare($sql);$stmt->execute([$eventTypeId]);
    return $stmt->fetchAll()?:[];
}

function agent_appointment_lifecycle_save_question_v700(PDO $pdo,array $user,array $input): array
{
    $userId=(int)($user['id']??0);$eventTypeId=(int)($input['event_type_id']??0);
    $event=agent_scheduling_event_type_v430($pdo,$eventTypeId);
    if(!$event||(int)$event['owner_user_id']!==$userId)throw new RuntimeException('Appointment type not found.');
    $id=max(0,(int)($input['id']??0));$existing=null;
    if($id>0){$s=$pdo->prepare('SELECT * FROM agent_scheduling_intake_questions WHERE id=? AND event_type_id=? LIMIT 1');$s->execute([$id,$eventTypeId]);$existing=$s->fetch()?:null;if(!$existing)throw new RuntimeException('Intake question not found.');}
    $label=trim(preg_replace('/\s+/u',' ',(string)($input['label']??($existing['label']??'')))??'');if($label==='')throw new RuntimeException('Enter an intake question.');
    $type=(string)($input['question_type']??($existing['question_type']??'short_text'));if(!in_array($type,['short_text','long_text','select','checkbox'],true))$type='short_text';
    $key=agent_scheduling_slug_v430((string)($input['question_key']??($existing['question_key']??$label)))?:'question';
    $options=array_values(array_filter(array_map('trim',(array)($input['options']??[])),static fn(string $v):bool=>$v!==''));
    if(!$options&&isset($input['options_text']))$options=array_values(array_filter(array_map('trim',preg_split('/[\r\n,]+/',(string)$input['options_text'])?:[]),static fn(string $v):bool=>$v!==''));
    if($type==='select'&&!$options)throw new RuntimeException('Select questions need at least one option.');
    $collision=$pdo->prepare('SELECT id FROM agent_scheduling_intake_questions WHERE event_type_id=? AND question_key=? AND id<>? LIMIT 1');$collision->execute([$eventTypeId,$key,$id]);if($collision->fetchColumn())throw new RuntimeException('That intake question key is already in use.');
    $json=$options?json_encode(array_slice($options,0,50),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    if($existing){
        $pdo->prepare('UPDATE agent_scheduling_intake_questions SET question_key=?,label=?,question_type=?,options_json=?,is_required=?,is_active=?,sort_order=? WHERE id=? AND event_type_id=?')
            ->execute([$key,mb_strimwidth($label,0,500,''),$type,$json,!empty($input['is_required'])?1:0,array_key_exists('is_active',$input)?(!empty($input['is_active'])?1:0):(int)$existing['is_active'],(int)($input['sort_order']??$existing['sort_order']),$id,$eventTypeId]);
    }else{
        $pdo->prepare('INSERT INTO agent_scheduling_intake_questions (event_type_id,question_key,label,question_type,options_json,is_required,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$eventTypeId,$key,mb_strimwidth($label,0,500,''),$type,$json,!empty($input['is_required'])?1:0,1,(int)($input['sort_order']??0)]);
        $id=(int)$pdo->lastInsertId();
    }
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_intake_questions WHERE id=? LIMIT 1');$stmt->execute([$id]);
    return $stmt->fetch()?:throw new RuntimeException('Intake question could not be saved.');
}

function agent_appointment_lifecycle_validate_intake_v700(PDO $pdo,int $eventTypeId,array $answers): void
{
    foreach(agent_appointment_lifecycle_questions_v700($pdo,$eventTypeId,true) as $q){
        $id=(int)$q['id'];$value=$answers[$id]??'';
        if(is_array($value))$value=implode(', ',array_map('strval',$value));
        $value=trim((string)$value);$type=(string)$q['question_type'];
        if($type==='checkbox')$value=in_array(strtolower($value),['1','yes','true','on'],true)?'Yes':'No';
        if(!empty($q['is_required'])&&($value===''||($type==='checkbox'&&$value!=='Yes')))throw new RuntimeException('Please answer: '.(string)$q['label']);
        if($type==='select'&&$value!==''){
            $options=json_decode((string)($q['options_json']??'[]'),true);
            if(!is_array($options)||!in_array($value,$options,true))throw new RuntimeException('Choose a valid answer for: '.(string)$q['label']);
        }
    }
}

