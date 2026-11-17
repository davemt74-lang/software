<?php
declare(strict_types=1);

// Only provider transport and host availability are fixtures. Policy storage,
// source authorization, workflow leases/receipts and Chat delivery are production.
set_error_handler(static function(int $severity,string $message,string $file,int $line): bool{
    if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);
    return false;
});
$scopeFile=tempnam(sys_get_temp_dir(),'vp3-initiative-scope-');
if(!$scopeFile)throw new RuntimeException('Could not create scope fixture.');
putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$scopeFile);
require __DIR__.'/image-generation-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__,2).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__,2).'/includes/agent-initiative-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-initiative-runtime-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-reminders-v1.php';
register_shutdown_function(static fn()=>unlink($scopeFile));
$GLOBALS['initiativeBrainAccess']=true;

class InitiativeScopePDO extends ImageFixturePDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if(str_contains($sql,'SELECT id FROM users WHERE id=? FOR UPDATE'))$GLOBALS['scopeOwnerLocks']=($GLOBALS['scopeOwnerLocks']??0)+1;
        $sql=str_replace(["JSON_UNQUOTE(JSON_EXTRACT(p.metadata_json,'$.enabled'))='true'","DATE_SUB(NOW(),INTERVAL 5 MINUTE)"],
            ["JSON_EXTRACT(p.metadata_json,'$.enabled')=1","datetime('now','-5 minutes')"],$sql);
        if(str_contains($sql,'INSERT INTO agent_memory_items')&&str_contains($sql,'ON DUPLICATE KEY UPDATE')){
            [$sql]=explode('ON DUPLICATE KEY UPDATE',$sql,2);
            $sql.='ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,
                confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql,$options);
    }
}

$pdo=new InitiativeScopePDO('sqlite:'.$scopeFile,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
foreach(['NOW','UTC_TIMESTAMP'] as $clock)$pdo->sqliteCreateFunction($clock,static fn()=>gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'main');
$pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
    ALTER TABLE chat_conversations ADD COLUMN title TEXT;
    INSERT INTO user_agents VALUES(17,1,1,'Owner one Agent A'),(18,1,1,'Owner one Agent B'),(19,2,1,'Owner two Agent');
    INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES
      (142,1,NULL,'System one'),(143,2,NULL,'System two'),(170,1,17,'Agent A'),
      (171,1,17,'Another Agent A Chat'),(180,1,18,'Agent B'),(190,2,19,'Other owner');
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_type TEXT,
      subject TEXT,memory_text TEXT,memory_hash TEXT,memory_scope_version INTEGER DEFAULT 410,source_archive_id INTEGER,
      confidence REAL,occurrence_count INTEGER,first_seen_at TEXT,last_seen_at TEXT,is_active INTEGER,metadata_json TEXT,
      UNIQUE(user_id,memory_hash));
    CREATE TABLE agent_chat_archive(user_agent_id INTEGER,created_at TEXT);
    CREATE INDEX idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
    CREATE INDEX idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,last_seen_at);
    CREATE INDEX idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id,created_at);
    ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name
      FROM main.sqlite_master WHERE type='index';
    CREATE TABLE user_data_policies(owner_user_id INTEGER,resource_type TEXT,resource_id TEXT,audience_scope TEXT,
      owner_agents_allowed INTEGER,profile_agent_allowed INTEGER,stonefellow_shared INTEGER);
    CREATE TABLE user_agent_data_rules(agent_id INTEGER,resource_type TEXT,resource_id TEXT,access_mode TEXT);
    CREATE TABLE initiative_scope_calendar(owner_id INTEGER PRIMARY KEY,title TEXT,starts_at TEXT);
    CREATE TABLE agent_activity_state(user_id INTEGER PRIMARY KEY,surface TEXT,context_key TEXT,activity_state TEXT,
      last_heartbeat_at TEXT,idle_since_at TEXT,details_json TEXT);
    CREATE TABLE agent_activity_events(id INTEGER PRIMARY KEY);");

function agent_brain_schema_ready(): bool{return true;}
function agent_brain_normalize(string $text): string{return strtolower(trim($text));}
function agent_brain_sentences(string $text): array{return [$text];}
require_once dirname(__DIR__,2).'/includes/agent-scheduling-v430.php';
require_once dirname(__DIR__,2).'/includes/user-calendar-v1300.php';
function vp3_agent_runtime_plan_v420(PDO $pdo,array $user,int $agentId,string $surface='chat'): array
{
    if(isset($GLOBALS['scopeBeforeRoute']))($GLOBALS['scopeBeforeRoute'])();
    $GLOBALS['scopeRoutes'][]=[(int)$user['id'],$agentId,$surface];
    return ['route'=>empty($GLOBALS['scopeRouteBlocked'])?'vp3_cloud':'homeserver','blocked'=>!empty($GLOBALS['scopeRouteBlocked']),
        'effective_preference'=>'vp3_cloud','cloud'=>['ready'=>empty($GLOBALS['scopeRouteBlocked'])],
        'home'=>['supported'=>false],'try_homeserver'=>false];
}
// Seed-only consumers can load the real provider configuration first. The
// standalone scope suite retains its deterministic transport and route checks.
if (!function_exists('ai_generate_chat_response')) {
    function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $scope=null,?array $control=null): array
    {
        $GLOBALS['scopeInference'][]=[(int)$user['id'],$scope,$control, json_decode($query,true,16,JSON_THROW_ON_ERROR)];
        $answer=($GLOBALS['scopeModel'])($query,(string)$control['protocol']);
        return ['ok'=>true,'answer'=>$answer];
    }
}
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo,array $user,DateTimeImmutable $now,int $days,bool $includeHomeServer=true): array
{
    if($includeHomeServer)throw new LogicException('A background projection must not probe a paired calendar.');
    $q=$pdo->prepare('SELECT * FROM initiative_scope_calendar WHERE owner_id=?');$q->execute([(int)$user['id']]);$row=$q->fetch();
    $event=$row?['ref'=>'event:'.(int)$user['id'],'id'=>(int)$user['id'],'kind'=>'event','title'=>$row['title'],'start_local'=>$row['starts_at']]:null;
    return ['available'=>true,'timezone'=>'UTC','upcoming'=>$event?[$event]:[],'current'=>null,'conflicts'=>[],
      'next'=>$event,'starts_soon'=>$event!==null];
}

$checks=0;
function scope_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    ++$GLOBALS['checks'];echo "PASS $message\n";
}
function scope_principal(array $user,int $agent): array
{
    return vp3_agent_chat_principal_v380($user,vp3_agent_chat_resolve_agent_v380(db(),$user,$agent));
}
function scope_command(array $user,int $agent,int $cid,string $command): array
{
    return vp3_initiative_chat_v1(db(),$user,scope_principal($user,$agent),['conversation_id'=>$cid],$command,null);
}
function scope_control(array $user,int $agent,int $cid,string $command,string $revision): array
{
    return vp3_initiative_chat_v1(db(),$user,scope_principal($user,$agent),
        ['conversation_id'=>$cid,'initiative_expected_revision'=>$revision],$command,null);
}
function scope_runs(int $owner,int $agent,string $capability): array
{
    // This fixture selects generic planner jobs. The Office suite selects its own entry runs.
    $q=db()->prepare('SELECT * FROM agent_workflow_runs WHERE owner_user_id=? AND '.($agent?'agent_id=?':'agent_id IS NULL')." AND source_kind='agent_initiative' AND capability_key=?
        AND source_key NOT LIKE 'office-entry:%' AND source_key NOT LIKE 'office-agent-task:%' ORDER BY id");
    $q->execute($agent?[$owner,$agent,$capability]:[$owner,$capability]);return $q->fetchAll();
}
function scope_claim(array $user,int $run): array
{
    return agent_job_claim_run_v1900(db(),$user,$run,'cloud',agent_worker_cloud_worker_id_v1910((int)$user['id']))
      ?:throw new RuntimeException('Scope fixture could not claim its canonical job.');
}
function scope_messages(): array
{
    // Count canonical workflow deliveries; the independently scheduled morning
    // brief is a different Chat source and does not belong to these assertions.
    return array_values(array_filter(db()->query("SELECT conversation_id,message,context_json FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL ORDER BY id")->fetchAll(),
        static fn(array $row):bool=>(json_decode($row['context_json'],true)['source']??null)==='agent_initiative'));
}
function scope_make_plan(array $user,int $agent,string $title): int
{
    db()->prepare('UPDATE initiative_scope_calendar SET title=? WHERE owner_id=?')->execute([$title,(int)$user['id']]);
    db()->prepare("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE user_id=? AND memory_type='initiative_sweep'")->execute([(int)$user['id']]);
    vp3_initiative_schedule_v1(db(),$user,$agent);
    return (int)array_slice(scope_runs((int)$user['id'],$agent,'initiative.plan'),-1)[0]['id'];
}

$one=['id'=>1,'is_active'=>1];$two=['id'=>2,'is_active'=>1];
$at=gmdate('Y-m-d H:i:s',time()+600);
$pdo->prepare('INSERT INTO initiative_scope_calendar VALUES(1,?,?),(2,?,?)')->execute(['Owner one launch',$at,'Owner two private launch',$at]);
$incoming=['user_id'=>2,'user_agent_id'=>19];$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$incoming;
