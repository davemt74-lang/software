<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-v1.php';
require_once __DIR__.'/agent-worker-cloud-v1910.php';
require_once __DIR__.'/agent-worker-health-v1.php';

/** Resolve an exact private Chat before reading any scoped Initiative policy. */
function vp3_initiative_runtime_context_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    if($agentId<0||$cid<1||(int)($user['id']??0)<1||vp3_participant_scope_v1()!==null){
        throw new RuntimeException('Initiative context is unavailable.',403);
    }
    try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
    catch(PDOException $e){throw $e;}
    catch(RuntimeException $e){throw new RuntimeException('Initiative Agent access changed.',403,$e);}
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
    if(!has_permission('account.access',$live)
        ||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],$agent)){
        throw new RuntimeException('Initiative context is unavailable.',403);
    }
    if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
        throw new RuntimeException('Initiative storage is unavailable.',503);
    }
    return ['user'=>$live,'policy'=>vp3_initiative_policy_v1($live,$agentId)];
}

/** Server-only selector: never release another Agent, policy or job family. */
function vp3_initiative_runtime_runs_v1(PDO $pdo,int $uid,int $agentId,array $policy,bool $pending=false,bool $executingOnly=false,
    bool $savedOnly=false,bool $coordinationOnly=false,bool $claimableOnly=false,?string $opportunityLane=null,bool $failuresOnly=false): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $path)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $states=$executingOnly?" AND r.status='executing'":($pending?" AND r.status IN ('approved','executing')":"");
    if($claimableOnly){
        require_once __DIR__.'/agent-coordination-schedule-v1.php';
        $due=vp3_initiative_job_due_sql_v1($pdo);
        // Keep executing leases in view so the pump can recover expired work
        // and continue distinguishing jobs another tick is already running.
        $states.=" AND ($due OR (r.status='executing' AND r.lease_expires_at IS NOT NULL))";
    }
    if($coordinationOnly||$savedOnly)$states.=" AND (r.source_key LIKE 'coordination:%' OR r.source_key LIKE 'youtube-offer:%' OR r.source_key LIKE 'connected-sites-scan:%')";
    if($savedOnly)$states.=" AND (CAST(".$value('$.coordination_saved')." AS CHAR) IN ('true','1') OR r.source_key LIKE 'youtube-offer:%' OR r.source_key LIKE 'connected-sites-scan:%')";
    if($failuresOnly)$states.=" AND r.status IN ('failed','approved','paused') AND COALESCE(r.last_error_class,'')<>''";
    $order=$pending?'ASC':'DESC';
    $lane=$value('$.opportunity_lane');
    if($opportunityLane!==null)$states.=" AND $lane=?";
    $oldest=vp3_initiative_db_wall_epoch_sql_v1($pdo,'r.created_at');
    $agedCutoff=time()-120;
    $priority=$pending?"CASE
        WHEN ".$value('$.followthrough')." IS NOT NULL THEN 0
        WHEN ($oldest+0)<=$agedCutoff THEN 1
        WHEN r.source_key LIKE 'coordination:%' AND ".$value('$.coordination_route.specialist')."='casual' THEN 9
        WHEN r.source_key LIKE 'connected-sites-scan:%' THEN 9
        WHEN r.source_key LIKE 'coordination:%' AND ".$value('$.coordination_route.specialist')."='media' THEN 4
        WHEN r.source_key LIKE 'coordination:%' AND ".$value('$.coordination_route.specialist')."='curiosity' THEN 5
        WHEN r.source_key LIKE 'coordination:%' AND (".$value('$.coordination_priority')."='high'
            OR ".$value('$.coordination_route.action')." IN ('ask','report')) THEN 2
        WHEN $lane IN ('app_suggestions','research','missions') THEN 3
        WHEN r.source_key LIKE 'coordination:%' THEN 6
        WHEN r.source_key LIKE 'office-entry:%' OR r.source_key LIKE 'office-agent-task:%' THEN 8
        ELSE 7 END, ":'';
    $recentFailure=$failuresOnly?'COALESCE(r.completed_at,r.updated_at) DESC,':'';
    $sql="SELECT r.* FROM agent_workflow_runs r WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
        AND r.source_kind='agent_initiative' AND r.execution_target='cloud'
        AND r.capability_key IN ('initiative.plan','initiative.prepare')
        AND CAST(".$value('$.binding.conversation_id')." AS CHAR)=? AND ".$value('$.binding.revision')."=?
        $states ORDER BY {$recentFailure}{$priority}r.id $order LIMIT 25";
    $stmt=$pdo->prepare($sql);
    $params=[$uid,(string)$agentId,(string)($policy['conversation_id']??0),(string)($policy['revision']??'')];
    if($opportunityLane!==null)$params[]=$opportunityLane;
    $stmt->execute($params);
    return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),static function(array $run)use($policy):bool{
        $payload=json_decode((string)($run['goal']??''),true);
        return is_array($payload)&&($payload['binding']??null)===$policy;
    }));
}

/** Attribute only scoped work, with opportunity planners distinguished from ob1. */
function vp3_initiative_runtime_job_specialist_v1(array $run): ?string
{
    require_once __DIR__.'/agent-initiative-specialist-registry-v1.php';
    $goal=json_decode((string)($run['goal']??''),true);
    if(!is_array($goal))return null;
    if(($goal['tool']??null)==='chat.check_in'){
        require_once __DIR__.'/agent-live-context-v1.php';
        if(vp3_live_context_eyes_visit_ref_v1($goal['primary']??null))return 'eyes';
    }
    return match($goal['opportunity_lane']??null){
        'app_suggestions'=>'app-suggestions','research'=>'research','missions'=>'mission',
        default=>($run['capability_key']??null)==='initiative.plan'?'ob1':vp3_initiative_goal_specialist_v1($goal),
    };
}

/** Translate worker-owned checkpoints and exact job kinds to displayable work. */
function vp3_initiative_runtime_job_phase_v1(array $run): string
{
    return match($run['progress_message']??null){
        'Assessing priorities across specialists.','Coordinating specialist findings.','Checking the plan and assigning eligible next steps.'=>'coordinating',
        'Brainstorming useful opportunities.','Brainstorming a private Canvas concept.'=>'brainstorming',
        'Researching the selected source.'=>'researching',
        'Presenting the verified result.'=>'presenting',
        'Reviewing specialist findings.','Connecting current specialist findings.'=>'reviewing',
        'Analyzing current sources and specialist reports.'=>'analyzing',
        default=>'preparing',
    };
}

/** Retain the exact action and its current attempt, independent of newer preparations. */
function vp3_initiative_runtime_assessment_rows_v1(PDO $pdo,int $owner,int $agentId,array $policy,bool $completedOnly,bool $hasPausedAt,
    ?string $opportunityLane=null): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $value=static function(string $key)use($sqlite):string{
        $sql="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$.$key')";
        return $sqlite?$sql:"JSON_UNQUOTE($sql)";
    };
    $cid=$value('binding.conversation_id');
    $revision=$value('binding.revision');
    $lane=$value('opportunity_lane');
    $laneFilter=$opportunityLane===null?"COALESCE($lane,'')<>'app_suggestions'":"$lane=?";
    $created=vp3_initiative_db_wall_epoch_sql_v1($pdo,'r.created_at');
    $receiptTime=vp3_initiative_db_wall_epoch_sql_v1($pdo,'p.created_at');
    $paused=$hasPausedAt?'r.paused_at':'NULL';
    $filter=$completedOnly?" AND r.status='completed' AND a.status='completed'
        AND a.completed_at IS NOT NULL AND a.completed_at<=UTC_TIMESTAMP()":'';
    $order=$completedOnly?'a.completed_at DESC,r.id DESC':
        "COALESCE(CASE r.status
            WHEN 'completed' THEN a.completed_at WHEN 'failed' THEN a.completed_at
            WHEN 'executing' THEN COALESCE(a.heartbeat_at,a.started_at)
            WHEN 'paused' THEN $paused WHEN 'cancelled' THEN r.cancelled_at
            WHEN 'approved' THEN CASE WHEN r.last_error_class<>'' THEN COALESCE(a.updated_at,r.next_attempt_at) END
            END,r.created_at) DESC,r.id DESC";
    $query=$pdo->prepare("SELECT r.id,r.goal,r.status,r.last_error_class,r.current_action_id,r.next_attempt_at,
        r.lease_expires_at,r.lease_token,r.lease_owner,$paused AS paused_at,r.cancelled_at,$created AS created_epoch,
        a.id AS action_id,a.status AS action_status,a.attempt_count,a.started_at,a.heartbeat_at,a.completed_at,a.result_json,a.error_class,
        p.result_json AS receipt_json,$receiptTime AS receipt_epoch
        FROM agent_workflow_runs r LEFT JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.capability_key='initiative.plan' AND a.execution_target='cloud'
            AND (r.status<>'executing' OR a.id=r.current_action_id)
        LEFT JOIN agent_workflow_receipts p ON p.id=(SELECT c.id FROM agent_workflow_receipts c WHERE c.run_id=r.id AND c.action_id=a.id
            AND c.owner_user_id=r.owner_user_id AND c.status=CASE WHEN a.status='queued' THEN 'retry_scheduled' ELSE a.status END
            AND c.error_class=a.error_class AND c.attempt_no=a.attempt_count AND c.executor='cloud' ORDER BY c.id DESC LIMIT 1)
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
        AND r.source_kind='agent_initiative' AND r.execution_target='cloud' AND r.capability_key='initiative.plan'
        AND CAST($cid AS CHAR)=? AND $revision=? AND $laneFilter $filter ORDER BY $order LIMIT 25");
    $params=[$owner,(string)$agentId,(string)$policy['conversation_id'],$policy['revision']];
    if($opportunityLane!==null)$params[]=$opportunityLane;
    $query->execute($params);
    return array_values(array_filter($query->fetchAll(PDO::FETCH_ASSOC),static function(array $row)use($policy):bool{
        $goal=json_decode((string)$row['goal'],true);
        return ($goal['binding']??null)===$policy;
    }));
}

function vp3_initiative_runtime_assessment_v1(array $row): ?array
{
    $failed=$row['status']==='failed';
    if(!in_array($row['status'],['completed','failed'],true)||$row['action_status']!==$row['status']
        ||$row['error_class']!==($failed?'assessment_unverified':'')||!is_string($row['result_json'])
        ||$row['result_json']!==$row['receipt_json'])return null;
    $completed=agent_job_utc_timestamp_v1900($row['completed_at']??null);
    if($completed<1||$completed>time())return null;
    $at=gmdate(DATE_ATOM,$completed);
    $result=json_decode((string)$row['result_json'],true);
    if($failed){
        $code=$result['assessment_error']??null;
        if(!in_array($code,Vp3InitiativePlanValidationV1::CODES,true))return null;
        $bytes=filter_var($result['assessment_output_bytes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>65536]]);
        $diagnostic=['code'=>$code,'at'=>$at,'run_id'=>(int)$row['id']];
        if($bytes!==false)$diagnostic['output_bytes']=$bytes;
        return $diagnostic;
    }
    $outcome=$result['effect_state']??null;
    $jobs=filter_var($result['jobs']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>8]]);
    if(!in_array($outcome,['idle','existing_assignments','assignments_queued'],true)||$jobs===false
        ||($outcome==='assignments_queued')!==($jobs>0))return null;
    $discarded=filter_var($result['opportunities_discarded']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>8]]);
    $activityOmitted=filter_var($result['activity_sources_omitted']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>8]]);
    $inputs=[];
    foreach(['gmail','planning','tasks','media'] as $family){
        $count=$result['initiative_tool_result']['output']['ob1_inputs'][$family]??null;
        if(is_array($count)&&((is_int($count['sources']??null)&&$count['sources']>0)
            ||(is_int($count['reports']??null)&&$count['reports']>0)))$inputs[]=$family;
    }
    return ['outcome'=>$outcome,'queued'=>$jobs,'at'=>$at,'run_id'=>(int)$row['id']]
        +($discarded!==false?['opportunities_discarded'=>$discarded]:[])
        +($activityOmitted!==false?['activity_sources_omitted'=>$activityOmitted]:[])
        +($inputs?['inputs'=>$inputs]:[]);
}

/** Compare actual UTC event times, using the run ID only to break ties. */
function vp3_initiative_runtime_assessment_order_v1(array $evidence): array
{
    return [$evidence['at'],(int)$evidence['run_id']];
}

/** Assessment progress is separate from the queue of work it may have assigned. */
function vp3_initiative_runtime_assessments_v1(PDO $pdo,int $owner,int $agentId,array $policy,int $now): array
{
    // Pause timestamps came from a separate, optional workflow control migration.
    require_once __DIR__.'/schema-metadata.php';
    $hasPausedAt=schema_metadata_column_exists($pdo,'agent_workflow_runs','paused_at');
    $last=null;
    foreach(vp3_initiative_runtime_assessment_rows_v1($pdo,$owner,$agentId,$policy,true,$hasPausedAt) as $row){
        $last=vp3_initiative_runtime_assessment_v1($row);
        if($last!==null)break;
    }
    $latest=$last===null?null:['run_id'=>$last['run_id'],'status'=>'completed','at'=>$last['at']];
    $latestOrder=$latest!==null?vp3_initiative_runtime_assessment_order_v1($latest):null;
    $error=null;
    foreach(vp3_initiative_runtime_assessment_rows_v1($pdo,$owner,$agentId,$policy,false,$hasPausedAt) as $row){
        $status=$row['status'];
        $reason=$row['last_error_class'];
        $retryAt=$status==='approved'&&in_array($reason,['model_unavailable','lease_expired','public_search_unavailable','source_unavailable'],true)
            ?agent_job_utc_timestamp_v1900($row['next_attempt_at']):0;
        $at=match($status){
            'completed','failed'=>agent_job_utc_timestamp_v1900($row['completed_at']),
            'executing'=>agent_job_utc_timestamp_v1900($row['heartbeat_at']??$row['started_at']),
            'paused'=>agent_job_utc_timestamp_v1900($row['paused_at']),
            'cancelled'=>agent_job_utc_timestamp_v1900($row['cancelled_at']),
            default=>$retryAt>0?(int)$row['receipt_epoch']:0,
        };
        $lease=agent_job_utc_timestamp_v1900($row['lease_expires_at']);
        $at=$at>0&&$at<=$now?$at:(int)$row['created_epoch'];
        $proof=in_array($status,['completed','failed'],true)?vp3_initiative_runtime_assessment_v1($row):null;
        if($status==='failed'&&$proof!==null&&($error===null
            ||vp3_initiative_runtime_assessment_order_v1($proof)>vp3_initiative_runtime_assessment_order_v1($error))){
            $error=$proof;
        }
        $state=match($status){
            'approved','queued'=>$retryAt>0?'retry_scheduled':'queued',
            'executing'=>$lease>$now&&$row['action_status']==='executing'&&!empty($row['lease_token'])&&!empty($row['lease_owner'])&&!empty($row['attempt_count'])
                &&(int)$row['current_action_id']===(int)$row['action_id']?'running':'interrupted',
            'completed'=>$proof!==null?'completed':'unverified',
            'failed','paused','cancelled'=>$status,
            default=>'unverified',
        };
        $event=['run_id'=>(int)$row['id'],'status'=>$state,'at'=>$at>0&&$at<=$now?gmdate(DATE_ATOM,$at):''];
        if($reason!==''&&in_array($state,['failed','retry_scheduled','paused'],true)){
            $event['reason_code']=in_array($reason,['model_unavailable','quota_exhausted','route_unavailable',
                'assessment_sources_changed','assessment_unverified','source_unavailable','lease_expired','public_search_unavailable',
                'cloud_authorization_denied'],true)?$reason:'review_failed';
        }
        if($retryAt>0)$event['retry_at']=gmdate(DATE_ATOM,$retryAt);
        $order=vp3_initiative_runtime_assessment_order_v1($event);
        if($event['at']===''&&$retryAt>0)$order[0]=gmdate(DATE_ATOM,min($retryAt,$now));
        if($latestOrder===null||$order>$latestOrder){$latest=$event;$latestOrder=$order;}
    }
    if($error!==null)$error['is_historical']=$last!==null
        &&vp3_initiative_runtime_assessment_order_v1($error)<vp3_initiative_runtime_assessment_order_v1($last);
    return ['last_assessment'=>$last,'latest_assessment_run'=>$latest,'assessment_error'=>$error];
}

/** Wall-clock dates in receipts/Chat use the database session time zone. */
function vp3_initiative_db_wall_epoch_sql_v1(PDO $pdo,string $column): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"STRFTIME('%s',$column)":"UNIX_TIMESTAMP($column)";
}

/** Older receipts may lack a cause, but the exact run can have metered provider evidence. */
function vp3_initiative_assessment_meter_v1(PDO $pdo,int $owner,int $runId,array $binding): array
{
    $cid=$binding['conversation_id']??null;
    if(!is_int($cid)||$cid<1)return [];
    require_once __DIR__.'/ai-service-usage-v1.php';
    if(!vp3_ai_service_usage_ready_v1($pdo))return [];
    $query=$pdo->prepare("SELECT COUNT(*) AS attempts, COUNT(DISTINCT provider) AS providers, MIN(provider) AS provider,
        SUM(CASE WHEN service_status IN ('unknown','pending') THEN 1 ELSE 0 END) AS unconfirmed,
        SUM(CASE WHEN service_status='rejected' THEN 1 ELSE 0 END) AS rejected,
        SUM(CASE WHEN service_status='completed' THEN 1 ELSE 0 END) AS completed
        FROM ai_execution_ledger
        WHERE user_id=? AND service_key='initiative' AND service_job_key=? AND run_id=?
        AND CAST(COALESCE(agent_id,0) AS CHAR)=? AND conversation_id=? AND is_background=1
        AND service_stage='vp3.initiative-plan.v1' AND service_attempt_key IS NOT NULL");
    $query->execute([$owner,hash('sha256','run:'.$runId),$runId,(string)($binding['agent_id']??0),$cid]);
    $usage=$query->fetch(PDO::FETCH_ASSOC);
    $attempts=(int)($usage['attempts']??0);
    if($attempts===0)return [];
    $status=match(true){
        (int)$usage['unconfirmed']>0=>'unconfirmed',
        (int)$usage['rejected']===$attempts=>'rejected',
        (int)$usage['completed']>0=>'response_recorded',
        default=>'recorded',
    };
    $provider=(int)$usage['providers']===1&&in_array($usage['provider'],['openai','anthropic'],true)?$usage['provider']:null;
    return ['model_usage_state'=>$status]+($provider!==null?['model_provider'=>$provider]:[]);
}

/** Report only bounded, server-owned diagnostics for an already scoped run. */
function vp3_initiative_runtime_failure_v1(PDO $pdo,int $owner,array $run,bool $latest): ?array
{
    $status=$run['status']??null;
    $storedError=$run['last_error_class']??null;
    if(!in_array($status,['failed','approved','paused'],true)||!is_string($storedError)||$storedError==='')return null;
    $runId=(int)$run['id'];
    $capability=$run['capability_key'];
    $actionStatus=$status==='failed'?'failed':'queued';
    $receiptStatus=$status==='failed'?'failed':'retry_scheduled';
    $receiptTime=vp3_initiative_db_wall_epoch_sql_v1($pdo,'r.created_at');
    $query=$pdo->prepare("SELECT a.id,a.status,a.attempt_count,a.max_attempts,a.completed_at,a.result_json AS action_result,
        r.id AS receipt_id,r.executor,$receiptTime AS receipt_epoch,r.result_json AS receipt_result
        FROM agent_workflow_actions a LEFT JOIN agent_workflow_receipts r
            ON r.run_id=a.run_id AND r.action_id=a.id AND r.owner_user_id=a.owner_user_id
            AND r.status=? AND r.error_class=a.error_class AND r.attempt_no=a.attempt_count
            AND (HEX(r.result_json)=HEX(a.result_json) OR (r.result_json IS NULL AND a.result_json IS NULL))
        WHERE a.run_id=? AND a.owner_user_id=? AND a.capability_key=? AND a.status=? AND a.error_class=?
        ORDER BY r.id DESC,a.id DESC LIMIT 1");
    $query->execute([$receiptStatus,$runId,$owner,$capability,$actionStatus,$storedError]);
    $action=$query->fetch(PDO::FETCH_ASSOC)?:[];
    $receiptMatches=isset($action['receipt_id'])&&$action['action_result']===$action['receipt_result'];
    $evidence=$receiptMatches?'receipt':($action?'action':'run');
    $receipt=$evidence==='receipt'?json_decode((string)($action['receipt_result']??''),true):null;
    $payload=json_decode((string)($run['goal']??''),true);
    $tool=$payload['tool']??'work.prepare_private';
    $stage=$capability==='initiative.plan'?'Assessment':match($tool){
        'chat.check_in'=>'Chat check-in','calendar.read'=>'Calendar review','tasks.read','tasks.lists.read'=>'Task review',
        'reminders.read'=>'Reminder review','transcripts.read'=>'Transcript review','topic.research'=>'Topic research',
        'artist.research'=>'Artist research','canvas.prepare'=>'Canvas preparation','missions.prepare','missions.evidence.read'=>'Mission review',
        'image.prepare'=>'Image concept','coordination.review'=>'Specialist review','products.content.prepare'=>'Product preparation',
        'knowledge.search'=>'Knowledge search','brain.read'=>'Agent memory review',
        'connected-sites.scan'=>match(true){
            ($payload['connected_site_target']['provider']??null)==='stoneward'=>'Stonefellow listening scan',
            ($payload['connected_site_target']['dataset']??null)==='sales'=>'FoodMonster daily sales check',
            default=>'Connected Sites POS scan',
        },default=>'Private preparation',
    };
    $phase=match($receipt['failure_phase']??null){
        'binding'=>'Checking Chat access','sources'=>'Checking source records','assessment'=>'Assessing and validating the plan',
        'tool'=>'Running the assigned step','validation'=>'Validating the result','commit'=>'Saving verified work',
        'delivery'=>'Delivering to Agent Chat',default=>null,
    };
    $modelCode=$storedError==='model_unavailable'?($receipt['model_diagnostic']??null):null;
    $modelReason=vp3_initiative_model_diagnostic_text_v1($modelCode);
    $officeFormat=$modelCode!==null?($receipt['office_report_error']??null):null;
    if(!in_array($officeFormat,Vp3InitiativeOfficeReportFormatV1::CODES,true))$officeFormat=null;
    $modelReason=match($officeFormat){
        'invalid_json'=>'The model returned malformed JSON for this report.',
        'object_required'=>'The report response must contain one JSON object.',
        'unexpected_fields'=>'The model added fields outside the report format.',
        'invalid_disposition'=>'The model did not provide a valid review decision.',
        'missing_draft'=>'The model left out the report text.',
        'invalid_draft'=>'The report text did not meet the plain-text or length requirements.',
        default=>$modelReason,
    };
    $modelStatus=$modelReason===null?false:filter_var($receipt['model_http_status']??null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>100,'max_range'=>599]]);
    $meter=[];
    if($modelReason===null&&$storedError==='model_unavailable'&&$capability==='initiative.plan'&&$receiptMatches
        &&is_array($payload['binding']??null)){
        try{$meter=vp3_initiative_assessment_meter_v1($pdo,$owner,$runId,$payload['binding']??[]);}
        catch(PDOException $error){}
    }
    $modelDetail=$modelReason??match($meter['model_usage_state']??null){
        'rejected'=>'The usage record shows that the AI provider rejected this assessment request. The provider’s reason was not recorded.',
        'unconfirmed'=>'The usage record shows an unconfirmed provider attempt for this assessment.',
        'response_recorded'=>'The usage record shows a provider response. The assessment failed before it could verify a plan.',
        default=>null,
    };
    $sourceDiagnostic=$storedError==='source_unavailable'
        ?Vp3InitiativeSourceProofUnavailableV1::diagnostic($receipt['source_family']??null,$receipt['source_coverage']??null):[];
    $reason=match($storedError){
        'model_unavailable'=>$modelDetail??'The AI request failed before producing a verified result. The saved attempt contains only a general model error.',
        'quota_exhausted'=>'The AI token budget was exhausted.',
        'route_unavailable'=>'The required AI execution route was unavailable.',
        'assessment_sources_changed'=>'The source records changed during assessment.',
        'assessment_unverified'=>'The assessment failed validation. See the assessment rejection code when available.',
        'source_unavailable'=>'The current source records could not be verified.',
        'lease_expired'=>'The execution lease expired before the action completed.',
        'public_search_unavailable'=>'The public search provider could not be reached.',
        'cloud_authorization_denied'=>'The Cloud worker could not verify authorization for this action.',
        default=>null,
    };
    $reasonCode=$reason!==null?$storedError:match($receipt['failure_cause']??null){
        'access_changed','source_changed','conflict','invalid_data','storage_error'=>$receipt['failure_cause'],
        default=>'unconfirmed',
    };
    $reason??=match($reasonCode){
        'access_changed'=>'The action could not verify access or an execution prerequisite.',
        'source_changed'=>'The source records changed before the result could be verified.',
        'conflict'=>'An execution check detected a conflict with current state.',
        'invalid_data'=>'Data failed validation at the recorded step.',
        'storage_error'=>'A database operation failed at the recorded step.',
        default=>($receipt['effect_state']??null)==='tool_result_withheld'
            ?'The tool returned a result, but Initiative could not verify or deliver it.'
            :'The saved failure record does not contain a specific cause.',
    };
    $completedAt=agent_job_utc_timestamp_v1900($action['completed_at']??null);
    $at=$status==='failed'&&$completedAt>0?$completedAt:($receiptMatches?(int)$action['receipt_epoch']:0);
    $retry=$status==='failed'?0:agent_job_utc_timestamp_v1900($run['next_attempt_at']??null);
    return ['run_id'=>$runId,'action_id'=>isset($action['id'])?(int)$action['id']:null,
        'stage'=>$stage,'phase'=>$phase,'status'=>$status,'action_status'=>$action['status']??null,
        'reason_code'=>$reasonCode,'reason'=>$reason,'executor'=>$receiptMatches&&in_array($action['executor']??null,['cloud','homeserver'],true)?$action['executor']:null,
        'at'=>$at>0&&$at<=time()?gmdate(DATE_ATOM,$at):'',
        'attempt'=>isset($action['attempt_count'])?(int)$action['attempt_count']:null,
        'max_attempts'=>isset($action['max_attempts'])?(int)$action['max_attempts']:null,
        'retry_at'=>$retry>0?gmdate(DATE_ATOM,$retry):'','evidence'=>$evidence,'is_latest_run'=>$latest]
        +($capability==='initiative.prepare'&&is_string($tool)&&preg_match('/\A[a-z_]+(?:\.[a-z_]+){1,3}\z/D',$tool)?['step'=>$tool]:[])
        +($modelReason!==null?['model_diagnostic'=>$modelCode]:[])
        +($officeFormat!==null?['office_report_error'=>$officeFormat]:[])
        +($modelStatus!==false?['model_http_status'=>$modelStatus]:[])+$meter+$sourceDiagnostic;
}

/** Read-only projection. Schema/configuration availability is not worker life. */
function vp3_initiative_worker_health_v1(PDO $pdo,array $user,int $agentId,int $cid,bool $observeOnly=true): array
{
    $base=['state'=>'unavailable','reason'=>'context_unavailable','enabled'=>false,'agent_id'=>$agentId,
        'conversation_id'=>$cid,'policy_revision'=>'','paused'=>false,'policy_exists'=>false,
        'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'configuration_ready'=>false,
        'execution_ready'=>false,'pump_available'=>false,'runner_state'=>'unknown','active_jobs'=>[],
        'runner_observation_scope'=>'server','runner_last_success_at'=>'','last_checked_at'=>'','last_execution_at'=>'','foreground_last_tick_at'=>'',
        'last_assessment'=>null,'latest_assessment_run'=>null,'assessment_read'=>'unchecked','assessment_error'=>null,'failure_detail'=>null,
        'retry_due_at'=>'','route'=>'','policy_reason'=>'',
        'execution_diagnostic'=>null];
    try{
        return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$base,$observeOnly):array{
            $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
            $live=$context['user'];$policy=$context['policy'];
            $health=$base;$health['configuration_ready']=true;
            $health['policy_exists']=$policy!==[];
            $revision=$policy['revision']??'';
            if(is_string($revision)&&preg_match('/^[a-f0-9]{32}$/D',$revision))$health['policy_revision']=$revision;
            $knowledge=$policy['knowledge_scope']??[];
            if(is_array($knowledge)&&in_array($knowledge['mode']??'', ['off','all','folder'],true)){
                $health['knowledge_scope']=['mode'=>$knowledge['mode'],
                    'folder_id'=>$knowledge['mode']==='folder'?max(0,(int)($knowledge['folder_id']??0)):0];
            }
            if($policy===[])return array_replace($health,['state'=>'starting','reason'=>'automatic_startup']);
            if(($policy['enabled']??null)===false)return array_replace($health,['state'=>'paused','reason'=>'initiative_paused','paused'=>true]);
            if(($policy['enabled']??null)!==true)return array_replace($health,['state'=>'unavailable','reason'=>'context_unavailable']);
            if(($policy['conversation_id']??null)!==$cid)return array_replace($health,['state'=>'linked_elsewhere','reason'=>'linked_elsewhere']);
            $live=vp3_initiative_access_v1($pdo,$live,$policy);
            $health['enabled']=true;$health['policy_revision']=(string)$policy['revision'];
            // A bound Chat can deliver completed work without a model route.
            $health['pump_available']=true;
            try{$runner=agent_worker_health_read_v1();}
            catch(Throwable $e){$runner=['state'=>'unknown','last_success_at'=>''];}
            $health['runner_state']=$runner['state'];$health['runner_last_success_at']=$runner['last_success_at'];
            $tick=vp3_initiative_foreground_tick_v1($live,$policy);
            $health['foreground_last_tick_at']=$tick['at']??'';
            $review=agent_brain_v122_memory($live,'initiative_sweep','last-review')['metadata']??[];
            if(($review['conversation_id']??null)===$cid&&($review['revision']??null)===$policy['revision']){
                $checked=strtotime((string)($review['checked_at']??''))?:0;
                if($checked>0&&$checked<=time())$health['last_checked_at']=gmdate(DATE_ATOM,$checked);
            }
            $health['state']=$health['last_checked_at']!==''?'checked':'waiting';
            $health['reason']=match($runner['state']){
                'fresh'=>$health['last_checked_at']!==''?'checked':'waiting_for_review',
                'stale'=>'worker_stale','failed'=>'worker_failed','unknown'=>'worker_observation_unavailable',default=>'worker_missing'};
            if($tick!==null&&strtotime($tick['at'])>=time()-90&&$runner['state']==='never_seen'){
                $health['reason']='foreground_active';
            }
            try{
                $health=array_replace($health,vp3_initiative_runtime_assessments_v1($pdo,(int)$live['id'],$agentId,$policy,time()));
                $health['assessment_read']='complete';
            }catch(Throwable $error){
                // A history read cannot revoke an already verified policy or stop Chat from advancing work.
                $health['assessment_read']='unavailable';
                $health['assessment_diagnostic']=vp3_initiative_health_diagnostic_v1($error,'assessment_read',(int)$live['id'],$agentId,$cid);
            }
            $latest=true;
            $recent=vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy);
            $executing=vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,executingOnly:true);
            foreach(array_replace(array_column($recent,null,'id'),array_column($executing,null,'id')) as $run){
                if($health['failure_detail']===null){
                    $health['failure_detail']=vp3_initiative_runtime_failure_v1($pdo,(int)$live['id'],$run,$latest);
                }
                $completed=agent_job_utc_timestamp_v1900($run['completed_at']??null);
                if($run['status']==='completed'&&$completed>0&&$completed<=time()){
                    if($health['last_execution_at']==='')$health['last_execution_at']=gmdate(DATE_ATOM,$completed);
                }
                if($run['status']==='executing'){
                    $expiresAt=agent_job_utc_timestamp_v1900($run['lease_expires_at']??null);
                    $action=$pdo->prepare("SELECT id FROM agent_workflow_actions WHERE id=? AND run_id=? AND owner_user_id=?
                        AND status='executing' AND execution_target='cloud' AND capability_key=? AND attempt_count>0 LIMIT 1");
                    $action->execute([(int)($run['current_action_id']??0),(int)$run['id'],(int)$live['id'],$run['capability_key']]);
                    if($expiresAt>time()&&!empty($run['lease_token'])&&!empty($run['lease_owner'])&&$action->fetchColumn()){
                        $health['state']='running';$health['reason']='reviewing';
                        $assessment=($run['capability_key']??'')==='initiative.plan';
                        $phase=vp3_initiative_runtime_job_phase_v1($run);
                        $step=$assessment?match($phase){
                            'coordinating'=>['planning','Coordinating specialist findings'],
                            'brainstorming'=>['brainstorming','Brainstorming useful opportunities'],
                            default=>['analyzing','Analyzing current sources and specialist reports'],
                        }:['acting','Preparing a private next step'];
                        $heartbeatAt=agent_job_utc_timestamp_v1900($run['heartbeat_at']??null);
                        $health['active_jobs'][]=['id'=>'workflow:'.$run['id'],'expiresAt'=>$expiresAt*1000,
                            'kind'=>$assessment?'assessment':'preparation',
                            'step'=>$step[1],'coordination_phase'=>$step[0],
                            'phase'=>$phase,
                            'at'=>$heartbeatAt>0&&$heartbeatAt<=time()?gmdate(DATE_ATOM,$heartbeatAt):'',
                            'specialist'=>vp3_initiative_runtime_job_specialist_v1($run)];
                    }elseif($latest){
                        $health['state']='waiting';$health['reason']='worker_interrupted';
                    }
                }
                if($latest&&$run['status']==='failed'){
                    $failure=(string)($run['last_error_class']??'');
                    $health['state']=$failure==='assessment_sources_changed'?'waiting':'failed';
                    $health['reason']=match($failure){
                        'model_unavailable','quota_exhausted','route_unavailable','assessment_sources_changed','assessment_unverified','public_search_unavailable','source_unavailable'=>$failure,
                        'lease_expired'=>'worker_interrupted',
                        default=>'review_failed',
                    };
                }
                if($latest&&$run['status']==='approved'&&in_array($run['last_error_class']??'', ['model_unavailable','lease_expired','public_search_unavailable','source_unavailable'],true)){
                    $due=agent_job_utc_timestamp_v1900($run['next_attempt_at']??null);
                    $health['state']='waiting';$health['reason']='retry_scheduled';
                    if($due>0)$health['retry_due_at']=gmdate(DATE_ATOM,$due);
                }
                $latest=false;
            }
            try{
                $driver=__DIR__.'/agent-initiative-model-v1.php';
                if(!function_exists('vp3_initiative_inference_health_v1')&&is_file($driver))require_once $driver;
                if(function_exists('vp3_initiative_inference_health_v1')){
                    $inference=vp3_initiative_inference_health_v1($pdo,$live,$agentId,true,$observeOnly);
                }else{
                    $plan=vp3_agent_runtime_plan_v420($pdo,$live,$agentId,'chat');
                    $ready=($plan['route']??'')==='vp3_cloud'&&empty($plan['blocked']);
                    $inference=['ready'=>$ready,'route'=>(string)($plan['route']??''),'reason'=>'model_unavailable'];
                    if(($plan['cloud']['reason']??'')==='cloud_balance_exhausted')$inference['reason']='quota_exhausted';
                    elseif(str_starts_with($inference['route'],'homeserver')||($plan['effective_preference']??'')==='homeserver_only')$inference['reason']='route_unavailable';
                }
            }
            catch(Throwable $e){$inference=['ready'=>false,'reason'=>'model_unavailable','model_diagnostic'=>'model_preflight_unavailable'];}
            $health['route']=(string)($inference['route']??'');
            if(empty($inference['ready'])){
                if(($inference['reason']??null)==='route_check_required'){
                    return array_replace($health,['state'=>$health['active_jobs']?'running':'waiting','reason'=>'route_check_required']);
                }
                $reason=in_array($inference['reason']??'', ['quota_exhausted','model_unavailable','route_unavailable'],true)
                    ?$inference['reason']:'model_unavailable';
                $code=in_array($reason,['model_unavailable','route_unavailable'],true)?($inference['model_diagnostic']??null):null;
                $detail=vp3_initiative_model_diagnostic_text_v1($code);
                return array_replace($health,['state'=>'blocked','reason'=>$reason,'policy_reason'=>(string)($inference['policy_reason']??''),
                    'execution_diagnostic'=>$detail!==null?['code'=>$code,'detail'=>$detail]:null]);
            }
            $health['execution_ready']=true;
            return $health;
        });
    }catch(Throwable $e){
        $code=(int)$e->getCode();
        $reason=in_array($code,[403,404],true)?'context_unavailable':($code===503?'storage_unavailable':'runtime_unavailable');
        if($reason==='runtime_unavailable'){
            $base['runtime_diagnostic']=vp3_initiative_health_diagnostic_v1($e,'runtime_read',(int)($user['id']??0),$agentId,$cid);
        }
        return array_replace($base,['reason'=>$reason]);
    }
}

function vp3_initiative_health_diagnostic_v1(Throwable $error,string $phase,int $owner,int $agentId,int $cid): array
{
    $failure=vp3_initiative_coordination_failure_v1($error,$phase);
    if(function_exists('agent_runtime_v125_trace')){
        try{agent_runtime_v125_trace('agent_core.health_unavailable',['user_id'=>$owner,
            'agent_id'=>$agentId,'conversation_id'=>$cid,'error_class'=>get_class($error)]+$failure);}
        catch(Throwable $ignored){}
    }
    return array_intersect_key($failure,['code'=>true,'sqlstate'=>true,'database_code'=>true]);
}

/**
 * Bounded authenticated POST primitive. Reuses the canonical queue, claimant,
 * lease, receipt and domain executor; it cannot pump images or public replies.
 */
function vp3_initiative_pump_v1(PDO $pdo,array $user,int $agentId,int $cid,string $expectedRevision,?callable $model=null,bool $allowDelivery=true): array
{
    $result=vp3_initiative_pump_once_v1($pdo,$user,$agentId,$cid,$expectedRevision,$model,$allowDelivery);
    if(($result['reason']??null)!=='off'){
        try{
            vp3_initiative_in_scope_v1($user,$agentId,static function()use($user,$agentId,$cid,$expectedRevision,$result):void{
                $current=vp3_initiative_policy_v1($user,$agentId);
                if(($current['conversation_id']??null)!==$cid||($current['revision']??null)!==$expectedRevision)return;
                // Keep one observation for this Agent, without private content.
                $at=gmdate(DATE_ATOM);
                $previous=vp3_initiative_foreground_tick_v1($user,$current);
                $coordination=vp3_initiative_tick_coordination_v1($result['coordination']??null);
                $coordinationCheck=$coordination!==null&&$coordination!=='not_due'
                    ?['at'=>$at,'outcome'=>$coordination]:($previous['coordination']??null);
                if($coordination==='failed'){
                    $coordinationCheck['failure']=vp3_initiative_tick_scan_failure_v1($result['coordination_failure']??null);
                }
                agent_brain_v122_upsert_system_memory($user,'initiative_sweep','foreground-tick','Open Chat background check.',
                    ['conversation_id'=>$cid,'revision'=>$expectedRevision,'at'=>$at,
                        'reason'=>vp3_initiative_tick_reason_v1($result['reason']??null),
                        'step'=>vp3_initiative_tick_step_v1($result['step']??null),
                        'run_id'=>$result['run_id']??null,'coordination'=>$coordinationCheck]);
            });
        }catch(Throwable $e){
            // Diagnostics must never change the outcome of the verified work.
        }
    }
    return $result;
}

function vp3_initiative_tick_reason_v1(mixed $reason): string
{
    return in_array($reason,['completed','no_work','blocked','at_capacity','already_running','retry_scheduled',
        'schedule_unavailable','assessment_sources_changed','assessment_unverified','source_unavailable','model_unavailable','quota_exhausted',
        'route_unavailable','public_search_unavailable','review_failed','execution_no_longer_active','off'],true)?$reason:'unverified';
}

function vp3_initiative_tick_coordination_v1(mixed $value): ?string
{
    return in_array($value,['not_due','sources_checked','queued_work','queued_chat_handoff','queued_private_work','queued_checkin','queued_media_offer',
        'morning_brief_posted','morning_brief_waiting','reminder_posted','no_candidate','handoff_already_handled','handoff_failed','handoff_cancelled',
        'handoff_paused','failed'],true)?$value:null;
}

function vp3_initiative_tick_step_v1(mixed $value): ?string
{
    return in_array($value,['assessment','preparation','saved_delivery'],true)?$value:null;
}

function vp3_initiative_tick_scan_failure_v1(mixed $value): ?array
{
    if(!is_array($value))return null;
    $phase=$value['phase']??null;
    $code=$value['code']??null;
    if(!in_array($phase,['scan_setup','owner_lock','chat_access','specialist_scan','queue_check','candidate_selection','checkpoint','commit'],true)
        ||!in_array($code,['storage_error','storage_schema','storage_contention','storage_duplicate','invalid_internal_type','invalid_data',
            'access_changed','state_changed','verification_unavailable','code_error','unconfirmed'],true))return null;
    $failure=['phase'=>$phase,'code'=>$code];
    $state=$value['sqlstate']??null;
    if(is_string($state)&&preg_match('/\A[A-Z0-9]{5}\z/D',$state))$failure['sqlstate']=$state;
    $number=$value['database_code']??null;
    if(is_int($number)&&$number>=1&&$number<=65535)$failure['database_code']=$number;
    $file=$value['file']??null;
    $line=$value['line']??null;
    if(is_string($file)&&strlen($file)<=100&&preg_match('/\A[a-z0-9-]+\.php\z/D',$file)&&is_int($line)&&$line>0){
        $failure['file']=$file;
        $failure['line']=$line;
    }
    return $failure;
}

function vp3_initiative_foreground_tick_v1(array $user,array $policy): ?array
{
    $tick=agent_brain_v122_memory($user,'initiative_sweep','foreground-tick')['metadata']??[];
    if(($tick['conversation_id']??null)!==($policy['conversation_id']??null)
        ||($tick['revision']??null)!==($policy['revision']??null))return null;
    $at=strtotime((string)($tick['at']??''))?:0;
    if($at<1||$at>time())return null;
    $scanAt=strtotime((string)($tick['coordination']['at']??''))?:0;
    $scanOutcome=vp3_initiative_tick_coordination_v1($tick['coordination']['outcome']??null);
    $coordination=$scanOutcome!==null&&$scanAt>0&&$scanAt<=$at?['at'=>gmdate(DATE_ATOM,$scanAt),'outcome'=>$scanOutcome]:null;
    if($scanOutcome==='failed'&&$coordination!==null){
        $failure=vp3_initiative_tick_scan_failure_v1($tick['coordination']['failure']??null);
        if($failure!==null)$coordination['failure']=$failure;
    }
    $id=$tick['run_id']??null;
    return ['at'=>gmdate(DATE_ATOM,$at),'reason'=>vp3_initiative_tick_reason_v1($tick['reason']??null),
        'step'=>vp3_initiative_tick_step_v1($tick['step']??null),
        'run_id'=>is_int($id)&&$id>0?$id:null,'coordination'=>$coordination];
}

function vp3_initiative_pump_once_v1(PDO $pdo,array $user,int $agentId,int $cid,string $expectedRevision,?callable $model=null,bool $allowDelivery=true): array
{
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$agentId,$cid,$expectedRevision,$model,$allowDelivery):array{
        $context=vp3_initiative_runtime_context_v1($pdo,$user,$agentId,$cid);
        $live=$context['user'];$policy=$context['policy'];
        if(empty($policy['enabled']))return ['ok'=>true,'reason'=>'off','health'=>vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)];
        if(($policy['conversation_id']??null)!==$cid||!preg_match('/^[a-f0-9]{32}$/D',$expectedRevision)
            ||!hash_equals((string)($policy['revision']??''),$expectedRevision)){
            throw new RuntimeException('Initiative settings changed. Refresh Chat.',409);
        }
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        // A committed Chat update can be acknowledged before any unrelated
        // provider readiness or specialist discovery runs on this request.
        if($allowDelivery&&vp3_initiative_deliver_pending_v1($pdo,$live,$agentId)){
            return ['ok'=>true,'reason'=>'completed','step'=>'saved_delivery'];
        }
        $health=vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid);
        if(empty($health['pump_available']))return ['ok'=>false,'reason'=>'blocked','health'=>$health];
        $executionReady=!empty($health['execution_ready']);
        // Discover saved work even while a model route is unavailable.
        $scan=null;
        $scanFailure=null;
        $coordinationReady=vp3_initiative_schedule_coordination_v1($pdo,$live,$policy,$executionReady,$scan,$scanFailure,allowDelivery:$allowDelivery);
        $coordination=['coordination'=>$scan];
        if($scanFailure!==null)$coordination['coordination_failure']=$scanFailure;
        if(in_array($scan,['morning_brief_posted','reminder_posted'],true)){
            return ['ok'=>true,'reason'=>'completed','step'=>'saved_delivery','health'=>$health]+$coordination;
        }
        require_once __DIR__.'/agent-initiative-connected-sites-v1.php';
        vp3_initiative_connected_sites_schedule_v1($pdo,$live,$policy);
        $workerId=agent_worker_cloud_worker_id_v1910((int)$live['id']);
        $active=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE owner_user_id=? AND status='executing' AND lease_owner LIKE ? AND lease_expires_at>=UTC_TIMESTAMP()");
        $active->execute([(int)$live['id'],'cloud:u'.(int)$live['id'].':%']);
        if((int)$active->fetchColumn()>=4)return ['ok'=>true,'reason'=>'at_capacity','health'=>$health]+$coordination;
        $reason='no_work';
        $claimNext=static function(bool $coordinationOnly)use($pdo,$live,$agentId,$cid,$policy,$model,$workerId,&$executionReady,$allowDelivery,&$reason):?array{
            foreach(vp3_initiative_runtime_runs_v1($pdo,(int)$live['id'],$agentId,$policy,true,false,!$executionReady,$coordinationOnly,true) as $run){
                if($run['status']==='executing'){
                    if(agent_job_utc_timestamp_v1900($run['lease_expires_at']??null)>=time()){$reason='already_running';continue;}
                    agent_job_recover_expired_v1900($pdo,$live,1,(int)$run['id']);
                }
                $live=vp3_initiative_access_v1($pdo,$live,$policy);
                $claim=agent_job_claim_run_v1900($pdo,$live,(int)$run['id'],'cloud',$workerId,180);
                if(!$claim)continue;
                $result=vp3_initiative_execute_v1($pdo,$live,$claim,$model,allowDelivery:$allowDelivery);
                return ['ok'=>!empty($result['ok']),'reason'=>(string)($result['reason']??'failed'),
                    'step'=>$run['capability_key']==='initiative.plan'?'assessment':'preparation','run_id'=>(int)$run['id'],
                    'health'=>vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid)];
            }
            return null;
        };
        if(!$executionReady){
            if(($result=$claimNext(true))!==null)return $result+$coordination;
            if(($health['reason']??null)==='route_check_required'){
                $health=vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid,observeOnly:false);
                $executionReady=!empty($health['execution_ready']);
            }
            if(!$executionReady)return ['ok'=>false,'reason'=>$coordinationReady?'blocked':'schedule_unavailable','health'=>$health]+$coordination;
        }
        vp3_initiative_schedule_office_entries_v1($pdo,$live,$policy);
        require_once __DIR__.'/agent-initiative-opportunity-schedule-v1.php';
        vp3_initiative_schedule_opportunities_v1($pdo,$live,$policy);
        $scheduleAssessment=static function()use($pdo,$live,$agentId,&$coordinationReady):void{
            try{vp3_initiative_schedule_v1($pdo,$live,$agentId,false);}
            catch(Throwable $error){
                if(function_exists('agent_runtime_v125_trace'))agent_runtime_v125_trace('initiative.foreground_schedule_failed',
                    ['user_id'=>(int)$live['id'],'error_class'=>get_class($error)]);
                $coordinationReady=false;
            }
        };
        $review=agent_brain_v122_memory($live,'initiative_sweep','last-review')['metadata']??[];
        $checked=strtotime((string)($review['checked_at']??''))?:0;
        // Consider early source/owner wakeups on the same 30-second floor
        // as the canonical review check. That check owns the normal cadence.
        $assessmentDue=($review['conversation_id']??null)!==$cid||($review['revision']??null)!==$policy['revision']
            ||$checked>time()||time()-$checked>=30;
        if($assessmentDue)$scheduleAssessment();
        if(($result=$claimNext(false))!==null)return $result+$coordination;
        if(!$assessmentDue)$scheduleAssessment();
        if(($result=$claimNext(false))!==null)return $result+$coordination;
        if(!$coordinationReady&&$reason==='no_work')return ['ok'=>false,'reason'=>'schedule_unavailable','health'=>$health]+$coordination;
        $health=vp3_initiative_worker_health_v1($pdo,$live,$agentId,$cid);
        if($reason==='no_work'&&$health['reason']==='retry_scheduled')$reason='retry_scheduled';
        return ['ok'=>true,'reason'=>$reason,'health'=>$health]+$coordination;
    });
}
