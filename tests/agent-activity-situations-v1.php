<?php
declare(strict_types=1);

// The projection reads the same canonical tables as Activity Center. These
// primitives supply the disposable database and signed-in fixture identity.
function db(): PDO{return $GLOBALS['situation_pdo'];}
function table_exists(string $table): bool
{
    $query=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return $query->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
}
function url(string $path=''): string{return '/vp3'.$path;}
function has_permission(string $permission,array $user): bool{return !in_array($permission,$user['denied']??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function user_agent_get_v236(PDO $pdo,int $owner,int $id): ?array
{
    return $owner===1&&$id===7?['id'=>7,'is_active'=>1]:null;
}
function user_agent_principal_v236(array $user,?array $agent): array
{
    return ['kind'=>$agent?'user_agent':'system','owner_user_id'=>$user['id'],'viewer_user_id'=>$user['id'],'agent_id'=>$agent['id']??0];
}
function notification_system_sql_predicate(string $alias=''): string{return '1=1';}
function notification_requires_attention(array $row): bool{return ($row['type']??'')==='action_required';}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user): string{return 'UTC';}
function ai_generate_chat_response(): never{throw new RuntimeException('Polling a situation must not invoke inference.');}

$root=dirname(__DIR__);
require_once $root.'/includes/cognitive-runtime-v500.php';
require_once $root.'/includes/cognitive-feed-v530.php';
require_once $root.'/includes/agent-chat-boundary-v380.php';
require_once $root.'/includes/agent-capability-chat-v1.php';
require_once $root.'/includes/agent-memory-scope-v410.php';
require_once $root.'/includes/agent-reminders-v1.php';
require_once $root.'/includes/knowledge-retrieval-v162.php';
require_once $root.'/includes/agent-initiative-v1.php';
require_once $root.'/includes/agent-activity-situations-v1.php';

$pdo=$GLOBALS['situation_pdo']=new PDO('sqlite::memory:',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES(1,1),(2,1),(3,0);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title TEXT,updated_at TEXT);
    INSERT INTO chat_conversations VALUES(42,1,NULL,'Client follow-up','2026-11-05 05:01:00'),
        (43,2,NULL,'Foreign follow-up','2026-11-05 05:01:00'),
        (44,1,NULL,'Client follow-up','2026-11-05 05:01:00'),
        (47,1,7,'Named Agent follow-up','2026-11-05 05:01:00');
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT);
    CREATE TABLE agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,conversation_id INTEGER,
        source_message_id INTEGER,role TEXT,input_mode TEXT,message_text TEXT,created_at TEXT,archived_at TEXT);
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,
        subject TEXT,memory_text TEXT,is_active INTEGER,metadata_json TEXT,source_archive_id INTEGER,last_seen_at TEXT,
        first_seen_at TEXT,occurrence_count INTEGER,memory_scope_version INTEGER);
    CREATE TABLE notifications(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,title TEXT,body TEXT,target_url TEXT,
        source_type TEXT,source_id INTEGER,is_read INTEGER,created_at TEXT);
    CREATE TABLE agent_event_inbox(id INTEGER PRIMARY KEY,owner_user_id INTEGER,source TEXT,event_type TEXT,
        verification_status TEXT,processing_status TEXT,payload_json TEXT,occurred_at TEXT,received_at TEXT,
        correlation_id TEXT,event_uuid TEXT)");
$pdo->exec("CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
    CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
    CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
        FROM main.sqlite_master WHERE type='index'");
$now=new DateTimeImmutable('2026-11-05T05:10:00Z');
$stamp='2026-11-05 05:01:00';
$user=['id'=>1,'is_active'=>1];
$message=$pdo->prepare('INSERT INTO chat_messages VALUES(?,?,1,\'user\',?,?,?)');
$archive=$pdo->prepare('INSERT INTO agent_chat_archive VALUES(?,1,?,?,?,\'user\',\'text\',?,?,?)');
foreach([[101,42,null],[102,44,null],[107,47,7]] as [$id,$conversation,$agent]){
    $message->execute([$id,$conversation,'PRIVATE_HISTORY_MESSAGE_'.$id,'{}',$stamp]);
    $archive->execute([$id,$agent,$conversation,$id,'PRIVATE_ARCHIVED_TEXT_'.$id,$stamp,$stamp]);
}
$memory=$pdo->prepare('INSERT INTO agent_memory_items VALUES(?,1,?,\'task\',?,\'PRIVATE_MEMORY_TEXT\',1,?,?,?, ?,1,410)');
foreach([[201,null,42,101],[207,7,47,107]] as [$id,$agent,$conversation,$archiveId]){
    $memory->execute([$id,$agent,'Send the agreed quote',json_encode(['conversation_id'=>$conversation,'task_status'=>'open'],JSON_THROW_ON_ERROR),$archiveId,$stamp,$stamp]);
}
$notification=$pdo->prepare('INSERT INTO notifications VALUES(?,1,\'action_required\',?,\'PRIVATE_NOTIFICATION_DETAIL\',?,\'brain_memory\',?,0,?)');
$notification->execute([301,'Follow-up needs review','/chat.php?agent=system&conversation_id=42',201,$stamp]);
$session='123e4567-e89b-42d3-a456-426614174000';
$eyes=$pdo->prepare('INSERT INTO agent_event_inbox VALUES(?,1,\'agent_eyes\',\'agent_eyes.arrival\',\'trusted\',\'processed\',?,?,?,?,?)');
$eyes->execute([401,json_encode(['session_id'=>$session,'conversation_id'=>42,'agent_namespace'=>'system','event_type'=>'arrival',
    'summary'=>'PRIVATE_CAMERA_SUMMARY','image_data'=>'PRIVATE_FRAME','transcript'=>'PRIVATE_LIVE_TRANSCRIPT',
    'authentication_authority'=>false],JSON_THROW_ON_ERROR),$stamp,$stamp,$session,'fixture-eyes-event']);

$checks=0;
function situation_check(bool $ok,string $name): void
{
    if(!$ok)throw new RuntimeException($name);
    $GLOBALS['checks']++;
    echo 'PASS '.$name."\n";
}
function situation_with_source(array $stream,string $kind): ?array
{
    foreach($stream['items'] as $item)if(($item['source_counts'][$kind]??0)>0)return $item;
    return null;
}
function situation_json(array $stream): string{return json_encode($stream,JSON_THROW_ON_ERROR);}

$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$pdo->exec('PRAGMA query_only=ON');
$stream=vp3_activity_situations_v1($pdo,$user,'system',$now);
$linked=situation_with_source($stream,'notifications');
situation_check($stream['complete']&&$stream['agent_namespace']==='system'&&$linked!==null,
    'the owner receives a bounded verified situation stream');
situation_check($linked['source_counts']==['notifications'=>1,'brain'=>1,'history'=>1,'eyes'=>0],
    'an exact task notification, Brain record and source archive form one developing situation');
situation_check(count($stream['items'])===3&&situation_with_source($stream,'eyes')['source_counts']['brain']===0,
    'unrelated same-title History and an advisory Eyes session remain distinct situations');
situation_check(situation_with_source($stream,'eyes')['sources'][0]['confirmation']==='browser_reported'
    &&!situation_with_source($stream,'eyes')['private_preparation_allowed'],
    'passive Eyes context retains its reported provenance and grants no preparation authority');
situation_check(!str_contains(situation_json($stream),'PRIVATE_HISTORY_MESSAGE')
    &&!str_contains(situation_json($stream),'PRIVATE_ARCHIVED_TEXT')
    &&!str_contains(situation_json($stream),'PRIVATE_MEMORY_TEXT')
    &&!str_contains(situation_json($stream),'PRIVATE_CAMERA_SUMMARY')
    &&!str_contains(situation_json($stream),'PRIVATE_NOTIFICATION_DETAIL')
    &&!str_contains(situation_json($stream),'PRIVATE_FRAME')
    &&!str_contains(situation_json($stream),'PRIVATE_LIVE_TRANSCRIPT'),
    'presentation excludes raw archives, Brain text, camera frames and live transcription');
situation_check(preg_match('/^[a-f0-9]{64}$/D',$linked['revision'])===1
    &&str_contains($linked['source_url'],'conversation_id=42'),
    'the situation has a stable opaque revision and an exact owned source action');
$later=vp3_activity_situations_v1($pdo,$user,'system',$now->modify('+1 minute'));
situation_check(array_column($stream['items'],'revision','id')===array_column($later['items'],'revision','id'),
    'polling an unchanged situation does not invent a new revision');
situation_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,
    'projection polling is read only and does not create Chat, memory, jobs or model work');
$named=vp3_activity_situations_v1($pdo,$user,'agent:7',$now);
situation_check(count($named['items'])===1&&($named['items'][0]['source_counts']['eyes']??0)===0
    &&($named['items'][0]['source_counts']['history']??0)===1,
    'a named Agent receives only its exact history and scoped Brain records');
$foreign=vp3_activity_situations_v1($pdo,['id'=>2,'is_active'=>1],'system',$now);
situation_check(!str_contains(situation_json($foreign),'Send the agreed quote')&&$foreign['items']===[],
    'another account cannot receive the first owner’s situations');
$pdo->exec("PRAGMA query_only=OFF; UPDATE agent_memory_items SET is_active=0 WHERE id=201; PRAGMA query_only=ON");
$restricted=vp3_activity_situations_v1($pdo,$user,'system',$now);
situation_check(situation_with_source($restricted,'notifications')===null
    &&!str_contains(situation_json($restricted),'Send the agreed quote'),
    'a revoked task record withdraws its linked notification and task evidence');
$pdo->exec("PRAGMA query_only=OFF; UPDATE agent_memory_items SET is_active=1 WHERE id=201; PRAGMA query_only=ON");
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>9];
$participantDenied=false;
try{vp3_activity_situations_v1($pdo,$user,'system',$now);}
catch(RuntimeException $error){$participantDenied=$error->getCode()===403;}
situation_check($participantDenied,'participant scope cannot inherit the owner’s activity situations');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec('PRAGMA query_only=OFF');
$pdo->exec('SAVEPOINT revise_source');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','in_progress') WHERE id=201");
$changed=vp3_activity_situations_v1($pdo,$user,'system',$now);
$changedLinked=situation_with_source($changed,'notifications');
situation_check($changedLinked!==null&&$changedLinked['id']===$linked['id']&&$changedLinked['revision']!==$linked['revision'],
    'a current source change advances the existing situation instead of creating a duplicate');
$pdo->exec('ROLLBACK TO revise_source; RELEASE revise_source');
$pdo->exec('SAVEPOINT unrelated_tasks');
foreach([208,209] as $id)$memory->execute([$id,null,'Send the agreed quote',json_encode(['conversation_id'=>42,'task_status'=>'open'],JSON_THROW_ON_ERROR),0,$stamp,$stamp]);
$independent=vp3_activity_situations_v1($pdo,$user,'system',$now);
situation_check(count($independent['items'])===5
    &&count(array_filter($independent['items'],static fn(array $item):bool=>$item['object_ref']['type']==='brain_task'))===3,
    'same-title tasks in the same Chat stay isolated unless a canonical source link connects them');
$pdo->exec('ROLLBACK TO unrelated_tasks; RELEASE unrelated_tasks');
$pdo->exec('SAVEPOINT completed_task');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','completed') WHERE id=201");
$completed=situation_with_source(vp3_activity_situations_v1($pdo,$user,'system',$now),'notifications');
situation_check($completed!==null&&$completed['state']==='resolved'&&!$completed['private_preparation_allowed'],
    'a completed canonical task overrides its older unread attention notification');
$pdo->exec('ROLLBACK TO completed_task; RELEASE completed_task');
$pdo->exec('SAVEPOINT advisory_decay');
$old='2000-01-01 00:00:00';
$message->execute([103,44,'ANCIENT_HISTORY_MESSAGE','{}',$old]);
$archive->execute([103,null,44,103,'ANCIENT_HISTORY_ARCHIVE',$old,$old]);
$memory->execute([203,null,'Old unresolved task',json_encode(['conversation_id'=>42,'task_status'=>'open'],JSON_THROW_ON_ERROR),0,$old,$old]);
$notification->execute([302,'ANCIENT_READ_NOTIFICATION','/chat.php?agent=system&conversation_id=42',201,$old]);
$pdo->exec('UPDATE notifications SET is_read=1 WHERE id=302');
$eyes->execute([402,json_encode(['session_id'=>$session,'conversation_id'=>42,'agent_namespace'=>'system','event_type'=>'arrival'],JSON_THROW_ON_ERROR),$old,$old,$session,'old-eyes-event']);
$decayed=vp3_activity_situations_v1($pdo,$user,'system',$now);
$decayedOriginal=situation_with_source($decayed,'notifications');
situation_check($decayedOriginal['revision']===$linked['revision']
    &&situation_with_source($decayed,'eyes')['source_counts']['eyes']===1
    &&count($decayed['items'])===4&&!str_contains(situation_json($decayed),'ANCIENT_'),
    'old History, Eyes and read notification events decay while an old unresolved task stays active');
$pdo->exec('ROLLBACK TO advisory_decay; RELEASE advisory_decay');
$pdo->exec('SAVEPOINT self_feedback');
$message->execute([104,42,'GENERATED_PRIVATE_DRAFT',json_encode(['source'=>'agent_initiative'],JSON_THROW_ON_ERROR),$stamp]);
$pdo->exec("UPDATE chat_messages SET role='assistant' WHERE id=104");
$archive->execute([104,null,42,104,'GENERATED_ARCHIVED_DRAFT',$stamp,$stamp]);
$pdo->exec("UPDATE agent_chat_archive SET role='assistant' WHERE id=104");
$afterReport=vp3_activity_situations_v1($pdo,$user,'system',$now);
situation_check(array_column($afterReport['items'],'revision','id')===array_column($stream['items'],'revision','id'),
    'an Agent Initiative report does not become fresh situation evidence or trigger its own next draft');
$pdo->exec('ROLLBACK TO self_feedback; RELEASE self_feedback');
$evidence=['task:201'=>['ref'=>'task:201','revision'=>hash('sha256','canonical task revision'),'title'=>'Send the agreed quote','detail'=>'Due today']];
$supportCoverage=[];
$supported=vp3_activity_situations_support_v1($pdo,$user,$evidence,$now,$supportCoverage);
situation_check(array_keys($supported)===array_keys($evidence)
    &&$supported['task:201']['canonical_source_revision']===$evidence['task:201']['revision']
    &&$supported['task:201']['activity_context']['advisory_only']===true
    &&$supported['task:201']['revision']!==$evidence['task:201']['revision'],
    'situation support enriches only an already admitted exact task and retains canonical source authority');
situation_check(vp3_activity_situations_support_v1($pdo,$user,[],$now,$supportCoverage)===[],
    'an advisory situation cannot invent work or admit passive Eyes as a new preparation source');
$pdo->exec('SAVEPOINT housekeeping_timestamp');
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2026-11-05 05:02:00' WHERE id=201");
$touched=situation_with_source(vp3_activity_situations_v1($pdo,$user,'system',$now),'notifications');
$touchedSupport=vp3_activity_situations_support_v1($pdo,$user,$evidence,$now,$supportCoverage);
situation_check($touched['updated_at']!==$linked['updated_at']&&$touched['revision']===$linked['revision']
    &&$touchedSupport['task:201']['revision']===$supported['task:201']['revision'],
    'a housekeeping timestamp updates display freshness without invalidating the situation or admitted preparation');
$pdo->exec('ROLLBACK TO housekeeping_timestamp; RELEASE housekeeping_timestamp');
$pdo->exec('SAVEPOINT supporting_revision');
$pdo->exec('UPDATE notifications SET is_read=1 WHERE id=301');
$readSupport=vp3_activity_situations_support_v1($pdo,$user,$evidence,$now,$supportCoverage);
situation_check($readSupport['task:201']['revision']!==$supported['task:201']['revision']
    &&$readSupport['task:201']['canonical_source_revision']===$evidence['task:201']['revision'],
    'a changed supporting source fences the augmented snapshot without changing the task’s canonical revision');
$pdo->exec('ROLLBACK TO supporting_revision; RELEASE supporting_revision');
$pdo->exec('SAVEPOINT eyes_unavailable');
$pdo->exec('ALTER TABLE agent_event_inbox RENAME TO unavailable_eyes_inbox');
$missing=vp3_activity_situations_v1($pdo,$user,'system',$now);
situation_check(!$missing['complete']&&$missing['coverage']['eyes']==='unavailable'
    &&situation_with_source($missing,'notifications')!==null&&situation_with_source($missing,'eyes')===null,
    'a missing Eyes source is reported unavailable while current authorized work remains visible');
$pdo->exec('ROLLBACK TO eyes_unavailable; RELEASE eyes_unavailable');
$pdo->exec('SAVEPOINT legacy_task_state');
$due=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-1 minute')->format('Y-m-d H:i:s');
$memory->execute([204,null,'Legacy task without a saved status',json_encode(['conversation_id'=>42,'due_at'=>$due],JSON_THROW_ON_ERROR),0,$stamp,$stamp]);
$legacyStream=vp3_activity_situations_v1($pdo,$user,'system',$now);
$legacyItem=array_values(array_filter($legacyStream['items'],static fn(array $item):bool=>$item['object_ref']['type']==='brain_task'&&$item['object_ref']['id']==='204'))[0]??null;
$legacyEvidence=['task:204'=>['ref'=>'task:204','revision'=>hash('sha256','legacy task snapshot'),'title'=>'Legacy task without a saved status','detail'=>'Due today']];
$legacySupport=vp3_activity_situations_support_v1($pdo,$user,$legacyEvidence,$now,$supportCoverage);
situation_check($legacyItem!==null&&$legacyItem['state']==='needs_attention'&&$legacyItem['private_preparation_allowed']
    &&isset($legacySupport['task:204']['activity_context']),
    'a legacy task with a due date and no task_status retains open-task preparation behavior');
$canonical=vp3_catch_up_candidates_v1($pdo,$user,now:new DateTimeImmutable('now',new DateTimeZone('UTC')));
$canonicalRevision=$canonical['source_revisions']['task:204']??null;
situation_check(is_string($canonicalRevision),'the legacy task remains admitted through the canonical catch-up reader');
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2026-11-05 05:02:00' WHERE id=204");
$canonicalTouched=vp3_catch_up_candidates_v1($pdo,$user,now:new DateTimeImmutable('now',new DateTimeZone('UTC')));
situation_check(($canonicalTouched['source_revisions']['task:204']??null)===$canonicalRevision,
    'canonical task hashes also ignore a housekeeping last_seen_at update');
$pdo->exec('ROLLBACK TO legacy_task_state; RELEASE legacy_task_state');
$pdo->exec('SAVEPOINT exact_support_verification');
$pdo->prepare("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.due_at',?) WHERE id=201")->execute([$due]);
$sourceCoverage=[];
$preparedEvidence=vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$sourceCoverage,['task:201']);
$prepared=$preparedEvidence['task:201']??null;
situation_check($prepared!==null&&isset($prepared['canonical_source_revision']),
    'the real Initiative evidence provider records both exact canonical and supporting revisions');
$storedSources=['task:201'=>$prepared['revision']];
$storedCanonical=['task:201'=>$prepared['canonical_source_revision']];
$pdo->exec('ALTER TABLE notifications RENAME TO unavailable_support_notifications');
$supportFailure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,$storedSources,false,$storedCanonical);}
catch(RuntimeException $error){$supportFailure=$error->getCode();}
situation_check($supportFailure===503,
    'a supporting-source outage with an unchanged verified canonical task remains retryable');
$legacyFailure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,$storedSources);}
catch(RuntimeException $error){$legacyFailure=$error->getCode();}
situation_check($legacyFailure===409,
    'an old preparation without a canonical revision map remains conservatively fenced');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','in_progress') WHERE id=201");
$semanticFailure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,$storedSources,false,$storedCanonical);}
catch(RuntimeException $error){$semanticFailure=$error->getCode();}
situation_check($semanticFailure===409,
    'a real task change still invalidates preparation during a simultaneous supporting-source outage');
$pdo->exec('ROLLBACK TO exact_support_verification; RELEASE exact_support_verification');
$pdo->exec('SAVEPOINT displaced_support');
$liveNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$older=$liveNow->modify('-1 hour')->format('Y-m-d H:i:s');
$newer=$liveNow->modify('-5 seconds')->format('Y-m-d H:i:s');
$pdo->prepare("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.due_at',?),last_seen_at=? WHERE id=201")
    ->execute([$due,$older]);
$focusedCoverage=[];
$beforeDisplacement=vp3_initiative_evidence_v1($pdo,$user,$liveNow,false,[],$focusedCoverage,['task:201'])['task:201'];
for($offset=0;$offset<40;$offset++){
    $taskId=1000+$offset;
    $memory->execute([$taskId,null,'Send the agreed quote',json_encode(['conversation_id'=>42,'task_status'=>'open','due_at'=>$due],JSON_THROW_ON_ERROR),0,$newer,$newer]);
    $notification->execute([2000+$offset,'Follow-up needs review','/chat.php?agent=system&conversation_id=42',$taskId,$newer]);
}
$displacedStream=vp3_activity_situations_v1($pdo,$user,'system',$liveNow);
situation_check(count($displacedStream['items'])===12
    &&!array_filter($displacedStream['items'],static fn(array $item):bool=>$item['object_ref']['type']==='brain_task'&&$item['object_ref']['id']==='201'),
    'unrelated later work may displace an old task from the bounded Activity display');
$afterDisplacement=vp3_initiative_evidence_v1($pdo,$user,$liveNow,false,[],$focusedCoverage,['task:201'])['task:201'];
situation_check($afterDisplacement['revision']===$beforeDisplacement['revision']
    &&$afterDisplacement['activity_context']===$beforeDisplacement['activity_context']
    &&$focusedCoverage['activity_context']==='ready',
    'forty unrelated tasks and notifications do not change exact admitted task support');
$stillCurrent=vp3_initiative_current_sources_v1($pdo,$user,['task:201'=>$beforeDisplacement['revision']],false,
    ['task:201'=>$beforeDisplacement['canonical_source_revision']]);
situation_check(array_keys($stillCurrent)===['task:201']&&$stillCurrent['task:201']['revision']===$beforeDisplacement['revision'],
    'canonical revalidation accepts the unchanged queued task even while its display card is displaced');
$notification->execute([3000,'A new review for this exact task','/chat.php?agent=system&conversation_id=42',201,$newer]);
$exactNewActivity=vp3_initiative_evidence_v1($pdo,$user,$liveNow,false,[],$focusedCoverage,['task:201'])['task:201'];
situation_check($exactNewActivity['revision']!==$beforeDisplacement['revision']
    &&$exactNewActivity['canonical_source_revision']===$beforeDisplacement['canonical_source_revision'],
    'new notification activity targeting the exact task advances its supporting revision');
$pdo->exec('ROLLBACK TO displaced_support; RELEASE displaced_support');
$pdo->exec('SAVEPOINT revoke_source');
$pdo->exec('UPDATE chat_conversations SET user_id=2 WHERE id=42');
$revoked=vp3_activity_situations_v1($pdo,$user,'system',$now);
situation_check(!in_array($linked['id'],array_column($revoked['items'],'id'),true)
    &&!str_contains(situation_json($revoked),'conversation_id=42'),
    'source revocation removes stale source links and prevents the former situation from being reused');
$pdo->exec('ROLLBACK TO revoke_source; RELEASE revoke_source');

echo 'AGENT_ACTIVITY_SITUATIONS_V1=PASS ('.$checks." cases)\n";
