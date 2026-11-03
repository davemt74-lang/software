<?php
declare(strict_types=1);
$fixtureRoot=dirname(__DIR__,2);
require_once $fixtureRoot.'/includes/knowledge.php';
require_once $fixtureRoot.'/includes/cloud-mission-schedules-v1.php';
require_once __DIR__.'/workflow-agent-schema-v1.php';
class ScheduledMissionPDO extends PDO {
    public function exec(string $statement): int|false {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$statement=str_replace("pause_requested_at TEXT DEFAULT ''","pause_requested_at TEXT DEFAULT NULL",$statement);
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$query=str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$query);
        return parent::prepare($query,$options);
    }
}
$fixtureDsn=getenv('VP3_CLOUD_SCHEDULE_DSN')?:'sqlite::memory:';
$pdo=new ScheduledMissionPDO($fixtureDsn,getenv('VP3_CLOUD_SCHEDULE_USER')?:null,getenv('VP3_CLOUD_SCHEDULE_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {return $GLOBALS['pdo'];}
function table_exists(string $name): bool {
    $q=db()->prepare(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT name FROM sqlite_master WHERE type='table' AND name=?":"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$q->execute([$name]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool {
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
    $q=db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function current_user(): ?array {return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1),'denied'=>isset($_SERVER['HTTP_X_FIXTURE_DENIED'])];}
function require_permission($p): void {if(!current_user()||!has_permission($p,current_user()))throw new RuntimeException('Access denied.',403);}
function csrf_token(): string {return 'fixture';}
function has_permission($p,$u): bool {return empty($u['denied'])&&empty($GLOBALS['revoked'])&&($p!=='ai.manage'||(int)$u['id']===1);}
function personal_capability_has_v242($p,$u): bool {return has_permission($p,$u);}
function can_view_visibility($visibility,$user): bool {return true;}
function user_agent_get_v236($pdo,$uid,$aid): ?array {return $uid===1&&$aid===7?['id'=>7,'is_active'=>1]:null;}
function vp3_agent_memory_scope_from_conversation_v410($u,$cid,$set=true): int {if($u['id']!==1||$cid!==11)throw new RuntimeException('Wrong conversation.',403);return 7;}
function vp3_agent_memory_scope_set_current_v410($uid,$aid): void {$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=['user_id'=>$uid,'user_agent_id'=>$aid];}
function homeserver_https_v1300_status($uid): array {return ['connected'=>false];}
function ai_active_provider(): string {return 'fixture';}
function ai_provider_model($p): string {return 'fixture';}
function ai_generate_chat_response($q,$history,$context,$user,$scope,$control): array {
    if(($control['surface']??'')!=='cloud_mission')throw new RuntimeException('Wrong inference surface.');
    $GLOBALS['modelContexts'][]=$context;
    return ['ok'=>true,'provider'=>'fixture','answer'=>'Source-backed research draft. '.str_repeat('Dough evidence comparison. ',30)];
}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('IF',fn($a,$b,$c)=>$a?$b:$c);
}
if(!table_exists('agent_workflow_runs')){
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT);INSERT INTO users VALUES(1,1),(2,1)');
        agent_job_engine_ensure_schema_v1900($pdo);agent_work_control_ensure_schema_v173($pdo);
    }else{
        workflow_agent_fixture_v1($pdo);
        foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$c);
        foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $c)$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$c);
        foreach(['receipt_key TEXT','result_json TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$c);
        $pdo->exec('CREATE UNIQUE INDEX scheduled_receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key)');
    }
    $auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE artist_transcript_folders_v177(id $auto,created_by_user_id INTEGER,folder_name VARCHAR(80),sort_order INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(created_by_user_id,folder_name));CREATE TABLE tracks(id INTEGER PRIMARY KEY,title VARCHAR(190));CREATE TABLE knowledge_items(id $auto,track_id INTEGER,folder_id INTEGER,title VARCHAR(190),description TEXT,file_name VARCHAR(190),file_path TEXT,file_type VARCHAR(40),mime_type VARCHAR(80),file_size INTEGER,content_text TEXT,visibility VARCHAR(20),is_published INTEGER,created_by_user_id INTEGER,knowledge_scope VARCHAR(20),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);CREATE TABLE knowledge_chunks(id $auto,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT)");
    cloud_mission_schema_v1($pdo);cloud_mission_schedules_schema_v1($pdo);
    personal_knowledge_store(['id'=>1],'source-dough','Dough research','Dough cold fermentation research: initial evidence.');
}
