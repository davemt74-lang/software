<?php
declare(strict_types=1);

// Execute the production upgrade page with isolated migration dependencies.
// Real PDO/SQLite and the production TV schema exercise the connection and readiness path.
$newTables=['agent_task_lists_v1','agent_task_list_items_v1','agent_task_list_actions_v1','agent_participant_settings_v1','agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1',
    'profile_agent_handoff_owners_pa3','profile_agent_handoff_presence_pa3','profile_agent_handoff_conversations_pa3','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_operations_pa3',
    'profile_calls_pa3c','profile_call_slots_pa3c','profile_call_operations_pa3c','profile_call_deliveries_pa3c',
    'profile_call_assistance_pa3c','profile_call_research_pa3c',
    'profile_visitor_voices_pa4a','profile_speech_items_pa4a','profile_speech_attempts_pa4a',
    'profile_attention_operations_pa4b','profile_attention_deliveries_pa4b','profile_event_projections_pa4b',
    'profile_conversation_checkouts_pa5','agent_product_launches_v1',
    'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1'];
$partialSpeech=[
    'partial-auto-greet'=>['team_user_presence','auto_greet_enabled'],
    'partial-conversation-checkout-review'=>['profile_conversation_checkouts_pa5','product_snapshot_hash'],
    'partial-speech-voice'=>['profile_visitor_voices_pa4a','assignment_revision'],
    'partial-speech-source'=>['profile_speech_items_pa4a','source_hash'],
    'partial-speech-ticket'=>['profile_speech_attempts_pa4a','ticket_hash'],
    'partial-speech-receipt'=>['profile_speech_attempts_pa4a','provider_attempted'],
    'partial-attention-state'=>['agent_attention_items','state_revision'],
    'partial-attention-delivery'=>['profile_attention_deliveries_pa4b','source_hash'],
    'partial-projection-receipt'=>['profile_event_projections_pa4b','result_hash'],
    'partial-assistance-revision'=>['profile_call_assistance_pa3c','capture_revision'],
    'partial-assistance-research'=>['profile_call_research_pa3c','result_json'],
];
if (!isset($argv[1])) {
    $modes=array_merge(['post', 'already-ready', 'missing-db', 'csrf', 'get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get','missing-orb-shape'],array_map(fn($table)=>'missing-'.$table,$newTables),array_keys($partialSpeech));
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
require dirname(__DIR__).'/includes/chat-settings-v237.php';
require dirname(__DIR__).'/includes/profile-agent-handoff-pa3.php';
require dirname(__DIR__).'/includes/profile-calls-pa3c.php';
require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';
require dirname(__DIR__).'/includes/profile-visitor-speech-pa4a.php';
require dirname(__DIR__).'/includes/profile-attention-pa4b.php';
require dirname(__DIR__).'/includes/profile-event-projection-pa4b.php';
require_once dirname(__DIR__).'/includes/agent-commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/initiative-product-launch-v1.php';
require_once dirname(__DIR__).'/includes/profile-promotion-attribution-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-playback-v1.php';
$fixture->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER); CREATE TABLE chat_messages(id INTEGER PRIMARY KEY)');
$fixture->exec('CREATE TABLE team_user_presence(user_id INTEGER PRIMARY KEY, presence_mode TEXT, social_chat_enabled INTEGER, sound_enabled INTEGER, agent_voice_enabled INTEGER, agent_assisted_phone_chat_enabled INTEGER)');
$fixture->exec('INSERT INTO team_user_presence (user_id) VALUES (1)');
if ($mode !== 'post') {
    $fixture->exec('ALTER TABLE team_user_presence ADD COLUMN auto_greet_enabled TINYINT(1) NOT NULL DEFAULT 0');
    $fixture->exec('UPDATE team_user_presence SET auto_greet_enabled=1 WHERE user_id=1');
}
if (!in_array($mode,['post','missing-orb-shape'],true)) {
    $fixture->exec("ALTER TABLE team_user_presence ADD COLUMN agent_orb_shape VARCHAR(16) NOT NULL DEFAULT 'orbit'");
    $fixture->exec("UPDATE team_user_presence SET agent_orb_shape='particle_face' WHERE user_id=1");
}
$fixture->exec('CREATE TABLE agent_commerce_products_v800(id INTEGER PRIMARY KEY)');
$fixture->exec('CREATE TABLE agent_commerce_orders_v800(id INTEGER PRIMARY KEY)');
function agent_commerce_ensure_schema_v800(?PDO $pdo=null): void
{
    initiative_product_launch_schema_v1($pdo??$GLOBALS['fixture']);
    profile_promotion_attribution_schema_v1($pdo??$GLOBALS['fixture']);
    $GLOBALS['calls'][]=__FUNCTION__;
}
// Older Profile migrations are isolated dependencies in this page fixture. Keep
// their historical attention/notification shape, then execute real PA4B DDL.
function profile_agent_ensure_schema(?PDO $connection=null): void
{
    $p=$connection??$GLOBALS['fixture'];
    $p->exec("CREATE TABLE IF NOT EXISTS agent_attention_items(id INTEGER PRIMARY KEY,owner_user_id INTEGER,source_event_id INTEGER UNIQUE,source_conversation_id INTEGER,profile_agent_id INTEGER,visitor_user_id INTEGER,attention_type VARCHAR(50),priority INTEGER,status VARCHAR(20) DEFAULT 'pending',headline VARCHAR(255),summary TEXT,assessment TEXT,recommended_response TEXT,actions_json TEXT,context_json TEXT,snoozed_until DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type VARCHAR(80),title VARCHAR(255),body TEXT,target_url TEXT,source_type VARCHAR(80),source_id INTEGER,is_read INTEGER DEFAULT 0,read_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}
// Load only the production DDL functions; unrelated upgrade dependencies stay isolated.
foreach([['agent-task-lists-v1.php','vp3_task_lists_schema_v1','vp3_task_lists_ready_v1'],['participant-updates-v1.php','vp3_participant_updates_schema_v1','vp3_participant_updates_ready_v1']] as [$file,$schema,$ready]){
    $ddl=file_get_contents(dirname(__DIR__).'/includes/'.$file);$start=strpos($ddl,'function '.$schema.'(');$end=strpos($ddl,'function '.$ready.'(',$start);eval(substr($ddl,$start,$end-$start));
}
$checkoutDdl=file_get_contents(dirname(__DIR__).'/includes/profile-conversation-checkout-pa5.php');
$checkoutStart=strpos($checkoutDdl,'function '.'vp3_profile_checkout_ensure_schema_pa5(');
$checkoutEnd=strpos($checkoutDdl,'function '.'vp3_profile_checkout_require_pa5(',$checkoutStart);
eval(substr($checkoutDdl,$checkoutStart,$checkoutEnd-$checkoutStart));
if($mode!=='post')vp3_profile_checkout_ensure_schema_pa5($fixture);

$missingTable=str_starts_with($mode,'missing-agent_')||str_starts_with($mode,'missing-profile_');
$modern=in_array($mode,['already-ready','lists-only','participants-only','complete-get','missing-orb-shape'],true)||$missingTable||isset($partialSpeech[$mode]);
if($modern){tv_schema_v1($fixture);reminder_schema_v1($fixture);if($mode!=='participants-only')vp3_task_lists_schema_v1($fixture);if($mode!=='lists-only')vp3_participant_updates_schema_v1($fixture);vp3_profile_handoff_ensure_schema_pa3($fixture);vp3_profile_call_ensure_schema_pa3c($fixture);vp3_profile_call_activity_ensure_schema_pa3c($fixture);vp3_profile_speech_ensure_schema_pa4a($fixture);profile_agent_ensure_schema($fixture);vp3_profile_attention_ensure_schema_pa4b($fixture);vp3_profile_projection_ensure_schema_pa4b($fixture);}
if($modern){
    initiative_product_launch_schema_v1($fixture);
    profile_promotion_attribution_schema_v1($fixture);
    agent_connection_youtube_consent_schema_v1($fixture);
    agent_connection_youtube_quota_schema_v1($fixture);
    agent_connection_youtube_results_schema_v1($fixture);
    vp3_youtube_playback_install_v1($fixture);
    vp3_youtube_picks_install_v1($fixture);
}
if($missingTable){$missing=substr($mode,8);if(!in_array($missing,$newTables,true))throw new RuntimeException('Invalid missing table fixture.');$fixture->exec('DROP TABLE '.$missing);}
if(isset($partialSpeech[$mode])){[$table,$column]=$partialSpeech[$mode];$fixture->exec('ALTER TABLE '.$table.' RENAME COLUMN '.$column.' TO removed_'.$column);}
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

$_SERVER['REQUEST_METHOD'] = (in_array($mode, ['get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get','missing-orb-shape'], true)||$missingTable||isset($partialSpeech[$mode])) ? 'GET' : 'POST';
// Reproduce the reported deployment: bootstrap does not supply a page-local PDO.
$pdo = null;
ob_start(); eval(preg_replace('/^<\?php\s*/', '', $source)); $html = ob_get_clean();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (in_array($mode, ['post', 'already-ready'], true)) {
    check($error === '', 'Upgrade POST failed: '.$error);
    check($dbCalls === ($mode==='already-ready'?2:1) && $pdo === $fixture, 'Upgrade must acquire its connection before the first migration and reuse it for final readiness');
    foreach (['social_feed_schema_v1', 'knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1', 'artist_music_v185_ensure_schema'] as $name) check(in_array($name, $calls, true), 'Upgrade did not reach '.$name);
    check(table_exists('tv_watching_state_v1') && table_exists('tv_watching_records_v1') && reminder_ready_v1($fixture) && $complete, 'Upgrade skipped TV/reminder storage');
    foreach($newTables as $table)check(table_exists($table),'Upgrade skipped required table '.$table);
    check(chat_settings_agent_orb_shape_schema_ready_v237($fixture), 'Upgrade skipped the Agent shape preference');
    check(chat_settings_agent_orb_shape_v237($fixture, 1) === ($mode === 'post' ? 'orbit' : 'particle_face'), 'Upgrade did not preserve the default or saved Agent shape');
    check(chat_settings_agent_auto_greet_schema_ready_v237($fixture), 'Upgrade skipped Auto Greet storage');
    check(chat_settings_get_v237($fixture,1)['auto_greet_enabled'] === ($mode !== 'post'), 'Upgrade did not preserve the default or saved Auto Greet preference');
    check(vp3_youtube_playback_ready_v1($fixture), 'Upgrade skipped the YouTube playback history schema');
    check(count($flashes) === 1 && $redirects === ['/admin/users.php'], 'Successful upgrade did not finish normally');
} elseif ($mode === 'complete-get') {
    check($complete&&$dbCalls===1&&$calls===[],'Complete GET must inspect speech columns read-only and report ready');
    check(!str_contains($html,'Run Upgrade')&&str_contains($html,'installed and ready'),'Complete schema must not prompt another upgrade');
} elseif ($mode === 'missing-orb-shape') {
    check(!$complete && $dbCalls===1 && $calls===[], 'Missing Agent shape preference must leave the upgrade available without writing on GET');
    check(!chat_settings_agent_orb_shape_schema_ready_v237($fixture) && str_contains($html,'Run Upgrade'), 'Missing Agent shape column was added or upgrade control was hidden on GET');
} elseif ($mode === 'missing-db') {
    check($error === 'Database connection is unavailable.' && $calls === [], 'Unavailable database must stop before all migrations');
    check(str_contains($html, $error) && $flashes === [] && $redirects === [], 'Database failure was hidden or marked complete');
} elseif ($mode === 'csrf') {
    check($error === 'Session expired. Please try again.' && $dbCalls === 0 && $calls === [], 'CSRF rejection allowed upgrade work');
} else {
    check(!$complete && $dbCalls === (isset($partialSpeech[$mode])?1:0) && $calls === [], 'Missing required storage was marked ready or GET performed migrations');
    check(str_contains($html, 'Run Upgrade'), 'Missing required storage must expose the upgrade control');
}
check($fixture->query('SELECT value FROM existing_content')->fetchColumn() === 'preserve me', 'Upgrade changed existing content');
echo 'PASS '.$mode."\n";
