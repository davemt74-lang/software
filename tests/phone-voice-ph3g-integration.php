<?php
declare(strict_types=1);
// PH3G deterministic carrier/tenant/child-call tests using isolated SQLite.
require dirname(__DIR__).'/includes/phone-cloud-v100.php';
require dirname(__DIR__).'/includes/phone-sms-v110.php';
require dirname(__DIR__).'/includes/phone-voice-v130.php';
require dirname(__DIR__).'/includes/phone-voice-conversation-v140.php';
require dirname(__DIR__).'/includes/phone-voice-stream-v150.php';

$settings=['phone_voice_stream_sip_uri_v150'=>'sip:vp3@us1.sip.livekit.cloud;transport=tls',
    'phone_voice_stream_worker_secret_v150'=>str_repeat('r',48)];
function setting(string $key,string $default=''): string {global $settings;return $settings[$key]??$default;}
function ai_decrypt_secret(string $cipher): string {return $cipher;}
function table_exists(string $name): bool {
    global $pdo;$q=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$name]);return (bool)$q->fetchColumn();
}
function subscription_has_entitlement(?array $user,string $key): bool {
    return (int)($user['id']??0)===1 && in_array($key,['phone.numbers','phone.voice'],true);
}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int {return 2;}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,phone_e164 TEXT,
 owner_user_id INTEGER,agent_id INTEGER,voice_enabled INTEGER)');
$pdo->exec("CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT,provider_call_sid TEXT,
 owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 TEXT,called_e164 TEXT,
 state TEXT DEFAULT 'voicemail_offered',created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid))");
$pdo->exec('CREATE TABLE phone_voice_modes_v140(phone_number_id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,mode TEXT)');
$pdo->exec('CREATE TABLE phone_voice_turns_v140(call_sid TEXT,step_number INTEGER,state TEXT)');
$pdo->exec('CREATE TABLE phone_voice_stream_routes_v150(phone_number_id INTEGER PRIMARY KEY,
 owner_user_id INTEGER,cloud_agent_id INTEGER,homeserver_agent_id INTEGER)');
$pdo->exec('CREATE TABLE phone_voice_stream_claims_v150(parent_call_sid TEXT PRIMARY KEY,child_call_sid TEXT UNIQUE)');
$pdo->exec('INSERT INTO users VALUES(1),(2)');
$pdo->exec("INSERT INTO user_agents VALUES(7,1,1,'Longfellow'),(8,2,1,'Private')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES(1,'twilio','+16025550100',1,7,1)");
$pdo->exec('INSERT INTO phone_voice_stream_routes_v150 VALUES(1,1,7,42)');

$checks=0;
function checkPh3g(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$callback='https://vp3.example.test/api/phone-voice-inbound-v130.php';
$carrierToken='twilio-test-only';$account='AC'.str_repeat('a',32);
$parent='CA'.str_repeat('b',32);$child='CA'.str_repeat('c',32);$other='CA'.str_repeat('d',32);
$form=['AccountSid'=>$account,'CallSid'=>$parent,'To'=>'+16025550100','From'=>'+16025550101','Direction'=>'inbound'];
$signed=vp3_phone_sms_twilio_signature_v110($callback,$form,$carrierToken);
$inbound=vp3_phone_voice_incoming_v130($pdo,$form,$signed,$callback,$carrierToken,$account);
checkPh3g($inbound['accepted'] && str_contains($inbound['twiml'],'<Dial')
    && str_contains($inbound['twiml'],'<Sip>'),'signed Twilio ingress routes to LiveKit SIP');
checkPh3g((string)$pdo->query('SELECT state FROM phone_voice_calls_v130')->fetchColumn()==='streaming_active',
    'first signed inbound receipt becomes streaming');
checkPh3g(!vp3_phone_voice_incoming_v130($pdo,$form,$signed,$callback,$carrierToken,$account)['accepted'],
    'duplicate carrier webhook does not dial a second SIP connection');
$route=vp3_phone_voice_current_route_v131($pdo,$parent,1800,['streaming_active']);
checkPh3g($route['accepted'] && $route['authority']==='unauthenticated_caller','Cloud binds anonymous caller');
$issued=time();$proof=vp3_phone_stream_proof_v150(vp3_phone_stream_secret_v150(),$parent,$issued,$route,42);
$payload=['call_sid'=>$child,'parent_call_sid'=>$parent,'issued'=>(string)$issued,'proof'=>$proof,
    'to_number'=>'+16025550100','from_number'=>'+16025550101'];
$binding=vp3_phone_stream_resolve_v150($pdo,$payload,vp3_phone_stream_secret_v150(),time());
checkPh3g($binding['ok'] && $binding['agent_id']===42 && !$binding['caller_verified'] && !$binding['allow_actions'],
    'verified Cloud receipt returns explicit HomeServer secondary Agent ID');
checkPh3g(vp3_phone_stream_active_v150($pdo,$child,$parent),'Cloud permits the verified child');
checkPh3g(vp3_phone_stream_resolve_v150($pdo,$payload,vp3_phone_stream_secret_v150(),time())['ok'],
    'same child may revalidate without duplicating claim');
checkPh3g(!vp3_phone_stream_resolve_v150($pdo,[...$payload,'call_sid'=>$other],
    vp3_phone_stream_secret_v150(),time())['ok'],'different SIP child cannot replay parent proof');
checkPh3g(!vp3_phone_stream_resolve_v150($pdo,[...$payload,'proof'=>str_repeat('0',64)],
    vp3_phone_stream_secret_v150(),time())['ok'],'tampered SIP proof rejected');
checkPh3g(!vp3_phone_stream_resolve_v150($pdo,[...$payload,'to_number'=>'+16025550109'],
    vp3_phone_stream_secret_v150(),time())['ok'],'SIP destination mismatch rejected');
checkPh3g(!vp3_phone_stream_resolve_v150($pdo,[...$payload,'issued'=>(string)($issued-181)],
    vp3_phone_stream_secret_v150(),time())['ok'],'expired proof rejected');

$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=8 WHERE id=1');
checkPh3g(!vp3_phone_stream_active_v150($pdo,$child,$parent),'tenant reassignment revokes active audio');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=7 WHERE id=1');
$pdo->exec('DELETE FROM phone_voice_stream_routes_v150 WHERE phone_number_id=1');
checkPh3g(!vp3_phone_stream_active_v150($pdo,$child,$parent),'admin disable revokes active audio');

$pdo->exec('INSERT INTO phone_voice_stream_routes_v150 VALUES(1,1,7,42)');
$completeUrl=vp3_phone_voice_sibling_url_v130($callback,'phone-voice-stream-complete-v150.php');
$finish=['AccountSid'=>$account,'CallSid'=>$parent,'DialCallStatus'=>'failed'];
$finishSig=vp3_phone_sms_twilio_signature_v110($completeUrl,$finish,$carrierToken);
$response=vp3_phone_stream_complete_v150($pdo,$finish,$finishSig,$callback,$carrierToken,$account);
checkPh3g($response['accepted'] && str_contains($response['twiml'],'<Record'),
    'signed failed SIP dial offers voicemail');
checkPh3g(!vp3_phone_stream_active_v150($pdo,$child,$parent),'failed SIP terminates live authorization');
echo 'PHONE_VOICE_PH3G_INTEGRATION=PASS ('.$checks.' checks)'.PHP_EOL;
