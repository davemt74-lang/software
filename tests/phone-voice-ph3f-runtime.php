<?php
declare(strict_types=1);
// PH3F integration harness: signed Twilio callbacks + tenant-bound turn ledger.
require __DIR__.'/../includes/phone-cloud-v100.php';
require __DIR__.'/../includes/phone-sms-v110.php';
require __DIR__.'/../includes/phone-voice-v130.php';
require __DIR__.'/../includes/phone-voice-conversation-v140.php';
function subscription_has_entitlement(?array $user,string $key): bool {
    return empty($GLOBALS['planSuspended']) && (int)($user['id']??0)===1
        && in_array($key,['phone.numbers','phone.voice'],true);
}
function subscription_entitlement_limit(?array $user,string $key,?int $default=null): ?int {return 2;}
function table_exists(string $name): bool {
    global $pdo;
    $q=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$name]);return (bool)$q->fetchColumn();
}
function ai_active_provider(): string {return 'anthropic';}
function ai_provider_configuration_failure(string $provider): ?array {return null;}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT)');
$pdo->exec('CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,provider TEXT,phone_e164 TEXT,
 owner_user_id INTEGER,agent_id INTEGER,voice_enabled INTEGER)');
$pdo->exec("CREATE TABLE phone_voice_calls_v130(id INTEGER PRIMARY KEY AUTOINCREMENT,provider TEXT,provider_call_sid TEXT,
 owner_user_id INTEGER,phone_number_id INTEGER,agent_id INTEGER,caller_e164 TEXT,called_e164 TEXT,
 state TEXT DEFAULT 'voicemail_offered',created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(provider,provider_call_sid))");
$pdo->exec('CREATE TABLE phone_voice_modes_v140(phone_number_id INTEGER PRIMARY KEY,owner_user_id INTEGER,agent_id INTEGER,mode TEXT)');
$pdo->exec("CREATE TABLE phone_voice_turns_v140(id INTEGER PRIMARY KEY AUTOINCREMENT,call_sid TEXT,step_number INTEGER,
 owner_user_id INTEGER,agent_id INTEGER,speech_hash TEXT,speech_text TEXT,response_text TEXT,state TEXT DEFAULT 'processing',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(call_sid,step_number))");
$pdo->exec('INSERT INTO users VALUES (1),(2)');
$pdo->exec("INSERT INTO user_agents VALUES (7,1,1,'Longfellow'),(8,2,1,'Other')");
$pdo->exec("INSERT INTO phone_cloud_numbers_v100 VALUES (1,'twilio','+16025550101',1,7,1),
 (2,'twilio','+16025550102',2,8,1)");
$url='https://vp3.example/api/phone-voice-inbound-v130.php';$token='SAMPLE-TOKEN';
$account='AC'.str_repeat('a',32);$checks=0;$generations=0;
function checkPh3f(bool $condition,string $name): void {
    global $checks;if (!$condition) throw new RuntimeException('FAIL: '.$name);$checks++;
}
function callFormPh3f(string $sid): array {
    global $account;return ['AccountSid'=>$account,'CallSid'=>$sid,'Direction'=>'inbound',
        'To'=>'+16025550101','From'=>'+16025550999'];
}
function inboundPh3f(PDO $pdo,string $sid): array {
    global $url,$token,$account;$form=callFormPh3f($sid);
    return vp3_phone_voice_incoming_v130($pdo,$form,vp3_phone_sms_twilio_signature_v110($url,$form,$token),
        $url,$token,$account);
}
function turnPh3f(PDO $pdo,string $sid,int $step,string $speech,?callable $generator=null,
    ?string $signUrl=null): array {
    global $url,$token,$account;
    $form=['AccountSid'=>$account,'CallSid'=>$sid,'SpeechResult'=>$speech];
    $signature=vp3_phone_sms_twilio_signature_v110(
        $signUrl??vp3_phone_voice_turn_url_v140($url,$step),$form,$token);
    return vp3_phone_voice_turn_v140($pdo,$form,$signature,$url,$token,$account,$step,$generator);
}
$sid='CA'.str_repeat('b',32);
checkPh3f(str_contains(inboundPh3f($pdo,$sid)['twiml'],'<Record'),'existing voicemail remains default');
vp3_phone_voice_set_mode_v140($pdo,1,true);
checkPh3f(vp3_phone_voice_live_config_v140($pdo,['phone_number_id'=>1,'owner_user_id'=>1,'agent_id'=>7]),
    'admin opt-in binds exact tenant agent');
checkPh3f(!vp3_phone_voice_live_config_v140($pdo,['phone_number_id'=>1,'owner_user_id'=>2,'agent_id'=>8]),
    'config rejects other tenant');
$sid='CA'.str_repeat('c',32);$welcome=inboundPh3f($pdo,$sid);
checkPh3f($welcome['accepted']&&str_contains($welcome['twiml'],'<Gather')
    &&str_contains($welcome['twiml'],'AI phone assistant'),'signed call starts disclosed AI speech');
checkPh3f(str_contains($welcome['twiml'],'phone-voice-turn-v140.php?step=1'),
    'callback carries signed step and exact sibling origin');
checkPh3f(vp3_phone_voice_current_route_v131($pdo,$sid)['accepted']===false,
    'conversation no longer qualifies for voicemail-only route');
$generator=function(string $speech,array $history,array $route)use(&$generations):array {
    $generations++;
    checkPh3f($route['authority']==='unauthenticated_caller'&&$route['execution']==='disabled',
        'model callback has no owner authority');
    if($generations===1)checkPh3f(!$history && $speech==='Hello & help','first call has no private history');
    if($generations===2)checkPh3f(count($history)===2
        &&$history[0]['message']==='Hello & help','only same-call history is sent');
    return ['ok'=>true,'answer'=>'Reply & <safe> '.$generations];
};
$turn=turnPh3f($pdo,$sid,1,'Hello & help',$generator);
checkPh3f($turn['accepted']&&str_contains($turn['twiml'],'Reply &amp; &lt;safe&gt;')
    &&str_contains($turn['twiml'],'?step=2'),'spoken response is XML-safe and loops');
$replay=turnPh3f($pdo,$sid,1,'Hello & help',$generator);
checkPh3f($replay['accepted']&&$replay['twiml']===$turn['twiml']&&$generations===1,
    'duplicate signed delivery replays stored response without AI charges');
checkPh3f(turnPh3f($pdo,$sid,1,'Different speech',$generator)['reason']==='turn_replay_conflict',
    'changed speech cannot overwrite signed turn');
checkPh3f(turnPh3f($pdo,$sid,3,'Skip ahead',$generator)['reason']==='step_out_of_sequence',
    'unsigned sequence jumps rejected even if carrier-signed');
try {turnPh3f($pdo,$sid,2,'hello',$generator,$url);throw new RuntimeException('accepted wrong URL');}
catch(RuntimeException $e) {checkPh3f($e->getMessage()==='Invalid provider signature.',
    'Twilio signature binds the exact query step');}
checkPh3f(turnPh3f($pdo,$sid,2,'Are you there?',$generator)['accepted'],
    'second spoken turn uses only prior phone history');
checkPh3f($generations===2,'exactly one model call per unique speech turn');
$inboundReplay=inboundPh3f($pdo,$sid);
checkPh3f($inboundReplay['accepted']&&str_contains($inboundReplay['twiml'],'?step=3'),
    'carrier retry of initial webhook resumes the current conversation');
vp3_phone_voice_set_mode_v140($pdo,1,false);
checkPh3f(turnPh3f($pdo,$sid,3,'Private data',$generator)['reason']==='call_not_authorized',
    'admin disable revokes live calls immediately');
checkPh3f((string)$pdo->query("SELECT state FROM phone_voice_calls_v130 WHERE provider_call_sid='".$sid."'")
    ->fetchColumn()==='completed','disable completes previous session');
vp3_phone_voice_set_mode_v140($pdo,1,true);
$sid='CA'.str_repeat('d',32);inboundPh3f($pdo,$sid);
$unavailable=turnPh3f($pdo,$sid,1,'Tell me a story',
    static fn():array=>['ok'=>false,'error'=>'credential secret should never be disclosed']);
checkPh3f($unavailable['accepted']&&str_contains($unavailable['twiml'],'<Record')
    &&!str_contains($unavailable['twiml'],'credential'),'model failure falls back to private voicemail');
try {inboundPh3f($pdo,$sid);throw new RuntimeException('unexpected replay acceptance');}
catch(RuntimeException $e) {checkPh3f($e->getMessage()==='Call receipt conflicts with number assignment.',
    'carrier retry cannot resurrect voicemail fallback into AI');}
$sid='CA'.str_repeat('e',32);inboundPh3f($pdo,$sid);
checkPh3f(turnPh3f($pdo,$sid,1,'',$generator)['fallback']==='voicemail',
    'silence is handled without model consumption');
$sid='CA'.str_repeat('f',32);inboundPh3f($pdo,$sid);
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=8 WHERE id=1');
checkPh3f(turnPh3f($pdo,$sid,1,'Who am I?',$generator)['reason']==='call_not_authorized',
    'agent reallocation revokes phone conversation');
checkPh3f($generations===2,'revoked routes never reach provider');
echo 'PHONE_VOICE_PH3F_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
