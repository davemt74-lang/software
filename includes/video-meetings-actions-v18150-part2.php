<?php
declare(strict_types=1);

function video_meeting_action_public_v18150(PDO $pdo,array $execution,array $meeting,array $user,bool $includeDraft=true): array
{
    $kind=(string)$execution['action_kind'];$draft=video_meeting_action_decode_v18150($execution['draft_json']??'');
    $out=[
        'id'=>(int)$execution['id'],'agenda_item_id'=>(int)$execution['agenda_item_id'],'action_kind'=>$kind,
        'status'=>(string)$execution['status'],'result_summary'=>(string)$execution['result_summary'],
        'workflow_run_id'=>(int)($execution['workflow_run_id']??0),'calendar_event_id'=>(int)($execution['calendar_event_id']??0),
        'crm_activity_id'=>(int)($execution['crm_activity_id']??0),'crm_lead_id'=>(int)($execution['crm_lead_id']??0),
        'error_class'=>(string)$execution['error_class'],'last_error'=>(string)$execution['last_error'],
        'approved_at'=>(string)($execution['approved_at']??''),'executing_at'=>(string)($execution['executing_at']??''),
        'executed_at'=>(string)($execution['executed_at']??''),'completed_at'=>(string)($execution['completed_at']??''),
        'retry_allowed'=>(string)$execution['status']==='failed'&&(string)$execution['error_class']!=='delivery_uncertain',
        'result'=>video_meeting_action_decode_v18150($execution['result_json']??''),
        'events'=>[],
    ];
    if($includeDraft)$out['draft']=$draft;
    $out['options']=$includeDraft?video_meeting_action_options_v18150($pdo,$meeting,$kind,$user):[];
    $stmt=$pdo->prepare('SELECT event_type,from_status,to_status,actor_kind,summary,created_at FROM video_meeting_action_events WHERE execution_id=? AND owner_user_id=? ORDER BY id DESC LIMIT 20');
    $stmt->execute([(int)$execution['id'],(int)$execution['owner_user_id']]);$out['events']=$stmt->fetchAll()?:[];
    $out['result_url']=match($kind){
        'calendar'=>(int)($execution['calendar_event_id']??0)>0?url('/calendar.php'):'',
        'crm'=>(int)($execution['crm_lead_id']??0)>0?url('/admin/crm-lead.php?id='.(int)$execution['crm_lead_id']):'',
        default=>'',
    };
    return $out;
}

function video_meeting_action_prepare_v18150(PDO $pdo,array $meeting,array $user,int $itemId): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_action_schema_ready_v18150($pdo))throw new RuntimeException('Meeting Action Execution is not ready. Run the current database upgrade first.');
    $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,$itemId);$kind=video_meeting_action_require_eligible_v18150($item);
    $existing=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,$itemId);
    if($existing){
        if((string)$existing['action_kind']!==$kind&&!in_array((string)$existing['status'],['needs_review','failed'],true))throw new RuntimeException('This agenda item already has an execution record for another action type.');
        if((string)$existing['action_kind']!==$kind){
            $draft=video_meeting_action_default_draft_v18150($pdo,$meeting,$item,$user);$json=video_meeting_action_json_v18150($draft);$hash=hash('sha256',$json);
            $pdo->prepare("UPDATE video_meeting_action_executions SET action_kind=?,status='needs_review',draft_json=?,draft_hash=?,result_summary='',result_json=NULL,error_class='',last_error='',approved_at=NULL,executing_at=NULL,executed_at=NULL,completed_at=NULL,failed_at=NULL,workflow_run_id=NULL,calendar_event_id=NULL,crm_activity_id=NULL,crm_lead_id=NULL,email_recipient='',updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$kind,$json,$hash,(int)$existing['id'],$ownerUserId]);
            $existing=video_meeting_action_row_v18150($pdo,$ownerUserId,(int)$existing['id'])?:$existing;
        }
        return video_meeting_action_public_v18150($pdo,$existing,$meeting,$user,true);
    }
    $draft=video_meeting_action_default_draft_v18150($pdo,$meeting,$item,$user);$json=video_meeting_action_json_v18150($draft);$hash=hash('sha256',$json);$idempotency=video_meeting_action_idempotency_v18150($meeting,$item);
    $stmt=$pdo->prepare("INSERT INTO video_meeting_action_executions (agenda_item_id,meeting_id,owner_user_id,action_kind,status,draft_json,draft_hash,idempotency_key) VALUES (?,?,?,?,'needs_review',?,?,?)");
    $stmt->execute([$itemId,(int)$meeting['id'],$ownerUserId,$kind,$json,$hash,$idempotency]);
    $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,(int)$pdo->lastInsertId())?:throw new RuntimeException('Meeting Action draft could not be created.');
    video_meeting_action_event_v18150($pdo,$execution,'prepared','','needs_review','Structured execution draft prepared from the approved agenda item.',['action_kind'=>$kind]);
    return video_meeting_action_public_v18150($pdo,$execution,$meeting,$user,true);
}

function video_meeting_action_save_draft_v18150(PDO $pdo,array $meeting,array $user,int $executionId,array $input): array
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action draft not found.');
    if(!in_array((string)$execution['status'],['needs_review','failed'],true))throw new RuntimeException('Only an unapproved or failed action draft can be edited.');
    $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,(int)$execution['agenda_item_id']);$kind=video_meeting_action_require_eligible_v18150($item);
    if($kind!==(string)$execution['action_kind'])throw new RuntimeException('The agenda action type changed. Discard this draft and prepare it again.');
    $draft=video_meeting_action_sanitize_draft_v18150($kind,$input);$json=video_meeting_action_json_v18150($draft);$hash=hash('sha256',$json);$from=(string)$execution['status'];
    $pdo->prepare("UPDATE video_meeting_action_executions SET status='needs_review',draft_json=?,draft_hash=?,result_summary='',result_json=NULL,error_class='',last_error='',approved_at=NULL,executing_at=NULL,failed_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$json,$hash,$executionId,$ownerUserId]);
    $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
    video_meeting_action_event_v18150($pdo,$execution,'draft_saved',$from,'needs_review','Meeting Action draft updated.');
    return video_meeting_action_public_v18150($pdo,$execution,$meeting,$user,true);
}

function video_meeting_action_approve_v18150(PDO $pdo,array $meeting,array $user,int $executionId): array
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action draft not found.');
    if(!in_array((string)$execution['status'],['needs_review','failed'],true))throw new RuntimeException('This Meeting Action is not waiting for approval.');
    $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,(int)$execution['agenda_item_id']);$kind=video_meeting_action_require_eligible_v18150($item);
    if($kind!==(string)$execution['action_kind'])throw new RuntimeException('The agenda action type changed. Discard this draft and prepare it again.');
    $draft=video_meeting_action_decode_v18150($execution['draft_json']??'');video_meeting_action_validate_draft_v18150($pdo,$meeting,$kind,$draft,$user);
    $from=(string)$execution['status'];$pdo->prepare("UPDATE video_meeting_action_executions SET status='approved',approved_at=UTC_TIMESTAMP(),failed_at=NULL,error_class='',last_error='',updated_at=NOW() WHERE id=? AND owner_user_id=? AND status IN ('needs_review','failed')")->execute([$executionId,$ownerUserId]);
    $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
    video_meeting_action_event_v18150($pdo,$execution,'approved',$from,'approved','Organizer explicitly approved this Meeting Action for execution.',['draft_hash'=>(string)$execution['draft_hash']]);
    return video_meeting_action_public_v18150($pdo,$execution,$meeting,$user,true);
}

function video_meeting_action_crm_existing_v18150(PDO $pdo,int $leadId,string $reference): int
{
    $needle='%"meeting_action_reference":"'.str_replace(['%','_'],['\\%','\\_'],$reference).'"%';
    $stmt=$pdo->prepare("SELECT id FROM crm_activities WHERE lead_id=? AND details_json LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$leadId,$needle]);
    return (int)$stmt->fetchColumn();
}

function video_meeting_action_execute_domain_v18150(PDO $pdo,array $meeting,array $item,array $execution,array $user): array
{
    $kind=(string)$execution['action_kind'];$draft=video_meeting_action_decode_v18150($execution['draft_json']??'');
    video_meeting_action_validate_draft_v18150($pdo,$meeting,$kind,$draft,$user);
    $ref='meeting-action-v18150:'.(int)$execution['id'].':'.substr((string)$execution['idempotency_key'],0,16);
    if($kind==='task'){
        $priority=[
            'key'=>$ref,'suggestion_hash'=>sha1((string)$execution['idempotency_key']),'title'=>(string)$draft['title'],
            'source'=>'meeting_agenda_external','prompt'=>(string)($draft['description']??$item['item_text']??''),
            'reason'=>'Approved meeting follow-up from '.video_meeting_action_text_v18150($meeting['title']??'Meeting',190),
            'score'=>1.0,'requires_approval'=>true,'risk_level'=>'low','action_id'=>'meeting-action-'.(int)$execution['id'],
        ];
        $agentId=max(0,(int)($meeting['organizer_agent_id']??0))?:null;
        $run=agent_workflow_create_from_priority_v1400($pdo,$user,$priority,$agentId);
        $runId=(int)($run['id']??0);if($runId<1)throw new RuntimeException('Agent work item could not be created.');
        if(empty($run['requires_approval']))throw new RuntimeException('Agent Work Control did not attach its required approval gate. Task execution is blocked.');
        $priorityValue=match((string)($draft['priority']??'normal')){'high'=>75,'low'=>25,default=>50};
        try{agent_work_control_priority_v173($pdo,$user,$runId,$priorityValue);}catch(Throwable $ignored){}
        return ['summary'=>'Created Agent work item #'.$runId.' with the canonical Agent Work approval gate.','workflow_run_id'=>$runId,'result'=>['workflow_run_id'=>$runId,'workflow_status'=>(string)($run['status']??''),'requires_approval'=>true]];
    }
    if($kind==='calendar'){
        $event=user_calendar_automation_create_event_v1300($pdo,$user,[
            'title'=>(string)$draft['title'],'description'=>(string)($draft['description']??''),'location'=>(string)($draft['location']??''),
            'date'=>(string)$draft['date'],'start_time'=>(string)$draft['start_time'],'duration_minutes'=>(int)$draft['duration_minutes'],
            'timezone'=>(string)$draft['timezone'],'all_day'=>false,
        ],$ref);
        return ['summary'=>'Created calendar event “'.(string)$event['title'].'”.','calendar_event_id'=>(int)$event['id'],'result'=>['calendar_event_id'=>(int)$event['id'],'start_at_utc'=>(string)$event['start_at_utc'],'end_at_utc'=>(string)$event['end_at_utc']]];
    }
    if($kind==='crm'){
        $leadId=(int)$draft['lead_id'];$existing=video_meeting_action_crm_existing_v18150($pdo,$leadId,$ref);
        $activityId=$existing>0?$existing:crm_v180_activity($pdo,$leadId,(string)$draft['activity_type'],(string)$draft['summary'],(int)$user['id'],[
            'meeting_action_reference'=>$ref,'meeting_id'=>(int)$meeting['id'],'agenda_item_id'=>(int)$item['id'],'execution_id'=>(int)$execution['id'],
        ]);
        return ['summary'=>'Added the meeting follow-up to CRM lead #'.$leadId.'.','crm_activity_id'=>$activityId,'crm_lead_id'=>$leadId,'result'=>['crm_activity_id'=>$activityId,'crm_lead_id'=>$leadId]];
    }
    $recipient=strtolower(trim((string)$draft['recipient_email']));
    if(!agent_appointment_lifecycle_email_v700($recipient,(string)$draft['subject'],(string)$draft['body']))throw new RuntimeException('The email transport did not accept this message.');
    return ['summary'=>'Sent the approved meeting follow-up email.','email_recipient'=>$recipient,'delivery_accepted'=>true,'result'=>['delivery'=>'accepted_by_mail_transport']];
}
