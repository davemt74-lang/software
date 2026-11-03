<?php
declare(strict_types=1);

// Execute the production upgrade page with isolated migration dependencies.
// Real PDO/SQLite and the production TV schema exercise the connection and readiness path.
$newTables=['agent_task_lists_v1','agent_task_list_items_v1','agent_task_list_actions_v1','agent_participant_settings_v1','agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1',
    'profile_agent_handoff_owners_pa3','profile_agent_handoff_presence_pa3','profile_agent_handoff_conversations_pa3','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_operations_pa3',
    'profile_calls_pa3c','profile_call_slots_pa3c','profile_call_operations_pa3c','profile_call_deliveries_pa3c'];
if (!isset($argv[1])) {
    $modes=array_merge(['post', 'already-ready', 'missing-db', 'csrf', 'get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get'],array_map(fn($table)=>'missing-'.$table,$newTables));
    foreach ($modes as $mode) {
        $command = escapeshellarg(PHP_BINARY).(php_ini_loaded_file() ? ' -c '.escapeshellarg(php_ini_loaded_file()) : '').' '.escapeshellarg(__FILE__).' '.escapeshellarg($mode);
        passthru($command, $status);
        if ($status !== 0) exit($status);
    }
    echo 'UPGRADE_DATABASE_CONNECTION=PASS ('.count($modes)." cases)\n";
    exit;
}

$mode = $argv[1];
$fixture = new PDO('sqlite::memory:');
$fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fixture->exec("CREATE TABLE existing_content (value TEXT); INSERT INTO existing_content VALUES ('preserve me')");
require dirname(__DIR__).'/includes/tv-watching-v1.php';
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
require dirname(__DIR__).'/includes/profile-agent-handoff-pa3.php';
require dirname(__DIR__).'/includes/profile-calls-pa3c.php';
$fixture->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER)');
// Load only the production DDL functions; unrelated upgrade dependencies stay isolated.
foreach([['agent-task-lists-v1.php','vp3_task_lists_schema_v1','vp3_task_lists_ready_v1'],['participant-updates-v1.php','vp3_participant_updates_schema_v1','vp3_participant_updates_ready_v1']] as [$file,$schema,$ready]){
    $ddl=file_get_contents(dirname(__DIR__).'/includes/'.$file);$start=strpos($ddl,'function '.$schema.'(');$end=strpos($ddl,'function '.$ready.'(',$start);eval(substr($ddl,$start,$end-$start));
}
$missingTable=str_starts_with($mode,'missing-agent_')||str_starts_with($mode,'missing-profile_');
$modern=in_array($mode,['already-ready','lists-only','participants-only','complete-get'],true)||$missingTable;
if($modern){tv_schema_v1($fixture);reminder_schema_v1($fixture);if($mode!=='participants-only')vp3_task_lists_schema_v1($fixture);if($mode!=='lists-only')vp3_participant_updates_schema_v1($fixture);vp3_profile_handoff_ensure_schema_pa3($fixture);vp3_profile_call_ensure_schema_pa3c($fixture);}
if($missingTable){$missing=substr($mode,8);if(!in_array($missing,$newTables,true))throw new RuntimeException('Invalid missing table fixture.');$fixture->exec('DROP TABLE '.$missing);}
if (in_array($mode,['already-ready','tv-only'],true)) tv_schema_v1($fixture);
if ($mode === 'already-ready') reminder_schema_v1($fixture);
if ($mode === 'state-only') $fixture->exec('CREATE TABLE tv_watching_state_v1 (owner_user_id BIGINT PRIMARY KEY)');
if ($mode === 'records-only') $fixture->exec('CREATE TABLE tv_watching_records_v1 (owner_user_id BIGINT)');
$calls = []; $dbCalls = 0; $flashes = []; $redirects = [];
function db(): ?PDO { $GLOBALS['dbCalls']++; return $GLOBALS['mode'] === 'missing-db' ? null : $GLOBALS['fixture']; }
function setting(...$args): string { return '1'; }
function table_exists(string $name): bool {
    if (!in_array($name, array_merge(['tv_watching_state_v1', 'tv_watching_records_v1','agent_reminders_v1','agent_reminder_occurrences_v1','agent_reminder_context_v1','agent_reminder_turns_v1'],$GLOBALS['newTables']), true)) return true;
    $q = $GLOBALS['fixture']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$name]); return (bool)$q->fetchColumn();
}
function verify_csrf(): bool { return $GLOBALS['mode'] !== 'csrf'; }
function require_permission(string $permission): void { if ($permission !== 'users.manage') throw new RuntimeException('Upgrade permission changed'); }
function flash(...$args): void { $GLOBALS['flashes'][] = $args; }
function redirect(string $url): void { $GLOBALS['redirects'][] = $url; }
function url(string $path): string { return $path; }
function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function csrf_field(): string { return ''; }
function migration_dependency(string $name, array $args): bool {
    if (str_contains($name, 'schema_ready') || str_ends_with($name, '_ready') || $name === 'column_exists' || $name === 'personal_capability_seeded_v242') return true;
    if (str_contains($name, 'schema') || str_contains($name, 'seed') || str_contains($name, 'migrate') || $name === 'ai_haiku55_upgrade_defaults') $GLOBALS['calls'][] = $name;
    return true;
}

$source = file_get_contents(getenv('VP3_UPGRADE_SOURCE') ?: dirname(__DIR__).'/upgrade.php');
// Replace includes only; keep the real permission, CSRF, migration order, error handling and rendering.
$source = preg_replace('/^\s*require(?:_once)?\s+[^;]+;\R/m', "\n", $source);
$tokens = token_get_all($source); $functions = [];
foreach ($tokens as $i => $token) {
    if (!is_array($token) || $token[0] !== T_STRING) continue;
    $next = $i + 1; while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
    $previous = $i - 1; while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) $previous--;
    if (($tokens[$next] ?? null) !== '(' || (is_array($tokens[$previous] ?? null) && in_array($tokens[$previous][0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true))) continue;
    $name = $token[1];
    if ($name === 'vp3_upgrade_complete' || function_exists($name) || class_exists($name)) continue;
    $functions[$name] = in_array($name, ['knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1'], true);
}
foreach ($functions as $name => $typed) {
    if ($typed) eval('function '.$name.'(PDO $connection, ...$args) { if ($connection !== $GLOBALS["fixture"]) throw new RuntimeException("Wrong upgrade connection"); return migration_dependency(__FUNCTION__, [$connection, ...$args]); }');
    else eval('function '.$name.'(...$args) { return migration_dependency(__FUNCTION__, $args); }');
}

$_SERVER['REQUEST_METHOD'] = (in_array($mode, ['get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get'], true)||$missingTable) ? 'GET' : 'POST';
// Reproduce the reported deployment: bootstrap does not supply a page-local PDO.
$pdo = null;
ob_start(); eval(preg_replace('/^<\?php\s*/', '', $source)); $html = ob_get_clean();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (in_array($mode, ['post', 'already-ready'], true)) {
    check($error === '', 'Upgrade POST failed: '.$error);
    check($dbCalls === 1 && $pdo === $fixture, 'Upgrade must acquire its connection before the first migration');
    foreach (['social_feed_schema_v1', 'knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1', 'artist_music_v185_ensure_schema'] as $name) check(in_array($name, $calls, true), 'Upgrade did not reach '.$name);
    check(table_exists('tv_watching_state_v1') && table_exists('tv_watching_records_v1') && reminder_ready_v1($fixture) && $complete, 'Upgrade skipped TV/reminder storage');
    foreach($newTables as $table)check(table_exists($table),'Upgrade skipped required table '.$table);
    check(count($flashes) === 1 && $redirects === ['/admin/users.php'], 'Successful upgrade did not finish normally');
} elseif ($mode === 'complete-get') {
    check($complete&&$dbCalls===0&&$calls===[],'Complete GET must remain read only and report ready');
    check(!str_contains($html,'Run Upgrade')&&str_contains($html,'installed and ready'),'Complete schema must not prompt another upgrade');
} elseif ($mode === 'missing-db') {
    check($error === 'Database connection is unavailable.' && $calls === [], 'Unavailable database must stop before all migrations');
    check(str_contains($html, $error) && $flashes === [] && $redirects === [], 'Database failure was hidden or marked complete');
} elseif ($mode === 'csrf') {
    check($error === 'Session expired. Please try again.' && $dbCalls === 0 && $calls === [], 'CSRF rejection allowed upgrade work');
} else {
    check(!$complete && $dbCalls === 0 && $calls === [], 'Missing required storage was marked ready or GET performed migrations');
    check(str_contains($html, 'Run Upgrade'), 'Missing required storage must expose the upgrade control');
}
check($fixture->query('SELECT value FROM existing_content')->fetchColumn() === 'preserve me', 'Upgrade changed existing content');
echo 'PASS '.$mode."\n";
