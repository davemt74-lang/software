<?php
declare(strict_types=1);

/**
 * VP3 v4.20 — canonical Agent compute/runtime routing boundary.
 *
 * One request resolves one immutable runtime plan from the authenticated user,
 * exact Agent namespace, surface, account preference, Agent override, current
 * commercial cloud capacity, and (only when relevant) paired HomeServer state.
 *
 * Agent identity/configuration never grants data or tool authority. This layer
 * decides only where already-authorized Agent work may execute.
 */
const VP3_AGENT_RUNTIME_ROUTING_V420='vp3-agent-runtime-routing-v420-20260910';

function vp3_agent_runtime_preference_v420(PDO $pdo,int $userId,int $agentId): array
{
    $account=agent_compute_v020_preference($pdo,$userId);
    $override=agent_compute_v023_override($pdo,$userId,max(0,$agentId));
    return agent_compute_v023_policy_from_values($account,$override);
}

function vp3_agent_runtime_cloud_state_v420(array $user): array
{
    $state=['ready'=>false,'entitled'=>false,'unlimited'=>false,'remaining'=>0,'reason'=>'cloud_unavailable'];
    if((int)($user['id']??0)<1)return $state;
    try{
        $state['entitled']=function_exists('subscription_has_entitlement')
            ?subscription_has_entitlement($user,'main_ai.access')
            :true;
        if(!$state['entitled']){$state['reason']='cloud_not_entitled';return $state;}
        if(!function_exists('subscription_ai_balance')){
            $state['ready']=true;$state['reason']='compatibility';return $state;
        }
        $balance=subscription_ai_balance($user);
        $state['unlimited']=!empty($balance['unlimited']);
        $state['remaining']=$state['unlimited']?null:max(0,(int)($balance['remaining']??0));
        $state['ready']=$state['unlimited']||((int)($state['remaining']??0)>0);
        $state['reason']=$state['ready']?'cloud_ready':'cloud_balance_exhausted';
    }catch(Throwable $e){
        $state['reason']='cloud_balance_unavailable';
    }
    return $state;
}

function vp3_agent_runtime_tool_plan_v420(int $userId,int $agentId,string $surface='chat'): array
{
    return [
        'version'=>'v4.20','surface'=>ai_gateway_v031_surface($surface),'user_id'=>max(0,$userId),'agent_id'=>max(0,$agentId),
        'requested_preference'=>'tool','effective_preference'=>'tool','policy_source'=>'tool','compute_policy'=>[],
        'route'=>'vp3_tool','route_reason'=>'vp3_tool_handled','blocked'=>false,'try_homeserver'=>false,
        'allow_vp3_fallback'=>false,'fallback_target'=>'none','homeserver_cloud_allowed'=>false,
        'home'=>['paired'=>false,'supported'=>false,'ready'=>false,'cloud_allowed'=>false,'scope_checked'=>false],
        'cloud'=>['ready'=>false,'entitled'=>false,'unlimited'=>false,'remaining'=>null,'reason'=>'not_checked'],
        'capability'=>['supported'=>true,'ready'=>true,'reason'=>'vp3_tool_handled'],'gateway_version'=>'v4.20',
    ];
}

function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'): array
{
    $userId=(int)($user['id']??0);$agentId=max(0,$agentId);
    if($userId<1)throw new RuntimeException('A signed-in VP3 user is required for Agent runtime routing.');
    if(function_exists('agent_compute_v023_assert_owned_agent'))agent_compute_v023_assert_owned_agent($pdo,$userId,$agentId);

    $rawPolicy=vp3_agent_runtime_preference_v420($pdo,$userId,$agentId);
    $requested=(string)($rawPolicy['effective_preference']??'auto');
    $cloud=vp3_agent_runtime_cloud_state_v420($user);
    $policy=$rawPolicy;
    $home=['paired'=>false,'supported'=>false,'ready'=>false,'cloud_allowed'=>true,'scope_checked'=>false];
    $brainCapability=['supported'=>false,'ready'=>false,'reason'=>'not_checked'];

    // Explicit VP3 Cloud is a strict transport boundary: do not call
    // HomeServer scope/capability/relay discovery merely to plan this request.
    if($requested!=='vp3_cloud'){
        if(function_exists('homeserver_scope_v026_fetch')&&function_exists('homeserver_scope_v026_apply_compute_policy')){
            $scopeState=homeserver_scope_v026_fetch($userId,false);
            $policy=homeserver_scope_v026_apply_compute_policy($policy,$scopeState);
            $home['scope_checked']=true;
            $publicScope=is_array($policy['homeserver_scope']??null)?$policy['homeserver_scope']:[];
            $home['cloud_allowed']=($publicScope['cloud_allowed']??null)!==false;
            if(!empty($policy['scope_override']))$home['cloud_allowed']=false;
        }
        if(function_exists('homeserver_agent_v018_credentials'))$home['paired']=homeserver_agent_v018_credentials($userId)!==null;
        if(function_exists('homeserver_capability_v024_registry')&&function_exists('homeserver_capability_v024_resolve')){
            $registry=homeserver_capability_v024_registry($userId,false);
            $brainCapability=homeserver_capability_v024_resolve($registry,'agent_brain','vp3_cloud');
            $home['supported']=!empty($brainCapability['supported']);
            $home['ready']=!empty($brainCapability['ready']);
        }else{
            $home['supported']=$home['paired'];
        }
    }

    $effective=(string)($policy['effective_preference']??$requested);
    if(!in_array($effective,['auto','homeserver_only','vp3_cloud'],true))$effective='auto';
    $gateway=ai_gateway_v031_plan([
        'surface'=>$surface,
        'preference'=>$effective,
        'home_paired'=>$home['paired'],
        'home_supported'=>$home['supported'],
        'home_ready'=>$home['ready'],
        'cloud_ready'=>!empty($cloud['ready']),
        'homeserver_cloud_allowed'=>!empty($home['cloud_allowed']),
    ]);

    return [
        'version'=>'v4.20','surface'=>ai_gateway_v031_surface($surface),'user_id'=>$userId,'agent_id'=>$agentId,
        'requested_preference'=>$requested,'effective_preference'=>$effective,
        'policy_source'=>(string)($policy['source']??'account'),
        'compute_policy'=>agent_compute_v023_public_policy($policy,$agentId),
        'route'=>(string)$gateway['route'],'route_reason'=>(string)$gateway['reason'],'blocked'=>!empty($gateway['blocked']),
        'try_homeserver'=>!empty($gateway['try_homeserver']),'allow_vp3_fallback'=>!empty($gateway['allow_vp3_fallback']),
        'fallback_target'=>(string)($gateway['fallback_target']??'none'),'homeserver_cloud_allowed'=>!empty($gateway['homeserver_cloud_allowed']),
        'home'=>$home,'cloud'=>$cloud,
        'capability'=>[
            'supported'=>!empty($brainCapability['supported']),'ready'=>!empty($brainCapability['ready']),
            'reason'=>mb_strimwidth((string)($brainCapability['reason']??'not_checked'),0,100,''),
        ],
        'gateway_version'=>(string)($gateway['version']??'v0.31'),
    ];
}

function vp3_agent_runtime_public_plan_v420(array $plan): array
{
    return [
        'version'=>'v4.20','surface'=>(string)($plan['surface']??'chat'),'agent_id'=>max(0,(int)($plan['agent_id']??0)),
        'requested_preference'=>(string)($plan['requested_preference']??'auto'),
        'effective_preference'=>(string)($plan['effective_preference']??'auto'),
        'policy_source'=>(string)($plan['policy_source']??'account'),'route'=>(string)($plan['route']??'blocked'),
        'route_reason'=>(string)($plan['route_reason']??'no_route_ready'),'blocked'=>!empty($plan['blocked']),
        'try_homeserver'=>!empty($plan['try_homeserver']),'allow_vp3_fallback'=>!empty($plan['allow_vp3_fallback']),
        'fallback_target'=>(string)($plan['fallback_target']??'none'),'homeserver_cloud_allowed'=>!empty($plan['homeserver_cloud_allowed']),
        'home'=>[
            'paired'=>!empty($plan['home']['paired']),'supported'=>!empty($plan['home']['supported']),
            'ready'=>!empty($plan['home']['ready']),'scope_checked'=>!empty($plan['home']['scope_checked']),
            'cloud_allowed'=>!empty($plan['home']['cloud_allowed']),
        ],
        'cloud'=>[
            'ready'=>!empty($plan['cloud']['ready']),'entitled'=>!empty($plan['cloud']['entitled']),
            'unlimited'=>!empty($plan['cloud']['unlimited']),'remaining'=>$plan['cloud']['remaining']??null,
            'reason'=>(string)($plan['cloud']['reason']??'cloud_unavailable'),
        ],
    ];
}

function vp3_agent_runtime_block_message_v420(array $plan): string
{
    $reason=(string)($plan['route_reason']??'no_route_ready');
    return match($reason){
        'homeserver_required_unpaired'=>'HomeServer-only compute is selected for this Agent, but this account is not connected to HomeServer. Connect HomeServer or change this Agent’s compute policy in My Account.',
        'homeserver_capability_unsupported'=>'HomeServer-only compute is selected for this Agent, but the paired HomeServer does not advertise the Agent Brain capability. Update or reconnect HomeServer, then try again.',
        'homeserver_only_retry'=>'HomeServer-only compute is selected for this Agent, but HomeServer could not complete this request. Start or reconnect HomeServer, then try again.',
        'vp3_cloud_unavailable'=>'VP3 Cloud is selected for this Agent, but cloud AI access or token capacity is unavailable. Add tokens or update your package to continue.',
        'no_route_unpaired_cloud_unavailable','no_route_ready'=>'No Agent compute route is currently available. Connect HomeServer or add VP3 Cloud token capacity.',
        default=>'The selected Agent compute route is currently unavailable.',
    };
}

function vp3_agent_runtime_homeserver_execution_v420(array $result): array
{
    $compute=trim((string)($result['compute_source']??''));
    if($compute!=='vp3_cloud')return chat_execution_v019_homeserver($result);
    $usage=is_array($result['usage']??null)?$result['usage']:[];
    return chat_execution_v019_base(
        'vp3_cloud','VP3 Cloud via HomeServer',
        trim((string)($result['provider']??'')),trim((string)($result['model']??'')),'connected',false,'none',$usage,
        max(0,(int)($result['run_id']??0)),max(0,(int)($result['cloud_tokens_debited']??0)),max(0,(int)($result['latency_ms']??0)),'none'
    );
}

function vp3_agent_runtime_actual_route_v420(array $execution): string
{
    $source=(string)($execution['source']??'');
    if($source==='vp3_tool')return 'vp3_tool';
    if($source==='vp3_retrieval')return 'vp3_retrieval';
    if($source==='user_provider')return 'homeserver_user_provider';
    if($source==='homeserver_local')return 'homeserver_local';
    if($source==='vp3_cloud')return (string)($execution['homeserver']??'not_used')==='connected'?'homeserver_vp3_cloud':'vp3_cloud';
    return $source!==''?$source:'unknown';
}

function vp3_agent_runtime_finalize_v420(array $plan,array $execution,string $attemptedRoute='',string $fallbackReason=''): array
{
    $actual=vp3_agent_runtime_actual_route_v420($execution);
    if($attemptedRoute==='')$attemptedRoute=!empty($plan['try_homeserver'])?'homeserver':(string)($plan['route']??'blocked');
    if($fallbackReason==='')$fallbackReason=(string)($execution['fallback_reason']??'');
    $execution['runtime_version']='v4.20';
    $execution['requested_route']=(string)($plan['requested_preference']??'auto');
    $execution['attempted_route']=$attemptedRoute;
    $execution['actual_route']=$actual;
    $execution['route_reason']=(string)($plan['route_reason']??'');
    $execution['fallback_reason']=$fallbackReason;
    $execution['compute_policy']=$plan['compute_policy']??[];
    $execution['runtime_plan']=vp3_agent_runtime_public_plan_v420($plan);
    return $execution;
}

function vp3_agent_runtime_capability_route_v420(array $plan,array $execution,bool $homeAttempted=false): array
{
    return [
        'version'=>'v4.20','capability'=>'agent_brain','planned_source'=>(string)($plan['route']??'blocked'),
        'actual_source'=>vp3_agent_runtime_actual_route_v420($execution),'supported'=>!empty($plan['capability']['supported'])||((string)($execution['source']??'')==='vp3_cloud')||((string)($execution['source']??'')==='vp3_tool'),
        'ready'=>true,'fallback_used'=>!empty($execution['fallback_used']),'reason'=>(string)($execution['fallback_reason']??$plan['route_reason']??''),
        'home_attempted'=>$homeAttempted,
    ];
}
