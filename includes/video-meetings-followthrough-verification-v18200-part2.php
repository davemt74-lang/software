<?php
declare(strict_types=1);

function video_meeting_followthrough_verification_execution_v18200(PDO $pdo,int $ownerUserId,int $executionId): ?array
{
    return video_meeting_action_row_v18150($pdo,$ownerUserId,$executionId);
}

function video_meeting_followthrough_verification_public_v18200(array $row): array
{
    $status=(string)$row['status'];$monitorStatus=(string)($row['monitor_status']??'');$executionStatus=(string)($row['execution_status']??'');
    $canConfirm=$status==='evidence_available'&&$monitorStatus!=='dismissed'&&$executionStatus!=='failed';
    return [
        'id'=>(int)$row['id'],'monitor_id'=>(int)$row['monitor_id'],'execution_id'=>(int)$row['execution_id'],'agenda_item_id'=>(int)$row['agenda_item_id'],
        'plan_id'=>(int)($row['plan_id']??0),'handoff_id'=>(int)($row['handoff_id']??0),'action_kind'=>(string)($row['action_kind']??''),'agenda_text'=>video_meeting_action_text_v18150($row['item_text']??'',1000),
        'status'=>$status,'monitor_status'=>$monitorStatus,'execution_status'=>$executionStatus,'criteria'=>(string)$row['criteria_snapshot'],'criteria_source'=>(string)$row['criteria_source'],
        'evidence_summary'=>(string)$row['evidence_summary'],'evidence_source'=>(string)$row['evidence_source'],'evidence_status'=>(string)$row['evidence_status'],
        'agent_suggestion'=>(string)$row['agent_suggestion'],'agent_rationale'=>(string)$row['agent_rationale'],'organizer_note'=>$status==='verified'?(string)$row['organizer_note']:'',
        'evidence_checked_at'=>(string)($row['evidence_checked_at']??''),'verified_at'=>(string)($row['verified_at']??''),
        'can_confirm'=>$canConfirm,'manual_confirmation_allowed'=>$canConfirm,'manual_note_required'=>false,'can_reopen'=>$status==='verified',
    ];
}

function video_meeting_followthrough_verification_state_v18200(PDO $pdo,array $meeting,array $user): array
{
    $owner=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$owner);
    if(!video_meeting_followthrough_verification_schema_ready_v18200($pdo))throw new RuntimeException('Follow-Through Verification is not ready. Run the current database upgrade first.');
    video_meeting_followthrough_verification_reconcile_owner_v18200($pdo,$owner);
    $s=$pdo->prepare("SELECT c.*,m.status AS monitor_status,e.action_kind,e.status AS execution_status,a.item_text FROM video_meeting_followthrough_closures c JOIN video_meeting_followthrough_monitors m ON m.id=c.monitor_id JOIN video_meeting_action_executions e ON e.id=c.execution_id JOIN video_meeting_agenda_items a ON a.id=c.agenda_item_id WHERE c.meeting_id=? AND c.owner_user_id=? ORDER BY FIELD(c.status,'needs_attention','evidence_available','waiting_for_verification','verified'),c.updated_at DESC,c.id DESC");
    $s->execute([(int)$meeting['id'],$owner]);$items=[];$counts=['waiting_for_verification'=>0,'evidence_available'=>0,'needs_attention'=>0,'verified'=>0];
    foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$items[]=video_meeting_followthrough_verification_public_v18200($row);$st=(string)$row['status'];if(isset($counts[$st]))$counts[$st]++;}
    return [
        'version'=>'v18.20','schema'=>'vp3.meeting.followthrough.verification','meeting'=>['id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>(string)$meeting['title']],
        'items'=>$items,'counts'=>$counts,
        'policy'=>['canonical_completion_is_evidence_not_success'=>true,'explicit_organizer_closure'=>true,'canonical_evidence_required_before_closure'=>true,'auto_external_side_effects'=>false,'positive_learning_requires_verified_closure'=>true],
        'privacy'=>['participant_scoring'=>false,'participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false],
        'generated_at'=>gmdate('c'),
    ];
}

function video_meeting_followthrough_verification_confirm_v18200(PDO $pdo,array $meeting,array $user,int $closureId,string $note=''): array
{
    $owner=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$owner);$closure=video_meeting_followthrough_verification_row_v18200($pdo,$owner,$closureId);
    if(!$closure||(int)$closure['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Outcome verification item not found.');
    if((string)$closure['status']==='verified')return $closure;
    $monitor=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,(int)$closure['monitor_id']);if(!$monitor)throw new RuntimeException('Follow-through monitor is unavailable.');
    if((string)$monitor['status']==='dismissed')throw new RuntimeException('Reopen the follow-through monitor before verifying the outcome.');
    $execution=video_meeting_followthrough_verification_execution_v18200($pdo,$owner,(int)$closure['execution_id']);if(!$execution)throw new RuntimeException('Meeting Action execution is unavailable.');
    if((string)$execution['status']==='failed')throw new RuntimeException('Resolve the failed Meeting Action before verifying its intended outcome.');
    $closure=video_meeting_followthrough_verification_reconcile_v18200($pdo,$closure,$execution);$criteria=trim((string)$closure['criteria_snapshot']);if($criteria==='')throw new RuntimeException('This outcome has no verification criterion. Define the plan criterion before closure.');
    if((string)$closure['status']!=='evidence_available')throw new RuntimeException('Reviewable canonical evidence is required before this intended outcome can be verified.');
    $note=video_meeting_action_text_v18150($note,1000);
    if((string)$execution['status']==='executed'){
        video_meeting_action_complete_v18150($pdo,$meeting,$user,(int)$execution['id']);
        $execution=video_meeting_followthrough_verification_execution_v18200($pdo,$owner,(int)$execution['id'])?:$execution;
    }
    $from=(string)$closure['status'];$pdo->prepare("UPDATE video_meeting_followthrough_closures SET status='verified',organizer_note=?,verified_at=UTC_TIMESTAMP(),needs_attention_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='evidence_available'")->execute([$note,$closureId,$owner]);
    if($pdo->query('SELECT ROW_COUNT()')->fetchColumn()==='0')throw new RuntimeException('Outcome verification state changed before closure. Refresh and review it again.');
    $fresh=video_meeting_followthrough_verification_row_v18200($pdo,$owner,$closureId)?:$closure;
    video_meeting_followthrough_verification_event_v18200($pdo,$fresh,'organizer_verified',$from,'verified','Organizer verified the intended meeting outcome against the recorded criterion.',['criteria_hash'=>(string)$fresh['criteria_hash'],'evidence_source'=>(string)$fresh['evidence_source'],'organizer_note_recorded'=>$note!=='']);
    video_meeting_followthrough_reconcile_owner_v18160($pdo,$owner);
    if(video_meeting_outcome_learning_schema_ready_v18170($pdo))video_meeting_outcome_learning_rebuild_v18170($pdo,$owner);
    return $fresh;
}

function video_meeting_followthrough_verification_reopen_v18200(PDO $pdo,array $meeting,array $user,int $closureId): array
{
    $owner=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$owner);$closure=video_meeting_followthrough_verification_row_v18200($pdo,$owner,$closureId);
    if(!$closure||(int)$closure['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Outcome verification item not found.');
    if((string)$closure['status']!=='verified')throw new RuntimeException('Only a verified outcome can be reopened.');
    $pdo->prepare("UPDATE video_meeting_followthrough_closures SET status='waiting_for_verification',verified_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=? AND status='verified'")->execute([$closureId,$owner]);
    $fresh=video_meeting_followthrough_verification_row_v18200($pdo,$owner,$closureId)?:$closure;video_meeting_followthrough_verification_event_v18200($pdo,$fresh,'verification_reopened','verified','waiting_for_verification','Organizer reopened outcome verification for additional review.');
    $execution=video_meeting_followthrough_verification_execution_v18200($pdo,$owner,(int)$fresh['execution_id']);if($execution)$fresh=video_meeting_followthrough_verification_reconcile_v18200($pdo,$fresh,$execution);
    if(video_meeting_outcome_learning_schema_ready_v18170($pdo))video_meeting_outcome_learning_rebuild_v18170($pdo,$owner);
    return $fresh;
}
