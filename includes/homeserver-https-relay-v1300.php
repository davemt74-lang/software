<?php
declare(strict_types=1);

class HomeServerHttpsSessionError extends RuntimeException {}

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
      transient_context TINYINT(1) NOT NULL DEFAULT 0,
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
      INDEX idx_homeserver_https_transient_expiry (transient_context,expires_at,id),
      CONSTRAINT fk_homeserver_https_request_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_https_v1300_schema_ready(): bool
{
    return function_exists('table_exists')&&table_exists('homeserver_https_sessions')&&table_exists('homeserver_https_requests');
}

/** Existing installations enable this opt-in only after the additive migration. */
function homeserver_https_v1300_transient_ready(PDO $pdo): bool
{
    try{
        if($pdo->query('SELECT transient_context FROM homeserver_https_requests WHERE 1=0')!==false)return true;
        $failure=$pdo->errorInfo();
    }catch(Throwable $error){$failure=$error instanceof PDOException&&is_array($error->errorInfo)?$error->errorInfo:[];}
    $state=(string)($failure[0]??'');$code=(int)($failure[1]??0);$detail=(string)($failure[2]??'');
    // Only an absent migration can use legacy request semantics. A denied or
    // failed metadata read cannot prove that existing requests are nonprivate.
    if(($state==='42S02'&&$code===1146&&preg_match("/\\ATable '[^']+\\.homeserver_https_requests' doesn't exist\\z/",$detail))
        ||($state==='42S22'&&$code===1054&&$detail==="Unknown column 'transient_context' in 'field list'")
        ||($state==='HY000'&&$code===1&&in_array($detail,['no such table: homeserver_https_requests','no such column: transient_context'],true)))return false;
    // Driver errors can include SQL, credentials or request content. Do not
    // retain the original exception in a publicly logged exception chain.
    throw new RuntimeException('Transient HomeServer metadata is unavailable.',503);
}

/** Bounded physical removal also covers a caller that died before its finally. */
function homeserver_https_v1300_scrub_transient(PDO $pdo,int $limit=100,?int $userId=null): int
{
    if(!homeserver_https_v1300_transient_ready($pdo))return 0;
    if($userId!==null&&$userId<1)return 0;
    $limit=max(1,min(1000,$limit));$owner=$userId===null?'':' AND user_id=?';
    $q=$pdo->prepare("SELECT id FROM homeserver_https_requests WHERE transient_context=1
        AND (expires_at<=UTC_TIMESTAMP() OR status='expired'){$owner} ORDER BY id LIMIT {$limit}");
    $q->execute($userId===null?[]:[$userId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(!$ids)return 0;
    $q=$pdo->prepare('DELETE FROM homeserver_https_requests WHERE transient_context=1 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')
        AND (expires_at<=UTC_TIMESTAMP() OR status=\'expired\')'.$owner);
    $q->execute($userId===null?$ids:array_merge($ids,[$userId]));
    return $q->rowCount();
}

function homeserver_https_v1300_transient_session(array $expected,?array $current): bool
{
    return $current!==null&&($current['status']??'')==='active'
        &&(int)($expected['user_id']??0)>0&&(int)$expected['user_id']===(int)($current['user_id']??0)
        &&is_string($expected['device_id']??null)&&$expected['device_id']!==''
        &&is_string($expected['session_token_hash']??null)&&$expected['session_token_hash']!==''
        &&hash_equals($expected['device_id'],(string)($current['device_id']??''))
        &&hash_equals($expected['session_token_hash'],(string)($current['session_token_hash']??''));
}

/** Consume the existing relay response with the same owner/session authority. */
function homeserver_https_v1300_wait_transient(string $requestId,int $userId,array $session,int $timeoutMs=24000): array
{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if($userId<1||(int)($session['user_id']??0)!==$userId
        ||!homeserver_https_v1300_transient_ready($pdo))throw new RuntimeException('Transient HomeServer inference is unavailable.');
    if($pdo->inTransaction())throw new RuntimeException('Transient HomeServer inference cannot wait inside a transaction.');
    $timeoutMs=max(1000,min(28000,$timeoutMs));$deadline=microtime(true)+$timeoutMs/1000;$ownsRequest=false;
    try{
        while(microtime(true)<$deadline){
            $pdo->beginTransaction();
            try{
                $lock=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
                $owner=$lock->fetch(PDO::FETCH_ASSOC);
                if(!$owner)throw new RuntimeException('HomeServer account authorization changed.',403);
                $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1 FOR UPDATE');$q->execute([$userId]);
                if(!homeserver_https_v1300_transient_session($session,$q->fetch(PDO::FETCH_ASSOC)?:null))
                    throw new RuntimeException('HomeServer session authorization changed.',403);
                $q=$pdo->prepare("SELECT status,response_status,response_json,expires_at FROM homeserver_https_requests
                    WHERE public_id=? AND user_id=? AND device_id=? AND operation='agent.infer.local' AND transient_context=1 LIMIT 1 FOR UPDATE");
                $q->execute([$requestId,$userId,$session['device_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if(!$row)throw new RuntimeException('HomeServer transient request was not found.');
                $ownsRequest=true;
                if((int)($owner['is_active']??0)!==1)throw new RuntimeException('HomeServer account authorization changed.',403);
                $expired=$pdo->prepare('SELECT 1 FROM homeserver_https_requests WHERE public_id=? AND user_id=? AND expires_at<=UTC_TIMESTAMP()');
                $expired->execute([$requestId,$userId]);
                if($expired->fetchColumn()||$row['status']==='expired')throw new RuntimeException('HomeServer transient request expired.');
                if(in_array($row['status'],['completed','failed'],true)){
                    $payload=json_decode((string)($row['response_json']??''),true);if(!is_array($payload))$payload=[];
                    $http=(int)$row['response_status'];
                    // The remote error text may itself contain the supplied private context.
                    if($row['status']==='failed'||$http<200||$http>=300)
                        throw new RuntimeException('HomeServer transient inference failed.',$http>=400&&$http<=599?$http:503);
                    $pdo->commit();return $payload;
                }
                $pdo->commit();
            }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
            usleep(250000);
        }
        throw new RuntimeException('HomeServer transient request timed out.');
    }finally{
        // Deletion is scoped to the exact request; a late poll cannot recreate it.
        // Do not suppress cleanup failure and return a misleading success.
        if($ownsRequest){
            $q=$pdo->prepare("DELETE FROM homeserver_https_requests WHERE public_id=? AND user_id=? AND device_id=?
                AND operation='agent.infer.local' AND transient_context=1");
            $q->execute([$requestId,$userId,$session['device_id']]);
        }
    }
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

function homeserver_https_v1300_pair(string $pairingToken,string $deviceId,string $homeServerToken,string $version='',array $capabilities=[],string $recoverySessionToken=''): array
{
    $deviceId=strtolower(trim($deviceId));
    if(!preg_match('/^hs-[a-f0-9]{24}$/D',$deviceId))throw new RuntimeException('HomeServer device identity is invalid.');
    $homeServerToken=trim($homeServerToken);
    if(strlen($homeServerToken)<32||strlen($homeServerToken)>512)throw new RuntimeException('HomeServer local authorization is invalid.');
    // An optional device-generated secret makes lost acknowledgements replayable.
    // Cloud keeps only its hash. Replay requires every original binding and TTL.
    if($recoverySessionToken!==''&&!preg_match('/^[A-Za-z0-9_-]{64}$/D',$recoverySessionToken))
        throw new RuntimeException('HomeServer pairing token recovery proof is invalid.');
    $tokenHash=homeserver_account_v1210_hash($pairingToken);
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    homeserver_account_v1210_ensure_schema($pdo);
    homeserver_https_v1300_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT user_id FROM homeserver_pairing_tokens WHERE token_hash=? LIMIT 1');
    $q->execute([$tokenHash]);$userId=(int)$q->fetchColumn();
    if($userId<1)throw new RuntimeException('The VP3 pairing token is invalid or expired.');
    $sessionToken=$recoverySessionToken!==''?$recoverySessionToken:homeserver_https_v1300_token();
    $hash=hash('sha256',$sessionToken);
    $capsJson=json_encode($capabilities,JSON_UNESCAPED_SLASHES);
    if(!is_string($capsJson))throw new RuntimeException('HomeServer capabilities are invalid.');
    $replayed=false;
    $pdo->beginTransaction();
    try{
        // Account lock serializes token generation, redemption and first pairing.
        $q=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);
        if(!$q->fetchColumn())throw new RuntimeException('The VP3 pairing token account is unavailable.');
        $q=$pdo->prepare('SELECT * FROM homeserver_pairing_tokens WHERE token_hash=? LIMIT 1 FOR UPDATE');
        $q->execute([$tokenHash]);$tokenRow=$q->fetch(PDO::FETCH_ASSOC);
        if(!$tokenRow||(int)$tokenRow['user_id']!==$userId||strtotime((string)$tokenRow['expires_at'].' UTC')<=time())
            throw new RuntimeException('The VP3 pairing token is invalid or expired.');
        $q=$pdo->prepare('SELECT * FROM homeserver_connections WHERE user_id=? LIMIT 1 FOR UPDATE');
        $q->execute([$userId]);$connection=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1 FOR UPDATE');
        $q->execute([$userId]);$session=$q->fetch(PDO::FETCH_ASSOC);
        if((string)$tokenRow['status']==='paired'){
            $replayed=$recoverySessionToken!==''&&$connection&&$session
                &&(string)$connection['status']==='paired'&&(string)$session['status']==='active'
                &&hash_equals((string)$tokenRow['device_id'],$deviceId)
                &&hash_equals((string)$connection['device_id'],$deviceId)
                &&hash_equals((string)$session['device_id'],$deviceId)
                &&hash_equals((string)$session['session_token_hash'],$hash)
                &&hash_equals(homeserver_vp3_decrypt((string)$connection['homeserver_token_enc']),$homeServerToken);
            if(!$replayed)throw new RuntimeException('The VP3 pairing token has already been used or revoked.');
        }else{
            if((string)$tokenRow['status']!=='pending')throw new RuntimeException('The VP3 pairing token has already been used or revoked.');
            if($connection||$session)throw new RuntimeException('This VP3 account already has a HomeServer connection.');
            // Plain INSERT also prevents a device collision from overwriting another account.
            $pdo->prepare("INSERT INTO homeserver_https_sessions
              (user_id,device_id,session_token_hash,status,installed_version,capabilities_json,last_seen_at)
              VALUES (?,?,?,'active',?,?,UTC_TIMESTAMP())")
              ->execute([$userId,$deviceId,$hash,mb_strimwidth($version,0,64,''),$capsJson]);
            $pdo->prepare("INSERT INTO homeserver_connections
              (user_id,device_id,relay_token_enc,homeserver_token_enc,pending_request_id,pending_claim_token_enc,pending_code,status,installed_version,last_seen_at,last_checked_at,last_error,capabilities_json)
              VALUES (?,?,NULL,?,'',NULL,'','paired',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'',?)")
              ->execute([$userId,$deviceId,homeserver_vp3_encrypt($homeServerToken),mb_strimwidth($version,0,64,''),$capsJson]);
            $q=$pdo->prepare("UPDATE homeserver_pairing_tokens SET status='paired',device_id=?,redeemed_at=COALESCE(redeemed_at,UTC_TIMESTAMP()) WHERE id=? AND status='pending'");
            $q->execute([$deviceId,(int)$tokenRow['id']]);
            if($q->rowCount()!==1)throw new RuntimeException('The VP3 pairing token could not be finalized.');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    // A projection failure after commit must never turn successful pairing into an error.
    if(!$replayed&&function_exists('vp3_cognitive_homeserver_event_v2390')){
        try{vp3_cognitive_homeserver_event_v2390($pdo,$userId,'homeserver.connected','paired');}
        catch(Throwable $e){error_log('HomeServer pairing projection failed: '.get_class($e));}
    }
    return [
      'user_id'=>$userId,'device_id'=>$deviceId,'session_token'=>$sessionToken,
      'poll_url'=>VP3_HOMESERVER_HTTPS_POLL_URL,'cloud_version'=>VP3_HOMESERVER_RELEASE_VERSION,
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

    // Keep all schema/DDL work outside the polling transaction. MySQL DDL
    // implicitly commits active transactions even when CREATE TABLE uses IF NOT EXISTS.
    homeserver_https_v1300_ensure_schema($pdo);
    $transientReady=homeserver_https_v1300_transient_ready($pdo);

    $pdo->beginTransaction();
    try{
        // Pair, poll and revoke serialize on the same account authority. The
        // preflight authentication snapshot cannot authorize later writes.
        $lock=$pdo->prepare('SELECT id'.($transientReady?',is_active':'').' FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
        $owner=$lock->fetch(PDO::FETCH_ASSOC);
        $transientOwnerActive=!$transientReady||(int)($owner['is_active']??0)===1;
        $live=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1 FOR UPDATE');
        $live->execute([$userId]);$current=$live->fetch(PDO::FETCH_ASSOC);
        if(!$current || !hash_equals((string)$current['device_id'],$deviceId)
            || !hash_equals((string)$current['session_token_hash'],(string)($session['session_token_hash']??'')))
            throw new HomeServerHttpsSessionError('HomeServer HTTPS session is not authorized.',401);
        if((string)$current['status']!=='active')
            throw new HomeServerHttpsSessionError('HomeServer HTTPS session was revoked.',410);
        if($transientReady){
            if(!$transientOwnerActive)$pdo->prepare('DELETE FROM homeserver_https_requests WHERE user_id=? AND transient_context=1')->execute([$userId]);
            else homeserver_https_v1300_scrub_transient($pdo,1000,$userId);
        }
        foreach((array)($body['results']??[]) as $result){
            if(!is_array($result))continue;
            $requestId=trim((string)($result['request_id']??''));
            if(!preg_match('/^[0-9a-f-]{36}$/i',$requestId))continue;
            $ok=!empty($result['ok']);$http=max(100,min(599,(int)($result['status']??($ok?200:500))));
            $payload=is_array($result['payload']??null)?$result['payload']:[];
            $json=json_encode($payload,JSON_UNESCAPED_SLASHES);if(!is_string($json))$json='{}';
            $transientFence=$transientReady?($transientOwnerActive?' AND (transient_context=0 OR (transient_context=1 AND operation=\'agent.infer.local\' AND expires_at>UTC_TIMESTAMP()))':' AND transient_context=0'):'';
            $clearRequest=$transientReady?",payload_json=IF(transient_context=1,'{}',payload_json)":'';
            $pdo->prepare("UPDATE homeserver_https_requests SET status=?,response_status=?,response_json=?,completed_at=UTC_TIMESTAMP(){$clearRequest}
              WHERE public_id=? AND user_id=? AND device_id=? AND status IN ('queued','delivered'){$transientFence}")
              ->execute([$ok?'completed':'failed',$http,$json,$requestId,$userId,$deviceId]);
        }

        $pdo->prepare("UPDATE homeserver_https_sessions SET installed_version=?,capabilities_json=?,last_seen_at=UTC_TIMESTAMP() WHERE user_id=? AND status='active'")
          ->execute([$version,$capsJson,$userId]);
        $pdo->prepare("UPDATE homeserver_connections SET status='paired',installed_version=?,last_seen_at=UTC_TIMESTAMP(),last_checked_at=UTC_TIMESTAMP(),last_error='',capabilities_json=? WHERE user_id=?")
          ->execute([$version,$capsJson,$userId]);

        $pdo->prepare("UPDATE homeserver_https_requests SET status='expired' WHERE user_id=? AND status IN ('queued','delivered') AND expires_at<=UTC_TIMESTAMP()")
          ->execute([$userId]);

        $transientColumn=$transientReady?',transient_context':'';
        $transientFence=$transientReady&&!$transientOwnerActive?' AND transient_context=0':'';
        $q=$pdo->prepare("SELECT public_id,operation,payload_json{$transientColumn} FROM homeserver_https_requests
          WHERE user_id=? AND device_id=? AND status='queued' AND expires_at>UTC_TIMESTAMP(){$transientFence}
          ORDER BY id LIMIT 8 FOR UPDATE");
        $q->execute([$userId,$deviceId]);$rows=$q->fetchAll()?:[];
        $home='';
        // Query the already-ensured connection table directly. Do not call
        // homeserver_vp3_connection() here because it performs schema DDL.
        $connQ=$pdo->prepare("SELECT homeserver_token_enc FROM homeserver_connections WHERE user_id=? LIMIT 1");
        $connQ->execute([$userId]);$conn=$connQ->fetch();
        if($conn)$home=homeserver_vp3_decrypt((string)($conn['homeserver_token_enc']??''));
        $requests=[];
        foreach($rows as $row){
            $payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))$payload=[];
            $clearRequest=!empty($row['transient_context'])?",payload_json='{}'":'';
            if(!empty($row['transient_context'])&&$row['operation']!=='agent.infer.local')throw new RuntimeException('Transient HomeServer operation is invalid.');
            $pdo->prepare("UPDATE homeserver_https_requests SET status='delivered',delivered_at=UTC_TIMESTAMP(){$clearRequest} WHERE public_id=? AND status='queued'")
              ->execute([(string)$row['public_id']]);
            $requests[]=['request_id'=>(string)$row['public_id'],'operation'=>(string)$row['operation'],'payload'=>$payload,'bearer_token'=>$home];
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

    return ['ok'=>true,'transport'=>'vp3_https','cloud_version'=>VP3_HOMESERVER_RELEASE_VERSION,'requests'=>$requests,'poll_after_ms'=>900,'server_time'=>gmdate(DATE_ATOM)];
}

function homeserver_https_v1300_queue(int $userId,string $operation,array $payload=[],bool $transientContext=false,?array $expectedSession=null): string
{
    $operation=trim($operation);
    if($userId<1||!preg_match('/^[a-z0-9._-]{1,80}$/',$operation))throw new RuntimeException('HomeServer operation is invalid.');
    if($transientContext&&$operation!=='agent.infer.local')throw new RuntimeException('Transient context requires agent.infer.local.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if($transientContext&&!homeserver_https_v1300_transient_ready($pdo))throw new RuntimeException('Transient HomeServer inference requires the database upgrade.');
    if($transientContext&&$pdo->inTransaction())throw new RuntimeException('Transient HomeServer inference cannot queue inside a transaction.');
    $session=homeserver_https_v1300_session($userId);
    if(!$session||(string)$session['status']!=='active')throw new RuntimeException('HomeServer HTTPS session is not active.');
    $last=!empty($session['last_seen_at'])?strtotime((string)$session['last_seen_at'].' UTC'):false;
    if(!$last||(time()-$last)>VP3_HOMESERVER_HTTPS_ONLINE_SECONDS)throw new RuntimeException('HomeServer is offline.');
    $json=json_encode($payload,JSON_UNESCAPED_SLASHES);if(!is_string($json))throw new RuntimeException('HomeServer request payload is invalid.');
    if(strlen($json)>262144)throw new RuntimeException('HomeServer request payload is too large.');
    $id=homeserver_https_v1300_uuid();
    if($transientContext){
        $expectedSession??=$session;
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id,is_active FROM users WHERE id=? FOR UPDATE');$q->execute([$userId]);
            $owner=$q->fetch(PDO::FETCH_ASSOC);
            if(!$owner||(int)($owner['is_active']??0)!==1)throw new RuntimeException('HomeServer account authorization changed.',403);
            $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=? LIMIT 1 FOR UPDATE');$q->execute([$userId]);
            $current=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if(!homeserver_https_v1300_transient_session($expectedSession,$current))throw new RuntimeException('HomeServer session authorization changed.',403);
            $last=strtotime((string)($current['last_seen_at']??'').' UTC')?:0;
            if($last<time()-VP3_HOMESERVER_HTTPS_ONLINE_SECONDS||$last>time()+5)throw new RuntimeException('HomeServer is offline.');
            homeserver_https_v1300_scrub_transient($pdo,100,$userId);
            $pdo->prepare("INSERT INTO homeserver_https_requests(public_id,user_id,device_id,operation,payload_json,transient_context,status,expires_at)
                VALUES (?,?,?,?,?,1,'queued',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))")
                ->execute([$id,$userId,(string)$current['device_id'],$operation,$json,VP3_HOMESERVER_HTTPS_REQUEST_TTL_SECONDS]);
            $pdo->commit();return $id;
        }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
    }
    $pdo->prepare("INSERT INTO homeserver_https_requests(public_id,user_id,device_id,operation,payload_json,status,expires_at)
      VALUES (?,?,?,?,?,'queued',DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND))")
      ->execute([$id,$userId,(string)$session['device_id'],$operation,$json,VP3_HOMESERVER_HTTPS_REQUEST_TTL_SECONDS]);
    return $id;
}

function homeserver_https_v1300_wait(string $requestId,int $timeoutMs=24000): array
{
    $timeoutMs=max(1000,min(28000,$timeoutMs));$deadline=microtime(true)+($timeoutMs/1000);
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $transientColumn=function_exists('homeserver_https_v1300_transient_ready')&&homeserver_https_v1300_transient_ready($pdo)?',transient_context':'';
    while(microtime(true)<$deadline){
        $q=$pdo->prepare("SELECT status,response_status,response_json{$transientColumn} FROM homeserver_https_requests WHERE public_id=? LIMIT 1");
        $q->execute([$requestId]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('HomeServer HTTPS request was not found.');
        if(!empty($row['transient_context']))throw new RuntimeException('Transient HomeServer request requires its scoped consumer.');
        $status=(string)$row['status'];
        if(in_array($status,['completed','failed'],true)){
            $payload=json_decode((string)($row['response_json']??''),true);if(!is_array($payload))$payload=[];
            if($status==='failed'||(int)$row['response_status']<200||(int)$row['response_status']>=300){
                $detail=trim((string)($payload['detail']??$payload['error']??''));
                $remoteStatus=(int)$row['response_status'];
                throw new RuntimeException($detail!==''?$detail:'HomeServer request failed.',
                    $remoteStatus>=400&&$remoteStatus<=599?$remoteStatus:503);
            }
            return $payload;
        }
        if($status==='expired')throw new RuntimeException('HomeServer request expired.');
        usleep(250000);
    }
    throw new RuntimeException('HomeServer request timed out.');
}

function homeserver_https_v1300_remote_operation(int $userId,string $operation,array $payload=[],bool $transientContext=false): array
{
    if($transientContext){
        if($operation!=='agent.infer.local')throw new RuntimeException('Transient context requires agent.infer.local.');
        $session=homeserver_https_v1300_session($userId);
        if(!$session)throw new RuntimeException('HomeServer HTTPS session is not active.');
        return homeserver_https_v1300_wait_transient(homeserver_https_v1300_queue($userId,$operation,$payload,true,$session),$userId,$session);
    }
    return homeserver_https_v1300_wait(homeserver_https_v1300_queue($userId,$operation,$payload));
}

function homeserver_https_v1300_status(int $userId): ?array
{
    $session=homeserver_https_v1300_session($userId);if(!$session)return null;
    $last=!empty($session['last_seen_at'])?strtotime((string)$session['last_seen_at'].' UTC'):false;
    $connected=(string)$session['status']==='active'&&$last&&(time()-$last)<=VP3_HOMESERVER_HTTPS_ONLINE_SECONDS;
    $caps=json_decode((string)($session['capabilities_json']??''),true);if(!is_array($caps))$caps=[];
    return [
      'transport'=>'vp3_https','cloud_version'=>VP3_HOMESERVER_RELEASE_VERSION,'connected'=>(bool)$connected,'paired'=>(string)$session['status']==='active',
      'device_id'=>(string)$session['device_id'],'last_seen_at'=>$session['last_seen_at']??null,
      'installed_version'=>(string)$session['installed_version'],'capabilities'=>$caps,
      'error'=>$connected?'':((string)$session['status']==='revoked'?'HomeServer pairing is revoked.':'HomeServer is offline.'),
    ];
}

function homeserver_https_v1300_revoke(int $userId,bool $delete=false): void
{
    $pdo=db();if(!$pdo)return;
    if(function_exists('table_exists')&&!table_exists('homeserver_https_sessions'))return;
    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction)$pdo->beginTransaction();
    try{
        $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$userId]);
        if(homeserver_https_v1300_transient_ready($pdo))
            $pdo->prepare('DELETE FROM homeserver_https_requests WHERE user_id=? AND transient_context=1')->execute([$userId]);
        $pdo->prepare("UPDATE homeserver_https_requests SET status='expired' WHERE user_id=? AND status IN ('queued','delivered')")->execute([$userId]);
        if($delete)$pdo->prepare("DELETE FROM homeserver_https_sessions WHERE user_id=?")->execute([$userId]);
        else $pdo->prepare("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=?")->execute([$userId]);
        if($ownsTransaction)$pdo->commit();
    }catch(Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
