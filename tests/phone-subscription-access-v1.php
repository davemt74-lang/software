<?php
declare(strict_types=1);

// Real package lookup, additive Admin grants/revocation, phone availability and
// signed-call receipt revalidation. No carrier, payment or provider requests.
// VP3_PHONE_MYSQL_DSN, when supplied, MUST identify a disposable test database:
// the fixture replaces these named tables, like the phone assignment fixture.
$dsn=getenv('VP3_PHONE_MYSQL_DSN')?:'sqlite::memory:';
$mysql=str_starts_with($dsn,'mysql:');
final class PhoneSubscriptionFixturePDO extends PDO
{
    public function exec(string $statement): int|false
    {
        // The actual grant API ensures its MySQL schema on every write. SQLite
        // already has the equivalent fixture table; keep all grant DML real.
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            && preg_match('/^CREATE TABLE IF NOT EXISTS user_entitlement_grants\s*\(/',$statement))return 0;
        return parent::exec($statement);
    }
}
$pdo=new PhoneSubscriptionFixturePDO($dsn,$mysql?(getenv('VP3_PHONE_MYSQL_USER')?:'root'):null,
    $mysql?(getenv('VP3_PHONE_MYSQL_PASSWORD')?:''):null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($mysql){$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$pdo->exec("SET time_zone='+00:00'");}
else $pdo->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'));
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $name): bool
{
    $q=db()->prepare($GLOBALS['mysql']?'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$name]);return (bool)$q->fetchColumn();
}
function has_permission(string $key,?array $user=null): bool
{return !empty($user['is_active'])&&in_array($key,['account.access','chat.access'],true);}
require dirname(__DIR__).'/includes/subscription-schema.php';
require dirname(__DIR__).'/includes/subscription-entitlements-v340.php';
require dirname(__DIR__).'/includes/subscription-access.php';
require dirname(__DIR__).'/includes/phone-workspace-v1.php';
require dirname(__DIR__).'/includes/phone-voice-v130.php';
$tables=['phone_voice_calls_v130','phone_cloud_numbers_v100','user_agents','subscription_audit_log',
    'user_entitlement_grants','ai_token_reservations','ai_usage_ledger','ai_token_credits',
    'user_subscriptions','package_entitlements','subscription_packages','users'];
if($mysql)foreach($tables as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$auto=$mysql?'INTEGER AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
$pdo->exec('CREATE TABLE users(id '.($mysql?'INTEGER UNSIGNED':'INTEGER').' PRIMARY KEY,role VARCHAR(24),is_active INTEGER)');
$pdo->exec('CREATE TABLE subscription_packages(id INTEGER PRIMARY KEY,slug VARCHAR(80),name VARCHAR(120),description TEXT,monthly_price_cents INTEGER,ai_tokens_monthly INTEGER,trial_tokens INTEGER,trial_days INTEGER,is_trial INTEGER,is_public INTEGER,is_active INTEGER)');
$pdo->exec('CREATE TABLE package_entitlements(package_id INTEGER,capability_key VARCHAR(120),is_enabled INTEGER,limit_value INTEGER NULL,metadata_json TEXT NULL,PRIMARY KEY(package_id,capability_key))');
$pdo->exec("CREATE TABLE user_subscriptions(id $auto,user_id INTEGER,package_id INTEGER,status VARCHAR(30),starts_at DATETIME,ends_at DATETIME NULL)");
$pdo->exec("CREATE TABLE subscription_audit_log(id $auto,actor_user_id INTEGER,target_user_id INTEGER,action VARCHAR(60),old_package_id INTEGER NULL,new_package_id INTEGER NULL,reason TEXT,details_json TEXT)");
foreach(['ai_token_credits','ai_usage_ledger','ai_token_reservations'] as $table)$pdo->exec('CREATE TABLE '.$table.'(id INTEGER PRIMARY KEY)');
if($mysql)subscription_entitlements_v340_ensure_schema($pdo);
else $pdo->exec("CREATE TABLE user_entitlement_grants(id $auto,user_id INTEGER,capability_key VARCHAR(120),is_enabled INTEGER,limit_value INTEGER NULL,source_kind VARCHAR(40),source_ref VARCHAR(190) NULL,status VARCHAR(30),starts_at DATETIME,ends_at DATETIME NULL,metadata_json TEXT,created_by INTEGER NULL,updated_at DATETIME NULL)");
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider VARCHAR(24),owner_user_id INTEGER,agent_id INTEGER,phone_e164 VARCHAR(20),voice_enabled INTEGER)');
$pdo->exec("CREATE TABLE phone_voice_calls_v130(id $auto,provider VARCHAR(24),provider_call_sid VARCHAR(40),owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 VARCHAR(20),called_e164 VARCHAR(20),state VARCHAR(24),created_at DATETIME)");
$pdo->exec("INSERT INTO users VALUES(1,'member',1),(2,'member',1),(3,'member',0),(4,'admin',1)");
// Price is fixture data only. Production package prices remain Admin managed.
$pdo->exec("INSERT INTO subscription_packages VALUES(1,'fixture-monthly','Fixture monthly','Monthly phone package',100,0,0,0,0,1,1)");
$pdo->exec("INSERT INTO package_entitlements VALUES(1,'phone.numbers',1,1,NULL),(1,'phone.voice',1,NULL,NULL),(1,'phone.sms',1,NULL,NULL)");
$start=gmdate('Y-m-d H:i:s',time()-86400);$future=gmdate('Y-m-d H:i:s',time()+86400);$past=gmdate('Y-m-d H:i:s',time()-3600);
$q=$pdo->prepare("INSERT INTO user_subscriptions(user_id,package_id,status,starts_at,ends_at) VALUES(?,1,'active',?,?)");
foreach([1,2,3] as $owner)$q->execute([$owner,$start,$future]);
$pdo->exec('INSERT INTO user_agents VALUES(7,1,1),(8,2,1),(9,3,1),(10,4,1)');
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES(1,'twilio',1,7,'+16025550101',1),(3,'twilio',3,9,'+16025550103',1),(4,'twilio',4,10,'+16025550104',1)");
$sid='CA'.str_repeat('a',32);
$q=$pdo->prepare("INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,state,created_at) VALUES('twilio',?,1,1,7,'+16025550999','+16025550101','voicemail_offered',?)");
$q->execute([$sid,gmdate('Y-m-d H:i:s')]);
$checks=0;
function check_phone_subscription(bool $ok,string $message): void
{if(!$ok)throw new RuntimeException('FAIL '.$message);$GLOBALS['checks']++;}
function phone_subscription_user(int $id): array
{$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();}
function phone_subscription_set(string $status,string $starts,?string $ends): void
{$q=db()->prepare('UPDATE user_subscriptions SET status=?,starts_at=?,ends_at=? WHERE user_id=1');$q->execute([$status,$starts,$ends]);}
function phone_subscription_route(): array
{return vp3_phone_voice_current_route_v131(db(),$GLOBALS['sid']);}
function phone_subscription_grant(string $key,?int $limit=null,int $owner=1,?string $ends=null): int
{return subscription_grant_entitlement_v340($owner,$key,$limit,'admin_addon','phone-access-fixture',$ends,4,['reason'=>'Explicit Admin phone override fixture']);}

check_phone_subscription(subscription_schema_ready($pdo),'actual subscription schema boundary is ready');
check_phone_subscription((int)subscription_current(phone_subscription_user(1))['package_id']===1,'actual current package resolves monthly subscription');
check_phone_subscription(vp3_phone_package_v100($pdo,1)===['included'=>true,'limit'=>1,'voice'=>true,'sms'=>true],'active monthly package composes number and call access');
check_phone_subscription(vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1)),'active package plus current assigned number exposes Phone');
check_phone_subscription(phone_subscription_route()['accepted'],'active package admits immutable current call receipt');
check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(2)),'monthly package alone cannot create a number assignment');
check_phone_subscription(!vp3_phone_package_v100($pdo,3)['voice']&&!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(3)),'inactive owner cannot use package or assigned number');
check_phone_subscription(subscription_has_entitlement(phone_subscription_user(4),'phone.voice'),'general internal Admin shortcut exists in real subscription runtime');
check_phone_subscription(!vp3_phone_package_v100($pdo,4)['voice']&&!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(4)),'role-only Admin shortcut is not an explicit phone override');

foreach([['cancelled',$start,$future],['active',$start,$past],['active',$future,null]] as [$status,$starts,$ends]){
    phone_subscription_set($status,$starts,$ends);
    check_phone_subscription(!vp3_phone_package_v100($pdo,1)['voice'],'cancelled, expired or future subscription cannot grant calling: '.$status.' '.$starts.' '.$ends);
    check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1)),'lost package hides assigned workspace');
    check_phone_subscription(phone_subscription_route()['reason']==='account_ineligible','lost package invalidates already signed call receipt');
}
phone_subscription_set('cancelled',$start,$future);
$voice=phone_subscription_grant('phone.voice');
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['voice'],'voice-only Admin grant does not allocate a number allowance');
$zero=phone_subscription_grant('phone.numbers',0);
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['included']&&!vp3_phone_package_v100($pdo,1)['voice'],'zero number grant cannot unlock paid calls');
$numbers=phone_subscription_grant('phone.numbers',1);
check_phone_subscription(vp3_phone_package_v100($pdo,1)['voice']&&vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1)),'explicit Admin number plus voice grants restore canceled-plan access');
check_phone_subscription(phone_subscription_route()['accepted'],'active explicit grants restore current call receipt eligibility');
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['sms'],'voice override does not implicitly grant SMS');
$q=$pdo->prepare('UPDATE user_entitlement_grants SET ends_at=? WHERE id=?');$q->execute([$past,$voice]);
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['voice']&&phone_subscription_route()['reason']==='account_ineligible','expired voice override blocks further call routing');
check_phone_subscription(vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1)),'number override alone retains assigned workspace without paid voice');
$voice=phone_subscription_grant('phone.voice');
check_phone_subscription(phone_subscription_route()['accepted'],'new explicit voice override restores route');
check_phone_subscription(subscription_revoke_entitlement_grant_v340($voice,1,4,'Fixture revoke'),'real Admin revoke updates grant');
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['voice']&&phone_subscription_route()['reason']==='account_ineligible','revoked voice override blocks further call routing');
phone_subscription_grant('phone.voice');
check_phone_subscription(subscription_revoke_entitlement_grant_v340($numbers,1,4,'Fixture revoke'),'real number override revoke succeeds');
check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1))&&phone_subscription_route()['reason']==='account_ineligible','revoked number allowance hides workspace and blocks paid callbacks');
phone_subscription_grant('phone.numbers',1,2);phone_subscription_grant('phone.voice',null,2);
check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(2)),'package plus explicit Admin grants still require actual number assignment');
phone_subscription_grant('phone.numbers',1,4);phone_subscription_grant('phone.voice',null,4);
check_phone_subscription(vp3_phone_workspace_available_v1($pdo,phone_subscription_user(4))&&vp3_phone_package_v100($pdo,4)['voice'],'Admin may use an explicitly granted own assigned number');
phone_subscription_set('active',$start,$future);
$pdo->exec("UPDATE package_entitlements SET limit_value=0 WHERE capability_key='phone.numbers'");
check_phone_subscription(!vp3_phone_package_v100($pdo,1)['voice'],'enabled monthly number entitlement with zero capacity cannot call');
$pdo->exec("UPDATE package_entitlements SET limit_value=NULL WHERE capability_key='phone.numbers'");
check_phone_subscription(vp3_phone_package_v100($pdo,1)['limit']===null&&vp3_phone_package_v100($pdo,1)['voice'],'existing unlimited package semantics are preserved');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1))&&phone_subscription_route()['reason']==='account_ineligible','archived assigned Agent blocks workspace and route despite entitlements');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
check_phone_subscription(!vp3_phone_workspace_available_v1($pdo,phone_subscription_user(1))&&phone_subscription_route()['reason']==='assignment_changed','number reassignment revokes old owner routing despite restored monthly plan');
check_phone_subscription((int)$pdo->query("SELECT COUNT(*) FROM subscription_audit_log WHERE action='entitlement_revoked'")->fetchColumn()===2,'real revoke paths retain subscription audit trail');
echo 'PHONE_SUBSCRIPTION_ACCESS_V1=PASS checks='.$checks.' driver='.($mysql?'mysql':'sqlite')."\n";
