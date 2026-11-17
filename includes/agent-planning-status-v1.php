<?php
declare(strict_types=1);

/** Project only work already verified by the meeting, workflow, and Research readers. */
function vp3_planning_status_v1(array $signals,int $now): array
{
    $idle=['id'=>'planning','state'=>'idle','detail'=>'No current planning work in this Chat.',
        'progress'=>null,'run_id'=>null,'lease_expires_at'=>'','expires_at'=>'','result_url'=>'','message_id'=>null];
    $status=$idle;
    $priority=['working'=>0,'waiting_owner'=>1,'waiting_specialist'=>2,'queued'=>3,'blocked'=>4,'failed'=>5,'ready'=>6,'idle'=>7];
    foreach($signals as $signal){
        $source=$signal['source'];$phase=$signal['phase'];
        [$state,$detail]=match([$source,$phase]){
            ['meeting_prep','working']=>['working','Preparing for an upcoming meeting.'],
            ['meeting_prep','queued']=>['queued','Upcoming meeting preparation is queued.'],
            ['meeting_prep','blocked']=>['blocked','Upcoming meeting preparation is waiting for the background service.'],
            ['meeting_prep','ready']=>['ready','Upcoming meeting preparation is ready.'],
            ['meeting_prep','failed']=>['failed','Upcoming meeting preparation needs attention.'],
            ['meeting_research','queued'],['meeting_research','working']=>['waiting_specialist','Waiting for Research on a linked meeting follow-up.'],
            ['meeting_research','failed']=>['blocked','Research on a linked meeting follow-up needs attention.'],
            ['meeting_research','ready']=>['ready','Research for the linked meeting follow-up is ready.'],
            ['meeting_research','review'],['meeting_action','review']=>['waiting_owner','A meeting action needs your review.'],
            ['meeting_research','saved'],['meeting_action','saved']=>['ready','A meeting action was saved and verified.'],
            default=>['idle',''],
        };
        if($state==='working'&&strtotime($signal['lease_expires_at']??'')<=$now)continue;
        if($priority[$state]>=$priority[$status['state']])continue;
        $status=array_replace($idle,array_intersect_key($signal,array_flip(['run_id','dependency_run_id','dependency_specialist',
            'expires_at','result_url','message_id'])),
            ['state'=>$state,'detail'=>$detail,'lease_expires_at'=>$state==='working'?$signal['lease_expires_at']:'']);
    }
    return $status;
}

/** Only the latest pending review is actionable. Saved results use their current domain receipt. */
function vp3_planning_chat_action_status_v1(PDO $pdo,array $user,array $principal,int $cid): ?array
{
    require_once __DIR__.'/agent-meeting-chat-actions-v1.php';
    $query=$pdo->prepare("SELECT id,context_json,created_at FROM (
        SELECT id,role,context_json,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 50
    ) AS recent WHERE role='assistant' AND context_json LIKE '%\"meeting_action_review_v1\"%' ORDER BY id DESC LIMIT 5");
    $query->execute([$cid]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $context=json_decode((string)$row['context_json'],true);
        $marker=$context['meeting_action_review_v1']??null;
        if(!is_array($marker))continue;
        $view=vp3_meeting_chat_projection_v1($pdo,$user,$principal,$cid,$marker);
        $review=$view['meeting_action']??null;
        $phase=match($review['status']??null){'approval_pending'=>'review','completed'=>'saved',default=>null};
        if($phase===null)continue;
        if($phase==='review'&&(int)$row['id']!==(int)(vp3_meeting_chat_latest_v1($pdo,$cid)['id']??0))continue;
        return ['source'=>'meeting_action','phase'=>$phase,'run_id'=>$review['plan_id'],'message_id'=>(int)$row['id'],
            'result_url'=>vp3_top_of_mind_media_href_v1((int)($principal['agent_id']??0),$cid,(int)$row['id']),
            'expires_at'=>$phase==='saved'?gmdate(DATE_ATOM,vp3_top_of_mind_lease_time_v1($row['created_at'])+120):''];
    }
    return null;
}

/** Exact reportable meeting-prep and linked Research candidates for one saved Chat policy. */
function vp3_planning_work_candidates_v1(PDO $pdo,int $owner,int $agentId,array $policy): array
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $json=static function(string $path)use($sqlite):string{
        $value="JSON_EXTRACT(CASE WHEN JSON_VALID(r.goal) THEN r.goal ELSE '{}' END,'$path')";
        return $sqlite?$value:"JSON_UNQUOTE($value)";
    };
    $conversation=$json('$.binding.conversation_id');$revision=$json('$.binding.revision');
    $tool=$json('$.tool');$primary=$json('$.primary');$report=$json('$.report');$true=$sqlite?'1':"'true'";
    $query=$pdo->prepare("SELECT r.id AS run_id,r.goal,r.status AS run_status,r.lease_expires_at,
        a.id AS action_id,a.attempt_count,a.result_json,a.completed_at AS action_completed_at
        FROM agent_workflow_runs r JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            AND a.sequence_no=1 AND a.action_key='initiative.prepare' AND a.capability_key='initiative.prepare' AND a.execution_target='cloud'
        WHERE r.owner_user_id=? AND CAST(COALESCE(r.agent_id,0) AS CHAR)=?
            AND r.workflow_type='initiative' AND r.source_kind='agent_initiative'
            AND r.capability_key='initiative.prepare' AND r.execution_target='cloud'
            AND CAST($conversation AS CHAR)=? AND $revision=? AND $report=$true
            AND (($tool='calendar.read' AND $primary LIKE 'calendar:%') OR ($tool='topic.research' AND EXISTS(
                SELECT 1 FROM agent_workflow_events e WHERE e.run_id=r.id AND e.owner_user_id=r.owner_user_id AND e.event_type='meeting_research_linked')))
            AND ((r.status IN ('queued','approved') AND a.status='queued') OR (r.status='executing' AND a.status='executing' AND r.current_action_id=a.id)
                OR (r.status IN ('completed','failed') AND a.status=r.status))
        ORDER BY CASE r.status WHEN 'executing' THEN 0 WHEN 'approved' THEN 1 WHEN 'queued' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END,r.id DESC LIMIT 8");
    $query->execute([$owner,(string)$agentId,(string)$policy['conversation_id'],$policy['revision']]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_planning_prep_status_v1(PDO $pdo,array $user,int $agentId,int $cid,array $policy,array $row,array $goal,string $phase,int $now): ?array
{
    require_once __DIR__.'/agent-initiative-office-records-v1.php';
    $ref=$goal['primary'];$result=json_decode((string)$row['result_json'],true);
    if($phase==='ready'){
        if(($result['effect_state']??null)!=='private_draft_ready'||($result['initiative_draft']??null)!==VP3_INITIATIVE_MEETING_PREP_MARKER_V1
            ||($result['initiative_tool_result']['output']['tool']??null)!=='calendar.read')return null;
        if(($result['initiative_delivery']??null)==='delivered'){
            $messageId=filter_var($result['initiative_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($messageId===false||vp3_initiative_meeting_prep_projection_v1($pdo,$user,$agentId,$cid,$messageId)===null)return null;
            return ['phase'=>'ready','message_id'=>$messageId,'result_url'=>vp3_top_of_mind_media_href_v1($agentId,$cid,$messageId)];
        }
        if(!in_array($result['initiative_delivery']??null,['private','pending'],true))return null;
    }
    $sources=vp3_initiative_current_sources_v1($pdo,$user,$goal['sources'],!empty($policy['screen_enabled']),
        $goal['canonical_sources']??[],$agentId,$goal['capability_sources']??[]);
    $prep=$sources[$ref]['meeting_prep']??null;
    if(!is_array($prep)||!vp3_initiative_meeting_prep_eligible_v1($pdo,(int)$user['id'],$agentId,$prep,new DateTimeImmutable('@'.$now)))return null;
    if($phase==='ready')vp3_initiative_result_current_v1($pdo,$user,$policy,$goal,$result['initiative_tool_result']??[]);
    return ['phase'=>$phase];
}

function vp3_planning_research_status_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,array $row,array $goal,string $phase,int $now): ?array
{
    require_once __DIR__.'/agent-meeting-research-v1.php';
    if(vp3_meeting_research_projection_v1($pdo,(int)$user['id'],$agentId,$cid,(int)$row['run_id'],$goal)===null)return null;
    if($phase!=='ready')return ['phase'=>$phase];
    $completed=vp3_initiative_research_status_completion_v1($pdo,(int)$user['id'],$agentId,$cid,$row,$goal,$now,true);
    if($completed===null)return null;
    if(!isset($completed['message_id']))return ['phase'=>'ready','expires_at'=>$completed['expires_at']];
    require_once __DIR__.'/agent-initiative-followthrough-v1.php';
    $messageId=$completed['message_id'];
    $loaded=vp3_initiative_followthrough_load_v1($pdo,$user,$principal,$cid,$messageId);
    if(!isset($loaded['meeting_research']))return null;
    $review=($loaded['state']['active_review_slot']??null)==='followup_run_id'?$loaded['followup_action']:$loaded['domain_action'];
    $phase=match($review['run']['status']??null){
        'approval_pending'=>'review','completed'=>'saved',
        default=>in_array($loaded['state']['state']??null,['declined','deferred','owner_resolved','completed','stale'],true)?null:'ready',
    };
    if($phase===null)return null;
    return ['phase'=>$phase,'message_id'=>$messageId,'result_url'=>vp3_top_of_mind_media_href_v1($agentId,$cid,$messageId),
        'expires_at'=>$phase==='ready'?$completed['expires_at']:'']
        +(isset($review['run']['id'])?['run_id'=>(int)$review['run']['id']]:[]);
}

/** A compact read for both Chat and Top of Mind. No model calls or scheduling. */
function vp3_planning_status_read_v1(PDO $pdo,array $user,array $principal,int $agentId,int $cid,array $policy,array $health=[],?int $now=null): array
{
    $now??=time();$signals=[];
    $scope=['conversation_id'=>$cid];
    if($cid<1||vp3_participant_scope_v1()!==null)return vp3_planning_status_v1($signals,$now)+$scope;
    try{
        $action=vp3_planning_chat_action_status_v1($pdo,$user,$principal,$cid);
        if($action!==null)$signals[]=$action;
        $policyInChat=($policy['conversation_id']??null)===$cid;
        if(!$policyInChat||($policy['enabled']??null)!==true){
            $status=vp3_planning_status_v1($signals,$now)+$scope;
            return !$signals&&$policyInChat&&($policy['enabled']??null)===false
                ?array_replace($status,['state'=>'paused','detail'=>'Background meeting preparation is paused.']):$status;
        }
        $user=vp3_initiative_access_v1($pdo,$user,$policy);
        $rows=agent_job_engine_schema_ready_v1900($pdo)?vp3_planning_work_candidates_v1($pdo,(int)$user['id'],$agentId,$policy):null;
    }catch(RuntimeException $error){
        $rows=null;
    }
    if($rows===null){
        $status=vp3_planning_status_v1($signals,$now)+$scope;
        return $signals?$status:array_replace($status,['state'=>'unavailable','detail'=>'Planning status is unavailable.']);
    }
    if(!$rows)return vp3_planning_status_v1($signals,$now)+$scope;
    require_once __DIR__.'/agent-initiative-research-status-v1.php';
    foreach($rows as $row){
        $goal=json_decode((string)$row['goal'],true);$ref=$goal['primary']??null;
        if(($goal['binding']??null)!==$policy||($goal['report']??null)!==true||!is_string($ref)
            ||!is_array($goal['sources']??null)||array_keys($goal['sources'])!==[$ref]
            ||!is_string($goal['sources'][$ref])||!preg_match('/\A[a-f0-9]{64}\z/D',$goal['sources'][$ref]))continue;
        $lease=vp3_top_of_mind_lease_time_v1($row['lease_expires_at']);
        $ended=vp3_top_of_mind_lease_time_v1($row['action_completed_at']);
        $phase=match($row['run_status']){'executing'=>$lease>$now?'working':null,'completed'=>'ready','failed'=>'failed',default=>'queued'};
        if($phase===null)continue;
        $prep=$goal['tool']==='calendar.read';
        if($prep&&(!empty($goal['followthrough'])||!empty($goal['followup'])))continue;
        if($prep&&$phase==='queued'&&($health['state']??null)==='blocked')$phase='blocked';
        try{
            if(in_array($phase,['ready','failed'],true)&&($ended<1||$ended>$now
                ||!vp3_initiative_research_status_receipt_v1($pdo,(int)$user['id'],$row)))continue;
            $signal=$prep?vp3_planning_prep_status_v1($pdo,$user,$agentId,$cid,$policy,$row,$goal,$phase,$now)
                :vp3_planning_research_status_v1($pdo,$user,$principal,$agentId,$cid,$row,$goal,$phase,$now);
        }catch(RuntimeException $error){continue;}
        if($signal===null)continue;
        $signals[]=$signal+['source'=>$prep?'meeting_prep':'meeting_research',
            'lease_expires_at'=>$phase==='working'?gmdate(DATE_ATOM,$lease):'',
            'expires_at'=>in_array($phase,['ready','failed'],true)?gmdate(DATE_ATOM,$ended+120):'']
            +($prep?['run_id'=>(int)$row['run_id']]:['dependency_run_id'=>(int)$row['run_id'],'dependency_specialist'=>'research']);
        if($prep&&$phase==='working')break;
    }
    return vp3_planning_status_v1($signals,$now)+$scope;
}
