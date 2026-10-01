<?php
declare(strict_types=1);
/** Short-lived owner-approved, HomeServer-initiated device-code authorization.
 *  The local device retains the high-entropy verifier; the Cloud claim page
 *  never receives it. Completed pairing still uses the canonical HTTPS v1.3
 *  pairing endpoint and existing account-bound single-use tokens.
 */
require_once __DIR__.'/homeserver-account-pairing-v1210.php';

const HS_DEVICE_V1_TTL = 900;
function hs_device_v1_code(string $value): string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
    if (!preg_match('/^[A-HJ-NP-Z2-9]{12}$/', $code)) throw new RuntimeException('Use the 12-character code shown by HomeServer.');
    return $code;
}
function hs_device_v1_verifier(string $value): string {
    if (!preg_match('/^[A-Za-z0-9_-]{40,96}$/D', $value)) throw new RuntimeException('Device authorization is invalid.');
    return $value;
}
function hs_device_v1_identity(string $value): string {
    $id = strtolower(trim($value));
    if (!preg_match('/^hs-[a-f0-9]{24}$/D', $id)) throw new RuntimeException('HomeServer device identity is invalid.');
    return $id;
}
function hs_device_v1_hash(string $code): string {
    return hash('sha256', 'hs-device-v1:'.hs_device_v1_code($code));
}
function hs_device_v1_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_device_codes_v1 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code_hash CHAR(64) NOT NULL,
        verifier_hash CHAR(64) NOT NULL,
        device_id VARCHAR(40) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'pending',
        user_id INT UNSIGNED NULL,
        pairing_token_enc LONGTEXT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_hs_device_code (code_hash),
        INDEX idx_hs_device_ip (ip_hash,created_at),
        INDEX idx_hs_device_expiry (expires_at,state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function hs_device_v1_start(string $code, string $verifier, string $deviceId, string $ip): array {
    $hash=hs_device_v1_hash($code);
    $verifierHash=hash('sha256',hs_device_v1_verifier($verifier));
    $id=hs_device_v1_identity($deviceId);
    $pdo=db();if(!$pdo)throw new RuntimeException('Device pairing is temporarily unavailable.');
    hs_device_v1_schema($pdo);
    $ipHash=hash('sha256','hs-device-ip-v1:'.$ip);
    $q=$pdo->prepare("SELECT COUNT(*) FROM homeserver_device_codes_v1 WHERE ip_hash=? AND created_at>DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)");
    $q->execute([$ipHash]);if((int)$q->fetchColumn()>=8)throw new RuntimeException('Too many new pairing requests. Try again later.');
    $q=$pdo->prepare("INSERT INTO homeserver_device_codes_v1 (code_hash,verifier_hash,device_id,ip_hash,state,expires_at)
       VALUES (?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))");
    try {$q->execute([$hash,$verifierHash,$id,$ipHash]);}
    catch(PDOException $e){throw new RuntimeException('That pairing code is unavailable. Generate another code.',0,$e);}
    return ['state'=>'pending','expires_in_seconds'=>HS_DEVICE_V1_TTL];
}
function hs_device_v1_poll(string $code,string $verifier,string $deviceId): array {
    $hash=hs_device_v1_hash($code);$verifyHash=hash('sha256',hs_device_v1_verifier($verifier));
    $id=hs_device_v1_identity($deviceId);
    $pdo=db();if(!$pdo)throw new RuntimeException('Device pairing is temporarily unavailable.');
    hs_device_v1_schema($pdo);
    $q=$pdo->prepare("SELECT verifier_hash,device_id,state,expires_at,pairing_token_enc FROM homeserver_device_codes_v1 WHERE code_hash=? LIMIT 1");
    $q->execute([$hash]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row||!hash_equals((string)$row['verifier_hash'],$verifyHash)||!hash_equals((string)$row['device_id'],$id))
        throw new RuntimeException('Device authorization is invalid.');
    if(strtotime((string)$row['expires_at'].' UTC')<=time())return ['state'=>'expired'];
    $state=(string)$row['state'];
    if($state==='claimed'&&!empty($row['pairing_token_enc'])) {
        return ['state'=>'claimed','pairing_token'=>homeserver_vp3_decrypt((string)$row['pairing_token_enc'])];
    }
    return ['state'=>in_array($state,['pending','complete'],true)?$state:'pending'];
}
function hs_device_v1_complete(string $code,string $verifier,string $deviceId): array {
    // Authenticated by the verifier from the original local registration.
    $state=hs_device_v1_poll($code,$verifier,$deviceId);
    $pdo=db();if(!$pdo)throw new RuntimeException('Device pairing is temporarily unavailable.');
    $q=$pdo->prepare("UPDATE homeserver_device_codes_v1 SET state='complete',pairing_token_enc=NULL WHERE code_hash=? AND verifier_hash=? AND device_id=? AND state='claimed'");
    $q->execute([hs_device_v1_hash($code),hash('sha256',hs_device_v1_verifier($verifier)),hs_device_v1_identity($deviceId)]);
    return ['state'=>'complete'];
}
function hs_device_v1_claim(int $userId,string $input): array {
    if($userId<1)throw new RuntimeException('Sign in to VP3 Cloud first.');
    $hash=hs_device_v1_hash($input);
    $pdo=db();if(!$pdo)throw new RuntimeException('Device pairing is temporarily unavailable.');
    hs_device_v1_schema($pdo);
    $q=$pdo->prepare("UPDATE homeserver_device_codes_v1 SET state='claiming',user_id=? WHERE code_hash=? AND state='pending' AND expires_at>UTC_TIMESTAMP()");
    $q->execute([$userId,$hash]);
    if($q->rowCount()!==1)throw new RuntimeException('That code has expired or has already been claimed. Ask HomeServer for a new code.');
    try {
        // Existing Cloud implementation enforces one active HomeServer per account.
        $token=homeserver_account_v1210_generate_token($userId);
        $secret=homeserver_vp3_encrypt((string)$token['token']);
        $q=$pdo->prepare("UPDATE homeserver_device_codes_v1 SET state='claimed',pairing_token_enc=? WHERE code_hash=? AND state='claiming' AND user_id=?");
        $q->execute([$secret,$hash,$userId]);
        if($q->rowCount()!==1)throw new RuntimeException('Could not complete device authorization.');
        return ['state'=>'claimed','expires_at'=>(string)$token['expires_at']];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE homeserver_device_codes_v1 SET state='pending',user_id=NULL WHERE code_hash=? AND state='claiming' AND user_id=?")->execute([$hash,$userId]);
        throw $e;
    }
}
