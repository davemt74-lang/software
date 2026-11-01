<?php
declare(strict_types=1);

/** PH3I-B: signed, replay-safe heartbeat from the optional HomeServer launcher.
 * A heartbeat never proves LiveKit media connectivity or authorizes a call.
 */
function vp3_phone_worker_health_ready_v171(): bool
{
    return table_exists('phone_voice_worker_health_v171') && table_exists('phone_voice_worker_nonces_v171');
}

function vp3_phone_worker_health_ensure_v171(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_voice_worker_health_v171 (
      worker_id VARCHAR(64) NOT NULL PRIMARY KEY,
      boot_id CHAR(32) NOT NULL,
      last_nonce CHAR(32) NOT NULL,
      preflight_ready TINYINT(1) NOT NULL DEFAULT 0,
      last_seen_epoch BIGINT UNSIGNED NOT NULL,
      KEY idx_phone_worker_seen (last_seen_epoch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS phone_voice_worker_nonces_v171 (
      nonce CHAR(32) NOT NULL PRIMARY KEY,
      seen_epoch BIGINT UNSIGNED NOT NULL,
      KEY idx_phone_worker_nonce_age (seen_epoch)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** Verifies the exact signed bytes before interpreting worker-supplied fields. */
function vp3_phone_worker_health_verify_v171(string $raw,string $stamp,string $signature,
    string $secret,int $now): array|false
{
    if (strlen($raw)<2 || strlen($raw)>1024 || strlen($secret)<32
        || !preg_match('/^[0-9]{10}$/D',$stamp) || abs($now-(int)$stamp)>90
        || !preg_match('/^[a-f0-9]{64}$/D',$signature)
        || !hash_equals(hash_hmac('sha256',$stamp.'.'.$raw,$secret),$signature)) return false;
    $body=json_decode($raw,true);
    if (!is_array($body)||count($body)!==5
        || array_diff(array_keys($body),['worker_id','boot_id','nonce','phase','preflight_ready'])) return false;
    if (!is_string($body['worker_id'])
        || !preg_match('/^[a-zA-Z0-9_-]{3,64}$/D',$body['worker_id'])
        || !is_string($body['boot_id']) || !preg_match('/^[a-f0-9]{32}$/D',$body['boot_id'])
        || !is_string($body['nonce']) || !preg_match('/^[a-f0-9]{32}$/D',$body['nonce'])
        || $body['phase']!=='process_reporting' || !is_bool($body['preflight_ready'])) return false;
    return $body;
}

/** Atomic nonce claim and worker update. Failure is never treated as healthy. */
function vp3_phone_worker_health_record_v171(PDO $pdo,array $body,int $now): bool
{
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM phone_voice_worker_nonces_v171 WHERE seen_epoch<?')
            ->execute([$now-900]);
        $nonce=$pdo->prepare('INSERT INTO phone_voice_worker_nonces_v171 (nonce,seen_epoch) VALUES (?,?)');
        if (!$nonce->execute([$body['nonce'],$now])) throw new RuntimeException('Nonce claim failed.');
        $q=$pdo->prepare('UPDATE phone_voice_worker_health_v171
            SET boot_id=?,last_nonce=?,preflight_ready=?,last_seen_epoch=? WHERE worker_id=?');
        if (!$q->execute([$body['boot_id'],$body['nonce'],(int)$body['preflight_ready'],$now,$body['worker_id']]))
            throw new RuntimeException('Worker update failed.');
        if ($q->rowCount()===0) {
            $q=$pdo->prepare('INSERT INTO phone_voice_worker_health_v171
                (worker_id,boot_id,last_nonce,preflight_ready,last_seen_epoch) VALUES (?,?,?,?,?)');
            if (!$q->execute([$body['worker_id'],$body['boot_id'],$body['nonce'],
                    (int)$body['preflight_ready'],$now])) throw new RuntimeException('Worker insert failed.');
        }
        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

function vp3_phone_worker_health_state_v171(PDO $pdo,int $now): array
{
    if (!vp3_phone_worker_health_ready_v171())
        return ['schema_ready'=>false,'workers'=>[],'live_certified'=>false];
    $rows=$pdo->query('SELECT worker_id,preflight_ready,last_seen_epoch
        FROM phone_voice_worker_health_v171 ORDER BY last_seen_epoch DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    $workers=[];
    foreach ($rows as $row) {
        $seen=(int)$row['last_seen_epoch'];$age=max(0,$now-$seen);
        $workers[]=['id'=>(string)$row['worker_id'],'status'=>$age<=90?'reporting':'stale',
            'age_seconds'=>$age,'preflight_ready'=>(int)$row['preflight_ready']===1,
            'last_seen_utc'=>gmdate('Y-m-d H:i:s',$seen).' UTC'];
    }
    return ['schema_ready'=>true,'workers'=>$workers,'live_certified'=>false];
}
