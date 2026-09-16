<?php
declare(strict_types=1);

function video_meeting_followthrough_intelligence_schema_ready_v18160(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach(['video_meeting_followthrough_monitors','video_meeting_followthrough_events'] as $table){if(!table_exists($table))return false;}
    foreach(['execution_id','status','expected_by_at','canonical_status','evidence_summary','verification_source','last_checked_at'] as $column){
        if(!column_exists('video_meeting_followthrough_monitors',$column))return false;
    }
    return true;
}

function video_meeting_followthrough_intelligence_ensure_schema_v18160(?PDO $pdo=null): void
{
    $pdo??=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_action_schema_ready_v18150($pdo))video_meeting_action_ensure_schema_v18150($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_monitors (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      execution_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      status VARCHAR(24) NOT NULL DEFAULT 'watching',
      expected_by_at DATETIME NULL,
      expected_source VARCHAR(40) NOT NULL DEFAULT '',
      canonical_status VARCHAR(40) NOT NULL DEFAULT '',
      evidence_summary VARCHAR(1000) NOT NULL DEFAULT '',
      verification_source VARCHAR(60) NOT NULL DEFAULT '',
      last_checked_at DATETIME NULL,
      verified_at DATETIME NULL,
      blocked_at DATETIME NULL,
      dismissed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_video_meeting_followthrough_execution (execution_id),
      INDEX idx_video_meeting_followthrough_meeting (meeting_id,status,expected_by_at,id),
      INDEX idx_video_meeting_followthrough_owner (owner_user_id,status,expected_by_at,id),
      CONSTRAINT fk_video_meeting_followthrough_execution FOREIGN KEY (execution_id) REFERENCES video_meeting_action_executions(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_followthrough_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      monitor_id BIGINT UNSIGNED NOT NULL,
      meeting_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      event_type VARCHAR(60) NOT NULL,
      from_status VARCHAR(24) NOT NULL DEFAULT '',
      to_status VARCHAR(24) NOT NULL DEFAULT '',
      summary VARCHAR(500) NOT NULL DEFAULT '',
      metadata_json TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_video_meeting_followthrough_event_monitor (monitor_id,id),
      INDEX idx_video_meeting_followthrough_event_owner (owner_user_id,created_at,id),
      CONSTRAINT fk_video_meeting_followthrough_event_monitor FOREIGN KEY (monitor_id) REFERENCES video_meeting_followthrough_monitors(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_event_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE,
      CONSTRAINT fk_video_meeting_followthrough_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_followthrough_event_v18160(PDO $pdo,array $monitor,string $eventType,string $from,string $to,string $summary,array $metadata=[]): void
{
    $pdo->prepare('INSERT INTO video_meeting_followthrough_events (monitor_id,meeting_id,owner_user_id,event_type,from_status,to_status,summary,metadata_json) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([(int)$monitor['id'],(int)$monitor['meeting_id'],(int)$monitor['owner_user_id'],video_meeting_action_text_v18150($eventType,60),video_meeting_action_text_v18150($from,24),video_meeting_action_text_v18150($to,24),video_meeting_action_text_v18150($summary,500),$metadata?video_meeting_action_json_v18150($metadata):null]);
}

function video_meeting_followthrough_monitor_row_v18160(PDO $pdo,int $ownerUserId,int $monitorId): ?array
{
    if($ownerUserId<1||$monitorId<1)return null;$s=$pdo->prepare('SELECT * FROM video_meeting_followthrough_monitors WHERE id=? AND owner_user_id=? LIMIT 1');$s->execute([$monitorId,$ownerUserId]);$row=$s->fetch();return is_array($row)?$row:null;
}

function video_meeting_followthrough_default_expected_v18160(PDO $pdo,array $execution): array
{
    $kind=(string)$execution['action_kind'];$anchor=(string)($execution['executed_at']??$execution['updated_at']??gmdate('Y-m-d H:i:s'));
    $base=strtotime($anchor)?:time();
    if($kind==='calendar'&&(int)($execution['calendar_event_id']??0)>0){
        $event=user_calendar_event_v1300($pdo,(int)$execution['owner_user_id'],(int)$execution['calendar_event_id']);
        $end=trim((string)($event['end_at_utc']??''));if($end!=='')return ['expected_by_at'=>$end,'source'=>'calendar_event_end'];
    }
    $seconds=$kind==='task'?72*3600:48*3600;
    return ['expected_by_at'=>gmdate('Y-m-d H:i:s',$base+$seconds),'source'=>$kind==='task'?'system_72h':'system_48h'];
}

function video_meeting_followthrough_monitor_ensure_v18160(PDO $pdo,array $execution): array
{
    $owner=(int)$execution['owner_user_id'];$executionId=(int)$execution['id'];
    $s=$pdo->prepare('SELECT * FROM video_meeting_followthrough_monitors WHERE execution_id=? AND owner_user_id=? LIMIT 1');$s->execute([$executionId,$owner]);$row=$s->fetch();if(is_array($row))return $row;
    $expected=video_meeting_followthrough_default_expected_v18160($pdo,$execution);
    $pdo->prepare("INSERT IGNORE INTO video_meeting_followthrough_monitors (execution_id,meeting_id,owner_user_id,status,expected_by_at,expected_source,canonical_status,last_checked_at) VALUES (?,?,?,'watching',?,?,?,UTC_TIMESTAMP())")
        ->execute([$executionId,(int)$execution['meeting_id'],$owner,$expected['expected_by_at'],$expected['source'],(string)$execution['status']]);
    $s->execute([$executionId,$owner]);$row=$s->fetch();if(!is_array($row))throw new RuntimeException('Follow-through monitor could not be created.');
    video_meeting_followthrough_event_v18160($pdo,$row,'monitor_created','','watching','Follow-through monitoring started.',['expected_source'=>$expected['source'],'expected_by_at'=>$expected['expected_by_at']]);
    return $row;
}

function video_meeting_followthrough_canonical_signal_v18160(PDO $pdo,array $execution): array
{
    $kind=(string)$execution['action_kind'];$status=(string)$execution['status'];
    if($status==='completed')return ['terminal'=>'verified','canonical_status'=>'completed','summary'=>'Meeting Action is marked completed.','source'=>'meeting_action'];
    if($status==='failed')return ['terminal'=>'blocked','canonical_status'=>'failed','summary'=>video_meeting_action_text_v18150($execution['last_error']??'Meeting Action failed.',900),'source'=>'meeting_action'];
    if($kind==='task'){
        $runId=(int)($execution['workflow_run_id']??0);$run=$runId>0?agent_workflow_row_v1400($pdo,(int)$execution['owner_user_id'],$runId):null;
        if(!$run)return ['terminal'=>'blocked','canonical_status'=>'missing','summary'=>'Linked Agent work item is unavailable.','source'=>'agent_workflow'];
        $runStatus=(string)$run['status'];
        if($runStatus==='completed')return ['terminal'=>'verified','canonical_status'=>$runStatus,'summary'=>'Linked Agent work item completed.','source'=>'agent_workflow'];
        if(in_array($runStatus,['failed','cancelled'],true))return ['terminal'=>'blocked','canonical_status'=>$runStatus,'summary'=>'Linked Agent work item is '.$runStatus.'.','source'=>'agent_workflow'];
        return ['terminal'=>'','canonical_status'=>$runStatus,'summary'=>'Linked Agent work item is '.$runStatus.'.','source'=>'agent_workflow'];
    }
    if($kind==='calendar'){
        $id=(int)($execution['calendar_event_id']??0);$event=$id>0?user_calendar_event_v1300($pdo,(int)$execution['owner_user_id'],$id):null;
        if(!$event)return ['terminal'=>'blocked','canonical_status'=>'missing','summary'=>'Linked calendar event is unavailable.','source'=>'calendar'];
        return ['terminal'=>'','canonical_status'=>'present','summary'=>'Calendar event remains present in the canonical calendar.','source'=>'calendar'];
    }
    if($kind==='crm'){
        $id=(int)($execution['crm_activity_id']??0);$lead=(int)($execution['crm_lead_id']??0);$s=$pdo->prepare('SELECT id FROM crm_activities WHERE id=? AND lead_id=? LIMIT 1');$s->execute([$id,$lead]);
        return $s->fetchColumn()?['terminal'=>'','canonical_status'=>'present','summary'=>'CRM follow-up activity remains present.','source'=>'crm']:['terminal'=>'blocked','canonical_status'=>'missing','summary'=>'Linked CRM activity is unavailable.','source'=>'crm'];
    }
    return ['terminal'=>'','canonical_status'=>'sent','summary'=>'Approved follow-up email was accepted by the configured mail transport.','source'=>'email'];
}

function video_meeting_followthrough_reconcile_monitor_v18160(PDO $pdo,array $monitor,array $execution): array
{
    if((string)$monitor['status']==='dismissed')return $monitor;
    $signal=video_meeting_followthrough_canonical_signal_v18160($pdo,$execution);$from=(string)$monitor['status'];$to=$from;
    if($signal['terminal']==='verified')$to='verified';
    elseif($signal['terminal']==='blocked')$to='blocked';
    else{
        $expected=strtotime((string)($monitor['expected_by_at']??''))?:0;$now=time();
        if($expected>0&&$now>=$expected)$to='overdue';
        elseif($expected>0&&$expected-$now<=86400)$to='due_soon';
        else $to='watching';
    }
    $verifiedAt=$to==='verified'?'UTC_TIMESTAMP()':'verified_at';$blockedAt=$to==='blocked'?'UTC_TIMESTAMP()':'blocked_at';
    $sql="UPDATE video_meeting_followthrough_monitors SET status=?,canonical_status=?,evidence_summary=?,verification_source=?,last_checked_at=UTC_TIMESTAMP(),verified_at=".$verifiedAt.",blocked_at=".$blockedAt.",updated_at=NOW() WHERE id=? AND owner_user_id=?";
    $pdo->prepare($sql)->execute([$to,video_meeting_action_text_v18150($signal['canonical_status'],40),video_meeting_action_text_v18150($signal['summary'],1000),video_meeting_action_text_v18150($signal['source'],60),(int)$monitor['id'],(int)$monitor['owner_user_id']]);
    $fresh=video_meeting_followthrough_monitor_row_v18160($pdo,(int)$monitor['owner_user_id'],(int)$monitor['id'])?:$monitor;
    if($to!==$from)video_meeting_followthrough_event_v18160($pdo,$fresh,'status_changed',$from,$to,(string)$signal['summary'],['canonical_status'=>$signal['canonical_status'],'verification_source'=>$signal['source']]);
    return $fresh;
}

function video_meeting_followthrough_reconcile_owner_v18160(PDO $pdo,int $ownerUserId,int $limit=VP3_VIDEO_MEETINGS_FOLLOWTHROUGH_WINDOW_V18160): int
{
    if($ownerUserId<1||!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo))return 0;$limit=max(1,min(200,$limit));
    $s=$pdo->prepare("SELECT * FROM video_meeting_action_executions WHERE owner_user_id=? AND status IN ('executed','completed','failed') ORDER BY updated_at DESC,id DESC LIMIT ".$limit);$s->execute([$ownerUserId]);$count=0;
    foreach($s->fetchAll()?:[] as $execution){
        if(!is_array($execution))continue;
        if((string)($execution['action_kind']??'')==='task'&&(string)($execution['status']??'')==='executed')$execution=video_meeting_action_refresh_task_v18150($pdo,$execution);
        $monitor=video_meeting_followthrough_monitor_ensure_v18160($pdo,$execution);video_meeting_followthrough_reconcile_monitor_v18160($pdo,$monitor,$execution);$count++;
    }
    return $count;
}

function video_meeting_followthrough_public_monitor_v18160(array $row): array
{
    return [
        'id'=>(int)$row['id'],'execution_id'=>(int)$row['execution_id'],'meeting_id'=>(int)$row['meeting_id'],'action_kind'=>(string)$row['action_kind'],'status'=>(string)$row['status'],
        'agenda_text'=>video_meeting_action_text_v18150($row['item_text']??'',1000),'expected_by_at'=>(string)($row['expected_by_at']??''),'expected_source'=>(string)$row['expected_source'],
        'canonical_status'=>(string)$row['canonical_status'],'evidence_summary'=>(string)$row['evidence_summary'],'verification_source'=>(string)$row['verification_source'],
        'result_summary'=>video_meeting_action_text_v18150($row['result_summary']??'',900),'last_checked_at'=>(string)($row['last_checked_at']??''),'verified_at'=>(string)($row['verified_at']??''),'blocked_at'=>(string)($row['blocked_at']??''),
        'review_path'=>'/meeting.php?meeting='.rawurlencode((string)($row['public_id']??'')),
    ];
}

function video_meeting_followthrough_state_v18160(PDO $pdo,array $meeting,array $user): array
{
    $owner=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$owner);video_meeting_followthrough_reconcile_owner_v18160($pdo,$owner);
    $s=$pdo->prepare("SELECT m.*,e.action_kind,e.result_summary,a.item_text,v.public_id FROM video_meeting_followthrough_monitors m JOIN video_meeting_action_executions e ON e.id=m.execution_id JOIN video_meeting_agenda_items a ON a.id=e.agenda_item_id JOIN video_meetings v ON v.id=m.meeting_id WHERE m.meeting_id=? AND m.owner_user_id=? ORDER BY FIELD(m.status,'blocked','overdue','due_soon','watching','verified','dismissed'),m.expected_by_at,m.id");
    $s->execute([(int)$meeting['id'],$owner]);$items=[];$counts=['watching'=>0,'due_soon'=>0,'overdue'=>0,'blocked'=>0,'verified'=>0,'dismissed'=>0];
    foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$items[]=video_meeting_followthrough_public_monitor_v18160($row);$st=(string)$row['status'];if(isset($counts[$st]))$counts[$st]++;}
    return ['version'=>'v18.16','schema'=>'vp3.meeting.followthrough.intelligence','meeting'=>['id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>(string)$meeting['title'],'timezone'=>(string)($meeting['timezone']??'UTC')],'items'=>$items,'counts'=>$counts,'policy'=>['external_side_effects'=>false,'canonical_verification'=>true,'task_auto_verification'=>true,'editable_followthrough_target'=>true],'generated_at'=>gmdate('c')];
}

function video_meeting_followthrough_expected_v18160(PDO $pdo,array $meeting,array $user,int $monitorId,string $localValue): array
{
    $owner=(int)($user['id']??0);$monitor=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId);if(!$monitor||(int)$monitor['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Follow-through item not found.');
    if(in_array((string)$monitor['status'],['verified','dismissed'],true))throw new RuntimeException('Verified or dismissed follow-through items do not accept a new target.');
    $value=trim($localValue);if($value==='')throw new RuntimeException('Choose a follow-through target date and time.');
    try{$tz=new DateTimeZone((string)($meeting['timezone']??'UTC'));$dt=(new DateTimeImmutable($value,$tz))->setTimezone(new DateTimeZone('UTC'));}catch(Throwable $e){throw new RuntimeException('Choose a valid follow-through target.');}
    $from=(string)$monitor['status'];$pdo->prepare("UPDATE video_meeting_followthrough_monitors SET expected_by_at=?,expected_source='organizer',dismissed_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$dt->format('Y-m-d H:i:s'),$monitorId,$owner]);
    $execution=video_meeting_action_row_v18150($pdo,$owner,(int)$monitor['execution_id']);$fresh=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId)?:$monitor;if($execution)$fresh=video_meeting_followthrough_reconcile_monitor_v18160($pdo,$fresh,$execution);
    video_meeting_followthrough_event_v18160($pdo,$fresh,'target_changed',$from,(string)$fresh['status'],'Organizer updated the follow-through target.',['expected_by_at'=>$dt->format('Y-m-d H:i:s')]);
    return $fresh;
}

function video_meeting_followthrough_confirm_v18160(PDO $pdo,array $meeting,array $user,int $monitorId): array
{
    $owner=(int)($user['id']??0);$monitor=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId);if(!$monitor||(int)$monitor['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Follow-through item not found.');
    if((string)$monitor['status']==='dismissed')throw new RuntimeException('Reopen this follow-through monitor before confirming it resolved.');
    if((string)$monitor['status']==='verified')return $monitor;
    $execution=video_meeting_action_row_v18150($pdo,$owner,(int)$monitor['execution_id']);if(!$execution)throw new RuntimeException('Meeting Action execution is unavailable.');
    $signal=video_meeting_followthrough_canonical_signal_v18160($pdo,$execution);
    if((string)($signal['terminal']??'')==='blocked')throw new RuntimeException('This follow-through is blocked by its canonical result and cannot be confirmed resolved yet.');
    if((string)$execution['status']==='executed')video_meeting_action_complete_v18150($pdo,$meeting,$user,(int)$execution['id']);
    $execution=video_meeting_action_row_v18150($pdo,$owner,(int)$execution['id'])?:$execution;$fresh=video_meeting_followthrough_reconcile_monitor_v18160($pdo,$monitor,$execution);
    if((string)$fresh['status']!=='verified')throw new RuntimeException('This follow-through cannot be verified yet.');
    return $fresh;
}

function video_meeting_followthrough_dismiss_v18160(PDO $pdo,array $meeting,array $user,int $monitorId): array
{
    $owner=(int)($user['id']??0);$monitor=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId);if(!$monitor||(int)$monitor['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Follow-through item not found.');$from=(string)$monitor['status'];
    if($from==='verified')throw new RuntimeException('Verified follow-through history cannot be dismissed.');
    if($from==='dismissed')return $monitor;
    $pdo->prepare("UPDATE video_meeting_followthrough_monitors SET status='dismissed',dismissed_at=UTC_TIMESTAMP(),updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$monitorId,$owner]);$fresh=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId)?:$monitor;video_meeting_followthrough_event_v18160($pdo,$fresh,'dismissed',$from,'dismissed','Organizer dismissed this follow-through monitor.');return $fresh;
}

function video_meeting_followthrough_reopen_v18160(PDO $pdo,array $meeting,array $user,int $monitorId): array
{
    $owner=(int)($user['id']??0);$monitor=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId);if(!$monitor||(int)$monitor['meeting_id']!==(int)$meeting['id'])throw new RuntimeException('Follow-through item not found.');if((string)$monitor['status']!=='dismissed')throw new RuntimeException('Only a dismissed follow-through item can be reopened.');
    $pdo->prepare("UPDATE video_meeting_followthrough_monitors SET status='watching',dismissed_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=?")->execute([$monitorId,$owner]);$fresh=video_meeting_followthrough_monitor_row_v18160($pdo,$owner,$monitorId)?:$monitor;$execution=video_meeting_action_row_v18150($pdo,$owner,(int)$monitor['execution_id']);if($execution)$fresh=video_meeting_followthrough_reconcile_monitor_v18160($pdo,$fresh,$execution);video_meeting_followthrough_event_v18160($pdo,$fresh,'reopened','dismissed',(string)$fresh['status'],'Organizer reopened follow-through monitoring.');return $fresh;
}
