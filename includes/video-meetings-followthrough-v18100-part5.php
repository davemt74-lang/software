<?php
declare(strict_types=1);

function video_meeting_followthrough_crm_v18100(PDO $pdo,array $meeting,array $user,array $item): array
{
    $leadId=(int)$item['lead_id'];$itemId=(string)$item['id'];$details=['source'=>'video_meeting_intelligence','source_label'=>'Meeting Intelligence','video_meeting_id'=>(int)$meeting['id'],'followthrough_item_id'=>$itemId,'source_url'=>url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'),'review_state'=>'approved'];
    $needle='%"followthrough_item_id":"'.str_replace(['%','_'],['\\%','\\_'],$itemId).'"%';
    if((string)$item['type']==='crm_note'){
        $find=$pdo->prepare("SELECT id FROM crm_activities WHERE lead_id=? AND activity_type='meeting_intelligence' AND details_json LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 1");$find->execute([$leadId,$needle]);$id=(int)$find->fetchColumn();
        if($id<1)$id=crm_v180_activity($pdo,$leadId,'meeting_intelligence',(string)$item['summary'],(int)$user['id'],$details);
        return ['id'=>$id,'kind'=>'crm_activity'];
    }
    $find=$pdo->prepare("SELECT details_json FROM crm_activities WHERE lead_id=? AND activity_type='meeting_intelligence_task_source' AND details_json LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 1");$find->execute([$leadId,$needle]);$prior=json_decode((string)$find->fetchColumn(),true);$taskId=is_array($prior)?max(0,(int)($prior['task_id']??0)):0;
    if($taskId>0){$check=$pdo->prepare('SELECT id FROM crm_tasks WHERE id=? AND lead_id=? LIMIT 1');$check->execute([$taskId,$leadId]);if(!(int)$check->fetchColumn())$taskId=0;}
    if($taskId<1){
        $assignment=video_meeting_followthrough_assignment_v18100($pdo,$user,(string)($item['owner']??''));$assigned=$assignment['user_id'];
        if($assigned>0){$valid=false;foreach(crm_v180_admin_users($pdo) as $candidate)if((int)$candidate['id']===$assigned){$valid=true;break;}if(!$valid)$assigned=0;}
        $taskId=crm_v180_create_task($pdo,$leadId,['title'=>'[Meeting] '.(string)$item['title'],'task_type'=>'follow_up','assigned_user_id'=>$assigned,'due_at'=>(string)($item['due_date']??'')],(int)$user['id']);
        crm_v180_activity($pdo,$leadId,'meeting_intelligence_task_source','Source: Meeting Intelligence · '.(string)$item['summary'],(int)$user['id'],$details+['task_id'=>$taskId]);
    }
    if(trim((string)($item['due_date']??''))!==''){
        $due=crm_v180_parse_datetime((string)$item['due_date'],'follow-up date');
        $pdo->prepare('UPDATE crm_leads SET next_follow_up_at=?,updated_at=NOW() WHERE id=?')->execute([$due,$leadId]);
    }
    return ['id'=>$taskId,'kind'=>'crm_task'];
}

function video_meeting_followthrough_followup_v18100(PDO $pdo,array $meeting,array $user,array $item): array
{
    $booking=video_meeting_followthrough_booking_v18100($pdo,$meeting);if(!$booking)throw new RuntimeException('Canonical appointment not found.');
    $owner=(int)$user['id'];$sourceKey=agent_meeting_workflow_source_key_v1410($booking,'meeting_followup');$existing=agent_meeting_workflow_existing_v1410($pdo,$owner,$sourceKey);
    if($existing){
        $run=agent_workflow_public_run_v1400($pdo,$existing,true);$followupId=agent_meeting_workflow_followup_id_v1410($pdo,$owner,(int)$run['id']);
        if($followupId>0){$find=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? AND booking_id=? AND owner_user_id=? LIMIT 1');$find->execute([$followupId,(int)$booking['id'],$owner]);$followup=$find->fetch();if(is_array($followup))agent_meeting_workflow_publish_followup_v1410($pdo,$user,$booking,$run,$followup);}
        return ['id'=>(int)$run['id'],'kind'=>'agent_workflow','url'=>url('/agent-workflows.php?id='.(int)$run['id'])];
    }
    $body=trim((string)($item['followup_body']??''));$subject=trim((string)($item['followup_subject']??''));
    if($body===''){
        $result=agent_meeting_workflow_ensure_followup_v1410($pdo,$booking);$run=$result['run'];return ['id'=>(int)$run['id'],'kind'=>'agent_workflow','url'=>url('/agent-workflows.php?id='.(int)$run['id'])];
    }
    if($subject==='')throw new RuntimeException('Review and add a follow-up subject before creating the delivery workflow.');
    $find=$pdo->prepare("SELECT * FROM agent_scheduling_followups WHERE booking_id=? AND owner_user_id=? AND notes=? AND message_status='draft' ORDER BY id DESC LIMIT 1");$find->execute([(int)$booking['id'],$owner,VP3_AGENT_MEETING_FOLLOWUP_NOTE_V1410]);$followup=$find->fetch();
    if(is_array($followup)){
        $pdo->prepare('UPDATE agent_scheduling_followups SET draft_subject=?,draft_body=?,updated_at=NOW() WHERE id=? AND booking_id=? AND owner_user_id=? AND message_status=\'draft\'')->execute([mb_strimwidth($subject,0,190,''),$body,(int)$followup['id'],(int)$booking['id'],$owner]);
        $find=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? LIMIT 1');$find->execute([(int)$followup['id']]);$followup=$find->fetch();
    }else{
        $followup=agent_appointment_lifecycle_save_followup_v700($pdo,$booking,$user,['notes'=>VP3_AGENT_MEETING_FOLLOWUP_NOTE_V1410,'task_title'=>'Follow up with '.video_meeting_followthrough_text_v18100($booking['guest_name']??'attendee',120),'task_due_at'=>'','draft_subject'=>$subject,'draft_body'=>$body],null);
    }
    $run=agent_meeting_workflow_create_run_v1410($pdo,$user,$booking,'meeting_followup',$sourceKey,'Post-meeting follow-up: '.((string)($booking['event_title']??'Appointment')),'Send the reviewed meeting follow-up after a separate explicit delivery approval.','The organizer reviewed the meeting-generated draft and promoted it into the canonical follow-up workflow.',[
        ['key'=>'send-followup:'.(int)$followup['id'],'label'=>'Send approved follow-up','summary'=>'Deliver the reviewed follow-up through the canonical appointment email path.','requires_approval'=>true,'capability_key'=>'scheduling.followup.send'],
    ],true,null);
    agent_meeting_workflow_publish_followup_v1410($pdo,$user,$booking,$run,$followup);
    return ['id'=>(int)$run['id'],'kind'=>'agent_workflow','url'=>url('/agent-workflows.php?id='.(int)$run['id'])];
}
