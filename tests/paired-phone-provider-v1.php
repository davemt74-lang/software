<?php
declare(strict_types=1);

// Exercise the production broker, prompt builders, usage reservations and dispatch guard.
// The shared provider fixture catches the outgoing HTTP body without using account credits.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once __DIR__.'/../includes/phone-tracking-v1.php';
require_once __DIR__.'/../includes/paired-phone-agent-model-v1.php';
require_once __DIR__.'/../includes/phone-tracking-chat-v1.php';

function has_permission(string $permission,array $user): bool
{
    return (int)$user['id']===1 && in_array($permission,['account.access','chat.access'],true);
}
function system_agent_name(): string { return 'System agent'; }
function vp3_agent_runtime_preference_readonly_v420(PDO $pdo,int $owner,int $agent): array
{
    return ['effective_preference'=>$GLOBALS['phonePreference']??'vp3_cloud'];
}
function homeserver_execution_v220_registry(int $owner): array
{
    return ['available'=>true,'capabilities'=>['unified_execution'=>['profile_safe_local_inference'=>[
        'operation'=>'agent.infer.local','stateless'=>true,'local_only'=>true,'caller_supplied_context_only'=>true,
        'tools_enabled'=>false,
    ]]]];
}
function homeserver_execution_v220_can_route(int $owner,string $operation): bool { return $owner===1 && $operation==='agent.infer.local'; }
function homeserver_https_v1300_transient_ready(PDO $pdo): bool { return !empty($GLOBALS['phoneRelayReady']); }
function homeserver_https_v1300_wait_transient(): array { throw new LogicException('Unexpected relay wait.'); }
function homeserver_https_v1300_remote_operation(int $owner,string $operation,array $payload,bool $transient,callable $guard): array
{
    if (!$transient || $operation!=='agent.infer.local' || array_keys($payload)!==['messages']) throw new LogicException('Unexpected local payload.');
    if (!$guard()) throw new RuntimeException('HomeServer inference authorization changed.',403);
    ++$GLOBALS['phoneLocalAttempts'];
    if (!empty($GLOBALS['phoneLocalTimeout'])) throw new RuntimeException('HomeServer transient request timed out.');
    return ['reply'=>'A reply from HomeServer.'];
}
$pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(7,1,1,'Studio assistant')");
$history=[
    ['role'=>'assistant','content'=>'Earlier reply for this phone.'],
    ['role'=>'user','content'=>'How do I find Camera?'],
];
$before=count($GLOBALS['gmailSummaryDispatch']);
$GLOBALS['gmailSummaryScenario']='phone';
$answer=vp3_paired_phone_model_reply_v1($pdo,1,7,$history,static fn()=>true);
$call=end($GLOBALS['gmailSummaryDispatch']);
$wire=json_encode($call['payload'],JSON_THROW_ON_ERROR);
if (count($GLOBALS['gmailSummaryDispatch'])!==$before+1 || $answer!=='Open the + menu in Chat and tap Camera.'
    || !str_contains($wire,'Studio assistant') || !str_contains($wire,'Earlier reply for this phone.')
    || !str_contains($wire,'How do I find Camera?') || str_contains($wire,'private-chat-persona')
    || str_contains($wire,'private-chat-tools') || isset($call['payload']['tools'])) {
    throw new RuntimeException('The phone turn must use only the paired conversation and no owner tools.');
}
$usage=$pdo->query("SELECT agent_id,service_status FROM ai_execution_ledger WHERE service_stage='paired_phone_chat' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ((int)$usage['agent_id']!==7 || $usage['service_status']!=='completed') {
    throw new RuntimeException('The connected agent must own the completed conversation usage entry.');
}
try {
    vp3_paired_phone_model_reply_v1($pdo,1,7,$history,static fn()=>false);
    throw new LogicException('Revoked phone must not dispatch.');
} catch (RuntimeException $error) {
    if ($error->getCode()!==503 || count($GLOBALS['gmailSummaryDispatch'])!==$before+1
        || (int)$pdo->query('SELECT COUNT(*) FROM ai_token_reservations')->fetchColumn()!==0) {
        throw new RuntimeException('Revocation left a paid attempt or pending reservation.');
    }
}
echo "PASS paired phone model: named agent, isolated transcript, metered reply and revoked dispatch.\n";

// Exercise the same entry point as chat_send and the independent owner announcements with a real turn row.
$pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT DEFAULT 'Owner';
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT);
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT);
    INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(9,1,7)");
vp3_phone_schema_v1($pdo);
vp3_paired_phone_schema_v1($pdo,'');
$id=bin2hex(random_bytes(16)); $token=vp3_phone_token_v1(random_bytes(32));
vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_devices_v1 (id,owner_user_id,token_hash,device_name,device_role,device_status,
    generation,allow_location,location_state,created_at,expires_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    [$id,1,vp3_extension_hash_v2000($token),'My phone','guest','approved',1,0,'off',time(),time()+600,time()]);
vp3_paired_phone_bind_v1($pdo,$id,1,7,9);
$input=['device_id'=>$id,'request_id'=>'11223344-5566-4777-8888-123456789abc','message'=>'How do I open the camera?'];
$phoneSend=static fn()=>vp3_paired_phone_chat_v1($pdo,$token,'chat_send',$input)['turns'][0];
$announcements=static fn()=>vp3_paired_phone_owner_updates_v1($pdo,1,7,9);
$initialPrompt=[];
$GLOBALS['gmailSummaryOnRoute']=static function()use(&$initialPrompt,$announcements):void { $initialPrompt=$announcements(); };
$pdo->exec("UPDATE user_llm_preferences SET route='own_key' WHERE user_id=1; DELETE FROM user_llm_credentials");
$calls=count($GLOBALS['gmailSummaryDispatch']);
$failed=$phoneSend();
if ($failed['state']!=='failed' || $failed['reply']!==null || !str_contains($failed['failure_message'],'account owner')
    || count($GLOBALS['gmailSummaryDispatch'])!==$calls || count($initialPrompt)!==1
    || !str_contains($initialPrompt[0]['speech'],$input['message']) || $announcements()!==[]
    || vp3_paired_phone_chat_v1($pdo,$token,'chat_status',['device_id'=>$id])['turns'][0]!==$failed) {
    throw new RuntimeException('A missing selected key must preserve the prompt and its actionable private-safe status.');
}
unset($GLOBALS['gmailSummaryOnRoute']);
$pdo->exec("UPDATE user_llm_preferences SET route='system' WHERE user_id=1; UPDATE vp3_paired_phone_turns_v1 SET attempted_at=0");
$replied=$phoneSend();$replyAnnouncement=$announcements();
if ($replied['state']!=='replied' || $replied['reply']!==$answer || isset($replied['failure_message'])
    || count($replyAnnouncement)!==1 || !str_contains($replyAnnouncement[0]['speech'],$answer)
    || $phoneSend()!==$replied || $announcements()!==[] || count($GLOBALS['gmailSummaryDispatch'])!==$calls+1) {
    throw new RuntimeException('Retrying the saved prompt must return and announce exactly one metered agent reply.');
}
echo "PASS paired phone holder send, missing owner credential, polling, same-ID retry, ordinary text and announcement delivery.\n";

$GLOBALS['phonePreference']='auto';
$GLOBALS['phoneLocalAttempts']=0;
$allow=true;
$plan=static function()use(&$allow):array {
    return ['blocked'=>false,'route'=>'homeserver','effective_preference'=>$GLOBALS['phonePreference'],
        'allow_vp3_fallback'=>true,'home'=>['cloud_allowed'=>$allow],'cloud'=>['ready'=>true]];
};
$GLOBALS['gmailSummaryOnRoute']=$plan;
$infer=static fn(?Closure $guard=null)=>vp3_paired_phone_model_reply_v1($pdo,1,7,$history,$guard??static fn()=>true);
$reject=static function(string $reason,?Closure $guard=null)use($infer):void {
    try { $infer($guard); }
    catch (PairedPhoneReplyFailure $error) { if ($error->reason===$reason) return; throw $error; }
    throw new LogicException('Unexpected successful reply.');
};
$calls=count($GLOBALS['gmailSummaryDispatch']);
if ($infer()!==$answer || count($GLOBALS['gmailSummaryDispatch'])!==$calls+1) throw new RuntimeException('Automatic must recover from unavailable transient relay.');
$allow=false; $reject('offline');
$allow=true; $GLOBALS['phonePreference']='homeserver_only'; $reject('offline');
$GLOBALS['phonePreference']='auto'; $checks=0;
$GLOBALS['gmailSummaryOnRoute']=static function()use(&$checks,$plan):array {
    $current=$plan();
    if (++$checks>1) $current['home']['cloud_allowed']=false;
    return $current;
};
$reject('offline');
$GLOBALS['gmailSummaryOnRoute']=$plan;
$GLOBALS['phoneRelayReady']=true;
if ($infer()!=='A reply from HomeServer.') throw new RuntimeException('An available local agent must reply on its selected route.');
$reject('access',static fn()=>false);
$GLOBALS['phoneLocalTimeout']=true; $reject('timeout');
if ($GLOBALS['phoneLocalAttempts']!==2 || count($GLOBALS['gmailSummaryDispatch'])!==$calls+1)
    throw new RuntimeException('HomeServer-only, cloud restrictions, revocation and unconfirmed local calls must never replay on Cloud.');
echo "PASS paired phone HomeServer reply, authorized pre-dispatch fallback, narrowed scope, revocation and timeout.\n";
