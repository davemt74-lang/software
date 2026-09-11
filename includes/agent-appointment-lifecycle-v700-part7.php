<?php
declare(strict_types=1);

function agent_appointment_lifecycle_agent_followup_v700(PDO $pdo,array $booking,array $user): array
{
    if((int)$booking['owner_user_id']!==(int)($user['id']??0))throw new RuntimeException('That appointment is not available to this account.');
    $brief=agent_appointment_lifecycle_brief_v700($pdo,(int)$booking['id']);
    if(!$brief){$prepared=agent_appointment_lifecycle_prepare_brief_v700($pdo,$booking);$agentId=$prepared['agent_id']??null;}
    else $agentId=max(0,(int)($brief['agent_id']??0))?:null;
    $guest=trim((string)$booking['guest_name'])?:'there';$host=trim((string)($user['display_name']??''))?:'VP3';
    $subject='Follow-up: '.trim((string)$booking['event_title']);
    $body="Hi {$guest},\n\nThanks for meeting with me about ".trim((string)$booking['event_title']).".\n\nHere are the next steps from our conversation:\n\n- \n\nIf I missed anything, reply and I’ll update the plan.\n\nBest,\n{$host}";
    $tz=new DateTimeZone(agent_scheduling_timezone_v430((string)($booking['organizer_timezone']??'UTC')));$due=(new DateTimeImmutable('tomorrow',$tz))->setTime(17,0)->format('Y-m-d\TH:i');
    return agent_appointment_lifecycle_save_followup_v700($pdo,$booking,$user,[
        'notes'=>'Agent-generated post-meeting follow-up draft. Review before sending.',
        'task_title'=>'Follow up with '.$guest,
        'task_due_at'=>$due,
        'draft_subject'=>$subject,
        'draft_body'=>$body,
    ],$agentId);
}

function agent_appointment_lifecycle_email_v700(string $to,string $subject,string $body): bool
{
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||$subject===''||$body==='')return false;
    global $config;$from=trim((string)($config['site']['email']??''));if(!filter_var($from,FILTER_VALIDATE_EMAIL))$from='no-reply@'.preg_replace('/[^a-z0-9.-]/i','',(string)($_SERVER['HTTP_HOST']??'localhost'));
    $from=str_replace(["\r","\n"],'',$from);$subject=str_replace(["\r","\n"],' ',mb_strimwidth($subject,0,190,''));
    $headers=['MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','From: VP3 <'.$from.'>'];
    return function_exists('mail')&&@mail($to,$subject,$body,implode("\r\n",$headers));
}

function agent_appointment_lifecycle_send_followup_v700(PDO $pdo,array $booking,array $user,int $followupId,?int $agentId=null): array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_scheduling_followups WHERE id=? AND booking_id=? AND owner_user_id=? AND message_status='draft' LIMIT 1");$stmt->execute([$followupId,(int)$booking['id'],(int)$user['id']]);$followup=$stmt->fetch();
    if(!$followup)throw new RuntimeException('Follow-up draft not found.');
    $to=strtolower(trim((string)$booking['guest_email']));if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('This appointment does not have a valid attendee email.');
    $subject=trim((string)$followup['draft_subject']);$body=trim((string)$followup['draft_body']);if($subject===''||$body==='')throw new RuntimeException('The follow-up needs a subject and message before it can be sent.');
    if(!agent_appointment_lifecycle_email_v700($to,$subject,$body))throw new RuntimeException('The follow-up email could not be delivered by this server.');
    $pdo->prepare("UPDATE agent_scheduling_followups SET message_status='sent',sent_at=NOW(),updated_at=NOW() WHERE id=? AND message_status='draft'")->execute([$followupId]);
    agent_appointment_lifecycle_event_v700($pdo,$booking,'followup_sent',agent_appointment_lifecycle_status_v700($booking),agent_appointment_lifecycle_status_v700($booking),$agentId?'agent':'member',(int)$user['id'],$agentId,['followup_id'=>$followupId,'recipient'=>$to]);
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? LIMIT 1');$stmt->execute([$followupId]);return $stmt->fetch()?:$followup;
}

function agent_appointment_lifecycle_manage_url_v700(PDO $pdo,array $booking): string
{
    $team=agent_appointment_lifecycle_team_context_v700($pdo,(int)$booking['id']);
    if($team){
        $stmt=$pdo->prepare('SELECT cancel_token FROM agent_team_scheduling_bookings WHERE id=? LIMIT 1');$stmt->execute([(int)$team['team_booking_id']]);$token=trim((string)$stmt->fetchColumn());
        $path=$token!==''?url('/team-book.php?manage='.rawurlencode($token)):'';
    }else{
        $profile=function_exists('profile_for_user')?profile_for_user($pdo,(int)$booking['owner_user_id'],false):null;
        $username=trim((string)($profile['username']??''));$token=trim((string)($booking['cancel_token']??''));
        $path=$username!==''&&$token!==''?agent_scheduling_public_manage_url_v450($username,$token):'';
    }
    if($path===''||preg_match('#^https?://#i',$path))return $path;
    global $config;$base=rtrim(trim((string)($config['site']['base_url']??'')),'/');
    return $base!==''?$base.'/'.ltrim($path,'/'):$path;
}

function agent_appointment_lifecycle_delivery_copy_v700(array $booking,string $key): array
{
    $when=(string)$booking['start_at_utc'].' UTC';$guest=(string)$booking['guest_name'];$title=(string)$booking['event_title'];
    return match($key){
        'confirmation'=>['Appointment confirmed',$title.' with '.$guest.' is confirmed for '.$when.'.'],
        'reminder_24h'=>['Appointment tomorrow','Reminder: '.$title.' with '.$guest.' is scheduled for '.$when.'.'],
        'reminder_soon'=>['Appointment coming up','Upcoming soon: '.$title.' with '.$guest.' at '.$when.'.'],
        'rescheduled'=>['Appointment rescheduled',$title.' with '.$guest.' is now scheduled for '.$when.'.'],
        'cancelled'=>['Appointment cancelled',$title.' with '.$guest.' was cancelled.'],
        'agent_prep'=>['Agent meeting brief ready','Preparation is ready for '.$title.' with '.$guest.' at '.$when.'.'],
        default=>['Appointment update',$title.' with '.$guest.' has an update.'],
    };
}

function agent_appointment_lifecycle_process_delivery_v700(PDO $pdo,array $delivery): void
{
    $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$delivery['booking_id']);
    if(!$booking){$pdo->prepare("UPDATE agent_scheduling_automation_deliveries SET status='skipped',last_error='Booking unavailable',updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);return;}
    $key=(string)$delivery['automation_key'];
    if(in_array($key,['confirmation','reminder_24h','reminder_soon','agent_prep'],true)&&!in_array((string)$booking['status'],['pending','confirmed'],true)){
        $pdo->prepare("UPDATE agent_scheduling_automation_deliveries SET status='skipped',last_error='Booking is no longer active',updated_at=NOW() WHERE id=?")->execute([(int)$delivery['id']]);return;
    }
    [$title,$body]=agent_appointment_lifecycle_delivery_copy_v700($booking,$key);
    if($key==='agent_prep'){
        $brief=agent_appointment_lifecycle_prepare_brief_v700($pdo,$booking);$body=mb_strimwidth((string)$brief['brief_text'],0,900,'…');
    }
    $ok=false;$error='';
    if((string)$delivery['channel']==='host_notification'&&(int)($delivery['recipient_user_id']??0)>0){
        try{
            create_notification((int)$delivery['recipient_user_id'],'appointment_'.$key,$title,$body,url('/appointment-lifecycle.php?booking='.(int)$booking['id']),'agent_scheduling_booking',(int)$booking['id']);$ok=true;
        }catch(Throwable $e){$error=$e->getMessage();}
    }elseif((string)$delivery['channel']==='guest_email'){
        $manage=agent_appointment_lifecycle_manage_url_v700($pdo,$booking);
        if($manage!=='')$body.="\n\nManage or reschedule this appointment:\n".$manage;
        $ok=agent_appointment_lifecycle_email_v700((string)$delivery['recipient_email'],$title,$body);
        if(!$ok)$error='Mail transport did not accept the message.';
    }
    $status=$ok?'sent':(((int)$delivery['attempts']+1)>=3?'failed':'pending');
    $pdo->prepare('UPDATE agent_scheduling_automation_deliveries SET status=?,attempts=attempts+1,last_error=?,sent_at=CASE WHEN ?=1 THEN NOW() ELSE sent_at END,updated_at=NOW() WHERE id=?')
        ->execute([$status,mb_strimwidth($error,0,1000,'…'),$ok?1:0,(int)$delivery['id']]);
    agent_appointment_lifecycle_event_v700($pdo,$booking,'automation_'.$key,agent_appointment_lifecycle_status_v700($booking),agent_appointment_lifecycle_status_v700($booking),'automation',null,null,['delivery_id'=>(int)$delivery['id'],'channel'=>(string)$delivery['channel'],'status'=>$status]);
}

function agent_appointment_lifecycle_reconcile_statuses_v700(PDO $pdo,int $limit=100): int
{
    $stmt=$pdo->query("SELECT * FROM agent_scheduling_bookings WHERE status IN ('cancelled','completed','no_show') AND COALESCE(NULLIF(lifecycle_status,''),'confirmed')<>status ORDER BY updated_at DESC,id DESC LIMIT ".max(1,min(500,$limit)));
    $count=0;
    foreach($stmt->fetchAll()?:[] as $booking){
        $from=agent_appointment_lifecycle_status_v700($booking);$to=(string)$booking['status'];
        $update=$pdo->prepare("UPDATE agent_scheduling_bookings SET lifecycle_status=?,last_lifecycle_event_at=NOW() WHERE id=? AND owner_user_id=? AND status=? AND COALESCE(NULLIF(lifecycle_status,''),'confirmed')<>?");
        $update->execute([$to,(int)$booking['id'],(int)$booking['owner_user_id'],$to,$to]);
        if($update->rowCount()!==1)continue;
        $booking['lifecycle_status']=$to;
        agent_appointment_lifecycle_event_v700($pdo,$booking,'status_reconciled',$from,$to,'system',null,null,['source'=>'legacy_or_direct_mutation']);
        if($to==='cancelled')agent_appointment_lifecycle_queue_notice_v700($pdo,$booking,'cancelled');
        $count++;
    }
    return $count;
}

