<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/transcript-agent-v1.php';

/** Accept only private preparation assignments grounded in the supplied snapshot. */
function vp3_initiative_plan_v1(string $answer,array $evidence): array
{
    if(strlen($answer)>6000)throw new InvalidArgumentException('Initiative plan too large.');
    $plan=json_decode($answer,false,16,JSON_THROW_ON_ERROR);
    if(!$plan instanceof stdClass||array_keys((array)$plan)!==['jobs'])throw new InvalidArgumentException('Invalid initiative plan.');
    $jobs=$plan->jobs;
    if(!is_array($jobs)||!array_is_list($jobs)||count($jobs)>3)throw new InvalidArgumentException('Invalid initiative jobs.');
    $primaries=[];$reports=0;
    foreach($jobs as &$job){
        $fields=['tool','primary','refs','focus','report'];
        if(!$job instanceof stdClass)throw new InvalidArgumentException('Invalid initiative assignment.');
        $job=(array)$job;
        if(count($job)!==count($fields)||array_diff(array_keys($job),$fields)
            ||($job['tool']??null)!=='work.prepare_private'||!is_string($job['primary']??null)
            ||!is_array($job['refs']??null)||!array_is_list($job['refs'])||count($job['refs'])>5
            ||!is_string($job['focus']??null)||!is_bool($job['report']??null)){
            throw new InvalidArgumentException('Invalid initiative assignment.');
        }
        $primary=$job['primary'];$refs=[];
        foreach($job['refs'] as $ref){
            if(!is_string($ref)||!isset($evidence[$ref])||isset($refs[$ref]))throw new InvalidArgumentException('Unknown or duplicate initiative source.');
            $refs[$ref]=true;
        }
        $job['focus']=trim($job['focus']);
        if(!isset($refs[$primary])||isset($primaries[$primary])||$job['focus']===''||mb_strlen($job['focus'])>300
            ||($job['report']&&++$reports>1))throw new InvalidArgumentException('Invalid initiative assignment scope.');
        $primaries[$primary]=true;
    }
    unset($job);
    return $jobs;
}

function vp3_initiative_evidence_v1(PDO $pdo,array $user,DateTimeImmutable $now): array
{
    $principal=vp3_agent_chat_principal_v380($user,null);
    $sources=[
        'calendar'=>fn()=>vp3_catch_up_calendar_v1($pdo,$user,$now),
        'mission'=>fn()=>vp3_catch_up_missions_v1($pdo,$user,$principal,$now,true),
    ];
    $evidence=[];
    foreach($sources as $kind=>$load){
        try{[$coverage,$items]=$load();}catch(Throwable $e){continue;}
        foreach(array_slice($items,0,4) as $item){
            $ref=(string)$item['id'];
            unset($item['priority']);
            if(($item['status']??'')==='active')unset($item['time'],$item['source_updated_at']);
            $evidence[$ref]=['ref'=>$ref,'kind'=>$kind,'title'=>$item['title'],'detail'=>$item['reason'],
                'url'=>$item['url'],'revision'=>hash('sha256',json_encode($item,JSON_THROW_ON_ERROR))];
        }
    }
    try{
        $catalog=vp3_transcript_catalog_v1($pdo,$user,$principal);
        foreach(array_slice($catalog['sessions'],0,4) as $row){
            $updated=vp3_catch_up_timestamp_v1($row['last_activity_at']??null,new DateTimeZone('UTC'));
            if($updated===null||$updated<$now->getTimestamp()-7*86400||$updated>$now->getTimestamp())continue;
            $id=(int)$row['id'];
            $ref='transcript:'.$id;
            try{
                $session=vp3_transcript_session_v1($pdo,$user,$principal,$id);
                video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session);
                $analysis=vp3_transcript_intelligence_v1($pdo,$user,$principal,$id,'initiative','actions');
                $counts=$analysis['review_counts']??[];
                $actions=$analysis['accepted_actions']??[];
                if(empty($analysis['fresh'])||(!$actions&&empty($counts['unreviewed'])))continue;
            }catch(Throwable $e){continue;}
            $evidence[$ref]=['ref'=>$ref,'kind'=>'transcript','title'=>mb_substr((string)$row['title'],0,170),
                'detail'=>'Transcript action review: '.(int)($counts['unreviewed']??0).' unreviewed suggestions.',
                'reviewed_actions'=>$actions,
                'url'=>url('/artist-listening.php?session='.$id),
                'revision'=>hash('sha256',json_encode([$row,$analysis['source_hash'],$analysis['generated_at'],$counts,$actions],JSON_THROW_ON_ERROR))];
        }
    }catch(Throwable $e){}
    ksort($evidence);
    return $evidence;
}

function vp3_initiative_policy_v1(array $user): array
{
    $memory=agent_brain_v122_memory($user,'initiative_policy','owner-opt-in');
    return array_intersect_key($memory['metadata']??[],array_flip(['enabled','conversation_id','revision']));
}

function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array
{
    $uid=(int)$user['id'];$cid=(int)($binding['conversation_id']??0);
    $live=vp3_capability_live_user_v1($pdo,$user,vp3_agent_chat_principal_v380($user,null),['conversation_id'=>$cid],null);
    $policy=vp3_initiative_policy_v1($live);
    if($cid<1||!has_permission('account.access',$live)||!personal_capability_has_v242('agent_brain.access',$live)||empty($policy['enabled'])
        ||($policy['conversation_id']??null)!==$cid||($policy['revision']??null)!==($binding['revision']??null)
        ||vp3_agent_memory_scope_current_v410($live)!==0){
        throw new RuntimeException('Initiative scope changed.',403);
    }
    return $live;
}

function vp3_initiative_model_v1(PDO $pdo,array $user,string $prompt,string $protocol): string
{
    $route=vp3_agent_runtime_plan_v420($pdo,$user,0,'chat');
    if(($route['route']??'')!=='vp3_cloud'||!empty($route['blocked']))throw new RuntimeException('Initiative Cloud inference unavailable.',503);
    $output=ai_generate_chat_response($prompt,[],[],$user,'main_ai',['surface'=>'tool_json','protocol'=>$protocol]);
    if(empty($output['ok'])||!is_string($output['answer']??null))throw new RuntimeException('Initiative inference was not confirmed.',503);
    return $output['answer'];
}

function vp3_initiative_enqueue_v1(PDO $pdo,array $user,string $capability,array $payload,string $hash): bool
{
    $uid=(int)$user['id'];
    $dedupe=hash('sha256',$capability.'|'.$hash);
    $existing=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
    $existing->execute([$uid,$dedupe]);
    if($existing->fetchColumn())return false;
    $label=$capability==='initiative.plan'?'Assess useful next steps':'Prepare a private next step';
    $pdo->prepare("INSERT INTO agent_workflow_runs
        (owner_user_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,next_attempt_at)
        VALUES (?,'initiative','brain','agent_initiative',?,?,?,?,?,'approved','low',0,'not_required','cloud',?,1,UTC_TIMESTAMP())")
        ->execute([$uid,$hash,substr($hash,0,40),$dedupe,$label,json_encode($payload,JSON_THROW_ON_ERROR),$capability]);
    $run=(int)$pdo->lastInsertId();
    if(agent_job_control_ready_v1900($pdo)){
        $pdo->prepare('UPDATE agent_workflow_runs SET work_priority=10 WHERE id=? AND owner_user_id=?')->execute([$run,$uid]);
    }
    $pdo->prepare("INSERT INTO agent_workflow_actions
        (run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,execution_target,capability_key,max_attempts)
        VALUES (?,?,1,?,'execute',?,'queued',0,'cloud',?,1)")
        ->execute([$run,$uid,$capability,$label,$capability]);
    agent_workflow_event_v1400($pdo,$uid,$run,'initiative_queued','','approved','system',$label);
    return true;
}

function vp3_initiative_owner_lock_v1(PDO $pdo,array $user): void
{
    $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $lock->execute([(int)$user['id']]);
    if(!$lock->fetchColumn())throw new RuntimeException('Initiative owner is unavailable.',403);
}

function vp3_initiative_schedule_v1(PDO $pdo,array $user): void
{
    if(vp3_agent_memory_scope_current_v410($user)!==0||!agent_job_engine_schema_ready_v1900($pdo))return;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return;
    $live=vp3_initiative_access_v1($pdo,$user,$policy);
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $last=agent_brain_v122_memory($live,'initiative_sweep','last-review');
        if(!$last||(strtotime((string)$last['last_seen_at'])?:0)<time()-300){
            $evidence=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            if($evidence){
                $payload=['binding'=>$policy,'sources'=>array_column($evidence,'revision','ref')];
                $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
                vp3_initiative_enqueue_v1($pdo,$live,'initiative.plan',$payload,$hash);
            }
            if(agent_brain_v122_upsert_system_memory($live,'initiative_sweep','last-review','Initiative source review checked.')<1){
                throw new RuntimeException('Initiative review could not be recorded.');
            }
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_initiative_sweep_v1(PDO $pdo,int $limit): void
{
    if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo))return;
    $limit=max(1,min(25,$limit));
    $stmt=$pdo->prepare("SELECT p.user_id FROM agent_memory_items p
        JOIN users u ON u.id=p.user_id AND u.is_active=1
        LEFT JOIN agent_memory_items s ON s.user_id=p.user_id AND s.user_agent_id IS NULL
            AND s.memory_type='initiative_sweep' AND s.subject='last-review' AND s.is_active=1
        WHERE p.user_agent_id IS NULL AND p.memory_type='initiative_policy' AND p.subject='owner-opt-in'
            AND p.is_active=1 AND JSON_VALID(p.metadata_json)
            AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'
            AND (s.id IS NULL OR s.last_seen_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))
        ORDER BY COALESCE(s.last_seen_at,p.last_seen_at),p.user_id LIMIT ".$limit);
    $stmt->execute();
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $owner){
        $user=agent_cognitive_loop_v310_user((int)$owner);
        if(!$user)continue;
        try{vp3_initiative_schedule_v1($pdo,$user);}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.schedule_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
}

function vp3_initiative_chat_v1(PDO $pdo,array $user,array $principal,array $context,string $command,?callable $authority): array
{
    $result=['handled'=>true,'actions'=>[],'sources'=>[]];
    $cid=(int)($context['conversation_id']??0);
    if(($principal['kind']??'')!=='system'||$cid<1||vp3_participant_scope_v1()!==null){
        return $result+['answer'=>'Use your private system Agent Chat to control initiative.'];
    }
    try{
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,$context,$authority);
        if(!has_permission('account.access',$live)||vp3_agent_memory_scope_current_v410($live)!==0
            ||($command!=='off'&&!personal_capability_has_v242('agent_brain.access',$live)))throw new RuntimeException('Initiative access denied.',403);
        if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            return $result+['answer'=>'Initiative needs the current Agent Brain and job database upgrades.'];
        }
        if($command==='status'){
            $policy=vp3_initiative_policy_v1($live);
            if(empty($policy['enabled']))return $result+['answer'=>'Initiative is off.'];
            if($policy['conversation_id']!==$cid)return $result+['answer'=>'Initiative is linked to another private conversation.'];
            return vp3_initiative_status_v1($pdo,$live,$policy,$result);
        }
        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_capability_live_user_v1($pdo,$live,$principal,$context,$authority);
        if(!has_permission('account.access',$live)||($command==='on'&&!personal_capability_has_v242('agent_brain.access',$live))){
            throw new RuntimeException('Initiative access denied.',403);
        }
        $policy=vp3_initiative_policy_v1($live);
        $enable=$command==='on';
        if($enable!==!empty($policy['enabled'])||($enable&&$policy['conversation_id']!==$cid)){
            $policy=['enabled'=>$enable,'conversation_id'=>$cid,'revision'=>bin2hex(random_bytes(16))];
            if(agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                throw new RuntimeException('Initiative setting could not be saved.');
            }
        }
        $pdo->commit();
        return $result+['answer'=>$enable?'Initiative is on for this conversation. With your Cloud AI settings, I can review upcoming calendar events, accepted transcript action notes, and Mission status. I can prepare private next steps and share useful results here. Use /initiative off to stop.':'Initiative is off.'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        return $result+['answer'=>'I couldn’t verify or update initiative for this conversation.'];
    }
}

function vp3_initiative_status_v1(PDO $pdo,array $user,array $policy,array $result): array
{
    $stmt=$pdo->prepare("SELECT r.goal,r.status,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.agent_id IS NULL
        ORDER BY r.id DESC LIMIT 15");
    $stmt->execute([(int)$user['id']]);
    $lines=['Initiative is on.'];$sources=[];
    $current=null;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $goal=json_decode((string)$row['goal'],true);
        if(($goal['binding']??null)!==$policy)continue;
        if($row['status']!=='completed'){
            if(in_array($row['status'],['approved','executing'],true))$lines[]='A private review is in progress.';
            if($row['status']==='failed')$lines[]='A previous review could not be verified.';
            continue;
        }
        $out=json_decode((string)$row['result_json'],true);
        if(($out['effect_state']??'')!=='private_draft_ready')continue;
        $current??=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')));
        $matching=array_intersect_key($current,$goal['sources']);
        if(array_column($matching,'revision','ref')!==$goal['sources'])continue;
        $lines[]=(string)$out['initiative_draft'];
        foreach($matching as $ref=>$item)$sources[$ref]=['source'=>$ref,'title'=>$item['title'],'url'=>$item['url']];
        if(count($lines)>=4)break;
    }
    $result['sources']=array_values($sources);
    return $result+['answer'=>implode("\n\n",array_values(array_unique($lines)))];
}

function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources): array
{
    $current=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    foreach($sources as $ref=>$revision){
        if(($current[$ref]['revision']??null)!==$revision)throw new RuntimeException('Initiative sources changed.',409);
    }
    return array_intersect_key($current,$sources);
}

function vp3_initiative_can_report_v1(PDO $pdo,array $user,int $cid): bool
{
    if(function_exists('agent_activity_v94_snapshot')&&empty(agent_activity_v94_snapshot($user,'chat')['interruptible']))return false;
    $stmt=$pdo->prepare("SELECT role,message,context_json FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 12");
    $stmt->execute([$cid]);
    $latest=$stmt->fetch(PDO::FETCH_ASSOC);
    if($latest&&($latest['role']==='user'||trim((string)$latest['message'])===''))return false;
    // A new situation can justify another report after the owner next engages.
    while($latest&&$latest['role']!=='user'){
        $context=json_decode((string)($latest['context_json']??''),true);
        if(($context['source']??'')==='agent_initiative')return false;
        $latest=$stmt->fetch(PDO::FETCH_ASSOC);
    }
    return true;
}

/** Use the existing leased worker and commit the result with its workflow receipt. */
function vp3_initiative_execute_v1(PDO $pdo,array $user,array $claim,?callable $model=null): array
{
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$run||$run['source_kind']!=='agent_initiative'||!in_array($run['action_capability_key'],['initiative.plan','initiative.prepare'],true)
        ||$run['agent_id']!==null)return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
    try{
        $payload=json_decode((string)$run['goal'],true,16,JSON_THROW_ON_ERROR);
        $binding=$payload['binding'];$sources=$payload['sources'];
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources);
        $modelEvidence=array_map(static fn(array $row):array=>array_diff_key($row,['url'=>true,'revision'=>true]),array_values($evidence));
        $planning=$run['action_capability_key']==='initiative.plan';
        $infer=$model??static fn(string $prompt,string $protocol):string=>vp3_initiative_model_v1($pdo,$live,$prompt,$protocol);
        if($planning){
            $answer=$infer(json_encode($modelEvidence,JSON_THROW_ON_ERROR),'vp3.initiative-plan.v1');
            $jobs=vp3_initiative_plan_v1($answer,$evidence);
        }else{
            $request=['focus'=>$payload['focus'],'evidence'=>$modelEvidence];
            $answer=$infer(json_encode($request,JSON_THROW_ON_ERROR),'vp3.initiative-draft.v1');
            $result=vp3_tool_json_object_v1($answer,['draft'],4200);
            $draft=is_string($result['draft']??null)?trim($result['draft']):'';
            if($draft===''||mb_strlen($draft)>490||preg_match('/https?:\/\/|\bwww\.|[<>]|[\x00-\x08\x0b-\x1f\x7f]/i',$draft)){
                throw new InvalidArgumentException('Invalid private draft.');
            }
        }

        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$binding);
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources);
        if($planning){
            $admitted=0;
            foreach($jobs as $job){
                $selected=array_intersect_key($sources,array_flip($job['refs']));
                $work=['binding'=>$binding,'sources'=>$selected,'focus'=>$job['focus'],'report'=>$job['report']];
                $hash=hash('sha256',json_encode([$binding,$job['primary'],$selected],JSON_THROW_ON_ERROR));
                if(vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$work,$hash))$admitted++;
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Initiative assessment recorded.',
                ['effect_state'=>$admitted?'assignments_queued':($jobs?'existing_assignments':'idle'),'jobs'=>$admitted]);
        }else{
            $cid=(int)$binding['conversation_id'];$uid=(int)$live['id'];
            if(!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,null,true))throw new RuntimeException('Initiative conversation changed.',403);
            if(!empty($payload['report'])&&vp3_initiative_can_report_v1($pdo,$live,$cid)){
                $links=array_map(static fn(array $row):array=>['source'=>$row['ref'],'title'=>$row['title'],'url'=>$row['url']],array_values($evidence));
                $context=['source'=>'agent_initiative','skip_brain_archive'=>true,'sources'=>$links];
                $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
                    VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$draft,json_encode($context,JSON_THROW_ON_ERROR)]);
                $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,$uid]);
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Private preparation completed.',
                ['effect_state'=>'private_draft_ready','initiative_draft'=>$draft]);
        }
        $pdo->commit();
        return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Initiative work could not be verified.',[],'initiative_unconfirmed',false);
        return ['ok'=>false,'reason'=>'initiative_unconfirmed','receipt'=>$receipt];
    }
}
