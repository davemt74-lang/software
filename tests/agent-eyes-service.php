<?php
declare(strict_types=1);
// Actual participant lookup, event ingress/dedupe, and Agent Eyes service over SQLite.
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $name):bool{$q=db()->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();}
function column_exists(string $table,string $column):bool{foreach(db()->query('PRAGMA table_info('.$table.')') as $row)if($row['name']===$column)return true;return false;}
$memories=[];
function agent_brain_v122_upsert_system_memory($user,$type,$subject,$text,$metadata,$confidence):void{$GLOBALS['memories'][$user['id'].':'.$subject]=compact('type','text','metadata','confidence');}
$pdo->exec("CREATE TABLE studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER,recognition_consent INTEGER);INSERT INTO studio_participants VALUES(7,1,'David',1,1),(8,2,'Other owner',1,1),(9,1,'Revoked',1,0),(10,1,'Inactive',0,1)");
$pdo->exec("CREATE TABLE agent_event_inbox(id INTEGER PRIMARY KEY AUTOINCREMENT,event_uuid TEXT UNIQUE,owner_user_id INTEGER,source TEXT,event_type TEXT,schema_version INTEGER DEFAULT 1,external_event_id TEXT,dedupe_hash TEXT,verification_status TEXT,verification_key_id TEXT,processing_status TEXT DEFAULT 'accepted',occurred_at TEXT,received_at TEXT DEFAULT CURRENT_TIMESTAMP,processing_started_at TEXT,processed_at TEXT,replay_count INTEGER DEFAULT 0,correlation_id TEXT,causation_id TEXT,payload_json TEXT,linked_run_id INTEGER,last_error_code TEXT DEFAULT '',last_error_message TEXT DEFAULT '',UNIQUE(owner_user_id,dedupe_hash))");
require dirname(__DIR__).'/includes/agent-event-infrastructure-v1920.php';
require dirname(__DIR__).'/includes/agent-eyes.php';
$checks=0;function check(bool $ok,string $name):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
function rejects(callable $fn,string $name):void{try{$fn();}catch(RuntimeException $e){check(true,$name);return;}throw new LogicException($name);}
$event=['event_id'=>'11111111-1111-4111-8111-111111111111','session_id'=>'22222222-2222-4222-8222-222222222222','track_id'=>'33333333-3333-4333-8333-333333333333','event_type'=>'arrival','participant_id'=>'7','display_name'=>'Spoofed admin','identity_source'=>'verified','authentication_authority'=>false];$user=['id'=>1];
$result=vp3_eyes_record($pdo,$user,$event);check($result['event']['display_name']==='David','name resolved from existing owned participant');
check($result['event']['identity_source']==='browser_reported_visual_similarity'&&!$result['event']['authentication_authority'],'visual match cannot become authentication');
$duplicate=vp3_eyes_record($pdo,$user,$event);check($duplicate['duplicate']&&$duplicate['stored_id']===$result['stored_id'],'lost response retry writes one canonical event');
check(count($memories)===1,'retry updates one existing Brain memory');
check(str_contains(array_values($memories)[0]['text'],'Provisional'),'Brain summary preserves provisional evidence');
check($pdo->query('SELECT processing_status FROM agent_event_inbox')->fetchColumn()==='processed','presence cannot accidentally trigger a generic event action');
rejects(fn()=>vp3_eyes_record($pdo,$user,array_replace($event,['participant_id'=>8])),'foreign owner participant rejected');
foreach([9,10] as $id){$e=vp3_eyes_event($pdo,$user,array_replace($event,['participant_id'=>$id]));check($e['participant_id']===0&&$e['display_name']==='Unidentified participant','revoked/inactive profile becomes unknown');}
foreach(['embedding','photo','audio','frame','token'] as $field)rejects(fn()=>vp3_eyes_event($pdo,$user,$event+[$field=>'not allowed']),'rejects unsupported '.$field);
rejects(fn()=>vp3_eyes_event($pdo,$user,array_replace($event,['authentication_authority'=>true])),'identity authority escalation rejected');
rejects(fn()=>vp3_eyes_event($pdo,$user,array_replace($event,['event_type'=>'execute_action'])),'arbitrary event action rejected');
rejects(fn()=>vp3_eyes_event($pdo,$user,array_replace($event,['track_id'=>'<script>'])),'invalid track reference rejected');
$unknown=vp3_eyes_event($pdo,$user,array_replace($event,['participant_id'=>'','display_name'=>'Pretend owner']));check($unknown['display_name']==='Unidentified participant','unidentified label cannot become a named identity');
check((int)$pdo->query('SELECT COUNT(*) FROM agent_event_inbox')->fetchColumn()===1,'rejected payloads do not write events');
$other=vp3_eyes_record($pdo,['id'=>2],array_replace($event,['participant_id'=>8]));
check(count(vp3_eyes_recent($pdo,$user))===1,'Brain timeline stays owner scoped');
check(vp3_eyes_recent($pdo,$user)[0]['payload']['display_name']==='David','timeline uses canonical event payload');
echo "AGENT_EYES_SERVICE=PASS ($checks cases)\n";
