<?php
declare(strict_types=1);

/** VP3 v0.18 — HomeServer-first Agent execution with per-chat continuity. */
function homeserver_agent_v018_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)return;
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
}

function homeserver_agent_v018_remote_id(int $userId,int $conversationId): string
{
    if($userId<1||$conversationId<1)return '';
    $pdo=db();if(!$pdo)return '';
    homeserver_agent_v018_ensure_schema($pdo);
    $stmt=$pdo->prepare('SELECT homeserver_conversation_id FROM homeserver_chat_sessions WHERE user_id=? AND vp3_conversation_id=? LIMIT 1');
    $stmt->execute([$userId,$conversationId]);
    return trim((string)$stmt->fetchColumn());
}

function homeserver_agent_v018_bind(int $userId,int $conversationId,array $result): void
{
    $remoteId=trim((string)($result['conversation_id']??''));
    if($userId<1||$conversationId<1||$remoteId===''||strlen($remoteId)>160)return;
    $pdo=db();if(!$pdo)return;
    homeserver_agent_v018_ensure_schema($pdo);
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
}

function homeserver_agent_v018_forget(int $userId,int $conversationId): void
{
    if($userId<1||$conversationId<1)return;
    $pdo=db();if(!$pdo)return;
    homeserver_agent_v018_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM homeserver_chat_sessions WHERE user_id=? AND vp3_conversation_id=?')->execute([$userId,$conversationId]);
}

function homeserver_agent_v018_credentials(int $userId): ?array
{
    $row=homeserver_vp3_connection($userId);
    if(!$row||empty($row['relay_token_enc'])||empty($row['homeserver_token_enc']))return null;
    try{
        $relay=homeserver_vp3_decrypt((string)$row['relay_token_enc']);
        $home=homeserver_vp3_decrypt((string)$row['homeserver_token_enc']);
    }catch(Throwable $e){return null;}
    if(strlen($relay)<20||strlen($home)<20)return null;
    return ['relay'=>$relay,'home'=>$home];
}

function homeserver_agent_v018_chat(array $user,string $query,int $conversationId): ?array
{
    $userId=(int)($user['id']??0);
    if($userId<1||$conversationId<1||trim($query)===''||!function_exists('homeserver_vp3_remote_operation'))return null;
    $credentials=homeserver_agent_v018_credentials($userId);
    if(!$credentials)return null;
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
        if(function_exists('ai_v100_telemetry'))ai_v100_telemetry(['scope'=>'chat','user_id'=>$userId,'provider'=>'homeserver','status'=>'failed','service'=>'homeserver-v0.18']);
        return null;
    }
    $reply=trim((string)($result['reply']??''));
    if($reply==='')return null;
    homeserver_agent_v018_bind($userId,$conversationId,$result);
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
    ];
}

/** Mirror the exact VP3 cloud ledger row to the paired HomeServer usage history. */
function homeserver_agent_v018_write_cloud_usage(array $user): void
{
    $userId=(int)($user['id']??0);
    if($userId<1||!function_exists('agent_runtime_v125_trace_id')||!function_exists('homeserver_vp3_remote_operation'))return;
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
    try{
        homeserver_vp3_remote_operation($credentials['relay'],'usage.write',$payload,$credentials['home']);
    }catch(Throwable $e){
        // Usage mirroring is best-effort and never turns a completed VP3 answer into an error.
    }
}
