<?php
declare(strict_types=1);

// Execute the production upgrade page with isolated migration dependencies.
// Real PDO/SQLite and the production TV schema exercise the connection and readiness path.
$newTables=['agent_task_lists_v1','agent_task_list_items_v1','agent_task_list_actions_v1','agent_participant_settings_v1','agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1','profile_chat_attachments_v1',
    'profile_agent_handoff_owners_pa3','profile_agent_handoff_presence_pa3','profile_agent_handoff_conversations_pa3','profile_agent_handoff_turns_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_operations_pa3',
    'profile_agent_response_settings_v1','profile_agent_owner_requests_v1',
    'profile_calls_pa3c','profile_call_slots_pa3c','profile_call_operations_pa3c','profile_call_deliveries_pa3c',
    'profile_call_assistance_pa3c','profile_call_research_pa3c',
    'profile_visitor_voices_pa4a','profile_speech_items_pa4a','profile_speech_attempts_pa4a',
    'profile_attention_operations_pa4b','profile_attention_deliveries_pa4b','profile_event_projections_pa4b','profile_event_clock_activation_v1',
    'profile_conversation_checkouts_pa5','agent_product_launches_v1',
    'agent_promotion_publications_v1','agent_promotion_clicks_v1','agent_promotion_orders_v1',
    'vp3_youtube_watch_v1','vp3_youtube_playhead_v1','vp3_youtube_saved_playlists_v1','vp3_youtube_playlist_plays_v1',
    'vp3_initiative_youtube_playlist_v1','vp3_profile_canvases_v1'];
$partialSpeech=[
    'partial-auto-greet'=>['team_user_presence','auto_greet_enabled'],
    'partial-profile-response-mode'=>['profile_agent_response_settings_v1','mode'],
    'partial-profile-owner-request'=>['profile_agent_owner_requests_v1','settled_at_ms'],
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
    'partial-profile-event-time'=>['profile_events','recorded_at_utc_ms'],
    'partial-profile-event-cutover'=>['profile_events','clock_legacy_token_v1'],
    'partial-promotion-native-context'=>['agent_promotion_clicks_v1','native_context_json_v1'],
    'partial-checkout-open-time'=>['agent_commerce_checkout_attempts_v800','opened_at_utc_ms'],
    'partial-canvas-edit-base'=>['vp3_coding_canvas_versions_v1','edit_base_revision'],
    'partial-canvas-edit-origin'=>['vp3_coding_canvas_versions_v1','edit_origin_revision'],
    'partial-canvas-edit-request'=>['vp3_coding_canvas_versions_v1','edit_request_id'],
    'partial-canvas-edit-hash'=>['vp3_coding_canvas_versions_v1','edit_request_hash'],
    'partial-canvas-workflow-report'=>['vp3_coding_canvas_versions_v1','workflow_result_json'],
    'partial-coding-response'=>['agent_workflow_runs','coding_provider_result_json'],
];
$phoneContactModes=['phone-contacts-old-get','phone-contacts-old-post','phone-contacts-new-post','phone-contacts-ready-get'];
$operatorPolicyModes=['operator-policy-old-get','operator-policy-old-post','operator-policy-ready-get'];
$ownerPhoneModes=['owner-phone-old-get','owner-phone-old-post','owner-phone-ready-get','owner-phone-preserve-post'];
if (!isset($argv[1])) {
    $modes=array_merge(['post', 'already-ready', 'missing-db', 'csrf', 'get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get','missing-orb-shape','missing-ai-service-usage','missing-coding-canvas','missing-canvas-source-index'],array_map(fn($table)=>'missing-'.$table,$newTables),array_keys($partialSpeech),$phoneContactModes,$operatorPolicyModes,$ownerPhoneModes);
    foreach ($modes as $mode) {
        $command = escapeshellarg(PHP_BINARY).(php_ini_loaded_file() ? ' -c '.escapeshellarg(php_ini_loaded_file()) : '').' '.escapeshellarg(__FILE__).' '.escapeshellarg($mode);
        passthru($command, $status);
        if ($status !== 0) exit($status);
    }
    echo 'UPGRADE_DATABASE_CONNECTION=PASS ('.count($modes)." cases)\n";
    exit;
}

$mode = $argv[1];
$phoneContacts=in_array($mode,$phoneContactModes,true);
$operatorPolicies=in_array($mode,$operatorPolicyModes,true);
$ownerPhones=in_array($mode,$ownerPhoneModes,true);
$fixture = new PDO('sqlite::memory:');
$fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fixture->exec("CREATE TABLE existing_content (value TEXT); INSERT INTO existing_content VALUES ('preserve me')");
if($operatorPolicies){require dirname(__DIR__).'/includes/agent-operator-auto-call-schema-v1.php';if($mode==='operator-policy-ready-get')vp3_operator_auto_call_ensure_schema_v1($fixture);}
if($phoneContacts){
    // Exercise the production readiness and migration, rather than their generic
    // dependency stubs. Core CRM and platform ownership remain ordered fixtures.
    require dirname(__DIR__).'/includes/phone-caller-contacts-schema-v1.php';
    function upgrade_phone_contacts_core_fixture(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id VARCHAR(64) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL DEFAULT '',email_normalized VARCHAR(190) NOT NULL,phone VARCHAR(80) NOT NULL DEFAULT '',company VARCHAR(190) NOT NULL DEFAULT '',source VARCHAR(80) NOT NULL DEFAULT 'manual',status VARCHAR(40) NOT NULL DEFAULT 'active',lifecycle_stage VARCHAR(80) NOT NULL DEFAULT '',marketing_status VARCHAR(40) NOT NULL DEFAULT 'unknown',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        if(!(int)$pdo->query('SELECT count(*) FROM crm_contacts')->fetchColumn())$pdo->exec("INSERT INTO crm_contacts(public_id,name,email_normalized,phone) VALUES('phone-contact-fixture','Existing phone contact','','+16025550122')");
    }
    function upgrade_phone_contacts_platform_fixture(PDO $pdo): void
    {
        $columns=array_column($pdo->query('PRAGMA table_info(crm_contacts)')->fetchAll(PDO::FETCH_ASSOC),'name');
        if(!in_array('owner_user_id',$columns,true))$pdo->exec('ALTER TABLE crm_contacts ADD COLUMN owner_user_id INTEGER NOT NULL DEFAULT 1');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS crm_contacts_owner_email ON crm_contacts(owner_user_id,email_normalized)');
    }
    function upgrade_phone_contacts_core_install(?PDO $connection=null): void
    {
        $connection??=$GLOBALS['fixture'];
        if($connection!==$GLOBALS['fixture'])throw new RuntimeException('Wrong CRM upgrade connection');
        upgrade_phone_contacts_core_fixture($connection);$GLOBALS['calls'][]='crm_v180_ensure_schema';
    }
    function upgrade_phone_contacts_platform_install(PDO $connection): void
    {
        if($connection!==$GLOBALS['fixture'])throw new RuntimeException('Wrong platform upgrade connection');
        upgrade_phone_contacts_platform_fixture($connection);$GLOBALS['calls'][]='campaigns_rewards_platform_ensure_schema_v100';
    }
    // Keep isolated fixture functions out of production installer source discovery.
    eval('function '.'crm_v180_ensure_schema'.'(?PDO $connection=null): void { upgrade_phone_contacts_core_install($connection); }');
    eval('function '.'campaigns_rewards_platform_ensure_schema_v100'.'(PDO $connection): void { upgrade_phone_contacts_platform_install($connection); }');
    if($mode!=='phone-contacts-new-post'){
        upgrade_phone_contacts_core_fixture($fixture);upgrade_phone_contacts_platform_fixture($fixture);
        if($mode==='phone-contacts-ready-get')vp3_phone_caller_contacts_ensure_schema_v1($fixture);
    }
}
require dirname(__DIR__).'/includes/tv-watching-v1.php';
require dirname(__DIR__).'/includes/coding-canvas-checks-schema-v1.php';
require dirname(__DIR__).'/includes/coding-canvas-response-schema-v1.php';
$fixture->exec('CREATE TABLE agent_workflow_runs(id INTEGER PRIMARY KEY,goal TEXT)');
$canvasDdl=file_get_contents(dirname(__DIR__).'/includes/coding-canvas-store-v1.php');
$canvasStart=strpos($canvasDdl,'function '.'vp3_coding_canvas_schema_v1(');
$canvasEnd=strpos($canvasDdl,'function '.'vp3_coding_canvas_authority_v1(',$canvasStart);
// Keep the real schema/readiness functions, with their source-schema dependency
// explicitly loaded rather than resolving a production __DIR__ inside eval.
$canvasSourceDdl=file_get_contents(dirname(__DIR__).'/includes/coding-canvas-source-v1.php');
$canvasSourceStart=strpos($canvasSourceDdl,'function '.'vp3_coding_canvas_source_schema_v1(');
$canvasSourceEnd=strpos($canvasSourceDdl,'function '.'vp3_coding_canvas_edit_context_v1(',$canvasSourceStart);
eval(substr($canvasSourceDdl,$canvasSourceStart,$canvasSourceEnd-$canvasSourceStart));
eval(preg_replace('/^\s*require_once\s+[^;]+;\R/m',"\n",substr($canvasDdl,$canvasStart,$canvasEnd-$canvasStart)));
$profileCanvasDdl=file_get_contents(dirname(__DIR__).'/includes/profile-canvases-v1.php');
$profileCanvasStart=strpos($profileCanvasDdl,'function '.'vp3_profile_canvases_install_v1(');
$profileCanvasEnd=strpos($profileCanvasDdl,'function '.'vp3_profile_canvas_version_v1(',$profileCanvasStart);
eval(substr($profileCanvasDdl,$profileCanvasStart,$profileCanvasEnd-$profileCanvasStart));
require dirname(__DIR__).'/includes/agent-reminders-v1.php';
require dirname(__DIR__).'/includes/chat-settings-v237.php';
require dirname(__DIR__).'/includes/profile-agent-handoff-pa3.php';
require_once dirname(__DIR__).'/includes/profile-agent-responses-v1.php';
require_once dirname(__DIR__).'/includes/profile-chat-attachments-v1.php';
require dirname(__DIR__).'/includes/profile-calls-pa3c.php';
require_once dirname(__DIR__).'/includes/profile-call-activity-pa3c.php';
require dirname(__DIR__).'/includes/profile-visitor-speech-pa4a.php';
require dirname(__DIR__).'/includes/profile-attention-pa4b.php';
require dirname(__DIR__).'/includes/profile-event-projection-pa4b.php';
require dirname(__DIR__).'/includes/profile-event-clock-v1.php';
require_once dirname(__DIR__).'/includes/agent-commerce-recovery-pa2.php';
require_once dirname(__DIR__).'/includes/initiative-product-launch-v1.php';
require_once dirname(__DIR__).'/includes/profile-promotion-attribution-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-youtube-playback-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-youtube-playlist-v1.php';
$fixture->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER); CREATE TABLE chat_messages(id INTEGER PRIMARY KEY)');
require_once dirname(__DIR__).'/includes/agent-operator-owner-phone-v1.php';
if($ownerPhones)$fixture->exec('INSERT INTO users(id,is_active) VALUES(1,1)');
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
$fixture->exec('CREATE TABLE agent_commerce_checkout_attempts_v800(id INTEGER PRIMARY KEY)');
function agent_commerce_ensure_schema_v800(?PDO $pdo=null): void
{
    initiative_product_launch_schema_v1($pdo??$GLOBALS['fixture']);
    profile_promotion_attribution_schema_v1($pdo??$GLOBALS['fixture']);
    profile_promotion_capture_install_v1($pdo??$GLOBALS['fixture'],'checkout');
    $GLOBALS['calls'][]=__FUNCTION__;
}
// Older Profile migrations are isolated dependencies in this page fixture. Keep
// their historical attention/notification shape, then execute real PA4B DDL.
// Actual writer clock behavior is covered by profile-event-clock-v1.php. The
// page fixture isolates those writers while exercising real upgrade readiness.
const VP3_PROFILE_EVENT_CREATE_CLOCK_CONTRACT_V1='vp3.profile-event-clock.v1';
const VP3_PROFILE_CONVERSION_CLOCK_CONTRACT_V1='vp3.profile-event-clock.v1';
function profile_event_create(): never {throw new LogicException('An upgrade must not create a visitor event.');}
function profile_conversion_outcome_record_v179(): never {throw new LogicException('An upgrade must not create a conversion.');}
function profile_agent_ensure_schema(?PDO $connection=null): void
{
    $p=$connection??$GLOBALS['fixture'];
    $p->exec('CREATE TABLE IF NOT EXISTS profile_events (id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL)');
    $p->exec('CREATE TABLE IF NOT EXISTS profile_agent_messages (id INTEGER PRIMARY KEY)');
    vp3_profile_chat_attachment_install_v1($p);
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

$missingYouTube=str_starts_with($mode,'missing-vp3_youtube_')||$mode==='missing-vp3_initiative_youtube_playlist_v1';
$missingTable=str_starts_with($mode,'missing-agent_')||str_starts_with($mode,'missing-profile_')||$missingYouTube||$mode==='missing-vp3_profile_canvases_v1';
$modern=in_array($mode,['already-ready','lists-only','participants-only','complete-get','missing-orb-shape','missing-ai-service-usage','missing-coding-canvas','missing-canvas-source-index'],true)||$missingTable||isset($partialSpeech[$mode])||$phoneContacts||$operatorPolicies||$ownerPhones;
$fixture->exec('CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY)');
if($modern&&$mode!=='missing-ai-service-usage')$fixture->exec('ALTER TABLE ai_execution_ledger ADD COLUMN service_attempt_key TEXT');
function vp3_ai_service_usage_ready_v1(PDO $pdo): bool
{
    try{return $pdo->query('SELECT service_attempt_key FROM ai_execution_ledger WHERE 1=0')!==false;}
    catch(PDOException){return false;}
}
function ai_usage_accounting_v032_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=$GLOBALS['fixture'];
    if(!vp3_ai_service_usage_ready_v1($pdo))$pdo->exec('ALTER TABLE ai_execution_ledger ADD COLUMN service_attempt_key TEXT');
    $GLOBALS['calls'][]=__FUNCTION__;
}
if($modern && !in_array($mode,['owner-phone-old-get','owner-phone-old-post'],true)){
    VP3OperatorOwnerPhoneV1::install($fixture);
    if($mode==='owner-phone-preserve-post')$fixture->exec("INSERT INTO phone_operator_owner_preferences_v1(owner_user_id,phone_e164,phone_revision,revision,updated_epoch) VALUES(1,'+16025550100',3,4,1800000000)");
}
if($modern){tv_schema_v1($fixture);reminder_schema_v1($fixture);if($mode!=='participants-only')vp3_task_lists_schema_v1($fixture);if($mode!=='lists-only')vp3_participant_updates_schema_v1($fixture);vp3_profile_handoff_ensure_schema_pa3($fixture);vp3_profile_call_ensure_schema_pa3c($fixture);vp3_profile_call_activity_ensure_schema_pa3c($fixture);vp3_profile_speech_ensure_schema_pa4a($fixture);profile_agent_ensure_schema($fixture);vp3_profile_attention_ensure_schema_pa4b($fixture);vp3_profile_projection_ensure_schema_pa4b($fixture);vp3_profile_event_clock_install_v1($fixture);}
if($modern){
    vp3_profile_responses_install_v1($fixture);
    $fixture->exec("INSERT INTO profile_agent_response_settings_v1(owner_user_id,mode,revision,created_at_ms,updated_at_ms) VALUES(1,'user_responses',4,1800000000000,1800000001000)");
    if ($mode !== 'missing-coding-canvas') vp3_coding_canvas_schema_v1($fixture);
    vp3_profile_canvases_install_v1($fixture);
    $fixture->exec("INSERT INTO vp3_profile_canvases_v1(canvas_id,owner_user_id,listed,generation) VALUES('00000000-0000-4000-8000-000000000001',1,1,3)");
    initiative_product_launch_schema_v1($fixture);
    profile_promotion_attribution_schema_v1($fixture);
    profile_promotion_capture_install_v1($fixture,'checkout');
    agent_connection_youtube_consent_schema_v1($fixture);
    agent_connection_youtube_quota_schema_v1($fixture);
    agent_connection_youtube_results_schema_v1($fixture);
    vp3_youtube_playback_install_v1($fixture);
    vp3_youtube_picks_install_v1($fixture);
}
if($missingTable){$missing=substr($mode,8);if(!in_array($missing,$newTables,true))throw new RuntimeException('Invalid missing table fixture.');$fixture->exec('DROP TABLE '.$missing);}
if(isset($partialSpeech[$mode])){[$table,$column]=$partialSpeech[$mode];$fixture->exec('ALTER TABLE '.$table.' RENAME COLUMN '.$column.' TO removed_'.$column);}
if($mode==='missing-canvas-source-index')$fixture->exec('DROP INDEX vp3_canvas_edit_request_v1');
if (in_array($mode,['already-ready','tv-only'],true)) tv_schema_v1($fixture);
if ($mode === 'already-ready') reminder_schema_v1($fixture);
if ($mode === 'state-only') $fixture->exec('CREATE TABLE tv_watching_state_v1 (owner_user_id BIGINT PRIMARY KEY)');
if ($mode === 'records-only') $fixture->exec('CREATE TABLE tv_watching_records_v1 (owner_user_id BIGINT)');
// Seeding and fault injection represent the previously installed database.
// The upgrade request starts with a fresh positive metadata cache.
$metadata=schema_metadata_state($fixture);$metadata->tables=[];$metadata->columns=[];
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

$_SERVER['REQUEST_METHOD'] = (in_array($mode, ['get', 'state-only', 'records-only', 'tv-only','lists-only','participants-only','complete-get','missing-orb-shape','missing-ai-service-usage','missing-coding-canvas','missing-canvas-source-index','phone-contacts-old-get','phone-contacts-ready-get','operator-policy-old-get','operator-policy-ready-get','owner-phone-old-get','owner-phone-ready-get'], true)||$missingTable||isset($partialSpeech[$mode])) ? 'GET' : 'POST';
// Reproduce the reported deployment: bootstrap does not supply a page-local PDO.
$pdo = null;
$phoneContactSchemaBefore=$phoneContacts?$fixture->query('SELECT type,name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC):[];
$phoneContactChangesBefore=$phoneContacts?(int)$fixture->query('SELECT total_changes()')->fetchColumn():0;
ob_start(); eval(preg_replace('/^<\?php\s*/', '', $source)); $html = ob_get_clean();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if($ownerPhones){
    $ready=$mode!=='owner-phone-old-get';
    check($complete===$ready&&VP3OperatorOwnerPhoneV1::ready($fixture)===$ready,'Owner phone upgrade readiness did not match actual schema');
    if($_SERVER['REQUEST_METHOD']==='GET')check($dbCalls===1&&$calls===[],'Owner phone GET must remain read-only');
    else check($error===''&&$dbCalls===2&&$pdo===$fixture,'Owner phone upgrade POST did not use shared connection: '.$error);
    if($ready){
        $rows=$fixture->query('SELECT * FROM phone_operator_owner_preferences_v1')->fetchAll(PDO::FETCH_ASSOC);
        if($mode==='owner-phone-preserve-post'){
            check(count($rows)===1&&(int)$rows[0]['revision']===4&&(int)$rows[0]['phone_revision']===3&&$rows[0]['phone_e164']==='+16025550100','Upgrade modified existing private phone');
            check((int)$rows[0]['updates_requested']===0&&(int)$rows[0]['hello_requested']===0,'Upgrade opted in existing user');
        }else check($rows===[],'Upgrade must not create owner preferences');
    }
} elseif($operatorPolicies){
    $ready=$mode!=='operator-policy-old-get';
    check($complete===$ready&&vp3_operator_auto_call_schema_ready_v1($fixture)===$ready,'Operator migration readiness did not match actual installed storage');
    check($fixture->query('SELECT value FROM existing_content')->fetchColumn()==='preserve me','Operator upgrade must preserve existing content');
    if($_SERVER['REQUEST_METHOD']==='GET')check($dbCalls===1&&$calls===[],'Operator GET must not install missing preferences');
    else check($error===''&&$dbCalls===2&&$pdo===$fixture,'Operator upgrade POST must install using the real shared connection');
    if($ready)check((int)$fixture->query('SELECT COUNT(*) FROM phone_operator_contact_policies_v1')->fetchColumn()===0,'Upgrade cannot authorize any contact');
} elseif($phoneContacts){
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $ready=$mode==='phone-contacts-ready-get';
        check($complete===$ready&&$dbCalls===1&&$calls===[],'Phone contact GET must reuse lazy PDO and inspect actual readiness without migrations');
        check(vp3_phone_caller_contacts_schema_ready_v1($fixture)===$ready,'Phone contact page readiness did not match actual nullable email storage');
        check($phoneContactSchemaBefore===$fixture->query('SELECT type,name,sql FROM sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC),'Phone contact GET changed the installed schema');
        check($phoneContactChangesBefore===(int)$fixture->query('SELECT total_changes()')->fetchColumn(),'Phone contact GET wrote source data');
        check(str_contains($html,'Run Upgrade')!==$ready,'Phone contact GET displayed the wrong upgrade control');
        check(!$ready||str_contains($html,'installed and ready'),'Nullable phone contact CRM should report installed and ready');
        check($fixture->query('SELECT email_normalized FROM crm_contacts WHERE id=1')->fetchColumn()===($ready?null:''),'Phone contact GET altered the existing email identity');
    }else{
        check($error===''&&$complete,'Phone contact upgrade POST did not finish: '.$error);
        check($dbCalls===2&&$pdo===$fixture,'Phone contact POST did not acquire then reuse the upgrade connection');
        check(vp3_phone_caller_contacts_schema_ready_v1($fixture),'Phone contact POST skipped actual nullable email migration');
        check($fixture->query('SELECT email_normalized FROM crm_contacts WHERE id=1')->fetchColumn()===null,'Phone contact POST did not clear legacy blank email identity');
        check($fixture->query('SELECT name FROM crm_contacts WHERE id=1')->fetchColumn()==='Existing phone contact','Phone contact POST changed the source contact');
        $core=array_search('crm_v180_ensure_schema',$calls,true);$platform=array_search('campaigns_rewards_platform_ensure_schema_v100',$calls,true);
        check($core!==false&&$platform!==false&&$core<$platform,'Phone contact POST must install CRM before platform owner storage');
        check(count($flashes)===1&&$redirects===['/admin/users.php'],'Phone contact upgrade did not finish normally');
    }
} elseif (in_array($mode, ['post', 'already-ready'], true)) {
    check($error === '', 'Upgrade POST failed: '.$error);
    check($dbCalls === ($mode==='already-ready'?2:1) && $pdo === $fixture, 'Upgrade must acquire its connection before the first migration and reuse it for final readiness');
    foreach (['social_feed_schema_v1', 'knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1', 'artist_music_v185_ensure_schema'] as $name) check(in_array($name, $calls, true), 'Upgrade did not reach '.$name);
    check(table_exists('tv_watching_state_v1') && table_exists('tv_watching_records_v1') && reminder_ready_v1($fixture) && $complete, 'Upgrade skipped TV/reminder storage');
    foreach($newTables as $table)check(table_exists($table),'Upgrade skipped required table '.$table);
    check(vp3_profile_responses_ready_v1($fixture),'Upgrade skipped Profile response settings or owner requests');
    $responsePolicy=vp3_profile_response_settings_v1($fixture,1);
    check($responsePolicy['mode']===($mode==='post'?'automated':'user_responses')&&$responsePolicy['revision']===($mode==='post'?0:4),'Upgrade changed the default or saved Profile response policy');
    check((int)$fixture->query('SELECT COUNT(*) FROM profile_agent_owner_requests_v1')->fetchColumn()===0,'Upgrade must not submit owner requests');
    check(chat_settings_agent_orb_shape_schema_ready_v237($fixture), 'Upgrade skipped the Agent shape preference');
    check(chat_settings_agent_orb_shape_v237($fixture, 1) === ($mode === 'post' ? 'orbit' : 'particle_face'), 'Upgrade did not preserve the default or saved Agent shape');
    check(chat_settings_agent_auto_greet_schema_ready_v237($fixture), 'Upgrade skipped Auto Greet storage');
    check(chat_settings_get_v237($fixture,1)['auto_greet_enabled'] === ($mode !== 'post'), 'Upgrade did not preserve the default or saved Auto Greet preference');
    check(vp3_youtube_playback_ready_v1($fixture), 'Upgrade skipped the YouTube playback history schema');
    check(vp3_youtube_watch_ready_v1($fixture), 'Upgrade skipped the YouTube watch time schema');
    check(vp3_youtube_playhead_ready_v1($fixture) && vp3_youtube_playlists_ready_v1($fixture), 'Upgrade skipped the YouTube resume or playlist schema');
    check(vp3_initiative_youtube_playlist_ready_v1($fixture), 'Upgrade skipped the pending YouTube report cache');
    check(vp3_ai_service_usage_ready_v1($fixture) && in_array('ai_usage_accounting_v032_ensure_schema',$calls,true), 'Upgrade skipped AI service usage storage');
    check(vp3_coding_canvas_ready_v1($fixture), 'Upgrade skipped private Coding Canvas storage');
    check(VP3OperatorOwnerPhoneV1::ready($fixture),'Upgrade skipped private owner phone storage');
    check(vp3_profile_canvases_ready_v1($fixture), 'Upgrade skipped public Profile Canvas listing storage');
    check((int)$fixture->query('SELECT COUNT(*) FROM vp3_profile_canvases_v1')->fetchColumn() === ($mode === 'post' ? 0 : 1), 'Upgrade opted an app in or discarded a saved Profile Canvas choice');
    if ($mode === 'already-ready') check((int)$fixture->query('SELECT generation FROM vp3_profile_canvases_v1')->fetchColumn() === 3, 'Upgrade changed a saved Profile Canvas revision');
    check(vp3_profile_event_clock_ready_v1($fixture), 'Upgrade skipped forward Profile event time activation');
    check(profile_promotion_capture_ready_v1($fixture), 'Upgrade skipped immutable promotion and checkout-open evidence');
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
    $needsConnection = isset($partialSpeech[$mode]) || $missingYouTube || in_array($mode,['missing-ai-service-usage','missing-coding-canvas','missing-canvas-source-index','missing-profile_event_clock_activation_v1','missing-vp3_profile_canvases_v1','missing-profile_agent_response_settings_v1','missing-profile_agent_owner_requests_v1'],true);
    check(!$complete && $dbCalls === ($needsConnection?1:0) && $calls === [], 'Missing required storage was marked ready or GET performed migrations');
    check(str_contains($html, 'Run Upgrade'), 'Missing required storage must expose the upgrade control');
}
check($fixture->query('SELECT value FROM existing_content')->fetchColumn() === 'preserve me', 'Upgrade changed existing content');
echo 'PASS '.$mode."\n";
