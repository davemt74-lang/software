<?php
declare(strict_types=1);

/**
 * VP3 v0.19+ — normalized, persistence-safe Agent execution provenance.
 *
 * v0.22 extends the same canonical execution object with sanitized latency,
 * failure classification and cloud-balance transparency. It never carries
 * credentials, raw relay errors, provider responses, prompts, or private
 * HomeServer payloads.
 */
function chat_execution_v019_usage(array $usage): array
{
    $prompt=max(0,(int)($usage['prompt_tokens']??$usage['input_tokens']??0));
    $completion=max(0,(int)($usage['completion_tokens']??$usage['output_tokens']??0));
    $total=max(0,(int)($usage['total_tokens']??($prompt+$completion)));
    return [
        'prompt_tokens'=>$prompt,
        'completion_tokens'=>$completion,
        'total_tokens'=>$total,
    ];
}

function chat_execution_v019_base(
    string $source,
    string $label,
    string $provider='',
    string $model='',
    string $homeserver='not_used',
    bool $fallbackUsed=false,
    string $fallbackReason='none',
    array $usage=[],
    int $runId=0,
    int $cloudTokensDebited=0,
    int $latencyMs=0,
    string $failureClass='none',
    ?int $cloudBalanceRemaining=null,
    bool $cloudBalanceUnlimited=false
): array {
    $allowedSources=['homeserver_local','user_provider','vp3_cloud','vp3_retrieval','vp3_tool'];
    $allowedHome=['connected','unavailable','not_paired','not_used'];
    $allowedReasons=['none','homeserver_not_paired','homeserver_unavailable','provider_unavailable','local_retrieval'];
    $allowedFailures=['none','homeserver_not_paired','homeserver_offline','relay_unreachable','timeout','authorization','provider_unavailable','empty_response','homeserver_unavailable'];
    if(!in_array($source,$allowedSources,true))$source='vp3_retrieval';
    if(!in_array($homeserver,$allowedHome,true))$homeserver='not_used';
    if(!in_array($fallbackReason,$allowedReasons,true))$fallbackReason='none';
    if(!in_array($failureClass,$allowedFailures,true))$failureClass='homeserver_unavailable';
    return [
        'route_version'=>'v0.19',
        'runtime_version'=>'v0.22',
        'source'=>$source,
        'label'=>mb_strimwidth(trim($label),0,80,''),
        'provider'=>mb_strimwidth(trim($provider),0,80,''),
        'model'=>mb_strimwidth(trim($model),0,160,''),
        'homeserver'=>$homeserver,
        'fallback_used'=>$fallbackUsed,
        'fallback_reason'=>$fallbackReason,
        'failure_class'=>$failureClass,
        'latency_ms'=>max(0,$latencyMs),
        'usage'=>chat_execution_v019_usage($usage),
        'cloud_tokens_debited'=>max(0,$cloudTokensDebited),
        'cloud_balance_remaining'=>$cloudBalanceUnlimited?null:($cloudBalanceRemaining===null?null:max(0,$cloudBalanceRemaining)),
        'cloud_balance_unlimited'=>$cloudBalanceUnlimited,
        'run_id'=>max(0,$runId),
    ];
}

function chat_execution_v019_cloud_balance(array $user): array
{
    if(!function_exists('subscription_ai_balance'))return ['unlimited'=>false,'remaining'=>null];
    try{
        $balance=subscription_ai_balance($user);
        $unlimited=!empty($balance['unlimited']);
        return [
            'unlimited'=>$unlimited,
            'remaining'=>$unlimited?null:max(0,(int)($balance['remaining']??0)),
        ];
    }catch(Throwable $e){
        return ['unlimited'=>false,'remaining'=>null];
    }
}

function chat_execution_v019_homeserver(array $result): array
{
    $compute=trim((string)($result['compute_source']??''));
    $provider=trim((string)($result['provider']??'homeserver'));
    $model=trim((string)($result['model']??''));
    $usage=is_array($result['usage']??null)?$result['usage']:[];
    $runId=(int)($result['run_id']??0);
    $cloudDebit=(int)($result['cloud_tokens_debited']??0);
    $latency=max(0,(int)($result['latency_ms']??0));
    if($compute==='user_provider'){
        return chat_execution_v019_base(
            'user_provider','Connected Provider',$provider,$model,'connected',false,'none',$usage,$runId,$cloudDebit,$latency,'none'
        );
    }
    return chat_execution_v019_base(
        'homeserver_local','HomeServer Local',$provider,$model,'connected',false,'none',$usage,$runId,$cloudDebit,$latency,'none'
    );
}

function chat_execution_v019_cloud_ledger(array $user): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('agent_runtime_v125_trace_id'))return null;
    $trace=trim((string)agent_runtime_v125_trace_id());
    if($trace==='')return null;
    try{
        $pdo=db();
        if(!$pdo||!table_exists('ai_usage_ledger'))return null;
        $stmt=$pdo->prepare('SELECT id,provider,model,input_tokens,output_tokens,total_tokens FROM ai_usage_ledger WHERE user_id=? AND trace_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$trace]);
        $row=$stmt->fetch();
        return $row?:null;
    }catch(Throwable $e){
        return null;
    }
}

/** Direct VP3 route selected by v0.20 — never presented as a HomeServer fallback. */
function chat_execution_v019_vp3_direct(array $user): array
{
    $ledger=chat_execution_v019_cloud_ledger($user);
    $balance=chat_execution_v019_cloud_balance($user);
    if($ledger){
        $total=max(0,(int)($ledger['total_tokens']??0));
        return chat_execution_v019_base(
            'vp3_cloud','VP3 Cloud',(string)($ledger['provider']??''),(string)($ledger['model']??''),'not_used',false,'none',
            [
                'input_tokens'=>(int)($ledger['input_tokens']??0),
                'output_tokens'=>(int)($ledger['output_tokens']??0),
                'total_tokens'=>$total,
            ],0,$total,0,'none',$balance['remaining'],!empty($balance['unlimited'])
        );
    }
    return chat_execution_v019_base(
        'vp3_retrieval','VP3 Retrieval','local','','not_used',false,'none',[],0,0,0,'none',$balance['remaining'],!empty($balance['unlimited'])
    );
}

function chat_execution_v019_fallback(array $user,bool $homePaired,bool $homeAttempted=true): array
{
    $ledger=chat_execution_v019_cloud_ledger($user);
    $balance=chat_execution_v019_cloud_balance($user);
    $homeState=$homePaired?($homeAttempted?'unavailable':'not_used'):'not_paired';
    $fallbackReason=$homePaired?($homeAttempted?'homeserver_unavailable':'none'):'homeserver_not_paired';
    $attempt=function_exists('homeserver_agent_v018_last_attempt')?homeserver_agent_v018_last_attempt():[];
    $failureClass=!$homePaired?'homeserver_not_paired':trim((string)($attempt['failure_class']??''));
    if($homePaired&&$homeAttempted&&($failureClass===''||$failureClass==='none'))$failureClass='homeserver_unavailable';
    if(!$homeAttempted)$failureClass='none';
    $latency=$homeAttempted?max(0,(int)($attempt['latency_ms']??0)):0;
    if($ledger){
        $total=max(0,(int)($ledger['total_tokens']??0));
        return chat_execution_v019_base(
            'vp3_cloud','VP3 Cloud',(string)($ledger['provider']??''),(string)($ledger['model']??''),
            $homeState,true,$fallbackReason,
            [
                'input_tokens'=>(int)($ledger['input_tokens']??0),
                'output_tokens'=>(int)($ledger['output_tokens']??0),
                'total_tokens'=>$total,
            ],0,$total,$latency,$failureClass,$balance['remaining'],!empty($balance['unlimited'])
        );
    }
    return chat_execution_v019_base(
        'vp3_retrieval','VP3 Retrieval','local','',$homeState,true,
        $fallbackReason==='none'?'local_retrieval':$fallbackReason,[],0,0,$latency,$failureClass,$balance['remaining'],!empty($balance['unlimited'])
    );
}

function chat_execution_v019_tool(): array
{
    return chat_execution_v019_base('vp3_tool','VP3 Tool','vp3','','not_used',false,'none',[]);
}

function chat_execution_v019_fallback_label(string $reason): string
{
    return match($reason){
        'homeserver_not_paired'=>'HomeServer not paired',
        'homeserver_unavailable'=>'HomeServer unavailable',
        'provider_unavailable'=>'Provider unavailable',
        'local_retrieval'=>'Local retrieval',
        default=>'',
    };
}

function chat_execution_v019_failure_label(string $failure): string
{
    return match($failure){
        'homeserver_not_paired'=>'HomeServer not paired',
        'homeserver_offline'=>'HomeServer offline',
        'relay_unreachable'=>'HomeServer relay unreachable',
        'timeout'=>'HomeServer timeout',
        'authorization'=>'HomeServer authorization issue',
        'provider_unavailable'=>'HomeServer model/provider unavailable',
        'empty_response'=>'HomeServer returned no answer',
        'homeserver_unavailable'=>'HomeServer unavailable',
        default=>'',
    };
}

/**
 * Reuse Chat's existing source-chip renderer so runtime truth is visible on the
 * immediate response, after reload, and through messages_after without adding
 * a parallel chat UI or persistence layer.
 */
function chat_execution_v019_source(array $execution): array
{
    $safe=chat_execution_v019_base(
        (string)($execution['source']??''),
        (string)($execution['label']??''),
        (string)($execution['provider']??''),
        (string)($execution['model']??''),
        (string)($execution['homeserver']??'not_used'),
        !empty($execution['fallback_used']),
        (string)($execution['fallback_reason']??'none'),
        is_array($execution['usage']??null)?$execution['usage']:[],
        (int)($execution['run_id']??0),
        (int)($execution['cloud_tokens_debited']??0),
        (int)($execution['latency_ms']??0),
        (string)($execution['failure_class']??'none'),
        array_key_exists('cloud_balance_remaining',$execution)&&$execution['cloud_balance_remaining']!==null?(int)$execution['cloud_balance_remaining']:null,
        !empty($execution['cloud_balance_unlimited'])
    );
    $parts=['Compute: '.((string)$safe['label']!==''?(string)$safe['label']:'VP3')];
    $provider=(string)$safe['provider'];
    $model=(string)$safe['model'];
    if($provider!==''&&$model!=='')$parts[]=$provider.' / '.$model;
    elseif($model!=='')$parts[]=$model;
    elseif($provider!=='')$parts[]=$provider;
    $latency=(int)$safe['latency_ms'];
    if($latency>0)$parts[]=number_format($latency).' ms HomeServer';
    $tokens=(int)($safe['usage']['total_tokens']??0);
    $cloudDebit=(int)$safe['cloud_tokens_debited'];
    if((string)$safe['source']==='vp3_cloud'&&$cloudDebit>0)$parts[]=number_format($cloudDebit).' VP3 tokens charged';
    elseif($tokens>0)$parts[]=number_format($tokens).' token'.($tokens===1?'':'s');
    $failure=chat_execution_v019_failure_label((string)$safe['failure_class']);
    $fallback=chat_execution_v019_fallback_label((string)$safe['fallback_reason']);
    if(!empty($safe['fallback_used'])&&$failure!=='')$parts[]=$failure.' → fallback';
    elseif($fallback!=='')$parts[]=$fallback;
    if((string)$safe['source']==='vp3_cloud'){
        if(!empty($safe['cloud_balance_unlimited']))$parts[]='Unlimited VP3 plan';
        elseif($safe['cloud_balance_remaining']!==null)$parts[]=number_format((int)$safe['cloud_balance_remaining']).' VP3 tokens left';
    }
    return [
        'source'=>'compute-routing:v019',
        'title'=>implode(' · ',$parts),
    ];
}