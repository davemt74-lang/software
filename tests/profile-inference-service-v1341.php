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
function setting(string $key,mixed $fallback=''):mixed{return $fallback;}
function column_exists(string $t,string $c):bool{return false;}
function chat_policy_terms_v236(string $q):array{return array_values(array_filter(preg_split('/[^a-z]+/',strtolower($q)),fn($x)=>strlen($x)>3));}
function url(string $s):string{return $s;}
function current_user():array{return ['id'=>1];}
function personal_capability_has_v242(...$args):bool{return true;}
function artist_workspace_v181_scope_id(...$args):int{return 0;}
function vp3_cognitive_presentation_owns_attention_v510():bool{return true;}
function vp3_profile_agent_public_conversation_v390(PDO $pdo,int $cid,int $owner,int $agent,int $session,bool $lock=false):?array{$s=$pdo->prepare('SELECT * FROM profile_agent_conversations WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=?');$s->execute([$cid,$owner,$agent,$session]);return $s->fetch()?:null;}
function vp3_profile_agent_prepare_visitor_turn_v390(PDO $pdo,array $c):array{return $c;}
function vp3_profile_agent_agent_may_reply_v390(array $c):bool{return $c['status']==='open';}
function vp3_profile_agent_public_state_v390(array $c):array{return $c;}
require dirname(__DIR__).'/includes/notifications.php';
require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/shared-knowledge-index-v236.php';
require dirname(__DIR__).'/includes/profile-agent.php';
require dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
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

$pdo->exec("UPDATE profile_agent_conversations SET status='open'");
$pdo->exec("CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER)");
$pdo->exec("INSERT INTO user_agents VALUES(7,1,'Public Guide',1)");
$pdo->exec("CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,title TEXT,description TEXT,content_text TEXT,visibility TEXT,is_published INTEGER,created_by_user_id INTEGER,updated_at TEXT)");
$pdo->exec("INSERT INTO knowledge_items VALUES(1,'Public hours','','Public hours are Monday','public',1,1,CURRENT_TIMESTAMP),(2,'Private hours','','Secret private hours','private',0,1,CURRENT_TIMESTAMP),(3,'Other owner hours','','Other owner private hours','public',1,2,CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE knowledge_chunks(id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT)");
$pdo->exec("INSERT INTO knowledge_chunks VALUES(1,1,0,'Public hours are Monday'),(2,2,0,'Secret private hours'),(3,3,0,'Other owner hours')");
$pdo->exec("CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER)");
$pdo->exec("INSERT INTO user_data_policies VALUES(1,'knowledge','*','inherit',1,1,0),(1,'homeserver_knowledge','*','public',1,1,0)");
$pdo->exec("CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT)");
$pdo->exec("CREATE TABLE user_relationships(owner_user_id INTEGER,related_user_id INTEGER,relationship_scope TEXT,status TEXT)");
$cloud=[];$home=[];$homeReady=false;$quotaClosed=false;$checks=0;
function homeserver_shared_v210_exchange(int $owner,string $query):array{return ['datasets'=>['knowledge'=>[['key'=>'public','title'=>'Approved Home hours','content'=>'Approved Home hours are Tuesday']],'contacts'=>[['key'=>'secret','title'=>'Private contacts','content'=>'Secret contacts hours']]]];}
function homeserver_profile_v235_answer(int $owner,string $query,array $history,array $context):?array{$GLOBALS['home'][]=compact('owner','query','history','context');return $GLOBALS['homeReady']?['answer'=>'HomeServer model answer']:null;}
function homeserver_profile_v235_last():array{return ['attempted'=>$GLOBALS['homeReady'],'success'=>$GLOBALS['homeReady']];}
function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $scope=null,?array $control=null):array{$GLOBALS['cloud'][]=compact('query','history','context','user','scope','control');return $GLOBALS['quotaClosed']?['ok'=>false,'quota_exhausted'=>true]:['ok'=>true,'answer'=>'Cloud model answer'];}
function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function turn(string $query):array{
    $pdo=db();$pdo->exec("UPDATE profile_agent_messages SET created_at=datetime('now','-5 minutes')");
    $ctx=['profile'=>['user_id'=>1,'display_name'=>'Owner','profile_agent_greeting'=>'','profile_agent_instructions'=>'Welcome visitors'],'owner_user_id'=>1,'owner_user'=>['id'=>1,'display_name'=>'Owner'],'visitor'=>null,'agent'=>['id'=>7,'owner_user_id'=>1,'display_name'=>'Public Guide'],'agent_id'=>7,'session'=>['id'=>10,'visitor_user_id'=>null,'identity_disclosed'=>0],'session_id'=>10];
    return vp3_profile_agent_public_message_service_v110($pdo,$ctx,$query,100);
}
$result=turn('Can we chat?');
check($result['answer']==='Cloud model answer','Conversation with no matching knowledge invokes the model');
check(count($cloud[0]['context'])===2,'Identity/rules context is sufficient for natural conversation, without private retrieval');
check($cloud[0]['user']['id']===1&&$cloud[0]['scope']==='profile_agent','Anonymous visitor compute uses owner identity and explicit scope');
check($cloud[0]['control']['profile_agent_id']===7&&$cloud[0]['control']['profile_owner_user_id']===1,'Selected public representative remains owner bound');
turn('What are the hours?');$sent=end($cloud)['context'];$sources=array_column($sent,'source');
check(in_array('knowledge:1',$sources,true),'Real policy/retrieval supplies approved public knowledge');
check(!in_array('knowledge:2',$sources,true)&&!in_array('knowledge:3',$sources,true),'Private and other-owner knowledge excluded');
check(in_array('homeserver:knowledge:public',$sources,true),'Explicitly enabled HomeServer knowledge is supplied');
check(!str_contains(json_encode($sent),'Secret contacts'),'HomeServer contacts are denied unless separately enabled');
$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','1','deny')");
turn('hours');check(!in_array('knowledge:1',array_column(end($cloud)['context'],'source'),true),'Explicit per-Agent deny overrides public item');
$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='homeserver_knowledge'");
turn('hours');check(!in_array('homeserver:knowledge:public',array_column(end($cloud)['context'],'source'),true),'Revoking Profile Agent sharing immediately removes HomeServer context');
$homeReady=true;$before=count($cloud);$local=turn('Can we talk?');
check($local['answer']==='HomeServer model answer'&&count($cloud)===$before,'Existing HomeServer route can converse without a knowledge match and avoids Cloud billing');
check(end($home)['owner']===1&&$local['answer_mode']==='homeserver_model','Local compute remains owner-bound and records its route');
$homeReady=false;$quotaClosed=true;$limited=turn('One more question');
check($limited['answer_mode']==='quota_handoff'&&str_contains($limited['answer'],'usage allowance'),'Exhausted owner allowance produces an explicit handoff, not a simulated model answer');
check((int)$pdo->query("SELECT count(*) FROM profile_events WHERE event_type='needs_owner'")->fetchColumn()===1,'Quota handoff actually notifies the owner');
echo 'PROFILE_INFERENCE_SERVICE_V1341='.$checks." assertions PASS (inference transports mocked)\n";
