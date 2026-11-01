<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/phone-worker-health-v171.php';
$checks=0;
function verify_worker(bool $ok,string $why): void {global $checks;if (!$ok)throw new RuntimeException($why);$checks++;}
$now=1793537000;$secret=str_repeat('s',48);
$payload=['worker_id'=>'vp3-phone','boot_id'=>str_repeat('a',32),'nonce'=>str_repeat('b',32),
    'phase'=>'process_reporting','preflight_ready'=>true];
$raw=json_encode($payload,JSON_THROW_ON_ERROR);$stamp=(string)$now;
$sig=hash_hmac('sha256',$stamp.'.'.$raw,$secret);
$validated=vp3_phone_worker_health_verify_v171($raw,$stamp,$sig,$secret,$now);
verify_worker($validated===$payload,'valid signed payload');
verify_worker(vp3_phone_worker_health_verify_v171($raw,$stamp,$sig,$secret,$now+91)===false,'stale timestamp refused');
verify_worker(vp3_phone_worker_health_verify_v171($raw,$stamp,str_repeat('0',64),$secret,$now)===false,'forged signature refused');
verify_worker(vp3_phone_worker_health_verify_v171($raw,$stamp,$sig,'short',$now)===false,'short worker secret refused');
verify_worker(vp3_phone_worker_health_verify_v171($raw,'invalid',$sig,$secret,$now)===false,'malformed timestamp refused');
foreach ([['worker_id'=>'bad/id'],['boot_id'=>'not_hex'],['nonce'=>'missing'],
    ['phase'=>'live_connected'],['preflight_ready'=>'true'],['auth_token'=>'never-store-me']] as $changes) {
    $bad=json_encode([...$payload,...$changes],JSON_THROW_ON_ERROR);
    $signed=hash_hmac('sha256',$stamp.'.'.$bad,$secret);
    verify_worker(vp3_phone_worker_health_verify_v171($bad,$stamp,$signed,$secret,$now)===false,
        'malformed or extraneous worker data refused');
}
$private='injected-private-signing-credential';
verify_worker(!str_contains((string)json_encode($validated),$secret)
    && !str_contains((string)json_encode($validated),$private),'secret absent from parsed record');

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE phone_voice_worker_health_v171 (worker_id TEXT PRIMARY KEY,
    boot_id TEXT NOT NULL,last_nonce TEXT NOT NULL,preflight_ready INTEGER NOT NULL,last_seen_epoch INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE phone_voice_worker_nonces_v171 (nonce TEXT PRIMARY KEY,seen_epoch INTEGER NOT NULL)');
function table_exists(string $name): bool {
    global $pdo;
    $q=$pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type=? AND name=?');
    $q->execute(['table',$name]);return (bool)$q->fetchColumn();
}
verify_worker(vp3_phone_worker_health_record_v171($pdo,$payload,$now),'first nonce accepted');
verify_worker(!vp3_phone_worker_health_record_v171($pdo,$payload,$now+1),'replayed nonce rejected');
verify_worker((int)$pdo->query('SELECT last_seen_epoch FROM phone_voice_worker_health_v171')->fetchColumn()===$now,
    'rejected replay does not renew liveness');
$second=[...$payload,'nonce'=>str_repeat('c',32),'preflight_ready'=>false];
verify_worker(vp3_phone_worker_health_record_v171($pdo,$second,$now+10),'new nonce updates worker');
$state=vp3_phone_worker_health_state_v171($pdo,$now+20);
verify_worker($state['workers'][0]['status']==='reporting','fresh launcher visible');
verify_worker(!$state['workers'][0]['preflight_ready'],'failed local preflight visible');
verify_worker(!$state['live_certified'],'heartbeat never certifies real audio');
$stale=vp3_phone_worker_health_state_v171($pdo,$now+102);
verify_worker($stale['workers'][0]['status']==='stale','stale launcher never presented as online');
verify_worker(count($stale['workers'])===1,'no duplicate worker records');
echo 'PHONE_WORKER_PH3IB_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
