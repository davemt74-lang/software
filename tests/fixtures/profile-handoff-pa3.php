<?php
declare(strict_types=1);

/** Real Profile authority, SQL and job engine. Only external inference is a fixture. */
$pa3Root=getenv('VP3_PA3_ROOT')?:dirname(__DIR__,2);
$GLOBALS['pa3Root']=$pa3Root;
final class PA3Statement extends PDOStatement
{
    protected function __construct(private PA3PDO $connection){}
    public function execute(?array $params=null): bool
    {
        $ok=parent::execute($params);
        if($hook=$this->connection->afterSql)$hook($this->queryString,$this->connection);
        return $ok;
    }
}
final class PA3PDO extends PDO
{
    public mixed $afterSql=null;
    public string $failSql='';
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if($this->failSql!==''&&str_contains($sql,$this->failSql))throw new PDOException('SECRET fixture SQL diagnostic');
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql=str_replace([' FOR UPDATE','INSERT IGNORE'],['','INSERT OR IGNORE'],$sql);
            $sql=preg_replace('/SHOW INDEX FROM `?([a-z0-9_]+)`? WHERE Key_name=\?/i',"SELECT name FROM sqlite_master WHERE tbl_name='$1' AND type='index' AND name=?",$sql);
            $sql=preg_replace("/DATE_SUB\((?:UTC_TIMESTAMP|NOW)\(\),INTERVAL (\d+) (DAY|HOUR|MINUTE|SECOND)\)/i","datetime('now','-$1 $2')",$sql);
            if(str_starts_with($sql,'INSERT INTO profile_visit_sessions ')){
                $sql=str_contains($sql,'VALUES (?,NULL,?,0,0)')
                    ?"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,NULL,?,0,0) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET last_seen_at=NOW()"
                    :"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,?,?,?,?) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET visitor_user_id=COALESCE(excluded.visitor_user_id,profile_visit_sessions.visitor_user_id),identity_disclosed=CASE WHEN excluded.visitor_user_id IS NULL THEN profile_visit_sessions.identity_disclosed ELSE excluded.identity_disclosed END,view_count=profile_visit_sessions.view_count+excluded.view_count,last_seen_at=NOW()";
            }
        }
        return parent::prepare($sql,$options);
    }
    public function exec(string $sql): int|false
    {
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            // Use the production job DDL, adapting only SQL dialect syntax.
            $sql=str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
            $sql=preg_replace('/\s+UNSIGNED\b|\s+ON UPDATE CURRENT_TIMESTAMP|\s+AFTER [a-z_]+/i','',$sql);
            $sql=preg_replace('/UNIQUE KEY [a-z0-9_]+\s*\(/i','UNIQUE (',$sql);
            $sql=preg_replace('/,\s*INDEX [a-z0-9_]+\s*\([^)]*\)/i','',$sql);
            $sql=preg_replace('/\s+ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/i','',$sql);
        }
        return parent::exec($sql);
    }
}

$cfgPath=getenv('VP3_PA3_CONFIG');
if($cfgPath&&is_file($cfgPath))$pa3Config=json_decode((string)file_get_contents($cfgPath),true,512,JSON_THROW_ON_ERROR);
else{
    $pa3Config=['dsn'=>'sqlite::memory:'];
    if($dsn=getenv('VP3_TEST_MYSQL_DSN')){
        $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';
        $admin=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $database='vp3_profile_handoff_pa3_'.bin2hex(random_bytes(6));
        $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $databaseDsn=preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database;
        $pa3Config=['dsn'=>$databaseDsn,'user'=>$user,'password'=>$password,'database'=>$database,'admin_dsn'=>$dsn];
        if(!$cfgPath)register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
    }elseif($cfgPath)$pa3Config=['dsn'=>'sqlite:'.dirname($cfgPath).'/handoff-pa3.sqlite'];
    if($cfgPath){file_put_contents($cfgPath,json_encode($pa3Config,JSON_THROW_ON_ERROR));chmod($cfgPath,0600);}
}
$GLOBALS['pa3Config']=$pa3Config;
$pdo=new PA3PDO($pa3Config['dsn'],$pa3Config['user']??null,$pa3Config['password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PA3Statement::class,[$pdo]]);
$GLOBALS['pa3Now']=(int)floor(microtime(true)*1000);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    $pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('IF',static fn($condition,$yes,$no)=>$condition?$yes:$no);
    $pdo->exec('PRAGMA busy_timeout=5000');
}else{$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
$GLOBALS['pa3ModelCalls']=[];$GLOBALS['pa3ModelHook']=null;
function vp3_profile_handoff_now_pa3(): int{return $GLOBALS['pa3Now'];}
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $p=db();$q=$p->prepare($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    if(!preg_match('/^[a-z0-9_]+$/D',$table))throw new RuntimeException('Invalid fixture table.');
    if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
function setting(string $key,mixed $fallback=''): mixed{return $GLOBALS['pa3Settings'][$key]??$fallback;}
function url(string $path): string{return $path;}
function user_account_types_for_user_id(int $id,string $role): array{return [$role];}
function user_roles_for_user(array $user): array{return [(string)$user['role']];}
function permissions_schema_ready(): bool{return true;}
function personal_capability_seeded_v242(): bool{return true;}
function personal_capability_schema_ready_v242(?PDO $pdo=null): bool{return true;}
function personal_capability_has_v242(string $key,?array $user=null): bool{return has_permission($key,$user);}
function has_permission(string $key,?array $user=null): bool
{
    $user??=current_user();if(!$user||empty($user['is_active']))return false;
    $q=db()->prepare('SELECT 1 FROM role_permissions WHERE role=? AND permission_key=?');$q->execute([(string)$user['role'],$key]);return(bool)$q->fetchColumn();
}
function csrf_token(): string{return 'pa3-fixture-csrf';}
function verify_csrf(): bool{return ($_POST['csrf_token']??'')===csrf_token();}
function chat_policy_terms_v236(string $query): array{return array_values(array_filter(preg_split('/[^a-z]+/',strtolower($query)),static fn($s)=>strlen($s)>3));}
function homeserver_shared_v210_exchange(int $owner,string $query): array{return ['datasets'=>['knowledge'=>[['key'=>'hours','title'=>'Home hours','content'=>'Home hours are Tuesday']]]];}
function homeserver_profile_v235_answer(int $owner,string $query,array $history,array $context): ?array{return null;}
function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $scope=null,?array $control=null): array
{
    $GLOBALS['pa3ModelCalls'][]=compact('query','history','context','user','scope','control');
    if($hook=$GLOBALS['pa3ModelHook'])$hook();
    if($log=getenv('VP3_PA3_INFERENCE_LOG'))file_put_contents($log,json_encode(['query'=>$query,'scope'=>$scope,'session_active'=>session_status()===PHP_SESSION_ACTIVE])."\n",FILE_APPEND|LOCK_EX);
    return ['ok'=>true,'answer'=>'PA3 approved public answer'];
}
require_once $pa3Root.'/includes/auth.php';
require_once $pa3Root.'/includes/notifications.php';
require_once $pa3Root.'/includes/user-agent-system-v236.php';
require_once $pa3Root.'/includes/shared-knowledge-index-v236.php';
require_once $pa3Root.'/includes/profile-agent.php';
require_once $pa3Root.'/includes/profile-agent-runtime.php';
require_once $pa3Root.'/includes/profile-visitor-crm-v243.php';
require_once $pa3Root.'/includes/profile-agent-boundary-v390.php';
require_once $pa3Root.'/includes/profile-agent-public-service-v110.php';
require_once $pa3Root.'/includes/profile-agent-accepted-turn-pa3.php';
require_once $pa3Root.'/includes/profile-agent-handoff-pa3.php';
require_once $pa3Root.'/includes/agent-worker-cloud-v1910.php';
require_once $pa3Root.'/tests/fixtures/profile-public-authority-pa1.php';

function pa3_login(int $id): void
{
    $_SESSION=[];
    if($id>0){$q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);$_SESSION=['user_id'=>$id,'auth_password_fingerprint'=>auth_password_fingerprint($id,(string)$q->fetchColumn())];}
    reset_current_user_cache();
}
function pa3_owner(int $id=1): array{$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();}
function pa3_context(int $visitor=0,string $browser='b'): array
{
    pa3_login($visitor);$_COOKIE=['sf_profile_visitor'=>str_repeat('a',64),'sf_profile_conversation'=>str_repeat($browser,64)];
    return vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user());
}
function pa3_reset(): void
{
    $p=db();if($p->inTransaction())$p->rollBack();$p->afterSql=null;$p->failSql='';
    $GLOBALS['pa3ModelCalls']=[];$GLOBALS['pa3ModelHook']=null;$GLOBALS['pa3Now']=(int)floor(microtime(true)*1000);$GLOBALS['pa3Settings']=[];
    foreach(['profile_agent_handoff_operations_pa3','profile_agent_handoff_deliveries_pa3','profile_agent_handoff_turns_pa3','profile_agent_handoff_conversations_pa3','profile_agent_handoff_presence_pa3','profile_agent_handoff_owners_pa3','agent_workflow_receipts','agent_workflow_action_dependencies','agent_workflow_events','agent_workflow_actions','agent_workflow_runs','chat_messages','chat_conversations'] as $table)if(table_exists($table))$p->exec('DELETE FROM '.$table);
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!table_exists('users'))$p->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,display_name VARCHAR(190),email VARCHAR(190),role VARCHAR(40),is_active INTEGER,avatar_path VARCHAR(255),password_hash VARCHAR(255),last_login_at DATETIME)');
    profile_public_authority_fixture_pa1($p);
    $p->exec("INSERT INTO role_permissions VALUES('member','chat.access'),('member','account.access')");
    $auto=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $p->exec('CREATE TABLE IF NOT EXISTS chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,title VARCHAR(190),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $p->exec("CREATE TABLE IF NOT EXISTS chat_messages(id $auto,conversation_id INTEGER,user_id INTEGER,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $p->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(11,1,7,'Owner main Chat'),(12,1,8,'Owner other Agent'),(13,4,9,'Foreign private Chat')");
    $p->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(11,1,'user','PRIVATE_CHAT_SECRET'),(13,4,'user','FOREIGN_CHAT_SECRET')");
    agent_job_engine_ensure_schema_v1900($p);
    vp3_profile_handoff_ensure_schema_pa3($p);
    pa3_context();
}
function pa3_count(string $table,string $where='1=1'): int{return(int)db()->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$where)->fetchColumn();}
function pa3_age(): void{db()->exec("UPDATE profile_agent_messages SET created_at='2001-01-01 00:00:00'");}
function pa3_admit(string $query='What are the public hours?',int $cid=0,?string $request=null,int $visitor=0,string $browser='b'): array
{
    $ctx=pa3_context($visitor,$browser);$request??=bin2hex(random_bytes(16));
    return vp3_profile_agent_public_message_service_v110(db(),$ctx,$query,$cid,['async'=>true,'client_request_id'=>$request]);
}
