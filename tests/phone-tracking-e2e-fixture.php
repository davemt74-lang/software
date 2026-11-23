<?php
declare(strict_types=1);

// Invoked only by the browser test, with an isolated SQLite database and session directory.
if (PHP_SAPI !== 'cli' || $argc !== 2) exit(1);
$root = $argv[1];
require $root.'/includes/functions.php';
require $root.'/includes/auth.php';
require $root.'/includes/agent-catch-up-v1.php';
require $root.'/includes/phone-tracking-chat-v1.php';

$db = new PDO('sqlite:'.dirname($root).'/data.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, display_name TEXT, email TEXT, role TEXT, password_hash TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE permissions (permission_key TEXT PRIMARY KEY)');
$db->exec('CREATE TABLE role_permissions (role TEXT, permission_key TEXT)');
$db->exec("INSERT INTO permissions VALUES ('chat.access'), ('account.access'), ('agent_brain.access')");
$db->exec("INSERT INTO role_permissions VALUES ('fan','chat.access'), ('fan','account.access'), ('fan','agent_brain.access')");
$db->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, artist_workspace_id INTEGER, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER, role TEXT, message TEXT, context_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec("INSERT INTO chat_conversations(id,user_id,title) VALUES (8,1,'Field work'),(9,1,'Other conversation'),(10,2,'Other account')");

// Use the same owner/agent scopes and indexes as the Brain task storage read by private Chat.
$db->exec('CREATE TABLE agent_chat_archive (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, conversation_id INTEGER, created_at TEXT)');
$db->exec('CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_id,user_agent_id,created_at)');
$db->exec('CREATE TABLE agent_memory_items (
    id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, memory_type TEXT, subject TEXT,
    memory_text TEXT, memory_hash TEXT UNIQUE, memory_scope_version INTEGER DEFAULT 410, source_archive_id INTEGER,
    confidence REAL, occurrence_count INTEGER DEFAULT 1, first_seen_at TEXT, last_seen_at TEXT, is_active INTEGER, metadata_json TEXT
)');
$db->exec('CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type)');
$db->exec('CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,occurrence_count)');
require_once $root.'/includes/agent-task-lists-v1.php';
vp3_task_lists_schema_v1($db);
$task = $db->prepare("INSERT INTO agent_memory_items
    (user_id,user_agent_id,subject,memory_text,memory_hash,memory_type,is_active,metadata_json,last_seen_at)
    VALUES (?,?,?,?,?,'task',?,?,CURRENT_TIMESTAMP)");
foreach ([
    [1,null,'Recording: mic check',1,'open'],
    [1,null,'Recording: archived mix',1,'completed'],
    [1,null,'Recording: shelved session',0,'open'],
    [1,null,'Buy groceries',1,'open'],
    [1,7,'Recording: other agent session',1,'open'],
    [2,null,'Recording: other owner session',1,'open'],
    [1,null,'Recording: bring XLR cables for confidential Juniper rehearsal',1,'completed'],
    [1,null,'Recording: replace old XLR cables',1,'completed'],
    [2,null,'Recording: XLR cables for different owner',1,'open'],
    [1,7,'Recording: XLR cables for another agent',1,'open'],
] as [$uid,$aid,$title,$active,$state]) {
    $task->execute([$uid,$aid,$title,$title,hash('sha256',$title),$active,json_encode(['task_status'=>$state],JSON_THROW_ON_ERROR)]);
}

vp3_phone_schema_v1($db);
vp3_phone_chat_schema_v1($db);

$users = [];
foreach ([1=>'Map Operator', 2=>'Other Account'] as $id=>$displayName) {
    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $db->prepare("INSERT INTO users VALUES (?,?,?,'fan',?,1)")->execute([$id,$displayName,"member$id@example.test",$hash]);
    session_id(bin2hex(random_bytes(16)));
    session_start();
    $_SESSION = ['user_id'=>$id, 'auth_password_fingerprint'=>auth_password_fingerprint($id,$hash)];
    $users[] = ['session'=>session_id(),'csrf'=>csrf_token(),'binding'=>vp3_catch_up_page_binding_v1(['id'=>$id])];
    session_write_close();
}
echo json_encode($users, JSON_THROW_ON_ERROR);
