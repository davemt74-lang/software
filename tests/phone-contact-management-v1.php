<?php
declare(strict_types=1);
// Contact/Calendar integration against production helpers; no provider or external requests.
define('STONEFELLOW_ROOT',dirname(__DIR__));
require dirname(__DIR__).'/includes/phone-browser-v1.php';
require dirname(__DIR__).'/includes/phone-browser-endpoint-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-specialist-v1.php';
require dirname(__DIR__).'/includes/phone-recording-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-history-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-followups-v1.php';
require_once dirname(__DIR__).'/includes/phone-contact-management-v1.php';
require_once dirname(__DIR__).'/includes/phone-contact-schedules-v1.php';
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
if($mysql)foreach(['phone_native_dialthrough_v1','phone_managed_recording_consent_v1','phone_managed_callback_controls_v1','phone_managed_callback_dispatch_v1','phone_managed_callback_requests_v1','vp3_paired_phone_threads_v1','vp3_phone_communication_profiles_v1','vp3_phone_devices_v1','phone_operator_owner_preferences_v1','phone_operator_live_sessions_v1','user_calendar_events','agent_scheduling_bookings','phone_operator_contact_policies_v1','artist_transcript_segments_v172','artist_transcript_sessions_v172','phone_recording_legacy_reads_v1','phone_recording_jobs_v1','phone_recording_policies_v1','phone_browser_deliveries_v1','phone_browser_presence_v1','phone_browser_numbers_v1','phone_browser_favorites_v1','phone_browser_preferences_v1','phone_browser_calls_v1','phone_voice_recordings_v130','phone_voice_calls_v130','crm_contact_phones','crm_contacts','chat_messages','chat_conversations','phone_sms_assignment_migrations_v111','phone_sms_threads_v110','phone_cloud_numbers_v100','user_agents','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
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
function denies(callable $fn,int $status,string $label): void{try{$fn();throw new LogicException('Missing denial: '.$label);}catch(VP3PhoneWorkspaceException $e){c($e->httpStatus===$status,$label.' ('.$e->publicCode.')');}}
function user(int $id=1): array{$q=db()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$id]);return $q->fetch();}
function api(string $method,array $input=[],int $owner=1,string $csrf='csrf-fixture'): array{return vp3_phone_workspace_request_v1(db(),user($owner),$method,$input,$csrf);}
function action(string $name,array $input=[],int $owner=1): array{return api('POST',array_replace(['action'=>$name,'csrf_token'=>'csrf-fixture','conversation_id'=>$owner===1?11:21,'agent_id'=>0],$input),$owner);}
function detail(int $id,int $owner=1,array $scope=[]): array{return api('GET',['action'=>'phone_contact_detail','contact_id'=>$id]+$scope,$owner);}
function person(int $id): array{$q=db()->prepare('SELECT * FROM crm_contacts WHERE id=?');$q->execute([$id]);return $q->fetch();}
function schedule_input(int $contact,array $changes=[]): array{return array_replace(['contact_id'=>$contact,'contact_revision'=>detail($contact)['contact']['revision'],'number_id'=>1,'event_id'=>0,'expected_revision'=>'','mutation_id'=>'fixture-schedule-request-01','date'=>gmdate('Y-m-d',time()+2*86400),'time'=>'09:30','timezone'=>'UTC','recurrence'=>'daily','duration_minutes'=>3,'objectives'=>"Discuss progress\nAgree next steps.",'confirmed'=>true],$changes);}
function schedule_save(array $input): array{return action('save_phone_contact_schedule',$input);}
function vp3_participant_scope_v1(): ?array{return $GLOBALS['participant_scope']??null;}
function event(int $id): array{return user_calendar_event_v1300(db(),1,$id);}
function calendar_fixture(): void{ddl("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER NOT NULL,created_by_user_id INTEGER,created_by_agent_id INTEGER,title VARCHAR(190) NOT NULL,description TEXT,location VARCHAR(500) NOT NULL DEFAULT '',start_at_utc DATETIME NOT NULL,end_at_utc DATETIME NOT NULL,timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',all_day INTEGER NOT NULL DEFAULT 0,recurrence VARCHAR(16) NOT NULL DEFAULT 'none',agent_notifications INTEGER NOT NULL DEFAULT 0,source VARCHAR(32) NOT NULL DEFAULT 'user',source_reference VARCHAR(190) NOT NULL DEFAULT '',status VARCHAR(24) NOT NULL DEFAULT 'active',cancelled_at DATETIME,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");}
if(!$mysql)$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));
vp3_phone_workspace_ensure_schema_v1($pdo);
$pdo->exec("UPDATE crm_contacts SET lifecycle_stage='customer' WHERE id=1");
$before=detail(1);
c(!$before['editable']&&$before['contact']['relationship']===''&&$before['contact']['notes']===''&&!$before['schedules']['available'],'pre-upgrade contact reads remain usable without treating sales lifecycle as relationship');
c(!column_exists('crm_contacts','notes')&&!column_exists('crm_contacts','relationship'),'reading contact details never installs columns');
$new=['contact_id'=>0,'expected_revision'=>'','name'=>'Ada <script>','phone'=>'+1 (602) 555-0117','relationship'=>'Friend','notes'=>"Owner-only note\nFollow up about her project."];
denies(fn()=>action('save_phone_contact',$new),503,'new editor requires explicit CRM upgrade');
vp3_phone_caller_contacts_ensure_schema_v1($pdo);
c(vp3_phone_contact_details_schema_ready_v1($pdo),'explicit upgrade installs canonical relationship and private notes');
c(person(1)['lifecycle_stage']==='customer'&&person(1)['relationship']===''&&person(1)['email_normalized']==='mine@example.test','schema upgrade preserves pipeline and email identity');
denies(fn()=>api('GET',['action'=>'save_phone_contact']+$new),405,'contact mutations reject GET');
denies(fn()=>action('phone_contact_detail',['contact_id'=>1]),405,'contact detail stays a read operation');
denies(fn()=>api('POST',['action'=>'save_phone_contact','csrf_token'=>'forged']+$new),403,'contact save requires CSRF');
denies(fn()=>action('save_phone_contact',$new+['owner_user_id'=>2]),422,'contact payload cannot select an owner');
foreach([['email'=>'invalid'],['email'=>"a@example.test\r\nBcc:b@example.test"],['phone'=>'6025550117'],['phone'=>'javascript:call()'],['name'=>"No\nline"],['relationship'=>str_repeat('x',81)],['notes'=>str_repeat('x',2001)],['notes'=>[1]],['phone'=>'+1'] ] as $bad)
    denies(fn()=>action('save_phone_contact',array_replace($new,$bad)),422,'invalid contact fields are rejected');
$saved=action('save_phone_contact',$new);$id=$saved['contact']['id'];
c($saved['created']===true&&$saved['contact']['phone_e164']==='+16025550117'&&$saved['contact']['relationship']==='Friend'&&$saved['contact']['notes']===$new['notes'],'save creates a personal relationship and owner-private notes with canonical phone');
c(person($id)['email_normalized']===null&&person($id)['lifecycle_stage']==='','phone-only contact has no invented email or pipeline status');
$again=action('save_phone_contact',$new);
c($again['contact']['id']===$id&&$again['created']===false,'identical create retry does not duplicate the contact');
denies(fn()=>action('save_phone_contact',array_replace($new,['name'=>'Different name'])),409,'a distinct contact cannot silently take an existing number');
$foreign=action('save_phone_contact',array_replace($new,['notes'=>'Other owner private note']),2);
c($foreign['contact']['id']!==$id&&$foreign['contact']['phone_e164']===$saved['contact']['phone_e164'],'different owners keep independent contacts for the same phone');
denies(fn()=>detail($foreign['contact']['id']),404,'wrong-owner private detail is inaccessible');
denies(fn()=>detail(3),404,'archived contact is inaccessible');
denies(fn()=>action('save_phone_contact',array_replace($new,['contact_id'=>$foreign['contact']['id'],'expected_revision'=>$foreign['contact']['revision']])),404,'wrong-owner contact edit is inaccessible');
$stale=$saved['contact']['revision'];
$edit=['contact_id'=>$id,'expected_revision'=>$stale,'name'=>'Ada Lovelace','phone'=>'+16025550118','relationship'=>'Colleague','notes'=>"Private <img src=x>\nShared project goals."];
$changed=action('save_phone_contact',$edit);
c($changed['contact']['id']===$id&&$changed['contact']['phone_e164']==='+16025550118'&&$changed['contact']['relationship']==='Colleague','contact edits keep canonical identity');
$channels=$pdo->query('SELECT phone,is_primary FROM crm_contact_phones WHERE contact_id='.$id.' ORDER BY id')->fetchAll();
c(count($channels)===2&&$channels[0]['phone']==='+16025550117'&&(int)$channels[0]['is_primary']===0&&$channels[1]['phone']==='+16025550118'&&(int)$channels[1]['is_primary']===1,'changing primary phone preserves previous alternate channels');
denies(fn()=>action('save_phone_contact',$edit),409,'stale contact revision cannot overwrite a newer contact');
$mine=detail(1)['contact'];
action('save_phone_contact',['contact_id'=>1,'expected_revision'=>$mine['revision'],'name'=>'Mine edited','phone'=>$mine['phone_e164'],'relationship'=>'Family','notes'=>'Private family note']);
c(person(1)['lifecycle_stage']==='customer'&&person(1)['relationship']==='Family'&&person(1)['email_normalized']==='mine@example.test','personal relationship edit preserves existing sales lifecycle and email');
$emailEdit=$edit;
$emailEdit['expected_revision']=detail($id)['contact']['revision'];
denies(fn()=>action('save_phone_contact',$emailEdit+['email'=>'MINE@example.test']),409,'duplicate owner email is rejected');
$result=action('save_phone_contact',$emailEdit+['email'=>'Ada+Phone@example.test']);
c($result['contact']['email']==='Ada+Phone@example.test'&&person($id)['email_normalized']==='ada+phone@example.test','contact stores email and canonical lookup key');
$emailEdit['expected_revision']=$result['contact']['revision'];
action('save_phone_contact',$emailEdit+['email'=>'']);
c(detail($id)['contact']['email']===''&&person($id)['email_normalized']===null,'clearing an email restores phone-only identity');
$state=api('GET')['state'];
c(!str_contains(json_encode($state),'Private family note')&&!str_contains(json_encode($state),'Shared project goals')&&!str_contains(json_encode($state),'Other owner private note'),'Phone list and call-card projection contain no private notes');
c(array_values(array_filter($state['contacts'],fn($row)=>$row['id']===$id))[0]['relationship']==='Colleague','contact list uses the personal relationship column');
$GLOBALS['participant_scope']=['participant_id'=>99];
denies(fn()=>detail($id),403,'participant Chat cannot read owner-private contact notes');
denies(fn()=>action('save_phone_contact',array_replace($new,['phone'=>'+16025550812'])),403,'participant Chat cannot create owner-private contacts');
unset($GLOBALS['participant_scope']);
c(!table_exists('user_calendar_events')&&!table_exists('phone_operator_contact_policies_v1')&&(int)$pdo->query('SELECT count(*) FROM phone_browser_calls_v1')->fetchColumn()===0,'editing contacts neither installs a scheduler nor grants Auto Call nor places a call');
denies(fn()=>schedule_save(schedule_input($id)),503,'schedule save requires explicit Calendar upgrade');
calendar_fixture();
c(detail($id)['schedules']===['available'=>true,'execution_enabled'=>false,'items'=>[],'limited'=>false],'empty Calendar projection is explicitly reminder-only');
$pdo->exec('ALTER TABLE user_calendar_events DROP COLUMN cancelled_at');
c(detail($id)['editable']&&!detail($id)['schedules']['available'],'partial optional Calendar upgrade cannot break the contact editor');
denies(fn()=>schedule_save(schedule_input($id)),503,'partial Calendar storage rejects scheduling before a mutation');
$pdo->exec('ALTER TABLE user_calendar_events ADD COLUMN cancelled_at DATETIME NULL');
denies(fn()=>api('GET',['action'=>'save_phone_contact_schedule']+schedule_input($id)),405,'schedule save rejects GET');
denies(fn()=>schedule_save(schedule_input($id,['confirmed'=>false])),422,'schedule requires explicit review and save');
denies(fn()=>schedule_save(schedule_input($id,['contact_revision'=>$stale])),409,'schedule rejects stale contact phone binding');
denies(fn()=>schedule_save(schedule_input($id,['number_id'=>2])),404,'schedule cannot use a foreign owner line');
denies(fn()=>action('save_phone_contact_schedule',schedule_input($id,['contact_id'=>$foreign['contact']['id'],'contact_revision'=>$foreign['contact']['revision']])),404,'schedule cannot reference a foreign owner contact');
foreach([['time'=>'25:00'],['date'=>'2027-02-30'],['timezone'=>'Mars/Olympus'],['recurrence'=>'hourly'],['duration_minutes'=>61],['duration_minutes'=>'10'],['objectives'=>str_repeat('x',2001)],['mutation_id'=>'short']] as $bad)
    denies(fn()=>schedule_save(schedule_input($id,$bad)),422,'invalid schedule fields are rejected');
$dstDate=(new DateTimeImmutable('second Sunday of March '.(gmdate('Y')+1),new DateTimeZone('UTC')))->format('Y-m-d');
denies(fn()=>schedule_save(schedule_input($id,['date'=>$dstDate,'time'=>'02:30','timezone'=>'America/New_York'])),422,'nonexistent DST wall-clock time is rejected clearly');
$foldDate=(new DateTimeImmutable('first Sunday of November '.(gmdate('Y')+1),new DateTimeZone('UTC')))->format('Y-m-d');
denies(fn()=>schedule_save(schedule_input($id,['date'=>$foldDate,'time'=>'01:30','timezone'=>'America/New_York','duration_minutes'=>60])),422,'fold-spanning interval is rejected before creating an uneditable Calendar event');
$request=schedule_input($id);$created=schedule_save($request);$schedule=$created['schedule'];$eventId=$schedule['event_id'];
c($created['created']&&$created['execution_enabled']===false&&$schedule['recurrence']==='daily'&&$schedule['duration_minutes']===3&&$schedule['objectives']===$request['objectives'],'daily schedule stores reviewed duration and ongoing objectives without execution authority');
$event=event($eventId);
c($event['source']==='user'&&str_starts_with($event['source_reference'],'operator-call-v1:'.$id.':7:')&&$event['recurrence']==='daily','schedule reuses one canonical Calendar source with contact and Agent binding');
$retry=schedule_save($request);
c(!$retry['created']&&$retry['schedule']['event_id']===$eventId&&(int)$pdo->query('SELECT count(*) FROM user_calendar_events')->fetchColumn()===1,'duplicate schedule submit replays one Calendar event');
denies(fn()=>schedule_save(array_replace($request,['objectives'=>'Changed replay'])),409,'same idempotency request cannot change details');
denies(fn()=>schedule_save(array_replace($request,['mutation_id'=>'fixture-schedule-request-02'])),409,'duplicate times with a different request are caught as Calendar conflicts');
$projection=user_calendar_events_v1300($pdo,user(),$event['start_at_utc'],gmdate('Y-m-d H:i:s',strtotime($event['start_at_utc'].' UTC')+3*86400),false);
c(count($projection)===3&&count(array_unique(array_column($projection,'occurrence_id')))===3,'native Calendar displays distinct daily occurrences from one event');
$upcoming=vp3_operator_calendar_upcoming_v1($pdo,user(),7,12,time());
c($upcoming['execution_enabled']===false&&count($upcoming['appointments'])>=4&&count(array_unique(array_column($upcoming['appointments'],'occurrence_id')))===count($upcoming['appointments']),'Operator upcoming projects daily series with distinct occurrence identities and no executor');
c(vp3_operator_calendar_upcoming_v1($pdo,user(),9,13,time())['appointments']===[],'upcoming calls keep Agent scope');
$wrongScope=detail($id,1,['agent_id'=>9,'conversation_id'=>13]);
c($wrongScope['schedules']['items']===[],'contact schedules keep Agent scope');
denies(fn()=>action('cancel_phone_contact_schedule',['contact_id'=>$id,'event_id'=>$eventId,'expected_revision'=>$schedule['revision'],'confirmed'=>true,'agent_id'=>9,'conversation_id'=>13]),404,'wrong Agent cannot cancel a schedule');
$otherContact=detail(1)['contact'];
denies(fn()=>action('cancel_phone_contact_schedule',['contact_id'=>1,'event_id'=>$eventId,'expected_revision'=>$schedule['revision'],'confirmed'=>true]),404,'schedule cancellation is bound to the reviewed contact');
denies(fn()=>action('cancel_phone_contact_schedule',['contact_id'=>$foreign['contact']['id'],'event_id'=>$eventId,'expected_revision'=>$schedule['revision'],'confirmed'=>true],2),404,'wrong owner cannot cancel a schedule');
$editRequest=array_replace($request,['event_id'=>$eventId,'expected_revision'=>$schedule['revision'],'time'=>'10:15','recurrence'=>'weekly','objectives'=>'Weekly progress review']);
$edited=schedule_save($editRequest);
c($edited['schedule']['event_id']===$eventId&&!$edited['created']&&event($eventId)['source_reference']===$event['source_reference']&&event($eventId)['recurrence']==='weekly','editing a schedule preserves the canonical series identity and binding');
denies(fn()=>schedule_save($editRequest),409,'schedule stale revision prevents overwrite');
denies(fn()=>action('cancel_phone_contact_schedule',['contact_id'=>$id,'event_id'=>$eventId,'expected_revision'=>$schedule['revision'],'confirmed'=>true]),409,'stale cancellation cannot remove a changed schedule');
// A contact edit between a schedule preview and its save invalidates even a duplicate request.
$contact=detail($id)['contact'];
action('save_phone_contact',['contact_id'=>$id,'expected_revision'=>$contact['revision'],'name'=>$contact['name'],'phone'=>'+16025550119','relationship'=>$contact['relationship'],'notes'=>$contact['notes']]);
denies(fn()=>schedule_save($request),409,'contact phone edits invalidate schedule request replay');
$cancelled=action('cancel_phone_contact_schedule',['contact_id'=>$id,'event_id'=>$eventId,'expected_revision'=>$edited['schedule']['revision'],'confirmed'=>true]);
c($cancelled['cancelled']&&$cancelled['execution_enabled']===false&&event($eventId)['status']==='cancelled'&&detail($id)['schedules']['items']===[],'cancel preserves historical Calendar row and removes all upcoming repeats');
// An old-anchor daily series can be edited in place, and projects future reminders in Operator.
$anchor=gmdate('Y-m-d',time()-14*86400);
$old=user_calendar_create_local_event_v1300($pdo,user(),['title'=>'Call Ada Lovelace','description'=>'Daily objective','date'=>$anchor,'start_time'=>'15:00','duration_minutes'=>10,'timezone'=>'UTC','recurrence'=>'daily','agent_notifications'=>true],'user',null,'operator-call-v1:'.$id.':7:'.str_repeat('d',64));
$oldUpcoming=vp3_operator_calendar_upcoming_v1($pdo,user(),7,12,time());
c(count($oldUpcoming['appointments'])>=7&&count($oldUpcoming['appointments'])<=8&&$oldUpcoming['appointments'][0]['recurrence']==='daily'&&$oldUpcoming['execution_enabled']===false,'old daily anchors remain visible as upcoming reminder occurrences, including an in-progress overlap');
$oldEdit=schedule_save(schedule_input($id,['event_id'=>(int)$old['id'],'expected_revision'=>section12_revision($old),'date'=>$anchor,'time'=>'16:00','objectives'=>'Updated daily objective']));
c($oldEdit['schedule']['event_id']===(int)$old['id']&&$oldEdit['schedule']['time']==='16:00'&&$oldEdit['schedule']['objectives']==='Updated daily objective','daily notes and time edit keep the historical series anchor');
// Canonical Calendar edits may exceed the compact Phone form's range without breaking contact detail.
$expanded=user_calendar_update_event_v1300($pdo,user(),(int)$old['id'],['expected_revision'=>$oldEdit['schedule']['revision'],'title'=>'Longer project conversation','description'=>str_repeat('Long notes ',220),'date'=>$anchor,'start_time'=>'16:00','end_time'=>'18:00','timezone'=>'UTC','recurrence'=>'daily','agent_notifications'=>true]);
$book=detail($id)['schedules'];
c(count($book['items'])===1&&$book['items'][0]['editable']===false&&$book['items'][0]['duration_minutes']===120,'extended Calendar event stays viewable and cancellable without a lossy Phone edit');
// First occurrence is free, but an existing canonical event blocks a later repeat.
user_calendar_create_local_event_v1300($pdo,user(),['title'=>'Existing Calendar event','date'=>gmdate('Y-m-d',time()+4*86400),'start_time'=>'12:00','duration_minutes'=>30,'timezone'=>'UTC'],'user',null,'fixture-conflict');
denies(fn()=>schedule_save(schedule_input($id,['mutation_id'=>'fixture-later-conflict-01','time'=>'12:00'])),409,'conflicts are checked on later repeats within the reviewed horizon');
c(!table_exists('phone_operator_contact_policies_v1')&&(int)$pdo->query('SELECT count(*) FROM phone_browser_calls_v1')->fetchColumn()===0,'manual reminder saves never create Auto Call permission or provider intents');
// Exercise the real private call card and the phone information exchange on SQLite.
if(!$mysql){
    require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
    $pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT 'Owner'");
    $pdo->exec("CREATE TABLE vp3_phone_devices_v1(owner_user_id INTEGER NOT NULL,id CHAR(32) NOT NULL,device_name TEXT,device_status TEXT,expires_at INTEGER,generation INTEGER,PRIMARY KEY(owner_user_id,id))");
    vp3_phone_communication_install_v1($pdo,'');vp3_phone_information_install_v1($pdo,'');
    $device=str_repeat('1',32);$profile=str_repeat('2',32);$callId=str_repeat('3',32);$exchange=str_repeat('4',32);
    $pdo->prepare("INSERT INTO vp3_phone_devices_v1 VALUES(1,?,'Handset','approved',?,1)")->execute([$device,time()+3600]);
    $pdo->prepare("INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,owner_requests,holder_requests,updated_epoch) VALUES(1,?,?,1,1,1,?)")->execute([$device,$profile,time()]);
    $contact=detail($id);
    c($contact['connected_phones'][0]['id']===$device,'contact editor lists only eligible linked devices');
    $bind=['contact_id'=>$id,'expected_revision'=>$contact['contact']['revision'],'device_id'=>$device];
    denies(fn()=>action('assign_contact_phone',array_replace($bind,['device_id'=>str_repeat('f',32)])),409,'an unrelated handset cannot be assigned');
    c(action('assign_contact_phone',$bind)['assigned']&&detail($id)['connected_phone_id']===$device,'verified owner can assign a connected phone');
    vp3_phone_browser_config_save_v1(['api_key_sid'=>'SK'.str_repeat('a',32),'api_key_secret'=>'fixture-secret-for-browser','twiml_app_sid'=>'AP'.str_repeat('b',32)]);
    vp3_phone_browser_number_policy_set_v1($pdo,1,true,false);
    $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,conversation_id,chat_agent_id,state,created_epoch,updated_epoch) VALUES(?,1,7,1,'+16025550101',?,'inbound',11,0,'answered',?,?)")->execute([$callId,detail($id)['contact']['phone_e164'],time(),time()]);
    $share=['call_id'=>$callId,'contact_id'=>$id,'device_id'=>$device,'exchange_id'=>$exchange,'message'=>'https://example.test/map'];
    c(action('share_call_item',$share)['shared']&&action('share_call_item',$share)['shared'],'retry acknowledges the same call share');
    $card=api('GET',['conversation_id'=>11])['state']['companion_cards'][$callId];
    c(count($card['items'])===1&&$card['items'][0]['message']===$share['message'],'one call card item is saved across duplicate requests');
    $d=vp3_phone_row_v1($pdo,1,$device);$p=vp3_phone_communication_row_v1($pdo,$d);
    c(vp3_phone_information_list_v1($pdo,$d,$p,time())['items'][0]['call_companion'],'the holder sees the same item as call companion content');
    $pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET holder_requests=0');
    c(api('GET',['conversation_id'=>11])['state']['companion_cards']===[],'withdrawal removes companion access');
    $pdo->exec("UPDATE phone_browser_calls_v1 SET state='completed'");
    denies(fn()=>action('share_call_item',$share),409,'ended call cannot share new items');
}
echo 'PASS '.$checks.' phone contact management and canonical schedule checks ('.($mysql?'MySQL':'SQLite').")\n";
