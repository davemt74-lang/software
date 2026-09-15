<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.3 — Agent Work Control.
 *
 * Chat is the owner-facing control surface; Phase 14/19 workflow and durable
 * job ledgers remain authoritative for execution. All controls are owner-scoped,
 * explicit, event-audited, and fail closed when the control schema is absent.
 */
const VP3_AGENT_WORK_CONTROL_V173='agent-work-control-v173-20260915';
const VP3_AGENT_WORK_PRIORITY_NORMAL_V173=50;

require_once __DIR__.'/agent-workflow-runs-v1400.php';
require_once __DIR__.'/agent-job-engine-v1900.php';

function agent_work_control_schema_ready_v173(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!agent_job_engine_schema_ready_v1900($pdo))return false;
    foreach(['work_priority','pause_requested_at','paused_at','paused_from_status'] as $column){
        if(!column_exists('agent_workflow_runs',$column))return false;
    }
    return true;
}

function agent_work_control_index_exists_v173(PDO $pdo,string $index): bool
{
    $stmt=$pdo->prepare("SHOW INDEX FROM agent_workflow_runs WHERE Key_name=?");
    $stmt->execute([$index]);
    return (bool)$stmt->fetch();
}

function agent_work_control_ensure_schema_v173(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_job_engine_ensure_schema_v1900($pdo);
    if(!column_exists('agent_workflow_runs','work_priority'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN work_priority TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER timeout_seconds");
    if(!column_exists('agent_workflow_runs','pause_requested_at'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN pause_requested_at DATETIME NULL AFTER work_priority");
    if(!column_exists('agent_workflow_runs','paused_at'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN paused_at DATETIME NULL AFTER pause_requested_at");
    if(!column_exists('agent_workflow_runs','paused_from_status'))$pdo->exec("ALTER TABLE agent_workflow_runs ADD COLUMN paused_from_status VARCHAR(32) NOT NULL DEFAULT '' AFTER paused_at");
    if(!agent_work_control_index_exists_v173($pdo,'idx_agent_work_control_due')){
        $pdo->exec("CREATE INDEX idx_agent_work_control_due ON agent_workflow_runs (owner_user_id,status,work_priority,next_attempt_at,id)");
    }
}

function agent_work_control_require_v173(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Agent Work Control is not available for this account.');
    if(!agent_work_control_schema_ready_v173($pdo))throw new RuntimeException('Agent Work Control is not installed yet. An administrator needs to run the Phase 17.3 upgrade.');
    return $uid;
}

function agent_work_control_priority_label_v173(int $priority): string
{
    $priority=max(1,min(100,$priority));
    return $priority>=90?'urgent':($priority>=70?'high':($priority<=30?'low':'normal'));
}

function agent_work_control_priority_value_v173(mixed $value): int
{
    if(is_numeric($value))return max(1,min(100,(int)$value));
    $value=mb_strtolower(trim((string)$value));
    return match(true){
        str_contains($value,'urgent'),str_contains($value,'critical')=>100,
        str_contains($value,'high'),str_contains($value,'prioritize')=>75,
        str_contains($value,'low'),str_contains($value,'deprioritize')=>25,
        default=>VP3_AGENT_WORK_PRIORITY_NORMAL_V173,
    };
}

function agent_work_control_allowed_v173(array $row): array
{
    $status=(string)($row['status']??'');
    $pauseRequested=trim((string)($row['pause_requested_at']??''))!=='';
    $closed=in_array($status,['completed','cancelled'],true);
    return [
        'approve'=>$status==='approval_pending',
        'pause'=>in_array($status,['approved','executing'],true)&&!$pauseRequested,
        'resume'=>$status==='paused',
        'cancel'=>!$closed,
        'retry'=>$status==='failed',
        'reschedule'=>in_array($status,['approved','paused'],true),
        'priority'=>!$closed,
        'inspect'=>true,
    ];
}

function agent_work_control_public_run_v173(PDO $pdo,array $row,bool $history=false): array
{
    $run=agent_job_public_run_v1900($pdo,$row,$history);
    $priority=max(1,min(100,(int)($row['work_priority']??VP3_AGENT_WORK_PRIORITY_NORMAL_V173)));
    $run['work_control_build']=VP3_AGENT_WORK_CONTROL_V173;
    $run['work_priority']=$priority;
    $run['work_priority_label']=agent_work_control_priority_label_v173($priority);
    $run['pause_requested_at']=(string)($row['pause_requested_at']??'');
    $run['paused_at']=(string)($row['paused_at']??'');
    $run['paused_from_status']=(string)($row['paused_from_status']??'');
    $run['controls']=agent_work_control_allowed_v173($row);
    return $run;
}

function agent_work_control_owned_row_v173(PDO $pdo,array $user,int $runId,bool $forUpdate=false): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    if($runId<1)throw new RuntimeException('A workflow number is required.');
    $row=agent_workflow_row_v1400($pdo,$uid,$runId,$forUpdate);
    if(!$row)throw new RuntimeException('Workflow not found.');
    return $row;
}

function agent_work_control_pause_v173(PDO $pdo,array $user,int $runId): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    try{
        $pdo->beginTransaction();
        $row=agent_workflow_row_v1400($pdo,$uid,$runId,true);
        if(!$row)throw new RuntimeException('Workflow not found.');
        $status=(string)$row['status'];
        if($status==='executing'){
            if(trim((string)($row['pause_requested_at']??''))!=='')throw new RuntimeException('A pause is already requested for this workflow.');
            $pdo->prepare("UPDATE agent_workflow_runs SET pause_requested_at=UTC_TIMESTAMP(),progress_message='Pause requested — finishing current action' WHERE id=? AND owner_user_id=? AND status='executing'")->execute([$runId,$uid]);
            agent_workflow_event_v1400($pdo,$uid,$runId,'pause_requested','executing','executing','user','User requested a safe pause after the current leased action.');
        }elseif($status==='approved'){
            $pdo->prepare("UPDATE agent_workflow_runs SET status='paused',paused_from_status='approved',paused_at=UTC_TIMESTAMP(),pause_requested_at=NULL,progress_message='Paused by user' WHERE id=? AND owner_user_id=? AND status='approved'")->execute([$runId,$uid]);
            agent_workflow_event_v1400($pdo,$uid,$runId,'paused','approved','paused','user','User paused the workflow before the next action was claimed.');
        }elseif($status==='paused'){
            throw new RuntimeException('This workflow is already paused.');
        }elseif($status==='approval_pending'){
            throw new RuntimeException('This workflow is waiting for approval. Approve, cancel, or leave it pending.');
        }else{
            throw new RuntimeException('This workflow cannot be paused in its current state.');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    if($row)agent_job_brain_memory_v1900($user,$row,'pause');
    return $row?agent_work_control_public_run_v173($pdo,$row,true):[];
}

function agent_work_control_resume_v173(PDO $pdo,array $user,int $runId): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    try{
        $pdo->beginTransaction();
        $row=agent_workflow_row_v1400($pdo,$uid,$runId,true);
        if(!$row)throw new RuntimeException('Workflow not found.');
        if((string)$row['status']!=='paused')throw new RuntimeException('Only a paused workflow can be resumed.');
        $future=(strtotime((string)($row['next_attempt_at']??''))?:0)>time();
        $message=$future?'Resumed · waiting for scheduled time':'Resumed and ready';
        $pdo->prepare("UPDATE agent_workflow_runs SET status='approved',pause_requested_at=NULL,paused_at=NULL,paused_from_status='',progress_message=? WHERE id=? AND owner_user_id=? AND status='paused'")->execute([$message,$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'resumed','paused','approved','user',$future?'User resumed the workflow; its existing schedule is preserved.':'User resumed the workflow and returned it to the durable queue.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    if($row)agent_job_brain_memory_v1900($user,$row,'resume');
    return $row?agent_work_control_public_run_v173($pdo,$row,true):[];
}

function agent_work_control_cancel_v173(PDO $pdo,array $user,int $runId): array
{
    agent_work_control_require_v173($pdo,$user);
    $run=agent_job_cancel_v1900($pdo,$user,$runId,'user');
    $uid=(int)$user['id'];
    $pdo->prepare("UPDATE agent_workflow_runs SET pause_requested_at=NULL,paused_at=NULL,paused_from_status='' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    return $row?agent_work_control_public_run_v173($pdo,$row,true):$run;
}

function agent_work_control_retry_v173(PDO $pdo,array $user,int $runId): array
{
    agent_work_control_require_v173($pdo,$user);
    $run=agent_job_retry_v1900($pdo,$user,$runId);
    $uid=(int)$user['id'];
    $pdo->prepare("UPDATE agent_workflow_runs SET pause_requested_at=NULL,paused_at=NULL,paused_from_status='' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    return $row?agent_work_control_public_run_v173($pdo,$row,true):$run;
}

function agent_work_control_approve_v173(PDO $pdo,array $user,int $runId): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    agent_workflow_approve_v1400($pdo,$user,$runId);
    $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=UTC_TIMESTAMP(),progress_message='Approved and ready',pause_requested_at=NULL,paused_at=NULL,paused_from_status='' WHERE id=? AND owner_user_id=?")->execute([$runId,$uid]);
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    if($row)agent_job_brain_memory_v1900($user,$row,'approved');
    return $row?agent_work_control_public_run_v173($pdo,$row,true):[];
}

function agent_work_control_timezone_v173(PDO $pdo,array $user): string
{
    try{
        if(function_exists('user_calendar_schema_ready_v1300')&&user_calendar_schema_ready_v1300($pdo)&&function_exists('user_calendar_default_timezone_v1300')){
            $tz=(string)user_calendar_default_timezone_v1300($pdo,$user);
            if($tz!==''&&in_array($tz,DateTimeZone::listIdentifiers(),true))return $tz;
        }
    }catch(Throwable $e){}
    return 'UTC';
}

function agent_work_control_parse_schedule_v173(PDO $pdo,array $user,string $value): array
{
    $value=trim($value);
    if($value==='')throw new RuntimeException('Tell me when the workflow should run.');
    $timezone=agent_work_control_timezone_v173($pdo,$user);
    try{
        $local=new DateTimeImmutable($value,new DateTimeZone($timezone));
    }catch(Throwable $e){throw new RuntimeException('I could not understand that schedule time. Try something like “tomorrow at 9 AM” or “2026-09-16 14:30”.');}
    $utc=$local->setTimezone(new DateTimeZone('UTC'));
    if($utc->getTimestamp()<=time()+5)throw new RuntimeException('The rescheduled time needs to be in the future.');
    return ['utc'=>$utc->format('Y-m-d H:i:s'),'local'=>$local->format('D M j, Y · g:i A'),'timezone'=>$timezone];
}

function agent_work_control_reschedule_v173(PDO $pdo,array $user,int $runId,string $when): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    $schedule=agent_work_control_parse_schedule_v173($pdo,$user,$when);
    try{
        $pdo->beginTransaction();
        $row=agent_workflow_row_v1400($pdo,$uid,$runId,true);
        if(!$row)throw new RuntimeException('Workflow not found.');
        $status=(string)$row['status'];
        if(!in_array($status,['approved','paused'],true))throw new RuntimeException('Only queued, scheduled, or paused work can be rescheduled.');
        $pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at=?,progress_message=? WHERE id=? AND owner_user_id=?")->execute([$schedule['utc'],$status==='paused'?'Paused · rescheduled':'Scheduled by user',$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'rescheduled',$status,$status,'user','User rescheduled the workflow.',['next_attempt_at'=>$schedule['utc'],'timezone'=>$schedule['timezone']]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    if($row)agent_job_brain_memory_v1900($user,$row,'rescheduled');
    $run=$row?agent_work_control_public_run_v173($pdo,$row,true):[];
    $run['scheduled_local']=$schedule['local'];
    $run['scheduled_timezone']=$schedule['timezone'];
    return $run;
}

function agent_work_control_priority_v173(PDO $pdo,array $user,int $runId,mixed $priority): array
{
    $uid=agent_work_control_require_v173($pdo,$user);
    $value=agent_work_control_priority_value_v173($priority);
    try{
        $pdo->beginTransaction();
        $row=agent_workflow_row_v1400($pdo,$uid,$runId,true);
        if(!$row)throw new RuntimeException('Workflow not found.');
        if(in_array((string)$row['status'],['completed','cancelled'],true))throw new RuntimeException('Closed workflows cannot be reprioritized.');
        $old=max(1,min(100,(int)($row['work_priority']??VP3_AGENT_WORK_PRIORITY_NORMAL_V173)));
        $pdo->prepare("UPDATE agent_workflow_runs SET work_priority=? WHERE id=? AND owner_user_id=?")->execute([$value,$runId,$uid]);
        agent_workflow_event_v1400($pdo,$uid,$runId,'priority_changed',(string)$row['status'],(string)$row['status'],'user','User changed durable work priority.',['from'=>$old,'to'=>$value,'label'=>agent_work_control_priority_label_v173($value)]);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_workflow_row_v1400($pdo,$uid,$runId);
    if($row)agent_job_brain_memory_v1900($user,$row,'priority');
    return $row?agent_work_control_public_run_v173($pdo,$row,true):[];
}

function agent_work_control_inspect_v173(PDO $pdo,array $user,int $runId): array
{
    $row=agent_work_control_owned_row_v173($pdo,$user,$runId,false);
    return agent_work_control_public_run_v173($pdo,$row,true);
}

function agent_work_control_extract_run_id_v173(string $query): int
{
    if(preg_match('/\b(?:workflow|work|job|run)\s*#?\s*(\d{1,12})\b/i',$query,$m))return max(0,(int)$m[1]);
    if(preg_match('/#(\d{1,12})\b/',$query,$m))return max(0,(int)$m[1]);
    return 0;
}

function agent_work_control_command_v173(string $query): array
{
    $trim=trim($query);$q=mb_strtolower($trim);$runId=agent_work_control_extract_run_id_v173($trim);
    if($runId<1)return ['action'=>'','run_id'=>0,'value'=>''];
    $prefix=preg_replace('/^(?:please\s+|can you\s+|could you\s+|would you\s+)?/i','',$trim)??$trim;
    $lowerPrefix=mb_strtolower($prefix);
    $question=(bool)preg_match('/\b(?:should|what if|how do|explain|tell me whether|would it)\b/i',$trim);

    if(!$question&&preg_match('/^(?:approve|authorize)\b/i',$lowerPrefix))return ['action'=>'approve','run_id'=>$runId,'value'=>''];
    if(!$question&&preg_match('/^(?:pause|hold)\b/i',$lowerPrefix))return ['action'=>'pause','run_id'=>$runId,'value'=>''];
    if(!$question&&preg_match('/^(?:resume|continue|unpause)\b/i',$lowerPrefix))return ['action'=>'resume','run_id'=>$runId,'value'=>''];
    if(!$question&&preg_match('/^(?:cancel|abort)\b/i',$lowerPrefix))return ['action'=>'cancel','run_id'=>$runId,'value'=>''];
    if(!$question&&preg_match('/^(?:retry|rerun|try\s+again)\b/i',$lowerPrefix))return ['action'=>'retry','run_id'=>$runId,'value'=>''];
    if(!$question&&preg_match('/^(?:reschedule|schedule|move)\b/i',$lowerPrefix)){
        $value='';
        if(preg_match('/\b(?:for|to)\s+(.+)$/i',$trim,$m))$value=trim($m[1]);
        elseif(preg_match('/\bat\s+(.+)$/i',$trim,$m))$value=trim($m[1]);
        return ['action'=>'reschedule','run_id'=>$runId,'value'=>$value];
    }
    if(!$question&&preg_match('/^(?:set|make|prioritize|deprioritize)\b/i',$lowerPrefix)&&preg_match('/\b(?:priority|urgent|high|normal|low|deprioritize|prioritize)\b/i',$trim)){
        $value='normal';
        if(preg_match('/\b(urgent|critical|high|normal|low)\b/i',$trim,$m))$value=mb_strtolower($m[1]);
        elseif(str_contains($q,'deprioritize'))$value='low';
        elseif(str_contains($q,'prioritize'))$value='high';
        return ['action'=>'priority','run_id'=>$runId,'value'=>$value];
    }
    if(preg_match('/\b(?:inspect|status|result|results|receipt|receipts|what happened|show|details|detail)\b/i',$trim))return ['action'=>'inspect','run_id'=>$runId,'value'=>''];
    return ['action'=>'','run_id'=>0,'value'=>''];
}

function agent_work_control_summary_v173(array $run,string $action='inspect'): string
{
    $id=(int)($run['id']??0);$title=trim((string)($run['title']??'Agent work'));$status=(string)($run['status']??'');$priority=(string)($run['work_priority_label']??'normal');
    $next=trim((string)($run['next_attempt_at']??''));$progress=max(0,min(100,(int)($run['progress_percent']??0)));$message=trim((string)($run['progress_message']??''));
    $lead=match($action){
        'approve'=>'Approved workflow #'.$id.'.',
        'pause'=>$status==='executing'?'Pause requested for workflow #'.$id.'; it will stop safely after the current action.':'Paused workflow #'.$id.'.',
        'resume'=>'Resumed workflow #'.$id.'.',
        'cancel'=>'Cancelled workflow #'.$id.'.',
        'retry'=>'Retry requested for workflow #'.$id.'.',
        'reschedule'=>'Rescheduled workflow #'.$id.'.',
        'priority'=>'Updated workflow #'.$id.' to '.$priority.' priority.',
        default=>'Workflow #'.$id.' — '.$title.'.',
    };
    $parts=[$lead,'Status: '.$status.'.'];
    if($action==='inspect')$parts[]='Priority: '.$priority.' ('.(int)($run['work_priority']??50).').';
    if($progress>0)$parts[]='Progress: '.$progress.'%.';
    if($message!=='')$parts[]=$message.'.';
    if($next!=='')$parts[]='Next attempt: '.$next.' UTC.';
    $receipts=is_array($run['receipts']??null)?$run['receipts']:[];
    if($action==='inspect'&&$receipts){$latest=$receipts[0];$parts[]='Latest receipt: '.((string)($latest['status']??'')).($latest['summary']??''?' — '.(string)$latest['summary']:'').'.';}
    return trim(implode(' ',$parts));
}

function agent_work_control_chat_v173(string $query,array $user,int $conversationId=0): array
{
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $command=agent_work_control_command_v173($query);
    if(($command['action']??'')==='')return $empty;
    $pdo=db();
    if(!$pdo)return ['handled'=>true,'answer'=>'Agent Work Control is temporarily unavailable because the database is unavailable.','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $runId=(int)$command['run_id'];$action=(string)$command['action'];
    try{
        $run=match($action){
            'approve'=>agent_work_control_approve_v173($pdo,$user,$runId),
            'pause'=>agent_work_control_pause_v173($pdo,$user,$runId),
            'resume'=>agent_work_control_resume_v173($pdo,$user,$runId),
            'cancel'=>agent_work_control_cancel_v173($pdo,$user,$runId),
            'retry'=>agent_work_control_retry_v173($pdo,$user,$runId),
            'reschedule'=>agent_work_control_reschedule_v173($pdo,$user,$runId,(string)($command['value']??'')),
            'priority'=>agent_work_control_priority_v173($pdo,$user,$runId,(string)($command['value']??'normal')),
            default=>agent_work_control_inspect_v173($pdo,$user,$runId),
        };
        $answer=agent_work_control_summary_v173($run,$action);
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_work_control_v173',$query,'completed',['action'=>$action,'run_id'=>$runId,'status'=>(string)($run['status']??'')],$conversationId>0?$conversationId:null);
        return ['handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-workflow:'.$runId,'title'=>'Agent Work Queue #'.$runId]]];
    }catch(RuntimeException $e){
        if(function_exists('agent_tool_log'))agent_tool_log($user,'agent_work_control_v173',$query,'blocked',['action'=>$action,'run_id'=>$runId,'error'=>$e->getMessage()],$conversationId>0?$conversationId:null);
        return ['handled'=>true,'answer'=>$e->getMessage(),'stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[['source'=>'agent-workflow:'.$runId,'title'=>'Agent Work Queue #'.$runId]]];
    }
}
