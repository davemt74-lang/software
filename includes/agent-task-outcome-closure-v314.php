<?php
declare(strict_types=1);

/**
 * Agent Brain task outcome closure v314.
 *
 * Bridges explicit task lifecycle closure back into the existing proactive
 * outcome learner. This deliberately does not create a second learner or task
 * store. A task can only train recommendation quality when its exact proactive
 * suggestion was actually surfaced/acted and that exposure has not already
 * been finalized.
 */
const STONEFELLOW_AGENT_TASK_OUTCOME_CLOSURE_V314='agent-task-outcome-closure-v314-20260907';
const STONEFELLOW_AGENT_TASK_OUTCOME_SCAN_SECONDS_V314=60;
const STONEFELLOW_AGENT_TASK_OUTCOME_LOOKBACK_DAYS_V314=60;

function agent_task_outcome_v314_status_outcome(string $status): string
{
    return match(strtolower(trim($status))){
        'completed'=>'resolved',
        'cancelled'=>'ignored',
        default=>'',
    };
}

function agent_task_outcome_v314_hash(string $taskKey): string
{
    $taskKey=trim($taskKey);
    return $taskKey===''?'':sha1('task|'.$taskKey);
}

function agent_task_outcome_v314_cycle_state(int $userId,string $hash): array
{
    $result=['eligible'=>false,'reason'=>'no-exposure','exposure_at'=>'','final_outcome'=>'','final_at'=>''];
    if($userId<1||!preg_match('/^[a-f0-9]{40}$/',$hash)||!table_exists('agent_proactive_events'))return $result;

    $pdo=db();
    if(!$pdo)return $result;
    try{
        $stmt=$pdo->prepare(
            "SELECT event_type,source_kind,context_json,created_at
             FROM agent_proactive_events
             WHERE user_id=? AND suggestion_hash=? AND source_kind='task_lifecycle'
               AND event_type IN ('shown','acted','dismissed')
               AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY)
             ORDER BY id DESC LIMIT 80"
        );
        $stmt->execute([$userId,$hash]);
        $rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){
        return ['eligible'=>false,'reason'=>'ledger-unavailable','exposure_at'=>'','final_outcome'=>'','final_at'=>''];
    }

    $exposureAt='';
    foreach($rows as $row){
        $eventType=(string)($row['event_type']??'');
        $context=function_exists('agent_action_v313_feedback_context')
            ? agent_action_v313_feedback_context((string)($row['context_json']??''))
            : (json_decode((string)($row['context_json']??''),true)?:[]);
        $outcome=function_exists('agent_action_v313_normalize_outcome')
            ? agent_action_v313_normalize_outcome((string)($context['outcome']??''),$eventType)
            : ($eventType==='dismissed'?'ignored':($eventType==='acted'?'acted':''));

        if(in_array($outcome,['successful','resolved','unsuccessful','ignored'],true)){
            if($exposureAt!==''){
                return ['eligible'=>true,'reason'=>'new-exposure-after-final','exposure_at'=>$exposureAt,'final_outcome'=>$outcome,'final_at'=>(string)($row['created_at']??'')];
            }
            return ['eligible'=>false,'reason'=>'already-final','exposure_at'=>'','final_outcome'=>$outcome,'final_at'=>(string)($row['created_at']??'')];
        }

        if($eventType==='shown'||($eventType==='acted'&&$outcome==='acted')){
            if($exposureAt==='')$exposureAt=(string)($row['created_at']??'');
        }
    }

    if($exposureAt!=='')return ['eligible'=>true,'reason'=>'open-exposure','exposure_at'=>$exposureAt,'final_outcome'=>'','final_at'=>''];
    return $result;
}

function agent_task_outcome_v314_close_task(array $user,array $task): array
{
    $userId=(int)($user['id']??0);
    $status=strtolower(trim((string)($task['status']??'')));
    $outcome=agent_task_outcome_v314_status_outcome($status);
    $taskKey=trim((string)($task['task_key']??''));
    $hash=agent_task_outcome_v314_hash($taskKey);
    if($userId<1||$outcome===''||$hash==='')return ['recorded'=>false,'reason'=>'not-final-task'];
    if(!function_exists('agent_action_v124_record_outcome'))return ['recorded'=>false,'reason'=>'outcome-writer-unavailable'];

    $cycle=agent_task_outcome_v314_cycle_state($userId,$hash);
    if(empty($cycle['eligible']))return ['recorded'=>false,'reason'=>(string)($cycle['reason']??'no-exposure'),'cycle'=>$cycle];

    $origin=is_array($task['origin']??null)?$task['origin']:[];
    $context=[
        'automatic'=>true,
        'trigger'=>'task_lifecycle',
        'task_key'=>$taskKey,
        'task_status'=>$status,
        'memory_id'=>max(0,(int)($task['memory_id']??0)),
        'source_label'=>trim((string)($task['source_label']??'')),
        'source_url'=>trim((string)($task['source_url']??'')),
        'origin'=>$origin,
        'exposure_at'=>(string)($cycle['exposure_at']??''),
        'closure_build'=>STONEFELLOW_AGENT_TASK_OUTCOME_CLOSURE_V314,
    ];
    $result=agent_action_v124_record_outcome($user,$hash,$outcome,'task_lifecycle_auto',[
        'source'=>'task_lifecycle',
        'outcome'=>$outcome,
        'context'=>$context,
    ]);
    $result['cycle']=$cycle;
    $result['task_key']=$taskKey;
    $result['task_status']=$status;
    $result['automatic']=true;
    $result['closure_build']=STONEFELLOW_AGENT_TASK_OUTCOME_CLOSURE_V314;

    if(!empty($result['recorded'])&&function_exists('agent_runtime_v125_trace')){
        agent_runtime_v125_trace('brain.task_outcome_auto_closed',[
            'user_id'=>$userId,
            'task_key'=>$taskKey,
            'task_status'=>$status,
            'outcome'=>$outcome,
            'memory_id'=>max(0,(int)($task['memory_id']??0)),
        ]);
    }
    return $result;
}

function agent_task_outcome_v314_reconcile(array $user): array
{
    $summary=['ready'=>false,'examined'=>0,'final_tasks'=>0,'eligible'=>0,'recorded'=>0,'duplicates'=>0,'skipped'=>0];
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('agent_memory_v123_tasks'))return $summary;
    $summary['ready']=true;

    try{$tasks=agent_memory_v123_tasks($user,true);}catch(Throwable $e){return $summary;}
    $summary['examined']=count($tasks);
    foreach($tasks as $task){
        if(!is_array($task))continue;
        $outcome=agent_task_outcome_v314_status_outcome((string)($task['status']??''));
        if($outcome==='')continue;
        $summary['final_tasks']++;
        $result=agent_task_outcome_v314_close_task($user,$task);
        if(!empty($result['cycle']['eligible']))$summary['eligible']++;
        if(!empty($result['recorded']))$summary['recorded']++;
        elseif(!empty($result['duplicate']))$summary['duplicates']++;
        else $summary['skipped']++;
    }
    return $summary;
}

function agent_task_outcome_v314_boot(): void
{
    if(PHP_SAPI==='cli'||!function_exists('current_user'))return;
    try{
        $user=current_user();
        $userId=(int)($user['id']??0);
        if(!$user||$userId<1)return;
        if(function_exists('has_permission')&&!has_permission('chat.access',$user))return;
        if(!table_exists('agent_proactive_events')||!table_exists('agent_memory_items'))return;

        $sessionKey='agent_task_outcome_v314_last_'.$userId;
        $last=max(0,(int)($_SESSION[$sessionKey]??0));
        if($last>0&&time()-$last<STONEFELLOW_AGENT_TASK_OUTCOME_SCAN_SECONDS_V314)return;
        // Claim the cadence before reconciliation so nested application calls
        // cannot recursively run the same closure scan.
        $_SESSION[$sessionKey]=time();
        agent_task_outcome_v314_reconcile($user);
    }catch(Throwable $e){
        if(function_exists('agent_runtime_v125_trace')){
            agent_runtime_v125_trace('brain.task_outcome_auto_close_failed',['error_class'=>get_class($e)]);
        }
    }
}
