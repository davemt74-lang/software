<?php
declare(strict_types=1);

/**
 * VP3 Phase 19.2 — Webhook + Event Infrastructure.
 * Durable ingress only. Agent Brain remains the decision layer and Phase 19.0
 * remains the sole durable execution queue.
 */
const VP3_AGENT_EVENT_INFRA_V1920='agent-event-infrastructure-v1920-20260914';
const VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920=65536;
const VP3_AGENT_EVENT_MAX_DEPTH_V1920=8;
const VP3_AGENT_EVENT_SIGNATURE_TOLERANCE_V1920=300;

require_once __DIR__.'/agent-job-engine-v1900.php';

function agent_event_schema_ready_v1920(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo||!table_exists('agent_event_inbox'))return false;
    foreach(['event_uuid','owner_user_id','source','event_type','dedupe_hash','verification_status','processing_status','payload_json','linked_run_id','replay_count'] as $column){
        if(!column_exists('agent_event_inbox',$column))return false;
    }
    return true;
}

function agent_event_ensure_schema_v1920(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_event_inbox (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      event_uuid CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      source VARCHAR(80) NOT NULL,
      event_type VARCHAR(120) NOT NULL,
      schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
      external_event_id VARCHAR(190) NOT NULL DEFAULT '',
      dedupe_hash CHAR(64) NOT NULL,
      verification_status VARCHAR(24) NOT NULL DEFAULT 'trusted',
      verification_key_id VARCHAR(120) NOT NULL DEFAULT '',
      processing_status VARCHAR(24) NOT NULL DEFAULT 'accepted',
      occurred_at DATETIME NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      processing_started_at DATETIME NULL,
      processed_at DATETIME NULL,
      replay_count INT UNSIGNED NOT NULL DEFAULT 0,
      correlation_id VARCHAR(120) NOT NULL DEFAULT '',
      causation_id VARCHAR(120) NOT NULL DEFAULT '',
      payload_json MEDIUMTEXT NULL,
      linked_run_id BIGINT UNSIGNED NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(300) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_event_uuid (event_uuid),
      UNIQUE KEY uq_agent_event_owner_dedupe (owner_user_id,dedupe_hash),
      INDEX idx_agent_event_owner_recent (owner_user_id,received_at,id),
      INDEX idx_agent_event_processing (processing_status,received_at,id),
      INDEX idx_agent_event_source (owner_user_id,source,event_type,received_at,id),
      INDEX idx_agent_event_run (linked_run_id),
      CONSTRAINT fk_agent_event_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_event_run FOREIGN KEY (linked_run_id) REFERENCES agent_workflow_runs(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function agent_event_text_v1920(mixed $value,int $limit=500): string
{
    $text=preg_replace('/\s+/u',' ',trim((string)$value))??'';
    return mb_strimwidth($text,0,max(1,$limit),'…');
}

function agent_event_uuid_v1920(): string
{
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function agent_event_forbidden_key_v1920(string $key): bool
{
    return (bool)preg_match('/(?:authorization|cookie|password|secret|token|credential|private[_-]?key|signature|api[_-]?key|access[_-]?key|refresh[_-]?key|native[_-]?path|filesystem[_-]?path|absolute[_-]?path|local[_-]?path)/i',$key);
}

function agent_event_sanitize_value_v1920(mixed $value,int $depth=0): mixed
{
    if($depth>=VP3_AGENT_EVENT_MAX_DEPTH_V1920)return '[depth-limited]';
    if($value===null||is_bool($value)||is_int($value)||is_float($value))return $value;
    if(is_string($value))return agent_event_text_v1920($value,2000);
    if(!is_array($value))return agent_event_text_v1920((string)$value,500);
    $out=[];$count=0;
    foreach($value as $key=>$item){
        if(++$count>100)break;
        $safeKey=agent_event_text_v1920((string)$key,80);
        if($safeKey===''||agent_event_forbidden_key_v1920($safeKey))continue;
        $out[$safeKey]=agent_event_sanitize_value_v1920($item,$depth+1);
    }
    return $out;
}

function agent_event_sanitize_payload_v1920(array $payload): array
{
    $safe=agent_event_sanitize_value_v1920($payload,0);
    if(!is_array($safe))return [];
    $json=json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(!is_string($json))return [];
    if(strlen($json)>VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920){
        return ['payload_truncated'=>true,'payload_sha256'=>hash('sha256',$json)];
    }
    return $safe;
}

function agent_event_json_v1920(mixed $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json)?$json:'{}';
}

function agent_event_canonicalize_v1920(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('agent_event_canonicalize_v1920',$value);
    ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=agent_event_canonicalize_v1920($item);
    return $value;
}

function agent_event_dedupe_hash_v1920(int $ownerId,string $source,string $eventType,string $externalId,array $payload,string $rawHash=''): string
{
    $identity=$externalId!==''?'external:'.$externalId:'payload:'.($rawHash!==''?$rawHash:hash('sha256',agent_event_json_v1920(agent_event_canonicalize_v1920($payload))));
    return hash('sha256',$ownerId.'|'.$source.'|'.$eventType.'|'.$identity);
}

function agent_event_parse_time_v1920(mixed $value): ?string
{
    if($value===null||$value==='')return null;
    $ts=is_numeric($value)?(int)$value:(strtotime((string)$value)?:0);
    if($ts<1)return null;
    return gmdate('Y-m-d H:i:s',$ts);
}

function agent_event_row_v1920(PDO $pdo,int $ownerId,int $eventId,bool $forUpdate=false): ?array
{
    if($ownerId<1||$eventId<1||!agent_event_schema_ready_v1920($pdo))return null;
    $s=$pdo->prepare('SELECT * FROM agent_event_inbox WHERE id=? AND owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':''));$s->execute([$eventId,$ownerId]);$row=$s->fetch();
    return is_array($row)?$row:null;
}

function agent_event_public_v1920(array $row): array
{
    $payload=json_decode((string)($row['payload_json']??''),true);if(!is_array($payload))$payload=[];
    return [
        'id'=>(int)($row['id']??0),'event_uuid'=>(string)($row['event_uuid']??''),'source'=>(string)($row['source']??''),'event_type'=>(string)($row['event_type']??''),
        'schema_version'=>(int)($row['schema_version']??1),'external_event_id'=>(string)($row['external_event_id']??''),'verification_status'=>(string)($row['verification_status']??''),
        'processing_status'=>(string)($row['processing_status']??''),'occurred_at'=>(string)($row['occurred_at']??''),'received_at'=>(string)($row['received_at']??''),'processed_at'=>(string)($row['processed_at']??''),
        'replay_count'=>(int)($row['replay_count']??0),'correlation_id'=>(string)($row['correlation_id']??''),'causation_id'=>(string)($row['causation_id']??''),
        'payload'=>$payload,'linked_run_id'=>(int)($row['linked_run_id']??0),'last_error_code'=>(string)($row['last_error_code']??''),'last_error_message'=>(string)($row['last_error_message']??''),
        'build'=>VP3_AGENT_EVENT_INFRA_V1920,
    ];
}

function agent_event_recent_v1920(PDO $pdo,array $user,int $limit=30): array
{
    $uid=(int)($user['id']??0);$limit=max(1,min(100,$limit));if($uid<1||!agent_event_schema_ready_v1920($pdo))return [];
    $s=$pdo->prepare('SELECT * FROM agent_event_inbox WHERE owner_user_id=? ORDER BY received_at DESC,id DESC LIMIT '.$limit);$s->execute([$uid]);
    return array_map('agent_event_public_v1920',$s->fetchAll()?:[]);
}

function agent_event_ingest_v1920(PDO $pdo,int $ownerId,string $source,string $eventType,array $payload,array $options=[]): array
{
    if(!agent_event_schema_ready_v1920($pdo))throw new RuntimeException('Agent Event Infrastructure is not installed.');
    $source=strtolower(agent_event_text_v1920($source,80));$eventType=agent_event_text_v1920($eventType,120);
    if($ownerId<1||$source===''||$eventType==='')throw new RuntimeException('Event identity is incomplete.');
    $verification=(string)($options['verification_status']??'trusted');if(!in_array($verification,['trusted','verified'],true))throw new RuntimeException('Only trusted or verified events may enter the inbox.');
    $safe=agent_event_sanitize_payload_v1920($payload);$externalId=agent_event_text_v1920($options['external_event_id']??'',190);$rawHash=agent_event_text_v1920($options['raw_hash']??'',64);
    if($rawHash!==''&&!preg_match('/^[a-f0-9]{64}$/',$rawHash))$rawHash='';
    $dedupe=agent_event_dedupe_hash_v1920($ownerId,$source,$eventType,$externalId,$safe,$rawHash);$uuid=agent_event_uuid_v1920();
    $values=[$uuid,$ownerId,$source,$eventType,max(1,min(65535,(int)($options['schema_version']??1))),$externalId,$dedupe,$verification,agent_event_text_v1920($options['verification_key_id']??'',120),agent_event_parse_time_v1920($options['occurred_at']??null),agent_event_text_v1920($options['correlation_id']??'',120),agent_event_text_v1920($options['causation_id']??'',120),agent_event_json_v1920($safe)];
    try{
        $s=$pdo->prepare("INSERT INTO agent_event_inbox (event_uuid,owner_user_id,source,event_type,schema_version,external_event_id,dedupe_hash,verification_status,verification_key_id,occurred_at,correlation_id,causation_id,payload_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");$s->execute($values);$id=(int)$pdo->lastInsertId();$row=agent_event_row_v1920($pdo,$ownerId,$id);return ['duplicate'=>false,'event'=>$row?agent_event_public_v1920($row):null];
    }catch(PDOException $e){
        if((string)$e->getCode()!=='23000')throw $e;$s=$pdo->prepare('SELECT * FROM agent_event_inbox WHERE owner_user_id=? AND dedupe_hash=? LIMIT 1');$s->execute([$ownerId,$dedupe]);$row=$s->fetch();if(!is_array($row))throw $e;return ['duplicate'=>true,'event'=>agent_event_public_v1920($row)];
    }
}

function &agent_event_handlers_v1920(): array
{
    if(!isset($GLOBALS['vp3_agent_event_handlers_v1920'])||!is_array($GLOBALS['vp3_agent_event_handlers_v1920']))$GLOBALS['vp3_agent_event_handlers_v1920']=[];
    return $GLOBALS['vp3_agent_event_handlers_v1920'];
}

function agent_event_register_handler_v1920(string $source,string $eventType,callable $handler): void
{
    $source=strtolower(agent_event_text_v1920($source,80));$eventType=agent_event_text_v1920($eventType,120);if($source===''||$eventType==='')throw new InvalidArgumentException('Event route is incomplete.');
    $handlers=&agent_event_handlers_v1920();$handlers[$source.'|'.$eventType]=$handler;
}

function agent_event_brain_observe_v1920(array $user,array $row,string $summary=''): void
{
    if(!function_exists('agent_brain_v122_upsert_system_memory'))return;$id=(int)($row['id']??0);if($id<1)return;
    $source=agent_event_text_v1920($row['source']??'',80);$type=agent_event_text_v1920($row['event_type']??'',120);$summary=agent_event_text_v1920($summary,300);
    $text='Verified Agent event “'.$type.'” arrived from '.$source.'.'.($summary!==''?' '.$summary:'');
    $metadata=['event_id'=>$id,'event_uuid'=>(string)($row['event_uuid']??''),'source'=>$source,'event_type'=>$type,'occurred_at'=>(string)($row['occurred_at']??''),'received_at'=>(string)($row['received_at']??''),'build'=>VP3_AGENT_EVENT_INFRA_V1920];
    try{agent_brain_v122_upsert_system_memory($user,'external_event','event-'.(string)$row['event_uuid'],$text,$metadata,0.97);}catch(Throwable $e){}
}

function agent_event_priority_v1920(array $row,array $requested): array
{
    $uuid=(string)($row['event_uuid']??'');$source=agent_event_text_v1920($requested['source']??('event_'.(string)($row['source']??'external')),100);
    $title=agent_event_text_v1920($requested['title']??('Review '.(string)($row['event_type']??'event')),190);$prompt=agent_event_text_v1920($requested['prompt']??$requested['goal']??$title,2000);
    return ['key'=>'event-'.$uuid,'suggestion_hash'=>sha1('event|'.$uuid),'source'=>$source,'title'=>$title,'prompt'=>$prompt,'reason'=>agent_event_text_v1920($requested['reason']??'Triggered by a verified durable event.',1500),'score'=>max(0.0,min(1.0,(float)($requested['score']??0.75))),'risk_level'=>(string)($requested['risk_level']??'low'),'requires_approval'=>!empty($requested['requires_approval']),'event_id'=>'event-'.$uuid,'action_id'=>'event-action-'.sha1($uuid)];
}

function agent_event_dispatch_v1920(PDO $pdo,array $user,int $eventId,bool $replay=false): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');if(!agent_event_schema_ready_v1920($pdo))throw new RuntimeException('Agent Event Infrastructure is not installed.');
    try{
        $pdo->beginTransaction();$row=agent_event_row_v1920($pdo,$uid,$eventId,true);if(!$row)throw new RuntimeException('Event not found.');$status=(string)$row['processing_status'];
        if($status==='processing'){throw new RuntimeException('This event is already being dispatched.');}
        if($status==='processed'&&!$replay){$pdo->commit();return ['duplicate_dispatch'=>true,'event'=>agent_event_public_v1920($row)];}
        $pdo->prepare("UPDATE agent_event_inbox SET processing_status='processing',processing_started_at=UTC_TIMESTAMP(),processed_at=NULL,last_error_code='',last_error_message='',replay_count=replay_count+? WHERE id=? AND owner_user_id=?")->execute([$replay?1:0,$eventId,$uid]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $row=agent_event_row_v1920($pdo,$uid,$eventId);if(!$row)throw new RuntimeException('Event not found.');
    $key=strtolower((string)$row['source']).'|'.(string)$row['event_type'];$handlers=&agent_event_handlers_v1920();$handler=$handlers[$key]??null;$linkedRunId=0;$summary='Observed by Agent Brain; no workflow route registered.';
    try{
        $result=[];if(is_callable($handler)){$result=$handler(agent_event_public_v1920($row),$user);if(!is_array($result))throw new RuntimeException('Registered event handler returned an invalid result.');$summary=agent_event_text_v1920($result['summary']??'Event route processed.',300);}
        agent_event_brain_observe_v1920($user,$row,$summary);
        if(is_array($result['priority']??null)){
            if(!agent_job_engine_schema_ready_v1900($pdo))throw new RuntimeException('Durable Agent Jobs are not ready for event dispatch.');$priority=agent_event_priority_v1920($row,$result['priority']);$run=agent_job_enqueue_from_brain_v1900($pdo,$user,$priority,isset($result['agent_id'])?(int)$result['agent_id']:null);$linkedRunId=(int)($run['id']??0);
        }
        $pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP(),linked_run_id=?,last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=? AND processing_status='processing'")->execute([$linkedRunId>0?$linkedRunId:null,$eventId,$uid]);$final=agent_event_row_v1920($pdo,$uid,$eventId);return ['duplicate_dispatch'=>false,'event'=>$final?agent_event_public_v1920($final):null,'linked_run_id'=>$linkedRunId,'summary'=>$summary];
    }catch(Throwable $e){
        $code=$e instanceof RuntimeException?'dispatch_rejected':'dispatch_failed';$message=$e instanceof RuntimeException?agent_event_text_v1920($e->getMessage(),300):'Event dispatch failed.';$pdo->prepare("UPDATE agent_event_inbox SET processing_status='failed',last_error_code=?,last_error_message=? WHERE id=? AND owner_user_id=? AND processing_status='processing'")->execute([$code,$message,$eventId,$uid]);throw $e;
    }
}

function agent_event_emit_internal_v1920(PDO $pdo,array $user,string $source,string $eventType,array $payload=[],array $options=[]): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');$options['verification_status']='trusted';$ingest=agent_event_ingest_v1920($pdo,$uid,$source,$eventType,$payload,$options);$event=(array)($ingest['event']??[]);$id=(int)($event['id']??0);
    if($id>0&&!$ingest['duplicate'])$ingest['dispatch']=agent_event_dispatch_v1920($pdo,$user,$id,false);return $ingest;
}

function agent_event_verify_hmac_v1920(string $rawBody,string $signature,string $key,string $timestamp='',int $tolerance=VP3_AGENT_EVENT_SIGNATURE_TOLERANCE_V1920): bool
{
    if($key===''||$signature==='')return false;$timestamp=trim($timestamp);if($timestamp!==''){$ts=(int)$timestamp;if($ts<1||abs(time()-$ts)>max(30,min(1800,$tolerance)))return false;}
    $signed=$timestamp!==''?$timestamp.'.'.$rawBody:$rawBody;$expected=hash_hmac('sha256',$signed,$key);$provided=strtolower(trim(preg_replace('/^sha256=/i','',$signature)??''));return preg_match('/^[a-f0-9]{64}$/',$provided)===1&&hash_equals($expected,$provided);
}

function &agent_event_webhook_sources_v1920(): array
{
    if(!isset($GLOBALS['vp3_agent_event_webhook_sources_v1920'])||!is_array($GLOBALS['vp3_agent_event_webhook_sources_v1920']))$GLOBALS['vp3_agent_event_webhook_sources_v1920']=[];
    return $GLOBALS['vp3_agent_event_webhook_sources_v1920'];
}

function agent_event_register_webhook_source_v1920(string $source,callable $verifier,array $allowedEventTypes): void
{
    $source=strtolower(agent_event_text_v1920($source,80));$types=[];foreach($allowedEventTypes as $type){$type=agent_event_text_v1920($type,120);if($type!=='')$types[$type]=true;}if($source===''||!$types)throw new InvalidArgumentException('Webhook source requires an allowlisted event type.');$sources=&agent_event_webhook_sources_v1920();$sources[$source]=['verifier'=>$verifier,'types'=>$types];
}

function agent_event_accept_webhook_v1920(PDO $pdo,string $source,string $rawBody,array $headers=[]): array
{
    $source=strtolower(agent_event_text_v1920($source,80));if($source===''||strlen($rawBody)>VP3_AGENT_EVENT_MAX_PAYLOAD_BYTES_V1920)throw new RuntimeException('Webhook request was rejected.');$sources=&agent_event_webhook_sources_v1920();$config=$sources[$source]??null;if(!is_array($config)||!is_callable($config['verifier']??null))throw new RuntimeException('Webhook source is not registered.');
    $verified=($config['verifier'])($rawBody,$headers);if(!is_array($verified)||empty($verified['verified'])||!is_array($verified['user']??null))throw new RuntimeException('Webhook verification failed.');$user=$verified['user'];$uid=(int)($user['id']??0);$type=agent_event_text_v1920($verified['event_type']??'',120);if($uid<1||$type===''||empty($config['types'][$type]))throw new RuntimeException('Webhook event type is not allowed.');$payload=is_array($verified['payload']??null)?$verified['payload']:[];
    $options=['verification_status'=>'verified','verification_key_id'=>agent_event_text_v1920($verified['key_id']??'',120),'external_event_id'=>agent_event_text_v1920($verified['external_event_id']??'',190),'occurred_at'=>$verified['occurred_at']??null,'correlation_id'=>agent_event_text_v1920($verified['correlation_id']??'',120),'causation_id'=>agent_event_text_v1920($verified['causation_id']??'',120),'schema_version'=>(int)($verified['schema_version']??1),'raw_hash'=>hash('sha256',$rawBody)];
    $ingest=agent_event_ingest_v1920($pdo,$uid,$source,$type,$payload,$options);$event=(array)($ingest['event']??[]);$id=(int)($event['id']??0);if($id>0&&!$ingest['duplicate'])$ingest['dispatch']=agent_event_dispatch_v1920($pdo,$user,$id,false);return $ingest;
}
