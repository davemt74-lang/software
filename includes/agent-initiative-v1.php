<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/agent-catch-up-v1.php';
require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-initiative-errors-v1.php';

/** Accept only private preparation assignments grounded in the supplied snapshot. */
function vp3_initiative_plan_v1(string $answer,array $evidence,array $allowedTools=['work.prepare_private']): array
{
    $limits=vp3_initiative_limits_v1();
    if(strlen($answer)>$limits['max_plan_bytes'])throw new InvalidArgumentException('Initiative plan too large.');
    $plan=json_decode($answer,false,16,JSON_THROW_ON_ERROR);
    if(!$plan instanceof stdClass||array_keys((array)$plan)!==['jobs'])throw new InvalidArgumentException('Invalid initiative plan.');
    $jobs=$plan->jobs;
    if(!is_array($jobs)||!array_is_list($jobs)||count($jobs)>$limits['max_jobs'])throw new InvalidArgumentException('Invalid initiative jobs.');
    $primaries=[];
    foreach($jobs as &$job){
        $fields=['tool','primary','refs','focus','report'];
        if(!$job instanceof stdClass)throw new InvalidArgumentException('Invalid initiative assignment.');
        $job=(array)$job;
        if(count($job)!==count($fields)||array_diff(array_keys($job),$fields)
            ||!is_string($job['tool']??null)||!in_array($job['tool'],$allowedTools,true)||!is_string($job['primary']??null)
            ||!is_array($job['refs']??null)||!array_is_list($job['refs'])||count($job['refs'])>$limits['max_refs']
            ||!is_string($job['focus']??null)||!is_bool($job['report']??null)){
            throw new InvalidArgumentException('Invalid initiative assignment.');
        }
        $primary=$job['primary'];$assignment=$job['tool'].'|'.$primary;$refs=[];
        foreach($job['refs'] as $ref){
            if(!is_string($ref)||!isset($evidence[$ref])||isset($refs[$ref]))throw new InvalidArgumentException('Unknown or duplicate initiative source.');
            $refs[$ref]=true;
        }
        $job['focus']=trim($job['focus']);
        if(!isset($refs[$primary])||isset($primaries[$assignment])||$job['focus']===''||mb_strlen($job['focus'])>300){
            throw new InvalidArgumentException('Invalid initiative assignment scope.');
        }
        $primaries[$assignment]=true;
    }
    unset($job);
    return $jobs;
}

function vp3_initiative_evidence_v1(PDO $pdo,array $user,DateTimeImmutable $now,bool $includeScreen=false,array $screenSessions=[],?array &$coverage=null,?array $selectedRefs=null,?int $agentId=null): array
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($pdo,$user,$now,$includeScreen,$screenSessions,&$coverage,$selectedRefs,$agentId):array{
        return vp3_initiative_evidence_scoped_v1($pdo,$user,$now,$includeScreen,$screenSessions,$coverage,$selectedRefs,$agentId);
    });
}

function vp3_initiative_evidence_scoped_v1(PDO $pdo,array $user,DateTimeImmutable $now,bool $includeScreen,array $screenSessions,?array &$coverage,?array $selectedRefs,int $agentId): array
{
    $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
    $current=vp3_catch_up_candidates_v1($pdo,$user,'all',$agentId,true,$now,true);
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
    if($includeScreen&&$agentId===0){
        require_once __DIR__.'/agent-initiative-screen-v1.php';
        try{$evidence+=vp3_initiative_screen_evidence_v1($pdo,$user,$now,$screenSessions);}
        catch(Throwable $e){
            $coverage['screen_followup']=$e->getCode()===403?'forbidden':'unavailable';
            if($screenSessions)throw $e;
        }
    }
    require_once __DIR__.'/agent-activity-situations-v1.php';
    $evidence=vp3_activity_situations_support_v1($pdo,$user,$evidence,$now,$coverage,$agentId>0?'agent:'.$agentId:'system');
    if($includeScreen&&$agentId>0){
        $coverage['screen_followup']='forbidden';
        if($screenSessions)throw new RuntimeException('Screen follow-up scope is unavailable.',403);
    }
    $readiness=__DIR__.'/agent-initiative-readiness-v1.php';
    if(is_file($readiness)){
        require_once $readiness;
        $evidence+=vp3_initiative_readiness_evidence_v1($pdo,$user,$principal,$now,$coverage,$selectedRefs);
    }
    $tools=__DIR__.'/agent-initiative-tools-v1.php';
    if(is_file($tools)){
        require_once $tools;
        try{
            $binding=vp3_initiative_policy_v1($user,$agentId);
            if(!empty($binding['enabled'])&&(int)($binding['conversation_id']??0)>0){
                $evidence+=vp3_initiative_tools_evidence_v1($pdo,$user,$binding,$selectedRefs,$coverage);
            }
        }catch(Throwable $e){
            foreach(['workbench_product','workbench_screen','workbench_mission'] as $family){
                $coverage[$family]=in_array($e->getCode(),[403,404],true)?'forbidden':'unavailable';
            }
        }
    }
    ksort($evidence);
    return $evidence;
}

function vp3_initiative_policy_v1(array $user,?int $agentId=null): array
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static function()use($user):array{
        $memory=agent_brain_v122_memory($user,'initiative_policy','owner-opt-in');
        return array_intersect_key($memory['metadata']??[],array_flip(['enabled','conversation_id','revision','screen_enabled','owner_user_id','agent_id','knowledge_scope']));
    });
}

function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array
{
    $uid=(int)$user['id'];$cid=(int)($binding['conversation_id']??0);$agentId=vp3_initiative_binding_agent_v1($binding);
    if(!is_int($binding['conversation_id']??null)||$cid<1||!is_string($binding['revision']??null)
        ||!preg_match('/\A[a-f0-9]{32}\z/D',$binding['revision']))throw new RuntimeException('Initiative binding is invalid.',403);
    if(($agentId>0&&($binding['owner_user_id']??null)!==$uid)
        ||(isset($binding['owner_user_id'])&&$binding['owner_user_id']!==$uid))throw new RuntimeException('Initiative owner changed.',403);
    $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$cid],null);
    $policy=vp3_initiative_policy_v1($live,$agentId);
    if($cid<1||!has_permission('account.access',$live)||!personal_capability_has_v242('agent_brain.access',$live)||($policy['enabled']??false)!==true
        ||$policy!==$binding||vp3_initiative_binding_agent_v1($policy)!==$agentId){
        throw new RuntimeException('Initiative scope changed.',403);
    }
    return $live;
}

function vp3_initiative_model_v1(PDO $pdo,array $user,string $prompt,string $protocol,?int $agentId=null): string
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    require_once __DIR__.'/agent-initiative-model-v1.php';
    return vp3_initiative_infer_v1($pdo,$user,$agentId,$prompt,$protocol);
}

function vp3_initiative_enqueue_v1(PDO $pdo,array $user,string $capability,array $payload,string $hash): bool
{
    if(!is_array($payload['binding']??null))throw new RuntimeException('Initiative binding is invalid.',403);
    vp3_initiative_access_v1($pdo,$user,$payload['binding']);
    $uid=(int)$user['id'];
    $dedupe=hash('sha256',$capability.'|'.$hash);
    $existing=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=?');
    $existing->execute([$uid,$dedupe]);
    if($existing->fetchColumn())return false;
    $label=$capability==='initiative.plan'?'Assess useful next steps':'Prepare a private next step';
    $pdo->prepare("INSERT INTO agent_workflow_runs
        (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,risk_level,requires_approval,approval_status,execution_target,capability_key,max_attempts,retry_backoff_seconds,next_attempt_at)
        VALUES (?,?,'initiative','brain','agent_initiative',?,?,?,?,?,'approved','low',0,'not_required','cloud',?,3,60,UTC_TIMESTAMP())")
        ->execute([$uid,($agent=vp3_initiative_binding_agent_v1($payload['binding']))>0?$agent:null,$hash,substr($hash,0,40),$dedupe,$label,json_encode($payload,JSON_THROW_ON_ERROR),$capability]);
    $run=(int)$pdo->lastInsertId();
    if(agent_job_control_ready_v1900($pdo)){
        $pdo->prepare('UPDATE agent_workflow_runs SET work_priority=10 WHERE id=? AND owner_user_id=?')->execute([$run,$uid]);
    }
    $pdo->prepare("INSERT INTO agent_workflow_actions
        (run_id,owner_user_id,sequence_no,action_key,action_type,label,status,requires_approval,execution_target,capability_key,max_attempts)
        VALUES (?,?,1,?,'execute',?,'queued',0,'cloud',?,3)")
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

function vp3_initiative_schedule_v1(PDO $pdo,array $user,?int $agentId=null): void
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user));
}

function vp3_initiative_schedule_scoped_v1(PDO $pdo,array $user): void
{
    if(!agent_job_engine_schema_ready_v1900($pdo))return;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return;
    $live=vp3_initiative_access_v1($pdo,$user,$policy);
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$policy);
        $last=agent_brain_v122_memory($live,'initiative_sweep','last-review');
        $previous=$last['metadata']??[];
        if(!$last||($previous['conversation_id']??null)!==($policy['conversation_id']??null)
            ||($previous['revision']??null)!==($policy['revision']??null)||(strtotime((string)$last['last_seen_at'])?:0)<time()-300){
            $availability=vp3_initiative_availability_v1(vp3_initiative_owner_context_v1($pdo,$live,$policy));
            $attentionRevision=$previous['attention_revision']??null;
            if(($previous['conversation_id']??null)!==($policy['conversation_id']??null)
                ||($previous['revision']??null)!==($policy['revision']??null)||($previous['owner_availability']??null)!==$availability
                ||!is_string($attentionRevision)||!preg_match('/\A[a-f0-9]{32}\z/D',$attentionRevision)){
                $attentionRevision=bin2hex(random_bytes(16));
            }
            $evidence=vp3_initiative_evidence_v1($pdo,$live,new DateTimeImmutable('now',new DateTimeZone('UTC')),!empty($policy['screen_enabled']));
            if($evidence){
                $canonical=array_map(static fn(array $row):string=>$row['canonical_source_revision']??$row['revision'],$evidence);
                $payload=['binding'=>$policy,'sources'=>array_column($evidence,'revision','ref'),'canonical_sources'=>$canonical,
                    'owner_availability'=>$availability,'attention_revision'=>$attentionRevision];
                $hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR));
                vp3_initiative_enqueue_v1($pdo,$live,'initiative.plan',$payload,$hash);
            }
            $review=[
                'conversation_id'=>$policy['conversation_id'],
                'revision'=>$policy['revision'],
                'owner_availability'=>$availability,'attention_revision'=>$attentionRevision,
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
    $stmt=$pdo->prepare("SELECT p.user_id,COALESCE(p.user_agent_id,0) AS agent_id FROM agent_memory_items p
        JOIN users u ON u.id=p.user_id AND u.is_active=1
        LEFT JOIN agent_memory_items s ON s.user_id=p.user_id AND COALESCE(s.user_agent_id,0)=COALESCE(p.user_agent_id,0)
            AND s.memory_type='initiative_sweep' AND s.subject='last-review' AND s.is_active=1
        WHERE p.memory_type='initiative_policy' AND p.subject='owner-opt-in'
            AND p.is_active=1 AND JSON_VALID(p.metadata_json)
            AND JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'
            AND (s.id IS NULL OR s.last_seen_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))
        ORDER BY COALESCE(s.last_seen_at,p.last_seen_at),p.user_id LIMIT ".$limit);
    $stmt->execute();
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $owner=(int)$row['user_id'];$agentId=(int)$row['agent_id'];
        $user=agent_cognitive_loop_v310_user($owner);
        if(!$user)continue;
        try{vp3_initiative_schedule_v1($pdo,$user,$agentId);}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.schedule_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
}

/** Both Agent Eyes and Chat commands use the same owner policy and revision. */
function vp3_initiative_screen_setting_v1(PDO $pdo,array $user,int $cid,?bool $enabled=null,?string $expectedRevision=null,?callable $authority=null): array
{
    $principal=vp3_capability_control_principal_v1($pdo,$user,$cid);
    $agentId=(int)($principal['agent_id']??0);
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_screen_setting_scoped_v1($pdo,$user,$cid,$enabled,$expectedRevision,$authority));
}

function vp3_initiative_screen_setting_scoped_v1(PDO $pdo,array $user,int $cid,?bool $enabled=null,?string $expectedRevision=null,?callable $authority=null): array
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
    $kind=$principal['kind']??'';$agentId=$kind==='user_agent'?(int)($principal['agent_id']??0):0;
    if(!in_array($kind,['system','user_agent'],true)||($kind==='user_agent'&&($agentId<1
        ||(int)($principal['owner_user_id']??0)!==(int)($user['id']??0)))){
        return ['handled'=>true,'ok'=>false,'answer'=>'Use your private owner Agent Chat to control initiative.','actions'=>[],'sources'=>[]];
    }
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_chat_scoped_v1($pdo,$user,$principal,$context,$command,$authority));
}

function vp3_initiative_chat_scoped_v1(PDO $pdo,array $user,array $principal,array $context,string $command,?callable $authority): array
{
    $result=['handled'=>true,'actions'=>[],'sources'=>[]];
    $cid=(int)($context['conversation_id']??0);
    $disabling=$command==='off';
    if($cid<1||vp3_participant_scope_v1()!==null){
        return $result+['ok'=>false,'answer'=>'Use your private owner Agent Chat to control initiative.'];
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
        if(!has_permission('account.access',$live)
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
        if(array_key_exists('initiative_expected_revision',$context)){
            $expected=$context['initiative_expected_revision'];
            if(!is_string($expected)||($expected!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$expected))
                ||!hash_equals((string)($policy['revision']??''),$expected)){
                throw new RuntimeException('initiative_settings_changed',409);
            }
        }
        $enable=$command==='on';
        $knowledge=array_key_exists('knowledge_scope',$context)?vp3_initiative_knowledge_scope_v1($context['knowledge_scope']):($policy['knowledge_scope']??['mode'=>'off','folder_id'=>0]);
        if($enable!==!empty($policy['enabled'])||($enable&&(($policy['conversation_id']??null)!==$cid
            ||(array_key_exists('knowledge_scope',$context)&&($policy['knowledge_scope']??null)!==$knowledge)))){
            $policy=['enabled'=>$enable,'conversation_id'=>$cid,'revision'=>bin2hex(random_bytes(16))];
            if(($agentId=vp3_agent_memory_scope_current_v410($live))>0)$policy+=['owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId];
            $policy['knowledge_scope']=$knowledge;
            if(agent_brain_v122_upsert_system_memory($live,'initiative_policy','owner-opt-in','Owner initiative preference.',$policy)<1){
                throw new RuntimeException('Initiative setting could not be saved.');
            }
        }
        $pdo->commit();
        return $result+['ok'=>true,'initiative_policy'=>$policy,'answer'=>$enable?'Initiative is on for this Agent conversation. I can assess its authorized work, prepare useful next steps with your configured AI, and bring verified results here. Changes that need your review still wait for approval. Use /initiative off to stop.':'Initiative is off.'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        return $result+['ok'=>false,'reason'=>$e->getMessage()==='initiative_settings_changed'?'settings_changed':'initiative_unconfirmed',
            'answer'=>$e->getMessage()==='initiative_settings_changed'?'Initiative settings changed in another tab. Check the current setting before trying again.':'I couldn’t verify or update initiative for this conversation.'];
    }
}

/** Project only a verified Initiative state for the exact private owner Agent Chat. */
function vp3_initiative_foreground_status_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    if($agentId<0||(int)($user['id']??0)<1)return ['state'=>'unavailable'];
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_foreground_status_scoped_v1($pdo,$user,$agentId,$cid));
}

function vp3_initiative_foreground_status_scoped_v1(PDO $pdo,array $user,int $agentId,int $cid): array
{
    $unavailable=['state'=>'unavailable'];
    if($cid<0||(int)($user['id']??0)<1
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return $unavailable;
    }
    try{
        $principal=vp3_initiative_principal_v1($pdo,$user,$agentId);
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
        [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agentId>0?['id'=>$agentId]:null,'r');
        $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
        $stmt=$pdo->prepare("SELECT r.goal,r.status,r.lease_expires_at,a.result_json,$effect AS effect_state,$delivery AS initiative_delivery FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
                AND CAST($conversation AS CHAR)=? AND $runRevision=?
            ORDER BY r.id DESC LIMIT 15");
        $stmt->execute(array_merge([(int)$live['id']],$agentParams,[(string)$cid,$revision]));
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
                vp3_initiative_current_sources_v1($pdo,$live,$goal['sources'],!empty($policy['screen_enabled']),$goal['canonical_sources']??[]);
                $result=json_decode((string)$row['result_json'],true);
                vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
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
    return vp3_initiative_in_scope_v1($user,vp3_initiative_binding_agent_v1($policy),
        static fn()=>vp3_initiative_status_scoped_v1($pdo,$user,$policy,$result));
}

function vp3_initiative_status_scoped_v1(PDO $pdo,array $user,array $policy,array $result): array
{
    $user=vp3_initiative_access_v1($pdo,$user,$policy);
    $agentId=vp3_initiative_binding_agent_v1($policy);
    [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agentId>0?['id'=>$agentId]:null,'r');
    $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
    $stmt=$pdo->prepare("SELECT r.goal,r.status,a.result_json FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
        ORDER BY r.id DESC LIMIT 15");
    $stmt->execute(array_merge([(int)$user['id']],$agentParams));
    $includeScreen=!empty($policy['screen_enabled']);
    $lines=['Initiative is on. Screen-share follow-ups are '.($includeScreen?'on.':'off.')];$sources=[];
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
        if(!is_array($goal['sources']??null)||!$goal['sources'])continue;
        try{
            $matching=vp3_initiative_current_sources_v1($pdo,$user,$goal['sources'],$includeScreen,$goal['canonical_sources']??[]);
            vp3_initiative_result_current_v1($pdo,$user,$policy,$goal,$out['initiative_tool_result']??[]);
        }
        catch(RuntimeException $e){
            if(!in_array($e->getCode(),[403,409,503],true))throw $e;
            if($e->getCode()===503)$lines[]='A previous preparation could not be verified right now.';
            continue;
        }
        $lines[]=(string)$out['initiative_draft'];
        foreach($matching as $ref=>$item)$sources[$ref]=['source'=>$ref,'title'=>$item['title'],'url'=>$item['url']];
        if(count($lines)>=4)break;
    }
    $result['sources']=array_values($sources);
    return $result+['answer'=>implode("\n\n",array_values(array_unique($lines)))];
}

function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources,bool $includeScreen=false,array $canonicalSources=[],?int $agentId=null): array
{
    $sessions=[];
    foreach(array_keys($sources) as $ref)if(str_starts_with($ref,'screen-followup:'))$sessions[]=substr($ref,16);
    $coverage=[];
    $current=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),$includeScreen&&$sessions!==[],$sessions,$coverage,array_keys($sources),$agentId);
    $families=['calendar'=>'calendar','calendar-conflict'=>'calendar','reminder'=>'reminders','task'=>'tasks','mission'=>'missions',
        'profile-call'=>'profile_calls','meeting-prep'=>'meeting_prep','order'=>'orders','campaign'=>'campaigns',
        'campaign-journey'=>'campaigns',
        'reward'=>'rewards','transcript'=>'transcripts','screen-followup'=>'screen_followup',
        'account-readiness'=>'account_readiness','business-readiness'=>'business_readiness',
        'product-readiness'=>'product_readiness','scheduling-readiness'=>'scheduling_readiness',
        'workbench-product'=>'workbench_product','workbench-screen'=>'workbench_screen','workbench-mission'=>'workbench_mission'];
    foreach($sources as $ref=>$revision){
        if(($current[$ref]['revision']??null)===$revision)continue;
        $family=$families[explode(':',$ref,2)[0]]??'';
        if(in_array($coverage[$family]??'', ['unavailable','limited'],true)){
            throw new RuntimeException('Initiative source verification is unavailable.',503);
        }
        $base=$current[$ref]['canonical_source_revision']??$current[$ref]['revision']??null;
        if(isset($canonicalSources[$ref])&&$canonicalSources[$ref]===$base&&($coverage['activity_context']??'')==='unavailable'){
            throw new RuntimeException('Initiative supporting context verification is unavailable.',503);
        }
        throw new RuntimeException('Initiative sources changed.',409);
    }
    return array_intersect_key($current,$sources);
}

/** A completed tool's retrieved records/artifact must still be current at delivery. */
function vp3_initiative_result_current_v1(PDO $pdo,array $user,array $binding,array $goal,array $toolResult): void
{
    if(!isset($toolResult['output'])&&!isset($toolResult['tool_receipt']))return;
    require_once __DIR__.'/agent-initiative-tools-v1.php';
    if(!function_exists('vp3_initiative_tools_result_current_v1'))throw new RuntimeException('Initiative result verification is unavailable.',503);
    vp3_initiative_tools_result_current_v1($pdo,$user,$binding,$goal,$toolResult);
}

function vp3_initiative_owner_present_v1(PDO $pdo,array $user,int $cid): bool
{
    require_once __DIR__.'/agent-activity-v94.php';
    $activity=agent_activity_v94_snapshot($user,'chat');
    if(empty($activity['tracking_ready'])
        ||($activity['state']??'')==='logged_out'||($activity['surface']??'')!=='chat'
        ||($activity['context_key']??'')!=='chat:'.$cid||($activity['seconds_since_activity']??PHP_INT_MAX)>90)return false;
    $stmt=$pdo->prepare('SELECT details_json FROM agent_activity_state WHERE user_id=?');
    $stmt->execute([(int)$user['id']]);
    $details=json_decode((string)$stmt->fetchColumn(),true);
    if(!is_array($details)||empty($details['visible'])||(int)($details['conversation_id']??0)!==$cid)return false;
    if(array_key_exists('chat_focused',$details)||array_key_exists('chat_busy',$details)){
        // Silent text can be useful while the owner is reading. Actual pending
        // Chat/Voice work and focus remain separate from general activity.
        return ($details['chat_focused']??null)===true&&($details['chat_busy']??null)===false;
    }
    return !empty($activity['interruptible']);
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

function vp3_initiative_report_v1(PDO $pdo,array $user,int $cid,string $draft,array $evidence,int $runId,array $toolResult=[]): int
{
    $links=array_map(static fn(array $row):array=>['source'=>$row['ref'],'title'=>$row['title'],'url'=>$row['url']],array_values($evidence));
    foreach(is_array($toolResult['sources']??null)?array_slice($toolResult['sources'],0,16):[] as $source){
        if(!is_array($source)||!is_string($source['source']??null)||!is_string($source['title']??null))continue;
        $link=['source'=>mb_substr($source['source'],0,190),'title'=>mb_substr($source['title'],0,190)];
        if(is_string($source['url']??null)&&str_starts_with($source['url'],'/')&&!str_starts_with($source['url'],'//')
            &&!str_contains($source['url'],'\\'))$link['url']=mb_substr($source['url'],0,1900);
        $links[]=$link;
    }
    $context=['source'=>'agent_initiative','skip_brain_archive'=>true,'sources'=>$links,'initiative_run_id'=>$runId];
    $agentId=vp3_agent_memory_scope_current_v410($user);
    if(!vp3_agent_chat_conversation_v380($pdo,$cid,(int)$user['id'],$agentId>0?['id'=>$agentId]:null,true)){
        throw new RuntimeException('Initiative conversation changed.',403);
    }
    $actions=$toolResult['output']['review_actions']??(isset($toolResult['output']['review_action'])?[$toolResult['output']['review_action']]:[]);
    $reviews=[];
    foreach(is_array($actions)?array_slice($actions,0,8):[] as $action){
        if(!is_array($action)||!in_array($action['tool']??'', ['product','screen','mission'],true)
            ||!is_string($action['source_id']??null)||$action['source_id']===''||strlen($action['source_id'])>190
            ||!is_string($action['source_revision']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$action['source_revision']))continue;
        $reviews[]=['tool'=>$action['tool'],'source_id'=>$action['source_id'],'source_revision'=>$action['source_revision']];
    }
    $context['initiative_work']=['owner_user_id'=>(int)$user['id'],'agent_id'=>$agentId,
        'conversation_id'=>$cid,'run_id'=>$runId,'review_actions'=>$reviews];
    if(is_array($toolResult['tool_receipt']??null))$context['tool_receipt']=$toolResult['tool_receipt'];
    if(is_array($toolResult['output']??null))$context['initiative_output']=$toolResult['output'];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES (?,NULL,'assistant',?,?,NOW())")->execute([$cid,$draft,json_encode($context,JSON_THROW_ON_ERROR)]);
    $messageId=(int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$cid,(int)$user['id']]);
    return $messageId;
}

/** Deliver saved results without inference when their owner returns to the linked Chat. */
function vp3_initiative_deliver_pending_v1(PDO $pdo,array $user,?int $agentId=null): bool
{
    $agentId??=vp3_agent_memory_scope_current_v410($user);
    return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_deliver_pending_scoped_v1($pdo,$user));
}

function vp3_initiative_deliver_pending_scoped_v1(PDO $pdo,array $user): bool
{
    if($pdo->inTransaction())return false;
    $agentId=vp3_agent_memory_scope_current_v410($user);
    $agent=$agentId>0?['id'=>$agentId]:null;
    $policy=vp3_initiative_policy_v1($user);
    if(empty($policy['enabled']))return false;
    $live=$user;$owner=(int)$user['id'];$cid=(int)$policy['conversation_id'];
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$live);
        $revoked=false;
        try{$live=vp3_initiative_access_v1($pdo,$live,$policy);}
        catch(RuntimeException $e){if($e->getCode()!==403)throw $e;$revoked=true;}
        if(!$revoked&&!vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent,true))$revoked=true;
        $pending="JSON_EXTRACT(CASE WHEN JSON_VALID(a.result_json) THEN a.result_json ELSE '{}' END,'$.initiative_delivery')";
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$pending="JSON_UNQUOTE($pending)";
        [$agentSql,$agentParams]=vp3_agent_chat_scope_sql_v380($agent,'r');
        $agentSql=str_replace('r.user_agent_id','r.agent_id',$agentSql);
        $stmt=$pdo->prepare("SELECT r.id AS run_id,a.id AS action_id,r.goal,a.result_json FROM agent_workflow_runs r
            JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
            WHERE r.owner_user_id=? AND r.source_kind='agent_initiative' AND $agentSql
                AND r.status='completed' AND a.status='completed' AND a.capability_key='initiative.prepare'
                AND $pending='pending' ORDER BY a.id DESC LIMIT 25");
        $stmt->execute(array_merge([$owner],$agentParams));
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $present=!$revoked&&vp3_initiative_owner_present_v1($pdo,$live,$cid)&&vp3_initiative_can_report_v1($pdo,$live,$cid);
        $delivered=false;
        foreach($rows as $row){
            $goal=json_decode((string)$row['goal'],true);
            $result=json_decode((string)$row['result_json'],true);
            if(!is_array($result))$result=[];
            $draft=$result['initiative_draft']??null;
            if($revoked||($goal['binding']??null)!==$policy||empty($goal['report'])||!is_array($goal['sources']??null)
                ||!$goal['sources']||!is_string($draft)||$draft===''||mb_strlen($draft)>vp3_initiative_limits_v1()['max_draft_chars']){
                $result['initiative_delivery']='withheld';
            }else{
                try{
                    $evidence=vp3_initiative_current_sources_v1($pdo,$live,$goal['sources'],!empty($policy['screen_enabled']),$goal['canonical_sources']??[]);
                    vp3_initiative_result_current_v1($pdo,$live,$policy,$goal,$result['initiative_tool_result']??[]);
                    if($present&&!$delivered){
                        $result['initiative_message_id']=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$row['run_id'],$result['initiative_tool_result']??[]);
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
    $stmt=$pdo->prepare("SELECT r.owner_user_id,COALESCE(r.agent_id,0) AS agent_id FROM agent_workflow_runs r
        JOIN agent_workflow_actions a ON a.run_id=r.id AND a.owner_user_id=r.owner_user_id
        JOIN users u ON u.id=r.owner_user_id AND u.is_active=1
        JOIN agent_memory_items p ON p.user_id=r.owner_user_id AND COALESCE(p.user_agent_id,0)=COALESCE(r.agent_id,0)
            AND p.memory_type='initiative_policy' AND p.subject='owner-opt-in' AND p.is_active=1
        WHERE r.source_kind='agent_initiative' AND r.status='completed'
            AND a.status='completed' AND a.capability_key='initiative.prepare' AND $pending='pending'
            AND CAST(JSON_EXTRACT(CASE WHEN JSON_VALID(p.metadata_json) THEN p.metadata_json ELSE '{}' END,'$.enabled') AS CHAR) IN ('true','1')
        GROUP BY r.owner_user_id,COALESCE(r.agent_id,0) ORDER BY MIN(COALESCE(a.updated_at,a.completed_at)),r.owner_user_id LIMIT ".max(1,min(25,$limit)));
    $stmt->execute();$delivered=0;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
        $owner=(int)$row['owner_user_id'];$agentId=(int)$row['agent_id'];
        $user=agent_cognitive_loop_v310_user($owner);
        if(!$user)continue;
        try{if(vp3_initiative_deliver_pending_v1($pdo,$user,$agentId))$delivered++;}
        catch(Throwable $e){agent_runtime_v125_trace('initiative.delivery_failed',['user_id'=>(int)$owner,'error_class'=>get_class($e)]);}
    }
    return $delivered;
}

function vp3_initiative_can_report_v1(PDO $pdo,array $user,int $cid): bool
{
    if(function_exists('agent_activity_v94_snapshot')&&!vp3_initiative_owner_present_v1($pdo,$user,$cid))return false;
    $stmt=$pdo->prepare("SELECT role,message FROM chat_messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid]);
    $latest=$stmt->fetch(PDO::FETCH_ASSOC);
    if($latest&&($latest['role']==='user'||trim((string)$latest['message'])===''))return false;
    $source="JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')";
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$source="JSON_UNQUOTE($source)";
    $cooldown=filter_var(getenv('VP3_INITIATIVE_REPORT_COOLDOWN_SECONDS')?:120,FILTER_VALIDATE_INT);
    $cooldown=max(15,min(3600,$cooldown===false?120:$cooldown));
    $cutoff=gmdate('Y-m-d H:i:s',time()-$cooldown);
    $stmt=$pdo->prepare("SELECT id FROM chat_messages WHERE conversation_id=? AND role='assistant'
        AND id>COALESCE((SELECT MAX(id) FROM chat_messages WHERE conversation_id=? AND role='user'),0)
        AND created_at>? AND $source='agent_initiative' LIMIT 1");
    $stmt->execute([$cid,$cid,$cutoff]);
    return !$stmt->fetchColumn();
}

/** Use the existing leased worker and commit the result with its workflow receipt. */
function vp3_initiative_execute_v1(PDO $pdo,array $user,array $claim,?callable $model=null): array
{
    $run=agent_worker_cloud_active_row_v1910($pdo,$user,$claim);
    if(!$run||$run['source_kind']!=='agent_initiative'||!in_array($run['action_capability_key'],['initiative.plan','initiative.prepare'],true)){
        return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
    }
    try{
        $payload=json_decode((string)$run['goal'],true,16,JSON_THROW_ON_ERROR);
        if(!is_array($payload)||!is_array($payload['binding']??null)||!is_array($payload['sources']??null))throw new RuntimeException('Initiative binding changed.',403);
        $agentId=vp3_initiative_binding_agent_v1($payload['binding']);
        if(($run['agent_id']===null?0:(int)$run['agent_id'])!==$agentId)throw new RuntimeException('Initiative Agent changed.',403);
        return vp3_initiative_in_scope_v1($user,$agentId,static fn()=>vp3_initiative_execute_scoped_v1($pdo,$user,$claim,$model,$run,$payload));
    }catch(Throwable $e){
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $reason=vp3_initiative_failure_reason_v1($e);
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Initiative work could not be verified.',[],$reason,false);
        return ['ok'=>false,'reason'=>$reason,'receipt'=>$receipt];
    }
}

function vp3_initiative_execute_scoped_v1(PDO $pdo,array $user,array $claim,?callable $model,array $run,array $payload): array
{
    $toolResult=[];
    try{
        $binding=$payload['binding'];$sources=$payload['sources'];
        $agentId=vp3_initiative_binding_agent_v1($binding);
        $canonicalSources=is_array($payload['canonical_sources']??null)?$payload['canonical_sources']:[];
        $live=vp3_initiative_access_v1($pdo,$user,$binding);
        $includeScreen=!empty($binding['screen_enabled']);
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources,$includeScreen,$canonicalSources);
        $modelEvidence=array_map(static fn(array $row):array=>array_diff_key($row,['url'=>true,'revision'=>true,'canonical_source_revision'=>true]),array_values($evidence));
        $planning=$run['action_capability_key']==='initiative.plan';
        $provider=$model??static fn(string $prompt,string $protocol):string=>vp3_initiative_model_v1($pdo,$live,$prompt,$protocol,$agentId);
        $modelCalls=0;
        $infer=static function(string $prompt,string $protocol)use($pdo,&$live,$binding,$claim,$provider,&$modelCalls):string{
            if($pdo->inTransaction())throw new RuntimeException('Initiative inference cannot hold a database transaction.');
            $live=vp3_initiative_access_v1($pdo,$live,$binding);
            if(!agent_worker_cloud_active_row_v1910($pdo,$live,$claim)
                ||!agent_job_heartbeat_v1900($pdo,$live,(int)$claim['run_id'],(int)$claim['action']['id'],
                    (string)$claim['lease_token'],min(90,++$modelCalls*10),'Preparing a verified private next step.',240)){
                throw new RuntimeException('Initiative lease changed.',409);
            }
            return $provider($prompt,$protocol);
        };
        $limits=vp3_initiative_limits_v1();
        $tools=['work.prepare_private'=>['id'=>'work.prepare_private','ready'=>true,'mode'=>'prepare',
            'description'=>'Prepare a private source-backed next step without changing the source.']];
        $toolFile=__DIR__.'/agent-initiative-tools-v1.php';
        if(is_file($toolFile)){
            require_once $toolFile;
            $tools+=vp3_initiative_tools_catalog_v1($pdo,$live,$binding,$evidence);
        }
        if($planning){
            $answer=$infer(json_encode(['evidence'=>$modelEvidence,'tools'=>$tools,'budget'=>$limits,
                'owner_context'=>vp3_initiative_owner_context_v1($pdo,$live,$binding)],JSON_THROW_ON_ERROR),'vp3.initiative-plan.v1');
            $allowed=['work.prepare_private'];
            foreach($tools as $id=>$entry)if(!empty($entry['ready']))$allowed[]=$id;
            $jobs=vp3_initiative_plan_v1($answer,$evidence,array_values(array_unique($allowed)));
        }else{
            $tool=$payload['tool']??'work.prepare_private';
            if(!is_string($tool)||!array_key_exists($tool,$tools)||($tool!=='work.prepare_private'&&empty($tools[$tool]['ready']))){
                throw new RuntimeException('Initiative tool is unavailable.',403);
            }
            if($tool==='work.prepare_private'){
                $request=['focus'=>$payload['focus'],'evidence'=>$modelEvidence,'budget'=>$limits];
                $answer=$infer(json_encode($request,JSON_THROW_ON_ERROR),'vp3.initiative-draft.v1');
                $toolResult=vp3_tool_json_object_v1($answer,['draft'],$limits['max_draft_bytes']);
            }else{
                $job=['tool'=>$tool,'primary'=>$payload['primary']??array_key_first($sources),'refs'=>array_keys($sources),
                    'focus'=>$payload['focus'],'report'=>$payload['report']];
                $toolResult=vp3_initiative_tool_execute_v1($pdo,$live,$binding,$job,$evidence,(string)$run['dedupe_key'],$infer);
            }
            $draft=is_string($toolResult['draft']??null)?trim($toolResult['draft']):'';
            if($draft===''||mb_strlen($draft)>$limits['max_draft_chars']||preg_match('/https?:\/\/|\bwww\.|[<>]|[\x00-\x08\x0b-\x1f\x7f]/i',$draft)){
                throw new InvalidArgumentException('Invalid private draft.');
            }
        }

        $pdo->beginTransaction();
        vp3_initiative_owner_lock_v1($pdo,$live);
        $live=vp3_initiative_access_v1($pdo,$live,$binding);
        $evidence=vp3_initiative_current_sources_v1($pdo,$live,$sources,$includeScreen,$canonicalSources);
        if($planning){
            $admitted=0;
            foreach($jobs as $job){
                $selected=array_intersect_key($sources,array_flip($job['refs']));
                $work=['binding'=>$binding,'sources'=>$selected,'canonical_sources'=>array_intersect_key($canonicalSources,$selected),
                    'tool'=>$job['tool'],'primary'=>$job['primary'],'focus'=>$job['focus'],'report'=>$job['report']];
                $hash=hash('sha256',json_encode([$binding,$job['tool'],$job['primary'],$selected],JSON_THROW_ON_ERROR));
                if(vp3_initiative_enqueue_v1($pdo,$live,'initiative.prepare',$work,$hash))$admitted++;
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Initiative assessment recorded.',
                ['effect_state'=>$admitted?'assignments_queued':($jobs?'existing_assignments':'idle'),'jobs'=>$admitted]);
        }else{
            $cid=(int)$binding['conversation_id'];$uid=(int)$live['id'];
            vp3_initiative_result_current_v1($pdo,$live,$binding,$payload,$toolResult);
            if(!vp3_agent_chat_conversation_v380($pdo,$cid,$uid,$agentId>0?['id'=>$agentId]:null,true))throw new RuntimeException('Initiative conversation changed.',403);
            $delivery=empty($payload['report'])?'private':'pending';$messageId=0;
            if($delivery==='pending'&&vp3_initiative_owner_present_v1($pdo,$live,$cid)&&vp3_initiative_can_report_v1($pdo,$live,$cid)){
                $messageId=vp3_initiative_report_v1($pdo,$live,$cid,$draft,$evidence,(int)$run['id'],$toolResult);
                $delivery='delivered';
            }
            $receipt=agent_worker_cloud_result_v1910($pdo,$live,$claim,true,'Private preparation completed.',
                ['effect_state'=>'private_draft_ready','initiative_draft'=>$draft,'initiative_tool_result'=>$toolResult,
                    'initiative_delivery'=>$delivery,'initiative_message_id'=>$messageId]);
        }
        $pdo->commit();
        return ['ok'=>true,'reason'=>'completed','receipt'=>$receipt];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!agent_worker_cloud_active_row_v1910($pdo,$user,$claim))return ['ok'=>false,'reason'=>'execution_no_longer_active','receipt'=>null];
        $retained=array_diff_key($toolResult,['draft'=>true]);
        $withheld=$retained?['effect_state'=>'tool_result_withheld','initiative_tool_result'=>$retained,'initiative_delivery'=>'withheld']:[];
        $retryable=$e instanceof Vp3InitiativeTransientInferenceV1&&$toolResult===[];
        if($retryable){
            // A transport outage cannot turn a concurrently revoked policy or
            // changed source into permission to queue another inference attempt.
            try{
                $retryUser=vp3_initiative_access_v1($pdo,$user,$binding);
                vp3_initiative_current_sources_v1($pdo,$retryUser,$sources,$includeScreen,$canonicalSources);
            }catch(Throwable $scopeError){$retryable=false;$e=$scopeError;}
        }
        $reason=vp3_initiative_failure_reason_v1($e);
        $receipt=agent_worker_cloud_result_v1910($pdo,$user,$claim,false,'Initiative work could not be verified.',$withheld,$reason,$retryable);
        return ['ok'=>false,'reason'=>$reason,'receipt'=>$receipt];
    }
}
