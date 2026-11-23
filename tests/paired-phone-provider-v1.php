<?php
declare(strict_types=1);

// Exercise the production broker, prompt builders, usage reservations and dispatch guard.
// The shared provider fixture catches the outgoing HTTP body without using account credits.
require __DIR__.'/agent-connection-gmail-inbox-initial-summary-v1.php';
require_once __DIR__.'/../includes/phone-tracking-v1.php';
require_once __DIR__.'/../includes/paired-phone-agent-model-v1.php';

function has_permission(string $permission,array $user): bool
{
    return (int)$user['id']===1 && in_array($permission,['account.access','chat.access'],true);
}
function system_agent_name(): string { return 'System agent'; }
function vp3_agent_runtime_preference_readonly_v420(PDO $pdo,int $owner,int $agent): array
{
    return ['effective_preference'=>'vp3_cloud'];
}
$pdo->exec("ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;
    CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);
    INSERT INTO user_agents VALUES(7,1,1,'Studio assistant')");
$history=[
    ['role'=>'assistant','content'=>'Earlier reply for this phone.'],
    ['role'=>'user','content'=>'How do I find Camera?'],
];
$before=count($GLOBALS['gmailSummaryDispatch']);
$answer=vp3_paired_phone_model_reply_v1($pdo,1,7,$history,static fn()=>true);
$call=end($GLOBALS['gmailSummaryDispatch']);
$wire=json_encode($call['payload'],JSON_THROW_ON_ERROR);
if (count($GLOBALS['gmailSummaryDispatch'])!==$before+1 || $answer===''
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
