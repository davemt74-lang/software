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

$transcriptScenario=getenv('VP3_INITIATIVE_HTTP_TRANSCRIPT')==='1';
$gmailScenario=getenv('VP3_INITIATIVE_HTTP_GMAIL')==='1';
$officeScenario=(string)getenv('VP3_INITIATIVE_HTTP_OFFICE');
if($transcriptScenario){
    require_once __DIR__.'/initiative-transcript-chat-http.php';
    require_once $source.'/includes/knowledge.php';
}
if($gmailScenario)require_once __DIR__.'/initiative-gmail-chat-http.php';
if($officeScenario!=='')require_once __DIR__.'/initiative-office-chat-http.php';
$freshGmailScenario=$gmailScenario&&initiative_http_gmail_fresh_v1();
if($freshGmailScenario){
    if(!defined('STONEFELLOW_ROOT'))define('STONEFELLOW_ROOT',$source);
    require_once $source.'/includes/agent-brain-v82.php';
    require_once $source.'/includes/agent-chat-runtime-v2160.php';
    require_once $source.'/includes/ai-usage-accounting-v032.php';
    function ai_v100_with_service_context(array $context,callable $call):mixed{return $call();}
}

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
        if(str_contains($sql,'INSERT INTO agent_activity_state')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT(user_id) DO UPDATE SET',$sql);
            $sql=preg_replace('/VALUES\((\w+)\)/','excluded.$1',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
$pdo=new InitiativeHttpPDO(getenv('VP3_IMAGE_TEST_DSN'),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $name)$pdo->sqliteCreateFunction($name,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$GLOBALS['initiativeBrainAccess']=true;

if(!function_exists('agent_brain_schema_ready')){function agent_brain_schema_ready():bool{return table_exists('agent_memory_items');}}
if(!function_exists('agent_brain_normalize')){function agent_brain_normalize(string $text):string{return strtolower(trim($text));}}
if(!function_exists('agent_brain_sentences')){function agent_brain_sentences(string $text):array{return [$text];}}
function agent_runtime_v125_trace(string $event,array $data=[]):void{}
function agent_runtime_v125_trace_id():string{return 'initiative-http-fixture';}
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'):array
{
    if(getenv('VP3_INITIATIVE_HTTP_OFFICE')==='task'
        &&$pdo->query('SELECT scenario FROM fixture_controls WHERE id=1')->fetchColumn()==='office_saved_only'){
        return ['route'=>'none','blocked'=>true,'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],
            'effective_preference'=>'vp3_cloud'];
    }
    return ['route'=>'vp3_cloud','blocked'=>false,'cloud'=>['ready'=>true],'effective_preference'=>'vp3_cloud'];
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true):array
{
    if($includeHomeServer)throw new LogicException('Background preparation cannot discover another calendar.');
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[],'current'=>null,'conflicts'=>[],'next'=>null,'starts_soon'=>false];
}
// Read-only HTTP consumers can load the actual provider configuration first;
// Chat execution journeys retain the deterministic transport below.
if(!function_exists('ai_generate_chat_response')){
function ai_generate_chat_response(string $prompt,array $history,array $context,array $user,string $model,array $options):array
{
    $protocol=$options['protocol']??'';
    db()->prepare('INSERT INTO fixture_model_calls(protocol,prompt) VALUES(?,?)')->execute([$protocol,$prompt]);
    if(str_contains($prompt,'SYSTEM SECRET')||str_contains($prompt,'FOREIGN SECRET'))throw new RuntimeException('Private fixture evidence crossed the principal boundary.');
    $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    if($protocol==='vp3.initiative-plan.v1'&&($input['role']??null)==='brainstorm'){
        // Independent creative work returns ideas privately; this host fixture
        // supplies no additional idea and must never impersonate the OB1 planner.
        return ['ok'=>true,'answer'=>'{"jobs":[],"opportunities":[]}'];
    }
    if(getenv('VP3_INITIATIVE_HTTP_GMAIL_FRESH')==='1'){
        return ['ok'=>true,'answer'=>json_encode(initiative_http_gmail_model_v1($protocol,$input),JSON_THROW_ON_ERROR)];
    }
    $officeScenario=(string)getenv('VP3_INITIATIVE_HTTP_OFFICE');
    if($officeScenario!==''){
        return ['ok'=>true,'answer'=>json_encode(initiative_http_office_model_v1($officeScenario,$protocol,$input),JSON_THROW_ON_ERROR)];
    }
    if(($input['response_kind']??null)==='office_entry_review'){
        // The selected Chat handoff and the routine per-entry review are independent.
        $record=$input['tool_result']['records'][0]['title']??'';
        return ['ok'=>true,'answer'=>json_encode(['office_disposition'=>'review','draft'=>'I reviewed '.$record.'.','owner_attention'=>false],JSON_THROW_ON_ERROR)];
    }
    if(getenv('VP3_INITIATIVE_HTTP_TRANSCRIPT')==='1'){
        return ['ok'=>true,'answer'=>json_encode(initiative_http_transcript_model_v1($protocol,$input,$prompt),JSON_THROW_ON_ERROR)];
    }
    if(getenv('VP3_INITIATIVE_HTTP_GMAIL')==='1'&&$protocol==='vp3.initiative-plan.v1'){
        return ['ok'=>true,'answer'=>'{"jobs":[],"opportunities":[]}'];
    }
    if(getenv('VP3_INITIATIVE_HTTP_MEDIA')==='1'&&$protocol==='vp3.initiative-plan.v1'){
        // Media offers use the real saved-source coordinator. This scenario
        // has no additional model-selected Brain task to prepare.
        return ['ok'=>true,'answer'=>'{"jobs":[],"opportunities":[]}'];
    }
    $checkIn=db()->query('SELECT scenario FROM fixture_controls WHERE id=1')->fetchColumn()==='check_in';
    if($protocol==='vp3.initiative-plan.v1'){
        $tools=array_column($input['tools'],'ready','id');
        if(empty($tools['brain.read'])||!in_array('task:100',array_column($input['evidence'],'ref'),true)){
            throw new RuntimeException('Real Brain capability and task evidence are required: '.json_encode($input['tools']));
        }
        $answer=['jobs'=>[['tool'=>$checkIn?'chat.check_in':'brain.read','primary'=>'task:100','refs'=>['task:100'],'focus'=>'launch','report'=>true]]];
    }elseif($protocol==='vp3.initiative-draft.v1'){
        if($checkIn){
            if(($input['response_kind']??'')!=='chat.check_in'||empty($input['recent_user_messages']))throw new RuntimeException('The actual check-in must use exact saved owner continuity.');
            $answer=['draft'=>'A check-in based on the earlier owner conversation.'];
        }else{
            $records=$input['tool_result']['records']??[];
            if(count($records)!==1||$records[0]['source']!=='brain:100')throw new RuntimeException('The real named-Agent Brain tool must retrieve its exact stored record.');
            $answer=['draft'=>'Verified background Brain analysis. '.str_repeat('The launch needs a product image and a reviewed promotion plan before it is ready. ',9)];
        }
    }else throw new RuntimeException('Unexpected model protocol.');
    return ['ok'=>true,'answer'=>json_encode($answer,JSON_THROW_ON_ERROR)];
}
}

// The large presentation projection is irrelevant to execution. Keep the real
// scope/policy projection and let the actual API attach read-only worker health.
const VP3_COGNITIVE_PRESENTATION_V510='initiative-http-fixture';
function vp3_cognitive_presentation_schema_ready_v510(PDO $pdo):bool{return true;}
function vp3_cognitive_presentation_state_v510(PDO $pdo,array $user,string $namespace,int $agentId,bool $working,bool $preview,?int $cid=null):array
{
    $gate=getenv('VP3_INITIATIVE_FIXTURE_GATE')?:'';
    if($gate!==''&&($_SERVER['HTTP_X_FIXTURE_HOLD_PROJECTION']??'')==='1'){
        file_put_contents($gate.'.entered','1');$deadline=microtime(true)+10;
        while(!is_file($gate.'.release')){
            if(microtime(true)>$deadline)throw new RuntimeException('Fixture projection gate timed out.');
            usleep(10000);
        }
    }
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
        CREATE TABLE fixture_controls(id INTEGER PRIMARY KEY,scenario TEXT);
        INSERT INTO fixture_controls VALUES(1,'brain');
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
    if (!$transcriptScenario && !$gmailScenario && $officeScenario === '') {
        // Keep the Brain test's evidence available without starting a competing Tasks conversation.
        require_once dirname(__DIR__,2).'/includes/agent-coordination-rules-v1.php';
        $current = vp3_coordination_rule_settings_v1($pdo,current_user(),7,11);
        vp3_coordination_rule_settings_v1($pdo,current_user(),7,11,$current['revision'],['tasks'=>['overdue'=>'watch']]);
    }
    if($transcriptScenario)initiative_http_seed_transcript_v1($pdo);
    if($gmailScenario)initiative_http_seed_gmail_v1($pdo);
    if($officeScenario!=='')initiative_http_seed_office_v1($pdo,$officeScenario);
    if(getenv('VP3_INITIATIVE_HTTP_BROWSER')==='1'){
        // These journeys begin after the daily brief and keep unrelated
        // conversations in Top of Mind so each exact worker result is observable.
        require_once $source.'/includes/agent-morning-brief-v1.php';
        require_once $source.'/includes/agent-coordination-rules-v1.php';
        $user=current_user();
        $day=vp3_morning_brief_local_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
        vp3_initiative_in_scope_v1($user,7,static fn()=>agent_brain_v122_upsert_system_memory(
            $user,'initiative_sweep','morning-brief:'.$day,'Prior daily brief fixture assessment',['local_day'=>$day]));
        $rules=vp3_coordination_rule_settings_v1($pdo,$user,7,11);
        $patch=['curiosity'=>['open_question'=>'watch']];
        if($transcriptScenario)$patch+=['tasks'=>['overdue'=>'watch'],'transcripts'=>['open_question'=>'watch']];
        vp3_coordination_rule_settings_v1($pdo,$user,7,11,$rules['revision'],$patch);
    }
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
    if(($body['action']??'')==='send'){
        $q=db()->prepare('INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,?,?)');
        $q->execute([$cid,(int)$user['id'],'user',(string)$body['message']]);$userMessage=(int)db()->lastInsertId();
        if(getenv('VP3_INITIATIVE_HTTP_GMAIL_FRESH')==='1'){
            $principal=vp3_agent_chat_principal_v380($user,$agent);
            $query=(string)$body['message'];
            $selection=vp3_initiative_followthrough_reply_v1(db(),$user,$principal,$cid,$userMessage,
                vp3_agent_chat_initiative_reply_v2160($body),$query);
            if(empty($selection['handled']))throw new RuntimeException('The ordinary owner reply did not bind a saved finding.');
            echo json_encode(vp3_agent_chat_followthrough_finish_v2160(db(),$user,$agent,$principal,$cid,$userMessage,$query,'text',$selection),
                JSON_THROW_ON_ERROR);exit;
        }
        $answer='I have saved your new direction.';
        $q->execute([$cid,null,'assistant',$answer]);
        echo json_encode(['ok'=>true,'conversation_id'=>$cid,'answer'=>$answer,'user_message_id'=>$userMessage,
            'assistant_message_id'=>(int)db()->lastInsertId()]);exit;
    }
    if(in_array($body['action']??'', ['load','messages_after'],true)){
        $q=db()->prepare('SELECT * FROM chat_messages WHERE conversation_id=? AND id>? ORDER BY id');
        $q->execute([$cid,($body['action']??'')==='messages_after'?(int)($body['after_id']??0):0]);$messages=$q->fetchAll();
        if(getenv('VP3_INITIATIVE_HTTP_TRANSCRIPT')==='1'){
            require_once dirname(__DIR__,2).'/includes/agent-initiative-research-canvas-v1.php';
            $messages=vp3_initiative_research_canvas_history_v1(db(),(int)$user['id'],$aid,$cid,$messages);
        }
        if(getenv('VP3_INITIATIVE_HTTP_GMAIL')==='1'){
            require_once dirname(__DIR__,2).'/includes/agent-connection-gmail-inbox-initial-v1.php';
            $messages=agent_connection_gmail_inbox_chat_history_v1(db(),$user,$aid,$cid,$messages);
        }
        if(getenv('VP3_INITIATIVE_HTTP_GMAIL_FRESH')==='1'){
            $messages=vp3_coordination_chat_history_v1(db(),$user,$aid,$cid,$messages);
        }
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
        'actions'=>db()->query('SELECT id,result_json FROM agent_workflow_actions ORDER BY id')->fetchAll(),
        'model_calls'=>db()->query('SELECT * FROM fixture_model_calls ORDER BY id')->fetchAll()];
    if(getenv('VP3_INITIATIVE_HTTP_GMAIL')==='1')$state['gmail']=initiative_http_gmail_state_v1(db(),current_user());
    $officeScenario=(string)getenv('VP3_INITIATIVE_HTTP_OFFICE');
    if($officeScenario!=='')$state['office']=initiative_http_office_state_v1(db(),$officeScenario);
    echo json_encode($state,JSON_THROW_ON_ERROR);exit;
}

function initiative_http_checkin_scenario_v1():never
{
    $body=json_decode((string)file_get_contents('php://input'),true,16,JSON_THROW_ON_ERROR);
    if($_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals(csrf_token(),(string)($body['csrf_token']??''))){http_response_code(419);exit;}
    if(isset($body['age_delivery_run_id'])){
        // Advancing Chromium timers does not advance the PHP retry clock.
        $q=db()->prepare("UPDATE agent_workflow_actions SET updated_at=? WHERE owner_user_id=1 AND run_id=?
            AND capability_key='initiative.prepare' AND status='completed'
            AND JSON_EXTRACT(result_json,'$.initiative_delivery')='pending'
            AND JSON_EXTRACT(result_json,'$.initiative_tool_result.output.type')='initiative_chat_check_in'");
        $q->execute([gmdate('Y-m-d H:i:s',time()-31),(int)$body['age_delivery_run_id']]);
        if($q->rowCount()!==1)throw new RuntimeException('The exact pending check-in retry was not aged.');
        header('Content-Type:application/json');echo '{"ok":true}';exit;
    }
    db()->exec("UPDATE fixture_controls SET scenario='check_in' WHERE id=1;
        UPDATE agent_memory_items SET memory_text='The launch now needs an owner decision about the promotion plan.' WHERE id=100");
    header('Content-Type:application/json');echo '{"ok":true}';exit;
}
