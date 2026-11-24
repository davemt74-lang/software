<?php
declare(strict_types=1);
// Production helpers only; no live carrier, speech, model or configuration request.
define('STONEFELLOW_ROOT',dirname(__DIR__));
require dirname(__DIR__).'/includes/phone-browser-v1.php';
require dirname(__DIR__).'/includes/phone-browser-endpoint-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-specialist-v1.php';
require dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
$dsn=getenv('VP3_PHONE_MYSQL_DSN')?:'sqlite::memory:';$mysql=str_starts_with($dsn,'mysql:');
$pdo=new PDO($dsn,$mysql?(getenv('VP3_PHONE_MYSQL_USER')?:'root'):null,$mysql?(getenv('VP3_PHONE_MYSQL_PASSWORD')?:''):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($mysql)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{$q=db()->prepare($GLOBALS['mysql']?'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();}
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
if($mysql)foreach(['phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_numbers_v1','phone_browser_favorites_v1','phone_browser_preferences_v1','phone_browser_calls_v1','phone_voice_recordings_v130','phone_voice_calls_v130','crm_contact_phones','crm_contacts','chat_messages','chat_conversations','phone_sms_assignment_migrations_v111','phone_sms_threads_v110','phone_cloud_numbers_v100','user_agents','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
ddl('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,plan INTEGER,denied INTEGER DEFAULT 0)');
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
vp3_phone_recording_ensure_schema_v1($pdo);
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
$pdo->exec('UPDATE users SET plan=0 WHERE id=1');denies(fn()=>action('save_caller_contact',['call_id'=>$contactCall,'name'=>'Revoked']),403,'revoked phone access prevents contact writes');
echo 'PHONE_BROWSER_WORKSPACE_V1=PASS ('.$checks.' checks)'.PHP_EOL;
