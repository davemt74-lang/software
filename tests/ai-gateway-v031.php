<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/agent-compute-v020.php';

function gateway_assert_v031(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}
}

$surfaces=['chat','agent','automation','tool','wrapper','api'];
foreach($surfaces as $surface){
    $plan=ai_gateway_v031_plan([
        'surface'=>$surface,
        'preference'=>'auto',
        'home_paired'=>true,
        'home_supported'=>true,
        'home_ready'=>true,
        'cloud_ready'=>true,
        'home_provider'=>'ollama',
        'home_model'=>'llama-default',
    ]);
    gateway_assert_v031($plan['version']==='v0.31','gateway version');
    gateway_assert_v031($plan['surface']===$surface,'surface must survive planning');
    gateway_assert_v031($plan['route']==='homeserver','automatic must prefer ready HomeServer');
    gateway_assert_v031($plan['reason']==='homeserver_preferred','route reason must explain HomeServer preference');
    gateway_assert_v031($plan['try_homeserver']===true,'ready HomeServer must be attempted');
    gateway_assert_v031($plan['fallback_target']==='vp3_cloud','automatic route must retain cloud fallback target');
}

$recovery=ai_gateway_v031_plan([
    'surface'=>'chat','preference'=>'auto','home_paired'=>true,'home_supported'=>true,
    'home_ready'=>false,'cloud_ready'=>true,
]);
gateway_assert_v031($recovery['try_homeserver']===true,'paired supported HomeServer must get a recovery attempt');
gateway_assert_v031($recovery['route']==='vp3_cloud','offline automatic route must resolve to cloud fallback');
gateway_assert_v031($recovery['reason']==='homeserver_retry_then_cloud','recovery/fallback reason must be explicit');

$unsupported=ai_gateway_v031_plan([
    'surface'=>'automation','preference'=>'homeserver_only','home_paired'=>true,
    'home_supported'=>false,'home_ready'=>false,'cloud_ready'=>true,
]);
gateway_assert_v031($unsupported['blocked']===true,'HomeServer-only must block unsupported capability');
gateway_assert_v031($unsupported['allow_vp3_fallback']===false,'HomeServer-only must never silently use VP3 cloud');
gateway_assert_v031($unsupported['reason']==='homeserver_capability_unsupported','unsupported capability reason must be explicit');

$cloudDown=ai_gateway_v031_plan([
    'surface'=>'wrapper','preference'=>'vp3_cloud','home_paired'=>true,
    'home_supported'=>true,'home_ready'=>true,'cloud_ready'=>false,
]);
gateway_assert_v031($cloudDown['blocked']===true,'explicit VP3 Cloud must block when cloud is unavailable');
gateway_assert_v031($cloudDown['route']==='blocked','unavailable explicit cloud must not reroute to HomeServer');
gateway_assert_v031($cloudDown['reason']==='vp3_cloud_unavailable','cloud outage reason must be explicit');

$model=ai_gateway_v031_plan([
    'surface'=>'api','preference'=>'vp3_cloud','cloud_ready'=>true,
    'cloud_provider'=>'openai','cloud_model'=>'gpt-default',
    'requested_model'=>'gpt-user-choice',
    'cloud_allowed_models'=>['gpt-default','gpt-user-choice'],
]);
gateway_assert_v031($model['model']['selected_model']==='gpt-user-choice','allow-listed user model override must be applied');
gateway_assert_v031($model['model']['override_applied']===true,'applied override must be explicit');
gateway_assert_v031($model['model']['override_rejected']===false,'accepted override must not be marked rejected');

$rejected=ai_gateway_v031_plan([
    'surface'=>'api','preference'=>'vp3_cloud','cloud_ready'=>true,
    'cloud_provider'=>'openai','cloud_model'=>'gpt-default',
    'requested_model'=>'untrusted-model',
    'cloud_allowed_models'=>['gpt-default'],
]);
gateway_assert_v031($rejected['model']['selected_model']==='gpt-default','unallowlisted override must fall back to configured model');
gateway_assert_v031($rejected['model']['override_rejected']===true,'rejected override must be explicit');

// Existing v0.20 callers must receive their historic route semantics while
// gaining the canonical gateway version/reason additively.
$legacyReady=agent_compute_v020_route_plan('auto',true,true);
gateway_assert_v031($legacyReady['resolved_route']==='homeserver','legacy auto ready route changed');
gateway_assert_v031($legacyReady['resolved_label']==='HomeServer first','legacy label changed');
gateway_assert_v031($legacyReady['gateway_version']==='v0.31','legacy route must prove gateway delegation');
gateway_assert_v031($legacyReady['route_reason']==='homeserver_preferred','legacy route must expose reason');

$legacyOffline=agent_compute_v020_route_plan('auto',true,false);
gateway_assert_v031($legacyOffline['try_homeserver']===true,'legacy recovery attempt changed');
gateway_assert_v031($legacyOffline['resolved_route']==='vp3_cloud','legacy fallback route changed');

$legacyHomeOnly=agent_compute_v020_route_plan('homeserver_only',true,false);
gateway_assert_v031($legacyHomeOnly['blocked']===true,'legacy HomeServer-only block changed');
gateway_assert_v031($legacyHomeOnly['allow_vp3_fallback']===false,'legacy HomeServer-only fallback changed');

$encoded=json_encode($model,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
foreach(['relay_token','home-secret','bearer_token','provider_response','raw_error'] as $forbidden){
    gateway_assert_v031(!str_contains($encoded,$forbidden),'gateway plan leaked '.$forbidden);
}

print("VP3 v0.31 canonical AI Gateway tests passed\n");
