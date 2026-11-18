<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/permissions.php';
require_once dirname(__DIR__).'/includes/personal-capabilities-v242.php';
require_once dirname(__DIR__).'/includes/profile-analytics-authority-v1.php';

final class PAAA_PDO extends PDO
{
    public string $failRead='';
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        if($this->failRead!==''&&str_contains($query,$this->failRead))throw new PDOException('SECRET permission diagnostic');
        return parent::prepare($query,$options);
    }
}
$checks=0;$native=0;$config=['dsn'=>'sqlite::memory:','user'=>null,'password'=>null];
if($dsn=getenv('VP3_TEST_MYSQL_DSN')){
    $user=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_profile_analytics_authority_'.bin2hex(random_bytes(6));
    $admin->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $config=['dsn'=>preg_match('/dbname=[^;]+/',$dsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$dsn):rtrim($dsn,';').';dbname='.$database,'user'=>$user,'password'=>$password];
    register_shutdown_function(static function()use($admin,$database):void {
        foreach(['paaa_db','paaa_writer'] as $key){$connection=$GLOBALS[$key]??null;if($connection instanceof PDO&&$connection->inTransaction())$connection->rollBack();}
        $admin->exec('DROP DATABASE IF EXISTS '.$database);
    });
}
$pdo=new PAAA_PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
}
$GLOBALS['paaa_db']=$pdo;
function db():PDO{return $GLOBALS['paaa_db'];}
function current_user():?array{return $GLOBALS['paaa_user']??null;}
function vp3_participant_scope_v1():?array{return $GLOBALS['paaa_participant']??null;}
function setting(string $key,mixed $fallback=''):mixed{$q=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$fallback:$v;}
function paaa_ok(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL '.$label);$GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;}
function paaa_denied(callable $run,int $code,string $label):void
{
    try{$run();}catch(RuntimeException $error){paaa_ok($error->getCode()===$code&&!str_contains($error->getMessage(),'SECRET'),$label.' ('.$error->getCode().')');return;}
    throw new RuntimeException('FAIL '.$label);
}
function paaa_begin(PDO $pdo):void{if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();}
function paaa_scalar(PDO $pdo,string $sql):mixed{$q=$pdo->query($sql);$value=$q->fetchColumn();$q->closeCursor();return $value;}
function paaa_blocked(PDO $writer,string $sql,string $label):void
{
    try{$writer->exec($sql);}catch(PDOException $error){paaa_ok((int)($error->errorInfo[1]??0)===1205,$label);return;}
    throw new RuntimeException('FAIL '.$label.' was not locked');
}
function paaa_read(array $scope,bool $requireProfile=true):array
{
    $pdo=db();paaa_begin($pdo);try{return vp3_profile_analytics_authority_current_v1($pdo,$scope,$requireProfile);}finally{if($pdo->inTransaction())$pdo->rollBack();}
}
$tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB':'';
foreach([
    "users(id BIGINT PRIMARY KEY,role VARCHAR(40),is_active INTEGER,display_name VARCHAR(100),password_hash VARCHAR(100))",
    "user_account_types(user_id BIGINT,role VARCHAR(40),assigned_explicitly_at DATETIME NULL,PRIMARY KEY(user_id,role))",
    "user_agents(id BIGINT PRIMARY KEY,owner_user_id BIGINT,is_active INTEGER,display_name VARCHAR(100),engine_key VARCHAR(100))",
    "chat_conversations(id BIGINT PRIMARY KEY,user_id BIGINT,user_agent_id BIGINT NULL)",
    "permissions(permission_key VARCHAR(190) PRIMARY KEY)",
    "role_permissions(role VARCHAR(40),permission_key VARCHAR(190),PRIMARY KEY(role,permission_key))",
    "settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value VARCHAR(100))",
    "authority_caller_sentinel(id BIGINT PRIMARY KEY)"
] as $schema)$pdo->exec('CREATE TABLE '.$schema.$tail);
$pdo->exec("INSERT INTO users VALUES(1,'fan',1,'Owner','PRIVATE_PASSWORD'),(2,'fan',1,'Other','OTHER_PASSWORD')");
$pdo->exec("INSERT INTO user_agents VALUES(7,1,1,'Current Agent','default'),(8,2,1,'Other Agent','default')");
$pdo->exec("INSERT INTO chat_conversations VALUES(11,1,7),(12,1,NULL),(13,2,8)");
$pdo->exec("INSERT INTO settings VALUES('personal_capabilities_seed_v242','1')");
$permissions=['account.access','chat.access','profile_agent.access','profile_chat.access'];
foreach($permissions as $key){$pdo->prepare('INSERT INTO permissions VALUES(?)')->execute([$key]);$pdo->prepare("INSERT INTO role_permissions VALUES('fan',?)")->execute([$key]);}
$scope=['user'=>['id'=>1,'role'=>'admin','roles'=>['admin']],'agent_id'=>7,'conversation_id'=>11,
    'principal'=>['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>1,'viewer_user_id'=>1]];
$GLOBALS['paaa_user']=$scope['user'];
paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope),503,'current authority requires an explicit caller transaction');
$fresh=paaa_read($scope);
paaa_ok($fresh['user']['role']==='fan'&&!isset($fresh['user']['password_hash'])&&$fresh['agent']['id']==7,'current rows replace copied roles and omit account credentials');
$system=$scope;$system['agent_id']=0;$system['conversation_id']=12;$system['principal']=['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>1];
paaa_ok(paaa_read($system)['agent']===null,'system scope requires the exact NULL private Agent namespace');
$bad=$system;$bad['conversation_id']=11;paaa_denied(fn()=>paaa_read($bad),403,'system scope cannot reuse a named-Agent Chat');
$bad=$scope;$bad['conversation_id']=13;paaa_denied(fn()=>paaa_read($bad),403,'foreign private Chat is denied');
$GLOBALS['paaa_participant']=['kind'=>'shared'];paaa_denied(fn()=>paaa_read($scope),403,'participant scope cannot acquire private analytics authority');$GLOBALS['paaa_participant']=null;
foreach($permissions as $key){
    $pdo->prepare("DELETE FROM role_permissions WHERE role='fan' AND permission_key=?")->execute([$key]);
    paaa_denied(fn()=>paaa_read($scope),403,'current canonical grant is required: '.$key);
    if(str_starts_with($key,'profile_'))paaa_ok(paaa_read($scope,false)['user']['id']==1,
        'private plan inventory remains independent of the optional Profile grant: '.$key);
    else paaa_denied(fn()=>paaa_read($scope,false),403,'private plan inventory still requires the current grant: '.$key);
    $pdo->prepare("INSERT INTO role_permissions VALUES('fan',?)")->execute([$key]);
}
$pdo->exec("DELETE FROM role_permissions WHERE permission_key IN ('profile_agent.access','profile_chat.access')");
$pdo->exec("UPDATE settings SET setting_value='0' WHERE setting_key='personal_capabilities_seed_v242'");
paaa_ok(paaa_read($scope)['user']['id']==1,'verified pre-seed personal defaults retain existing canonical behavior');
$pdo->exec("DELETE FROM settings WHERE setting_key='personal_capabilities_seed_v242'");
paaa_denied(fn()=>paaa_read($scope),503,'a missing seed marker cannot certify default permission from an unlocked gap');
paaa_ok(paaa_read($scope,false)['user']['id']==1,'account and Chat inventory requires its explicit grants but no unrelated personal-default seed');
$pdo->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
paaa_denied(fn()=>paaa_read($scope,false),403,'missing personal seed never excuses a revoked private Chat grant');
$pdo->exec("INSERT INTO role_permissions(role,permission_key) VALUES('fan','chat.access')");
$pdo->exec("INSERT INTO settings VALUES('personal_capabilities_seed_v242','invalid')");
paaa_denied(fn()=>paaa_read($scope),503,'malformed seed provenance cannot certify personal defaults');
$pdo->exec("UPDATE settings SET setting_value='1' WHERE setting_key='personal_capabilities_seed_v242'");
paaa_denied(fn()=>paaa_read($scope),403,'seeded explicit personal revocation cannot fall back to defaults');
$pdo->exec("INSERT INTO user_account_types VALUES(1,'admin',NULL)");
paaa_denied(fn()=>paaa_read($scope),403,'an old unassigned administrator row cannot confer authority');
$pdo->exec("UPDATE user_account_types SET assigned_explicitly_at='2026-11-01 00:00:00' WHERE user_id=1 AND role='admin'");
paaa_ok(paaa_read($scope)['user']['id']==1,'a current explicit administrator assignment retains the canonical bypass');
$pdo->exec("DELETE FROM user_account_types WHERE user_id=1 AND role='admin'");
foreach(['profile_agent.access','profile_chat.access'] as $key)$pdo->prepare("INSERT INTO role_permissions VALUES('fan',?)")->execute([$key]);
foreach(['FROM users','FROM user_account_types','FROM settings','FROM permissions','FROM role_permissions'] as $sql){
    $pdo->failRead=$sql;paaa_denied(fn()=>paaa_read($scope),503,'unreadable current authority does not restore defaults: '.$sql);$pdo->failRead='';
}
$pdo->exec("UPDATE users SET is_active=0 WHERE id=1");paaa_denied(fn()=>paaa_read($scope),403,'inactive current owner cannot retain a copied active session');$pdo->exec("UPDATE users SET is_active=1 WHERE id=1");
$pdo->exec("UPDATE user_agents SET is_active=0 WHERE id=7");paaa_denied(fn()=>paaa_read($scope),403,'retired exact private Agent cannot read analytics');$pdo->exec("UPDATE user_agents SET is_active=1 WHERE id=7");
paaa_begin($pdo);$pdo->exec('INSERT INTO authority_caller_sentinel VALUES(1)');vp3_profile_analytics_authority_current_v1($pdo,$scope);
paaa_ok($pdo->inTransaction(),'successful current reads preserve the caller transaction');$pdo->rollBack();
paaa_ok((int)$pdo->query('SELECT COUNT(*) FROM authority_caller_sentinel')->fetchColumn()===0,'the reader never commits unrelated caller writes');
paaa_begin($pdo);$pdo->exec('INSERT INTO authority_caller_sentinel VALUES(1)');
$bad=$scope;$bad['conversation_id']=13;
paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$bad),403,'denial inside a caller-owned transaction stays an authority denial');
paaa_ok($pdo->inTransaction(),'denial leaves the caller in control of its transaction');$pdo->rollBack();
paaa_ok((int)paaa_scalar($pdo,'SELECT COUNT(*) FROM authority_caller_sentinel')===0,'denial neither commits nor partially persists caller writes');
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    $writer=new PDO($config['dsn'],$config['user'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $GLOBALS['paaa_writer']=$writer;
    $writer->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    $writer->exec('SET innodb_lock_wait_timeout=1');
    $races=[
        ['disable owner',"UPDATE users SET is_active=0 WHERE id=1","UPDATE users SET is_active=1 WHERE id=1",'SELECT is_active FROM users WHERE id=1'],
        ['change primary role',"UPDATE users SET role='investor' WHERE id=1","UPDATE users SET role='fan' WHERE id=1",'SELECT role FROM users WHERE id=1'],
        ['retire private Agent',"UPDATE user_agents SET is_active=0 WHERE id=7","UPDATE user_agents SET is_active=1 WHERE id=7",'SELECT is_active FROM user_agents WHERE id=7'],
        ['reassign private Agent',"UPDATE user_agents SET owner_user_id=2 WHERE id=7","UPDATE user_agents SET owner_user_id=1 WHERE id=7",'SELECT owner_user_id FROM user_agents WHERE id=7'],
        ['retarget private Chat',"UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11","UPDATE chat_conversations SET user_agent_id=7 WHERE id=11",'SELECT user_agent_id FROM chat_conversations WHERE id=11'],
        ['reassign private Chat',"UPDATE chat_conversations SET user_id=2 WHERE id=11","UPDATE chat_conversations SET user_id=1 WHERE id=11",'SELECT user_id FROM chat_conversations WHERE id=11'],
    ];
    foreach($permissions as $key)$races[]=['revoke '.$key,"DELETE FROM role_permissions WHERE role='fan' AND permission_key='$key'","INSERT INTO role_permissions VALUES('fan','$key')","SELECT COUNT(*) FROM role_permissions WHERE role='fan' AND permission_key='$key'"];
    foreach($races as [$label,$change,$restore,$ordinary]){
        paaa_begin($pdo);$pdo->exec('INSERT INTO authority_caller_sentinel VALUES(2)');
        $old=paaa_scalar($pdo,$ordinary);$writer->exec($change);
        paaa_ok(paaa_scalar($pdo,$ordinary)===$old,'native RR setup retains its older ordinary snapshot: '.$label);
        paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope),403,'current locking fence rejects the committed change inside that old snapshot: '.$label);
        paaa_ok($pdo->inTransaction(),'denial preserves the caller transaction: '.$label);
        $pdo->rollBack();$writer->exec($restore);$native++;
    }
    paaa_begin($pdo);
    $old=paaa_scalar($pdo,"SELECT COUNT(*) FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
    $writer->exec("DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'");
    paaa_ok(paaa_scalar($pdo,"SELECT COUNT(*) FROM role_permissions WHERE role='fan' AND permission_key='chat.access'")===$old,
        'private inventory race begins from a real stale Chat grant snapshot');
    paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope,false),403,
        'account/Chat-only inventory rejects a committed grant revocation despite its old snapshot');
    $pdo->rollBack();$writer->exec("INSERT INTO role_permissions VALUES('fan','chat.access')");$native++;
    paaa_begin($pdo);vp3_profile_analytics_authority_current_v1($pdo,$scope,false);
    paaa_blocked($writer,"DELETE FROM role_permissions WHERE role='fan' AND permission_key='chat.access'",
        'private inventory retains its required Chat grant until the caller releases its transaction');
    $pdo->rollBack();$native++;
    $writer->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'");
    $writer->exec("INSERT INTO user_account_types VALUES(1,'admin','2026-11-01 00:00:00')");
    paaa_begin($pdo);paaa_ok(personal_capability_has_v242('profile_agent.access',$scope['user']),'the old production reader initially sees explicit admin');
    $writer->exec("DELETE FROM user_account_types WHERE user_id=1 AND role='admin'");
    paaa_ok(personal_capability_has_v242('profile_agent.access',$scope['user']),'ordinary canonical role reads retain the old assignment in RR');
    paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope),403,'current role range denies a concurrently revoked admin assignment');
    $pdo->rollBack();$writer->exec("INSERT INTO role_permissions VALUES('fan','profile_agent.access')");$native++;
    $writer->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'");
    $writer->exec("UPDATE settings SET setting_value='0' WHERE setting_key='personal_capabilities_seed_v242'");
    paaa_begin($pdo);paaa_ok(personal_capability_has_v242('profile_agent.access',$scope['user']),'pre-seed default is visible before concurrent activation');
    $writer->exec("UPDATE settings SET setting_value='1' WHERE setting_key='personal_capabilities_seed_v242'");
    paaa_ok(personal_capability_has_v242('profile_agent.access',$scope['user']),'ordinary reads demonstrate the stale pre-seed snapshot');
    paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope),403,'current seed and grants defeat stale defaults');
    $pdo->rollBack();$writer->exec("INSERT INTO role_permissions VALUES('fan','profile_agent.access')");$native++;
    foreach(["DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'","UPDATE user_agents SET is_active=0 WHERE id=7","UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11","UPDATE users SET role='investor' WHERE id=1"] as $change){
        paaa_begin($pdo);vp3_profile_analytics_authority_current_v1($pdo,$scope);
        paaa_blocked($writer,$change,'current authority rows stay locked until caller releases transaction');
        $pdo->rollBack();$native++;
    }
    $writer->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'");
    $writer->exec("INSERT INTO user_account_types VALUES(1,'admin','2026-11-01 00:00:00')");
    paaa_begin($pdo);vp3_profile_analytics_authority_current_v1($pdo,$scope);
    paaa_blocked($writer,"DELETE FROM user_account_types WHERE user_id=1 AND role='admin'",'an explicit administrator assignment used for authority stays locked');
    $pdo->rollBack();$writer->exec("DELETE FROM user_account_types WHERE user_id=1 AND role='admin'");
    $writer->exec("UPDATE settings SET setting_value='0' WHERE setting_key='personal_capabilities_seed_v242'");
    paaa_begin($pdo);vp3_profile_analytics_authority_current_v1($pdo,$scope);
    paaa_blocked($writer,"UPDATE settings SET setting_value='1' WHERE setting_key='personal_capabilities_seed_v242'",'a verified pre-seed default holds the existing marker against concurrent activation');
    $pdo->rollBack();$writer->exec("UPDATE settings SET setting_value='1' WHERE setting_key='personal_capabilities_seed_v242'");
    $writer->exec("INSERT INTO role_permissions VALUES('fan','profile_agent.access')");$native+=2;

    // A session default does not identify the actual next-transaction isolation.
    // Demonstrate both mismatches using real concurrent changes, not SQL labels.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$pdo->beginTransaction();
    paaa_ok(paaa_scalar($pdo,'SELECT @@session.transaction_isolation')==='REPEATABLE-READ','fixture session default remains RR while the active transaction is RC');
    $name=paaa_scalar($pdo,'SELECT display_name FROM users WHERE id=1');
    $writer->exec("UPDATE users SET display_name='Concurrent RC owner' WHERE id=1");
    paaa_ok(paaa_scalar($pdo,'SELECT display_name FROM users WHERE id=1')==='Concurrent RC owner','ordinary reads prove the per-transaction RC override actually took effect');
    $current=vp3_profile_analytics_authority_current_v1($pdo,$scope);
    paaa_ok($current['user']['display_name']==='Concurrent RC owner'&&$pdo->inTransaction(),'RC authority uses current locked records without certifying a session-default snapshot');
    paaa_blocked($writer,"DELETE FROM role_permissions WHERE role='fan' AND permission_key='profile_chat.access'",'a successful RC authority proof retains the exact grant lock');
    $pdo->rollBack();$writer->prepare('UPDATE users SET display_name=? WHERE id=1')->execute([$name]);$native++;

    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    paaa_ok(paaa_scalar($pdo,'SELECT @@session.transaction_isolation')==='READ-COMMITTED','fixture session default remains RC while the active transaction is RR');
    $active=paaa_scalar($pdo,'SELECT is_active FROM users WHERE id=1');
    $writer->exec('UPDATE users SET is_active=0 WHERE id=1');
    paaa_ok(paaa_scalar($pdo,'SELECT is_active FROM users WHERE id=1')===$active,'ordinary reads prove the per-transaction RR snapshot remains stale');
    paaa_denied(fn()=>vp3_profile_analytics_authority_current_v1($pdo,$scope),403,'current authority rejects a revoked owner despite the misleading RC session default');
    paaa_ok($pdo->inTransaction(),'isolation mismatch denial preserves caller transaction ownership');
    $pdo->rollBack();$writer->exec('UPDATE users SET is_active=1 WHERE id=1');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$native++;
}
echo 'PROFILE_ANALYTICS_AUTHORITY_V1=PASS ('.$checks.' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; native_races='.$native.')'.PHP_EOL;
