<?php
declare(strict_types=1);

function video_meeting_action_execute_v18150(PDO $pdo,array $meeting,array $user,int $executionId): array
{
    $ownerUserId=(int)($user['id']??0);
    try{
        $pdo->beginTransaction();
        $execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId,true);
        if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action not found.');
        if((string)$execution['status']==='executing')throw new RuntimeException('This Meeting Action has already started. It will not be executed twice.');
        if((string)$execution['status']!=='approved')throw new RuntimeException('Approve this Meeting Action before execution.');
        $item=video_meeting_action_item_v18150($pdo,$meeting,$ownerUserId,(int)$execution['agenda_item_id'],true);$kind=video_meeting_action_require_eligible_v18150($item);
        if($kind!==(string)$execution['action_kind'])throw new RuntimeException('The agenda action type changed. Execution is blocked.');
        $pdo->prepare("UPDATE video_meeting_action_executions SET status='executing',executing_at=UTC_TIMESTAMP(),error_class='',last_error='',updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='approved'")->execute([$executionId,$ownerUserId]);
        $execution['status']='executing';video_meeting_action_event_v18150($pdo,$execution,'execution_started','approved','executing','Organizer started the approved Meeting Action.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    $deliveryAccepted=false;
    try{
        $result=video_meeting_action_execute_domain_v18150($pdo,$meeting,$item,$execution,$user);
        $deliveryAccepted=!empty($result['delivery_accepted']);
        $summary=video_meeting_action_text_v18150($result['summary']??'Meeting Action executed.',1000);$resultJson=video_meeting_action_json_v18150((array)($result['result']??[]));
        $update=$pdo->prepare("UPDATE video_meeting_action_executions SET status='executed',workflow_run_id=?,calendar_event_id=?,crm_activity_id=?,crm_lead_id=?,email_recipient=?,result_summary=?,result_json=?,error_class='',last_error='',executed_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='executing'");
        $update->execute([
            max(0,(int)($result['workflow_run_id']??0))?:null,max(0,(int)($result['calendar_event_id']??0))?:null,max(0,(int)($result['crm_activity_id']??0))?:null,max(0,(int)($result['crm_lead_id']??0))?:null,
            video_meeting_action_text_v18150($result['email_recipient']??'',190),$summary,$resultJson,$executionId,$ownerUserId,
        ]);
        if($update->rowCount()!==1)throw new RuntimeException('Meeting Action execution state changed before the result could be recorded.');
        $fresh=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
        video_meeting_action_event_v18150($pdo,$fresh,'executed','executing','executed',$summary,['action_kind'=>(string)$fresh['action_kind']]);
        return video_meeting_action_public_v18150($pdo,$fresh,$meeting,$user,true);
    }catch(Throwable $e){
        $error=video_meeting_action_text_v18150($e->getMessage(),1000);
        $isEmail=(string)$execution['action_kind']==='email';
        $class=$isEmail&&$deliveryAccepted?'delivery_uncertain':($isEmail?'mail_not_accepted':'domain_execution_failed');
        $recordError=$isEmail&&$deliveryAccepted
            ?'Mail transport accepted the message, but VP3 could not confirm the execution receipt. Do not retry automatically.'
            :$error;
        try{
            $pdo->prepare("UPDATE video_meeting_action_executions SET status='failed',error_class=?,last_error=?,failed_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='executing'")
                ->execute([$class,video_meeting_action_text_v18150($recordError,1000),$executionId,$ownerUserId]);
            $fresh=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
            video_meeting_action_event_v18150($pdo,$fresh,'failed','executing','failed',$recordError,['error_class'=>$class,'delivery_accepted'=>$deliveryAccepted]);
        }catch(Throwable $recordFailure){
            error_log('VP3 meeting action receipt failure: '.$recordFailure->getMessage());
        }
        if($class==='delivery_uncertain')throw new RuntimeException('The email may have been delivered, but VP3 could not confirm the receipt. Automatic retry is blocked to prevent duplicate email.');
        throw new RuntimeException($error);
    }
}

function video_meeting_action_reopen_v18150(PDO $pdo,array $meeting,array $user,int $executionId): array
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action not found.');
    if((string)$execution['status']!=='approved')throw new RuntimeException('Only an approved, not-yet-executed Meeting Action can be reopened.');
    $pdo->prepare("UPDATE video_meeting_action_executions SET status='needs_review',approved_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='approved'")->execute([$executionId,$ownerUserId]);
    $fresh=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
    video_meeting_action_event_v18150($pdo,$fresh,'reopened','approved','needs_review','Organizer reopened the Meeting Action draft before execution.');
    return video_meeting_action_public_v18150($pdo,$fresh,$meeting,$user,true);
}

function video_meeting_action_retry_v18150(PDO $pdo,array $meeting,array $user,int $executionId): array
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action not found.');
    if((string)$execution['status']!=='failed')throw new RuntimeException('Only a failed Meeting Action can be retried.');
    if((string)$execution['error_class']==='delivery_uncertain')throw new RuntimeException('This email delivery is uncertain and cannot be retried automatically.');
    $pdo->prepare("UPDATE video_meeting_action_executions SET status='approved',error_class='',last_error='',failed_at=NULL,approved_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='failed'")->execute([$executionId,$ownerUserId]);
    $fresh=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
    video_meeting_action_event_v18150($pdo,$fresh,'retry_approved','failed','approved','Organizer approved a retry of the failed Meeting Action.');
    return video_meeting_action_public_v18150($pdo,$fresh,$meeting,$user,true);
}

function video_meeting_action_complete_v18150(PDO $pdo,array $meeting,array $user,int $executionId): array
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action not found.');
    if((string)$execution['status']!=='executed')throw new RuntimeException('Only an executed Meeting Action can be marked completed.');
    if((string)$execution['action_kind']==='task'){
        $runId=(int)($execution['workflow_run_id']??0);$run=$runId>0?agent_workflow_row_v1400($pdo,$ownerUserId,$runId):null;
        if(!$run||(string)$run['status']!=='completed')throw new RuntimeException('The linked Agent work item is not completed yet.');
    }
    $pdo->prepare("UPDATE video_meeting_action_executions SET status='completed',completed_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='executed'")->execute([$executionId,$ownerUserId]);
    $fresh=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId)?:$execution;
    video_meeting_action_event_v18150($pdo,$fresh,'completed','executed','completed','Organizer marked the Meeting Action completed.');
    return video_meeting_action_public_v18150($pdo,$fresh,$meeting,$user,true);
}

function video_meeting_action_discard_v18150(PDO $pdo,array $meeting,array $user,int $executionId): void
{
    $ownerUserId=(int)($user['id']??0);$execution=video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
    if(!$execution||(int)$execution['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Meeting Action draft not found.');
    if(!in_array((string)$execution['status'],['needs_review','failed'],true))throw new RuntimeException('Approved or executed Meeting Actions cannot be discarded.');
    if((string)$execution['status']==='failed'&&(string)$execution['error_class']==='delivery_uncertain')throw new RuntimeException('This uncertain email delivery must remain in the audit history and cannot be discarded.');
    $pdo->prepare('DELETE FROM video_meeting_action_executions WHERE id=? AND owner_user_id=?')->execute([$executionId,$ownerUserId]);
}

function video_meeting_action_refresh_task_v18150(PDO $pdo,array $execution): array
{
    if((string)($execution['action_kind']??'')!=='task'||(int)($execution['workflow_run_id']??0)<1||(string)$execution['status']!=='executed')return $execution;
    $run=agent_workflow_row_v1400($pdo,(int)$execution['owner_user_id'],(int)$execution['workflow_run_id']);
    if(!$run)return $execution;
    $runStatus=(string)$run['status'];
    if($runStatus==='completed'){
        $summary='Linked Agent work item completed.';
        $pdo->prepare("UPDATE video_meeting_action_executions SET status='completed',result_summary=?,completed_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='executed'")
            ->execute([$summary,(int)$execution['id'],(int)$execution['owner_user_id']]);
        $fresh=video_meeting_action_row_v18150($pdo,(int)$execution['owner_user_id'],(int)$execution['id'])?:$execution;
        video_meeting_action_event_v18150($pdo,$fresh,'linked_work_completed','executed','completed',$summary,['workflow_run_id'=>(int)$execution['workflow_run_id']]);
        return $fresh;
    }
    if(in_array($runStatus,['failed','cancelled'],true)){
        $summary='Linked Agent work item '.$runStatus.'. Meeting Action execution remains executed because the canonical work item was created successfully.';
        if((string)$execution['result_summary']!==$summary){
            $pdo->prepare("UPDATE video_meeting_action_executions SET result_summary=?,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='executed'")
                ->execute([$summary,(int)$execution['id'],(int)$execution['owner_user_id']]);
            $execution=video_meeting_action_row_v18150($pdo,(int)$execution['owner_user_id'],(int)$execution['id'])?:$execution;
        }
    }
    return $execution;
}

function video_meeting_action_state_v18150(PDO $pdo,array $meeting,array $user): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    $itemsStmt=$pdo->prepare("SELECT * FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND action_kind IN ('task','calendar','crm','email') AND approval_state='approved_for_agent_review' ORDER BY sort_order,id");
    $itemsStmt->execute([(int)$meeting['id'],$ownerUserId]);$items=[];
    foreach($itemsStmt->fetchAll()?:[] as $item){
        $execution=video_meeting_action_row_for_item_v18150($pdo,$ownerUserId,(int)$item['id']);
        if($execution)$execution=video_meeting_action_refresh_task_v18150($pdo,$execution);
        $items[]=[
            'agenda_item'=>['id'=>(int)$item['id'],'text'=>(string)$item['item_text'],'priority'=>(string)$item['priority'],'action_kind'=>(string)$item['action_kind'],'source_review_path'=>(string)$item['source_review_path']],
            'execution'=>$execution?video_meeting_action_public_v18150($pdo,$execution,$meeting,$user,true):null,
            'display_status'=>$execution?(string)$execution['status']:'needs_review',
        ];
    }
    return [
        'version'=>'v18.15','schema'=>'vp3.meeting.intelligence.action_execution',
        'meeting'=>['id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>(string)($meeting['title']??'')],
        'items'=>$items,'policy'=>[
            'structured_draft_required'=>true,'explicit_execution_approval'=>true,'duplicate_click_execution'=>false,
            'task'=>'canonical_agent_workflow','calendar'=>'canonical_calendar_automation','crm'=>'canonical_crm_activity','email'=>'existing_mail_transport',
            'email_auto_retry_on_uncertain_delivery'=>false,
        ],
        'privacy'=>['raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false,'participant_email_read'=>'email_action_only'],
        'generated_at'=>gmdate('c'),
    ];
}
