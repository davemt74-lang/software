<?php
declare(strict_types=1);

/** VP3 v0.18 — HomeServer-first Agent execution with per-chat continuity. */
function homeserver_agent_v018_ensure_schema(?PDO $pdo=null): bool
{
    try{
        $pdo??=db();
        if(!$pdo)return false;
        $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_chat_sessions (
            user_id INT UNSIGNED NOT NULL,
            vp3_conversation_id BIGINT UNSIGNED NOT NULL,
            homeserver_conversation_id VARCHAR(160) NOT NULL,
            last_provider VARCHAR(80) NOT NULL DEFAULT '',
            last_model VARCHAR(160) NOT NULL DEFAULT '',
            last_compute_source VARCHAR(80) NOT NULL DEFAULT '',
            last_run_id BIGINT UNSIGNED NULL,
            last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id,vp3_conversation_id),
            INDEX idx_homeserver_chat_session_remote (homeserver_conversation_id),
            CONSTRAINT fk_homeserver_chat_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    }catch(Throwable $e){
        return false;
    }
}

function homeserver_agent_v018_remote_id(int $userId,int $conversationId): string
{
    if($userId<1||$conversationId<1)return '';
    try{
        $pdo=db();
        if(!$pdo||!homeserver_agent_v018_ensure_schema($pdo))return '';
        $stmt=$pdo->prepare('SELECT homeserver_conversation_id FROM homeserver_chat_sessions WHERE user_id=? AND vp3_conversation_id=? LIMIT 1');
        $stmt->execute([$userId,$conversationId]);
        return trim((string)$stmt->fetchColumn());
    }catch(Throwable $e){
        return '';
    }
}

function homeserver_agent_v018_bind(int $userId,int $conversationId,array $result): void
{
    $remoteId=trim((string)($result['conversation_id']??''));
    if($userId<1||$conversationId<1||$remoteId===''||strlen($remoteId)>160)return;
    try{
        $pdo=db();
        if(!$pdo||!homeserver_agent_v018_ensure_schema($pdo))return;
        $stmt=$pdo->prepare("INSERT INTO homeserver_chat_sessions
            (user_id,vp3_conversation_id,homeserver_conversation_id,last_provider,last_model,last_compute_source,last_run_id,last_used_at)
            VALUES (?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE homeserver_conversation_id=VALUES(homeserver_conversation_id),last_provider=VALUES(last_provider),last_model=VALUES(last_model),last_compute_source=VALUES(last_compute_source),last_run_id=VALUES(last_run_id),last_used_at=NOW()");
        $stmt->execute([
            $userId,$conversationId,$remoteId,
            mb_strimwidth((string)($result['provider']??''),0,80,''),
            mb_strimwidth((string)($result['model']??''),0,160,''),
            mb_strimwidth((string)($result['compute_source']??''),0,80,''),
            isset($result['run_id'])?(int)$result['run_id']:null,
        ]);
    }catch(Throwable $e){
        // Continuity metadata must never turn a successful Agent answer into an error.
    }
}

function homeserver_agent_v018_forget(int $userId,int $conversationId): void
{
    if($userId<1||$conversationId<1)return;
    try{
        $pdo=db();
        if(!$pdo||!homeserver_agent_v018_ensure_schema($pdo))return;
        $pdo->prepare('DELETE FROM homeserver_chat_sessions WHERE user_id=? AND vp3_conversation_id=?')->execute([$userId,$conversationId]);
    }catch(Throwable $e){
        // The canonical VP3 conversation delete still proceeds if metadata cleanup cannot run.
    }
}

function homeserver_agent_v018_credentials(int $userId): ?array
{
    if($userId<1)return null;
    try{
        $row=homeserver_vp3_connection($userId);
        if(!$row||empty($row['relay_token_enc'])||empty($row['homeserver_token_enc']))return null;
        $relay=homeserver_vp3_decrypt((string)$row['relay_token_enc']);
        $home=homeserver_vp3_decrypt((string)$row['homeserver_token_enc']);
    }catch(Throwable $e){
        return null;
    }
    if(strlen($relay)<20||strlen($home)<20)return null;
    return ['relay'=>$relay,'home'=>$home];
}

/** v0.19 safe, user-visible compute routing metadata. Never includes credentials or raw relay errors. */
function homeserver_agent_v019_usage(array $usage): array
{
    return [
        'prompt_tokens'=>max(0,(int)($usage['prompt_tokens']??$usage['input_tokens']??0)),
        'completion_tokens'=>max(0,(int)($usage['completion_tokens']??$usage['output_tokens']??0)),
        'total_tokens'=>max(0,(int)($usage['total_tokens']??0)),
    ];
}

function homeserver_agent_v019_fallback_label(string $reason): string
{
    return match($reason){
        'not_paired'=>'HomeServer not paired',
        'integration_unavailable'=>'HomeServer integration unavailable',
        'relay_unavailable'=>'HomeServer unavailable',
        'empty_reply'=>'HomeServer returned no reply',
        default=>'',
    };
}

function homeserver_agent_v019_route_label(string $computeSource): string
{
    return match($computeSource){
        'homeserver_local'=>'HomeServer Local',
        'user_provider'=>'Connected Provider',
        'vp3_cloud'=>'VP3 Cloud',
        'vp3_tool'=>'VP3 Tool',
        default=>'HomeServer',
    };
}

function homeserver_agent_v019_route(array $route): array
{
    $computeSource=mb_strimwidth(trim((string)($route['compute_source']??'')),0,80,'');
    $fallbackReason=mb_strimwidth(trim((string)($route['fallback_reason']??'')),0,80,'');
    $connected=$route['homeserver_connected']??null;
    return [
        'compute_source'=>$computeSource,
        'label'=>homeserver_agent_v019_route_label($computeSource),
        'provider'=>mb_strimwidth(trim((string)($route['provider']??'')),0,80,''),
        'model'=>mb_strimwidth(trim((string)($route['model']??'')),0,160,''),
        'homeserver_connected'=>is_bool($connected)?$connected:null,
        'fallback_reason'=>$fallbackReason!==''?$fallbackReason:null,
        'usage'=>homeserver_agent_v019_usage(is_array($route['usage']??null)?$route['usage']:[]),
        'cloud_tokens_debited'=>max(0,(int)($route['cloud_tokens_debited']??0)),
    ];
}

function homeserver_agent_v019_route_source(array $route): array
{
    $safe=homeserver_agent_v019_route($route);
    $parts=['Compute: '.(string)$safe['label']];
    $provider=(string)$safe['provider'];
    $model=(string)$safe['model'];
    if($provider!==''&&$model!=='')$parts[]=$provider.' / '.$model;
    elseif($model!=='')$parts[]=$model;
    elseif($provider!=='')$parts[]=$provider;
    $tokens=(int)($safe['usage']['total_tokens']??0);
    if($tokens>0)$parts[]=number_format($tokens).' token'.($tokens===1?'':'s');
    $fallback=homeserver_agent_v019_fallback_label((string)($safe['fallback_reason']??''));
    if($fallback!=='')$parts[]=$fallback;
    return ['source'=>'compute-routing:v019','title'=>implode(' · ',$parts)];
}

function homeserver_agent_v019_cloud_route(array $user,string $fallbackReason=''): array
{
    $route=[
        'compute_source'=>'vp3_cloud',
        'provider'=>'vp3-cloud',
        'model'=>'',
        'homeserver_connected'=>false,
        'fallback_reason'=>$fallbackReason,
        'usage'=>[],
        'cloud_tokens_debited'=>0,
    ];
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('agent_runtime_v125_trace_id'))return homeserver_agent_v019_route($route);
    try{
        $trace=trim((string)agent_runtime_v125_trace_id());
        $pdo=db();
        if($trace===''||!$pdo||!table_exists('ai_usage_ledger'))return homeserver_agent_v019_route($route);
        $stmt=$pdo->prepare('SELECT provider,model,input_tokens,output_tokens,total_tokens FROM ai_usage_ledger WHERE user_id=? AND trace_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$trace]);
        $usage=$stmt->fetch();
        if($usage){
            $route['provider']=(string)($usage['provider']??'vp3-cloud');
            $route['model']=(string)($usage['model']??'');
            $route['usage']=[
                'prompt_tokens'=>(int)($usage['input_tokens']??0),
                'completion_tokens'=>(int)($usage['output_tokens']??0),
                'total_tokens'=>(int)($usage['total_tokens']??0),
            ];
            $route['cloud_tokens_debited']=max(0,(int)($usage['total_tokens']??0));
        }
    }catch(Throwable $e){
        // Routing visibility is best-effort; never expose or propagate internal database details.
    }
    return homeserver_agent_v019_route($route);
}

function homeserver_agent_v019_tool_route(): array
{
    return homeserver_agent_v019_route([
        'compute_source'=>'vp3_tool',
        'provider'=>'vp3',
        'model'=>'',
        'homeserver_connected'=>null,
        'usage'=>[],
        'cloud_tokens_debited'=>0,
    ]);
}

function homeserver_agent_v018_chat(array $user,string $query,int $conversationId,?string &$fallbackReason=null): ?array
{
    $fallbackReason='';
    $userId=(int)($user['id']??0);
    if($userId<1||$conversationId<1||trim($query)==='')return null;
    if(!function_exists('homeserver_vp3_remote_operation')){
        $fallbackReason='integration_unavailable';
        return null;
    }
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials){
        $fallbackReason='not_paired';
        return null;
    }
    $payload=[
        'message'=>$query,
        'include_memory'=>true,
        'include_knowledge'=>true,
        'include_contacts'=>true,
        'cloud_allowed'=>true,
        'max_context_chars'=>12000,
    ];
    $remoteId=homeserver_agent_v018_remote_id($userId,$conversationId);
    if($remoteId!=='')$payload['conversation_id']=$remoteId;
    try{
        $result=homeserver_vp3_remote_operation($credentials['relay'],'agent.chat',$payload,$credentials['home']);
    }catch(Throwable $e){
        $fallbackReason='relay_unavailable';
        if(function_exists('ai_v100_telemetry'))ai_v100_telemetry(['scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','status'=>'failed','service'=>'homeserver-v0.18']);
        return null;
    }
    $reply=trim((string)($result['reply']??''));
    if($reply===''){
        $fallbackReason='empty_reply';
        return null;
    }
    homeserver_agent_v018_bind($userId,$conversationId,$result);
    if(function_exists('ai_v100_telemetry'))ai_v100_telemetry([
        'scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','model'=>(string)($result['model']??''),'status'=>'success','service'=>'homeserver-v0.18',
        'input_tokens'=>(int)($result['usage']['prompt_tokens']??0),'output_tokens'=>(int)($result['usage']['completion_tokens']??0),'total_tokens'=>(int)($result['usage']['total_tokens']??0),
    ]);
    $route=homeserver_agent_v019_route([
        'compute_source'=>(string)($result['compute_source']??'homeserver'),
        'provider'=>(string)($result['provider']??'homeserver'),
        'model'=>(string)($result['model']??''),
        'homeserver_connected'=>true,
        'fallback_reason'=>'',
        'usage'=>is_array($result['usage']??null)?$result['usage']:[],
        'cloud_tokens_debited'=>(int)($result['cloud_tokens_debited']??0),
    ]);
    return [
        'answer'=>$reply,
        'provider'=>(string)$route['provider'],
        'model'=>(string)$route['model'],
        'compute_source'=>(string)$route['compute_source'],
        'usage'=>$route['usage'],
        'cloud_tokens_debited'=>(int)$route['cloud_tokens_debited'],
        'routing'=>$route,
        'run_id'=>(int)($result['run_id']??0),
        'conversation_id'=>(string)($result['conversation_id']??''),
    ];
}

/** Mirror the exact VP3 cloud ledger row to the paired HomeServer usage history. */
function homeserver_agent_v018_write_cloud_usage(array $user): void
{
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('agent_runtime_v125_trace_id')||!function_exists('homeserver_vp3_remote_operation'))return;
    try{
        $trace=trim((string)agent_runtime_v125_trace_id());
        if($trace==='')return;
        $pdo=db();
        if(!$pdo||!table_exists('ai_usage_ledger'))return;
        $stmt=$pdo->prepare('SELECT id,scope,provider,model,input_tokens,output_tokens,total_tokens FROM ai_usage_ledger WHERE user_id=? AND trace_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId,$trace]);
        $usage=$stmt->fetch();
        if(!$usage)return;
        $credentials=homeserver_agent_v018_credentials($userId);
        if(!$credentials)return;
        $balance=null;
        if(function_exists('subscription_ai_balance')){
            $state=subscription_ai_balance($user);
            if(empty($state['unlimited']))$balance=max(0,(int)($state['remaining']??0));
        }
        $payload=[
            'event_id'=>'vp3-ledger:'.(int)$usage['id'],
            'provider_key'=>mb_strimwidth((string)$usage['provider'],0,80,''),
            'model'=>mb_strimwidth((string)$usage['model'],0,200,''),
            'request_kind'=>mb_strimwidth((string)$usage['scope'],0,80,''),
            'prompt_tokens'=>max(0,(int)$usage['input_tokens']),
            'completion_tokens'=>max(0,(int)$usage['output_tokens']),
            'total_tokens'=>max(0,(int)$usage['total_tokens']),
            'billable_tokens'=>max(0,(int)$usage['total_tokens']),
        ];
        if($balance!==null)$payload['balance_after_tokens']=$balance;
        homeserver_vp3_remote_operation($credentials['relay'],'usage.write',$payload,$credentials['home']);
    }catch(Throwable $e){
        // Usage mirroring is best-effort and never turns a completed VP3 answer into an error.
    }
}
