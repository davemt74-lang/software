<?php
declare(strict_types=1);
// Production helpers only; no live carrier, speech, model or configuration request.
$directory=sys_get_temp_dir().'/vp3-voicemail-'.bin2hex(random_bytes(8));mkdir($directory,0700);
define('STONEFELLOW_ROOT',$directory);
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
function vp3_participant_scope_v1(): ?array{return $GLOBALS['fixture_participant_scope']??null;}
// An external DSN is an explicitly dedicated disposable test database, like the existing phone assignment fixture.
if($mysql)foreach(['phone_voicemail_deletions_v1','phone_voicemail_greeting_tokens_v1','phone_voicemail_receipts_v1','phone_voicemail_settings_v1','phone_native_dialthrough_v1','phone_managed_recording_consent_v1','phone_managed_callback_controls_v1','phone_managed_callback_dispatch_v1','phone_managed_callback_requests_v1','vp3_paired_phone_threads_v1','vp3_phone_communication_profiles_v1','vp3_phone_devices_v1','phone_operator_owner_preferences_v1','phone_operator_live_sessions_v1','user_calendar_events','agent_scheduling_bookings','phone_operator_contact_policies_v1','artist_transcript_master_analysis_v237','artist_transcript_segments_v172','artist_transcript_sessions_v172','phone_recording_speech_budget_v1','phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_numbers_v1','phone_browser_favorites_v1','phone_browser_preferences_v1','phone_browser_calls_v1','phone_voice_recordings_v130','phone_voice_calls_v130','crm_contact_phones','crm_contacts','chat_messages','chat_conversations','phone_sms_assignment_migrations_v111','phone_sms_threads_v110','phone_cloud_numbers_v100','user_agents','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
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

vp3_phone_workspace_ensure_schema_v1($pdo);vp3_phone_recording_ensure_schema_v1($pdo);
ddl('CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,conversation_id INTEGER,client_session_key VARCHAR(64),title TEXT,status TEXT,language TEXT,metadata_json TEXT,duration_ms INTEGER,started_at TEXT,stopped_at TEXT,last_activity_at TEXT,discarded_at TEXT DEFAULT NULL,UNIQUE(created_by_user_id,client_session_key))');
ddl('CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER,client_segment_key TEXT,segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,confidence REAL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
ddl('CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,analysis_json TEXT)');
$scope=['user'=>user(),'owner_user_id'=>1,'agent_id'=>0,'conversation_id'=>11];
$foreignScope=['user'=>user(2),'owner_user_id'=>2,'agent_id'=>0,'conversation_id'=>21];
$wav='RIFF'.pack('V',32036).'WAVEfmt '.pack('VvvVVvv',16,1,1,16000,32000,2,16).'data'.pack('V',32000).str_repeat("\0",32000);
function vm_call(int $id): array{
 $sid='CA'.str_pad(dechex($id),32,'0',STR_PAD_LEFT);$q=db()->prepare("INSERT INTO phone_voice_calls_v130(id,provider,provider_call_sid,owner_user_id,phone_number_id,agent_id,caller_e164,called_e164,state,created_at) VALUES (?,'twilio',?,1,1,7,'+16025550999','+16025550101','voicemail_offered',?)");$q->execute([$id,$sid,gmdate('Y-m-d H:i:s')]);
 $q=db()->prepare('SELECT * FROM phone_voice_calls_v130 WHERE id=?');$q->execute([$id]);$call=$q->fetch();$call['source_kind']='legacy';$call['public_id']='legacy-'.$id;$call['voicemail_message']=true;return $call;
}
function vm_receipt(array $call,int $id): array{
 $form=['AccountSid'=>setting('phone_twilio_account_sid'),'CallSid'=>$call['provider_call_sid'],'RecordingSid'=>'RE'.str_pad(dechex($id),32,'0',STR_PAD_LEFT),'RecordingStatus'=>'completed','RecordingDuration'=>'3'];
 $url=vp3_phone_voice_sibling_url_v130(vp3_phone_voice_callback_url_v130(),'phone-voice-recording-v130.php');$signature=vp3_phone_sms_twilio_signature_v110($url,$form,'carrier-auth-token-fixture');
 vp3_phone_voice_recording_event_v130(db(),$form,$signature,$url,'carrier-auth-token-fixture',setting('phone_twilio_account_sid'));return vp3_phone_recording_receive_v1(db(),$call,$form);
}
$frame="\xff\xfb\x90\x00".str_repeat("\0",413);$audio=$frame.$frame;$counts=['fetch'=>0,'speech'=>0];
$adapters=['fetch'=>static function($url,$job)use(&$counts,$audio):string{$counts['fetch']++;return $audio;},'speech'=>static function($path,$options,$user)use(&$counts):array{$counts['speech']++;return ['text'=>'Please return my call tomorrow.','words'=>[['text'=>'Please return my call tomorrow.','start'=>0,'end'=>2,'speaker_id'=>'caller']]];}];
try{
 c(vp3_phone_voicemail_ready_v1($pdo),'explicit recording installer includes voicemail schema');
 $defaults=vp3_phone_voicemail_settings_v1($pdo,$scope,1);
 c($defaults['revision']===0&&$defaults['rings']===4&&$defaults['ring_seconds']===24&&!$defaults['transcribe']&&$defaults['announce']&&!$defaults['greeting']['present'],'voicemail defaults are separate from call recording consent');
 bad(fn()=>vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>0,'rings'=>1,'transcribe'=>false,'announce'=>true]),'one exact ring cannot be requested');
 bad(fn()=>vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>0,'rings'=>11,'transcribe'=>false,'announce'=>true]),'ring timeout is bounded');
 bad(fn()=>vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>0,'rings'=>4,'transcribe'=>'false','announce'=>true]),'voicemail preferences require booleans');
 bad(fn()=>vp3_phone_voicemail_settings_v1($pdo,$foreignScope,1),'another owner cannot read line settings');
 bad(fn()=>api('POST',['action'=>'save_voicemail_settings','csrf_token'=>'wrong','number_id'=>1,'expected_revision'=>0,'rings'=>6,'transcribe'=>false,'announce'=>true]),'mutations keep workspace CSRF gate');
 $saved=action('save_voicemail_settings',['number_id'=>1,'expected_revision'=>0,'rings'=>6,'transcribe'=>false,'announce'=>true])['voicemail_settings'];
 c($saved['revision']===1&&$saved['ring_seconds']===36,'owner saves approximate rings with explicit seconds');
 bad(fn()=>vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>0,'rings'=>4,'transcribe'=>false,'announce'=>true]),'stale settings cannot overwrite current preferences');
 c(vp3_phone_voicemail_wav_v1($wav)===1,'canonical mono 16k PCM greeting accepted');
 foreach([substr($wav,0,100),str_replace('WAVE','FAKE',$wav),$wav.'extra',substr_replace($wav,pack('V',48000),24,4)] as $invalid)bad(fn()=>vp3_phone_voicemail_wav_v1($invalid),'invalid or noncanonical greeting rejected');
 $saved=vp3_phone_voicemail_greeting_save_v1($pdo,$scope,1,1,$wav)['voicemail_settings'];
 c($saved['revision']===2&&$saved['greeting']['present']&&$saved['greeting']['duration_seconds']===1,'recorded greeting has its own saved revision and preview');
 c((int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===0,'saving greeting never creates a message or paid recording job');
 c(vp3_phone_voicemail_greeting_audio_v1($pdo,user(),['number_id'=>1,'revision'=>2])===$wav,'authenticated owner preview reads exactly the saved greeting');
 bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,user(2),['number_id'=>1,'revision'=>2]),'greeting preview rejects foreign owner');
 bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,user(),['number_id'=>1,'revision'=>1]),'preview cannot reuse stale greeting revision');
 $greetingCall=vm_call(101);$xml=vp3_phone_voicemail_twiml_v1($pdo,$greetingCall,$greetingCall['provider_call_sid'],'First Agent',vp3_phone_voice_callback_url_v130());preg_match('/token=([a-f0-9]{64})/',$xml,$match);$token=$match[1]??'';
 c(str_contains($xml,'<Play>')&&str_contains($xml,'<Record ')&&str_contains($xml,'recorded')&&strlen($token)===64,'incoming voicemail plays private greeting and retains recording disclosure');
 c(vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token])===$wav,'carrier greeting grant is bound to a live original incoming call');
 $GLOBALS['fixture_participant_scope']=['participant_id'=>99,'owner_user_id'=>1];
 c(vp3_phone_voicemail_settings_v1($pdo,$scope,1)['reason_code']==='private_owner_required','participant context hides private owner voicemail settings');
 denies(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,user(),['number_id'=>1,'revision'=>2]),403,'participant callback with owner user cannot preview private greeting');
 denies(fn()=>vp3_phone_voicemail_greeting_save_v1($pdo,$scope,1,2,$wav),403,'participant cannot replace owner greeting');denies(fn()=>vp3_phone_voicemail_greeting_clear_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>2]),403,'participant cannot clear owner greeting');
 denies(fn()=>vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>2,'rings'=>4,'transcribe'=>true,'announce'=>true]),403,'participant cannot change voicemail consent');
 foreach(['transcribe','delete','post'] as $operation){$fn='vp3_phone_voicemail_'.$operation.'_v1';denies(fn()=>$fn($pdo,$scope,'job-1'),403,'participant cannot '.$operation.' owner voicemail');}
 c(vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token])===$wav,'current call greeting token remains independent of owner UI participant context');unset($GLOBALS['fixture_participant_scope']);
 $pdo->exec('UPDATE phone_voicemail_greeting_tokens_v1 SET expires_epoch=1');bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token]),'expired greeting grant rejected');
 $pdo->exec('UPDATE phone_voicemail_greeting_tokens_v1 SET expires_epoch='.(time()+600));$pdo->exec("UPDATE phone_voice_calls_v130 SET state='completed' WHERE id=101");bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token]),'greeting grant cannot outlive incoming call phase');$pdo->exec("UPDATE phone_voice_calls_v130 SET state='voicemail_offered' WHERE id=101");
 $pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token]),'line reassignment invalidates old carrier greeting grant');
 c(!vp3_phone_voicemail_settings_v1($pdo,$foreignScope,1)['greeting']['present'],'new assignee never inherits old private greeting');$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
 $saved=vp3_phone_voicemail_greeting_clear_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>2])['voicemail_settings'];c(!$saved['greeting']['present']&&$saved['revision']===3,'use standard greeting clears audio and advances revision');bad(fn()=>vp3_phone_voicemail_greeting_audio_v1($pdo,null,['token'=>$token]),'clearing greeting revokes outstanding grants');
 vp3_phone_browser_config_save_v1(['api_key_sid'=>'SK'.str_repeat('b',32),'api_key_secret'=>'api-secret-fixture-123456','twiml_app_sid'=>'AP'.str_repeat('c',32)]);
 $pdo->exec('INSERT INTO phone_browser_numbers_v1(phone_number_id,owner_user_id,agent_id,browser_enabled,recording_enabled) VALUES (1,1,7,1,0)');
 $pdo->prepare('INSERT INTO phone_browser_presence_v1(owner_user_id,conversation_id,chat_agent_id,last_epoch) VALUES (1,12,7,?)')->execute([time()]);
 $ringCall=vm_call(102);$incoming=vp3_phone_browser_incoming_v1($pdo,$ringCall,$ringCall['provider_call_sid']);
 c(is_string($incoming)&&str_contains($incoming,'timeout="31"')&&str_contains($incoming,'<Client '),'incoming browser Dial uses approximate 36-second ringing including carrier buffer');
 c(str_contains(vp3_phone_browser_dial_attrs_v1(['public_id'=>str_repeat('d',32)],false),'timeout="20"'),'outbound and managed dialing keep existing default timeout');
 $pdo->exec("UPDATE phone_browser_calls_v1 SET state='completed'");
 $saved=vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>3,'rings'=>6,'transcribe'=>true,'announce'=>true])['voicemail_settings'];
 $call=vm_call(201);vp3_phone_voicemail_twiml_v1($pdo,$call,$call['provider_call_sid'],'First Agent',vp3_phone_voice_callback_url_v130());
 vp3_phone_workspace_delivery_v1($pdo,$call+['conversation_id'=>12,'chat_agent_id'=>7],'call','call:legacy-201','Incoming fixture caller.');
 $receipt=vm_receipt($call,201);$id=vp3_phone_recording_job_id_v1($receipt['recording']['id']);$job=vp3_phone_recording_row_v1($pdo,$id);$policy=json_decode($job['policy_json'],true);
 c($receipt['queued']&&$policy['purpose']==='voicemail'&&$policy['transcribe']&&!$policy['analyze']&&$counts===['fetch'=>0,'speech'=>0],'signed voicemail callback snapshots consent without paid work');
 c((int)$job['conversation_id']===12,'voicemail announcement keeps original incoming Agent conversation');
 $messages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();vm_receipt($call,201);c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$messages,'duplicate callback does not duplicate announcement');
 $result=vp3_phone_recording_worker_once_v1($pdo,$adapters);if(!$result['ok'])throw new RuntimeException(json_encode($result));$job=vp3_phone_recording_row_v1($pdo,$id);
 c($counts===['fetch'=>1,'speech'=>1]&&(int)$job['session_id']>0&&(int)$job['analysis_dispatched']===0,'existing worker transcribes voicemail without call analysis');
 $message=$pdo->query('SELECT * FROM chat_messages WHERE id='.(int)$job['report_message_id'])->fetch();$marker=json_decode($message['context_json'],true)['phone_v1'];
 c((int)$message['conversation_id']===12&&$marker['call_id']==='legacy-201'&&$marker['voicemail_id']==='job-'.$id&&str_contains($message['message'],'+16025550999')&&str_contains($message['message'],'Please return my call tomorrow.'),'Chat report preserves original caller call Agent and canonical transcript');
 $media=vp3_phone_recording_audio_any_v1($pdo,user(),'job-'.$id);c(is_file($media['path']),'voicemail keeps private canonical playback');
 bad(fn()=>vp3_phone_voicemail_post_v1($pdo,$foreignScope,'job-'.$id),'foreign owner cannot post voicemail');bad(fn()=>vp3_phone_voicemail_delete_v1($pdo,array_replace($scope,['agent_id'=>9]),'job-'.$id),'another scoped Agent cannot delete voicemail');
 $saved=vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>4,'rings'=>4,'transcribe'=>false,'announce'=>false])['voicemail_settings'];
 $silentCall=vm_call(202);vp3_phone_voicemail_twiml_v1($pdo,$silentCall,$silentCall['provider_call_sid'],'First Agent',vp3_phone_voice_callback_url_v130());$messages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();$silent=vm_receipt($silentCall,202);$silentId=vp3_phone_recording_job_id_v1($silent['recording']['id']);$before=$counts;
 c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$messages,'owner can disable automatic voicemail Chat posts');
 c(vp3_phone_recording_worker_once_v1($pdo,$adapters)['ok']&&$counts===$before,'default-off voicemail processing makes no provider request');
 c(vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$silentId)['queued'],'manual Transcribe authorizes only this existing message');
 c(vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$silentId)['queued'],'identical queued manual request is idempotent');
 c(vp3_phone_recording_worker_once_v1($pdo,$adapters)['ok']&&$counts['speech']===$before['speech']+1,'manual action uses the existing bounded worker');
 c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$messages,'manual transcription honors automatic Chat opt-out');
 $posted=vp3_phone_voicemail_post_v1($pdo,$scope,'job-'.$silentId);$again=vp3_phone_voicemail_post_v1($pdo,$scope,'job-'.$silentId);
 c($posted['posted']&&$posted['conversation_id']===11&&$posted['message_id']===$again['message_id'],'explicit Post to Chat is scoped and deduplicated');
 c(str_contains((string)$pdo->query('SELECT message FROM chat_messages WHERE id='.$posted['message_id'])->fetchColumn(),'Please return my call tomorrow.'),'explicit post contains saved transcript');
 $before=$counts;c(vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$silentId)['already_transcribed']&&$counts===$before,'saved transcript prevents another paid request');
 $saved=vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>5,'rings'=>4,'transcribe'=>false,'announce'=>true])['voicemail_settings'];
 // Simulate a pre-feature voicemail retained under the old call-recording policy.
 $oldPolicy=vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>false,'analyze'=>false]);
 $oldCall=vm_call(203);$old=vm_receipt($oldCall,203);$oldId=vp3_phone_recording_job_id_v1($old['recording']['id']);$pdo->prepare('UPDATE phone_recording_jobs_v1 SET policy_json=? WHERE id=?')->execute([json_encode($oldPolicy),$oldId]);
 c(vp3_phone_recording_worker_once_v1($pdo,$adapters)['ok'],'historical audio-only voicemail imports under its saved original policy');$oldJob=vp3_phone_recording_row_v1($pdo,$oldId);$oldSession=(int)$oldJob['session_id'];$oldMedia=vp3_phone_recording_audio_any_v1($pdo,user(),'job-'.$oldId);$oldAudioHash=hash_file('sha256',$oldMedia['path']);
 $oldReport=vp3_phone_recording_deliver_v1($pdo,$oldJob,vp3_phone_recording_call_v1($pdo,$oldJob),[],false);$beforeMessages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
 c(vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$oldId)['queued'],'existing audio-only saved message can be transcribed');
 c(vp3_phone_recording_worker_once_v1($pdo,$adapters)['ok'],'audio-only session upgrades through normal transcription worker');$updated=vp3_phone_recording_row_v1($pdo,$oldId);
 c((int)$updated['session_id']===$oldSession&&hash_file('sha256',$oldMedia['path'])===$oldAudioHash&&(int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id='.$oldSession)->fetchColumn()===1,'manual transcript reuses exact owned audio-only session without replacing its retained audio');
 $newMessage=(string)$pdo->query('SELECT message FROM chat_messages WHERE id='.(int)$updated['report_message_id'])->fetchColumn();
 c((int)$updated['report_message_id']!==$oldReport['message_id']&&str_contains($newMessage,'Please return my call tomorrow.')&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$beforeMessages+1,'new transcript receives one updated Chat report after historical audio-only report');
 $repeat=vp3_phone_recording_deliver_v1($pdo,$updated,vp3_phone_recording_call_v1($pdo,$updated),[],true);c($repeat['message_id']===(int)$updated['report_message_id']&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$beforeMessages+1,'transcript report replay uses stable source-hash deduplication');
 $saved=vp3_phone_voicemail_save_settings_v1($pdo,$scope,['number_id'=>1,'expected_revision'=>6,'rings'=>4,'transcribe'=>true,'announce'=>true])['voicemail_settings'];
 $lateCall=vm_call(204);vp3_phone_voicemail_twiml_v1($pdo,$lateCall,$lateCall['provider_call_sid'],'First Agent',vp3_phone_voice_callback_url_v130());$late=vm_receipt($lateCall,204);$lateId=vp3_phone_recording_job_id_v1($late['recording']['id']);
 $mutating=$adapters;$mutating['speech']=static function(...$args)use($adapters,$pdo,$scope,$lateId):array{$result=$adapters['speech'](...$args);vp3_phone_voicemail_delete_v1($pdo,$scope,'job-'.$lateId);return $result;};
 $result=vp3_phone_recording_worker_once_v1($pdo,$mutating);$lateJob=vp3_phone_recording_row_v1($pdo,$lateId);
 c(!$result['ok']&&$lateJob['state']==='deleted'&&(int)$lateJob['session_id']===0&&!is_file(vp3_phone_recording_source_path_v1($lateJob)),'delete while speech is in flight fences import and removes private source');
 $messages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();vp3_phone_recording_announce_v1($pdo,$lateJob,$lateCall);$duplicate=vm_receipt($lateCall,204);
 c(!empty($duplicate['deleted'])&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$messages&&vp3_phone_recording_row_v1($pdo,$lateId)['state']==='deleted','late announcement and duplicate signed callbacks cannot resurrect deleted voicemail');
 $fetchCall=vm_call(205);vp3_phone_voicemail_twiml_v1($pdo,$fetchCall,$fetchCall['provider_call_sid'],'First Agent',vp3_phone_voice_callback_url_v130());$fetchReceipt=vm_receipt($fetchCall,205);$fetchId=vp3_phone_recording_job_id_v1($fetchReceipt['recording']['id']);$fetching=$adapters;
 $fetching['fetch']=static function(...$args)use($adapters,$pdo,$scope,$fetchId):string{$bytes=$adapters['fetch'](...$args);vp3_phone_voicemail_delete_v1($pdo,$scope,'job-'.$fetchId);return $bytes;};$before=$counts;
 $result=vp3_phone_recording_worker_once_v1($pdo,$fetching);$fetchJob=vp3_phone_recording_row_v1($pdo,$fetchId);
 c(!$result['ok']&&$fetchJob['state']==='deleted'&&!is_file(vp3_phone_recording_source_path_v1($fetchJob))&&$counts['speech']===$before['speech'],'delete during provider download fences local source creation and paid dispatch');
 $agentScope=array_replace($scope,['conversation_id'=>12,'agent_id'=>7]);$deleted=vp3_phone_voicemail_delete_v1($pdo,$agentScope,'job-'.$id);
 c($deleted['deleted']&&count($deleted['redacted_chat_message_ids'])===2&&!is_file($media['path']),'owner deletion removes retained audio and returns current Agent Chat redactions');
 c((int)$pdo->query('SELECT COUNT(*) FROM artist_transcript_segments_v172 WHERE session_id='.(int)$job['session_id'])->fetchColumn()===0,'deletion clears canonical transcript text');
 foreach($deleted['redacted_chat_message_ids'] as $mid){$row=$pdo->query('SELECT * FROM chat_messages WHERE id='.$mid)->fetch();c($row['message']==='Voice message deleted.'&&!str_contains($row['context_json'],'recording_refs')&&!str_contains($row['context_json'],'Please return'),'deleted posts keep no saved media or transcript context');}
 bad(fn()=>vp3_phone_recording_audio_any_v1($pdo,user(),'job-'.$id),'deleted job media route refuses replay');bad(fn()=>vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$id),'deleted message cannot be transcribed');bad(fn()=>vp3_phone_voicemail_post_v1($pdo,$scope,'job-'.$id),'deleted message cannot be posted');
 $legacyId=(int)$pdo->query("SELECT id FROM phone_voice_recordings_v130 WHERE provider_recording_sid='".$job['provider_recording_sid']."'")->fetchColumn();
 c(vp3_phone_voicemail_delete_v1($pdo,$agentScope,'legacy-'.$legacyId)['redacted_chat_message_ids']===$deleted['redacted_chat_message_ids'],'idempotent legacy alias deletion returns the same scoped Chat redactions');
 c(!in_array('job-'.$id,array_column(vp3_phone_recording_workspace_v1($pdo,user()),'id'),true),'deleted message disappears from Phone projection');
 $beforeJobs=(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn();c(!empty(vm_receipt($call,201)['deleted'])&&(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_jobs_v1')->fetchColumn()===$beforeJobs,'tombstone keeps provider recording idempotency durable after delete');
 // Recreate the exact private files to model a crash after tombstone commit but before unlink.
 $source=vp3_phone_recording_source_path_v1($job);if(!is_dir(dirname($source)))mkdir(dirname($source),0700,true);file_put_contents($source,$audio);file_put_contents($media['path'],$audio);
 $purge=static function($endpoint,$deleted):int{c($endpoint==='https://api.twilio.com/2010-04-01/Accounts/'.setting('phone_twilio_account_sid').'/Recordings/'.$deleted['provider_recording_sid'].'.json','provider deletion uses immutable account and recording path');return 204;};
 for($attempt=0;$attempt<5;$attempt++)vp3_phone_voicemail_purge_once_v1($pdo,$purge);
 c(!is_file($source)&&!is_file($media['path'])&&$pdo->query("SELECT purge_state FROM phone_voicemail_deletions_v1 WHERE provider_recording_sid='".$job['provider_recording_sid']."'")->fetchColumn()==='complete','existing worker retries local crash residue before completing provider cleanup');
 $oldDeleted=vp3_phone_voicemail_delete_v1($pdo,$scope,'job-'.$oldId);c(count($oldDeleted['redacted_chat_message_ids'])===3,'deletion covers initial historical and versioned transcript reports');
 $failedPurge=vp3_phone_voicemail_purge_once_v1($pdo,static fn()=>503);c(!$failedPurge['ok'],'unconfirmed provider delete remains pending');$pdo->exec("UPDATE phone_voicemail_deletions_v1 SET next_attempt_epoch=0 WHERE purge_state='pending'");c(vp3_phone_voicemail_purge_once_v1($pdo,static fn()=>404)['ok'],'provider already absent response completes idempotent cleanup');
 // The conditional UPDATE must notice a worker claiming the job after the read.
 $raceCall=vm_call(206);$race=vm_receipt($raceCall,206);$raceId=vp3_phone_recording_job_id_v1($race['recording']['id']);
 if(!$mysql){
  $pdo->exec("CREATE TRIGGER vm_claim_before_manual BEFORE UPDATE OF policy_json ON phone_recording_jobs_v1 WHEN OLD.id=$raceId BEGIN UPDATE phone_recording_jobs_v1 SET state='transcribing',lease_token='fixture-worker',lease_until=9999999999 WHERE id=OLD.id; SELECT RAISE(IGNORE); END");
  bad(fn()=>vp3_phone_voicemail_transcribe_v1($pdo,$scope,'job-'.$raceId),'concurrent worker claim cannot be reported as a queued manual request');$pdo->exec('DROP TRIGGER vm_claim_before_manual');
 }
 $lease=vp3_phone_recording_claim_v1($pdo);$checkpoint=vp3_phone_recording_checkpoint_v1($pdo,$lease,['stage'=>$lease['stage'],'audio_sha256'=>$lease['audio_sha256']]);
 c($checkpoint['lease_token']===$lease['lease_token']&&$checkpoint['state']==='transcribing','identical persisted checkpoint keeps its current live worker lease on native MySQL');
 $pdo->prepare("UPDATE phone_recording_jobs_v1 SET lease_token='replacement-worker' WHERE id=?")->execute([$raceId]);bad(fn()=>vp3_phone_recording_checkpoint_v1($pdo,$lease,['stage'=>$lease['stage']]),'unchanged values cannot excuse a replaced worker lease');
 $pdo->prepare('UPDATE phone_recording_jobs_v1 SET lease_token=?,lease_until=1 WHERE id=?')->execute([$lease['lease_token'],$raceId]);bad(fn()=>vp3_phone_recording_checkpoint_v1($pdo,$lease,['stage'=>$lease['stage']]),'unchanged values cannot excuse an expired worker lease');
 $pdo->exec("UPDATE phone_recording_jobs_v1 SET state='failed' WHERE id=$raceId");
 $beforePrefs=(int)$pdo->query('SELECT COUNT(*) FROM phone_recording_policies_v1')->fetchColumn();c($beforePrefs===1,'voicemail controls never create redundant call recording policies');
 $empty=new PDO('sqlite::memory:');$empty->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);c(!vp3_phone_voicemail_deleted_v1($empty,'RE'.str_repeat('a',32)),'genuine pre-upgrade database supports existing non-deleted recordings');$empty->exec('CREATE TABLE phone_voicemail_settings_v1(phone_number_id INTEGER)');bad(fn()=>vp3_phone_voicemail_deleted_v1($empty,'RE'.str_repeat('a',32)),'installed voicemail missing tombstone storage fails closed');$empty->exec('CREATE TABLE phone_voicemail_deletions_v1(wrong_column INTEGER)');bad(fn()=>vp3_phone_voicemail_deleted_v1($empty,'RE'.str_repeat('a',32)),'unexpected tombstone query errors cannot authorize deleted media');
 $pdo->exec($mysql?'RENAME TABLE phone_voicemail_deletions_v1 TO fixture_vm_deletions':'ALTER TABLE phone_voicemail_deletions_v1 RENAME TO fixture_vm_deletions');
 $partial=api('GET',['action'=>'state','conversation_id'=>11,'agent_id'=>0])['state'];
 c(!$partial['recording_readiness']['ready']&&$partial['voicemails']===[]&&count($partial['numbers'])>0&&count($partial['calls'])>0&&count($partial['contacts'])>0,'partial voicemail migration keeps Connections Contacts and Recent calls loading with an actionable recordings upgrade');
 $pdo->exec($mysql?'RENAME TABLE fixture_vm_deletions TO phone_voicemail_deletions_v1':'ALTER TABLE fixture_vm_deletions RENAME TO phone_voicemail_deletions_v1');
 echo 'PHONE_VOICEMAIL_V1=PASS ('.$checks.' checks)'.PHP_EOL;
}finally{
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($directory);
}
