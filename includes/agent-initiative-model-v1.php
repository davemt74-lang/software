<?php
declare(strict_types=1);

require_once __DIR__.'/agent-tool-json-v1.php';
require_once __DIR__.'/homeserver-profile-agent-v235.php';
require_once __DIR__.'/agent-inference-budget-v1.php';
require_once __DIR__.'/agent-initiative-errors-v1.php';

/** Preserve the Agent's transport preference while applying the Initiative service policy. */
function vp3_initiative_inference_health_v1(PDO $pdo,array $user,int $agentId,bool $background=true,bool $observeOnly=false): array
{
    $blocked=['ready'=>false,'route'=>'none','reason'=>'route_unavailable'];
    if($agentId<0||(int)($user['id']??0)<1)return $blocked;
    try{
        $context=['service'=>'initiative','background'=>$background];
        $policy=null;
        if(function_exists('ai_v100_service_policy')){
            $policy=ai_v100_service_policy($pdo,$user,$context);
            if($policy!==null&&empty($policy['enabled'])){
                $reason=$policy['reason']??'';
                if(in_array($reason,['service_disabled','background_disabled','service_budget_exhausted',
                    'service_model_forbidden','service_policy_unavailable'],true))$blocked['policy_reason']=$reason;
                return $blocked;
            }
        }
        $plan=vp3_agent_runtime_plan_v420($pdo,$user,$agentId,'chat',$context,$observeOnly);
        if(($plan['route_reason']??null)==='route_check_required'){
            return ['ready'=>false,'route'=>'none','reason'=>'route_check_required'];
        }
        $preference=$plan['effective_preference']??'auto';
        $cloudReason=$plan['cloud']['reason']??null;
        if(!empty($plan['blocked'])){
            if($preference==='vp3_cloud'&&$cloudReason==='cloud_balance_exhausted'){
                $blocked['reason']='quota_exhausted';
            }
            if($preference!=='homeserver_only'){
                $diagnostic=match($cloudReason){
                    'user_key_unavailable'=>'provider_configuration',
                    'cloud_service_route_unavailable'=>'service_configuration',
                    default=>null,
                };
                if($diagnostic!==null)$blocked['model_diagnostic']=$diagnostic;
            }
            return $blocked;
        }
        $cloudSelected=($plan['route']??'')==='vp3_cloud';
        $cloudFallback=$preference!=='homeserver_only'
            &&!empty($plan['allow_vp3_fallback'])&&vp3_initiative_cloud_scope_allowed_v1($plan);
        $cloudAvailable=($cloudSelected||$cloudFallback)&&!empty($plan['cloud']['ready']);
        $cloudDiagnostic=$cloudAvailable?vp3_initiative_cloud_model_diagnostic_v1($pdo,$user,$policy):null;
        $cloudReady=$cloudAvailable&&$cloudDiagnostic===null;
        // agent.chat can read private HomeServer state or execute tools. Only the
        // already-governed, caller-context-only inference operation is suitable.
        if(!empty($plan['try_homeserver'])&&!empty($plan['home']['supported'])
            &&function_exists('homeserver_execution_v230_execute')
            &&homeserver_profile_v235_supported((int)$user['id'])){
            return ['ready'=>true,'route'=>'homeserver','reason'=>'ready',
                'cloud_fallback_allowed'=>$cloudFallback&&$cloudReady];
        }
        if($cloudReady){
            return ['ready'=>true,'route'=>'vp3_cloud','reason'=>'ready'];
        }
        if($cloudAvailable){
            return ['ready'=>false,'route'=>'vp3_cloud','reason'=>'model_unavailable','model_diagnostic'=>$cloudDiagnostic];
        }
        if(($cloudSelected||$cloudFallback)&&$cloudReason==='cloud_balance_exhausted'){
            $blocked['reason']='quota_exhausted';
        }
        return $blocked;
    }catch(Throwable $e){
        return ['ready'=>false,'route'=>'none','reason'=>'model_unavailable','model_diagnostic'=>'model_preflight_unavailable'];
    }
}

/** Return a closed readiness diagnostic before any provider call. */
function vp3_initiative_cloud_model_diagnostic_v1(PDO $pdo,array $user,?array $policy): ?string
{
    // Explicit service routes already check the credentials of their actual
    // primary and fallback targets in the runtime plan.
    if(!empty($policy['configured']))return function_exists('curl_init')?null:'provider_configuration';
    if(!function_exists('ai_cloud_account_route'))return null;

    try{
        $own=ai_cloud_account_route($pdo,$user,false);
        $funding=$own['route']??'unavailable';
        if($funding==='own_key'){
            if(!function_exists('curl_init'))return 'provider_configuration';
            $provider=(string)$own['provider'];
            $models=[(string)$own['model']];
        }elseif($funding==='system'){
            $provider=ai_active_provider();
            if($provider==='local')return 'service_configuration';
            if(ai_provider_configuration_failure($provider)!==null)return 'provider_configuration';
            $models=array_unique(array_merge(...array_map(static fn(string $complexity):array=>
                ai_v100_model_candidates($provider,$complexity),['routine','complex','deep'])));
        }else{
            return 'provider_configuration';
        }
        $targetsReady=$policy===null?$models!==[]:ai_v100_service_targets($policy,$funding,$provider,$models)!==[];
        return $targetsReady?null:'service_configuration';
    }catch(Throwable $error){
        return 'model_preflight_unavailable';
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
    if(!in_array($protocol,['vp3.initiative-plan.v1','vp3.initiative-draft.v1',VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1,'vp3.initiative-reply.v1','vp3.initiative-canvas.v1','vp3.artist-research.v1','vp3.topic-research.v1','vp3.transcript-research-plan.v1','vp3.image-brainstorm.v1','vp3.operator-outreach-reason.v1','vp3.phone-agent-email-call-decision.v1','vp3.email-preference-suggestion.v1'],true)
        ||trim($prompt)===''||strlen($prompt)>200000){
        throw new InvalidArgumentException('Invalid initiative inference request.');
    }
    $background=!empty($job['background']);
    $gmailFollowthrough=($job['gmail_followthrough_draft']??false)===true;
    $phoneEmailCall=($job['phone_agent_email_call_decision']??false)===true;
    $emailPreference=($job['email_preference_suggestion']??false)===true;
    if($gmailFollowthrough&&($protocol!=='vp3.initiative-draft.v1'||!$transientContext||!$background||$beforeInference===null)){
        throw new InvalidArgumentException('An email follow-up requires its current background dispatch claim.');
    }
    if(($phoneEmailCall||$protocol==='vp3.phone-agent-email-call-decision.v1')
        &&(!$phoneEmailCall||$protocol!=='vp3.phone-agent-email-call-decision.v1'||!$transientContext||!$background
            ||!($beforeInference instanceof Closure)||$gmailFollowthrough)){
        throw new InvalidArgumentException('An owner email-call decision requires its current background dispatch claim.');
    }
    if(($emailPreference||$protocol==='vp3.email-preference-suggestion.v1')
        &&(!$emailPreference||$protocol!=='vp3.email-preference-suggestion.v1'||!$transientContext||$background
            ||!($beforeInference instanceof Closure)||$gmailFollowthrough||$phoneEmailCall)){
        throw new InvalidArgumentException('An email writing suggestion requires its explicit owner dispatch claim.');
    }
    $guardedDispatch=$gmailFollowthrough||$phoneEmailCall||$emailPreference;
    unset($job['gmail_followthrough_draft'],$job['phone_agent_email_call_decision'],$job['email_preference_suggestion']);
    $route=vp3_initiative_inference_health_v1($pdo,$user,$agentId,$background);
    if(empty($route['ready']))throw vp3_initiative_inference_blocked_v1($route);
    if($route['route']==='homeserver'){
        if($guardedDispatch)vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'homeserver',$background);
        if($beforeInference!==null)$beforeInference();
        if($transientContext&&!$guardedDispatch)vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'homeserver',$background);
        try{
            $run=homeserver_execution_v230_execute((int)$user['id'],'agent.infer.local',[
                'messages'=>[
                    ['role'=>'system','content'=>vp3_tool_json_contract_v1($protocol)],
                    ['role'=>'user','content'=>$prompt],
                ],
            ],null,$transientContext);
            $result=is_array($run['result']??null)?$run['result']:[];
            $answer=is_string($result['reply']??null)?trim($result['reply']):'';
            if($answer==='')throw new Vp3InitiativeModelDiagnosticV1('response_empty');
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
            if($guardedDispatch||empty($route['cloud_fallback_allowed'])||empty($current['ready'])
                ||($current['route']!=='vp3_cloud'&&empty($current['cloud_fallback_allowed']))){
                if(empty($current['ready'])){
                    throw vp3_initiative_inference_blocked_v1($current,$e);
                }
                $failure=function_exists('homeserver_execution_v230_failure_class')?homeserver_execution_v230_failure_class($e):'';
                if($e instanceof Vp3InitiativeModelDiagnosticV1)throw $e;
                if(in_array($failure,['timeout','homeserver_offline','relay_unreachable'],true)){
                    throw new Vp3InitiativeTransientInferenceV1($e,$failure==='timeout'?'inference_timeout':'homeserver_unavailable');
                }
                throw new Vp3InitiativeModelDiagnosticV1('homeserver_unavailable',null,$e);
            }
            $route['route']='vp3_cloud';
        }
    }
    if($route['route']==='vp3_cloud'){
        // A permitted fallback is another inference attempt. A stopped or
        // revoked live source cannot travel to it on an old request snapshot.
        if(!$guardedDispatch&&$beforeInference!==null)$beforeInference();
        if($transientContext)vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'vp3_cloud',$background);
        $control=['surface'=>'tool_json','protocol'=>$protocol];$dispatchFailure=null;
        if($guardedDispatch){
            // Consume the persisted one-use claim after the usage reservation
            // lock, immediately before the single actual provider request.
            $flag=$emailPreference?'email_preference_suggestion':($phoneEmailCall?'phone_agent_email_call_decision':'gmail_followthrough_draft');
            $callback=$emailPreference?'before_email_preference_dispatch':($phoneEmailCall?'before_phone_agent_email_call_dispatch':'before_gmail_followthrough_dispatch');
            $control[$flag]=true;
            $control[$callback]=static function()use($pdo,$user,$agentId,$background,$beforeInference,&$dispatchFailure):bool{
                try {
                    vp3_initiative_transient_route_current_v1($pdo,$user,$agentId,'vp3_cloud',$background);
                    $beforeInference();
                    return true;
                } catch (Throwable $error) {
                    // The transport must close its handle and release reserved
                    // usage first. Preserve the source/control cause for the
                    // worker, which withdraws superseded no-reply evidence.
                    $dispatchFailure=$error;
                    throw $error;
                }
            };
        }
        $call=static fn()=>ai_generate_chat_response($prompt,[],[],$user,'main_ai',$control);
        $result=function_exists('ai_v100_with_service_context')
            ?ai_v100_with_service_context(['service'=>'initiative','agent_id'=>$agentId,'stage'=>$protocol]+$job,$call)
            :$call();
        if($dispatchFailure!==null)throw $dispatchFailure;
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
    if($answer==='')throw new Vp3InitiativeModelDiagnosticV1('response_empty');
    if(strlen($answer)>65536)throw new Vp3InitiativeModelDiagnosticV1('response_too_large');
    return $answer;
}

function vp3_initiative_inference_blocked_v1(array $health,?Throwable $previous=null): RuntimeException
{
    $reason=$health['reason']??null;
    if(in_array($reason,['quota_exhausted','route_unavailable'],true))return new RuntimeException($reason,503,$previous);
    return new Vp3InitiativeModelDiagnosticV1($health['model_diagnostic']??'model_preflight_unavailable',null,$previous);
}
