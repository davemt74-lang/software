<?php
declare(strict_types=1);
require __DIR__.'/../includes/phone-cloud-v100.php';
require __DIR__.'/../includes/phone-sms-v110.php';

function subscription_has_entitlement(?array $user,string $key): bool
{
    return (int)($user['id']??0)===1 && in_array($key,['phone.numbers','phone.sms'],true);
}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int {return 2;}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,phone_e164 TEXT,
    owner_user_id INTEGER,agent_id INTEGER,sms_enabled INTEGER)');
$pdo->exec('CREATE TABLE phone_sms_threads_v110(id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,contact_e164 TEXT,
    last_message_at TEXT DEFAULT CURRENT_TIMESTAMP,unread_count INTEGER DEFAULT 0,
    UNIQUE(phone_number_id,contact_e164))');
$pdo->exec('CREATE TABLE phone_sms_messages_v110(id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id INTEGER,owner_user_id INTEGER,provider TEXT,provider_message_sid TEXT,
    direction TEXT,body TEXT,delivery_status TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(provider,provider_message_sid))');
$pdo->exec('CREATE TABLE phone_sms_contacts_v110(phone_number_id INTEGER,contact_e164 TEXT,
    carrier_opted_out INTEGER DEFAULT 0,last_keyword TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(phone_number_id,contact_e164))');
$pdo->exec('INSERT INTO users(id) VALUES (1),(2)');
$pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Longfellow'),(8,2,1,'Other agent')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES (1,'twilio','+16025550101',1,7,1),
    (2,'twilio','+16025550102',2,8,1),(3,'twilio','+16025550103',1,7,0)");
vp3_phone_sms_ensure_assignments_v111($pdo);
$url='https://vp3.example/api/phone-sms-inbound-v110.php';
$token='TEST-TWILIO-TOKEN-FOR-SIGNATURE-ONLY';$account='AC'.str_repeat('a',32);
$checks=0;
function check(bool $truth,string $message): void {global $checks;if(!$truth)throw new RuntimeException('FAIL '.$message);$checks++;}
function message(array $overrides=[]): array
{
    global $account;
    return array_replace(['AccountSid'=>$account,'MessageSid'=>'SM'.str_repeat('b',32),
      'From'=>'+16025550999','To'=>'+16025550101','Body'=>'Hello agent','NumMedia'=>'0'],$overrides);
}
function receive(PDO $pdo,array $form,?string $overrideSignature=null): array
{
    global $url,$token,$account;
    return vp3_phone_sms_ingest_v110($pdo,$form,
        $overrideSignature??vp3_phone_sms_twilio_signature_v110($url,$form,$token),$url,$token,$account);
}

$form=message();$first=receive($pdo,$form);
check($first['accepted']===true && $first['duplicate']===false,'valid signed inbound');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_threads_v110')->fetchColumn()===1,'creates one thread');
check((int)$pdo->query('SELECT unread_count FROM phone_sms_threads_v110')->fetchColumn()===1,'unread increases');
$duplicate=receive($pdo,$form);
check($duplicate['duplicate']===true,'webhook replay idempotency');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_messages_v110')->fetchColumn()===1,'replay never adds message');
try {receive($pdo,message(['MessageSid'=>'SM'.str_repeat('c',32)]),'bad-signature');throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check($error->getMessage()==='Invalid provider signature.','reject forged signature');}
try {receive($pdo,message(['AccountSid'=>'AC'.str_repeat('c',32)]));throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check($error->getMessage()==='Provider account mismatch.','reject wrong Twilio account');}
$ineligible=receive($pdo,message(['MessageSid'=>'SM'.str_repeat('d',32),'To'=>'+16025550102']));
check($ineligible['reason']==='account_ineligible','plan-gated tenant');
$inactive=receive($pdo,message(['MessageSid'=>'SM'.str_repeat('e',32),'To'=>'+16025550103']));
check($inactive['reason']==='number_inactive','disabled inbound ignored');
$unknown=receive($pdo,message(['MessageSid'=>'SM'.str_repeat('f',32),'To'=>'+16025550111']));
check($unknown['reason']==='number_inactive','unknown number not claimed');
$stop=receive($pdo,message(['MessageSid'=>'SM'.str_repeat('1',32),'Body'=>'STOP']));
check($stop['accepted']===true,'STOP stored');
check((int)$pdo->query('SELECT carrier_opted_out FROM phone_sms_contacts_v110')->fetchColumn()===1,'STOP records opt-out');
receive($pdo,message(['MessageSid'=>'SM'.str_repeat('2',32),'Body'=>'START']));
check((int)$pdo->query('SELECT carrier_opted_out FROM phone_sms_contacts_v110')->fetchColumn()===0,'START releases carrier stop, not marketing consent');
$unnamedThreads=vp3_phone_sms_threads_v110($pdo,1);
check(count($unnamedThreads)===1 && count(vp3_phone_sms_threads_v110($pdo,2))===0,'tenant-isolated thread list');
check(($unnamedThreads[0]['contact_name']??'')===''&&(int)($unnamedThreads[0]['contact_id']??0)===0,'missing optional CRM storage keeps SMS history available by number');
$pdo->exec("CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name TEXT,phone TEXT,status TEXT,email TEXT DEFAULT '',email_normalized TEXT NULL)");
$pdo->exec("INSERT INTO crm_contacts(id,owner_user_id,name,phone,status) VALUES
    (1,1,'My caller <example> & Co','(602) 555-0999','active'),
    (2,2,'Other owner name','+16025550999','active'),
    (3,1,'Archived caller','+16025550999','archived')");
$namedThreads=vp3_phone_sms_threads_v110($pdo,1);
check($namedThreads[0]['contact_name']==='My caller <example> & Co'&&(int)$namedThreads[0]['contact_id']===1
    &&$namedThreads[0]['contact_e164']==='+16025550999','SMS history resolves the owner phone-only contact and retains its number');
$pdo->exec("UPDATE crm_contacts SET name='Updated caller name' WHERE id=1");
check(vp3_phone_sms_threads_v110($pdo,1)[0]['contact_name']==='Updated caller name','saved contact changes appear in existing SMS history');
$pdo->exec("INSERT INTO phone_sms_threads_v110(owner_user_id,phone_number_id,agent_id,contact_e164) VALUES(2,2,8,'+16025550999')");
check(vp3_phone_sms_threads_v110($pdo,2)[0]['contact_name']==='Other owner name','the same caller number resolves independently for another owner');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
check(vp3_phone_sms_threads_v110($pdo,1)[0]['contact_name']==='Updated caller name','reassigned numbers do not replace the original owner historical contact name');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
$pdo->exec("UPDATE crm_contacts SET status='archived' WHERE id=1");
check(vp3_phone_sms_threads_v110($pdo,1)[0]['contact_name']===''&&(int)vp3_phone_sms_threads_v110($pdo,1)[0]['contact_id']===0,'archiving a contact removes its label without exposing another owner name');
$pdo->exec("UPDATE crm_contacts SET status='active' WHERE id=1");
$threadId=(int)$first['thread_id'];
check(count(vp3_phone_sms_messages_v110($pdo,1,$threadId))===3,'owner reads own thread');
try {vp3_phone_sms_messages_v110($pdo,2,$threadId);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check($error->getMessage()==='Conversation not found.','other owner cannot read thread');}
try {vp3_phone_sms_mark_read_v110($pdo,2,$threadId);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check($error->getMessage()==='Conversation not found.','other owner cannot mark read');}
vp3_phone_sms_mark_read_v110($pdo,1,$threadId);
check((int)$pdo->query('SELECT unread_count FROM phone_sms_threads_v110')->fetchColumn()===0,'owner can mark read');
echo 'PHONE_SMS_PH3A_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
