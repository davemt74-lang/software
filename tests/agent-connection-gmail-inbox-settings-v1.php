<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';

final class InboxSettingsFixturePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $key, mixed $default = ''): mixed { return $default; }
function table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && ($permission === 'account.access' || !empty($user['chat_allowed']));
}

function inbox_settings_check(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}

function inbox_settings_reject(int $status, callable $operation, string $message): void
{
    try { $operation(); }
    catch (AgentConnectionExceptionV1 $error) {
        inbox_settings_check($error->getCode() === $status,$message);
        return;
    }
    throw new RuntimeException($message);
}

$checks = 0;
$root = sys_get_temp_dir().'/vp3-inbox-settings-'.bin2hex(random_bytes(8));
mkdir($root,0700);
define('STONEFELLOW_ROOT',$root);
putenv('VP3_GMAIL_CLIENT_ID=vp3-inbox-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=vp3-inbox-fixture-secret');

try {
    $pdo = new InboxSettingsFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("PRAGMA foreign_keys=ON;
        CREATE TABLE users (id INTEGER PRIMARY KEY,is_active INTEGER,chat_allowed INTEGER);
        INSERT INTO users VALUES (1,1,1),(2,1,1);
        CREATE TABLE settings (setting_key TEXT PRIMARY KEY,setting_value TEXT);
        CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,
            authorization_key TEXT,client_fingerprint TEXT,account_email TEXT,read_enabled INTEGER,access_expires_at INTEGER,
            access_token_enc TEXT,refresh_token_enc TEXT);
        CREATE TABLE agent_connection_sends_v1 (operation_key TEXT);
        CREATE TABLE agent_connection_email_drafts_v1 (authorization_key TEXT,message_enc TEXT);
        CREATE TABLE agent_connection_email_followups_v1 (claim_key TEXT);
        INSERT INTO agent_connection_email_followups_v1 VALUES ('fixture-explicit-watch');
        CREATE TABLE notifications (user_id INTEGER,source_type TEXT,type TEXT);");
    agent_connection_gmail_inbox_revoke_v1($pdo,1,7,str_repeat('a',64));
    inbox_settings_check(!agent_connection_gmail_inbox_ready_v1($pdo),'pre-upgrade Inbox Review storage fails closed');
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_gmail_inbox_install_v1($pdo);
    inbox_settings_reject(503,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,[],[],
        static fn()=>throw new RuntimeException('Partial installation contacted Gmail.')),
        'missing receipt storage prevents any Gmail request');
    agent_connection_gmail_inbox_receipts_install_v1($pdo);
    $grant = str_repeat('a',64);
    $token = ai_encrypt_secret('fixture-only-token');
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (7,1,'gmail','connected',?,?,?,1,?,?,'')")
        ->execute([$grant,agent_connections_client_fingerprint_v1(),'owner@example.test',time()+3600,$token]);
    $user = ['id'=>1,'is_active'=>1,'chat_allowed'=>1];
    $other = ['id'=>2,'is_active'=>1,'chat_allowed'=>1];
    $saved = static fn(): ?array => agent_connection_gmail_inbox_policy_v1($pdo,1);
    $base = ['connection_id'=>'7','connection_revision'=>hash('sha256',$grant),'inbox_revision'=>'','action'=>'save'];
    $input = ['ignore_senders'=>"News@Example.com\r\nnews@example.com",'ignore_domains'=>"Example.net\n",'ignore_subjects'=>"Réunion [QA]\nréunion [qa]",
        'ignore_github_actions'=>'1'];
    $rules = agent_connection_gmail_inbox_rules_v1($input);
    inbox_settings_check($rules === ['github_actions'=>true,'senders'=>['news@example.com'],'domains'=>['example.net'],'subjects'=>['Réunion [QA]']],
        'exact rules normalize and deduplicate without converting phrases into patterns');
    foreach ([['ignore_domains'=>'*.example.net'],['ignore_senders'=>'from:(news@example.com)'],['ignore_subjects'=>str_repeat('x',161)],
        ['ignore_domains'=>array_fill(0,21,'example.net')],['ignore_subjects'=>"a\0b"],['ignore_github_actions'=>['1']]] as $invalid) {
        inbox_settings_reject(422,static fn()=>agent_connection_gmail_inbox_rules_v1($invalid),'invalid or oversized rule input is rejected');
    }
    $message = static fn(string $from,string $cc='',string $subject=''): array => compact('from','cc','subject');
    inbox_settings_check(agent_connection_gmail_inbox_ignored_v1($message('News <NEWS@example.com>'),$rules)
        && agent_connection_gmail_inbox_ignored_v1($message('orders@example.net'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message('orders@sub.example.net'),$rules)
        && agent_connection_gmail_inbox_ignored_v1($message('someone@other.test','','RE: RÉUNION [qa]'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message('someone@other.test','','réunion Q'),$rules),
        'sender/domain are exact and subject phrases match literally with Unicode case folding');
    $githubFrom = 'GitHub <notifications@github.com>';
    inbox_settings_check(agent_connection_gmail_inbox_ignored_v1($message($githubFrom,'"Team, Ops" <team@example.net>, "CI \\"Bot\\"" <ci_activity@noreply.github.com>'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message($githubFrom,'"ci_activity@noreply.github.com" <other@example.org>'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message('"notifications@github.com" <other@example.org>','ci_activity@noreply.github.com'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message('Group: '.$githubFrom.';','ci_activity@noreply.github.com'),$rules)
        && !agent_connection_gmail_inbox_ignored_v1($message('someone@else.test, '.$githubFrom,'ci_activity@noreply.github.com'),$rules),
        'GitHub Actions preset needs exact From and an actual Cc mailbox');

    inbox_settings_check($saved() === null,'a connected mailbox starts with Inbox Review off');
    $requests = 0;
    $profileEmail = 'wrong@example.test';
    $profileCursor = '18446744073709551615';
    $http = static function (string $method,string $url,array $headers,?array $body,bool $form) use (&$requests,&$profileEmail,&$profileCursor,$pdo): array {
        if ($method !== 'GET' || $body !== null || $form || $pdo->inTransaction()
            || $url !== VP3_GMAIL_API_V1.'/profile?fields=emailAddress,historyId'
            || !in_array('Authorization: Bearer fixture-only-token',$headers,true)) throw new RuntimeException('Unapproved fixture provider request.');
        $requests++;
        return ['status'=>200,'json'=>['emailAddress'=>$profileEmail,'historyId'=>$profileCursor]];
    };
    inbox_settings_reject(409,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,$other,$base+['enabled'=>'1'],$http),
        'another user cannot activate a mailbox with its connection and form revision');
    inbox_settings_reject(403,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,array_replace($user,['chat_allowed'=>0]),$base+['enabled'=>'1'],$http),
        'activation requires current Agent Chat access');
    inbox_settings_check(agent_connection_gmail_inbox_save_v1($pdo,$user,$base+$input,$http) === 'inbox_disabled'
        && $requests === 0 && !(int)$saved()['enabled'],'saving inactive rules reads no Gmail data');
    $base['inbox_revision'] = $saved()['revision'];
    inbox_settings_reject(401,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,$user,$base+$input+['enabled'=>'1'],$http),
        'profile identity mismatch leaves the opt-in off');
    $profileEmail = 'owner@example.test';
    $profileCursor = '001';
    inbox_settings_reject(502,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,$user,$base+$input+['enabled'=>'1'],$http),
        'noncanonical history cursor is never accepted');
    $profileCursor = '18446744073709551615';
    inbox_settings_check(agent_connection_gmail_inbox_save_v1($pdo,$user,$base+$input+['enabled'=>'1'],$http) === 'inbox_saved'
        && $requests === 3 && (int)$saved()['enabled'] === 1 && $saved()['history_id'] === $profileCursor
        && $saved()['last_status'] === 'waiting','activation verifies only Gmail profile outside SQL locks and preserves its full decimal cursor');
    inbox_settings_reject(409,static fn()=>agent_connection_gmail_inbox_save_v1($pdo,$user,$base+$input+['enabled'=>'1'],$http),
        'a stale Inbox Review form is rejected before contacting Gmail');

    $base['inbox_revision'] = $saved()['revision'];
    $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET pending_event_id='18446744073709551616',pending_offset=2,
        pending_fingerprint='prior',history_page_token='page',claim_key='worker',last_review_json='{}' WHERE owner_user_id=1");
    $pdo->exec("INSERT INTO agent_connection_gmail_inbox_receipts_v1 VALUES (1,'first'),(2,'second');
        INSERT INTO notifications VALUES (1,'agent_connection_gmail_inbox','agent_email_inbox_review'),
            (2,'agent_connection_gmail_inbox','agent_email_inbox_review'),(1,'watch','agent_email_sender_watch');");
    inbox_settings_check(agent_connection_gmail_inbox_save_v1($pdo,$user,$base+['enabled'=>'1','ignore_senders'=>'new@example.com'],$http) === 'inbox_saved'
        && $requests === 3 && $saved()['history_id'] === $profileCursor && $saved()['pending_event_id'] === '18446744073709551616'
        && (int)$saved()['pending_offset'] === 2 && $saved()['history_page_token'] === 'page' && $saved()['claim_key'] === null
        && $saved()['rules']['senders'] === ['new@example.com'],'editing rules preserves history progress and invalidates the active claim');

    $base['inbox_revision'] = $saved()['revision'];
    $profileCursor = '18446744073709551617';
    inbox_settings_check(agent_connection_gmail_inbox_save_v1($pdo,$user,array_replace($base,['action'=>'restart']),$http) === 'inbox_restarted'
        && $requests === 4 && $saved()['history_id'] === $profileCursor && $saved()['rules']['senders'] === ['new@example.com']
        && $saved()['pending_event_id'] === '' && $saved()['history_page_token'] === null && $saved()['last_review_json'] === null
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_receipts_v1')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_followups_v1')->fetchColumn() === 1,
        'restart baselines only the current owner and preserves rules and separate watches');

    $base['inbox_revision'] = $saved()['revision'];
    $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET last_review_json='{}',last_checked_at='2026-11-09 12:30:00',claim_key='worker'");
    inbox_settings_check(agent_connection_gmail_inbox_save_v1($pdo,$user,$base+['ignore_senders'=>'new@example.com'],$http) === 'inbox_disabled'
        && $requests === 4 && !(int)$saved()['enabled'] && $saved()['history_id'] === '' && $saved()['enabled_at_ms'] === null
        && $saved()['next_check_at'] === null && $saved()['last_checked_at'] === null && $saved()['last_review_json'] === null
        && $saved()['rules']['senders'] === ['new@example.com'],'disabling drops the review, claim and cursor while preserving owner rules');

    $revision = $saved()['revision'];
    agent_connection_gmail_inbox_revoke_v1($pdo,1,7,str_repeat('b',64));
    inbox_settings_check($saved()['revision'] === $revision,'a mismatched grant cannot revoke this review');
    agent_connection_gmail_inbox_revoke_v1($pdo,1,7,$grant);
    inbox_settings_check($saved()['revision'] !== $revision && $saved()['rules']['senders'] === ['new@example.com'],
        'revoking the exact Gmail grant invalidates its form and retains private rule preferences');
    echo 'Gmail Inbox Review settings: '.$checks.' checks passed'.PHP_EOL;
} finally {
    foreach (glob($root.'/private/*') ?: [] as $file) unlink($file);
    if (is_dir($root.'/private')) rmdir($root.'/private');
    rmdir($root);
}
