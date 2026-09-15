<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.9 — Objective Portfolio + Priority Arbitration.
 *
 * Derived, owner-scoped portfolio intelligence over the canonical objective,
 * dependency, work-control, proactive proposal, and outcome-memory ledgers.
 * No new scheduler, worker, queue, dashboard, or persistence table is added.
 * Automatic arbitration is advisory only; mutations require explicit chat input.
 */
const VP3_AGENT_OBJECTIVE_PORTFOLIO_V179='agent-objective-portfolio-v179-20260915';
const VP3_AGENT_OBJECTIVE_PORTFOLIO_LIMIT_V179=12;
const VP3_AGENT_OBJECTIVE_PORTFOLIO_DUPLICATE_THRESHOLD_V179=0.48;

require_once __DIR__.'/agent-proactive-objectives-v178.php';

function agent_objective_portfolio_ready_v179(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_objective_ready_v175($pdo)
        && agent_work_control_schema_ready_v173($pdo)
        && agent_work_dependencies_schema_ready_v174($pdo));
}

function agent_objective_portfolio_require_v179(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Objective Portfolio is not available for this account.');
    if(!agent_objective_portfolio_ready_v179($pdo))throw new RuntimeException('Objective Portfolio is not ready yet. Run the normal VP3 upgrade first.');
    return $uid;
}

function agent_objective_portfolio_terms_v179(string $text): array
{
    if(function_exists('agent_objective_memory_terms_v177'))return agent_objective_memory_terms_v177($text);
    $text=mb_strtolower($text);
    $parts=preg_split('/[^\pL\pN]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $stop=['the','and','for','with','from','into','this','that','your','my','our','objective','complete','finish','prepare','review','work'];
    $out=[];foreach($parts as $part){if(mb_strlen($part)<3||in_array($part,$stop,true))continue;$out[$part]=true;}return array_keys($out);
}

function agent_objective_portfolio_similarity_v179(string $a,string $b): float
{
    $aa=agent_objective_portfolio_terms_v179($a);$bb=agent_objective_portfolio_terms_v179($b);if(!$aa||!$bb)return 0.0;
    $intersection=count(array_intersect($aa,$bb));if($intersection<1)return 0.0;
    $union=count(array_unique(array_merge($aa,$bb)));return $union>0?round($intersection/$union,4):0.0;
}

function agent_objective_portfolio_parent_v179(PDO $pdo,int $uid,int $objectiveId,bool $forUpdate=false): array
{
    $row=agent_workflow_row_v1400($pdo,$uid,$objectiveId,$forUpdate);
    if(!$row||(string)($row['source_kind']??'')!=='objective_plan')throw new RuntimeException('Objective not found.');
    return $row;
}

function agent_objective_portfolio_child_stats_v179(PDO $pdo,int $uid,array $parent): array
{
    $hash=(string)($parent['source_hash']??'');
    $out=['total'=>0,'active'=>0,'executing'=>0,'approval'=>0,'failed'=>0,'paused'=>0,'completed'=>0,'blocked'=>0,'homeserver'=>0,'cloud'=>0,'next_attempt_at'=>''];
    if($hash==='')return $out;
    $stmt=$pdo->prepare("SELECT r.id,r.status,r.approval_status,r.execution_target,r.next_attempt_at,EXISTS(SELECT 1 FROM agent_workflow_run_dependencies d INNER JOIN agent_workflow_runs p ON p.id=d.depends_on_run_id AND p.owner_user_id=d.owner_user_id WHERE d.owner_user_id=r.owner_user_id AND d.run_id=r.id AND p.status<>'completed') blocked FROM agent_workflow_runs r WHERE r.owner_user_id=? AND r.source_kind='objective_step' AND r.source_hash=? ORDER BY r.id");
    $stmt->execute([$uid,$hash]);$nextTs=0;
    foreach($stmt->fetchAll()?:[] as $row){
        $out['total']++;$status=(string)($row['status']??'');$approval=(string)($row['approval_status']??'');
        if(in_array($status,['queued','planning','approved','executing'],true))$out['active']++;
        if($status==='executing')$out['executing']++;if($status==='failed')$out['failed']++;if($status==='paused')$out['paused']++;if($status==='completed')$out['completed']++;
        if($status==='approval_pending'||$approval==='pending')$out['approval']++;if(!empty($row['blocked']))$out['blocked']++;
        $target=(string)($row['execution_target']??'cloud');$out[$target==='homeserver'?'homeserver':'cloud']++;
        $ts=strtotime((string)($row['next_attempt_at']??''))?:0;if($ts>time()&&($nextTs===0||$ts<$nextTs)){$nextTs=$ts;$out['next_attempt_at']=(string)$row['next_attempt_at'];}
    }
    return $out;
}

function agent_objective_portfolio_history_signal_v179(PDO $pdo,string $goal,array $memories): array
{
    $bestSuccess=0.0;$bestFailure=0.0;$bestScore=0;
    foreach($memories as $memory){if(!is_array($memory))continue;$similar=agent_objective_portfolio_similarity_v179($goal,(string)($memory['goal']??''));if($similar<0.12)continue;$status=(string)($memory['outcome_status']??'');$quality=max(0,min(100,(int)($memory['outcome_score']??0)));
        if(in_array($status,['achieved','remediated'],true)&&$similar>$bestSuccess){$bestSuccess=$similar;$bestScore=$quality;}
        if($status==='failed')$bestFailure=max($bestFailure,$similar);
    }
    $signal=0.5;if($bestSuccess>0)$signal=min(1.0,0.58+($bestSuccess*0.20)+(($bestScore/100)*0.22));elseif($bestFailure>0)$signal=max(0.05,0.45-($bestFailure*0.25));
    return ['score'=>$signal,'success_similarity'=>$bestSuccess,'failure_similarity'=>$bestFailure,'outcome_score'=>$bestScore];
}

function agent_objective_portfolio_score_v179(array $parent,array $stats,array $history): array
{
    $priority=max(1,min(100,(int)($parent['work_priority']??50)))/100;
    $status=(string)($parent['status']??'approved');$state=match($status){'failed'=>1.0,'approval_pending'=>0.94,'executing'=>0.96,'planning'=>0.78,'queued'=>0.72,'approved'=>0.70,'paused'=>0.34,'completed'=>0.08,'cancelled'=>0.0,default=>0.55};
    $verification=(string)($parent['objective_verification_status']??'');$verify=match($verification){'needs_remediation'=>1.0,'verifying'=>0.88,'waiting'=>0.68,'achieved'=>0.05,default=>0.55};
    $total=max(1,(int)$stats['total']);$attention=min(1.0,((int)$stats['failed']*1.0+(int)$stats['approval']*0.75+(int)$stats['blocked']*0.55+(int)$stats['executing']*0.35)/$total);
    $schedule=0.30;$next=strtotime((string)($stats['next_attempt_at']??''))?:0;if($next>0){$hours=max(0,($next-time())/3600);$schedule=$hours<=24?0.95:($hours<=72?0.72:0.42);}
    $updated=strtotime((string)($parent['updated_at']??''))?:time();$ageHours=max(0,(time()-$updated)/3600);$recency=max(0.15,1.0-min(1.0,$ageHours/(30*24)));
    $historyScore=max(0.0,min(1.0,(float)($history['score']??0.5)));
    $score=($priority*0.30)+($state*0.20)+($verify*0.14)+($attention*0.14)+($schedule*0.08)+($historyScore*0.09)+($recency*0.05);
    return ['score'=>round(max(0,min(1,$score)),4),'components'=>['user_priority'=>round($priority,3),'state'=>round($state,3),'verification'=>round($verify,3),'attention'=>round($attention,3),'schedule'=>round($schedule,3),'historical_outcome'=>round($historyScore,3),'recency'=>round($recency,3)]];
}

function agent_objective_portfolio_recommendation_v179(array $item): array
{
    $status=(string)($item['status']??'');$verification=(string)($item['verification_status']??'');$stats=(array)($item['child_stats']??[]);
    if($verification==='needs_remediation'||(int)($stats['failed']??0)>0)return ['action'=>'repair_now','label'=>'Repair now','reason'=>'Failure or remediation evidence is blocking verified completion.'];
    if((int)($stats['approval']??0)>0)return ['action'=>'review_approval','label'=>'Review approval','reason'=>'Required approvals are holding objective work.'];
    if((int)($stats['blocked']??0)>0)return ['action'=>'wait','label'=>'Wait on prerequisites','reason'=>'One or more objective workflows are dependency-blocked.'];
    if($status==='paused')return ['action'=>'review_resume','label'=>'Review resume','reason'=>'The objective is paused; resume only if it still outranks competing work.'];
    if($status==='executing'||(int)($stats['executing']??0)>0)return ['action'=>'continue','label'=>'Continue','reason'=>'Work is already executing and currently ranks highly.'];
    return ['action'=>'start_next','label'=>'Start next','reason'=>'This is the strongest current candidate for the next objective slot.'];
}

function agent_objective_portfolio_v179(PDO $pdo,array $user,bool $includeProposals=true): array
{
    $uid=agent_objective_portfolio_require_v179($pdo,$user);
    $verificationColumn=column_exists('agent_workflow_runs','objective_verification_status')?',objective_verification_status':'';
    $stmt=$pdo->prepare("SELECT id,title,goal,status,work_priority,source_hash,execution_target,updated_at,next_attempt_at{$verificationColumn} FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_plan' AND status NOT IN ('cancelled') ORDER BY updated_at DESC,id DESC LIMIT 60");
    $stmt->execute([$uid]);$parents=$stmt->fetchAll()?:[];
    $memories=[];if(function_exists('agent_objective_memory_schema_ready_v177')&&agent_objective_memory_schema_ready_v177($pdo)){try{$m=$pdo->prepare('SELECT goal,outcome_status,outcome_score FROM agent_objective_outcome_memory WHERE owner_user_id=? ORDER BY id DESC LIMIT 120');$m->execute([$uid]);$memories=$m->fetchAll()?:[];}catch(Throwable $e){}}
    $items=[];
    foreach($parents as $parent){
        $stats=agent_objective_portfolio_child_stats_v179($pdo,$uid,$parent);$history=agent_objective_portfolio_history_signal_v179($pdo,(string)($parent['goal']??''),$memories);$rank=agent_objective_portfolio_score_v179($parent,$stats,$history);
        $item=['id'=>(int)$parent['id'],'title'=>(string)($parent['title']??''),'goal'=>(string)($parent['goal']??''),'status'=>(string)($parent['status']??''),'priority'=>max(1,min(100,(int)($parent['work_priority']??50))),'priority_label'=>agent_work_control_priority_label_v173((int)($parent['work_priority']??50)),'verification_status'=>(string)($parent['objective_verification_status']??''),'score'=>$rank['score'],'score_percent'=>(int)round($rank['score']*100),'score_components'=>$rank['components'],'historical_signal'=>$history,'child_stats'=>$stats,'updated_at'=>(string)($parent['updated_at']??''),'duplicate_of'=>0,'duplicate_similarity'=>0.0,'schedule_conflict_with'=>0,'recommendation'=>[]];
        $item['recommendation']=agent_objective_portfolio_recommendation_v179($item);$items[]=$item;
    }
    usort($items,static fn(array $a,array $b):int=>((float)$b['score']<=>(float)$a['score'])?:((int)$b['priority']<=>(int)$a['priority'])?:((int)$b['id']<=>(int)$a['id']));
    for($i=0;$i<count($items);$i++)for($j=$i+1;$j<count($items);$j++){
        if(in_array((string)$items[$i]['status'],['completed'],true)||in_array((string)$items[$j]['status'],['completed'],true))continue;
        $similar=agent_objective_portfolio_similarity_v179((string)$items[$i]['goal'],(string)$items[$j]['goal']);
        if($similar>=VP3_AGENT_OBJECTIVE_PORTFOLIO_DUPLICATE_THRESHOLD_V179){$items[$j]['duplicate_of']=(int)$items[$i]['id'];$items[$j]['duplicate_similarity']=$similar;$items[$j]['recommendation']=['action'=>'merge_review','label'=>'Review overlap','reason'=>'This objective substantially overlaps higher-ranked objective #'.(int)$items[$i]['id'].'.'];}
        $a=strtotime((string)($items[$i]['child_stats']['next_attempt_at']??''))?:0;$b=strtotime((string)($items[$j]['child_stats']['next_attempt_at']??''))?:0;
        if($a>0&&$b>0&&abs($a-$b)<=3600&&!$items[$j]['duplicate_of']){$items[$j]['schedule_conflict_with']=(int)$items[$i]['id'];$items[$j]['recommendation']=['action'=>'sequence_review','label'=>'Review sequence','reason'=>'Scheduled objective work overlaps higher-ranked objective #'.(int)$items[$i]['id'].'.'];}
    }
    $proposals=[];
    if($includeProposals&&function_exists('agent_proactive_objective_ready_v178')&&agent_proactive_objective_ready_v178($pdo)){
        try{$proposals=agent_proactive_objectives_v178($pdo,$user,[],['unread_notifications'=>function_exists('notification_unread_count')?max(0,(int)notification_unread_count($user)):0,'knowledge_count'=>0],false);}catch(Throwable $e){$proposals=[];}
    }
    return ['build'=>VP3_AGENT_OBJECTIVE_PORTFOLIO_V179,'objectives'=>array_slice($items,0,VP3_AGENT_OBJECTIVE_PORTFOLIO_LIMIT_V179),'proposals'=>array_slice($proposals,0,2),'counts'=>['active'=>count(array_filter($items,static fn(array $r):bool=>!in_array((string)$r['status'],['completed','cancelled'],true))),'completed'=>count(array_filter($items,static fn(array $r):bool=>(string)$r['status']==='completed')),'overlap'=>count(array_filter($items,static fn(array $r):bool=>(int)$r['duplicate_of']>0)),'schedule_conflicts'=>count(array_filter($items,static fn(array $r):bool=>(int)$r['schedule_conflict_with']>0)),'proposals'=>count($proposals)]];
}

function agent_objective_portfolio_priority_v179(PDO $pdo,array $user,int $objectiveId,mixed $priority): array
{
    $uid=agent_objective_portfolio_require_v179($pdo,$user);$parent=agent_objective_portfolio_parent_v179($pdo,$uid,$objectiveId);if(in_array((string)$parent['status'],['completed','cancelled'],true))throw new RuntimeException('Closed objectives cannot be reprioritized.');
    $value=agent_work_control_priority_value_v173($priority);agent_work_control_priority_v173($pdo,$user,$objectiveId,$value);
    $hash=(string)($parent['source_hash']??'');if($hash!==''){$stmt=$pdo->prepare("SELECT id,status FROM agent_workflow_runs WHERE owner_user_id=? AND source_hash=? AND source_kind='objective_step' ORDER BY id");$stmt->execute([$uid,$hash]);foreach($stmt->fetchAll()?:[] as $row){if(in_array((string)$row['status'],['completed','cancelled'],true))continue;agent_work_control_priority_v173($pdo,$user,(int)$row['id'],$value);}}
    $updated=agent_objective_portfolio_parent_v179($pdo,$uid,$objectiveId);agent_workflow_event_v1400($pdo,$uid,$objectiveId,'objective_portfolio_priority',(string)$updated['status'],(string)$updated['status'],'user','Objective portfolio priority was explicitly changed.',['priority'=>$value,'label'=>agent_work_control_priority_label_v173($value)]);
    return $updated;
}

function agent_objective_portfolio_remaining_children_v179(PDO $pdo,int $uid,array $parent): array
{
    $hash=(string)($parent['source_hash']??'');if($hash==='')return [];
    $stmt=$pdo->prepare("SELECT id,status FROM agent_workflow_runs WHERE owner_user_id=? AND source_hash=? AND source_kind='objective_step' AND status NOT IN ('completed','cancelled') ORDER BY id");$stmt->execute([$uid,$hash]);return $stmt->fetchAll()?:[];
}

function agent_objective_portfolio_dependency_v179(PDO $pdo,array $user,int $objectiveId,int $dependsOnObjectiveId): array
{
    $uid=agent_objective_portfolio_require_v179($pdo,$user);if($objectiveId===$dependsOnObjectiveId)throw new RuntimeException('An objective cannot depend on itself.');
    $dependent=agent_objective_portfolio_parent_v179($pdo,$uid,$objectiveId);$prerequisite=agent_objective_portfolio_parent_v179($pdo,$uid,$dependsOnObjectiveId);
    if(in_array((string)$dependent['status'],['completed','cancelled'],true))throw new RuntimeException('Closed objectives cannot have their execution order changed.');
    if((string)$prerequisite['status']==='cancelled')throw new RuntimeException('A cancelled objective cannot be used as a prerequisite.');
    $remaining=agent_objective_portfolio_remaining_children_v179($pdo,$uid,$dependent);if(!$remaining)throw new RuntimeException('The dependent objective has no unfinished workflows to sequence.');
    foreach($remaining as $row)if((string)($row['status']??'')==='executing')throw new RuntimeException('Pause the executing objective work before changing cross-objective order.');
    foreach($remaining as $row)agent_work_dependency_add_v174($pdo,$user,(int)$row['id'],(int)$prerequisite['id'],'objective_portfolio_v179');
    agent_workflow_event_v1400($pdo,$uid,$objectiveId,'objective_portfolio_dependency',(string)$dependent['status'],(string)$dependent['status'],'user','Objective execution was explicitly sequenced behind another objective.',['depends_on_objective_id'=>$dependsOnObjectiveId,'remaining_workflows'=>count($remaining)]);
    return ['objective_id'=>$objectiveId,'depends_on_objective_id'=>$dependsOnObjectiveId,'remaining_count'=>count($remaining)];
}

function agent_objective_portfolio_answer_v179(array $portfolio,bool $conflictsOnly=false): string
{
    $items=(array)($portfolio['objectives']??[]);if(!$items)return 'There are no objective plans in the portfolio yet.';$lines=[];
    foreach(array_slice($items,0,8) as $index=>$item){if($conflictsOnly&&(int)($item['duplicate_of']??0)<1&&(int)($item['schedule_conflict_with']??0)<1)continue;$rec=(array)($item['recommendation']??[]);$detail='#'.(int)$item['id'].' · '.(int)$item['score_percent'].'/100 · '.ucfirst((string)$item['priority_label']).' · '.ucfirst((string)$item['status']).' · '.(string)($rec['label']??'Review');if((int)($item['duplicate_of']??0)>0)$detail.=' · overlaps #'.(int)$item['duplicate_of'];if((int)($item['schedule_conflict_with']??0)>0)$detail.=' · schedule conflict #'.(int)$item['schedule_conflict_with'];$lines[]=($index+1).'. '.$detail.' — '.agent_objective_text_v175((string)$item['goal'],130);}
    if(!$lines)return 'I do not see material overlap or schedule conflicts between the current objectives.';
    $counts=(array)($portfolio['counts']??[]);$prefix=$conflictsOnly?'Objective portfolio conflicts: ':'Objective portfolio — '.(int)($counts['active']??0).' active, '.(int)($counts['proposals']??0).' proposed. ';
    return $prefix.implode("\n",$lines).'\nRanking is advisory. Say “set objective #12 priority to high” or “objective #15 depends on objective #12” to explicitly change execution order.';
}

function agent_objective_portfolio_chat_v179(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;
    $intent=(bool)preg_match('/\b(?:objective portfolio|portfolio conflicts?|rank objectives?|objective priorit(?:y|ies)|prioritize objective|deprioritize objective|set objective .*priority|objective\s*#?\s*\d+\s+depends?\s+on\s+objective|what objective should i do first|which objective should i do first)\b/i',$q);if(!$intent)return $empty;
    $pdo=db();if(!$pdo)return $empty;
    try{
        $uid=agent_objective_portfolio_require_v179($pdo,$user);$answer='';$tool='objective.portfolio';
        if(preg_match('/\b(?:set\s+)?objective\s*#?\s*(\d+)\s+(?:priority\s+(?:to\s+)?|to\s+priority\s+|)(urgent|critical|high|normal|low)\b/i',$q,$m)||preg_match('/\b(prioritize|deprioritize)\s+objective\s*#?\s*(\d+)/i',$q,$m2)){
            if(isset($m[1])){$id=(int)$m[1];$priority=(string)$m[2];}else{$id=(int)$m2[2];$priority=strtolower((string)$m2[1])==='prioritize'?'high':'low';}
            $row=agent_objective_portfolio_priority_v179($pdo,$user,$id,$priority);$value=max(1,min(100,(int)($row['work_priority']??agent_work_control_priority_value_v173($priority))));$answer='Objective #'.$id.' and its unfinished child workflows are now '.agent_work_control_priority_label_v173($value).' priority ('.$value.'). No work was otherwise started, paused, cancelled, or approved.';$tool='objective.portfolio.priority';
        }elseif(preg_match('/\bobjective\s*#?\s*(\d+)\s+(?:depends?\s+on|waits?\s+for|after)\s+objective\s*#?\s*(\d+)\b/i',$q,$m)){
            $state=agent_objective_portfolio_dependency_v179($pdo,$user,(int)$m[1],(int)$m[2]);$answer='Objective #'.(int)$state['objective_id'].' now waits for objective #'.(int)$state['depends_on_objective_id'].' before its remaining workflows can advance. Existing approvals, receipts, retries, and Phase 19 execution remain unchanged.';$tool='objective.portfolio.dependency';
        }elseif(preg_match('/\b(?:portfolio conflicts?|objective conflicts?|overlapping objectives?)\b/i',$q)){
            $answer=agent_objective_portfolio_answer_v179(agent_objective_portfolio_v179($pdo,$user,true),true);$tool='objective.portfolio.conflicts';
        }else{
            $portfolio=agent_objective_portfolio_v179($pdo,$user,true);$answer=agent_objective_portfolio_answer_v179($portfolio,false);$tool='objective.portfolio.list';
        }
        $out=$empty;$out['handled']=true;$out['answer']=$answer;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',['build'=>VP3_AGENT_OBJECTIVE_PORTFOLIO_V179],$conversationId);return $out;
    }catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not use Objective Portfolio: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.portfolio',$query,'error',['error'=>get_class($e)],$conversationId);return $out;}
}

function agent_objective_portfolio_brief_v179(PDO $pdo,array $user): array
{
    try{$portfolio=agent_objective_portfolio_v179($pdo,$user,false);$items=(array)($portfolio['objectives']??[]);$top=$items[0]??null;return ['available'=>true,'counts'=>(array)($portfolio['counts']??[]),'top'=>$top,'prompt'=>'Show my objective portfolio and explain what I should do first.'];}catch(Throwable $e){return ['available'=>false,'counts'=>[],'top'=>null,'prompt'=>''];}
}
