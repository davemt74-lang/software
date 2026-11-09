<?php
declare(strict_types=1);

/** Memory scope is a context selector; canonical principal checks grant access. */
function vp3_initiative_in_scope_v1(array $user,int $agentId,callable $work): mixed
{
    if((int)($user['id']??0)<1||$agentId<0)throw new RuntimeException('Initiative scope is unavailable.',403);
    $previous=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
    try{
        vp3_agent_memory_scope_set_current_v410((int)$user['id'],$agentId);
        return $work();
    }finally{
        if($previous===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);
        else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$previous;
    }
}

/** A legacy policy without an Agent remains System-only. */
function vp3_initiative_binding_agent_v1(array $binding): int
{
    if(!array_key_exists('agent_id',$binding))return 0;
    if(!is_int($binding['agent_id'])||$binding['agent_id']<0)throw new RuntimeException('Initiative binding is invalid.',403);
    return $binding['agent_id'];
}

function vp3_initiative_principal_v1(PDO $pdo,array $user,int $agentId): array
{
    try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
    catch(PDOException $e){throw $e;}
    catch(RuntimeException $e){throw new RuntimeException('Initiative Agent access changed.',403,$e);}
    return vp3_agent_chat_principal_v380($user,$agent);
}

/** Runtime bounds constrain cost and output, rather than limiting feature kinds. */
function vp3_initiative_limits_v1(): array
{
    $jobs=filter_var(getenv('VP3_INITIATIVE_MAX_JOBS')?:3,FILTER_VALIDATE_INT);
    return ['max_jobs'=>max(1,min(8,$jobs===false?3:$jobs)),
        'max_refs'=>5,'max_draft_chars'=>8000,'max_plan_bytes'=>24000,'max_draft_bytes'=>34000];
}

function vp3_initiative_knowledge_scope_v1(mixed $scope): array
{
    if(!is_array($scope)||!in_array($scope['mode']??'', ['off','all','folder'],true))return ['mode'=>'off','folder_id'=>0];
    if($scope['mode']==='folder'){
        $id=$scope['folder_id']??0;
        return is_int($id)&&$id>0?['mode'=>'folder','folder_id'=>$id]:['mode'=>'off','folder_id'=>0];
    }
    return ['mode'=>$scope['mode'],'folder_id'=>0];
}

/** Only safe reason codes from the governed assessment and inference paths may escape. */
function vp3_initiative_failure_reason_v1(Throwable $error,bool $assessment=false): string
{
    if($assessment&&$error instanceof Vp3InitiativeSourcesChangedV1)return 'assessment_sources_changed';
    if($assessment&&$error instanceof Vp3InitiativeInvalidAssessmentV1)return 'assessment_unverified';
    return in_array($error->getMessage(),['model_unavailable','quota_exhausted','route_unavailable'],true)
        ?$error->getMessage():'initiative_unconfirmed';
}

/** Bounded owner availability from existing saved activity; never a sensing request. */
function vp3_initiative_owner_context_v1(PDO $pdo,array $user,array $binding,bool $includeEngagement=true): array
{
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$timezone='UTC';
    try{
        if(function_exists('user_calendar_default_timezone_v1300'))$timezone=user_calendar_default_timezone_v1300($pdo,$user);
        $zone=new DateTimeZone($timezone);
    }catch(Throwable $e){$timezone='UTC';$zone=new DateTimeZone('UTC');}
    $context=['source'=>'saved_owner_activity','current_time'=>$now->format(DATE_ATOM),'timezone'=>$timezone,
        'local_time'=>$now->setTimezone($zone)->format(DATE_ATOM),'available'=>false,'state'=>'unknown','interruptible'=>null];
    if($includeEngagement){
        require_once __DIR__.'/agent-initiative-engagement-memory-v1.php';
        $context['engagement']=vp3_initiative_engagement_memory_v1($pdo,$user,$binding);
    }
    try{
        require_once __DIR__.'/agent-activity-v94.php';
        $snapshot=agent_activity_v94_snapshot($user,'chat');
        $age=$snapshot['seconds_since_activity']??PHP_INT_MAX;
        if(empty($snapshot['tracking_ready'])||!is_numeric($age)||$age<0||$age>600)return $context;
        $context['available']=true;
        $context['state']=in_array($snapshot['state']??'', ['working','paused','idle','logged_out'],true)?$snapshot['state']:'unknown';
        $context['interruptible']=is_bool($snapshot['interruptible']??null)?$snapshot['interruptible']:null;
        $context['surface']=substr(preg_replace('/[^a-z0-9_-]/','',strtolower((string)($snapshot['surface']??'')))??'',0,32);
        $context['observed_at']=$now->modify('-'.(int)$age.' seconds')->format(DATE_ATOM);
        $agentId=vp3_initiative_binding_agent_v1($binding);$conversationId=(int)($binding['conversation_id']??0);
        $bound=$conversationId>0&&vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$user['id'],$agentId>0?['id'=>$agentId]:null);
        if(function_exists('vp3_initiative_owner_present_v1'))$context['text_delivery_available']=$bound&&vp3_initiative_owner_present_v1($pdo,$user,$conversationId);
        // Availability may be shared by the owner, but another Agent's task title
        // is not implicitly granted as this Agent's working context.
        if($bound&&($snapshot['context_key']??'')==='chat:'.$conversationId){
            $title=trim(preg_replace('/\s+/u',' ',strip_tags((string)($snapshot['task_title']??'')))??'');
            if($title!=='')$context['task_title']=mb_substr($title,0,190);
        }
    }catch(Throwable $e){}
    return $context;
}

/** Only semantic availability changes reassess an unchanged source snapshot. */
function vp3_initiative_availability_v1(array $context): string
{
    if(($context['available']??false)!==true)return 'unknown';
    if(($context['text_delivery_available']??false)===true)return 'quiet_chat';
    return ($context['interruptible']??null)===false?'busy':'away';
}
