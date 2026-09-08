<?php
declare(strict_types=1);

/**
 * VP3 v0.19 — normalized, persistence-safe Agent execution provenance.
 *
 * This metadata is intentionally coarse. It never carries credentials, raw
 * relay errors, provider responses, prompts, or other private runtime detail.
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
    int $cloudTokensDebited=0
): array {
    $allowedSources=['homeserver_local','user_provider','vp3_cloud','vp3_retrieval','vp3_tool'];
    $allowedHome=['connected','unavailable','not_paired','not_used'];
    $allowedReasons=['none','homeserver_not_paired','homeserver_unavailable','provider_unavailable','local_retrieval'];
    if(!in_array($source,$allowedSources,true))$source='vp3_retrieval';
    if(!in_array($homeserver,$allowedHome,true))$homeserver='not_used';
    if(!in_array($fallbackReason,$allowedReasons,true))$fallbackReason='none';
    return [
        'route_version'=>'v0.19',
        'source'=>$source,
        'label'=>mb_strimwidth(trim($label),0,80,''),
        'provider'=>mb_strimwidth(trim($provider),0,80,''),
        'model'=>mb_strimwidth(trim($model),0,160,''),
        'homeserver'=>$homeserver,
        'fallback_used'=>$fallbackUsed,
        'fallback_reason'=>$fallbackReason,
        'usage'=>chat_execution_v019_usage($usage),
        'cloud_tokens_debited'=>max(0,$cloudTokensDebited),
        'run_id'=>max(0,$runId),
    ];
}

function chat_execution_v019_homeserver(array $result): array
{
    $compute=trim((string)($result['compute_source']??''));
    $provider=trim((string)($result['provider']??'homeserver'));
    $model=trim((string)($result['model']??''));
    $usage=is_array($result['usage']??null)?$result['usage']:[];
    $runId=(int)($result['run_id']??0);
    $cloudDebit=(int)($result['cloud_tokens_debited']??0);
    if($compute==='user_provider'){
        return chat_execution_v019_base(
            'user_provider','Connected Provider',$provider,$model,'connected',false,'none',$usage,$runId,$cloudDebit
        );
    }
    return chat_execution_v019_base(
        'homeserver_local','HomeServer Local',$provider,$model,'connected',false,'none',$usage,$runId,$cloudDebit
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
    if($ledger){
        $total=max(0,(int)($ledger['total_tokens']??0));
        return chat_execution_v019_base(
            'vp3_cloud','VP3 Cloud',(string)($ledger['provider']??''),(string)($ledger['model']??''),'not_used',false,'none',
            [
                'input_tokens'=>(int)($ledger['input_tokens']??0),
                'output_tokens'=>(int)($ledger['output_tokens']??0),
                'total_tokens'=>$total,
            ],0,$total
        );
    }
    return chat_execution_v019_base(
        'vp3_retrieval','VP3 Retrieval','local','','not_used',false,'none',[]
    );
}

function chat_execution_v019_fallback(array $user,bool $homePaired,bool $homeAttempted=true): array
{
    $ledger=chat_execution_v019_cloud_ledger($user);
    $homeState=$homePaired?($homeAttempted?'unavailable':'not_used'):'not_paired';
    $fallbackReason=$homePaired?($homeAttempted?'homeserver_unavailable':'none'):'homeserver_not_paired';
    if($ledger){
        $total=max(0,(int)($ledger['total_tokens']??0));
        return chat_execution_v019_base(
            'vp3_cloud','VP3 Cloud',(string)($ledger['provider']??''),(string)($ledger['model']??''),
            $homeState,true,$fallbackReason,
            [
                'input_tokens'=>(int)($ledger['input_tokens']??0),
                'output_tokens'=>(int)($ledger['output_tokens']??0),
                'total_tokens'=>$total,
            ],0,$total
        );
    }
    return chat_execution_v019_base(
        'vp3_retrieval','VP3 Retrieval','local','',$homeState,true,
        $fallbackReason==='none'?'local_retrieval':$fallbackReason,[]
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

/**
 * Reuse Chat's existing source-chip renderer so routing is visible immediately,
 * after reload, and through messages_after without adding a parallel UI runtime.
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
        (int)($execution['cloud_tokens_debited']??0)
    );
    $parts=['Compute: '.((string)$safe['label']!==''?(string)$safe['label']:'VP3')];
    $provider=(string)$safe['provider'];
    $model=(string)$safe['model'];
    if($provider!==''&&$model!=='')$parts[]=$provider.' / '.$model;
    elseif($model!=='')$parts[]=$model;
    elseif($provider!=='')$parts[]=$provider;
    $tokens=(int)($safe['usage']['total_tokens']??0);
    if($tokens>0)$parts[]=number_format($tokens).' token'.($tokens===1?'':'s');
    $fallback=chat_execution_v019_fallback_label((string)$safe['fallback_reason']);
    if($fallback!=='')$parts[]=$fallback;
    return [
        'source'=>'compute-routing:v019',
        'title'=>implode(' · ',$parts),
    ];
}