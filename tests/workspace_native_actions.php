<?php
declare(strict_types=1);
$dsn=getenv('VP3_WORKSPACE_MYSQL_DSN')?:'sqlite::memory:';
$pdo=new PDO($dsn,'root',getenv('VP3_WORKSPACE_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$tail=$mysql?' ENGINE=InnoDB':'';
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{if(! $GLOBALS['mysql']){$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return(bool)$s->fetchColumn();}$s=db()->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(bool)$s->fetchColumn();}
function column_exists(string $table,string $column):bool{if(!$GLOBALS['mysql'])return in_array($column,array_column(db()->query('PRAGMA table_info(`'.$table.'`)')->fetchAll(),'name'),true);$s=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return(bool)$s->fetchColumn();}
function has_permission(string $permission,?array $user=null):bool{return !empty($user['can_edit']);}
function personal_capability_has_v242(string $permission,?array $user=null):bool{return !empty($user['can_knowledge']);}
class HomeServerHttpsSessionError extends RuntimeException{}
require __DIR__.'/../includes/homeserver-workspace-sync-v1.php';
require __DIR__.'/../includes/crm-v180.php';
require __DIR__.'/../includes/knowledge.php';
require __DIR__.'/../includes/user-calendar-v1300.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $call,int $code):void{try{$call();}catch(Throwable $e){check($e->getCode()===$code,'Unexpected denial: '.$e->getMessage().' code '.$e->getCode());return;}throw new RuntimeException('Edit should be denied');}
// Separate test processes also run against the same MySQL service.
foreach(['homeserver_workspace_mutations_v1','knowledge_chunks','knowledge_items','user_calendar_events','crm_contacts','homeserver_https_sessions','users'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,can_edit INTEGER,can_knowledge INTEGER)'.$tail);
$pdo->exec('INSERT INTO users VALUES(1,1,1,1),(2,1,1,1)');
$pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id VARCHAR(50),session_token_hash CHAR(64),status VARCHAR(20))'.$tail);
$session=['user_id'=>1,'device_id'=>'hs-'.str_repeat('a',24),'session_token_hash'=>str_repeat('b',64),'status'=>'active'];$pdo->prepare('INSERT INTO homeserver_https_sessions VALUES(?,?,?,?)')->execute(array_values($session));
$pdo->exec('CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name VARCHAR(120),email VARCHAR(190),email_normalized VARCHAR(190),phone VARCHAR(80),company VARCHAR(190),lifecycle_stage VARCHAR(80),status VARCHAR(20),updated_at VARCHAR(40))'.$tail);
$pdo->exec("INSERT INTO crm_contacts VALUES(1,1,'Original','one@example.invalid','one@example.invalid','','','new','active','2026-01-01'),(2,2,'Other account','two@example.invalid','two@example.invalid','','','new','active','2026-01-01')");
$pdo->exec('CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,title VARCHAR(190),description TEXT,content_text TEXT,knowledge_scope VARCHAR(30),file_path VARCHAR(190),updated_at VARCHAR(40))'.$tail);
$pdo->exec("INSERT INTO knowledge_items VALUES(1,1,'Original note','','Original content','personal','uploads/keep-original.pdf','2026-01-01'),(2,1,'System note','','Shared content','system','','2026-01-01'),(3,2,'Other note','','Secret','personal','','2026-01-01')");
$pdo->exec('CREATE TABLE knowledge_chunks(knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT)'.$tail);
$pdo->exec('CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title VARCHAR(190),description TEXT,location VARCHAR(500),start_at_utc VARCHAR(40),end_at_utc VARCHAR(40),timezone VARCHAR(80),all_day INTEGER,status VARCHAR(20),updated_at VARCHAR(40),source VARCHAR(32),source_reference VARCHAR(190))'.$tail);
$pdo->exec("INSERT INTO user_calendar_events VALUES(1,1,'Original event','','','2026-10-08 16:00:00','2026-10-08 17:00:00','America/Phoenix',0,'active','2026-01-01','user',''),(2,2,'Other event','','','2026-10-08 16:00:00','2026-10-08 17:00:00','UTC',0,'active','2026-01-01','user','')");
function edit(string $dataset,string $key):array{return workspace_sync_exchange_v1($GLOBALS['session'],['action'=>'edit','dataset'=>$dataset,'key'=>$key]);}
function change(string $dataset,string $key,array $fields,string $id):array{$base=edit($dataset,$key);return ['action'=>'mutate','dataset'=>$dataset,'key'=>$key,'fields'=>$fields,'mutation_id'=>$id,'expected_revision'=>$base['record']['record_revision']];}
$request=change('contacts','crm_contacts:1',['display_name'=>'Updated 💡','email'=>'NEW@example.invalid'],'retry-00001');
$receipt=workspace_sync_exchange_v1($session,$request);check($receipt['applied'],'Cloud accepted contact edit');
check($pdo->query('SELECT name FROM crm_contacts WHERE id=1')->fetchColumn()==='Updated 💡','Exact native contact changed');
check($pdo->query('SELECT email_normalized FROM crm_contacts WHERE id=1')->fetchColumn()==='new@example.invalid','Contact email normalized');
$pdo->exec("UPDATE crm_contacts SET name='Later native edit' WHERE id=1");
check(workspace_sync_exchange_v1($session,$request)['idempotent_replay'],'Lost receipt replays original result');
check($pdo->query('SELECT name FROM crm_contacts WHERE id=1')->fetchColumn()==='Later native edit','Replay cannot overwrite newer native change');
$bad=$request;$bad['fields']['display_name']='Different payload';denied(fn()=>workspace_sync_exchange_v1($session,$bad),409);
$stale=change('contacts','crm_contacts:1',['phone'=>'555123'],'stale-00001');$pdo->exec("UPDATE crm_contacts SET phone='999' WHERE id=1");denied(fn()=>workspace_sync_exchange_v1($session,$stale),409);check($pdo->query('SELECT phone FROM crm_contacts WHERE id=1')->fetchColumn()==='999','Stale edit preserved source');
denied(fn()=>edit('contacts','crm_contacts:2'),409);denied(fn()=>edit('knowledge','knowledge_items:3'),409);denied(fn()=>edit('knowledge','knowledge_items:2'),403);
$k=change('knowledge','knowledge_items:1',['title'=>'Updated note','content_text'=>'Searchable full 中文 💡 evidence'],'knowledge-00001');
workspace_sync_exchange_v1($session,$k);check($pdo->query('SELECT content_text FROM knowledge_items WHERE id=1')->fetchColumn()==='Searchable full 中文 💡 evidence','Knowledge source updated');check(str_contains((string)$pdo->query('SELECT chunk_text FROM knowledge_chunks WHERE knowledge_id=1')->fetchColumn(),'中文'),'Native search index rebuilt');check($pdo->query('SELECT file_path FROM knowledge_items WHERE id=1')->fetchColumn()==='uploads/keep-original.pdf','Original attachment preserved');
$calendar=change('calendar','user_calendar_events:1',['date'=>'2026-10-09','end_date'=>'2026-10-09','start_time'=>'10:30','end_time'=>'11:30'],'calendar-00001');workspace_sync_exchange_v1($session,$calendar);check($pdo->query('SELECT start_at_utc FROM user_calendar_events WHERE id=1')->fetchColumn()==='2026-10-09 17:30:00','Native timezone conversion');check((int)$pdo->query('SELECT count(*) FROM user_calendar_events')->fetchColumn()===2,'No duplicate calendar event');
$invalid=change('calendar','user_calendar_events:1',['end_time'=>'00:01'],'calendar-00002');denied(fn()=>workspace_sync_exchange_v1($session,$invalid),0); // InvalidArgumentException is mapped to 422 by the HTTP API.
$bad=change('contacts','crm_contacts:1',['display_name'=>'Valid'],'permissions-00001');$pdo->exec('UPDATE users SET can_edit=0 WHERE id=1');denied(fn()=>workspace_sync_exchange_v1($session,$bad),403);$pdo->exec('UPDATE users SET can_edit=1 WHERE id=1');
$bad['fields']=['owner_user_id'=>'2'];denied(fn()=>workspace_sync_exchange_v1($session,$bad),0);
$pdo->exec('UPDATE crm_contacts SET owner_user_id=2 WHERE id=1');denied(fn()=>workspace_sync_exchange_v1($session,$request),409);$pdo->exec('UPDATE crm_contacts SET owner_user_id=1 WHERE id=1');
$pdo->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");denied(fn()=>workspace_sync_exchange_v1($session,$request),410);
echo 'Native writes, index rebuild, attachments, timezone, exact retry, stale edits, current permissions, ownership and revocation PASS'.PHP_EOL;
