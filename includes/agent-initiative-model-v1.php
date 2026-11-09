<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/homeserver-profile-agent-v235.php';
require_once __DIR__.'/agent-inference-budget-v1.php';
require_once __DIR__.'/agent-initiative-errors-v1.php';

/** Preserve the Agent's transport preference while applying the Initiative service policy. */
function vp3_initiative_inference_health_v1(PDO $pdo,array $user,int $agentId,bool $background=true): array
{
    $blocked=['ready'=>false,'route'=>'none','reason'=>'route_unavailable'];
    if($agentId<0||(int)($user['id']??0)<1)return $blocked;
    try{
        $context=['service'=>'initiative','background'=>$background];
        if(function_exists('ai_v100_service_policy')){
            $policy=ai_v100_service_policy($pdo,$user,$context);
            if($policy!==null&&empty($policy['enabled']))return $blocked;
        }
        $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agentId,'chat',$context);
        if(!empty($plan['blocked'])){
            if(($plan['effective_preference']??'')==='vp3_cloud'&&($plan['cloud']['reason']??'')==='cloud_balance_exhausted'){
                $blocked['reason']='quota_exhausted';
            }
            return $blocked;
        }
        // agent.chat can read private HomeServer state or execute tools. Only the
        // already-governed, caller-context-only inference operation is suitable.
        if(!empty($plan['try_homeserver'])&&!empty($plan['home']['supported'])
            &&function_exists('homeserver_execution_v230_execute')
            &&homeserver_profile_v235_supported((int)$user['id'])){
            return ['ready'=>true,'route'=>'homeserver','reason'=>'ready',
                'cloud_fallback_allowed'=>($plan['effective_preference']??'')!=='homeserver_only'
                    &&!empty($plan['allow_vp3_fallback'])&&vp3_initiative_cloud_scope_allowed_v1($plan)
                    &&!empty($plan['cloud']['ready'])];
        }
        $cloudSelected=($plan['route']??'')==='vp3_cloud';
        $cloudFallback=($plan['effective_preference']??'')!=='homeserver_only'
            &&!empty($plan['allow_vp3_fallback'])&&vp3_initiative_cloud_scope_allowed_v1($plan);
        if(($cloudSelected||$cloudFallback)&&!empty($plan['cloud']['ready'])){
            return ['ready'=>true,'route'=>'vp3_cloud','reason'=>'ready'];
        }
        if(($cloudSelected||$cloudFallback)&&($plan['cloud']['reason']??'')==='cloud_balance_exhausted'){
            $blocked['reason']='quota_exhausted';
        }
        return $blocked;
    }catch(Throwable $e){
        return ['ready'=>false,'route'=>'none','reason'=>'model_unavailable'];
    }
}

function vp3_initiative_cloud_scope_allowed_v1(array $plan): bool
{
    return !empty($plan['home']['cloud_allowed']) || !empty($plan['homeserver_cloud_allowed']);
}

/** Execute through the same owner routing and governed inference boundaries as Chat. */
function vp3_initiative_infer_v1(PDO $pdo,array $user,int $agentId,string $prompt,string $protocol,bool $transientContext=false,?callable $beforeInference=null,array $job=[]): string
{
    return vp3_ai_with_budget_v1(55,static fn()=>vp3_initiative_infer_scoped_v1($pdo,$user,$agentId,$prompt,$protocol,$transientContext,$beforeInference,$job));
}

/** Recheck the actual selected transport after a live-source preflight. */
function vp3_initiative_transient_route_current_v1(PDO $pdo,array $user,int $agentId,string $target,bool $background): void
{
    $current=vp3_initiative_inference_health_v1($pdo,$user,$agentId,$background);
    $allowed=($current['ready']??false)===true&&(
        $target==='homeserver'?($current['route']??'')==='homeserver'
            :(($current['route']??'')==='vp3_cloud'||($current['cloud_fallback_allowed']??false)===true));
    if(!$allowed)throw new RuntimeException('route_unavailable',503);
}

function vp3_initiative_infer_scoped_v1(PDO $pdo,array $user,int $agentId,string $prompt,string $protocol,bool $transientContext=false,?callable $beforeInference=null,array $job=[]): string
{
    $GLOBALS['vp3_initiative_inference_last_v1']=[];
    if(!in_array($protocol,['vp3.initiative-plan.v1','vp3.initiative-draft.v1','vp3.initiative-reply.v1','vp3.artist-research.v1','vp3.topic-research.v1'],true)
        ||trim($prompt)===''||strlen($prompt)>200000){
        throw new InvalidArgumentException('Invalid initiative inference request.');
    }
    $background=!empty($job['background']);
    $route=vp3_initiative_inference_health_v1($pdo,$user,$agentId,$background);
    if(empty($route['ready']))throw new RuntimeException((string)$route['reason'],503);
    if($route['route']==='homeserver'){
        if($beforeInference!==null)$beforeInference();
        if($transientContext)vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'homeserver',$background);
        try{
            $run=homeserver_execution_v230_execute((int)$user['id'],'agent.infer.local',[
                'messages'=>[
                    ['role'=>'system','content'=>vp3_tool_json_contract_v1($protocol)],
                    ['role'=>'user','content'=>$prompt],
                ],
            ],null,$transientContext);
            $result=is_array($run['result']??null)?$run['result']:[];
            $answer=is_string($result['reply']??null)?trim($result['reply']):'';
            if($answer==='')throw new RuntimeException('model_unavailable',503);
            $GLOBALS['vp3_initiative_inference_last_v1']=[
                'route'=>'homeserver','agent_id'=>$agentId,
                'execution'=>is_array($run['execution']??null)?$run['execution']:[],
                'provider'=>is_string($result['provider']??null)?$result['provider']:'homeserver',
                'model'=>is_string($result['model']??null)?$result['model']:'',
                'compute_source'=>is_string($result['compute_source']??null)?$result['compute_source']:'homeserver_local',
                'usage'=>is_array($result['usage']??null)?$result['usage']:[],
            ];
        }catch(Throwable $e){
            $current=vp3_initiative_inference_health_v1($pdo,$user,$agentId,$background);
            if(empty($route['cloud_fallback_allowed'])||empty($current['ready'])
                ||($current['route']!=='vp3_cloud'&&empty($current['cloud_fallback_allowed']))){
                if(empty($current['ready'])){
                    $reason=in_array($current['reason']??'', ['quota_exhausted','route_unavailable','model_unavailable'],true)?$current['reason']:'model_unavailable';
                    throw new RuntimeException($reason,503,$e);
                }
                $failure=function_exists('homeserver_execution_v230_failure_class')?homeserver_execution_v230_failure_class($e):'';
                if(in_array($failure,['timeout','homeserver_offline','relay_unreachable'],true))throw new Vp3InitiativeTransientInferenceV1($e);
                throw new RuntimeException('model_unavailable',503,$e);
            }
            $route['route']='vp3_cloud';
        }
    }
    if($route['route']==='vp3_cloud'){
        // A permitted fallback is another inference attempt. A stopped or
        // revoked live source cannot travel to it on an old request snapshot.
        if($beforeInference!==null)$beforeInference();
        if($transientContext)vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'vp3_cloud',$background);
        $call=static fn()=>ai_generate_chat_response($prompt,[],[],$user,'main_ai',['surface'=>'tool_json','protocol'=>$protocol]);
        $result=function_exists('ai_v100_with_service_context')
            ?ai_v100_with_service_context(['service'=>'initiative','agent_id'=>$agentId,'stage'=>$protocol]+$job,$call)
            :$call();
        if(empty($result['ok']))throw vp3_initiative_provider_failure_v1($result);
        $answer=is_string($result['answer']??null)?trim($result['answer']):'';
        $GLOBALS['vp3_initiative_inference_last_v1']=['route'=>'vp3_cloud','agent_id'=>$agentId,
            'provider'=>is_string($result['provider']??null)?$result['provider']:'',
            'model'=>is_string($result['model']??null)?$result['model']:'',
            'compute_source'=>is_string($result['compute_source']??null)?$result['compute_source']:'vp3_cloud',
            'service_attempt_ids'=>is_array($result['service_attempt_ids']??null)?$result['service_attempt_ids']:[],
            'cloud_tokens_charged'=>max(0,(int)($result['cloud_tokens_charged']??0)),
            'usage'=>is_array($result['usage']??null)?$result['usage']:[]];
    }
    if($answer===''||strlen($answer)>65536)throw new RuntimeException('model_unavailable',503);
    return $answer;
}
