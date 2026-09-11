<?php
declare(strict_types=1);

function agent_appointment_lifecycle_prepare_brief_v700(PDO $pdo,array $booking): array
{
    $owner=agent_appointment_lifecycle_owner_user_v700($pdo,(int)$booking['owner_user_id'])?:[];
    $agentId=max(0,(int)($booking['agent_id']??$booking['created_by_agent_id']??0))?:null;
    $team=agent_appointment_lifecycle_team_context_v700($pdo,(int)$booking['id']);
    if($team&&max(0,(int)($team['pool_agent_id']??0))>0){$poolAgentId=(int)$team['pool_agent_id'];if(function_exists('user_agent_get_v236')&&user_agent_get_v236($pdo,(int)$booking['owner_user_id'],$poolAgentId))$agentId=$poolAgentId;}

    $context=[
        'appointment'=>[
            'booking_id'=>(int)$booking['id'],'title'=>(string)$booking['event_title'],'guest_name'=>(string)$booking['guest_name'],
            'guest_email'=>(string)$booking['guest_email'],'guest_phone'=>(string)$booking['guest_phone'],
            'start_at_utc'=>(string)$booking['start_at_utc'],'end_at_utc'=>(string)$booking['end_at_utc'],
            'location_type'=>(string)$booking['location_type'],'location_value'=>(string)$booking['location_value'],
            'purpose'=>trim((string)($booking['guest_notes']??'')),
        ],
        'intake'=>[],
        'prior_messages'=>[],
        'crm'=>[],
        'knowledge'=>[],
        'team'=>$team?['team_booking_id'=>(int)$team['team_booking_id'],'mode'=>(string)$team['mode'],'members'=>(array)$team['members']]:null,
    ];
    foreach(agent_appointment_lifecycle_intake_answers_v700($pdo,(int)$booking['id']) as $answer)$context['intake'][]=['question'=>(string)$answer['question_label'],'answer'=>(string)($answer['answer_text']??'')];

    $guestEmail=strtolower(trim((string)$booking['guest_email']));
    if($guestEmail!==''&&filter_var($guestEmail,FILTER_VALIDATE_EMAIL)){
        try{
            $guestStmt=$pdo->prepare('SELECT id,display_name FROM users WHERE LOWER(email)=? AND is_active=1 LIMIT 1');$guestStmt->execute([$guestEmail]);$guest=$guestStmt->fetch();
            if($guest&&table_exists('human_conversations')&&table_exists('human_messages')){
                $a=min((int)$booking['owner_user_id'],(int)$guest['id']);$b=max((int)$booking['owner_user_id'],(int)$guest['id']);
                $conv=$pdo->prepare("SELECT id FROM human_conversations WHERE conversation_type='direct' AND direct_user_low_id=? AND direct_user_high_id=? LIMIT 1");$conv->execute([$a,$b]);$cid=(int)$conv->fetchColumn();
                if($cid>0){$msg=$pdo->prepare('SELECT sender_user_id,body,created_at FROM human_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 5');$msg->execute([$cid]);$context['prior_messages']=array_reverse($msg->fetchAll()?:[]);}
            }
        }catch(Throwable $ignored){}

        if(function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo)){
            try{
                $isAdmin=$owner&&function_exists('user_has_role')&&user_has_role('admin',$owner);
                $sql='SELECT c.name,c.company,l.stage,l.priority,l.next_follow_up_at,l.demo_focus,l.internal_notes FROM crm_contacts c JOIN crm_leads l ON l.contact_id=c.id WHERE c.email_normalized=?';
                $args=[$guestEmail];if(!$isAdmin){$sql.=' AND l.assigned_user_id=?';$args[]=(int)$booking['owner_user_id'];}
                $sql.=' ORDER BY l.updated_at DESC LIMIT 3';$stmt=$pdo->prepare($sql);$stmt->execute($args);$context['crm']=$stmt->fetchAll()?:[];
            }catch(Throwable $ignored){}
        }
    }

    $purpose=trim((string)($booking['guest_notes']??''));
    if($purpose===''&&$context['intake'])$purpose=implode(' ',array_map(static fn(array $a):string=>$a['question'].' '.$a['answer'],$context['intake']));
    if($purpose!==''&&$owner&&function_exists('search_knowledge')){
        try{
            foreach(search_knowledge($purpose,$owner,3) as $row)$context['knowledge'][]=['title'=>(string)$row['title'],'excerpt'=>mb_strimwidth(trim((string)($row['chunk_text']??$row['description']??'')),0,500,'…'),'scope'=>(string)($row['knowledge_scope']??'system')];
        }catch(Throwable $ignored){}
    }

    $lines=[
        'Appointment: '.(string)$booking['event_title'].' with '.(string)$booking['guest_name'],
        'When: '.(string)$booking['start_at_utc'].' UTC',
        'Location: '.(trim((string)$booking['location_value'])!==''?(string)$booking['location_value']:(string)$booking['location_type']),
    ];
    if(trim((string)($booking['guest_notes']??''))!=='')$lines[]='Requested purpose: '.trim((string)$booking['guest_notes']);
    if($context['intake']){$lines[]='Intake:';foreach($context['intake'] as $a)$lines[]='- '.$a['question'].': '.$a['answer'];}
    if($context['prior_messages']){$lines[]='Recent messages:';foreach($context['prior_messages'] as $m)$lines[]='- '.((int)$m['sender_user_id']===(int)$booking['owner_user_id']?'Host':'Attendee').': '.mb_strimwidth(trim((string)$m['body']),0,280,'…');}
    if($context['crm']){$lines[]='CRM context:';foreach($context['crm'] as $crm)$lines[]='- Stage '.(string)$crm['stage'].' · priority '.(string)$crm['priority'].($crm['company']?' · '.(string)$crm['company']:'');}
    if($context['knowledge']){$lines[]='Relevant knowledge:';foreach($context['knowledge'] as $k)$lines[]='- '.$k['title'].': '.$k['excerpt'];}
    $brief=implode("\n",$lines);$json=json_encode($context,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO agent_scheduling_agent_briefs (booking_id,agent_id,brief_text,context_json,prepared_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id),brief_text=VALUES(brief_text),context_json=VALUES(context_json),prepared_at=NOW(),updated_at=NOW()')
        ->execute([(int)$booking['id'],$agentId,$brief,$json?:null]);
    agent_appointment_lifecycle_event_v700($pdo,$booking,'agent_brief_prepared',agent_appointment_lifecycle_status_v700($booking),agent_appointment_lifecycle_status_v700($booking),'agent',null,$agentId,['knowledge_count'=>count($context['knowledge']),'message_count'=>count($context['prior_messages']),'crm_count'=>count($context['crm'])]);
    return ['brief_text'=>$brief,'context'=>$context,'agent_id'=>$agentId];
}

function agent_appointment_lifecycle_brief_v700(PDO $pdo,int $bookingId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_agent_briefs WHERE booking_id=? LIMIT 1');$stmt->execute([$bookingId]);return $stmt->fetch()?:null;
}

function agent_appointment_lifecycle_save_followup_v700(PDO $pdo,array $booking,array $user,array $input,?int $agentId=null): array
{
    if((int)$booking['owner_user_id']!==(int)($user['id']??0))throw new RuntimeException('That appointment is not available to this account.');
    $notes=trim((string)($input['notes']??''));$task=trim((string)($input['task_title']??''));$subject=trim((string)($input['draft_subject']??''));$body=trim((string)($input['draft_body']??''));
    $due=null;if(trim((string)($input['task_due_at']??''))!==''){try{$tz=new DateTimeZone(agent_scheduling_timezone_v430((string)($booking['organizer_timezone']??'UTC')));$due=(new DateTimeImmutable((string)$input['task_due_at'],$tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}catch(Throwable $e){throw new RuntimeException('Enter a valid follow-up due date.');}}
    if($notes===''&&$task===''&&$subject===''&&$body==='')throw new RuntimeException('Add notes, a task, or a follow-up draft.');
    $pdo->prepare('INSERT INTO agent_scheduling_followups (booking_id,owner_user_id,created_by_user_id,created_by_agent_id,notes,task_title,task_due_at,draft_subject,draft_body,message_status) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$booking['id'],(int)$booking['owner_user_id'],(int)$user['id'],$agentId?:null,$notes?:null,mb_strimwidth($task,0,190,''),$due,mb_strimwidth($subject,0,190,''),$body?:null,'draft']);
    $id=(int)$pdo->lastInsertId();
    agent_appointment_lifecycle_event_v700($pdo,$booking,'post_meeting_followup_created',agent_appointment_lifecycle_status_v700($booking),agent_appointment_lifecycle_status_v700($booking),$agentId?'agent':'member',(int)$user['id'],$agentId,['followup_id'=>$id,'has_task'=>$task!=='','has_draft'=>$body!=='']);

    // Reuse the existing CRM only when it is safely attributable to this owner.
    if($notes!==''&&function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo)&&filter_var((string)$booking['guest_email'],FILTER_VALIDATE_EMAIL)){
        try{
            $owner=agent_appointment_lifecycle_owner_user_v700($pdo,(int)$booking['owner_user_id']);$isAdmin=$owner&&function_exists('user_has_role')&&user_has_role('admin',$owner);
            $sql='SELECT l.id FROM crm_leads l JOIN crm_contacts c ON c.id=l.contact_id WHERE c.email_normalized=?';$args=[strtolower((string)$booking['guest_email'])];
            if(!$isAdmin){$sql.=' AND l.assigned_user_id=?';$args[]=(int)$booking['owner_user_id'];}
            $sql.=' ORDER BY l.updated_at DESC LIMIT 1';$find=$pdo->prepare($sql);$find->execute($args);$leadId=(int)$find->fetchColumn();
            if($leadId>0&&function_exists('crm_v180_activity'))crm_v180_activity($pdo,$leadId,'appointment_follow_up',mb_strimwidth($notes,0,500,'…'),(int)$user['id'],['booking_id'=>(int)$booking['id'],'followup_id'=>$id]);
        }catch(Throwable $ignored){}
    }
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? LIMIT 1');$stmt->execute([$id]);return $stmt->fetch()?:throw new RuntimeException('Follow-up could not be saved.');
}

