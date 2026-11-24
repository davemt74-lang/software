<?php
declare(strict_types=1);
// Production helpers only; no live carrier, speech, model or configuration request.
define('STONEFELLOW_ROOT',dirname(__DIR__));
require dirname(__DIR__).'/includes/phone-browser-v1.php';
require dirname(__DIR__).'/includes/phone-browser-endpoint-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-specialist-v1.php';
require dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-history-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-followups-v1.php';
$dsn=getenv('VP3_PHONE_MYSQL_DSN')?:'sqlite::memory:';$mysql=str_starts_with($dsn,'mysql:');
$pdo=new PDO($dsn,$mysql?(getenv('VP3_PHONE_MYSQL_USER')?:'root'):null,$mysql?(getenv('VP3_PHONE_MYSQL_PASSWORD')?:''):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare($GLOBALS['mysql']?'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool{if($GLOBALS['mysql']){$q=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();}return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name'),true);}
function agent_scheduling_schema_ready_v430(?PDO $pdo=null):bool{return table_exists('agent_scheduling_bookings');}
function ddl(string $sql): void{if($GLOBALS['mysql'])$sql=str_replace(['INTEGER PRIMARY KEY AUTOINCREMENT','provider_call_sid TEXT','contact_e164 TEXT'],['INTEGER AUTO_INCREMENT PRIMARY KEY','provider_call_sid VARCHAR(40)','contact_e164 VARCHAR(20)'],$sql);db()->exec($sql);}
function has_permission(string $key,?array $user=null): bool{return !empty($user['is_active'])&&empty($user['denied']);}
function subscription_has_entitlement(?array $user,string $key): bool{return !empty($user['plan'])&&($key!=='phone.voice'||empty($GLOBALS['phone_fixture_voice_denied']));}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int{return 20;}
function setting(string $key,mixed $default=''): mixed{return $GLOBALS['settings'][$key]??$default;}
function save_setting(string $key,string $value): void{$GLOBALS['settings'][$key]=$value;}
function ai_encrypt_secret(string $value): string{return 'fixture-encrypted:'.base64_encode($value);}
function ai_decrypt_secret(string $value): string{if(!str_starts_with($value,'fixture-encrypted:'))throw new RuntimeException('Unreadable secret');return base64_decode(substr($value,18),true)?:'';}
function ai_encrypted_secret_state(string $value): string{try{return ai_decrypt_secret($value)!==''?'ready':'empty';}catch(Throwable){return 'invalid';}}
function url(string $path): string{return 'https://cloud.example'.$path;}
// An external DSN is an explicitly dedicated disposable test database, like the existing phone assignment fixture.
if($mysql)foreach(['phone_voicemail_deletions_v1','phone_voicemail_greeting_tokens_v1','phone_voicemail_receipts_v1','phone_voicemail_settings_v1','phone_native_dialthrough_v1','phone_managed_recording_consent_v1','phone_managed_callback_controls_v1','phone_managed_callback_dispatch_v1','phone_managed_callback_requests_v1','vp3_paired_phone_threads_v1','vp3_phone_communication_profiles_v1','vp3_phone_devices_v1','phone_operator_owner_preferences_v1','phone_operator_live_sessions_v1','user_calendar_events','agent_scheduling_bookings','phone_operator_contact_policies_v1','artist_transcript_segments_v172','artist_transcript_sessions_v172','phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_numbers_v1','phone_browser_favorites_v1','phone_browser_preferences_v1','phone_browser_calls_v1','phone_voice_recordings_v130','phone_voice_calls_v130','crm_contact_phones','crm_contacts','chat_messages','chat_conversations','phone_sms_assignment_migrations_v111','phone_sms_threads_v110','phone_cloud_numbers_v100','user_agents','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
ddl('CREATE TABLE users(id '.($mysql?'INT UNSIGNED':'INTEGER').' PRIMARY KEY,is_active INTEGER,plan INTEGER,denied INTEGER DEFAULT 0)');
ddl('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
ddl('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider VARCHAR(24),provider_sid VARCHAR(40),phone_e164 VARCHAR(20),owner_user_id INTEGER,agent_id INTEGER,status VARCHAR(24),voice_enabled INTEGER)');
ddl('CREATE TABLE phone_sms_threads_v110(id INTEGER PRIMARY KEY,phone_number_id INTEGER,owner_user_id INTEGER,agent_id INTEGER,contact_e164 TEXT,UNIQUE(phone_number_id,owner_user_id,agent_id,contact_e164))');
ddl('CREATE TABLE phone_sms_assignment_migrations_v111(id INTEGER PRIMARY KEY)');$pdo->exec('INSERT INTO phone_sms_assignment_migrations_v111 VALUES(1)');
ddl('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,title TEXT,created_at DATETIME,updated_at DATETIME)');
ddl('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at DATETIME)');
ddl("CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id VARCHAR(36) NOT NULL UNIQUE,owner_user_id INTEGER NOT NULL,name VARCHAR(120) NOT NULL,email VARCHAR(190) NOT NULL DEFAULT '',email_normalized VARCHAR(190) NOT NULL,phone VARCHAR(80) NOT NULL DEFAULT '',company VARCHAR(190) NOT NULL DEFAULT '',source VARCHAR(40) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL DEFAULT 'active',lifecycle_stage VARCHAR(40) NOT NULL DEFAULT '',marketing_status VARCHAR(24) NOT NULL DEFAULT 'unknown',created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE(owner_user_id,email_normalized))");
ddl("CREATE TABLE crm_contact_phones(id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER NOT NULL,phone VARCHAR(80) NOT NULL,phone_normalized VARCHAR(80) NOT NULL,label VARCHAR(40) NOT NULL DEFAULT 'primary',is_primary INTEGER NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,UNIQUE(contact_id,phone_normalized))");
ddl("CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,provider VARCHAR(24),provider_call_sid TEXT,owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 TEXT,called_e164 TEXT,state VARCHAR(24) DEFAULT 'voicemail_offered',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid))");
ddl('CREATE TABLE phone_voice_recordings_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,call_id INTEGER,owner_user_id INTEGER,provider VARCHAR(24),provider_recording_sid VARCHAR(40),recording_status VARCHAR(24),duration_seconds INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_recording_sid))');
$pdo->exec("INSERT INTO users VALUES(1,1,1,0),(2,1,1,0),(3,1,1,0),(4,0,1,0),(5,1,1,1)");
$pdo->exec("INSERT INTO user_agents VALUES(7,1,1,'First Agent'),(8,2,1,'Foreign Agent'),(9,1,1,'Second Agent'),(10,4,1,'Disabled'),(11,5,1,'Denied'),(12,1,0,'Archived')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES(1,'twilio','PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','+16025550101',1,7,'ready',1),(2,'twilio','PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','+16025550102',2,8,'ready',1),(3,'twilio','PNcccccccccccccccccccccccccccccccc','+16025550103',1,9,'ready',1),(4,'twilio','PNdddddddddddddddddddddddddddddddd','+16025550104',4,10,'ready',1),(5,'twilio','PNeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','+16025550105',5,11,'ready',1),(6,'twilio','PNffffffffffffffffffffffffffffffff','+16025550106',1,12,'ready',1)");
$pdo->exec("INSERT INTO chat_conversations VALUES(11,1,NULL,'Main','2026-01-01','2026-01-01'),(12,1,7,'First','2026-01-01','2026-01-01'),(13,1,9,'Second','2026-01-01','2026-01-01'),(21,2,NULL,'Foreign','2026-01-01','2026-01-01')");
$pdo->exec("INSERT INTO crm_contacts(id,public_id,owner_user_id,name,email,email_normalized,phone,status,created_at,updated_at) VALUES(1,'11111111-1111-4111-8111-111111111111',1,'Mine','mine@example.test','mine@example.test','+16025550999','active','2026-01-01','2026-01-01'),(2,'22222222-2222-4222-8222-222222222222',2,'Foreign','foreign@example.test','foreign@example.test','+16025550888','active','2026-01-01','2026-01-01'),(3,'33333333-3333-4333-8333-333333333333',1,'Archived','archived@example.test','archived@example.test','+16025550777','archived','2026-01-01','2026-01-01')");
$GLOBALS['settings']=['phone_twilio_account_sid'=>'AC'.str_repeat('a',32),'phone_twilio_auth_token'=>ai_encrypt_secret('carrier-auth-token-fixture'),'phone_voice_webhook_url_v130'=>'https://cloud.example/api/phone-voice-inbound-v130.php'];
$checks=0;
function c(bool $ok,string $label): void{if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function denies(callable $fn,int $status,string $label): void{try{$fn();throw new LogicException('Missing denial: '.$label);}catch(VP3PhoneWorkspaceException $e){c($e->httpStatus===$status,$label);}}
function bad(callable $fn,string $label): void{try{$fn();throw new LogicException('Missing rejection: '.$label);}catch(InvalidArgumentException|RuntimeException $e){c(true,$label);}}
function rejects_message(callable $fn,string $message,string $label): void{try{$fn();}catch(RuntimeException $e){c(str_contains($e->getMessage(),$message),$label);return;}c(false,$label);}
function user(int $id=1): array{$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();}
function api(string $method,array $input=[],int $owner=1,string $csrf='csrf-fixture'): array{return vp3_phone_workspace_request_v1(db(),user($owner),$method,$input,$csrf);}
function action(string $name,array $input=[],int $owner=1): array{return api('POST',array_replace(['action'=>$name,'csrf_token'=>'csrf-fixture','conversation_id'=>11,'agent_id'=>0],$input),$owner);}
function form(string $sid,array $extra=[]): array{return ['AccountSid'=>setting('phone_twilio_account_sid'),'CallSid'=>$sid]+$extra;}
function signature(string $kind,array $form,string $public=''): string{return vp3_phone_sms_twilio_signature_v110(vp3_phone_browser_callback_v1($kind,$public!==''?['call'=>$public]:[]),$form,'carrier-auth-token-fixture');}
function readcall(string $public): array{$q=db()->prepare('SELECT * FROM phone_browser_calls_v1 WHERE public_id=?');$q->execute([$public]);return $q->fetch();}
function prepare(bool $record=false,int $number=1): array{return action('prepare_call',['number_id'=>$number,'to_number'=>'+16025550999','recording_enabled'=>$record]);}
function start(array $prepared,string $hex='b',array $extra=[]): array{$call=$prepared['call'];$f=form('CA'.str_repeat($hex,32),['From'=>'client:'.vp3_phone_browser_identity_v1(1,0),'call_intent'=>$prepared['connect_params']['call_intent']]+$extra);return [vp3_phone_browser_voice_v1(db(),$f,signature('voice',$f)),$f];}
function status(array $call,string $value,string $hex='c',array $extra=[],bool $complete=false): array{$f=form($complete?(string)$call['provider_call_sid']:'CA'.str_repeat($hex,32),$complete?['DialCallSid'=>'CA'.str_repeat($hex,32),'DialCallStatus'=>$value]:['ParentCallSid'=>$call['provider_call_sid'],'CallStatus'=>$value]);$f=array_replace($f,$extra);return vp3_phone_browser_status_v1(db(),$f,signature($complete?'complete':'status',$f,$call['public_id']),$call['public_id'],$complete);}

c(!vp3_phone_workspace_schema_ready_v1($pdo),'unreleased tables cannot masquerade as installed');
vp3_phone_workspace_ensure_schema_v1($pdo);c(vp3_phone_workspace_schema_ready_v1($pdo),'explicit schema installs phone workspace');
// Reading the existing Phone workspace never installs optional recording tables.
$pdo->exec("INSERT INTO phone_voice_calls_v130(id,provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,state,created_at) VALUES(90001,'twilio','CA00000000000000000000000000090001',1,1,7,'+16025550999','+16025550101','completed','2026-01-01 01:00:00')");
$pdo->exec("INSERT INTO phone_voice_recordings_v130(id,call_id,owner_user_id,provider,provider_recording_sid,recording_status,duration_seconds,created_at) VALUES(90001,90001,1,'twilio','RE00000000000000000000000000090001','completed',12,'2026-01-01 01:00:00')");
$recordingUpgradeReason='Phone recordings need the Cloud database upgrade. Ask an administrator to run upgrade.php, then retry.';
$unupgraded=api('GET')['state'];
c(count($unupgraded['numbers'])===2&&count($unupgraded['contacts'])===1&&count($unupgraded['calls'])===1,'missing optional recording storage preserves assigned lines, contacts and call history');
c($unupgraded['recording_readiness']===['ready'=>false,'reason'=>$recordingUpgradeReason]&&$unupgraded['post_call_policy']===null&&$unupgraded['voicemails']===[],'missing recording storage explicitly reports unavailable recordings instead of a successful empty inbox');
c(!table_exists('phone_recording_jobs_v1')&&!table_exists('phone_recording_policies_v1'),'Phone state reads do not run the recording migration');
vp3_phone_recording_ensure_schema_v1($pdo);
$upgraded=api('GET')['state'];
c($upgraded['recording_readiness']===['ready'=>true,'reason'=>'']&&is_array($upgraded['post_call_policy'])&&count($upgraded['voicemails'])===1,'explicit recording upgrade restores policy and the existing voicemail');
foreach(['phone_recording_policies_v1','phone_recording_jobs_v1','phone_recording_legacy_reads_v1','phone_recording_speech_budget_v1'] as $recordingTable){
    $pdo->exec('DROP TABLE '.$recordingTable);
    $partial=api('GET')['state'];
    c($partial['recording_readiness']===['ready'=>false,'reason'=>$recordingUpgradeReason]&&$partial['post_call_policy']===null&&$partial['voicemails']===[],'partial recording upgrade is explicit when '.$recordingTable.' is missing');
    c($partial['numbers']===$upgraded['numbers']&&$partial['contacts']===$upgraded['contacts']&&$partial['calls']===$upgraded['calls']&&!table_exists($recordingTable),'missing '.$recordingTable.' cannot break other Phone sections or silently recreate storage');
    vp3_phone_recording_ensure_schema_v1($pdo);
    c(api('GET')['state']['recording_readiness']['ready'],'replaying the recording installer recovers '.$recordingTable);
}
$pdo->exec('ALTER TABLE phone_managed_callback_dispatch_v1 DROP COLUMN pilot_required');
try{api('GET');throw new LogicException('Missing core upgrade rejection.');}catch(VP3PhoneWorkspaceException $e){c($e->httpStatus===503&&$e->publicCode==='phone_upgrade_required','a missing managed/core migration retains the typed upgrade failure');}
vp3_phone_workspace_ensure_schema_v1($pdo);
vp3_phone_recording_ensure_schema_v1($pdo);
$recovered=api('GET')['state'];
c($recovered['recording_readiness']['ready']&&$recovered['numbers']===$upgraded['numbers']&&$recovered['calls']===$upgraded['calls']&&count($recovered['voicemails'])===1,'replaying explicit installers restores the complete Phone state without losing history');
$pdo->exec('DELETE FROM phone_voice_recordings_v130 WHERE id=90001');
$pdo->exec('DELETE FROM phone_voice_calls_v130 WHERE id=90001');
c(!vp3_operator_auto_call_schema_ready_v1($pdo),'missing optional Operator migration does not create schema on read');
c(api('GET')['state']['auto_call']===['available'=>false,'execution_enabled'=>false,'policies'=>[]],'unupgraded Operator preferences do not disable manual phone access');
denies(fn()=>action('save_auto_call_policy',['contact_id'=>1,'number_id'=>1,'enabled'=>false,'expected_revision'=>0]),503,'Operator preferences require an explicit upgrade');
$pdo->exec('DELETE FROM phone_sms_assignment_migrations_v111');c(!vp3_phone_workspace_schema_ready_v1($pdo),'old v110 schema does not grant readiness');$pdo->exec('INSERT INTO phone_sms_assignment_migrations_v111 VALUES(1)');
c(vp3_phone_workspace_available_v1($pdo,user()),'actual owned active assignment exposes Phone');
c(!vp3_phone_workspace_available_v1($pdo,user(3)),'plan alone never exposes Phone');
c(!vp3_phone_workspace_available_v1($pdo,user(4))&&!vp3_phone_workspace_available_v1($pdo,user(5)),'account activity and permissions rechecked');
denies(fn()=>vp3_phone_workspace_request_v1($pdo,null,'GET',[],''),401,'authentication required');
denies(fn()=>api('GET',['action'=>'token']),405,'token never issued by GET');
denies(fn()=>api('POST',['action'=>'token','csrf_token'=>'forged']),403,'mutation rejects forged CSRF');
denies(fn()=>api('POST',['action'=>'token']),403,'mutation rejects absent CSRF');
denies(fn()=>api('GET',[],3),403,'API rejects plan without assignment');
denies(fn()=>api('GET',['conversation_id'=>21]),403,'conversation owner IDOR denied');
denies(fn()=>api('GET',['conversation_id'=>12,'agent_id'=>9]),403,'conversation Agent namespace enforced');
denies(fn()=>api('GET',['agent_id'=>8]),403,'foreign Agent denied');
$state=api('GET')['state'];c(count($state['numbers'])===2&&count($state['contacts'])===1,'number and contact projection is owner-only and active');
c(!$state['contact_write_ready']&&vp3_phone_workspace_available_v1($pdo,user()),'pending contact upgrade leaves existing phone access available');
denies(fn()=>action('save_caller_contact',['call_id'=>str_repeat('0',32),'name'=>'Caller']),503,'contact creation requires an explicit CRM upgrade rather than runtime DDL');
vp3_phone_caller_contacts_ensure_schema_v1($pdo);
c(api('GET')['state']['contact_write_ready'],'explicit CRM upgrade enables contact saving without changing phone readiness');
c((int)$pdo->query('SELECT COUNT(*) FROM crm_contacts')->fetchColumn()===3,'contact migration preserves existing people');
c(!$state['readiness']['ready'],'missing API credentials are not ready');
vp3_phone_browser_config_save_v1(['api_key_sid'=>'SK'.str_repeat('b',32),'api_key_secret'=>'api-secret-fixture-123456','twiml_app_sid'=>'AP'.str_repeat('c',32)]);
c(setting('phone_browser_api_key_secret_v1')!=='api-secret-fixture-123456','API secret encrypted at rest');
vp3_phone_browser_config_save_v1(['api_key_sid'=>'SK'.str_repeat('b',32),'twiml_app_sid'=>'AP'.str_repeat('c',32)]);
bad(fn()=>vp3_phone_browser_config_save_v1(['api_key_sid'=>'SK'.str_repeat('d',32),'twiml_app_sid'=>'AP'.str_repeat('c',32)]),'changing API key requires replacement secret');
c(!api('GET')['state']['readiness']['ready'],'configured provider without explicit number browser approval is not ready');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET voice_enabled=0 WHERE id=1');
vp3_phone_browser_number_policy_set_v1($pdo,1,false,true);
$savedPolicy=vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7]);
c(!(int)$savedPolicy['browser_enabled']&&(int)$savedPolicy['recording_enabled']===1,'Admin may save disabled browser preferences while an activation prerequisite is absent');
rejects_message(fn()=>vp3_phone_browser_number_policy_set_v1($pdo,1,true,false),'Enable incoming voicemail','disabled incoming voice identifies its exact activation control');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7])===$savedPolicy,'incoming-voice rejection preserves the existing browser and recording policy');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET voice_enabled=1 WHERE id=1');
$pdo->exec('UPDATE users SET plan=0 WHERE id=1');
rejects_message(fn()=>vp3_phone_browser_number_policy_set_v1($pdo,1,true,false),'Agent Phone Numbers Included','missing number allowance is distinguished from missing voice allowance');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7])===$savedPolicy,'number-allowance rejection preserves the existing policy');
$pdo->exec('UPDATE users SET plan=1 WHERE id=1');
$GLOBALS['phone_fixture_voice_denied']=true;
rejects_message(fn()=>vp3_phone_browser_number_policy_set_v1($pdo,1,true,false),'Agent Phone Calls (phone.voice)','missing voice allowance identifies the assigned member capability');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7])===$savedPolicy,'voice-allowance rejection preserves the existing policy');
unset($GLOBALS['phone_fixture_voice_denied']);
$savedBrowserSettings=$GLOBALS['settings'];unset($GLOBALS['settings']['phone_browser_api_key_sid_v1']);
rejects_message(fn()=>vp3_phone_browser_number_policy_set_v1($pdo,1,true,false),'Twilio API Key SID, API Key Secret and TwiML App SID','missing browser credentials identify the separate Browser phone setup');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7])===$savedPolicy,'credential rejection preserves the existing policy');
$GLOBALS['settings']=$savedBrowserSettings;unset($GLOBALS['settings']['phone_voice_webhook_url_v130']);
rejects_message(fn()=>vp3_phone_browser_number_policy_set_v1($pdo,1,true,false),'Signed HTTPS voice webhook URL under Incoming calls','missing callback identifies the incoming-voice configuration field');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>1,'agent_id'=>7])===$savedPolicy,'callback rejection preserves the existing policy');
$GLOBALS['settings']=$savedBrowserSettings;
vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);vp3_phone_browser_number_policy_set_v1($pdo,3,true,true);
c(api('GET')['state']['readiness']['ready'],'explicit browser approval plus provider credentials becomes ready');
c(vp3_operator_call_status_v1($pdo,user(),0,11,time())['state']==='idle','Operator can monitor an eligible private MAIN Chat');
c(vp3_operator_call_status_v1($pdo,user(),7,12,time())['state']==='idle','Operator can monitor an eligible named Agent Chat');
c(vp3_operator_call_status_v1($pdo,user(),0,21,time())===null,'Operator denies a foreign conversation');
c(vp3_operator_call_status_v1($pdo,user(),9,12,time())===null,'Operator denies a mismatched Agent and Chat');
c(vp3_operator_call_status_v1($pdo,user(3),0,0,time())===null,'Operator cannot confer a number assignment');
$GLOBALS['phone_fixture_voice_denied']=true;
c(vp3_operator_call_status_v1($pdo,user(),0,11,time())===null,'Operator cannot confer a revoked voice allowance');
unset($GLOBALS['phone_fixture_voice_denied']);
$token=action('token',['number_id'=>1]);$parts=explode('.',$token['token']);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
c($claims['sub']===setting('phone_twilio_account_sid')&&$claims['iss']===setting('phone_browser_api_key_sid_v1')&&$claims['exp']-$claims['iat']===300,'short JWT binds configured Twilio account and API key');
c($claims['grants']['identity']===$token['identity']&&$claims['grants']['voice']['incoming']['allow']&&$claims['grants']['voice']['outgoing']['application_sid']===setting('phone_browser_twiml_app_sid_v1'),'JWT grants only owner identity and configured TwiML app');
c($claims['grants']['identity']==='vp3_phone_u1_a0'&&preg_match('/^[A-Za-z0-9_]{1,121}$/D',$token['identity'])===1,'actual MAIN Voice token uses the carrier identity character and length contract');
$namedToken=action('token',['number_id'=>1,'agent_id'=>7,'conversation_id'=>12]);$namedClaims=json_decode(base64_decode(strtr(explode('.',$namedToken['token'])[1],'-_','+/')),true);
c($namedClaims['grants']['identity']===$namedToken['identity']&&$namedToken['identity']==='vp3_phone_u1_a7'&&preg_match('/^[A-Za-z0-9_]{1,121}$/D',$namedToken['identity'])===1,'actual named-Agent Voice token uses the same carrier identity contract');
vp3_phone_browser_number_policy_set_v1($pdo,2,true,false);$otherToken=action('token',['number_id'=>2,'conversation_id'=>21],2);
c($otherToken['identity']==='vp3_phone_u2_a0'&&count(array_unique([$token['identity'],$namedToken['identity'],$otherToken['identity']]))===3,'Voice identity keeps owner and Chat Agent namespaces distinct');
c(preg_match('/^[A-Za-z0-9_]{1,121}$/D',vp3_phone_browser_identity_v1(PHP_INT_MAX,PHP_INT_MAX))===1,'maximum supported numeric identities stay within the carrier character and length limits');
action('token',['number_id'=>1]);
c(hash_equals(vp3_phone_browser_base64_v1(hash_hmac('sha256',$parts[0].'.'.$parts[1],'api-secret-fixture-123456',true)),$parts[2]),'JWT authenticated by API secret');
c(!str_contains(json_encode($token),'carrier-auth-token-fixture')&&!str_contains(json_encode($token),'api-secret-fixture'),'response excludes credentials');
denies(fn()=>action('token',['number_id'=>2]),404,'foreign assigned caller ID denied');
denies(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'+16025550999','conversation_id'=>0]),409,'outgoing requires canonical private Chat');
bad(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'911']),'destination requires international E164');
denies(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'+16025550999','recording_enabled'=>'true']),422,'recording requires explicit JSON boolean');
$first=prepare();$public=$first['call']['public_id'];c($first['call']['agent_id']===7&&$first['call']['conversation_id']===11,'number Agent7 can place call in owner MAIN agent0');
c(!str_contains(json_encode($first['call']),'intent')&&!str_contains(json_encode($first['call']),'provider_call_sid'),'public call does not disclose carrier handles or intent hash');
c(readcall($public)['intent_hash']===hash('sha256',$first['connect_params']['call_intent']),'only hashed short-lived intent persisted');
c(vp3_operator_call_status_v1($pdo,user(),0,11,time())['state']==='idle','Operator never claims an unconsumed dial intent is a live call');
c(vp3_operator_history_v1($pdo,user(),0,11)['calls']===[],'Operator history excludes an unconsumed intent');
denies(fn()=>prepare(),409,'one prepared call prevents parallel dial');
action('cancel_prepared',['call_id'=>$public]);c(readcall($public)['state']==='canceled','preconnect failure can release unconsumed intent');
$out=prepare(true);$public=$out['call']['public_id'];c(readcall($public)['recording_enabled']==1,'explicit recording plus admin approval retained');
$wrong=form('CA'.str_repeat('b',32),['From'=>'client:'.vp3_phone_browser_identity_v1(2),'call_intent'=>$out['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$wrong,signature('voice',$wrong)),'Hangup')&&readcall($public)['intent_consumed_epoch']==0,'another client identity cannot consume intent');
$legacyIdentity=form('CA'.str_repeat('b',32),['From'=>'client:vp3-phone-u1-a0','call_intent'=>$out['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$legacyIdentity,signature('voice',$legacyIdentity)),'Hangup')&&readcall($public)['intent_consumed_epoch']==0,'a nonconforming former identity cannot consume a new underscore-identity intent');
bad(fn()=>vp3_phone_browser_voice_v1($pdo,$wrong,'forged'),'forged Twilio signature rejected');
$wrong['AccountSid']='AC'.str_repeat('f',32);bad(fn()=>vp3_phone_browser_voice_v1($pdo,$wrong,signature('voice',$wrong)),'cross Twilio account rejected');
[$xml,$voice]=start($out,'b',['To'=>'+16025550000','CallerId'=>'+16025550001']);
c($voice['From']==='client:'.$token['identity']&&str_contains($xml,'<Dial '),'the actual MAIN token identity is accepted by the signed outbound From gate');
c(str_contains($xml,'callerId="+16025550101"')&&str_contains($xml,'>+16025550999</Number>')&&!str_contains($xml,'+16025550000'),'dial target and owned caller ID are immutable server values');
c(str_contains($xml,'<Number url="https://cloud.example/api/phone-browser-disclosure-v1.php?call='.$public)&&str_contains($xml,'record-from-answer-dual'),'recorded called leg uses fixed server whisper before bridge');
c(str_contains(vp3_phone_browser_voice_v1($pdo,$voice,signature('voice',$voice)),'Hangup'),'single-use intent replay cannot redial');
denies(fn()=>action('cancel_prepared',['call_id'=>$public]),409,'ambiguous carrier accepted call cannot be canceled as preparation');
$call=readcall($public);$whisper=form('CA'.str_repeat('c',32),['ParentCallSid'=>$call['provider_call_sid'],'From'=>$call['phone_e164'],'To'=>$call['peer_e164']]);
c(str_contains(vp3_phone_browser_disclosure_v1($pdo,$whisper,signature('disclosure',$whisper,$public),$public),'This call will be recorded'),'callee disclosure works before any child status callback');
c(readcall($public)['provider_child_sid']===$whisper['CallSid'],'whisper correlates and binds immutable child SID');
$spoof=$whisper;$spoof['CallSid']='CA'.str_repeat('d',32);c(str_contains(vp3_phone_browser_disclosure_v1($pdo,$spoof,signature('disclosure',$spoof,$public),$public),'Hangup'),'whisper cannot change bound child');
$spoof=$whisper;$spoof['To']='+16025550000';c(str_contains(vp3_phone_browser_disclosure_v1($pdo,$spoof,signature('disclosure',$spoof,$public),$public),'Hangup'),'whisper validates actual called party');
c(!status($call,'ringing','c',['ParentCallSid'=>'CA'.str_repeat('f',32)])['accepted'],'status rejects unrelated parent SID');
c(status($call,'in-progress')['accepted']&&readcall($public)['state']==='answered','signed called leg advances to answered');
$operatorBefore=readcall($public);$operatorNow=(int)$operatorBefore['updated_epoch'];
$operator=vp3_operator_call_status_v1($pdo,user(),0,11,$operatorNow);
c($operator['state']==='working'&&str_contains($operator['detail'],'answered')&&strtotime($operator['status_fresh_until'])===$operatorNow+300,'Operator presents fresh signed call state with a bounded freshness window');
c(array_keys($operator)===['id','conversation_id','state','progress','detail','status_fresh_until']&&!str_contains(json_encode($operator),'+16025550999'),'Operator status contains no contact numbers or provider credentials');
c(vp3_operator_call_status_v1($pdo,user(),7,12,$operatorNow)['state']==='idle','Operator cannot move call activity into another Agent Chat');
c(vp3_operator_call_status_v1($pdo,user(),0,0,$operatorNow)['state']==='idle','Operator cannot claim call activity without a saved Chat');
c(vp3_operator_call_status_v1($pdo,user(),0,11,$operatorNow+300)['state']==='unavailable','Operator does not imply stale carrier state is a live call');
c(vp3_operator_call_status_v1($pdo,user(),0,11,$operatorNow-1)['state']==='unavailable','Operator does not accept future carrier timestamps');
c(readcall($public)===$operatorBefore,'Operator reads do not mutate the canonical call receipt');
c(status($call,'ringing')['duplicate']&&readcall($public)['state']==='answered','out-of-order ringing cannot regress answered');
c(status($call,'completed','c',['CallDuration'=>'24'])['accepted']&&readcall($public)['state']==='completed','terminal callback finalizes duration');
c(vp3_operator_call_status_v1($pdo,user(),0,11,time())['state']==='idle','Operator clears activity after terminal carrier receipt');
c(status($call,'in-progress')['duplicate']&&readcall($public)['duration_seconds']==24,'late status cannot resurrect terminal call or erase duration');
$recording=form($call['provider_call_sid'],['RecordingSid'=>'RE'.str_repeat('a',32),'RecordingStatus'=>'completed','RecordingDuration'=>'24','RecordingUrl'=>'https://untrusted.example/never-fetch']);
$saved=vp3_phone_browser_recording_v1($pdo,$recording,signature('recording',$recording,$public),$public);
$operatorHistory=vp3_operator_history_v1($pdo,user(),0,11);$operatorCalls=array_column($operatorHistory['calls'],null,'call_id');
c($operatorHistory['coverage']==='ready'&&isset($operatorCalls[$public])&&$operatorCalls[$public]['contact_name']==='Mine'&&$operatorCalls[$public]['direction']==='outbound','Operator history projects a signed terminal call with its canonical contact');
c($operatorCalls[$public]['recording']===['state'=>'queued','transcript_saved'=>false,'report_saved'=>false],'Operator distinguishes a queued recording from saved results');
c(!str_contains(json_encode($operatorHistory),'provider_call_sid')&&!str_contains(json_encode($operatorHistory),'lease_token')&&!str_contains(json_encode($operatorHistory),'carrier-auth-token-fixture'),'Operator history does not export internal carrier or worker credentials');
c(vp3_operator_history_v1($pdo,user(),0,21)['coverage']==='unavailable'&&vp3_operator_history_v1($pdo,user(),9,12)['coverage']==='unavailable','Operator rejects foreign Chat and mismatched Agent scopes');
c(vp3_operator_history_v1($pdo,user(),0,0)['coverage']==='no_chat'&&vp3_operator_history_v1($pdo,user(2),0,21)['calls']===[],'Operator requires saved owner Chat and does not expose another owner history');
c(vp3_operator_history_v1($pdo,user(),9,13)['calls']===[]&&isset(array_column(vp3_operator_history_v1($pdo,user(),7,12)['calls'],null,'call_id')[$public]),'Operator named Agent view is scoped to the assigned call Agent');
c($saved['accepted']&&$saved['queued']&&str_starts_with($saved['recording']['id'],'job-'),'signed opted-in recording enqueues durable typed job without provider I/O');
c(!str_contains($pdo->query('SELECT policy_json FROM phone_recording_jobs_v1')->fetchColumn(),'untrusted.example'),'untrusted RecordingUrl is not persisted or fetched');
c(vp3_phone_browser_recording_v1($pdo,$recording,signature('recording',$recording,$public),$public)['duplicate'],'recording receipt replay is idempotent');
bad(fn()=>vp3_phone_browser_recording_v1($pdo,$recording,'forged',$public),'recording requires valid carrier signature');
$crossRecord=$recording;$crossRecord['CallSid']='CA'.str_repeat('f',32);c(!vp3_phone_browser_recording_v1($pdo,$crossRecord,signature('recording',$crossRecord,$public),$public)['accepted'],'recording cannot attach to unrelated signed call');
$before=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();vp3_phone_workspace_delivery_v1($pdo,readcall($public),'call','call:'.$public,'Duplicate.');c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,'one canonical Chat message per call under replay');
$context=json_decode($pdo->query('SELECT context_json FROM chat_messages ORDER BY id DESC LIMIT 1')->fetchColumn(),true);c($context['phone_v1']['call_id']===$public&&!str_contains(json_encode($context),'CA'.str_repeat('b',32)),'Chat card contains stable public call reference only');

// Presence and identity obey the current Chat Agent; MAIN can answer all owned assignments.
action('token',['number_id'=>1,'agent_id'=>7,'conversation_id'=>12]);
$namedPrepared=action('prepare_call',['number_id'=>1,'to_number'=>'+16025550999','agent_id'=>7,'conversation_id'=>12]);$namedPublic=$namedPrepared['call']['public_id'];
$namedForm=form('CA'.str_repeat('ab',16),['From'=>'client:'.$token['identity'],'call_intent'=>$namedPrepared['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$namedForm,signature('voice',$namedForm)),'Hangup')&&readcall($namedPublic)['intent_consumed_epoch']==0,'MAIN identity cannot consume a named-Agent outgoing intent');
$namedForm['From']='client:'.$namedToken['identity'];$namedXml=vp3_phone_browser_voice_v1($pdo,$namedForm,signature('voice',$namedForm));
c(str_contains($namedXml,'<Dial ')&&readcall($namedPublic)['intent_consumed_epoch']>0,'the actual named-Agent token identity is accepted by the signed outbound From gate');
$namedStatus=form('CA'.str_repeat('ac',16),['ParentCallSid'=>$namedForm['CallSid'],'CallStatus'=>'completed']);
c(vp3_phone_browser_status_v1($pdo,$namedStatus,signature('status',$namedStatus,$namedPublic),$namedPublic)['accepted'],'named-Agent identity regression completes its called leg');
$namedIncomingSid='CA'.str_repeat('ad',16);$pdo->prepare('INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164) VALUES (?,?,?,?,?,?,?)')->execute(['twilio',$namedIncomingSid,1,1,7,'+16025550888','+16025550101']);
$namedIncoming=vp3_phone_browser_incoming_v1($pdo,['phone_number_id'=>1,'owner_user_id'=>1,'agent_id'=>7,'caller_e164'=>'+16025550888'],$namedIncomingSid);$namedIncomingCall=vp3_phone_browser_call_by_sid_v1($pdo,$namedIncomingSid);
c(is_string($namedIncoming)&&str_contains($namedIncoming,'<Identity>'.$namedToken['identity'].'</Identity>'),'incoming named-Agent TwiML addresses the identity issued in its actual Voice token');
$namedIncomingStatus=form('CA'.str_repeat('ae',16),['ParentCallSid'=>$namedIncomingSid,'CallStatus'=>'completed']);
c(vp3_phone_browser_status_v1($pdo,$namedIncomingStatus,signature('status',$namedIncomingStatus,$namedIncomingCall['public_id']),$namedIncomingCall['public_id'])['accepted'],'named-Agent incoming identity regression completes its called leg');
$route=['phone_number_id'=>3,'owner_user_id'=>1,'agent_id'=>9,'caller_e164'=>'+16025550888'];
c(vp3_phone_browser_incoming_v1($pdo,$route,'CA'.str_repeat('1',32))===null,'Agent A Device cannot receive Agent B incoming call');
action('token',['number_id'=>3]);
$sid='CA'.str_repeat('2',32);$pdo->prepare('INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164) VALUES (?,?,?,?,?,?,?)')->execute(['twilio',$sid,1,3,9,'+16025550888','+16025550103']);
$incoming=vp3_phone_browser_incoming_v1($pdo,$route,$sid);$in=vp3_phone_browser_call_by_sid_v1($pdo,$sid);
c(is_string($incoming)&&str_contains($incoming,'<Identity>'.vp3_phone_browser_identity_v1(1,0).'</Identity>')&&str_contains($incoming,'phone_call_id'),'MAIN Device receives owner second Agent with server card reference');
c(str_contains($incoming,'<Identity>'.$token['identity'].'</Identity>'),'incoming MAIN TwiML addresses the identity issued in its actual Voice token');
c(strpos($incoming,'This call will be recorded')<strpos($incoming,'<Dial'),'incoming PSTN caller receives recording disclosure before browser bridge');
c($in['conversation_id']==11&&$in['agent_id']==9&&$in['chat_agent_id']==0,'incoming assigned Agent and owner MAIN Chat kept separately');
denies(fn()=>action('bind_call',['call_id'=>$in['public_id'],'agent_id'=>7,'conversation_id'=>12]),404,'named Agent cannot bind another assigned Agent incoming call');
c(action('bind_call',['call_id'=>$in['public_id']])['call']['public_id']===$in['public_id'],'owner MAIN can bind authorized incoming call');
c(status($in,'no-answer','3')['accepted'],'called leg failure recorded before Dial complete');
$end=status($in,'no-answer','3',[],true);c($end['fallback']===true,'Dial complete still offers voicemail when terminal child status arrived first');
c(empty(status($in,'no-answer','3',[],true)['fallback']),'replayed Dial complete cannot offer a second voicemail');

// Exercise the actual public callback dispatcher, including warning-free XML fallback.
$sid='CA'.str_repeat('6',32);$pdo->prepare('INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164) VALUES (?,?,?,?,?,?,?)')->execute(['twilio',$sid,1,3,9,'+16025550888','+16025550103']);
vp3_phone_browser_incoming_v1($pdo,$route,$sid);$second=vp3_phone_browser_call_by_sid_v1($pdo,$sid);status($second,'no-answer','7');
$endForm=form($sid,['DialCallSid'=>'CA'.str_repeat('7',32),'DialCallStatus'=>'no-answer']);$_POST=$endForm;$_GET=['call'=>$second['public_id']];$_SERVER=['REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/x-www-form-urlencoded','CONTENT_LENGTH'=>200,'HTTP_X_TWILIO_SIGNATURE'=>signature('complete',$endForm,$second['public_id'])];
set_error_handler(static function(int $severity,string $message,string $file,int $line): never{throw new ErrorException($message,0,$severity,$file,$line);});
ob_start();vp3_phone_browser_endpoint_v1('complete');$fallbackXml=ob_get_clean();restore_error_handler();
c(http_response_code()===200&&str_contains($fallbackXml,'Second Agent')&&str_contains($fallbackXml,'<Record'),'actual complete endpoint returns current Agent voicemail TwiML without warnings');
$_GET=['call'=>['forged']];ob_start();vp3_phone_browser_endpoint_v1('complete');$invalidXml=ob_get_clean();c(http_response_code()===422&&$invalidXml==='','array public query fails without corrupting XML');
$legacyId=(int)$pdo->query("SELECT id FROM phone_voice_calls_v130 WHERE provider_call_sid='$sid'")->fetchColumn();$pdo->prepare("INSERT INTO phone_voice_recordings_v130(call_id,owner_user_id,provider,provider_recording_sid,recording_status,duration_seconds) VALUES (?,1,'twilio',?,'completed',12)")->execute([$legacyId,'RE'.str_repeat('b',32)]);
$operatorFallback=array_column(vp3_operator_history_v1($pdo,user(),0,11)['calls'],null,'call_id');
c(($operatorFallback[$second['public_id']]['recording']['state']??'')==='received'&&!isset($operatorFallback['legacy-'.$legacyId]),'Operator links fallback voicemail to its canonical browser call without duplicate history');
$rows=api('GET')['state']['voicemails'];c(count($rows)===2&&count(array_filter($rows,fn($r)=>str_starts_with($r['id'],'job-')))===1&&count(array_filter($rows,fn($r)=>str_starts_with($r['id'],'legacy-')))===1,'existing voicemail and new recording have distinct stable IDs');
foreach($rows as $vm){action('mark_voicemail_read',['voicemail_id'=>$vm['id']]);}
c(count(array_filter(api('GET')['state']['voicemails'],fn($r)=>$r['read']))===2,'legacy and job voicemail read state persists');
c(api('GET',['action'=>'recording_policy','number_id'=>3])['policy']['model_route']==='account_cloud','policy binds selected assigned Agent from MAIN');

// Existing inbound routes must announce even when nobody registered a browser Device
// and the caller hangs up before a recording callback ever arrives.
$pdo->exec('DELETE FROM phone_browser_presence_v1');$priorMessages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();$priorBrowser=(int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn();
$offlineForm=form('CA'.str_repeat('8',32),['Direction'=>'inbound','From'=>'+16025550777','To'=>'+16025550101']);
$offlineSignature=vp3_phone_sms_twilio_signature_v110((string)setting('phone_voice_webhook_url_v130'),$offlineForm,'carrier-auth-token-fixture');
$offline=vp3_phone_voice_incoming_v130($pdo,$offlineForm,$offlineSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
$query=$pdo->prepare('SELECT * FROM phone_voice_calls_v130 WHERE provider_call_sid=?');$query->execute([$offlineForm['CallSid']]);$offlineReceipt=$query->fetch();$offlineId='legacy-'.(int)$offlineReceipt['id'];
c($offline['accepted']&&str_contains($offline['twiml'],'<Record'),'offline browser preserves existing voicemail routing');
c((int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn()===$priorBrowser,'offline route reuses existing receipt without inventing browser call');
c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$priorMessages+1,'call without any recording callback still announces once in Chat');
$offlineMessage=$pdo->query('SELECT * FROM chat_messages ORDER BY id DESC LIMIT 1')->fetch();$offlineMarker=json_decode($offlineMessage['context_json'],true)['phone_v1'];
c($offlineMarker===['kind'=>'call','call_id'=>$offlineId]&&str_contains($offlineMessage['message'],'+16025550777')&&str_contains($offlineMessage['message'],'+16025550101'),'offline announcement uses stable receipt reference and observed caller/called numbers');
vp3_phone_voice_incoming_v130($pdo,$offlineForm,$offlineSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$priorMessages+1,'repeated signed offline callback cannot duplicate announcement');
$legacyCalls=array_values(array_filter(api('GET')['state']['calls'],fn($r)=>$r['public_id']===$offlineId));
c(count($legacyCalls)===1&&$legacyCalls[0]['source_kind']==='legacy'&&!$legacyCalls[0]['browser_control']&&$legacyCalls[0]['agent_id']===7&&$legacyCalls[0]['phone_e164']==='+16025550101','Recent calls includes read-only original legacy receipt');
c(!str_contains(json_encode($legacyCalls),$offlineForm['CallSid']),'legacy public history does not expose carrier SID');
denies(fn()=>action('bind_call',['call_id'=>$offlineId]),404,'legacy history ID never grants browser call controls');
action('token',['number_id'=>1,'agent_id'=>7,'conversation_id'=>12]);
$replayAfterDevice=vp3_phone_voice_incoming_v130($pdo,$offlineForm,$offlineSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
c(str_contains($replayAfterDevice['twiml'],'<Record')&&vp3_phone_browser_call_by_sid_v1($pdo,$offlineForm['CallSid'])===null&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$priorMessages+1,'Device registration cannot promote a retried legacy route into a duplicate browser announcement');
$secondOffline=form('CA'.str_repeat('9',32),['Direction'=>'inbound','From'=>'+16025550666','To'=>'+16025550103']);$secondSignature=vp3_phone_sms_twilio_signature_v110((string)setting('phone_voice_webhook_url_v130'),$secondOffline,'carrier-auth-token-fixture');
vp3_phone_voice_incoming_v130($pdo,$secondOffline,$secondSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
$query->execute([$secondOffline['CallSid']]);$secondOfflineReceipt=$query->fetch();$secondOfflineId='legacy-'.(int)$secondOfflineReceipt['id'];
$delivered=$pdo->query("SELECT conversation_id,chat_agent_id FROM phone_browser_deliveries_v1 WHERE event_key='call:$secondOfflineId'")->fetch();
c((int)$delivered['conversation_id']===11&&(int)$delivered['chat_agent_id']===0,'Agent B fallback never announces inside current Agent A conversation');
$agentACalls=api('GET',['agent_id'=>7,'conversation_id'=>12])['state']['calls'];$agentBCalls=api('GET',['agent_id'=>9,'conversation_id'=>13])['state']['calls'];
c(in_array($offlineId,array_column($agentACalls,'public_id'),true)&&!in_array($secondOfflineId,array_column($agentACalls,'public_id'),true)&&in_array($secondOfflineId,array_column($agentBCalls,'public_id'),true),'legacy Recent calls preserves exact named Agent namespace');
$privateForm=form('CA'.str_repeat('0',32),['Direction'=>'inbound','From'=>'anonymous','To'=>'+16025550103']);$privateSignature=vp3_phone_sms_twilio_signature_v110((string)setting('phone_voice_webhook_url_v130'),$privateForm,'carrier-auth-token-fixture');
vp3_phone_voice_incoming_v130($pdo,$privateForm,$privateSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
c(str_contains($pdo->query('SELECT message FROM chat_messages ORDER BY id DESC LIMIT 1')->fetchColumn(),'private caller'),'private caller is not invented as an authenticated identity');
$browserLegacy='legacy-'.(int)$in['voice_call_id'];$allIds=array_column(api('GET')['state']['calls'],'public_id');
c(in_array($in['public_id'],$allIds,true)&&!in_array($browserLegacy,$allIds,true),'linked browser and legacy receipts project as one call');
$pdo->prepare('UPDATE phone_browser_calls_v1 SET voice_call_id=NULL WHERE public_id=?')->execute([$in['public_id']]);
c(!in_array($browserLegacy,array_column(api('GET')['state']['calls'],'public_id'),true),'provider parent SID also excludes legacy duplicate when older link is absent');
// Chat projection failure must not interrupt the accepted original call route.
$pdo->exec('ALTER TABLE chat_messages RENAME TO phone_fixture_hidden_chat_messages');
$failureForm=form('CA'.str_repeat('e',32),['Direction'=>'inbound','From'=>'+16025550555','To'=>'+16025550101']);$failureSignature=vp3_phone_sms_twilio_signature_v110((string)setting('phone_voice_webhook_url_v130'),$failureForm,'carrier-auth-token-fixture');
$oldErrorLog=ini_get('error_log');ini_set('error_log','/dev/null');
try{$failureCall=vp3_phone_voice_incoming_v130($pdo,$failureForm,$failureSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));}finally{ini_set('error_log',$oldErrorLog);$pdo->exec('ALTER TABLE phone_fixture_hidden_chat_messages RENAME TO chat_messages');}
c($failureCall['accepted']&&str_contains($failureCall['twiml'],'<Record')&&!$pdo->inTransaction(),'failed optional Chat projection leaves voicemail routing and transaction usable');
c(vp3_phone_browser_call_by_sid_v1($pdo,$failureForm['CallSid'])===null,'failed browser Chat projection rolls back the proposed browser route before safe voicemail fallback');
$pdo->exec('DELETE FROM phone_browser_presence_v1');
vp3_phone_voice_incoming_v130($pdo,$failureForm,$failureSignature,(string)setting('phone_voice_webhook_url_v130'),'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
c(count(array_filter(api('GET')['state']['calls'],fn($r)=>str_starts_with($r['public_id'],'legacy-')))===4,'retry after Chat projection recovery retains one call per immutable legacy receipt');
foreach($argv as $arg)if(str_starts_with($arg,'--emit-state=')){
    $path=substr($arg,13);if($path===''||!is_dir(dirname($path)))throw new RuntimeException('Create the state fixture destination directory first.');
    file_put_contents($path,json_encode(api('GET')['state'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
for($i=0;$i<60;$i++){
    $pdo->prepare("INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,state,created_at) VALUES ('twilio',?,1,1,7,'+16025550444','+16025550101','completed',?)")
        ->execute(['CA'.str_pad(dechex(256+$i),32,'0',STR_PAD_LEFT),gmdate('Y-m-d H:i:s',1577836800+$i)]);
}
$bounded=api('GET')['state']['calls'];c(count($bounded)===50,'mixed legacy/browser Recent calls is bounded to50');
$timestamps=array_column($bounded,'created_at');$sorted=$timestamps;rsort($sorted,SORT_STRING);c($timestamps===$sorted&&end($timestamps)>='2020-01-01T00:00:00+00:00','mixed recent history preserves chronological UTC order');

$pending=prepare();$pendingId=$pending['call']['public_id'];$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
[$refused]=start($pending,'4');c(str_contains($refused,'Hangup')&&readcall($pendingId)['intent_consumed_epoch']==0,'reassignment invalidates an issued call intent before carrier use');
denies(fn()=>action('token',['number_id'=>1]),404,'reassignment revokes new credentials for former owner');
c(vp3_phone_browser_policy_v1($pdo,['id'=>1,'owner_user_id'=>2,'agent_id'=>8])['browser_enabled']==0,'former owner browser approval does not transfer');
c(readcall($public)['owner_user_id']==1,'historical call remains with original owner after reassignment');
$ownerTwo=api('GET',[],2)['state'];c(!in_array($public,array_column($ownerTwo['calls'],'public_id'),true),'new number owner cannot read former owner call history');
c(!in_array($offlineId,array_column($ownerTwo['calls'],'public_id'),true)&&in_array($offlineId,array_column(api('GET')['state']['calls'],'public_id'),true),'legacy history remains with original owner after number reassignment');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
$pdo->exec('UPDATE phone_browser_calls_v1 SET intent_expires_epoch=1 WHERE id='.(int)$pending['call']['id']);
[$expired]=start($pending,'5');c(str_contains($expired,'Hangup'),'expired intent cannot place call');
action('cancel_prepared',['call_id'=>$pendingId]);
$pdo->exec('UPDATE users SET plan=0 WHERE id=1');c(!vp3_phone_workspace_available_v1($pdo,user()),'assignment also requires an active package or explicit administrator grant');
denies(fn()=>api('GET'),403,'lost phone package removes private workspace access');
denies(fn()=>action('token',['number_id'=>1]),403,'entitlement revoke prevents new browser tokens');

// Receipt age is UTC even when the database clock and PHP timezone differ.
// SQLite overrides the clock used by DEFAULT CURRENT_TIMESTAMP; MySQL uses
// actual session timezones, so the production signed ingress runs in both.
$pdo->exec('UPDATE users SET plan=1 WHERE id=1');action('token',['number_id'=>1]);
$oldPhpTimezone=date_default_timezone_get();$oldDatabaseTimezone=$mysql?(string)$pdo->query('SELECT @@session.time_zone')->fetchColumn():null;
$databaseClockOffset=0;
if(!$mysql&&!$pdo->sqliteCreateFunction('current_timestamp',static function()use(&$databaseClockOffset):string{return gmdate('Y-m-d H:i:s',time()+$databaseClockOffset);}))throw new RuntimeException('The SQLite database clock fixture could not be installed.');
$inboundCallback=(string)setting('phone_voice_webhook_url_v130');
$receiveIncoming=static function(array $form,?string $signature=null)use($pdo,$inboundCallback):array{
    return vp3_phone_voice_incoming_v130($pdo,$form,$signature??vp3_phone_sms_twilio_signature_v110($inboundCallback,$form,'carrier-auth-token-fixture'),$inboundCallback,'carrier-auth-token-fixture',(string)setting('phone_twilio_account_sid'));
};
$utcCase=0;
try{
    foreach(['-06:00'=>-21600,'+09:00'=>32400] as $databaseTimezone=>$offsetSeconds){
        if($mysql)$pdo->exec('SET SESSION time_zone = '.$pdo->quote($databaseTimezone));
        else $databaseClockOffset=$offsetSeconds;
        foreach(['America/Phoenix','Asia/Tokyo'] as $phpTimezone){
            date_default_timezone_set($phpTimezone);$label=' DB '.$databaseTimezone.' / PHP '.$phpTimezone;$utcCase++;
            $probeSid='CA'.str_pad(dechex(1024+$utcCase),32,'0',STR_PAD_LEFT);$beforeProbe=time();
            $pdo->prepare("INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164) VALUES ('twilio',?,1,1,7,'+16025550444','+16025550101')")->execute([$probeSid]);
            $probeQuery=$pdo->prepare('SELECT created_at FROM phone_voice_calls_v130 WHERE provider_call_sid=?');$probeQuery->execute([$probeSid]);$probeEpoch=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$probeQuery->fetchColumn(),new DateTimeZone('UTC'))->getTimestamp();$probeQuery->closeCursor();
            c($probeEpoch>=$beforeProbe+$offsetSeconds-1&&$probeEpoch<=time()+$offsetSeconds+1,'the fixture exercises a non-UTC database default clock'.$label);
            c((vp3_phone_voice_current_route_v131($pdo,$probeSid)['reason']??'')==='call_expired','database-local default timestamps reproduce the incoming receipt-age failure'.$label);
            $incomingForm=form('CA'.str_pad(dechex(2048+$utcCase),32,'0',STR_PAD_LEFT),['Direction'=>'inbound','From'=>'+16025550444','To'=>'+16025550101']);$beforeIncoming=time();
            $received=$receiveIncoming($incomingForm);
            c(!empty($received['accepted'])&&str_contains((string)($received['twiml']??''),'<Client '),'fresh signed incoming calls reach the browser across independent clocks'.$label);
            $receiptQuery=$pdo->prepare('SELECT created_at FROM phone_voice_calls_v130 WHERE provider_call_sid=?');$receiptQuery->execute([$incomingForm['CallSid']]);$receiptCreated=(string)$receiptQuery->fetchColumn();$receiptQuery->closeCursor();$receiptEpoch=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$receiptCreated,new DateTimeZone('UTC'))->getTimestamp();
            c($receiptEpoch>=$beforeIncoming&&$receiptEpoch<=time(),'signed incoming receipts are stored as actual UTC'.$label);
            $browserCall=vp3_phone_browser_call_by_sid_v1($pdo,$incomingForm['CallSid']);$completed=form('CA'.str_pad(dechex(3072+$utcCase),32,'0',STR_PAD_LEFT),['ParentCallSid'=>$incomingForm['CallSid'],'CallStatus'=>'completed']);
            c(vp3_phone_browser_status_v1($pdo,$completed,signature('status',$completed,$browserCall['public_id']),$browserCall['public_id'])['accepted'],'the timezone case completes its browser leg'.$label);
            $changeCreated=$pdo->prepare('UPDATE phone_voice_calls_v130 SET created_at=? WHERE provider_call_sid=?');
            foreach([-14401,120] as $ageOffset){
                $changeCreated->execute([gmdate('Y-m-d H:i:s',time()+$ageOffset),$incomingForm['CallSid']]);
                c((vp3_phone_voice_current_route_v131($pdo,$incomingForm['CallSid'])['reason']??'')==='call_expired','aged or future incoming receipts remain rejected offset '.$ageOffset.$label);
            }
            $changeCreated->execute([$receiptCreated,$incomingForm['CallSid']]);
            rejects_message(fn()=>$receiveIncoming($incomingForm,'forged'),'Invalid provider signature.','non-UTC clocks do not bypass carrier signature checks'.$label);
            $pdo->exec('UPDATE user_agents SET owner_user_id=2 WHERE id=7');
            try{c(($receiveIncoming($incomingForm)['reason']??'')==='account_ineligible','non-UTC clocks do not bypass the assigned Agent owner check'.$label);}
            finally{$pdo->exec('UPDATE user_agents SET owner_user_id=1 WHERE id=7');}
        }
    }
}finally{
    date_default_timezone_set($oldPhpTimezone);
    if($mysql)$pdo->exec('SET SESSION time_zone = '.$pdo->quote($oldDatabaseTimezone));
    else $databaseClockOffset=0;
    $pdo->exec('UPDATE users SET plan=0 WHERE id=1');
}

// Save real owner-private people from an immutable call, without inventing an email.
$pdo->exec('UPDATE users SET plan=1 WHERE id=1');
$fixtureCaller=static function(int $owner,int $agent,string $peer,int $number=1)use($pdo):string{
    static $sequence=8192;$sequence++;
    $pdo->prepare("INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,state,created_at) VALUES ('twilio',?,?,?,?,?,?,'completed',?)")
        ->execute(['CA'.str_pad(dechex($sequence),32,'0',STR_PAD_LEFT),$owner,$number,$agent,$peer,'+16025550101',gmdate('Y-m-d H:i:s')]);
    return 'legacy-'.(int)$pdo->lastInsertId();
};
$contactCall=$fixtureCaller(1,7,'+16025550601');$otherOwnerCall=$fixtureCaller(2,8,'+16025550602',2);
$save=['action'=>'save_caller_contact','call_id'=>$contactCall,'name'=>'  Taylor Caller  ','csrf_token'=>'csrf-fixture'];
denies(fn()=>api('GET',$save),405,'caller contacts cannot be changed with GET');
denies(fn()=>api('POST',array_diff_key($save,['csrf_token'=>true])),403,'caller contact rejects missing CSRF');
denies(fn()=>api('POST',array_replace($save,['csrf_token'=>'forged'])),403,'caller contact rejects forged CSRF');
denies(fn()=>action('save_caller_contact',['call_id'=>$otherOwnerCall,'name'=>'Foreign']),404,'a foreign immutable call cannot supply a contact number');
denies(fn()=>action('save_caller_contact',['call_id'=>$contactCall,'name'=>'Wrong Agent','agent_id'=>9,'conversation_id'=>13]),404,'named Agent cannot save another Agent caller');
foreach(['',str_repeat('x',121),"Caller\nInjected",['not a string']] as $invalidName)denies(fn()=>action('save_caller_contact',['call_id'=>$contactCall,'name'=>$invalidName]),422,'invalid caller name is rejected');
foreach(['owner_user_id'=>2,'owner_id'=>2,'phone'=>'+16025550000','phone_e164'=>'+16025550000','phone_number'=>'+16025550000','number'=>2] as $field=>$value)denies(fn()=>action('save_caller_contact',['call_id'=>$contactCall,'name'=>'Forged',$field=>$value]),422,'caller identity authority cannot be supplied through '.$field);
denies(fn()=>action('save_caller_contact',['call_id'=>'legacy-0','name'=>'Invalid']),404,'malformed legacy reference is rejected');
$privateCall=$fixtureCaller(1,7,'');denies(fn()=>action('save_caller_contact',['call_id'=>$privateCall,'name'=>'Private']),422,'withheld caller number is never fabricated');
c((int)$pdo->query('SELECT COUNT(*) FROM crm_contacts')->fetchColumn()===3,'rejected contact requests do not create CRM records');
$saved=action('save_caller_contact',['call_id'=>$contactCall,'name'=>'  Taylor Caller  ','agent_id'=>7,'conversation_id'=>12]);$contactId=$saved['contact']['id'];
c($saved['created']&&$saved['contact']===['id'=>$contactId,'name'=>'Taylor Caller','phone_e164'=>'+16025550601'],'contact creation returns the canonical ledger number and trimmed name');
$q=$pdo->prepare('SELECT * FROM crm_contacts WHERE id=?');$q->execute([$contactId]);$person=$q->fetch();
c((int)$person['owner_user_id']===1&&$person['email']===''&&$person['email_normalized']===null&&$person['phone']==='+16025550601'&&$person['source']==='phone_call','phone-only person persists in the canonical private CRM without synthetic email');
c(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$person['public_id'])===1,'phone contact uses a normal canonical CRM public identifier');
$q=$pdo->prepare('SELECT phone,phone_normalized,is_primary FROM crm_contact_phones WHERE contact_id=?');$q->execute([$contactId]);$channel=$q->fetch();
c($channel['phone']==='+16025550601'&&$channel['phone_normalized']==='16025550601'&&(int)$channel['is_primary']===1,'phone contact shares the existing primary contact channel');
$again=action('save_caller_contact',['call_id'=>$contactCall,'name'=>'Do not rename an existing person']);
c(!$again['created']&&$again['contact']===$saved['contact']&&(int)$pdo->query('SELECT COUNT(*) FROM crm_contacts')->fetchColumn()===4,'repeat save deduplicates and preserves the established contact name');
$secondContact=action('save_caller_contact',['call_id'=>$fixtureCaller(1,7,'+16025550603'),'name'=>'Second phone-only person']);
c($secondContact['created']&&$secondContact['contact']['id']!==$contactId&&(int)$pdo->query("SELECT COUNT(*) FROM crm_contacts WHERE owner_user_id=1 AND email='' AND email_normalized IS NULL")->fetchColumn()===2,'different phone-only people coexist under owner/email uniqueness');
$existing=action('save_caller_contact',['call_id'=>$public,'name'=>'Do not rename Mine']);
c(!$existing['created']&&$existing['contact']['id']===1&&$existing['contact']['name']==='Mine','browser-ledger references reuse existing canonical people');
denies(fn()=>action('save_caller_contact',['call_id'=>$public,'name'=>'Foreign browser','conversation_id'=>21],2),404,'another owner cannot save a browser-ledger reference');
$pdo->exec("UPDATE crm_contacts SET phone='(602) 555-0999' WHERE id=1");
c(action('save_caller_contact',['call_id'=>$public,'name'=>'Formatted'])['contact']['id']===1,'human-formatted primary number deduplicates against its known international caller');
$pdo->prepare("INSERT INTO crm_contact_phones(contact_id,phone,phone_normalized,label,is_primary,created_at,updated_at) VALUES (1,'+44 (20) 7946 0100','442079460100','work',0,?,?)")->execute([gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
$alternate=action('save_caller_contact',['call_id'=>$fixtureCaller(1,7,'+442079460100'),'name'=>'Alternate']);
c(!$alternate['created']&&$alternate['contact']['id']===1&&$alternate['contact']['phone_e164']==='+442079460100','alternate contact phone resolves to its existing owner-private person');
$foreignMatch=action('save_caller_contact',['call_id'=>$fixtureCaller(1,7,'+16025550888'),'name'=>'My separate caller']);
c($foreignMatch['created']&&$foreignMatch['contact']['id']!==2&&$foreignMatch['contact']['name']==='My separate caller','a foreign owner contact neither leaks nor suppresses the current owner contact');
$archivedMatch=action('save_caller_contact',['call_id'=>$offlineId,'name'=>'Active caller']);
c($archivedMatch['created']&&$archivedMatch['contact']['id']!==3,'archived people are not silently reactivated by caller saving');
$repeatCall=$fixtureCaller(1,7,'+16025550601');
$refreshed=api('GET')['state'];$card=array_values(array_filter($refreshed['calls'],fn($row)=>$row['call_id']===$contactCall))[0];
c($card['contact_id']===$contactId&&$card['contact_name']==='Taylor Caller'&&!$card['caller_familiar'],'first call projects its saved contact without treating later calls as prior history');
$repeatCard=array_values(array_filter($refreshed['calls'],fn($row)=>$row['call_id']===$repeatCall))[0];
c($repeatCard['contact_name']==='Taylor Caller'&&$repeatCard['caller_familiar'],'repeat call projects earlier owner-scoped history even within one timestamp second');
$foreignAgentPrior=$fixtureCaller(1,9,'+16025550605',3);$ownAgentFirst=$fixtureCaller(1,7,'+16025550605');
$mainFirst=array_values(array_filter(api('GET')['state']['calls'],fn($row)=>$row['call_id']===$ownAgentFirst))[0];
$namedFirst=array_values(array_filter(api('GET',['agent_id'=>7,'conversation_id'=>12])['state']['calls'],fn($row)=>$row['call_id']===$ownAgentFirst))[0];
c($mainFirst['caller_familiar']&&!$namedFirst['caller_familiar'],'repeat caller evidence respects the exact named Agent history boundary');
$tieEpoch=time();$browserFirst=str_repeat('f',32);$browserLater=str_repeat('0',32);
$browserReceipt=$pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,state,created_epoch,updated_epoch,ended_epoch) VALUES (?,1,7,1,'+16025550101','+16025550606','inbound','completed',?,?,?)");
foreach([$browserFirst,$browserLater] as $ref)$browserReceipt->execute([$ref,$tieEpoch,$tieEpoch,$tieEpoch]);
$browserHistory=array_column(api('GET')['state']['calls'],null,'call_id');
c(!$browserHistory[$browserFirst]['caller_familiar']&&$browserHistory[$browserLater]['caller_familiar'],'same-second browser receipts use monotonic database IDs rather than random public ID order');
$legacyTie=$fixtureCaller(1,7,'+16025550607');$pdo->prepare('UPDATE phone_voice_calls_v130 SET created_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',$tieEpoch),(int)substr($legacyTie,7)]);
$browserReceipt=$pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,state,created_epoch,updated_epoch,ended_epoch) VALUES (?,1,7,1,'+16025550101','+16025550607','inbound','completed',?,?,?)");$crossKindRef=str_repeat('1',32);$browserReceipt->execute([$crossKindRef,$tieEpoch,$tieEpoch,$tieEpoch]);
$crossKindCards=array_column(api('GET')['state']['calls'],null,'call_id');
c(!$crossKindCards[$legacyTie]['caller_familiar']&&!$crossKindCards[$crossKindRef]['caller_familiar'],'equal-second receipts in separate ledgers conservatively avoid an unknown prior order');
$oldCallerCards=array_values(array_filter($refreshed['calls'],fn($row)=>$row['peer_e164']==='+16025550444'&&$row['created_at']>'2020-01-02'));
c(count($oldCallerCards)>0&&count(array_filter($oldCallerCards,fn($row)=>$row['caller_familiar']))===count($oldCallerCards),'earlier history outside the recent fifty calls still identifies a familiar caller');
$firstCard=array_values(array_filter($refreshed['calls'],fn($row)=>$row['call_id']===$privateCall))[0];
c($firstCard['contact_id']===0&&$firstCard['contact_name']===''&&!$firstCard['caller_familiar'],'private callers stay unnamed and are never grouped as familiar');
$contactsById=array_column($refreshed['contacts'],null,'id');
c(isset($contactsById[$contactId])&&$contactsById[$contactId]['name']==='Taylor Caller'&&$contactsById[$contactId]['phone_e164']==='+16025550601','saved name and number appear in the phone Contacts list');
$matchingVoicemail=array_values(array_filter($refreshed['voicemails'],fn($row)=>$row['peer_e164']==='+16025550888'));
c(count($matchingVoicemail)>0&&count(array_filter($matchingVoicemail,fn($row)=>$row['contact_id']===$foreignMatch['contact']['id']&&$row['contact_name']==='My separate caller'))===count($matchingVoicemail),'saved owner contact name is shared by existing voicemail history');
require_once dirname(__DIR__).'/includes/workspace-native-actions-v1.php';
$edited=workspace_native_contact_update_v1($pdo,1,$contactId,['display_name'=>'Taylor Updated']);
$editedSecond=workspace_native_contact_update_v1($pdo,1,$secondContact['contact']['id'],['display_name'=>'Second Updated']);
c($edited['name']==='Taylor Updated'&&$edited['email_normalized']===null&&$editedSecond['email_normalized']===null,'existing native contact editor can edit multiple people with no email');
$edited=workspace_native_contact_update_v1($pdo,1,$contactId,['email'=>'Taylor@Example.Test']);
c($edited['email']==='taylor@example.test'&&$edited['email_normalized']==='taylor@example.test','adding a real email preserves existing normalized email identity');
try{$pdo->exec("UPDATE crm_contacts SET email_normalized='mine@example.test' WHERE id=".(int)$contactId);c(false,'existing owner/email uniqueness remains enforced');}catch(PDOException){c(true,'existing owner/email uniqueness remains enforced');}
$edited=workspace_native_contact_update_v1($pdo,1,$contactId,['email'=>'']);c($edited['email']===''&&$edited['email_normalized']===null,'removing optional email restores NULL identity');
$historyCall=$fixtureCaller(1,7,'+16025550604');$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
try{
    $historyContact=action('save_caller_contact',['call_id'=>$historyCall,'name'=>'Historical caller']);
    c($historyContact['created']&&$historyContact['contact']['phone_e164']==='+16025550604','original call owner can save their history after the number is reassigned');
    denies(fn()=>action('save_caller_contact',['call_id'=>$historyCall,'name'=>'New number owner','conversation_id'=>21],2),404,'number reassignment never transfers original caller contact authority');
    $newOwnerContacts=api('GET',[],2)['state']['contacts'];
    c(!in_array($historyContact['contact']['id'],array_column($newOwnerContacts,'id'),true),'new number owner cannot see the historical owner contact');
}finally{$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');}
// A recent saved caller remains available when the ordinary alphabetical book is full.
$insert=$pdo->prepare("INSERT INTO crm_contacts(public_id,owner_user_id,name,email,email_normalized,phone,status,created_at,updated_at) VALUES (?,1,?,'',NULL,?,'active',?,?)");
for($i=0;$i<251;$i++)$insert->execute([sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d',$i),'A contact '.sprintf('%03d',$i),'+1602555'.str_pad((string)(1000+$i),4,'0',STR_PAD_LEFT),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
action('favorite',['contact_id'=>$contactId,'favorite'=>true]);$boundedBook=api('GET')['state'];$boundedContacts=array_column($boundedBook['contacts'],null,'id');
c(count($boundedBook['contacts'])===250&&isset($boundedContacts[$contactId])&&$boundedContacts[$contactId]['favorite'],'related caller and favorite flag survive the bounded contact book');
// Exercise the exact delivered-report binding using the real signed call and recording job.
// The canonical intelligence reader is replaced by an in-process saved-result fixture.
ddl('CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,metadata_json TEXT,discarded_at DATETIME NULL)');
ddl('CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,segment_type VARCHAR(30),transcript_text TEXT)');
$q=$pdo->prepare("SELECT * FROM phone_recording_jobs_v1 WHERE owner_user_id=1 AND call_public_id=? AND origin_kind='browser'");$q->execute([$public]);$followJob=$q->fetch(PDO::FETCH_ASSOC);$originalFollowJob=$followJob;$q->closeCursor();
$session=771;$hash=str_repeat('a',64);$revision=str_repeat('b',64);
$meta=['source'=>'phone_call_v1','phone_recording_v1'=>['job_id'=>(int)$followJob['id'],'call_kind'=>'browser','call_id'=>$public,'agent_id'=>7]];
$pdo->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(?,1,?,NULL)')->execute([$session,json_encode($meta,JSON_THROW_ON_ERROR)]);
$pdo->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(1,?,'transcript','Fixture private transcript')")->execute([$session]);
$delivery=vp3_phone_workspace_delivery_v1($pdo,readcall($public),'voicemail','phone-recording-report:'.$followJob['public_id'],'Fixture reviewed source',
    ['voicemail_id'=>'job-'.(int)$followJob['id'],'report'=>['source_hash'=>$hash,'review_state'=>'unreviewed']]);
$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='ready',session_id=?,transcript_hash=?,analysis_hash=?,conversation_id=?,report_message_id=? WHERE id=?")
    ->execute([$session,$hash,str_repeat('c',64),$delivery['conversation_id'],$delivery['message_id'],$followJob['id']]);
$followJob=vp3_phone_recording_row_v1($pdo,(int)$followJob['id']);
$candidate=['session_id'=>$session,'source_hash'=>$hash,'kind'=>'followup','plugin'=>'actions','section'=>'items','item_id'=>'action:1',
    'item_revision'=>$revision,'review_state'=>'unreviewed','question'=>'Review the proposal.','next_step'=>'Confirm timing.',
    'source_url'=>'https://untrusted.example/unsafe?page=1'];
$savedFollow=['session_id'=>$session,'source_hash'=>$hash,'fresh'=>true,'page_count'=>2,'research_candidates'=>[
    $candidate,array_replace($candidate,['item_id'=>'action:2','review_state'=>'accepted','question'=>'Confirm the meeting.']),
    array_replace($candidate,['item_id'=>'action:3','review_state'=>'rejected']),
    array_replace($candidate,['item_id'=>'action:4','kind'=>'research']),$candidate]];
$reads=0;$reader=static function(int $id)use(&$reads,$session,$savedFollow):array{$reads++;if($id!==$session)throw new RuntimeException('Wrong fixture session');return $savedFollow;};
$followScope=['user'=>user(),'principal'=>['kind'=>'system'],'agent_id'=>0,'conversation_id'=>11];
$followNow=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$review=vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,$reader);
$items=$review['reports'][0]['items']??[];
c($review['coverage']==='ready'&&count($items)===2&&$items[0]['review_state']==='accepted'&&$reads===1,'Operator reviews canonical open suggestions, deduplicates and prioritizes reviewed items');
c($items[1]['source_url']==='/artist-listening-evidence.php?session=771&plugin=actions&section=items&item=action%3A1&page=1'&&!str_contains(json_encode($review),'untrusted.example'),'Operator rebuilds exact same-origin evidence links');
c(!str_contains(json_encode($review),'Fixture private transcript')&&!str_contains(json_encode($review),'provider_call_sid'),'Operator review does not export speech or carrier identifiers');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,['followup:'.$followJob['public_id']],$reader)===$review,'exact saved report re-reads the same evidence');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,['followup:'.str_repeat('0',32)],$reader)['reports']===[],'unknown report reference never substitutes another call');
$beforeReads=$reads;
c(vp3_operator_followup_queue_v1($pdo,array_replace($followScope,['agent_id'=>7,'conversation_id'=>12]),$followNow,null,$reader)['reports']===[]&&$reads===$beforeReads,'another Chat cannot borrow the original report');
c(vp3_operator_followup_queue_v1($pdo,array_replace($followScope,['user'=>user(2),'conversation_id'=>21]),$followNow,null,$reader)['reports']===[],'another owner cannot borrow the report');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow->modify('+8 days'),null,$reader)['reports']===[],'proactive review is limited to recent reports');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,static fn(int $id):array=>array_replace($savedFollow,['fresh'=>false]))['reports']===[],'stale canonical analysis cannot suggest a follow-up');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,static fn(int $id):array=>array_replace($savedFollow,['source_hash'=>str_repeat('d',64)]))['reports']===[],'edited transcript cannot reuse the original report suggestions');
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,static function(int $id):array{throw new RuntimeException('Reader unavailable');})['coverage']==='limited','failed verification is not a verified empty queue');
$pdo->prepare('UPDATE artist_transcript_sessions_v172 SET discarded_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s'),$session]);
$beforeReads=$reads;
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,$reader)['reports']===[]&&$reads===$beforeReads,'discarded transcript prevents further candidate reads');
$pdo->prepare('UPDATE artist_transcript_sessions_v172 SET discarded_at=NULL WHERE id=?')->execute([$session]);
$pdo->prepare('DELETE FROM phone_browser_deliveries_v1 WHERE event_key=?')->execute(['phone-recording-report:'.$followJob['public_id']]);
c(vp3_operator_followup_queue_v1($pdo,$followScope,$followNow,null,$reader)['reports']===[],'removed canonical report delivery fences the follow-up');
// Restore the original recording job before the next integration section reads
// the real Phone workspace. The synthetic evidence is not a canonical transcript.
$pdo->prepare('UPDATE phone_recording_jobs_v1 SET state=?,session_id=?,transcript_hash=?,analysis_hash=?,conversation_id=?,report_message_id=? WHERE id=?')
    ->execute([$originalFollowJob['state'],$originalFollowJob['session_id'],$originalFollowJob['transcript_hash'],$originalFollowJob['analysis_hash'],$originalFollowJob['conversation_id'],$originalFollowJob['report_message_id'],$originalFollowJob['id']]);
// The SQLite fixture is in memory and still has earlier read cursors. Its
// connection teardown releases these tables; clean the shared MySQL fixture explicitly.
if($mysql){$pdo->exec('DROP TABLE artist_transcript_segments_v172');$pdo->exec('DROP TABLE artist_transcript_sessions_v172');}
// Operator authorization changes are owner-only preferences, not call intents.
vp3_operator_auto_call_ensure_schema_v1($pdo);vp3_operator_auto_call_ensure_schema_v1($pdo);
c(vp3_operator_auto_call_schema_ready_v1($pdo)&&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_contact_policies_v1')->fetchColumn()===0,'explicit repeatable upgrade leaves every contact off');
$pdo->exec("UPDATE crm_contacts SET phone='+16025550999' WHERE id=1");
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET voice_enabled=1 WHERE id=1');
vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
action('select_number',['number_id'=>1]);
$autoSettings=vp3_operator_auto_call_defaults_v1();$autoSettings['timezone']='America/Phoenix';
$authorization=['contact_id'=>1,'number_id'=>1,'enabled'=>true,'expected_revision'=>0,'expected_destination'=>'+16025550999','expected_line'=>'+16025550101','settings'=>$autoSettings];
$policyScope=vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>0,'conversation_id'=>11]);
$readPolicy=static fn():array=>vp3_operator_auto_call_book_v1($pdo,$policyScope,1,[['id'=>1]])['policies'][0];
c($readPolicy()['state']==='off'&&$readPolicy()['execution_enabled']===false,'Auto Call defaults off and has no execution capability');
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>false,'analyze'=>false]);
denies(fn()=>action('save_auto_call_policy',$authorization),409,'permission cannot enable without configured recording and transcription');
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
c($readPolicy()['can_enable'],'explicit recording and transcription preferences permit saving owner authorization');
$callCount=(int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn();
$recordingCount=(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn();
$request=['action'=>'save_auto_call_policy','agent_id'=>0,'conversation_id'=>11]+$authorization;
denies(fn()=>api('GET',$request),405,'GET cannot save a contact authorization');
denies(fn()=>api('POST',$request),403,'authorization save needs CSRF');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['csrf_token'=>'forged'])),403,'forged CSRF cannot change Auto Call');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['contact_id'=>2])),404,'foreign contact cannot be authorized');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['contact_id'=>3])),404,'archived contact cannot be authorized');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['number_id'=>2])),404,'foreign carrier line cannot be authorized');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['agent_id'=>9,'conversation_id'=>13])),404,'named Agent cannot authorize a different Agent line');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['conversation_id'=>0])),403,'authorization requires a saved private Chat');
denies(fn()=>action('save_auto_call_policy',$authorization+['owner_user_id'=>2]),422,'client cannot supply owner authority');
denies(fn()=>action('save_auto_call_policy',$authorization+['recording_required'=>false]),422,'client cannot turn off mandatory recording');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['expected_destination'=>'+16025550888'])),409,'browser cannot supply another dial destination');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['expected_line'=>'+16025550103'])),409,'browser cannot substitute another calling line');
foreach (['timezone'=>'not/a/timezone','window_start'=>'25:00','window_end'=>'08:00','max_calls_per_day'=>4,'min_gap_minutes'=>0,'max_duration_seconds'=>901] as $field=>$invalid)
    denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['settings'=>array_replace($autoSettings,[$field=>$invalid])])),422,'invalid Auto Call limit '.$field.' rejected');
$stored=action('save_auto_call_policy',$authorization)['policy'];
c($stored['state']==='saved'&&$stored['revision']===1&&$stored['enabled']&&$stored['recording_required']&&$stored['transcription_required']&&!$stored['execution_enabled'],'exact owner authorization persists mandatory recording and explicit execution-disabled state');
c($stored['settings']===$autoSettings&&$readPolicy()===$stored,'saved time zone, window and calling limits round trip');
c(!str_contains(json_encode($stored),'provider_sid_hash'),'permission projection never exposes carrier identity fingerprints');
denies(fn()=>action('save_auto_call_policy',$authorization),409,'stale editor cannot overwrite a newer permission');
// Native Cloud Calendar fixtures exercise the released calendar writer and recurrence projection.
$previewInput=['action'=>'operator_calendar_preview','agent_id'=>0,'conversation_id'=>11,'contact_id'=>1,'number_id'=>1,'expected_revision'=>1];
denies(fn()=>api('GET',$previewInput),503,'planning requires installed native Calendar storage');
ddl("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER NOT NULL,created_by_user_id INTEGER NULL,created_by_agent_id INTEGER NULL,title VARCHAR(190) NOT NULL,description TEXT,location VARCHAR(500) NOT NULL DEFAULT '',start_at_utc DATETIME NOT NULL,end_at_utc DATETIME NOT NULL,timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',all_day INTEGER NOT NULL DEFAULT 0,recurrence VARCHAR(16) NOT NULL DEFAULT 'none',agent_notifications INTEGER NOT NULL DEFAULT 0,source VARCHAR(32) NOT NULL DEFAULT 'user',source_reference VARCHAR(190) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL DEFAULT 'active')");
ddl("CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER NOT NULL,status VARCHAR(24),start_at_utc DATETIME,end_at_utc DATETIME,event_title VARCHAR(190),guest_name VARCHAR(120),location_value VARCHAR(190),organizer_timezone VARCHAR(80),source VARCHAR(32),schedule_id INTEGER)");
require_once dirname(__DIR__).'/includes/agent-operator-calendar-v1.php';
$now=time();
$preview=vp3_operator_calendar_preview_v1($pdo,$policyScope,$previewInput,$now);
c($preview['coverage']==='cloud_calendar_only'&&!$preview['execution_enabled']&&count($preview['slots'])===3,'preview provides three bounded calling options with honest coverage');
c((int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===0,'preview never creates a calendar event');
foreach($preview['slots'] as $slot)c(vp3_operator_calendar_slot_v1($autoSettings,$slot['start_epoch'],$slot['duration_seconds'],$now)===$slot,'suggested time honors contact policy timezone and duration');
$firstSlot=$preview['slots'][0];$secondSlot=$preview['slots'][1];$thirdSlot=$preview['slots'][2];
$insertEvent=$pdo->prepare("INSERT INTO user_calendar_events(owner_user_id,title,start_at_utc,end_at_utc,timezone,recurrence) VALUES (?,?,?,?,?,?)");
$insertEvent->execute([1,'Calendar conflict',$firstSlot['start_at_utc'],$firstSlot['end_at_utc'],'America/Phoenix','none']);
$insertEvent->execute([1,'Weekly conflict',gmdate('Y-m-d H:i:s',$thirdSlot['start_epoch']-604800),gmdate('Y-m-d H:i:s',$thirdSlot['start_epoch']+$thirdSlot['duration_seconds']-604800),'America/Phoenix','weekly']);
$insertEvent->execute([2,'Other owner',$secondSlot['start_at_utc'],$secondSlot['end_at_utc'],'America/Phoenix','none']);
$pdo->prepare("INSERT INTO agent_scheduling_bookings(owner_user_id,status,start_at_utc,end_at_utc,event_title,organizer_timezone,source,schedule_id) VALUES(1,'pending',?,?,'Booking conflict','America/Phoenix','user',1)")->execute([$secondSlot['start_at_utc'],$secondSlot['end_at_utc']]);
$updatedPreview=vp3_operator_calendar_preview_v1($pdo,$policyScope,$previewInput,$now);
$blockedStarts=array_column($preview['slots'],'start_epoch');
c(!array_intersect($blockedStarts,array_column($updatedPreview['slots'],'start_epoch')),'native events, recurring events and pending bookings exclude their occupied slots');
$makePlan=static fn(array $slot):array=>['contact_id'=>1,'number_id'=>1,'expected_revision'=>1,'start_epoch'=>$slot['start_epoch'],'duration_seconds'=>$slot['duration_seconds']];
denies(fn()=>action('save_operator_calendar',$makePlan($firstSlot)),409,'saving rechecks a newly occupied calendar slot');
$slot=$updatedPreview['slots'][0];$saveInput=$makePlan($slot);
denies(fn()=>api('GET',['action'=>'save_operator_calendar']+$saveInput),405,'GET cannot save a phone appointment');
denies(fn()=>action('save_operator_calendar',array_replace($saveInput,['expected_revision'=>2])),409,'stale permission cannot save an appointment');
denies(fn()=>action('save_operator_calendar',array_replace($saveInput,['number_id'=>2])),404,'foreign assigned line cannot save an appointment');
denies(fn()=>action('save_operator_calendar',array_replace($saveInput,['csrf_token'=>'forged'])),403,'appointment requires owner CSRF');
$planned=action('save_operator_calendar',$saveInput);$plannedEvent=$planned['appointment'];
c($planned['created']&&!$plannedEvent['execution_enabled']&&$plannedEvent['event_id']>0,'explicit review creates a native reminder, not a call job');
$event=user_calendar_event_v1300($pdo,1,$plannedEvent['event_id']);
c($event['source']==='user'&&(int)$event['agent_notifications']===1&&$event['recurrence']==='none','planned call uses native Planning notifications without an autonomous execution source');
c(!str_contains($event['source_reference'],'+1602555')&&!str_contains($event['description'],'+1602555'),'calendar storage contains no dial destination or provider credentials');
$replay=action('save_operator_calendar',$saveInput);
c(!$replay['created']&&$replay['appointment']['event_id']===$plannedEvent['event_id'],'exact calendar save retries do not duplicate the event');
$existingPlan=api('GET',$previewInput)['plan'];
c($existingPlan['existing']['event_id']===$plannedEvent['event_id']&&$existingPlan['slots']===[],'existing appointment is opened rather than double-booked');
c(vp3_operator_calendar_upcoming_v1($pdo,user(),7,12,$now)['appointments'][0]['event_id']===$plannedEvent['event_id'],'Operator Brain sees planned appointments for its assigned Agent');
c(vp3_operator_calendar_upcoming_v1($pdo,user(),9,13,$now)['appointments']===[]&&vp3_operator_calendar_upcoming_v1($pdo,user(2),0,21,$now)['appointments']===[],'Operator planned appointment visibility isolates Agent and owner');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$plannedEvent['event_id']]);
denies(fn()=>action('save_operator_calendar',$saveInput),409,'cancelled appointment cannot silently resurrect on replay');
c(vp3_operator_calendar_upcoming_v1($pdo,user(),0,11,$now)['appointments']===[],'native cancellation removes the appointment from Operator Brain');
$pdo->exec("UPDATE crm_contacts SET phone='+16025550699' WHERE id=1");
c($readPolicy()['state']==='needs_review','changed primary contact number does not inherit authorization');
denies(fn()=>action('save_auto_call_policy',array_replace($authorization,['expected_revision'=>1])),409,'previous destination cannot be reauthorized silently');
$pdo->exec("UPDATE crm_contacts SET phone='+16025550999' WHERE id=1");
$pdo->exec('UPDATE phone_browser_numbers_v1 SET recording_enabled=0 WHERE phone_number_id=1');
c($readPolicy()['state']==='blocked','revoked recording approval blocks saved owner authorization');
$revoked=action('save_auto_call_policy',['contact_id'=>1,'number_id'=>1,'enabled'=>false,'expected_revision'=>1])['policy'];
c(!$revoked['enabled']&&$revoked['revision']===2,'owner can revoke while calling prerequisites are disabled');
$pdo->exec('UPDATE phone_browser_numbers_v1 SET recording_enabled=1 WHERE phone_number_id=1');
$storedAgain=action('save_auto_call_policy',array_replace($authorization,['expected_revision'=>2]))['policy'];
c($storedAgain['revision']===3&&$storedAgain['enabled'],'owner can explicitly reauthorize');
vp3_operator_auto_call_revoke_line_v1($pdo,1);
c(!$readPolicy()['enabled']&&$readPolicy()['revision']===4,'assignment invalidation revokes previously stored authority and advances its revision');
c((int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn()===$callCount
    &&(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===$recordingCount,'permission writes do not create call intents or recording jobs');
// Live caption authorization is a separate, default-off permission on an exact signed call.
require_once dirname(__DIR__).'/includes/agent-operator-live-v1.php';
c(!vp3_operator_live_schema_ready_v1($pdo)&&api('GET')['state']['operator_live']['available']===false,'unupgraded live captions leave the existing Phone workspace available');
$liveNow=time();
$livePublic=str_repeat('f',31).'1';$liveParent='CA'.str_repeat('f',31).'1';$liveChild='CA'.str_repeat('f',31).'2';
$insertLive=$pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,provider_call_sid,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch) VALUES (?,1,7,1,'+16025550101','+16025550999','outbound',?,11,0,'dialing',0,?,?)");
$insertLive->execute([$livePublic,$liveParent,$liveNow,$liveNow]);$liveCallId=(int)$pdo->lastInsertId();
$sent=0;$fixturePcm=str_repeat("\0",128000);
$speech=static function(string $audio)use(&$sent,$fixturePcm):string{
    $sent++;
    c($audio===$fixturePcm,'only exact bounded PCM bytes reach the server speech adapter');
    $wav=vp3_operator_live_wav_v1($audio);
    c(strlen($wav)===128044&&substr($wav,0,4)==='RIFF'&&substr($wav,8,8)==='WAVEfmt '&&unpack('V',substr($wav,24,4))[1]===16000,'caption transport constructs an exact 16 kHz mono PCM WAV');
    return 'Fixture remote words <script> are data.';
};
$liveRequest=['action'=>'start','csrf_token'=>'csrf-fixture','agent_id'=>0,'conversation_id'=>11,'call_id'=>$livePublic,'consent_attested'=>true];
$liveApi=static function(array $input,int $at,?callable $transport=null,int $owner=1,string $method='POST')use($pdo,$speech):array{
    return vp3_operator_live_request_v1($pdo,user($owner),$method,$input,'csrf-fixture',$transport??$speech,$at);
};
denies(fn()=>$liveApi($liveRequest,$liveNow),503,'live captions require explicit migration');
vp3_operator_live_ensure_schema_v1($pdo);vp3_operator_live_ensure_schema_v1($pdo);
c(vp3_operator_live_schema_ready_v1($pdo)&&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_live_sessions_v1')->fetchColumn()===0,'repeated caption upgrade creates no authorization');
denies(fn()=>$liveApi($liveRequest,$liveNow,null,1,'GET'),405,'caption actions never run through GET');
denies(fn()=>$liveApi(array_replace($liveRequest,['csrf_token'=>'forged']),$liveNow),403,'live caption permission requires owner CSRF');
denies(fn()=>$liveApi(array_replace($liveRequest,['consent_attested'=>false]),$liveNow),422,'recording notice is not consent to live AI transcription');
denies(fn()=>$liveApi($liveRequest,$liveNow),409,'dialing call cannot start live audio processing');
$liveForm=form($liveChild,['ParentCallSid'=>$liveParent,'CallStatus'=>'in-progress']);
c(vp3_phone_browser_status_v1($pdo,$liveForm,signature('status',$liveForm,$livePublic),$livePublic)['accepted'],'signed called leg establishes answered state for caption authority');
$liveNow=time();
denies(fn()=>$liveApi(array_replace($liveRequest,['conversation_id'=>12,'agent_id'=>7]),$liveNow),404,'other Agent Chat cannot read this exact live call');
denies(fn()=>$liveApi(array_replace($liveRequest,['conversation_id'=>21]),$liveNow,null,2),404,'other owner cannot authorize a live call');
denies(fn()=>$liveApi($liveRequest,$liveNow+301),409,'old call receipt cannot authorize live capture');
$liveBeforeMessages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$liveBeforeJobs=(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn();
$captionGrant=$liveApi($liveRequest,$liveNow);
c($captionGrant['call_id']===$livePublic&&$captionGrant['execution']==='captions_only'&&$captionGrant['expires_epoch']===$liveNow+120,'explicit consent authorizes only bounded live captions');
$captionToken=$captionGrant['token'];$sessionRow=vp3_operator_live_row_v1($pdo,$liveCallId);
c(strlen($captionToken)===64&&$sessionRow['token_hash']===hash('sha256',$captionToken)&&!str_contains(json_encode($sessionRow),$captionToken),'caption token is stored only as a hash');
denies(fn()=>$liveApi($liveRequest,$liveNow),409,'second browser cannot reopen the same caption session');
$chunk=['action'=>'chunk','csrf_token'=>'csrf-fixture','agent_id'=>0,'conversation_id'=>11,'call_id'=>$livePublic,'token'=>$captionToken,'seq'=>1,'pcm'=>base64_encode($fixturePcm)];
denies(fn()=>$liveApi(array_replace($chunk,['pcm'=>'a']),$liveNow+4),422,'invalid audio is rejected before any provider request');
denies(fn()=>$liveApi(array_replace($chunk,['token'=>str_repeat('0',64)]),$liveNow+4),409,'another browser token cannot dispatch speech');
denies(fn()=>$liveApi(array_replace($chunk,['seq'=>2]),$liveNow+4),409,'out-of-order caption chunks cannot dispatch');
denies(fn()=>$liveApi($chunk,$liveNow+1),409,'caption request cannot outrun its bounded audio interval');
c($sent===0,'rejected caption inputs do not send paid speech requests');
$caption=$liveApi($chunk,$liveNow+4);
c($caption===['call_id'=>$livePublic,'seq'=>1,'text'=>'Fixture remote words <script> are data.','speaker'=>'remote_audio']&&$sent===1,'a current authorized audio chunk returns only a transient caption');
denies(fn()=>$liveApi($chunk,$liveNow+8),409,'a previously reserved sequence cannot be billed again');
$stop=['action'=>'stop','csrf_token'=>'csrf-fixture','agent_id'=>0,'conversation_id'=>11,'call_id'=>$livePublic,'token'=>$captionToken];
$stopDuringSpeech=static function(string $audio)use(&$sent,$liveApi,$stop,$liveNow):string{
    $sent++;$liveApi($stop,$liveNow+8);return 'Late private result';
};
denies(fn()=>$liveApi(array_replace($chunk,['seq'=>2]),$liveNow+8,$stopDuringSpeech),409,'consent revoked while speech is pending suppresses the late result');
c($sent===2&&vp3_operator_live_row_v1($pdo,$liveCallId)['state']==='closed'&&(int)vp3_operator_live_row_v1($pdo,$liveCallId)['reserved_seconds']===8,'uncertain or stopped requests remain counted and cannot replay');
c($liveApi($stop,$liveNow+9)['stopped'],'owner stop is idempotent');
denies(fn()=>$liveApi(array_replace($chunk,['seq'=>3]),$liveNow+12),409,'stopped caption session cannot transmit audio');
c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$liveBeforeMessages
    &&(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===$liveBeforeJobs
    &&!str_contains(json_encode(vp3_operator_live_row_v1($pdo,$liveCallId)),'Fixture remote words'),'live caption processing does not persist speech, create a recording or post an independent Chat message');
$terminalForm=form($liveChild,['ParentCallSid'=>$liveParent,'CallStatus'=>'completed']);
vp3_phone_browser_status_v1($pdo,$terminalForm,signature('status',$terminalForm,$livePublic),$livePublic);
denies(fn()=>$liveApi(array_replace($chunk,['seq'=>3]),$liveNow+16),409,'terminal carrier receipt blocks subsequent caption audio');
$live2=str_repeat('f',31).'3';$liveParent2='CA'.str_repeat('f',31).'3';$liveChild2='CA'.str_repeat('f',31).'4';
$insertLive->execute([$live2,$liveParent2,time(),time()]);$liveCallId2=(int)$pdo->lastInsertId();
$liveForm2=form($liveChild2,['ParentCallSid'=>$liveParent2,'CallStatus'=>'in-progress']);
vp3_phone_browser_status_v1($pdo,$liveForm2,signature('status',$liveForm2,$live2),$live2);
$liveNow2=time();$req2=array_replace($liveRequest,['call_id'=>$live2]);
$grant2=$liveApi($req2,$liveNow2);
$chunk2=array_replace($chunk,['call_id'=>$live2,'token'=>$grant2['token'],'seq'=>1]);
$revokeDuringSpeech=static function(string $audio)use($pdo,&$sent):string{
    $sent++;$pdo->exec('UPDATE phone_recording_policies_v1 SET revision=revision+1 WHERE owner_user_id=1 AND agent_id=7');return 'Revoked private result';
};
denies(fn()=>$liveApi($chunk2,$liveNow2+4,$revokeDuringSpeech),409,'a changed recording/transcription policy fences an in-flight caption result');
c(vp3_operator_live_row_v1($pdo,$liveCallId2)['state']==='closed','failed authority verification closes the session');
$pdo->prepare("UPDATE phone_operator_live_sessions_v1 SET reserved_seconds=240 WHERE call_id=?")->execute([$liveCallId]);
$live3=str_repeat('f',31).'5';$liveParent3='CA'.str_repeat('f',31).'5';$liveChild3='CA'.str_repeat('f',31).'6';
$insertLive->execute([$live3,$liveParent3,time(),time()]);
$liveForm3=form($liveChild3,['ParentCallSid'=>$liveParent3,'CallStatus'=>'in-progress']);
vp3_phone_browser_status_v1($pdo,$liveForm3,signature('status',$liveForm3,$live3),$live3);
denies(fn()=>$liveApi(array_replace($liveRequest,['call_id'=>$live3]),time()),429,'account speech allowance prevents new caption authorizations');
$terminalForm3=form($liveChild3,['ParentCallSid'=>$liveParent3,'CallStatus'=>'completed']);vp3_phone_browser_status_v1($pdo,$terminalForm3,signature('status',$terminalForm3,$live3),$live3);
$terminalForm2=form($liveChild2,['ParentCallSid'=>$liveParent2,'CallStatus'=>'completed']);vp3_phone_browser_status_v1($pdo,$terminalForm2,signature('status',$terminalForm2,$live2),$live2);
$pdo->exec('UPDATE users SET plan=0 WHERE id=1');denies(fn()=>action('save_caller_contact',['call_id'=>$contactCall,'name'=>'Revoked']),403,'revoked phone access prevents contact writes');
c(vp3_operator_history_v1($pdo,user(),0,11)['coverage']==='unavailable','Operator history cannot bypass revoked phone access');

// Isolated second-account Phone Sync checks, after the existing call assertions.
$pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NOT NULL DEFAULT 'fixture-password-hash'");
if(!$mysql)$pdo->exec('PRAGMA foreign_keys=ON');
$_SESSION=['user_id'=>2,'auth_password_fingerprint'=>hash('sha256','2:fixture-password-hash')];
$ownerGet=static fn(array $extra=[])=>api('GET',array_replace(['action'=>'owner_phone_settings','conversation_id'=>21,'agent_id'=>0],$extra),2);
$ownerAct=static fn(string $name,array $extra=[])=>action($name,array_replace(['conversation_id'=>21,'agent_id'=>0],$extra),2);
denies(fn()=>$ownerGet(),503,'owner phone settings require explicit migration');
VP3OperatorOwnerPhoneV1::install($pdo);
c($ownerGet()['owner_phone']['phone_e164']==='','private owner number initially empty');
denies(fn()=>$ownerGet(['conversation_id'=>11]),403,'owner phone settings cannot read foreign Chat');
denies(fn()=>$ownerGet(['conversation_id'=>0]),403,'owner phone settings require saved private Chat');
denies(fn()=>api('POST',['action'=>'save_owner_phone','conversation_id'=>21,'agent_id'=>0,'phone_e164'=>'+16025550100','expected_revision'=>'0'],2),403,'owner phone changes require CSRF');
$mine=$ownerAct('save_owner_phone',['phone_e164'=>'+1 (602) 555-0100','expected_revision'=>'0'])['owner_phone'];
c($mine['phone_e164']==='+16025550100'&&$mine['phone_revision']===1&&$mine['revision']===1&&!$mine['execution_enabled'],'Chat saves canonical number, never live authority');
c(VP3OperatorOwnerPhoneV1::read($pdo,user(2))['phone_e164']===$mine['phone_e164'],'Account and Chat use one private number');
denies(fn()=>$ownerAct('save_owner_phone',['phone_e164'=>'+16025550108','expected_revision'=>'0']),409,'stale phone form cannot replace newer number');
$mine=$ownerAct('save_owner_call_preferences',['expected_revision'=>'1','updates_requested'=>true,'hello_requested'=>true])['owner_phone'];
c($mine['updates_requested']&&$mine['hello_requested']&&!$mine['execution_enabled'],'requested outreach preferences are non-executable');
c(VP3OperatorOwnerPhoneV1::read($pdo,user(2))['min_gap_minutes']===240,'Chat toggles preserve Account calling limits');
vp3_phone_browser_number_policy_set_v1($pdo,2,true,true);
$prepareOwner=static fn(array $extra=[])=>$ownerAct('prepare_owner_test',array_replace(['number_id'=>2,'phone_revision'=>1,'confirmed'=>true],$extra));
denies(fn()=>$prepareOwner(['confirmed'=>false]),422,'test requires explicit confirmation');
denies(fn()=>$prepareOwner(['to_number'=>'+16025550109']),422,'test never accepts arbitrary destination');
denies(fn()=>$prepareOwner(['recording_enabled'=>true]),422,'test recording denied');
denies(fn()=>$prepareOwner(['phone_revision'=>2]),409,'test rejects stale phone revision');
denies(fn()=>$prepareOwner(['number_id'=>1]),404,'test cannot use another owner line');
$test=$prepareOwner();$id=$test['call']['public_id'];$row=readcall($id);
c($test['call']['owner_test']===true&&$row['peer_e164']==='+16025550100'&&(int)$row['owner_phone_revision']===1&&!(int)$row['recording_enabled'],'test binds exact number revision and recording off');
c($row['provider_call_sid']===null&&$row['state']==='prepared','test preparation is not carrier send');
denies(fn()=>$prepareOwner(),429,'duplicate test is rate limited');
denies(fn()=>$ownerAct('confirm_owner_test',['call_id'=>$id,'phone_revision'=>1,'received'=>true]),409,'unanswered test cannot be confirmed');
$mine=$ownerAct('save_owner_phone',['phone_e164'=>'+16025550108','expected_revision'=>'2'])['owner_phone'];
c($mine['phone_revision']===2&&!$mine['updates_requested']&&!$mine['hello_requested']&&$mine['last_test']===null,'number change resets preferences and old test evidence');
$f=form('CA'.md5('ps2-superseded-parent'),['From'=>'client:'.vp3_phone_browser_identity_v1(2,0),'call_intent'=>$test['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$f,signature('voice',$f)),'<Hangup')&&readcall($id)['state']==='prepared','signed voice callback fences superseded number');
$ownerAct('cancel_prepared',['call_id'=>$id]);
denies(fn()=>$prepareOwner(['phone_revision'=>2]),429,'number change does not reset test cooldown');
$pdo->prepare('UPDATE phone_browser_calls_v1 SET created_epoch=? WHERE public_id=?')->execute([time()-121,$id]);
$test=$prepareOwner(['phone_revision'=>2]);$id2=$test['call']['public_id'];
$f=form('CA'.md5('ps2-test-parent'),['From'=>'client:'.vp3_phone_browser_identity_v1(2,0),'call_intent'=>$test['connect_params']['call_intent']]);
$xml=vp3_phone_browser_voice_v1($pdo,$f,signature('voice',$f));$row=readcall($id2);
c(str_contains($xml,'+16025550108')&&str_contains($xml,'timeLimit="60"')&&!str_contains($xml,' record='),'test TwiML limits duration and never records');
c(str_contains(vp3_phone_browser_voice_v1($pdo,$f,signature('voice',$f)),'<Hangup'),'test carrier intent single use');
$child=form('CA'.md5('ps2-test-child'),['ParentCallSid'=>$row['provider_call_sid'],'CallStatus'=>'in-progress']);
vp3_phone_browser_status_v1($pdo,$child,signature('status',$child,$id2),$id2);
c($ownerGet()['owner_phone']['last_test']['can_confirm']===true,'signed answer makes receipt confirmation available');
$scope=vp3_phone_workspace_scope_v1($pdo,user(2),['conversation_id'=>21,'agent_id'=>0]);
denies(fn()=>vp3_operator_live_call_v1($pdo,$scope,$id2,time()),409,'test call cannot authorize AI captions');
$received=$ownerAct('confirm_owner_test',['call_id'=>$id2,'phone_revision'=>2,'received'=>true])['owner_phone'];
c($received['last_test']['received']&&!$received['last_test']['can_confirm']&&!$received['execution_enabled'],'receipt is not identity certification or live grant');
$stamp=(int)readcall($id2)['owner_test_received_epoch'];
$ownerAct('confirm_owner_test',['call_id'=>$id2,'phone_revision'=>2,'received'=>true]);
c((int)readcall($id2)['owner_test_received_epoch']===$stamp,'confirmation idempotent');
$terminal=form('CA'.md5('ps2-test-child'),['ParentCallSid'=>$row['provider_call_sid'],'CallStatus'=>'completed']);
vp3_phone_browser_status_v1($pdo,$terminal,signature('status',$terminal,$id2),$id2);
$mine=$ownerAct('save_owner_phone',['phone_e164'=>'','expected_revision'=>'3'])['owner_phone'];
c($mine['phone_e164']===''&&$mine['last_test']===null,'remove number clears test projection');
unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
denies(fn()=>$ownerGet(),403,'stale browser session cannot read owner phone');


// PS4 uses the same canonical browser ledger and signed carrier callbacks.
// Build a minimal paired-device/thread fixture with the real profile installer.
require_once dirname(__DIR__).'/includes/phone-communication-profile-v1.php';
$pdo->exec('CREATE TABLE vp3_phone_devices_v1(id CHAR(32) PRIMARY KEY,owner_user_id '.($mysql?'INT UNSIGNED':'INTEGER').' NOT NULL,device_status VARCHAR(12) NOT NULL,expires_at BIGINT NOT NULL,generation BIGINT NOT NULL DEFAULT 1,UNIQUE(owner_user_id,id))'.($mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''));
vp3_phone_communication_install_v1($pdo,$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'');
$pdo->exec('CREATE TABLE vp3_paired_phone_threads_v1(device_id CHAR(32) PRIMARY KEY,owner_user_id INTEGER NOT NULL,agent_id INTEGER NOT NULL,conversation_id INTEGER NOT NULL)');
$device1=md5('ps4-device-one');$profile1=md5('ps4-profile-one');$device2=md5('ps4-device-two');$profile2=md5('ps4-profile-two');$clock=time();
$insertDevice=$pdo->prepare("INSERT INTO vp3_phone_devices_v1(id,owner_user_id,device_status,expires_at) VALUES (?,2,'approved',?)");
$insertDevice->execute([$device1,$clock+3600]);$insertDevice->execute([$device2,$clock+3600]);
$insertThread=$pdo->prepare('INSERT INTO vp3_paired_phone_threads_v1 VALUES (?,2,0,21)');$insertThread->execute([$device1]);$insertThread->execute([$device2]);
$zone=gmdate('H:i',$clock)==='23:59'?'America/Phoenix':'UTC';
$insertProfile=$pdo->prepare("INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,phone_revision,phone_e164,owner_calls,holder_calls,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,updated_epoch) VALUES(2,?,?,1,1,?,1,1,?,'00:00','23:59',2,120,?)");
$insertProfile->execute([$device1,$profile1,'+16025550135',$zone,$clock]);$insertProfile->execute([$device2,$profile2,'+16025550136',$zone,$clock]);
$prepareConnected=static fn(array $extra=[])=>$ownerAct('prepare_connected_call',array_replace(['number_id'=>2,'device_id'=>$device1,'profile_id'=>$profile1,'profile_revision'=>1,'phone_revision'=>1,'confirmed'=>true],$extra));
denies(fn()=>$prepareConnected(['confirmed'=>false]),422,'connected calling requires explicit confirmation');
denies(fn()=>$prepareConnected(['to_number'=>'+16025550199']),422,'connected calling refuses caller-supplied destination');
denies(fn()=>$prepareConnected(['recording_enabled'=>false]),422,'connected call has no recording input');
denies(fn()=>$prepareConnected(['number_id'=>1]),404,'foreign assigned line is rejected');
denies(fn()=>$prepareConnected(['profile_id'=>$profile2]),409,'cannot mix device and profile');
denies(fn()=>$prepareConnected(['profile_revision'=>2]),409,'stale profile revision rejected');
denies(fn()=>$prepareConnected(['phone_revision'=>2]),409,'stale number revision rejected');
denies(fn()=>$prepareConnected(['conversation_id'=>0]),409,'connected call needs its saved private conversation');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=0 WHERE profile_id=?')->execute([$profile1]);
denies(fn()=>$prepareConnected(),409,'holder must consent to direct calls');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=1 WHERE profile_id=?')->execute([$profile1]);
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET owner_calls=0 WHERE profile_id=?')->execute([$profile1]);
denies(fn()=>$prepareConnected(),409,'owner must also grant direct calls');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET owner_calls=1 WHERE profile_id=?')->execute([$profile1]);
$local=(new DateTimeImmutable('@'.$clock))->setTimezone(new DateTimeZone($zone))->format('H:i');
[$quietStart,$quietEnd]=$local<'12:00'?['13:00','14:00']:['01:00','02:00'];
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET window_start=?,window_end=? WHERE profile_id=?')->execute([$quietStart,$quietEnd,$profile1]);
denies(fn()=>$prepareConnected(),409,'quiet hours block reservation');
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='23:59' WHERE profile_id=?")->execute([$profile1]);
$pc=$prepareConnected();$pcid=$pc['call']['public_id'];$row=readcall($pcid);
c($pc['call']['connected_phone']===true&&$pc['call']['peer_e164']===''&&$pc['call']['peer_masked']==='Ending in 0135'
    &&$row['peer_e164']==='+16025550135'&&$row['paired_profile_id']===$profile1&&!$row['recording_enabled'],'private destination bound to profile and redacted in response');
c($row['provider_call_sid']===null,'prepared connected call is not carrier dispatch');
denies(fn()=>$prepareConnected(),429,'per-profile minimum interval includes reservations');
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=0,revision=2 WHERE profile_id=?")->execute([$profile1]);
$pcform=form('CA'.md5('ps4-denied-parent'),['From'=>'client:'.vp3_phone_browser_identity_v1(2,0),'call_intent'=>$pc['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$pcform,signature('voice',$pcform)),'<Hangup')&&readcall($pcid)['state']==='prepared','carrier cannot dial after holder revokes consent');
$ownerAct('cancel_prepared',['call_id'=>$pcid]);
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=1,revision=3 WHERE profile_id=?")->execute([$profile1]);
denies(fn()=>$prepareConnected(['profile_revision'=>3]),429,'consent edits do not reset frequency budget');
$pc2=$prepareConnected(['device_id'=>$device2,'profile_id'=>$profile2]);$pcid2=$pc2['call']['public_id'];
$pcform2=form('CA'.md5('ps4-connected-parent'),['From'=>'client:'.vp3_phone_browser_identity_v1(2,0),'call_intent'=>$pc2['connect_params']['call_intent']]);
$xml2=vp3_phone_browser_voice_v1($pdo,$pcform2,signature('voice',$pcform2));
c(str_contains($xml2,'+16025550136')&&str_contains($xml2,'timeLimit="180"')&&!str_contains($xml2,' record=')
    &&str_contains($xml2,'callerId="+16025550102"'),'second profile uses same owned line with bounded no-record call');
c(str_contains(vp3_phone_browser_voice_v1($pdo,$pcform2,signature('voice',$pcform2)),'<Hangup'),'connected call intent single use');
$pcrow=readcall($pcid2);$pcchild=form('CA'.md5('ps4-connected-child'),['ParentCallSid'=>$pcrow['provider_call_sid'],'CallStatus'=>'in-progress']);
vp3_phone_browser_status_v1($pdo,$pcchild,signature('status',$pcchild,$pcid2),$pcid2);
denies(fn()=>$ownerAct('bind_call',['call_id'=>$pcid2]),404,'connected call cannot be rebound to a different conversation');
denies(fn()=>vp3_operator_live_call_v1($pdo,$scope,$pcid2,time()),409,'connected reachability call has no AI speech authority');
denies(fn()=>$ownerAct('save_caller_contact',['call_id'=>$pcid2,'name'=>'Private phone']),403,'contact save cannot expose private destination');
$pcend=form('CA'.md5('ps4-connected-child'),['ParentCallSid'=>$pcrow['provider_call_sid'],'CallStatus'=>'completed','CallDuration'=>'15']);
vp3_phone_browser_status_v1($pdo,$pcend,signature('status',$pcend,$pcid2),$pcid2);
$recent1=vp3_connected_phone_recent_v1($pdo,2,$device1,$profile1);
$recent2=vp3_connected_phone_recent_v1($pdo,2,$device2,$profile2);
c(count($recent1)===1&&count($recent2)===1&&$recent1[0]['call_id']===$pcid&&$recent2[0]['call_id']===$pcid2&&$recent2[0]['duration_seconds']===15,'each profile has isolated redacted telephone activity');
c(vp3_connected_phone_recent_v1($pdo,1,$device2,$profile2)===[],'other account cannot read connected profile call activity');
$hist=vp3_operator_history_v1($pdo,user(2),0,21);
$connectedHistory=array_values(array_filter($hist['calls'],fn($call)=>$call['call_id']===$pcid2));
c(count($connectedHistory)===1&&$connectedHistory[0]['connected_profile_id']===$profile2&&$connectedHistory[0]['peer_e164']===''&&$connectedHistory[0]['peer_masked']==='Ending in 0136','Operator history uses separate masked profile identity');

// PS5E.1: test the actual durable admission on both database engines, no provider I/O.
require_once dirname(__DIR__).'/includes/phone-managed-callback-v1.php';
c(vp3_managed_callback_ready_v1($pdo),'managed callback migration is installed by Phone upgrade');
vp3_managed_callback_install_v1($pdo);
$managedDevice=md5('ps5e-device');$managedProfile=md5('ps5e-profile');$managedId=md5('ps5e-request-one');
$insertDevice->execute([$managedDevice,$clock+3600]);
$insertThread->execute([$managedDevice]);
$insertProfile->execute([$managedDevice,$managedProfile,'+16025550138',$zone,$clock]);
$managedArgs=['request_id'=>$managedId,'device_id'=>$managedDevice,'profile_id'=>$managedProfile,
    'generation'=>1,'profile_revision'=>1,'phone_revision'=>1,'number_id'=>2,'agent_id'=>0,'conversation_id'=>21,
    'destination_e164'=>'+16025550139','confirmed'=>true];
$managed=static fn(array $extra=[],int $owner=2,int $at=0)=>vp3_managed_callback_prepare_v1($pdo,user($owner),array_replace($managedArgs,$extra),$at?:$clock);
$holderResponse=static fn(array $extra=[],string $device='',int $at=0)=>vp3_managed_callback_holder_v1($pdo,2,$device?:$managedDevice,array_replace(['request_id'=>$managedId,'decision'=>'approve','confirmed'=>true,'destination_e164'=>'+16025550139'],$extra),$at?:$clock);
denies(fn()=>$managed(['confirmed'=>false]),422,'managed request requires explicit owner confirmation');
denies(fn()=>$managed(['recording_enabled'=>true]),422,'managed admission cannot enable recording');
denies(fn()=>$managed(['holder_e164'=>'+16025550199']),422,'managed callback refuses caller supplied holder number');
denies(fn()=>$managed(['number_id'=>1]),404,'managed callback rejects foreign Twilio line');
denies(fn()=>$managed(['generation'=>2]),409,'managed callback rejects stale pairing generation');
denies(fn()=>$managed(['profile_revision'=>2]),409,'managed callback rejects stale holder revision');
denies(fn()=>$managed(['destination_e164'=>'+16025550102']),422,'managed callback cannot dial its own line');
denies(fn()=>$managed(['destination_e164'=>'+16025550138']),422,'managed callback cannot dial its holder as second leg');
denies(fn()=>$managed(['destination_e164'=>'+16025550139#123']),422,'managed destination accepts only E.164');
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=0 WHERE profile_id=?")->execute([$managedProfile]);
denies(fn()=>$managed(),409,'managed request requires current holder calling grant');
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=1 WHERE profile_id=?")->execute([$managedProfile]);
$beforeCalls=(int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn();
$preparedManaged=$managed();
c($preparedManaged['state']==='pending'&&!isset($preparedManaged['destination_e164'],$preparedManaged['holder_e164'])&&$preparedManaged['recording_enabled']===false,'owner receives only redacted pending reservation');
c($managed()===$preparedManaged,'exact managed request retry is idempotent');
denies(fn()=>$managed(['destination_e164'=>'+16025550140']),409,'same request cannot replace target');
denies(fn()=>$managed(['request_id'=>md5('ps5e-duplicate')]),409,'one outstanding callback per owner');
$storedManaged=vp3_managed_callback_row_v1($pdo,$managedId);
c($storedManaged['holder_e164']==='+16025550138'&&$storedManaged['line_e164']==='+16025550102','managed callback binds assigned line and exact saved holder');
denies(fn()=>vp3_managed_callback_plan_v1($storedManaged,$clock,'https://cloud.example/api/answer','https://cloud.example/api/status'),409,'pending request cannot build carrier command');
denies(fn()=>$holderResponse([], $device1),404,'another paired phone cannot approve');
denies(fn()=>$holderResponse(['destination_e164'=>'+16025550140']),422,'holder approves only the exact displayed destination');
denies(fn()=>$holderResponse(['confirmed'=>false]),422,'holder requires explicit call confirmation');
$approved=$holderResponse();
c($approved['state']==='approved'&&$approved['approved_epoch']===$clock&&!isset($approved['destination_e164']),'holder approval stores bounded authority, not a call');
c($holderResponse()===$approved,'repeated holder approval does not create another effect');
$storedManaged=vp3_managed_callback_row_v1($pdo,$managedId);
$plan=vp3_managed_callback_plan_v1($storedManaged,$clock,'https://cloud.example/api/answer','https://cloud.example/api/status');
c($plan['From']==='+16025550102'&&$plan['To']==='+16025550138'&&$plan['Record']==='false'&&$plan['TimeLimit']===240
    &&!in_array('+16025550139',$plan,true),'first leg command targets holder only, not destination, and does not record');
denies(fn()=>vp3_managed_callback_plan_v1($storedManaged,$clock,'http://cloud.example/api/answer','https://cloud.example/api/status'),422,'managed plan requires HTTPS');
denies(fn()=>vp3_managed_callback_plan_v1($storedManaged,$clock,'https://user@cloud.example/api/answer','https://cloud.example/api/status'),422,'managed plan rejects URL user info');
denies(fn()=>vp3_managed_callback_plan_v1($storedManaged,$clock+120,'https://cloud.example/api/answer','https://cloud.example/api/status'),409,'expired approval cannot prepare carrier command');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET revision=2 WHERE profile_id=?')->execute([$managedProfile]);
denies(fn()=>$holderResponse(),409,'changed communication consent invalidates approved callback');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET revision=1 WHERE profile_id=?')->execute([$managedProfile]);
denies(fn()=>vp3_managed_callback_cancel_v1($pdo,user(1),$managedId,$clock),404,'foreign account cannot cancel request');
$cancel=vp3_managed_callback_cancel_v1($pdo,user(2),$managedId,$clock);
c($cancel['state']==='canceled'&&vp3_managed_callback_row_v1($pdo,$managedId)['destination_e164']==='','owner cancellation removes sensitive target');
denies(fn()=>$holderResponse(),409,'canceled request cannot be approved');
c((int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn()===$beforeCalls,'admission and planning do not insert a carrier call or execute dispatch');
$ageManaged=static function(string $id)use($pdo,$clock):void{$pdo->prepare('UPDATE phone_managed_callback_requests_v1 SET created_epoch=? WHERE public_id=?')->execute([$clock-61,$id]);};
$ageManaged($managedId);
$managedId2=md5('ps5e-request-two');
$managed(['request_id'=>$managedId2]);
$declined=$holderResponse(['request_id'=>$managedId2,'decision'=>'decline']);
c($declined['state']==='declined'&&vp3_managed_callback_row_v1($pdo,$managedId2)['holder_e164']==='','holder decline removes stored phone numbers');
c($holderResponse(['request_id'=>$managedId2,'decision'=>'decline'])===$declined,'repeat decline is safe');
$ageManaged($managedId2);
$managedId3=md5('ps5e-request-three');
$managed(['request_id'=>$managedId3]);
vp3_managed_callback_revoke_v1($pdo,2,$managedDevice,$clock);
c(vp3_managed_callback_row_v1($pdo,$managedId3)['state']==='canceled'&&vp3_managed_callback_row_v1($pdo,$managedId3)['destination_e164']==='','withdrawal cancels pending admission and clears destination');

// PS5E.2: run real reservations and signed TwiML events with a synthetic carrier.
require_once dirname(__DIR__).'/includes/phone-managed-transport-v1.php';
c(vp3_managed_transport_ready_v1($pdo),'managed transport migration installed');
vp3_managed_transport_install_v1($pdo);
$makeManagedTransport=static function(string $tag,string $holder,string $dest)use($pdo,$insertDevice,$insertThread,$insertProfile,$zone,$clock):array{
    $device=md5('transport-device-'.$tag);$profile=md5('transport-profile-'.$tag);$id=md5('transport-request-'.$tag);
    $insertDevice->execute([$device,$clock+3600]);$insertThread->execute([$device]);$insertProfile->execute([$device,$profile,$holder,$zone,$clock]);
    $input=['request_id'=>$id,'device_id'=>$device,'profile_id'=>$profile,'generation'=>1,'profile_revision'=>1,'phone_revision'=>1,
        'number_id'=>2,'agent_id'=>0,'conversation_id'=>21,'destination_e164'=>$dest,'confirmed'=>true];
    vp3_managed_callback_prepare_v1($pdo,user(2),$input,$clock);
    return ['id'=>$id,'device'=>$device,'profile'=>$profile,'holder'=>$holder,'destination'=>$dest];
};
$approveManagedTransport=static fn(array $v)=>vp3_managed_callback_holder_v1($pdo,2,$v['device'],['request_id'=>$v['id'],'decision'=>'approve','confirmed'=>true,'destination_e164'=>$v['destination']],$clock);
$transportEvent=static function(string $event,string $public,string $nonce,array $f)use($pdo,$clock):array{
    $url=vp3_managed_transport_url_v1($event,$public,$nonce);
    return vp3_managed_transport_event_v1($pdo,$event,$public,$nonce,$f,vp3_phone_sms_twilio_signature_v110($url,$f,'carrier-auth-token-fixture'),$clock);
};
$transportNonce=static function(array $plan):string{parse_str((string)parse_url($plan['Url'],PHP_URL_QUERY),$query);return $query['nonce'];};
$t1=$makeManagedTransport('first','+16025550141','+16025550142');
$posts=0;$sent=[];$parent1='CA'.md5('managed-parent-one');
$carrier=static function(array $plan)use(&$posts,&$sent,$pdo,$parent1):array{
    c(!$pdo->inTransaction(),'carrier dispatch runs outside DB transaction');$posts++;$sent=$plan;
    return ['sid'=>$parent1,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$plan['From'],'to'=>$plan['To']];
};
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],true,$carrier,$clock),503,'managed carrier dispatch defaults disabled');
$GLOBALS['settings']['phone_managed_callback_enabled_v1']='1';
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],true,$carrier,$clock),409,'unapproved request cannot dispatch');
$approveManagedTransport($t1);
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],false,$carrier,$clock),403,'dispatch requires explicit owner confirmation');
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(1),$t1['id'],true,$carrier,$clock),404,'foreign owner cannot dispatch approved request');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET revision=2 WHERE profile_id=?')->execute([$t1['profile']]);
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],true,$carrier,$clock),409,'consent revision is checked before dispatch');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET revision=1 WHERE profile_id=?')->execute([$t1['profile']]);
$out=vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],true,$carrier,$clock);
$public=$out['call']['public_id'];$nonce=$transportNonce($sent);
c($posts===1&&$out['dispatch_state']==='submitted'&&readcall($public)['provider_call_sid']===$parent1,'one carrier command binds verified parent SID');
c($out['call']['transport_kind']==='managed_callback'&&!$out['call']['browser_control']&&!$out['call']['recording_enabled']&&$out['call']['peer_e164']==='','managed call uses canonical redacted ledger without browser media controls');
c($sent['To']===$t1['holder']&&$sent['From']==='+16025550102'&&!in_array($t1['destination'],$sent,true)&&$sent['Record']==='false','first carrier leg calls holder only, not destination or recorder');
c(!str_contains(json_encode($out,JSON_THROW_ON_ERROR),$nonce),'internal carrier callback nonce not exposed in owner result');
$retry=vp3_managed_transport_dispatch_v1($pdo,user(2),$t1['id'],true,$carrier,$clock);
c($retry['call']['public_id']===$public&&$posts===1,'dispatch retries never place another first leg');
$parentForm=form($parent1,['From'=>$sent['From'],'To'=>$t1['holder'],'CallStatus'=>'in-progress']);
bad(fn()=>vp3_managed_transport_event_v1($pdo,'answer',$public,$nonce,$parentForm,'forged',$clock),'unsigned carrier event rejected');
c(!$transportEvent('answer',$public,str_repeat('f',64),$parentForm)['accepted'],'different callback nonce rejected');
c(!$transportEvent('answer',$public,$nonce,array_replace($parentForm,['To'=>'+16025550199']))['accepted'],'carrier called leg must match saved holder');
c(!$transportEvent('answer',$public,$nonce,array_replace($parentForm,['CallSid'=>'CA'.md5('other-parent')]))['accepted'],'wrong bound parent SID rejected');
c(!$transportEvent('confirm',$public,$nonce,$parentForm+['Digits'=>'1'])['accepted'],'confirm cannot precede signed answer');
$gather=$transportEvent('answer',$public,$nonce,$parentForm);
c($gather['accepted']&&str_contains($gather['xml'],'<Gather')&&!str_contains($gather['xml'],'<Dial')&&!str_contains($gather['xml'],$t1['destination']),'answer or voicemail cannot dial destination before keypad consent');
$bridge=$transportEvent('confirm',$public,$nonce,$parentForm+['Digits'=>'1']);
c($bridge['accepted']&&str_contains($bridge['xml'],'<Dial callerId="+16025550102"')&&str_contains($bridge['xml'],$t1['destination'])&&str_contains($bridge['xml'],'timeLimit="180"')&&!str_contains($bridge['xml'],' record='),'affirmative keypad consent produces exact bounded unrecorded second leg');
c(!$transportEvent('confirm',$public,$nonce,$parentForm+['Digits'=>'1'])['accepted'],'duplicate confirm cannot create a second Dial');
$childSid='CA'.md5('managed-child-one');$childForm=form($childSid,['ParentCallSid'=>$parent1,'From'=>$sent['From'],'To'=>$t1['destination'],'CallStatus'=>'ringing']);
c(!$transportEvent('child',$public,$nonce,array_replace($childForm,['ParentCallSid'=>'CA'.md5('wrong-parent')]))['accepted'],'foreign child parent binding rejected');
c(!$transportEvent('child',$public,$nonce,array_replace($childForm,['To'=>'+16025550199']))['accepted'],'wrong child target rejected');
c($transportEvent('child',$public,$nonce,$childForm)['accepted']&&readcall($public)['state']==='ringing','signed destination ring updates canonical state');
c(!vp3_phone_browser_status_v1($pdo,$childForm,signature('status',$childForm,$public),$public)['accepted'],'ordinary browser callback cannot mutate managed transport');
$transportEvent('child',$public,$nonce,array_replace($childForm,['CallStatus'=>'in-progress']));
$transportEvent('child',$public,$nonce,$childForm);
c(readcall($public)['state']==='answered','out-of-order ringing cannot regress answered destination');
$parentDone=array_replace($parentForm,['CallStatus'=>'completed','CallDuration'=>'45']);
$transportEvent('parent',$public,$nonce,$parentDone);
c(readcall($public)['state']==='answered','completed parent alone does not prove destination completion');
$childDone=array_replace($childForm,['CallStatus'=>'completed','CallDuration'=>'31']);
$transportEvent('child',$public,$nonce,$childDone);
$finished=readcall($public);
c($finished['state']==='completed'&&(int)$finished['duration_seconds']===31&&$finished['provider_child_sid']===$childSid,'signed child completion records destination duration and identity');
c(vp3_managed_callback_row_v1($pdo,$t1['id'])['destination_e164']===''&&vp3_managed_callback_row_v1($pdo,$t1['id'])['holder_e164']==='','terminal callback scrubs temporary routing numbers');
c($transportEvent('child',$public,$nonce,$childDone)['duplicate']===true,'duplicate terminal event is idempotent');

$t2=$makeManagedTransport('uncertain','+16025550143','+16025550144');$approveManagedTransport($t2);
$uncertainPosts=0;$uncertainPlan=[];
$uncertainCarrier=static function(array $plan)use(&$uncertainPosts,&$uncertainPlan):array{$uncertainPosts++;$uncertainPlan=$plan;throw new RuntimeException('Synthetic uncertain carrier response');};
$unknown=vp3_managed_transport_dispatch_v1($pdo,user(2),$t2['id'],true,$uncertainCarrier,$clock);
$unknownPublic=$unknown['call']['public_id'];$unknownNonce=$transportNonce($uncertainPlan);
c($unknown['dispatch_state']==='uncertain'&&!readcall($unknownPublic)['provider_call_sid']&&$uncertainPosts===1,'lost carrier response is uncertain, never assumed failed or retried');
vp3_managed_transport_dispatch_v1($pdo,user(2),$t2['id'],true,$uncertainCarrier,$clock);
c($uncertainPosts===1,'uncertain reservation cannot produce another POST');
$unknownParent='CA'.md5('managed-parent-unknown');$unknownForm=form($unknownParent,['From'=>$uncertainPlan['From'],'To'=>$t2['holder'],'CallStatus'=>'in-progress']);
c($transportEvent('answer',$unknownPublic,$unknownNonce,$unknownForm)['accepted']&&readcall($unknownPublic)['provider_call_sid']===$unknownParent,'signed answer can recover parent binding after lost REST receipt');
c(!$transportEvent('confirm',$unknownPublic,$unknownNonce,$unknownForm+['Digits'=>'2'])['accepted'],'nonaffirmative keypad answer hangs up');
c(!$transportEvent('confirm',$unknownPublic,$unknownNonce,$unknownForm+['Digits'=>'1'])['accepted'],'declined challenge cannot subsequently authorize destination');
$transportEvent('parent',$unknownPublic,$unknownNonce,array_replace($unknownForm,['CallStatus'=>'completed','CallDuration'=>'10']));
c(readcall($unknownPublic)['state']==='canceled'&&(int)readcall($unknownPublic)['duration_seconds']===0,'parent-only connection is not a completed destination call');

$t3=$makeManagedTransport('revoked','+16025550145','+16025550146');$approveManagedTransport($t3);
$revokedPlan=[];$revokedParent='CA'.md5('managed-parent-revoked');
$revokedCarrier=static function(array $plan)use(&$revokedPlan,$revokedParent):array{$revokedPlan=$plan;return ['sid'=>$revokedParent,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$plan['From'],'to'=>$plan['To']];};
$revoked=vp3_managed_transport_dispatch_v1($pdo,user(2),$t3['id'],true,$revokedCarrier,$clock);
$revokedPublic=$revoked['call']['public_id'];$revokedNonce=$transportNonce($revokedPlan);
$revokedForm=form($revokedParent,['From'=>$revokedPlan['From'],'To'=>$t3['holder'],'CallStatus'=>'in-progress']);
c($transportEvent('answer',$revokedPublic,$revokedNonce,$revokedForm)['accepted'],'third callback reaches challenge');
vp3_managed_callback_revoke_v1($pdo,2,$t3['device'],$clock);
c(!$transportEvent('confirm',$revokedPublic,$revokedNonce,$revokedForm+['Digits'=>'1'])['accepted'],'withdrawal between answer and confirmation prevents destination dial');
$transportEvent('parent',$revokedPublic,$revokedNonce,array_replace($revokedForm,['CallStatus'=>'completed']));
c(readcall($revokedPublic)['state']==='canceled'&&vp3_managed_callback_row_v1($pdo,$t3['id'])['destination_e164']==='','signed parent termination resolves revoked unbridged call');
unset($GLOBALS['settings']['phone_managed_callback_enabled_v1']);

// PS5E.3: the owner/holder review boundaries reuse authenticated, immutable scope.
require_once dirname(__DIR__).'/includes/phone-managed-ui-v1.php';
$uiDevice=md5('managed-ui-device');$uiProfile=md5('managed-ui-profile');$uiId=md5('managed-ui-request');
$insertDevice->execute([$uiDevice,$clock+3600]);$insertThread->execute([$uiDevice]);$insertProfile->execute([$uiDevice,$uiProfile,'+16025550147',$zone,$clock]);
$uiArgs=['device_id'=>$uiDevice,'generation'=>1,'managed_request_id'=>$uiId,'profile_id'=>$uiProfile,
    'profile_revision'=>1,'phone_revision'=>1,'number_id'=>2,'destination_e164'=>'+16025550148','confirmed'=>true];
$uiRaw=vp3_managed_ui_device_v1($pdo,2,$uiDevice);
$uiView=vp3_managed_ui_projection_v1($pdo,$uiRaw,$clock,false);
c($uiView['available']&&$uiView['dispatch_enabled']===false&&array_column($uiView['lines'],'id')===[2],'map exposes only eligible assigned line, and review-only mode');
c(vp3_managed_ui_projection_v1($pdo,$uiRaw,$clock,true)['lines']===[],'holder does not receive owner line inventory');
denies(fn()=>vp3_managed_ui_owner_action_v1($pdo,user(1),'managed_prepare',$uiArgs,$clock),404,'foreign owner cannot prepare managed review');
denies(fn()=>vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_dispatch',$uiArgs,$clock),422,'review interface cannot invoke live dispatch');
denies(fn()=>vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_prepare',$uiArgs+['agent_id'=>1],$clock),422,'client may not supply alternate Chat scope');
denies(fn()=>vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_prepare',$uiArgs+['recording_enabled'=>true],$clock),422,'client may not enable recording');
$beforeUI=(int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn();
$uiSaved=vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_prepare',$uiArgs,$clock)['managed_callback'];
c($uiSaved['request']['state']==='pending'&&!isset($uiSaved['request']['destination_e164'])&&$uiSaved['dispatch_enabled']===false,'owner prepares review, not a paid call');
c((int)vp3_managed_callback_row_v1($pdo,$uiId)['conversation_id']===21,'review derives paired conversation server-side');
$holderUI=vp3_managed_ui_projection_v1($pdo,$uiRaw,$clock,true);
c($holderUI['request']['destination_e164']==='+16025550148','paired holder sees exact pending target');
$uiResponse=['device_id'=>$uiDevice,'generation'=>1,'managed_request_id'=>$uiId,'decision'=>'approve','destination_e164'=>'+16025550148','confirmed'=>true];
denies(fn()=>vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_managed_ui_holder_action_v1($pdo,vp3_managed_ui_device_v1($pdo,2,$device1),$uiResponse,$clock)),403,'holder action cannot use another paired device');
denies(fn()=>vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_managed_ui_holder_action_v1($pdo,$uiRaw,array_replace($uiResponse,['destination_e164'=>'+16025550149']),$clock)),422,'holder cannot approve another destination');
$uiApproved=vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_managed_ui_holder_action_v1($pdo,$uiRaw,$uiResponse,$clock))['managed_callback'];
c($uiApproved['request']['state']==='approved'&&!isset($uiApproved['request']['destination_e164']),'holder approval is reflected without retaining destination in the view');
$uiCancel=['device_id'=>$uiDevice,'generation'=>1,'managed_request_id'=>$uiId];
c(vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_cancel',$uiCancel,$clock)['managed_callback']['request']['state']==='canceled','owner can cancel approved review');
c((int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn()===$beforeUI,'review UI creates no carrier ledger or billable call');
$uiId2=md5('managed-ui-request-two');
$pdo->prepare('UPDATE phone_managed_callback_requests_v1 SET created_epoch=? WHERE public_id=?')->execute([$clock-61,$uiId]);
vp3_managed_ui_owner_action_v1($pdo,user(2),'managed_prepare',array_replace($uiArgs,['managed_request_id'=>$uiId2]),$clock);
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET revision=2 WHERE profile_id=?')->execute([$uiProfile]);
c(vp3_managed_ui_projection_v1($pdo,$uiRaw,$clock,true)['request']===null,'stale profile request is not disclosed to handset');
denies(fn()=>vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_managed_ui_holder_action_v1($pdo,$uiRaw,array_replace($uiResponse,['managed_request_id'=>$uiId2]),$clock)),409,'stale holder consent cannot approve');
vp3_managed_callback_revoke_v1($pdo,2,$uiDevice,$clock);

// PS5E.4: exercise provider read/stop controls with synthetic, exact SID evidence.
require_once dirname(__DIR__).'/includes/phone-managed-control-v1.php';
c(vp3_managed_control_ready_v1($pdo),'managed control migration installed');
vp3_managed_control_install_v1($pdo);
$controlScope=vp3_phone_workspace_scope_v1($pdo,user(2),['agent_id'=>0,'conversation_id'=>21]);
// Earlier revoked-access assertions intentionally disabled owner 1's package.
// Restore the fixture so cross-account denial tests exercise call ownership.
$pdo->exec('UPDATE users SET plan=1 WHERE id=1');
$foreignControlScope=vp3_phone_workspace_scope_v1($pdo,user(1),['agent_id'=>0,'conversation_id'=>11]);
$carrierAccount=(string)setting('phone_twilio_account_sid');
$carrierEvidence=static function(string $sid,string $to,string $status,string $duration='',?string $parent=null)use($carrierAccount):array{
    return ['sid'=>$sid,'account_sid'=>$carrierAccount,'from'=>'+16025550102','to'=>$to,'direction'=>$parent===null?'outbound-api':'outbound-dial',
        'parent_call_sid'=>$parent,'status'=>$status,'duration'=>$duration];
};
$controlDispatch=static function(array $v,string $tag)use($pdo,$clock,$approveManagedTransport,$transportNonce):array{
    $approveManagedTransport($v);$plan=[];$sid='CA'.md5('control-parent-'.$tag);
    $carrier=static function(array $fields)use(&$plan,$sid):array{$plan=$fields;return ['sid'=>$sid,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$fields['From'],'to'=>$fields['To']];};
    $result=vp3_managed_transport_dispatch_v1($pdo,user(2),$v['id'],true,$carrier,$clock);
    return ['public'=>$result['call']['public_id'],'sid'=>$sid,'nonce'=>$transportNonce($plan)];
};
$GLOBALS['settings']['phone_managed_callback_enabled_v1']='1';
$t4=$makeManagedTransport('reconcile','+16025550150','+16025550151');$d4=$controlDispatch($t4,'reconcile');
$p4=$d4['public'];$parent4=$d4['sid'];$nonce4=$d4['nonce'];
$read4=['action'=>'managed_reconcile','call_id'=>$p4];$end4=['action'=>'managed_end','call_id'=>$p4,'confirmed'=>true];
$noCarrier=static function():array{throw new LogicException('Unexpected carrier request');};
denies(fn()=>vp3_managed_control_action_v1($pdo,$foreignControlScope,$read4,$noCarrier,$clock),404,'foreign account cannot reconcile managed carrier call');
denies(fn()=>vp3_managed_control_action_v1($pdo,array_replace($controlScope,['conversation_id'=>999]),$read4,$noCarrier,$clock),404,'unrelated Chat cannot control managed call');
denies(fn()=>vp3_managed_control_action_v1($pdo,$controlScope,array_replace($end4,['confirmed'=>false]),$noCarrier,$clock),403,'managed end requires explicit confirmation');
c(vp3_managed_control_evidence_v1(array_replace($carrierEvidence($parent4,$t4['holder'],'completed','12'),['account_sid'=>'AC'.str_repeat('f',32)]),$carrierAccount,readcall($p4))===null,'wrong carrier account rejected as evidence');
$readMethods=[];
$parentRead=static function(string $method,string $resource,array $fields)use(&$readMethods,$carrierEvidence,$parent4,$t4,$pdo):array{
    c(!$pdo->inTransaction(),'managed control carrier IO is outside transaction');
    $readMethods[]=$method;c(str_contains($resource,$parent4)&&$fields===[],'carrier read targets exact stored parent SID');
    return $carrierEvidence($parent4,$t4['holder'],'in-progress');
};
$checked=vp3_managed_control_action_v1($pdo,$controlScope,$read4,$parentRead,$clock);
c($readMethods===['GET']&&$checked['call']['state']==='dialing'&&$checked['call']['managed_control']['read_state']==='parent_answered','parent answered evidence never implies destination answered');
denies(fn()=>vp3_managed_control_action_v1($pdo,$controlScope,$read4,$noCarrier,$clock+1),429,'carrier status checks are rate limited');
$parentForm4=form($parent4,['From'=>'+16025550102','To'=>$t4['holder'],'CallStatus'=>'in-progress']);
c($transportEvent('answer',$p4,$nonce4,$parentForm4)['accepted'],'signed parent answer opens confirmation');
c($transportEvent('confirm',$p4,$nonce4,$parentForm4+['Digits'=>'1'])['accepted'],'holder confirms second leg');
$child4='CA'.md5('control-child-reconcile');
$childForm4=form($child4,['ParentCallSid'=>$parent4,'From'=>'+16025550102','To'=>$t4['destination'],'CallStatus'=>'ringing']);
c($transportEvent('child',$p4,$nonce4,$childForm4)['accepted'],'signed destination binds child SID');
$readLegs=[];
$terminalRead=static function(string $method,string $resource,array $fields)use(&$readLegs,$carrierEvidence,$parent4,$child4,$t4):array{
    c($method==='GET'&&$fields===[],'reconciliation never mutates carrier');$readLegs[]=$resource;
    if(str_contains($resource,$parent4))return $carrierEvidence($parent4,$t4['holder'],'completed','44');
    return $carrierEvidence($child4,$t4['destination'],'completed','27',$parent4);
};
$done=vp3_managed_control_action_v1($pdo,$controlScope,$read4,$terminalRead,$clock+11);
c(count($readLegs)===2&&$done['call']['state']==='completed'&&$done['call']['duration_seconds']===27&&$done['call']['managed_control']['read_state']==='terminal_verified','read-only bound leg evidence resolves lost terminal callback using child duration');
c(!$done['call']['managed_control']['can_end']&&!$done['call']['managed_control']['can_reconcile'],'terminal call has no further carrier controls');
$noCalls=0;$unexpected=static function()use(&$noCalls):array{$noCalls++;return [];};
vp3_managed_control_action_v1($pdo,$controlScope,$end4,$unexpected,$clock+12);
c($noCalls===0,'terminal call end is idempotent without provider IO');

$t5=$makeManagedTransport('stop-uncertain','+16025550152','+16025550153');$d5=$controlDispatch($t5,'stop-uncertain');
$p5=$d5['public'];$parent5=$d5['sid'];$nonce5=$d5['nonce'];$end5=['action'=>'managed_end','call_id'=>$p5,'confirmed'=>true];
$stopMethods=[];
$stopCarrier=static function(string $method,string $resource,array $fields)use(&$stopMethods,$carrierEvidence,$parent5,$t5,$pdo):array{
    c(!$pdo->inTransaction()&&str_contains($resource,$parent5),'end action targets bound parent outside DB transaction');
    $stopMethods[]=$method;
    if($method==='GET')return $carrierEvidence($parent5,$t5['holder'],'ringing');
    c($fields===['Status'=>'canceled'],'ringing parent uses cancellation instead of new call');
    throw new RuntimeException('Synthetic lost end receipt');
};
$stopResult=vp3_managed_control_action_v1($pdo,$controlScope,$end5,$stopCarrier,$clock);
c($stopMethods===['GET','POST']&&$stopResult['call']['managed_control']['end_state']==='uncertain','lost end receipt is not reported as successful');
c(vp3_managed_callback_row_v1($pdo,$t5['id'])['state']==='revoked','end intent fences destination authorization before carrier IO');
$parentForm5=form($parent5,['From'=>'+16025550102','To'=>$t5['holder'],'CallStatus'=>'in-progress']);
c(!$transportEvent('answer',$p5,$nonce5,$parentForm5)['accepted'],'late answer cannot bridge after end intent');
vp3_managed_control_action_v1($pdo,$controlScope,$end5,$stopCarrier,$clock+1);
c($stopMethods===['GET','POST'],'uncertain end is never automatically sent twice');
$stopRead=static fn(string $method,string $resource,array $fields):array=>$carrierEvidence($parent5,$t5['holder'],'no-answer');
$stopDone=vp3_managed_control_action_v1($pdo,$controlScope,['action'=>'managed_reconcile','call_id'=>$p5],$stopRead,$clock+11);
c($stopDone['call']['state']==='no_answer'&&$stopDone['call']['managed_control']['missed_leg']==='holder','verified unanswered parent is distinct from a missed destination');

// No parent SID: no number/time-based provider lookup. Stop fences even an uncertain first leg.
$t6=$makeManagedTransport('unbound-stop','+16025550154','+16025550155');$approveManagedTransport($t6);
$unboundPlan=[];$unboundCarrier=static function(array $fields)use(&$unboundPlan):array{$unboundPlan=$fields;throw new RuntimeException('Synthetic lost dispatch receipt');};
$unbound=vp3_managed_transport_dispatch_v1($pdo,user(2),$t6['id'],true,$unboundCarrier,$clock);
$p6=$unbound['call']['public_id'];$nonce6=$transportNonce($unboundPlan);
$beforeNoCalls=$noCalls;
$unboundRead=vp3_managed_control_action_v1($pdo,$controlScope,['action'=>'managed_reconcile','call_id'=>$p6],$unexpected,$clock);
c($unboundRead['call']['managed_control']['read_state']==='parent_unbound'&&$noCalls===$beforeNoCalls,'unbound parent cannot be discovered by untrusted number or time');
$unboundEnd=vp3_managed_control_action_v1($pdo,$controlScope,['action'=>'managed_end','call_id'=>$p6,'confirmed'=>true],$unexpected,$clock);
c($unboundEnd['call']['managed_control']['end_state']==='awaiting_identity'&&$noCalls===$beforeNoCalls,'unbound stop records intent but sends no carrier command');
$parent6='CA'.md5('control-parent-unbound-stop');
$parentForm6=form($parent6,['From'=>'+16025550102','To'=>$t6['holder'],'CallStatus'=>'in-progress']);
c(!$transportEvent('answer',$p6,$nonce6,$parentForm6)['accepted']&&readcall($p6)['provider_call_sid']===$parent6,'late signed callback binds identity but cannot bridge after stop');
$knownEndMethods=[];
$knownEnd=static function(string $method,string $resource,array $fields)use(&$knownEndMethods,$carrierEvidence,$parent6,$t6):array{
    $knownEndMethods[]=$method;return $carrierEvidence($parent6,$t6['holder'],$method==='GET'?'in-progress':'completed',$method==='GET'?'':'8');
};
$knownStop=vp3_managed_control_action_v1($pdo,$controlScope,['action'=>'managed_end','call_id'=>$p6,'confirmed'=>true],$knownEnd,$clock+1);
c($knownEndMethods===['GET','POST']&&$knownStop['call']['state']==='canceled'&&$knownStop['call']['managed_control']['end_state']==='parent_ended','explicit end after signed identity uses one bound carrier stop');
unset($GLOBALS['settings']['phone_managed_callback_enabled_v1']);

// PS5E.5: real consent and recording receipt domain with synthetic provider commands.
require_once dirname(__DIR__).'/includes/phone-managed-recording-v1.php';
c(vp3_managed_recording_ready_v1($pdo),'managed recording consent migration installed');
vp3_managed_recording_install_v1($pdo);
vp3_phone_browser_number_policy_set_v1($pdo,2,true,true);
$recPolicy=vp3_phone_recording_save_policy_v1($pdo,user(2),8,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
$recordDevice=md5('ps5e5-device');$recordProfile=md5('ps5e5-profile');$recordRequest=md5('ps5e5-request');
$insertDevice->execute([$recordDevice,$clock+3600]);$insertThread->execute([$recordDevice]);$insertProfile->execute([$recordDevice,$recordProfile,'+16025550156',$zone,$clock]);
$recArgs=['request_id'=>$recordRequest,'device_id'=>$recordDevice,'profile_id'=>$recordProfile,'generation'=>1,'profile_revision'=>1,'phone_revision'=>1,'number_id'=>2,'agent_id'=>0,'conversation_id'=>21,'destination_e164'=>'+16025550157','confirmed'=>true,'recording_requested'=>true,'recording_policy_revision'=>$recPolicy['revision']];
$recApprove=['request_id'=>$recordRequest,'decision'=>'approve','confirmed'=>true,'destination_e164'=>'+16025550157','recording_consent'=>true,'recording_consent_revision'=>1];
$recPrepare=static fn(array $extra=[])=>vp3_managed_callback_prepare_v1($pdo,user(2),array_replace($recArgs,$extra),$clock);
$recHolder=static fn(array $extra=[])=>vp3_managed_callback_holder_v1($pdo,2,$recordDevice,array_replace($recApprove,$extra),$clock);
denies(fn()=>$recPrepare(['recording_policy_revision'=>$recPolicy['revision']+1]),409,'owner cannot select a stale processing policy');
$recView=$recPrepare();
c($recView['recording']['requested']&&$recView['recording']['transcribe']&&!$recView['recording']['analyze']&&!$recView['recording_enabled'],'recording review snapshots exact owner policy without recording');
denies(fn()=>$recPrepare(['recording_requested'=>false]),409,'request retry cannot silently remove recording consent');
denies(fn()=>$recHolder(['recording_consent'=>false]),422,'holder must explicitly accept recording');
denies(fn()=>$recHolder(['recording_consent_revision'=>2]),422,'holder cannot consent to another revision');
c($recHolder()['recording']['holder_consented'],'holder accepts exact per-call recording and processing policy');
$GLOBALS['settings']['phone_managed_callback_enabled_v1']='1';
$recordPlan=[];$recParent='CA'.md5('managed-recording-parent');
$recCarrier=static function(array $fields)use(&$recordPlan,$recParent):array{
    $recordPlan=$fields;return ['sid'=>$recParent,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$fields['From'],'to'=>$fields['To']];
};
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$recordRequest,true,$recCarrier,$clock),409,'recording-requested call cannot dispatch with recording capability disabled');
$GLOBALS['settings']['phone_managed_recording_enabled_v1']='1';
$pdo->prepare('UPDATE phone_browser_calls_v1 SET created_epoch=? WHERE owner_user_id=2')->execute([$clock-601]);
$recCall=vp3_managed_transport_dispatch_v1($pdo,user(2),$recordRequest,true,$recCarrier,$clock);
$recPublic=$recCall['call']['public_id'];$recNonce=$transportNonce($recordPlan);
c($recordPlan['Record']==='false'&&!$recCall['call']['recording_enabled'],'first call leg never starts a recording');
$recParentForm=form($recParent,['From'=>'+16025550102','To'=>'+16025550156','CallStatus'=>'in-progress']);
$notice=$transportEvent('answer',$recPublic,$recNonce,$recParentForm);
c($notice['accepted']&&str_contains($notice['xml'],'Cloud AI will transcribe')&&!str_contains($notice['xml'],'<Dial'),'holder hears recording and processing disclosure before pressing a key');
$bridge=$transportEvent('confirm',$recPublic,$recNonce,$recParentForm+['Digits'=>'1']);
c($bridge['accepted']&&str_contains($bridge['xml'],'record_notice')&&!str_contains($bridge['xml'],' record='),'bridge includes destination consent whisper but no record attribute');
$recChild='CA'.md5('managed-recording-child');
$recChildForm=form($recChild,['ParentCallSid'=>$recParent,'From'=>'+16025550102','To'=>'+16025550157','CallStatus'=>'in-progress']);
$recIO=0;
$noRecording=static function()use(&$recIO):array{$recIO++;return [];};
denies(fn()=>vp3_managed_recording_start_v1($pdo,$recPublic,$recNonce,$noRecording,$clock),409,'recording cannot begin without destination consent');
c($recIO===0,'no provider IO before both parties consent');
c(!$transportEvent('record_notice',$recPublic,$recNonce,array_replace($recChildForm,['To'=>'+16025550199']))['accepted'],'wrong destination cannot enter recording consent');
$destNotice=$transportEvent('record_notice',$recPublic,$recNonce,$recChildForm);
c($destNotice['accepted']&&str_contains($destNotice['xml'],'<Gather')&&!str_contains($destNotice['xml'],'<Record'),'destination hears affirmative consent prompt without recording');
c($transportEvent('record_consent',$recPublic,$recNonce,$recChildForm+['Digits'=>'1'])['accepted'],'destination affirmative consent binds exact child');
c(!$transportEvent('record_consent',$recPublic,$recNonce,$recChildForm+['Digits'=>'1'])['accepted'],'recording consent replay cannot create another authorization');
$transportEvent('child',$recPublic,$recNonce,$recChildForm);
$recSid='RE'.md5('managed-recording-media');
$recForm=form($recParent,['RecordingSid'=>$recSid,'RecordingStatus'=>'completed','RecordingSource'=>'StartCallRecordingAPI','RecordingChannels'=>'2','RecordingTrack'=>'both','RecordingDuration'=>'12']);
c(!$transportEvent('recording',$recPublic,$recNonce,$recForm)['accepted'],'receipt before a recording start claim is rejected');
$recMethods=[];
$recTransport=static function(string $method,string $resource,array $fields)use(&$recMethods,$carrierEvidence,$recParent,$recChild,$recSid,$pdo):array{
    c(!$pdo->inTransaction(),'recording start provider IO runs outside transaction');
    $recMethods[]=$method;
    if($method==='GET'){
        if(str_contains($resource,$recParent))return $carrierEvidence($recParent,'+16025550156','in-progress');
        return $carrierEvidence($recChild,'+16025550157','in-progress','',$recParent);
    }
    c(str_ends_with($resource,'/Calls/'.$recParent.'/Recordings.json')&&$fields['RecordingChannels']==='dual'&&$fields['RecordingTrack']==='both'&&$fields['PlayBeep']==='true','one exact parent dual-channel recording request with audible beep');
    return ['sid'=>$recSid,'account_sid'=>setting('phone_twilio_account_sid'),'call_sid'=>$recParent,'source'=>'StartCallRecordingAPI','status'=>'in-progress','channels'=>2];
};
require_once dirname(__DIR__).'/includes/phone-managed-media-worker-v1.php';
c(vp3_managed_media_tick_v1($pdo,$recTransport,$clock)['attempted']===0&&$recMethods===[],'automatic recording driver defaults disabled');
$GLOBALS['settings']['phone_managed_media_driver_enabled_v1']='1';
$driven=vp3_managed_media_tick_v1($pdo,$recTransport,$clock);
$started=vp3_managed_recording_view_v1(vp3_managed_recording_row_v1($pdo,$recordRequest));
c($driven['started']===1&&$driven['attempted']===1,'automatic driver consumes exact consented active call once');
unset($GLOBALS['settings']['phone_managed_media_driver_enabled_v1']);
c($started['state']==='started'&&$recMethods===['GET','GET','POST']&&(int)readcall($recPublic)['recording_enabled']===1,'verified consent and both exact active legs permit one recording command');
denies(fn()=>vp3_managed_recording_start_v1($pdo,$recPublic,$recNonce,$recTransport,$clock),409,'recording start cannot be replayed');
c($recMethods===['GET','GET','POST'],'no duplicate recording provider mutation');
c(!$transportEvent('recording',$recPublic,$recNonce,array_replace($recForm,['RecordingSid'=>'RE'.md5('other-recording')]))['accepted'],'different media SID cannot use consented receipt');
c(!$transportEvent('recording',$recPublic,$recNonce,array_replace($recForm,['RecordingChannels'=>'1']))['accepted'],'wrong recording channel mode rejected');
c(!vp3_phone_browser_recording_v1($pdo,$recForm,signature('recording',$recForm,$recPublic),$recPublic)['accepted'],'ordinary browser recording endpoint cannot bypass managed consent');
$saved= $transportEvent('recording',$recPublic,$recNonce,$recForm);
c($saved['accepted']&&$saved['queued']&&$saved['recording']['peer_e164']==='','signed managed recording enters existing private pipeline with redacted peer');
$jobId=vp3_phone_recording_job_id_v1($saved['recording']['id']);$job=vp3_phone_recording_row_v1($pdo,$jobId);
c(vp3_phone_recording_decode_v1($job['policy_json'])===$recPolicy&&$job['call_public_id']===$recPublic,'post-call job preserves exact consented processing policy');
c($transportEvent('recording',$recPublic,$recNonce,$recForm)['duplicate']===true,'duplicate recording callback is idempotent');
$transportEvent('child',$recPublic,$recNonce,array_replace($recChildForm,['CallStatus'=>'completed','CallDuration'=>'15']));
vp3_phone_recording_authorize_v1($pdo,$job,true);
vp3_phone_recording_save_policy_v1($pdo,user(2),8,['transcribe'=>false]);
bad(fn()=>vp3_phone_recording_authorize_v1($pdo,$job,true),'changed owner policy cannot broaden consent or run additional paid processing');
unset($GLOBALS['settings']['phone_managed_recording_enabled_v1'],$GLOBALS['settings']['phone_managed_callback_enabled_v1']);

// PS5E.6: saved report evidence, profile continuity and count-only coordination.
require_once dirname(__DIR__).'/includes/agent-operator-managed-intelligence-v1.php';
$intelScope=vp3_phone_workspace_scope_v1($pdo,user(2),['agent_id'=>0,'conversation_id'=>21]);
$intelScope['principal']=['kind'=>'system'];
$intelForeign=vp3_phone_workspace_scope_v1($pdo,user(1),['agent_id'=>0,'conversation_id'=>11]);
$intelClock=time();
$queuedReview=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock);
c($queuedReview['coverage']==='queued'&&$queuedReview['report']===null&&!$queuedReview['execution_enabled'],'queued recording does not invent a report or execution permission');
denies(fn()=>vp3_managed_intelligence_review_v1($pdo,$intelForeign,$recPublic,$intelClock),404,'managed report cannot cross account');
denies(fn()=>vp3_managed_intelligence_review_v1($pdo,array_replace($intelScope,['conversation_id'=>999]),$recPublic,$intelClock),404,'managed report cannot cross exact conversation');
denies(fn()=>api('POST',['action'=>'managed_review','call_id'=>$recPublic,'conversation_id'=>21,'agent_id'=>0,'csrf_token'=>'csrf-fixture'],2),405,'conversation review is read only');
if($mysql){
    ddl('CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,metadata_json TEXT,discarded_at DATETIME NULL)');
    ddl('CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,segment_type VARCHAR(30),transcript_text TEXT)');
}
$intelSession=772;$intelHash=str_repeat('e',64);$intelRevision=str_repeat('f',64);
$intelMeta=['source'=>'phone_call_v1','phone_recording_v1'=>['job_id'=>$jobId,'call_kind'=>'browser','call_id'=>$recPublic,'agent_id'=>8,'transport_kind'=>'managed_callback']];
$pdo->prepare('INSERT INTO artist_transcript_sessions_v172 VALUES(?,2,?,NULL)')->execute([$intelSession,json_encode($intelMeta,JSON_THROW_ON_ERROR)]);
$pdo->prepare("INSERT INTO artist_transcript_segments_v172 VALUES(2,?,'transcript','Fixture private managed speech')")->execute([$intelSession]);
$reportText="Phone call report\nKey findings\nReview the synthetic proposal.\nActions & follow-ups\nConfirm timing.\nOpen questions\nWhich day?\n";
$intelDelivery=vp3_phone_workspace_delivery_v1($pdo,readcall($recPublic),'voicemail','phone-recording-report:'.$job['public_id'],$reportText,
    ['voicemail_id'=>'job-'.$jobId,'report'=>['source_hash'=>$intelHash,'review_state'=>'unreviewed','text'=>$reportText]]);
$pdo->prepare("UPDATE phone_recording_jobs_v1 SET state='ready',session_id=?,transcript_hash=?,analysis_hash=?,report_message_id=? WHERE id=?")
    ->execute([$intelSession,$intelHash,str_repeat('a',64),$intelDelivery['message_id'],$jobId]);
$intelCandidate=['session_id'=>$intelSession,'source_hash'=>$intelHash,'kind'=>'followup','plugin'=>'actions','section'=>'items','item_id'=>'managed:1',
    'item_revision'=>$intelRevision,'review_state'=>'unreviewed','question'=>'Confirm timing.','next_step'=>'Review the original call.',
    'source_url'=>'https://untrusted.example/other?page=1'];
$intelSaved=['session_id'=>$intelSession,'source_hash'=>$intelHash,'fresh'=>true,'page_count'=>2,'research_candidates'=>[
    $intelCandidate,array_replace($intelCandidate,['plugin'=>'research_brief','item_id'=>'managed:outside-policy']),
    array_replace($intelCandidate,['item_id'=>'managed:rejected','review_state'=>'rejected']),$intelCandidate]];
$intelReads=0;$intelReader=static function(int $id)use(&$intelReads,$intelSaved,$intelSession):array{
    $intelReads++;if($id!==$intelSession)throw new RuntimeException('Wrong managed fixture session');return $intelSaved;
};
$noAnalysis=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,$intelReader);
c($noAnalysis['coverage']==='analysis_off'&&$noAnalysis['report']===null&&$noAnalysis['followups']===[],'transcription-only consent does not expose unconsented analysis');
c(vp3_operator_followup_queue_v1($pdo,$intelScope,new DateTimeImmutable('@'.$intelClock),['followup:'.$job['public_id']],$intelReader)['reports']===[],'transcription-only managed recording has no proactive analysis candidate');
// Explicit historical analysis fixture, not a production policy update: the
// original consent snapshot and its saved job reflect the same authorized modules.
$intelPolicy=array_replace($recPolicy,['analyze'=>true,'modules'=>['basic','actions','qa','decisions','followup']]);
$intelPolicyJson=vp3_phone_recording_json_v1($intelPolicy);
$pdo->prepare('UPDATE phone_managed_recording_consent_v1 SET policy_json=? WHERE request_id=?')->execute([$intelPolicyJson,$recordRequest]);
$pdo->prepare('UPDATE phone_recording_jobs_v1 SET policy_json=? WHERE id=?')->execute([$intelPolicyJson,$jobId]);
$intelReview=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,$intelReader);
c($intelReview['coverage']==='ready'&&$intelReview['report']['historical']&&$intelReview['report']['review_state']==='unreviewed'&&count($intelReview['followups'])===1,'saved managed report and consented canonical follow-up appear in private review');
c($intelReview['continuity']['state']==='permission_required','ongoing connected-profile context defaults off');
c(!str_contains(json_encode($intelReview),'Fixture private managed speech')&&!str_contains(json_encode($intelReview),'provider_call_sid')&&!str_contains(json_encode($intelReview),'untrusted.example'),'review exposes no raw transcript, provider ID or foreign evidence URL');
$beforeIntelReads=$intelReads;
$intelQueue=vp3_operator_followup_queue_v1($pdo,$intelScope,new DateTimeImmutable('@'.$intelClock),['followup:'.$job['public_id']],$intelReader);
c($intelQueue['reports']===[]&&$intelReads===$beforeIntelReads,'no proactive candidate read without current connected memory grant');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=1 WHERE profile_id=?')->execute([$recordProfile]);
$intelReview=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,$intelReader);
c($intelReview['continuity']['state']==='available'&&$intelReview['continuity']['calls']===[],'history excludes different connected profile calls');
$intelQueue=vp3_operator_followup_queue_v1($pdo,$intelScope,new DateTimeImmutable('@'.$intelClock),['followup:'.$job['public_id']],$intelReader);
c(count($intelQueue['reports'])===1&&count($intelQueue['reports'][0]['items'])===1,'consented managed report reuses existing Operator followup queue with module filter');
$staleIntel=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,static fn(int $id):array=>array_replace($intelSaved,['source_hash'=>str_repeat('d',64)]));
c($staleIntel['coverage']==='stale'&&$staleIntel['report']===null&&$staleIntel['followups']===[],'changed transcript cannot reuse the prior report');
$managedRow=readcall($recPublic);
denies(fn()=>vp3_phone_workspace_delivery_v1($pdo,array_replace($managedRow,['conversation_id'=>999]),'voicemail','fixture-managed-wrong-chat','Private report'),409,'managed report cannot fall back to another Chat');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE profile_id=?')->execute([$recordProfile]);
c(vp3_operator_followup_queue_v1($pdo,$intelScope,new DateTimeImmutable('@'.$intelClock),['followup:'.$job['public_id']],$intelReader)['reports']===[],'withdrawing memory grant removes proactive followup');
$pdo->prepare('DELETE FROM phone_browser_deliveries_v1 WHERE owner_user_id=2 AND event_key=?')->execute(['phone-recording-report:'.$job['public_id']]);
$missingReport=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,$intelReader);
c($missingReport['coverage']==='stale'&&$missingReport['report']===null,'removed report delivery prevents source reuse');
vp3_managed_recording_revoke_v1($pdo,$recordRequest,$intelClock);
$revokedReview=vp3_managed_intelligence_review_v1($pdo,$intelScope,$recPublic,$intelClock,$intelReader);
c($revokedReview['coverage']==='consent_unavailable'&&$revokedReview['report']===null&&$revokedReview['followups']===[],'recording consent withdrawal blocks further managed report review');


// PS5E.7: native caller admission, single-use keypad, no carrier or live dial.
require_once dirname(__DIR__).'/includes/phone-native-dialthrough-v1.php';
c(vp3_native_dialthrough_ready_v1($pdo),'native dial-through schema installed');
vp3_native_dialthrough_install_v1($pdo);
$nativeClock=$clock;
$nativeNumber=['id'=>2,'owner_user_id'=>2,'agent_id'=>8,'voice_enabled'=>1];
$nativeLine='+16025550102';
$makeNative=static function(string $tag,string $holder,string $destination)use($pdo,$insertDevice,$insertThread,$insertProfile,$zone,$nativeClock):array{
    $device=md5('native-device-'.$tag);$profile=md5('native-profile-'.$tag);$id=md5('native-request-'.$tag);
    $insertDevice->execute([$device,$nativeClock+3600]);$insertThread->execute([$device]);$insertProfile->execute([$device,$profile,$holder,$zone,$nativeClock]);
    $args=['request_id'=>$id,'device_id'=>$device,'profile_id'=>$profile,'generation'=>1,'profile_revision'=>1,'phone_revision'=>1,
        'number_id'=>2,'agent_id'=>0,'conversation_id'=>21,'destination_e164'=>$destination,'confirmed'=>true,'transport'=>'twilio_native'];
    $review=vp3_managed_callback_prepare_v1($pdo,user(2),$args,$nativeClock);
    c($review['transport']==='twilio_native'&&$review['native']['can_issue_pin']===false&&!$review['recording_enabled'],'owner prepares native review but cannot issue holder PIN');
    return ['id'=>$id,'device'=>$device,'profile'=>$profile,'holder'=>$holder,'destination'=>$destination,'args'=>$args];
};
$nativeIssue=static function(array $v,array $extra=[])use($pdo,$nativeClock):array{
    $d=vp3_managed_ui_device_v1($pdo,2,$v['device']);
    return vp3_phone_workspace_atomic_v1($pdo,fn()=>vp3_native_dialthrough_issue_v1($pdo,$d,array_replace(['device_id'=>$v['device'],'generation'=>1,'managed_request_id'=>$v['id'],'confirmed'=>true],$extra),$nativeClock));
};
$nativeApprove=static fn(array $v)=>vp3_managed_callback_holder_v1($pdo,2,$v['device'],['request_id'=>$v['id'],'decision'=>'approve','confirmed'=>true,'destination_e164'=>$v['destination']],$nativeClock);
$nativeInbound=static fn(string $sid,string $from,int $at=0)=>vp3_native_dialthrough_inbound_v1($pdo,$nativeNumber,$sid,$from,$nativeLine,$at?:$nativeClock);
$nativeEvent=static function(string $event,string $public,string $nonce,array $form,int $at=0)use($pdo,$nativeClock):array{
    $url=vp3_managed_transport_url_v1($event,$public,$nonce);
    return vp3_managed_transport_event_v1($pdo,$event,$public,$nonce,$form,vp3_phone_sms_twilio_signature_v110($url,$form,'carrier-auth-token-fixture'),$at?:$nativeClock);
};
$nv=$makeNative('valid','+16025550158','+16025550159');
denies(fn()=>$nativeIssue($nv),503,'native pilot defaults disabled');
$GLOBALS['settings']['phone_managed_callback_enabled_v1']='1';
$GLOBALS['settings']['phone_native_dialthrough_enabled_v1']='1';
$nativePilotFor=static function(array $v)use($nativeClock):void{
    $GLOBALS['settings']['phone_managed_live_pilot_enabled_v1']='1';
    $GLOBALS['settings']['phone_managed_pilot_scope_v1']=json_encode(['owner_user_id'=>2,'number_id'=>2,'device_id'=>$v['device'],'holder_e164'=>$v['holder'],'destination_e164'=>$v['destination'],'expires_epoch'=>$nativeClock+900],JSON_THROW_ON_ERROR);
};
$nativePilotFor($nv);
denies(fn()=>$nativeIssue($nv),409,'pending owner review cannot issue native code');
denies(fn()=>vp3_managed_callback_prepare_v1($pdo,user(2),array_replace($nv['args'],['transport'=>'twilio_callback']),$nativeClock),409,'native mode cannot be changed by retry');
denies(fn()=>vp3_managed_callback_prepare_v1($pdo,user(2),$nv['args']+['recording_requested'=>true],$nativeClock),422,'native pilot refuses recording');
$nativeApprove($nv);
denies(fn()=>vp3_managed_transport_dispatch_v1($pdo,user(2),$nv['id'],true,static fn()=>throw new LogicException('No carrier IO'),$nativeClock),409,'native approval cannot be used for callback dispatch');
denies(fn()=>$nativeIssue($nv,['confirmed'=>false]),422,'code issuance requires holder gesture');
$beforeNativeCalls=(int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn();
$ticket=$nativeIssue($nv)['native_dialthrough_ticket'];$pin=$ticket['pin'];
c(preg_match('/^[0-9]{8}$/D',$pin)===1&&$ticket['line_e164']===$nativeLine&&$ticket['expires_epoch']===$nativeClock+120,'one private eight digit code bound to assigned line and expiry');
$nativeStored=vp3_native_dialthrough_row_v1($pdo,$nv['id']);
c($nativeStored['pin_hash']!==$pin&&hash_equals($nativeStored['pin_hash'],vp3_native_dialthrough_digest_v1($nv['id'],$pin)),'only keyed PIN digest retained');
$nativePoll=vp3_managed_ui_projection_v1($pdo,vp3_managed_ui_device_v1($pdo,2,$nv['device']),$nativeClock,true);
c(!isset($nativePoll['request']['native']['pin'],$nativePoll['request']['native']['pin_hash'])&&!$nativePoll['request']['native']['can_issue_pin'],'status does not replay secret code');
denies(fn()=>$nativeIssue($nv),409,'one code issuance per request');
c($nativeInbound('CA'.md5('ordinary-native'),'+16025550198')===null,'unrelated caller retains ordinary routing');
$sid='CA'.md5('native-first');$form=form($sid,['From'=>$nv['holder'],'To'=>$nativeLine,'Direction'=>'inbound']);
$prompt=$nativeInbound($sid,$nv['holder']);
c($prompt&&$prompt['accepted']&&str_contains($prompt['twiml'],'numDigits="8"')&&!str_contains($prompt['twiml'],'<Dial')&&!str_contains($prompt['twiml'],$nv['destination']),'matching caller ID alone produces only keypad challenge');
c((int)$pdo->query('SELECT COUNT(*) FROM phone_browser_calls_v1')->fetchColumn()===$beforeNativeCalls,'no billable destination call or managed ledger before PIN');
$nativeNonce=vp3_native_dialthrough_nonce_v1($nv['id'],$sid);
$pinForm=$form+['Digits'=>$pin];
bad(fn()=>vp3_managed_transport_event_v1($pdo,'native_pin',$nv['id'],$nativeNonce,$pinForm,'forged',$nativeClock),'forged PIN callback rejected');
c(!$nativeEvent('native_pin',$nv['id'],$nativeNonce,array_replace($pinForm,['CallSid'=>'CA'.md5('foreign-native')]))['accepted'],'another parent cannot use the code');
$bridgeNative=$nativeEvent('native_pin',$nv['id'],$nativeNonce,$pinForm);
c($bridgeNative['accepted']&&str_contains($bridgeNative['xml'],'<Dial callerId="'.$nativeLine.'"')&&str_contains($bridgeNative['xml'],$nv['destination'])&&!str_contains($bridgeNative['xml'],' record='),'signed correct code authorizes exact bounded unrecorded destination');
$nativeStored=vp3_native_dialthrough_row_v1($pdo,$nv['id']);$nativePublic=$nativeStored['call_public_id'];
c($nativeStored['state']==='consumed'&&$nativeStored['pin_hash']===null,'successful PIN consumed and erased');
c(readcall($nativePublic)['provider_call_sid']===$sid&&readcall($nativePublic)['managed_parent_kind']==='native','canonical call ledger binds inbound parent');
c(!$nativeEvent('native_pin',$nv['id'],$nativeNonce,$pinForm)['accepted'],'signed PIN replay never returns another Dial');
c(!str_contains($nativeInbound($sid,$nv['holder'])['twiml'],'<Gather'),'inbound replay never falls back to normal voicemail');
preg_match('/action="([^"]+)"/',$bridgeNative['xml'],$m);parse_str((string)parse_url(html_entity_decode($m[1],ENT_QUOTES|ENT_XML1),PHP_URL_QUERY),$nativeQuery);
$childNative='CA'.md5('native-child');$childFormNative=form($childNative,['ParentCallSid'=>$sid,'From'=>$nativeLine,'To'=>$nv['destination'],'CallStatus'=>'in-progress']);
c($nativeEvent('child',$nativePublic,$nativeQuery['nonce'],$childFormNative)['accepted'],'signed native destination child updates shared ledger');
$nativeParentEvidence=['sid'=>$sid,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$nv['holder'],'to'=>$nativeLine,'direction'=>'inbound','parent_call_sid'=>null,'status'=>'in-progress','duration'=>''];
c(vp3_managed_control_evidence_v1($nativeParentEvidence,(string)setting('phone_twilio_account_sid'),readcall($nativePublic))['state']==='answered','control evidence validates reversed native parent direction');
$completeNative=form($sid,['From'=>$nv['holder'],'To'=>$nativeLine,'DialCallSid'=>$childNative,'DialCallStatus'=>'completed','DialCallDuration'=>'22']);
c($nativeEvent('complete',$nativePublic,$nativeQuery['nonce'],$completeNative)['accepted']&&readcall($nativePublic)['state']==='completed'&&(int)readcall($nativePublic)['duration_seconds']===22,'native completion records destination duration, not parent time');
$badNative=$makeNative('wrong-pin','+16025550160','+16025550161');$nativePilotFor($badNative);$nativeApprove($badNative);$badTicket=$nativeIssue($badNative)['native_dialthrough_ticket'];
$badSid='CA'.md5('native-wrong');$nativeInbound($badSid,$badNative['holder']);
$badNonce=vp3_native_dialthrough_nonce_v1($badNative['id'],$badSid);$badForm=form($badSid,['From'=>$badNative['holder'],'To'=>$nativeLine,'Digits'=>$badTicket['pin']==='00000000'?'11111111':'00000000']);
c(!$nativeEvent('native_pin',$badNative['id'],$badNonce,$badForm)['accepted'],'incorrect PIN cannot bridge');
c(vp3_native_dialthrough_row_v1($pdo,$badNative['id'])['pin_hash']===null&&vp3_managed_callback_row_v1($pdo,$badNative['id'])['state']==='canceled','incorrect code consumes request and clears secret');
c(!$nativeEvent('native_pin',$badNative['id'],$badNonce,array_replace($badForm,['Digits'=>$badTicket['pin']]))['accepted'],'no second attempt after wrong code');
$revNative=$makeNative('revoked','+16025550162','+16025550163');$nativePilotFor($revNative);$nativeApprove($revNative);$revTicket=$nativeIssue($revNative)['native_dialthrough_ticket'];
$revSid='CA'.md5('native-revoked');$nativeInbound($revSid,$revNative['holder']);$revNonce=vp3_native_dialthrough_nonce_v1($revNative['id'],$revSid);
vp3_managed_callback_revoke_v1($pdo,2,$revNative['device'],$nativeClock);
c(!$nativeEvent('native_pin',$revNative['id'],$revNonce,form($revSid,['From'=>$revNative['holder'],'To'=>$nativeLine,'Digits'=>$revTicket['pin']]))['accepted'],'pairing withdrawal before PIN blocks bridge');
c(vp3_native_dialthrough_row_v1($pdo,$revNative['id'])['pin_hash']===null,'withdrawal erases PIN digest');
unset($GLOBALS['settings']['phone_native_dialthrough_enabled_v1'],$GLOBALS['settings']['phone_managed_callback_enabled_v1'],$GLOBALS['settings']['phone_managed_live_pilot_enabled_v1'],$GLOBALS['settings']['phone_managed_pilot_scope_v1']);


// PS5E.8: public pilot boundary is narrower than transport test seams.
$pilot=$makeManagedTransport('supervised-pilot','+16025550164','+16025550165');$approveManagedTransport($pilot);
$pilotArgs=['device_id'=>$pilot['device'],'generation'=>1,'managed_request_id'=>$pilot['id'],'confirmed'=>true];
$pilotPosts=0;$pilotPlan=[];$pilotParent='CA'.md5('supervised-pilot-parent');
$pilotCarrier=static function(array $fields)use(&$pilotPosts,&$pilotPlan,$pilotParent):array{
    $pilotPosts++;$pilotPlan=$fields;return ['sid'=>$pilotParent,'account_sid'=>setting('phone_twilio_account_sid'),'from'=>$fields['From'],'to'=>$fields['To']];
};
$GLOBALS['settings']['phone_managed_callback_enabled_v1']='1';
denies(fn()=>vp3_managed_ui_pilot_dispatch_v1($pdo,user(2),$pilotArgs,$clock,$pilotCarrier),403,'base transport flag alone never exposes paid pilot dispatch');
c($pilotPosts===0,'disabled pilot sends no carrier command');
denies(fn()=>vp3_managed_pilot_configure_v1($pdo,array_replace(user(2),['denied'=>1]),['number_id'=>2,'device_id'=>$pilot['device'],'destination_e164'=>$pilot['destination'],'minutes'=>15,'confirmed'=>true],$clock),403,'denied administrator cannot configure pilot');
vp3_managed_pilot_configure_v1($pdo,user(2),['number_id'=>2,'device_id'=>$pilot['device'],'destination_e164'=>$pilot['destination'],'minutes'=>15,'confirmed'=>true],$clock);
c($pilotPosts===0,'pilot configuration itself is not dispatch');
$pilotView=vp3_managed_ui_projection_v1($pdo,vp3_managed_ui_device_v1($pdo,2,$pilot['device']),$clock,false);
c($pilotView['dispatch_enabled']===true&&$pilotView['request']['pilot']['recording_available']===false,'only exact approved unrecorded pilot exposes dispatch');
denies(fn()=>vp3_managed_ui_pilot_dispatch_v1($pdo,user(2),array_replace($pilotArgs,['confirmed'=>false]),$clock,$pilotCarrier),422,'pilot still requires explicit billable action confirmation');
denies(fn()=>vp3_managed_ui_pilot_dispatch_v1($pdo,user(2),$pilotArgs+['destination_e164'=>'+16025550199'],$clock,$pilotCarrier),422,'pilot action cannot replace destination');
denies(fn()=>vp3_managed_ui_pilot_dispatch_v1($pdo,user(1),$pilotArgs,$clock,$pilotCarrier),404,'pilot cannot cross owner accounts');
$pilotDispatch=vp3_managed_ui_pilot_dispatch_v1($pdo,user(2),$pilotArgs,$clock,$pilotCarrier);
$pilotPublic=$pilotDispatch['call']['public_id'];$pilotNonce=$transportNonce($pilotPlan);
c($pilotPosts===1&&$pilotDispatch['dispatch_state']==='submitted'&&(int)vp3_managed_transport_row_v1($pdo,$pilotPublic)['pilot_required']===1,'one explicit pilot action reserves and submits the exact call');
vp3_managed_ui_pilot_dispatch_v1($pdo,user(2),$pilotArgs,$clock,$pilotCarrier);
c($pilotPosts===1,'pilot replay does not repeat billable POST');
$pilotForm=form($pilotParent,['From'=>'+16025550102','To'=>$pilot['holder'],'CallStatus'=>'in-progress']);
c($transportEvent('answer',$pilotPublic,$pilotNonce,$pilotForm)['accepted'],'approved pilot parent reaches confirmation');
vp3_managed_pilot_disable_v1(user(2));
c(!$transportEvent('confirm',$pilotPublic,$pilotNonce,$pilotForm+['Digits'=>'1'])['accepted'],'disabling pilot between answer and confirm prevents paid second leg');
$transportEvent('parent',$pilotPublic,$pilotNonce,array_replace($pilotForm,['CallStatus'=>'completed']));
c(readcall($pilotPublic)['state']==='canceled','parent-only pilot termination cannot be a completed destination call');
unset($GLOBALS['settings']['phone_managed_callback_enabled_v1']);

// Existing recorded fixture: simulate lost recording completion state to
// exercise withdrawal stop independently of the already tested report receipt.
$pdo->prepare("UPDATE phone_managed_recording_consent_v1 SET start_state='started',stop_state='none',stop_epoch=0 WHERE request_id=?")->execute([$recordRequest]);
$mediaCalls=[];
$mediaCarrier=static function(string $method,string $resource,array $fields)use(&$mediaCalls,$recSid,$recParent,$pdo):array{
    c(!$pdo->inTransaction(),'recording stop IO is outside transaction');$mediaCalls[]=$method;
    c(str_ends_with($resource,'/Calls/'.$recParent.'/Recordings/'.$recSid.'.json'),'stop addresses exact known recording resource');
    if($method==='POST')c($fields===['Status'=>'stopped'],'recording stop command cannot start or resume recording');
    return ['sid'=>$recSid,'account_sid'=>setting('phone_twilio_account_sid'),'call_sid'=>$recParent,'source'=>'StartCallRecordingAPI','track'=>'both','channels'=>2,'status'=>$method==='GET'?'processing':'stopped'];
};
$stopped=vp3_managed_media_tick_v1($pdo,$mediaCarrier,$clock);
c($stopped['stopped']===1&&$mediaCalls===['GET','POST'],'withdrawal driver stops exact recording even when start flag is off');
vp3_managed_media_tick_v1($pdo,$mediaCarrier,$clock);
c($mediaCalls===['GET','POST'],'verified stop is not repeated');
$jobCount=(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn();
$discard=$transportEvent('recording',$recPublic,$recNonce,$recForm);
c($discard['accepted']&&$discard['queued']===false&&(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===$jobCount,'signed terminal receipt after withdrawal closes media without adding a processing job');

echo 'PHONE_BROWSER_WORKSPACE_V1=PASS ('.$checks.' checks)'.PHP_EOL;
