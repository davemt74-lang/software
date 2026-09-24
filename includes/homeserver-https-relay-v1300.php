<?php
declare(strict_types=1);

const VP3_HOMESERVER_HTTPS_RELAY_V1300='homeserver-https-relay-v1300-20260924';
const VP3_HOMESERVER_HTTPS_ONLINE_SECONDS=20;
const VP3_HOMESERVER_HTTPS_REQUEST_TTL_SECONDS=120;
const VP3_HOMESERVER_HTTPS_POLL_URL='https://vp3.me/api/homeserver-https-poll-v1300.php';

function homeserver_https_v1300_uuid(): string
{
    $b=random_bytes(16);
    $b[6]=chr((ord($b[6])&0x0f)|0x40);
    $b[8]=chr((ord($b[8])&0x3f)|0x80);
    $h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function homeserver_https_v1300_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
}

function homeserver_https_v1300_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    homeserver_vp3_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_https_sessions (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      device_id VARCHAR(100) NOT NULL,
      session_token_hash CHAR(64) NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      installed_version VARCHAR(64) NOT NULL DEFAULT '',
      capabilities_json LONGTEXT NULL,
      last_seen_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_homeserver_https_device (device_id),
      UNIQUE KEY uq_homeserver_https_token (session_token_hash),
      INDEX idx_homeserver_https_status_seen (status,last_seen_at),
      CONSTRAINT fk_homeserver_https_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_https_requests (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      device_id VARCHAR(100) NOT NULL,
      operation VARCHAR(80) NOT NULL,
      payload_json LONGTEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'queued',
      response_status SMALLINT UNSIGNED NULL,
      response_json LONGTEXT NULL,
      expires_at DATETIME NOT NULL,
      delivered_at DATETIME NULL,
      completed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_homeserver_https_request_public (public_id),
      INDEX idx_homeserver_https_request_queue (user_id,device_id,status,expires_at,id),
      INDEX idx_homeserver_https_request_status (status,updated_at,id),
      CONSTRAINT fk_homeserver_https_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_https_v1300_schema_ready(): bool
{
    return function_exists('table_exists')&&table_exists('homeserver_https_sessions')&&table_exists('homeserver_https_requests');
}

function homeserver_https_v1300_session(int $userId): ?array
{
    if($userId<1)return null;
    $pdo=db();if(!$pdo)return null;
    if(function_exists('table_exists')&&!table_exists('homeserver_https_sessions'))return null;
    $q=$pdo->prepare("SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1");
    $q->execute([$userId]);$row=$q->fetch();
    return $row?:null;
}

function homeserver_https_v1300_has_session(int $userId): bool
{
    $row=homeserver_https_v1300_session($userId);
    return $row&&in_array((string)$row['status'],['active','revoked'],true);
}

function homeserver_https_v1300_pair(string $pairingToken,string $deviceId,string $homeServerToken,string $version='',array $capabilities=[]): array
{
    $deviceId=strtolower(trim($deviceId));
    if(!preg_match('/^hs-[a-f0-9]{24}$/',$deviceId))throw new RuntimeException('HomeServer device identity is invalid.');
    $homeServerToken=trim($homeServerToken);
    if(strlen($homeServerToken)<32||strlen($homeServerToken)>512)throw new RuntimeException('HomeServer local authorization is invalid.');

    $tokenRow=homeserver_account_v1210_begin_redeem($pairingToken);
    $tokenId=(int)$tokenRow['id'];$userId=(int)$tokenRow['user_id'];
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    homeserver_https_v1300_ensure_schema($pdo);
    $sessionToken=homeserver_https_v1300_token();
    $hash=hash('sha256',$sessionToken);
    $capsJson=json_encode($capabilities,JSON_UNESCAPED_SLASHES);
    if(!is_string($capsJson))$capsJson='{}';

    $pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO homeserver_https_sessions
          (user_id,device_id,session_token_hash,status,installed_version,capabilities_json,last_seen_at)
          VALUES (?,?,?,'active',?,?,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),session_token_hash=VALUES(session_token_hash),
            status='active',installed_version=VALUES(installed_version),capabilities_json=VALUES(capabilities_json),
            last_seen_at=UTC_TIMESTAMP()")
          ->execute([$userId,$deviceId,$hash,mb_strimwidth($version,0,64,''),$capsJson]);

        $pdo->prepare("INSERT INTO homeserver_connections
          (user_id,device_id,relay_token_enc,homeserver_token_enc,pending_request_id,pending_claim_token_enc,pending_code,status,installed_version,last_seen_at,last_checked_at,last_error,capabilities_json)
          VALUES (?,?,NULL,?,'',NULL,'','paired',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'',?)
          ON DUPLICATE KEY UPDATE device_id=VALUES(device_id),relay_token_enc=NULL,homeserver_token_enc=VALUES(homeserver_token_enc),
            pending_request_id='',pending_claim_token_enc=NULL,pending_code='',status='paired',
            installed_version=VALUES(installed_version),last_seen_at=UTC_TIMESTAMP(),last_checked_at=UTC_TIMESTAMP(),
            last_error='',capabilities_json=VALUES(capabilities_json)")
          ->execute([$userId,$deviceId,homeserver_vp3_encrypt($homeServerToken),mb_strimwidth($version,0,64,''),$capsJson]);

        $pdo->prepare("UPDATE homeserver_pairing_tokens SET status='paired',device_id=?,redeemed_at=COALESCE(redeemed_at,UTC_TIMESTAMP()) WHERE id=? AND status='redeeming'")
          ->execute([$deviceId,$tokenId]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        homeserver_account_v1210_reset_redeem($tokenId);
        throw $e;
    }

    if(function_exists('vp3_cognitive_homeserver_event_v2390'))vp3_cognitive_homeserver_event_v2390($pdo,$userId,'homeserver.connected','paired');
    return [
      'user_id'=>$userId,'device_id'=>$deviceId,'session_token'=>$sessionToken,
      'poll_url'=>VP3_HOMESERVER_HTTPS_POLL_URL,
      'transport'=>'vp3_https','protocol'=>'https-relay-v1','poll_after_ms'=>900,
    ];
}

function homeserver_https_v1300_authenticate(string $sessionToken,string $deviceId): array
{
    $sessionToken=trim($sessionToken);$deviceId=strtolower(trim($deviceId));
    if(strlen($sessionToken)<32||!preg_match('/^hs-[a-f0-9]{24}$/',$deviceId))throw new RuntimeException('HomeServer HTTPS session authorization is invalid.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    homeserver_https_v1300_ensure_schema($pdo);
    $q=$pdo->prepare("SELECT * FROM homeserver_https_sessions WHERE session_token_hash=? AND device_id=? LIMIT 1");
    $q->execute([hash('sha256',$sessionToken),$deviceId]);$row=$q->fetch();
    if(!$row)throw new RuntimeException('HomeServer HTTPS session is not authorized.');
    if((string)($row['status']??'')!=='active')throw new RuntimeException('HomeServer HTTPS session was revoked.');
    return $row;
}

function homeserver_https_v1300_poll(array $session,array $body): array
{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $userId=(int)$session['user_id'];$deviceId=(string)$session['device_id'];
    $version=mb_strimwidth(trim((string)($body['version']??'')),0,64,'');
    $caps=is_array($body['capabilities']??null)?$body['capabilities']:[];
    $capsJson=json_encode($caps,JSON_UNESCAPED_SLASHES);if(!is_string($capsJson))$capsJson='{}';

    $pdo->beginTransaction();
    try{
        foreach((array)($body['results']??[]) as $result){
            if(!is_array($result))continue;
            $requestId=trim((string)($result['request_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$requestId))continue;
            $ok=!empty($result['ok']);$http=max(100,min(599,(int)($result['status']??($ok?200:500))));
            $payload=is_array($result['payload']??null)?$result['payload']:[];
            $json=json_encode($payload,JSON_UNESCAPED_SLASHES);if(!is_string($json))$json='{}';
            $pdo->prepare("UPDATE homeserver_https_requests SET status=?,response_status=?,response_json=?,completed_at=UTC_TIMESTAMP()
              WHERE public_id=? AND user_id=? AND device_id=? AND status IN ('queued','delivered')")
              ->execute([$ok?'completed':'failed',$http,$json,$requestId,$userId,$deviceId]);
        }

        $pdo->prepare("UPDATE homeserver_https_sessions SET installed_version=?,capabilities_json=?,last_seen_at=UTC_TIMESTAMP(),status='active' WHERE user_id=?")
          ->execute([$version,$capsJson,$userId]);
        $pdo->prepare("UPDATE homeserver_connections SET status='paired',installed_version=?,last_seen_at=UTC_TIMESTAMP(),last_checked_at=UTC_TIMESTAMP(),last_error='',capabilities_json=? WHERE user_id=?")
          ->execute([$version,$capsJson,$userId]);

        $pdo->prepare("UPDATE homeserver_https_requests SET status='expired' WHERE user_id=? AND status IN ('queued','delivered') AND expires_at<=UTC_TIMESTAMP()")
          ->execute([$userId]);

        $q=$pdo->prepare("SELECT public_id,operation,payload_json FROM homeserver_https_requests
          WHERE user_id=? AND device_id=? AND status='queued' AND expires_at>UTC_TIMESTAMP()
          ORDER BY id LIMIT 8 FOR UPDATE");
        $q->execute([$userId,$deviceId]);$rows=$q->fetchAll()?:[];
        $home='';
        $conn=homeserver_vp3_connection($userId);
        if($conn)$home=homeserver_vp3_decrypt((string)($conn['homeserver_token_enc']??''));
        $requests=[];
        foreach($rows as $row){
            $pdo->prepare("UPDATE homeserver_https_requests SET status='delivered',delivered_at=UTC_TIMESTAMP() WHERE public_id=? AND status='queued'")
              ->execute([(string)$row['public_id']]);
            $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))$payload=[];
            $requests[]=['request_id'=>(string)$row['public_id'],'operation'=>(string)$row['operation'],'payload'=>$payload,'bearer_token'=>$home];
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    return ['ok'=>true,'transport'=>'vp3_https','requests'=>$requests,'poll_after_ms'=>900,'server_time'=>gmdate(DATE_ATOM)];
}

function homeserver_https_v1300_queue(int $userId,string $operation,array $payload=[]): string
{
    $operation=trim($operation);
    if($userId<1||!preg_match('/^[a-z0-9._-]{1,80}$/',$operation))throw new RuntimeException('HomeServer operation is invalid.');
    $session=homeserver_https_v1300_session($userId);
    if(!$session||(string)$session['status']!=='active')throw new RuntimeException('HomeServer HTTPS session is not active.');
    $last=!empty($session['last_seen_at'])?strtotime((string)$session['last_seen_at'].' UTC'):false;
    if(!$last||(time()-$last)>VP3_HOMESERVER_HTTPS_ONLINE_SECONDS)throw new RuntimeException('HomeServer is offline.');
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES);if(!is_string($json))throw new RuntimeException('HomeServer request payload is invalid.');
    if(strlen($json)>262144)throw new RuntimeException('HomeServer request payload is too large.');
    $id=homeserver_https_v1300_uuid();
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare("INSERT INTO homeserver_https_requests(public_id,user_id,device_id,operation,payload_json,status,expires_at)
      VALUES (?,?,?,?,?,'queued',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))")
      ->execute([$id,$userId,(string)$session['device_id'],$operation,$json,VP3_HOMESERVER_HTTPS_REQUEST_TTL_SECONDS]);
    return $id;
}

function homeserver_https_v1300_wait(string $requestId,int $timeoutMs=24000): array
{
    $timeoutMs=max(1000,min(28000,$timeoutMs));$deadline=microtime(true)+($timeoutMs/1000);
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    while(microtime(true)<$deadline){
        $q=$pdo->prepare("SELECT status,response_status,response_json FROM homeserver_https_requests WHERE public_id=? LIMIT 1");
        $q->execute([$requestId]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('HomeServer HTTPS request was not found.');
        $status=(string)$row['status'];
        if(in_array($status,['completed','failed'],true)){
            $payload=json_decode((string)($row['response_json']??''),true);if(!is_array($payload))$payload=[];
            if($status==='failed'||(int)$row['response_status']<200||(int)$row['response_status']>=300){
                $detail=trim((string)($payload['detail']??$payload['error']??''));
                throw new RuntimeException($detail!==''?$detail:'HomeServer request failed.');
            }
            return $payload;
        }
        if($status==='expired')throw new RuntimeException('HomeServer request expired.');
        usleep(250000);
    }
    throw new RuntimeException('HomeServer request timed out.');
}

function homeserver_https_v1300_remote_operation(int $userId,string $operation,array $payload=[]): array
{
    return homeserver_https_v1300_wait(homeserver_https_v1300_queue($userId,$operation,$payload));
}

function homeserver_https_v1300_status(int $userId): ?array
{
    $session=homeserver_https_v1300_session($userId);if(!$session)return null;
    $last=!empty($session['last_seen_at'])?strtotime((string)$session['last_seen_at'].' UTC'):false;
    $connected=(string)$session['status']==='active'&&$last&&(time()-$last)<=VP3_HOMESERVER_HTTPS_ONLINE_SECONDS;
    $caps=json_decode((string)($session['capabilities_json']??''),true);if(!is_array($caps))$caps=[];
    return [
      'transport'=>'vp3_https','connected'=>(bool)$connected,'paired'=>(string)$session['status']==='active',
      'device_id'=>(string)$session['device_id'],'last_seen_at'=>$session['last_seen_at']??null,
      'installed_version'=>(string)$session['installed_version'],'capabilities'=>$caps,
      'error'=>$connected?'':((string)$session['status']==='revoked'?'HomeServer pairing is revoked.':'HomeServer is offline.'),
    ];
}

function homeserver_https_v1300_revoke(int $userId,bool $delete=false): void
{
    $pdo=db();if(!$pdo)return;
    if(function_exists('table_exists')&&!table_exists('homeserver_https_sessions'))return;
    $pdo->prepare("UPDATE homeserver_https_requests SET status='expired' WHERE user_id=? AND status IN ('queued','delivered')")->execute([$userId]);
    if($delete)$pdo->prepare("DELETE FROM homeserver_https_sessions WHERE user_id=?")->execute([$userId]);
    else $pdo->prepare("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=?")->execute([$userId]);
}
