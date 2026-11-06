<?php
declare(strict_types=1);

// Production authority, canonical source readers, work claimant, report writer,
// saved owner turns, broker and native effects. Only SQL dialect and inference
// transport are fixtures; no permission, policy or action function is replaced.
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool{
    if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;
});
class InitiativeActionPDO extends PDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        $sql=str_replace([' FOR UPDATE','INSERT IGNORE',"JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'","DATE_SUB(NOW(),INTERVAL 5 MINUTE)"],
            ['','INSERT OR IGNORE',"JSON_EXTRACT(p.metadata_json,'$.enabled')=1","datetime('now','-5 minutes')"],$sql);
        if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);$sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,
                confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql,$options);
    }
}
$pdo=new InitiativeActionPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $clock)$pdo->sqliteCreateFunction($clock,static fn():string=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATABASE',static fn():string=>'main');$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
function db(): PDO{return $GLOBALS['pdo'];}
function setting(string $key,mixed $fallback=''): mixed{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$fallback:$v;}
function url(string $path=''): string{return $path;}
function agent_brain_schema_ready(): bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $text): string{return strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/personal-capabilities-v242.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-worker-cloud-v1910.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-memory-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-task-lifecycle-v123.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
require_once __DIR__.'/fixtures/workflow-agent-schema-v1.php';
workflow_agent_fixture_v1($pdo);
foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $column)$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$column);
foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $column)$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$column);
foreach(['receipt_key TEXT','result_json TEXT'] as $column)$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$column);
$pdo->exec("CREATE UNIQUE INDEX action_receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key);
ALTER TABLE users ADD role TEXT DEFAULT 'artist';
CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
CREATE TABLE permissions(permission_key TEXT);INSERT INTO permissions VALUES('account.access'),('chat.access'),('agent_brain.access');
CREATE TABLE role_permissions(role TEXT,permission_key TEXT);INSERT INTO role_permissions VALUES('artist','account.access'),('artist','chat.access'),('artist','agent_brain.access');
CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);INSERT INTO user_agents VALUES(7,1,1,'Owner Agent'),(8,1,1,'Other Agent'),(9,2,1,'Foreign Agent');
CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title TEXT,updated_at TEXT);INSERT INTO chat_conversations VALUES(10,1,7,'Agent Chat',NULL),(11,1,8,'Other Chat',NULL),(12,2,9,'Foreign Chat',NULL),(14,1,NULL,'System Chat',NULL);
CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER DEFAULT 410,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
CREATE TABLE agent_chat_archive(user_agent_id INTEGER,created_at TEXT);
CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
ATTACH DATABASE ':memory:' AS information_schema;
CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name FROM main.sqlite_master WHERE type='index';
CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,activity_state TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT);
CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY);
INSERT INTO agent_activity_state VALUES(1,'chat','chat:10','paused',NOW(),NULL,'{}');");
vp3_task_lists_schema_v1($pdo);
$user=['id'=>1,'is_active'=>1,'role'=>'artist'];$principal=vp3_initiative_principal_v1($pdo,$user,7);$actionChecks=0;$actionFixtureAgent=7;$actionFixtureCid=10;
function initiative_action_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['actionChecks']++;echo 'PASS '.$label.PHP_EOL;}
function initiative_action_reject(callable $work,int $code,string $label): void{try{$work();}catch(Throwable $error){initiative_action_check($error->getCode()===$code,$label.' ('.$error->getMessage().')');return;}throw new RuntimeException($label.' did not fail');}
function initiative_action_owner(string $query,?int $cid=null,int $owner=1): int{$cid??=$GLOBALS['actionFixtureCid'];db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")->execute([$cid,$owner,$query]);return(int)db()->lastInsertId();}
function initiative_action_task(string $title): int{static $serial=0;return vp3_task_list_save_task_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],$title,null,'fixture-action-task-'.++$serial,$GLOBALS['actionFixtureCid']);}
function initiative_action_seed(string $ref,string $label='Review saved launch work',array $otherRefs=[]): array
{
    static $serial=0;$serial++;$pdo=db();$u=$GLOBALS['user'];$p=$GLOBALS['principal'];$agent=$GLOBALS['actionFixtureAgent'];$cid=$GLOBALS['actionFixtureCid'];
    vp3_initiative_chat_v1($pdo,$u,$p,['conversation_id'=>$cid],'on',null);
    initiative_action_owner('Review the current source '.$serial);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,NULL,'assistant','I will review the current saved source.')")->execute([$cid]);
    $pdo->prepare("UPDATE agent_activity_state SET context_key=?,last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")->execute(['chat:'.$cid,json_encode(['visible'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
    $binding=vp3_initiative_policy_v1($u,$agent);$scope=['user'=>$u,'principal'=>$p,'agent_id'=>$agent,'conversation_id'=>$cid];$coverage=[];
    $refs=array_merge([$ref],$otherRefs);$evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),$refs,$coverage);
    if(!isset($evidence[$ref]))throw new RuntimeException('Selected real source is unavailable: '.$ref);
    $tool=str_starts_with($ref,'task-list:')?'tasks.lists.read':'tasks.read';
    $sources=[];$map=[];foreach($refs as $sourceRef){if(!isset($evidence[$sourceRef]))throw new RuntimeException('Extra real source is unavailable.');$sources[$sourceRef]=$evidence[$sourceRef]['revision'];$map[$sourceRef]=str_starts_with($sourceRef,'task-list:')?'tasks.lists.read':'tasks.read';}
    $goal=['binding'=>$binding,'sources'=>$sources,'capability_sources'=>$map,
        'tool'=>$tool,'primary'=>$ref,'focus'=>$label,'report'=>true];
    $dedupe=hash('sha256','action-broker-fixture-'.$serial);vp3_initiative_enqueue_v1($pdo,$u,'initiative.prepare',$goal,$dedupe);
    $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');$q->execute([hash('sha256','initiative.prepare|'.$dedupe)]);$run=(int)$q->fetchColumn();
    $claim=agent_job_claim_run_v1900($pdo,$u,$run,'cloud',agent_worker_cloud_worker_id_v1910(1));
    if(!$claim)throw new RuntimeException('Actual canonical work claim failed.');
    $executed=vp3_initiative_execute_v1($pdo,$u,$claim,static function(string $prompt,string $protocol)use($label):string{
        if(db()->inTransaction())throw new LogicException('Inference held a transaction.');
        return json_encode(['draft'=>$label.' We can advance this saved work through a reviewed private action.'],JSON_THROW_ON_ERROR);
    });
    if(empty($executed['ok']))throw new RuntimeException('Actual canonical preparation failed: '.json_encode($executed));
    $q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);$saved=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $message=(int)($saved['initiative_message_id']??0);if(!$message)throw new RuntimeException('Actual preparation report was not delivered.');
    return vp3_initiative_followthrough_load_v1($pdo,$u,$p,$cid,$message);
}
function initiative_action_ref(array $loaded): array{return ['message_id'=>(int)$loaded['message']['id'],'reply_ref'=>$loaded['state']['reply_ref'],'revision'=>$loaded['state']['revision']];}
function initiative_action_reply(array $loaded,array $decision,string $query,?callable $during=null): array
{
    $id=initiative_action_owner($query);
    return vp3_initiative_followthrough_reply_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],$GLOBALS['actionFixtureCid'],$id,initiative_action_ref($loaded),$query,null,
        static function(string $prompt,string $protocol)use($decision,$during):string{
            initiative_action_check(!db()->inTransaction()&&$protocol==='vp3.initiative-reply.v1','decision uses canonical outside-transaction inference');
            $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);$GLOBALS['actionLastPrompt']=$input;
            if($during)$during();return json_encode($decision+['intent'=>'advance','focus'=>'Advance this exact private action.'],JSON_THROW_ON_ERROR);
        });
}
function initiative_action_load(array $loaded): array{return vp3_initiative_followthrough_load_v1(db(),$GLOBALS['user'],$GLOBALS['principal'],$GLOBALS['actionFixtureCid'],(int)$loaded['message']['id']);}
function initiative_action_ack(array $decision,string $text='Review this exact private action.'): int
{
    $context=['initiative_followthrough'=>$decision['initiative_followthrough'],'initiative_action'=>$decision['advisory_context']['verified_action']];
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,NULL,'assistant',?,?)")->execute([$GLOBALS['actionFixtureCid'],$text,json_encode($context,JSON_THROW_ON_ERROR)]);return(int)db()->lastInsertId();
}

$source=initiative_action_task('Review product launch');$loaded=initiative_action_seed('task:'.$source);
$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
$review=initiative_action_reply($loaded,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Draft launch images']]],'Save that as a follow-up task');
initiative_action_check($review['advisory_context']['verified_action']['status']==='approval_pending'&&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$before,'owner request prepares one typed native action review without creating a task');
initiative_action_ack($review);$pending=initiative_action_load($loaded);
$complete=initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, save that exact task');
initiative_action_check($complete['advisory_context']['verified_action']['status']==='completed'&&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$before+1,'saved owner approval commits one actual task and native broker receipt');
$done=initiative_action_load($loaded);initiative_action_check($done['domain_action']['result']['domain_result']['verified']&&$done['domain_action']['receipt_id']>0,'completed proposal reload verifies exact canonical domain and receipt proof');

// A lost transport acknowledgment rechecks the committed result; it never calls
// the native mutation again, and cannot reset a later owner status change.
$before=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$countBefore=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
$cached=['conversation_id'=>10,'agent_id'=>7,'initiative_followthrough'=>$complete['initiative_followthrough'],'initiative_action'=>$complete['advisory_context']['verified_action'],'answer'=>'The saved task is ready.'];
$replayInput=['conversation_id'=>10,'message'=>'Yes, save that exact task','initiative_reply'=>initiative_action_ref($pending)];
vp3_initiative_followthrough_replay_v1($pdo,$user,$principal,$replayInput,$cached);
initiative_action_check((int)$pdo->query('SELECT total_changes()')->fetchColumn()===$before&&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$countBefore,'lost acknowledgment replay verifies the same receipt without a second task or any write');
$nativeTask=$done['domain_action']['result']['domain_result']['office_action']['task_id'];
vp3_initiative_in_scope_v1($user,7,static fn()=>agent_task_v123_update($GLOBALS['user'],$nativeTask,'completed'));
initiative_action_reject(fn()=>vp3_initiative_followthrough_replay_v1($pdo,$user,$principal,$replayInput,$cached),409,'later owner edit makes replay stale rather than reapplying task creation');
initiative_action_check(vp3_initiative_office_action_task_v1($pdo,$user,$principal,$nativeTask)['status']==='completed','stale replay preserves the owner completed status');

// Completion can remove its own active source, with an inspected exact-record
// tombstone. The old readonly report and goal remain unchanged.
$closeTask=initiative_action_task('Complete the reviewed source');$closeRoot=initiative_action_seed('task:'.$closeTask);
$goalBefore=$closeRoot['run']['goal'];$review=initiative_action_reply($closeRoot,['action'=>['adapter'=>'task.set_status','arguments'=>['task_id'=>$closeTask,'status'=>'completed']]],'Mark that task complete');
initiative_action_ack($review);$pending=initiative_action_load($closeRoot);$completed=initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, mark that exact task complete');
$closed=initiative_action_load($closeRoot);$transition=$closed['domain_action']['result']['source_transition'];
initiative_action_check($transition['removed_refs']===['task:'.$closeTask]&&$transition['after_revisions']['task:'.$closeTask]===null&&$closed['run']['goal']===$goalBefore,'own source completion is verified with exact tombstone while original report binding stays immutable');

// Task lists remain one canonical list with referenced Brain tasks. Adding an
// item and closing a member both transition the parent source as one situation.
$list=vp3_task_list_create_v1($pdo,$user,$principal,'Product launch working list');$listId=(int)$list['id'];$member=initiative_action_task('Review launch pricing');vp3_task_list_attach_task_v1($pdo,$user,$principal,$list,$member,'Review launch pricing');
$listRoot=initiative_action_seed('task-list:'.$listId,'Review launch list');$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
$review=initiative_action_reply($listRoot,['action'=>['adapter'=>'task_list.add','arguments'=>['list_id'=>$listId,'title'=>'Draft product gallery']]],'Add that gallery draft to the launch list');initiative_action_ack($review);
$approved=initiative_action_reply(initiative_action_load($listRoot),['action_decision'=>'approve'],'Yes, add that exact item');$listDone=initiative_action_load($listRoot);
initiative_action_check($listDone['domain_action']['result']['source_transition']['affected_refs']===['task-list:'.$listId]&&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$before+1,'list-add action verifies its own changed list source and one new canonical task');
$memberRoot=initiative_action_seed('task-list:'.$listId,'Review member completion');$review=initiative_action_reply($memberRoot,['action'=>['adapter'=>'task.set_status','arguments'=>['task_id'=>$member,'status'=>'completed']]],'Mark the pricing task complete');initiative_action_ack($review);
$approved=initiative_action_reply(initiative_action_load($memberRoot),['action_decision'=>'approve'],'Yes, complete that exact pricing task');$memberDone=initiative_action_load($memberRoot);$memberTransition=$memberDone['domain_action']['result']['source_transition'];
initiative_action_check($memberTransition['parent_refs']===['task-list:'.$listId]&&$memberTransition['affected_refs']===['task-list:'.$listId]&&vp3_initiative_office_action_task_v1($pdo,$user,$principal,$member)['status']==='completed','member status action verifies the actual parent-list aggregate without waiving other members');

// Missing or altered owner-visible reviews never become effect authority.
$guardTask=initiative_action_task('Guard the review');$guardRoot=initiative_action_seed('task:'.$guardTask);$review=initiative_action_reply($guardRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Guarded new task']]],'Save that as a task');
$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
initiative_action_reject(fn()=>initiative_action_reply(initiative_action_load($guardRoot),['action_decision'=>'approve'],'Yes'),409,'approval without the exact saved assistant review is refused');
initiative_action_check((int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$before,'missing review causes no native task effect');

$staleTask=initiative_action_task('Original source title');$staleRoot=initiative_action_seed('task:'.$staleTask);$review=initiative_action_reply($staleRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Must not use stale source']]],'Create a follow-up for that');initiative_action_ack($review);$pending=initiative_action_load($staleRoot);
initiative_action_reject(fn()=>initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, create it',static fn()=>db()->prepare('UPDATE agent_memory_items SET subject=? WHERE id=?')->execute(['Source changed while interpreting approval',$staleTask])),409,'source change during approval inference prevents stale action');

$revokeTask=initiative_action_task('Permission test source');$revokeRoot=initiative_action_seed('task:'.$revokeTask);$review=initiative_action_reply($revokeRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Must not run after revoke']]],'Create a follow-up');initiative_action_ack($review);$pending=initiative_action_load($revokeRoot);
initiative_action_reject(fn()=>initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, do it',static fn()=>db()->exec("DELETE FROM role_permissions WHERE permission_key='agent_brain.access'")),409,'live personal permission revocation invalidates source authority before approved effect');$pdo->exec("INSERT INTO role_permissions VALUES('artist','agent_brain.access')");

$turnTask=initiative_action_task('Concurrent conversation source');$turnRoot=initiative_action_seed('task:'.$turnTask);$review=initiative_action_reply($turnRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Must not run after newer turn']]],'Create a next-step task');initiative_action_ack($review);$pending=initiative_action_load($turnRoot);
initiative_action_reject(fn()=>initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, do that',static fn()=>initiative_action_owner('A different new request wins')),409,'new owner turn during approval fences the stale action');

// A native side effect cannot hide unrelated source changes. SQLite triggers
// model a non-cooperating local writer inside the effect boundary itself.
$target=initiative_action_task('Exact target source');$other=initiative_action_task('Unrelated source must stay current');$both=initiative_action_seed('task:'.$target,'Review two current tasks',['task:'.$other]);
$review=initiative_action_reply($both,['action'=>['adapter'=>'task.set_status','arguments'=>['task_id'=>$target,'status'=>'completed']]],'Complete the exact target');initiative_action_ack($review);$pending=initiative_action_load($both);
$pdo->exec('CREATE TRIGGER initiative_action_unrelated_source AFTER UPDATE OF metadata_json ON agent_memory_items WHEN NEW.id='.$target.' BEGIN UPDATE agent_memory_items SET subject=\'Unexpected source edit\' WHERE id='.$other.'; END');
initiative_action_reject(fn()=>initiative_action_reply($pending,['action_decision'=>'approve'],'Yes, complete that target'),409,'unrelated source mutation inside native effect rolls back the entire action');$pdo->exec('DROP TRIGGER initiative_action_unrelated_source');
initiative_action_check(vp3_initiative_office_action_task_v1($pdo,$user,$principal,$target)['status']==='open'&&vp3_initiative_office_action_task_v1($pdo,$user,$principal,$other)['title']==='Unrelated source must stay current','failed source transition rolls back both target effect and interfering write');

$cancelTask=initiative_action_task('Cancel source');$cancelRoot=initiative_action_seed('task:'.$cancelTask);$review=initiative_action_reply($cancelRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'Cancelled new task']]],'Create that task');initiative_action_ack($review);$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn();
$cancelled=initiative_action_reply(initiative_action_load($cancelRoot),['intent'=>'decline','action_decision'=>'cancel'],'No, cancel that');
initiative_action_check($cancelled['advisory_context']['verified_action']['status']==='cancelled'&&(int)$pdo->query("SELECT COUNT(*) FROM agent_memory_items WHERE memory_type='task'")->fetchColumn()===$before,'owner cancellation closes existing action workflow with no domain effect');

// The existing generic workflow controls cannot approve or claim this governed
// action, including a deliberately inconsistent approved workflow fixture.
$pendingGuard=initiative_action_load($guardRoot);$runId=(int)$pendingGuard['domain_action']['run']['id'];
initiative_action_reject(fn()=>agent_workflow_approve_v1400($pdo,$user,$runId),409,'generic workflow approve cannot bypass original Chat review');
$pdo->prepare("UPDATE agent_workflow_runs SET status='approved' WHERE id=?")->execute([$runId]);$pdo->prepare("UPDATE agent_workflow_actions SET status='queued' WHERE run_id=?")->execute([$runId]);
initiative_action_check(agent_workflow_claim_next_action_v1400($pdo,$user,$runId,'cloud')===null,'legacy generic claimant cannot execute an action workflow');
initiative_action_check(agent_job_claim_run_v1900($pdo,$user,$runId,'cloud','fixture-bypass-worker')===null,'durable generic claimant cannot execute an action workflow');
$pdo->prepare("UPDATE agent_workflow_runs SET status='approval_pending' WHERE id=?")->execute([$runId]);$pdo->prepare("UPDATE agent_workflow_actions SET status='approval_pending' WHERE run_id=?")->execute([$runId]);

$noopRoot=initiative_action_seed('task-list:'.$listId,'Review already completed task');$review=initiative_action_reply($noopRoot,['action'=>['adapter'=>'task.set_status','arguments'=>['task_id'=>$member,'status'=>'completed']]],'Mark that already completed pricing task complete');initiative_action_ack($review);
$noopDone=initiative_action_reply(initiative_action_load($noopRoot),['action_decision'=>'approve'],'Yes, use the current task');$noopLoaded=initiative_action_load($noopRoot);$native=$noopLoaded['domain_action']['result']['domain_result'];
initiative_action_check($native['tool_receipt']['status']==='read_complete'&&!$native['tool_receipt']['changes_applied']&&$noopDone['advisory_context']['verified_action']['status']==='completed','typed no-op task review completes with a verified native read_complete receipt');
$existingListRoot=initiative_action_seed('task-list:'.$listId,'Review the current existing list');$review=initiative_action_reply($existingListRoot,['action'=>['adapter'=>'task_list.create','arguments'=>['name'=>'Product launch working list']]],'Create that named list');initiative_action_ack($review);
$listCount=(int)$pdo->query('SELECT COUNT(*) FROM agent_task_lists_v1')->fetchColumn();initiative_action_reply(initiative_action_load($existingListRoot),['action_decision'=>'approve'],'Yes, use that exact list');$existingListDone=initiative_action_load($existingListRoot);$native=$existingListDone['domain_action']['result']['domain_result'];
initiative_action_check($native['tool_receipt']['status']==='read_complete'&&!$native['tool_receipt']['changes_applied']&&(int)$pdo->query('SELECT COUNT(*) FROM agent_task_lists_v1')->fetchColumn()===$listCount,'typed existing list creation returns current native list with no duplicate or mutation claim');

$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn();$closed=initiative_action_load($closeRoot);
$afterComplete=initiative_action_reply($closed,[],'Continue that completed task');
initiative_action_check($afterComplete['advisory_context']['verified_action']['status']==='completed'&&$afterComplete['initiative_followthrough']['continuation_run_id']===0
    &&(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn()===$before,'later advance discusses actual completed outcome without requeuing pre-action source work');

// The durable System Agent uses canonical owner_user_id=0 in its principal and
// NULL in Brain scope. It exercises the entire same governed broker path.
$actionFixtureAgent=0;$actionFixtureCid=14;$principal=vp3_initiative_principal_v1($pdo,$user,0);
$systemTask=initiative_action_task('System source task');$systemRoot=initiative_action_seed('task:'.$systemTask,'Review System source');
$review=initiative_action_reply($systemRoot,['action'=>['adapter'=>'task.create','arguments'=>['title'=>'System next task']]],'Save this as a System task');initiative_action_ack($review);
$systemDone=initiative_action_reply(initiative_action_load($systemRoot),['action_decision'=>'approve'],'Yes, save that exact System task');$systemLoaded=initiative_action_load($systemRoot);$systemNative=$systemLoaded['domain_action']['result']['domain_result'];
$q=$pdo->prepare('SELECT user_agent_id FROM agent_memory_items WHERE id=?');$q->execute([$systemNative['office_action']['task_id']]);
initiative_action_check($principal['owner_user_id']===0&&$systemDone['advisory_context']['verified_action']['status']==='completed'&&$q->fetchColumn()===null,'real System principal completes root broker action in canonical null Agent namespace');
echo 'INITIATIVE_ACTIONS=PASS ('.$actionChecks.' checks; actual authority, source/report and reviewed broker effects)'.PHP_EOL;
