<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-task-lists-v1.php';
require dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require dirname(__DIR__).'/includes/agent-brain-v122.php';
require dirname(__DIR__).'/includes/participant-updates-v1.php';
$dsn=(string)getenv('VP3_SECTION7_MYSQL_DSN');if(!$dsn)throw new RuntimeException('Explicit task-list fixture DSN required.');
$pdo=new PDO($dsn,'root',(string)getenv('VP3_SECTION7_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mode=$argv[1]??'';$name=(string)getenv('VP3_TASK_LIST_FIXTURE_DATABASE');
if($mode===''){$name='tasklists_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE `'.$name.'`');putenv('VP3_TASK_LIST_FIXTURE_DATABASE='.$name);}
if(!preg_match('/^tasklists_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Invalid fixture database.');$pdo->exec('USE `'.$name.'`');
function db(){return $GLOBALS['pdo'];}function table_exists($t){$q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$t]);return(bool)$q->fetchColumn();}function column_exists($t,$c){$q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$t,$c]);return(bool)$q->fetchColumn();}
function has_permission(...$a){return true;}function personal_capability_has_v242(...$a){return true;}function knowledge_retrieval_v162_owner_session($u,$p){return $u['id']===($p['owner_user_id']??0);}function agent_brain_schema_ready(){return true;}function agent_brain_normalize($s){return mb_strtolower(trim($s));}function ck($ok,$why){if(!$ok)throw new RuntimeException($why);}
$u=['id'=>1,'is_active'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$ctx=['conversation_id'=>1];
if($mode==='presence'){vp3_participant_observed_v1($pdo,$u,8,'heard');echo '{}';exit;}
if($mode==='offer'){echo json_encode(vp3_participant_offer_v1($pdo,$u,8));exit;}
if($mode==='shared-add'){$r=vp3_participant_with_scope_v1($pdo,$u,$p,8,true,fn()=>vp3_task_list_execute_v1($pdo,$u,$p,['action'=>'add','list_name'=>'Prep','title'=>'Shared flour'],$ctx));echo json_encode($r);exit;}
if($mode!==''){$r=vp3_task_list_execute_v1($pdo,$u,$p,$mode==='confirm'?['action'=>'confirm']:['action'=>'add','list_name'=>'Prep','title'=>'Sauce'],$ctx);if($r['tool_receipt']['status']==='failed'){fwrite(STDERR,json_encode($r));exit(1);}echo json_encode($r);exit;}
function workers($mode){$all=[];for($i=0;$i<4;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$all[]=[$proc,$pipes];}$results=[];foreach($all as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ck(proc_close($proc)===0&&$err==='','Concurrent task worker failed: '.$err);$results[]=json_decode($out,true,32,JSON_THROW_ON_ERROR);}return $results;}
try{
 $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT) ENGINE=InnoDB;INSERT INTO users VALUES(1,1);CREATE TABLE chat_conversations(id INT PRIMARY KEY,user_id INT,user_agent_id INT);INSERT INTO chat_conversations VALUES(1,1,7);CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id INT,role VARCHAR(20),context_json LONGTEXT);CREATE TABLE agent_chat_archive(id BIGINT PRIMARY KEY,user_agent_id INT,INDEX idx_agent_archive_agent_created_v410(user_agent_id));CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id INT NULL,memory_type VARCHAR(40),subject VARCHAR(190),memory_text LONGTEXT,memory_hash CHAR(40),memory_scope_version INT,source_archive_id BIGINT NULL,confidence FLOAT,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active TINYINT,metadata_json LONGTEXT,UNIQUE KEY uq_task_hash(user_id,memory_hash),INDEX idx_agent_memory_agent_type_v410(user_agent_id,memory_type),INDEX idx_agent_memory_agent_occurrence_v410(user_agent_id,occurrence_count)) ENGINE=InnoDB");
 vp3_task_lists_schema_v1($pdo);vp3_task_lists_schema_v1($pdo);ck(vp3_task_lists_ready_v1($pdo),'MySQL schema ready.');
 $r=vp3_task_list_execute_v1($pdo,$u,$p,['action'=>'add','list_name'=>'Prep','title'=>'Pizza dough'],$ctx);$q=$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,context_json) VALUES(1,'assistant',?)");$q->execute([json_encode($r)]);
 $results=workers('confirm');ck(count(array_filter($results,fn($r)=>$r['tool_receipt']['changes_applied']))===1,'One of four concurrent confirmations changes records.');
 ck((int)$pdo->query('SELECT COUNT(*) FROM agent_task_lists_v1')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM agent_task_list_items_v1')->fetchColumn()===1,'Concurrent confirms save one list, task and reference.');
 $results=workers('add');ck(count(array_filter($results,fn($r)=>$r['tool_receipt']['changes_applied']))===1&&(int)$pdo->query('SELECT COUNT(*) FROM agent_memory_items')->fetchColumn()===2,'Four concurrent same-item adds save one canonical task.');
 $pdo->exec("CREATE TABLE studio_participants(id BIGINT PRIMARY KEY,owner_user_id INT UNSIGNED,display_name VARCHAR(120),is_active TINYINT,recognition_consent TINYINT); INSERT INTO studio_participants VALUES(7,1,'David',1,1),(8,1,'Alex',1,1)");
 vp3_participant_updates_schema_v1($pdo);vp3_participant_updates_schema_v1($pdo);ck(vp3_participant_updates_ready_v1($pdo),'Participant MySQL schema is ready and idempotent.');
 $pdo->exec('INSERT INTO agent_participant_settings_v1(owner_user_id,enabled,self_participant_id,offer_updates) VALUES(1,1,7,1)');
 $list=(int)$pdo->query('SELECT id FROM agent_task_lists_v1 LIMIT 1')->fetchColumn();$pdo->exec("INSERT INTO agent_participant_grants_v1(owner_user_id,participant_id,agent_scope_id,resource_kind,resource_id,access_mode) VALUES(1,8,7,'task_list',$list,'edit')");
 $results=workers('shared-add');ck(count(array_filter($results,fn($r)=>$r['tool_receipt']['changes_applied']))===1,'Four concurrent shared adds save one canonical task.');
 workers('presence');ck((int)$pdo->query('SELECT COUNT(*) FROM agent_participant_presence_v1')->fetchColumn()===1,'Four simultaneous first voice events create one presence row.');
 $results=workers('offer');ck(count(array_filter($results))===1,'Only one tab claims an update offer for the visit.');
 $offer=array_values(array_filter($results))[0];vp3_participant_offer_v1($pdo,$u,8,$offer);ck(vp3_participant_offer_v1($pdo,$u,8)===null,'Acknowledged offer is not repeated.');
 $pdo->exec('DELETE FROM agent_participant_grants_v1');$r=vp3_participant_with_scope_v1($pdo,$u,$p,8,true,fn()=>vp3_task_list_execute_v1($pdo,$u,$p,['action'=>'add','list_name'=>'Prep','title'=>'Revoked flour'],$ctx));ck($r['tool_receipt']['status']==='blocked','Revoked shared write is blocked on MySQL.');
 $pdo->exec('DELETE FROM users WHERE id=1');foreach(['agent_task_lists_v1','agent_task_list_items_v1','agent_task_list_actions_v1','agent_participant_settings_v1','agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1'] as $table)ck((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'Owner deletion cleans '.$table);
 echo "TASK_LISTS_MYSQL=PASS (real schema; concurrent confirms and owner/shared adds, voice presence, cross-tab offers, grant revocation and owner cleanup)\n";
}finally{$pdo->exec('DROP DATABASE `'.$name.'`');}
