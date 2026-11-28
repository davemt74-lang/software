<?php
declare(strict_types=1);

// Exercise the canonical SQL against a real database, including LEFT JOIN NULL
// semantics. No service methods or SELECT results are mocked.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $name): bool{$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function check(bool $ok,string $message): void{if(!$ok)throw new RuntimeException($message);}
require dirname(__DIR__).'/includes/social-network-v320.php';
require dirname(__DIR__).'/includes/human-messaging-v370.php';

$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,avatar_path TEXT,is_active INTEGER);
 CREATE TABLE user_social_settings(user_id INTEGER PRIMARY KEY,message_policy TEXT);
 CREATE TABLE user_follows(follower_user_id INTEGER,followed_user_id INTEGER);
 CREATE TABLE user_friendships(user_low_id INTEGER,user_high_id INTEGER,status TEXT);
 CREATE TABLE user_blocks(blocker_user_id INTEGER,blocked_user_id INTEGER);
 CREATE TABLE human_conversations(id INTEGER PRIMARY KEY,conversation_type TEXT,title TEXT,direct_user_low_id INTEGER,direct_user_high_id INTEGER,updated_at TEXT);
 CREATE TABLE human_conversation_members(conversation_id INTEGER,user_id INTEGER,left_at TEXT);
 CREATE TABLE human_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,sender_user_id INTEGER,body TEXT,reply_to_message_id INTEGER,edited_at TEXT,deleted_at TEXT,created_at TEXT);
 CREATE TABLE human_message_requests(conversation_id INTEGER PRIMARY KEY,requester_user_id INTEGER,recipient_user_id INTEGER,status TEXT,created_at TEXT,initial_message_id INTEGER);
 CREATE TABLE human_conversation_reads_v370(conversation_id INTEGER,user_id INTEGER,last_read_message_id INTEGER);
 CREATE TABLE human_message_legacy_links_v370(source_type TEXT,source_id INTEGER);
 INSERT INTO users VALUES(1,'Owner','',1),(2,'Friend','',1),(3,'Visitor','',1),(4,'Blocked','',1);
 INSERT INTO human_conversations VALUES(10,'direct','',1,2,'2026-11-27 12:00:00'),(20,'direct','',1,3,'2026-11-27 12:00:00'),(30,'direct','',1,4,'2026-11-27 12:00:00');
 INSERT INTO human_conversation_members VALUES(10,1,NULL),(10,2,NULL),(20,1,NULL),(20,3,NULL),(30,1,NULL),(30,4,NULL);
 INSERT INTO human_message_requests VALUES(20,3,1,'pending','2026-11-27 12:00:00',201);
 INSERT INTO human_messages VALUES(201,20,3,'Please accept',NULL,NULL,NULL,'2026-11-27 12:00:00');
 INSERT INTO human_messages VALUES(301,30,4,'Blocked',NULL,NULL,NULL,'2026-11-27 12:00:00');
 INSERT INTO user_blocks VALUES(4,1);
 INSERT INTO human_conversation_reads_v370 VALUES(10,1,3)");
$insert=$pdo->prepare("INSERT INTO human_messages VALUES(?,10,2,?,NULL,NULL,NULL,'2026-11-27 12:00:00')");
for($i=1;$i<=180;$i++)$insert->execute([$i,'Message '.$i]);
$inbox=vp3_human_inbox_v370($pdo,1);
check(array_map('intval',array_column($inbox['conversations'],'id'))===[10],'Normal direct conversations without a request row disappeared from the inbox');
check((int)$inbox['conversations'][0]['unread_count']===177,'Unread total lost canonical read state');
check(array_map('intval',array_column($inbox['requests'],'conversation_id'))===[20],'Incoming request was not isolated in Requests');
check(vp3_human_inbox_v370($pdo,2)['requests']===[],'Another member can see incoming requests');
check(array_map('intval',array_column(vp3_human_inbox_v370($pdo,3)['conversations'],'id'))===[20],'Requester lost the pending outgoing request');
$page=vp3_human_message_page_v370($pdo,10,1);
check(count($page['messages'])===120&&$page['messages'][0]['id']===61&&end($page['messages'])['id']===180,'Initial conversation did not show the latest messages');
check($page['has_older']&&!$page['has_more'],'Latest page did not expose older history correctly');
$older=vp3_human_message_page_v370($pdo,10,1,0,61);
check(count($older['messages'])===60&&$older['messages'][0]['id']===1&&end($older['messages'])['id']===60&&!$older['has_older'],'Older-page history skipped or duplicated messages');
$after=vp3_human_message_page_v370($pdo,10,1,10,0,20);
check(count($after['messages'])===20&&$after['messages'][0]['id']===11&&end($after['messages'])['id']===30&&$after['has_more'],'Catch-up pagination skipped new messages');
check(vp3_human_message_page_v370($pdo,10,1,180)['messages']===[],'Up-to-date polling repeated messages');
$pdo->exec("INSERT INTO human_conversations VALUES(40,'group','Empty',NULL,NULL,'2026-11-27 12:00:00');INSERT INTO human_conversation_members VALUES(40,1,NULL)");
check(vp3_human_mark_read_v370($pdo,40,1)===0,'Empty thread attempted to store an invalid message foreign key');
try{vp3_human_message_page_v370($pdo,10,3);throw new LogicException('Foreign conversation exposed');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Foreign conversation exposed');}
try{vp3_human_message_page_v370($pdo,30,1);throw new LogicException('Blocked conversation exposed');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Blocked conversation exposed');}
echo "PASS real SQL inbox/null joins, requests, block/account isolation, unread counts, latest messages, earlier history and bounded catch-up\n";
