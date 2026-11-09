<?php
declare(strict_types=1);

require_once __DIR__.'/ai-service-usage-v1.php';

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
        $result=$provider==='openai'
            ?ai_curl_json('https://api.openai.com/v1/responses',['Authorization: Bearer '.$key,'Content-Type: application/json'],$payload,$timeout)
            :ai_curl_json('https://api.anthropic.com/v1/messages',['x-api-key: '.$key,'anthropic-version: 2023-06-01','Content-Type: application/json'],$payload,$timeout);
        $reported=$result['data']['usage']??null;
        $usage=is_array($reported)?vp3_ai_service_usage_tokens_v1($reported):null;
        if($metered&&$usage!==null){
            subscription_ai_commit_usage($reservation,$user,$scope,$provider,$model,$usage,'direct:'.bin2hex(random_bytes(16)));
            $reservation=0;
        }
        return $result;
    }catch(PDOException $error){
        throw new RuntimeException('Account AI quota or settings are unavailable.',503,$error);
    }finally{
        if($reservation>0)subscription_ai_release_reservation($reservation);
    }
}
