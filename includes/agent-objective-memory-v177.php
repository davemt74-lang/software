<?php
declare(strict_types=1);

/**
 * VP3 Phase 17.7 — Objective Memory + Outcome Learning.
 *
 * This is a derived, owner-scoped learning layer over the canonical objective,
 * workflow, dependency and receipt ledgers. It stores reusable plan/outcome
 * patterns only. It never stores or replays receipts, result payloads, leases,
 * approval decisions, or terminal workflow state, and it does not introduce a
 * scheduler, worker, polling loop, or second command center.
 */
const VP3_AGENT_OBJECTIVE_MEMORY_V177='agent-objective-memory-v177-20260915';
const VP3_AGENT_OBJECTIVE_MEMORY_SYNC_LIMIT_V177=80;
const VP3_AGENT_OBJECTIVE_MEMORY_MATCH_LIMIT_V177=5;

require_once __DIR__.'/agent-objective-verification-v176.php';

function agent_objective_memory_schema_ready_v177(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)($pdo
        && agent_objective_verification_schema_ready_v176($pdo)
        && table_exists('agent_objective_outcome_memory')
        && column_exists('agent_objective_outcome_memory','source_objective_run_id')
        && column_exists('agent_objective_outcome_memory','plan_template')
        && column_exists('agent_objective_outcome_memory','outcome_score'));
}

function agent_objective_memory_ensure_schema_v177(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    agent_objective_verification_ensure_schema_v176($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_objective_outcome_memory (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        source_objective_run_id BIGINT UNSIGNED NOT NULL,
        goal VARCHAR(900) NOT NULL DEFAULT '',
        intent_signature VARCHAR(255) NOT NULL DEFAULT '',
        outcome_status VARCHAR(32) NOT NULL DEFAULT '',
        outcome_score INT NOT NULL DEFAULT 0,
        remediation_count INT UNSIGNED NOT NULL DEFAULT 0,
        plan_template MEDIUMTEXT NOT NULL,
        success_criteria MEDIUMTEXT NULL,
        outcome_summary VARCHAR(500) NOT NULL DEFAULT '',
        reuse_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_reused_at DATETIME NULL,
        learned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_agent_objective_memory_source (owner_user_id,source_objective_run_id),
        KEY idx_agent_objective_memory_rank (owner_user_id,outcome_status,outcome_score,id),
        KEY idx_agent_objective_memory_recent (owner_user_id,updated_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function agent_objective_memory_require_v177(PDO $pdo,array $user): int
{
    $uid=(int)($user['id']??0);
    if($uid<1||!has_permission('account.access',$user))throw new RuntimeException('Objective memory is not available for this account.');
    if(!agent_objective_memory_schema_ready_v177($pdo))throw new RuntimeException('Objective memory is not ready yet. Run the normal VP3 database upgrade first.');
    return $uid;
}

function agent_objective_memory_json_v177(mixed $value): array
{
    if(is_array($value))return $value;
    $text=trim((string)$value);if($text==='')return [];
    $decoded=json_decode($text,true);return is_array($decoded)?$decoded:[];
}

function agent_objective_memory_terms_v177(string $text): array
{
    $text=mb_strtolower(trim($text));if($text==='')return [];
    $parts=preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $stop=array_flip(['a','an','and','are','as','at','be','by','for','from','i','in','into','is','it','my','of','on','or','our','the','this','to','we','with','objective','plan','workflow','workflows']);
    $out=[];
    foreach($parts as $part){$part=mb_substr((string)$part,0,40);if(mb_strlen($part)<3||isset($stop[$part]))continue;$out[$part]=true;if(count($out)>=24)break;}
    return array_keys($out);
}

function agent_objective_memory_signature_v177(string $goal): string
{
    return mb_substr(implode(' ',agent_objective_memory_terms_v177($goal)),0,255);
}

function agent_objective_memory_outcome_v177(array $parent): ?array
{
    $verification=(string)($parent['objective_verification_status']??'');
    $remediation=max(0,(int)($parent['objective_remediation_count']??0));
    $status=(string)($parent['status']??'');
    if($verification==='achieved'){
        $outcome=$remediation>0?'remediated':'achieved';
        $score=max(55,($outcome==='achieved'?100:86)-min(24,$remediation*6));
        return ['status'=>$outcome,'score'=>$score,'remediation_count'=>$remediation];
    }
    if(in_array($status,['failed','cancelled'],true))return ['status'=>'failed','score'=>10,'remediation_count'=>$remediation];
    return null;
}

function agent_objective_memory_template_v177(PDO $pdo,int $uid,array $parent): array
{
    $children=agent_objective_children_rows_v175($pdo,$uid,$parent);
    $stages=[];$lessons=[];
    foreach($children as $child){
        if(!preg_match('/:s(\d+)t(\d+)$/',(string)($child['source_key']??''),$m))continue;
        $stage=(int)$m[1];$task=(int)$m[2];
        $title=agent_objective_text_v175((string)($child['title']??''),190);
        $instruction=agent_objective_text_v175((string)($child['goal']??$title),1500);
        $target=in_array((string)($child['execution_target']??''),['cloud','homeserver'],true)?(string)$child['execution_target']:'cloud';
        if($stage>=VP3_AGENT_OBJECTIVE_REMEDIATION_STAGE_BASE_V176){
            if($title!=='')$lessons[]=['title'=>$title,'instruction'=>$instruction,'execution_target_preference'=>$target];
            continue;
        }
        $stages[$stage][$task]=[
            'title'=>$title,
            'instruction'=>$instruction,
            'execution_target_preference'=>$target,
            'observed_risk_level'=>(string)($child['risk_level']??'low'),
            'observed_requires_approval'=>!empty($child['requires_approval']),
        ];
    }
    ksort($stages,SORT_NUMERIC);$normalized=[];
    foreach($stages as $tasks){ksort($tasks,SORT_NUMERIC);$normalized[]=array_values($tasks);}
    return [
        'version'=>1,
        'source_objective_run_id'=>(int)($parent['id']??0),
        'stages'=>$normalized,
        'remediation_lessons'=>array_slice($lessons,0,12),
        'note'=>'Observed risk/approval fields and execution targets are learning hints only. Reuse must recalculate current approval/risk and normalize the current execution boundary.',
    ];
}

function agent_objective_memory_capture_v177(PDO $pdo,int $uid,array $parent): ?int
{
    $outcome=agent_objective_memory_outcome_v177($parent);if(!$outcome)return null;
    $template=agent_objective_memory_template_v177($pdo,$uid,$parent);
    $stageCount=count((array)($template['stages']??[]));$stepCount=0;foreach((array)($template['stages']??[]) as $stage)$stepCount+=count((array)$stage);
    if($stageCount<1||$stepCount<2)return null;
    $goal=agent_objective_text_v175((string)($parent['goal']??''),900);
    $criteria=agent_objective_verification_json_v176($parent['objective_success_criteria']??'');
    $summary=agent_objective_text_v175((string)($parent['objective_verification_summary']??''),500);
    $stmt=$pdo->prepare("INSERT INTO agent_objective_outcome_memory (owner_user_id,source_objective_run_id,goal,intent_signature,outcome_status,outcome_score,remediation_count,plan_template,success_criteria,outcome_summary) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE goal=VALUES(goal),intent_signature=VALUES(intent_signature),outcome_status=VALUES(outcome_status),outcome_score=VALUES(outcome_score),remediation_count=VALUES(remediation_count),plan_template=VALUES(plan_template),success_criteria=VALUES(success_criteria),outcome_summary=VALUES(outcome_summary),updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$uid,(int)$parent['id'],$goal,agent_objective_memory_signature_v177($goal),(string)$outcome['status'],(int)$outcome['score'],(int)$outcome['remediation_count'],agent_workflow_json_v1400($template),$criteria?agent_workflow_json_v1400($criteria):null,$summary]);
    $find=$pdo->prepare('SELECT id FROM agent_objective_outcome_memory WHERE owner_user_id=? AND source_objective_run_id=? LIMIT 1');$find->execute([$uid,(int)$parent['id']]);$id=(int)$find->fetchColumn();return $id>0?$id:null;
}

function agent_objective_memory_sync_recent_v177(PDO $pdo,array $user,int $limit=VP3_AGENT_OBJECTIVE_MEMORY_SYNC_LIMIT_V177): int
{
    $uid=agent_objective_memory_require_v177($pdo,$user);$limit=max(1,min(VP3_AGENT_OBJECTIVE_MEMORY_SYNC_LIMIT_V177,$limit));
    $stmt=$pdo->prepare("SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND source_kind='objective_plan' AND (objective_verification_status='achieved' OR status IN ('failed','cancelled')) ORDER BY id DESC LIMIT ".$limit);
    $stmt->execute([$uid]);$count=0;
    foreach($stmt->fetchAll()?:[] as $parent){if(agent_objective_memory_capture_v177($pdo,$uid,$parent)!==null)$count++;}
    return $count;
}

function agent_objective_memory_row_v177(PDO $pdo,int $uid,int $memoryId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_objective_outcome_memory WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$memoryId,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_memory_by_source_v177(PDO $pdo,int $uid,int $objectiveRunId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM agent_objective_outcome_memory WHERE source_objective_run_id=? AND owner_user_id=? LIMIT 1');$stmt->execute([$objectiveRunId,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
}

function agent_objective_memory_similarity_v177(string $goal,array $memory): float
{
    $query=agent_objective_memory_terms_v177($goal);$past=agent_objective_memory_terms_v177((string)($memory['intent_signature']??$memory['goal']??''));
    if(!$query||!$past)return 0.0;
    $intersection=count(array_intersect($query,$past));$union=count(array_unique(array_merge($query,$past)));$lexical=$union>0?$intersection/$union:0.0;
    $quality=max(0,min(100,(int)($memory['outcome_score']??0)))/100;
    $outcome=(string)($memory['outcome_status']??'');$outcomeFactor=$outcome==='failed'?0.20:($outcome==='remediated'?0.88:1.0);
    return round(($lexical*0.78+$quality*0.22)*$outcomeFactor,4);
}

function agent_objective_memory_similar_v177(PDO $pdo,array $user,string $goal,int $limit=VP3_AGENT_OBJECTIVE_MEMORY_MATCH_LIMIT_V177,bool $includeFailures=true): array
{
    $uid=agent_objective_memory_require_v177($pdo,$user);$goal=agent_objective_text_v175($goal,900);if($goal==='')return [];
    agent_objective_memory_sync_recent_v177($pdo,$user);
    $sql='SELECT * FROM agent_objective_outcome_memory WHERE owner_user_id=?'.($includeFailures?'':" AND outcome_status<>'failed'").' ORDER BY outcome_score DESC,id DESC LIMIT 120';
    $stmt=$pdo->prepare($sql);$stmt->execute([$uid]);$ranked=[];
    foreach($stmt->fetchAll()?:[] as $row){$row['similarity_score']=agent_objective_memory_similarity_v177($goal,$row);if((float)$row['similarity_score']<=0.08)continue;$ranked[]=$row;}
    usort($ranked,static fn(array $a,array $b): int=>((float)$b['similarity_score']<=>(float)$a['similarity_score'])?:((int)$b['outcome_score']<=>(int)$a['outcome_score'])?:((int)$b['id']<=>(int)$a['id']));
    return array_slice($ranked,0,max(1,min(10,$limit)));
}

function agent_objective_memory_replay_stages_v177(array $memory): array
{
    $template=agent_objective_memory_json_v177($memory['plan_template']??'');$out=[];
    foreach(array_slice((array)($template['stages']??[]),0,VP3_AGENT_OBJECTIVE_MAX_STAGES_V175) as $stage){
        $clean=[];
        foreach((array)$stage as $step){
            if(!is_array($step))continue;
            $title=agent_objective_text_v175($step['title']??'',190);$instruction=agent_objective_text_v175($step['instruction']??$title,1500);if($title==='')continue;
            $preferred=(string)($step['execution_target_preference']??'cloud');$target=agent_objective_target_v175($instruction,in_array($preferred,['cloud','homeserver'],true)?$preferred:'cloud');
            $clean[]=['title'=>$title,'instruction'=>$instruction,'target'=>$target];
        }
        if($clean)$out[]=$clean;
    }
    return $out;
}

function agent_objective_memory_reuse_v177(PDO $pdo,array $user,array $memory,string $goal,int $conversationId=0,?int $agentId=null): array
{
    $uid=agent_objective_memory_require_v177($pdo,$user);if((int)($memory['owner_user_id']??0)!==$uid)throw new RuntimeException('Objective memory not found.');
    if(!in_array((string)($memory['outcome_status']??''),['achieved','remediated'],true))throw new RuntimeException('Failed objective memory is negative evidence and cannot be replayed as a plan.');
    $goal=agent_objective_text_v175($goal!==''?$goal:(string)($memory['goal']??''),900);if($goal==='')throw new RuntimeException('A new objective goal is required.');
    $stages=agent_objective_memory_replay_stages_v177($memory);$steps=0;foreach($stages as $stage)$steps+=count($stage);if($steps<2)throw new RuntimeException('This learned plan is not reusable.');
    // Critical boundary: create a completely fresh objective through v17.5.
    // Current risk/approval planning, IDs, dependencies, leases and receipts are
    // regenerated; no historical receipt/result/approval payload is copied.
    $state=agent_objective_create_v175($pdo,$user,$goal,$stages,$conversationId,$agentId);
    $parentId=(int)($state['objective']['id']??0);
    $pdo->prepare('UPDATE agent_objective_outcome_memory SET reuse_count=reuse_count+1,last_reused_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?')->execute([(int)$memory['id'],$uid]);
    if($parentId>0)agent_workflow_event_v1400($pdo,$uid,$parentId,'objective_memory_reused','','approved','agent','A learned objective pattern seeded fresh canonical workflows.',['memory_id'=>(int)$memory['id'],'source_objective_run_id'=>(int)$memory['source_objective_run_id'],'historical_outcome'=>(string)$memory['outcome_status']]);
    $state['memory_reuse']=['memory_id'=>(int)$memory['id'],'source_objective_run_id'=>(int)$memory['source_objective_run_id'],'historical_outcome'=>(string)$memory['outcome_status'],'historical_score'=>(int)$memory['outcome_score']];
    return $state;
}

function agent_objective_memory_answer_matches_v177(array $matches): string
{
    if(!$matches)return 'I do not have a sufficiently similar learned objective yet.';
    $parts=[];foreach($matches as $row){$parts[]='Memory #'.(int)$row['id'].' · '.ucfirst((string)$row['outcome_status']).' · '.(int)round(((float)$row['similarity_score'])*100).'% similar · “'.agent_objective_text_v175((string)$row['goal'],130).'”';}
    return 'I found '.count($matches).' relevant objective memor'.(count($matches)===1?'y':'ies').': '.implode(' | ',$parts).'. Clean achieved plans rank above remediated plans; failures are retained only as negative evidence.';
}

function agent_objective_memory_chat_v177(string $query,array $user,int $conversationId=0): array
{
    $empty=agent_objective_empty_tool_v175();$q=trim($query);if($q==='')return $empty;
    $memoryLanguage=(bool)preg_match('/\b(?:objective memory|past objectives?|previous objectives?|similar objectives?|learned plans?|past plans?|reuse objective|reuse memory|best past plan|what (?:have you|did you) learn)\b/i',$q);
    if(!$memoryLanguage)return $empty;$pdo=db();if(!$pdo)return $empty;
    try{
        $uid=agent_objective_memory_require_v177($pdo,$user);agent_objective_memory_sync_recent_v177($pdo,$user);
        $state=[];$tool='objective.memory';$answer='';
        if(preg_match('/\b(?:reuse|use)\s+(?:objective\s+)?memory\s*#?(\d+)(?:\s+(?:for|to)\s+(.+))?/i',$q,$m)){
            $memory=agent_objective_memory_row_v177($pdo,$uid,(int)$m[1]);if(!$memory)throw new RuntimeException('Objective memory not found.');$goal=trim((string)($m[2]??''));$state=agent_objective_memory_reuse_v177($pdo,$user,$memory,$goal,$conversationId,(int)($user['agent_id']??0)?:null);$answer='Reused learned memory #'.(int)$memory['id'].' as a fresh objective #'.(int)($state['objective']['id']??0).'. Current workflows, dependencies, approvals, execution boundaries and receipts were regenerated; historical execution state was not copied.';$tool='objective.memory.reuse';
        }elseif(preg_match('/\breuse\s+objective\s*#?(\d+)\s+(?:plan\s+)?(?:for|to)\s+(.+)/i',$q,$m)){
            $memory=agent_objective_memory_by_source_v177($pdo,$uid,(int)$m[1]);if(!$memory)throw new RuntimeException('That objective has no reusable learned outcome yet.');$state=agent_objective_memory_reuse_v177($pdo,$user,$memory,trim((string)$m[2]),$conversationId,(int)($user['agent_id']??0)?:null);$answer='Reused the learned pattern from objective #'.(int)$memory['source_objective_run_id'].' as fresh objective #'.(int)($state['objective']['id']??0).'. Current safety and approval planning was recalculated.';$tool='objective.memory.reuse';
        }elseif(preg_match('/\b(?:use|reuse)\s+(?:the\s+)?best\s+past\s+plan\s+(?:for|to)\s+(.+)/i',$q,$m)){
            $goal=trim((string)$m[1]);$matches=agent_objective_memory_similar_v177($pdo,$user,$goal,1,false);if(!$matches)throw new RuntimeException('No sufficiently similar successful objective memory is available.');$state=agent_objective_memory_reuse_v177($pdo,$user,$matches[0],$goal,$conversationId,(int)($user['agent_id']??0)?:null);$answer='Used the strongest similar learned plan (memory #'.(int)$matches[0]['id'].') to create fresh objective #'.(int)($state['objective']['id']??0).'. Current approvals and execution boundaries were recalculated.';$tool='objective.memory.reuse_best';
        }elseif(preg_match('/\b(?:show|inspect)\s+(?:objective\s+)?memory\s*#?(\d+)/i',$q,$m)){
            $memory=agent_objective_memory_row_v177($pdo,$uid,(int)$m[1]);if(!$memory)throw new RuntimeException('Objective memory not found.');$template=agent_objective_memory_json_v177($memory['plan_template']);$stepCount=0;foreach((array)($template['stages']??[]) as $stage)$stepCount+=count((array)$stage);$answer='Memory #'.(int)$memory['id'].' learned from objective #'.(int)$memory['source_objective_run_id'].': '.ucfirst((string)$memory['outcome_status']).' (quality '.(int)$memory['outcome_score'].'/100), '.count((array)($template['stages']??[])).' stage(s), '.$stepCount.' reusable step(s), '.(int)$memory['remediation_count'].' remediation cycle(s), '.(int)$memory['reuse_count'].' reuse(s). Goal: '.agent_objective_text_v175((string)$memory['goal'],220).'.';$tool='objective.memory.inspect';
        }elseif(preg_match('/\b(?:similar objectives?|past plans?|learned plans?)\s+(?:for|to|about)\s+(.+)/i',$q,$m)){
            $matches=agent_objective_memory_similar_v177($pdo,$user,trim((string)$m[1]),VP3_AGENT_OBJECTIVE_MEMORY_MATCH_LIMIT_V177,true);$answer=agent_objective_memory_answer_matches_v177($matches);$tool='objective.memory.search';
        }else{
            $stmt=$pdo->prepare("SELECT outcome_status,COUNT(*) AS total,ROUND(AVG(outcome_score)) AS avg_score,SUM(reuse_count) AS reused FROM agent_objective_outcome_memory WHERE owner_user_id=? GROUP BY outcome_status ORDER BY outcome_status");$stmt->execute([$uid]);$parts=[];foreach($stmt->fetchAll()?:[] as $row)$parts[]=ucfirst((string)$row['outcome_status']).': '.(int)$row['total'].' learned, avg quality '.(int)$row['avg_score'].'/100, '.(int)$row['reused'].' reuse(s)';$answer=$parts?'Objective learning is owner-scoped. '.implode(' | ',$parts).'. I retain failed outcomes as negative evidence and never replay them automatically.':'I do not have completed objective outcomes to learn from yet.';$tool='objective.memory.summary';
        }
        $out=$empty;$out['handled']=true;$out['answer']=$answer;if(function_exists('agent_tool_log'))agent_tool_log($user,$tool,$query,'success',['memory_build'=>VP3_AGENT_OBJECTIVE_MEMORY_V177,'objective_run_id'=>(int)($state['objective']['id']??0)],$conversationId);return $out;
    }catch(Throwable $e){$out=$empty;$out['handled']=true;$out['answer']='I could not use objective memory: '.$e->getMessage();if(function_exists('agent_tool_log'))agent_tool_log($user,'objective.memory',$query,'error',['error'=>get_class($e)],$conversationId);return $out;}
}
