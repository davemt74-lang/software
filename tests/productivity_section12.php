<?php
declare(strict_types=1);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $call,string $message):void{try{$call();}catch(Throwable $e){return;}throw new RuntimeException($message);}
class Section12PDO extends PDO{public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);}}
$pdo=new Section12PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->sqliteCreateFunction('UTC_TIMESTAMP',static fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('NOW',static fn()=>gmdate('Y-m-d H:i:s'));
function db():PDO{global $pdo;return $pdo;}
function table_exists(string $table):bool{$s=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return (bool)$s->fetchColumn();}
function column_exists(string $table,string $column):bool{return in_array($column,array_column(db()->query('PRAGMA table_info('.$table.')')->fetchAll(),'name'),true);}
function agent_scheduling_schema_ready_v430(?PDO $pdo=null):bool{return false;}
function homeserver_federated_v240_canonical_id(string $source,string $dataset,string $key):string{return 'fd24_'.substr(hash('sha256',$source.'|'.$dataset.'|'.$key),0,40);}
function homeserver_federated_v240_envelope(string $source,string $dataset,string $key,string $title='',string $content='',?string $at=null):array{return ['authority_source'=>$source,'dataset'=>$dataset,'authority_key'=>$key,'canonical_id'=>homeserver_federated_v240_canonical_id($source,$dataset,$key),'record_revision'=>hash('sha256',json_encode([$title,$content,$at])),'federation_version'=>'2.4'];}
function homeserver_federated_v240_observe(int $userId,array $item,string $observed='vp3_cloud'):array{db()->prepare("INSERT INTO homeserver_federated_records(user_id,canonical_id,authority_source,authority_key,observed_source,tombstoned,last_seen_at) VALUES(?,?,?,?,?,0,UTC_TIMESTAMP()) ON CONFLICT(user_id,canonical_id) DO UPDATE SET tombstoned=0,last_seen_at=UTC_TIMESTAMP()")->execute([$userId,$item['canonical_id'],$item['authority_source'],$item['authority_key'],$observed]);return $item;}
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1),(3,0);CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,created_by_user_id INTEGER,created_by_agent_id INTEGER,title TEXT,description TEXT,location TEXT,start_at_utc TEXT,end_at_utc TEXT,timezone TEXT,all_day INTEGER,source TEXT,source_reference TEXT,status TEXT,cancelled_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY AUTOINCREMENT,public_id TEXT,owner_user_id INTEGER,vp3_user_id INTEGER,name TEXT,email TEXT,email_normalized TEXT,phone TEXT,company TEXT,source TEXT,status TEXT,lifecycle_stage TEXT,marketing_status TEXT,created_at TEXT,updated_at TEXT);CREATE TABLE homeserver_contact_mutations(user_id INTEGER,mutation_id TEXT,action_key TEXT,request_hash TEXT,canonical_id TEXT,result_json TEXT,PRIMARY KEY(user_id,mutation_id));CREATE TABLE homeserver_federated_records(user_id INTEGER,canonical_id TEXT,authority_source TEXT,authority_key TEXT,observed_source TEXT,tombstoned INTEGER,last_seen_at TEXT,PRIMARY KEY(user_id,canonical_id));");
$pdo->exec("CREATE TABLE crm_contact_emails(id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER,email TEXT,email_normalized TEXT,label TEXT,is_primary INTEGER,updated_at TEXT,UNIQUE(contact_id,email_normalized));CREATE TABLE crm_contact_phones(id INTEGER PRIMARY KEY AUTOINCREMENT,contact_id INTEGER,phone TEXT,phone_normalized TEXT,label TEXT,is_primary INTEGER,updated_at TEXT,UNIQUE(contact_id,phone_normalized));");
require dirname(__DIR__).'/includes/user-calendar-v1300.php';require dirname(__DIR__).'/includes/crm-v180.php';require dirname(__DIR__).'/includes/homeserver-contacts-v241.php';
$user=['id'=>1];$input=['title'=>'Session','date'=>'2026-10-05','start_time'=>'08:00','end_time'=>'09:00','timezone'=>'America/Phoenix'];
$event=user_calendar_create_local_event_v1300($pdo,$user,$input,'automation',null,'job:s12');
check($event['start_at_utc']==='2026-10-05 15:00:00','Phoenix offset is wrong');
check(user_calendar_create_local_event_v1300($pdo,$user,$input,'automation',null,'job:s12')['id']===$event['id'],'Automation retry duplicated an event');
$revision=section12_revision($event);$updated=user_calendar_update_event_v1300($pdo,$user,(int)$event['id'],[...$input,'title'=>'Updated','expected_revision'=>$revision]);
rejects(fn()=>user_calendar_update_event_v1300($pdo,$user,(int)$event['id'],[...$input,'expected_revision'=>$revision]),'Stale calendar save succeeded');
rejects(fn()=>user_calendar_cancel_event_v1300($pdo,$user,(int)$event['id'],$revision),'Stale calendar delete succeeded');
rejects(fn()=>user_calendar_update_event_v1300($pdo,['id'=>2],(int)$event['id'],$input),'Foreign calendar mutation succeeded');
check(user_calendar_cancel_event_v1300($pdo,$user,(int)$event['id'],section12_revision($updated)),'Current calendar cancel failed');
check(user_calendar_create_local_event_v1300($pdo,$user,$input,'automation',null,'job:s12')['status']==='cancelled','Retry resurrected a cancelled event');
rejects(fn()=>user_calendar_local_range_v1300([...$input,'date'=>'2026-02-30']),'Invalid local date normalized');
rejects(fn()=>user_calendar_local_range_v1300([...$input,'date'=>'2026-03-08','start_time'=>'02:30','end_time'=>'04:00','timezone'=>'America/New_York']),'Nonexistent DST time normalized');
$day=user_calendar_local_range_v1300(['date'=>'2026-03-08','all_day'=>1,'timezone'=>'America/New_York']);check((strtotime($day['end_at_utc'].' UTC')-strtotime($day['start_at_utc'].' UTC'))===23*3600,'All-day DST duration is wrong');
$contact=homeserver_contacts_v241_cloud_crm_create(1,['mutation_id'=>'s12-create','display_name'=>'Contact','email'=>'contact@example.invalid']);
check(homeserver_contacts_v241_cloud_crm_create(1,['mutation_id'=>'s12-create','display_name'=>'Contact','email'=>'contact@example.invalid'])['contact']['canonical_id']===$contact['contact']['canonical_id'],'CRM retry changed identity');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_contacts')->fetchColumn()===1,'CRM retry duplicated a contact');
$change=['mutation_id'=>'s12-update','expected_revision'=>$contact['contact']['record_revision'],'display_name'=>'Changed','email'=>'changed@example.invalid','phone'=>'+1 555 0101'];
$saved=homeserver_contacts_v241_cloud_crm_update(1,$contact['contact']['canonical_id'],$change);
check($pdo->query('SELECT email FROM crm_contact_emails WHERE is_primary=1')->fetchColumn()==='changed@example.invalid','CRM primary email diverged');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_contact_emails WHERE is_primary=1')->fetchColumn()===1,'Multiple primary CRM emails');
check($pdo->query('SELECT phone_normalized FROM crm_contact_phones WHERE is_primary=1')->fetchColumn()==='15550101','CRM primary phone diverged');
rejects(fn()=>homeserver_contacts_v241_cloud_crm_update(1,$contact['contact']['canonical_id'],[...$change,'mutation_id'=>'s12-stale']),'Stale CRM save succeeded');
rejects(fn()=>homeserver_contacts_v241_cloud_crm_update(2,$contact['contact']['canonical_id'],[...$change,'mutation_id'=>'s12-foreign']),'Foreign CRM save succeeded');
$pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON homeserver_contact_mutations BEGIN SELECT RAISE(ABORT,'receipt failed'); END;");
rejects(fn()=>homeserver_contacts_v241_cloud_crm_update(1,$contact['contact']['canonical_id'],['mutation_id'=>'s12-fail','expected_revision'=>$saved['contact']['record_revision'],'display_name'=>'Partial']),'Receipt failure was hidden');
check($pdo->query('SELECT name FROM crm_contacts')->fetchColumn()==='Changed','CRM save survived receipt failure');
rejects(fn()=>homeserver_contacts_v241_cloud_crm_create(1,['mutation_id'=>'s12-create-fail','display_name'=>'Orphan','email'=>'orphan@example.invalid']),'Create receipt failure hidden');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_contacts')->fetchColumn()===1,'Orphan CRM contact survived');$pdo->exec('DROP TRIGGER fail_receipt');
rejects(fn()=>homeserver_contacts_v241_cloud_crm_create(3,['mutation_id'=>'s12-disabled','email'=>'disabled@example.invalid']),'Disabled owner can write CRM');
check(!$pdo->inTransaction(),'Failed writer leaked a transaction');
// Execute the production token refresh against controlled provider responses.
$sync=file_get_contents(dirname(__DIR__).'/includes/agent-calendar-sync-v500.php');$start=strpos($sync,'function agent_calendar_sync_refresh_v500');$end=strpos($sync,'function agent_calendar_sync_api_v500',$start);eval(substr($sync,$start,$end-$start));
function agent_calendar_sync_connection_v500(PDO $pdo,int $ownerId,int $id):?array{$s=$pdo->prepare('SELECT * FROM agent_calendar_connections WHERE id=? AND owner_user_id=?');$s->execute([$id,$ownerId]);return $s->fetch()?:null;}
function agent_calendar_sync_decrypt_v500(string $value):string{return $value;}
function agent_calendar_sync_encrypt_v500(string $value):string{return $value;}
function agent_calendar_sync_config_v500():array{return ['google'=>['client_id'=>'fixture','client_secret'=>'fixture']];}
function agent_calendar_sync_http_v500(...$args):array{global $providerHook;if($providerHook)$providerHook();return ['json'=>['access_token'=>'new-access','refresh_token'=>'new-refresh','expires_in'=>3600]];}
$pdo->exec("CREATE TABLE agent_calendar_connections(id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,sync_enabled INTEGER,write_enabled INTEGER,access_token_ciphertext TEXT,refresh_token_ciphertext TEXT,token_expires_at TEXT,last_error TEXT);INSERT INTO agent_calendar_connections VALUES(1,1,'google','connected',1,1,'access','refresh','2020-01-01 00:00:00','');");
$connection=agent_calendar_sync_connection_v500($pdo,1,1);$providerHook=static function()use($pdo){$pdo->exec("UPDATE agent_calendar_connections SET status='disconnected',access_token_ciphertext='',refresh_token_ciphertext=NULL,sync_enabled=0,write_enabled=0 WHERE id=1");};
rejects(fn()=>agent_calendar_sync_refresh_v500($pdo,$connection),'Late refresh reconnected disconnected calendar');
$after=agent_calendar_sync_connection_v500($pdo,1,1);check($after['status']==='disconnected'&&$after['access_token_ciphertext']==='','Disconnected credentials restored');
$providerHook=null;rejects(fn()=>agent_calendar_sync_refresh_v500($pdo,$connection),'Stale provider snapshot accepted');
echo "PRODUCTIVITY_SECTION12=PASS\n";
