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
$db->exec("INSERT INTO permissions VALUES ('chat.access'), ('account.access')");
$db->exec("INSERT INTO role_permissions VALUES ('fan','chat.access'), ('fan','account.access')");
$db->exec('CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, artist_workspace_id INTEGER, title TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY, conversation_id INTEGER, user_id INTEGER, role TEXT, message TEXT, context_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec("INSERT INTO chat_conversations(id,user_id,title) VALUES (8,1,'Field work'),(9,1,'Other conversation'),(10,2,'Other account')");
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
