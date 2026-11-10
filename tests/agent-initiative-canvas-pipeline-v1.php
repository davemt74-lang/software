<?php
declare(strict_types=1);

// Exercise the real scheduler, governed inference wrapper, planner, Canvas
// adapter, receipt transaction, coding executor, meter and private store.
// Only host availability and the two physical provider transports are fixtures.
$mysqlDsn = getenv('VP3_TEST_MYSQL_DSN');
$fixtureFile = null;
if ($mysqlDsn) {
    $admin = new PDO($mysqlDsn, getenv('VP3_TEST_MYSQL_USER') ?: null, getenv('VP3_TEST_MYSQL_PASS') ?: null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database = 'initiative_canvas_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn = preg_replace('/;?dbname=[^;]*/', '', $mysqlDsn).';dbname='.$database;
    register_shutdown_function(static function () use ($admin, $database): void {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']->inTransaction()) $GLOBALS['pdo']->rollBack();
        $admin->exec('DROP DATABASE '.$database);
    });
} else {
    $fixtureFile = tempnam(sys_get_temp_dir(), 'initiative-canvas-pipeline-');
    if ($fixtureFile === false) throw new RuntimeException('Could not create pipeline fixture.');
    $dsn = 'sqlite:'.$fixtureFile;
    register_shutdown_function(static fn()=>unlink($fixtureFile));
}
putenv('VP3_IMAGE_TEST_DSN='.$dsn);
putenv('VP3_IMAGE_TEST_USER='.(getenv('VP3_TEST_MYSQL_USER') ?: ''));
putenv('VP3_IMAGE_TEST_PASSWORD='.(getenv('VP3_TEST_MYSQL_PASS') ?: ''));
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-reminders-v1.php';
require_once dirname(__DIR__).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__).'/includes/agent-initiative-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-canvas-policy-v1.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';
require_once dirname(__DIR__).'/includes/ai-runtime-v100.php';

function agent_brain_schema_ready(): bool { return true; }
function agent_brain_normalize(string $value): string { return strtolower(trim($value)); }
function agent_brain_sentences(string $value): array { return [$value]; }
function personal_knowledge_available(array $user): bool { return personal_capability_has_v242('personal_knowledge.access', $user); }
function vp3_agent_runtime_plan_v420(PDO $pdo, array $user, int $agent, string $surface='chat', array $context=[]): array
{
    return ['route'=>'vp3_cloud','blocked'=>false,'effective_preference'=>'vp3_cloud','cloud'=>['ready'=>true],
        'home'=>['supported'=>false],'try_homeserver'=>false];
}
function ai_generate_chat_response(string $prompt, array $history, array $context, ?array $user=null, ?string $scope=null, ?array $control=null): array
{
    $GLOBALS['pipelineInference'][] = ['protocol'=>$control['protocol'] ?? '', 'scope'=>$scope,
        'context'=>ai_v100_service_context(), 'history'=>$history, 'retrieved'=>$context];
    return ['ok'=>true,'answer'=>($GLOBALS['pipelineModel'])($prompt, (string)$control['protocol'])];
}
function ai_curl_json(string $endpoint, array $headers, array $payload, int $timeout=45,
    ?AiV100ServiceMeter $meter=null, ?array $target=null, ?string $personalKey=null, bool $singleAttempt=false): array
{
    if (!$meter || !$target || !$singleAttempt) throw new RuntimeException('Expected the canonical metered single provider request.');
    $ticket = $meter->begin($target, $payload, $target['provider']==='openai' ? 'max_output_tokens' : 'max_tokens');
    $GLOBALS['pipelineCodingCalls'][] = ['target'=>$target,'model'=>$payload['model'],'ticket'=>$ticket];
    $usage = ['input_tokens'=>40,'output_tokens'=>80,'total_tokens'=>120];
    $meter->finish($ticket, $usage, 'completed', ['provider_request_id'=>'fixture-canvas-'.count($GLOBALS['pipelineCodingCalls'])]);
    if (is_callable($GLOBALS['pipelineGenerationHook'] ?? null)) ($GLOBALS['pipelineGenerationHook'])();
    $source = json_encode(['title'=>'Private prep board','html'=>'<button id="ready">Mark ready</button>',
        'css'=>'button{padding:12px}','js'=>'CanvasUI.on("#ready","click",()=>CanvasData.save({ready:true}));'], JSON_THROW_ON_ERROR);
    return ['ok'=>true,'data'=>$target['provider']==='openai'
        ? ['status'=>'completed','output_text'=>$source,'usage'=>$usage]
        : ['stop_reason'=>'end_turn','content'=>[['type'=>'text','text'=>$source]],'usage'=>$usage]];
}

class InitiativeCanvasPipelineStatement extends PDOStatement
{
    protected function __construct(private InitiativeCanvasPipelinePDO $connection) {}
    public function execute(?array $params=null): bool
    {
        if (str_starts_with($this->queryString, 'INSERT INTO agent_workflow_receipts')
            && ($params[7] ?? null)==='completed' && is_callable($this->connection->beforeReceipt)) {
            ($this->connection->beforeReceipt)($params);
        }
        return parent::execute($params);
    }
}
class InitiativeCanvasPipelinePDO extends ImageFixturePDO
{
    public mixed $beforeReceipt = null;
    public function prepare(string $sql, array $options=[]): PDOStatement|false
    {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
            $sql = str_replace(["JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'", 'DATE_SUB(NOW(),INTERVAL 5 MINUTE)'],
                ["JSON_EXTRACT(p.metadata_json,'$.enabled')=1", "datetime('now','-5 minutes')"], $sql);
            if (str_contains($sql, 'INSERT INTO agent_memory_items') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                [$sql] = explode('ON DUPLICATE KEY UPDATE', $sql, 2);
                $sql .= 'ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,
                    confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
            }
        }
        return parent::prepare($sql, $options);
    }
}
$pdo = $GLOBALS['pdo'] = new InitiativeCanvasPipelinePDO($dsn, getenv('VP3_IMAGE_TEST_USER') ?: null,
    getenv('VP3_IMAGE_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [InitiativeCanvasPipelineStatement::class, [$pdo]]);
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if ($mysqlDsn && !$mysql) throw new RuntimeException('The native pipeline fixture must actually use MySQL.');
if (!$mysql) {
    foreach (['NOW','UTC_TIMESTAMP'] as $clock) $pdo->sqliteCreateFunction($clock, static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('DATABASE', static fn()=>'main');
    $pdo->sqliteCreateFunction('IF', static fn($condition,$yes,$no)=>$condition ? $yes : $no);
    $pdo->exec('DELETE FROM agent_workflow_runs');
}
$key = $mysql ? 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
$itemKey = $mysql ? 'INT UNSIGNED PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
    ALTER TABLE chat_conversations ADD COLUMN title VARCHAR(190);
    UPDATE chat_conversations SET title='Private prep work';
    CREATE TABLE agent_memory_items(id $key,user_id INTEGER,user_agent_id INTEGER,memory_type VARCHAR(40),subject VARCHAR(190),
      memory_text TEXT,memory_hash VARCHAR(64),memory_scope_version INTEGER DEFAULT 410,source_archive_id INTEGER,
      confidence DOUBLE,occurrence_count INTEGER,first_seen_at DATETIME,last_seen_at DATETIME,is_active INTEGER,metadata_json TEXT,
      UNIQUE(user_id,memory_hash));
    CREATE TABLE agent_chat_archive(user_agent_id INTEGER,created_at DATETIME);
    CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
    CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
    CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type VARCHAR(40),resource_id VARCHAR(190),audience_scope VARCHAR(40),
      owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type VARCHAR(40),resource_id VARCHAR(190),access_mode VARCHAR(20));
    CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface VARCHAR(40),context_key VARCHAR(190),activity_state VARCHAR(40),
      last_heartbeat_at DATETIME,idle_since_at DATETIME,details_json TEXT);
    CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY);
    CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE artist_transcript_folders_v177(id $key,created_by_user_id INT,folder_name VARCHAR(80),sort_order INT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE knowledge_items(id $itemKey,title VARCHAR(190),description TEXT,file_name VARCHAR(190),file_type VARCHAR(60),
      mime_type VARCHAR(80),file_size INT,content_text TEXT,visibility VARCHAR(20),is_published INT,created_by_user_id INT,
      knowledge_scope VARCHAR(20),folder_id BIGINT,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE ai_execution_ledger(id $key,user_id INT,agent_id BIGINT,conversation_id BIGINT,cloud_ledger_id BIGINT,
      trace_id VARCHAR(120) DEFAULT '',source VARCHAR(40),provider VARCHAR(80),model VARCHAR(160),run_id BIGINT,
      input_tokens INT DEFAULT 0,output_tokens INT DEFAULT 0,total_tokens INT DEFAULT 0,cloud_tokens_charged INT DEFAULT 0,
      estimated_cost_micros BIGINT,cost_currency VARCHAR(3) DEFAULT 'USD',cost_rate_source VARCHAR(40),latency_ms INT DEFAULT 0,
      failure_class VARCHAR(40),created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
if (!$mysql) $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
      FROM main.sqlite_master WHERE type='index'");
$GLOBALS['initiativeBrainAccess'] = $GLOBALS['initiativeScreenAccess'] = true;
$GLOBALS['pipelineInference'] = $GLOBALS['pipelineCodingCalls'] = [];
vp3_ai_service_usage_ensure_schema_v1($pdo);
vp3_coding_canvas_schema_v1($pdo);
$owner = ['id'=>1,'is_active'=>1];
$principal = vp3_agent_chat_principal_v380($owner, vp3_agent_chat_resolve_agent_v380($pdo, $owner, 7));
$selected = ['provider'=>'openai','model'=>'coding-pipeline-selected'];
$replacement = ['provider'=>'anthropic','model'=>'coding-pipeline-next'];
foreach ([$selected,$replacement] as $target) vp3_ai_service_save_model_v1($pdo, $target['provider'], $target['model'], $target['model'], ['code_build','code_repair']);
vp3_coding_canvas_model_save_v1($pdo, $owner, $selected);
$pdo->exec("INSERT INTO agent_activity_state VALUES(1,'chat','chat:11','idle',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,
    '{\"visible\":true,\"conversation_id\":11,\"chat_focused\":true,\"chat_busy\":false}')");
$checks = 0;
function canvas_pipeline_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    ++$GLOBALS['checks'];
    echo 'PASS '.$message."\n";
}
function canvas_pipeline_count(string $table, string $where='1=1'): int { return (int)db()->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$where)->fetchColumn(); }
function canvas_pipeline_runs(string $capability): array
{
    $q=db()->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=1 AND capability_key=? ORDER BY id');
    $q->execute([$capability]); return $q->fetchAll();
}
function canvas_pipeline_claim(int $id): array
{
    return agent_job_claim_run_v1900(db(), ['id'=>1,'is_active'=>1], $id, 'cloud', agent_worker_cloud_worker_id_v1910(1), 180)
        ?: throw new RuntimeException('Could not claim the actual workflow run.');
}
function canvas_pipeline_result(int $id): array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? AND status=\'completed\' ORDER BY id DESC LIMIT 1');
    $q->execute([$id]); return json_decode((string)$q->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
}
function canvas_pipeline_model(int $task): Closure
{
    return static function(string $prompt,string $protocol) use ($task): string {
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        if ($protocol==='vp3.initiative-plan.v1') {
            canvas_pipeline_check(!empty($input['tools']['canvas.prepare']['ready'])
                && in_array('task:'.$task,$input['tools']['canvas.prepare']['source_refs'],true), 'the actual planner sees a ready Canvas tool for its exact live task');
            return json_encode(['jobs'=>[['tool'=>'canvas.prepare','primary'=>'task:'.$task,'refs'=>['task:'.$task],
                'focus'=>'Make this repeated prep task easier with a private checklist','report'=>true]]], JSON_THROW_ON_ERROR);
        }
        if ($protocol==='vp3.initiative-canvas.v1') {
            canvas_pipeline_check($input['mode']==='propose_private_canvas' && !db()->inTransaction(), 'the actual governed proposal inference runs before receipt locks');
            return json_encode(['title'=>'Task '.$task.' prep board','prompt'=>'Build a small private checklist for the verified prep task '.$task.'.',
                'draft'=>'A reusable prep board could help with this task. Would you like me to prepare a draft?'], JSON_THROW_ON_ERROR);
        }
        throw new RuntimeException('Unexpected pipeline model protocol '.$protocol);
    };
}
function canvas_pipeline_prepare(int $task, bool $execute=true): int
{
    $user=['id'=>1,'is_active'=>1];
    db()->exec('UPDATE agent_memory_items SET is_active=0 WHERE memory_type=\'task\'');
    db()->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
        memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
        VALUES(?,1,7,'task',?,'Prepare the same recurring checklist each week',?,410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)")
        ->execute([$task,'Recurring prep '.$task,'pipeline-task-'.$task,json_encode(['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)],JSON_THROW_ON_ERROR)]);
    db()->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep';
        UPDATE agent_activity_state SET last_heartbeat_at=CURRENT_TIMESTAMP;
        UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
    $GLOBALS['pipelineModel']=canvas_pipeline_model($task);
    $plans=count(canvas_pipeline_runs('initiative.plan'));
    vp3_initiative_schedule_v1(db(),$user,7);
    $rows=canvas_pipeline_runs('initiative.plan');
    canvas_pipeline_check(count($rows)===$plans+1, 'the real scheduler admits one fresh source-backed assessment');
    $id=(int)end($rows)['id'];
    canvas_pipeline_check(vp3_initiative_execute_v1(db(),$user,canvas_pipeline_claim($id))['ok'], 'the actual planner completes through the governed inference wrapper');
    $jobs=canvas_pipeline_runs('initiative.prepare');$job=(int)end($jobs)['id'];
    if ($execute) canvas_pipeline_check(vp3_initiative_execute_v1(db(),$user,canvas_pipeline_claim($job))['ok'], 'the actual Canvas adapter commits with the canonical parent receipt');
    return $job;
}
function canvas_pipeline_reply(int $messageId, string $intent): array
{
    $pdo=db();$q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$messageId]);
    $context=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$state=$context['initiative_followthrough_state'];
    $text=match($intent){'discuss'=>'Could it also track preparation time?','defer'=>'Put this aside until I ask again.',default=>'Yes, build that exact private draft now.'};
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(11,1,'user',?,CURRENT_TIMESTAMP)")->execute([$text]);
    $reply=(int)$pdo->lastInsertId();
    $GLOBALS['pipelineModel']=static function(string $prompt,string $protocol) use ($intent): string {
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        canvas_pipeline_check($protocol==='vp3.initiative-reply.v1' && isset($input['canvas_proposal']), 'owner feedback uses the real Canvas-aware follow-through classifier');
        return json_encode(['intent'=>$intent,'focus'=>'Keep this exact private prep board proposal'],JSON_THROW_ON_ERROR);
    };
    return vp3_initiative_followthrough_reply_v1($pdo,['id'=>1,'is_active'=>1],$GLOBALS['principal'],11,$reply,
        ['message_id'=>$messageId,'reply_ref'=>$state['reply_ref'],'revision'=>$state['revision']],$text);
}

$enabled=vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null);
canvas_pipeline_check(!empty($enabled['ok']), 'the owner enables the actual Agent-scoped Initiative policy');
$setting=vp3_initiative_canvas_setting_v1($pdo,$owner,7);
canvas_pipeline_check($setting['available'] && !$setting['enabled'], 'Auto Build begins off in the actual persisted policy');
$proposalRun=canvas_pipeline_prepare(1001);
$proposal=canvas_pipeline_result($proposalRun);$output=$proposal['initiative_tool_result']['output'];
canvas_pipeline_check($output['status']==='proposal_ready' && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===0
    && canvas_pipeline_count('vp3_coding_canvases_v1')===0 && canvas_pipeline_count('artist_transcript_folders_v177')===0
    && $GLOBALS['pipelineCodingCalls']===[], 'default-off planning delivers a suggestion without starting coding or creating an app folder');
$proposalMessage=(int)$proposal['initiative_message_id'];
canvas_pipeline_check($proposal['initiative_delivery']==='delivered' && $proposalMessage>0, 'the verified Canvas suggestion appears in ordinary owner Chat');
$inference=array_values(array_filter($GLOBALS['pipelineInference'],static fn(array $row):bool=>$row['protocol']==='vp3.initiative-canvas.v1'));
canvas_pipeline_check(count($inference)===1 && $inference[0]['context']['service']==='initiative'
    && $inference[0]['context']['agent_id']===7 && $inference[0]['context']['run_id']===$proposalRun
    && $inference[0]['context']['background']===true && $inference[0]['history']===[] && $inference[0]['retrieved']===[],
    'the production Canvas protocol reaches the governed Initiative service with exact attribution and no unrelated Chat context');
$discussion=canvas_pipeline_reply($proposalMessage,'discuss');
canvas_pipeline_check(($discussion['initiative_followthrough']['state']??null)==='discussed'
    && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===0, 'discussion records a real decision without granting a build');
$deferred=canvas_pipeline_reply($proposalMessage,'defer');
canvas_pipeline_check(($deferred['initiative_followthrough']['state']??null)==='deferred'
    && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===0, 'postponing the actual suggestion records feedback and starts no coding');
$advanced=canvas_pipeline_reply($proposalMessage,'advance');
canvas_pipeline_check(($advanced['initiative_followthrough']['state']??null)==='queued', 'an explicit later owner request can resume the postponed exact Canvas proposal');
$continuations=canvas_pipeline_runs('initiative.prepare');$continuation=(int)end($continuations)['id'];
$GLOBALS['pipelineModel']=static fn()=>throw new RuntimeException('Owner-approved continuation must reuse its exact saved proposal.');
$continuationClaim=canvas_pipeline_claim($continuation);
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,$continuationClaim)['ok'], 'the original adapter turns the actual owner continuation into one coding request');
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,$continuationClaim)['ok']
    && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===1,
    'a lost acknowledgement cannot replay a completed parent and admit another coding request');
$continued=canvas_pipeline_result($continuation);$codingId=(int)$continued['initiative_tool_result']['output']['coding_run_id'];
$codingGoal=json_decode(vp3_coding_canvas_job_row_v1($pdo,$owner,$codingId)['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check($codingGoal['initiative_origin']['authorization']==='owner_followthrough'
    && $codingGoal['model']===$selected+['funding'=>'system'] && !vp3_initiative_canvas_setting_v1($pdo,$owner,7)['enabled'],
    'the saved owner decision authorizes only this draft while Auto Build remains off and the selected model is pinned');
vp3_coding_canvas_model_save_v1($pdo,$owner,$replacement);
$codingClaim=canvas_pipeline_claim($codingId);
canvas_pipeline_check(vp3_coding_canvas_execute_v1($pdo,$owner,$codingClaim)['ok'], 'the canonical coding executor saves the real generated draft');
$draft=vp3_coding_canvas_read_v1($pdo,$owner,7,$codingGoal['canvas_id']);
canvas_pipeline_check($draft['canvas']['active_revision']===null && $draft['revision']['id']===1
    && is_object($draft['state']['value']) && get_object_vars($draft['state']['value'])===[], 'the generated app remains an inactive saved draft with its own empty app state');
$folder=(int)$draft['canvas']['folder_id'];
canvas_pipeline_check(canvas_pipeline_count('artist_transcript_folders_v177','id='.$folder.' AND created_by_user_id=1')===1
    && canvas_pipeline_count('knowledge_items',"folder_id=$folder AND created_by_user_id=1 AND knowledge_scope='personal' AND visibility='private' AND is_published=0")===2,
    'code and app data occupy the new app’s own private folder in the user Knowledge Base');
$usage=$pdo->query('SELECT * FROM ai_execution_ledger ORDER BY id DESC LIMIT 1')->fetch();
canvas_pipeline_check($usage['model']===$selected['model'] && (int)$usage['user_id']===1 && (int)$usage['agent_id']===7
    && (int)$usage['conversation_id']===11 && (int)$usage['run_id']===$codingId && $usage['service_key']==='code_build'
    && (int)$usage['is_background']===1 && (int)$usage['total_tokens']===120 && $usage['service_status']==='completed'
    && count($GLOBALS['imageUsage'])===1, 'actual provider metering preserves the pinned model, per-user attribution, background classification and exact reported tokens');
canvas_pipeline_check(!vp3_coding_canvas_execute_v1($pdo,$owner,$codingClaim)['ok'] && count($GLOBALS['pipelineCodingCalls'])===1,
    'a settled coding lease cannot repeat the paid provider transport');

$setting=vp3_initiative_canvas_setting_v1($pdo,$owner,7);
vp3_initiative_canvas_setting_v1($pdo,$owner,7,true,$setting['revision']);
$autoRun=canvas_pipeline_prepare(2001,false);
$beforeCoding=canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'");
$observer=new PDO($dsn,getenv('VP3_IMAGE_TEST_USER') ?: null,getenv('VP3_IMAGE_TEST_PASSWORD') ?: null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->beforeReceipt=static function(array $params) use ($autoRun,$beforeCoding,$observer,$pdo): void {
    if ((int)$params[0]!==$autoRun) return;
    $pdo->beforeReceipt=null;
    canvas_pipeline_check($pdo->inTransaction() && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===$beforeCoding+1
        && (int)$observer->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$beforeCoding,
        'the coding request exists only inside the parent receipt transaction until commit');
};
$GLOBALS['pipelineModel']=canvas_pipeline_model(2001);
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($autoRun))['ok'], 'opt-in Auto Build admission commits with the completed Initiative receipt');
$autoResult=canvas_pipeline_result($autoRun);$autoCode=(int)$autoResult['initiative_tool_result']['output']['coding_run_id'];
canvas_pipeline_check((int)$observer->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$beforeCoding+1,
    'another connection can observe the coding request after the canonical parent receipt commits');
canvas_pipeline_check(vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($autoCode))['ok'], 'an opted-in proactive request uses the same coding executor and private store');

$rollbackRun=canvas_pipeline_prepare(3001,false);$beforeCoding=canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'");
$beforeMessages=canvas_pipeline_count('chat_messages');
$pdo->beforeReceipt=static function(array $params) use ($rollbackRun,$beforeCoding,$observer,$pdo): void {
    if ((int)$params[0]!==$rollbackRun) return;
    $pdo->beforeReceipt=null;
    canvas_pipeline_check(canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===$beforeCoding+1
        && (int)$observer->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$beforeCoding,
        'the failure injection occurs after real queue admission but before any public receipt');
    throw new RuntimeException('Injected receipt persistence failure.');
};
$GLOBALS['pipelineModel']=canvas_pipeline_model(3001);
canvas_pipeline_check(!vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($rollbackRun))['ok']
    && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===$beforeCoding
    && canvas_pipeline_count('chat_messages')===$beforeMessages, 'failure before the parent receipt rolls back the queued coding request and Chat report together');

$revokedRun=canvas_pipeline_prepare(4001);$revokedResult=canvas_pipeline_result($revokedRun);
$revokedCode=(int)$revokedResult['initiative_tool_result']['output']['coding_run_id'];$beforeDrafts=canvas_pipeline_count('vp3_coding_canvases_v1');
$GLOBALS['pipelineGenerationHook']=static fn()=>db()->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=4001');
canvas_pipeline_check(!vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($revokedCode))['ok']
    && canvas_pipeline_count('vp3_coding_canvases_v1')===$beforeDrafts, 'source revocation after a metered provider response prevents saving a new private app');
unset($GLOBALS['pipelineGenerationHook']);

$optOutRun=canvas_pipeline_prepare(5001);$optOutResult=canvas_pipeline_result($optOutRun);
$optOutCode=(int)$optOutResult['initiative_tool_result']['output']['coding_run_id'];
$GLOBALS['pipelineGenerationHook']=static function() use ($owner): void {
    $setting=vp3_initiative_canvas_setting_v1(db(),$owner,7);
    vp3_initiative_canvas_setting_v1(db(),$owner,7,false,$setting['revision']);
};
canvas_pipeline_check(!vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($optOutCode))['ok']
    && !vp3_initiative_canvas_setting_v1($pdo,$owner,7)['enabled']
    && canvas_pipeline_count('vp3_coding_canvases_v1')===$beforeDrafts, 'turning off the actual Auto Build setting during generation prevents a late app save');
unset($GLOBALS['pipelineGenerationHook']);
$setting=vp3_initiative_canvas_setting_v1($pdo,$owner,7);
vp3_initiative_canvas_setting_v1($pdo,$owner,7,true,$setting['revision']);

$budgetRun=canvas_pipeline_prepare(6001);$budgetResult=canvas_pipeline_result($budgetRun);
$budgetCode=(int)$budgetResult['initiative_tool_result']['output']['coding_run_id'];$beforeCalls=count($GLOBALS['pipelineCodingCalls']);
vp3_ai_service_save_rule_v1($pdo,'user',1,'code_build',['background_daily_tokens'=>0]);
canvas_pipeline_check(!vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($budgetCode))['ok']
    && count($GLOBALS['pipelineCodingCalls'])===$beforeCalls && canvas_pipeline_count('vp3_coding_canvases_v1')===$beforeDrafts,
    'an exhausted background allowance blocks proactive coding before a physical provider request');
canvas_pipeline_check(canvas_pipeline_count('ai_execution_ledger',"service_key='code_build' AND service_status='completed' AND total_tokens=120")===count($GLOBALS['pipelineCodingCalls'])
    && count($GLOBALS['imageUsage'])===count($GLOBALS['pipelineCodingCalls']),
    'late source or opt-in revocation preserves exact usage for completed physical provider attempts without charging a retry');

echo 'INITIATIVE_CANVAS_PIPELINE=PASS ('.$checks.' assertions, '.($mysql ? 'mysql' : 'sqlite').")\n";
