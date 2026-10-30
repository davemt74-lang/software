<?php
declare(strict_types=1);
require __DIR__.'/../includes/phone-cloud-v100.php';
require __DIR__.'/../includes/phone-sms-v110.php';
require __DIR__.'/../includes/phone-sms-outbound-v120.php';

$GLOBALS['smsLive']='0';$GLOBALS['monthlyLimit']=1;$GLOBALS['carrierSends']=0;
function setting(string $name,string $default=''): string
{
    return match($name){'phone_sms_outbound_live_v120'=>$GLOBALS['smsLive'],
      'phone_sms_status_url_v120'=>'https://vp3.example/api/phone-sms-status-v120.php',default=>$default};
}
function subscription_has_entitlement(?array $user,string $key): bool
{
    return (int)($user['id']??0)===1 && in_array($key,['phone.numbers','phone.sms','phone.sms.monthly'],true);
}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int
{
    return $key==='phone.sms.monthly'?$GLOBALS['monthlyLimit']:2;
}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,phone_e164 TEXT,
    owner_user_id INTEGER,agent_id INTEGER,provider_sid TEXT,sms_enabled INTEGER)');
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
$pdo->exec('CREATE TABLE phone_sms_outbox_v120(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,
    phone_number_id INTEGER,agent_id INTEGER,recipient_e164 TEXT,body TEXT,consent_source TEXT,
    status TEXT DEFAULT \'draft\',provider TEXT DEFAULT \'twilio\',provider_message_sid TEXT,
    authorized_by_user_id INTEGER,authorized_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_message_sid))');
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Longfellow'),(8,2,1,'Other')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES
  (1,'twilio','+16025550101',1,7,'PN00000000000000000000000000000001',1),
  (2,'twilio','+16025550102',2,8,'PN00000000000000000000000000000002',1)");
$checks=0;
function check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;}
$fakeCarrier=static function(string $from,string $to,string $body,string $callback):array {
    $GLOBALS['carrierSends']++;
    if($from!=='+16025550101'||$to!=='+16025550999'||$body!=='Appointment confirmed.'
        ||$callback!=='https://vp3.example/api/phone-sms-status-v120.php')
        throw new RuntimeException('Unexpected carrier arguments.');
    return ['sid'=>'SM'.str_repeat('a',32)];
};
$id=vp3_phone_sms_draft_v120($pdo,1,1,'+16025550999','Appointment confirmed.',
    'Customer expressly requested an appointment confirmation.');
check($id>0,'draft created');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_messages_v110')->fetchColumn()===0,'draft does not send');
check(vp3_phone_sms_draft_owned_v120($pdo,2,$id)===null,'other tenant cannot read draft');
try {vp3_phone_sms_draft_v120($pdo,2,1,'+16025550999','test','Verified recipient consent');throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'account'),'other tenant cannot use number');}
try {vp3_phone_sms_authorize_send_v120($pdo,1,$id,'SEND +16025550999',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'not been activated'),'disabled gateway blocks sending');}
$GLOBALS['smsLive']='1';
try {vp3_phone_sms_authorize_send_v120($pdo,1,$id,'SEND wrong',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'Type SEND'),'exact authorization required');}
$pdo->exec("INSERT INTO phone_sms_contacts_v110 VALUES (1,'+16025550999',1,'STOP',CURRENT_TIMESTAMP)");
try {vp3_phone_sms_authorize_send_v120($pdo,1,$id,'SEND +16025550999',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'opted out'),'recipient opt-out blocks sending');}
$pdo->exec("UPDATE phone_sms_contacts_v110 SET carrier_opted_out=0,last_keyword='START'");
$sent=vp3_phone_sms_authorize_send_v120($pdo,1,$id,'SEND +16025550999',$fakeCarrier);
check($sent['sent']===true&&$GLOBALS['carrierSends']===1,'one authorized send');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_sms_messages_v110')->fetchColumn()===1,'sent text stored in timeline');
check(vp3_phone_sms_monthly_quota_v120($pdo,1)['remaining']===0,'monthly quota decremented');
try {vp3_phone_sms_authorize_send_v120($pdo,1,$id,'SEND +16025550999',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'already sent'),'one-shot send cannot replay');}
$second=vp3_phone_sms_draft_v120($pdo,1,1,'+16025550999','Second message','Customer expressly requested an appointment confirmation.');
try {vp3_phone_sms_authorize_send_v120($pdo,1,$second,'SEND +16025550999',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'exhausted'),'monthly quota enforced');}
check($GLOBALS['carrierSends']===1,'quota failures never call carrier');
$GLOBALS['monthlyLimit']=2;
$uncertainCarrier=static function():array {$GLOBALS['carrierSends']++;throw new RuntimeException('Simulated carrier timeout');};
try {vp3_phone_sms_authorize_send_v120($pdo,1,$second,'SEND +16025550999',$uncertainCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'could not be confirmed'),'uncertain provider result requires review');}
check($pdo->query('SELECT status FROM phone_sms_outbox_v120 WHERE id='.$second)->fetchColumn()==='review_required',
    'uncertain send holds quota and requires reconciliation');
try {vp3_phone_sms_authorize_send_v120($pdo,1,$second,'SEND +16025550999',$fakeCarrier);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'already sent'),'uncertain send cannot be retried automatically');}
$token='test-signature-key';$url='https://vp3.example/api/phone-sms-status-v120.php';
$account='AC'.str_repeat('b',32);
$event=['AccountSid'=>$account,'MessageSid'=>$sent['provider_message_sid'],'MessageStatus'=>'delivered'];
$signature=vp3_phone_sms_twilio_signature_v110($url,$event,$token);
check(vp3_phone_sms_delivery_event_v120($pdo,$event,$signature,$url,$token,$account),'signed status callback accepted');
check($pdo->query('SELECT status FROM phone_sms_outbox_v120 WHERE id='.$id)->fetchColumn()==='delivered','delivery updates outbox');
$event['MessageStatus']='sent';$signature=vp3_phone_sms_twilio_signature_v110($url,$event,$token);
vp3_phone_sms_delivery_event_v120($pdo,$event,$signature,$url,$token,$account);
check($pdo->query('SELECT status FROM phone_sms_outbox_v120 WHERE id='.$id)->fetchColumn()==='delivered','terminal state never regresses');
try {vp3_phone_sms_delivery_event_v120($pdo,$event,'forged',$url,$token,$account);throw new RuntimeException('missing reject');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'signature'),'reject forged delivery callback');}
echo 'PHONE_SMS_PH3B_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
