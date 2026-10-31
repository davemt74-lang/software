<?php
declare(strict_types=1);
require __DIR__.'/../includes/phone-cloud-v100.php';
require __DIR__.'/../includes/phone-sms-v110.php';
require __DIR__.'/../includes/phone-voice-v130.php';

function subscription_has_entitlement(?array $user,string $key): bool
{
    return (int)($user['id']??0)===1 && in_array($key,['phone.numbers','phone.voice'],true);
}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int {return 2;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,provider_sid TEXT,phone_e164 TEXT,
 owner_user_id INTEGER,agent_id INTEGER,voice_enabled INTEGER)');
$pdo->exec('CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT,provider_call_sid TEXT,
 owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 TEXT,called_e164 TEXT,
 state TEXT DEFAULT \'voicemail_offered\',created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid))');
$pdo->exec('CREATE TABLE phone_voice_recordings_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,call_id INTEGER,owner_user_id INTEGER,
 provider TEXT,provider_recording_sid TEXT,recording_status TEXT,duration_seconds INTEGER,
 UNIQUE(provider,provider_recording_sid))');
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Longfellow'),(8,2,1,'Other agent')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES
 (1,'twilio','PNaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','+16025550101',1,7,1),
 (2,'twilio','PNbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb','+16025550102',2,8,1),
 (3,'twilio','PNcccccccccccccccccccccccccccccccc','+16025550103',1,7,0)");

$url='https://vp3.example/api/phone-voice-inbound-v130.php';
$token='TEST-SIGNATURE-TOKEN';$account='AC'.str_repeat('a',32);$checks=0;
function check(bool $yes,string $label): void {
    global $checks; if (!$yes) throw new RuntimeException('FAIL '.$label);$checks++;
}
function callForm(array $override=[]): array {
    global $account;
    return array_replace(['AccountSid'=>$account,'CallSid'=>'CA'.str_repeat('b',32),
        'Direction'=>'inbound','From'=>'+16025550999','To'=>'+16025550101'], $override);
}
function inbound(PDO $pdo,array $form,?string $signature=null): array {
    global $url,$token,$account;
    return vp3_phone_voice_incoming_v130($pdo,$form,
        $signature??vp3_phone_sms_twilio_signature_v110($url,$form,$token),$url,$token,$account);
}
function record(PDO $pdo,array $form,?string $signature=null): array {
    global $url,$token,$account;
    $endpoint=vp3_phone_voice_sibling_url_v130($url,'phone-voice-recording-v130.php');
    return vp3_phone_voice_recording_event_v130($pdo,$form,
        $signature??vp3_phone_sms_twilio_signature_v110($endpoint,$form,$token),$endpoint,$token,$account);
}
$first=inbound($pdo,callForm());
check($first['accepted']===true && str_contains($first['twiml'],'Longfellow'),'signed inbound greeting');
check(str_contains($first['twiml'],'phone-voice-recording-v130.php')
    && str_contains($first['twiml'],'phone-voice-complete-v130.php'),'signed recording callbacks');
check(str_contains($first['twiml'],'automated answering service'),'automated disclosure');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_voice_calls_v130')->fetchColumn()===1,'call receipt stored');
inbound($pdo,callForm());
check((int)$pdo->query('SELECT COUNT(*) FROM phone_voice_calls_v130')->fetchColumn()===1,'inbound replay idempotent');
try {inbound($pdo,callForm(),'forged');throw new RuntimeException('missing rejection');}
catch(RuntimeException $e){check($e->getMessage()==='Invalid provider signature.','reject forged callback');}
try {inbound($pdo,callForm(['AccountSid'=>'AC'.str_repeat('c',32)]));throw new RuntimeException('missing rejection');}
catch(RuntimeException $e){check($e->getMessage()==='Provider account mismatch.','reject cross-account callback');}
check(inbound($pdo,callForm(['CallSid'=>'CA'.str_repeat('1',32),'To'=>'+16025550102']))['reason']==='account_ineligible',
    'plan isolates other tenant');
check(inbound($pdo,callForm(['CallSid'=>'CA'.str_repeat('2',32),'To'=>'+16025550103']))['reason']==='number_inactive',
    'disabled number rejected');
check(inbound($pdo,callForm(['CallSid'=>'CA'.str_repeat('3',32),'From'=>'anonymous']))['accepted']===true,
    'private caller permitted without identity claim');
try {inbound($pdo,callForm(['From'=>'+16025558888']));throw new RuntimeException('missing mismatch');}
catch(RuntimeException $e){check($e->getMessage()==='Call receipt conflicts with number assignment.','no call reuse identity mutation');}

$event=['AccountSid'=>$account,'CallSid'=>'CA'.str_repeat('b',32),'RecordingSid'=>'RE'.str_repeat('d',32),
    'RecordingStatus'=>'completed','RecordingDuration'=>'36'];
check(record($pdo,$event)['accepted']===true,'recording finalized without To and From');
check((int)$pdo->query('SELECT COUNT(*) FROM phone_voice_recordings_v130')->fetchColumn()===1,'one voicemail stored');
check(record($pdo,$event)['duplicate']===true,'duplicate recording idempotent');
check((string)$pdo->query("SELECT state FROM phone_voice_calls_v130 WHERE provider_call_sid='CA".str_repeat('b',32)."'")
    ->fetchColumn()==='voicemail_ready','call reflects ready voicemail');
check(count(vp3_phone_voice_calls_v130($pdo,1))===2 && count(vp3_phone_voice_calls_v130($pdo,2))===0,
    'owner-only call list');
try {record($pdo,array_replace($event,['CallSid'=>'CA'.str_repeat('3',32)]));throw new RuntimeException('missing mismatch');}
catch(RuntimeException $e){check($e->getMessage()==='Recording receipt conflicts with original call.','voicemail cannot move accounts or calls');}
try {record($pdo,$event,'bad');throw new RuntimeException('missing rejection');}
catch(RuntimeException $e){check($e->getMessage()==='Invalid provider signature.','recording webhook signed');}
check(record($pdo,array_replace($event,['CallSid'=>'CA'.str_repeat('e',32)]))['reason']==='unknown_call',
    'unknown call receipt cannot create orphan voicemail');
echo 'PHONE_VOICE_PH3C_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
