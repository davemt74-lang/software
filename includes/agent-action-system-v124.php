<?php
declare(strict_types=1);

const STONEFELLOW_AGENT_ACTION_V124='agent-action-phase4-v124-20260826';

function agent_action_v124_query(string $sql,array $params=[]): array
{
    $pdo=db();if(!$pdo)return [];
    try{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function agent_action_v124_scan_subject(string $surface): string
{
    return 'proactive-scan:'.preg_replace('/[^a-z0-9_-]/','',strtolower($surface));
}

function agent_action_v124_scan_since(array $user,string $surface='chat'): string
{
    $fallback=date('Y-m-d H:i:s',time()-86400);
    if(!function_exists('agent_brain_v122_memory'))return $fallback;
    $row=agent_brain_v122_memory($user,'scan_cursor',agent_action_v124_scan_subject($surface));
    if(!$row)return $fallback;
    $meta=json_decode((string)($row['metadata_json']??''),true);$value=is_array($meta)?(string)($meta['last_scan_at']??''):'';$ts=strtotime($value);
    if($ts===false)return $fallback;
    return date('Y-m-d H:i:s',max(time()-7*86400,$ts-300));
}

function agent_action_v124_advance_scan(array $user,string $surface,string $startedAt): void
{
    if(!function_exists('agent_brain_v122_upsert_system_memory'))return;
    $subject=agent_action_v124_scan_subject($surface);$text='Incremental proactive scan cursor for '.$surface.'. Last successful scan began at '.$startedAt.'.';
    agent_brain_v122_upsert_system_memory($user,'scan_cursor',$subject,$text,['last_scan_at'=>$startedAt,'surface'=>$surface,'runtime'=>STONEFELLOW_AGENT_ACTION_V124],0.99);
}

function agent_action_v124_feedback_rows(int $uid,string $where,array $params): array
{
    $out=['shown'=>0,'acted'=>0,'dismissed'=>0,'last_shown'=>'','last_acted'=>'','last_dismissed'=>''];
    if($uid<1||!table_exists('agent_proactive_events'))return $out;
    $rows=agent_action_v124_query("SELECT event_type,COUNT(*) c,MAX(created_at) latest FROM agent_proactive_events WHERE user_id=? AND {$where} AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY) GROUP BY event_type",array_merge([$uid],$params));
    foreach($rows as $row){$kind=(string)$row['event_type'];if(isset($out[$kind])){$out[$kind]=(int)$row['c'];$out['last_'.$kind]=(string)$row['latest'];}}
    return $out;
}

function agent_action_v124_feedback(int $uid,string $hash): array
{
    return agent_action_v124_feedback_rows($uid,'suggestion_hash=?',[$hash]);
}

function agent_action_v124_source_feedback(int $uid,string $source): array
{
    return agent_action_v124_feedback_rows($uid,'source_kind=?',[$source]);
}

function agent_action_v124_suppression(array $action,int $uid): array
{
    $feedback=agent_action_v124_feedback($uid,(string)($action['hash']??''));$now=time();
    $lastDismiss=strtotime((string)$feedback['last_dismissed'])?:0;$lastActed=strtotime((string)$feedback['last_acted'])?:0;$lastShown=strtotime((string)$feedback['last_shown'])?:0;
    $source=(string)($action['source']??'');$score=(float)($action['score']??0.5);
    $dismissCooldown=$feedback['dismissed']>=3?21*86400:($feedback['dismissed']===2?14*86400:7*86400);
    $actedCooldown=in_array($source,['task_lifecycle','activity_resume','release_calendar'],true)?12*3600:2*86400;
    $repeatCooldown=$score>=0.82?2*3600:($score>=0.65?6*3600:18*3600);
    $suppressed=false;$reason='';$until=0;
    if($lastDismiss>0&&$now-$lastDismiss<$dismissCooldown){$suppressed=true;$reason='dismissed-cooldown';$until=$lastDismiss+$dismissCooldown;}
    elseif($lastActed>0&&$now-$lastActed<$actedCooldown){$suppressed=true;$reason='acted-cooldown';$until=$lastActed+$actedCooldown;}
    elseif($lastShown>0&&$now-$lastShown<$repeatCooldown){$suppressed=true;$reason='repeat-cooldown';$until=$lastShown+$repeatCooldown;}
    return ['suppressed'=>$suppressed,'reason'=>$reason,'until'=>$until>0?date('c',$until):'','feedback'=>$feedback,'cooldowns'=>['dismiss_seconds'=>$dismissCooldown,'acted_seconds'=>$actedCooldown,'repeat_seconds'=>$repeatCooldown]];
}

function agent_action_v124_risk(array $action): array
{
    $source=mb_strtolower((string)($action['source']??''));$text=mb_strtolower((string)($action['title']??'').' '.(string)($action['prompt']??''));
    $external=(bool)preg_match('/\b(?:send|publish|post|book|purchase|delete|refund|email|message|release|upload|submit|invite|schedule)\b/u',$text);
    if(preg_match('/release_calendar|booking|messages|posts|merch|external/',$source))$external=true;
    $destructive=(bool)preg_match('/\b(?:delete|remove permanently|refund|cancel booking|revoke)\b/u',$text);
    return ['level'=>$destructive?'high':($external?'medium':'low'),'external_side_effect'=>$external,'destructive'=>$destructive,'requires_approval'=>$external||$destructive];
}

function agent_action_v124_plan(array $action,array $event,array $dependencies=[]): array
{
    $risk=agent_action_v124_risk($action);$steps=[];
    $steps[]=['id'=>'inspect','kind'=>'read','label'=>'Inspect supporting evidence','instruction'=>'Review the linked event, current project state, Agent Brain context, and any recent related changes.','requires_approval'=>false];
    if($dependencies)$steps[]=['id'=>'dependencies','kind'=>'resolve','label'=>'Resolve dependencies','instruction'=>'Check the listed dependency nodes and unblock or finish prerequisites before changing the target.','requires_approval'=>false,'depends_on'=>$dependencies];
    $steps[]=['id'=>'prepare','kind'=>'prepare','label'=>'Prepare the next action','instruction'=>(string)($action['prompt']??'Prepare the next useful action.'),'requires_approval'=>false];
    $steps[]=['id'=>'execute','kind'=>'execute','label'=>'Execute when allowed','instruction'=>(string)($action['prompt']??'Execute the prepared action.'),'requires_approval'=>$risk['requires_approval']];
    $steps[]=['id'=>'verify','kind'=>'verify','label'=>'Verify the result','instruction'=>'Confirm the intended state changed, capture the outcome, and update related task or commitment status.','requires_approval'=>false];
    return ['plan_id'=>'plan-'.sha1((string)($action['action_id']??$action['hash']??'').'|'.(string)$event['id']),'event_id'=>(string)$event['id'],'action_id'=>(string)($action['action_id']??''),'risk'=>$risk,'steps'=>$steps,'ready'=>!$dependencies,'requires_approval'=>$risk['requires_approval']];
}

function agent_action_v124_dependency_graph(array $actions,array $tasks): array
{
    $nodes=[];$edges=[];$taskNodes=[];
    foreach($tasks as $task){$id='task-'.(string)$task['task_key'];$taskNodes[$id]=$task;$nodes[$id]=['id'=>$id,'type'=>'task','label'=>(string)$task['title'],'status'=>(string)$task['status'],'confidence'=>(float)$task['confidence']];}
    foreach($actions as $action){$id=(string)($action['action_id']??'action-'.sha1((string)($action['hash']??'')));$nodes[$id]=['id'=>$id,'type'=>'action','label'=>(string)($action['title']??'Action'),'status'=>'proposed','score'=>(float)($action['score']??0)];
        foreach($taskNodes as $taskId=>$task){$overlap=function_exists('agent_memory_v123_overlap')?agent_memory_v123_overlap((string)($action['title']??'').' '.(string)($action['reason']??''),(string)$task['title'].' '.(string)$task['text']):0.0;
            if($overlap>=0.32){$blocking=(string)$task['status']==='waiting';$edges[]=['from'=>$taskId,'to'=>$id,'type'=>$blocking?'blocks':'relates_to','strength'=>round($overlap,4)];}
        }
    }
    $blocked=[];foreach($edges as $edge)if($edge['type']==='blocks')$blocked[]=(string)$edge['to'];
    return ['nodes'=>array_values($nodes),'edges'=>$edges,'blocked_actions'=>array_values(array_unique($blocked))];
}

function agent_action_v124_outcome_factor(array $feedback): float
{
    $acted=(int)($feedback['acted']??0);$dismissed=(int)($feedback['dismissed']??0);$total=$acted+$dismissed;
    if($total===0)return 1.0;$rate=$acted/max(1,$total);return max(0.55,min(1.25,0.72+$rate*0.53-($dismissed>=3?0.12:0.0)));
}

function agent_action_v124_mark_shown(array $user,array $action,string $surface): void
{
    $uid=(int)($user['id']??0);if($uid<1||!function_exists('agent_proactive_v93_event'))return;
    $feedback=agent_action_v124_feedback($uid,(string)$action['hash']);$last=strtotime((string)$feedback['last_shown'])?:0;
    if($last>0&&time()-$last<6*3600)return;
    agent_proactive_v93_event($user,(string)$action['hash'],'shown',$surface,['title'=>(string)$action['title'],'prompt'=>(string)$action['prompt'],'source'=>(string)$action['source'],'context'=>['action_id'=>$action['action_id']??'','event_id'=>$action['event_id']??'','score'=>$action['score']??0,'runtime'=>STONEFELLOW_AGENT_ACTION_V124]]);
}

function agent_action_v124_enrich_result(array $result,array $user,string $surface='chat',array $context=[],?string $scanStartedAt=null): array
{
    $uid=(int)($user['id']??0);$tasks=function_exists('agent_memory_v123_tasks')?agent_memory_v123_tasks($user,false):[];$sourceActions=array_values(array_filter((array)($result['suggestions']??[]),'is_array'));
    $graph=agent_action_v124_dependency_graph($sourceActions,$tasks);$events=[];foreach((array)($result['events']??[]) as $event)if(is_array($event)&&!empty($event['id']))$events[(string)$event['id']]=$event;
    $visible=[];$suppressed=[];
    foreach($sourceActions as $action){$state=agent_action_v124_suppression($action,$uid);if($state['suppressed']){$suppressed[]=['hash'=>$action['hash']??'','action_id'=>$action['action_id']??'','title'=>$action['title']??'','state'=>$state];continue;}
        $eventId=(string)($action['event_id']??'');$event=$events[$eventId]??['id'=>$eventId!==''?$eventId:'event-'.sha1((string)($action['hash']??'')),'type'=>'event','event_kind'=>'recommendation_context','source'=>$action['source']??'agent','title'=>$action['reason']??$action['title']??'','summary'=>$action['reason']??'','occurred_at'=>date('Y-m-d H:i:s'),'evidence'=>[]];
        $deps=[];foreach($graph['edges'] as $edge)if($edge['to']===(string)($action['action_id']??'')&&$edge['type']==='blocks')$deps[]=$edge['from'];
        $sourceFeedback=agent_action_v124_source_feedback($uid,(string)($action['source']??''));$factor=agent_action_v124_outcome_factor($sourceFeedback);$base=(float)($action['score']??0.5);$learned=max(0.0,min(1.0,$base*$factor));
        $action['base_score']=$base;$action['outcome_factor']=round($factor,4);$action['score']=round($learned,6);$action['priority']=(int)round($learned*200);$action['suppression']=$state;$action['source_outcomes']=$sourceFeedback;$action['plan']=agent_action_v124_plan($action,$event,$deps);$visible[]=$action;
    }
    usort($visible,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp((string)$a['title'],(string)$b['title']));$limit=max(1,(int)($result['profile']['limit']??4));$visible=array_slice($visible,0,$limit);
    $used=array_flip(array_map(static fn(array $a):string=>(string)($a['event_id']??''),$visible));$result['events']=array_values(array_filter($events,static fn(array $e):bool=>isset($used[(string)$e['id']])));$result['suggestions']=$visible;$result['suppressed_actions']=$suppressed;$result['dependency_graph']=$graph;$result['tasks']=$tasks;$result['outcome_learning']='v124';$result['suppression_lifecycle']='v124';$result['action_plans']='v124';
    foreach($visible as $action)agent_action_v124_mark_shown($user,$action,$surface);
    if($scanStartedAt!==null)agent_action_v124_advance_scan($user,$surface,$scanStartedAt);
    return $result;
}

function agent_action_v124_record_outcome(array $user,string $hash,string $eventType,string $surface,array $payload=[]): void
{
    if(!in_array($eventType,['acted','dismissed'],true)||!function_exists('agent_proactive_v93_event'))return;
    agent_proactive_v93_event($user,$hash,$eventType,$surface,$payload);
    if($eventType==='acted'&&!empty($payload['memory_id'])&&function_exists('agent_task_v123_update')&&!empty($payload['task_status']))agent_task_v123_update($user,(int)$payload['memory_id'],(string)$payload['task_status'],'action_outcome');
}
