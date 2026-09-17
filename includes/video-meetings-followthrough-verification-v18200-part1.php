<?php
declare(strict_types=1);

function video_meeting_followthrough_verification_schema_ready_v18200(?PDO $pdo=null): bool
{
    $pdo??=db();if(!$pdo)return false;
    foreach(['video_meeting_followthrough_closures','video_meeting_followthrough_closure_events'] as $table){if(!table_exists($table))return false;}
    foreach(['monitor_id','execution_id','plan_id','handoff_id','criteria_snapshot','criteria_hash','status','evidence_json','evidence_summary','evidence_source','agent_suggestion','organizer_note','verified_at'] as $column){
        if(!column_exists('video_meeting_followthrough_closures',$column))return false;
    }
    return true;
}

function video_meeting_followthrough_verification_ensure_schema_v18200(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_plan_action_schema_ready_v18190($pdo))video_meeting_plan_action_ensure_schema_v18190($pdo);
    if(!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo))video_meeting_followthrough_intelligence_ensure_schema_v18160($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_closures (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      monitor_id BIGINT UNSIGNED NOT NULL,
      execution_id BIGINT UNSIGNED NOT NULL,
      plan_id BIGINT UNSIGNED NULL,
      handoff_id BIGINT UNSIGNED NULL,
      agenda_item_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      criteria_snapshot VARCHAR(1000) NOT NULL DEFAULT '',
      criteria_hash CHAR(64) NOT NULL DEFAULT '',
      criteria_source VARCHAR(40) NOT NULL DEFAULT '',
      status VARCHAR(32) NOT NULL DEFAULT 'waiting_for_verification',
      evidence_json TEXT NULL,
      evidence_summary VARCHAR(1000) NOT NULL DEFAULT '',
      evidence_source VARCHAR(60) NOT NULL DEFAULT '',
      evidence_status VARCHAR(40) NOT NULL DEFAULT '',
      agent_suggestion VARCHAR(40) NOT NULL DEFAULT '',
      agent_rationale VARCHAR(1000) NOT NULL DEFAULT '',
      organizer_note VARCHAR(1000) NOT NULL DEFAULT '',
      evidence_checked_at DATETIME NULL,
      verified_at DATETIME NULL,
      needs_attention_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_followthrough_closure_monitor (monitor_id),
      UNIQUE KEY uq_video_meeting_followthrough_closure_execution (execution_id),
      INDEX idx_video_meeting_followthrough_closure_meeting (meeting_id,status,id),
      INDEX idx_video_meeting_followthrough_closure_owner (owner_user_id,status,updated_at,id),
      CONSTRAINT fk_video_meeting_followthrough_closure_monitor FOREIGN KEY (monitor_id) REFERENCES video_meeting_followthrough_monitors(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_execution FOREIGN KEY (execution_id) REFERENCES video_meeting_action_executions(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_plan FOREIGN KEY (plan_id) REFERENCES video_meeting_followthrough_plans(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_followthrough_closure_handoff FOREIGN KEY (handoff_id) REFERENCES video_meeting_plan_action_handoffs(id) ON DELETE SET NULL,
      CONSTRAINT fk_video_meeting_followthrough_closure_item FOREIGN KEY (agenda_item_id) REFERENCES video_meeting_agenda_items(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_closure_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      closure_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      from_status VARCHAR(32) NOT NULL DEFAULT '',
      to_status VARCHAR(32) NOT NULL DEFAULT '',
      summary VARCHAR(500) NOT NULL DEFAULT '',
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_followthrough_closure_event (closure_id,id),
      INDEX idx_video_meeting_followthrough_closure_event_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_video_meeting_followthrough_closure_event_closure FOREIGN KEY (closure_id) REFERENCES video_meeting_followthrough_closures(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_closure_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_followthrough_verification_event_v18200(PDO $pdo,array $closure,string $type,string $from,string $to,string $summary,array $metadata=[]): void
{
    $pdo->prepare('INSERT INTO video_meeting_followthrough_closure_events (closure_id,meeting_id,owner_user_id,event_type,from_status,to_status,summary,metadata_json) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int)$closure['id'],(int)$closure['meeting_id'],(int)$closure['owner_user_id'],video_meeting_action_text_v18150($type,60),video_meeting_action_text_v18150($from,32),video_meeting_action_text_v18150($to,32),video_meeting_action_text_v18150($summary,500),$metadata?video_meeting_action_json_v18150($metadata):null]);
}

function video_meeting_followthrough_verification_row_v18200(PDO $pdo,int $ownerUserId,int $closureId): ?array
{
    if($ownerUserId<1||$closureId<1)return null;$s=$pdo->prepare('SELECT * FROM video_meeting_followthrough_closures WHERE id=? AND owner_user_id=? LIMIT 1');$s->execute([$closureId,$ownerUserId]);$row=$s->fetch();return is_array($row)?$row:null;
}

function video_meeting_followthrough_verification_row_for_monitor_v18200(PDO $pdo,int $ownerUserId,int $monitorId): ?array
{
    if($ownerUserId<1||$monitorId<1)return null;$s=$pdo->prepare('SELECT * FROM video_meeting_followthrough_closures WHERE monitor_id=? AND owner_user_id=? LIMIT 1');$s->execute([$monitorId,$ownerUserId]);$row=$s->fetch();return is_array($row)?$row:null;
}

function video_meeting_followthrough_verification_criteria_v18200(PDO $pdo,array $execution): array
{
    $owner=(int)$execution['owner_user_id'];$executionId=(int)$execution['id'];$itemId=(int)$execution['agenda_item_id'];
    if(table_exists('video_meeting_plan_action_handoffs')){
        $s=$pdo->prepare('SELECT id,plan_id,plan_snapshot_json FROM video_meeting_plan_action_handoffs WHERE action_execution_id=? AND owner_user_id=? LIMIT 1');$s->execute([$executionId,$owner]);$handoff=$s->fetch();
        if(is_array($handoff)){
            $snapshot=video_meeting_action_decode_v18150($handoff['plan_snapshot_json']??'');$criteria=video_meeting_action_text_v18150($snapshot['verification_criteria']??'',1000);
            if($criteria!=='')return ['criteria'=>$criteria,'source'=>'handoff_snapshot','plan_id'=>(int)($handoff['plan_id']??0),'handoff_id'=>(int)$handoff['id']];
        }
    }
    if(table_exists('video_meeting_followthrough_plans')){
        $s=$pdo->prepare('SELECT id,verification_criteria FROM video_meeting_followthrough_plans WHERE agenda_item_id=? AND owner_user_id=? LIMIT 1');$s->execute([$itemId,$owner]);$plan=$s->fetch();
        if(is_array($plan)){$criteria=video_meeting_action_text_v18150($plan['verification_criteria']??'',1000);if($criteria!=='')return ['criteria'=>$criteria,'source'=>'current_plan','plan_id'=>(int)$plan['id'],'handoff_id'=>0];}
    }
    return ['criteria'=>'','source'=>'none','plan_id'=>0,'handoff_id'=>0];
}

function video_meeting_followthrough_verification_evidence_v18200(PDO $pdo,array $execution): array
{
    $kind=(string)($execution['action_kind']??'');$status=(string)($execution['status']??'');$owner=(int)$execution['owner_user_id'];
    if($status==='failed')return ['state'=>'needs_attention','canonical_status'=>'failed','source'=>'meeting_action','summary'=>video_meeting_action_text_v18150($execution['last_error']??'Meeting Action failed.',900),'details'=>['error_class'=>(string)($execution['error_class']??'')]];
    if($kind==='task'){
        $runId=(int)($execution['workflow_run_id']??0);$run=$runId>0?agent_workflow_row_v1400($pdo,$owner,$runId):null;
        if(!$run)return ['state'=>'needs_attention','canonical_status'=>'missing','source'=>'agent_workflow','summary'=>'Linked Agent work item is unavailable.','details'=>['workflow_run_id'=>$runId]];
        $runStatus=(string)$run['status'];
        if($runStatus==='completed')return ['state'=>'available','canonical_status'=>'completed','source'=>'agent_workflow','summary'=>'Linked Agent work item completed in the canonical workflow system.','details'=>['workflow_run_id'=>$runId,'workflow_status'=>$runStatus]];
        if(in_array($runStatus,['failed','cancelled'],true))return ['state'=>'needs_attention','canonical_status'=>$runStatus,'source'=>'agent_workflow','summary'=>'Linked Agent work item is '.$runStatus.'.','details'=>['workflow_run_id'=>$runId,'workflow_status'=>$runStatus]];
        return ['state'=>'waiting','canonical_status'=>$runStatus,'source'=>'agent_workflow','summary'=>'Linked Agent work item is still '.$runStatus.'.','details'=>['workflow_run_id'=>$runId,'workflow_status'=>$runStatus]];
    }
    if($kind==='calendar'){
        $eventId=(int)($execution['calendar_event_id']??0);$event=$eventId>0?user_calendar_event_v1300($pdo,$owner,$eventId):null;
        if(!$event)return ['state'=>'needs_attention','canonical_status'=>'missing','source'=>'calendar','summary'=>'Linked calendar event is unavailable.','details'=>['calendar_event_id'=>$eventId]];
        $end=(string)($event['end_at_utc']??'');$ended=$end!==''&&(strtotime($end)?:PHP_INT_MAX)<=time();
        return ['state'=>$ended?'available':'waiting','canonical_status'=>$ended?'event_ended':'scheduled','source'=>'calendar','summary'=>$ended?'The linked calendar event has ended; review whether the planned outcome was achieved.':'The linked calendar event has not ended yet.','details'=>['calendar_event_id'=>$eventId,'start_at_utc'=>(string)($event['start_at_utc']??''),'end_at_utc'=>$end]];
    }
    if($kind==='crm'){
        $activityId=(int)($execution['crm_activity_id']??0);$leadId=(int)($execution['crm_lead_id']??0);$s=$pdo->prepare('SELECT id,created_at FROM crm_activities WHERE id=? AND lead_id=? LIMIT 1');$s->execute([$activityId,$leadId]);$row=$s->fetch();
        if(!is_array($row))return ['state'=>'needs_attention','canonical_status'=>'missing','source'=>'crm','summary'=>'Linked CRM follow-up activity is unavailable.','details'=>['crm_activity_id'=>$activityId,'crm_lead_id'=>$leadId]];
        return ['state'=>'available','canonical_status'=>'activity_present','source'=>'crm','summary'=>'The linked CRM follow-up activity exists; review whether it satisfies the planned verification criterion.','details'=>['crm_activity_id'=>$activityId,'crm_lead_id'=>$leadId,'created_at'=>(string)($row['created_at']??'')]];
    }
    if($kind==='email'){
        if((string)($execution['error_class']??'')==='delivery_uncertain')return ['state'=>'needs_attention','canonical_status'=>'delivery_uncertain','source'=>'email','summary'=>'Email delivery is uncertain, so the intended outcome cannot be verified from VP3 evidence.','details'=>[]];
        $result=video_meeting_action_decode_v18150($execution['result_json']??'');$delivery=(string)($result['delivery']??'');
        if(in_array($status,['executed','completed'],true)&&($delivery==='accepted_by_mail_transport'||(string)($execution['result_summary']??'')!==''))return ['state'=>'available','canonical_status'=>'transport_accepted','source'=>'email','summary'=>'The approved email was accepted by the configured mail transport. This confirms sending, not the recipient outcome.','details'=>['delivery'=>$delivery?:'accepted_by_mail_transport']];
        return ['state'=>'waiting','canonical_status'=>$status,'source'=>'email','summary'=>'Waiting for a confirmed email execution receipt.','details'=>[]];
    }
    if($status==='completed')return ['state'=>'available','canonical_status'=>'completed','source'=>'meeting_action','summary'=>'Meeting Action is marked completed; review the intended verification criterion before closure.','details'=>[]];
    return ['state'=>'waiting','canonical_status'=>$status,'source'=>'meeting_action','summary'=>'Waiting for canonical evidence that the action reached a reviewable result.','details'=>[]];
}

function video_meeting_followthrough_verification_assessment_v18200(string $criteria,array $evidence): array
{
    if($criteria==='')return ['status'=>'needs_attention','suggestion'=>'define_verification_criteria','rationale'=>'This action has no recorded verification criterion. Define what success means before closing the outcome.'];
    $state=(string)($evidence['state']??'waiting');
    if($state==='needs_attention')return ['status'=>'needs_attention','suggestion'=>'resolve_canonical_issue','rationale'=>'The canonical result has a missing or failed dependency. Resolve that issue before treating the intended outcome as achieved.'];
    if($state==='available')return ['status'=>'evidence_available','suggestion'=>'review_for_verification','rationale'=>'Canonical evidence is consistent with a completed action, but structured evidence alone does not prove that it satisfies the intended criterion: '.$criteria.' Organizer confirmation is required.'];
    return ['status'=>'waiting_for_verification','suggestion'=>'wait_for_evidence','rationale'=>'The canonical action has not produced enough evidence to review the intended criterion yet.'];
}

function video_meeting_followthrough_verification_ensure_v18200(PDO $pdo,array $monitor,array $execution): array
{
    $owner=(int)$execution['owner_user_id'];$monitorId=(int)$monitor['id'];$criteria=video_meeting_followthrough_verification_criteria_v18200($pdo,$execution);$criteriaText=(string)$criteria['criteria'];$criteriaHash=$criteriaText!==''?hash('sha256',$criteriaText):'';
    $row=video_meeting_followthrough_verification_row_for_monitor_v18200($pdo,$owner,$monitorId);
    if(!$row){
        $insert=$pdo->prepare("INSERT IGNORE INTO video_meeting_followthrough_closures (monitor_id,execution_id,plan_id,handoff_id,agenda_item_id,meeting_id,owner_user_id,criteria_snapshot,criteria_hash,criteria_source,status) VALUES (?,?,?,?,?,?,?,?,?,?,'waiting_for_verification')");
        $insert->execute([$monitorId,(int)$execution['id'],(int)$criteria['plan_id']?:null,(int)$criteria['handoff_id']?:null,(int)$execution['agenda_item_id'],(int)$execution['meeting_id'],$owner,$criteriaText,$criteriaHash,(string)$criteria['source']]);
        $created=$insert->rowCount()===1;
        $row=video_meeting_followthrough_verification_row_for_monitor_v18200($pdo,$owner,$monitorId);if(!$row)throw new RuntimeException('Follow-through closure could not be created.');
        if($created)video_meeting_followthrough_verification_event_v18200($pdo,$row,'closure_created','','waiting_for_verification','Outcome verification started from the meeting follow-through plan.',['criteria_source'=>(string)$criteria['source']]);
    }elseif((string)$row['status']!=='verified'&&((string)$row['criteria_hash']!==$criteriaHash||(string)$row['criteria_source']!==(string)$criteria['source']||(int)($row['plan_id']??0)!==(int)$criteria['plan_id']||(int)($row['handoff_id']??0)!==(int)$criteria['handoff_id'])){
        $pdo->prepare('UPDATE video_meeting_followthrough_closures SET plan_id=?,handoff_id=?,criteria_snapshot=?,criteria_hash=?,criteria_source=?,updated_at=NOW() WHERE id=? AND owner_user_id=?')->execute([(int)$criteria['plan_id']?:null,(int)$criteria['handoff_id']?:null,$criteriaText,$criteriaHash,(string)$criteria['source'],(int)$row['id'],$owner]);
        $row=video_meeting_followthrough_verification_row_v18200($pdo,$owner,(int)$row['id'])?:$row;
        video_meeting_followthrough_verification_event_v18200($pdo,$row,'criteria_refreshed',(string)$row['status'],(string)$row['status'],'Verification criteria were refreshed before closure.',['criteria_source'=>(string)$criteria['source']]);
    }
    return $row;
}

function video_meeting_followthrough_verification_reconcile_v18200(PDO $pdo,array $closure,array $execution): array
{
    if((string)$closure['status']==='verified')return $closure;
    $criteria=(string)$closure['criteria_snapshot'];$evidence=video_meeting_followthrough_verification_evidence_v18200($pdo,$execution);$assessment=video_meeting_followthrough_verification_assessment_v18200($criteria,$evidence);$from=(string)$closure['status'];$to=(string)$assessment['status'];
    $evidenceJson=video_meeting_action_json_v18150((array)($evidence['details']??[]));$needsAttentionAt=$to==='needs_attention'?'UTC_TIMESTAMP()':'NULL';
    $pdo->prepare("UPDATE video_meeting_followthrough_closures SET status=?,evidence_json=?,evidence_summary=?,evidence_source=?,evidence_status=?,agent_suggestion=?,agent_rationale=?,evidence_checked_at=UTC_TIMESTAMP(),needs_attention_at=".$needsAttentionAt.",updated_at=NOW() WHERE id=? AND owner_user_id=?")
        ->execute([$to,$evidenceJson,video_meeting_action_text_v18150($evidence['summary']??'',1000),video_meeting_action_text_v18150($evidence['source']??'',60),video_meeting_action_text_v18150($evidence['canonical_status']??'',40),video_meeting_action_text_v18150($assessment['suggestion']??'',40),video_meeting_action_text_v18150($assessment['rationale']??'',1000),(int)$closure['id'],(int)$closure['owner_user_id']]);
    $fresh=video_meeting_followthrough_verification_row_v18200($pdo,(int)$closure['owner_user_id'],(int)$closure['id'])?:$closure;
    if($to!==$from)video_meeting_followthrough_verification_event_v18200($pdo,$fresh,'status_changed',$from,$to,(string)$fresh['evidence_summary'],['evidence_source'=>(string)$fresh['evidence_source'],'evidence_status'=>(string)$fresh['evidence_status']]);
    return $fresh;
}

function video_meeting_followthrough_verification_reconcile_owner_v18200(PDO $pdo,int $ownerUserId,int $limit=VP3_VIDEO_MEETINGS_VERIFICATION_WINDOW_V18200): int
{
    if($ownerUserId<1||!video_meeting_followthrough_verification_schema_ready_v18200($pdo))return 0;$limit=max(1,min(200,$limit));
    video_meeting_followthrough_reconcile_owner_v18160($pdo,$ownerUserId,$limit);
    $s=$pdo->prepare("SELECT m.*,e.agenda_item_id,e.action_kind,e.status AS execution_status,e.workflow_run_id,e.calendar_event_id,e.crm_activity_id,e.crm_lead_id,e.error_class,e.last_error,e.result_json,e.result_summary,e.updated_at AS execution_updated_at FROM video_meeting_followthrough_monitors m JOIN video_meeting_action_executions e ON e.id=m.execution_id WHERE m.owner_user_id=? ORDER BY m.updated_at DESC,m.id DESC LIMIT ".$limit);$s->execute([$ownerUserId]);$count=0;
    foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$execution=['id'=>(int)$row['execution_id'],'agenda_item_id'=>(int)$row['agenda_item_id'],'meeting_id'=>(int)$row['meeting_id'],'owner_user_id'=>$ownerUserId,'action_kind'=>(string)$row['action_kind'],'status'=>(string)$row['execution_status'],'workflow_run_id'=>(int)($row['workflow_run_id']??0),'calendar_event_id'=>(int)($row['calendar_event_id']??0),'crm_activity_id'=>(int)($row['crm_activity_id']??0),'crm_lead_id'=>(int)($row['crm_lead_id']??0),'error_class'=>(string)($row['error_class']??''),'last_error'=>(string)($row['last_error']??''),'result_json'=>(string)($row['result_json']??''),'result_summary'=>(string)($row['result_summary']??''),'updated_at'=>(string)($row['execution_updated_at']??'')];$closure=video_meeting_followthrough_verification_ensure_v18200($pdo,$row,$execution);video_meeting_followthrough_verification_reconcile_v18200($pdo,$closure,$execution);$count++;}
    return $count;
}
