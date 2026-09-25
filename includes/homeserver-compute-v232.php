<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 3 — canonical compute receipts across v4.20 routing.
 */
const VP3_HOMESERVER_COMPUTE_V232='vp3-homeserver-compute-v232-20260925';

function homeserver_compute_v232_policy(): array
{
    return function_exists('homeserver_execution_v230_policy')
        ? homeserver_execution_v230_policy('agent.chat',[])
        : ['version'=>'2.3','operation'=>'agent.chat','domain'=>'agent_compute','fallback_allowed'=>true,'write_or_physical'=>false];
}

function homeserver_compute_v232_route(array $homeResult): string
{
    return match(trim((string)($homeResult['compute_source']??''))){
        'homeserver_local'=>'homeserver_local',
        'user_provider'=>'homeserver_user_provider',
        'vp3_cloud'=>'vp3_cloud_via_homeserver',
        default=>'homeserver',
    };
}

function homeserver_compute_v232_meta(array $source): array
{
    $meta=[];
    foreach(['provider','model','compute_source','run_id','cloud_tokens_debited'] as $key){
        if(!array_key_exists($key,$source)||is_array($source[$key])||is_object($source[$key]))continue;
        $value=$source[$key];
        if(is_string($value))$value=mb_strimwidth(trim($value),0,160,'');
        if(in_array($key,['run_id','cloud_tokens_debited'],true))$value=max(0,(int)$value);
        $meta[$key]=$value;
    }
    return $meta;
}

function homeserver_compute_v232_attempt(): array
{
    $attempt=function_exists('homeserver_agent_v018_last_attempt')?homeserver_agent_v018_last_attempt():[];
    return [
      'attempted'=>!empty($attempt['attempted']),
      'success'=>!empty($attempt['success']),
      'latency_ms'=>max(0,(int)($attempt['latency_ms']??0)),
      'failure_class'=>trim((string)($attempt['failure_class']??'none'))?:'none',
      'provider'=>mb_strimwidth(trim((string)($attempt['provider']??'')),0,80,''),
      'model'=>mb_strimwidth(trim((string)($attempt['model']??'')),0,160,''),
      'compute_source'=>mb_strimwidth(trim((string)($attempt['compute_source']??'')),0,80,''),
    ];
}

function homeserver_compute_v232_write(
    int $userId,string $route,string $status,bool $fallback,string $failure,int $duration,array $meta=[]
): ?array {
    if($userId<1||!function_exists('homeserver_execution_v230_receipt'))return null;
    try{
        return homeserver_execution_v230_receipt(
          $userId,bin2hex(random_bytes(16)),homeserver_compute_v232_policy(),
          $route,$status,$fallback,$failure,max(0,$duration),$meta
        );
    }catch(Throwable $e){return null;}
}

function homeserver_compute_v232_success(int $userId,array $homeResult): ?array
{
    return homeserver_compute_v232_write(
      $userId,
      homeserver_compute_v232_route($homeResult),
      'completed',
      false,
      'none',
      max(0,(int)($homeResult['latency_ms']??0)),
      homeserver_compute_v232_meta($homeResult)
    );
}

function homeserver_compute_v232_failure(int $userId): ?array
{
    $attempt=homeserver_compute_v232_attempt();
    if(empty($attempt['attempted']))return null;
    return homeserver_compute_v232_write(
      $userId,'homeserver','failed',false,(string)$attempt['failure_class'],
      (int)$attempt['latency_ms'],homeserver_compute_v232_meta($attempt)
    );
}

function homeserver_compute_v232_fallback(int $userId,array $execution): ?array
{
    $attempt=homeserver_compute_v232_attempt();
    if(empty($attempt['attempted']))return null;
    $meta=homeserver_compute_v232_meta($attempt);
    $meta['fallback_source']=mb_strimwidth(trim((string)($execution['source']??'')),0,80,'');
    $meta['fallback_actual_route']=mb_strimwidth(trim((string)($execution['actual_route']??'')),0,100,'');
    return homeserver_compute_v232_write(
      $userId,'cloud_fallback','completed',true,(string)$attempt['failure_class'],
      (int)$attempt['latency_ms'],$meta
    );
}

function homeserver_compute_v232_attach(
    int $userId,array $execution,?array $homeResult,bool $homeAttempted
): array {
    $receipt=null;
    if($homeResult)$receipt=homeserver_compute_v232_success($userId,$homeResult);
    elseif($homeAttempted)$receipt=homeserver_compute_v232_fallback($userId,$execution);
    if($receipt)$execution['homeserver_compute_receipt']=$receipt;
    return $execution;
}
