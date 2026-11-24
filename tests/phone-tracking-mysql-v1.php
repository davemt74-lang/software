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

/** Use the production private Chat entry points on each child's own connection. */
function phone_test_chat_identity(PDO $db, int $owner): array
{
    require_once dirname(__DIR__).'/includes/functions.php';
    require_once dirname(__DIR__).'/includes/auth.php';
    require_once dirname(__DIR__).'/includes/personal-capabilities-v242.php';
    require_once dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
    require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
    $user = vp3_phone_sql_v1($db,'SELECT * FROM users WHERE id=?',[$owner])->fetch();
    $GLOBALS['__stonefellow_current_user'] = $user;
    $_SERVER['HTTP_X_VP3_WORKING_ATTENTION'] = vp3_catch_up_page_binding_v1($user);
    $principal = ['kind'=>'system','agent_id'=>0,'owner_user_id'=>$owner,'viewer_user_id'=>$owner];
    return [$user,$principal];
}

function phone_test_chat(PDO $db, int $owner, array $input, bool $send): array
{
    [$user,$principal] = phone_test_chat_identity($db,$owner);
    $input['agent_context'] = ['user_agent_id'=>0];
    if (!$send) return vp3_phone_chat_updates_v1($db,$user,$principal,$input + ['phone_updates'=>true]);
    $plan = vp3_phone_chat_route_v1($db,$user,$principal,$input);
    if ($plan === null) throw new RuntimeException('The local research command did not reach the phone Chat handler.');
    return vp3_phone_chat_send_v1($db,$user,null,$principal,$input,$plan,null);
}

/** Reproduce the pre-research schema in dependency order without disabling referential integrity. */
function phone_test_remove_local_research(PDO $db): void
{
    $db->exec('DROP TABLE IF EXISTS vp3_phone_local_approvals_v1');
    $db->exec('DROP TABLE IF EXISTS vp3_phone_local_research_v1');
    $db->exec('DROP TABLE IF EXISTS vp3_phone_local_offers_v1');
    $db->exec('DROP TABLE IF EXISTS vp3_phone_arrival_search_intents_v1');
}

function phone_test_dispatch(PDO $db, array $job): array
{
    [$principal, $identity, $action, $input] = $job;
    try {
        if ($principal === 'installer') {
            vp3_phone_schema_v1($db);
            vp3_phone_chat_schema_v1($db);
            return ['status'=>200,'ready'=>vp3_phone_schema_ready_v1($db) && vp3_phone_chat_schema_ready_v1($db)];
        }
        if ($principal === 'owner_reply') {
            [$user,$chatPrincipal]=phone_test_chat_identity($db,$identity);
            return ['status'=>200,'data'=>vp3_paired_phone_owner_reply_v1($db,$user,$chatPrincipal,$input)];
        }
        $result = match ($principal) {
            'owner' => vp3_phone_owner_v1($db,$identity,$action,$input),
            'places' => vp3_phone_places_owner_v1($db,$identity,$action,$input),
            'arrival_poll','research_chat' => phone_test_chat($db,$identity,$input,$principal === 'research_chat'),
            'camera' => vp3_phone_camera_owner_v1($db, $identity, 0, 41, $action, $input),
            'record_owner' => vp3_phone_record_owner_v1($db, $identity, 0, 41, $action, $input),
            'record_holder' => vp3_phone_record_holder_v1($db, $identity, $action, $input),
            'record_upload' => vp3_phone_record_chunk_v1($db, $identity, $input, file_get_contents(__DIR__.'/fixtures/phone-camera-recording-vp8.webm')),
            'device' => vp3_phone_device_v1($db,$identity,$action,$input),
        };
        return ['status'=>200, 'data'=>$result];
    } catch (PDOException $exception) {
        throw $exception;
    } catch (RuntimeException $exception) {
        return ['status'=>(int)$exception->getCode()];
    }
}

// Each child establishes its own database connection before the barrier opens.
if (($argv[1] ?? '') === 'client') {
    define('STONEFELLOW_ROOT',(string)getenv('VP3_PHONE_TEST_MEDIA_ROOT'));
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
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __FILE__, 'client'], [
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
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/'.$schema);
putenv('VP3_PHONE_TEST_MEDIA_ROOT='.STONEFELLOW_ROOT);
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
    $db->exec('CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id INT UNSIGNED NULL, updated_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec('CREATE TABLE chat_messages(id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, conversation_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL, role VARCHAR(12) NOT NULL, message TEXT NOT NULL, context_json LONGTEXT NOT NULL,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE) ENGINE=InnoDB');
    $db->exec("INSERT INTO users(id,display_name) VALUES(1,'Test Owner'),(2,'Other Owner')");
    $db->exec(file_get_contents(dirname(__DIR__).'/upgrade-stonefellow-v86.sql'));
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
    require_once __DIR__.'/phone-communication-profile-cases-v1.php';
    vp3_test_phone_communication_v1($db,1,2,$deviceId,$token,'phone_test_check');
    require_once __DIR__.'/phone-information-exchange-cases-v1.php';
    vp3_test_phone_information_v1($db,1,2,$deviceId,$token,'phone_test_check');
    require_once __DIR__.'/phone-information-file-cases-v1.php';
    vp3_test_phone_files_v1($db,1,2,$deviceId,$token,'phone_test_check');
    require_once __DIR__.'/phone-remote-dialer-cases-v1.php';
    vp3_test_phone_dialer_v1($db,1,2,$deviceId,$token,'phone_test_check');
    // Restore the Phase 1 layout around a real approved phone and its existing approval receipt.
    $db->exec('ALTER TABLE vp3_phone_devices_v1 DROP COLUMN update_interval_seconds, DROP COLUMN accuracy_mode, DROP COLUMN preferences_version,
        DROP COLUMN holder_can_save_places, DROP COLUMN holder_place_version');
    $upgradeConnection = phone_test_db($isolatedDsn);
    phone_test_check(!vp3_phone_schema_ready_v1($upgradeConnection), 'Phase 1 schema reports that a settings upgrade is needed');
    $installer = ['installer',null,'upgrade',[]];
    $upgrades = phone_test_race($installer,$installer);
    phone_test_check(array_column($upgrades,'ready') === [true,true],
        'two administrators can upgrade the populated Phase 1 schema concurrently');
    vp3_phone_schema_v1($upgradeConnection);
    $migrated = vp3_phone_device_v1($db,$token,'status',[])['device'];
    phone_test_check(vp3_phone_schema_ready_v1($upgradeConnection) && $owner('approve',$approval)['duplicate']
        && $migrated['id'] === $deviceId && $migrated['generation'] === $device['generation'] && $migrated['status'] === 'approved'
        && !$migrated['holder_can_save_places'] && $migrated['holder_place_version'] === 1
        && $migrated['preferences_version'] === 1 && $migrated['update_interval_seconds'] === 5 && $migrated['accuracy_mode'] === 'high',
        'upgrading preserves the paired phone, holder credential, approval receipt and original reporting defaults');
    $setPreferences = fn(int $interval) => ['owner',1,'set_preferences',$input($device['generation'])
        + ['preferences_version'=>1,'update_interval_seconds'=>$interval,'accuracy_mode'=>'balanced']];
    $writes = phone_test_race($setPreferences(15),$setPreferences(30));
    $statuses = array_column($writes,'status');
    sort($statuses);
    $settings = $state()[0];
    phone_test_check($statuses === [200,409] && $settings['preferences_version'] === 2
        && $settings['generation'] === $device['generation'] && $settings['location_state'] === 'off',
        'competing settings saves produce exactly one edit and leave sharing off');
    phone_test_remove_local_research($db);
    $db->exec('DROP TABLE vp3_phone_arrival_events_v1');
    $db->exec('DROP TABLE vp3_phone_arrival_rules_v1');
    $db->exec('DROP TABLE vp3_phone_history_points_v1, vp3_phone_places_v1');
    $db->exec('ALTER TABLE vp3_phone_devices_v1 DROP COLUMN history_state, DROP COLUMN history_retention_days,
        DROP COLUMN history_version, DROP COLUMN history_accepted_at, DROP COLUMN places_generation, DROP COLUMN place_permission_version');
    phone_test_check(!vp3_phone_schema_ready_v1(phone_test_db($isolatedDsn)), 'a populated Phase 2 install requests an upgrade');
    $upgrades = phone_test_race($installer,$installer);
    $upgraded = vp3_phone_device_v1($db,$token,'status',[])['device'];
    phone_test_check(array_column($upgrades,'ready') === [true,true] && $upgraded['id'] === $deviceId
        && $upgraded['generation'] === $settings['generation'] && $upgraded['update_interval_seconds'] === $settings['update_interval_seconds']
        && $upgraded['preferences_version'] === $settings['preferences_version'] && $upgraded['history_state'] === 'off'
        && !$upgraded['allow_saved_places'] && !$upgraded['history_retention_days'],
        'Phase 3 upgrade keeps the paired holder and its preferences while starting with both retention permissions off');
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
    // Each worker holds its own PDO connection. A delayed upload can still update
    // the live map after trail deletion, but must never recreate a retained point.
    $live = $startWithFix();
    $scope = ['device_id'=>$deviceId,'generation'=>$live['generation']];
    $pendingHistory = $owner('request_history',$input($live['generation']) + [
        'retention_days'=>7,'history_version'=>$state()[0]['history_version'],
    ])['device'];
    $acceptHistory = $scope + ['history_version'=>$pendingHistory['history_version']];
    $history = vp3_phone_device_v1($db,$token,'accept_history',$acceptHistory)['device'];
    $retainedFix = array_replace($live, ['fix_at'=>time() + 1,'history_version'=>$history['history_version']]);
    vp3_phone_device_v1($db,$token,'fix',$retainedFix);
    phone_test_check(count(vp3_phone_history_read_v1($db,1,$deviceId)['points']) === 1,
        'only a new fix following the holder’s approval enters history');
    $erase = $input($live['generation']) + ['history_version'=>$history['history_version']];
    $nextFix = array_replace($retainedFix,['fix_at'=>$retainedFix['fix_at'] + 1]);
    $race = phone_test_race(['device',$token,'fix',$nextFix], ['owner',1,'delete_history',$erase]);
    $lateFix = array_replace($nextFix,['fix_at'=>$nextFix['fix_at'] + 1]);
    $liveAgain = vp3_phone_device_v1($db,$token,'fix',$lateFix)['device'];
    phone_test_check(array_column($race,'status') === [200,200] && $owner('delete_history',$erase)['duplicate']
        && $liveAgain['location_state'] === 'live' && $liveAgain['fix_at'] === $lateFix['fix_at']
        && $liveAgain['history_state'] === 'off' && !vp3_phone_history_read_v1($db,1,$deviceId)['points'],
        'concurrent delete and upload, then a delayed upload, leave the trail empty and live GPS working');

    $permission = vp3_phone_device_v1($db,$token,'set_place_permission',$scope + [
        'place_permission_version'=>$liveAgain['place_permission_version'],'allow_saved_places'=>true
    ])['device'];
    $save = ['places',1,'save_place',$input($permission['generation']) + ['name'=>'Studio']];
    $sameSave = phone_test_race($save,$save);
    $savedPlaces = fn() => vp3_phone_places_owner_v1($db,1,'places')['places'];
    phone_test_check(array_column($sameSave,'status') === [200,200] && count($savedPlaces()) === 1
        && $sameSave[0]['data']['place']['id'] === $sameSave[1]['data']['place']['id']
        && (int)$sameSave[0]['data']['duplicate'] + (int)$sameSave[1]['data']['duplicate'] === 1,
        'simultaneous saved place retries create one place and one receipt on MySQL');
    $withdraw = ['device',$token,'set_place_permission',$scope + [
        'place_permission_version'=>$permission['place_permission_version'],'allow_saved_places'=>false
    ]];
    $newPlace = ['places',1,'save_place',$input($permission['generation']) + ['name'=>'At withdrawal']];
    [$withdrawal,$atWithdrawal] = phone_test_race($withdraw,$newPlace);
    $nextPlace = ['places',1,'save_place',$input($permission['generation']) + ['name'=>'After withdrawal']];
    $afterWithdrawal = phone_test_dispatch($db,$nextPlace);
    phone_test_check($withdrawal['status'] === 200 && !$withdrawal['data']['device']['allow_saved_places']
        && in_array($atWithdrawal['status'],[200,403],true) && $afterWithdrawal['status'] === 403
        && count($savedPlaces()) === ($atWithdrawal['status'] === 200 ? 2 : 1)
        && in_array('Studio',array_column($savedPlaces(),'name'),true),
        'concurrent withdrawal and save respect commit order; existing places survive and subsequent saves are rejected');
    // Upgrade a Phase 3 installation that already holds a real paired phone and a saved place.
    phone_test_remove_local_research($db);
    $db->exec('DROP TABLE vp3_phone_arrival_events_v1');
    $db->exec('DROP TABLE vp3_phone_arrival_rules_v1');
    phone_test_check(!vp3_phone_schema_ready_v1(phone_test_db($isolatedDsn)), 'Phase 3 reports the missing arrival schema');
    $upgrades = phone_test_race($installer,$installer);
    $holder = vp3_phone_device_v1($db,$token,'status',[])['device'];
    $studio = $sameSave[0]['data']['place'];
    phone_test_check(array_column($upgrades,'ready') === [true,true] && $holder['arrival_rules'] === []
        && $holder['id'] === $deviceId && in_array($studio['id'],array_column($savedPlaces(),'id'),true),
        'concurrent Phase 4 upgrades preserve the saved place and live phone without granting an alert');

    $owner('stop',$input($live['generation']));
    $live = $startWithFix();
    $db->exec('INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (41,1,NULL)');
    $rule = vp3_phone_arrival_request_v1($db,1,0,41,$deviceId,$live['generation'],$studio['id'],'recording');
    $ruleInput = ['device_id'=>$deviceId, 'generation'=>$live['generation'], 'rule_id'=>$rule['id'], 'rule_version'=>$rule['version']];
    $holding = vp3_phone_device_v1($db,$token,'accept_arrival',$ruleInput)['device'];
    $active = array_column($holding['arrival_rules'],null,'id')[$rule['id']];
    phone_test_check($active['state'] === 'active' && $holding['history_state'] === 'off', 'arrival grant is usable with trail history off');
    $lastSample = max(time(),$live['fix_at']);
    $nextSample = static function () use (&$lastSample): int {
        // Use genuine sensor seconds, including when the MySQL runner operates on a fast clock.
        while (time() <= $lastSample) usleep(50000);
        return $lastSample = time();
    };
    $outside = array_replace($live,['fix_at'=>$nextSample(),'latitude'=>$studio['latitude'] + 0.02,
        'longitude'=>$studio['longitude'],'accuracy'=>5.0,'arrival_grants'=>[$rule['id']=>$active['version']]]);
    vp3_phone_device_v1($db,$token,'fix',$outside);
    $inside = array_replace($outside,['fix_at'=>$nextSample(),'latitude'=>$studio['latitude']]);
    $arrive = ['device',$token,'fix',$inside];
    $twoInside = phone_test_race($arrive,$arrive);
    $events = static fn() => (int)vp3_phone_sql_v1($db,'SELECT COUNT(*) FROM vp3_phone_arrival_events_v1 WHERE rule_id=?',[$rule['id']])->fetchColumn();
    phone_test_check(array_column($twoInside,'status') === [200,200] && $events() === 1,
        'two concurrent reports of the same arrival yield one undelivered visit');
    $db->exec("CREATE TABLE agent_memory_items(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
        user_agent_id INT UNSIGNED NULL,memory_scope_version INT NOT NULL DEFAULT 410,is_active TINYINT NOT NULL DEFAULT 1,
        memory_type VARCHAR(24) NOT NULL DEFAULT 'task',subject VARCHAR(180) NOT NULL,memory_text TEXT,metadata_json LONGTEXT) ENGINE=InnoDB");
    $seedTask = $db->prepare('INSERT INTO agent_memory_items(id,user_id,user_agent_id,subject,memory_text,metadata_json) VALUES(?,?,?,?,?,?)');
    $seedTask->execute([1,1,null,'Recording mic check','','{"task_status":"open"}']);
    $seedTask->execute([2,1,null,'Recording completed','','{"task_status":"completed"}']);
    $seedTask->execute([3,1,null,'Recording invalid','','malformed legacy JSON']);
    $seedTask->execute([4,1,7,'Recording in another Agent','',null]);
    $seedTask->execute([5,2,null,'Recording by another owner','',null]);
    $seedTask->execute([6,1,null,'Test room cables','For the recording session',null]);
    $poll = ['arrival_poll',1,'updates',['conversation_id'=>41]];
    $polls = phone_test_race($poll,$poll);
    $announcements = array_merge($polls[0]['data'] ?? [],$polls[1]['data'] ?? []);
    $answer = $announcements[0]['speech'] ?? '';
    phone_test_check(array_column($polls,'status') === [200,200] && count($announcements) === 1 && $events() === 0
        && str_contains($answer,'Recording mic check') && str_contains($answer,'Test room cables')
        && !str_contains($answer,'Recording completed') && !str_contains($answer,'Recording invalid')
        && !str_contains($answer,'another Agent') && !str_contains($answer,'another owner')
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_arrival_briefings_v1')->fetchColumn() === 1,
        'two native private Chat polls emit one arrival with authorized open tasks, including a legacy task without status');

    // Emulate a populated Phase 4 installation with a granted alert and a delivered private briefing.
    phone_test_remove_local_research($db);
    phone_test_check(!vp3_phone_schema_ready_v1(phone_test_db($isolatedDsn)) && !vp3_phone_chat_schema_ready_v1(phone_test_db($isolatedDsn)),
        'Phase 4 phone and private Chat installations report the missing local research upgrade');
    $upgrade = phone_test_race($installer,$installer);
    $legacyRule = vp3_phone_arrival_list_v1($db,1,0,41)[0];
    phone_test_check(array_column($upgrade,'ready') === [true,true] && $legacyRule['id'] === $rule['id']
        && $legacyRule['version'] === $active['version'] && $legacyRule['state'] === 'active'
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_arrival_search_intents_v1')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_offers_v1')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_arrival_briefings_v1')->fetchColumn() === 1,
        'two Phase 5 installers preserve the active Phase 4 grant and briefing without adding any search authorization');

    // Keep a second real visit pending to independently exercise the withdrawal race below.
    vp3_phone_device_v1($db,$token,'fix',array_replace($outside,['fix_at'=>$nextSample()]));
    vp3_phone_device_v1($db,$token,'fix',array_replace($inside,['fix_at'=>$nextSample()]));
    phone_test_check($events() === 1, 'a verified exit and re-entry enqueue the next visit');
    $withdrawRule = array_replace($ruleInput,['rule_version'=>$active['version']]);
    $raced = phone_test_race(['device',$token,'stop_arrival',$withdrawRule],
        ['device',$token,'fix',array_replace($outside,['fix_at'=>$nextSample()])]);
    vp3_phone_device_v1($db,$token,'fix',array_replace($inside,['fix_at'=>$nextSample()]));
    phone_test_check($raced[0]['status'] === 200 && $events() === 0
        && vp3_phone_device_v1($db,$token,'status',[])['device']['arrival_rules'] === []
        && !vp3_phone_history_read_v1($db,1,$deviceId)['points'],
        'holder withdrawal clears an unannounced arrival and a delayed fix cannot revive it');

    // A second alert explicitly names the need and business category. Only its own confirmed visits may produce offers.
    $intent = ['item_term'=>'XLR cables','merchant_category'=>'music stores','open_today'=>true];
    $searchRule = vp3_phone_arrival_request_v1($db,1,0,41,$deviceId,$live['generation'],$studio['id'],'recording',$intent);
    $searchGrant = vp3_phone_device_v1($db,$token,'accept_arrival',[
        'device_id'=>$deviceId,'generation'=>$live['generation'],'rule_id'=>$searchRule['id'],'rule_version'=>$searchRule['version'],
    ])['device'];
    $version = array_column($searchGrant['arrival_rules'],null,'id')[$searchRule['id']]['version'];
    $visitStudio = static function () use($db,$token,$outside,$inside,$nextSample,$searchRule,$version): void {
        $grant = [$searchRule['id']=>$version];
        foreach ([$outside,$inside] as $position) {
            vp3_phone_device_v1($db,$token,'fix',array_replace($position,['fix_at'=>$nextSample(),'arrival_grants'=>$grant]));
        }
    };
    $needId = 71;
    $privateTitle = 'Recording: buy XLR cables for PRIVATE-SESSION-417';
    $seedTask->execute([$needId,1,null,$privateTitle,'Private stage box wiring','{"task_status":"open"}']);
    $seedTask->execute([72,1,7,'Recording: XLR cables in another Agent','',null]);
    $seedTask->execute([73,2,null,'Recording: XLR cables for other owner','',null]);
    $visitStudio();
    $offerPolls = phone_test_race($poll,$poll);
    $offerRows = vp3_phone_sql_v1($db,'SELECT assistant_message_id,visit_number FROM vp3_phone_local_offers_v1 WHERE rule_id=?',[$searchRule['id']])->fetchAll();
    phone_test_check(array_column($offerPolls,'status') === [200,200] && count($offerRows) === 1 && (int)$offerRows[0]['visit_number'] === 1
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn() === 0,
        'one qualifying visit and two simultaneous private polls create one local offer without running research');
    $offerId = (int)$offerRows[0]['assistant_message_id'];
    $say = static fn(string $message,int $conversation = 41,int $uid = 1) => ['research_chat',$uid,'send',[
        'conversation_id'=>$conversation,'message'=>$message,'idempotency_key'=>bin2hex(random_bytes(16)),
    ]];
    $db->exec('INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(42,1,NULL),(43,2,NULL)');
    foreach ([$say("Search area for offer #$offerId: Phoenix, Arizona",42),$say("Search area for offer #$offerId: Phoenix, Arizona",43,2)] as $wrongScope) {
        phone_test_dispatch($db,$wrongScope);
    }
    phone_test_check((int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_approvals_v1')->fetchColumn() === 0,
        'another private conversation and another account cannot stage an area for the offer');
    $preview = phone_test_dispatch($db,$say("Search area for offer #$offerId: Phoenix, Arizona"));
    $previewText = $preview['data']['answer'] ?? '';
    phone_test_check(($preview['status'] ?? 0) === 200 && preg_match('/Approve local search ([A-F0-9]{12})/i',$previewText,$tokenMatch) === 1
        && str_contains($previewText,'Phoenix') && stripos($previewText,'XLR cables') !== false
        && stripos($previewText,'music stores') !== false
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn() === 0,
        'the public area preview lists the permitted search terms before a mission can be queued');
    $approveSearch = 'Approve local search '.$tokenMatch[1];
    $approvals = phone_test_race($say($approveSearch),$say($approveSearch));
    $jobs = $db->query('SELECT offer_id,owner_user_id,agent_id,conversation_id,state,public_json FROM vp3_phone_local_research_v1')->fetchAll();
    $approvalStatuses = array_column($approvals,'status');
    phone_test_check(in_array(200,$approvalStatuses,true) && !array_diff($approvalStatuses,[200,409]) && count($jobs) === 1 && (int)$jobs[0]['offer_id'] === $offerId && $jobs[0]['state'] === 'queued'
        && (int)$jobs[0]['owner_user_id'] === 1 && (int)$jobs[0]['agent_id'] === 0 && (int)$jobs[0]['conversation_id'] === 41,
        'two simultaneous approvals with distinct Chat operation IDs queue exactly one mission for the original owner, Agent and conversation');
    $public = json_decode($jobs[0]['public_json'],true,flags: JSON_THROW_ON_ERROR);
    $publicText = $jobs[0]['public_json'];
    $allowedFields = ['area','item','category','open_today','date'];
    phone_test_check(is_array($public) && !array_diff(array_keys($public),$allowedFields)
        && str_contains($publicText,'Phoenix') && stripos($publicText,'music stores') !== false && stripos($publicText,'XLR cables') !== false
        && !str_contains($publicText,'PRIVATE-SESSION') && !str_contains($publicText,$deviceId) && !str_contains($publicText,$studio['name'])
        && !str_contains($publicText,(string)$studio['latitude']) && !str_contains($publicText,(string)$studio['longitude']),
        'queued research carries the public area and item while private task, saved place, device and GPS stay private');

    // Route the real worker through a deterministic source. Triggering a normal Chat poll must never contact a public provider in this fixture.
    [$localUser,$localPrincipal] = phone_test_chat_identity($db,1);
    $queries = [];
    $reads = [];
    $sourceUrl = 'https://desert.example.com/store';
    $sourcePage = '<html><body><h1>Desert Audio</h1><script type="application/ld+json">'.
        '{"@context":"https://schema.org","@type":"MusicStore","name":"Desert Audio","address":{"@type":"PostalAddress","addressLocality":"Phoenix","addressRegion":"AZ"}}'.
        '</script></body></html>';
    $transport = [
        'search'=>static function (string $query, int $limit, int $timeout) use (&$queries,$sourceUrl): array {
            $queries[] = $query;
            return ['ok'=>true,'results'=>[['title'=>'Desert Audio','url'=>$sourceUrl]]];
        },
        'request'=>static function (string $url,array $domains,callable $guard,?callable $resolver,int $timeout) use (&$reads,$sourcePage): array {
            $guard();
            $reads[] = $url;
            return ['status'=>200,'content_type'=>'text/html','location'=>'','body'=>$sourcePage];
        },
    ];
    $checkOffer = static fn(int $id) => vp3_phone_local_authorized_offer_v1($db,$localUser,$localPrincipal,41,$id);
    vp3_phone_local_research_work_v1($db,$localUser,$localPrincipal,41,$checkOffer,$transport);
    $queued = (int)$db->query("SELECT COUNT(*) FROM vp3_phone_local_research_v1 WHERE state='queued'")->fetchColumn();
    phone_test_check(count($queries) >= 1 && count($reads) >= 1 && $queued === 0
        && !str_contains(implode(' ',$queries),'PRIVATE-SESSION') && !str_contains(implode(' ',$queries),$studio['name']),
        'the native research worker executes an explicitly approved public lookup through the prepared transport');
    $resultPolls = phone_test_race($poll,$poll);
    $answers = array_merge($resultPolls[0]['data'] ?? [],$resultPolls[1]['data'] ?? []);
    $resultId = (int)vp3_phone_sql_v1($db,'SELECT result_message_id FROM vp3_phone_local_approvals_v1 WHERE offer_id=?',[$offerId])->fetchColumn();
    phone_test_check(count($answers) === 1 && str_contains($answers[0]['speech'],'Desert Audio') && $resultId > 0,
        'two simultaneous result polls create one source-backed private result turn');
    $db->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE id=? AND user_id=1')->execute(['{"task_status":"completed"}',$needId]);
    $visitStudio();
    $quietPoll = phone_test_dispatch($db,$poll);
    phone_test_check($quietPoll['status'] === 200
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_offers_v1')->fetchColumn() === 1
        && (int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn() === 1,
        'completing the qualifying task suppresses a new offer and mission, even when another Agent or account still needs the item');
    // A task can be updated in another tab after the area was reviewed.
    $setTaskState = $db->prepare('UPDATE agent_memory_items SET metadata_json=? WHERE user_id=1 AND id=?');
    $setTaskState->execute(['{"task_status":"open"}',$needId]);
    $visitStudio();
    phone_test_dispatch($db,$poll);
    $newOffer = (int)vp3_phone_sql_v1($db,'SELECT assistant_message_id FROM vp3_phone_local_offers_v1 WHERE rule_id=? ORDER BY visit_number DESC LIMIT 1',
        [$searchRule['id']])->fetchColumn();
    phone_test_check($newOffer > $offerId,'reopening the task permits a new offer only on a later visit');
    // Age only the sensor clock, leaving its last coordinates inside and its current visit intact.
    vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET fix_at=? WHERE owner_user_id=1 AND id=?',[time()-3600,$deviceId]);
    $stalePreview = phone_test_dispatch($db,$say("Search area for offer #$newOffer: Tempe, Arizona"));
    phone_test_check(!preg_match('/Approve local search [A-F0-9]{12}/i',$stalePreview['data']['answer'] ?? '')
        && (int)vp3_phone_sql_v1($db,'SELECT COUNT(*) FROM vp3_phone_local_approvals_v1 WHERE offer_id=?',[$newOffer])->fetchColumn() === 0,
        'an old phone fix cannot authorize a public area even though its last recorded position was inside');
    vp3_phone_device_v1($db,$token,'fix',array_replace($inside,[
        'fix_at'=>$nextSample(),'arrival_grants'=>[$searchRule['id']=>$version],
    ]));
    $nextPreview = phone_test_dispatch($db,$say("Search area for offer #$newOffer: Tempe, Arizona"));
    phone_test_check(preg_match('/Approve local search ([A-F0-9]{12})/i',$nextPreview['data']['answer'] ?? '',$laterToken) === 1,
        'the next eligible visit has its own public-area approval');
    $setTaskState->execute(['{"task_status":"completed"}',$needId]);
    $staleApproval = phone_test_dispatch($db,$say('Approve local search '.$laterToken[1]));
    phone_test_check(in_array($staleApproval['status'],[200,403,404,409],true)
        && (int)vp3_phone_sql_v1($db,'SELECT COUNT(*) FROM vp3_phone_local_research_v1 WHERE offer_id=?',[$newOffer])->fetchColumn() === 0,
        'a completed task invalidates an already previewed offer before any new external mission is created');
    vp3_phone_device_v1($db,$token,'stop_arrival',['device_id'=>$deviceId,'generation'=>$live['generation'],
        'rule_id'=>$searchRule['id'],'rule_version'=>$version]);
    phone_test_check((int)$db->query('SELECT COUNT(*) FROM vp3_phone_local_research_v1')->fetchColumn() === 0,
        'withdrawing the holder grant clears the associated research job');

    $owner('stop',$input($live['generation']));
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
    $owner('approve', ['request_id'=>$request(), 'device_id'=>$phone['device']['id'],
        'generation'=>$phone['device']['generation'], 'role'=>'guest','allow_location'=>false]);
    $startCamera = ['camera',1,'request',['device_id'=>$phone['device']['id']]];
    $deviceId=$phone['device']['id'];
    vp3_paired_phone_bind_v1($db,$deviceId,1,0,41);
    $phoneMessage=['device_id'=>$deviceId,'request_id'=>'abc12345-0123-4567-89ab-0123456789ab','message'=>'Which entrance?'];
    $delivered=vp3_paired_phone_chat_v1($db,$phone['token'],'chat_send',$phoneMessage);
    phone_test_check($delivered['turns'][0]['state']==='awaiting_owner','an incoming phone message waits for its owner');
    $ownerAnswer=['device_id'=>$deviceId,'request_id'=>$phoneMessage['request_id'],'agent_id'=>0,'conversation_id'=>41,'message'=>'Use the west door.'];
    $replyJob=['owner_reply',1,'reply',$ownerAnswer];
    $answers=phone_test_race($replyJob,$replyJob);
    $replyId=$answers[0]['data']['reply_message_id']??0;
    $received=vp3_paired_phone_chat_v1($db,$phone['token'],'chat_status',['device_id'=>$deviceId])['turns'][0];
    phone_test_check(array_column($answers,'status')===[200,200] && $replyId>0 && $replyId===$answers[1]['data']['reply_message_id']
        && $received['reply']===$ownerAnswer['message'] && $received['reply_author']==='owner',
        'concurrent retries deliver the same single human reply back to the phone');
    $ownerAnswer['message']='A competing reply';
    phone_test_check(phone_test_dispatch($db,['owner_reply',1,'reply',$ownerAnswer])['status']===409,
        'a second owner tab cannot overwrite an already delivered human reply');
    $cameraRequests = phone_test_race($startCamera,$startCamera);
    $sessionId = $cameraRequests[0]['data']['camera']['session_id'] ?? '';
    phone_test_check(array_column($cameraRequests,'status') === [200,200] && $sessionId !== ''
        && $sessionId === $cameraRequests[1]['data']['camera']['session_id'],
        'concurrent requests select a single preview on a phone with location disabled');
    $session = ['session_id'=>$sessionId];
    phone_test_check(vp3_phone_camera_holder_v1($db,$phone['token'],'status',[])['camera']['state'] === 'requested'
        && vp3_phone_camera_holder_v1($db,$phone['token'],'start',$session)['camera']['state'] === 'sharing',
        'only the paired holder starts the requested camera');
    $offer = "v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\na=sendonly\r\n";
    vp3_phone_camera_holder_v1($db,$phone['token'],'offer',$session+['sdp'=>$offer]);
    vp3_phone_camera_holder_v1($db,$phone['token'],'status',$session+['heartbeat'=>true,'map_source_live'=>true]);
    $mapScope=$session+['device_id'=>$phone['device']['id']];
    $viewerA=$mapScope+['viewer_id'=>bin2hex(random_bytes(16))];
    $viewerB=$mapScope+['viewer_id'=>bin2hex(random_bytes(16))];
    $openResults=phone_test_race(['camera',1,'map_open',$viewerA],['camera',1,'map_open',$viewerB]);
    $statuses=array_column($openResults,'status');
    sort($statuses);
    phone_test_check($statuses===[200,409], 'concurrent map opens grant the viewer slot to exactly one popup');
    [$winner,$other]=$openResults[0]['status']===200 ? [$viewerA,$viewerB] : [$viewerB,$viewerA];
    vp3_phone_camera_owner_v1($db,1,0,41,'map_close',$other);
    $open=vp3_phone_camera_owner_v1($db,1,0,41,'map_status',$winner)['camera'];
    phone_test_check($open['viewer_id']===$winner['viewer_id'], 'a late close from another popup preserves the live viewer');
    $chatRaces=phone_test_race(['camera',1,'chat_open',$viewerA],['camera',1,'chat_open',$viewerB]);
    $chatStatuses=array_column($chatRaces,'status');
    sort($chatStatuses);
    phone_test_check($chatStatuses===[200,409] && vp3_phone_camera_owner_v1($db,1,0,41,'map_status',$winner)['camera']['viewer_id']===$winner['viewer_id'],
        'concurrent inline Chat opens admit one Chat viewer while preserving the map winner');
    $recordRequest=['record_owner',1,'request',$session+['request_id'=>bin2hex(random_bytes(16))]];
    $recordRequests=phone_test_race($recordRequest,$recordRequest);
    $takeId=$recordRequests[0]['data']['recording']['id']??'';
    phone_test_check(array_column($recordRequests,'status')===[200,200] && $takeId!==''
        && $recordRequests[1]['data']['recording']['id']===$takeId,
        'simultaneous retries on one camera create a single holder recording request');
    $take=$session+['recording_id'=>$takeId];
    $consent=phone_test_dispatch($db,['record_holder',$phone['token'],'accept',$take]);
    $recordedTake=$take+['take_id'=>$consent['data']['recording']['take_id']??''];
    $review=phone_test_dispatch($db,['record_holder',$phone['token'],'review',$recordedTake]);
    phone_test_check($consent['status']===200 && $review['status']===200,
        'the paired phone can record and review the requested take');
    $clip=file_get_contents(__DIR__.'/fixtures/phone-camera-recording-vp8.webm');
    $upload=['record_upload',$phone['token'],'chunk',$recordedTake+['index'=>'0','total_bytes'=>(string)strlen($clip),'mime_type'=>'video/webm']];
    $submissions=phone_test_race($upload,$upload);
    $assets=$db->query("SELECT file_path FROM user_media_assets WHERE user_id=1 AND source='phone_camera'")->fetchAll(PDO::FETCH_COLUMN);
    $receipts=(int)$db->query("SELECT COUNT(*) FROM vp3_phone_chat_turns_v1 WHERE map_link='camera_clip'")->fetchColumn();
    phone_test_check(array_column($submissions,'status')===[200,200] && count($assets)===1 && $receipts===1
        && file_get_contents(STONEFELLOW_ROOT.'/'.$assets[0])===$clip,
        'retrying the final binary chunk concurrently publishes one exact private video and one Chat receipt');
    $stops = phone_test_race(['camera',1,'stop',$session], ['camera',1,'stop',$session]);
    $row = vp3_phone_sql_v1($db,'SELECT state,offer_sdp,answer_sdp FROM vp3_phone_camera_sessions_v1 WHERE id=?',[$sessionId])->fetch();
    phone_test_check(array_column($stops,'status') === [200,200] && $row['state']==='stopped'
        && $row['offer_sdp']===null && $row['answer_sdp']===null,
        'duplicate private stops erase camera signaling');
    phone_test_check(!vp3_phone_sql_v1($db,'SELECT 1 FROM vp3_phone_camera_map_v1 WHERE session_id=?',[$sessionId])->fetchColumn()
        && !vp3_phone_sql_v1($db,'SELECT 1 FROM vp3_phone_camera_viewers_v1 WHERE session_id=?',[$sessionId])->fetchColumn(),
        'stopping sharing also removes the private Map and Chat viewers and their signaling');
    require_once __DIR__.'/phone-handoff-cases-v1.php';
    vp3_test_phone_handoff_v1($db,1,2,'phone_test_check');
    require_once __DIR__.'/phone-push-cases-v1.php';
    vp3_test_phone_push_v1($db,1,2,'phone_test_check');
    $db->exec('DELETE FROM users WHERE id=1');
    $tables = [
        'vp3_phone_invites_v1', 'vp3_phone_devices_v1', 'vp3_phone_requests_v1',
        'vp3_phone_history_points_v1', 'vp3_phone_places_v1', 'vp3_phone_camera_sessions_v1','vp3_phone_handoffs_v1','vp3_phone_push_subscriptions_v1',
    ];
    foreach ($tables as $table) {
        phone_test_check(!(int)$db->query("SELECT COUNT(*) FROM $table WHERE owner_user_id=1")->fetchColumn(),
            "deleting the account removes its rows from $table");
    }
    phone_test_check(phone_test_dispatch($db, ['device',$phone['token'],'status',[]])['status'] === 403,
        'deleting the account invalidates previously connected phones');
    echo "PASS Native MySQL phone pairing, consent and revocation races.\n";
} finally {
    $server->exec("DROP DATABASE `$schema`");
    if (is_dir(STONEFELLOW_ROOT)) {
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir(STONEFELLOW_ROOT);
    }
}
