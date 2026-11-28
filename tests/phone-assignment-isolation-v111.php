<?php
declare(strict_types=1);
// Optional MySQL execution requires an explicitly dedicated, disposable DB.
// CI supplies VP3_PHONE_MYSQL_DSN; the same production paths run on SQLite.
require dirname(__DIR__).'/includes/phone-cloud-v100.php';
require dirname(__DIR__).'/includes/phone-sms-v110.php';
require dirname(__DIR__).'/includes/phone-sms-outbound-v120.php';
require_once dirname(__DIR__).'/includes/profile-phone-v1.php';

final class AssignmentSQLite extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}
$dsn=getenv('VP3_PHONE_MYSQL_DSN')?:'sqlite::memory:';
$mysql=str_starts_with($dsn,'mysql:');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo=$mysql?new PDO($dsn,getenv('VP3_PHONE_MYSQL_USER')?:'root',getenv('VP3_PHONE_MYSQL_PASSWORD')?:'',$options)
    :new AssignmentSQLite($dsn,null,null,$options);
function db(): PDO {return $GLOBALS['pdo'];}
function table_exists(string $table): bool {
    $q=db()->prepare($GLOBALS['mysql']?'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$table]);return (bool)$q->fetchColumn();
}
function subscription_has_entitlement(?array $user,string $key): bool {return (int)($user['id']??0)>0;}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int {return 50;}
function setting(string $key,string $default=''): string {
    return match($key){'phone_sms_outbound_live_v120'=>'1','phone_sms_status_url_v120'=>'https://cloud.example/api/phone-sms-status-v120.php',default=>$default};
}
$checks=0;
function assignment_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function fixture(bool $legacy): void {
    $pdo=db();$id=$GLOBALS['mysql']?'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $pdo->exec('DROP TABLE IF EXISTS profile_phone_settings_v1');
    vp3_profile_phone_install_v1($pdo);
    $pdo->exec("INSERT INTO profile_phone_settings_v1 VALUES(1,1,7,'+16025550101',1,1)");
    foreach (['phone_browser_numbers_v1','phone_sms_assignment_migrations_v111','phone_sms_outbox_v120','phone_sms_messages_v110','phone_sms_contacts_v110','phone_sms_threads_v110','phone_cloud_requests_v100','phone_cloud_numbers_v100','user_agents','users'] as $table) $pdo->exec('DROP TABLE IF EXISTS '.$table);
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name VARCHAR(80))');
    $pdo->exec("CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider VARCHAR(24),provider_sid VARCHAR(40),phone_e164 VARCHAR(20),owner_user_id INTEGER,agent_id INTEGER,status VARCHAR(24),sms_enabled INTEGER,voice_enabled INTEGER)");
    $pdo->exec("CREATE TABLE phone_cloud_requests_v100(id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,phone_e164 VARCHAR(20),status VARCHAR(24))");
    $pdo->exec('CREATE TABLE phone_browser_numbers_v1(phone_number_id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,browser_enabled INTEGER,recording_enabled INTEGER)');
    $unique=$legacy?'phone_number_id,contact_e164':'phone_number_id,owner_user_id,agent_id,contact_e164';
    $pdo->exec("CREATE TABLE phone_sms_threads_v110(id $id,owner_user_id INTEGER NOT NULL,phone_number_id INTEGER NOT NULL,agent_id INTEGER NOT NULL,contact_e164 VARCHAR(20) NOT NULL,last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,unread_count INTEGER DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE($unique))");
    $pdo->exec('CREATE INDEX idx_phone_sms_owner_recent ON phone_sms_threads_v110(owner_user_id,last_message_at,id)');
    $pdo->exec("CREATE TABLE phone_sms_messages_v110(id $id,thread_id INTEGER,owner_user_id INTEGER,provider VARCHAR(24),provider_message_sid VARCHAR(40),direction VARCHAR(12),body TEXT,delivery_status VARCHAR(24),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_message_sid))");
    $pdo->exec("CREATE TABLE phone_sms_contacts_v110(phone_number_id INTEGER,contact_e164 VARCHAR(20),carrier_opted_out INTEGER DEFAULT 0,last_keyword VARCHAR(12),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(phone_number_id,contact_e164))");
    $pdo->exec("CREATE TABLE phone_sms_outbox_v120(id $id,owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,recipient_e164 VARCHAR(20),body TEXT,consent_source VARCHAR(250),status VARCHAR(24) DEFAULT 'draft',provider VARCHAR(24) DEFAULT 'twilio',provider_message_sid VARCHAR(40),authorized_by_user_id INTEGER,authorized_at DATETIME,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_message_sid))");
    $pdo->exec('INSERT INTO users VALUES (1),(2),(3)');
    $pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Owner A'),(8,2,1,'Owner B'),(9,3,1,'Former owner'),(10,1,1,'Second Agent A')");
    $pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES (1,'twilio','PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','+16025550101',1,7,'unconfigured',1,0)");
}
function incoming(string $hex,string $body): array {
    $url='https://cloud.example/api/phone-sms-inbound-v110.php';$key='test-signed-carrier-key';$account='AC'.str_repeat('a',32);
    $form=['AccountSid'=>$account,'MessageSid'=>'SM'.str_repeat($hex,32),'From'=>'+16025550999','To'=>'+16025550101','Body'=>$body,'NumMedia'=>'0'];
    return vp3_phone_sms_ingest_v110(db(),$form,vp3_phone_sms_twilio_signature_v110($url,$form,$key),$url,$key,$account);
}
function bodies(int $owner,int $thread): array {return array_column(vp3_phone_sms_messages_v110(db(),$owner,$thread),'body');}
function assign(int $owner,int $agent): void {
    $current=db()->query('SELECT owner_user_id,agent_id FROM phone_cloud_numbers_v100 WHERE id=1')->fetch();
    db()->prepare('INSERT INTO phone_browser_numbers_v1 VALUES (1,?,?,1,1)')->execute([(int)$current['owner_user_id'],(int)$current['agent_id']]);
    vp3_phone_assign_v100(db(),1,$owner,$agent);
    assignment_check((int)db()->query('SELECT sms_enabled FROM phone_cloud_numbers_v100 WHERE id=1')->fetchColumn()===0,'Assignment must revoke SMS until explicitly reactivated');
    assignment_check((int)db()->query('SELECT COUNT(*) FROM phone_browser_numbers_v1')->fetchColumn()===0,'Assignment revokes the former browser-answering and recording approvals');
    assignment_check((int)db()->query('SELECT enabled FROM profile_phone_settings_v1 WHERE owner_user_id=1')->fetchColumn()===0,'Assignment and returning assignments cannot restore an old public Profile line approval');
    // Local fixture activation only. No carrier API is contacted by this test.
    db()->exec('UPDATE phone_cloud_numbers_v100 SET sms_enabled=1 WHERE id=1');
}

fixture(true);
assignment_check(!vp3_phone_sms_assignment_schema_ready_v111($pdo),'Legacy schema cannot be reported current');
try {vp3_phone_assign_v100($pdo,1,2,8);throw new LogicException('Assignment bypassed the legacy storage upgrade');}
catch(RuntimeException $error) {assignment_check(str_contains($error->getMessage(),'upgrade'),'Administrator reassignment requires completed assignment-aware storage');}
$pdo->exec("INSERT INTO phone_sms_threads_v110(owner_user_id,phone_number_id,agent_id,contact_e164) VALUES (1,1,7,'+16025550999')");
$oldId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO phone_sms_messages_v110(thread_id,owner_user_id,provider,provider_message_sid,direction,body,delivery_status) VALUES (?,1,'twilio',?,'inbound','Owner A private history','received')")
    ->execute([$oldId,'SM'.str_repeat('1',32)]);
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
try {incoming('2','Would be stranded before upgrade');throw new LogicException('Legacy uniqueness was mistaken for a duplicate receipt');}
catch(RuntimeException $error) {assignment_check(str_contains($error->getMessage(),'upgrade'),'Signed ingress is blocked before writing to legacy assignment storage');}
assignment_check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_messages_v110')->fetchColumn()===1,'Failed legacy ingress leaves no partial message');
// Reproduce already persisted mixed-owner records from the previous version.
$put=$pdo->prepare("INSERT INTO phone_sms_messages_v110(thread_id,owner_user_id,provider,provider_message_sid,direction,body,delivery_status) VALUES (?,?,'twilio',?,'inbound',?,'received')");
$put->execute([$oldId,2,'SM'.str_repeat('3',32),'Owner B stranded history']);
$put->execute([$oldId,3,'SM'.str_repeat('4',32),'Former owner historical text']);
$pdo->exec("UPDATE phone_sms_messages_v110 SET created_at='2030-01-01 00:00:00' WHERE owner_user_id<>1");
$pdo->exec("UPDATE phone_sms_threads_v110 SET last_message_at='2030-01-01 00:00:00' WHERE id=".$oldId);
vp3_phone_sms_ensure_assignments_v111($pdo);
assignment_check(vp3_phone_sms_assignment_schema_ready_v111($pdo),'Upgrade receipt and assignment uniqueness are required');
assignment_check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_messages_v110 m JOIN phone_sms_threads_v110 t ON t.id=m.thread_id WHERE m.owner_user_id<>t.owner_user_id')->fetchColumn()===0,'Migration repairs all mixed-owner links');
assignment_check(bodies(1,$oldId)===['Owner A private history'],'Migration preserves the original owner and message identity');
assignment_check($pdo->query('SELECT last_message_at FROM phone_sms_threads_v110 WHERE id='.$oldId)->fetchColumn()===$pdo->query('SELECT created_at FROM phone_sms_messages_v110 WHERE owner_user_id=1')->fetchColumn(),'Former owner thread metadata excludes another owner later message activity');
$bArchived=(int)$pdo->query('SELECT id FROM phone_sms_threads_v110 WHERE owner_user_id=2 AND agent_id=0')->fetchColumn();
$archived=(int)$pdo->query('SELECT id FROM phone_sms_threads_v110 WHERE owner_user_id=3 AND agent_id=0')->fetchColumn();
assignment_check($bArchived>0 && bodies(2,$bArchived)===['Owner B stranded history'],'Current owner recovers stranded text without inventing its historical Agent');
assignment_check($archived>0 && bodies(3,$archived)===['Former owner historical text'],'Unknown historical Agent is not inferred or granted to another Agent');
foreach ([[1,$bArchived],[2,$oldId],[2,$archived],[3,$bArchived]] as [$owner,$thread]) {
    try {bodies($owner,$thread);throw new LogicException('Another owner read a private thread');}
    catch(RuntimeException $error) {assignment_check($error->getMessage()==='Conversation not found.','Cross-owner reads stay denied');}
}
$before=$pdo->query('SELECT id,owner_user_id,agent_id,unread_count FROM phone_sms_threads_v110 ORDER BY id')->fetchAll();
vp3_phone_sms_ensure_assignments_v111($pdo);
assignment_check($before===$pdo->query('SELECT id,owner_user_id,agent_id,unread_count FROM phone_sms_threads_v110 ORDER BY id')->fetchAll(),'Repeated upgrade does not duplicate history or inflate unread counts');
$next=incoming('5','Current owner B new message');
$bThread=(int)$next['thread_id'];
assignment_check($bThread!==$bArchived && bodies(2,$bThread)===['Current owner B new message'],'Signed inbound uses the current Agent without importing unknown historical Agent text');
assignment_check(incoming('5','Current owner B new message')['duplicate']===true,'Provider receipt replay remains idempotent');
assign(1,7);
assignment_check((int)incoming('6','Owner A returns')['thread_id']===$oldId,'A returning owner can resume their own Agent history');
$draft=vp3_phone_sms_draft_v120($pdo,1,1,'+16025550999','Old Agent draft','Explicit fixture recipient consent');
assign(1,10);
$new=incoming('7','Second Agent A only');$newId=(int)$new['thread_id'];
assignment_check($newId!==$oldId && bodies(1,$newId)===['Second Agent A only'],'Same-owner Agent reassignment starts a separate conversation');
assignment_check(vp3_phone_sms_threads_v110($pdo,1,7)===[],'A former Agent cannot read the current assignment through scoped history');
assignment_check(count(vp3_phone_sms_threads_v110($pdo,1,10))===1,'Current Agent sees its own assignment thread');
$sends=0;
$carrier=static function() use (&$sends): array {$sends++;return ['sid'=>'SM'.str_repeat('8',32)];};
try {vp3_phone_sms_authorize_send_v120($pdo,1,$draft,'SEND +16025550999',$carrier);throw new LogicException('Old Agent draft reached carrier');}
catch(RuntimeException $error) {assignment_check(str_contains($error->getMessage(),'reassigned'),'Saved Agent mismatch blocks a draft before dispatch');}
assignment_check($sends===0,'An obsolete draft never contacts the carrier');
$draft=vp3_phone_sms_draft_v120($pdo,1,1,'+16025550999','Current Agent outbound','Explicit fixture recipient consent');
$sent=vp3_phone_sms_authorize_send_v120($pdo,1,$draft,'SEND +16025550999',$carrier);
assignment_check($sends===1 && (int)$sent['thread_id']===$newId,'Owner-approved outgoing history uses the exact current Agent thread');
assignment_check(bodies(1,$newId)===['Second Agent A only','Current Agent outbound'],'Outgoing history contains no former owner or Agent message');
assignment_check(bodies(2,$bThread)===['Current owner B new message'] && bodies(2,$bArchived)===['Owner B stranded history'],'Reassignment never rewrites another owner historical text');
echo 'PHONE_ASSIGNMENT_V111=PASS ('.$checks.' checks, '.($mysql?'MySQL':'SQLite').')'.PHP_EOL;

fixture(false);
vp3_phone_sms_ensure_assignments_v111($pdo);
assignment_check(vp3_phone_sms_assignment_schema_ready_v111($pdo),'A fresh assignment-aware schema also gets an explicit migration receipt');
assignment_check(incoming('9','Fresh installation')['accepted']===true,'Fresh installations accept signed SMS');
echo "PHONE_ASSIGNMENT_V111_FRESH=PASS\n";
