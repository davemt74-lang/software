<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';

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

function vp3_initiative_evidence_v1(PDO $pdo,array $user,DateTimeImmutable $now,bool $includeScreen=false,array $screenSessions=[],?array &$coverage=null,?array $selectedRefs=null): array
{
    $principal=vp3_agent_chat_principal_v380($user,null);
    $current=vp3_catch_up_candidates_v1($pdo,$user,'all',0,true,$now,true);
    $coverage=$current['coverage']+['transcripts'=>'ready','screen_followup'=>$includeScreen?'ready':'forbidden'];
    $selected=$selectedRefs===null?null:array_fill_keys($selectedRefs,true);
    $kinds=[
        'calendar'=>'calendar','reminders'=>'reminder','tasks'=>'task','missions'=>'mission',
        'profile_calls'=>'profile_call','meeting_prep'=>'meeting_prep','orders'=>'order',
        'campaigns'=>'campaign','rewards'=>'reward',
    ];
    $evidence=[];$counts=[];
    foreach($current['items'] as $item){
        $ref=(string)$item['id'];
        if($selected!==null&&!isset($selected[$ref]))continue;
        $family=$current['source_kinds'][$ref];
        $counts[$family]=($counts[$family]??0)+1;
        if($selected===null&&$counts[$family]>4)continue;
        $evidence[$ref]=['ref'=>$ref,'kind'=>$kinds[$family],'title'=>$item['title'],'detail'=>$item['reason'],
            'url'=>$item['url'],'revision'=>$current['source_revisions'][$ref]];
    }
    try{
        require_once __DIR__.'/transcript-agent-v1.php';
        $sessions=[];
        if($selected===null){
            $catalog=vp3_transcript_catalog_v1($pdo,$user,$principal);
            $rows=array_slice($catalog['sessions'],0,4);
            if(count($catalog['sessions'])>4||($catalog['next_before']??null)!==null)$coverage['transcripts']='limited';
        }else{
            $rows=[];
            foreach($selectedRefs as $ref){
                if(!preg_match('/^transcript:([1-9][0-9]*)$/D',$ref,$match))continue;
                try{$session=vp3_transcript_session_v1($pdo,$user,$principal,(int)$match[1]);}
                catch(RuntimeException $e){if($e->getCode()===404)continue;throw $e;}
                $sessions[(int)$session['id']]=$session;
                $row=[];
                foreach(['id','title','status','conversation_id','started_at','last_activity_at'] as $field)$row[$field]=$session[$field];
                $rows[]=$row;
            }
        }
        foreach($rows as $row){
            $updated=vp3_catch_up_timestamp_v1($row['last_activity_at']??null,new DateTimeZone('UTC'));
            if($updated===null||$updated<$now->getTimestamp()-7*86400||$updated>$now->getTimestamp())continue;
            $id=(int)$row['id'];
            $ref='transcript:'.$id;
            try{
                $session=$sessions[$id]??vp3_transcript_session_v1($pdo,$user,$principal,$id);
                video_meeting_transcription_assert_cloud_ai_v1801($pdo,$user,$session);
                $analysis=vp3_transcript_intelligence_v1($pdo,$user,$principal,$id,'initiative','actions');
                $counts=$analysis['review_counts']??[];
                $actions=$analysis['accepted_actions']??[];
                if(empty($analysis['fresh'])||(!$actions&&empty($counts['unreviewed'])))continue;
            }catch(Throwable $e){$coverage['transcripts']=$e->getCode()===403?'forbidden':'unavailable';continue;}
            $evidence[$ref]=['ref'=>$ref,'kind'=>'transcript','title'=>mb_substr((string)$row['title'],0,170),
                'detail'=>'Transcript action review: '.(int)($counts['unreviewed']??0).' unreviewed suggestions.',
                'reviewed_actions'=>$actions,
                'url'=>url('/artist-listening.php?session='.$id),
                'revision'=>hash('sha256',json_encode([$row,$analysis['source_hash'],$analysis['generated_at'],$counts,$actions],JSON_THROW_ON_ERROR))];
        }
    }catch(Throwable $e){$coverage['transcripts']=$e->getCode()===403?'forbidden':'unavailable';}
    if($includeScreen){
        require_once __DIR__.'/agent-initiative-screen-v1.php';
        try{$evidence+=vp3_initiative_screen_evidence_v1($pdo,$user,$now,$screenSessions);}
        catch(Throwable $e){
            $coverage['screen_followup']=$e->getCode()===403?'forbidden':'unavailable';
            if($screenSessions)throw $e;
        }
    }
    ksort($evidence);
    return $evidence;
}

function vp3_initiative_policy_v1(array $user): array
{
    $memory=agent_brain_v122_memory($user,'initiative_policy','owner-opt-in');
    return array_intersect_key($memory['metadata']??[],array_flip(['enabled','conversation_id','revision','screen_enabled']));
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
            $evidence=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),!empty($policy['screen_enabled']));
            if($evidence){
                $payload=['binding'=>$policy,'sources'=>array_column($evidence,'revision','ref')];
                $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
                vp3_initiative_enqueue_v1($pdo,$live,'initiative.plan',$payload,$hash);
            }
            $review=[
                'conversation_id'=>$policy['conversation_id'],
                'revision'=>$policy['revision'],
                'checked_at'=>gmdate(DATE_ATOM),
            ];
            if(agent_brain_v122_upsert_system_memory($live,'initiative_sweep','last-review','Initiative source review checked.',$review)<1){
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
    vp3_initiative_delivery_sweep_v1($pdo,$limit);
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

/** Both Agent Eyes and Chat commands use the same owner policy and revision. */
function vp3_initiative_screen_setting_v1(PDO $pdo,array $user,int $cid,?bool $enabled=null,?string $expectedRevision=null,?callable $authority=null): array
{
    if($cid<1||vp3_participant_scope_v1()!==null||vp3_agent_memory_scope_current_v410($user)!==0){
        throw new RuntimeException('Open your private System Agent Chat to change this setting.',403);
    }
    $unavailable=['available'=>false,'enabled'=>false,'revision'=>''];
    $principal=vp3_agent_chat_principal_v380($user,null);
    try{
        if($enabled!==null){
            $pdo->beginTransaction();
            vp3_initiative_owner_lock_v1($pdo,$user);
        }
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],$authority);
        if(!has_permission('account.access',$live)||!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$live['id'],null,$enabled!==null)){
            throw new RuntimeException('Conversation access changed.',403);
        }
        if(!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            if($enabled!==null)throw new RuntimeException('Initiative storage needs its database upgrades.',503);
            return $unavailable+['reason'=>'storage_unavailable'];
        }
        $policy=vp3_initiative_policy_v1($live);
        if(empty($policy['enabled'])||$policy['conversation_id']!==$cid){
            if($enabled!==null)$pdo->rollBack();
            return $unavailable+['reason'=>empty($policy['enabled'])?'initiative_off':'linked_elsewhere'];
        }
        $screenEnabled=!empty($policy['screen_enabled']);
        $canEnable=personal_capability_has_v242('agent_brain.access',$live)&&personal_knowledge_available($live);
        if($enabled!==null){
            if($expectedRevision!==null&&!hash_equals((string)$policy['revision'],$expectedRevision)){
                throw new RuntimeException('Screen follow-up settings changed. Refresh and try again.',409);
            }
            if($enabled&&!$canEnable)throw new RuntimeException('Agent Brain and My Knowledge access are required.',403);
            if($enabled!==$screenEnabled){
                $policy['screen_enabled']=$enabled;
                $policy['revision']=bin2hex(random_bytes(16));
                if(agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                    throw new RuntimeException('Initiative screen setting could not be saved.',503);
                }
                $screenEnabled=$enabled;
            }
            $pdo->commit();
        }
        return ['available'=>$canEnable||$screenEnabled,'enabled'=>$screenEnabled,
            'revision'=>(string)$policy['revision'],'reason'=>$canEnable?'':'access_unavailable'];
    }catch(Throwable $e){
        if($enabled!==null&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_initiative_chat_v1(PDO $pdo,array $user,array $principal,array $context,string $command,?callable $authority): array
{
    $result=['handled'=>true,'actions'=>[],'sources'=>[]];
    $cid=(int)($context['conversation_id']??0);
    $disabling=$command==='off';
    if(($principal['kind']??'')!=='system'||$cid<1||vp3_participant_scope_v1()!==null){
        return $result+['answer'=>'Use your private system Agent Chat to control initiative.'];
    }
    try{
        if(in_array($command,['screen on','screen off'],true)){
            $enableScreen=$command==='screen on';
            $setting=vp3_initiative_screen_setting_v1($pdo,$user,$cid,$enableScreen,null,$authority);
            if(in_array($setting['reason'],['initiative_off','linked_elsewhere'],true)){
                return $result+['answer'=>'Enable Initiative here first, or open the private Chat where it is already enabled.'];
            }
            return $result+['answer'=>$enableScreen?'Screen-share follow-ups are on. Initiative can use saved note text and session titles from finished shares with your configured Cloud AI model. Use /initiative screen off to stop.':'Screen-share follow-ups are off.'];
        }
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,$context,$authority);
        if(!has_permission('account.access',$live)||vp3_agent_memory_scope_current_v410($live)!==0
            ||(!$disabling&&!personal_capability_has_v242('agent_brain.access',$live)))throw new RuntimeException('Initiative access denied.',403);
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
        if(!has_permission('account.access',$live)||(!$disabling&&!personal_capability_has_v242('agent_brain.access',$live))){
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
        return $result+['answer'=>$enable?'Initiative is on for this conversation. With your Cloud AI settings, I can review upcoming calendar events, accepted transcript action notes, and Mission status. I can prepare private next steps and share useful results here. Use /initiative screen on to include saved screen-share follow-ups or /initiative off to stop.':'Initiative is off.'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        return $result+['answer'=>'I couldn’t verify or update initiative for this conversation.'];
    }
}

/** Project only a verified Initiative state for the current private System Agent Chat. */
function vp3_initiative_foreground_status_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $unavailable=['state'=>'unavailable'];
    if($agentId!==0||$cid<0||(int)($user['id']??0)<1||vp3_agent_memory_scope_current_v410($user)!==0
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return $unavailable;
    }
    try{
        $principal=vp3_agent_chat_principal_v380($user,null);
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
        if(!has_permission('account.access',$live)||!personal_capability_has_v242('agent_brain.access',$live)
            ||!agent_brain_schema_ready()||!agent_job_engine_schema_ready_v1900($pdo)){
            return $unavailable;
        }
        $policy=vp3_initiative_policy_v1($live);
        if(empty($policy['enabled']))return ['state'=>'off'];
        if($cid<1||($policy['conversation_id']??null)!==$cid)return ['state'=>'linked_elsewhere'];
        $revision=$policy['revision']??null;
        if(!is_string($revision)||!preg_match('/\A[a-f0-9]{32}\z/',$revision))return $unavailable;

        $status=['state'=>'waiting_for_review'];
        $review=agent_brain_v122_memory($live,'initiative_sweep','last-review')['metadata']??[];
        $utc=new DateTimeZone('UTC');
        if(($review['conversation_id']??null)===$cid&&($review['revision']??null)===$revision){
            $checkedAt=vp3_catch_up_timestamp_v1($review['checked_at']??null,$utc);
            if($checkedAt!==null&&$checkedAt<=time()){
                $status=['state'=>'checked','last_checked_at'=>gmdate(DATE_ATOM,$checkedAt)];
            }
        }
        $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
        $jsonValue=static function(string $column,string $path)use($sqlite): string {
            $value="JSON_EXTRACT(CASE WHEN JSON_VALID($column) THEN $column ELSE '{}' END,'$path')";
            return $sqlite?$value:"JSON_UNQUOTE($value)";
        };
        $conversation=$jsonValue('r.goal','$.binding.conversation_id');
        $runRevision=$jsonValue('r.goal','$.binding.revision');
        $effect=$jsonValue('a.result_json','$.effect_state');
        $delivery=$jsonValue('a.result_json','$.initiative_delivery');
        $stmt=$pdo->prepare("SELECT r.goal,r.status,r.lease_expires_at,$effect AS effect_state,$delivery AS initiative_delivery FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.agent_id IS NULL
                AND CAST($conversation AS CHAR)=? AND $runRevision=?
            ORDER BY r.id DESC LIMIT 15");
        $stmt->execute([(int)$live['id'],(string)$cid,$revision]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $goal=json_decode((string)$row['goal'],true);
            if(($goal['binding']??null)!==$policy)continue;
            if($row['status']==='failed')return ['state'=>'failed']+$status;
            if(in_array($row['status'],['approved','queued'],true))return ['state'=>'waiting_for_review']+$status;
            if($row['status']==='executing'){
                $lease=vp3_catch_up_timestamp_v1($row['lease_expires_at'],$utc);
                return ['state'=>$lease!==null&&$lease>=time()?'reviewing':'waiting_for_review']+$status;
            }
            if($row['status']!=='completed')continue;
            $status['state']='checked';
            if($row['effect_state']!=='private_draft_ready'||in_array($row['initiative_delivery'],['delivered','withheld'],true)
                ||!is_array($goal['sources']??null)||!$goal['sources'])continue;
            try{
                vp3_initiative_current_sources_v1($pdo,$live,$goal['sources'],!empty($policy['screen_enabled']));
                return ['state'=>'ready']+$status;
            }catch(RuntimeException $e){
                if($e->getCode()!==409)throw $e;
                return $status;
            }
        }
        return $status;
    }catch(Throwable $e){
        return $unavailable;
    }
}

function vp3_initiative_status_v1(PDO $pdo,array $user,array $policy,array $result): array
{
    $stmt=$pdo->prepare("SELECT r.goal,r.status,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.agent_id IS NULL
        ORDER BY r.id DESC LIMIT 15");
    $stmt->execute([(int)$user['id']]);
    $includeScreen=!empty($policy['screen_enabled']);
    $lines=['Initiative is on. Screen-share follow-ups are '.($includeScreen?'on.':'off.')];$sources=[];
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
        $current??=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),$includeScreen);
        $matching=array_intersect_key($current,$goal['sources']);
        if(array_column($matching,'revision','ref')!==$goal['sources'])continue;
        $lines[]=(string)$out['initiative_draft'];
        foreach($matching as $ref=>$item)$sources[$ref]=['source'=>$ref,'title'=>$item['title'],'url'=>$item['url']];
        if(count($lines)>=4)break;
    }
    $result['sources']=array_values($sources);
    return $result+['answer'=>implode("\n\n",array_values(array_unique($lines)))];
}

function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources,bool $includeScreen=false): array
{
    $sessions=[];
    foreach(array_keys($sources) as $ref)if(str_starts_with($ref,'screen-followup:'))$sessions[]=substr($ref,16);
    $coverage=[];
    $current=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),$includeScreen&&$sessions!==[],$sessions,$coverage,array_keys($sources));
    $families=['calendar'=>'calendar','calendar-conflict'=>'calendar','reminder'=>'reminders','task'=>'tasks','mission'=>'missions',
        'profile-call'=>'profile_calls','meeting-prep'=>'meeting_prep','order'=>'orders','campaign'=>'campaigns',
        'campaign-journey'=>'campaigns',
        'reward'=>'rewards','transcript'=>'transcripts','screen-followup'=>'screen_followup'];
    foreach($sources as $ref=>$revision){
        if(($current[$ref]['revision']??null)===$revision)continue;
        $family=$families[explode(':',$ref,2)[0]]??'';
        if(in_array($coverage[$family]??'', ['unavailable','limited'],true)){
            throw new RuntimeException('Initiative source verification is unavailable.',503);
        }
        throw new RuntimeException('Initiative sources changed.',409);
    }
    return array_intersect_key($current,$sources);
}

function vp3_initiative_owner_present_v1(PDO $pdo,array $user,int $cid): bool
{
    require_once __DIR__.'/agent-activity-v94.php';
    $activity=agent_activity_v94_snapshot($user,'chat');
    if(empty($activity['tracking_ready'])||empty($activity['interruptible'])
        ||($activity['state']??'')==='logged_out'||($activity['surface']??'')!=='chat'
        ||($activity['context_key']??'')!=='chat:'.$cid||($activity['seconds_since_activity']??PHP_INT_MAX)>90)return false;
    $stmt=$pdo->prepare('SELECT details_json FROM agent_activity_state WHERE user_id=?');
    $stmt->execute([(int)$user['id']]);
    $details=json_decode((string)$stmt->fetchColumn(),true);
    return is_array($details)&&!empty($details['visible'])&&(int)($details['conversation_id']??0)===$cid;
}

/** Keep the private preparation receipt and its eventual Chat delivery atomic. */
function vp3_initiative_delivery_result_v1(PDO $pdo,int $owner,array $row,array $result): void
{
    $json=agent_workflow_json_v1400($result);
    $pdo->prepare("UPDATE agent_workflow_actions SET result_json=?,updated_at=UTC_TIMESTAMP()
        WHERE id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,(int)$row['action_id'],(int)$row['run_id'],$owner]);
    $pdo->prepare("UPDATE agent_workflow_receipts SET result_json=?
        WHERE action_id=? AND run_id=? AND owner_user_id=? AND status='completed'")
        ->execute([$json,(int)$row['action_id'],(int)$row['run_id'],$owner]);
}

function vp3_initiative_report_v1(PDO $pdo,array $user,int $cid,string $draft,array $evidence,int $runId): int
{
    $links=array_map(static fn(array $row):array=>['source'=>$row['ref'],'title'=>$row['title'],'url'=>$row['url']],array_values($evidence));
    $context=['source'=>'agent_initiative','skip_brain_archive'=>true,'sources'=>$links,'initiative_run_id'=>$runId];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$draft,json_encode($context,JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$user['id']]);
    return $messageId;
}

/** Deliver saved results without inference when their owner returns to the linked Chat. */
function vp3_initiative_deliver_pending_v1(PDO $pdo,array $user): bool
{
    if($pdo->inTransaction()||vp3_agent_memory_scope_current_v410($user)!==0)return false;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return false;
    $live=$user;$owner=(int)$user['id'];$cid=(int)$policy['conversation_id'];
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $revoked=false;
        try{$live=vp3_initiative_access_v1($pdo,$live,$policy);}
        catch(RuntimeException $e){if($e->getCode()!==403)throw $e;$revoked=true;}
        if(!$revoked&&!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,null,true))$revoked=true;
        $pending="JSON_EXTRACT(CASE WHEN JSON_VALID(a.result_json) THEN a.result_json ELSE '{}' END,'$.initiative_delivery')";
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$pending="JSON_UNQUOTE($pending)";
        $stmt=$pdo->prepare("SELECT r.id AS run_id,a.id AS action_id,r.goal,a.result_json FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND r.agent_id IS NULL
                AND r.status='completed' AND a.status='completed' AND a.capability_key='initiative.prepare'
                AND $pending='pending' ORDER BY a.id DESC LIMIT 25");
        $stmt->execute([$owner]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $present=!$revoked&&vp3_initiative_owner_present_v1($pdo,$live,$cid)&&vp3_initiative_can_report_v1($pdo,$live,$cid);
        $delivered=false;
        foreach($rows as $row){
            $goal=json_decode((string)$row['goal'],true);
            $result=json_decode((string)$row['result_json'],true);
            if(!is_array($result))$result=[];
            $draft=$result['initiative_draft']??null;
            if($revoked||($goal['binding']??null)!==$policy||empty($goal['report'])||!is_array($goal['sources']??null)
                ||!$goal['sources']||!is_string($draft)||$draft===''||mb_strlen($draft)>490){
                $result['initiative_delivery']='withheld';
            }else{
                try{
                    $evidence=vp3_initiative_current_sources_v1($pdo,$live,$goal['sources'],!empty($policy['screen_enabled']));
                    if($present&&!$delivered){
                        $result['initiative_message_id']=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$row['run_id']);
                        $result['initiative_delivery']='delivered';
                        $delivered=true;
                        agent_workflow_event_v1400($pdo,$owner,(int)$row['run_id'],'initiative_reported','completed','completed','system',
                            'Private preparation delivered to the linked Chat.',['message_id'=>$result['initiative_message_id']]);
                    }
                }catch(RuntimeException $e){
                    if(!in_array($e->getCode(),[403,409,503],true))throw $e;
                    if($e->getCode()!==503)$result['initiative_delivery']='withheld';
                }
            }
            vp3_initiative_delivery_result_v1($pdo,$owner,$row,$result);
        }
        $pdo->commit();
        return $delivered;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_initiative_delivery_sweep_v1(PDO $pdo,int $limit): int
{
    $pending="JSON_EXTRACT(CASE WHEN JSON_VALID(a.result_json) THEN a.result_json ELSE '{}' END,'$.initiative_delivery')";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$pending="JSON_UNQUOTE($pending)";
    $stmt=$pdo->prepare("SELECT r.owner_user_id FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        JOIN users u ON u.id=r.owner_user_id AND u.is_active=1
        JOIN agent_memory_items p ON p.user_id=r.owner_user_id AND p.user_agent_id IS NULL
            AND p.memory_type='initiative_policy' AND p.subject='owner-opt-in' AND p.is_active=1
        WHERE r.source_kind='agent_initiative' AND r.agent_id IS NULL AND r.status='completed'
            AND a.status='completed' AND a.capability_key='initiative.prepare' AND $pending='pending'
            AND CAST(JSON_EXTRACT(CASE WHEN JSON_VALID(p.metadata_json) THEN p.metadata_json ELSE '{}' END,'$.enabled') AS CHAR) IN ('true','1')
        GROUP BY r.owner_user_id ORDER BY MIN(COALESCE(a.updated_at,a.completed_at)),r.owner_user_id LIMIT ".max(1,min(25,$limit)));
    $stmt->execute();$delivered=0;
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN) as $owner){
        $user=agent_cognitive_loop_v310_user((int)$owner);
        if(!$user)continue;
        try{if(vp3_initiative_deliver_pending_v1($pdo,$user))$delivered++;}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.delivery_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
    return $delivered;
}

function vp3_initiative_can_report_v1(PDO $pdo,array $user,int $cid): bool
{
    if(function_exists('agent_activity_v94_snapshot')&&empty(agent_activity_v94_snapshot($user,'chat')['interruptible']))return false;
    $stmt=$pdo->prepare("SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $latest=$stmt->fetch(PDO::FETCH_ASSOC);
    if($latest&&($latest['role']==='user'||trim((string)$latest['message'])===''))return false;
    $source="JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$source="JSON_UNQUOTE($source)";
    $stmt=$pdo->prepare("SELECT id FROM chat_messages WHERE conversation_id=? AND role='assistant'
        AND id>COALESCE((SELECT MAX(id) FROM chat_messages WHERE conversation_id=? AND role='user'),0)
        AND $source='agent_initiative' LIMIT 1");
    $stmt->execute([$cid,$cid]);
    return !$stmt->fetchColumn();
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
        $includeScreen=!empty($binding['screen_enabled']);
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources,$includeScreen);
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
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources,$includeScreen);
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
            $delivery=empty($payload['report'])?'private':'pending';$messageId=0;
            if($delivery==='pending'&&vp3_initiative_owner_present_v1($pdo,$live,$cid)&&vp3_initiative_can_report_v1($pdo,$live,$cid)){
                $messageId=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$run['id']);
                $delivery='delivered';
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Private preparation completed.',
                ['effect_state'=>'private_draft_ready','initiative_draft'=>$draft,'initiative_delivery'=>$delivery,'initiative_message_id'=>$messageId]);
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
