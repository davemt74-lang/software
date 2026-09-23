<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v24.40 — Goal & Task Continuity.
 *
 * This is a read-only continuity projection over existing durable authorities:
 * Agent Goals, Agent Workflows, Cognitive Orchestration, meeting commitments,
 * Browser transaction continuity, and the live-session projection.
 *
 * It creates no second task store, goal store, workflow queue, or execution path.
 */
const VP3_COGNITIVE_CONTINUITY_V2440='vp3-cognitive-continuity-v2440-20260922';
const VP3_COGNITIVE_CONTINUITY_CONTRACT_V2440='cognitive-continuity-v1';
const VP3_COGNITIVE_CONTINUITY_MAX_ITEMS_V2440=16;

function vp3_cognitive_continuity_state_score_v2440(string $state): float
{
    return match($state){
        'needs_approval'=>100.0,
        'needs_user'=>98.0,
        'repair_needed'=>95.0,
        'blocked'=>93.0,
        'working'=>91.0,
        'ready'=>88.0,
        'needs_planning'=>85.0,
        'verifying'=>83.0,
        'needs_review'=>81.0,
        'planned'=>78.0,
        'scheduled'=>76.0,
        'tracking'=>74.0,
        'paused'=>62.0,
        default=>70.0,
    };
}

function vp3_cognitive_continuity_item_v2440(
    string $ref,
    string $kind,
    string $state,
    string $title,
    string $summary,
    array $extra=[]
): array {
    $state=preg_match('/^[a-z][a-z0-9_]{0,63}$/',$state)?$state:'planned';
    $score=vp3_cognitive_continuity_state_score_v2440($state)+(float)($extra['score_boost']??0.0);
    unset($extra['score_boost']);
    return [
        'ref'=>vp3_cognitive_text_v500($ref,190),
        'kind'=>vp3_cognitive_text_v500($kind,80),
        'state'=>$state,
        'title'=>vp3_cognitive_text_v500($title,190),
        'summary'=>vp3_cognitive_text_v500($summary,900),
        'score'=>round(max(0.0,min(120.0,$score)),3),
        'requires_user'=>!empty($extra['requires_user']),
        'requires_approval'=>!empty($extra['requires_approval']),
        'blocked_by'=>max(0,(int)($extra['blocked_by']??0)),
        'resume_action'=>vp3_cognitive_text_v500($extra['resume_action']??'',220),
        'goal_ref'=>vp3_cognitive_text_v500($extra['goal_ref']??'',190),
        'task_ref'=>vp3_cognitive_text_v500($extra['task_ref']??'',190),
        'project_ref'=>vp3_cognitive_text_v500($extra['project_ref']??'',190),
        'surface'=>vp3_cognitive_text_v500($extra['surface']??'',80),
        'source'=>vp3_cognitive_text_v500($extra['source']??$kind,100),
        'updated_at'=>(string)($extra['updated_at']??''),
        'metadata'=>vp3_cognitive_sanitize_value_v500(is_array($extra['metadata']??null)?$extra['metadata']:[]),
    ];
}

function vp3_cognitive_continuity_live_v2440(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_live_session_snapshot_v2370'))return [];
    try{$snapshot=vp3_live_session_snapshot_v2370($pdo,$user,false);}catch(Throwable $e){return [];}
    $session=is_array($snapshot['session']??null)?$snapshot['session']:null;
    if(!$session)return [];
    $sessionNamespace=trim((string)($session['agent_namespace']??'system'))?:'system';
    if(!hash_equals($namespace,$sessionNamespace))return [];

    $status=(string)($session['status']??'');
    if(!in_array($status,['active','paused'],true))return [];
    $goalRef=vp3_cognitive_text_v500($session['current_goal_ref']??'',190);
    $taskRef=vp3_cognitive_text_v500($session['current_task_ref']??'',190);
    $projectRef=vp3_cognitive_text_v500($session['current_project_ref']??'',190);
    $contextKey=vp3_cognitive_text_v500($session['current_context_key']??'',180);
    $specific=$goalRef!==''||$taskRef!==''||$projectRef!=='';
    if(!$specific&&$contextKey==='')return [];
    $state=$status==='active'?'working':'paused';
    $title=$taskRef!==''?$taskRef:($goalRef!==''?$goalRef:($projectRef!==''?$projectRef:$contextKey));
    return [vp3_cognitive_continuity_item_v2440(
        'live-session:'.vp3_cognitive_text_v500($session['public_id']??'',120),
        'live_session',$state,$title,
        $status==='active'?'This is the current authorized live-session focus.':'This live-session focus is paused.',
        [
            'score_boost'=>$specific?8.0:0.0,
            'goal_ref'=>$goalRef,'task_ref'=>$taskRef,'project_ref'=>$projectRef,
            'surface'=>(string)($session['current_surface']??''),
            'resume_action'=>$status==='active'?'Continue the current authorized work.':'Resume this work only when the user or authoritative workflow state permits it.',
            'source'=>'cognitive_live_session_v2370',
            'updated_at'=>(string)($session['last_activity_at']??''),
            'metadata'=>['conversation_id'=>max(0,(int)($session['current_conversation_id']??0)),'context_key'=>$contextKey],
        ]
    )];
}

function vp3_cognitive_continuity_workflows_v2440(PDO $pdo,array $user,string $namespace): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!function_exists('agent_workflow_schema_ready_v1400')||!agent_workflow_schema_ready_v1400($pdo))return [];
    $agentId=vp3_cognitive_agent_id_v500($namespace);
    $where=$agentId>0?'agent_id=?':'agent_id IS NULL';
    $params=$agentId>0?[$uid,$agentId]:[$uid];
    try{
        $stmt=$pdo->prepare(
            "SELECT * FROM agent_workflow_runs
             WHERE owner_user_id=? AND {$where}
               AND status NOT IN ('completed','cancelled')
             ORDER BY updated_at DESC,id DESC LIMIT 12"
        );
        $stmt->execute($params);$rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}

    $out=[];
    foreach($rows as $row){
        $status=(string)($row['status']??'');
        $blockers=function_exists('agent_work_dependency_blockers_v174')
            ?agent_work_dependency_blockers_v174($pdo,$uid,(int)$row['id']):[];
        $blockerCount=count($blockers);
        $nextAt=(string)($row['next_attempt_at']??'');
        $scheduled=$nextAt!==''&&(strtotime($nextAt.' UTC')?:0)>time();
        $paused=!empty($row['paused_at'])||$status==='paused';
        $state=match(true){
            $status==='approval_pending'=>'needs_approval',
            $status==='failed'=>'repair_needed',
            $paused=>'paused',
            $blockerCount>0=>'blocked',
            $status==='executing'=>'working',
            $scheduled=>'scheduled',
            $status==='approved'=>'ready',
            default=>'planned',
        };
        $summary=vp3_cognitive_text_v500($row['decision_summary']??$row['goal']??'',700);
        $resume=match($state){
            'needs_approval'=>'Review and explicitly approve or reject the existing workflow.',
            'repair_needed'=>'Review the failure and use the existing retry/replan path.',
            'blocked'=>'Resolve the existing workflow dependencies before resuming.',
            'paused'=>'Resume the existing workflow if the user wants work to continue.',
            'scheduled'=>'Wait for the scheduled attempt or reschedule through Agent Work Control.',
            'ready'=>'Continue through the existing Agent Workflow executor.',
            'working'=>'Continue monitoring the existing workflow execution.',
            default=>'Continue through the existing workflow lifecycle.',
        };
        $out[]=vp3_cognitive_continuity_item_v2440(
            'workflow:'.(int)$row['id'],'workflow',$state,
            (string)($row['title']??'Agent workflow'),$summary,
            [
                'score_boost'=>min(10.0,max(0.0,((int)($row['work_priority']??50)-50)/5)),
                'requires_user'=>$state==='needs_approval',
                'requires_approval'=>$state==='needs_approval',
                'blocked_by'=>$blockerCount,
                'task_ref'=>'workflow:'.(int)$row['id'],
                'resume_action'=>$resume,
                'source'=>'agent_workflow_runs',
                'updated_at'=>(string)($row['updated_at']??''),
                'metadata'=>[
                    'workflow_type'=>(string)($row['workflow_type']??''),
                    'approval_status'=>(string)($row['approval_status']??''),
                    'next_attempt_at'=>$nextAt,
                    'progress_percent'=>max(0,min(100,(int)($row['progress_percent']??0))),
                ],
            ]
        );
    }
    return $out;
}

function vp3_cognitive_continuity_goals_v2440(PDO $pdo,array $user,string $namespace): array
{
    // The Phase 17.10 goal tables are owner-scoped but not Agent-namespaced.
    // Until their authority model carries an Agent namespace, only the System
    // Agent may project them into unified continuity.
    if($namespace!=='system'||!function_exists('agent_goal_strategy_schema_ready_v1710')||!agent_goal_strategy_schema_ready_v1710($pdo))return [];
    try{$goals=agent_goal_list_v1710($pdo,$user,false);}catch(Throwable $e){return [];}
    $out=[];
    foreach(array_slice($goals,0,4) as $entry){
        $goal=is_array($entry['goal']??null)?$entry['goal']:[];
        $goalId=(int)($goal['id']??0);if($goalId<1)continue;
        try{
            $execution=function_exists('agent_goal_execution_state_v1712')
                ?agent_goal_execution_state_v1712($pdo,$user,$goalId):[];
            $commitment=function_exists('agent_goal_commitment_state_v1715')
                ?agent_goal_commitment_state_v1715($pdo,$user,$goalId):[];
        }catch(Throwable $e){continue;}
        $execState=(string)($execution['execution_state']??'review_needed');
        if(in_array($execState,['achieved','archived'],true))continue;
        $decision=(string)($commitment['commitment']['decision']??'keep_working');
        $commitmentScore=max(0,min(100,(int)($commitment['commitment']['score_percent']??0)));
        $state=match($execState){
            'waiting_approval'=>'needs_approval',
            'blocked'=>'blocked',
            'repair_needed'=>'repair_needed',
            'executing'=>'working',
            'ready'=>'ready',
            'scheduled'=>'scheduled',
            'goal_paused','paused'=>'paused',
            'needs_objective','plan_missing'=>'needs_planning',
            'verification','objective_achieved'=>'verifying',
            default=>'needs_review',
        };
        if($decision!=='keep_working'&&$decision!=='completed'&&$decision!=='historical'&&$commitmentScore>=58&&$state!=='needs_approval'){
            $state='needs_user';
        }
        $title=vp3_cognitive_text_v500($goal['title']??$goal['goal']??'Goal',190);
        $reason=vp3_cognitive_text_v500(
            $commitment['commitment']['reason']??$execution['reason']??'',
            800
        );
        $out[]=vp3_cognitive_continuity_item_v2440(
            'goal:'.$goalId,'goal',$state,$title,$reason,
            [
                'score_boost'=>min(10.0,$commitmentScore/10),
                'requires_user'=>$state==='needs_user'||$state==='needs_approval',
                'requires_approval'=>$state==='needs_approval',
                'goal_ref'=>'goal:'.$goalId,
                'task_ref'=>isset($execution['focus']['id'])?'workflow:'.(int)$execution['focus']['id']:'',
                'resume_action'=>match($state){
                    'needs_user'=>'Review the existing goal commitment and decide whether to recommit, replan, pause, or archive.',
                    'needs_approval'=>'Review the next existing objective workflow approval.',
                    'blocked'=>'Resolve the canonical objective/workflow dependency.',
                    'repair_needed'=>'Repair or replan the existing objective workflow.',
                    'needs_planning'=>'Continue through the existing goal planning system.',
                    'verifying'=>'Wait for or complete canonical objective verification.',
                    'paused'=>'Resume the existing goal only when intentionally requested.',
                    default=>'Continue the existing goal execution path.',
                },
                'source'=>'agent_goals',
                'updated_at'=>(string)($goal['updated_at']??''),
                'metadata'=>[
                    'progress_percent'=>max(0,min(100,(int)($goal['progress_percent']??0))),
                    'target_date'=>(string)($goal['target_date']??''),
                    'commitment_decision'=>$decision,
                    'commitment_score_percent'=>$commitmentScore,
                    'execution_state'=>$execState,
                ],
            ]
        );
    }
    return $out;
}

function vp3_cognitive_continuity_orchestration_v2440(PDO $pdo,array $user,string $namespace): array
{
    if(!function_exists('vp3_cognitive_orchestration_schema_ready_v560')||!vp3_cognitive_orchestration_schema_ready_v560($pdo))return [];
    try{
        $stmt=$pdo->prepare(
            "SELECT * FROM cognitive_plan_runs_v560
             WHERE owner_user_id=? AND agent_namespace=?
               AND status NOT IN ('completed','closed','superseded','cancelled')
             ORDER BY updated_at DESC,id DESC LIMIT 8"
        );
        $stmt->execute([(int)$user['id'],$namespace]);$rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return [];}
    $out=[];
    foreach($rows as $run){
        try{
            $plan=vp3_cognitive_orchestration_plan_row_v560($pdo,$run);if(!$plan)continue;
            $underlying=vp3_cognitive_orchestration_underlying_ref_v560($plan);
            if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$underlying,'read'))continue;
        }catch(Throwable $e){continue;}
        $status=(string)($run['status']??'active');
        $state=match($status){
            'awaiting_approval'=>'needs_approval',
            'awaiting_user'=>'needs_user',
            'blocked'=>'blocked',
            'needs_replan'=>'repair_needed',
            'verifying'=>'verifying',
            default=>'working',
        };
        $title=vp3_cognitive_text_v500($plan['title']??$plan['summary']??'Accepted cognitive plan',190);
        $out[]=vp3_cognitive_continuity_item_v2440(
            'cognitive-plan:'.(string)$run['public_id'],'cognitive_plan',$state,$title,
            'This accepted plan remains open in the canonical Cognitive Orchestration runtime.',
            [
                'requires_user'=>in_array($state,['needs_user','needs_approval'],true),
                'requires_approval'=>$state==='needs_approval',
                'task_ref'=>'cognitive-plan:'.(string)$run['public_id'],
                'resume_action'=>match($state){
                    'needs_approval'=>'Continue through the existing authoritative approval handoff.',
                    'needs_user'=>'Ask for the user decision required by the current plan step.',
                    'blocked'=>'Resolve the current canonical plan blocker.',
                    'repair_needed'=>'Replan the existing accepted plan through v5.60.',
                    'verifying'=>'Continue waiting for canonical outcome evidence.',
                    default=>'Continue the existing accepted plan.',
                },
                'source'=>'cognitive_orchestration_v560',
                'updated_at'=>(string)($run['updated_at']??''),
                'metadata'=>[
                    'verification_state'=>(string)($run['verification_state']??''),
                    'current_step_key'=>(string)($run['current_step_key']??''),
                    'replan_count'=>(int)($run['replan_count']??0),
                    'source_object_ref'=>$underlying,
                ],
            ]
        );
    }
    return $out;
}

function vp3_cognitive_continuity_meetings_v2440(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system'||!function_exists('video_meeting_commitment_command_state_v18230'))return [];
    try{$state=video_meeting_commitment_command_state_v18230($pdo,(int)$user['id'],20);}catch(Throwable $e){return [];}
    if(empty($state['available']))return [];
    $out=[];
    foreach((array)($state['commitments']??[]) as $item){
        if(!is_array($item))continue;
        $command=is_array($item['command']??null)?$item['command']:[];
        $bucket=(string)($command['bucket']??'active');
        if($bucket==='verified')continue;
        $attention=!empty($command['attention_required']);
        $stateName=match($bucket){
            'action_failed'=>'repair_needed',
            'followthrough_blocked'=>'blocked',
            'overdue','handoff_drift','verification_needs_attention','verification_review','action_needs_review','plan_needs_definition','ready_for_handoff'=>'needs_user',
            'waiting_for_verification'=>'verifying',
            default=>'tracking',
        };
        $text=vp3_cognitive_text_v500($item['commitment']??'Meeting commitment',700);
        $meeting=is_array($item['meeting']??null)?$item['meeting']:[];
        $out[]=vp3_cognitive_continuity_item_v2440(
            'meeting-commitment:'.(int)($item['agenda_item_id']??0),'meeting_commitment',$stateName,
            $text,$attention?'This meeting commitment needs attention in its authoritative meeting workflow.':'This meeting commitment is still open and being followed through.',
            [
                'score_boost'=>$attention?6.0:0.0,
                'requires_user'=>$stateName==='needs_user',
                'task_ref'=>'meeting-commitment:'.(int)($item['agenda_item_id']??0),
                'project_ref'=>'meeting:'.vp3_cognitive_text_v500($meeting['public_id']??'',120),
                'surface'=>'meetings',
                'resume_action'=>'Continue through the existing meeting commitment/follow-through controls.',
                'source'=>'video_meeting_commitment_command_v18230',
                'updated_at'=>(string)($item['followthrough']['last_checked_at']??$meeting['start_at_utc']??''),
                'metadata'=>[
                    'bucket'=>$bucket,
                    'stage'=>(string)($command['stage']??''),
                    'meeting_title'=>vp3_cognitive_text_v500($meeting['title']??'',190),
                    'review_path'=>(string)($meeting['review_path']??''),
                ],
            ]
        );
    }
    return array_slice($out,0,4);
}

function vp3_cognitive_continuity_browser_v2440(PDO $pdo,array $user,string $namespace): array
{
    if($namespace!=='system'||!function_exists('vp3_browser_continuity_schema_ready_v2260')||!vp3_browser_continuity_schema_ready_v2260($pdo))return [];
    $uid=(int)($user['id']??0);if($uid<1)return [];
    try{
        $stmt=$pdo->prepare("SELECT * FROM browser_transaction_continuities_v2260
          WHERE owner_user_id=? AND tracking_status='active'
          ORDER BY updated_at DESC,id DESC LIMIT 8");
        $stmt->execute([$uid]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$rows)return [];
        $ids=array_values(array_map(static fn(array $row): int => (int)$row['id'],$rows));
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $proposalCounts=[];
        if($marks!==''&&table_exists('browser_transaction_followthrough_proposals_v2260')){
            $p=$pdo->prepare("SELECT continuity_id,COUNT(*) AS c
              FROM browser_transaction_followthrough_proposals_v2260
              WHERE owner_user_id=? AND status='proposed' AND continuity_id IN ({$marks})
              GROUP BY continuity_id");
            $p->execute(array_merge([$uid],$ids));
            foreach($p->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$proposalCounts[(int)$row['continuity_id']]=(int)$row['c'];
        }
    }catch(Throwable $e){return [];}

    $out=[];
    foreach($rows as $row){
        $continuity=vp3_browser_continuity_public_v2260($row);
        $proposalCount=max(0,(int)($proposalCounts[(int)$row['id']]??0));
        $requiresUser=$proposalCount>0;
        $state=$requiresUser?'needs_user':'tracking';
        $domain=vp3_cognitive_text_v500($continuity['domain']??'Browser transaction',190);
        $life=vp3_cognitive_text_v500($continuity['lifecycle_state']??'active',80);
        $out[]=vp3_cognitive_continuity_item_v2440(
            'browser-continuity:'.(string)($continuity['continuity_id']??''),'browser_continuity',$state,
            $domain.' — '.$life,
            $requiresUser?'A browser transaction follow-through proposal is waiting for user review.':'This browser transaction is still under canonical follow-through tracking.',
            [
                'score_boost'=>$requiresUser?5.0:0.0,
                'requires_user'=>$requiresUser,
                'task_ref'=>'browser-continuity:'.(string)($continuity['continuity_id']??''),
                'surface'=>'browser',
                'resume_action'=>$requiresUser?'Review the existing browser follow-through proposal.':'Continue monitoring through Browser Transaction Continuity.',
                'source'=>'browser_transaction_continuity_v2260',
                'updated_at'=>(string)($continuity['updated_at']??''),
                'metadata'=>[
                    'lifecycle_family'=>(string)($continuity['lifecycle_family']??''),
                    'lifecycle_state'=>$life,
                    'proposal_count'=>$proposalCount,
                    'last_change_at'=>(string)($continuity['last_change_at']??''),
                ],
            ]
        );
    }
    return array_slice($out,0,4);
}

function vp3_cognitive_continuity_sort_v2440(array $items): array
{
    $seen=[];$out=[];
    foreach($items as $item){
        if(!is_array($item))continue;
        $ref=(string)($item['ref']??'');if($ref===''||isset($seen[$ref]))continue;
        $seen[$ref]=true;$out[]=$item;
    }
    usort($out,static function(array $a,array $b): int {
        $score=((float)($b['score']??0))<=>((float)($a['score']??0));if($score!==0)return $score;
        $at=strtotime((string)($a['updated_at']??''))?:0;$bt=strtotime((string)($b['updated_at']??''))?:0;
        if($at!==$bt)return $bt<=>$at;
        return strcmp((string)($a['ref']??''),(string)($b['ref']??''));
    });
    return array_slice($out,0,VP3_COGNITIVE_CONTINUITY_MAX_ITEMS_V2440);
}

function vp3_cognitive_continuity_snapshot_v2440(PDO $pdo,array $user,string $namespace='system'): array
{
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $items=[];
    foreach(vp3_cognitive_continuity_live_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_continuity_workflows_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_continuity_goals_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_continuity_orchestration_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_continuity_meetings_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    foreach(vp3_cognitive_continuity_browser_v2440($pdo,$user,$namespace) as $item)$items[]=$item;
    $items=vp3_cognitive_continuity_sort_v2440($items);
    $counts=[];$waiting=0;
    foreach($items as $item){
        $state=(string)$item['state'];$counts[$state]=($counts[$state]??0)+1;
        if(!empty($item['requires_user'])||!empty($item['requires_approval']))$waiting++;
    }
    ksort($counts);
    return [
        'contract'=>VP3_COGNITIVE_CONTINUITY_CONTRACT_V2440,
        'build'=>VP3_COGNITIVE_CONTINUITY_V2440,
        'agent_namespace'=>$namespace,
        'focus'=>$items[0]??null,
        'items'=>$items,
        'counts'=>$counts,
        'waiting_for_user'=>$waiting,
        'authority'=>[
            'projection_only'=>true,
            'goal_store'=>'agent_goals',
            'workflow_store'=>'agent_workflow_runs',
            'plan_store'=>'cognitive_plan_runs_v560',
            'meeting_commitments'=>'video_meeting_commitment_command_v18230',
            'browser_followthrough'=>'browser_transaction_continuity_v2260',
            'execution_authority'=>false,
            'approval_authority'=>false,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_continuity_context_item_v2440(PDO $pdo,array $user,string $namespace): ?array
{
    $snapshot=vp3_cognitive_continuity_snapshot_v2440($pdo,$user,$namespace);
    if(empty($snapshot['items']))return null;
    $public=[];
    foreach(array_slice($snapshot['items'],0,8) as $item){
        $public[]=[
            'ref'=>(string)$item['ref'],
            'kind'=>(string)$item['kind'],
            'state'=>(string)$item['state'],
            'title'=>(string)$item['title'],
            'summary'=>(string)$item['summary'],
            'requires_user'=>!empty($item['requires_user']),
            'requires_approval'=>!empty($item['requires_approval']),
            'blocked_by'=>(int)$item['blocked_by'],
            'resume_action'=>(string)$item['resume_action'],
            'goal_ref'=>(string)$item['goal_ref'],
            'task_ref'=>(string)$item['task_ref'],
            'project_ref'=>(string)$item['project_ref'],
            'source'=>(string)$item['source'],
        ];
    }
    $json=json_encode([
        'focus'=>$public[0]??null,
        'open_items'=>$public,
        'waiting_for_user'=>(int)$snapshot['waiting_for_user'],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))return null;
    return vp3_cognitive_context_item_v2420(
        'continuity','cognitive-continuity:v2440','Open goal and task continuity',
        $json,94.0,['direct'=>true,'ephemeral_projection'=>true]
    );
}

function vp3_cognitive_continuity_resume_v2440(PDO $pdo,array $user,string $namespace): array
{
    $snapshot=vp3_cognitive_continuity_snapshot_v2440($pdo,$user,$namespace);
    $focus=is_array($snapshot['focus']??null)?$snapshot['focus']:null;
    if(!$focus)return [
        'resumable'=>false,'ref'=>'','kind'=>'','state'=>'','title'=>'',
        'goal_ref'=>'','task_ref'=>'','project_ref'=>'','resume_action'=>'',
        'requires_user'=>false,'requires_approval'=>false,'source'=>'',
    ];
    return [
        'resumable'=>true,
        'ref'=>(string)$focus['ref'],
        'kind'=>(string)$focus['kind'],
        'state'=>(string)$focus['state'],
        'title'=>(string)$focus['title'],
        'goal_ref'=>(string)$focus['goal_ref'],
        'task_ref'=>(string)$focus['task_ref'],
        'project_ref'=>(string)$focus['project_ref'],
        'resume_action'=>(string)$focus['resume_action'],
        'requires_user'=>!empty($focus['requires_user']),
        'requires_approval'=>!empty($focus['requires_approval']),
        'source'=>(string)$focus['source'],
    ];
}

function vp3_cognitive_continuity_activity_projection_v2440(PDO $pdo,array $user,string $namespace): array
{
    $snapshot=vp3_cognitive_continuity_snapshot_v2440($pdo,$user,$namespace);
    return [
        'build'=>VP3_COGNITIVE_CONTINUITY_V2440,
        'agent_namespace'=>$snapshot['agent_namespace'],
        'focus'=>$snapshot['focus'],
        'items'=>array_slice($snapshot['items'],0,6),
        'counts'=>$snapshot['counts'],
        'waiting_for_user'=>$snapshot['waiting_for_user'],
        'open_count'=>count($snapshot['items']),
        'projection_only'=>true,
    ];
}
