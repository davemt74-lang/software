<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';
require_once dirname(__DIR__).'/includes/permissions.php';

// The real permission helpers must see the same transaction as the phone service.
function db(): ?PDO
{
    global $db;
    return $db;
}

function phone_test_db(string $dsn): PDO
{
    return new PDO($dsn, getenv('VP3_TEST_MYSQL_USER') ?: 'root', (string)getenv('VP3_TEST_MYSQL_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function phone_test_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "ok - $message\n";
}

function phone_test_dispatch(PDO $db, array $job): array
{
    [$principal, $identity, $action, $input] = $job;
    try {
        $result = $principal === 'owner'
            ? vp3_phone_owner_v1($db, $identity, $action, $input)
            : vp3_phone_device_v1($db, $identity, $action, $input);
        return ['status'=>200, 'data'=>$result];
    } catch (PDOException $exception) {
        throw $exception;
    } catch (RuntimeException $exception) {
        return ['status'=>(int)$exception->getCode()];
    }
}

// Each child establishes its own database connection before the barrier opens.
if (($argv[1] ?? '') === 'client') {
    $db = phone_test_db((string)getenv('VP3_PHONE_TEST_ISOLATED_DSN'));
    $job = json_decode((string)fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
    echo "READY\n";
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'GO') exit(2);
    echo json_encode(phone_test_dispatch($db, $job), JSON_THROW_ON_ERROR)."\n";
    exit;
}

function phone_test_race(array ...$jobs): array
{
    $clients = [];
    try {
        foreach ($jobs as $job) {
            $process = proc_open([PHP_BINARY, __FILE__, 'client'], [
                0=>['pipe','r'], 1=>['pipe','w'], 2=>STDERR,
            ], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Unable to start test client.');
            $clients[] = [$process, $pipes[0], $pipes[1]];
            stream_set_timeout($pipes[1], 25);
            fwrite($pipes[0], json_encode($job, JSON_THROW_ON_ERROR)."\n");
        }
        foreach ($clients as [, , $stdout]) {
            if (trim((string)fgets($stdout)) !== 'READY') throw new RuntimeException('Test client did not reach the barrier.');
        }
        foreach ($clients as [, $stdin]) fwrite($stdin, "GO\n");
        $results = [];
        foreach ($clients as [, , $stdout]) {
            $results[] = json_decode((string)fgets($stdout), true, flags: JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($clients as [$process, $stdin, $stdout]) {
            fclose($stdin);
            fclose($stdout);
            proc_close($process);
        }
    }
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('Configure VP3_TEST_MYSQL_DSN for an isolated MySQL fixture.');
$schema = 'vp3_phone_test_'.bin2hex(random_bytes(8));
$serverDsn = preg_replace('/(?:^mysql:|;)dbname=[^;]*(?:;|$)/',
    str_starts_with(substr($dsn, 6), 'dbname=') ? 'mysql:' : ';', $dsn);
$server = phone_test_db($serverDsn);
$server->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $isolatedDsn = rtrim($serverDsn, ';').';dbname='.$schema;
    putenv('VP3_PHONE_TEST_ISOLATED_DSN='.$isolatedDsn);
    $db = phone_test_db($isolatedDsn);
    $db->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY, display_name VARCHAR(120) NOT NULL, is_active TINYINT NOT NULL DEFAULT 1,
        role VARCHAR(32) NOT NULL DEFAULT 'fan') ENGINE=InnoDB");
    $db->exec('CREATE TABLE permissions(permission_key VARCHAR(64) PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE role_permissions(role VARCHAR(32), permission_key VARCHAR(64), PRIMARY KEY(role, permission_key)) ENGINE=InnoDB');
    $db->exec("INSERT INTO permissions VALUES ('chat.access'),('account.access')");
    $db->exec("INSERT INTO role_permissions VALUES ('fan','chat.access'),('fan','account.access')");
    $db->exec('CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec('CREATE TABLE chat_messages(id BIGINT UNSIGNED PRIMARY KEY, conversation_id BIGINT UNSIGNED NOT NULL,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec("INSERT INTO users(id,display_name) VALUES(1,'Test Owner'),(2,'Other Owner')");
    vp3_phone_schema_v1($db);
    vp3_phone_chat_schema_v1($db);
    vp3_phone_schema_v1($db);
    vp3_phone_chat_schema_v1($db);
    phone_test_check(vp3_phone_schema_ready_v1($db) && vp3_phone_chat_schema_ready_v1($db),
        'repeat installation of phone and private chat tables is safe on a native MySQL database');
    $owner = fn(string $action, array $input) => vp3_phone_owner_v1($db, 1, $action, $input);
    $request = fn() => bin2hex(random_bytes(12));
    $invite = $owner('invite', ['request_id'=>$request()])['invite'];
    $makeClaim = fn(string $name) => ['device', '', 'claim', ['invite'=>$invite, 'name'=>$name, 'claim_key'=>bin2hex(random_bytes(32))]];
    $claims = phone_test_race($makeClaim('Test Phone One'), $makeClaim('Test Phone Two'));
    $successful = array_values(array_filter($claims, fn($r) => $r['status'] === 200));
    $rejected = array_values(array_filter($claims, fn($r) => in_array($r['status'], [403,404,409], true)));
    phone_test_check(count($successful) === 1 && count($rejected) === 1, 'simultaneous claims by different phones consume one invitation once');
    $device = $successful[0]['data']['device'];
    $token = $successful[0]['data']['token'];
    $deviceId = $device['id'];
    $input = fn(int $generation) => ['device_id'=>$deviceId, 'generation'=>$generation, 'request_id'=>$request()];
    $approval = $input($device['generation']) + ['role'=>'owner', 'allow_location'=>true];
    $results = phone_test_race(['owner',1,'approve',$approval], ['owner',1,'approve',$approval]);
    phone_test_check(array_column($results,'status') === [200,200]
        && (int)$results[0]['data']['duplicate'] + (int)$results[1]['data']['duplicate'] === 1,
        'simultaneous delivery of the same approval has a single effect');
    $state = fn() => $owner('state', [])['devices'];
    $device = $state()[0];
    phone_test_check(count($state()) === 1 && $device['status'] === 'approved'
        && !vp3_phone_owner_v1($db, 2, 'state', [])['devices'], 'paired phone belongs only to its approving account');
    $asked = $owner('request_start',$input($device['generation']))['device'];
    $results = phone_test_race(['device',$token,'start',['generation'=>$asked['generation'],'device_id'=>$deviceId]],
        ['owner',1,'stop',$input($asked['generation'])]);
    phone_test_check(in_array($results[0]['status'], [200,403,409], true) && $results[1]['status'] === 200
        && $state()[0]['location_state'] === 'off', 'stop wins over concurrent holder acceptance of a pending request');
    $makeFix = fn(int $generation) => ['device_id'=>$deviceId,'generation'=>$generation,'latitude'=>33.4501,'longitude'=>-112.0712,'accuracy'=>11.0,'fix_at'=>time()];
    $startWithFix = function () use ($owner,$input,$state,$db,$deviceId,$token,$makeFix): array {
        $asked = $owner('request_start', $input($state()[0]['generation']))['device'];
        $device = vp3_phone_device_v1($db, $token, 'start', ['generation'=>$asked['generation'],'device_id'=>$deviceId])['device'];
        $fix = $makeFix($device['generation']);
        vp3_phone_device_v1($db, $token, 'fix', $fix);
        return $fix;
    };
    foreach (['account.access','chat.access'] as $permission) {
        $fix = $startWithFix();
        vp3_phone_sql_v1($db, "DELETE FROM role_permissions WHERE role='fan' AND permission_key=?", [$permission]);
        $deniedReads = phone_test_dispatch($db, ['owner',1,'state',[]])['status'] === 403
            && phone_test_dispatch($db, ['device',$token,'status',[]])['status'] === 403;
        $deniedWrite = phone_test_dispatch($db, ['device',$token,'fix',$fix])['status'] === 403;
        vp3_phone_sql_v1($db, "INSERT INTO role_permissions VALUES ('fan',?)", [$permission]);
        $restored = $state()[0];
        phone_test_check($deniedReads && $deniedWrite && $restored['id'] === $deviceId
            && $restored['generation'] > $fix['generation'] && $restored['location_state'] === 'off'
            && $restored['fix_at'] === null && $restored['latitude'] === null && $restored['longitude'] === null,
            "removing $permission blocks owner and holder access and restored access cannot revive the old fix");
    }
    $fix = $startWithFix();
    $db->exec('UPDATE users SET is_active=0 WHERE id=1');
    $blocked = phone_test_dispatch($db, ['owner',1,'state',[]])['status'] === 403
        && phone_test_dispatch($db, ['device',$token,'fix',$fix])['status'] === 403;
    $db->exec('UPDATE users SET is_active=1 WHERE id=1');
    $restored = $state()[0];
    phone_test_check($blocked && $restored['id'] === $deviceId && $restored['generation'] > $fix['generation']
        && $restored['location_state'] === 'off' && $restored['fix_at'] === null,
        'a disabled account cannot receive or read GPS and its old grant stays off after reactivation');
    $fix = $startWithFix();
    $gen = $fix['generation'];
    // A distinct sample within the allowed device clock skew exercises the write path.
    $newFix = array_replace($fix, ['latitude'=>33.4502,'fix_at'=>$fix['fix_at'] + 1]);
    $results = phone_test_race(['device',$token,'fix',$newFix], ['owner',1,'stop',$input($gen)]);
    $late = phone_test_dispatch($db, ['device',$token,'fix',$makeFix($gen)]);
    $after = $state()[0];
    phone_test_check(in_array($results[0]['status'], [200,403,409], true) && $results[1]['status'] === 200
        && in_array($late['status'], [403,409], true) && $after['location_state'] === 'off'
        && $after['latitude'] === null && $after['longitude'] === null && $after['fix_at'] === null,
        'stop erases the live position and rejects delayed callbacks from the previous grant');
    $asked = $owner('request_start',$input($after['generation']))['device'];
    $gen = vp3_phone_device_v1($db, $token, 'start', ['generation'=>$asked['generation'],'device_id'=>$deviceId])['device']['generation'];
    $future = phone_test_dispatch($db, ['owner',1,'revoke',$input($gen + 1)]);
    phone_test_check($future['status'] === 409 && $state()[0]['generation'] === $gen,
        'a future generation cannot revoke a pairing');
    $observed = $after['generation'];
    $results = phone_test_race(['device',$token,'fix',$makeFix($gen)],
        ['device',$token,'stop',['device_id'=>$deviceId,'generation'=>$gen]],
        ['owner',1,'revoke',$input($observed)]);
    $afterRevoke = phone_test_dispatch($db, ['device',$token,'status',[]]);
    phone_test_check($observed < $gen && $results[2]['status'] === 200 && $results[2]['data']['device']['status'] === 'revoked'
        && in_array($results[0]['status'], [200,403,409], true) && in_array($results[1]['status'], [200,403,409], true)
        && $afterRevoke['status'] === 403 && $state() === [],
        'revoking the same pairing from an older owner view wins over a concurrent holder fix and stop');
    $invite = $owner('invite', ['request_id'=>$request()])['invite'];
    $phone = vp3_phone_device_v1($db, '', 'claim', ['invite'=>$invite, 'name'=>'Temporary phone']);
    $terminal = ['device_id'=>$phone['device']['id'], 'generation'=>$phone['device']['generation']];
    $db->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
    $end = phone_test_dispatch($db, ['device',$phone['token'],'disconnect',$terminal]);
    $db->exec("INSERT INTO role_permissions VALUES ('fan','chat.access')");
    phone_test_check($end['status'] === 403 && $state() === []
        && phone_test_dispatch($db, ['device',$phone['token'],'status',[]])['status'] === 403,
        'the holder can end the pairing during a permission suspension');
    $invite = $owner('invite', ['request_id'=>$request()])['invite'];
    $phone = vp3_phone_device_v1($db, '', 'claim', ['invite'=>$invite, 'name'=>'Deletion check']);
    $db->exec('DELETE FROM users WHERE id=1');
    $tables = ['vp3_phone_invites_v1','vp3_phone_devices_v1','vp3_phone_requests_v1'];
    foreach ($tables as $table) {
        phone_test_check(!(int)$db->query("SELECT COUNT(*) FROM $table WHERE owner_user_id=1")->fetchColumn(),
            "deleting the account removes its rows from $table");
    }
    phone_test_check(phone_test_dispatch($db, ['device',$phone['token'],'status',[]])['status'] === 403,
        'deleting the account invalidates previously connected phones');
    echo "PASS Native MySQL phone pairing, consent and revocation races.\n";
} finally {
    $server->exec("DROP DATABASE `$schema`");
}
