<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');
$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('An explicit disposable MySQL DSN is required.');
$login = (string)getenv('VP3_TEST_MYSQL_USER');
$secret = (string)getenv('VP3_TEST_MYSQL_PASS');
$worker = ($argv[1] ?? '') === '--save';
$database = $worker ? (string)($argv[2] ?? '') : 'initiative_canvas_settings_'.bin2hex(random_bytes(6));
if (!preg_match('/\Ainitiative_canvas_settings_[a-f0-9]{12}\z/D', $database)) throw new RuntimeException('Invalid fixture database.');
$admin = new PDO($dsn, $login, $secret, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (!$worker) $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$isolated = preg_replace('/;dbname=[^;]*/', '', $dsn).';dbname='.$database;
putenv('VP3_IMAGE_TEST_DSN='.$isolated);
putenv('VP3_IMAGE_TEST_USER='.$login);
putenv('VP3_IMAGE_TEST_PASSWORD='.$secret);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-initiative-canvas-policy-v1.php';
function agent_brain_schema_ready(): bool { return table_exists('agent_memory_items'); }
function agent_brain_normalize(string $text): string { return mb_strtolower(trim($text)); }
function personal_knowledge_available(?array $user = null): bool { return true; }
$GLOBALS['initiativeScreenAccess'] = true;
$owner = ['id'=>1, 'is_active'=>1];

function native_canvas_setting_wait(callable $ready): void
{
    $until = microtime(true) + 15;
    while (!$ready()) {
        if (microtime(true) > $until) throw new RuntimeException('Native setting barrier timed out.');
        usleep(10000);
    }
}
if ($worker) {
    $gate = (string)($argv[3] ?? '');
    $index = (string)($argv[4] ?? '');
    if (!is_dir($gate) || !in_array($index, ['1','2'], true)) throw new RuntimeException('Invalid fixture barrier.');
    file_put_contents($gate.'/ready-'.$index, 'ready');
    native_canvas_setting_wait(static fn()=>is_file($gate.'/go'));
    file_put_contents($gate.'/attempting-'.$index, 'attempting');
    try {
        $state = vp3_initiative_canvas_setting_v1($pdo, $owner, 7, true, str_repeat('a',32));
        echo json_encode(['ok'=>true, 'enabled'=>$state['enabled']], JSON_THROW_ON_ERROR);
    } catch (RuntimeException $error) {
        echo json_encode(['ok'=>false, 'code'=>$error->getCode()], JSON_THROW_ON_ERROR);
    }
    file_put_contents($gate.'/done-'.$index, 'done');
    exit;
}

$gate = sys_get_temp_dir().'/vp3-native-canvas-setting-'.bin2hex(random_bytes(8));
$children = [];
try {
    $pdo->exec('CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
        CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,
          memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash CHAR(40),memory_scope_version SMALLINT,
          source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active TINYINT,metadata_json TEXT,
          UNIQUE KEY owner_memory(user_id,memory_hash));
        CREATE TABLE artist_transcript_folders_v177(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created_by_user_id INT UNSIGNED,folder_name VARCHAR(80),sort_order INT);
        CREATE TABLE knowledge_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created_by_user_id INT UNSIGNED,folder_id BIGINT UNSIGNED);
        CREATE TABLE ai_execution_ledger(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,created_at DATETIME)');
    vp3_coding_canvas_schema_v1($pdo);
    vp3_ai_service_usage_ensure_schema_v1($pdo);
    $model = ['provider'=>'openai','model'=>'native-canvas-setting'];
    vp3_ai_service_save_model_v1($pdo, $model['provider'], $model['model'], 'Native coder', ['code_build','code_repair']);
    foreach (['code_build','code_repair'] as $service) vp3_ai_service_save_route_v1($pdo, $service, $model, null);
    $binding = ['enabled'=>true,'conversation_id'=>11,'revision'=>str_repeat('a',32),'owner_user_id'=>1,'agent_id'=>7,
        'knowledge_scope'=>['mode'=>'off','folder_id'=>0]];
    vp3_initiative_in_scope_v1($owner, 7, static fn()=>agent_brain_v122_upsert_system_memory($owner, 'initiative_policy', 'owner-opt-in', 'Fixture preference.', $binding));
    $read = vp3_initiative_canvas_setting_v1($pdo, $owner, 7);
    if (!$read['available'] || !$read['can_enable'] || $read['enabled']) throw new RuntimeException('Native default preference did not match.');
    mkdir($gate, 0700);
    foreach (['1','2'] as $index) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--save', $database, $gate, $index], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start native settings writer.');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    native_canvas_setting_wait(static fn()=>is_file($gate.'/ready-1') && is_file($gate.'/ready-2'));
    file_put_contents($gate.'/go', 'go');
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Native setting child failed: '.substr($stderr,0,300));
        $results[] = json_decode($stdout, true, 8, JSON_THROW_ON_ERROR);
    }
    $children = [];
    if (count(array_filter($results, static fn(array $r):bool=>($r['ok'] ?? false) === true && ($r['enabled'] ?? false) === true)) !== 1
        || count(array_filter($results, static fn(array $r):bool=>($r['code'] ?? 0) === 409)) !== 1) {
        throw new RuntimeException('Concurrent stale settings forms did not serialize.');
    }
    $after = vp3_initiative_canvas_setting_v1($pdo, $owner, 7);
    if (!$after['enabled'] || $after['revision'] === $read['revision'] || vp3_initiative_canvas_setting_v1($pdo, $owner, 8)['enabled']) {
        throw new RuntimeException('Native preference revision or Agent isolation did not hold.');
    }
    $GLOBALS['noImageEntitlement'] = true;
    if (vp3_initiative_canvas_setting_v1($pdo, $owner, 7, false, $after['revision'])['enabled']) {
        throw new RuntimeException('Native authorized opt-out was blocked.');
    }
    $GLOBALS['noImageEntitlement'] = false;
    foreach (glob($gate.'/*') ?: [] as $file) unlink($file);
    vp3_initiative_in_scope_v1($owner, 7, static fn()=>agent_brain_v122_upsert_system_memory($owner, 'initiative_policy', 'owner-opt-in', 'Fixture preference.', $binding));
    $started = false;
    $paused = vp3_initiative_chat_v1($pdo, $owner, vp3_initiative_principal_v1($pdo, $owner, 7), ['conversation_id'=>11], 'off',
        function () use ($pdo, $database, $gate, &$started, &$children): void {
            // The actual Initiative controller has acquired its owner lock.
            // Start an Auto Build form with the old revision while pause holds it.
            if (!$pdo->inTransaction() || $started) return;
            $started = true;
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--save', $database, $gate, '1'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Could not start competing Auto Build form.');
            fclose($pipes[0]);
            $children[] = [$process,$pipes];
            native_canvas_setting_wait(static fn()=>is_file($gate.'/ready-1'));
            file_put_contents($gate.'/go', 'go');
            native_canvas_setting_wait(static fn()=>is_file($gate.'/attempting-1'));
            usleep(100000);
            if (is_file($gate.'/done-1')) throw new RuntimeException('Auto Build did not wait for the canonical Initiative owner lock.');
        });
    if (empty($paused['ok']) || !$started) throw new RuntimeException('Canonical concurrent pause did not complete.');
    [$process,$pipes] = $children[0];
    $result = json_decode(stream_get_contents($pipes[1]),true,8,JSON_THROW_ON_ERROR);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process) !== 0 || ($result['code'] ?? null) !== 409) throw new RuntimeException('A stale Auto Build form failed to reject the canonical pause: '.substr($stderr,0,200));
    $children = [];
    $preserved = vp3_initiative_canvas_setting_v1($pdo, $owner, 7);
    if ($preserved['initiative_on'] || $preserved['enabled']) throw new RuntimeException('A competing Auto Build form undid the owner’s pause.');
    echo 'INITIATIVE_CANVAS_SETTINGS_MYSQL=PASS native storage, concurrent forms, canonical pause race, Agent isolation and entitlement-independent opt-out'.PHP_EOL;
} finally {
    foreach ($children as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($process);
    }
    if (is_dir($gate)) { foreach (glob($gate.'/*') ?: [] as $file) unlink($file); rmdir($gate); }
    $admin->exec('DROP DATABASE `'.$database.'`');
}
