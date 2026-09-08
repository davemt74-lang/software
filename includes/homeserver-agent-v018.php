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

/**
 * v0.22 keeps one sanitized attempt snapshot in request memory so the canonical
 * chat execution object can explain a fallback without exposing relay errors,
 * credentials, provider bodies, prompts, or other private runtime details.
 */
function homeserver_agent_v018_set_last_attempt(array $attempt): void
{
    $allowedFailures=['none','homeserver_not_paired','relay_unreachable','timeout','authorization','provider_unavailable','empty_response','homeserver_unavailable'];
    $failure=trim((string)($attempt['failure_class']??'none'));
    if(!in_array($failure,$allowedFailures,true))$failure='homeserver_unavailable';
    $GLOBALS['vp3_homeserver_agent_last_attempt_v022']=[
        'attempted'=>!empty($attempt['attempted']),
        'success'=>!empty($attempt['success']),
        'latency_ms'=>max(0,(int)($attempt['latency_ms']??0)),
        'failure_class'=>$failure,
        'provider'=>mb_strimwidth(trim((string)($attempt['provider']??'')),0,80,''),
        'model'=>mb_strimwidth(trim((string)($attempt['model']??'')),0,160,''),
        'compute_source'=>mb_strimwidth(trim((string)($attempt['compute_source']??'')),0,80,''),
    ];
}

function homeserver_agent_v018_last_attempt(): array
{
    $attempt=$GLOBALS['vp3_homeserver_agent_last_attempt_v022']??null;
    return is_array($attempt)?$attempt:[
        'attempted'=>false,'success'=>false,'latency_ms'=>0,'failure_class'=>'none',
        'provider'=>'','model'=>'','compute_source'=>'',
    ];
}

function homeserver_agent_v018_failure_class(string $message): string
{
    $message=mb_strtolower($message);
    if(str_contains($message,'timed out')||str_contains($message,'timeout'))return 'timeout';
    if(str_contains($message,'401')||str_contains($message,'403')||str_contains($message,'unauthorized')||str_contains($message,'permission')||str_contains($message,'bearer'))return 'authorization';
    if(str_contains($message,'model')||str_contains($message,'provider')||str_contains($message,'inference'))return 'provider_unavailable';
    if(str_contains($message,'relay')||str_contains($message,'connection')||str_contains($message,'connect')||str_contains($message,'curl'))return 'relay_unreachable';
    return 'homeserver_unavailable';
}

function homeserver_agent_v018_chat(array $user,string $query,int $conversationId,bool $cloudAllowed=true): ?array
{
    $userId=(int)($user['id']??0);
    homeserver_agent_v018_set_last_attempt(['attempted'=>false,'success'=>false,'failure_class'=>'none']);
    if($userId<1||$conversationId<1||trim($query)===''||!function_exists('homeserver_vp3_remote_operation'))return null;
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials){
        homeserver_agent_v018_set_last_attempt(['attempted'=>false,'success'=>false,'failure_class'=>'homeserver_not_paired']);
        return null;
    }
    $payload=[
        'message'=>$query,
        'include_memory'=>true,
        'include_knowledge'=>true,
        'include_contacts'=>true,
        'cloud_allowed'=>$cloudAllowed,
        'max_context_chars'=>12000,
    ];
    $remoteId=homeserver_agent_v018_remote_id($userId,$conversationId);
    if($remoteId!=='')$payload['conversation_id']=$remoteId;
    $started=microtime(true);
    homeserver_agent_v018_set_last_attempt(['attempted'=>true,'success'=>false,'failure_class'=>'none']);
    try{
        $result=homeserver_vp3_remote_operation($credentials['relay'],'agent.chat',$payload,$credentials['home']);
    }catch(Throwable $e){
        $latency=max(0,(int)round((microtime(true)-$started)*1000));
        homeserver_agent_v018_set_last_attempt([
            'attempted'=>true,'success'=>false,'latency_ms'=>$latency,
            'failure_class'=>homeserver_agent_v018_failure_class($e->getMessage()),
        ]);
        if(function_exists('ai_v100_telemetry'))ai_v100_telemetry(['scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','status'=>'failed','service'=>'homeserver-v0.18']);
        return null;
    }
    $latency=max(0,(int)round((microtime(true)-$started)*1000));
    $reply=trim((string)($result['reply']??''));
    if($reply===''){
        homeserver_agent_v018_set_last_attempt(['attempted'=>true,'success'=>false,'latency_ms'=>$latency,'failure_class'=>'empty_response']);
        return null;
    }
    homeserver_agent_v018_bind($userId,$conversationId,$result);
    $attempt=[
        'attempted'=>true,'success'=>true,'latency_ms'=>$latency,'failure_class'=>'none',
        'provider'=>(string)($result['provider']??'homeserver'),
        'model'=>(string)($result['model']??''),
        'compute_source'=>(string)($result['compute_source']??'homeserver'),
    ];
    homeserver_agent_v018_set_last_attempt($attempt);
    if(function_exists('ai_v100_telemetry'))ai_v100_telemetry([
        'scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','model'=>(string)($result['model']??''),'status'=>'success','service'=>'homeserver-v0.18',
        'input_tokens'=>(int)($result['usage']['prompt_tokens']??0),'output_tokens'=>(int)($result['usage']['completion_tokens']??0),'total_tokens'=>(int)($result['usage']['total_tokens']??0),
    ]);
    return [
        'answer'=>$reply,
        'provider'=>(string)($result['provider']??'homeserver'),
        'model'=>(string)($result['model']??''),
        'compute_source'=>(string)($result['compute_source']??'homeserver'),
        'usage'=>is_array($result['usage']??null)?$result['usage']:[],
        'run_id'=>(int)($result['run_id']??0),
        'conversation_id'=>(string)($result['conversation_id']??''),
        'latency_ms'=>$latency,
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