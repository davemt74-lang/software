<?php
declare(strict_types=1);

// Native MySQL with production connection, Chat review and worker code. The
// injected Gmail transport keeps this fixture independent of external accounts.
$root = dirname(__DIR__);
require_once $root.'/includes/agent-connections-v1.php';
require_once $root.'/includes/agent-connection-health-v1.php';
require_once $root.'/includes/agent-connection-email-chat-v1.php';
require_once $root.'/includes/agent-connection-email-followup-v1.php';
require_once $root.'/includes/agent-capability-chat-v1.php';
require_once $root.'/includes/agent-chat-boundary-v380.php';
require_once $root.'/includes/user-agent-system-v236.php';
require_once $root.'/includes/knowledge-retrieval-v162.php';
require_once $root.'/includes/participant-updates-v1.php';
require_once $root.'/includes/notifications.php';

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $key, mixed $fallback=''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function table_exists(string $table): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && in_array($permission, ['account.access','chat.access'], true);
}

function gmail_connection_check_v1(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['gmail_connection_checks']++;
    echo 'PASS '.$label.PHP_EOL;
}

function gmail_connection_reject_v1(callable $operation, int $status, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException|DomainException $error) {
        if ($error instanceof PDOException || (int)$error->getCode() !== $status) throw $error;
        gmail_connection_check_v1(true, $label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

function gmail_connection_fixture_schema_v1(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active INT NOT NULL)');
    $pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
    $pdo->exec("CREATE TABLE user_agents (id BIGINT UNSIGNED PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL,
        display_name VARCHAR(100) NOT NULL DEFAULT 'Fixture Agent', is_active INT NOT NULL)");
    $pdo->exec('INSERT INTO user_agents (id,owner_user_id,is_active) VALUES (7,1,1),(8,1,1),(9,2,1)');
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        user_agent_id BIGINT UNSIGNED NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,7),(12,1,7),(13,1,8),(21,2,9)');
    $pdo->exec('CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL, role VARCHAR(20), message TEXT, context_json TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type VARCHAR(50) NOT NULL, title VARCHAR(190) NOT NULL, body VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL,
        source_type VARCHAR(80) NOT NULL, source_id BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT NOT NULL DEFAULT 0, read_at DATETIME NULL)");
}

function gmail_connection_fixture_message_v1(string $id, int $time, string $from, string $to, string $rfcId,
    string $parent='', array $labels=['INBOX'], string $authentication=''): array
{
    $headers = ['From'=>$from,'To'=>$to,'Message-ID'=>$rfcId,'In-Reply-To'=>$parent,
        'Authentication-Results'=>$authentication,'Subject'=>'Fixture private reply subject'];
    return ['id'=>$id,'threadId'=>'fixture_thread','internalDate'=>(string)$time,'labelIds'=>$labels,
        'snippet'=>'Fixture private reply snippet','payload'=>['headers'=>array_map(
            static fn(string $name,string $value): array => ['name'=>$name,'value'=>$value], array_keys($headers), $headers
        )]];
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn, $login, $password, $options);
$database = 'vp3_connections_fixture_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot = sys_get_temp_dir().'/vp3-connections-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT', $fixtureRoot);
mkdir($fixtureRoot, 0700);
$gmail_connection_checks = 0;
putenv('VP3_GMAIL_CLIENT_ID=vp3-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=fixture-not-a-secret');
putenv('VP3_GMAIL_REDIRECT_URI=https://fixture.example.test/connection-oauth.php');

try {
    $fixtureDsn = preg_replace('/;dbname=[^;]+/', '', $dsn).';dbname='.$database;
    $pdo = new PDO($fixtureDsn, $login, $password, $options);
    $pdo->exec("SET time_zone='+00:00'");
    gmail_connection_fixture_schema_v1($pdo);
    foreach (['setup.php','upgrade.php'] as $entry) {
        $source = file_get_contents($root.'/'.$entry);
        gmail_connection_check_v1(substr_count($source, 'agent_connections_install_v1($pdo)') === 1,
            $entry.' invokes the shared Connections installer exactly once');
    }
    gmail_connection_check_v1(!agent_connections_schema_ready_v1($pdo), 'the historical schema reports an upgrade is required');
    agent_connections_install_v1($pdo);
    $tableNames = ['agent_connections_v1','agent_connection_sends_v1','agent_connection_email_drafts_v1','agent_connection_email_followups_v1'];
    gmail_connection_check_v1(agent_connections_schema_ready_v1($pdo) && !in_array(false,array_map('table_exists',$tableNames),true),
        'installation provides all four production tables');
    $pdo->exec('DROP TABLE agent_connection_email_followups_v1');
    gmail_connection_check_v1(!agent_connections_schema_ready_v1($pdo), 'partial installation cannot advertise a working Connections module');
    agent_connections_install_v1($pdo);
    $pdo->exec('ALTER TABLE agent_connection_email_followups_v1 DROP COLUMN sender_after_ms');
    gmail_connection_check_v1(agent_connections_schema_ready_v1($pdo) && !agent_connection_email_sender_watch_ready_v1($pdo),
        'legacy Gmail remains available while sender watches await Database Upgrade');
    agent_connections_install_v1($pdo);
    gmail_connection_check_v1(agent_connection_email_sender_watch_ready_v1($pdo), 'the shared installer upgrades legacy Gmail follow-up storage');

    $owner = ['id'=>1,'is_active'=>1];
    $other = ['id'=>2,'is_active'=>1];
    $principal = vp3_agent_chat_principal_v380($owner, vp3_agent_chat_resolve_agent_v380($pdo, $owner, 7));
    $otherPrincipal = vp3_agent_chat_principal_v380($other, vp3_agent_chat_resolve_agent_v380($pdo, $other, 9));
    $grant = agent_connections_client_fingerprint_v1();
    $mailbox = 'owner@example.test';
    $buyer = 'buyer@example.test';
    $token = ['external_account_id'=>'fixture-google-owner','account_email'=>$mailbox,'expires_in'=>3600,
        'access_token'=>'fixture-owner-access','refresh_token'=>'fixture-owner-refresh'];
    $policy = ['read'=>true,'send'=>true,'followups'=>true];
    $callback = agent_connections_callback_v1();
    $popupToken = array_replace($token, ['refresh_token'=>'']);
    gmail_connection_check_v1(agent_connections_gmail_popup_origin_v1() === 'https://fixture.example.test'
        && agent_connections_gmail_popup_origin_v1('https://Fixture.Example.Test:443/connection-oauth.php') === 'https://fixture.example.test'
        && agent_connections_gmail_popup_origin_v1('https://Fixture.Example.Test:8443/connection-oauth.php') === 'https://fixture.example.test:8443',
        'popup origin comes from the configured callback and matches browser normalization');
    gmail_connection_reject_v1(static fn()=>agent_connections_gmail_popup_origin_v1('https://fixture.example.test/not-a-callback.php'),503,
        'popup origin validation still requires the configured callback path');
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,'',$grant,$callback,true),428,
        'the first popup connection requires offline access');
    agent_connections_store_gmail_v1(1, $token, $policy, '', $grant, $callback, true);
    $beforePopup = agent_connections_find_v1($pdo,1,'gmail');
    $expectedKey = $beforePopup['authorization_key'];
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$grant,$callback),400,
        'the legacy redirect still requires a new refresh token');
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,
        array_replace($popupToken,['external_account_id'=>'fixture-switched-account']),$policy,$expectedKey,$grant,$callback,true),428,
        'a popup cannot reuse a refresh token for another Google subject');
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,
        array_replace($popupToken,['account_email'=>'other@example.test']),$policy,$expectedKey,$grant,$callback,true),428,
        'a popup cannot reuse a refresh token for another verified mailbox address');
    putenv('VP3_GMAIL_CLIENT_ID=alternate-fixture.apps.googleusercontent.com');
    try {
        $otherClient = agent_connections_client_fingerprint_v1();
        gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$otherClient,$callback,true),428,
            'a popup cannot reuse a refresh token for another OAuth client');
    } finally {
        putenv('VP3_GMAIL_CLIENT_ID=vp3-fixture.apps.googleusercontent.com');
    }
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$grant,
        'https://changed.example.test/connection-oauth.php',true),409,'a popup rechecks the exact callback inside the grant write');
    $pdo->prepare('UPDATE agent_connections_v1 SET refresh_token_enc=? WHERE id=?')->execute(['unreadable-fixture',$beforePopup['id']]);
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$grant,$callback,true),428,
        'a popup cannot reuse a refresh token that the installation cannot decrypt');
    $pdo->prepare('UPDATE agent_connections_v1 SET refresh_token_enc=? WHERE id=?')->execute([$beforePopup['refresh_token_enc'],$beforePopup['id']]);
    gmail_connection_check_v1(agent_connections_find_v1($pdo,1,'gmail')['authorization_key'] === $expectedKey,
        'rejected popup attempts preserve the current grant revision');
    agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$grant,$callback,true);
    $afterPopup = agent_connections_find_v1($pdo,1,'gmail');
    gmail_connection_check_v1($afterPopup['authorization_key'] !== $expectedKey
        && $afterPopup['access_token_enc'] !== $beforePopup['access_token_enc']
        && $afterPopup['refresh_token_enc'] === $beforePopup['refresh_token_enc'],
        'same-owner popup reconnect rotates the grant and safely preserves the existing encrypted refresh token');
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$popupToken,$policy,$expectedKey,$grant,$callback,true),409,
        'a stale popup cannot overwrite the latest grant revision');
    agent_connections_store_gmail_v1(2, array_replace($token,[
        'external_account_id'=>'fixture-google-other','account_email'=>'other@example.test',
        'access_token'=>'fixture-other-access','refresh_token'=>'fixture-other-refresh',
    ]), ['read'=>true,'send'=>false,'followups'=>false], '', $grant);
    $activationRows = $pdo->query("SELECT id,user_id,body FROM notifications WHERE source_type='agent_connection_activation' AND type='gmail_connected' ORDER BY id")->fetchAll();
    $pendingActivation = agent_connection_notice_after_v1($pdo,['id'=>1,'is_active'=>1],0)['items'];
    gmail_connection_check_v1(count($activationRows) === 3 && array_map('intval',array_column($activationRows,'user_id')) === [1,1,2]
        && count($pendingActivation) === 1 && (int)$activationRows[1]['id'] === $pendingActivation[0]['id']
        && !str_contains(json_encode($activationRows,JSON_THROW_ON_ERROR),$mailbox),
        'only committed owner-specific Gmail grants create a canonical notice, and reconnection supersedes pending old access');
    $summary = agent_connections_summary_v1(1);
    $connectionId = $summary['connection']['id'];
    $otherId = agent_connections_summary_v1(2)['connection']['id'];
    gmail_connection_check_v1($summary['provider_ready'] && $summary['connection']['permissions'] === $policy
        && $connectionId !== $otherId && $summary['connection']['account_email'] === $mailbox,
        'different owners have separate Gmail connections and capability policies');
    $saved = $pdo->query('SELECT * FROM agent_connections_v1 WHERE owner_user_id=1')->fetch();
    gmail_connection_check_v1(!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'fixture-owner-access')
        && !isset($summary['connection']['authorization_key']) && $saved['access_token_enc'] !== $token['access_token']
        && $saved['refresh_token_enc'] !== $token['refresh_token'] && ai_decrypt_secret($saved['access_token_enc']) === $token['access_token'],
        'public connection summaries omit credentials and real storage encrypts both tokens');
    gmail_connection_check_v1(agent_connections_record_v1(2,$connectionId) === null
        && !agent_connections_disconnect_v1(2,$connectionId), 'another owner cannot look up or disconnect the connection by ID');
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(2,$connectionId,'read'),404,'another owner cannot use the access grant');
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(2,$otherId,'send'),403,'connected mail reading grants no send permission');
    gmail_connection_reject_v1(static fn()=>agent_connections_store_gmail_v1(1,$token,$policy,'',$grant),409,
        'a stale OAuth callback cannot replace an existing grant');
    agent_connections_install_v1($pdo);
    gmail_connection_check_v1($pdo->query('SELECT * FROM agent_connections_v1 WHERE owner_user_id=1')->fetch() === $saved,
        'repeating installation preserves the exact granted account');

    gmail_connection_check_v1(!agent_connection_health_schema_ready_v1($pdo)
        && agent_connection_health_platform_v1($pdo,'gmail')['status'] === 'unavailable',
        'health reads tolerate a pending migration without affecting existing Connections');
    agent_connection_health_install_v1($pdo);
    agent_connection_health_install_v1($pdo);
    gmail_connection_check_v1(agent_connection_health_schema_ready_v1($pdo)
        && agent_connection_health_gmail_counts_v1($pdo) === ['healthy'=>0,'needs_attention'=>0,'unchecked'=>2,'available'=>true],
        'idempotent health migration starts both linked Gmail accounts unchecked');

    $platformCalls = 0;
    $setupProbe = static function (string $method, string $url, array $headers, ?array $body, bool $form) use (&$platformCalls): array {
        $platformCalls++;
        gmail_connection_check_v1($method === 'GET' && $url === 'https://accounts.google.com/.well-known/openid-configuration'
            && $headers === ['Accept: application/json'] && $body === null && !$form,
            'administrator Gmail setup probe sends no credentials or mailbox data');
        return ['status'=>200,'json'=>['issuer'=>'https://accounts.google.com',
            'authorization_endpoint'=>'https://accounts.google.com/o/oauth2/v2/auth','token_endpoint'=>'https://oauth2.googleapis.com/token']];
    };
    $gmailSetup = agent_connection_health_test_platform_v1($pdo,'gmail',$setupProbe);
    gmail_connection_check_v1($gmailSetup['status'] === 'setup_ready' && $gmailSetup['checked_at'] !== null
        && agent_connection_health_platform_v1($pdo,'gmail') === $gmailSetup,
        'Gmail setup reachability is persisted separately from verified mailbox health');
    gmail_connection_check_v1(agent_connection_health_test_platform_v1($pdo,'gmail',$setupProbe)['status'] === 'rate_limited'
        && $platformCalls === 1 && agent_connection_health_platform_v1($pdo,'gmail') === $gmailSetup,
        'test cooldown skips remote calls and preserves the most recent completed check');

    $revision = $summary['connection']['revision'];
    $profileProbe = static function (string $method, string $url, array $headers) use ($mailbox): array {
        gmail_connection_check_v1($method === 'GET' && $url === VP3_GMAIL_API_V1.'/profile?fields=emailAddress'
            && $headers === ['Accept: application/json','Authorization: Bearer fixture-owner-access'],
            'member check verifies the mailbox through the profile endpoint only');
        return ['status'=>200,'json'=>['emailAddress'=>$mailbox]];
    };
    $unexpectedProbe = static function (): array { throw new LogicException('Unauthorized health probe attempted network access.'); };
    gmail_connection_reject_v1(static fn()=>agent_connection_health_test_member_v1($pdo,2,$connectionId,$revision,$unexpectedProbe),409,
        'health checks reject another owner before network access');
    gmail_connection_reject_v1(static fn()=>agent_connection_health_test_member_v1($pdo,1,$connectionId,str_repeat('0',64),$unexpectedProbe),409,
        'health checks reject stale grant revisions before network access');
    $mailboxCheck = agent_connection_health_test_member_v1($pdo,1,$connectionId,$revision,$profileProbe);
    gmail_connection_check_v1($mailboxCheck['status'] === 'healthy'
        && agent_connection_health_member_v1($pdo,1,$connectionId) === $mailboxCheck
        && agent_connection_health_member_v1($pdo,2,$connectionId)['status'] === 'unchecked',
        'only the connection owner can retrieve a verified mailbox check');
    $otherSummary = agent_connections_summary_v1(2)['connection'];
    $otherGrant = agent_connections_record_v1(2,$otherId)['authorization_key'];
    agent_connections_update_policy_v1(2,$otherId,['read'=>false,'send'=>false,'followups'=>false],$otherGrant);
    $failedProfile = static function (string $method, string $url, array $headers): array {
        gmail_connection_check_v1($headers === ['Accept: application/json','Authorization: Bearer fixture-other-access'],
            'owner can explicitly test their own grant with Agent mailbox reading disabled');
        return ['status'=>403,'json'=>['error'=>['message'=>'private provider error']]];
    };
    $failedCheck = agent_connection_health_test_member_v1($pdo,2,$otherId,$otherSummary['revision'],$failedProfile);
    agent_connections_update_policy_v1(2,$otherId,$otherSummary['permissions'],$otherGrant);
    gmail_connection_check_v1($failedCheck['status'] === 'needs_attention' && !str_contains($failedCheck['message'],'private provider error')
        && agent_connection_health_gmail_counts_v1($pdo) === ['healthy'=>1,'needs_attention'=>1,'unchecked'=>0,'available'=>true],
        'health counts reflect current checked grants without exposing a provider error body');

    putenv('VP3_YOUTUBE_API_KEY=AIza_fixture_key_0123456789abcdef');
    $youtubeProbe = static function (string $url, array $headers): array {
        gmail_connection_check_v1(str_starts_with($url,VP3_YOUTUBE_HEALTH_ENDPOINT_V1.'?') && !str_contains($url,'AIza_fixture_key')
            && in_array('X-Goog-Api-Key: AIza_fixture_key_0123456789abcdef',$headers,true),
            'YouTube health uses a non-search endpoint and puts the credential in a server header');
        return ['status'=>200,'json'=>['items'=>[['snippet'=>['gl'=>'US']]]]];
    };
    $youtubeCheck = agent_connection_health_test_platform_v1($pdo,'youtube',$youtubeProbe);
    gmail_connection_check_v1($youtubeCheck['status'] === 'healthy' && agent_connection_health_platform_v1($pdo,'youtube') === $youtubeCheck,
        'YouTube records successful key verification without consuming search quota');
    putenv('VP3_YOUTUBE_API_KEY=AIza_fixture_key_rotated_0123456789');
    gmail_connection_check_v1(agent_connection_health_platform_v1($pdo,'youtube')['status'] === 'unchecked',
        'a changed provider credential cannot inherit a previous key health result');
    putenv('VP3_YOUTUBE_API_KEY');

    $subject = 'Fixture order follow-up';
    $body = 'Can you confirm the order for Tuesday?';
    $prompt = "Draft an email to {$buyer}\nSubject: {$subject}\nBody: {$body}";
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (11,1,'user',?)")->execute([$prompt]);
    $originId = (int)$pdo->lastInsertId();
    $planned = agent_connection_email_chat_v1($pdo,$owner,$principal,$prompt,11,$originId);
    $draftId = $planned['actions'][0]['id']??'';
    gmail_connection_check_v1(preg_match('/\A[a-f0-9]{32}\z/D',$draftId) === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn() === 0
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_followups_v1')->fetchColumn() === 0,
        'Agent Chat prepares a private draft and performs no provider send or background watch');
    $idleSpecialist = ['state'=>'idle','origin_message_id'=>null];
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === $idleSpecialist,
        'a connected mailbox and a draft awaiting approval do not create an Email Specialist');
    $key = str_repeat('c',64);
    $reviewInput = ['action'=>'review','draft_id'=>$draftId];
    $review = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$reviewInput,$key);
    gmail_connection_check_v1($review['status'] === 'prepared' && $review['from'] === $mailbox && $review['to'] === $buyer
        && $review['subject'] === $subject && $review['body'] === $body && isset($review['watch_review_token']),
        'the review shows the exact sender, recipient, wording, and optional reply watch');
    $draftRow = $pdo->query('SELECT message_enc FROM agent_connection_email_drafts_v1')->fetchColumn();
    gmail_connection_check_v1(is_string($draftRow) && !str_contains($draftRow,$body), 'review drafts store the message encrypted');
    gmail_connection_reject_v1(static fn()=>agent_connection_email_chat_action_v1($pdo,$other,$otherPrincipal,21,$reviewInput,$key),404,
        'another owner cannot open the Gmail draft');
    gmail_connection_reject_v1(static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,$reviewInput,$key),404,
        'the same owner and Agent cannot use a different Chat to review this draft');

    $sendCalls = 0;
    $outgoingRfcId = '';
    $transport = static function(string $method,string $url,array $headers,?array $payload,bool $form)
        use (&$sendCalls,&$outgoingRfcId,$mailbox,$buyer,$subject,$body): array {
        $sendCalls++;
        if ($method !== 'POST' || $url !== VP3_GMAIL_API_V1.'/messages/send' || $form
            || !in_array('Authorization: Bearer fixture-owner-access',$headers,true)) {
            throw new LogicException('Gmail send escaped the expected mailbox or endpoint.');
        }
        $mime = base64_decode(strtr($payload['raw']??'', '-_', '+/'),true);
        [$header,$encoded] = array_pad(explode("\r\n\r\n",(string)$mime,2),2,'');
        if (!str_contains($header,"From: {$mailbox}\r\nTo: {$buyer}\r\nSubject: {$subject}\r\n")
            || base64_decode($encoded,true) !== $body || !preg_match('/Message-ID: (<[^<>]+>)/',$header,$match)) {
            throw new LogicException('Gmail did not receive the exact reviewed draft.');
        }
        $outgoingRfcId = $match[1];
        return ['status'=>200,'json'=>['id'=>'fixture_sent','threadId'=>'fixture_thread','labelIds'=>['SENT']]];
    };
    $confirm = ['action'=>'confirm','draft_id'=>$draftId,'confirmed'=>true,
        'review_token'=>$review['watch_review_token'],'watch_replies'=>true];
    $run = static fn(array $input,?string $sessionKey=null)=>agent_connection_email_chat_action_v1(
        $pdo,$owner,$principal,11,$input,$sessionKey??$key,$transport);
    gmail_connection_reject_v1(static fn()=>$run(array_replace($confirm,['confirmed'=>false])),422,'a review token alone cannot send');
    gmail_connection_reject_v1(static fn()=>$run($confirm,str_repeat('d',64)),409,'approval cannot be replayed from another session');
    gmail_connection_reject_v1(static fn()=>$run(array_replace($confirm,['watch_replies'=>false])),409,'approval binds the reviewed watch choice');
    $edit = $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?');
    $edit->execute([ai_encrypt_secret(json_encode(['subject'=>$subject,'body'=>'Changed content'],JSON_THROW_ON_ERROR)),$draftId]);
    gmail_connection_reject_v1(static fn()=>$run($confirm),409,'approval cannot send a changed draft');
    $edit->execute([$draftRow,$draftId]);
    gmail_connection_check_v1($sendCalls === 0, 'invalid approvals never invoke Gmail transport');

    $sent = $run($confirm);
    $verified = $pdo->query('SELECT * FROM agent_connection_sends_v1')->fetch();
    $watch = $pdo->query('SELECT * FROM agent_connection_email_followups_v1')->fetch();
    gmail_connection_check_v1($sent['status'] === 'sent' && $sent['message_id'] === 'fixture_sent' && $sent['watch_registered']
        && $verified['provider_thread_id'] === 'fixture_thread' && $watch['sent_message_id'] === 'fixture_sent'
        && (int)$watch['origin_user_message_id'] === $originId && $watch['draft_id'] === $draftId && $sendCalls === 1,
        'explicit approval commits the Gmail receipt and a watch tied to the originating Chat request');
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === ['state'=>'watching','origin_message_id'=>$originId]
        && agent_connection_email_followup_specialist_v1($pdo,1,7,12) === $idleSpecialist
        && agent_connection_email_followup_specialist_v1($pdo,2,9,21) === $idleSpecialist,
        'the Email Specialist reports real work in the originating Chat and omits Gmail contents and addresses');
    gmail_connection_reject_v1(static fn()=>agent_connection_email_followup_specialist_v1($pdo,1,8,11),404,
        'an alternate Agent cannot inspect the originating Chat specialist status');
    gmail_connection_reject_v1(static fn()=>agent_connection_email_followup_specialist_v1($pdo,2,9,11),404,
        'another owner cannot inspect the originating Chat specialist status');
    $pdo->exec("INSERT INTO settings VALUES ('connections_gmail_enabled','0')");
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === ['state'=>'paused','origin_message_id'=>$originId],
        'a site-wide Gmail pause is reflected for a current watch');
    $pdo->exec("DELETE FROM settings WHERE setting_key='connections_gmail_enabled'");
    $setWatchExpiration = $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET expires_at=? WHERE owner_user_id=1 AND operation_key=?');
    $setWatchExpiration->execute(['2000-01-01 00:00:00',$watch['operation_key']]);
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === $idleSpecialist,
        'an expired watch retires the Email Specialist before the worker sweeps');
    $setWatchExpiration->execute([$watch['expires_at'],$watch['operation_key']]);
    gmail_connection_check_v1($run($confirm)['status'] === 'sent' && $sendCalls === 1
        && (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='assistant'")->fetchColumn() === 1,
        'replaying approval returns the verified receipt without sending or notifying twice');

    $now = time()*1000;
    $messages = [
        gmail_connection_fixture_message_v1('fixture_sent',$now,$mailbox,$buyer,$outgoingRfcId,'',['SENT']),
        gmail_connection_fixture_message_v1('historical',$now-1,$buyer,$mailbox,'<old@example.test>',$outgoingRfcId),
        gmail_connection_fixture_message_v1('unrelated',$now+1,$buyer,$mailbox,'<unrelated@example.test>','<other@example.test>'),
        gmail_connection_fixture_message_v1('dmarc_fail',$now+2,$buyer,$mailbox,'<fake@example.test>',$outgoingRfcId,['INBOX'],
            'mx.google.com; dmarc=fail header.from=example.test'),
        gmail_connection_fixture_message_v1('wrong_sender',$now+3,'someone@example.test',$mailbox,'<someone@example.test>',$outgoingRfcId),
    ];
    $threadCalls = 0;
    $poll = static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$messages,&$threadCalls): array {
        $threadCalls++;
        if ($method !== 'GET' || !str_starts_with($url,VP3_GMAIL_API_V1.'/threads/fixture_thread?format=metadata')
            || $payload !== null || $form || !in_array('Authorization: Bearer fixture-owner-access',$headers,true)) {
            throw new LogicException('The worker used an unexpected Gmail capability or mailbox.');
        }
        return ['status'=>200,'json'=>['id'=>'fixture_thread','messages'=>$messages]];
    };
    $due = static function() use ($pdo): void {
        $pdo->exec("UPDATE agent_connection_email_followups_v1 SET next_check_at='2000-01-01 00:00:00' WHERE status='waiting'");
    };
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$poll) === ['processed'=>1,'delivered'=>0]
        && $threadCalls === 1, 'worker ignores older, unrelated, wrong-sender, and explicitly failed-authentication messages');
    $messages[] = gmail_connection_fixture_message_v1('fixture_reply',$now+4,$buyer,$mailbox,'<reply@example.test>',$outgoingRfcId);
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$poll) === ['processed'=>1,'delivered'=>1],
        'a background worker delivers a newly observed linked reply without a browser session');
    $reply = $pdo->query("SELECT * FROM agent_connection_email_followups_v1 WHERE status='delivered'")->fetch();
    $noticeQuery = $pdo->prepare('SELECT conversation_id,role,message,context_json FROM chat_messages WHERE id=?');
    $noticeQuery->execute([$reply['reply_chat_message_id']]);
    $notice = $noticeQuery->fetch();
    $context = json_decode($notice['context_json'],true,16,JSON_THROW_ON_ERROR);
    gmail_connection_check_v1((int)$notice['conversation_id'] === 11 && $notice['role'] === 'assistant'
        && $reply['reply_message_id'] === 'fixture_reply' && $context['email_followup']['draft_id'] === $draftId
        && $context['skip_brain_archive'] && !str_contains($notice['message'].$notice['context_json'],'Fixture private reply'),
        'reply delivery returns to the exact private Chat with source linkage and no inbox subject or snippet');
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === $idleSpecialist,
        'completed background work retires the Email Specialist');
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$poll) === ['processed'=>0,'delivered'=>0]
        && $threadCalls === 2, 'repeated worker sweeps neither re-read nor re-deliver a completed watch');

    $sender = 'updates@example.test';
    $senderPrompt = 'Notify me when I get an email from '.$sender;
    $stopPrompt = 'Stop watching Gmail for emails from '.$sender;
    $searchPrompt = 'Find inbound emails from '.strtoupper($sender).'.';
    $privateGmailTurn = static function(string $request,int $chat=11,int $user=1) use ($pdo): int {
        $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (?,?,'user',?)")->execute([$chat,$user,$request]);
        return (int)$pdo->lastInsertId();
    };
    $searchCalls = 0;
    $senderMessages = [];
    $search = static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$searchCalls,&$senderMessages,$sender): array {
        if ($method !== 'GET' || $payload !== null || $form || !in_array('Authorization: Bearer fixture-owner-access',$headers,true)) {
            throw new LogicException('Sender search did not use the correct private Gmail read grant.');
        }
        if (str_starts_with($url,VP3_GMAIL_API_V1.'/messages?')) {
            parse_str((string)parse_url($url,PHP_URL_QUERY),$filters);
            if ($filters['q'] === 'in:inbox') return ['status'=>200,'json'=>[]];
            if (!str_starts_with($filters['q'],'from:"'.$sender.'" -in:sent -in:drafts')) throw new LogicException('Incorrect exact sender query.');
            $searchCalls++;
            return ['status'=>200,'json'=>['messages'=>array_map(static fn(array $mail): array => ['id'=>$mail['id']],$senderMessages)]];
        }
        foreach ($senderMessages as $mail) {
            if (str_starts_with($url,VP3_GMAIL_API_V1.'/messages/'.$mail['id'].'?format=metadata')) return ['status'=>200,'json'=>$mail];
        }
        throw new LogicException('Sender search fetched a nonmatching provider endpoint.');
    };
    $senderMessages = [
        gmail_connection_fixture_message_v1('lookalike',$now,'updates2@example.test',$mailbox,'<similar@example.test>'),
        gmail_connection_fixture_message_v1('sent',$now,$sender,$mailbox,'<sent@example.test>','',['SENT']),
        gmail_connection_fixture_message_v1('existing',$now,'Sender <'.$sender.'>',$mailbox,'<existing@example.test>'),
    ];
    $readInbox = agent_connection_email_chat_v1($pdo,$owner,$principal,'Show my Gmail',11,0,null,false,$search);
    $readSender = agent_connection_email_chat_v1($pdo,$owner,$principal,$searchPrompt,11,0,null,false,$search);
    $voiceRead = agent_connection_email_chat_v1($pdo,$owner,$principal,$searchPrompt,11,0,null,true,$search);
    $ambiguousWatch = agent_connection_email_chat_v1($pdo,$owner,$principal,$senderPrompt.' and other@example.test',11,0);
    gmail_connection_check_v1($readInbox['tool_receipt']['status'] === 'no_matches' && $readInbox['email_private_read_v1']
        && $readSender['tool_receipt']['count'] === 1 && $readSender['email_private_read_v1'] && str_contains($readSender['answer'],'ID: existing')
        && $voiceRead['tool_receipt']['reason'] === 'scope_restricted' && $searchCalls === 1
        && agent_connection_email_chat_plan_v1('Find messages from Alex') === null
        && agent_connection_email_chat_plan_v1('Notify me when I get a message from Alex') === null
        && $ambiguousWatch['tool_receipt']['status'] === 'clarification_required',
        'Gmail routes inbox and exact sender queries, preserves private typed results, and asks for an unambiguous watch address');
    $watchSender = static fn(int $message, bool $voice=false): array => agent_connection_email_chat_v1($pdo,$owner,$principal,$senderPrompt,11,$message,null,$voice);
    $spoken = 'Notify me when a new email arrives from updates at example dot test.';
    $watchOrigin = $privateGmailTurn($spoken);
    $watchResult = agent_connection_email_chat_v1($pdo,$owner,$principal,$spoken,11,$watchOrigin,null,true);
    gmail_connection_check_v1($watchResult['tool_receipt']['status'] === 'completed' && !str_contains($watchResult['answer'],$sender)
        && agent_connection_email_chat_plan_v1($spoken)['sender'] === ''
        && agent_connection_email_chat_plan_v1($spoken.' and other at example dot test',true)['sender'] === ''
        && $watchSender($watchOrigin)['tool_receipt']['status'] === 'completed'
        && $watchSender($privateGmailTurn($senderPrompt))['tool_receipt']['status'] === 'completed'
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_followups_v1 WHERE sender_after_ms IS NOT NULL')->fetchColumn() === 1,
        'explicit typed and voice watches deduplicate per Chat and never speak the private sender address');
    $senderCutoff = (int)$pdo->query('SELECT sender_after_ms FROM agent_connection_email_followups_v1 WHERE origin_user_message_id='.$watchOrigin)->fetchColumn();
    $senderMessages[] = gmail_connection_fixture_message_v1('old_equal',$senderCutoff,$sender,$mailbox,'<old@example.test>');
    usleep(5000);
    $senderMessages[] = gmail_connection_fixture_message_v1('future_spoof',$senderCutoff+1,$sender,$mailbox,'<spoof@example.test>','',['INBOX'],
        'mx.google.com; dmarc=fail header.from=example.test');
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$search) === ['processed'=>1,'delivered'=>0],
        'the worker rejects historical and exact-cutoff mail and a newer failed-authentication sender match');
    $senderMessages[] = gmail_connection_fixture_message_v1('sender_new',$senderCutoff+2,$sender,$mailbox,'<new@example.test>');
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$search) === ['processed'=>1,'delivered'=>1],
        'the next new matching sender message completes the watch in the background');
    $senderWatch = $pdo->query('SELECT reply_message_id,reply_chat_message_id FROM agent_connection_email_followups_v1 WHERE origin_user_message_id='.$watchOrigin)->fetch();
    $noticeQuery->execute([$senderWatch['reply_chat_message_id']]);
    $senderNotice = $noticeQuery->fetch();
    $bell = $pdo->query("SELECT * FROM notifications WHERE source_type='agent_connection_email_followup' AND source_id=".(int)$senderWatch['reply_chat_message_id'])->fetch();
    gmail_connection_check_v1($senderWatch['reply_message_id'] === 'sender_new' && (int)$senderNotice['conversation_id'] === 11
        && str_contains($senderNotice['message'],$sender) && !str_contains($senderNotice['message'].$senderNotice['context_json'],'Fixture private')
        && json_decode($senderNotice['context_json'],true)['skip_brain_archive'] === true
        && (int)$bell['user_id'] === 1 && str_contains($bell['target_url'],'conversation_id=11') && !str_contains(json_encode($bell),$sender)
        && agent_connection_email_followup_sweep_v1($pdo,5,$search) === ['processed'=>0,'delivered'=>0]
        && $watchSender($watchOrigin)['tool_receipt']['status'] === 'no_matches',
        'one private Chat notice and one generic owner notification survive replay without exposing Gmail metadata');

    $nextWatch = $privateGmailTurn($senderPrompt);
    $watchSender($nextWatch);
    $stopElsewhere = agent_connection_email_chat_v1($pdo,$owner,$principal,$stopPrompt,12,$privateGmailTurn($stopPrompt,12),null,true);
    $stopHere = agent_connection_email_chat_v1($pdo,$owner,$principal,$stopPrompt,11,$privateGmailTurn($stopPrompt),null,true);
    gmail_connection_check_v1($stopElsewhere['tool_receipt']['status'] === 'no_matches' && $stopHere['tool_receipt']['status'] === 'completed'
        && agent_connection_email_followup_sweep_v1($pdo,5,$search) === ['processed'=>0,'delivered'=>0],
        'a stop request is restricted to the originating Agent Chat and prevents further polling');
    $watchSender($privateGmailTurn($senderPrompt));
    $authorization = agent_connections_find_v1($pdo,1,$connectionId)['authorization_key'];
    agent_connections_update_policy_v1(1,$connectionId,array_replace($policy,['followups'=>false]),$authorization);
    $blocked = $watchSender($privateGmailTurn($senderPrompt));
    agent_connections_update_policy_v1(1,$connectionId,$policy,$authorization);
    gmail_connection_check_v1($blocked['tool_receipt']['reason'] === 'setup_required'
        && agent_connection_email_followup_sweep_v1($pdo,5,$search) === ['processed'=>0,'delivered'=>0],
        'owner revocation blocks activation and cancels already scheduled watches even after permission is restored');

    $raceOrigin = $privateGmailTurn($senderPrompt);
    $watchSender($raceOrigin);
    $raceCutoff = (int)$pdo->query('SELECT sender_after_ms FROM agent_connection_email_followups_v1 WHERE origin_user_message_id='.$raceOrigin)->fetchColumn();
    usleep(5000);
    $senderMessages = [gmail_connection_fixture_message_v1('racing_message',$raceCutoff+1,$sender,$mailbox,'<racing@example.test>')];
    $cancelledInFlight = false;
    $race = static function(string $method,string $url,array $headers,?array $payload,bool $form)
        use ($pdo,$owner,$principal,$search,$stopPrompt,$privateGmailTurn,&$cancelledInFlight): array {
        if (str_contains($url,'/messages/racing_message?')) {
            $stopped = agent_connection_email_chat_v1($pdo,$owner,$principal,$stopPrompt,11,$privateGmailTurn($stopPrompt),null,true);
            $cancelledInFlight = $stopped['tool_receipt']['status'] === 'completed';
        }
        return $search($method,$url,$headers,$payload,$form);
    };
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$race) === ['processed'=>1,'delivered'=>0]
        && $cancelledInFlight, 'delivery rechecks the watch claim after a cancellation during the provider request');

    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (11,1,'user',?)")->execute([$prompt]);
    $secondOrigin = (int)$pdo->lastInsertId();
    $second = agent_connection_email_chat_v1($pdo,$owner,$principal,$prompt,11,$secondOrigin);
    $secondId = $second['actions'][0]['id'];
    $secondKey = agent_connection_email_chat_operation_key_v1($secondId);
    $secondReview = $run(['action'=>'review','draft_id'=>$secondId]);
    $revised = $run(['action'=>'revise','draft_id'=>$secondId,'review_token'=>$secondReview['review_token'],
        'subject'=>$subject,'body'=>'Please confirm your order.']);
    $secondConfirm = ['action'=>'confirm','draft_id'=>$secondId,'confirmed'=>true,
        'review_token'=>$revised['watch_review_token'],'watch_replies'=>true];
    gmail_connection_reject_v1(static fn()=>$run(array_replace($secondConfirm,[
        'review_token'=>$secondReview['watch_review_token'],
    ])),409,'editing a reviewed draft invalidates approval from the earlier revision');
    gmail_connection_reject_v1(static fn()=>agent_connections_send_v1(1,$connectionId,$buyer,$subject,$revised['body'],
        $secondKey,$saved['authorization_key'],null,$transport,static function(): never {
            throw new DomainException('Fixture rejected dispatch.',409);
        }),409,'a last-moment authority change blocks dispatch even after the durable claim');
    $claim = $pdo->prepare('SELECT status FROM agent_connection_sends_v1 WHERE operation_key=?');
    $claim->execute([$secondKey]);
    gmail_connection_check_v1($claim->fetchColumn() === false && $sendCalls === 1,
        'a definitely blocked dispatch releases the untouched claim for an authorized retry');
    $ambiguousPosts = 0;
    $lookupCalls = 0;
    $lostAck = static function(string $method,string $url,array $headers,?array $payload,bool $form)
        use (&$ambiguousPosts,&$lookupCalls,$mailbox,$buyer,$revised,$secondKey): array {
        if (!in_array('Authorization: Bearer fixture-owner-access',$headers,true)) {
            throw new LogicException('Reconciliation used a different mailbox.');
        }
        if ($method === 'POST' && $url === VP3_GMAIL_API_V1.'/messages/send') {
            $ambiguousPosts++;
            $mime = base64_decode(strtr($payload['raw']??'', '-_', '+/'),true);
            [, $encoded] = array_pad(explode("\r\n\r\n",(string)$mime,2),2,'');
            if (base64_decode($encoded,true) !== $revised['body']) throw new LogicException('Approved revision was not sent.');
            return ['status'=>503,'json'=>[]];
        }
        if ($method === 'GET' && str_starts_with($url,VP3_GMAIL_API_V1.'/messages?labelIds=SENT&')) {
            $lookupCalls++;
            return ['status'=>200,'json'=>['messages'=>[['id'=>'fixture_recovered']]]];
        }
        if ($method === 'GET' && str_starts_with($url,VP3_GMAIL_API_V1.'/messages/fixture_recovered?')) {
            $lookupCalls++;
            return ['status'=>200,'json'=>gmail_connection_fixture_message_v1('fixture_recovered',time()*1000,
                $mailbox,$buyer,agent_connection_gmail_rfc_message_id_v1($secondKey),'',['SENT'])];
        }
        throw new LogicException('Unexpected Gmail reconciliation request.');
    };
    $retrySend = static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$secondConfirm,$key,$lostAck);
    gmail_connection_reject_v1($retrySend,502,'a lost Gmail acknowledgement remains unconfirmed');
    $claim->execute([$secondKey]);
    gmail_connection_check_v1($claim->fetchColumn() === 'pending' && $ambiguousPosts === 1 && $lookupCalls === 0,
        'a potentially dispatched message keeps its durable claim');
    $recovered = $retrySend();
    gmail_connection_check_v1($recovered['status'] === 'sent' && $recovered['message_id'] === 'fixture_recovered'
        && $recovered['watch_registered'] && $ambiguousPosts === 1 && $lookupCalls === 2,
        'retry reconciles the exact Gmail Sent receipt and never sends a second POST');

    agent_connections_store_gmail_v1(1,$token,$policy,$saved['authorization_key'],$grant);
    $newGrant = agent_connections_record_v1(1,$connectionId);
    gmail_connection_check_v1($newGrant['revision'] !== $summary['connection']['revision']
        && !agent_connections_update_policy_v1(1,$connectionId,['read'=>false,'send'=>false,'followups'=>false],$saved['authorization_key'])
        && !agent_connections_disconnect_v1(1,$connectionId,'gmail',$saved['authorization_key']),
        'a stale Connections page cannot change or unlink the replacement OAuth grant');
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === $idleSpecialist,
        'a replacement Gmail grant never inherits the previous watch or its rail presence');
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$poll) === ['processed'=>1,'delivered'=>0]
        && $threadCalls === 2, 'reconnecting does not carry old reply watches into the replacement grant');
    gmail_connection_check_v1(agent_connections_disconnect_v1(1,$connectionId,'gmail',$newGrant['authorization_key'])
        && agent_connections_summary_v1(1)['connection']['status'] === 'disconnected'
        && agent_connections_summary_v1(2)['connection']['status'] === 'connected',
        'disconnect clears the correct owner grant and preserves the other connection');
    $unlinked = $pdo->query('SELECT * FROM agent_connections_v1 WHERE owner_user_id=1')->fetch();
    gmail_connection_check_v1($unlinked['access_token_enc'] === '' && $unlinked['refresh_token_enc'] === ''
        && !hash_equals($newGrant['authorization_key'],$unlinked['authorization_key']),
        'disconnect destroys stored credentials and invalidates previous review grants');
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(1,$connectionId,'read'),401,
        'a disconnected account cannot be read by the Agent');
    echo 'AGENT_CONNECTIONS_GMAIL=PASS ('.$gmail_connection_checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
