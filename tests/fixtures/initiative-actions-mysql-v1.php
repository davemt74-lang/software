<?php
declare(strict_types=1);

// Native PDO, production permissions, source readers, worker, broker and domain
// kernels. Inference is supplied explicitly by the test; it never calls a host.
$pdo=new PDO($GLOBALS['actionMysqlDsn'],$GLOBALS['actionMysqlUser'],$GLOBALS['actionMysqlPassword'],[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function db(): PDO{return $GLOBALS['pdo'];}
function setting(string $key,mixed $fallback=''): mixed{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$fallback:$v;}
function url(string $path=''): string{return $path;}
function agent_brain_schema_ready(): bool{return table_exists('agent_memory_items');}
function agent_brain_normalize(string $text): string{return mb_strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
require_once dirname(__DIR__,2).'/includes/permissions.php';
require_once dirname(__DIR__,2).'/includes/personal-capabilities-v242.php';
require_once dirname(__DIR__,2).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-worker-cloud-v1910.php';
require_once dirname(__DIR__,2).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__,2).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__,2).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__,2).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__,2).'/includes/agent-memory-lifecycle-v123.php';
require_once dirname(__DIR__,2).'/includes/agent-task-lifecycle-v123.php';
require_once dirname(__DIR__,2).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-initiative-runtime-v1.php';

function action_mysql_schema(): void
{
    $pdo=db();
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL,role VARCHAR(40) NOT NULL);
        INSERT INTO users VALUES(1,1,'artist'),(2,1,'artist');
        CREATE TABLE user_account_types(user_id INT UNSIGNED,role VARCHAR(40),assigned_explicitly_at DATETIME NULL,PRIMARY KEY(user_id,role));
        CREATE TABLE permissions(permission_key VARCHAR(190) PRIMARY KEY);
        INSERT INTO permissions VALUES('account.access'),('chat.access'),('agent_brain.access');
        CREATE TABLE role_permissions(role VARCHAR(40),permission_key VARCHAR(190),PRIMARY KEY(role,permission_key));
        INSERT INTO role_permissions VALUES('artist','account.access'),('artist','chat.access'),('artist','agent_brain.access');
        CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
        INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,is_active TINYINT,display_name VARCHAR(100));
        INSERT INTO user_agents VALUES(7,1,1,'Owner Agent'),(8,1,1,'Other Agent'),(9,2,1,'Foreign Agent');
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,title VARCHAR(190),updated_at DATETIME);
        INSERT INTO chat_conversations VALUES(10,1,7,'Agent Chat',NOW()),(11,1,8,'Other Chat',NOW()),(12,2,9,'Foreign Chat',NOW());
        CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED NULL,
            role VARCHAR(20),message TEXT,context_json MEDIUMTEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY chat_tail(conversation_id,id),CONSTRAINT fk_native_action_chat FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id));
        CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,
            memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash CHAR(40),memory_scope_version SMALLINT,
            source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active TINYINT,metadata_json TEXT,
            UNIQUE KEY owner_memory(user_id,memory_hash),KEY idx_agent_memory_agent_type_v410(user_id,user_agent_id,memory_type),
            KEY idx_agent_memory_agent_occurrence_v410(user_id,user_agent_id,last_seen_at));
        CREATE TABLE agent_chat_archive(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,created_at DATETIME,
            KEY idx_agent_archive_agent_created_v410(user_id,user_agent_id,created_at));
        CREATE TABLE user_data_policies(owner_user_id INT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(190),audience_scope VARCHAR(40),
            owner_agents_allowed TINYINT,profile_agent_allowed TINYINT,stonefellow_shared TINYINT);
        CREATE TABLE user_agent_data_rules(agent_id BIGINT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(190),access_mode VARCHAR(40));
        CREATE TABLE agent_activity_state(user_id INT UNSIGNED PRIMARY KEY,surface VARCHAR(30),context_key VARCHAR(80),activity_state VARCHAR(30),
            last_heartbeat_at DATETIME,idle_since_at DATETIME,details_json TEXT);
        INSERT INTO agent_activity_state VALUES(1,'chat','chat:10','paused',NOW(),NULL,'{}');
        CREATE TABLE agent_activity_events(id INT PRIMARY KEY)");
    agent_job_engine_ensure_schema_v1900($pdo);agent_work_control_ensure_schema_v173($pdo);vp3_task_lists_schema_v1($pdo);
}

function action_mysql_user(): array{return ['id'=>1,'is_active'=>1,'role'=>'artist'];}
function action_mysql_principal(): array{return vp3_initiative_principal_v1(db(),action_mysql_user(),7);}
function action_mysql_owner(string $query): int
{
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(10,1,'user',?)")->execute([$query]);return (int)db()->lastInsertId();
}
function action_mysql_ref(array $loaded): array{return ['message_id'=>(int)$loaded['message']['id'],'reply_ref'=>$loaded['state']['reply_ref'],'revision'=>$loaded['state']['revision']];}
function action_mysql_load(int $message): array{return vp3_initiative_followthrough_load_v1(db(),action_mysql_user(),action_mysql_principal(),10,$message);}
function action_mysql_seed(string $label): array
{
    $pdo=db();$u=action_mysql_user();$p=action_mysql_principal();
    vp3_agent_memory_scope_set_current_v410(1,7);
    try{$task=vp3_task_list_save_task_v1($pdo,$u,$p,$label,null,'native-action-source-'.bin2hex(random_bytes(12)),10);}
    finally{unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);}
    vp3_initiative_chat_v1($pdo,$u,$p,['conversation_id'=>10],'on',null);action_mysql_owner('Review '.$label);
    $pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(10,NULL,'assistant','I will inspect this saved task.')");
    $pdo->prepare('UPDATE agent_activity_state SET last_heartbeat_at=NOW(),details_json=? WHERE user_id=1')
        ->execute([json_encode(['visible'=>true,'conversation_id'=>10,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
    $binding=vp3_initiative_policy_v1($u,7);$scope=['user'=>$u,'principal'=>$p,'agent_id'=>7,'conversation_id'=>10];$coverage=[];$ref='task:'.$task;
    $evidence=vp3_initiative_capabilities_evidence_v1($pdo,$scope,$binding,new DateTimeImmutable('now',new DateTimeZone('UTC')),[$ref],$coverage);
    if(!isset($evidence[$ref]))throw new RuntimeException('Actual native task source was unavailable.');
    $goal=['binding'=>$binding,'sources'=>[$ref=>$evidence[$ref]['revision']],'capability_sources'=>[$ref=>'tasks.read'],
        'tool'=>'tasks.read','primary'=>$ref,'focus'=>'Prepare this exact saved task for a reviewed action.','report'=>true];
    $dedupe=hash('sha256',random_bytes(24));vp3_initiative_enqueue_v1($pdo,$u,'initiative.prepare',$goal,$dedupe);
    $q=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE owner_user_id=1 AND dedupe_key=?');$q->execute([hash('sha256','initiative.prepare|'.$dedupe)]);$run=(int)$q->fetchColumn();
    $claim=agent_job_claim_run_v1900($pdo,$u,$run,'cloud',agent_worker_cloud_worker_id_v1910(1));
    if(!$claim)throw new RuntimeException('Actual native preparation could not be claimed.');
    $executed=vp3_initiative_execute_v1($pdo,$u,$claim,static function(string $prompt,string $protocol):string{
        if(db()->inTransaction())throw new LogicException('Native draft inference held a transaction.');
        return json_encode(['draft'=>'This exact saved task can advance through an owner-reviewed private action.'],JSON_THROW_ON_ERROR);
    });
    if(empty($executed['ok']))throw new RuntimeException('Actual native preparation failed.');
    $q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);$saved=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    $message=(int)($saved['initiative_message_id']??0);if(!$message)throw new RuntimeException('Actual native preparation was not delivered.');
    return ['message_id'=>$message,'task_id'=>$task,'loaded'=>action_mysql_load($message)];
}
function action_mysql_decide(array $loaded,array $decision,string $query,?callable $during=null,?callable $authority=null,?int $messageId=null): array
{
    $messageId??=action_mysql_owner($query);
    return vp3_initiative_followthrough_reply_v1(db(),action_mysql_user(),action_mysql_principal(),10,$messageId,action_mysql_ref($loaded),$query,$authority,
        static function(string $prompt,string $protocol)use($decision,$during):string{
            if(db()->inTransaction()||$protocol!=='vp3.initiative-reply.v1')throw new LogicException('Native decision inference held a transaction or used the wrong protocol.');
            if($during)$during();return json_encode($decision+['intent'=>'advance','focus'=>'Advance this exact reviewed native action.'],JSON_THROW_ON_ERROR);
        });
}
function action_mysql_ack(array $selection): void
{
    vp3_initiative_followthrough_transaction_v1(db(),static function()use($selection):void{
        vp3_initiative_owner_lock_v1(db(),action_mysql_user());
        $advisory=vp3_initiative_followthrough_current_v1(db(),action_mysql_user(),action_mysql_principal(),10,$selection['initiative_followthrough']);
        $ctx=['initiative_followthrough'=>$selection['initiative_followthrough'],'initiative_action'=>$advisory['verified_action']];
        db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(10,NULL,'assistant',?,?)")
            ->execute(['Review these exact private action parameters.',json_encode($ctx,JSON_THROW_ON_ERROR)]);
    });
}
function action_mysql_review(array $root,string $adapter='task.set_status',?array $arguments=null): array
{
    $arguments??=['task_id'=>$root['task_id'],'status'=>'completed'];
    $selection=action_mysql_decide($root['loaded'],['action'=>['adapter'=>$adapter,'arguments'=>$arguments]],'Prepare that exact private action');
    action_mysql_ack($selection);return $root+['pending'=>action_mysql_load($root['message_id'])];
}
function action_mysql_task(int $id): array
{
    $q=db()->prepare('SELECT subject,metadata_json,is_active FROM agent_memory_items WHERE id=? AND user_id=1 AND user_agent_id=7');$q->execute([$id]);$row=$q->fetch();
    if(!$row)throw new RuntimeException('Actual native task is unavailable.');$row['metadata']=json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);return $row;
}
