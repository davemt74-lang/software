<?php
declare(strict_types=1);
$configPath=$argv[2]??throw new RuntimeException('Fixture configuration is required.');
if(($argv[1]??'')==='cleanup'){
    $cfg=json_decode((string)file_get_contents($configPath),true,512,JSON_THROW_ON_ERROR);
    if(!empty($cfg['database'])){
        if(!preg_match('/^vp3_pa1_owner_[a-f0-9]{16}$/D',$cfg['database']))throw new RuntimeException('Unexpected fixture database.');
        (new PDO($cfg['admin_dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]))->exec('DROP DATABASE `'.$cfg['database'].'`');
    }
    exit;
}
$cfg=['dsn'=>'sqlite:'.dirname($configPath).'/owner-api.sqlite'];
if($dsn=getenv('VP3_TEST_MYSQL_DSN')){
    $name='vp3_pa1_owner_'.bin2hex(random_bytes(8));$user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $admin->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $cfg=['dsn'=>preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$name,$dsn):rtrim($dsn,';').';dbname='.$name,'user'=>$user,'password'=>$password,'database'=>$name,'admin_dsn'=>$dsn];
}
file_put_contents($configPath,json_encode($cfg,JSON_THROW_ON_ERROR));chmod($configPath,0600);
$pdo=new PDO($cfg['dsn'],$cfg['user']??null,$cfg['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$id=str_starts_with($cfg['dsn'],'mysql:')?'INTEGER PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec('CREATE TABLE profile_visit_sessions (id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL,visitor_user_id INTEGER,session_key CHAR(64) NOT NULL,identity_disclosed INTEGER NOT NULL DEFAULT 0,view_count INTEGER NOT NULL DEFAULT 0,first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,last_message_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(owner_user_id,session_key))');
$pdo->exec('CREATE TABLE profile_events (id '.$id.',owner_user_id INTEGER NOT NULL,profile_session_id INTEGER,visitor_user_id INTEGER,profile_agent_id INTEGER,event_type VARCHAR(50) NOT NULL,priority INTEGER NOT NULL DEFAULT 10,dedupe_key CHAR(64) UNIQUE,metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE profile_agent_conversations (id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL,profile_agent_id INTEGER NOT NULL,profile_session_id INTEGER NOT NULL,visitor_user_id INTEGER,status VARCHAR(30),last_summary TEXT,last_message_at DATETIME,updated_at DATETIME)');
$pdo->exec('CREATE TABLE profile_agent_messages (id '.$id.',conversation_id INTEGER NOT NULL,sender_type VARCHAR(20),sender_user_id INTEGER,message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE agent_attention_items (id INTEGER PRIMARY KEY,owner_user_id INTEGER NOT NULL,source_conversation_id INTEGER,status VARCHAR(30),snoozed_until DATETIME)');
$pdo->exec('CREATE TABLE fixture_owner_mutations (id '.$id.',action VARCHAR(50),owner_user_id INTEGER)');
$session=$pdo->prepare('INSERT INTO profile_visit_sessions (id,owner_user_id,visitor_user_id,session_key) VALUES (?,?,?,?)');
foreach([[11,1,3],[12,1,4],[21,2,5]] as [$sessionId,$ownerId,$visitorId])$session->execute([$sessionId,$ownerId,$visitorId,hash('sha256','historical-session-'.$sessionId)]);
$pdo->exec("INSERT INTO profile_agent_conversations VALUES(101,1,7,11,3,'open','Visitor one','2030-01-01 00:00:00','2030-01-01 00:00:00'),(102,1,8,12,4,'open','Historical Agent visitor','2030-01-01 00:00:00','2030-01-01 00:00:00'),(201,2,9,21,5,'open','SECRET foreign visitor','2030-01-01 00:00:00','2030-01-01 00:00:00')");
$pdo->exec("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message) VALUES(101,'visitor',3,'Visitor one message'),(102,'visitor',4,'Historical Agent message'),(201,'visitor',5,'SECRET foreign message')");
$pdo->exec("INSERT INTO agent_attention_items VALUES(1,1,101,'pending',NULL),(2,1,102,'pending',NULL),(3,2,201,'pending',NULL)");
echo str_starts_with($cfg['dsn'],'mysql:')?'mysql':'sqlite';
