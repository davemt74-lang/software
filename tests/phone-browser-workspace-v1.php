<?php
declare(strict_types=1);
// Production helpers only; no live carrier, speech, model or configuration request.
define('STONEFELLOW_ROOT',dirname(__DIR__));
require dirname(__DIR__).'/includes/phone-browser-v1.php';
require dirname(__DIR__).'/includes/phone-browser-endpoint-v1.php';
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
if($mysql)foreach(['phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_numbers_v1','phone_browser_favorites_v1','phone_browser_preferences_v1','phone_browser_calls_v1','phone_voice_recordings_v130','phone_voice_calls_v130','crm_contacts','chat_messages','chat_conversations','phone_sms_assignment_migrations_v111','phone_sms_threads_v110','phone_cloud_numbers_v100','user_agents','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
ddl('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,plan INTEGER,denied INTEGER DEFAULT 0)');
ddl('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
ddl('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider VARCHAR(24),provider_sid VARCHAR(40),phone_e164 VARCHAR(20),owner_user_id INTEGER,agent_id INTEGER,status VARCHAR(24),voice_enabled INTEGER)');
ddl('CREATE TABLE phone_sms_threads_v110(id INTEGER PRIMARY KEY,phone_number_id INTEGER,owner_user_id INTEGER,agent_id INTEGER,contact_e164 TEXT,UNIQUE(phone_number_id,owner_user_id,agent_id,contact_e164))');
ddl('CREATE TABLE phone_sms_assignment_migrations_v111(id INTEGER PRIMARY KEY)');$pdo->exec('INSERT INTO phone_sms_assignment_migrations_v111 VALUES(1)');
ddl('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,title TEXT,created_at DATETIME,updated_at DATETIME)');
ddl('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT,created_at DATETIME)');
ddl('CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name TEXT,phone TEXT,status TEXT)');
ddl("CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,provider VARCHAR(24),provider_call_sid TEXT,owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 TEXT,called_e164 TEXT,state VARCHAR(24) DEFAULT 'voicemail_offered',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid))");
ddl('CREATE TABLE phone_voice_recordings_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,call_id INTEGER,owner_user_id INTEGER,provider VARCHAR(24),provider_recording_sid VARCHAR(40),recording_status VARCHAR(24),duration_seconds INTEGER,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_recording_sid))');
$pdo->exec("INSERT INTO users VALUES(1,1,1,0),(2,1,1,0),(3,1,1,0),(4,0,1,0),(5,1,1,1)");
$pdo->exec("INSERT INTO user_agents VALUES(7,1,1,'First Agent'),(8,2,1,'Foreign Agent'),(9,1,1,'Second Agent'),(10,4,1,'Disabled'),(11,5,1,'Denied'),(12,1,0,'Archived')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES(1,'twilio','PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','+16025550101',1,7,'ready',1),(2,'twilio','PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','+16025550102',2,8,'ready',1),(3,'twilio','PNcccccccccccccccccccccccccccccccc','+16025550103',1,9,'ready',1),(4,'twilio','PNdddddddddddddddddddddddddddddddd','+16025550104',4,10,'ready',1),(5,'twilio','PNeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee','+16025550105',5,11,'ready',1),(6,'twilio','PNffffffffffffffffffffffffffffffff','+16025550106',1,12,'ready',1)");
$pdo->exec("INSERT INTO chat_conversations VALUES(11,1,NULL,'Main','2026-01-01','2026-01-01'),(12,1,7,'First','2026-01-01','2026-01-01'),(13,1,9,'Second','2026-01-01','2026-01-01'),(21,2,NULL,'Foreign','2026-01-01','2026-01-01')");
$pdo->exec("INSERT INTO crm_contacts VALUES(1,1,'Mine','+16025550999','active'),(2,2,'Foreign','+16025550888','active'),(3,1,'Archived','+16025550777','archived')");
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
$token=action('token',['number_id'=>1]);$parts=explode('.',$token['token']);$claims=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
c($claims['sub']===setting('phone_twilio_account_sid')&&$claims['iss']===setting('phone_browser_api_key_sid_v1')&&$claims['exp']-$claims['iat']===300,'short JWT binds configured Twilio account and API key');
c($claims['grants']['identity']===$token['identity']&&$claims['grants']['voice']['incoming']['allow']&&$claims['grants']['voice']['outgoing']['application_sid']===setting('phone_browser_twiml_app_sid_v1'),'JWT grants only owner identity and configured TwiML app');
c(hash_equals(vp3_phone_browser_base64_v1(hash_hmac('sha256',$parts[0].'.'.$parts[1],'api-secret-fixture-123456',true)),$parts[2]),'JWT authenticated by API secret');
c(!str_contains(json_encode($token),'carrier-auth-token-fixture')&&!str_contains(json_encode($token),'api-secret-fixture'),'response excludes credentials');
denies(fn()=>action('token',['number_id'=>2]),404,'foreign assigned caller ID denied');
denies(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'+16025550999','conversation_id'=>0]),409,'outgoing requires canonical private Chat');
bad(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'911']),'destination requires international E164');
denies(fn()=>action('prepare_call',['number_id'=>1,'to_number'=>'+16025550999','recording_enabled'=>'true']),422,'recording requires explicit JSON boolean');
$first=prepare();$public=$first['call']['public_id'];c($first['call']['agent_id']===7&&$first['call']['conversation_id']===11,'number Agent7 can place call in owner MAIN agent0');
c(!str_contains(json_encode($first['call']),'intent')&&!str_contains(json_encode($first['call']),'provider_call_sid'),'public call does not disclose carrier handles or intent hash');
c(readcall($public)['intent_hash']===hash('sha256',$first['connect_params']['call_intent']),'only hashed short-lived intent persisted');
denies(fn()=>prepare(),409,'one prepared call prevents parallel dial');
action('cancel_prepared',['call_id'=>$public]);c(readcall($public)['state']==='canceled','preconnect failure can release unconsumed intent');
$out=prepare(true);$public=$out['call']['public_id'];c(readcall($public)['recording_enabled']==1,'explicit recording plus admin approval retained');
$wrong=form('CA'.str_repeat('b',32),['From'=>'client:'.vp3_phone_browser_identity_v1(2),'call_intent'=>$out['connect_params']['call_intent']]);
c(str_contains(vp3_phone_browser_voice_v1($pdo,$wrong,signature('voice',$wrong)),'Hangup')&&readcall($public)['intent_consumed_epoch']==0,'another client identity cannot consume intent');
bad(fn()=>vp3_phone_browser_voice_v1($pdo,$wrong,'forged'),'forged Twilio signature rejected');
$wrong['AccountSid']='AC'.str_repeat('f',32);bad(fn()=>vp3_phone_browser_voice_v1($pdo,$wrong,signature('voice',$wrong)),'cross Twilio account rejected');
[$xml,$voice]=start($out,'b',['To'=>'+16025550000','CallerId'=>'+16025550001']);
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
c(status($call,'ringing')['duplicate']&&readcall($public)['state']==='answered','out-of-order ringing cannot regress answered');
c(status($call,'completed','c',['CallDuration'=>'24'])['accepted']&&readcall($public)['state']==='completed','terminal callback finalizes duration');
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
$route=['phone_number_id'=>3,'owner_user_id'=>1,'agent_id'=>9,'caller_e164'=>'+16025550888'];
c(vp3_phone_browser_incoming_v1($pdo,$route,'CA'.str_repeat('1',32))===null,'Agent A Device cannot receive Agent B incoming call');
action('token',['number_id'=>3]);
$sid='CA'.str_repeat('2',32);$pdo->prepare('INSERT INTO phone_voice_calls_v130(provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164) VALUES (?,?,?,?,?,?,?)')->execute(['twilio',$sid,1,3,9,'+16025550888','+16025550103']);
$incoming=vp3_phone_browser_incoming_v1($pdo,$route,$sid);$in=vp3_phone_browser_call_by_sid_v1($pdo,$sid);
c(is_string($incoming)&&str_contains($incoming,'<Identity>'.vp3_phone_browser_identity_v1(1,0).'</Identity>')&&str_contains($incoming,'phone_call_id'),'MAIN Device receives owner second Agent with server card reference');
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
echo 'PHONE_BROWSER_WORKSPACE_V1=PASS ('.$checks.' checks)'.PHP_EOL;
