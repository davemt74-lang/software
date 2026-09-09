<?php
declare(strict_types=1);

/**
 * VP3 v0.31 — canonical AI Gateway route decision contract.
 *
 * This is deliberately a pure planner. It does not call providers, spend
 * tokens, mutate pairing state, or persist secrets. Any VP3 surface can ask
 * the same questions and receive the same bounded execution plan.
 */
function ai_gateway_v031_surfaces(): array
{
    return ['chat','agent','automation','tool','wrapper','api'];
}

function ai_gateway_v031_preferences(): array
{
    return ['auto','homeserver_only','vp3_cloud'];
}

function ai_gateway_v031_surface(string $surface): string
{
    $surface=strtolower(trim($surface));
    return in_array($surface,ai_gateway_v031_surfaces(),true)?$surface:'api';
}

function ai_gateway_v031_preference(string $preference): string
{
    $preference=strtolower(trim($preference));
    return in_array($preference,ai_gateway_v031_preferences(),true)?$preference:'auto';
}

function ai_gateway_v031_bounded(string $value,int $max): string
{
    return mb_strimwidth(trim($value),0,$max,'');
}

function ai_gateway_v031_string_list(mixed $value,int $maxItems=100,int $maxChars=160): array
{
    if(!is_array($value))return [];
    $out=[];
    foreach($value as $item){
        if(!is_scalar($item))continue;
        $item=ai_gateway_v031_bounded((string)$item,$maxChars);
        if($item===''||in_array($item,$out,true))continue;
        $out[]=$item;
        if(count($out)>=$maxItems)break;
    }
    return $out;
}

/**
 * A model override is accepted only when the caller supplies an explicit
 * allow-list from the selected execution domain. This prevents a request from
 * smuggling an arbitrary provider model into HomeServer or VP3 Cloud.
 */
function ai_gateway_v031_model_selection(
    string $defaultModel,
    string $requestedModel,
    array $allowedModels,
    string $source
): array {
    $defaultModel=ai_gateway_v031_bounded($defaultModel,160);
    $requestedModel=ai_gateway_v031_bounded($requestedModel,160);
    $allowedModels=ai_gateway_v031_string_list($allowedModels,100,160);
    $selected=$defaultModel;
    $overrideApplied=false;
    $overrideRejected=false;

    if($requestedModel!==''){
        if(in_array($requestedModel,$allowedModels,true)){
            $selected=$requestedModel;
            $overrideApplied=true;
        }else{
            $overrideRejected=true;
        }
    }

    return [
        'source'=>$source,
        'selected_model'=>$selected,
        'default_model'=>$defaultModel,
        'requested_model'=>$requestedModel,
        'override_applied'=>$overrideApplied,
        'override_rejected'=>$overrideRejected,
    ];
}

/**
 * Produce a single route plan for all VP3 execution surfaces.
 *
 * Required facts are passed in as data so this function remains deterministic
 * and can be reused by web requests, workers, CLI jobs, and paired wrappers.
 */
function ai_gateway_v031_plan(array $input): array
{
    $surface=ai_gateway_v031_surface((string)($input['surface']??'api'));
    $preference=ai_gateway_v031_preference((string)($input['preference']??'auto'));
    $homePaired=!empty($input['home_paired']);
    $homeSupported=array_key_exists('home_supported',$input)?!empty($input['home_supported']):$homePaired;
    $homeReady=!empty($input['home_ready']);
    $cloudReady=array_key_exists('cloud_ready',$input)?!empty($input['cloud_ready']):true;
    $homeCloudAllowed=array_key_exists('homeserver_cloud_allowed',$input)?!empty($input['homeserver_cloud_allowed']):true;

    $route='blocked';
    $reason='no_route_ready';
    $tryHome=false;
    $allowFallback=false;
    $fallbackTarget='none';
    $blocked=true;

    if($preference==='vp3_cloud'){
        $route=$cloudReady?'vp3_cloud':'blocked';
        $reason=$cloudReady?'policy_vp3_cloud':'vp3_cloud_unavailable';
        $allowFallback=$cloudReady;
        $blocked=!$cloudReady;
    }elseif($preference==='homeserver_only'){
        $tryHome=$homePaired&&$homeSupported;
        $allowFallback=false;
        if(!$homePaired){
            $reason='homeserver_required_unpaired';
        }elseif(!$homeSupported){
            $reason='homeserver_capability_unsupported';
        }elseif($homeReady){
            $route='homeserver';
            $reason='homeserver_only_ready';
            $blocked=false;
        }else{
            // Runtime callers may still make one recovery attempt against a
            // paired/supported HomeServer before presenting the blocked state.
            $reason='homeserver_only_retry';
        }
    }else{
        $tryHome=$homePaired&&$homeSupported;
        $allowFallback=$cloudReady;
        if($homePaired&&$homeSupported&&$homeReady){
            $route='homeserver';
            $reason='homeserver_preferred';
            $fallbackTarget=$cloudReady?'vp3_cloud':'none';
            $blocked=false;
        }elseif($cloudReady){
            $route='vp3_cloud';
            $fallbackTarget='vp3_cloud';
            $reason=!$homePaired
                ?'homeserver_not_paired_fallback'
                :(!$homeSupported?'homeserver_capability_fallback':'homeserver_retry_then_cloud');
            $blocked=false;
        }else{
            $reason=!$homePaired?'no_route_unpaired_cloud_unavailable':'no_route_ready';
        }
    }

    $homeProvider=ai_gateway_v031_bounded((string)($input['home_provider']??''),80);
    $homeModel=ai_gateway_v031_bounded((string)($input['home_model']??''),160);
    $cloudProvider=ai_gateway_v031_bounded((string)($input['cloud_provider']??''),80);
    $cloudModel=ai_gateway_v031_bounded((string)($input['cloud_model']??''),160);
    $requestedModel=ai_gateway_v031_bounded((string)($input['requested_model']??''),160);

    $modelSource=$route==='homeserver'?'homeserver':($route==='vp3_cloud'?'vp3_cloud':'blocked');
    $modelSelection=$route==='homeserver'
        ?ai_gateway_v031_model_selection($homeModel,$requestedModel,is_array($input['home_allowed_models']??null)?$input['home_allowed_models']:[],'homeserver')
        :($route==='vp3_cloud'
            ?ai_gateway_v031_model_selection($cloudModel,$requestedModel,is_array($input['cloud_allowed_models']??null)?$input['cloud_allowed_models']:[],'vp3_cloud')
            :ai_gateway_v031_model_selection('',$requestedModel,[],'blocked'));

    $provider=$route==='homeserver'?$homeProvider:($route==='vp3_cloud'?$cloudProvider:'');

    return [
        'version'=>'v0.31',
        'surface'=>$surface,
        'preference'=>$preference,
        'route'=>$route,
        'reason'=>$reason,
        'blocked'=>$blocked,
        'try_homeserver'=>$tryHome,
        'homeserver_cloud_allowed'=>$homeCloudAllowed,
        'allow_vp3_fallback'=>$allowFallback,
        'fallback_target'=>$fallbackTarget,
        'provider'=>$provider,
        'model_source'=>$modelSource,
        'model'=>$modelSelection,
        'facts'=>[
            'home_paired'=>$homePaired,
            'home_supported'=>$homeSupported,
            'home_ready'=>$homeReady,
            'cloud_ready'=>$cloudReady,
        ],
    ];
}

/**
 * Compatibility adapter for the established v0.20 route contract. Existing
 * callers keep their exact keys while all route decisions flow through v0.31.
 */
function ai_gateway_v031_legacy_route_plan(string $preference,bool $homePaired,bool $homeReady): array
{
    $plan=ai_gateway_v031_plan([
        'surface'=>'agent',
        'preference'=>$preference,
        'home_paired'=>$homePaired,
        'home_supported'=>$homePaired,
        'home_ready'=>$homeReady,
        // v0.20 historically plans VP3 fallback availability separately from
        // provider health, so preserve that behavior in the compatibility API.
        'cloud_ready'=>true,
        'homeserver_cloud_allowed'=>true,
    ]);

    $resolvedRoute=(string)$plan['route'];
    if($plan['preference']==='homeserver_only'&&!$homeReady)$resolvedRoute='blocked';

    $label=match($plan['preference']){
        'vp3_cloud'=>'VP3 Cloud',
        'homeserver_only'=>$homeReady?'HomeServer':'Waiting for HomeServer',
        default=>$homeReady?'HomeServer first':'VP3 Cloud',
    };

    return [
        'preference'=>(string)$plan['preference'],
        'try_homeserver'=>!empty($plan['try_homeserver']),
        'homeserver_cloud_allowed'=>!empty($plan['homeserver_cloud_allowed']),
        'allow_vp3_fallback'=>!empty($plan['allow_vp3_fallback']),
        'resolved_route'=>$resolvedRoute,
        'resolved_label'=>$label,
        'blocked'=>!empty($plan['blocked']),
        'gateway_version'=>'v0.31',
        'route_reason'=>(string)$plan['reason'],
    ];
}
