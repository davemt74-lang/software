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
require_once $root.'/includes/cognitive-runtime-v500.php';
require_once $root.'/includes/cognitive-presentation-v510.php';

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
function chat_settings_agent_voice_enabled_v237(PDO $pdo,array $user): bool { return $GLOBALS['fixtureAgentVoice']??true; }
function personal_capability_has_v242(string $capability,array $user): bool
{
    return in_array($capability,['personal_knowledge.access','personal_knowledge.manage'],true)
        && empty($GLOBALS['fixtureKnowledgeRevoked']);
}
function vp3_cognitive_attention_notification_row_signal_v2410(array $user,array $row,int $count): array { return []; }
function vp3_cognitive_attention_arbitrate_v2410(PDO $pdo,array $user,string $namespace,array $signal,array $context): array
{
    return ['voice'=>empty($GLOBALS['fixtureVoiceDeferred'])];
}
function vp3_cognitive_attention_mark_delivered_v2410(PDO $pdo,array $user,string $namespace,string $key): void {}

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
putenv('VP3_GMAIL_CLIENT_SECRET=fixture-'.bin2hex(random_bytes(16)));
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

    $youtubeFixtureKey = 'fixture-'.bin2hex(random_bytes(16));
    putenv('VP3_YOUTUBE_API_KEY='.$youtubeFixtureKey);
    $youtubeProbe = static function (string $url, array $headers) use ($youtubeFixtureKey): array {
        gmail_connection_check_v1(str_starts_with($url,VP3_YOUTUBE_HEALTH_ENDPOINT_V1.'?') && !str_contains($url,$youtubeFixtureKey)
            && in_array('X-Goog-Api-Key: '.$youtubeFixtureKey,$headers,true),
            'YouTube health uses a non-search endpoint and puts the credential in a server header');
        return ['status'=>200,'json'=>['items'=>[['snippet'=>['gl'=>'US']]]]];
    };
    $youtubeCheck = agent_connection_health_test_platform_v1($pdo,'youtube',$youtubeProbe);
    gmail_connection_check_v1($youtubeCheck['status'] === 'healthy' && agent_connection_health_platform_v1($pdo,'youtube') === $youtubeCheck,
        'YouTube records successful key verification without consuming search quota');
    putenv('VP3_YOUTUBE_API_KEY=fixture-'.bin2hex(random_bytes(16)));
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

    vp3_cognitive_presentation_ensure_schema_v510($pdo);
    $digestState = vp3_cognitive_presentation_state_row_v510($pdo,$owner,'agent:7');
    $otherDigestState = vp3_cognitive_presentation_state_row_v510($pdo,$other,'agent:9');
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
        && agent_connection_email_chat_plan_v1('Gmail search: first email review')['operation'] === 'search'
        && agent_connection_email_chat_plan_v1('/gmail review')['operation'] === 'review_status'
        && agent_connection_email_chat_plan_v1('Where is my latest Gmail inbox review?')['operation'] === 'review_status'
        && agent_connection_email_chat_plan_v1('Draft an email to buyer@example.test about the first Gmail review')['operation'] === 'draft'
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

    $digestState['last_meaningful_at'] = date('Y-m-d H:i:s',time()-45*60);
    gmail_connection_check_v1(vp3_cognitive_presentation_digest_v510($pdo,$owner,'agent:7',$digestState) === null,
        'Gmail watch notices follow the informational return window');
    $digestState['last_meaningful_at'] = date('Y-m-d H:i:s',time()-65*60);
    $gmailCallsBeforeDigest = [$threadCalls,$searchCalls];
    $awayDigest = vp3_cognitive_presentation_digest_v510($pdo,$owner,'agent:7',$digestState);
    $awayItems = $awayDigest['items'] ?? [];
    $awayJson = json_encode($awayItems,JSON_THROW_ON_ERROR);
    $otherDigestState['last_meaningful_at'] = $digestState['last_meaningful_at'];
    gmail_connection_check_v1(array_column($awayItems,'title') === ['New Gmail message','Gmail reply received']
        && array_column($awayItems,'type') === ['agent_email_followup','agent_email_followup']
        && array_column($awayItems,'source_id') === [(int)$senderWatch['reply_chat_message_id'],(int)$reply['reply_chat_message_id']]
        && array_column($awayItems,'target_url') === [
            '/chat.php?agent=7&conversation_id=11&message_id='.(int)$senderWatch['reply_chat_message_id'],
            '/chat.php?agent=7&conversation_id=11&message_id='.(int)$reply['reply_chat_message_id'],
        ]
        && !str_contains($awayJson,$sender) && !str_contains($awayJson,$buyer) && !str_contains($awayJson,'Fixture private')
        && !vp3_cognitive_presentation_voice_allowed_type_v510($bell)
        && vp3_cognitive_presentation_digest_v510($pdo,$other,'agent:9',$otherDigestState) === null
        && [$threadCalls,$searchCalls] === $gmailCallsBeforeDigest,
        'While you were away shows both verified Gmail watch notices as private links to the originating Chat');

    $inboxGrant = agent_connections_find_v1($pdo,1,$connectionId)['authorization_key'];
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms)
        VALUES (1,?,?,1,'{}',?,'910',?)")->execute([$connectionId,$inboxGrant,str_repeat('a',32),time()*1000]);
    $inboxPolicy = agent_connection_gmail_inbox_policy_v1($pdo,1);
    $inboxReview = ['at'=>gmdate(DATE_ATOM),'total'=>3,'important'=>2,'replies'=>1,'filtered'=>9,
        'items'=>[['from'=>'private-inbox@example.test','subject'=>'PRIVATE_INBOX_SUBJECT','body'=>'PRIVATE_INBOX_BODY']]];
    $noticeCursor = notification_latest_id($owner);
    $unreadBeforeReview = notification_unread_count($owner);
    $pdo->beginTransaction();
    agent_connection_gmail_inbox_notify_v1($pdo,1,$inboxReview,$inboxPolicy);
    $pdo->rollBack();
    gmail_connection_check_v1(notification_latest_id($owner) === $noticeCursor,
        'an Inbox Review notice rolls back with an uncommitted cursor');

    $pdo->beginTransaction();
    agent_connection_gmail_inbox_notify_v1($pdo,1,array_replace($inboxReview,['total'=>0]),$inboxPolicy);
    agent_connection_gmail_inbox_notify_v1($pdo,1,$inboxReview,$inboxPolicy);
    $pdo->commit();
    $pdo->beginTransaction();
    agent_connection_gmail_inbox_notify_v1($pdo,1,array_replace($inboxReview,['total'=>4,'replies'=>2]),$inboxPolicy);
    $pdo->commit();
    $inboxNotices = notification_agent_brain_activity_after($owner,$noticeCursor,50,7);
    $noticeJson = json_encode($inboxNotices,JSON_THROW_ON_ERROR);
    gmail_connection_check_v1(array_column($inboxNotices,'type') === ['agent_email_inbox_review','agent_email_inbox_review']
        && array_column($inboxNotices,'target_url') === array_fill(0,2,'/connections.php?app=gmail#gmail-inbox-review')
        && array_column($inboxNotices,'body') === ['Reviewed 3 new Gmail messages. 2 marked important, 1 reply.',
            'Reviewed 4 new Gmail messages. 2 marked important, 2 replies.']
        && !str_contains($noticeJson,'PRIVATE_INBOX') && !str_contains($noticeJson,'private-inbox@example.test')
        && !str_contains($noticeJson,$inboxGrant) && !notification_requires_attention($inboxNotices[0]+['requires_attention'=>1])
        && !vp3_cognitive_presentation_voice_allowed_type_v510($inboxNotices[0])
        && notification_unread_count($owner) === $unreadBeforeReview+2
        && notification_agent_brain_activity_after($other,$noticeCursor,50,9) === [],
        'Inbox Review sends only generic, silent counts and an owner-only link for each verified retained batch');

    vp3_cognitive_presentation_digest_ack_v510($pdo,$owner,'agent:7',$awayDigest['id'],'acknowledged');
    create_notification(1,'calendar_update','Calendar fixture','Calendar event is ready.','/calendar.php');
    $mixedDigest = vp3_cognitive_presentation_digest_v510($pdo,$owner,'agent:7',$digestState);
    $mixedItems = $mixedDigest['items'] ?? [];
    $groupedInbox = array_values(array_filter($mixedItems,'notification_is_gmail_inbox_review_v1'));
    $mixedVoice = vp3_cognitive_presentation_voice_candidate_v510($pdo,$owner,$digestState,$mixedDigest);
    gmail_connection_check_v1(count($groupedInbox) === 1 && $groupedInbox[0]['count'] === 2
        && $groupedInbox[0]['body'] === $inboxNotices[1]['body']
        && count(array_filter($mixedItems,static fn(array $item):bool=>$item['type']==='agent_email_followup')) === 2
        && $mixedVoice['kind'] === 'notification' && $mixedVoice['message'] === 'Calendar fixture. Calendar event is ready.',
        'the digest groups Inbox Review batches separately from watched mail and keeps Inbox Review out of mixed speech');

    $pdo->exec("INSERT INTO settings VALUES ('connections_gmail_enabled','0')");
    $pausedDigest = vp3_cognitive_presentation_open_digest_v510($pdo,$owner,'agent:7');
    gmail_connection_check_v1($pausedDigest['id'] === $mixedDigest['id']
        && array_column($pausedDigest['items'],'title') === ['Calendar fixture','New Gmail message','Gmail reply received']
        && !array_filter(notification_recent($owner,20),'notification_is_gmail_inbox_review_v1'),
        'a provider pause hides only Inbox Review counts from the already presented digest and Notifications');
    $pdo->exec("DELETE FROM settings WHERE setting_key='connections_gmail_enabled'");
    putenv('VP3_GMAIL_CLIENT_ID=reconfigured-fixture.apps.googleusercontent.com');
    try {
        gmail_connection_check_v1(!array_filter(notification_agent_brain_activity_after($owner,$noticeCursor,50,7),'notification_is_gmail_inbox_review_v1'),
            'a replaced OAuth client hides Inbox Review counts from Brain before any provider access');
    } finally {
        putenv('VP3_GMAIL_CLIENT_ID=vp3-fixture.apps.googleusercontent.com');
    }
    $reviewPrompt = 'i just ran the first email reveiw and there is absolutely nothng in the agent chat.';
    $noReviewProviderCalls = static fn() => throw new LogicException('A Chat status read contacted Gmail.');
    $readFirstReview = static fn(bool $voice=false) => agent_connection_email_chat_v1(
        $pdo,$owner,$principal,$reviewPrompt,11,0,null,$voice,$noReviewProviderCalls);
    gmail_connection_check_v1($readFirstReview()['tool_receipt']['status'] === 'no_matches',
        'the reported misspelled Gmail question resolves to first review status before a run exists');
    $firstScope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$owner);
    agent_connection_gmail_inbox_initial_start_v1($pdo,$owner,7,agent_connection_gmail_inbox_initial_start_token_v1($firstScope));
    gmail_connection_check_v1($readFirstReview()['answer'] === 'Your first Gmail review is queued.',
        'an explicit Chat question reports the actual queued state');
    $firstRow = agent_connection_gmail_inbox_initial_row_v1($pdo,$firstScope);
    $pdo->beginTransaction();
    agent_connection_gmail_inbox_initial_complete_v1($pdo,$firstScope,$firstRow,$inboxReview+['scanned'=>12,'truncated'=>false],
        ['status'=>'ready','summary'=>'The sampled subjects suggest scheduling.','tokens_charged'=>247]);
    $pdo->commit();
    $latestReview = array_replace($inboxReview,['at'=>gmdate('Y-m-d H:i:s'),'total'=>4]);
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET last_review_json=?,claim_key=?,next_check_at=? WHERE owner_user_id=1')
        ->execute([json_encode($latestReview,JSON_THROW_ON_ERROR),str_repeat('a',32),gmdate('Y-m-d H:i:s',time()+90)]);
    $plainStatus = agent_connection_gmail_inbox_initial_view_v1($pdo,$owner);
    $chatStatus = agent_connection_gmail_inbox_initial_view_v1($pdo,$owner,includeChatResult:true);
    $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
    $afterLease = agent_connection_gmail_inbox_initial_view_v1($pdo,$owner,includeChatResult:true);
    $itemLabels = [['from'=>'private-inbox@example.test','subject'=>'PRIVATE_INBOX_SUBJECT','category'=>'new']];
    gmail_connection_check_v1(!isset($plainStatus['chat_result']) && !isset($plainStatus['inbox_working_until'])
        && $chatStatus['chat_result'] === ['summary'=>'The sampled subjects suggest scheduling.',
            'summary_status'=>'ready','important'=>2,'replies'=>1,
            'items'=>$itemLabels,'latest'=>['total'=>4,'important'=>2,'replies'=>1,
                'at'=>str_replace(' ','T',$latestReview['at']).'Z','items'=>$itemLabels]]
        && isset($chatStatus['inbox_working_until']) && !isset($afterLease['inbox_working_until'])
        && !str_contains(json_encode($plainStatus,JSON_THROW_ON_ERROR),'PRIVATE_INBOX')
        && !str_contains(json_encode($chatStatus,JSON_THROW_ON_ERROR),'PRIVATE_INBOX_BODY'),
        'private Chat projects the saved summary and latest batch while only a live incremental lease shows as working');
    $firstTyped = $readFirstReview();
    $firstVoice = $readFirstReview(true);
    [$storedReview,$storedReviewContext] = agent_connection_email_chat_view_v1($firstTyped,$firstTyped['answer'],[]);
    $foreignReview = agent_connection_email_chat_v1($pdo,$other,$otherPrincipal,$reviewPrompt,11,0,null,false,$noReviewProviderCalls);
    gmail_connection_check_v1(str_starts_with($firstTyped['answer'],'Your 7-day first Gmail review is ready. It found 3 matching inbox messages.')
        && str_contains($firstTyped['answer'],'The sampled subjects suggest scheduling.')
        && str_contains($firstTyped['answer'],'latest saved inbox update reviewed 4 messages')
        && $firstTyped['tool_receipt']['operation'] === 'review_status' && $firstTyped['tool_receipt']['status'] === 'read_complete'
        && $firstVoice['answer'] === $firstTyped['answer'] && $firstVoice['actions'] === $firstTyped['actions']
        && !empty($firstVoice['email_private_read_v1'])
        && $firstTyped['actions'][0]['url'] === '/connections.php?app=gmail#gmail-inbox-review'
        && $storedReview === 'A private Gmail result was displayed for this turn.' && !empty($storedReviewContext['skip_brain_archive'])
        && !str_contains(json_encode([$firstTyped,$firstVoice,$foreignReview],JSON_THROW_ON_ERROR),'PRIVATE_INBOX')
        && $foreignReview['tool_receipt']['status'] === 'blocked',
        'typed and spoken Chat read the saved first review without provider calls or private mail disclosure');

    $pdo->beginTransaction();
    agent_connection_gmail_inbox_revoke_v1($pdo,1,$connectionId,$inboxGrant);
    $pdo->commit();
    $revokedDigest = vp3_cognitive_presentation_open_digest_v510($pdo,$owner,'agent:7');
    gmail_connection_check_v1($revokedDigest['id'] === $mixedDigest['id']
        && array_column($revokedDigest['items'],'title') === ['Calendar fixture','New Gmail message','Gmail reply received']
        && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id=1 AND type='agent_email_inbox_review'")->fetchColumn() === 0
        && [$threadCalls,$searchCalls] === $gmailCallsBeforeDigest,
        'revocation purges Inbox Review while preserving watched mail, other digest items and read-only provider boundaries');
    gmail_connection_check_v1($readFirstReview()['tool_receipt']['status'] === 'blocked'
        && agent_connection_gmail_inbox_initial_row_v1($pdo,$firstScope) === null,
        'Chat cannot recall the first review after inbox access is revoked');
    $pdo->exec('DELETE FROM agent_connection_gmail_inbox_v1 WHERE owner_user_id=1');

    $pdo->exec('CREATE TABLE artist_transcript_folders_v177 (id BIGINT PRIMARY KEY,created_by_user_id INT NOT NULL,folder_name VARCHAR(80) NOT NULL)');
    $pdo->exec("INSERT INTO artist_transcript_folders_v177 VALUES (61,1,'Private workflow notes'),(62,2,'Other private workflow notes')");
    $pdo->prepare("INSERT INTO agent_connection_gmail_github_v1
        (owner_user_id,connection_id,authorization_key,folder_id,enabled,revision,history_id,enabled_at_ms)
        VALUES (1,?,?,61,1,?,'915',?)")->execute([$connectionId,$inboxGrant,str_repeat('b',32),time()*1000]);
    $githubPolicy = agent_connection_gmail_github_policy_v1($pdo,1);
    $githubCursor = notification_latest_id($owner);
    $pdo->beginTransaction();
    agent_connection_gmail_github_actions_notify_v1($pdo,1,$githubPolicy,1,0);
    $pdo->rollBack();
    gmail_connection_check_v1(agent_connection_gmail_github_actions_voice_offer_v1($pdo,$owner) === null
        && notification_latest_id($owner) === $githubCursor,'uncommitted GitHub workflow results have neither a notice nor speech');
    foreach ([[2,0],[0,1]] as [$successes,$failures]) {
        $pdo->beginTransaction();
        agent_connection_gmail_github_actions_notify_v1($pdo,1,$githubPolicy,$successes,$failures);
        $pdo->commit();
    }
    $githubOffer = agent_connection_gmail_github_actions_voice_offer_v1($pdo,$owner);
    $githubNotices = notification_agent_brain_activity_after($owner,$githubCursor,10,7);
    gmail_connection_check_v1(array_keys($githubOffer) === ['generation','through_id']
        && array_column($githubNotices,'target_url') === ['/knowledge.php?folder=61','/knowledge.php?folder=61']
        && array_column($githubNotices,'body') === ['GitHub Actions reports 2 runs succeeded.','GitHub Actions reports 1 run failed.']
        && agent_connection_gmail_github_actions_voice_offer_v1($pdo,$other) === null
        && !vp3_cognitive_presentation_voice_allowed_type_v510($githubNotices[0]),'owner notices link to private Knowledge and only offer speech metadata through Chat');
    $claimGithub = static fn(string $namespace='agent:7'):?array => agent_connection_gmail_github_actions_voice_claim_v1(
        $pdo,$owner,$namespace,$githubOffer['generation'],$githubOffer['through_id']);
    $GLOBALS['fixtureAgentVoice'] = false;
    gmail_connection_check_v1($claimGithub() === null,'Agent Voice disabled leaves GitHub updates silent');
    $GLOBALS['fixtureAgentVoice'] = true;
    $GLOBALS['fixtureVoiceDeferred'] = true;
    gmail_connection_check_v1($claimGithub() === null && agent_connection_gmail_github_actions_voice_offer_v1($pdo,$owner)===$githubOffer,
        'the canonical attention policy defers a GitHub batch without consuming it');
    unset($GLOBALS['fixtureVoiceDeferred']);
    $GLOBALS['fixtureKnowledgeRevoked'] = true;
    gmail_connection_check_v1($claimGithub() === null && notification_agent_brain_activity_after($owner,$githubCursor,10,7) === [],
        'live Knowledge permission is required for GitHub notices and voice claims');
    unset($GLOBALS['fixtureKnowledgeRevoked']);
    $spokenGithub = $claimGithub();
    gmail_connection_check_v1($spokenGithub === $githubOffer+['message'=>'GitHub Actions reports 1 run failed and 2 runs succeeded.']
        && $claimGithub('agent:8') === null && agent_connection_gmail_github_actions_voice_offer_v1($pdo,$owner) === null,
        'one owner-wide claim coalesces verified counts, puts failures first and cannot replay in another Agent');
    $pdo->beginTransaction();
    agent_connection_gmail_github_revoke_v1($pdo,1,$connectionId,$inboxGrant);
    $pdo->commit();
    gmail_connection_check_v1(notification_agent_brain_activity_after($owner,$githubCursor,10,7) === [],
        'revoking GitHub Workflow Review purges its generic notices');

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

    // Reuse the exact reviewed-send ledger and a controlled Gmail wire for thread replies.
    $newReplyTurn = static function(string $command, int $conversation=11) use ($pdo): int {
        $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,1,'user',?,'{}')")
            ->execute([$conversation,$command]);
        return (int)$pdo->lastInsertId();
    };
    $replySends = $replyMetadata = $replyFull = $replyReconciles = 0;
    $replyChanged = false;
    $lastThreadReply = [];
    $replyTransport = static function($method,$url,$headers,$body) use (&$replySends,&$replyMetadata,&$replyFull,&$replyReconciles,
        &$lastThreadReply,&$replyChanged,$buyer,$mailbox): array {
        if ($method === 'GET' && preg_match('~/messages/((?:manual|ai|auto|pending|revoked)_source)\?format=(metadata|full)~',$url,$match)) {
            $id = $match[1];
            $full = $match[2] === 'full';
            if ($full) $replyFull++; else $replyMetadata++;
            $mail = gmail_connection_fixture_message_v1($id,time()*1000,$buyer,$mailbox,'<'.$id.'@example.test>');
            $mail['threadId'] = $replyChanged ? 'unexpected_thread' : 'source_thread';
            if ($full) {
                $mail['payload']['mimeType'] = 'text/plain';
                $mail['payload']['body']['data'] = rtrim(strtr(base64_encode('PRIVATE_SOURCE '.str_repeat('context ',700)),'+/','-_'),'=');
            }
            return ['status'=>200,'json'=>$mail];
        }
        if ($method === 'GET' && str_starts_with($url,VP3_GMAIL_API_V1.'/messages?labelIds=SENT&')) {
            $replyReconciles++;
            return ['status'=>200,'json'=>['messages'=>[['id'=>'reply_recovered']]]];
        }
        if ($method === 'GET' && str_starts_with($url,VP3_GMAIL_API_V1.'/messages/reply_recovered?')) {
            $replyReconciles++;
            return ['status'=>200,'json'=>$lastThreadReply];
        }
        if ($method !== 'POST' || $url !== VP3_GMAIL_API_V1.'/messages/send' || ($body['threadId'] ?? '') !== 'source_thread') {
            throw new LogicException('Reply transport selected the wrong Gmail action.');
        }
        $mime = base64_decode(strtr($body['raw'],'-_','+/'),true);
        if (!is_string($mime) || !str_contains($mime,"\r\nTo: $buyer\r\n") || !str_contains($mime,"\r\nSubject: Fixture private reply subject\r\n")
            || !preg_match('/\r\nIn-Reply-To: <((?:manual|ai|auto|pending)_source)@example\.test>\r\n/',$mime,$parent)
            || !str_contains($mime,"\r\nReferences: <".$parent[1]."@example.test>\r\n")
            || !preg_match('/\r\nMessage-ID: (<[^>]+>)\r\n/',$mime,$rfc)) {
            throw new LogicException('Gmail did not receive the exact approved recipient and original thread headers.');
        }
        $replySends++;
        if (in_array($parent[1],['auto_source','pending_source'],true)) {
            $lastThreadReply = gmail_connection_fixture_message_v1('reply_recovered',time()*1000,$mailbox,$buyer,$rfc[1],'',['SENT']);
            $lastThreadReply['threadId'] = 'source_thread';
            return ['status'=>503,'json'=>[]];
        }
        return ['status'=>200,'json'=>['id'=>'manual_reply_sent','threadId'=>'source_thread']];
    };
    $replyModel = static function(string $data,array $service,array $control): array {
        $input = json_decode($data,true,8,JSON_THROW_ON_ERROR);
        if ($service['service'] !== 'conversation' || $service['stage'] !== 'vp3.gmail-reply-draft.v1'
            || $control['protocol'] !== 'vp3.gmail-reply-draft.v1' || strlen($input['original_message']['excerpt']) > 4000
            || !str_starts_with($input['original_message']['excerpt'],'PRIVATE_SOURCE')) {
            throw new LogicException('Gmail reply input was not isolated, attributed and bounded.');
        }
        if (($control['before_gmail_reply_dispatch'])() !== true) return ['ok'=>false];
        $GLOBALS['fixtureReplyModelCalls'] = ($GLOBALS['fixtureReplyModelCalls'] ?? 0)+1;
        if (isset($GLOBALS['fixtureReplyAfterModel'])) ($GLOBALS['fixtureReplyAfterModel'])();
        return ['ok'=>true,'answer'=>json_encode(['body'=>'Thanks for the update.'],JSON_THROW_ON_ERROR)];
    };
    $chatReply = static function(string $command, int $conversation=11, ?int $turn=null, bool $voice=false)
        use ($pdo,$owner,$principal,$newReplyTurn,$replyTransport,$replyModel): array {
        return agent_connection_email_chat_v1($pdo,$owner,$principal,$command,$conversation,$turn ?? $newReplyTurn($command,$conversation),
            null,$voice,$replyTransport,$replyModel);
    };
    $manualCommand = 'Reply to Gmail message manual_source';
    $manualReply = $chatReply($manualCommand);
    $manualId = $manualReply['actions'][0]['id'] ?? '';
    $manualEditor = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'review','draft_id'=>$manualId],$key,$replyTransport);
    gmail_connection_check_v1($manualReply['tool_receipt']['status'] === 'clarification_required' && $manualEditor['is_reply'] === true
        && $manualEditor['to'] === $buyer && $manualEditor['subject'] === 'Fixture private reply subject' && $manualEditor['body'] === ''
        && !isset($manualEditor['reply']) && !isset($manualEditor['thread_id']) && $replySends === 0 && $replyMetadata === 1 && $replyFull === 0,
        'manual reply opens a scoped empty editor with exact recipient and original subject, using no model or send');
    $manualConfirm = ['action'=>'confirm','draft_id'=>$manualId,'review_token'=>$manualEditor['review_token'],'confirmed'=>true,'watch_replies'=>false];
    gmail_connection_reject_v1(static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$manualConfirm,$key,$replyTransport),422,
        'empty manual replies cannot be sent');
    $manualEdit = ['action'=>'revise','draft_id'=>$manualId,'review_token'=>$manualEditor['review_token'],'subject'=>'Wrong subject','body'=>'Exact reviewed text.'];
    gmail_connection_reject_v1(static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$manualEdit,$key,$replyTransport),422,
        'a user cannot accidentally break thread grouping by changing the subject');
    $manualEditor = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,
        array_replace($manualEdit,['subject'=>$manualEditor['subject']]),$key,$replyTransport);
    $manualConfirm['review_token'] = $manualEditor['review_token'];
    $replyChanged = true;
    gmail_connection_reject_v1(static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$manualConfirm,$key,$replyTransport),409,
        'the provider parent is reread and a changed thread blocks dispatch');
    $replyChanged = false;
    $manualSent = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$manualConfirm,$key,$replyTransport);
    gmail_connection_check_v1($manualSent['status'] === 'sent' && !$manualSent['watch_registered'] && $replySends === 1,
        'manual review sends only after approval and only to the original thread');

    $draftCommand = 'Draft reply to Gmail message ai_source: thank the sender';
    $draftTurn = $newReplyTurn($draftCommand);
    $draftReply = $chatReply($draftCommand,11,$draftTurn,true);
    $draftRetry = $chatReply($draftCommand,11,$draftTurn);
    $aiEditor = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'review','draft_id'=>$draftReply['actions'][0]['id']],$key,$replyTransport);
    gmail_connection_check_v1($draftReply['actions'] === $draftRetry['actions'] && $GLOBALS['fixtureReplyModelCalls'] === 1 && $replyFull === 1
        && $aiEditor['body'] === 'Thanks for the update.' && $replySends === 1 && !str_contains(json_encode($draftReply),'Thanks for the update.'),
        'voice and typed retries reuse one metered private draft without sending or copying reply text into Chat');

    $autoGrant = agent_connections_record_v1(1,$connectionId)['authorization_key'];
    $autoItems = ['items'=>array_map(static fn(string $id): array => [
        'from'=>$buyer,'subject'=>'Fixture private reply subject','category'=>'new','message_id_enc'=>ai_encrypt_secret($id),
    ],['auto_source','revoked_source','pending_source'])];
    $seedReplyReport = $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms,last_review_json)
        VALUES (1,?,?,1,'{}',?,'998',?,?) ON DUPLICATE KEY UPDATE enabled=1,connection_id=VALUES(connection_id),
        authorization_key=VALUES(authorization_key),revision=VALUES(revision),history_id='998',enabled_at_ms=VALUES(enabled_at_ms),
        last_review_json=VALUES(last_review_json)");
    $seedReplyReport->execute([$connectionId,$autoGrant,bin2hex(random_bytes(16)),time()*1000,json_encode($autoItems,JSON_THROW_ON_ERROR)]);
    $autoScope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$owner);
    $autoRefs = agent_connection_gmail_inbox_latest_view_v1($pdo,$owner,$autoScope['policy']['revision'])['items'];
    $autoCommand = 'Auto reply to Gmail review item '.$autoRefs[0]['item_ref'];
    $blockedAuto = $chatReply($autoCommand);
    gmail_connection_check_v1($blockedAuto['tool_receipt']['status'] === 'blocked' && $replySends === 1 && $GLOBALS['fixtureReplyModelCalls'] === 1,
        'Auto Send disabled refuses before drafting or sending');
    agent_connection_gmail_autosend_save_v1($pdo,$owner,['connection_id'=>(string)$connectionId,'connection_revision'=>hash('sha256',$autoGrant),
        'autosend_revision'=>'','enabled'=>'1','addresses'=>$buyer]);
    $unknown = $chatReply($autoCommand);
    $sameAutoAcrossChats = $chatReply($autoCommand,12);
    $originalAuto = $chatReply($autoCommand);
    $autoNoticeId = $originalAuto['email_outcome_chat_message_id'] ?? 0;
    $autoNotice = $pdo->query('SELECT * FROM chat_messages WHERE id='.(int)$autoNoticeId)->fetch(PDO::FETCH_ASSOC);
    $autoContext = json_decode($autoNotice['context_json'] ?? '{}',true);
    $autoView = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,
        ['action'=>'review','draft_id'=>$originalAuto['actions'][0]['id']],$key,$replyTransport);
    gmail_connection_check_v1($unknown['tool_receipt']['reason'] === 'outcome_unverified' && !isset($unknown['email_outcome_chat_message_id'])
        && $sameAutoAcrossChats['tool_receipt']['status'] === 'read_complete' && !isset($sameAutoAcrossChats['email_outcome_chat_message_id'])
        && $sameAutoAcrossChats['actions'] === [] && $replySends === 2 && $replyReconciles === 2 && $GLOBALS['fixtureReplyModelCalls'] === 2
        && (int)($autoNotice['conversation_id'] ?? 0) === 11 && ($autoContext['email_outcome']['mode'] ?? '') === 'auto_reply'
        && ($autoContext['email_outcome']['watch_registered'] ?? false) === true && ($autoContext['skip_brain_archive'] ?? null) === false
        && $autoContext['actions'][0]['label'] === 'View sent reply' && $autoView['body'] === 'Thanks for the update.' && !isset($autoView['review_token'])
        && !str_contains($autoNotice['message'].$autoNotice['context_json'],'Thanks for the update.')
        && (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%\"mode\":\"auto_reply\"%'")->fetchColumn() === 1,
        'lost automatic acknowledgement reconciles once across Chats, then exposes the exact sent reply only in the original Chat');

    $revokedCommand = 'Auto reply to Gmail review item '.$autoRefs[1]['item_ref'];
    $GLOBALS['fixtureReplyAfterModel'] = static function() use ($pdo,$owner,$connectionId,$autoGrant,$buyer): void {
        $policy = agent_connection_gmail_autosend_settings_v1($pdo,$owner,$connectionId);
        agent_connection_gmail_autosend_save_v1($pdo,$owner,['connection_id'=>(string)$connectionId,'connection_revision'=>hash('sha256',$autoGrant),
            'autosend_revision'=>$policy['revision'],'addresses'=>$buyer]);
    };
    $revoked = $chatReply($revokedCommand);
    unset($GLOBALS['fixtureReplyAfterModel']);
    $revokedReplay = $chatReply($revokedCommand,12);
    gmail_connection_check_v1($revoked['tool_receipt']['status'] === 'blocked' && $revokedReplay['tool_receipt']['status'] === 'failed'
        && $replySends === 2 && $GLOBALS['fixtureReplyModelCalls'] === 3,
        'revoking Auto Send during the metered draft prevents the send and a replay does not draft again');
    $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='stopped',next_check_at=NULL WHERE draft_id=?")
        ->execute([$autoContext['email_outcome']['draft_id']]);
    $autoPolicy = agent_connection_gmail_autosend_settings_v1($pdo,$owner,$connectionId);
    agent_connection_gmail_autosend_save_v1($pdo,$owner,['connection_id'=>(string)$connectionId,'connection_revision'=>hash('sha256',$autoGrant),
        'autosend_revision'=>$autoPolicy['revision'],'enabled'=>'1','addresses'=>$buyer]);
    $pendingReply = $chatReply('Auto reply to Gmail review item '.$autoRefs[2]['item_ref']);
    gmail_connection_check_v1($pendingReply['tool_receipt']['reason'] === 'outcome_unverified' && $replySends === 3
        && $GLOBALS['fixtureReplyModelCalls'] === 4,'the second automatic reply stays pending when Gmail acknowledgement is lost');
    $pdo->exec('DELETE FROM agent_connection_gmail_inbox_v1 WHERE owner_user_id=1');

    $seedInboxReview = $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,enabled_at_ms,claim_key,next_check_at,last_status,last_review_json)
        VALUES (?,?,?,1,?,?,'991',?,? ,UTC_TIMESTAMP(),'ready',?)
        ON DUPLICATE KEY UPDATE authorization_key=VALUES(authorization_key),enabled=1,revision=VALUES(revision),history_id='991',
        enabled_at_ms=VALUES(enabled_at_ms),claim_key=VALUES(claim_key),next_check_at=UTC_TIMESTAMP(),last_review_json=VALUES(last_review_json)");
    $seedGithubReview = $pdo->prepare("INSERT INTO agent_connection_gmail_github_v1
        (owner_user_id,connection_id,authorization_key,folder_id,enabled,revision,history_id,enabled_at_ms,claim_key,next_check_at,last_status)
        VALUES (?,?,?,?,1,?,'992',?,?,UTC_TIMESTAMP(),'ready')
        ON DUPLICATE KEY UPDATE authorization_key=VALUES(authorization_key),enabled=1,revision=VALUES(revision),history_id='992',
            enabled_at_ms=VALUES(enabled_at_ms),claim_key=VALUES(claim_key),next_check_at=UTC_TIMESTAMP(),last_status='ready'");
    $startFixtureReview = static function(int $id, int $connection, string $key) use ($seedInboxReview,$seedGithubReview): void {
        $seedInboxReview->execute([$id,$connection,$key,json_encode(['senders'=>['workflow@example.test'],'domains'=>[],'subjects'=>[],'github_actions'=>true],JSON_THROW_ON_ERROR),
            bin2hex(random_bytes(16)),time()*1000,bin2hex(random_bytes(16)),json_encode(['items'=>[['subject'=>'PRIVATE_INBOX_FIXTURE']]],JSON_THROW_ON_ERROR)]);
        $seedGithubReview->execute([$id,$connection,$key,60+$id,bin2hex(random_bytes(16)),time()*1000,bin2hex(random_bytes(16))]);
    };
    $reviewCleared = static function(int $id) use ($pdo): bool {
        $row = agent_connection_gmail_inbox_policy_v1($pdo,$id);
        $github = agent_connection_gmail_github_policy_v1($pdo,$id);
        return is_array($row) && (int)$row['enabled'] === 0 && (string)$row['history_id'] === ''
            && (string)$row['claim_key'] === '' && $row['next_check_at'] === null
            && !str_contains((string)$row['last_review_json'],'PRIVATE_INBOX_FIXTURE')
            && str_contains((string)$row['rules_json'],'workflow@example.test')
            && is_array($github) && (int)$github['enabled'] === 0 && (string)$github['history_id'] === ''
            && (string)$github['claim_key'] === '' && $github['next_check_at'] === null;
    };
    $startFixtureReview(2,$otherId,$otherGrant);
    gmail_connection_check_v1(agent_connections_update_policy_v1(2,$otherId,['read'=>false,'send'=>false,'followups'=>false],$otherGrant)
        && agent_connections_update_policy_v1(2,$otherId,$otherSummary['permissions'],$otherGrant) && $reviewCleared(2),
        'Gmail read revocation clears both background readers even if read is immediately restored');
    $startFixtureReview(1,$connectionId,$saved['authorization_key']);
    agent_connections_store_gmail_v1(1,$token,$policy,$saved['authorization_key'],$grant);
    $newGrant = agent_connections_record_v1(1,$connectionId);
    gmail_connection_check_v1($newGrant['revision'] !== $summary['connection']['revision']
        && !agent_connections_update_policy_v1(1,$connectionId,['read'=>false,'send'=>false,'followups'=>false],$saved['authorization_key'])
        && !agent_connections_disconnect_v1(1,$connectionId,'gmail',$saved['authorization_key']),
        'a stale Connections page cannot change or unlink the replacement OAuth grant');
    gmail_connection_check_v1(agent_connection_email_followup_specialist_v1($pdo,1,7,11) === $idleSpecialist,
        'a replacement Gmail grant never inherits the previous watch or its rail presence');
    gmail_connection_check_v1($reviewCleared(1),'OAuth replacement retires both background readers’ cursors and claims');
    $seedReplyReport->execute([$connectionId,$newGrant['authorization_key'],bin2hex(random_bytes(16)),time()*1000,
        json_encode($autoItems,JSON_THROW_ON_ERROR)]);
    $replacementScope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$owner);
    $replacementRefs = agent_connection_gmail_inbox_latest_view_v1($pdo,$owner,$replacementScope['policy']['revision'])['items'];
    $beforeReplacement = [$replySends,$replyMetadata,$replyFull,$replyReconciles,$GLOBALS['fixtureReplyModelCalls']];
    $previousSent = $chatReply('Auto reply to Gmail review item '.$replacementRefs[0]['item_ref']);
    $previousPending = $chatReply('Auto reply to Gmail review item '.$replacementRefs[2]['item_ref']);
    $oldSentView = agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,
        ['action'=>'review','draft_id'=>$originalAuto['actions'][0]['id']],$key,$replyTransport);
    gmail_connection_check_v1($oldSentView['connection_changed'] === true && $oldSentView['body'] === ''
        && $oldSentView['subject'] === '' && $oldSentView['message_id'] === null,
        'a replaced grant leaves the historical send receipt but hides the previous private reply body');
    gmail_connection_check_v1($previousSent['tool_receipt']['status'] === 'read_complete' && $previousSent['actions'] === []
        && !isset($previousSent['email_outcome_chat_message_id']) && $previousPending['tool_receipt']['reason'] === 'outcome_unverified'
        && [$replySends,$replyMetadata,$replyFull,$replyReconciles,$GLOBALS['fixtureReplyModelCalls']] === $beforeReplacement,
        'reconnecting the same Gmail mailbox does not redraft, resend or expose an older-grant auto reply');
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(1,$connectionId,'read',
        static fn()=>throw new LogicException('A stale grant must not refresh a replacement mailbox.'),$saved['authorization_key']),409,
        'an exact grant read rejects a replaced authorization before provider access');
    $due();
    gmail_connection_check_v1(agent_connection_email_followup_sweep_v1($pdo,5,$poll) === ['processed'=>1,'delivered'=>0]
        && $threadCalls === 2, 'reconnecting does not carry old reply watches into the replacement grant');
    $startFixtureReview(1,$connectionId,$newGrant['authorization_key']);
    gmail_connection_check_v1(agent_connections_disconnect_v1(1,$connectionId,'gmail',$newGrant['authorization_key'])
        && agent_connections_summary_v1(1)['connection']['status'] === 'disconnected'
        && agent_connections_summary_v1(2)['connection']['status'] === 'connected' && $reviewCleared(1),
        'disconnect clears both background readers for the correct owner and preserves the other connection');
    $unlinked = $pdo->query('SELECT * FROM agent_connections_v1 WHERE owner_user_id=1')->fetch();
    gmail_connection_check_v1($unlinked['access_token_enc'] === '' && $unlinked['refresh_token_enc'] === ''
        && !hash_equals($newGrant['authorization_key'],$unlinked['authorization_key']),
        'disconnect destroys stored credentials and invalidates previous review grants');
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(1,$connectionId,'read'),401,
        'a disconnected account cannot be read by the Agent');
    $startFixtureReview(2,$otherId,$otherGrant);
    $pdo->prepare('UPDATE agent_connections_v1 SET access_expires_at=0 WHERE id=?')->execute([$otherId]);
    $revokedRefresh = static fn(): array => ['status'=>400,'json'=>['error'=>'invalid_grant']];
    gmail_connection_reject_v1(static fn()=>agent_connections_access_v1(2,$otherId,'read',$revokedRefresh,$otherGrant),401,
        'a confirmed Gmail refresh revocation closes access');
    gmail_connection_check_v1(agent_connections_summary_v1(2)['connection']['status'] === 'reauthorize' && $reviewCleared(2),
        'provider-side revocation clears both background readers before requiring reconnection');
    echo 'AGENT_CONNECTIONS_GMAIL=PASS ('.$gmail_connection_checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
