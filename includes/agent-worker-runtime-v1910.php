<?php
declare(strict_types=1);

/**
 * VP3 Phase 19.1 — Distributed Worker Runtime.
 *
 * Thin server-side coordinator over the Phase 19.0 durable job engine.
 * It does not create another queue, lease, retry, receipt, approval, pairing,
 * capability, or authorization store. Phase 19.0 remains authoritative for
 * execution state; HomeServer pairing/capability/action policy remains
 * authoritative for local execution.
 */
const VP3_AGENT_WORKER_RUNTIME_V1910='agent-worker-runtime-v1910-20260914';
const VP3_AGENT_WORKER_STALE_SECONDS_V1910=300;
const VP3_AGENT_WORKER_CLOUD_MAX_CONCURRENCY_V1910=4;
const VP3_AGENT_WORKER_HOMESERVER_MAX_CONCURRENCY_V1910=1;
const VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910='agent.chat';
const VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910=145;
const VP3_AGENT_WORKER_HOMESERVER_LEASE_SECONDS_V1910=180;
const VP3_AGENT_WORKER_INSTRUCTION_MAX_CHARS_V1910=8000;

require_once __DIR__.'/agent-job-engine-v1900.php';
require_once __DIR__.'/homeserver-vp3.php';
require_once __DIR__.'/homeserver-capability-registry-v033.php';
require_once __DIR__.'/agent-tool-authorization-v400.php';

function agent_worker_runtime_slug_v1910(string $value,int $limit=60): string
{
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9._-]+/','-',$value)??'';
    $value=trim($value,'-_.');
    return mb_strimwidth($value,0,max(1,$limit),'');
}

function agent_worker_runtime_cloud_id_v1910(int $ownerUserId,string $requested='primary'): string
{
    $slug=agent_worker_runtime_slug_v1910($requested);
    if($slug==='')$slug='primary';
    return 'cloud:u'.max(0,$ownerUserId).':'.$slug;
}

function agent_worker_runtime_homeserver_id_v1910(int $ownerUserId,string $deviceId): string
{
    $deviceId=trim($deviceId);
    if($ownerUserId<1||$deviceId==='')return '';
    return 'homeserver:u'.$ownerUserId.':'.substr(hash('sha256',$deviceId),0,20);
}

/**
 * Execution readiness must come from the live authenticated v0.33 registry,
 * never from the legacy capabilities_json cache. The v0.33 function refreshes
 * connection state and calls HomeServer capability.registry through the paired
 * relay/token boundary before returning a sanitized registry.
 */
function agent_worker_runtime_homeserver_registry_v1910(int $ownerUserId): array
{
    if($ownerUserId<1||!function_exists('homeserver_capability_v033_registry')){
        return homeserver_capability_v033_empty('capability_registry_unavailable');
    }
    try{
        return homeserver_capability_v033_registry($ownerUserId,true);
    }catch(Throwable $e){
        return homeserver_capability_v033_empty('remote_unavailable');
    }
}

/**
 * Phase 19 workflow capability keys are orchestration labels, not raw
 * HomeServer operation names. Keep the mapping explicit and fail closed so a
 * newly introduced workflow capability cannot silently broaden remote access.
 */
function agent_worker_runtime_homeserver_transport_v1910(string $capabilityKey): ?array
{
    $capabilityKey=agent_worker_runtime_slug_v1910($capabilityKey,120);
    $allowed=[
        'agent.next_action'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,
        'calendar.review_conflict'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,
        'calendar.prepare_commitment'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,
        'scheduling.prepare_followup'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,
        'commerce.review_next_action'=>VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,
    ];
    if($capabilityKey===''||!isset($allowed[$capabilityKey]))return null;
    return ['capability_key'=>$capabilityKey,'operation'=>$allowed[$capabilityKey]];
}

function agent_worker_runtime_active_count_v1910(PDO $pdo,int $ownerUserId,string $executor): int
{
    if($ownerUserId<1||!in_array($executor,['cloud','homeserver'],true)||!agent_job_engine_schema_ready_v1900($pdo))return 0;
    $prefix=$executor.':u'.$ownerUserId.':%';
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM agent_workflow_runs WHERE owner_user_id=? AND status='executing' AND lease_owner LIKE ? AND lease_expires_at>=UTC_TIMESTAMP()");
    $stmt->execute([$ownerUserId,$prefix]);
    return max(0,(int)$stmt->fetchColumn());
}

function agent_worker_runtime_receipt_stats_v1910(PDO $pdo,int $ownerUserId,string $workerId): array
{
    $out=['completed'=>0,'retry_scheduled'=>0,'dead_lettered'=>0,'last_receipt_at'=>''];
    if($ownerUserId<1||$workerId===''||!table_exists('agent_workflow_receipts'))return $out;
    $stmt=$pdo->prepare("SELECT
        SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed_count,
        SUM(CASE WHEN status='retry_scheduled' THEN 1 ELSE 0 END) retry_count,
        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed_count,
        MAX(created_at) last_receipt_at
        FROM agent_workflow_receipts WHERE owner_user_id=? AND worker_id=?");
    $stmt->execute([$ownerUserId,$workerId]);$row=$stmt->fetch()?:[];
    return [
        'completed'=>max(0,(int)($row['completed_count']??0)),
        'retry_scheduled'=>max(0,(int)($row['retry_count']??0)),
        // Phase 19.0 canonical status remains `failed`; only the owner-facing
        // worker summary calls terminal failed receipts dead-lettered.
        'dead_lettered'=>max(0,(int)($row['failed_count']??0)),
        'last_receipt_at'=>(string)($row['last_receipt_at']??''),
    ];
}

function agent_worker_runtime_homeserver_capability_keys_v1910(array $registry): array
{
    $keys=[];
    foreach((array)($registry['operations']??[]) as $key){$key=agent_worker_runtime_slug_v1910(str_replace(':','.',(string)$key),120);if($key!=='')$keys[$key]=true;}
    foreach((array)($registry['app']['scope']['tool_names']??[]) as $key){$key=agent_worker_runtime_slug_v1910((string)$key,120);if($key!=='')$keys[$key]=true;}
    foreach((array)($registry['tools']??[]) as $tool){if(!is_array($tool)||empty($tool['available'])||empty($tool['enabled']))continue;$key=agent_worker_runtime_slug_v1910((string)($tool['key']??''),120);if($key!=='')$keys[$key]=true;}
    return array_keys($keys);
}

function agent_worker_runtime_supports_capability_v1910(array $worker,string $capabilityKey): bool
{
    $capabilityKey=agent_worker_runtime_slug_v1910($capabilityKey,120);
    if($capabilityKey==='')return false;
    if((string)($worker['executor']??'')==='cloud')return true; // Capability narrows routing; it never grants authority.
    $transport=agent_worker_runtime_homeserver_transport_v1910($capabilityKey);
    if(!$transport)return false;
    $supported=array_fill_keys((array)($worker['capability_keys']??[]),true);
    // The paired HomeServer must explicitly advertise the mapped operation.
    // This only proves transport availability; HomeServer still enforces the
    // paired app's permissions, scoped tools and local action policy.
    return isset($supported[(string)$transport['operation']]);
}

function agent_worker_runtime_worker_v1910(PDO $pdo,array $user,string $executor,string $requestedWorkerId='primary'): array
{
    $uid=(int)($user['id']??0);$executor=strtolower(trim($executor));
    $base=[
        'build'=>VP3_AGENT_WORKER_RUNTIME_V1910,'worker_id'=>'','executor'=>$executor,'label'=>'',
        'ready'=>false,'state'=>'unavailable','reason'=>'invalid_worker','max_concurrency'=>0,'active_jobs'=>0,
        'last_seen_at'=>'','capability_keys'=>[],'capability_count'=>0,'receipts'=>['completed'=>0,'retry_scheduled'=>0,'dead_lettered'=>0,'last_receipt_at'=>''],
    ];
    if($uid<1||!agent_job_engine_schema_ready_v1900($pdo)){$base['reason']=$uid<1?'invalid_owner':'job_engine_not_ready';return $base;}

    if($executor==='cloud'){
        $base['worker_id']=agent_worker_runtime_cloud_id_v1910($uid,$requestedWorkerId);
        $base['label']='VP3 Cloud Worker';$base['ready']=true;$base['state']='ready';$base['reason']='ready';
        $base['max_concurrency']=VP3_AGENT_WORKER_CLOUD_MAX_CONCURRENCY_V1910;
        $base['active_jobs']=agent_worker_runtime_active_count_v1910($pdo,$uid,'cloud');
        $base['capability_keys']=['canonical_vp3'];$base['capability_count']=1;
        $base['receipts']=agent_worker_runtime_receipt_stats_v1910($pdo,$uid,$base['worker_id']);
        return $base;
    }

    if($executor!=='homeserver')return $base;
    $connection=homeserver_vp3_connection($uid);
    if(!$connection){$base['reason']='homeserver_unpaired';$base['state']='unpaired';return $base;}
    $deviceId=trim((string)($connection['device_id']??''));
    $paired=$deviceId!==''&&!empty($connection['homeserver_token_enc']);
    if(!$paired){$base['reason']='homeserver_unpaired';$base['state']='unpaired';return $base;}

    $base['worker_id']=agent_worker_runtime_homeserver_id_v1910($uid,$deviceId);
    $base['label']='Paired HomeServer';$base['max_concurrency']=VP3_AGENT_WORKER_HOMESERVER_MAX_CONCURRENCY_V1910;
    $base['active_jobs']=agent_worker_runtime_active_count_v1910($pdo,$uid,'homeserver');
    $base['receipts']=agent_worker_runtime_receipt_stats_v1910($pdo,$uid,$base['worker_id']);

    // A fresh authenticated registry response is the execution-readiness
    // authority. Cached capabilities may describe UI state but cannot release
    // durable work to HomeServer.
    $registry=agent_worker_runtime_homeserver_registry_v1910($uid);

    // v0.33 refreshes connection state. Reload only the sanitized connection
    // metadata we need for observability/identity after that live check.
    $refreshed=homeserver_vp3_connection($uid);
    if($refreshed){
        $connection=$refreshed;
        $refreshedDeviceId=trim((string)($connection['device_id']??''));
        if($refreshedDeviceId!==''&&!hash_equals($deviceId,$refreshedDeviceId)){
            $base['worker_id']=agent_worker_runtime_homeserver_id_v1910($uid,$refreshedDeviceId);
            $deviceId=$refreshedDeviceId;
            $base['receipts']=agent_worker_runtime_receipt_stats_v1910($pdo,$uid,$base['worker_id']);
        }
    }
    $base['last_seen_at']=(string)($connection['last_seen_at']??'');
    $base['capability_keys']=agent_worker_runtime_homeserver_capability_keys_v1910($registry);
    $base['capability_count']=count($base['capability_keys']);

    if(empty($registry['available'])){
        $seenAt=strtotime($base['last_seen_at'])?:0;
        $base['reason']=$seenAt>0&&$seenAt<time()-VP3_AGENT_WORKER_STALE_SECONDS_V1910?'homeserver_stale':'homeserver_offline';
        $base['state']=$base['reason']==='homeserver_stale'?'stale':'offline';
        return $base;
    }
    if(!in_array(VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910,$base['capability_keys'],true)){
        $base['reason']='homeserver_agent_chat_unavailable';$base['state']='capability_unavailable';
        return $base;
    }

    $base['ready']=true;$base['state']='ready';$base['reason']='ready';
    return $base;
}

function agent_worker_runtime_summary_v1910(PDO $pdo,array $user): array
{
    $cloud=agent_worker_runtime_worker_v1910($pdo,$user,'cloud','primary');
    $home=agent_worker_runtime_worker_v1910($pdo,$user,'homeserver');
    $public=static function(array $worker): array{
        return [
            'worker_id'=>(string)($worker['worker_id']??''),'executor'=>(string)($worker['executor']??''),'label'=>(string)($worker['label']??''),
            'ready'=>!empty($worker['ready']),'state'=>(string)($worker['state']??'unavailable'),'reason'=>(string)($worker['reason']??''),
            'max_concurrency'=>max(0,(int)($worker['max_concurrency']??0)),'active_jobs'=>max(0,(int)($worker['active_jobs']??0)),
            'last_seen_at'=>(string)($worker['last_seen_at']??''),'capability_count'=>max(0,(int)($worker['capability_count']??0)),
            'receipts'=>is_array($worker['receipts']??null)?$worker['receipts']:[],
        ];
    };
    return ['build'=>VP3_AGENT_WORKER_RUNTIME_V1910,'workers'=>[$public($cloud),$public($home)]];
}

function agent_worker_runtime_authorize_claim_v1910(PDO $pdo,array $user,array $worker,array $claim): array
{
    $uid=(int)($user['id']??0);$runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$workerId=(string)($worker['worker_id']??'');
    if($uid<1||$runId<1||$actionId<1||$workerId===''||empty($worker['ready']))return ['authorized'=>false,'reason'=>'worker_unavailable'];
    $stmt=$pdo->prepare('SELECT r.owner_user_id,r.status run_status,r.approval_status,r.requires_approval run_requires_approval,r.lease_owner,r.lease_token,r.lease_expires_at,a.status action_status,a.requires_approval action_requires_approval,a.execution_target,a.capability_key FROM agent_workflow_runs r INNER JOIN agent_workflow_actions a ON a.id=r.current_action_id AND a.run_id=r.id AND a.owner_user_id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? AND a.id=? LIMIT 1');
    $stmt->execute([$runId,$uid,$actionId]);$row=$stmt->fetch();
    if(!$row)return ['authorized'=>false,'reason'=>'claim_not_owned'];
    if((string)$row['run_status']!=='executing'||(string)$row['action_status']!=='executing')return ['authorized'=>false,'reason'=>'claim_not_executing'];
    if(!hash_equals((string)$row['lease_owner'],$workerId)||!hash_equals((string)$row['lease_token'],(string)($claim['lease_token']??'')))return ['authorized'=>false,'reason'=>'lease_identity_mismatch'];
    if((strtotime((string)$row['lease_expires_at'])?:0)<time())return ['authorized'=>false,'reason'=>'lease_expired'];
    if((string)$row['execution_target']!==(string)($worker['executor']??''))return ['authorized'=>false,'reason'=>'executor_mismatch'];
    if((!empty($row['run_requires_approval'])||!empty($row['action_requires_approval']))&&!in_array((string)$row['approval_status'],['approved','not_required'],true))return ['authorized'=>false,'reason'=>'approval_required'];
    $capability=(string)($row['capability_key']??'');
    if(!agent_worker_runtime_supports_capability_v1910($worker,$capability))return ['authorized'=>false,'reason'=>'capability_unavailable'];
    return ['authorized'=>true,'reason'=>'authorized','capability_key'=>$capability,'principal_user_id'=>$uid,'server_derived'=>true];
}

function agent_worker_runtime_terminal_deny_v1910(PDO $pdo,array $user,array $claim,string $reason): array
{
    $runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$attempt=max(1,(int)($claim['action']['attempt_count']??1));
    return agent_job_record_result_v1900(
        $pdo,$user,$runId,$actionId,(string)($claim['lease_token']??''),
        'v1910-deny-'.$runId.'-'.$actionId.'-'.$attempt,false,
        'Worker execution was denied by the server-side runtime boundary.',[],agent_worker_runtime_slug_v1910($reason,80)?:'worker_authorization_denied',false
    );
}

/**
 * Server-only worker poll. No browser/API route should expose this primitive.
 */
function agent_worker_runtime_poll_v1910(PDO $pdo,array $user,string $executor,string $requestedWorkerId='primary',int $limit=25): array
{
    $worker=agent_worker_runtime_worker_v1910($pdo,$user,$executor,$requestedWorkerId);
    if(empty($worker['ready']))return ['ok'=>false,'reason'=>(string)$worker['reason'],'claim'=>null,'worker'=>$worker,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    if((int)$worker['active_jobs']>=(int)$worker['max_concurrency'])return ['ok'=>true,'reason'=>'at_capacity','claim'=>null,'worker'=>$worker,'recovery'=>['recovered'=>0,'failed'=>0],'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    $limit=max(1,min(100,$limit));$recovery=agent_job_recover_expired_v1900($pdo,$user,$limit);
    $claim=agent_job_claim_next_v1900($pdo,$user,$executor,(string)$worker['worker_id'],$limit);
    if(!$claim)return ['ok'=>true,'reason'=>'no_work','claim'=>null,'worker'=>$worker,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    $authorization=agent_worker_runtime_authorize_claim_v1910($pdo,$user,$worker,$claim);
    if(empty($authorization['authorized'])){
        agent_worker_runtime_terminal_deny_v1910($pdo,$user,$claim,(string)($authorization['reason']??'worker_authorization_denied'));
        return ['ok'=>false,'reason'=>(string)($authorization['reason']??'worker_authorization_denied'),'claim'=>null,'worker'=>$worker,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }
    // Executable payload is released only after owner, lease, approval,
    // executor, freshness and capability checks all succeed.
    $claim['authorization']=$authorization;
    return ['ok'=>true,'reason'=>'claimed','claim'=>$claim,'worker'=>$worker,'recovery'=>$recovery,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
}

function agent_worker_runtime_heartbeat_v1910(PDO $pdo,array $user,array $claim,int $progress,string $message='',int $leaseSeconds=120): bool
{
    return agent_job_heartbeat_v1900(
        $pdo,$user,(int)($claim['run_id']??0),(int)($claim['action']['id']??0),(string)($claim['lease_token']??''),
        $progress,$message,$leaseSeconds
    );
}

function agent_worker_runtime_result_v1910(PDO $pdo,array $user,array $claim,string $idempotencyKey,bool $success,string $summary,array $result=[],string $errorClass='',bool $retryable=true): array
{
    // v4.00 is the canonical principal/result boundary. If an executor returns
    // browser actions, sanitize them before any result can be persisted/used.
    if(isset($result['actions'])&&is_array($result['actions'])&&function_exists('vp3_agent_tool_authorize_result_v400')){
        $authorized=vp3_agent_tool_authorize_result_v400(['handled'=>true,'answer'=>'','stem_media'=>[],'media'=>[],'sources'=>[],'actions'=>$result['actions']],$user,'');
        $result['authorized_action_count']=count((array)($authorized['actions']??[]));
        unset($result['actions']);
    }
    return agent_job_record_result_v1900(
        $pdo,$user,(int)($claim['run_id']??0),(int)($claim['action']['id']??0),(string)($claim['lease_token']??''),
        $idempotencyKey,$success,$summary,$result,$errorClass,$retryable
    );
}

function agent_worker_runtime_homeserver_instruction_v1910(array $claim): string
{
    $action=is_array($claim['action']??null)?$claim['action']:[];
    $label=trim((string)($action['label']??''));
    $summary=trim((string)($action['summary']??''));
    $capability=agent_worker_runtime_slug_v1910((string)($action['capability_key']??($claim['authorization']['capability_key']??'')),120);
    $instruction="Execute only this approved VP3 durable action within the paired VP3 app permissions and HomeServer action policy. Do not broaden the requested scope. If local HomeServer policy requires approval, create or return that approval request instead of bypassing it.";
    if($capability!=='')$instruction.="\nCapability: ".$capability.'.';
    if($label!=='')$instruction.="\nAction: ".$label.'.';
    if($summary!=='')$instruction.="\nInstruction: ".$summary;
    return mb_strimwidth($instruction,0,VP3_AGENT_WORKER_INSTRUCTION_MAX_CHARS_V1910,'…');
}

/**
 * Long-running HomeServer worker transport. This deliberately does not alter
 * the shared 8-second status helper used by UI requests.
 */
function agent_worker_runtime_homeserver_relay_v1910(string $relayToken,string $homeServerToken,string $operation,array $payload): array
{
    $base=homeserver_vp3_relay_base_url();
    if($base===''||$relayToken===''||$homeServerToken==='')return ['ok'=>false,'http_status'=>0,'error_class'=>'homeserver_credentials_unavailable','retryable'=>false];
    if(!function_exists('curl_init'))return ['ok'=>false,'http_status'=>0,'error_class'=>'homeserver_transport_unavailable','retryable'=>false];
    if($operation!==VP3_AGENT_WORKER_HOMESERVER_OPERATION_V1910)return ['ok'=>false,'http_status'=>0,'error_class'=>'homeserver_operation_denied','retryable'=>false];
    $body=json_encode(['operation'=>$operation,'payload'=>$payload,'bearer_token'=>$homeServerToken],JSON_UNESCAPED_SLASHES);
    if(!is_string($body))return ['ok'=>false,'http_status'=>0,'error_class'=>'homeserver_request_invalid','retryable'=>false];
    $ch=curl_init(rtrim($base,'/').'/v1/request');
    if($ch===false)return ['ok'=>false,'http_status'=>0,'error_class'=>'homeserver_transport_unavailable','retryable'=>true];
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>VP3_AGENT_WORKER_HOMESERVER_RELAY_TIMEOUT_V1910,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$relayToken],
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>$body,
    ]);
    $raw=curl_exec($ch);$errno=curl_errno($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if(!is_string($raw)){
        return ['ok'=>false,'http_status'=>0,'error_class'=>$errno===CURLE_OPERATION_TIMEDOUT?'homeserver_transport_timeout':'homeserver_transport_network','retryable'=>true];
    }
    $decoded=json_decode($raw,true);
    if(!is_array($decoded))return ['ok'=>false,'http_status'=>$status,'error_class'=>'homeserver_transport_invalid_response','retryable'=>$status===0||$status>=500];
    if($status<200||$status>=300){
        $retryable=$status===0||$status===408||$status===425||$status===429||$status>=500;
        $errorClass=in_array($status,[401,403],true)?'homeserver_auth_denied':($retryable?'homeserver_transport_retryable':'homeserver_request_denied');
        return ['ok'=>false,'http_status'=>$status,'error_class'=>$errorClass,'retryable'=>$retryable];
    }
    $response=$decoded['payload']??[];
    if(!is_array($response))return ['ok'=>false,'http_status'=>$status,'error_class'=>'homeserver_transport_invalid_response','retryable'=>true];
    return ['ok'=>true,'http_status'=>$status,'payload'=>$response,'error_class'=>'','retryable'=>false];
}

function agent_worker_runtime_homeserver_safe_result_v1910(array $response): array
{
    $tools=is_array($response['tools']??null)?$response['tools']:[];
    $requests=[];
    foreach((array)($tools['action_request_ids']??[]) as $requestId){
        $requestId=trim((string)$requestId);
        if($requestId!==''&&preg_match('/^[A-Za-z0-9._:-]{1,128}$/',$requestId))$requests[$requestId]=true;
        if(count($requests)>=50)break;
    }
    $reply=mb_strimwidth(trim((string)($response['reply']??'')),0,1600,'…');
    $provider=agent_worker_runtime_slug_v1910((string)($response['provider']??''),80);
    $model=mb_strimwidth(trim((string)($response['model']??'')),0,160,'');
    $compute=agent_worker_runtime_slug_v1910((string)($response['compute_source']??''),80);
    $conversationId=mb_strimwidth(trim((string)($response['conversation_id']??'')),0,128,'');
    $runId=max(0,(int)($response['run_id']??0));
    return [
        'effect_state'=>$requests?'homeserver_approval_pending':'reported_complete',
        'reply'=>$reply,'provider'=>$provider,'model'=>$model,'compute_source'=>$compute,
        'conversation_id'=>$conversationId,'homeserver_run_id'=>$runId,
        'local_action_request_count'=>count($requests),
    ];
}

function agent_worker_runtime_homeserver_failure_v1910(PDO $pdo,array $user,array $claim,string $errorClass,bool $retryable,string $summary): array
{
    $runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$attempt=max(1,(int)($claim['action']['attempt_count']??1));
    $key='v1910-hs-'.$runId.'-'.$actionId.'-'.$attempt;
    return agent_worker_runtime_result_v1910($pdo,$user,$claim,$key,false,$summary,[],agent_worker_runtime_slug_v1910($errorClass,80)?:'homeserver_execution_failed',$retryable);
}

/**
 * Execute one leased HomeServer-targeted action through the existing trusted
 * relay. This is server-side infrastructure; it is intentionally not exposed
 * by an HTTP/browser executor endpoint.
 */
function agent_worker_runtime_execute_homeserver_once_v1910(PDO $pdo,array $user,int $limit=25): array
{
    $uid=(int)($user['id']??0);
    if($uid<1)return ['ok'=>false,'reason'=>'invalid_owner','claim'=>null,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];

    // Poll performs a live authenticated v0.33 capability registry check
    // before any HomeServer-targeted durable action can be leased.
    $poll=agent_worker_runtime_poll_v1910($pdo,$user,'homeserver','primary',$limit);
    $claim=is_array($poll['claim']??null)?$poll['claim']:null;
    if(!$claim)return $poll;

    if(!agent_worker_runtime_heartbeat_v1910($pdo,$user,$claim,10,'Dispatching to paired HomeServer',VP3_AGENT_WORKER_HOMESERVER_LEASE_SECONDS_V1910)){
        return ['ok'=>false,'reason'=>'lease_lost','claim'=>null,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }

    $transport=agent_worker_runtime_homeserver_transport_v1910((string)($claim['authorization']['capability_key']??''));
    if(!$transport){
        $receipt=agent_worker_runtime_homeserver_failure_v1910($pdo,$user,$claim,'homeserver_transport_unmapped',false,'HomeServer execution was denied because this workflow capability is not mapped to an approved transport operation.');
        return ['ok'=>false,'reason'=>'transport_unmapped','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }

    $connection=homeserver_vp3_connection($uid);
    $deviceId=trim((string)($connection['device_id']??''));
    $expectedWorker=agent_worker_runtime_homeserver_id_v1910($uid,$deviceId);
    if(!$connection||$expectedWorker===''||!hash_equals((string)($poll['worker']['worker_id']??''),$expectedWorker)){
        $receipt=agent_worker_runtime_homeserver_failure_v1910($pdo,$user,$claim,'homeserver_identity_changed',false,'HomeServer execution stopped because the paired device identity changed after the job was leased.');
        return ['ok'=>false,'reason'=>'identity_changed','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }

    try{
        $relayToken=homeserver_vp3_decrypt((string)($connection['relay_token_enc']??''));
        $homeToken=homeserver_vp3_decrypt((string)($connection['homeserver_token_enc']??''));
    }catch(Throwable $e){
        $relayToken='';$homeToken='';
    }
    if($relayToken===''||$homeToken===''){
        $receipt=agent_worker_runtime_homeserver_failure_v1910($pdo,$user,$claim,'homeserver_credentials_unavailable',false,'HomeServer execution stopped because the paired credentials are unavailable.');
        return ['ok'=>false,'reason'=>'credentials_unavailable','receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }

    $message=agent_worker_runtime_homeserver_instruction_v1910($claim);
    $remote=agent_worker_runtime_homeserver_relay_v1910($relayToken,$homeToken,(string)$transport['operation'],['message'=>$message,'conversation_id'=>null]);
    if(empty($remote['ok'])){
        $errorClass=(string)($remote['error_class']??'homeserver_execution_failed');
        $retryable=!empty($remote['retryable']);
        $receipt=agent_worker_runtime_homeserver_failure_v1910($pdo,$user,$claim,$errorClass,$retryable,'The paired HomeServer could not complete this execution handoff.');
        return ['ok'=>false,'reason'=>$errorClass,'retryable'=>$retryable,'receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
    }

    $safe=agent_worker_runtime_homeserver_safe_result_v1910((array)$remote['payload']);
    $pending=(int)$safe['local_action_request_count'];
    $summary=$pending>0
        ?'HomeServer accepted the durable action and created '.$pending.' local approval request'.($pending===1?'':'s').'.'
        :((string)$safe['reply']!==''?(string)$safe['reply']:'HomeServer reported the durable action complete.');
    $runId=(int)($claim['run_id']??0);$actionId=(int)($claim['action']['id']??0);$attempt=max(1,(int)($claim['action']['attempt_count']??1));
    $receipt=agent_worker_runtime_result_v1910($pdo,$user,$claim,'v1910-hs-'.$runId.'-'.$actionId.'-'.$attempt,true,$summary,$safe,'',false);
    return ['ok'=>true,'reason'=>$pending>0?'homeserver_approval_pending':'completed','effect_state'=>(string)$safe['effect_state'],'receipt'=>$receipt,'build'=>VP3_AGENT_WORKER_RUNTIME_V1910];
}
