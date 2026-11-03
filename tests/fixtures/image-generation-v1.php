<?php
declare(strict_types=1);
$imageFixtureRoot=basename(__DIR__)==='includes'?dirname(__DIR__):dirname(__DIR__,2);
require_once $imageFixtureRoot.'/includes/agent-capability-chat-v1.php';
require_once $imageFixtureRoot.'/includes/knowledge-retrieval-v162.php';
require_once $imageFixtureRoot.'/includes/agent-chat-boundary-v380.php';
require_once $imageFixtureRoot.'/includes/user-agent-system-v236.php';
require_once $imageFixtureRoot.'/includes/media-studio-v86.php';
require_once __DIR__.'/workflow-agent-schema-v1.php';
class ImageFixturePDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$sql);return parent::prepare($sql,$options);}
}
$imageFixtureDsn=getenv('VP3_IMAGE_TEST_DSN')?:'sqlite:'.($GLOBALS['imageFixtureFile']??':memory:');
$pdo=new ImageFixturePDO($imageFixtureDsn,getenv('VP3_IMAGE_TEST_USER')?:null,getenv('VP3_IMAGE_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists($name): bool {$q=db()->prepare(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT name FROM sqlite_master WHERE type='table' AND name=?":"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");$q->execute([$name]);return(bool)$q->fetchColumn();}
function column_exists($table,$column): bool {if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);$q=db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}
function has_permission($key,$u): bool{return empty($GLOBALS['imageDenied'])&&!isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function personal_capability_has_v242(...$args): bool{return false;}
function current_user(): ?array{return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1),'is_active'=>1];}
function csrf_token(): string{return 'fixture';}
function user_account_types_for_user_id(...$args): array{return ['member'];}
function setting($key,$default=''){return $GLOBALS['imageSettings'][$key]??$default;}
function ai_provider_api_key($p): string{return empty($GLOBALS['noImageKey'])?'fixture-image-secret':'';}
function ai_provider_enabled($p): bool{return empty($GLOBALS['noImageProvider']);}
function subscription_has_entitlement(...$args): bool{return empty($GLOBALS['noImageEntitlement']);}
function subscription_ai_preflight(...$args): array{$GLOBALS['imagePreflight'][]=$args;return ['reservation_id'=>23,'max_output_tokens'=>empty($GLOBALS['lowImageQuota'])?16000:64];}
function subscription_ai_commit_usage(...$args): void{$GLOBALS['imageUsage'][]=$args;}
function subscription_ai_release_reservation($id): void{$GLOBALS['imageReleased'][]=$id;}
function url($path): string{return $path;}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('IF',fn($a,$b,$c)=>$a?$b:$c);
}
if(!table_exists('agent_workflow_runs')){
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
        $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT);INSERT INTO users VALUES(1,1),(2,1)');agent_job_engine_ensure_schema_v1900($pdo);agent_work_control_ensure_schema_v173($pdo);
    }else{
        workflow_agent_fixture_v1($pdo);
        foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$c);
        foreach(['run_id INTEGER','owner_user_id INTEGER','action_id INTEGER','depends_on_action_id INTEGER'] as $c)$pdo->exec('ALTER TABLE agent_workflow_action_dependencies ADD COLUMN '.$c);
        foreach(['receipt_key TEXT','result_json TEXT'] as $c)$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$c);
        $pdo->exec('CREATE UNIQUE INDEX image_receipt_unique ON agent_workflow_receipts(owner_user_id,receipt_key)');
    }
    $auto=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec("CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name VARCHAR(100));INSERT INTO user_agents VALUES(7,1,1,'Fixture Agent'),(8,1,1,'Other Agent'),(9,2,1,'Foreign Agent');CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);INSERT INTO chat_conversations VALUES(11,1,7),(12,1,8),(13,2,9);CREATE TABLE chat_messages(id $auto,conversation_id INTEGER,user_id INTEGER,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);CREATE TABLE user_media_assets(id $auto,user_id INTEGER,media_type VARCHAR(20),title VARCHAR(190),mime_type VARCHAR(80),file_path TEXT,file_size INTEGER,duration_seconds DOUBLE,source VARCHAR(60),metadata_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);CREATE TABLE video_editor_projects(id INTEGER PRIMARY KEY,user_id INTEGER)");
}
function image_fixture_plan_v1(): array{return ['operation'=>'image_generate','ids'=>['images.generate'],'prompt'=>'A cinematic sunset over the Arizona desert','title'=>'Desert sunset','size'=>'1024x1024','quality'=>'medium','background'=>'opaque'];}
function image_fixture_turn_v1(): array{$p=db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(11,1,'user','Create a desert image')");$p->execute();return ['conversation_id'=>11,'chat_user_message_id'=>(int)db()->lastInsertId()];}
function image_fixture_response_v1(string $size='1024x1024'): array{
    [$width,$height]=array_map('intval',explode('x',$size));
    $chunk=static fn($type,$data)=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat("\x40\x60\x80\xff",$width),$height))).$chunk('IEND','');
    return ['data'=>[['b64_json'=>base64_encode($png)]],'usage'=>['input_tokens'=>20,'output_tokens'=>200,'total_tokens'=>220]];
}
