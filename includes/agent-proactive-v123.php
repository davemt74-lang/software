<?php
declare(strict_types=1);

const STONEFELLOW_AGENT_PROACTIVE_V123='agent-proactive-phase3-v123-20260826';

function agent_proactive_v123_feedback(int $uid,string $source): array
{
    $out=['shown'=>0,'acted'=>0,'dismissed'=>0];
    if($uid<1||!agent_proactive_v93_schema_ready())return $out;
    foreach(agent_proactive_v93_query("SELECT event_type,COUNT(*) c FROM agent_proactive_events WHERE user_id=? AND source_kind=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY) GROUP BY event_type",[$uid,$source]) as $row){$kind=(string)$row['event_type'];if(isset($out[$kind]))$out[$kind]=(int)$row['c'];}
    return $out;
}
function agent_proactive_v123_urgency(string $text): float
{
    $q=mb_strtolower($text);$score=0.2;
    if(preg_match('/\b(?:overdue|due today|urgent|critical|blocked|failed|unanswered)\b/u',$q))$score=1.0;
    elseif(preg_match('/\b(?:due tomorrow|due in [12] days|new message|approaching|upcoming)\b/u',$q))$score=0.84;
    elseif(preg_match('/\b(?:paused|idle|incomplete|unfinished|open)\b/u',$q))$score=0.68;
    elseif(preg_match('/\b(?:recent|momentum|updated|changed|repeated)\b/u',$q))$score=0.48;
    return $score;
}
function agent_proactive_v123_context_fit(string $surface,string $source,string $title,string $task=''): float
{
    $surface=mb_strtolower($surface);$source=mb_strtolower($source);$fit=0.35;
    if($surface==='stem'&&preg_match('/stem|studio|track|mix|production/',$source.' '.$title))$fit=1.0;
    elseif($surface==='video'&&preg_match('/video|media|content|edit/',$source.' '.$title))$fit=1.0;
    elseif($surface==='chat')$fit=0.62;
    if($task!==''&&agent_memory_v123_overlap($task,$title)>=0.3)$fit=max($fit,0.92);
    return $fit;
}
function agent_proactive_v123_score(array $candidate,array $activity,array $task=null): array
{
    $source=(string)($candidate['source']??'agent_brain');$title=(string)($candidate['title']??'');$reason=(string)($candidate['reason']??'');$uid=(int)($candidate['_user_id']??0);$feedback=agent_proactive_v123_feedback($uid,$source);
    $confidence=is_array($task)?max(0.0,min(1.0,(float)($task['confidence']??0.65))):max(0.2,min(1.0,(float)($candidate['_confidence']??0.68)));
    $urgency=agent_proactive_v123_urgency($title.' '.$reason);$recency=max(0.15,min(1.0,(float)($candidate['_recency']??0.62)));
    if(is_array($task)&&!empty($task['last_seen_at'])){$ts=strtotime((string)$task['last_seen_at'])?:time();$recency=max(0.15,1.0-min(1.0,(time()-$ts)/(45*86400)));}
    $repetition=is_array($task)?min(1.0,log(1+max(1,(int)($task['occurrences']??1)),6)):max(0.1,min(1.0,(float)($candidate['_repetition']??0.35)));
    $contextFit=agent_proactive_v123_context_fit((string)($candidate['_surface']??'chat'),$source,$title,(string)($activity['task_title']??''));
    $actionability=trim((string)($candidate['prompt']??''))!==''?0.9:0.45;if(trim((string)($candidate['url']??''))!=='')$actionability=min(1.0,$actionability+0.08);
    $feedbackScore=0.5;if($feedback['acted']+$feedback['dismissed']>0)$feedbackScore=max(0.05,min(1.0,0.5+($feedback['acted']*0.12)-($feedback['dismissed']*0.18)));
    $interruptibility=empty($activity['interruptible'])?0.38:1.0;if((string)($activity['state']??'')==='working'&&$source==='activity_working')$interruptibility=0.24;
    $score=($confidence*0.20)+($urgency*0.23)+($recency*0.12)+($repetition*0.08)+($contextFit*0.14)+($actionability*0.10)+($feedbackScore*0.08)+($interruptibility*0.05);
    return ['score'=>round(max(0.0,min(1.0,$score)),6),'components'=>['confidence'=>round($confidence,4),'urgency'=>round($urgency,4),'recency'=>round($recency,4),'repetition'=>round($repetition,4),'context_fit'=>round($contextFit,4),'actionability'=>round($actionability,4),'feedback'=>round($feedbackScore,4),'interruptibility'=>round($interruptibility,4)],'feedback'=>$feedback];
}
function agent_proactive_v123_event(array $candidate,?array $task=null): array
{
    $source=(string)($candidate['source']??'agent_brain');$key=(string)($candidate['key']??$candidate['hash']??sha1(json_encode($candidate)));$eventId='event-'.sha1($source.'|'.$key);$kind=is_array($task)?'task_state':'ecosystem_change';
    return ['id'=>$eventId,'type'=>'event','event_kind'=>$kind,'source'=>$source,'title'=>(string)($candidate['reason']??$candidate['title']??'Stonefellow observed a change.'),'summary'=>(string)($candidate['reason']??''),'occurred_at'=>(string)($task['last_seen_at']??$candidate['_occurred_at']??date('Y-m-d H:i:s')),'evidence'=>is_array($task)?['task_key'=>$task['task_key'],'status'=>$task['status'],'confidence'=>$task['confidence']]:['candidate_key'=>$key]];
}
function agent_proactive_v123_action(array $candidate,array $event,array $rank): array
{
    $score=(float)$rank['score'];
    return ['hash'=>(string)($candidate['hash']??sha1((string)$event['id'].'|'.(string)($candidate['prompt']??''))),'key'=>(string)($candidate['key']??$event['id']),'action_id'=>'action-'.sha1((string)$event['id'].'|'.(string)($candidate['title']??'')),'event_id'=>(string)$event['id'],'type'=>'recommended_action','title'=>(string)($candidate['title']??'Next action'),'prompt'=>(string)($candidate['prompt']??''),'reason'=>(string)($candidate['reason']??''),'source'=>(string)($candidate['source']??'agent_brain'),'url'=>(string)($candidate['url']??''),'score'=>$score,'priority'=>(int)round($score*200),'score_components'=>$rank['components'],'feedback'=>$rank['feedback']];
}
function agent_proactive_v123_task_candidates(array $user,string $surface,array $activity): array
{
    $out=[];foreach(agent_memory_v123_tasks($user,false) as $task){$status=(string)$task['status'];$title=trim((string)$task['title'])?:'Open task';$text=(string)$task['text'];$reason=ucwords(str_replace('_',' ',$status)).' task from Agent Brain';if($task['due_at']!=='')$reason.=' · '.$task['due_at'];$out[]=['hash'=>sha1('task|'.$task['task_key']),'key'=>'task:'.$task['task_key'],'title'=>($status==='waiting'?'Check ':'Continue ').$title,'prompt'=>'Review this Agent Brain task, its current state and related project context, then help me take the next useful step: '.$text,'reason'=>$reason,'source'=>'task_lifecycle','url'=>'','_task'=>$task,'_confidence'=>$task['confidence'],'_occurred_at'=>$task['last_seen_at']];}return $out;
}
function agent_proactive_v123_evidence_candidates(array $user,string $surface,array $context,array $activity): array
{
    $uid=(int)($user['id']??0);$candidates=[];
    if(function_exists('agent_ecosystem_v118_scan')){
        $scanSince=function_exists('agent_action_v124_scan_since')?agent_action_v124_scan_since($user,$surface):date('Y-m-d H:i:s',time()-30*86400);
        foreach(agent_ecosystem_v118_scan($user,$scanSince) as $item){
            if(!is_array($item))continue;$title=(string)($item['title']??'Next action');$reason=(string)($item['body']??'Stonefellow observed a useful change.');$source=(string)($item['source']??'ecosystem');$key=(string)($item['key']??$item['id']??sha1($title.'|'.$reason));
            $candidates[]=['hash'=>sha1('v123|'.$key),'key'=>'ecosystem:'.$key,'title'=>$title,'prompt'=>'Review this Stonefellow ecosystem event and help me take the best next action: '.$reason,'reason'=>$reason,'source'=>$source,'url'=>(string)($item['target_url']??''),'_occurred_at'=>(string)($item['created_at']??''),'_recency'=>0.78];
        }
    }else{
        $legacy=agent_proactive_v93_suggestions($user,$surface,$context);foreach((array)($legacy['suggestions']??[]) as $item)if(is_array($item))$candidates[]=$item;
    }
    $activityTask=trim((string)($activity['task_title']??''));
    if($activityTask!==''&&in_array((string)($activity['state']??''),['paused','idle'],true))$candidates[]=['hash'=>sha1('activity|'.$activityTask),'key'=>'activity:'.sha1($activityTask),'title'=>'Resume '.$activityTask,'prompt'=>'Review where I stopped on '.$activityTask.' and help me continue with the most useful next step.','reason'=>ucfirst((string)$activity['state']).' active task','source'=>'activity_resume','url'=>'','_confidence'=>0.9,'_recency'=>0.95];
    foreach(agent_proactive_v123_task_candidates($user,$surface,$activity) as $item)$candidates[]=$item;
    if(table_exists('agent_memory_items'))foreach(agent_proactive_v93_query("SELECT subject,memory_text,occurrence_count,confidence,last_seen_at FROM agent_memory_items WHERE user_id=? AND is_active=1 AND memory_type='theme' AND occurrence_count>=3 ORDER BY last_seen_at DESC LIMIT 12",[$uid]) as $row){$subject=agent_proactive_v93_text((string)$row['subject'],80);if($subject==='')continue;$candidates[]=['hash'=>sha1('theme|'.$subject),'key'=>'theme:'.sha1($subject),'title'=>'Go deeper on '.$subject,'prompt'=>'Use the recurring Agent Brain pattern around "'.$subject.'" to identify one concrete thing I can make, finish, improve, or follow up on now.','reason'=>'Recurring Agent Brain theme · '.(int)$row['occurrence_count'].' occurrences','source'=>'pattern','url'=>'','_confidence'=>(float)$row['confidence'],'_repetition'=>min(1.0,(int)$row['occurrence_count']/8),'_occurred_at'=>(string)$row['last_seen_at']];}
    if(table_exists('agent_edit_events'))foreach(agent_proactive_v93_query("SELECT editor_kind,action_key,COUNT(*) c,MAX(created_at) last_seen FROM agent_edit_events WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY editor_kind,action_key HAVING COUNT(*)>=4 ORDER BY last_seen DESC LIMIT 8",[$uid]) as $row){$label=str_replace(['_','.'],' ',(string)$row['action_key']);$candidates[]=['hash'=>sha1('edit|'.$row['editor_kind'].'|'.$row['action_key']),'key'=>'edit-pattern:'.$row['editor_kind'].':'.$row['action_key'],'title'=>'Review repeated '.$label.' edits','prompt'=>'Review my repeated '.$label.' changes in the '.$row['editor_kind'].' editor and tell me whether to consolidate, automate, or improve the pattern.','reason'=>'Repeated edit pattern · '.(int)$row['c'].' changes in 14 days','source'=>'edit_pattern','url'=>'','_repetition'=>min(1.0,(int)$row['c']/10),'_occurred_at'=>(string)$row['last_seen']];}
    if(table_exists('agent_tool_history'))foreach(agent_proactive_v93_query("SELECT tool_key,COUNT(*) c,MAX(created_at) last_seen FROM agent_tool_history WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) GROUP BY tool_key HAVING COUNT(*)>=3 ORDER BY last_seen DESC LIMIT 6",[$uid]) as $row){$label=str_replace(['_','.'],' ',(string)$row['tool_key']);$candidates[]=['hash'=>sha1('tool|'.$row['tool_key']),'key'=>'tool-pattern:'.$row['tool_key'],'title'=>'Keep momentum on '.$label,'prompt'=>'Review my recent '.$label.' history and suggest the most useful next task related to it.','reason'=>'Repeated Agent tool usage · '.(int)$row['c'].' times','source'=>'tool_pattern','url'=>'','_repetition'=>min(1.0,(int)$row['c']/8),'_occurred_at'=>(string)$row['last_seen']];}
    return $candidates;
}
function agent_proactive_v123_suggestions(array $user,string $surface='chat',array $context=[]): array
{
    $uid=(int)($user['id']??0);$surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';$reconciliation=agent_memory_v123_reconcile_user($user);$profile=agent_proactive_v93_profile($user);$activity=function_exists('agent_activity_v94_snapshot')?agent_activity_v94_snapshot($user,$surface,$context):['state'=>'idle','task_title'=>'','interruptible'=>true];
    $candidates=agent_proactive_v123_evidence_candidates($user,$surface,$context,$activity);$events=[];$actions=[];$seen=[];
    foreach($candidates as $candidate){if(!is_array($candidate))continue;$task=is_array($candidate['_task']??null)?$candidate['_task']:null;$candidate['_user_id']=$uid;$candidate['_surface']=$surface;$event=agent_proactive_v123_event($candidate,$task);$rank=agent_proactive_v123_score($candidate,$activity,$task);$action=agent_proactive_v123_action($candidate,$event,$rank);$dedup=(string)$action['hash'];if(isset($seen[$dedup])&&$seen[$dedup]>=$action['score'])continue;$seen[$dedup]=$action['score'];$events[$event['id']]=$event;$actions[]=$action;}
    usort($actions,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp((string)$a['title'],(string)$b['title']));$limit=max(1,(int)($profile['limit']??4));$actions=array_slice($actions,0,$limit);$eventIds=array_flip(array_map(static fn(array $a):string=>(string)$a['event_id'],$actions));$events=array_values(array_filter($events,static fn(array $e):bool=>isset($eventIds[(string)$e['id']])));
    if(!$actions){$fallback=['hash'=>sha1('v123-fallback'),'key'=>'fallback:next','title'=>'Ask Stonefellow for a next move','prompt'=>'Look across my Agent Brain, current projects, tasks and recent changes and give me one useful next action.','reason'=>'No higher-confidence action is currently waiting.','source'=>'fallback','url'=>'','_user_id'=>$uid,'_surface'=>$surface];$event=agent_proactive_v123_event($fallback);$rank=agent_proactive_v123_score($fallback,$activity);$events=[$event];$actions=[agent_proactive_v123_action($fallback,$event,$rank)];}
    return ['profile'=>$profile,'activity'=>$activity,'events'=>$events,'suggestions'=>$actions,'reconciliation'=>$reconciliation,'scoring'=>'dynamic-v123','candidate_pool'=>'evidence-first'];
}
