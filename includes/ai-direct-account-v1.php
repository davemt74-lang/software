<?php
declare(strict_types=1);

require_once __DIR__.'/ai-service-usage-v1.php';
require_once __DIR__.'/ai-usage-accounting-v032.php';

/** Observe the provider result independently of the subscription debit. */
function vp3_ai_direct_observe_v1(?PDO $pdo,array $user,array $route,string $scope,string $requestKey,array $result,float $started,?string $cloudRequestKey=null): string
{
    $owner=(int)($user['id']??0);
    if($owner<1||!function_exists('table_exists'))return '';
    try{
        $pdo??=function_exists('db')?db():null;
        if(!$pdo instanceof PDO)return '';
        // Public-profile callers supply an already authorized owner. Never
        // substitute the current visitor when that owner is unavailable.
        $q=$pdo->prepare('SELECT id FROM users WHERE id=?');$q->execute([$owner]);
        if((int)$q->fetchColumn()!==$owner)return '';
        $reported=is_array($result['data']['usage']??null)?$result['data']['usage']:[];
        $usage=vp3_ai_service_usage_tokens_v1($reported);
        $rejected=$usage===null&&empty($result['ok'])&&in_array((int)($result['status']??0),[400,401,403,404,422,429],true);
        $status=$rejected?'rejected':($usage===null?'unknown':(!empty($result['ok'])?'completed':'failed'));
        if($rejected)$usage=['input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0];
        $providerRequest=$result['data']['id']??'';
        ai_usage_accounting_v032_record($pdo,$user,0,0,[
            'source'=>$route['funding']==='own_key'?'user_provider':'vp3_cloud',
            'provider'=>$route['provider'],'model'=>$route['model'],'usage'=>$usage??[],
            'usage_known'=>$usage!==null,'usage_split_known'=>$rejected||!empty($usage['split_known']),
            'cloud_request_key'=>$cloudRequestKey??$requestKey,'service_key'=>'direct','service_stage'=>$scope,
            'service_status'=>$status,'credential_source'=>$route['funding'],
            // Server-generated per-attempt keys keep transport retries distinct.
            'service_attempt_key'=>hash('sha256',$requestKey),
            'provider_request_id'=>is_string($providerRequest)?$providerRequest:'',
            'latency_ms'=>max(0,(int)($result['duration_ms']??round((microtime(true)-$started)*1000))),
            'failure_class'=>$status==='completed'?'none':'provider_'.$status,
        ]);
        return hash('sha256',$requestKey);
    }catch(Throwable $error){
        error_log('VP3 direct AI execution observation failed: '.$error->getMessage());
        return '';
    }
}

/** System route for private default inference or an independently authorized public service. */
function vp3_ai_direct_system_route_v1(): array
{
    $provider=ai_active_provider();
    if(!in_array($provider,['openai','anthropic'],true)||!ai_provider_ready($provider)){
        throw new RuntimeException('Enable and configure OpenAI or Claude to use this AI feature.',503);
    }
    return ['funding'=>'system','provider'=>$provider,'model'=>ai_provider_model($provider),'api_key'=>ai_provider_api_key($provider)];
}

function vp3_ai_direct_account_route_v1(array $user): array
{
    $pdo=function_exists('db')?db():null;
    if((int)($user['id']??0)<1||!$pdo instanceof PDO){
        throw new RuntimeException('Account AI settings are unavailable.',503);
    }
    if(!function_exists('vp3_user_llm_v1_effective'))require_once __DIR__.'/user-llm-v1.php';
    try{$own=vp3_user_llm_v1_effective($pdo,$user);}
    catch(PDOException $error){throw new RuntimeException('Account AI settings are unavailable.',503,$error);}
    if(($own['route']??null)==='system')return vp3_ai_direct_system_route_v1();
    if(($own['route']??null)!=='own_key'||!in_array($own['provider']??null,['openai','anthropic'],true)
        ||empty($own['model'])||empty($own['api_key'])){
        throw new RuntimeException('Your saved provider key is unavailable. Re-enter it or select VP3 system inference.',503);
    }
    if(function_exists('subscription_has_entitlement')&&!subscription_has_entitlement($user,'main_ai.access')){
        throw new RuntimeException('AI access is not included for this account.',403);
    }
    return ['funding'=>'own_key','provider'=>$own['provider'],'model'=>$own['model'],'api_key'=>$own['api_key']];
}

/** Send the existing provider payload while reserving and settling system-funded private usage. */
function vp3_ai_direct_request_v1(array $user,array $route,string $scope,array $payload,int $timeout): array
{
    $provider=$route['provider'];
    $model=$route['model'];
    $key=$route['api_key'];
    $outputField=$provider==='openai'?'max_output_tokens':'max_tokens';
    $payload['model']=$model;
    $reservation=0;
    $pdo=null;$result=[];$started=null;$requestKey='direct:'.bin2hex(random_bytes(16));$observed=false;$observedKey='';$attempt=0;
    $private=$scope!=='profile_call_public';
    $metered=$private&&$route['funding']==='system'
        &&function_exists('subscription_ai_preflight')&&function_exists('subscription_ai_commit_usage')
        &&function_exists('subscription_ai_release_reservation');
    try{
        $pdo=$private?db():null;
        if($metered){
            $encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $estimate=subscription_estimate_tokens_from_chars(mb_strlen($encoded));
            if(!$pdo instanceof PDO||$pdo->inTransaction())throw new RuntimeException('Account AI quota is unavailable.',503);
            $pdo->beginTransaction();
            try{
                vp3_ai_service_usage_lock_owner_v1($pdo,(int)$user['id']);
                vp3_ai_service_usage_assert_funding_v1($pdo,$user,'system');
                $quota=subscription_ai_preflight($user,$scope,$estimate,(int)$payload[$outputField],$pdo);
                $reservation=(int)($quota['reservation_id']??0);
                $pdo->commit();
            }catch(Throwable $error){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $error;
            }
            $payload[$outputField]=min((int)$payload[$outputField],(int)$quota['max_output_tokens']);
        }elseif($private){
            if(!$pdo instanceof PDO)throw new RuntimeException('Account AI settings are unavailable.',503);
            vp3_ai_service_usage_assert_funding_v1($pdo,$user,$route['funding']);
            if($route['funding']==='own_key'){
                $current=vp3_user_llm_v1_effective($pdo,$user);
                if(($current['provider']??null)!==$provider||($current['model']??null)!==$model
                    ||!hash_equals($key,(string)($current['api_key']??''))){
                    throw new RuntimeException('The saved provider changed. Retry with your selected key.',403);
                }
            }
        }
        $started=microtime(true);
        $observer=static function(array $response)use($pdo,$user,$route,$scope,$requestKey,$started,&$observed,&$observedKey,&$attempt):void{
            $observed=true;$attempt++;
            $observedKey=vp3_ai_direct_observe_v1($pdo,$user,$route,$scope,$requestKey.':attempt:'.$attempt,$response,$started,'');
        };
        $result=$provider==='openai'
            ?ai_curl_json('https://api.openai.com/v1/responses',['Authorization: Bearer '.$key,'Content-Type: application/json'],$payload,$timeout,null,null,null,false,$observer)
            :ai_curl_json('https://api.anthropic.com/v1/messages',['x-api-key: '.$key,'anthropic-version: 2023-06-01','Content-Type: application/json'],$payload,$timeout,null,null,null,false,$observer);
        $reported=$result['data']['usage']??null;
        $usage=is_array($reported)?vp3_ai_service_usage_tokens_v1($reported):null;
        if($metered&&$usage!==null){
            subscription_ai_commit_usage($reservation,$user,$scope,$provider,$model,$usage,$requestKey);
            $reservation=0;
            if($observedKey!=='')ai_usage_accounting_v032_link_observation($pdo,$user,$observedKey,$requestKey);
        }
        return $result;
    }catch(PDOException $error){
        throw new RuntimeException('Account AI quota or settings are unavailable.',503,$error);
    }finally{
        if($reservation>0)subscription_ai_release_reservation($reservation);
        if($started!==null&&!$observed)vp3_ai_direct_observe_v1($pdo,$user,$route,$scope,$requestKey,$result,$started);
    }
}
