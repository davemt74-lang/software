<?php
declare(strict_types=1);

/**
 * HomeServer v2.3 Section 1 — canonical local execution contract + receipts.
 *
 * This layer does not create a second tool engine. It normalizes routing through
 * the established paired HTTPS relay, records only sanitized provenance, and
 * permits Cloud fallback only for explicitly read/compute-safe operations.
 */
const VP3_HOMESERVER_EXECUTION_V230='vp3-homeserver-execution-v230-20260924';
const VP3_HOMESERVER_EXECUTION_VERSION='2.3';

function homeserver_execution_v230_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= function_exists('db') ? db() : null;
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_execution_receipts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      request_id CHAR(32) NOT NULL,
      domain_key VARCHAR(40) NOT NULL,
      operation_key VARCHAR(100) NOT NULL,
      route_key VARCHAR(40) NOT NULL DEFAULT 'homeserver',
      status_key VARCHAR(40) NOT NULL,
      fallback_used TINYINT(1) NOT NULL DEFAULT 0,
      failure_class VARCHAR(80) NOT NULL DEFAULT 'none',
      duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
      result_meta_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_hs_exec_receipts_user (user_id,created_at,id),
      INDEX idx_hs_exec_receipts_request (request_id),
      CONSTRAINT fk_hs_exec_receipts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_execution_v230_schema_ready(): bool
{
    return function_exists('table_exists') && table_exists('homeserver_execution_receipts');
}

function homeserver_execution_v230_operation(string $operation): string
{
    $op=trim($operation);
    return $op==='tools.execute'?'tool.execute':$op;
}

function homeserver_execution_v230_domain(string $operation,array $payload=[]): string
{
    $op=homeserver_execution_v230_operation($operation);
    if(in_array($op,['agent.chat','agent.infer.local','inference.status'],true))return 'agent_compute';
    if(str_starts_with($op,'files.'))return 'files';
    if($op==='knowledge.search')return 'knowledge';
    if(str_starts_with($op,'speech.'))return 'voice';
    if(str_starts_with($op,'action.'))return 'governance';
    if($op==='tool.execute'){
        $tool=strtolower(trim((string)($payload['tool_key']??'')));
        if(str_starts_with($tool,'devices.'))return 'devices';
        if(str_starts_with($tool,'files.'))return 'files';
        if($tool==='knowledge.search')return 'knowledge';
        return 'tools';
    }
    if($op==='tools.list'||$op==='skills.list'||$op==='capability.registry')return 'tools';
    return 'system';
}

function homeserver_execution_v230_policy(string $operation,array $payload=[]): array
{
    $op=homeserver_execution_v230_operation($operation);
    $domain=homeserver_execution_v230_domain($op,$payload);
    $readSafe=[
      'agent.chat','agent.infer.local','inference.status','capabilities','capability.registry','speech.status',
      'knowledge.search','files.list','files.read','tools.list','skills.list',
      'tasks.list','notifications.list','shared.context.exchange','system.ping',
    ];
    $fallbackAllowed=in_array($op,$readSafe,true);
    if($op==='tool.execute'){
        $tool=strtolower(trim((string)($payload['tool_key']??'')));
        $fallbackAllowed=in_array($tool,['contacts.search','files.list','files.read','knowledge.search','memory.list','tasks.list','notifications.list','devices.list'],true);
    }
    return [
      'version'=>VP3_HOMESERVER_EXECUTION_VERSION,
      'operation'=>$op,
      'domain'=>$domain,
      'fallback_allowed'=>$fallbackAllowed,
      'write_or_physical'=>!$fallbackAllowed,
    ];
}

function homeserver_execution_v230_failure_class(Throwable $e): string
{
    $message=mb_strtolower($e->getMessage());
    if(str_contains($message,'timeout')||str_contains($message,'timed out'))return 'timeout';
    if(str_contains($message,'401')||str_contains($message,'403')||str_contains($message,'authorization')||str_contains($message,'bearer')||str_contains($message,'permission'))return 'authorization';
    if(str_contains($message,'offline')||str_contains($message,'not connected'))return 'homeserver_offline';
    if(str_contains($message,'relay')||str_contains($message,'connect')||str_contains($message,'unreachable'))return 'relay_unreachable';
    if(str_contains($message,'provider')||str_contains($message,'model')||str_contains($message,'inference'))return 'provider_unavailable';
    return 'homeserver_unavailable';
}

function homeserver_execution_v230_result_meta(mixed $result): array
{
    if(!is_array($result))return ['result_type'=>get_debug_type($result)];
    $meta=['result_keys'=>array_slice(array_values(array_filter(array_keys($result),'is_string')),0,20)];
    foreach(['run_id','count','request_id','status','approval_required','owner_approval_required','action','action_key','local','provider','model','bytes','content_type','voice','voice_source','stateless','tools_enabled'] as $key){
        if(!array_key_exists($key,$result)||is_array($result[$key])||is_object($result[$key]))continue;
        $value=$result[$key];
        if(is_string($value))$value=mb_strimwidth($value,0,160,'');
        $meta[$key]=$value;
    }
    if(isset($result['items'])&&is_array($result['items']))$meta['item_count']=count($result['items']);
    if(isset($result['result'])&&is_array($result['result'])){
        if(isset($result['result']['items'])&&is_array($result['result']['items']))$meta['item_count']=count($result['result']['items']);
        foreach(['count','created','executed','request_id'] as $key){
            if(isset($result['result'][$key])&&!is_array($result['result'][$key])&&!is_object($result['result'][$key]))$meta[$key]=$result['result'][$key];
        }
    }
    return $meta;
}

function homeserver_execution_v230_receipt(
    int $userId,string $requestId,array $policy,string $route,string $status,
    bool $fallbackUsed,string $failureClass,int $durationMs,array $resultMeta=[]
): array {
    $receipt=[
      'version'=>VP3_HOMESERVER_EXECUTION_VERSION,
      'request_id'=>$requestId,
      'domain'=>(string)$policy['domain'],
      'operation'=>(string)$policy['operation'],
      'route'=>$route,
      'status'=>$status,
      'fallback_used'=>$fallbackUsed,
      'failure_class'=>$failureClass,
      'duration_ms'=>max(0,$durationMs),
      'result_meta'=>$resultMeta,
    ];
    try{
        $pdo=function_exists('db')?db():null;
        if($pdo){
            homeserver_execution_v230_ensure_schema($pdo);
            $stmt=$pdo->prepare("INSERT INTO homeserver_execution_receipts
              (user_id,request_id,domain_key,operation_key,route_key,status_key,fallback_used,failure_class,duration_ms,result_meta_json)
              VALUES (?,?,?,?,?,?,?,?,?,?)");
            $json=json_encode($resultMeta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $stmt->execute([
              $userId,$requestId,(string)$policy['domain'],(string)$policy['operation'],$route,$status,
              $fallbackUsed?1:0,$failureClass,max(0,$durationMs),is_string($json)?$json:'{}',
            ]);
            $receipt['id']=(int)$pdo->lastInsertId();
        }
    }catch(Throwable $ignored){
        // Provenance persistence must never turn completed execution into a user-facing failure.
    }
    return $receipt;
}

function homeserver_execution_v230_recent(int $userId,int $limit=20): array
{
    if($userId<1||!function_exists('db'))return [];
    try{
        $pdo=db();if(!$pdo)return [];
        homeserver_execution_v230_ensure_schema($pdo);
        $limit=max(1,min(50,$limit));
        $stmt=$pdo->prepare("SELECT id,request_id,domain_key,operation_key,route_key,status_key,fallback_used,failure_class,duration_ms,result_meta_json,created_at
          FROM homeserver_execution_receipts WHERE user_id=? ORDER BY id DESC LIMIT ".$limit);
        $stmt->execute([$userId]);
        $out=[];
        foreach($stmt->fetchAll()?:[] as $row){
            $meta=json_decode((string)($row['result_meta_json']??''),true);
            $out[]=[
              'id'=>(int)$row['id'],'request_id'=>(string)$row['request_id'],
              'domain'=>(string)$row['domain_key'],'operation'=>(string)$row['operation_key'],
              'route'=>(string)$row['route_key'],'status'=>(string)$row['status_key'],
              'fallback_used'=>!empty($row['fallback_used']),'failure_class'=>(string)$row['failure_class'],
              'duration_ms'=>(int)$row['duration_ms'],'result_meta'=>is_array($meta)?$meta:[],
              'created_at'=>(string)$row['created_at'],
            ];
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function homeserver_execution_v230_execute(
    int $userId,string $operation,array $payload=[],?callable $fallback=null
): array {
    if($userId<1)throw new RuntimeException('Authentication required.');
    $policy=homeserver_execution_v230_policy($operation,$payload);
    $requestId=bin2hex(random_bytes(16));
    $started=microtime(true);

    try{
        if(function_exists('homeserver_execution_v220_can_route')&&!homeserver_execution_v220_can_route($userId,(string)$policy['operation'])){
            throw new RuntimeException('The requested HomeServer capability is unavailable.');
        }
        if(!function_exists('homeserver_https_v1300_remote_operation'))throw new RuntimeException('HomeServer relay is unavailable.');
        $result=homeserver_https_v1300_remote_operation($userId,(string)$policy['operation'],$payload);
        if(!is_array($result))$result=['value'=>$result];
        $duration=max(0,(int)round((microtime(true)-$started)*1000));
        $receipt=homeserver_execution_v230_receipt(
          $userId,$requestId,$policy,'homeserver','completed',false,'none',$duration,
          homeserver_execution_v230_result_meta($result)
        );
        return ['ok'=>true,'result'=>$result,'execution'=>$receipt];
    }catch(Throwable $e){
        $duration=max(0,(int)round((microtime(true)-$started)*1000));
        $failure=homeserver_execution_v230_failure_class($e);
        if($fallback&&$policy['fallback_allowed']){
            $fallbackStarted=microtime(true);
            $result=$fallback($e,$policy);
            if(!is_array($result))$result=['value'=>$result];
            $total=$duration+max(0,(int)round((microtime(true)-$fallbackStarted)*1000));
            $receipt=homeserver_execution_v230_receipt(
              $userId,$requestId,$policy,'cloud_fallback','completed',true,$failure,$total,
              homeserver_execution_v230_result_meta($result)
            );
            return ['ok'=>true,'result'=>$result,'execution'=>$receipt];
        }
        homeserver_execution_v230_receipt($userId,$requestId,$policy,'homeserver','failed',false,$failure,$duration,[]);
        throw $e;
    }
}
