<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_permission('account.access');

$pdo=db();$user=current_user();
if(!$pdo||!$user)redirect(url('/login.php'));
if(!agent_appointment_lifecycle_schema_ready_v700($pdo))redirect(url('/upgrade.php'));

function appointment_lifecycle_redirect_v700(int $bookingId,string $saved,string $anchor=''): never
{
    $target=url('/appointment-lifecycle.php'.($bookingId>0?'?booking='.$bookingId:''));
    $target.=($bookingId>0?'&':'?').'saved='.rawurlencode($saved);
    if($anchor!=='')$target.='#'.rawurlencode($anchor);
    redirect($target);
}
function appointment_lifecycle_label_v700(string $utc,string $timezone='UTC'): string
{
    try{return(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(agent_scheduling_timezone_v430($timezone)))->format('D, M j · g:i A T');}catch(Throwable $e){return $utc.' UTC';}
}

$userId=(int)$user['id'];$pageError='';$selectedId=max(0,(int)($_GET['booking']??$_POST['booking_id']??0));

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$pageError='Session expired. Please try again.';
    else{
        try{
            $action=trim((string)($_POST['action']??''));
            if($action==='save_automation_settings'){
                $eventId=(int)($_POST['event_type_id']??0);$event=agent_scheduling_event_type_v430($pdo,$eventId);
                if(!$event||(int)$event['owner_user_id']!==$userId)throw new RuntimeException('Appointment type not found.');
                $soon=max(5,min(240,(int)($_POST['reminder_soon_minutes']??30)));$prep=max(15,min(1440,(int)($_POST['agent_prep_minutes']??60)));
                $pdo->prepare('UPDATE agent_scheduling_event_types SET confirmation_enabled=?,reminder_24h_enabled=?,reminder_soon_minutes=?,agent_prep_minutes=? WHERE id=? AND schedule_id=?')
                    ->execute([!empty($_POST['confirmation_enabled'])?1:0,!empty($_POST['reminder_24h_enabled'])?1:0,$soon,$prep,$eventId,(int)$event['schedule_id']]);
                appointment_lifecycle_redirect_v700($selectedId,'Automation settings saved.','automation');
            }
            if($action==='save_question'){
                agent_appointment_lifecycle_save_question_v700($pdo,$user,[
                    'id'=>(int)($_POST['question_id']??0),'event_type_id'=>(int)($_POST['event_type_id']??0),
                    'label'=>(string)($_POST['label']??''),'question_type'=>(string)($_POST['question_type']??'short_text'),
                    'options_text'=>(string)($_POST['options_text']??''),'is_required'=>!empty($_POST['is_required']),
                    'sort_order'=>(int)($_POST['sort_order']??0),'is_active'=>true,
                ]);
                appointment_lifecycle_redirect_v700($selectedId,'Intake question saved.','intake');
            }
            if($action==='toggle_question'){
                $questionId=(int)($_POST['question_id']??0);
                $stmt=$pdo->prepare('SELECT q.*,e.schedule_id,s.owner_user_id FROM agent_scheduling_intake_questions q JOIN agent_scheduling_event_types e ON e.id=q.event_type_id JOIN agent_scheduling_schedules s ON s.id=e.schedule_id WHERE q.id=? AND s.owner_user_id=? LIMIT 1');
                $stmt->execute([$questionId,$userId]);$q=$stmt->fetch();if(!$q)throw new RuntimeException('Intake question not found.');
                $pdo->prepare('UPDATE agent_scheduling_intake_questions SET is_active=? WHERE id=?')->execute([empty($q['is_active'])?1:0,$questionId]);
                appointment_lifecycle_redirect_v700($selectedId,'Intake question updated.','intake');
            }

            $booking=$selectedId>0?agent_appointment_lifecycle_booking_v700($pdo,$selectedId,$userId):null;
            if(in_array($action,['transition','prepare_brief','agent_followup','save_followup','send_followup'],true)&&!$booking)throw new RuntimeException('Appointment not found.');
            if($action==='transition'){
                $to=(string)($_POST['to_status']??'');
                $booking=agent_appointment_lifecycle_transition_v700($pdo,$booking,$to,'member',$userId,null,['surface'=>'appointment-lifecycle.php']);
                appointment_lifecycle_redirect_v700((int)$booking['id'],'Appointment marked '.str_replace('_',' ',$to).'.','appointment');
            }
            if($action==='prepare_brief'){
                agent_appointment_lifecycle_prepare_brief_v700($pdo,$booking);
                appointment_lifecycle_redirect_v700((int)$booking['id'],'Agent preparation brief refreshed.','brief');
            }
            if($action==='agent_followup'){
                $follow=agent_appointment_lifecycle_agent_followup_v700($pdo,$booking,$user);
                appointment_lifecycle_redirect_v700((int)$booking['id'],'Agent follow-up draft and task created. Review before sending.','followup-'.(int)$follow['id']);
            }
            if($action==='save_followup'){
                $follow=agent_appointment_lifecycle_save_followup_v700($pdo,$booking,$user,[
                    'notes'=>(string)($_POST['notes']??''),'task_title'=>(string)($_POST['task_title']??''),
                    'task_due_at'=>(string)($_POST['task_due_at']??''),'draft_subject'=>(string)($_POST['draft_subject']??''),
                    'draft_body'=>(string)($_POST['draft_body']??''),
                ]);
                appointment_lifecycle_redirect_v700((int)$booking['id'],'Post-meeting workflow saved.','followup-'.(int)$follow['id']);
            }
            if($action==='send_followup'){
                if(empty($_POST['confirm_send']))throw new RuntimeException('Confirm the external follow-up before sending.');
                $follow=agent_appointment_lifecycle_send_followup_v700($pdo,$booking,$user,(int)($_POST['followup_id']??0));
                appointment_lifecycle_redirect_v700((int)$booking['id'],'Follow-up sent and audited.','followup-'.(int)$follow['id']);
            }
            if($action==='run_automation'){
                $result=agent_appointment_lifecycle_housekeeping_v700($pdo,300);
                appointment_lifecycle_redirect_v700($selectedId,'Automation processed '.(int)$result['processed'].' due deliver'.((int)$result['processed']===1?'y':'ies').'.','automation');
            }
            throw new RuntimeException('Unknown lifecycle action.');
        }catch(Throwable $e){$pageError=$e->getMessage();}
    }
}

$list=$pdo->prepare("SELECT b.*,e.slug AS event_slug FROM agent_scheduling_bookings b LEFT JOIN agent_scheduling_event_types e ON e.id=b.event_type_id WHERE b.owner_user_id=? ORDER BY CASE WHEN b.status IN ('pending','confirmed') AND b.end_at_utc>=UTC_TIMESTAMP() THEN 0 ELSE 1 END,b.start_at_utc ".($selectedId>0?'ASC':'DESC').",b.id DESC LIMIT 120");
$list->execute([$userId]);$bookings=$list->fetchAll()?:[];
if($selectedId<1&&$bookings){
    foreach($bookings as $row)if(in_array((string)$row['status'],['pending','confirmed'],true)&&strtotime((string)$row['end_at_utc'])>=time()){$selectedId=(int)$row['id'];break;}
    if($selectedId<1)$selectedId=(int)$bookings[0]['id'];
}
$selected=$selectedId>0?agent_appointment_lifecycle_booking_v700($pdo,$selectedId,$userId):null;
$eventsStmt=$pdo->prepare('SELECT e.*,s.name AS schedule_name,s.timezone AS schedule_timezone FROM agent_scheduling_event_types e JOIN agent_scheduling_schedules s ON s.id=e.schedule_id WHERE s.owner_user_id=? ORDER BY s.is_default DESC,s.name,e.sort_order,e.title,e.id');
$eventsStmt->execute([$userId]);$eventTypes=$eventsStmt->fetchAll()?:[];
$configEventId=max(0,(int)($_GET['event']??($selected['event_type_id']??($eventTypes[0]['id']??0))));
$configEvent=null;foreach($eventTypes as $evt)if((int)$evt['id']===$configEventId){$configEvent=$evt;break;}
$questions=$configEvent?agent_appointment_lifecycle_questions_v700($pdo,(int)$configEvent['id'],false):[];
$brief=$selected?agent_appointment_lifecycle_brief_v700($pdo,(int)$selected['id']):null;
$intake=$selected?agent_appointment_lifecycle_intake_answers_v700($pdo,(int)$selected['id']):[];
$lifecycle=[];$deliveries=[];$followups=[];
if($selected){
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_lifecycle_events WHERE booking_id=? ORDER BY created_at DESC,id DESC LIMIT 40');$stmt->execute([(int)$selected['id']]);$lifecycle=$stmt->fetchAll()?:[];
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_automation_deliveries WHERE booking_id=? ORDER BY due_at,automation_key,recipient_key');$stmt->execute([(int)$selected['id']]);$deliveries=$stmt->fetchAll()?:[];
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE booking_id=? ORDER BY created_at DESC,id DESC');$stmt->execute([(int)$selected['id']]);$followups=$stmt->fetchAll()?:[];
}
$upcomingCount=0;$completedCount=0;$noShowCount=0;
foreach($bookings as $row){$status=agent_appointment_lifecycle_status_v700($row);if(in_array((string)$row['status'],['pending','confirmed'],true)&&strtotime((string)$row['end_at_utc'])>=time())$upcomingCount++;if($status==='completed')$completedCount++;if($status==='no_show')$noShowCount++;}
$saved=trim((string)($_GET['saved']??''));
$defaultSubject=$selected?'Thanks for '.(string)$selected['event_title']:'Thanks for meeting';
$defaultBody=$selected?"Hi ".(string)$selected['guest_name'].",\n\nThanks for meeting with me. Here are the next steps we discussed:\n\n\nBest,\n".trim((string)($user['display_name']??'')):'';
