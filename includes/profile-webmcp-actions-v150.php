<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_ACTIONS_V150='profile-webmcp-actions-v150-20260928';

function vp3_profile_webmcp_actions_schema_ready_v150(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && table_exists('profile_webmcp_actions')
        && column_exists('profile_webmcp_actions','intent_id')
        && column_exists('profile_webmcp_actions','idempotency_hash')
        && column_exists('profile_webmcp_actions','result_json');
}

function vp3_profile_webmcp_actions_ensure_schema_v150(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_webmcp_actions (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      owner_user_id INT UNSIGNED NOT NULL,
      profile_username VARCHAR(64) NOT NULL DEFAULT '',
      surface VARCHAR(24) NOT NULL,
      property_id BIGINT UNSIGNED NULL,
      session_hash CHAR(64) NOT NULL,
      intent_id CHAR(32) NOT NULL,
      operation VARCHAR(80) NOT NULL,
      payload_hash CHAR(64) NOT NULL,
      idempotency_hash CHAR(64) NULL,
      state VARCHAR(24) NOT NULL DEFAULT 'prepared',
      result_type VARCHAR(40) NOT NULL DEFAULT '',
      result_id BIGINT UNSIGNED NULL,
      result_json LONGTEXT NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      expires_at DATETIME NOT NULL,
      committed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_profile_webmcp_action_intent (owner_user_id,intent_id),
      UNIQUE KEY uq_profile_webmcp_action_idempotency (owner_user_id,operation,idempotency_hash),
      INDEX idx_profile_webmcp_action_lookup (owner_user_id,surface,state,expires_at,id),
      CONSTRAINT fk_profile_webmcp_action_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_profile_webmcp_canonicalize_v150(mixed $value): mixed
{
    if(!is_array($value))return $value;
    if(array_is_list($value))return array_map('vp3_profile_webmcp_canonicalize_v150',$value);
    ksort($value,SORT_STRING);
    foreach($value as $key=>$item)$value[$key]=vp3_profile_webmcp_canonicalize_v150($item);
    return $value;
}

function vp3_profile_webmcp_payload_hash_v150(array $payload): string
{
    return hash('sha256',json_encode(vp3_profile_webmcp_canonicalize_v150($payload),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}

function vp3_profile_webmcp_idempotency_hash_v150(int $ownerUserId,string $operation,string $key): string
{
    $key=vp3_profile_webmcp_transport_id_v130($key);
    if($ownerUserId<1||$key==='')throw new InvalidArgumentException('A valid idempotency key is required.');
    return hash('sha256',$ownerUserId.'|'.strtolower(trim($operation)).'|'.$key);
}

function vp3_profile_webmcp_action_prepare_v150(
    PDO $pdo,array $context,string $operation,array $payload,int $ttlSeconds=600
): array {
    if(!vp3_profile_webmcp_actions_schema_ready_v150($pdo))throw new RuntimeException('WebMCP action ledger is not ready.');
    $owner=(int)($context['owner_user_id']??0);
    $surface=(string)($context['surface']??'');
    $username=(string)($context['profile_username']??'');
    $sessionHash=(string)($context['session_hash']??'');
    $propertyId=(int)($context['property_id']??0)?:null;
    if($owner<1||!in_array($surface,['native_profile','external_site'],true)||$username===''||!preg_match('/^[a-f0-9]{64}$/',$sessionHash)){
        throw new RuntimeException('WebMCP action context is invalid.');
    }
    $operation=mb_strimwidth(strtolower(trim($operation)),0,80,'');
    if($operation==='')throw new RuntimeException('WebMCP action operation is invalid.');
    $payloadHash=vp3_profile_webmcp_payload_hash_v150($payload);
    $intentId=bin2hex(random_bytes(16));
    $ttlSeconds=max(60,min(1800,$ttlSeconds));
    $stmt=$pdo->prepare("INSERT INTO profile_webmcp_actions
      (owner_user_id,profile_username,surface,property_id,session_hash,intent_id,operation,payload_hash,state,expires_at)
      VALUES (?,?,?,?,?,?,?,?,'prepared',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))");
    $stmt->execute([$owner,$username,$surface,$propertyId,$sessionHash,$intentId,$operation,$payloadHash,$ttlSeconds]);
    return [
        'id'=>(int)$pdo->lastInsertId(),
        'owner_user_id'=>$owner,
        'profile_username'=>$username,
        'surface'=>$surface,
        'property_id'=>$propertyId,
        'session_hash'=>$sessionHash,
        'intent_id'=>$intentId,
        'operation'=>$operation,
        'payload_hash'=>$payloadHash,
        'state'=>'prepared',
        'expires_at_unix'=>time()+$ttlSeconds,
    ];
}

function vp3_profile_webmcp_action_row_v150(PDO $pdo,int $ownerUserId,string $intentId,bool $forUpdate=false): ?array
{
    if($ownerUserId<1||!preg_match('/^[a-f0-9]{32}$/',$intentId))return null;
    $sql='SELECT * FROM profile_webmcp_actions WHERE owner_user_id=? AND intent_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$ownerUserId,$intentId]);
    return $stmt->fetch()?:null;
}

function vp3_profile_webmcp_action_by_idempotency_v150(PDO $pdo,int $ownerUserId,string $operation,string $idempotencyHash,bool $forUpdate=false): ?array
{
    if($ownerUserId<1||!preg_match('/^[a-f0-9]{64}$/',$idempotencyHash))return null;
    $sql='SELECT * FROM profile_webmcp_actions WHERE owner_user_id=? AND operation=? AND idempotency_hash=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$ownerUserId,$operation,$idempotencyHash]);
    return $stmt->fetch()?:null;
}

function vp3_profile_webmcp_action_commit_v150(PDO $pdo,int $actionId,string $idempotencyHash,string $resultType,int $resultId,array $result): void
{
    $safe=[
        'booking'=>is_array($result['booking']??null)?$result['booking']:null,
        'payment_required'=>!empty($result['payment_required']),
        'payment_status'=>mb_strimwidth((string)($result['payment_status']??''),0,40,''),
    ];
    $stmt=$pdo->prepare("UPDATE profile_webmcp_actions
      SET idempotency_hash=?,state='committed',result_type=?,result_id=?,result_json=?,last_error_code='',committed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
      WHERE id=? AND state='prepared'");
    $stmt->execute([
        $idempotencyHash,mb_strimwidth($resultType,0,40,''),$resultId>0?$resultId:null,
        json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$actionId
    ]);
    if($stmt->rowCount()!==1)throw new RuntimeException('WebMCP action changed before it could be committed.');
}
