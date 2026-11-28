<?php
declare(strict_types=1);

class ActivityReadPDO extends PDO
{
    public bool $readOnly = false;
    public int $writeAttempts = 0;
    public ?Closure $afterHistory = null;

    private function inspect(string $sql): void
    {
        if ($this->readOnly && !preg_match('/\A\s*(?:SELECT|PRAGMA)\b/i', $sql)) {
            $this->writeAttempts++;
            throw new RuntimeException('Activity GET attempted a database write.');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->inspect($query);
        if ($this->readOnly && $this->afterHistory && str_contains($query, 'AS last_message')) {
            $callback = $this->afterHistory;
            $this->afterHistory = null;
            $callback();
        }
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->inspect($statement);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->inspect($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

function db(): PDO { return $GLOBALS['activity_pdo']; }
function url(string $path = ''): string { return '/vp3'.$path; }
function csrf_token(): string { return 'fixture-session-csrf'; }
function setting(string $key, mixed $default = null): mixed { return $default; }
function get_tracks(): array { return []; }
function has_permission(string $permission, array $user): bool
{
    return !in_array($permission, $GLOBALS['activity_denied'][(int)$user['id']] ?? [], true);
}
function personal_capability_has_v242(string $permission, array $user): bool { return has_permission($permission, $user); }
function table_exists(string $table): bool
{
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $query = db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        return $query->fetchColumn() !== false;
    }
    $query = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return $query->fetchColumn() !== false;
}
function column_exists(string $table, string $column): bool
{
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $query = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $query->execute([$table, $column]);
        return $query->fetchColumn() !== false;
    }
    return in_array($column, array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
}
function agent_brain_schema_ready(): bool { return table_exists('agent_memory_items'); }
function homeserver_task_calendar_v243_homeserver_calendar_items(...$args): array
{
    throw new RuntimeException('Activity GET attempted a remote Calendar call.');
}

require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';

function activity_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['activity_checks'] = ($GLOBALS['activity_checks'] ?? 0) + 1;
}
function activity_rejects(int $code, callable $work): void
{
    try { $work(); }
    catch (Throwable $error) {
        activity_check($error->getCode() === $code, 'Expected '.$code.', got '.$error->getCode().' '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error '.$code);
}
function activity_sections(array $state): array { return array_column($state['sections'], null, 'id'); }

$dsn = getenv('VP3_TEST_MYSQL_DSN') ?: 'sqlite::memory:';
$pdo = $GLOBALS['activity_pdo'] = new ActivityReadPDO($dsn, getenv('VP3_TEST_MYSQL_USER') ?: null, getenv('VP3_TEST_MYSQL_PASS') ?: null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
if ($mysql) {
    $database = 'activity_hub_'.bin2hex(random_bytes(6));
    $pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function () use ($pdo, $database): void {
        $pdo->readOnly = false;
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec('DROP DATABASE '.$database);
    });
    $pdo->exec('USE '.$database);
} else {
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main');
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema;
        CREATE TABLE information_schema.statistics(table_schema TEXT,table_name TEXT,index_name TEXT)");
}
$intKey = $mysql ? 'INT UNSIGNED PRIMARY KEY' : 'INTEGER PRIMARY KEY';
$bigKey = $mysql ? 'BIGINT UNSIGNED PRIMARY KEY' : 'INTEGER PRIMARY KEY';
$pdo->exec("CREATE TABLE users(id $intKey,is_active INTEGER,role TEXT);
    INSERT INTO users VALUES(1,1,'member'),(2,1,'member');
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(11,1,1,'Longfellow'),(12,1,1,'Second Agent'),(21,2,1,'Other owner');
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title TEXT,updated_at TEXT);
    INSERT INTO chat_conversations VALUES(10,1,NULL,'System Chat','2026-01-01'),(11,1,11,'First project','2026-01-01'),
      (12,1,12,'Secret second Agent','2026-01-01'),(13,1,11,'Latest project','2026-01-01'),(20,2,21,'Secret other owner','2026-01-01');
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,role TEXT,message TEXT);
    INSERT INTO chat_messages VALUES(10,10,'user','System history'),(11,11,'user','First message'),
      (12,12,'user','Secret other Agent message'),(20,20,'user','Secret foreign message'),(30,13,'user','Latest message');
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
      owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE agent_memory_items(id $bigKey,user_id INTEGER,user_agent_id INTEGER,memory_scope_version INTEGER,
      memory_type VARCHAR(40),subject TEXT,memory_text TEXT,metadata_json TEXT,is_active INTEGER,last_seen_at DATETIME);
    CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type);
    CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,last_seen_at);
    CREATE TABLE agent_chat_archive(id INTEGER,user_agent_id INTEGER,created_at DATETIME);
    CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,location TEXT,
      start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,recurrence TEXT,agent_notifications INTEGER,source TEXT,
      source_reference TEXT,status TEXT,created_by_agent_id INTEGER);
    CREATE TABLE artist_transcript_folders_v177(id $bigKey,created_by_user_id INTEGER);
    CREATE TABLE knowledge_items(id $intKey,folder_id INTEGER,created_by_user_id INTEGER,
      knowledge_scope TEXT,visibility TEXT,is_published INTEGER,content_text TEXT);
    CREATE TABLE agent_workflow_runs(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,source_kind TEXT,
      source_key TEXT,execution_target TEXT,title TEXT,status TEXT,updated_at TEXT);
    CREATE TABLE agent_workflow_actions(id INTEGER PRIMARY KEY,run_id INTEGER,owner_user_id INTEGER,result_json TEXT);
    CREATE TABLE agent_cloud_missions_v1(mission_id CHAR(36) PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,run_id INTEGER);
");
if (!$mysql) $pdo->exec("INSERT INTO information_schema.statistics SELECT 'main',tbl_name,name FROM sqlite_master WHERE type='index'");
reminder_schema_v1($pdo);
vp3_task_lists_schema_v1($pdo);
vp3_coding_canvas_schema_v1($pdo);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$task = $pdo->prepare('INSERT INTO agent_memory_items VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach ([[1,1,null,'System task'],[2,1,11,'Named task'],[3,1,12,'Secret other Agent task'],
    [4,2,21,'Secret other owner task'],[5,1,11,'Denied task'],[6,1,11,'Closed task']] as [$id,$owner,$agent,$title]) {
    $task->execute([$id,$owner,$agent,410,'task',$title,'Private task body never returned',
        json_encode(['task_status'=>$id===6?'completed':'open']),1,$now->format('Y-m-d H:i:s')]);
}
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(11,'knowledge','brain:5','deny');
    INSERT INTO agent_task_lists_v1 VALUES(1,1,0,'System list','system','2026-01-01'),
      (2,1,11,'Named list','named','2026-01-01'),(3,1,12,'Secret other Agent list','other','2026-01-01'),
      (4,2,21,'Secret other owner list','foreign','2026-01-01');
    INSERT INTO agent_task_list_items_v1 VALUES(2,2,'named-task','2026-01-01'),(2,5,'denied-task','2026-01-01');");
$reminder = $pdo->prepare("INSERT INTO agent_reminders_v1
    (id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,followup_action_id,created_at,updated_at)
    VALUES(?,?,?,'UTC',?,'none','chat',?,'active',?,?,?,?)");
foreach ([[1,1,'Owner reminder',11,null],[2,2,'Secret owner reminder',20,null],[3,1,'Other Agent revisit',12,103]] as [$id,$owner,$title,$cid,$action]) {
    $reminder->execute([$id,$owner,$title,$now->format('Y-m-d\TH:i:s'),reminder_utc_v1($now->modify('+1 hour')),
        $cid,$action,reminder_utc_v1($now),reminder_utc_v1($now)]);
}
$event = $pdo->prepare("INSERT INTO user_calendar_events VALUES(?,?,?,'Private event detail','','','','UTC',0,'none',1,'user','','active',NULL)");
foreach ([[1,1,'Today event'],[2,1,'Future event'],[3,2,'Secret calendar'],[4,1,'Cancelled event']] as [$id,$owner,$title]) {
    $event->execute([$id,$owner,$title]);
}
$updateEvent = $pdo->prepare('UPDATE user_calendar_events SET start_at_utc=?,end_at_utc=?,status=? WHERE id=?');
foreach ([[1,'+1 hour','+2 hours','active'],[2,'+3 days','+3 days 1 hour','active'],
    [3,'+30 minutes','+1 hour','active'],[4,'+2 hours','+3 hours','cancelled']] as [$id,$start,$end,$status]) {
    $updateEvent->execute([$now->modify($start)->format('Y-m-d H:i:s'),$now->modify($end)->format('Y-m-d H:i:s'),$status,$id]);
}
$missionId = '8a000000-0000-4000-8000-000000000001';
$pdo->prepare("INSERT INTO agent_workflow_runs (id,owner_user_id,agent_id,source_kind,source_key,execution_target,title,status,updated_at) VALUES(101,1,11,'cloud_mission',?,'cloud','Active mission','executing',?)")
    ->execute([$missionId,$now->format('Y-m-d H:i:s')]);
$pdo->prepare('INSERT INTO agent_cloud_missions_v1 VALUES(?,1,11,101)')->execute([$missionId]);
$pdo->exec("INSERT INTO agent_workflow_runs (id,owner_user_id,agent_id,source_kind,source_key,execution_target,title,status,updated_at) VALUES(103,1,12,'agent_initiative_action','','cloud','Other revisit','completed','2026-01-01');
    INSERT INTO agent_workflow_actions VALUES(103,103,1,'{}');");
$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN capability_key TEXT');
$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN sequence_no INTEGER DEFAULT 1');
$pdo->exec('ALTER TABLE agent_workflow_runs ADD COLUMN goal TEXT');
$pdo->exec('ALTER TABLE agent_workflow_runs ADD COLUMN capability_key TEXT');
$pdo->exec('ALTER TABLE agent_workflow_runs ADD COLUMN last_error_class TEXT');
$pdo->exec("UPDATE agent_workflow_actions SET capability_key='initiative.action' WHERE id=103;
    INSERT INTO artist_transcript_folders_v177 VALUES(1,1),(2,1),(3,2);
    INSERT INTO knowledge_items VALUES(1,1,1,'personal','private',0,'SECRET SOURCE CODE'),
      (2,1,1,'personal','private',0,'SECRET APP DATA'),
      (3,2,1,'personal','private',0,'OTHER AGENT CODE'),
      (4,3,2,'personal','private',0,'OTHER OWNER CODE');");
$canvasId = '9a000000-0000-4000-8000-000000000001';
$codingRun = $pdo->prepare("INSERT INTO agent_workflow_runs
    (id,owner_user_id,agent_id,source_kind,source_key,execution_target,title,status,updated_at,goal,capability_key)
    VALUES(?,?,?,'coding_canvas','','cloud',?,?,'2026-01-01',?,'coding.build')");
foreach ([[201,1,11,11,'Queued draft','approved'],[202,1,12,12,'Secret other Agent build','executing'],
    [203,2,21,20,'Secret foreign build','approved'],[204,1,11,12,'Secret wrong Agent Chat','approved'],
    [205,1,11,20,'Secret wrong owner Chat','approved'],[206,1,11,999,'Deleted build Chat','approved'],
    [207,1,11,11,'Finished build','completed'],[208,1,0,10,'System queued draft','approved']] as [$id,$owner,$agent,$cid,$title,$status]) {
    $codingRun->execute([$id,$owner,$agent,$title,$status,json_encode(['conversation_id'=>$cid,'canvas_id'=>$canvasId,
        'prompt'=>'SECRET BUILD PROMPT', 'model'=>['provider'=>'openai','model'=>'fixture-coder','funding'=>'system']],JSON_THROW_ON_ERROR)]);
}
$insertCanvas = $pdo->prepare('INSERT INTO vp3_coding_canvases_v1
    (id,owner_user_id,agent_id,conversation_id,folder_id,title,state_item_id,draft_revision) VALUES(?,?,?,?,?,?,?,1)');
$insertCanvas->execute([$canvasId,1,11,11,1,'Saved draft',2]);
$insertCanvas->execute(['9a000000-0000-4000-8000-000000000002',1,12,12,2,'Secret other Agent Canvas',3]);
$pdo->prepare('INSERT INTO vp3_coding_canvas_versions_v1(canvas_id,revision,code_item_id,artifact_hash) VALUES(?,1,1,?)')
    ->execute([$canvasId,str_repeat('0',64)]);
$pdo->exec('CREATE TABLE agent_connection_email_drafts_v1(id CHAR(32) PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,
        conversation_id INTEGER,status VARCHAR(16),sent_message_id VARCHAR(190));
    CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE agent_connections_v1(id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider VARCHAR(32),status VARCHAR(16),
        authorization_key CHAR(64),client_fingerprint CHAR(64),account_email VARCHAR(254),read_enabled INTEGER,
        send_enabled INTEGER,followups_enabled INTEGER);
    CREATE TABLE agent_connection_email_followups_v1(owner_user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,
        connection_id INTEGER,authorization_key CHAR(64),account_email VARCHAR(254),status VARCHAR(20),next_check_at DATETIME,
        expires_at DATETIME,operation_key VARCHAR(190),origin_user_message_id INTEGER,recipient_email VARCHAR(254),
        sent_message_id VARCHAR(190),claim_key CHAR(32),reply_chat_message_id INTEGER);
    ALTER TABLE chat_messages ADD COLUMN user_id INTEGER;
    UPDATE chat_messages SET user_id=(SELECT user_id FROM chat_conversations c WHERE c.id=chat_messages.conversation_id) WHERE role=\'user\';
    ALTER TABLE chat_messages ADD COLUMN context_json TEXT;
    ALTER TABLE chat_messages ADD COLUMN created_at DATETIME');
$config['connections']['gmail'] = ['client_id'=>'activity-fixture','client_secret'=>str_repeat('x',12)];
$gmailGrant = str_repeat('a',64);
$pdo->prepare("INSERT INTO agent_connections_v1 VALUES (1,1,'gmail','connected',?,?,?,1,1,1)")
    ->execute([$gmailGrant,agent_connections_client_fingerprint_v1(),'owner@example.test']);
$watch = $pdo->prepare('INSERT INTO agent_connection_email_followups_v1 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL)');
foreach ([[1,11,11,$gmailGrant,'new_sender',null,'sender@example.test',''],[1,11,13,$gmailGrant,'thread',30,'thread@example.test','sent_43'],
    [1,12,12,$gmailGrant,'wrong_agent',12,'hidden@example.test',''],[1,11,13,str_repeat('b',64),'old_grant',null,'stale@example.test',''],
    [1,11,13,$gmailGrant,'missing_origin',999,'missing@example.test',''],[2,21,20,$gmailGrant,'other_owner',null,'other@example.test','']] as
    [$owner,$agent,$conversation,$grant,$key,$origin,$sender,$sent]) {
    $watch->execute([$owner,$agent,$conversation,1,$grant,'owner@example.test','waiting',$now->format('Y-m-d H:i:s'),
        $now->modify('+1 day')->format('Y-m-d H:i:s'),$key,$origin,$sender,$sent]);
}
$gmailOutcome=$pdo->prepare("INSERT INTO chat_messages (id,conversation_id,role,message,context_json,created_at)
    VALUES (?,?,'assistant','PRIVATE SENTINEL',?,?)");
$gmailDraft=$pdo->prepare('INSERT INTO agent_connection_email_drafts_v1 VALUES (?,?,?,?,?,?)');
foreach ([[40,1,11,13,'auto_reply','sent'],[41,1,12,12,'auto_reply','sent'],[42,2,21,20,'auto_reply','sent'],
    [43,1,11,13,null,'sent'],[44,1,11,13,'auto_reply','prepared'],[45,1,11,13,'manual','sent']] as [$id,$owner,$agent,$cid,$mode,$status]) {
    $draftId = str_pad((string)$id,32,'0',STR_PAD_LEFT);
    $providerId = 'sent_'.$id;
    $gmailDraft->execute([$draftId,$owner,$agent,$cid,$status,$id===45?'different_id':$providerId]);
    $gmailOutcome->execute([$id,$cid,json_encode(['source'=>'agent_connection_email','email_outcome'=>[
        'draft_id'=>$draftId,'message_id'=>$providerId]+($mode===null?[]:['mode'=>$mode])],JSON_THROW_ON_ERROR),
        gmdate('Y-m-d H:i:s')]);
}

// The activity reader projects consent state only; private coordinates and names must stay in Phone Tracking.
$pdo->exec("CREATE TABLE vp3_phone_devices_v1(id CHAR(32),owner_user_id INTEGER,generation INTEGER,
    device_status VARCHAR(12),allow_location INTEGER,expires_at BIGINT,location_state VARCHAR(12),device_name VARCHAR(48));
    CREATE TABLE vp3_phone_places_v1(id CHAR(32),owner_user_id INTEGER,name VARCHAR(80),latitude DOUBLE,longitude DOUBLE);
    CREATE TABLE vp3_phone_arrival_rules_v1(owner_user_id INTEGER,agent_id INTEGER,conversation_id INTEGER,device_id CHAR(32),
        generation INTEGER,place_id CHAR(32),state VARCHAR(12),task_query VARCHAR(100))");
$pdo->prepare("INSERT INTO vp3_phone_devices_v1 VALUES ('a',1,2,'approved',1,?,'live','Private handset')")
    ->execute([time()+3600]);
$pdo->exec("INSERT INTO vp3_phone_places_v1 VALUES ('b',1,'Private landmark',33.4484,-112.0740);
    INSERT INTO vp3_phone_arrival_rules_v1 VALUES (1,11,13,'a',2,'b','active','Private task topic'),
    (1,11,11,'a',2,'b','requested','Other conversation'),(1,12,12,'a',2,'b','requested','Other Agent'),
    (2,21,20,'a',2,'b','requested','Other account'),(1,11,13,'a',1,'b','requested','Old phone session')");

if ($mysql) $pdo->exec('START TRANSACTION READ ONLY');
else $pdo->exec('PRAGMA query_only=ON');
$pdo->readOnly = true;
$before = $mysql ? 0 : (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$user = ['id'=>1,'is_active'=>1];
$binding = vp3_catch_up_page_binding_v1($user);
$state = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding);
$location = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'location');
activity_check($location['sections'] === [] && $location['specialists'] === [
    ['id'=>'location','conversation_id'=>13,'state'=>'armed','progress'=>null,'detail'=>'1 active arrival alert.']
], 'A named Agent sees only the active alert count for its exact Chat and current phone session');
activity_check(!str_contains(json_encode($location,JSON_THROW_ON_ERROR),'Private landmark')
    && !str_contains(json_encode($location,JSON_THROW_ON_ERROR),'Private task topic'), 'Location activity omits private place and task content');
$otherThread = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','11',$binding,'location');
activity_check($otherThread['specialists'][0]['state'] === 'awaiting_consent'
    && $otherThread['specialists'][0]['detail'] === '1 alert awaits phone approval.', 'Pending phone consent is visible only in its originating Chat');
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','12',$binding,'location'));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','21','20',$binding,'location'));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'operator'));
$sections = activity_sections($state);
$gmailOnly = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'gmail');
activity_check(array_column($gmailOnly['sections'],'id')===['gmail_inbox'] && $gmailOnly['current_chat']===$state['current_chat'],
    'The Gmail report loads without projecting unrelated sources and keeps the exact Chat scope');
activity_rejects(422,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,['gmail']));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','12',$binding,'gmail'));
$mediaOnly = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'media');
activity_check(array_column($mediaOnly['sections'],'id')===['youtube','songs'] && $mediaOnly['current_chat']===$state['current_chat']
    && is_array($mediaOnly['media_report']['findings'] ?? null),
    'Media loads only the scoped report and playback history without writes');
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','12',$binding,'media'));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','21','20',$binding,'media'));
activity_rejects(422,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'media','all'));
activity_check($state['agent_name']==='Longfellow' && $state['current_chat']===['id'=>13,'title'=>'Latest project'], 'Agent and current Chat metadata reflect the exact owned scope');
activity_check(array_column($sections['history']['items'],'title')===['Latest project','First project'], 'History spans only the selected Agent Chats and is newest first');
$gmail = $sections['gmail_outcomes']['items'];
activity_check(array_column($gmail,'kind')===['gmail_watch','gmail_outcome','gmail_outcome']
    && array_column(array_slice($gmail,1),'id')===['gmail:sent:43','gmail:sent:40']
    && $gmail[1]['actions'][0]['conversation_id']===13 && $gmail[1]['actions'][0]['message_id']===43
    && !preg_match('/PRIVATE SENTINEL|sent_(?:40|43)/',json_encode($gmail,JSON_THROW_ON_ERROR)),
    'Top of Mind shows a live watch and the exact verified manual and auto sends without mailbox identifiers or foreign results');
$watches = vp3_agent_activity_gmail_watches_v1($pdo,vp3_agent_activity_scope_v1($pdo,$user,11,13),$now);
activity_check(array_column($watches,'title')===['Watching for new Gmail','Waiting for a Gmail reply']
    && $watches[0]['actions'][0]['conversation_id']===11 && $watches[1]['actions'][0]['message_id']===30,
    'Top of Mind keeps both sender and thread watches in their exact Chats, omitting stale grants and missing origins');
activity_check(array_column($sections['tasks']['items'],'title')===['Named task'], 'Tasks exclude other owners, other Agents, denied data and completed work');
activity_check($sections['tasks']['items'][0]['actions'][0]['list_id']===2, 'A task can open its actual existing parent list');
activity_check(array_column($sections['lists']['items'],'title')===['Named list'] && $sections['lists']['items'][0]['detail']==='1 open task', 'List count includes only permitted canonical members');
activity_check(array_column($sections['reminders']['items'],'title')===['Owner reminder'], 'Account reminders respect other-owner and Initiative Agent boundaries');
activity_check(!isset($sections['office_reviews']), 'The full activity feed does not load private Office review details');
activity_check(array_column($sections['calendar']['items'],'title')===['Today event','Future event'], 'Seven-day Calendar includes future events and excludes foreign or cancelled events');
activity_check(count($sections['missions']['items'])===1 && $sections['missions']['items'][0]['actions'][0]['mission_id']===$missionId, 'An active Mission retains its canonical exact source ID');
activity_check(array_column($sections['canvases']['items'],'title')===['Coding Agent','Saved draft'], 'Canvas work combines an active Coding Agent and saved draft metadata');
activity_check($sections['canvases']['items'][0]['actions'][0]['conversation_id']===11
    && $sections['canvases']['items'][0]['detail']==='A private draft is queued. · fixture-coder', 'Active coding work spans owned Chats with exact source navigation and actual status');
activity_check($sections['canvases']['items'][1]['actions'][0]['revision']===1, 'Canvas action selects the exact saved draft');
$serialized = json_encode($state,JSON_THROW_ON_ERROR);
activity_check(!str_contains($serialized,'SECRET') && !str_contains($serialized,'Secret') && !str_contains($serialized,'Private task body'), 'Response excludes raw data, private detail and foreign records');
foreach ($state['sections'] as $section) {
    activity_check(count($section['items'])<=3, 'Every section stays within its item bound');
    foreach ($section['items'] as $item) foreach ($item['actions'] as $action) {
        activity_check(str_starts_with($action['href'],'/vp3/'), 'Every action has a local fallback');
    }
}
activity_check(array_sum(array_map(static fn(array $section):int=>count($section['items']),$state['sections']))<=30, 'The complete feed stays within its item bound');
$system = activity_sections(vp3_agent_activity_hub_v1($pdo,$user,0,0));
activity_check(array_column($system['tasks']['items'],'title')===['System task'], 'CID zero works and system tasks cannot claim named-Agent tasks');
activity_check(array_column($system['lists']['items'],'title')===['System list'], 'System lists keep their separate namespace');
activity_check(array_column($system['canvases']['items'],'id')===['coding:208'], 'System Coding jobs keep their separate Agent namespace');
activity_rejects(405,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'POST','11','13',$binding));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13','stale'));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13','stale','tasks'));
activity_rejects(422,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','-1',$binding));
activity_rejects(422,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET',[],'13',$binding));
activity_rejects(403,fn()=>vp3_agent_activity_hub_v1($pdo,$user,21,0));
activity_rejects(403,fn()=>vp3_agent_activity_hub_v1($pdo,$user,11,12));
activity_rejects(403,fn()=>vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','12',$binding,'tasks'));
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>11];
activity_rejects(403,fn()=>vp3_agent_activity_hub_v1($pdo,$user,11,13));
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$GLOBALS['activity_denied'][1]=['agent_brain.access','personal_knowledge.access'];
$denied=vp3_agent_activity_hub_v1($pdo,$user,11,0);
activity_check($denied['coverage']['tasks']==='forbidden' && $denied['coverage']['lists']==='forbidden'
    && $denied['coverage']['canvases']==='forbidden', 'Source permission denial is explicit and removes its cards');
$GLOBALS['activity_denied']=[];
$pdo->afterHistory=static function():void{$GLOBALS['activity_denied'][1]=['account.access'];};
activity_rejects(403,fn()=>vp3_agent_activity_hub_v1($pdo,$user,11,13));
$GLOBALS['activity_denied']=[];
activity_check($pdo->writeAttempts===0 && ($mysql || (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before), 'GET makes zero attempted or successful database writes');
$pdo->readOnly=false;
if ($mysql) $pdo->rollBack();
else $pdo->exec('PRAGMA query_only=OFF');

// Exercise the workspace past both the preview and the older inventory limits.
$taskIds = range(1000, 1204);
$listIds = range(1400, 1415);
$reminderIds = range(2000, 2102);
$addMember = $pdo->prepare("INSERT INTO agent_task_list_items_v1 VALUES (2,?,?,'2026-01-01')");
foreach ($taskIds as $id) {
    $status = match ($id) { 1000=>'completed', 1001=>'cancelled', default=>'open' };
    $metadata = ['task_status'=>$status];
    if (in_array($id, [1002, 1003], true)) $metadata['assigned_user_id'] = 2;
    $task->execute([$id,1,11,410,'task','Inventory task '.$id,'Private inventory task body',
        json_encode($metadata,JSON_THROW_ON_ERROR),1,$now->format('Y-m-d H:i:s')]);
    if ($id !== 1002) $addMember->execute([$id,'inventory-'.$id]);
}
foreach ([[1301,1,11,0],[1302,1,11,1],[1303,2,21,1],[1304,1,12,1]] as [$id,$owner,$agent,$active]) {
    $task->execute([$id,$owner,$agent,410,'task','Hidden inventory task '.$id,'Private inventory task body',
        '{"task_status":"open"}',$active,$now->format('Y-m-d H:i:s')]);
    $addMember->execute([$id,'inventory-hidden-'.$id]);
}
$pdo->exec("INSERT INTO user_agent_data_rules VALUES (11,'knowledge','brain:1302','deny')");
$denySecondAgentTask = $pdo->prepare("INSERT INTO user_agent_data_rules VALUES (12,'knowledge',?,'deny')");
foreach (range(1600, 1650) as $id) {
    $task->execute([$id,1,12,410,'task','Restricted second Agent task','Private inventory task body',
        '{"task_status":"open"}',1,$now->format('Y-m-d H:i:s')]);
    $denySecondAgentTask->execute(['brain:'.$id]);
}
$addList = $pdo->prepare("INSERT INTO agent_task_lists_v1 VALUES (?,1,11,?,?,'2026-01-01')");
foreach ($listIds as $id) $addList->execute([$id,'Inventory list '.$id,'inventory-'.$id]);
$stamp = reminder_utc_v1($now);
$addReminder = $pdo->prepare("INSERT INTO agent_reminders_v1
    (id,owner_user_id,title,timezone,start_local,recurrence,delivery,next_at,status,conversation_id,created_at,updated_at)
    VALUES (?,1,?,'UTC',?,'none','chat',?,?,11,?,?)");
foreach ($reminderIds as $id) {
    $status = match ($id) { 2101=>'finished', 2102=>'cancelled', default=>'active' };
    $addReminder->execute([$id,'Inventory reminder '.$id,$now->format('Y-m-d\TH:i:s'),
        $status === 'active' ? reminder_utc_v1($now->modify('+1 hour')) : null,$status,$stamp,$stamp]);
}

if ($mysql) $pdo->exec('START TRANSACTION READ ONLY');
else $pdo->exec('PRAGMA query_only=ON');
$pdo->readOnly=true;
$inventoryBefore = $mysql ? 0 : (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$office = static fn(mixed $collection='',mixed $cursor='',mixed $listId=''):array
    => vp3_agent_activity_hub_request_v1($pdo,$user,'GET','11','13',$binding,'tasks',$collection,$cursor,$listId);
$compactOffice = activity_sections($office());
activity_check(array_keys(activity_sections($office('reviews')))===['office_reviews'],
    'The compact specialist can read its latest review without loading any inventory sections');
activity_check(array_keys($compactOffice)===['office_reviews','reminders','tasks','lists']
    && count($compactOffice['tasks']['items'])===3 && count($compactOffice['lists']['items'])===3
    && count($compactOffice['reminders']['items'])===3
    && !str_contains(json_encode($compactOffice,JSON_THROW_ON_ERROR),'Inventory reminder 2102'),
    'The compact Office view retains its three-item previews and open-reminder behavior');
$expanded = $office('all');
$expandedSections = activity_sections($expanded);
activity_check(array_keys($expandedSections)===array_keys($compactOffice)
    && $expanded['current_chat']===$state['current_chat']
    && array_keys($expanded['pages'])===['reminders','tasks','lists'],
    'The expanded Office view includes only the four scoped sections and inventory pagination');

$inventoryCards = static function (string $collection,array $page,string $listId='') use ($office): array {
    $cards = [];
    $seenCursors = [];
    while (true) {
        $sections = activity_sections($page);
        activity_check($page['coverage'][$collection]==='ready' && count($sections[$collection]['items'])<=12,
            'Each '.$collection.' inventory page is ready and stays within its 12-item bound');
        $cards = [...$cards,...$sections[$collection]['items']];
        $cursor = $page['pages'][$collection]['next_cursor'];
        if ($cursor === null) return $cards;
        activity_check(is_string($cursor) && $cursor !== '' && !isset($seenCursors[$cursor]),
            'The '.$collection.' inventory cursor advances without replay');
        $seenCursors[$cursor] = true;
        $page = $office($collection,$cursor,$listId);
        activity_check(array_keys(activity_sections($page))===[$collection] && array_keys($page['pages'])===[$collection]
            && ($listId === '' || ($page['list_id'] ?? null)===(int)$listId),
            'A continuation projects only the selected '.$collection.' collection');
    }
};

foreach (['tasks'=>$taskIds,'lists'=>$listIds,'reminders'=>$reminderIds] as $collection=>$ids) {
    $prefix = 'Inventory '.rtrim($collection,'s').' ';
    $expected = array_map(static fn(int $id):string=>$prefix.$id,array_reverse($ids));
    array_push($expected,...match ($collection) {
        'tasks'=>['Closed task','Named task'], 'lists'=>['Named list'], 'reminders'=>['Owner reminder'],
    });
    $firstPage = $office($collection);
    activity_check(array_keys(activity_sections($firstPage))===[$collection]
        && count($expandedSections[$collection]['items'])===12
        && $expandedSections[$collection]['items']===activity_sections($firstPage)[$collection]['items']
        && $expanded['pages'][$collection]===$firstPage['pages'][$collection],
        'The expanded and targeted '.$collection.' first pages agree');
    $cards = $inventoryCards($collection,$expanded);
    activity_check(array_column($cards,'title')===$expected,
        'The '.$collection.' inventory returns every permitted record in descending order once, including saved closed work');
    $details = array_column($cards,'detail','title');
    if ($collection === 'tasks') {
        activity_check(str_contains(strtolower($details['Inventory task 1000']),'completed')
            && str_contains(strtolower($details['Inventory task 1001']),'cancelled'),
            'Closed tasks display their saved status');
    } elseif ($collection === 'reminders') {
        activity_check(str_contains(strtolower($details['Inventory reminder 2101']),'finished')
            && str_contains(strtolower($details['Inventory reminder 2102']),'cancelled'),
            'Closed reminders display their saved status');
    }
}

$members = $office('list_items','','2');
activity_check(array_keys(activity_sections($members))===['list_items']
    && activity_sections($members)['list_items']['label']==='Named list' && $members['list_id']===2,
    'The member view returns the selected list title and no unrelated collection');
$expectedMembers = array_map(static fn(int $id):string=>'Inventory task '.$id,
    array_values(array_filter(array_reverse($taskIds),static fn(int $id):bool=>$id!==1002)));
$expectedMembers[] = 'Named task';
activity_check(array_column($inventoryCards('list_items',$members,'2'),'title')===$expectedMembers,
    'Member pagination returns the complete exact list, including closed members, excluding standalone, inactive, denied and out-of-scope tasks');
activity_check(!str_contains(json_encode([$expanded,$members],JSON_THROW_ON_ERROR),'Private inventory task body'),
    'The expanded workspace returns task cards without the raw task body');

$restrictedPage = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','12','12',$binding,'tasks','tasks');
$restrictedCursor = $restrictedPage['pages']['tasks']['next_cursor'];
activity_check(activity_sections($restrictedPage)['tasks']['items']===[] && is_string($restrictedCursor),
    'A full scanned page of restricted tasks returns a usable continuation without exposing a card');
$permittedPage = vp3_agent_activity_hub_request_v1($pdo,$user,'GET','12','12',$binding,'tasks','tasks',$restrictedCursor);
activity_check(array_column(activity_sections($permittedPage)['tasks']['items'],'id')===['task:1304','task:3']
    && $permittedPage['pages']['tasks']['next_cursor']===null,
    'A restricted-page continuation still reaches every older permitted task');

activity_rejects(422,static fn()=> $office('all','12'));
activity_rejects(422,static fn()=> $office('tasks',[]));
activity_rejects(422,static fn()=> $office('tasks','-1'));
activity_rejects(422,static fn()=> $office('reminders','99999999999999999'));
activity_rejects(422,static fn()=> $office('lists','','2'));
activity_rejects(422,static fn()=> $office('list_items'));
activity_rejects(422,static fn()=> $office('unknown'));
foreach (['3','4','999'] as $listId) activity_rejects(403,static fn()=> $office('list_items','',$listId));
activity_check($pdo->writeAttempts===0 && ($mysql || (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$inventoryBefore),
    'All inventory and list-member GET pages make zero attempted or successful database writes');
$pdo->readOnly=false;
if ($mysql) $pdo->rollBack();
else $pdo->exec('PRAGMA query_only=OFF');

$pdo->exec('DROP TABLE user_calendar_events');
if ($mysql) $pdo->exec('START TRANSACTION READ ONLY');
else $pdo->exec('PRAGMA query_only=ON');
$pdo->readOnly=true;
$missing=vp3_agent_activity_hub_v1($pdo,$user,11,13);
activity_check($missing['coverage']['calendar']==='unavailable' && activity_sections($missing)['calendar']['items']===[], 'Unavailable storage is explicit without dropping authorized sources');
activity_check(activity_sections($missing)['history']['items']!==[], 'One unavailable source leaves the other permitted sections available');
echo 'AGENT_ACTIVITY_HUB=PASS ('.$GLOBALS['activity_checks'].' assertions, '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
