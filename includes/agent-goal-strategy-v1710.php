<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.10 — Goals, Strategy & Objective Hierarchy.
 *
 * Durable owner-scoped goals sit above canonical Phase 17.5 objectives.
 * Goal progress is derived only from Phase 17.6 verified objective outcomes.
 * This layer never creates a competing worker, scheduler, receipt, approval,
 * lease, retry system, or manual completion path. Strategy remains advisory
 * until a user explicitly creates, links, reprioritizes, or sequences work.
 */
const VP3_AGENT_GOAL_STRATEGY_V1710='agent-goal-strategy-v1710-20260915';
const VP3_AGENT_GOAL_LIMIT_V1710=24;
const VP3_AGENT_GOAL_OBJECTIVE_LIMIT_V1710=32;

require_once __DIR__.'/agent-objective-portfolio-v179.php';

function agent_goal_strategy_schema_ready_v1710(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_objective_verification_schema_ready_v176($pdo)
        && table_exists('agent_goals')
        && table_exists('agent_goal_objectives')
        && table_exists('agent_goal_events')
        && column_exists('agent_goals','owner_user_id')
        && column_exists('agent_goals','success_criteria')
        && column_exists('agent_goal_objectives','objective_run_id'));
}

function agent_goal_strategy_ensure_schema_v1710(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_objective_verification_ensure_schema_v176($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_goals (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL DEFAULT '',
        goal VARCHAR(900) NOT NULL DEFAULT '',
        strategy_summary VARCHAR(1500) NOT NULL DEFAULT '',
        success_criteria MEDIUMTEXT NULL,
        target_date DATE NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        priority INT NOT NULL DEFAULT 50,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        archived_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_agent_goals_owner_status (owner_user_id,status,priority,id),
        KEY idx_agent_goals_owner_target (owner_user_id,target_date,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_goal_objectives (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        goal_id BIGINT UNSIGNED NOT NULL,
        objective_run_id BIGINT UNSIGNED NOT NULL,
        contribution_weight INT UNSIGNED NOT NULL DEFAULT 100,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_agent_goal_objective (owner_user_id,goal_id,objective_run_id),
        KEY idx_agent_goal_objective_goal (owner_user_id,goal_id,id),
        KEY idx_agent_goal_objective_objective (owner_user_id,objective_run_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_goal_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        goal_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(64) NOT NULL DEFAULT '',
        actor VARCHAR(32) NOT NULL DEFAULT 'user',
        summary VARCHAR(500) NOT NULL DEFAULT '',
        event_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_agent_goal_events_goal (owner_user_id,goal_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function agent_goal_strategy_require_v1710(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Goals are not available for this account.');
    if(!agent_goal_strategy_schema_ready_v1710($pdo))throw new RuntimeException('Goals are not ready yet. Run the normal VP3 database upgrade first.');
    return $uid;
}

function agent_goal_text_v1710(mixed $value,int $limit=900): string
{
    return agent_objective_text_v175((string)$value,$limit);
}

function agent_goal_event_v1710(PDO $pdo,int $uid,int $goalId,string $type,string $summary,array $payload=[]): void
{
    $stmt=$pdo->prepare('INSERT INTO agent_goal_events (owner_user_id,goal_id,event_type,actor,summary,event_json) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$uid,$goalId,agent_goal_text_v1710($type,64),'user',agent_goal_text_v1710($summary,500),$payload?agent_workflow_json_v1400($payload):null]);
}

function agent_goal_row_v1710(PDO $pdo,int $uid,int $goalId,bool $forUpdate=false): array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_goals WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));
    $stmt->execute([$goalId,$uid]);$row=$stmt->fetch();
    if(!is_array($row))throw new RuntimeException('Goal not found.');
    return $row;
}

function agent_goal_require_active_v1710(array $goal): void
{
    $status=(string)($goal['status']??'');
    if($status==='paused')throw new RuntimeException('Resume this goal before changing its strategy or objective links.');
    if($status==='archived')throw new RuntimeException('Archived goals are immutable history.');
    if($status!=='active')throw new RuntimeException('This goal is not active.');
}

function agent_goal_objective_row_v1710(PDO $pdo,int $uid,int $objectiveId): array
{
    $row=agent_workflow_row_v1400($pdo,$uid,$objectiveId);
    if(!$row||(string)($row['source_kind']??'')!=='objective_plan')throw new RuntimeException('Objective plan not found.');
    return $row;
}

function agent_goal_create_v1710(PDO $pdo,array $user,string $goal,string $criteria='',?string $targetDate=null,int $priority=50): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);
    $goal=agent_goal_text_v1710($goal,900);if($goal==='')throw new RuntimeException('A goal is required.');
    $criteria=agent_goal_text_v1710($criteria,2000);$priority=max(1,min(100,$priority));$date=null;
    if($targetDate!==null&&trim($targetDate)!==''){$ts=strtotime($targetDate);if($ts===false)throw new RuntimeException('The goal target date could not be understood.');$date=date('Y-m-d',$ts);}
    $strategy='Coordinate verified objectives that materially contribute to this goal. Prefer the highest-value unblocked objective, preserve completed work, and revise strategy when evidence changes.';
    $stmt=$pdo->prepare('INSERT INTO agent_goals (owner_user_id,title,goal,strategy_summary,success_criteria,target_date,status,priority) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$uid,agent_goal_text_v1710($goal,190),$goal,$strategy,$criteria!==''?$criteria:null,$date,'active',$priority]);
    $id=(int)$pdo->lastInsertId();agent_goal_event_v1710($pdo,$uid,$id,'goal_created','Goal created.',['target_date'=>$date,'priority'=>$priority]);
    return agent_goal_state_v1710($pdo,$user,$id);
}

function agent_goal_update_v1710(PDO $pdo,array $user,int $goalId,array $changes): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);$sets=[];$params=[];$event=[];
    if(array_key_exists('strategy_summary',$changes)){$value=agent_goal_text_v1710($changes['strategy_summary'],1500);if($value==='')throw new RuntimeException('Strategy cannot be empty.');$sets[]='strategy_summary=?';$params[]=$value;$event['strategy_updated']=true;}
    if(array_key_exists('success_criteria',$changes)){$value=agent_goal_text_v1710($changes['success_criteria'],2000);$sets[]='success_criteria=?';$params[]=$value!==''?$value:null;$event['criteria_updated']=true;}
    if(array_key_exists('target_date',$changes)){$raw=trim((string)$changes['target_date']);$value=null;if($raw!==''){$ts=strtotime($raw);if($ts===false)throw new RuntimeException('The goal target date could not be understood.');$value=date('Y-m-d',$ts);}$sets[]='target_date=?';$params[]=$value;$event['target_date']=$value;}
    if(!$sets)throw new RuntimeException('No goal changes were supplied.');$params[]=$goalId;$params[]=$uid;
    $pdo->prepare('UPDATE agent_goals SET '.implode(',',$sets).' WHERE id=? AND owner_user_id=?')->execute($params);agent_goal_event_v1710($pdo,$uid,$goalId,'goal_strategy_updated','Goal strategy metadata updated.',$event);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_link_objective_v1710(PDO $pdo,array $user,int $goalId,int $objectiveId,int $weight=100): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);agent_goal_objective_row_v1710($pdo,$uid,$objectiveId);$weight=max(1,min(1000,$weight));
    $stmt=$pdo->prepare('INSERT INTO agent_goal_objectives (owner_user_id,goal_id,objective_run_id,contribution_weight) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE contribution_weight=VALUES(contribution_weight)');$stmt->execute([$uid,$goalId,$objectiveId,$weight]);agent_goal_event_v1710($pdo,$uid,$goalId,'objective_linked','Objective linked to goal.',['objective_run_id'=>$objectiveId,'weight'=>$weight]);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_unlink_objective_v1710(PDO $pdo,array $user,int $goalId,int $objectiveId): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);agent_goal_objective_row_v1710($pdo,$uid,$objectiveId);$stmt=$pdo->prepare('DELETE FROM agent_goal_objectives WHERE owner_user_id=? AND goal_id=? AND objective_run_id=?');$stmt->execute([$uid,$goalId,$objectiveId]);if($stmt->rowCount()>0)agent_goal_event_v1710($pdo,$uid,$goalId,'objective_unlinked','Objective unlinked from goal.',['objective_run_id'=>$objectiveId]);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_objectives_v1710(PDO $pdo,int $uid,int $goalId): array
{
    $stmt=$pdo->prepare("SELECT l.contribution_weight,l.created_at linked_at,r.id,r.title,r.goal,r.status,r.work_priority,r.source_hash,r.updated_at,r.objective_verification_status FROM agent_goal_objectives l INNER JOIN agent_workflow_runs r ON r.id=l.objective_run_id AND r.owner_user_id=l.owner_user_id WHERE l.owner_user_id=? AND l.goal_id=? AND r.source_kind='objective_plan' ORDER BY l.id LIMIT ".VP3_AGENT_GOAL_OBJECTIVE_LIMIT_V1710);$stmt->execute([$uid,$goalId]);return $stmt->fetchAll()?:[];
}

function agent_goal_progress_v1710(array $objectives): array
{
    $totalWeight=0;$achievedWeight=0;$counts=['total'=>0,'achieved'=>0,'remediation'=>0,'failed'=>0,'approval'=>0,'active'=>0,'paused'=>0];
    foreach($objectives as $row){if(!is_array($row))continue;$weight=max(1,(int)($row['contribution_weight']??100));$totalWeight+=$weight;$counts['total']++;$verification=(string)($row['objective_verification_status']??'');$status=(string)($row['status']??'');
        if($verification==='achieved'){$achievedWeight+=$weight;$counts['achieved']++;}elseif($verification==='needs_remediation')$counts['remediation']++;elseif($status==='failed')$counts['failed']++;elseif($status==='approval_pending')$counts['approval']++;elseif($status==='paused')$counts['paused']++;else $counts['active']++;}
    $progress=$totalWeight>0?(int)floor(($achievedWeight/$totalWeight)*100):0;
    return ['progress_percent'=>max(0,min(100,$progress)),'counts'=>$counts,'achieved_weight'=>$achievedWeight,'total_weight'=>$totalWeight,'derived_status'=>$counts['total']>0&&$counts['achieved']===$counts['total']?'achieved':'active'];
}

function agent_goal_deadline_v1710(array $goal,int $progress): array
{
    $target=trim((string)($goal['target_date']??''));$out=['target_date'=>$target,'days_remaining'=>null,'at_risk'=>false,'reason'=>''];if($target==='')return $out;$today=strtotime(gmdate('Y-m-d').' 00:00:00 UTC')?:time();$targetTs=strtotime($target.' 00:00:00 UTC')?:0;if($targetTs<=0)return $out;$days=(int)floor(($targetTs-$today)/86400);$out['days_remaining']=$days;
    if($progress<100&&$days<0){$out['at_risk']=true;$out['reason']='The target date has passed without verified goal achievement.';}elseif($progress<80&&$days<=7){$out['at_risk']=true;$out['reason']='The target date is within one week and verified progress is below 80%.';}elseif($progress<50&&$days<=30){$out['at_risk']=true;$out['reason']='The target date is within 30 days and verified progress is below 50%.';}return $out;
}

function agent_goal_attention_v1710(array $goal,array $progress): int
{
    if((string)($progress['derived_status']??'')==='achieved')return 0;
    $priority=max(1,min(100,(int)($goal['priority']??50)))/100;$counts=(array)($progress['counts']??[]);$total=max(1,(int)($counts['total']??0));$issues=min(1.0,(((int)($counts['remediation']??0)+(int)($counts['failed']??0))*1.0+((int)($counts['approval']??0)*0.7)+((int)($counts['paused']??0)*0.3))/$total);$remaining=max(0.0,min(1.0,1-((int)($progress['progress_percent']??0)/100)));$deadline=agent_goal_deadline_v1710($goal,(int)($progress['progress_percent']??0));$days=$deadline['days_remaining'];$deadlineScore=$days===null?0.20:($days<0?1.0:($days<=7?0.95:($days<=30?0.75:($days<=90?0.50:0.25))));$score=($priority*0.40)+($deadlineScore*0.25)+($issues*0.20)+($remaining*0.15);if((string)($goal['status']??'')==='paused')$score*=0.55;return (int)round(max(0,min(1,$score))*100);
}

function agent_goal_portfolio_map_v1710(PDO $pdo,array $user): array
{
    try{$portfolio=agent_objective_portfolio_v179($pdo,$user,false);}catch(Throwable $e){return [];}$map=[];foreach((array)($portfolio['objectives']??[]) as $item)if(is_array($item)&&isset($item['id']))$map[(int)$item['id']]=$item;return $map;
}

function agent_goal_strategy_analysis_v1710(PDO $pdo,array $user,array $goal,array $objectives,array $progress): array
{
    $portfolio=agent_goal_portfolio_map_v1710($pdo,$user);$ranked=[];$blockers=[];$maxWeight=1;foreach($objectives as $row)$maxWeight=max($maxWeight,(int)($row['contribution_weight']??100));
    foreach($objectives as $objective){$id=(int)($objective['id']??0);if((string)($objective['objective_verification_status']??'')==='achieved')continue;$item=$portfolio[$id]??['id'=>$id,'score_percent'=>50,'recommendation'=>['action'=>'review','label'=>'Review'],'goal'=>(string)($objective['goal']??'')];$ratio=max(0.0,min(1.0,((int)($objective['contribution_weight']??100))/$maxWeight));$item['goal_contribution_weight']=(int)($objective['contribution_weight']??100);$item['goal_score_percent']=(int)round(((int)($item['score_percent']??50)*0.75)+($ratio*25));$ranked[]=$item;$rec=(array)($item['recommendation']??[]);if(in_array((string)($rec['action']??''),['repair_now','review_approval','wait','sequence_review','merge_review'],true))$blockers[]=['objective_id'=>$id,'action'=>(string)$rec['action'],'label'=>(string)($rec['label']??'Review'),'reason'=>(string)($rec['reason']??'')];}
    usort($ranked,static fn(array $a,array $b):int=>((int)($b['goal_score_percent']??0)<=>(int)($a['goal_score_percent']??0))?:((int)($b['id']??0)<=>(int)($a['id']??0)));
    $memories=[];if(agent_objective_memory_schema_ready_v177($pdo))try{$memories=agent_objective_memory_similar_v177($pdo,$user,(string)($goal['goal']??''),3,true);}catch(Throwable $e){$memories=[];}
    $proposals=[];if(function_exists('agent_proactive_objective_ready_v178')&&agent_proactive_objective_ready_v178($pdo))try{$all=agent_proactive_objectives_v178($pdo,$user,[],['unread_notifications'=>0,'knowledge_count'=>0],false);foreach($all as $proposal){if(!is_array($proposal))continue;$similar=agent_objective_portfolio_similarity_v179((string)($goal['goal']??''),(string)($proposal['goal']??''));if($similar>=0.12){$proposal['goal_similarity']=$similar;$proposals[]=$proposal;}}usort($proposals,static fn(array $a,array $b):int=>((float)($b['goal_similarity']??0)<=>(float)($a['goal_similarity']??0)));}catch(Throwable $e){$proposals=[];}
    return ['next_objective'=>$ranked[0]??null,'ranked_objectives'=>array_slice($ranked,0,8),'blockers'=>array_slice($blockers,0,8),'deadline'=>agent_goal_deadline_v1710($goal,(int)$progress['progress_percent']),'learned_outcomes'=>array_slice($memories,0,3),'proposed_objectives'=>array_slice($proposals,0,2)];
}

function agent_goal_state_v1710(PDO $pdo,array $user,int $goalId): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);$objectives=agent_goal_objectives_v1710($pdo,$uid,$goalId);$progress=agent_goal_progress_v1710($objectives);$strategy=agent_goal_strategy_analysis_v1710($pdo,$user,$goal,$objectives,$progress);$public=[];
    foreach($objectives as $row)$public[]=['id'=>(int)$row['id'],'title'=>(string)$row['title'],'goal'=>(string)$row['goal'],'status'=>(string)$row['status'],'verification_status'=>(string)$row['objective_verification_status'],'priority'=>(int)($row['work_priority']??50),'contribution_weight'=>(int)($row['contribution_weight']??100)];
    return ['build'=>VP3_AGENT_GOAL_STRATEGY_V1710,'goal'=>['id'=>(int)$goal['id'],'title'=>(string)$goal['title'],'goal'=>(string)$goal['goal'],'strategy_summary'=>(string)$goal['strategy_summary'],'success_criteria'=>agent_goal_text_v1710($goal['success_criteria']??'',2000),'target_date'=>(string)($goal['target_date']??''),'status'=>(string)$goal['status'],'priority'=>(int)$goal['priority'],'derived_status'=>(string)$progress['derived_status'],'progress_percent'=>(int)$progress['progress_percent'],'attention_score_percent'=>agent_goal_attention_v1710($goal,$progress)],'counts'=>$progress['counts'],'objectives'=>$public,'strategy'=>$strategy];
}

function agent_goal_list_v1710(PDO $pdo,array $user,bool $includeArchived=false): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$sql='SELECT * FROM agent_goals WHERE owner_user_id=?'.($includeArchived?'':" AND status<>'archived'").' ORDER BY id DESC LIMIT '.VP3_AGENT_GOAL_LIMIT_V1710;$stmt=$pdo->prepare($sql);$stmt->execute([$uid]);$out=[];
    foreach($stmt->fetchAll()?:[] as $goal){$objectives=agent_goal_objectives_v1710($pdo,$uid,(int)$goal['id']);$progress=agent_goal_progress_v1710($objectives);$out[]=['goal'=>['id'=>(int)$goal['id'],'goal'=>(string)$goal['goal'],'status'=>(string)$goal['status'],'priority'=>(int)$goal['priority'],'target_date'=>(string)($goal['target_date']??''),'derived_status'=>(string)$progress['derived_status'],'progress_percent'=>(int)$progress['progress_percent'],'attention_score_percent'=>agent_goal_attention_v1710($goal,$progress)],'counts'=>$progress['counts']];}
    usort($out,static fn(array $a,array $b):int=>((int)($b['goal']['attention_score_percent']??0)<=>(int)($a['goal']['attention_score_percent']??0))?:((int)($b['goal']['priority']??0)<=>(int)($a['goal']['priority']??0))?:((int)($b['goal']['id']??0)<=>(int)($a['goal']['id']??0)));return $out;
}

function agent_goal_priority_v1710(PDO $pdo,array $user,int $goalId,mixed $priority): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);$value=agent_work_control_priority_value_v173($priority);$pdo->prepare('UPDATE agent_goals SET priority=? WHERE id=? AND owner_user_id=?')->execute([$value,$goalId,$uid]);agent_goal_event_v1710($pdo,$uid,$goalId,'goal_priority_changed','Goal priority changed.',['priority'=>$value]);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_status_v1710(PDO $pdo,array $user,int $goalId,string $status): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);$current=(string)$goal['status'];$status=mb_strtolower(trim($status));if(!in_array($status,['active','paused','archived'],true))throw new RuntimeException('Unsupported goal status.');if($current==='archived'&&$status!=='archived')throw new RuntimeException('Archived goals are retained as history and cannot be reopened automatically.');if($status==='active'&&$current!=='paused'&&$current!=='active')throw new RuntimeException('Only paused goals can be resumed.');
    $pdo->prepare('UPDATE agent_goals SET status=?,archived_at=? WHERE id=? AND owner_user_id=?')->execute([$status,$status==='archived'?gmdate('Y-m-d H:i:s'):null,$goalId,$uid]);agent_goal_event_v1710($pdo,$uid,$goalId,'goal_status_changed','Goal status changed.',['status'=>$status]);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_create_objective_v1710(PDO $pdo,array $user,int $goalId,string $objectiveGoal,int $conversationId=0): array
{
    $uid=agent_goal_strategy_require_v1710($pdo,$user);$goal=agent_goal_row_v1710($pdo,$uid,$goalId);agent_goal_require_active_v1710($goal);$objectiveGoal=agent_goal_text_v1710($objectiveGoal,900);if($objectiveGoal==='')throw new RuntimeException('An objective description is required.');$state=agent_objective_create_v175($pdo,$user,$objectiveGoal,agent_objective_heuristic_stages_v175($objectiveGoal),$conversationId,null);$objectiveId=(int)($state['objective']['id']??0);if($objectiveId<1)throw new RuntimeException('The objective could not be created.');agent_goal_link_objective_v1710($pdo,$user,$goalId,$objectiveId,100);agent_goal_event_v1710($pdo,$uid,$goalId,'objective_created_for_goal','A fresh objective was explicitly created for this goal.',['objective_run_id'=>$objectiveId]);return agent_goal_state_v1710($pdo,$user,$goalId);
}

function agent_goal_parse_create_v1710(string $query): ?array
{
    $q=trim($query);if(!preg_match('/^\s*(?:create|add|start|define)\s+(?:a\s+)?goal\s+(?:to|for)?\s*:?[ ]*(.+)$/i',$q,$m))return null;$body=trim((string)$m[1]);$criteria='';$target=null;if(preg_match('/^(.*?)\s+\b(?:success|criteria)\s*:\s*(.+)$/is',$body,$parts)){$body=trim((string)$parts[1]);$criteria=trim((string)$parts[2]);}if(preg_match('/^(.*?)\s+\bby\s+((?:20\d{2}-\d{1,2}-\d{1,2})|(?:[A-Za-z]+\s+\d{1,2}(?:,\s*20\d{2})?))\s*$/i',$body,$date)){$body=trim((string)$date[1]);$target=trim((string)$date[2]);}$body=agent_goal_text_v1710($body,900);if($body==='')return null;return ['goal'=>$body,'criteria'=>agent_goal_text_v1710($criteria,2000),'target_date'=>$target];
}

function agent_goal_answer_v1710(array $state,bool $strategyFocus=false): string
{
    $goal=(array)($state['goal']??[]);$id=(int)($goal['id']??0);$counts=(array)($state['counts']??[]);$strategy=(array)($state['strategy']??[]);$answer='Goal #'.$id.' — '.(string)($goal['goal']??'').' · '.(int)($goal['progress_percent']??0).'% verified progress · '.(int)($counts['achieved']??0).'/'.(int)($counts['total']??0).' objectives achieved';if((string)($goal['target_date']??'')!=='')$answer.=' · target '.(string)$goal['target_date'];$answer.='.';
    if((string)($goal['derived_status']??'')==='achieved')return $answer.' Every linked objective has a Phase 17.6 verified achieved outcome.';
    $deadline=(array)($strategy['deadline']??[]);if(!empty($deadline['at_risk']))$answer.=' Deadline risk: '.(string)$deadline['reason'];$next=is_array($strategy['next_objective']??null)?$strategy['next_objective']:null;if($next)$answer.=' Next strategic objective: #'.(int)$next['id'].' (goal score '.(int)($next['goal_score_percent']??0).'/100) — '.agent_goal_text_v1710($next['goal']??'',180).'.';elseif((int)($counts['total']??0)===0)$answer.=' No objectives are linked yet; create or attach an objective before execution can advance this goal.';
    $blockers=(array)($strategy['blockers']??[]);if($blockers){$labels=[];foreach(array_slice($blockers,0,3) as $b)$labels[]='#'.(int)$b['objective_id'].' '.(string)$b['label'];$answer.=' Current blockers: '.implode(', ',$labels).'.';}
    if($strategyFocus){$memories=(array)($strategy['learned_outcomes']??[]);if($memories){$best=$memories[0];$answer.=' Best learned precedent: “'.agent_goal_text_v1710($best['goal']??'',140).'” · '.(string)($best['outcome_status']??'outcome').' · score '.(int)($best['outcome_score']??0).'.';}$proposals=(array)($strategy['proposed_objectives']??[]);if($proposals)$answer.=' '.count($proposals).' related proactive objective proposal'.(count($proposals)===1?'':'s').' may help, but none will be attached or executed without your explicit instruction.';}
    return $answer;
}

function agent_goal_chat_v1710(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;$create=agent_goal_parse_create_v1710($q);$goalIntent=$create!==null||(bool)preg_match('/\b(?:my goals?|goal\s*#?\s*\d+|goals? strategy|strategic goals?|which goal|what goal needs attention)\b/i',$q);if(!$goalIntent)return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        if($create!==null){$state=agent_goal_create_v1710($pdo,$user,(string)$create['goal'],(string)$create['criteria'],$create['target_date']!==null?(string)$create['target_date']:null);$out=$empty;$out['handled']=true;$out['answer']='Created '.agent_goal_answer_v1710($state,true);if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.create',$query,'success',['goal_id'=>(int)$state['goal']['id']],$conversationId);return $out;}
        $listIntent=(bool)(preg_match('/\b(?:show|list|what are)\s+(?:all\s+)?(?:my\s+)?goals?\b/i',$q)||preg_match('/\b(?:which|what)\s+goal\b.*\b(?:attention|first|priority|focus)\b/i',$q));
        if($listIntent&&!preg_match('/goal\s*#?\s*\d+/i',$q)){$goals=agent_goal_list_v1710($pdo,$user,false);$lines=[];foreach($goals as $state){$g=(array)$state['goal'];$lines[]='#'.(int)$g['id'].' · attention '.(int)$g['attention_score_percent'].'/100 · '.(int)$g['progress_percent'].'% verified · '.ucfirst((string)$g['status']).' · '.agent_goal_text_v1710($g['goal']??'',150);} $out=$empty;$out['handled']=true;if(!$lines)$out['answer']='You do not have any active goals yet.';else{$top=(array)$goals[0]['goal'];$out['answer']='Goal #'.(int)$top['id'].' needs the most attention right now ('.(int)$top['attention_score_percent'].'/100).'."\n".implode("\n",$lines);}return $out;}
        $goalId=0;if(preg_match('/\bgoal\s*#?\s*(\d+)\b/i',$q,$gm))$goalId=(int)$gm[1];if($goalId<1)return $empty;$tool='goal.inspect';
        if(preg_match('/\b(?:attach|link|add)\s+objective\s*#?\s*(\d+)\b.*\bgoal\b/i',$q,$m)||preg_match('/\bgoal\s*#?\s*\d+\b.*\b(?:attach|link|add)\s+objective\s*#?\s*(\d+)\b/i',$q,$m)){$weight=100;if(preg_match('/\bweight\s+(\d+)\b/i',$q,$wm))$weight=(int)$wm[1];$state=agent_goal_link_objective_v1710($pdo,$user,$goalId,(int)$m[1],$weight);$answer='Linked objective #'.(int)$m[1].'. '.agent_goal_answer_v1710($state,true);$tool='goal.objective.link';}
        elseif(preg_match('/\b(?:remove|unlink|detach)\s+objective\s*#?\s*(\d+)\b/i',$q,$m)){$state=agent_goal_unlink_objective_v1710($pdo,$user,$goalId,(int)$m[1]);$answer='Unlinked objective #'.(int)$m[1].'. '.agent_goal_answer_v1710($state,true);$tool='goal.objective.unlink';}
        elseif(preg_match('/\bcreate\s+(?:a\s+)?(?:new\s+)?objective\s+(?:for\s+)?goal\s*#?\s*\d+\s+(?:to|for)\s+(.+)$/i',$q,$m)){$state=agent_goal_create_objective_v1710($pdo,$user,$goalId,(string)$m[1],$conversationId);$answer='Created and linked a fresh objective. '.agent_goal_answer_v1710($state,true);$tool='goal.objective.create';}
        elseif(preg_match('/\b(?:set\s+)?goal\s*#?\s*\d+\s+priority\s+(?:to\s+)?(urgent|critical|high|normal|low)\b/i',$q,$m)){$state=agent_goal_priority_v1710($pdo,$user,$goalId,(string)$m[1]);$answer='Updated goal priority. '.agent_goal_answer_v1710($state,true);$tool='goal.priority';}
        elseif(preg_match('/\b(?:set|change|update)\s+goal\s*#?\s*\d+\s+(?:target|deadline)\s+(?:to\s+)?(.+)$/i',$q,$m)){$state=agent_goal_update_v1710($pdo,$user,$goalId,['target_date'=>(string)$m[1]]);$answer='Updated the goal target date. '.agent_goal_answer_v1710($state,true);$tool='goal.target';}
        elseif(preg_match('/\b(?:set|change|update)\s+goal\s*#?\s*\d+\s+(?:success\s+criteria|criteria)\s+(?:to\s+)?(.+)$/i',$q,$m)){$state=agent_goal_update_v1710($pdo,$user,$goalId,['success_criteria'=>(string)$m[1]]);$answer='Updated the goal success criteria. '.agent_goal_answer_v1710($state,true);$tool='goal.criteria';}
        elseif(preg_match('/\b(?:set|change|update)\s+goal\s*#?\s*\d+\s+strategy\s+(?:to\s+)?(.+)$/i',$q,$m)){$state=agent_goal_update_v1710($pdo,$user,$goalId,['strategy_summary'=>(string)$m[1]]);$answer='Updated the goal strategy. '.agent_goal_answer_v1710($state,true);$tool='goal.strategy.update';}
        elseif(preg_match('/\b(?:pause|hold)\b/i',$q)){$state=agent_goal_status_v1710($pdo,$user,$goalId,'paused');$answer='Paused goal planning. Existing objective/workflow execution was not changed. '.agent_goal_answer_v1710($state,true);$tool='goal.pause';}
        elseif(preg_match('/\b(?:resume|reactivate)\b/i',$q)){$state=agent_goal_status_v1710($pdo,$user,$goalId,'active');$answer='Resumed goal planning. Existing objective/workflow execution was not otherwise changed. '.agent_goal_answer_v1710($state,true);$tool='goal.resume';}
        elseif(preg_match('/\barchive\b/i',$q)){$state=agent_goal_status_v1710($pdo,$user,$goalId,'archived');$answer='Archived the goal as immutable history. Linked objectives and their receipts remain untouched.';$tool='goal.archive';}
        else{$state=agent_goal_state_v1710($pdo,$user,$goalId);$strategyFocus=(bool)preg_match('/\b(?:strategy|blocking|blockers?|next|what should i do|how do i reach|how can i reach|behind|risk)\b/i',$q);$answer=agent_goal_answer_v1710($state,$strategyFocus);$tool=$strategyFocus?'goal.strategy':'goal.inspect';}
        $out=$empty;$out['handled']=true;$out['answer']=$answer;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',['goal_id'=>$goalId,'build'=>VP3_AGENT_GOAL_STRATEGY_V1710],$conversationId);return $out;
    }catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not use the goal strategy layer: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'goal.strategy',$query,'error',['error'=>get_class($e)],$conversationId);return $out;}
}
