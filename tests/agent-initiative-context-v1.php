<?php
declare(strict_types=1);

// Real scoped task, unified situation and Initiative source adapters.
// Only account/permission services and the disposable SQLite database are fixtures.
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
function has_permission(string $permission,array $user): bool{return !in_array($permission,$GLOBALS['context_denied_permissions']??[],true);}
function personal_capability_has_v242(string $permission,array $user): bool{return has_permission($permission,$user);}
function user_agent_get_v236(PDO $pdo,int $owner,int $id): ?array
{
    return $owner===1&&in_array($id,[7,8],true)?['id'=>$id,'is_active'=>1]:null;
}
function user_agent_principal_v236(array $user,?array $agent): array
{
    return ['kind'=>$agent?'user_agent':'system','owner_user_id'=>$user['id'],'viewer_user_id'=>$user['id'],'agent_id'=>$agent['id']??0];
}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['situation_participant']??null;}
function vp3_participant_resource_v1(PDO $pdo,array $user,array $principal,string $kind,int $id): bool
{
    return $kind!=='task'||!in_array($id,$GLOBALS['situation_denied_tasks']??[],true);
}
function notification_system_sql_predicate(string $alias=''): string{return '1=1';}
function notification_requires_attention(array $row): bool{return ($row['type']??'')==='action_required';}
function user_calendar_default_timezone_v1300(PDO $pdo,array $user): string{return 'UTC';}
function ai_generate_chat_response(): never{throw new RuntimeException('Polling a situation must not invoke inference.');}

function user_data_policy_can_use_v236(PDO $pdo,array $principal,int $owner,string $kind,string $id,bool $legacy=false): bool {return !in_array($id,$GLOBALS['context_denied_records']??[],true);}

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

require_once $root.'/includes/agent-initiative-context-v1.php';

$checks=0;
function context_check(bool $ok,string $name): void
{
    if(!$ok)throw new RuntimeException($name);
    $GLOBALS['checks']++;echo 'PASS '.$name."\n";
}
function context_read(?array $refs=null,?array $who=null,?array $principal=null,?DateTimeImmutable $at=null): array
{
    $who??=$GLOBALS['user'];$principal??=vp3_agent_chat_principal_v380($who,null);
    $coverage=[];
    $rows=vp3_initiative_context_evidence_v1(db(),$who,$principal,$at??$GLOBALS['now'],$coverage,$refs);
    return [$rows,$coverage];
}
function context_kind(array $rows,string $kind): array{return array_filter($rows,static fn(array $row):bool=>$row['kind']===$kind);}

$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();
$incoming=['user_id'=>77,'agent_id'=>99];$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$incoming;
$pdo->exec('PRAGMA query_only=ON');
[$rows,$coverage]=context_read();
context_check(isset($rows['open-task:201'])&&!isset($rows['open-task:207'])&&$rows['open-task:201']['task']['due_at']===null,
    'an undated canonical open task independently enters the System Agent working context');
context_check($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']===$incoming,
    'context discovery restores the incoming owner and Agent memory scope');
$standalone=context_kind($rows,'activity_situation');
context_check(count($standalone)===2&&count(context_kind($rows,'open_task'))===1,
    'standalone history and Eyes become first-class context without duplicating the linked task situation');
$eyesRow=array_values(array_filter($standalone,static fn(array $r):bool=>($r['activity_context']['object_ref']['type']??'')==='agent_eyes_session'))[0]??null;
context_check($eyesRow!==null&&$eyesRow['activity_context']['advisory_only']===true
    &&$eyesRow['activity_context']['private_preparation_allowed']===false
    &&$eyesRow['activity_context']['action_authority']===false&&$eyesRow['activity_context']['capture_authority']===false
    &&$eyesRow['activity_context']['sources'][0]['confirmation']==='browser_reported',
    'passive Eyes keeps provisional provenance and grants no capture, identity or action authority');
$json=json_encode($rows,JSON_THROW_ON_ERROR);
context_check(!str_contains($json,'PRIVATE_FRAME')&&!str_contains($json,'PRIVATE_CAMERA_SUMMARY')
    &&!str_contains($json,'PRIVATE_LIVE_TRANSCRIPT')&&!str_contains($json,'PRIVATE_HISTORY_MESSAGE')
    &&!str_contains($json,'PRIVATE_ARCHIVED_TEXT')&&!str_contains($json,'PRIVATE_NOTIFICATION_DETAIL'),
    'new context excludes raw screen/camera, transcript, archive and notification bodies');
context_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before,
    'admitting context is read only and invokes no model, task mutation, queue or Chat delivery');
[$later]=context_read(at:$now->modify('+1 minute'));
context_check(array_column($rows,'revision','ref')===array_column($later,'revision','ref'),
    'polling unchanged contexts produces no invented new situation revision');
[$selected]=context_read(['open-task:201']);
context_check(array_keys($selected)===['open-task:201']&&$selected['open-task:201']['revision']===$rows['open-task:201']['revision'],
    'selected references reread the exact task without unrelated context substitution');
[$none]=context_read([]);
context_check($none===[],'an empty selected set never broadens into all recent sources');
$namedPrincipal=vp3_agent_chat_principal_v380($user,['id'=>7]);
[$named]=context_read(principal:$namedPrincipal);
context_check(isset($named['open-task:207'])&&!isset($named['open-task:201'])
    &&!str_contains(json_encode($named,JSON_THROW_ON_ERROR),'PRIVATE_CAMERA_SUMMARY'),
    'a named Agent receives only its own authorized undated task and context namespace');
[$foreign]=context_read(who:['id'=>2,'is_active'=>1]);
context_check($foreign===[],'another owner cannot borrow the first account’s task or situations');
$spoofed=false;
try{context_read(principal:array_replace($namedPrincipal,['owner_user_id'=>2]));}catch(RuntimeException $e){$spoofed=$e->getCode()===403;}
context_check($spoofed,'a forged named-Agent owner binding fails closed');
$GLOBALS['situation_participant']=['participant_id'=>4];$denied=false;
try{context_read();}catch(RuntimeException $e){$denied=$e->getCode()===403;}
unset($GLOBALS['situation_participant']);
context_check($denied,'participant scope cannot inherit owner-private context admission');
$GLOBALS['context_denied_permissions']=['agent_brain.access'];
[$withoutBrain,$withoutBrainCoverage]=context_read();
context_check(!context_kind($withoutBrain,'open_task')&&!context_kind($withoutBrain,'activity_situation')
    &&$withoutBrainCoverage['open_tasks']==='forbidden',
    'removing source Brain permission withholds tasks, memory, history and Eyes context');
unset($GLOBALS['context_denied_permissions']);
$GLOBALS['context_denied_records']=['brain:207'];
[$revokedNamed]=context_read(['open-task:207'],principal:$namedPrincipal);
unset($GLOBALS['context_denied_records']);
context_check($revokedNamed===[],'per-record named-Agent task revocation withdraws selected context');
$pdo->exec('PRAGMA query_only=OFF');

$pdo->exec('SAVEPOINT named_memory_policy');
$pdo->exec("INSERT INTO agent_memory_items SELECT 901,user_id,user_agent_id,'preference','Private named preference',memory_text,is_active,
    metadata_json,source_archive_id,last_seen_at,first_seen_at,occurrence_count,memory_scope_version FROM agent_memory_items WHERE id=207");
[$withPreference]=context_read(principal:$namedPrincipal);
$preferenceSituations=context_kind($withPreference,'activity_situation');
context_check(count($preferenceSituations)===1,
    'named conversation history and its authorized saved preference share one canonical situation');
$GLOBALS['context_denied_records']=['brain:901'];
[$withoutPreference]=context_read(array_keys($preferenceSituations),principal:$namedPrincipal);
unset($GLOBALS['context_denied_records']);
context_check($withoutPreference===[],
    'grouping a revoked named Brain memory into a conversation cannot launder its source permission');
$pdo->exec('ROLLBACK TO named_memory_policy; RELEASE named_memory_policy');

$pdo->exec('SAVEPOINT independent_notification');
$pdo->prepare("INSERT INTO notifications VALUES(303,1,'action_required','A saved account review','PRIVATE_NOTIFICATION_BODY','/account.php','account',1,0,?)")
    ->execute([$stamp]);
$GLOBALS['context_denied_permissions']=['agent_brain.access'];
[$accountOnly]=context_read();
unset($GLOBALS['context_denied_permissions']);
context_check(count(context_kind($accountOnly,'activity_situation'))===1
    &&!context_kind($accountOnly,'open_task')&&!str_contains(json_encode($accountOnly,JSON_THROW_ON_ERROR),'PRIVATE_NOTIFICATION_BODY'),
    'an independently authorized account notification can inform private context without granting general Brain access');
[$namedAccount]=context_read(principal:$namedPrincipal);
context_check(!str_contains(json_encode($namedAccount,JSON_THROW_ON_ERROR),'A saved account review'),
    'unscoped account notifications never cross into a named Agent’s situation namespace');
$pdo->exec('ROLLBACK TO independent_notification; RELEASE independent_notification');

$pdo->exec('SAVEPOINT task_changes');
$revision=$rows['open-task:201']['revision'];
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2026-11-05 05:02:00' WHERE id=201");
[$touched]=context_read(['open-task:201']);
context_check($touched['open-task:201']['revision']===$revision,'task housekeeping timestamps do not trigger duplicate background work');
$pdo->exec("UPDATE agent_memory_items SET memory_text='Prepare the revised quote privately' WHERE id=201");
[$changed]=context_read(['open-task:201']);
context_check($changed['open-task:201']['revision']!==$revision,'a task content change invalidates the saved source revision');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','in_progress') WHERE id=201");
[$progress]=context_read(['open-task:201']);
context_check($progress['open-task:201']['task']['status']==='in_progress'
    &&$progress['open-task:201']['revision']!==$changed['open-task:201']['revision'],
    'canonical task progress advances its existing source rather than creating an unrelated situation');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.due_at','2026-11-06') WHERE id=201");
[$dated]=context_read(['open-task:201']);
context_check($dated===[],'a newly dated task leaves the undated source instead of retaining stale evidence');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_remove(metadata_json,'$.due_at') WHERE id=201");
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','completed') WHERE id=201");
[$completed]=context_read(['open-task:201']);
context_check($completed===[],'completed canonical work cannot return as an open undated task');
$pdo->exec('ROLLBACK TO task_changes; RELEASE task_changes');

$pdo->exec('SAVEPOINT source_changes');
$history=array_values(array_filter($standalone,static fn(array $r):bool=>($r['activity_context']['object_ref']['type']??'')==='conversation'))[0];
[$exactHistory]=context_read([$history['ref']]);
context_check(array_keys($exactHistory)===[$history['ref']]&&$exactHistory[$history['ref']]['revision']===$history['revision'],
    'a standalone conversation situation can be revalidated by its exact opaque reference');
$pdo->exec("UPDATE chat_conversations SET title='Changed client priorities' WHERE id=44");
[$changedHistory]=context_read([$history['ref']]);
context_check($changedHistory[$history['ref']]['revision']!==$history['revision'],
    'a changed source conversation advances the same situation revision before delayed delivery');
$pdo->exec('UPDATE chat_conversations SET user_id=2 WHERE id=44');
[$withdrawn]=context_read([$history['ref']]);
context_check($withdrawn===[],'conversation ownership revocation withdraws its exact standalone situation');
$pdo->exec('ROLLBACK TO source_changes; RELEASE source_changes');

$pdo->exec('SAVEPOINT no_tasks');
$pdo->exec("UPDATE agent_memory_items SET is_active=0 WHERE memory_type IN ('task','commitment')");
[$onlyContext]=context_read();
context_check(!context_kind($onlyContext,'open_task')&&count(context_kind($onlyContext,'activity_situation'))>=2,
    'fresh History and saved Eyes still seed private assessment when there are no open or due task anchors');
$pdo->exec('ROLLBACK TO no_tasks; RELEASE no_tasks');

$pdo->exec('SAVEPOINT later_work');
for($offset=0;$offset<110;$offset++)$memory->execute([1000+$offset,null,'Unrelated newer task',
    json_encode(['conversation_id'=>42,'task_status'=>'open'],JSON_THROW_ON_ERROR),0,'2026-11-05 05:09:00','2026-11-05 05:09:00']);
[$bounded,$boundedCoverage]=context_read();
[$stillExact]=context_read(['open-task:201']);
context_check(count(context_kind($bounded,'open_task'))<=8&&$boundedCoverage['open_tasks']==='limited'
    &&isset($stillExact['open-task:201'])&&$stillExact['open-task:201']['revision']===$revision,
    'bounded discovery never prevents exact selected task revalidation after 110 unrelated later tasks');
[$displaced,$displacedCoverage]=context_read([$history['ref']]);
context_check($displaced===[]&&$displacedCoverage['activity_situations']==='limited',
    'a situation displaced from the bounded stream is withheld as limited, never replaced by a newer source');
$pdo->exec('ROLLBACK TO later_work; RELEASE later_work');

$pdo->exec('SAVEPOINT closed_work');
for($offset=0;$offset<110;$offset++)$memory->execute([2000+$offset,null,'Completed newer task',
    json_encode(['conversation_id'=>42,'task_status'=>'completed'],JSON_THROW_ON_ERROR),0,'2026-11-05 05:09:00','2026-11-05 05:09:00']);
[$amidClosed]=context_read();
context_check(isset($amidClosed['open-task:201']),
    'closed or already dated work cannot crowd an unresolved undated task out of discovery before its limit');
$pdo->exec('ROLLBACK TO closed_work; RELEASE closed_work');

$pdo->exec('SAVEPOINT feedback');
$message->execute([999,44,'GENERATED_PRIVATE_REPORT',json_encode(['source'=>'agent_initiative','skip_brain_archive'=>true],JSON_THROW_ON_ERROR),$stamp]);
$archive->execute([999,null,44,999,'GENERATED_PRIVATE_ARCHIVE',$stamp,$stamp]);
[$afterReport]=context_read();
context_check(array_column($afterReport,'revision','ref')===array_column($rows,'revision','ref'),
    'the Agent’s own completed initiative report does not become a new situation and feedback loop');
$pdo->exec('ROLLBACK TO feedback; RELEASE feedback');

$pdo->exec('SAVEPOINT decay');
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE id=201");
[$oldTask]=context_read(['open-task:201']);
[$oldContext]=context_read([$history['ref']],at:$now->modify('+8 days'));
context_check(isset($oldTask['open-task:201'])&&$oldTask['open-task:201']['revision']===$revision&&$oldContext===[],
    'unresolved undated work stays active while low-value standalone observations decay after seven days');
$pdo->exec('ROLLBACK TO decay; RELEASE decay');

$pdo->exec('SAVEPOINT source_outage');
$pdo->exec('ALTER TABLE agent_memory_items RENAME TO unavailable_memories');
[$outage,$outageCoverage]=context_read(['open-task:201']);
context_check($outage===[]&&$outageCoverage['open_tasks']==='unavailable',
    'a missing canonical task store is unavailable, not an invented empty or successful source');
$pdo->exec('ROLLBACK TO source_outage; RELEASE source_outage');

$pdo->exec('SAVEPOINT canonical_revalidation');
$liveNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$fresh=$liveNow->modify('-1 minute')->format('Y-m-d H:i:s');
$pdo->prepare('UPDATE agent_chat_archive SET created_at=?,archived_at=?')->execute([$fresh,$fresh]);
$sourceCoverage=[];
$canonical=vp3_initiative_evidence_v1($pdo,$user,$liveNow,false,[],$sourceCoverage,['open-task:201'],0);
context_check(isset($canonical['open-task:201'])&&$sourceCoverage['open_tasks']==='ready',
    'the real Initiative evidence provider admits the new exact undated source');
$sourceRevision=$canonical['open-task:201']['revision'];
$verified=vp3_initiative_current_sources_v1($pdo,$user,['open-task:201'=>$sourceRevision],agentId:0);
context_check(array_keys($verified)===['open-task:201'],
    'the canonical delayed-delivery source verifier accepts the unchanged admitted task');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','completed') WHERE id=201");
$failure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,['open-task:201'=>$sourceRevision],agentId:0);}
catch(RuntimeException $e){$failure=$e->getCode();}
context_check($failure===409,'canonical delivery rejects completed undated work with a changed-source fence');
$pdo->exec("UPDATE agent_memory_items SET metadata_json=json_set(metadata_json,'$.task_status','open') WHERE id=201");
$pdo->exec('ALTER TABLE agent_memory_items RENAME TO unavailable_memories');$failure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,['open-task:201'=>$sourceRevision],agentId:0);}
catch(RuntimeException $e){$failure=$e->getCode();}
context_check($failure===503,'canonical delivery treats a new context source outage as retryable verification failure');
$pdo->exec('ALTER TABLE unavailable_memories RENAME TO agent_memory_items');
$freshSituation=vp3_initiative_evidence_v1($pdo,$user,$liveNow,false,[],$sourceCoverage,[$history['ref']],0);
context_check(isset($freshSituation[$history['ref']]),'the real Initiative evidence provider admits standalone history without a due task');
$situationRevision=$freshSituation[$history['ref']]['revision'];
$pdo->exec('UPDATE chat_conversations SET user_id=2 WHERE id=44');$failure=0;
try{vp3_initiative_current_sources_v1($pdo,$user,[$history['ref']=>$situationRevision],agentId:0);}
catch(RuntimeException $e){$failure=$e->getCode();}
context_check($failure===409,'canonical delayed delivery withholds an owner-revoked standalone conversation situation');
$pdo->exec('ROLLBACK TO canonical_revalidation; RELEASE canonical_revalidation');

echo 'AGENT_INITIATIVE_CONTEXT_V1=PASS ('.$checks." cases)\n";
