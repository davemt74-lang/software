<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}

function watchlist_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}

function watchlist_reject(int $code,callable $action,string $label): void
{
    try { $action(); }
    catch (DomainException $error) {
        watchlist_check($error->getCode() === $code,$label);
        return;
    }
    throw new RuntimeException($label);
}

$config = ['connections'=>['gmail'=>['client_id'=>'watchlist-fixture','client_secret'=>str_repeat('x',12)]]];
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY,is_active INTEGER);
    INSERT INTO users VALUES (1,1),(2,1);
    CREATE TABLE user_agents (id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER);
    INSERT INTO user_agents VALUES (7,1,1),(8,1,1),(9,2,1);
    CREATE TABLE chat_conversations (id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES (11,1,7),(12,1,7),(13,1,8),(21,2,9);
    CREATE TABLE settings (setting_key TEXT,setting_value TEXT);
    CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,
        authorization_key TEXT,client_fingerprint TEXT,account_email TEXT,read_enabled INTEGER,send_enabled INTEGER,followups_enabled INTEGER);
    CREATE TABLE agent_connection_sends_v1 (operation_key TEXT);
    CREATE TABLE agent_connection_email_drafts_v1 (authorization_key TEXT);");
agent_connection_email_followup_install_v1($pdo);
$grant = str_repeat('a',64);
$connection = $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (7,1,'gmail','connected',?,?,?,1,0,1)");
$connection->execute([$grant,agent_connections_client_fingerprint_v1(),'owner@example.test']);
$view = static fn(int $conversation=11): array => agent_connection_email_sender_watchlist_v1($pdo,1,7,$conversation);
$key = $view()['key'];
$sender = 'sender@example.test';
$set = static fn(bool $enabled,int $conversation=11,?string $reference=null): array =>
    agent_connection_email_sender_watchlist_v1($pdo,1,7,$conversation,$sender,$enabled,$reference ?? $key);

watchlist_check($view() === ['available'=>true,'senders'=>[],'key'=>$key]
    && preg_match('/\A[a-f0-9]{64}\z/D',$key) && $key !== $grant && $view(12)['key'] !== $key,
    'private watchlist projects a scoped reference without exposing the Gmail grant');
watchlist_check($set(true)['senders'] === [$sender] && $set(true)['senders'] === [$sender]
    && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_followups_v1')->fetchColumn() === 1,
    'repeated checking starts one exact sender watch without Gmail send permission');
$saved = $pdo->query('SELECT * FROM agent_connection_email_followups_v1')->fetch(PDO::FETCH_ASSOC);
watchlist_check($saved['origin_user_message_id'] === null && $saved['recipient_email'] === $sender
    && $saved['status'] === 'waiting' && (int)$saved['sender_after_ms'] > 0
    && strtotime($saved['expires_at'].' UTC') >= time()+29*86400,
    'checkbox watch uses the existing background worker queue and its 30-day expiry');
watchlist_reject(409,static fn()=>$set(true,12),'a checkbox reference cannot be replayed in another Chat');
watchlist_check($set(true,12,$view(12)['key'])['senders'] === [$sender], 'the same sender can be independently watched in another Chat');
watchlist_reject(404,static fn()=>agent_connection_email_sender_watchlist_v1($pdo,1,8,11),'a different Agent cannot read the private watchlist');
watchlist_reject(404,static fn()=>agent_connection_email_sender_watchlist_v1($pdo,2,7,11),'a different owner cannot read the private watchlist');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['owner'=>1,'participant_id'=>1];
watchlist_reject(404,static fn()=>$set(false),'participant mode cannot change the owner watchlist');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
watchlist_reject(422,static fn()=>agent_connection_email_sender_watchlist_v1($pdo,1,7,11,'from:example.test',true,$key),
    'Gmail query syntax cannot become a sender watch');

$pdo->exec("INSERT INTO agent_connection_email_followups_v1 SELECT owner_user_id,'thread-reply',connection_id,authorization_key,
    agent_id,conversation_id,origin_user_message_id,draft_id,account_email,recipient_email,'sent-mail','thread',status,claim_key,
    next_check_at,expires_at,reply_message_id,reply_chat_message_id,NULL FROM agent_connection_email_followups_v1 WHERE conversation_id=11");
watchlist_check($set(false)['senders'] === [] && $set(false)['senders'] === [] && $view(12)['senders'] === [$sender]
    && $pdo->query("SELECT status FROM agent_connection_email_followups_v1 WHERE operation_key='thread-reply'")->fetchColumn() === 'waiting',
    'unchecking is idempotent and stops neither another Chat nor a thread reply watch');
$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=0');
watchlist_check($view() === ['available'=>false,'senders'=>[]],'revoking followups hides the active watch controls');
watchlist_reject(409,static fn()=>$set(true),'revoking followups blocks stale checkbox actions');
$pdo->prepare('UPDATE agent_connections_v1 SET followups_enabled=1,authorization_key=?')->execute([str_repeat('b',64)]);
watchlist_reject(409,static fn()=>$set(true),'a reconnected grant rejects old checkbox actions');
watchlist_check($view()['senders'] === [],'an old grant cannot contribute watched addresses to the new mailbox');
