<?php
declare(strict_types=1);

/**
 * VP3 Phase 14.1 — Proactive Meeting Prep + Follow-up Execution.
 *
 * Scheduling remains authoritative for appointments, briefs and follow-up
 * records. Phase 14 provides the durable workflow/approval ledger. This layer
 * binds them together and requires every meeting-prep report to be published
 * into the owner's Agent Chat canvas.
 */
const VP3_AGENT_MEETING_WORKFLOWS_V1410='agent-meeting-workflows-v1410-20260912';
const VP3_AGENT_MEETING_FOLLOWUP_NOTE_V1410='Agent-generated post-meeting follow-up draft. Review before sending.';

require_once __DIR__.'/agent-workflow-runs-v1400.php';

function agent_meeting_workflow_ready_v1410(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && function_exists('agent_appointment_lifecycle_schema_ready_v700')
        && agent_appointment_lifecycle_schema_ready_v700($pdo)
        && agent_workflow_schema_ready_v1400($pdo);
}

function agent_meeting_workflow_source_key_v1410(array $booking,string $kind,string $revision=''): string
{
    $bookingId=max(0,(int)($booking['id']??0));
    $anchor=$kind==='meeting_prep'
        ? trim((string)($booking['start_at_utc']??''))
        : trim((string)($booking['completed_at']??$booking['last_lifecycle_event_at']??$booking['updated_at']??''));
    if($revision!=='')$anchor.='|'.$revision;
    return agent_workflow_text_v1400($kind.':booking:'.$bookingId.':'.sha1($anchor),190);
}

function agent_meeting_workflow_existing_v1410(PDO $pdo,int $ownerUserId,string $sourceKey): ?array
{
    if($ownerUserId<1||$sourceKey==='')return null;
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='appointment' AND source_key=? LIMIT 1");
    $stmt->execute([$ownerUserId,$sourceKey]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function agent_meeting_workflow_event_exists_v1410(PDO $pdo,int $ownerUserId,int $runId,string $eventType): bool
{
    $stmt=$pdo->prepare('SELECT id FROM agent_workflow_events WHERE owner_user_id=? AND run_id=? AND event_type=? LIMIT 1');
    $stmt->execute([$ownerUserId,$runId,$eventType]);
    return (int)$stmt->fetchColumn()>0;
}

function agent_meeting_workflow_active_action_v1410(PDO $pdo,int $ownerUserId,array $run): ?array
{
    $runId=(int)($run['id']??0);$actionId=(int)($run['current_action_id']??0);
    if($ownerUserId<1||$runId<1||$actionId<1||(string)($run['status']??'')!=='executing')return null;
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=? AND status='executing' LIMIT 1");
    $stmt->execute([$actionId,$runId,$ownerUserId]);$action=$stmt->fetch();
    return is_array($action)?agent_workflow_public_action_v1400($action):null;
}

/**
 * Create one canonical Phase 14 run for an appointment occurrence.
 * $steps: [['key','label','summary','requires_approval','capability_key']]
 */
function agent_meeting_workflow_create_run_v1410(PDO $pdo,array $user,array $booking,string $workflowType,string $sourceKey,string $title,string $goal,string $decision,array $steps,bool $requiresApproval=false,?int $agentId=null): array
{
    if(!agent_meeting_workflow_ready_v1410($pdo))throw new RuntimeException('Meeting workflows are not ready. Run the Phase 14 database upgrade first.');
    $ownerUserId=(int)($user['id']??0);$bookingId=(int)($booking['id']??0);
    if($ownerUserId<1||$bookingId<1||(int)($booking['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('That appointment is not available to this account.');
    $sourceKey=agent_workflow_text_v1400($sourceKey,190);$dedupe=hash('sha256','meeting|'.$ownerUserId.'|'.$sourceKey);
    $existing=agent_meeting_workflow_existing_v1410($pdo,$ownerUserId,$sourceKey);
    if($existing)return agent_workflow_public_run_v1400($pdo,$existing,true);
    if(!$steps)throw new RuntimeException('A meeting workflow needs at least one observable action.');

    $capability=agent_workflow_text_v1400((string)($steps[0]['capability_key']??''),160);
    try{
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("INSERT INTO agent_workflow_runs (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,decision_summary,status,risk_level,requires_approval,approval_status,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,'planning',?,?,?,?,?)");
        $stmt->execute([
            $ownerUserId,$agentId&&$agentId>0?$agentId:null,agent_workflow_text_v1400($workflowType,80),'appointment','appointment',$sourceKey,sha1($sourceKey),$dedupe,
            agent_workflow_text_v1400($title,190),agent_workflow_text_v1400($goal,2000),agent_workflow_text_v1400($decision,1500),
            $requiresApproval?'medium':'low',$requiresApproval?1:0,$requiresApproval?'pending':'not_required','cloud',$capability,
        ]);
        $runId=(int)$pdo->lastInsertId();
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'created','','planning','agent','Meeting workflow created from the canonical appointment.',['booking_id'=>$bookingId,'workflow_type'=>$workflowType]);
        $insert=$pdo->prepare('INSERT INTO agent_workflow_actions (run_id,owner_user_id,sequence_no,action_key,action_type,label,summary,status,requires_approval,execution_target,capability_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach(array_values($steps) as $index=>$step){
            $stepRequires=!empty($step['requires_approval']);
            $insert->execute([
                $runId,$ownerUserId,$index+1,agent_workflow_text_v1400($step['key']??('step-'.($index+1)),120),'execute',
                agent_workflow_text_v1400($step['label']??'Meeting workflow action',190),agent_workflow_text_v1400($step['summary']??'',1500),
                $stepRequires?'approval_pending':'queued',$stepRequires?1:0,'cloud',agent_workflow_text_v1400($step['capability_key']??'',160),
            ]);
        }
        $final=$requiresApproval?'approval_pending':'approved';
        $pdo->prepare('UPDATE agent_workflow_runs SET status=? WHERE id=? AND owner_user_id=?')->execute([$final,$runId,$ownerUserId]);
        agent_workflow_event_v1400($pdo,$ownerUserId,$runId,$requiresApproval?'approval_requested':'approval_not_required','planning',$final,'system',$requiresApproval?'External follow-up delivery is waiting for explicit user approval.':'Read-only meeting preparation is approved automatically.',['booking_id'=>$bookingId]);
        $pdo->commit();
        $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);
        if(!$row)throw new RuntimeException('Meeting workflow could not be loaded.');
        return agent_workflow_public_run_v1400($pdo,$row,true);
    }catch(PDOException $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if((string)$e->getCode()==='23000'){
            $existing=agent_meeting_workflow_existing_v1410($pdo,$ownerUserId,$sourceKey);
            if($existing)return agent_workflow_public_run_v1400($pdo,$existing,true);
        }
        throw $e;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function agent_meeting_workflow_owner_v1410(PDO $pdo,int $ownerUserId): ?array
{
    if($ownerUserId<1)return null;
    if(function_exists('agent_appointment_lifecycle_owner_user_v700'))return agent_appointment_lifecycle_owner_user_v700($pdo,$ownerUserId);
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? AND is_active=1 LIMIT 1');$stmt->execute([$ownerUserId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function agent_meeting_workflow_chat_context_v1410(array $booking,int $runId,string $sourceLabel): array
{
    $bookingId=(int)($booking['id']??0);
    return [
        'source'=>'agent_meeting_workflow',
        'source_label'=>$sourceLabel,
        'booking_id'=>$bookingId,
        'workflow_run_id'=>$runId,
        'appointment_start_at_utc'=>(string)($booking['start_at_utc']??''),
        'meeting_workflow'=>true,
        'actions'=>[
            ['label'=>'Open appointment','url'=>url('/appointment-lifecycle.php?booking='.$bookingId)],
            ['label'=>'Open workflow','url'=>url('/agent-workflows.php?id='.$runId)],
        ],
        'sources'=>[
            ['source'=>'appointment:'.$bookingId,'title'=>(string)($booking['event_title']??'Appointment')],
            ['source'=>'workflow:'.$runId,'title'=>'Agent workflow run #'.$runId],
        ],
        // The report is derived from canonical appointment/context data. Keep it
        // visible in Agent Chat without re-ingesting the generated summary as a
        // new Brain memory and creating a feedback loop.
        'skip_brain_archive'=>true,
        'generated_at'=>gmdate('c'),
    ];
}

function agent_meeting_workflow_publish_prep_v1410(PDO $pdo,array $user,array $booking,int $runId,array $brief): int
{
    if(!function_exists('agent_chat_v101_append_ecosystem_message'))throw new RuntimeException('Agent Chat is unavailable, so the meeting prep report cannot be delivered.');
    $title=trim((string)($booking['event_title']??''))?:'Appointment';$guest=trim((string)($booking['guest_name']??''))?:'attendee';
    $report=trim((string)($brief['brief_text']??''));if($report==='')throw new RuntimeException('Meeting prep did not produce a report.');
    $message="Meeting prep report: {$title} with {$guest}\n\n".mb_strimwidth($report,0,12000,'…')."\n\nI’ve added this report to your Agent Chat canvas and linked it to workflow #{$runId}.";
    $context=agent_meeting_workflow_chat_context_v1410($booking,$runId,'Meeting Prep');$context['meeting_prep_report']=true;
    $conversationId=agent_chat_v101_append_ecosystem_message($user,$message,$context);
    if($conversationId<1)throw new RuntimeException('Agent Chat did not accept the meeting prep report.');
    agent_workflow_event_v1400($pdo,(int)$user['id'],$runId,'meeting_prep_chat_published','executing','executing','agent','Meeting prep report published to the Agent Chat canvas.',['booking_id'=>(int)$booking['id'],'conversation_id'=>$conversationId]);
    return $conversationId;
}

function agent_meeting_workflow_execute_prep_action_v1410(PDO $pdo,array $user,array $booking,int $runId,array $action,?array &$brief,int &$conversationId): void
{
    $ownerUserId=(int)$user['id'];$actionId=(int)($action['id']??0);$key=(string)($action['action_key']??'');
    if($actionId<1)throw new RuntimeException('Meeting-prep action is unavailable.');
    try{
        if($key==='prepare-brief'){
            $brief=agent_appointment_lifecycle_prepare_brief_v700($pdo,$booking);
            agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,true,'Meeting brief prepared from canonical appointment context.',['booking_id'=>(int)$booking['id'],'context_sections'=>count((array)($brief['context']??[]))]);
        }elseif($key==='publish-chat'){
            if(!$brief){$saved=agent_appointment_lifecycle_brief_v700($pdo,(int)$booking['id']);if($saved)$brief=['brief_text'=>(string)$saved['brief_text'],'context'=>json_decode((string)($saved['context_json']??'{}'),true)?:[],'agent_id'=>$saved['agent_id']??null];}
            if(!$brief)throw new RuntimeException('Meeting brief is unavailable for Agent Chat publication.');
            if(agent_meeting_workflow_event_exists_v1410($pdo,$ownerUserId,$runId,'meeting_prep_chat_published'))$conversationId=0;
            else $conversationId=agent_meeting_workflow_publish_prep_v1410($pdo,$user,$booking,$runId,$brief);
            agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,true,'Meeting prep report published to Agent Chat.',['booking_id'=>(int)$booking['id'],'conversation_id'=>$conversationId]);
        }else{
            throw new RuntimeException('Unknown meeting-prep workflow action.');
        }
    }catch(Throwable $e){
        try{agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,false,'Meeting prep action failed.',[],get_class($e));}catch(Throwable $ignored){}
        throw $e;
    }
}

/**
 * Prepare one appointment occurrence and make Chat-canvas publication part of
 * the same durable Phase 14 run. Automatic runs dedupe by appointment start;
 * manual refreshes intentionally create a new report/run.
 */
function agent_meeting_workflow_prepare_v1410(PDO $pdo,array $booking,bool $manualRefresh=false): array
{
    if(!agent_meeting_workflow_ready_v1410($pdo))throw new RuntimeException('Meeting workflows are not ready.');
    $ownerUserId=(int)($booking['owner_user_id']??0);$user=agent_meeting_workflow_owner_v1410($pdo,$ownerUserId);
    if(!$user)throw new RuntimeException('Appointment owner is unavailable.');
    $revision=$manualRefresh?'manual|'.gmdate('Y-m-d H:i:s').'|'.bin2hex(random_bytes(4)):'';
    $sourceKey=agent_meeting_workflow_source_key_v1410($booking,'meeting_prep',$revision);
    $agentId=max(0,(int)($booking['agent_id']??$booking['created_by_agent_id']??0))?:null;
    $run=agent_meeting_workflow_create_run_v1410($pdo,$user,$booking,'meeting_prep',$sourceKey,
        'Meeting prep: '.((string)($booking['event_title']??'Appointment')),
        'Prepare the host for this appointment using canonical scheduling, intake, message, CRM and knowledge context, then publish the report to Agent Chat.',
        'The appointment is approaching its configured Agent preparation window.',[
            ['key'=>'prepare-brief','label'=>'Prepare meeting brief','summary'=>'Build the canonical read-only appointment brief.','capability_key'=>'scheduling.meeting_prep.prepare'],
            ['key'=>'publish-chat','label'=>'Publish prep to Agent Chat','summary'=>'Report the meeting prep into the owner’s Agent Chat canvas.','capability_key'=>'agent.chat.publish_meeting_prep'],
        ],false,$agentId);
    $runId=(int)($run['id']??0);
    if($runId<1)throw new RuntimeException('Meeting prep workflow could not be created.');

    if((string)($run['status']??'')==='failed')$run=agent_workflow_retry_v1400($pdo,$user,$runId);
    if((string)($run['status']??'')==='completed'){
        $saved=agent_appointment_lifecycle_brief_v700($pdo,(int)$booking['id']);
        return ['run'=>$run,'brief'=>$saved?:null,'conversation_id'=>0,'already_completed'=>true];
    }

    $brief=null;$conversationId=0;
    // Prep actions are read-only/idempotent enough to resume safely if PHP was
    // interrupted after Phase 14 marked an action executing. Chat publication
    // is protected by the workflow event marker before a second append attempt.
    $lockedRun=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);
    if($lockedRun&&($active=agent_meeting_workflow_active_action_v1410($pdo,$ownerUserId,$lockedRun))){
        agent_meeting_workflow_execute_prep_action_v1410($pdo,$user,$booking,$runId,$active,$brief,$conversationId);
    }
    for($guard=0;$guard<4;$guard++){
        $action=agent_workflow_claim_next_action_v1400($pdo,$user,$runId,'cloud');
        if(!$action)break;
        agent_meeting_workflow_execute_prep_action_v1410($pdo,$user,$booking,$runId,$action,$brief,$conversationId);
    }
    $row=agent_workflow_row_v1400($pdo,$ownerUserId,$runId);
    $final=$row?agent_workflow_public_run_v1400($pdo,$row,true):$run;
    if((string)($final['status']??'')!=='completed')throw new RuntimeException('Meeting prep did not complete its Agent Chat delivery contract.');
    return ['run'=>$final,'brief'=>$brief?:agent_appointment_lifecycle_brief_v700($pdo,(int)$booking['id']),'conversation_id'=>$conversationId,'already_completed'=>false];
}

function agent_meeting_workflow_followup_draft_v1410(PDO $pdo,array $booking,array $user): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE booking_id=? AND owner_user_id=? AND notes=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$booking['id'],(int)$user['id'],VP3_AGENT_MEETING_FOLLOWUP_NOTE_V1410]);$row=$stmt->fetch();
    if($row)return $row;
    return agent_appointment_lifecycle_agent_followup_v700($pdo,$booking,$user);
}

function agent_meeting_workflow_followup_id_v1410(PDO $pdo,int $ownerUserId,int $runId): int
{
    foreach(agent_workflow_actions_v1400($pdo,$ownerUserId,$runId) as $action){
        if(preg_match('/^send-followup:(\d+)$/',(string)($action['action_key']??''),$m))return (int)$m[1];
    }
    return 0;
}

function agent_meeting_workflow_publish_followup_v1410(PDO $pdo,array $user,array $booking,array $run,array $followup): int
{
    $runId=(int)($run['id']??0);$ownerUserId=(int)($user['id']??0);
    if(agent_meeting_workflow_event_exists_v1410($pdo,$ownerUserId,$runId,'meeting_followup_chat_published'))return 0;
    if(!function_exists('agent_chat_v101_append_ecosystem_message'))throw new RuntimeException('Agent Chat is unavailable, so the follow-up draft cannot be surfaced for approval.');
    $guest=trim((string)($booking['guest_name']??''))?:'attendee';$subject=trim((string)($followup['draft_subject']??''));$body=trim((string)($followup['draft_body']??''));
    $message="Post-meeting follow-up ready for {$guest}\n\nSubject: {$subject}\n\n".mb_strimwidth($body,0,9000,'…')."\n\nThis message has not been sent. Review workflow #{$runId} and approve it before VP3 can deliver the external follow-up.";
    $context=agent_meeting_workflow_chat_context_v1410($booking,$runId,'Meeting Follow-up');$context['meeting_followup_draft']=true;$context['followup_id']=(int)$followup['id'];
    $conversationId=agent_chat_v101_append_ecosystem_message($user,$message,$context);
    if($conversationId<1)throw new RuntimeException('Agent Chat did not accept the follow-up draft.');
    agent_workflow_event_v1400($pdo,$ownerUserId,$runId,'meeting_followup_chat_published','approval_pending','approval_pending','agent','Post-meeting follow-up draft published to Agent Chat for approval.',['booking_id'=>(int)$booking['id'],'followup_id'=>(int)$followup['id'],'conversation_id'=>$conversationId]);
    return $conversationId;
}

function agent_meeting_workflow_ensure_followup_v1410(PDO $pdo,array $booking): array
{
    $status=(string)($booking['lifecycle_status']??$booking['status']??'');
    if($status!=='completed'&&(string)($booking['status']??'')!=='completed')throw new RuntimeException('Post-meeting follow-up is only created after a completed appointment.');
    $ownerUserId=(int)$booking['owner_user_id'];$user=agent_meeting_workflow_owner_v1410($pdo,$ownerUserId);
    if(!$user)throw new RuntimeException('Appointment owner is unavailable.');
    $sourceKey=agent_meeting_workflow_source_key_v1410($booking,'meeting_followup');
    $existing=agent_meeting_workflow_existing_v1410($pdo,$ownerUserId,$sourceKey);
    $followup=null;
    if($existing){
        $run=agent_workflow_public_run_v1400($pdo,$existing,true);$followupId=agent_meeting_workflow_followup_id_v1410($pdo,$ownerUserId,(int)$existing['id']);
        if($followupId>0){$stmt=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? AND booking_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$followupId,(int)$booking['id'],$ownerUserId]);$followup=$stmt->fetch()?:null;}
    }else{
        $followup=agent_meeting_workflow_followup_draft_v1410($pdo,$booking,$user);$followupId=(int)$followup['id'];
        $agentId=max(0,(int)($followup['created_by_agent_id']??0))?:null;
        $run=agent_meeting_workflow_create_run_v1410($pdo,$user,$booking,'meeting_followup',$sourceKey,
            'Post-meeting follow-up: '.((string)($booking['event_title']??'Appointment')),
            'Send the prepared post-meeting follow-up to the attendee after explicit user approval.',
            'The appointment is completed and a follow-up draft is ready for review.',[
                ['key'=>'send-followup:'.$followupId,'label'=>'Send approved follow-up','summary'=>'Deliver the reviewed follow-up through the canonical appointment email path.','requires_approval'=>true,'capability_key'=>'scheduling.followup.send'],
            ],true,$agentId);
    }
    if(!$followup)throw new RuntimeException('Post-meeting follow-up record is unavailable.');
    $conversationId=agent_meeting_workflow_publish_followup_v1410($pdo,$user,$booking,$run,$followup);
    return ['run'=>$run,'followup'=>$followup,'conversation_id'=>$conversationId];
}

function agent_meeting_workflow_reconcile_followups_v1410(PDO $pdo,int $limit=80): int
{
    if(!agent_meeting_workflow_ready_v1410($pdo))return 0;$limit=max(1,min(200,$limit));
    $stmt=$pdo->query("SELECT id FROM agent_scheduling_bookings WHERE status='completed' OR lifecycle_status='completed' ORDER BY COALESCE(completed_at,updated_at) DESC,id DESC LIMIT ".$limit);
    $count=0;
    foreach($stmt->fetchAll()?:[] as $row){
        try{$booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$row['id']);if(!$booking)continue;$before=agent_meeting_workflow_existing_v1410($pdo,(int)$booking['owner_user_id'],agent_meeting_workflow_source_key_v1410($booking,'meeting_followup'));agent_meeting_workflow_ensure_followup_v1410($pdo,$booking);if(!$before)$count++;}catch(Throwable $ignored){}
    }
    return $count;
}

function agent_meeting_workflow_execute_followups_v1410(PDO $pdo,int $limit=40): int
{
    if(!agent_meeting_workflow_ready_v1410($pdo))return 0;$limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE workflow_type='meeting_followup' AND source_kind='appointment' AND status IN ('approved','executing') ORDER BY updated_at,id LIMIT ".$limit);$stmt->execute();$executed=0;
    foreach($stmt->fetchAll()?:[] as $run){
        $ownerUserId=(int)$run['owner_user_id'];$user=agent_meeting_workflow_owner_v1410($pdo,$ownerUserId);if(!$user)continue;
        $runId=(int)$run['id'];$wasInterrupted=(string)$run['status']==='executing'&&(int)($run['current_action_id']??0)>0;
        $action=$wasInterrupted?agent_meeting_workflow_active_action_v1410($pdo,$ownerUserId,$run):agent_workflow_claim_next_action_v1400($pdo,$user,$runId,'cloud');
        if(!$action)continue;$actionId=(int)$action['id'];
        try{
            if(!preg_match('/^send-followup:(\d+)$/',(string)$action['action_key'],$m))throw new RuntimeException('Unknown meeting follow-up action.');
            $followupId=(int)$m[1];if(!preg_match('/meeting_followup:booking:(\d+):/',(string)$run['source_key'],$bm))throw new RuntimeException('Meeting follow-up is missing its appointment reference.');
            $booking=agent_appointment_lifecycle_booking_v700($pdo,(int)$bm[1]);if(!$booking||(int)$booking['owner_user_id']!==$ownerUserId)throw new RuntimeException('Appointment is unavailable for this workflow.');
            $find=$pdo->prepare('SELECT * FROM agent_scheduling_followups WHERE id=? AND booking_id=? AND owner_user_id=? LIMIT 1');$find->execute([$followupId,(int)$booking['id'],$ownerUserId]);$followup=$find->fetch();if(!$followup)throw new RuntimeException('Approved follow-up draft no longer exists.');
            if($wasInterrupted&&(string)$followup['message_status']!=='sent'){
                // mail() has no provider idempotency key. If PHP died during an
                // external send, never guess and resend automatically.
                agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,false,'Follow-up delivery state is ambiguous after an interrupted execution. Review before retrying.',[],'ambiguous_delivery');
                continue;
            }
            if((string)$followup['message_status']==='sent')$sent=$followup;
            else $sent=agent_appointment_lifecycle_send_followup_v700($pdo,$booking,$user,$followupId,max(0,(int)($followup['created_by_agent_id']??0))?:null);
            agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,true,'Approved post-meeting follow-up delivered.',['booking_id'=>(int)$booking['id'],'followup_id'=>$followupId,'message_status'=>(string)($sent['message_status']??'sent')]);$executed++;
        }catch(Throwable $e){
            try{agent_workflow_record_action_result_v1400($pdo,$user,$runId,$actionId,false,'Approved post-meeting follow-up could not be delivered.',[],get_class($e));}catch(Throwable $ignored){}
        }
    }
    return $executed;
}

function agent_meeting_workflow_housekeeping_v1410(PDO $pdo,int $limit=80): array
{
    if(!agent_meeting_workflow_ready_v1410($pdo))return ['followups_created'=>0,'followups_executed'=>0];
    $created=agent_meeting_workflow_reconcile_followups_v1410($pdo,$limit);
    $executed=agent_meeting_workflow_execute_followups_v1410($pdo,min(60,$limit));
    return ['followups_created'=>$created,'followups_executed'=>$executed];
}
