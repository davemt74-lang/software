<?php
declare(strict_types=1);

function agent_appointment_lifecycle_capture_intake_v700(PDO $pdo,array $booking,array $answers): void
{
    $questions=agent_appointment_lifecycle_questions_v700($pdo,(int)($booking['event_type_id']??0),true);
    if(!$questions)return;
    $upsert=$pdo->prepare('INSERT INTO agent_scheduling_intake_answers (booking_id,question_id,question_key,question_label,answer_text) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE question_key=VALUES(question_key),question_label=VALUES(question_label),answer_text=VALUES(answer_text),updated_at=NOW()');
    foreach($questions as $q){
        $id=(int)$q['id'];$value=$answers[$id]??'';
        if(is_array($value))$value=implode(', ',array_map('strval',$value));
        $value=trim((string)$value);$type=(string)$q['question_type'];
        if($type==='checkbox')$value=in_array(strtolower($value),['1','yes','true','on'],true)?'Yes':'No';
        if(!empty($q['is_required'])&&($value===''||($type==='checkbox'&&$value!=='Yes')))throw new RuntimeException('Please answer: '.(string)$q['label']);
        if($type==='select'&&$value!==''){
            $options=json_decode((string)($q['options_json']??'[]'),true);if(!is_array($options)||!in_array($value,$options,true))throw new RuntimeException('Choose a valid answer for: '.(string)$q['label']);
        }
        $upsert->execute([(int)$booking['id'],$id,(string)$q['question_key'],(string)$q['label'],$value!==''?mb_strimwidth($value,0,8000,'…'):null]);
    }
    agent_appointment_lifecycle_event_v700($pdo,$booking,'intake_saved',agent_appointment_lifecycle_status_v700($booking),agent_appointment_lifecycle_status_v700($booking),'guest',null,null,['answer_count'=>count($questions)]);
}

function agent_appointment_lifecycle_intake_answers_v700(PDO $pdo,int $bookingId): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_intake_answers WHERE booking_id=? ORDER BY id');$stmt->execute([$bookingId]);return $stmt->fetchAll()?:[];
}

function agent_appointment_lifecycle_team_context_v700(PDO $pdo,int $bookingId): ?array
{
    if($bookingId<1||!function_exists('agent_team_scheduling_schema_ready_v600')||!agent_team_scheduling_schema_ready_v600($pdo))return null;
    $stmt=$pdo->prepare('SELECT tb.id AS team_booking_id,tb.pool_id,tb.workspace_owner_user_id,tb.assigned_user_id,p.mode,p.agent_id AS pool_agent_id,bm.user_id,bm.role FROM agent_team_scheduling_booking_members bm JOIN agent_team_scheduling_bookings tb ON tb.id=bm.team_booking_id JOIN agent_team_scheduling_pools p ON p.id=tb.pool_id WHERE bm.canonical_booking_id=? LIMIT 1');
    $stmt->execute([$bookingId]);$row=$stmt->fetch();if(!$row)return null;
    $members=$pdo->prepare('SELECT bm.user_id,bm.role,bm.canonical_booking_id,u.display_name,u.email FROM agent_team_scheduling_booking_members bm JOIN users u ON u.id=bm.user_id WHERE bm.team_booking_id=? ORDER BY bm.role DESC,bm.id');
    $members->execute([(int)$row['team_booking_id']]);$rows=$members->fetchAll()?:[];
    $row['members']=$rows;$row['primary_booking_id']=$rows?min(array_map(static fn(array $m):int=>(int)$m['canonical_booking_id'],$rows)):$bookingId;
    return $row;
}

function agent_appointment_lifecycle_host_recipients_v700(PDO $pdo,array $booking): array
{
    $team=agent_appointment_lifecycle_team_context_v700($pdo,(int)$booking['id']);
    if($team){
        foreach((array)$team['members'] as $member)if((int)$member['canonical_booking_id']===(int)$booking['id'])return [['user_id'=>(int)$member['user_id'],'name'=>(string)$member['display_name'],'email'=>(string)$member['email']]];
    }
    $stmt=$pdo->prepare('SELECT id,display_name,email FROM users WHERE id=? LIMIT 1');$stmt->execute([(int)$booking['owner_user_id']]);$owner=$stmt->fetch();
    return $owner?[['user_id'=>(int)$owner['id'],'name'=>(string)$owner['display_name'],'email'=>(string)$owner['email']]]:[];
}

function agent_appointment_lifecycle_guest_delivery_allowed_v700(PDO $pdo,array $booking): bool
{
    $team=agent_appointment_lifecycle_team_context_v700($pdo,(int)$booking['id']);
    return !$team||(int)$team['primary_booking_id']===(int)$booking['id'];
}

function agent_appointment_lifecycle_occurrence_key_v700(array $booking,string $key): string
{
    $anchor=match($key){
        'confirmation'=>(string)($booking['created_at']??''),
        'reminder_24h','reminder_soon','agent_prep'=>(string)($booking['start_at_utc']??''),
        'rescheduled'=>(string)($booking['start_at_utc']??'').'|'.(string)($booking['rescheduled_at']??''),
        'cancelled'=>(string)($booking['cancelled_at']??$booking['updated_at']??''),
        default=>(string)($booking['updated_at']??$booking['start_at_utc']??''),
    };
    return hash('sha256',(int)($booking['id']??0).'|'.$key.'|'.$anchor);
}

function agent_appointment_lifecycle_queue_delivery_v700(PDO $pdo,array $booking,string $key,string $recipientKey,?int $recipientUserId,string $recipientEmail,string $channel,string $dueAt): void
{
    $occurrence=agent_appointment_lifecycle_occurrence_key_v700($booking,$key);
    $pdo->prepare("INSERT IGNORE INTO agent_scheduling_automation_deliveries (booking_id,automation_key,recipient_key,occurrence_key,recipient_user_id,recipient_email,channel,due_at,status) VALUES (?,?,?,?,?,?,?,?,'pending')")
        ->execute([(int)$booking['id'],mb_strimwidth($key,0,50,''),mb_strimwidth($recipientKey,0,255,''),$occurrence,$recipientUserId?:null,mb_strimwidth(strtolower(trim($recipientEmail)),0,190,''),$channel,$dueAt]);
}

function agent_appointment_lifecycle_queue_booking_v700(PDO $pdo,array $booking,bool $includeConfirmation=true): void
{
    if(!in_array((string)($booking['status']??''),['pending','confirmed'],true))return;
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$start=new DateTimeImmutable((string)$booking['start_at_utc'],new DateTimeZone('UTC'));
    $hosts=agent_appointment_lifecycle_host_recipients_v700($pdo,$booking);
    if($includeConfirmation&&!empty($booking['confirmation_enabled'])){
        foreach($hosts as $host)agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'confirmation','user:'.(int)$host['user_id'],(int)$host['user_id'],'','host_notification',$now->format('Y-m-d H:i:s'));
        if(agent_appointment_lifecycle_guest_delivery_allowed_v700($pdo,$booking)&&filter_var((string)$booking['guest_email'],FILTER_VALIDATE_EMAIL))agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'confirmation','email:'.strtolower((string)$booking['guest_email']),null,(string)$booking['guest_email'],'guest_email',$now->format('Y-m-d H:i:s'));
    }
    if(!empty($booking['reminder_24h_enabled'])){
        $due=$start->modify('-24 hours');if($due>$now->modify('-2 hours')) {
            foreach($hosts as $host)agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'reminder_24h','user:'.(int)$host['user_id'],(int)$host['user_id'],'','host_notification',$due->format('Y-m-d H:i:s'));
            if(agent_appointment_lifecycle_guest_delivery_allowed_v700($pdo,$booking)&&filter_var((string)$booking['guest_email'],FILTER_VALIDATE_EMAIL))agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'reminder_24h','email:'.strtolower((string)$booking['guest_email']),null,(string)$booking['guest_email'],'guest_email',$due->format('Y-m-d H:i:s'));
        }
    }
    $soon=max(5,min(240,(int)($booking['reminder_soon_minutes']??30)));$due=$start->modify('-'.$soon.' minutes');
    if($due>$now->modify('-60 minutes')){
        foreach($hosts as $host)agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'reminder_soon','user:'.(int)$host['user_id'],(int)$host['user_id'],'','host_notification',$due->format('Y-m-d H:i:s'));
        if(agent_appointment_lifecycle_guest_delivery_allowed_v700($pdo,$booking)&&filter_var((string)$booking['guest_email'],FILTER_VALIDATE_EMAIL))agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'reminder_soon','email:'.strtolower((string)$booking['guest_email']),null,(string)$booking['guest_email'],'guest_email',$due->format('Y-m-d H:i:s'));
    }
    $prep=max(15,min(1440,(int)($booking['agent_prep_minutes']??60)));$due=$start->modify('-'.$prep.' minutes');
    if($due>$now->modify('-2 hours'))foreach($hosts as $host)agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,'agent_prep','user:'.(int)$host['user_id'],(int)$host['user_id'],'','host_notification',$due->format('Y-m-d H:i:s'));
}

function agent_appointment_lifecycle_queue_notice_v700(PDO $pdo,array $booking,string $kind): void
{
    if(!in_array($kind,['rescheduled','cancelled'],true))return;
    $now=gmdate('Y-m-d H:i:s');
    foreach(agent_appointment_lifecycle_host_recipients_v700($pdo,$booking) as $host)agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,$kind,'user:'.(int)$host['user_id'],(int)$host['user_id'],'','host_notification',$now);
    if(agent_appointment_lifecycle_guest_delivery_allowed_v700($pdo,$booking)&&filter_var((string)$booking['guest_email'],FILTER_VALIDATE_EMAIL))agent_appointment_lifecycle_queue_delivery_v700($pdo,$booking,$kind,'email:'.strtolower((string)$booking['guest_email']),null,(string)$booking['guest_email'],'guest_email',$now);
}

function agent_appointment_lifecycle_reset_timed_deliveries_v700(PDO $pdo,int $bookingId): void
{
    $pdo->prepare("DELETE FROM agent_scheduling_automation_deliveries WHERE booking_id=? AND status='pending' AND automation_key IN ('reminder_24h','reminder_soon','agent_prep')")->execute([$bookingId]);
}

function agent_appointment_lifecycle_owner_user_v700(PDO $pdo,int $ownerId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');$stmt->execute([$ownerId]);return $stmt->fetch()?:null;
}

