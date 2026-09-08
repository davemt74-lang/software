<?php
declare(strict_types=1);

/**
 * VP3 v0.25 — stateless Agent Brain delegation to an explicitly capable
 * HomeServer while VP3 remains the canonical conversation/history owner.
 */
function homeserver_agent_v025_supported(int $userId): bool
{
    if($userId<1||!function_exists('homeserver_capability_v024_raw'))return false;
    $raw=homeserver_capability_v024_raw($userId);
    $features=homeserver_capability_v024_string_list($raw['features']??[]);
    return in_array('agent.delegation.v1',$features,true);
}

function homeserver_agent_v025_history(array $history): array
{
    $selected=[];$remaining=24000;
    foreach(array_reverse(array_slice($history,-12)) as $row){
        if(!is_array($row)||$remaining<1)continue;
        $role=strtolower(trim((string)($row['role']??'')));
        if(!in_array($role,['user','assistant'],true))continue;
        $content=trim((string)($row['message']??$row['content']??''));
        if($content==='')continue;
        $limit=min(6000,$remaining);
        if(mb_strlen($content)>$limit)$content=mb_substr($content,-$limit);
        $remaining-=mb_strlen($content);
        $selected[]=['role'=>$role,'content'=>$content];
    }
    return array_reverse($selected);
}

function homeserver_agent_v025_persona(array $principal,?array $activeAgent): array
{
    $name=mb_strimwidth(trim((string)($principal['display_name']??'Agent')),0,190,'');
    if($name==='')$name='Agent';
    $role=$activeAgent?trim((string)($activeAgent['agent_role']??'personal')):(string)($principal['kind']??'system');
    $role=preg_replace('/[^a-z0-9_-]/','',strtolower($role))?:'system';
    $instructions=$activeAgent?mb_strimwidth(trim((string)($activeAgent['instructions']??'')),0,4000,''):'';
    return ['name'=>$name,'role'=>mb_strimwidth($role,0,80,''),'instructions'=>$instructions];
}

function homeserver_agent_v025_surface(array $agentContext): array
{
    if(function_exists('agent_surface_v131_sanitize')){
        try{return agent_surface_v131_sanitize($agentContext);}catch(Throwable $e){}
    }
    return [
        'surface'=>'chat',
        'conversation_id'=>max(0,(int)($agentContext['conversation_id']??0)),
        'path'=>mb_strimwidth(trim((string)($agentContext['path']??'')),0,500,''),
    ];
}

function homeserver_agent_v025_public_state(array $result): array
{
    $delegation=is_array($result['delegation']??null)?$result['delegation']:[];
    $mode=(string)($delegation['mode']??'legacy_stateful');
    if(!in_array($mode,['stateless_vp3_canonical','legacy_stateful'],true))$mode='legacy_stateful';
    return [
        'version'=>$mode==='stateless_vp3_canonical'?'v0.25':'v0.18',
        'mode'=>$mode,
        'canonical_conversation_owner'=>$mode==='stateless_vp3_canonical'?'vp3':'homeserver_mirror',
        'history_messages'=>max(0,min(12,(int)($delegation['history_messages']??0))),
        'surface_context'=>!empty($delegation['surface_context']),
    ];
}

function homeserver_agent_v025_chat(
    array $user,
    string $query,
    int $conversationId,
    array $history,
    array $principal,
    ?array $activeAgent,
    array $agentContext,
    bool $cloudAllowed=true
): ?array {
    $userId=(int)($user['id']??0);
    if(!homeserver_agent_v025_supported($userId)){
        $legacy=homeserver_agent_v018_chat($user,$query,$conversationId,$cloudAllowed);
        if($legacy)$legacy['delegation']=['mode'=>'legacy_stateful','history_messages'=>0,'surface_context'=>false];
        return $legacy;
    }

    homeserver_agent_v018_set_last_attempt(['attempted'=>false,'success'=>false,'failure_class'=>'none']);
    if($userId<1||$conversationId<1||trim($query)===''||!function_exists('homeserver_vp3_remote_operation'))return null;
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials){
        homeserver_agent_v018_set_last_attempt(['attempted'=>false,'success'=>false,'failure_class'=>'homeserver_not_paired']);
        return null;
    }

    $boundedHistory=homeserver_agent_v025_history($history);
    $surface=homeserver_agent_v025_surface($agentContext);
    $payload=[
        'message'=>$query,
        'external_conversation_id'=>'vp3:'.$conversationId,
        'delegation'=>homeserver_agent_v025_persona($principal,$activeAgent),
        'history'=>$boundedHistory,
        'surface_context'=>$surface,
        'include_memory'=>true,
        'include_knowledge'=>true,
        'include_contacts'=>true,
        'cloud_allowed'=>$cloudAllowed,
        'max_context_chars'=>12000,
    ];

    $started=microtime(true);
    homeserver_agent_v018_set_last_attempt(['attempted'=>true,'success'=>false,'failure_class'=>'none']);
    try{
        // Reuse the established agent.chat relay operation. Delegation is a
        // capability-negotiated payload extension, not a second transport.
        $result=homeserver_vp3_remote_operation($credentials['relay'],'agent.chat',$payload,$credentials['home']);
    }catch(Throwable $e){
        $latency=max(0,(int)round((microtime(true)-$started)*1000));
        homeserver_agent_v018_set_last_attempt([
            'attempted'=>true,'success'=>false,'latency_ms'=>$latency,
            'failure_class'=>homeserver_agent_v018_failure_class($e->getMessage()),
        ]);
        if(function_exists('ai_v100_telemetry'))ai_v100_telemetry(['scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','status'=>'failed','service'=>'homeserver-v0.25-delegation']);
        return null;
    }

    $latency=max(0,(int)round((microtime(true)-$started)*1000));
    $reply=trim((string)($result['reply']??''));
    if($reply===''){
        homeserver_agent_v018_set_last_attempt(['attempted'=>true,'success'=>false,'latency_ms'=>$latency,'failure_class'=>'empty_response']);
        return null;
    }

    $attempt=[
        'attempted'=>true,'success'=>true,'latency_ms'=>$latency,'failure_class'=>'none',
        'provider'=>(string)($result['provider']??'homeserver'),
        'model'=>(string)($result['model']??''),
        'compute_source'=>(string)($result['compute_source']??'homeserver'),
    ];
    homeserver_agent_v018_set_last_attempt($attempt);
    if(function_exists('ai_v100_telemetry'))ai_v100_telemetry([
        'scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','model'=>(string)($result['model']??''),'status'=>'success','service'=>'homeserver-v0.25-delegation',
        'input_tokens'=>(int)($result['usage']['prompt_tokens']??0),'output_tokens'=>(int)($result['usage']['completion_tokens']??0),'total_tokens'=>(int)($result['usage']['total_tokens']??0),
    ]);

    $remoteDelegation=is_array($result['delegation']??null)?$result['delegation']:[];
    return [
        'answer'=>$reply,
        'provider'=>(string)($result['provider']??'homeserver'),
        'model'=>(string)($result['model']??''),
        'compute_source'=>(string)($result['compute_source']??'homeserver'),
        'usage'=>is_array($result['usage']??null)?$result['usage']:[],
        'run_id'=>(int)($result['run_id']??0),
        'conversation_id'=>'',
        'cloud_tokens_debited'=>max(0,(int)($result['cloud_tokens_debited']??0)),
        'latency_ms'=>$latency,
        'delegation'=>[
            'mode'=>'stateless_vp3_canonical',
            'history_messages'=>max(0,min(12,(int)($remoteDelegation['history_messages']??count($boundedHistory)))),
            'surface_context'=>!empty($remoteDelegation['surface_context'])||!empty($surface),
        ],
    ];
}
