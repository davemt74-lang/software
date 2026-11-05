<?php
declare(strict_types=1);

// Adds disposable database/provider transport to capability-chat-bootstrap.php.
// The three HTTP controllers, principal checks, Initiative readers, workflow
// claimant, executor, receipts and saved Chat delivery remain production code.
require_once __DIR__.'/agent-initiative-runtime-v1.php';
require_once __DIR__.'/agent-worker-cloud-v1910.php';
require_once __DIR__.'/agent-activity-v94.php';

function followthrough_fixture_schema_v1(): void
{
    $pdo=db();
    if(table_exists('followthrough_fixture_calls'))return;
    // MySQL autocommit/buffered reads do not block another request's bootstrap.
    // WAL gives the disposable SQLite transport the same concurrent access.
    $pdo->exec('PRAGMA journal_mode=WAL');
    workflow_agent_fixture_v1($pdo);
    foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $column){
        $name=explode(' ',$column)[0];if(!column_exists('agent_workflow_actions',$name))$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$column);
    }
    foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $column){
        $name=explode(' ',$column)[0];if(!column_exists('agent_workflow_action_dependencies',$name))$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$column);
    }
    foreach(['receipt_key TEXT','result_json TEXT'] as $column){
        $name=explode(' ',$column)[0];if(!column_exists('agent_workflow_receipts',$name))$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$column);
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS followthrough_receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key);
        CREATE TABLE IF NOT EXISTS agent_chat_archive(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,created_at TEXT);
        CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_type_v410 ON agent_memory_items(user_id,user_agent_id,memory_type);
        CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_id,user_agent_id,last_seen_at);
        CREATE INDEX IF NOT EXISTS idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_id,user_agent_id,created_at);
        CREATE TABLE IF NOT EXISTS agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,task_kind TEXT,task_title TEXT,
          activity_state TEXT,last_input_at TEXT,last_task_at TEXT,last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT,updated_at TEXT);
        CREATE TABLE IF NOT EXISTS agent_activity_events(id INTEGER PRIMARY KEY,user_id INTEGER,surface TEXT,context_key TEXT,task_kind TEXT,
          task_title TEXT,previous_state TEXT,activity_state TEXT,reason TEXT,details_json TEXT,created_at TEXT);
        CREATE TABLE IF NOT EXISTS studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER,
          recognition_consent INTEGER,cloning_consent INTEGER,linked_user_id INTEGER,relationship_scope TEXT);
        INSERT OR IGNORE INTO studio_participants VALUES(101,1,'Owner self',1,1,0,1,'self'),(102,1,'Guest participant',1,1,0,NULL,'guest');
        CREATE TABLE followthrough_fixture_calls(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,payload TEXT)");
    vp3_participant_updates_schema_v1($pdo);
}

function followthrough_fixture_log_v1(string $kind,array $payload): void
{
    db()->prepare('INSERT INTO followthrough_fixture_calls(kind,payload) VALUES(?,?)')->execute([$kind,json_encode($payload,JSON_THROW_ON_ERROR)]);
}

function followthrough_fixture_participants_v1(int $self=101): array
{
    $pdo=db();$user=['id'=>1,'is_active'=>1];$principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
    return vp3_participant_configure_v1($pdo,$user,$principal,['enabled'=>true,'self_participant_id'=>$self,'offer_updates'=>false,
        'participant_id'=>102,'grants'=>[],'revision'=>vp3_participant_settings_v1($pdo,$user)['revision']]);
}

function followthrough_fixture_provider_gate_v1(string $phase='ack'): void
{
    $token=(string)($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_GATE']??'');if($token==='')return;
    if((string)($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_GATE_PHASE']??'ack')!==$phase)return;
    if(!preg_match('/\A[a-z0-9-]{1,80}\z/D',$token))throw new RuntimeException('Invalid provider barrier.');
    if(db()->inTransaction())throw new RuntimeException('Provider barrier held a database transaction.');
    $path=dirname(__DIR__).'/provider-gate-'.$token;file_put_contents($path.'.entered',(string)getmypid());
    $deadline=microtime(true)+20;
    while(!is_file($path.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('Provider barrier timed out.');usleep(10000);}
}

function followthrough_fixture_before_user_gate_v1(): void
{
    $calls=array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,10),'function');
    $anchor=in_array('vp3_initiative_followthrough_anchor_store_v1',$calls,true);
    $target=$anchor?'anchor':'user';$header=$anchor?'HTTP_X_FIXTURE_FOLLOWTHROUGH_BEFORE_ANCHOR_GATE':'HTTP_X_FIXTURE_FOLLOWTHROUGH_BEFORE_USER_GATE';
    $token=(string)($_SERVER[$header]??'');if($token===''||!empty($GLOBALS['followthroughBeforeGateEntered'][$target]))return;
    if(!$anchor&&!in_array('vp3_agent_chat_followthrough_save_user_v2160',$calls,true))return;
    if(!preg_match('/\A[a-z0-9-]{1,80}\z/D',$token)||db()->inTransaction())throw new RuntimeException('Invalid pre-insert fixture barrier.');
    $GLOBALS['followthroughBeforeGateEntered'][$target]=true;$path=dirname(__DIR__).'/before-'.$target.'-gate-'.$token;
    file_put_contents($path.'.entered',(string)getmypid());$deadline=microtime(true)+20;
    while(!is_file($path.'.release')){if(microtime(true)>$deadline)throw new RuntimeException('Pre-insert fixture barrier timed out.');usleep(10000);}
}

function calendar_schedule_awareness_snapshot_v1330(...$args): array
{
    // No calendar sensing or remote connector is needed for the real saved task.
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>[],'current'=>null,'conflicts'=>[],'next'=>null,'starts_soon'=>false];
}

function chat_remote_answer(string $query,array $history,array $context,array $user,?array $control=null): ?string
{
    followthrough_fixture_log_v1('ordinary_agent_chat',['query'=>$query,'owner'=>$user['id']]);
    throw new RuntimeException('An associated reply reached the ordinary agent.chat path.');
}

function ai_generate_chat_response($prompt,...$args): array
{
    $options=is_array($args[4]??null)?$args[4]:[];
    $protocol=(string)($options['protocol']??'');
    if(str_starts_with($protocol,'vp3.initiative-')){
        followthrough_fixture_log_v1('initiative_model',['protocol'=>$protocol,'prompt'=>$prompt]);
        if($protocol==='vp3.initiative-reply.v1')followthrough_fixture_provider_gate_v1('intent');
        if($protocol==='vp3.initiative-reply.v1'&&isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_PARTICIPANT_CHANGE'])){
            if(db()->inTransaction())throw new RuntimeException('Participant inference race held a database transaction.');
            $change=(string)$_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_PARTICIPANT_CHANGE'];
            if($change==='enable')followthrough_fixture_participants_v1();
            elseif($change==='self')followthrough_fixture_participants_v1(102);
            elseif($change==='deactivate')db()->exec('UPDATE studio_participants SET is_active=0 WHERE owner_user_id=1 AND id=101');
            else throw new RuntimeException('Unknown participant inference race.');
        }
        if($protocol==='vp3.initiative-reply.v1'&&isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_INTENT_FAIL']))return ['ok'=>false,'error'=>'Controlled intent provider failure.'];
        if($protocol==='vp3.initiative-reply.v1')return ['ok'=>true,'answer'=>json_encode([
            'intent'=>(string)($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_INTENT']??'discuss'),
            'focus'=>'Review the launch task using the same verified private sources.',
        ],JSON_THROW_ON_ERROR)];
        if($protocol==='vp3.initiative-plan.v1')return ['ok'=>true,'answer'=>json_encode(['jobs'=>[['tool'=>'brain.read','primary'=>'task:100','refs'=>['task:100'],'focus'=>'launch','report'=>true]]],JSON_THROW_ON_ERROR)];
        if($protocol==='vp3.initiative-draft.v1'){
            $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
            if(($input['response_kind']??'')==='followthrough_reply'){
                followthrough_fixture_log_v1('main_model',['query'=>$input['request']??'','owner'=>$args[2]['id']??0,'context'=>$input]);
                followthrough_fixture_provider_gate_v1();
                if(isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_COMPLETE'])&&($input['verified_followthrough']['proposal']['state']??'')==='queued'){
                    $run=(int)($input['verified_followthrough']['proposal']['continuation_run_id']??0);
                    $completed=vp3_initiative_execute_v1(db(),['id'=>1,'is_active'=>1],followthrough_fixture_claim_v1($run),
                        static fn(string $prompt,string $protocol): string=>json_encode(['draft'=>'Verified continuation completed during the main acknowledgement. The launch still needs its image and reviewed promotion plan.'],JSON_THROW_ON_ERROR));
                    if(empty($completed['ok']))throw new RuntimeException('The concurrent canonical continuation did not complete.');
                }
                if(isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_CHANGE']))$GLOBALS['followthroughFixtureRace']=true;
                if(!empty($GLOBALS['followthroughFixtureRace']))db()->exec("UPDATE agent_memory_items SET memory_text='Changed while the main model reasoned',last_seen_at=NOW() WHERE id=100");
                if(isset($_SERVER['HTTP_X_FIXTURE_FOLLOWTHROUGH_MODEL_FAIL']))return ['ok'=>false,'error'=>'Controlled main model failure.'];
                $draft=($input['verified_followthrough']['intent']??'')==='none'
                    ?'Which next step would you like to discuss for the verified launch task?'
                    :(!empty($input['verified_followthrough']['verified_private_completion'])
                        ?'The verified private continuation is complete. It confirms the launch still needs its image and reviewed promotion plan.'
                        :'The verified launch task still needs a product image and reviewed promotion plan. Let us work through your next step.');
                return ['ok'=>true,'answer'=>json_encode(['draft'=>$draft],JSON_THROW_ON_ERROR)];
            }
            return ['ok'=>true,'answer'=>json_encode(['draft'=>'I verified the saved launch task: it needs a product image and a reviewed promotion plan.'],JSON_THROW_ON_ERROR)];
        }
    }
    return capability_fixture_ai_generate_chat_response($prompt,...$args);
}

function followthrough_fixture_claim_v1(int $id): array
{
    $user=['id'=>1,'is_active'=>1];
    return agent_job_claim_run_v1900(db(),$user,$id,'cloud',agent_worker_cloud_worker_id_v1910(1))
        ?:throw new RuntimeException('The canonical claimant did not acquire this fixture run.');
}

function followthrough_fixture_seed_v1(): array
{
    $pdo=db();$user=['id'=>1,'is_active'=>1];
    foreach(['agent_workflow_receipts','agent_workflow_action_dependencies','agent_workflow_events','agent_workflow_actions','agent_workflow_runs',
        'chat_messages','chat_conversations','agent_memory_items','agent_activity_state','agent_activity_events','user_agent_data_rules','user_data_policies','followthrough_fixture_calls'] as $table)$pdo->exec('DELETE FROM '.$table);
    foreach(['agent_task_list_items_v1','agent_task_list_actions_v1','agent_task_lists_v1'] as $table)if(table_exists($table))$pdo->exec('DELETE FROM '.$table);
    foreach(['agent_participant_grants_v1','agent_participant_presence_v1','agent_participant_observers_v1','agent_participant_settings_v1'] as $table)$pdo->exec('DELETE FROM '.$table);
    $pdo->exec('UPDATE studio_participants SET is_active=1,recognition_consent=1 WHERE owner_user_id=1');
    $pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title,updated_at) VALUES
        (11,1,7,'Launch follow-ups',NOW()),(12,1,8,'Sibling Agent Chat',NOW()),(13,2,9,'Foreign owner Chat',NOW()),(14,1,7,'Other launch Chat',NOW());
        INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES
        (11,1,'user','Keep my launch follow-ups moving','2000-01-01 00:00:00'),(11,NULL,'assistant','I will review the available private work.','2000-01-01 00:00:00'),
        (12,NULL,'assistant','SIBLING CHAT SECRET','2000-01-01 00:00:00'),(13,NULL,'assistant','FOREIGN CHAT SECRET','2000-01-01 00:00:00'),
        (14,NULL,'assistant','OTHER CHAT PRIVATE SECRET','2000-01-01 00:00:00');
        INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,confidence,
          occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json) VALUES
        (100,1,7,'task','Launch follow-up','The launch needs a product image and a reviewed promotion plan.','followthrough-task-100',410,1,1,NOW(),NOW(),1,'{\"task_status\":\"open\",\"due_at\":\"2020-01-01T00:00:00Z\"}'),
        (200,1,8,'task','SIBLING SECRET','SIBLING SECRET cannot enter this Agent.','followthrough-task-200',410,1,1,NOW(),NOW(),1,'{\"task_status\":\"open\"}'),
        (300,2,9,'task','FOREIGN SECRET','FOREIGN SECRET cannot enter this owner.','followthrough-task-300',410,1,1,NOW(),NOW(),1,'{\"task_status\":\"open\"}');
        INSERT INTO agent_activity_state VALUES(1,'chat','chat:11','chat','Launch follow-up','paused',NOW(),NOW(),NOW(),NULL,
          '{\"visible\":true,\"conversation_id\":11,\"chat_focused\":true,\"chat_busy\":false}',NOW())");
    $principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
    $enabled=vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>11],'on',null);
    if(empty($enabled['ok']))throw new RuntimeException('Canonical policy opt-in failed.');
    vp3_initiative_schedule_v1($pdo,$user,7);
    $plan=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE capability_key='initiative.plan'")->fetchColumn();
    $provider=static function(string $prompt,string $protocol): string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        if(str_contains($prompt,'SECRET'))throw new RuntimeException('The named fixture crossed an ownership boundary.');
        if($protocol==='vp3.initiative-plan.v1'){
            if(!isset($input['evidence']['task:100'])&&!in_array('task:100',array_column($input['evidence'],'ref'),true))throw new RuntimeException('Canonical task evidence missing.');
            return json_encode(['jobs'=>[['tool'=>'brain.read','primary'=>'task:100','refs'=>['task:100'],'focus'=>'launch','report'=>true]]],JSON_THROW_ON_ERROR);
        }
        if(($input['tool_result']['records'][0]['source']??'')!=='brain:100')throw new RuntimeException('Canonical Brain did not supply the actual saved task.');
        return json_encode(['draft'=>'I verified the saved launch task: it needs a product image and a reviewed promotion plan.'],JSON_THROW_ON_ERROR);
    };
    $planned=vp3_initiative_execute_v1($pdo,$user,followthrough_fixture_claim_v1($plan),$provider);
    if(empty($planned['ok']))throw new RuntimeException('Canonical planning failed: '.json_encode($planned));
    $prepare=(int)$pdo->query("SELECT MAX(id) FROM agent_workflow_runs WHERE capability_key='initiative.prepare'")->fetchColumn();
    $prepared=vp3_initiative_execute_v1($pdo,$user,followthrough_fixture_claim_v1($prepare),$provider);
    if(empty($prepared['ok']))throw new RuntimeException('Canonical preparation failed: '.json_encode($prepared));
    return followthrough_fixture_state_v1();
}

function followthrough_fixture_state_v1(): array
{
    $pdo=db();
    return ['messages'=>$pdo->query('SELECT * FROM chat_messages ORDER BY id')->fetchAll(),
        'runs'=>$pdo->query('SELECT * FROM agent_workflow_runs ORDER BY id')->fetchAll(),
        'actions'=>$pdo->query('SELECT * FROM agent_workflow_actions ORDER BY id')->fetchAll(),
        'receipts'=>$pdo->query('SELECT * FROM agent_workflow_receipts ORDER BY id')->fetchAll(),
        'events'=>$pdo->query('SELECT * FROM agent_workflow_events ORDER BY id')->fetchAll(),
        'calls'=>$pdo->query('SELECT * FROM followthrough_fixture_calls ORDER BY id')->fetchAll(),
        'list_actions'=>$pdo->query('SELECT * FROM agent_task_list_actions_v1 ORDER BY token')->fetchAll(),
        'lists'=>$pdo->query('SELECT * FROM agent_task_lists_v1 ORDER BY id')->fetchAll(),
        'task'=>$pdo->query('SELECT * FROM agent_memory_items WHERE id=100')->fetch(),
        'reminders'=>(int)$pdo->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn(),
        'notifications_exists'=>table_exists('notifications'),
        'policy'=>vp3_initiative_policy_v1(['id'=>1,'is_active'=>1],7)];
}

function followthrough_fixture_control_v1(): never
{
    header('Content-Type: application/json');
    try{
        $pdo=db();$action=(string)($_GET['action']??'state');
        if($action==='seed')$state=followthrough_fixture_seed_v1();
        elseif($action==='source_change'){$pdo->exec("UPDATE agent_memory_items SET memory_text='The saved source has changed',last_seen_at=NOW() WHERE id=100");$state=followthrough_fixture_state_v1();}
        elseif($action==='source_revoke'){$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','brain:100','deny')");$state=followthrough_fixture_state_v1();}
        elseif($action==='participants_on'){
            $settings=followthrough_fixture_participants_v1();
            $state=followthrough_fixture_state_v1()+['participant_settings'=>$settings];
        }
        elseif($action==='policy_change'){
            $user=['id'=>1,'is_active'=>1];$principal=vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380($pdo,$user,7));
            vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>11],'off',null);$state=followthrough_fixture_state_v1();
        }elseif($action==='state_change'){
            $q=$pdo->query("SELECT id,context_json FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL ORDER BY id LIMIT 1");$row=$q->fetch();
            $context=json_decode($row['context_json'],true,32,JSON_THROW_ON_ERROR);
            $context['initiative_followthrough_state']['revision']++;
            $context['initiative_work']['followthrough']['revision']=$context['initiative_followthrough_state']['revision'];
            $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($context,JSON_THROW_ON_ERROR),$row['id']]);
            $state=followthrough_fixture_state_v1();
        }elseif($action==='clone_post'){
            $pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
                SELECT conversation_id,user_id,role,message,context_json,NOW() FROM chat_messages
                WHERE role='assistant' AND context_json IS NOT NULL ORDER BY id LIMIT 1");
            $id=(int)$pdo->lastInsertId();$state=followthrough_fixture_state_v1()+['forged_post_id'=>$id];
        }elseif($action==='execute'){
            $id=(int)($_GET['id']??0);$fail=($_GET['fail']??'')==='1';
            $result=vp3_initiative_execute_v1($pdo,['id'=>1,'is_active'=>1],followthrough_fixture_claim_v1($id),
                static fn(string $prompt,string $protocol): string=>$fail?'invalid-controlled-provider-output':json_encode(['draft'=>'Verified continuation: the launch task still requires a product image and a reviewed promotion plan.'],JSON_THROW_ON_ERROR));
            $state=followthrough_fixture_state_v1()+['execution_result'=>$result];
        }elseif($action==='deliver'){
            $delivered=vp3_initiative_deliver_pending_v1($pdo,['id'=>1,'is_active'=>1],7);
            $state=followthrough_fixture_state_v1()+['delivered'=>$delivered];
        }else $state=followthrough_fixture_state_v1();
        echo json_encode(['ok'=>true]+$state,JSON_THROW_ON_ERROR);
    }catch(Throwable $error){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'at'=>basename($error->getFile()).':'.$error->getLine()]);}
    exit;
}

followthrough_fixture_schema_v1();
