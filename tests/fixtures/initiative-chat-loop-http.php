<?php
declare(strict_types=1);

// Infrastructure adapters only: disposable SQLite, signed-in fixture account,
// Calendar availability and deterministic provider output. The HTTP controller,
// capability inventory, source readers, planner, real Brain tool, claimant,
// workflow receipts, saved Chat delivery and unread receipts are production.
require_once __DIR__.'/image-generation-v1.php';
$source=dirname(__DIR__,2);
require_once $source.'/includes/agent-memory-scope-v410.php';
require_once $source.'/includes/agent-brain-v122.php';
require_once $source.'/includes/agent-brain-owner-context-v1.php';
require_once $source.'/includes/agent-activity-v94.php';
require_once $source.'/includes/agent-initiative-runtime-v1.php';
require_once $source.'/includes/agent-chat-read-v1.php';
require_once $source.'/includes/cognitive-runtime-v500.php';
require_once $source.'/includes/agent-scheduling-v430.php';
require_once $source.'/includes/user-calendar-v1300.php';
require_once $source.'/includes/agent-reminders-v1.php';

class InitiativeHttpPDO extends ImageFixturePDO
{
    public function prepare(string $sql,array $options=[]):PDOStatement|false
    {
        if(str_contains($sql,'information_schema.statistics')){
            $sql="SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=? LIMIT 1";
        }
        if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
            $sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,confidence=excluded.confidence,
                last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql,$options);
    }
}
$pdo=new InitiativeHttpPDO(getenv('VP3_IMAGE_TEST_DSN'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $name)$pdo->sqliteCreateFunction($name,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$GLOBALS['initiativeBrainAccess']=true;

function agent_brain_schema_ready():bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $text):string{return strtolower(trim($text));}
function agent_brain_sentences(string $text):array{return [$text];}
function vp3_participant_scope_v1():?array{return null;}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'):array
{
    return ['route'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true],'effective_preference'=>'vp3_cloud'];
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true):array
{
    if($includeHomeServer)throw new LogicException('Background preparation cannot discover another calendar.');
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[],'current'=>null,'conflicts'=>[],'next'=>null,'starts_soon'=>false];
}
function ai_generate_chat_response(string $prompt,array $history,array $context,array $user,string $model,array $options):array
{
    $protocol=$options['protocol']??'';
    db()->prepare('INSERT INTO fixture_model_calls(protocol,prompt) VALUES(?,?)')->execute([$protocol,$prompt]);
    if(str_contains($prompt,'SYSTEM SECRET')||str_contains($prompt,'FOREIGN SECRET'))throw new RuntimeException('Private fixture evidence crossed the principal boundary.');
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'){
        $tools=array_column($input['tools'],'ready','id');
        if(empty($tools['brain.read'])||!in_array('task:100',array_column($input['evidence'],'ref'),true)){
            throw new RuntimeException('Real Brain capability and task evidence are required: '.json_encode($input['tools']));
        }
        $answer=['jobs'=>[['tool'=>'brain.read','primary'=>'task:100','refs'=>['task:100'],'focus'=>'launch','report'=>true]]];
    }elseif($protocol==='vp3.initiative-draft.v1'){
        $records=$input['tool_result']['records']??[];
        if(count($records)!==1||$records[0]['source']!=='brain:100')throw new RuntimeException('The real named-Agent Brain tool must retrieve its exact stored record.');
        $answer=['draft'=>'Verified background Brain analysis. '.str_repeat('The launch needs a product image and a reviewed promotion plan before it is ready. ',9)];
    }else throw new RuntimeException('Unexpected model protocol.');
    return ['ok'=>true,'answer'=>json_encode($answer,JSON_THROW_ON_ERROR)];
}

// The large presentation projection is irrelevant to execution. Keep the real
// scope/policy projection and let the actual API attach read-only worker health.
const VP3_COGNITIVE_PRESENTATION_V510='initiative-http-fixture';
function vp3_cognitive_presentation_schema_ready_v510(PDO $pdo):bool{return true;}
function vp3_cognitive_presentation_state_v510(PDO $pdo,array $user,string $namespace,int $agentId,bool $working,bool $preview,?int $cid=null):array
{
    $state=['brief'=>['agent_name'=>'Fixture Agent','active'=>true,'status_label'=>'Active']];
    if($cid!==null)$state['live_context']=['state'=>'quiet','attention'=>null,
        'initiative'=>vp3_initiative_foreground_status_v1($pdo,$user,$agentId,$cid)];
    return $state;
}
function vp3_cognitive_presentation_touch_v510(PDO $pdo,array $user,string $namespace):void{}

if(!table_exists('fixture_model_calls')){
    $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN title TEXT;
        ALTER TABLE chat_conversations ADD COLUMN updated_at TEXT;
        CREATE TABLE fixture_model_calls(id INTEGER PRIMARY KEY,protocol TEXT,prompt TEXT);
        CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,subject TEXT,
            memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER,source_archive_id INTEGER,confidence REAL,occurrence_count INTEGER,
            first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,UNIQUE(user_id,memory_hash));
        CREATE TABLE agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,created_at TEXT);
        CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type);
        CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,last_seen_at);
        CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_id,user_agent_id,created_at);
        CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
            owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
        CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
        CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,
            activity_state TEXT,last_input_at TEXT,last_task_at TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT,updated_at TEXT);
        CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,task_kind TEXT,
            task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT);
        DELETE FROM agent_workflow_runs;DELETE FROM agent_workflow_actions;
        INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
            (11,1,'user','Keep my launch follow-ups moving'),(11,NULL,'assistant','Ready to review the available work');
        INSERT INTO agent_activity_state VALUES(1,'chat','chat:11','chat','Launch follow-up','paused',NOW(),NOW(),NOW(),NULL,
            '{\"visible\":true,\"conversation_id\":11}',NOW());
        INSERT INTO agent_memory_items VALUES
            (100,1,7,'task','Launch follow-up','The launch needs a product image and a reviewed promotion plan.','task-100',410,NULL,1,1,NOW(),NOW(),1,'{\"task_status\":\"open\",\"due_at\":\"2020-01-01T00:00:00Z\"}'),
            (200,1,NULL,'note','Launch SYSTEM SECRET','SYSTEM SECRET must never reach this named Agent.','secret-200',410,NULL,1,1,NOW(),NOW(),1,'{}'),
            (300,2,9,'note','Launch FOREIGN SECRET','FOREIGN SECRET must never reach this owner.','secret-300',410,NULL,1,1,NOW(),NOW(),1,'{}')");
    vp3_chat_read_ensure_schema_v1($pdo);
}

function initiative_http_chat_v1():never
{
    header('Content-Type: application/json');
    $body=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    $user=current_user();$aid=max(0,(int)($_GET['agent']??0));$cid=max(0,(int)($body['conversation_id']??11));
    $agent=vp3_agent_chat_resolve_agent_v380(db(),$user,$aid);
    if(!hash_equals(vp3_catch_up_page_binding_v1($user),(string)($_SERVER['HTTP_X_VP3_WORKING_ATTENTION']??''))
        ||!hash_equals(csrf_token(),(string)($body['csrf_token']??$body['csrf']??''))){
        http_response_code(403);echo '{"ok":false,"error":"fixture_binding"}';exit;
    }
    if(!vp3_agent_chat_conversation_v380(db(),$cid,(int)$user['id'],$agent)){
        http_response_code(404);echo '{"ok":false,"error":"conversation_not_found"}';exit;
    }
    $messages=[];
    if(in_array($body['action']??'', ['load','messages_after'],true)){
        $q=db()->prepare('SELECT * FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id');
        $q->execute([$cid,($body['action']??'')==='messages_after'?(int)($body['after_id']??0):0]);$messages=$q->fetchAll();
    }
    $latest=db()->query('SELECT MAX(id) FROM chat_messages WHERE conversation_id=11')->fetchColumn();
    echo json_encode(['ok'=>true,'messages'=>$messages,'conversations'=>[['id'=>11,'title'=>'Launch follow-ups']],
        'conversation_id'=>$cid,'latest_message_id'=>(int)$latest,'latest_id'=>0,'updates'=>[],'unread_count'=>0]);exit;
}

function initiative_http_state_v1():never
{
    header('Content-Type: application/json');
    $coverage=[];
    $evidence=vp3_initiative_evidence_v1(db(),current_user(),new DateTimeImmutable('now',new DateTimeZone('UTC')),false,[],$coverage,null,7);
    $taskFailure='';
    try{vp3_catch_up_tasks_v1(db(),current_user(),vp3_agent_chat_principal_v380(current_user(),vp3_agent_chat_resolve_agent_v380(db(),current_user(),7)),new DateTimeImmutable('now',new DateTimeZone('UTC')));}
    catch(Throwable $e){$taskFailure=$e->getMessage();}
    $state=['policy'=>vp3_initiative_policy_v1(current_user(),7),
        'task_failure'=>$taskFailure,'coverage'=>$coverage,'evidence'=>$evidence,'memory_ready'=>vp3_agent_memory_scope_schema_ready_v410(db()),
        'reports'=>db()->query("SELECT * FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL")->fetchAll(),
        'runs'=>db()->query('SELECT id,agent_id,status,goal FROM agent_workflow_runs ORDER BY id')->fetchAll(),
        'receipts'=>db()->query('SELECT * FROM agent_workflow_receipts ORDER BY id')->fetchAll(),
        'model_calls'=>db()->query('SELECT * FROM fixture_model_calls ORDER BY id')->fetchAll()];
    echo json_encode($state,JSON_THROW_ON_ERROR);exit;
}
