<?php
// CI-only HTTP fixture: calls the production exchanger with two real PDO accounts.
declare(strict_types=1);
$folder=getenv('VP3_WORKSPACE_FIXTURE_DIR');
if(!$folder)throw new RuntimeException('Test directory required.');
$pdo=new PDO('sqlite:'.$folder.'/cloud.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info(`'.$table.'`)')->fetchAll(),'name'),true);}
class HomeServerHttpsSessionError extends RuntimeException{}
function has_permission(string $permission,?array $user=null):bool{return !empty($user['is_active']);}
function personal_capability_has_v242(string $permission,?array $user=null):bool{return !empty($user['is_active']);}
require __DIR__.'/../includes/homeserver-workspace-sync-v1.php';
require __DIR__.'/../includes/crm-v180.php';
require __DIR__.'/../includes/knowledge.php';
require __DIR__.'/../includes/user-calendar-v1300.php';
if(!table_exists('users')){
 $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,display_name TEXT,is_active INTEGER)');
 $pdo->exec("INSERT INTO users VALUES(1,'own@example.invalid','Own account',1),(2,'other@example.invalid','OTHER ACCOUNT',1)");
 $pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id TEXT,session_token_hash TEXT,status TEXT)');
 $pdo->prepare('INSERT INTO homeserver_https_sessions VALUES(1,?,?,?)')->execute([getenv('VP3_WORKSPACE_FIXTURE_DEVICE'),hash('sha256',str_repeat('a',64)),'active']);
 $pdo->exec("CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name TEXT,email TEXT DEFAULT '',email_normalized TEXT DEFAULT '',phone TEXT DEFAULT '',company TEXT DEFAULT '',lifecycle_stage TEXT DEFAULT '',status TEXT DEFAULT 'active',updated_at TEXT DEFAULT '2026-01-01')");
 $pdo->exec("INSERT INTO crm_contacts(id,owner_user_id,name) VALUES(1,1,'Cloud contact'),(2,2,'OTHER CONTACT')");
 $pdo->exec('CREATE TABLE agent_commerce_products_v800(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,sku TEXT)');
 $pdo->exec("INSERT INTO agent_commerce_products_v800 VALUES(1,1,'Cloud product','SKU-HTTP'),(2,2,'OTHER PRODUCT','NO')");
 $pdo->exec("CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,title TEXT,content_text TEXT,file_path TEXT,description TEXT DEFAULT '',knowledge_scope TEXT DEFAULT 'personal',updated_at TEXT DEFAULT '2026-01-01')");
 $pdo->exec('CREATE TABLE knowledge_chunks(knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT)');
 $pdo->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,status TEXT,source TEXT,source_reference TEXT,updated_at TEXT)");
 $pdo->exec("INSERT INTO user_calendar_events VALUES(1,1,'Cloud event','','','2026-10-09 16:00:00','2026-10-09 17:00:00','America/Phoenix',0,'active','user','','2026-01-01')");
 $uploads=dirname(__DIR__).'/uploads';if(!is_dir($uploads))mkdir($uploads,0700,true);
 $name='workspace-http-'.basename($folder).'.bin';file_put_contents($uploads.'/'.$name,str_repeat('PDF original fixture ',100000));
 $pdo->prepare('INSERT INTO knowledge_items(id,created_by_user_id,title,content_text,file_path) VALUES(1,1,?,?,?)')->execute(['Cloud document',str_repeat('完整 💡 ',17000),'uploads/'.$name]);
}
header('Content-Type: application/json');
try{
 if(!hash_equals('Bearer '.str_repeat('a',64),(string)($_SERVER['HTTP_AUTHORIZATION']??''))||
   !hash_equals((string)getenv('VP3_WORKSPACE_FIXTURE_DEVICE'),(string)($_SERVER['HTTP_X_HOMESERVER_DEVICE']??'')))throw new HomeServerHttpsSessionError('Invalid test authority',401);
 $session=$pdo->query('SELECT * FROM homeserver_https_sessions WHERE user_id=1')->fetch();
 $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
 echo json_encode(workspace_sync_exchange_v1($session,$body),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code($e->getCode()>=400&&$e->getCode()<600?$e->getCode():422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
