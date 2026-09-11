<?php
declare(strict_types=1);

function agent_appointment_lifecycle_booking_v700(PDO $pdo,int $bookingId,?int $ownerUserId=null): ?array
{
    if($bookingId<1)return null;
    $sql='SELECT b.*,e.slug AS event_slug,e.confirmation_enabled,e.reminder_24h_enabled,e.reminder_soon_minutes,e.agent_prep_minutes FROM agent_scheduling_bookings b LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id WHERE b.id=?';
    $args=[$bookingId];
    if($ownerUserId!==null&&$ownerUserId>0){$sql.=' AND b.owner_user_id=?';$args[]=$ownerUserId;}
    $sql.=' LIMIT 1';
    $stmt=$pdo->prepare($sql);$stmt->execute($args);
    return $stmt->fetch()?:null;
}

function agent_appointment_lifecycle_status_v700(array $booking): string
{
    $status=trim((string)($booking['lifecycle_status']??''));
    if($status!=='')return $status;
    return match((string)($booking['status']??'')){
        'cancelled'=>'cancelled','completed'=>'completed','no_show'=>'no_show',default=>'confirmed'
    };
}

function agent_appointment_lifecycle_event_v700(
    PDO $pdo,array $booking,string $eventType,string $from,string $to,
    string $actorType='system',?int $actorUserId=null,?int $actorAgentId=null,array $details=[]
): void {
    $json=$details?json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null;
    $pdo->prepare('INSERT INTO agent_scheduling_lifecycle_events (booking_id,owner_user_id,event_type,from_status,to_status,actor_type,actor_user_id,actor_agent_id,details_json) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$booking['id'],(int)$booking['owner_user_id'],mb_strimwidth($eventType,0,50,''),mb_strimwidth($from,0,24,''),mb_strimwidth($to,0,24,''),mb_strimwidth($actorType,0,24,''),$actorUserId?:null,$actorAgentId?:null,$json]);
    $pdo->prepare('UPDATE agent_scheduling_bookings SET last_lifecycle_event_at=NOW() WHERE id=?')->execute([(int)$booking['id']]);
}

function agent_appointment_lifecycle_transition_v700(
    PDO $pdo,array $booking,string $to,string $actorType='member',?int $actorUserId=null,?int $actorAgentId=null,array $details=[]
): array {
    $current=agent_appointment_lifecycle_status_v700($booking);
    $to=strtolower(trim($to));
    $allowed=[
        'confirmed'=>['completed','cancelled','no_show'],
        'rescheduled'=>['completed','cancelled','no_show'],
        'pending'=>['confirmed','cancelled'],
    ];
    if($to===$current)return $booking;
    if(!in_array($to,$allowed[$current]??[],true))throw new RuntimeException('That appointment lifecycle transition is not allowed.');

    if($to==='cancelled'){
        if(!agent_scheduling_cancel_booking_v430($pdo,(int)$booking['id'],(int)$booking['owner_user_id']))throw new RuntimeException('Appointment could not be cancelled.');
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status='cancelled' WHERE id=? AND owner_user_id=? AND status='cancelled'");
        $update->execute([(int)$booking['id'],(int)$booking['owner_user_id']]);
    }elseif($to==='completed'){
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET status='completed',lifecycle_status='completed',completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed')");
        $update->execute([(int)$booking['id'],(int)$booking['owner_user_id']]);
        if($update->rowCount()!==1)throw new RuntimeException('That appointment changed before it could be marked completed.');
    }elseif($to==='no_show'){
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET status='no_show',lifecycle_status='no_show',no_show_at=COALESCE(no_show_at,NOW()),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('pending','confirmed')");
        $update->execute([(int)$booking['id'],(int)$booking['owner_user_id']]);
        if($update->rowCount()!==1)throw new RuntimeException('That appointment changed before it could be marked no-show.');
    }else{
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET status='confirmed',lifecycle_status='confirmed',updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='pending'");
        $update->execute([(int)$booking['id'],(int)$booking['owner_user_id']]);
        if($update->rowCount()!==1)throw new RuntimeException('That appointment changed before it could be confirmed.');
    }

    $fresh=agent_appointment_lifecycle_booking_v700($pdo,(int)$booking['id'],(int)$booking['owner_user_id']);
    if(!$fresh)throw new RuntimeException('Appointment could not be reloaded.');
    agent_appointment_lifecycle_event_v700($pdo,$fresh,'status_changed',$current,$to,$actorType,$actorUserId,$actorAgentId,$details);
    agent_appointment_lifecycle_queue_notice_v700($pdo,$fresh,$to);
    return $fresh;
}

function agent_appointment_lifecycle_validate_reschedule_v700(PDO $pdo,array $event,array $booking,DateTimeImmutable $startUtcObject): void
{
    $timezone=agent_scheduling_timezone_v430((string)$event['schedule_timezone']);$tz=new DateTimeZone($timezone);
    $localStart=$startUtcObject->setTimezone($tz);$duration=max(5,min(1440,(int)$event['duration_minutes']));
    $localDate=$localStart->format('Y-m-d');$startMinute=((int)$localStart->format('G')*60)+(int)$localStart->format('i');$endMinute=$startMinute+$duration;
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    if($startUtcObject<=$now)throw new RuntimeException('Choose a future appointment time.');
    $notice=max(0,(int)$event['minimum_notice_minutes']);if($startUtcObject<$now->modify('+'.$notice.' minutes'))throw new RuntimeException('That time is inside the minimum booking notice.');
    $windowDays=max(1,(int)$event['booking_window_days']);if($startUtcObject>$now->modify('+'.$windowDays.' days'))throw new RuntimeException('That time is outside the booking window.');
    $allowed=false;$interval=max(5,(int)$event['slot_interval_minutes']);
    foreach(agent_scheduling_windows_for_date_v430($pdo,$event,$localDate) as [$windowStart,$windowEnd])if($startMinute>=$windowStart&&$endMinute<=$windowEnd&&(($startMinute-$windowStart)%$interval)===0){$allowed=true;break;}
    if(!$allowed)throw new RuntimeException('That time is outside the available booking hours.');

    $max=max(0,(int)$event['max_bookings_per_day']);
    if($max>0){
        $dayStart=$localStart->setTime(0,0)->setTimezone(new DateTimeZone('UTC'));$dayEnd=$dayStart->setTimezone($tz)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'));
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_scheduling_bookings WHERE event_type_id=? AND id<>? AND status IN ('pending','confirmed') AND start_at_utc>=? AND start_at_utc<?");
        $stmt->execute([(int)$event['id'],(int)$booking['id'],$dayStart->format('Y-m-d H:i:s'),$dayEnd->format('Y-m-d H:i:s')]);
        if((int)$stmt->fetchColumn()>=$max)throw new RuntimeException('The daily booking limit has been reached.');
    }

    $endUtc=$startUtcObject->modify('+'.$duration.' minutes');$bufferBefore=max(0,(int)$event['buffer_before_minutes']);$bufferAfter=max(0,(int)$event['buffer_after_minutes']);
    $candidateStart=$startUtcObject->modify('-'.$bufferBefore.' minutes')->format('Y-m-d H:i:s');$candidateEnd=$endUtc->modify('+'.$bufferAfter.' minutes')->format('Y-m-d H:i:s');
    $native=$pdo->prepare("SELECT id FROM agent_scheduling_bookings WHERE schedule_id=? AND id<>? AND status IN ('pending','confirmed') AND DATE_SUB(start_at_utc,INTERVAL buffer_before_minutes MINUTE)<? AND DATE_ADD(end_at_utc,INTERVAL buffer_after_minutes MINUTE)>? LIMIT 1");
    $native->execute([(int)$event['schedule_id'],(int)$booking['id'],$candidateEnd,$candidateStart]);if($native->fetchColumn())throw new RuntimeException('That time is already busy. Please choose another time.');

    if(function_exists('agent_calendar_sync_conflict_v500')){
        $external=agent_calendar_sync_conflict_v500($pdo,(int)$event['schedule_id'],$candidateStart,$candidateEnd);
        if($external){
            $self=false;
            if(table_exists('agent_calendar_booking_links')){
                $link=$pdo->prepare('SELECT 1 FROM agent_calendar_booking_links WHERE booking_id=? AND connection_id=? LIMIT 1');$link->execute([(int)$booking['id'],(int)($external['connection_id']??0)]);
                $sameOld=(string)($external['start_at_utc']??'')===(string)$booking['start_at_utc']&&(string)($external['end_at_utc']??'')===(string)$booking['end_at_utc'];
                $self=$sameOld&&(bool)$link->fetchColumn();
            }
            if(!$self)throw new RuntimeException('That time is already busy on a connected calendar. Please choose another time.');
        }
    }
}

