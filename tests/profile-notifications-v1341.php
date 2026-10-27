<?php
declare(strict_types=1);
// Real persisted production services. The adapter only translates MySQL syntax
// so the same notification/attention/chat code can execute against SQLite.
final class ProfileTestPDO extends PDO {
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$this->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$this->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$this->sqliteCreateFunction('JSON_UNQUOTE',fn($s)=>$s);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $query=preg_replace("/DATE_SUB\((?:UTC_TIMESTAMP|NOW)\(\),INTERVAL (\d+) (DAY|HOUR|MINUTE)\)/i","datetime('now','-$1 $2')",$query);
        return parent::prepare($query,$options);
    }
}
$pdo=new ProfileTestPDO();
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return(bool)$s->fetchColumn();}
function system_agent_name():string{return 'Longfellow';}
function url(string $s):string{return $s;}
function current_user():array{return ['id'=>1];}
function personal_capability_has_v242(...$args):bool{return false;}
function artist_workspace_v181_scope_id(...$args):int{return 0;}
function vp3_cognitive_presentation_owns_attention_v510():bool{return true;}
$policyCalls=[];$delivered=[];
function vp3_cognitive_agent_namespace_v500(PDO $pdo,array $user,int $agent):string{return 'system';}
function vp3_cognitive_attention_notification_row_signal_v2410(array $user,array $row):array{return ['key'=>'notification:'.$row['id']];}
function vp3_cognitive_presentation_context_v500(PDO $pdo,array $user,array $options):array{return $options+['agent_voice_enabled'=>false];}
function vp3_cognitive_attention_arbitrate_v2410(PDO $pdo,array $user,string $namespace,array $signal,array $context):array{$GLOBALS['policyCalls'][]=$context;return ['voice'=>false];}
function vp3_cognitive_attention_mark_delivered_v2410(PDO $pdo,array $user,string $namespace,string $key):void{$GLOBALS['delivered'][]=$key;}

function vp3_profile_agent_public_conversation_v390(PDO $pdo,int $cid,int $owner,int $agent,int $session,bool $lock=false):?array{$s=$pdo->prepare('SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=?');$s->execute([$cid,$owner,$agent,$session]);return $s->fetch()?:null;}
function vp3_profile_agent_prepare_visitor_turn_v390(PDO $pdo,array $c):array{return $c;}
function vp3_profile_agent_agent_may_reply_v390(array $c):bool{return false;}
function vp3_profile_agent_public_state_v390(array $c):array{return $c;}
require dirname(__DIR__).'/includes/notifications.php';
require dirname(__DIR__).'/includes/profile-agent.php';
require dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
// Load the actual API service functions without executing its HTTP dispatcher.
$source=file_get_contents(dirname(__DIR__).'/api/chat-notifications-brain-v240.php');
$start=strpos($source,'function chat_notifications_v240_json');$end=strpos($source,"\n\$user = current_user();");
eval(substr($source,$start,$end-$start));
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,email TEXT,role TEXT,is_active INTEGER,avatar_path TEXT)");
$pdo->exec("INSERT INTO users VALUES(1,'Owner','owner@example.test','admin',1,''),(2,'Other','other@example.test','member',1,'')");
$pdo->exec("CREATE TABLE profile_visit_sessions(id INTEGER PRIMARY KEY,visitor_user_id INTEGER,identity_disclosed INTEGER,owner_user_id INTEGER,last_message_at TEXT,last_seen_at TEXT)");
$pdo->exec("INSERT INTO profile_visit_sessions VALUES(10,NULL,0,1,NULL,CURRENT_TIMESTAMP),(20,NULL,0,2,NULL,CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE profile_agent_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,profile_agent_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,status TEXT,last_summary TEXT,last_message_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO profile_agent_conversations VALUES(100,1,7,10,NULL,'owner_joined','',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE profile_agent_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,sender_type TEXT,sender_user_id INTEGER,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE profile_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,profile_session_id INTEGER,visitor_user_id INTEGER,profile_agent_id INTEGER,event_type TEXT,priority INTEGER,dedupe_key TEXT UNIQUE,metadata_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE agent_attention_items(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,source_event_id INTEGER UNIQUE,source_conversation_id INTEGER,profile_agent_id INTEGER,visitor_user_id INTEGER,attention_type TEXT,priority INTEGER,headline TEXT,summary TEXT,assessment TEXT,recommended_response TEXT,actions_json TEXT,context_json TEXT,status TEXT DEFAULT 'pending',snoozed_until TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,source_type TEXT,source_id INTEGER,is_read INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$checks=0;
function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function reject(callable $call,string $message):void{try{$call();}catch(Throwable $e){check(true,$message);return;}throw new RuntimeException($message);}
$user=['id'=>1];$profile=['user_id'=>1];$agent=['id'=>7,'display_name'=>'Profile helper'];$session=['id'=>10,'visitor_user_id'=>null,'identity_disclosed'=>0];
$visit=profile_event_create($pdo,1,$session,'profile_view',10,7,[],'visit-1');profile_attention_from_event($pdo,$visit,$session,$agent);
check((int)$pdo->query("SELECT count(*) FROM notifications WHERE type='profile_view'")->fetchColumn()===1,'Visit creates canonical owner notification');
$first=notification_profile_attention_after_v1341($user);
check(count($first['items'])===1&&$first['items'][0]['type']==='profile_view','Profile priority poll sees visits with cognitive ownership enabled');
$start=profile_event_create($pdo,1,$session,'conversation_started',60,7,[],'start-100');profile_attention_from_event($pdo,$start,$session,$agent,100);
$ctx=['profile'=>$profile,'owner_user_id'=>1,'owner_user'=>$user,'visitor'=>null,'agent'=>$agent,'agent_id'=>7,'session'=>$session,'session_id'=>10];
$result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Can we meet next week?',100);
check(!empty($result['awaiting_owner']),'Owner takeover remains intact');
$note=$pdo->query("SELECT * FROM notifications WHERE type='profile_message_received'")->fetch();
check(is_array($note)&&str_contains($note['body'],'Can we meet next week?'),'Actual visitor service records message notification before owner-takeover return');
check((int)$pdo->query("SELECT is_read FROM notifications WHERE type='profile_conversation_started'")->fetchColumn()===1,'Saved message supersedes empty start notification');
check((int)$pdo->query("SELECT count(*) FROM agent_attention_items WHERE attention_type='conversation_started' AND status='handled'")->fetchColumn()===1,'Superseded start attention is retired');
$messageId=(int)$pdo->query('SELECT MAX(id) FROM profile_agent_messages')->fetchColumn();
profile_agent_message_received_v1341($pdo,$profile,$agent,$session,['id'=>100,'owner_user_id'=>1,'profile_session_id'=>10],$messageId,'Can we meet next week?');
check((int)$pdo->query("SELECT count(*) FROM notifications WHERE type='profile_message_received'")->fetchColumn()===1,'Saved message identity deduplicates event retry');
$pdo->exec("UPDATE profile_agent_messages SET created_at=datetime('now','-10 seconds')");
vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Please call tomorrow.',100);
check((int)$pdo->query("SELECT count(*) FROM notifications WHERE type='profile_message_received'")->fetchColumn()===2,'Later messages in an existing thread also notify');
reject(fn()=>vp3_profile_agent_public_message_service_v110($pdo,[...$ctx,'session_id'=>20],'Other session',100),'Public session cannot notify from another conversation');
reject(fn()=>profile_agent_message_received_v1341($pdo,[...$profile,'user_id'=>2],$agent,$session,['id'=>100,'owner_user_id'=>1,'profile_session_id'=>10],900,'bad'),'Message ownership mismatch rejects');
$present=chat_notifications_v240_present_attention($pdo,$user,['notification_id'=>(int)$note['id']]);
check(!empty($present['handled'])&&empty($present['duplicate']),'Cognitive owner hands profile priority to the existing canonical writer');
check($present['voice_allowed']===false&&end($delivered)==='notification:'.$note['id'],'Voice policy can suppress speech without suppressing the priority turn');
check(end($policyCalls)['agent_voice_enabled']===false,'Handoff preserves the authoritative voice preference');
check(str_contains($present['message'],'Can we meet next week?'),'Main chat turn includes the actual visitor message');
$stored=$pdo->query('SELECT * FROM chat_messages')->fetch();$context=json_decode($stored['context_json'],true);
check($context['attention']['priority']==='high'&&$context['attention']['required']===true,'Persisted assistant turn is high-priority actionable attention');
check($context['actions'][0]['url']==='/profile-agent.php?tab=inbox&conversation=100','Turn links to its exact profile conversation');
$retry=chat_notifications_v240_present_attention($pdo,$user,['notification_id'=>(int)$note['id']]);
check(!empty($retry['duplicate'])&&(int)$pdo->query('SELECT count(*) FROM chat_messages')->fetchColumn()===1,'Lost response retry reuses the persisted turn');
reject(fn()=>chat_notifications_v240_present_attention($pdo,['id'=>2],['notification_id'=>(int)$note['id']]),'Another owner cannot present the notification');
create_notification(1,'approval_required','Unrelated approval','Review','/tasks.php','task',11);
$other=(int)$pdo->lastInsertId();$last=$pdo->query("SELECT id FROM notifications WHERE type='approval_required'")->fetchColumn();
$blocked=chat_notifications_v240_present_attention($pdo,$user,['notification_id'=>(int)$last]);
check(empty($blocked['handled'])&&$blocked['presentation_owner']==='cognitive_runtime_v510','Unrelated notification ownership remains with cognitive runtime');
create_notification(1,'agent_activity_radar_visit','Routine crawler','Crawler','/profile-agent.php','radar_event',55);
create_notification(1,'radar_agent_visit_needs_attention','AI search visitor','Known signature','/profile-agent.php?tab=radar','radar_event',56);
$batch=notification_profile_attention_after_v1341($user);
check(!in_array('agent_activity_radar_visit',array_column($batch['items'],'type'),true),'Routine crawler activity stays in Brain');
check(in_array('radar_agent_visit_needs_attention',array_column($batch['items'],'type'),true),'Actionable Radar visit reaches priority handoff');
$newNote=$pdo->query("SELECT id FROM notifications WHERE type='profile_message_received' ORDER BY id DESC LIMIT 1")->fetchColumn();
$pdo->exec("CREATE TRIGGER fail_attention_insert BEFORE INSERT ON chat_messages BEGIN SELECT RAISE(ABORT,'injected write failure'); END");
reject(fn()=>chat_notifications_v240_present_attention($pdo,$user,['notification_id'=>(int)$newNote]),'Failed canonical insert rejects');
check(!$pdo->inTransaction()&&(int)$pdo->query('SELECT count(*) FROM chat_messages')->fetchColumn()===1,'Failed presentation rolls back the complete write');
$pdo->exec('DROP TRIGGER fail_attention_insert');
$recovered=chat_notifications_v240_present_attention($pdo,$user,['notification_id'=>(int)$newNote]);
check(!empty($recovered['handled'])&&(int)$pdo->query('SELECT count(*) FROM chat_messages')->fetchColumn()===2,'A failed canonical write can be retried safely');
$privateSession=[...$session,'visitor_user_id'=>2];
$privateEvent=profile_event_create($pdo,1,$privateSession,'profile_view',10,7,[],'private-visit');
profile_attention_from_event($pdo,$privateEvent,$privateSession,$agent);
$privateNote=$pdo->query("SELECT title,body FROM notifications WHERE source_id=".(int)$privateEvent['id']." AND source_type='profile_event'")->fetch();
check(str_contains($privateNote['title'],'Signed-in member')&&!str_contains(json_encode($privateNote),'other@example.test'),'A non-disclosed signed-in visit never exposes identity');
check(profile_event_create($pdo,1,[...$session,'visitor_user_id'=>1],'profile_view',10,7)===null,'Owner self-visits remain excluded');
$pdo->prepare('DELETE FROM notifications WHERE id=?')->execute([(int)$note['id']]);
profile_agent_reconcile_notifications_v1341($pdo,$user);profile_agent_reconcile_notifications_v1341($pdo,$user);
check((int)$pdo->query("SELECT count(*) FROM notifications WHERE source_id=".(int)$note['source_id']." AND source_type='profile_event'")->fetchColumn()===1,'Existing attention ledger repairs a lost notification exactly once');
$visitNote=$pdo->query("SELECT id FROM notifications WHERE type='profile_view' ORDER BY id LIMIT 1")->fetchColumn();
$visitSource=$pdo->query('SELECT source_id FROM notifications WHERE id='.(int)$visitNote)->fetchColumn();
$pdo->exec("UPDATE agent_attention_items SET status='handled' WHERE source_event_id=".(int)$visitSource);
check(!in_array((int)$visitNote,array_column(notification_profile_attention_after_v1341($user)['items'],'id'),true),'A resolved/handled profile item does not return as a new priority');
$pdo->exec("UPDATE agent_attention_items SET status='ignored' WHERE source_event_id=".(int)$visitSource);
check(!in_array((int)$visitNote,array_column(notification_profile_attention_after_v1341($user)['items'],'id'),true),'Ignored attention stays ignored');
$pdo->exec("UPDATE agent_attention_items SET status='snoozed',snoozed_until=datetime('now','+1 day') WHERE source_event_id=".(int)$visitSource);
$scan=notification_profile_attention_after_v1341($user);$cursor=$scan['latest_id'];
check(!in_array((int)$visitNote,array_column($scan['items'],'id'),true),'Active snooze defers the priority');
$pdo->exec("UPDATE agent_attention_items SET snoozed_until=datetime('now','-1 minute') WHERE source_event_id=".(int)$visitSource);
$wake=notification_profile_attention_after_v1341($user,$cursor);
check(in_array((int)$visitNote,array_column($wake['items'],'id'),true)&&$wake['latest_id']>=$cursor,'Expired snooze wakes even after the scan cursor has advanced');
// More than one response page must never skip IDs because of a global MAX(id).
$pdo->exec('DELETE FROM notifications');
for($i=0;$i<65;$i++)create_notification(1,'profile_message_received','Message '.$i,'new','/profile-agent.php','profile_event',1000+$i);
create_notification(2,'profile_message_received','Other owner','private','/profile-agent.php','profile_event',2000);
$ids=[];$cursor=0;
for($i=0;$i<4;$i++){$batch=notification_profile_attention_after_v1341($user,$cursor);$cursor=$batch['latest_id'];$ids=array_merge($ids,array_column($batch['items'],'id'));}
check(count($ids)===65&&count(array_unique($ids))===65,'Paged backlog delivers all 65 owner items once without skipping');
check(!in_array('Other owner',array_column(notification_profile_attention_after_v1341($user)['items'],'title'),true),'Priority queue stays owner scoped');
$pdo->exec("UPDATE notifications SET is_read=1");
check(notification_profile_attention_after_v1341($user)['items']===[],'Read priorities do not replay on reload');
echo 'PROFILE_NOTIFICATIONS_V1341='.$checks." assertions PASS\n";
