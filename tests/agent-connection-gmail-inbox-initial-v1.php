<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';

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
    return !empty($user['is_active']) && in_array($permission, ['account.access', 'chat.access'], true);
}
function first_review_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function first_review_message(string $id, int $received, string $from, string $subject, array $labels=['INBOX'], string $cc='', string $reply=''): array
{
    $headers = ['From'=>$from, 'Subject'=>$subject, 'Cc'=>$cc, 'In-Reply-To'=>$reply];
    return ['id'=>$id, 'internalDate'=>(string)$received, 'labelIds'=>$labels, 'snippet'=>'FIXTURE_SNIPPET_SENTINEL',
        'payload'=>['headers'=>array_map(static fn(string $name, string $value): array => ['name'=>$name, 'value'=>$value],
            array_keys($headers), $headers), 'body'=>['data'=>'FIXTURE_BODY_SENTINEL']]];
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn, $login, $password, $options);
$database = 'vp3_first_inbox_fixture_'.bin2hex(random_bytes(6));
$fixtureRoot = sys_get_temp_dir().'/vp3-first-inbox-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT', $fixtureRoot);
mkdir($fixtureRoot, 0700);
putenv('VP3_GMAIL_CLIENT_ID=vp3-first-inbox-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=first-inbox-fixture-only');
$checks = 0;

try {
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $fixtureDsn = preg_replace('/;dbname=[^;]+/', '', $dsn).';dbname='.$database;
    $pdo = new PDO($fixtureDsn, $login, $password, $options);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active TINYINT NOT NULL)');
    $pdo->exec('INSERT INTO users VALUES (1,1),(2,1),(3,1)');
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id BIGINT UNSIGNED NULL)');
    $pdo->exec("CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type VARCHAR(50) NOT NULL, title VARCHAR(190) NOT NULL, body VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL,
        source_type VARCHAR(80) NOT NULL, source_id BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);

    $users = $tokens = [];
    $grantPolicy = ['read'=>true, 'send'=>false, 'followups'=>false];
    $fingerprint = agent_connections_client_fingerprint_v1();
    foreach ([1, 2, 3] as $owner) {
        $users[$owner] = ['id'=>$owner, 'is_active'=>1];
        $tokens[$owner] = ['external_account_id'=>'owner-'.$owner, 'account_email'=>'owner'.$owner.'@example.test',
            'access_token'=>'fixture-inbox-access-'.$owner, 'refresh_token'=>'fixture-inbox-refresh-'.$owner, 'expires_in'=>3600];
        agent_connections_store_gmail_v1($owner, $tokens[$owner], $grantPolicy, '', $fingerprint);
    }

    $baseline = '18446744073709551600';
    $profileCalls = $listCalls = $metadataCalls = [];
    $pages = $messages = [];
    $onMetadata = null;
    $http = static function(string $method, string $url, array $headers, ?array $body, bool $form) use (
        $pdo, $baseline, $tokens, &$profileCalls, &$listCalls, &$metadataCalls, &$pages, &$messages, &$onMetadata
    ): array {
        if ($method !== 'GET' || $body !== null || $form || !str_starts_with($url, VP3_GMAIL_API_V1.'/') || $pdo->inTransaction()) {
            throw new LogicException('Unexpected provider request or Gmail request made in a transaction.');
        }
        $owner = 0;
        foreach ($tokens as $candidate=>$token) {
            if (in_array('Authorization: Bearer '.$token['access_token'], $headers, true)) $owner = $candidate;
        }
        if (!$owner) throw new LogicException('The request used an unexpected Gmail grant.');
        $path = (string)parse_url($url, PHP_URL_PATH);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        if (str_ends_with($path, '/profile')) {
            if ($query !== ['fields'=>'emailAddress,historyId']) throw new LogicException('Unexpected Gmail profile fields.');
            $profileCalls[] = $owner;
            return ['status'=>200, 'json'=>['emailAddress'=>$tokens[$owner]['account_email'], 'historyId'=>$baseline]];
        }
        if (str_ends_with($path, '/messages')) {
            $listCalls[] = [$owner, $query];
            $response = array_shift($pages[$owner]);
            if (!$response) throw new LogicException('Unexpected Gmail list page.');
            return ['status'=>200, 'json'=>$response];
        }
        if (!str_contains($path, '/messages/') || ($query['format']??'') !== 'metadata'
            || ($query['fields']??'') !== 'id,internalDate,labelIds,payload(headers)'
            || !str_contains($url, 'metadataHeaders=From&metadataHeaders=Cc&metadataHeaders=Subject&metadataHeaders=In-Reply-To')) {
            throw new LogicException('The first review requested unexpected fields or full message content.');
        }
        $id = basename($path);
        $metadataCalls[] = [$owner, $id];
        if ($onMetadata !== null) $onMetadata($owner, $id);
        return isset($messages[$owner][$id]) ? ['status'=>200, 'json'=>$messages[$owner][$id]] : ['status'=>404, 'json'=>[]];
    };
    $form = static function(int $owner, array $rules=[]) use ($pdo): array {
        $connection = agent_connections_find_v1($pdo, $owner, 'gmail');
        $policy = agent_connection_gmail_inbox_bound_policy_v1(agent_connection_gmail_inbox_policy_v1($pdo, $owner), $connection);
        return ['connection_id'=>(string)$connection['id'], 'connection_revision'=>hash('sha256', $connection['authorization_key']),
            'inbox_revision'=>$policy['revision']??'', 'action'=>'save', 'enabled'=>'1']+$rules;
    };
    $page = static function(array $ids, string $next=''): array {
        $data = ['messages'=>array_map(static fn(string $id): array => ['id'=>$id], $ids)];
        if ($next !== '') $data['nextPageToken'] = $next;
        return $data;
    };
    $view = static fn(int $owner, bool $report=false, ?string $expectedRevision=null): array =>
        agent_connection_gmail_inbox_initial_view_v1($pdo, $users[$owner], $report, $expectedRevision);
    $start = static function(int $owner, int $days, ?string $token=null) use ($pdo, $users, $view): void {
        agent_connection_gmail_inbox_initial_start_v1($pdo, $users[$owner], $days, $token ?? $view($owner, true)['start_token']);
    };
    $startRejected = static function(int $owner, string $token) use ($start): bool {
        try { $start($owner, 7, $token); }
        catch (AgentConnectionExceptionV1 $error) { return $error->getCode() === 409; }
        return false;
    };
    $row = static fn(int $owner): ?array => agent_connection_gmail_inbox_initial_row_v1($pdo, agent_connection_gmail_inbox_initial_scope_v1($pdo, $users[$owner]));
    $noticeRows = static function(int $owner) use ($pdo): array {
        $q = $pdo->prepare('SELECT title,body,target_url FROM notifications WHERE user_id=? ORDER BY id');
        $q->execute([$owner]);
        return $q->fetchAll();
    };
    $summaries = [];
    $secondCaller = null;
    $summarize = static function(PDO $pdo, array $owner, array $report, callable $before) use (
        $fixtureDsn, $login, $password, $options, &$summaries, &$secondCaller
    ): array {
        if ($pdo->inTransaction()) throw new LogicException('The model callback ran inside a transaction.');
        $before();
        $contender = new PDO($fixtureDsn, $login, $password, $options);
        $working = agent_connection_gmail_inbox_initial_view_v1($contender, $owner);
        $duplicateCalls = 0;
        $unexpectedWork = static function() use (&$duplicateCalls): never {
            $duplicateCalls++;
            throw new LogicException('A second caller began work during the summary lease.');
        };
        $advanced = agent_connection_gmail_inbox_initial_advance_v1($contender, $owner, $working['run_id'], $unexpectedWork, $unexpectedWork);
        $secondCaller = ['state'=>$working['state'], 'advanced'=>$advanced, 'calls'=>$duplicateCalls];
        $summaries[] = [(int)$owner['id'], $report];
        return ['status'=>'ready', 'summary'=>'Fixture subject theme.', 'tokens_charged'=>247];
    };
    $advance = static fn(int $owner, string $runId): bool => agent_connection_gmail_inbox_initial_advance_v1($pdo, $users[$owner], $runId, $http, $summarize);

    first_review_check($view(1) === ['state'=>'disabled', 'limit'=>50] && agent_connection_gmail_inbox_initial_sweep_v1($pdo) === 0,
        'a connected mailbox alone leaves the first review disabled');
    first_review_check($startRejected(1, str_repeat('a', 64)), 'first review requires explicit Inbox Review consent');
    $rules = ['ignore_senders'=>'muted@example.test', 'ignore_github_actions'=>'1'];
    agent_connection_gmail_inbox_save_v1($pdo, $users[1], $form(1, $rules), $http);
    agent_connection_gmail_inbox_save_v1($pdo, $users[2], $form(2), $http);
    agent_connection_gmail_inbox_save_v1($pdo, $users[3], $form(3), $http);
    $cursorBefore = agent_connection_gmail_inbox_policy_v1($pdo, 1);
    first_review_check($profileCalls === [1, 2, 3] && $cursorBefore['history_id'] === $baseline
        && $view(1)['state'] === 'needs_start' && agent_connection_gmail_inbox_initial_sweep_v1($pdo) === 0
        && $listCalls === [] && $summaries === [], 'enabling Inbox Review only verifies the mailbox cursor and waits for explicit first review');

    $initialToken = $view(1, true)['start_token'];
    $start(1, 14, $initialToken);
    $queued = $view(1);
    $run = $queued['run_id'];
    $start(1, 7, $initialToken);
    first_review_check($queued['state'] === 'queued' && $queued['days'] === 14 && $queued['scanned'] === 0
        && preg_match('/\A[a-f0-9]{32}\z/', $run) === 1 && $view(1) === $queued && $listCalls === []
        && preg_match('/\A[a-f0-9]{64}\z/', $initialToken) === 1 && !isset($queued['start_token']),
        'start saves the selected window and a repeated start preserves the queued run');
    first_review_check(!$advance(2, $run) && $view(2, true)['state'] === 'needs_start' && $listCalls === [],
        'another owner cannot advance or read the first owner’s review');

    $startedAt = (int)$row(1)['started_at_ms'];
    $lower = $startedAt - 14*86400000;
    $pages[1] = [$page(['good', 'muted', 'archived', 'future', 'good'], 'cursor-two'),
        $page(['good', 'old', 'boundary', 'github-ci', 'reply'])];
    $messages[1] = [
        'good'=>first_review_message('good', $lower+2000, 'customer@example.test', 'PRIVATE_SUBJECT_SENTINEL'),
        'muted'=>first_review_message('muted', $lower+2000, 'muted@example.test', 'Filtered sender'),
        'archived'=>first_review_message('archived', $lower+2000, 'customer@example.test', 'Archived', []),
        'future'=>first_review_message('future', $startedAt, 'customer@example.test', 'At exclusive end boundary'),
        'old'=>first_review_message('old', $lower-1, 'customer@example.test', 'Outside lookback'),
        'boundary'=>first_review_message('boundary', $lower, 'customer@example.test', 'At start boundary'),
        'github-ci'=>first_review_message('github-ci', $lower+2000, 'notifications@github.com', 'CI update', ['INBOX'], 'ci_activity@noreply.github.com'),
        'reply'=>first_review_message('reply', $startedAt-1, 'reply@example.test', 'Just before end boundary', ['INBOX', 'IMPORTANT'], '', '<original@example.test>'),
    ];
    $expectedQuery = ['q'=>'after:'.(intdiv($lower, 1000)-1).' before:'.(int)ceil($startedAt/1000),
        'labelIds'=>'INBOX', 'includeSpamTrash'=>'false', 'maxResults'=>'5', 'fields'=>'messages(id),nextPageToken'];
    first_review_check($advance(1, $run) && $view(1)['scanned'] === 5 && $view(1)['stage'] === 'scan'
        && $listCalls === [[1, $expectedQuery]] && count($metadataCalls) === 4 && $summaries === [],
        'the first pass reads one frozen Gmail page and deduplicates repeated metadata');
    first_review_check($advance(1, $run) && $view(1)['scanned'] === 10 && $view(1)['stage'] === 'summary'
        && $listCalls[1] === [1, $expectedQuery+['pageToken'=>'cursor-two']]
        && array_column($metadataCalls, 1) === ['good', 'muted', 'archived', 'future', 'old', 'boundary', 'github-ci', 'reply']
        && $summaries === [], 'the next pass preserves the window, resumes the saved page, and queues the summary separately');
    first_review_check($advance(1, $run) && count($summaries) === 1 && $summaries[0][0] === 1,
        'the owner-bound summary runs once after the metadata scan');
    first_review_check($secondCaller === ['state'=>'working', 'advanced'=>false, 'calls'=>0],
        'a second database connection sees the live summary lease and cannot repeat inference or Gmail reads');
    $report = $view(1, true)['report'];
    $rail = $view(1);
    first_review_check($report['days'] === 14 && $report['scanned'] === 10 && $report['total'] === 3
        && $report['filtered'] === 2 && $report['important'] === 1 && $report['replies'] === 1
        && array_column($report['items'], 'subject') === ['PRIVATE_SUBJECT_SENTINEL', 'At start boundary', 'Just before end boundary']
        && $report['summary'] === 'Fixture subject theme.' && $report['summary_status'] === 'ready' && $report['tokens_charged'] === 247,
        'the saved report applies Inbox membership, exact cutoffs, owner filters, and summary token receipt');
    $notices = $noticeRows(1);
    first_review_check($rail['state'] === 'ready' && $rail['total'] === 3 && !isset($rail['report'])
        && !str_contains(json_encode([$rail, $notices], JSON_THROW_ON_ERROR), 'PRIVATE_SUBJECT_SENTINEL')
        && !str_contains(json_encode([$report, $summaries], JSON_THROW_ON_ERROR), 'FIXTURE_BODY_SENTINEL')
        && !str_contains(json_encode([$report, $summaries], JSON_THROW_ON_ERROR), 'FIXTURE_SNIPPET_SENTINEL')
        && count($notices) === 1 && $notices[0]['title'] === 'First Gmail review ready',
        'only the private report includes reviewed headers and the rail and bell expose aggregate status');
    first_review_check(agent_connection_gmail_inbox_policy_v1($pdo, 1) === $cursorBefore && !$advance(1, $run)
        && count($summaries) === 1 && $noticeRows(1) === $notices,
        'first review leaves the incremental cursor untouched and completed work cannot replay');

    $ref = $report['items'][0]['item_ref'];
    $stored = json_decode($row(1)['report_json'],true,12,JSON_THROW_ON_ERROR)['items'][0];
    $chatView = agent_connection_gmail_inbox_initial_view_v1($pdo,$users[1],includeChatResult:true);
    first_review_check(ai_decrypt_secret($stored['message_id_enc']) === 'good'
        && !isset($report['items'][0]['message_id_enc'],$rail['chat_result'])
        && $chatView['chat_result']['items'][0]['item_ref'] === $ref
        && !isset($chatView['chat_result']['items'][0]['message_id'],$chatView['chat_result']['items'][0]['message_id_enc']),
        'the saved ID is encrypted and explicit private views expose only an opaque exact item selector');
    $reads = 0;
    $openHttp = static function(string $method, string $url, array $headers) use (&$reads): array {
        if ($method !== 'GET' || !str_ends_with($url,'/messages/good?format=full')
            || !in_array('Authorization: Bearer fixture-inbox-access-1',$headers,true)) {
            throw new LogicException('Opening the report item must fetch only its exact original message and account.');
        }
        $reads++;
        return ['status'=>200,'json'=>['id'=>'good','threadId'=>'original-thread','labelIds'=>['INBOX'],
            'payload'=>['mimeType'=>'text/plain','body'=>['data'=>rtrim(strtr(base64_encode('Exact fixture body.'),'+/','-_'),'=')],
                'headers'=>[['name'=>'From','value'=>'sender@example.test'],['name'=>'Subject','value'=>'Exact fixture subject'],
                    ['name'=>'Message-ID','value'=>'<original@example.test>']]]]];
    };
    $opened = agent_connection_gmail_inbox_open_item_v1($pdo,$users[1],$ref,$openHttp);
    first_review_check($opened === ['subject'=>'Exact fixture subject','from'=>'sender@example.test','body'=>'Exact fixture body.','auto_reply'=>false]
        && $reads === 1,'explicit Open reads one exact message and returns plain text without provider IDs');
    foreach ([[$users[2],$ref],[$users[1],str_repeat('0',64)]] as [$wrongUser,$wrongRef]) {
        try { agent_connection_gmail_inbox_open_item_v1($pdo,$wrongUser,$wrongRef,$openHttp); }
        catch (DomainException $error) { if (!in_array($error->getCode(),[403,404],true)) throw $error; }
    }
    first_review_check($reads === 1,'an unknown or cross-owner review reference never reaches Gmail');

    $start(2, 1);
    $pages[2] = [$page([])];
    first_review_check($advance(2, $view(2)['run_id']) && $view(2, true)['report']['total'] === 0
        && $view(2, true)['report']['summary_status'] === 'no_messages' && $view(2, true)['report']['tokens_charged'] === 0
        && count($summaries) === 1, 'an empty first review completes with zero tokens and no AI call');
    $otherReport = $view(2, true);

    $start(3, 7);
    $paidRun = $view(3)['run_id'];
    $pages[3] = [$page(['lease-mail'])];
    $messages[3]['lease-mail'] = first_review_message('lease-mail', (int)$row(3)['started_at_ms']-500, 'customer@example.test', 'Lease fixture');
    first_review_check($advance(3, $paidRun) && $view(3)['stage'] === 'summary', 'the lease fixture reaches the actual summary claim');
    $paidAttempts = 0;
    $expireAfterInference = static function(PDO $pdo, array $user, array $report, callable $before) use (&$paidAttempts): array {
        $before();
        $paidAttempts++;
        $pdo->prepare("UPDATE agent_connection_gmail_inbox_initial_v1 SET lease_until='2000-01-01 00:00:00' WHERE owner_user_id=?")
            ->execute([$user['id']]);
        return ['status'=>'ready', 'summary'=>'Uncommitted result.', 'tokens_charged'=>42];
    };
    first_review_check(!agent_connection_gmail_inbox_initial_advance_v1($pdo, $users[3], $paidRun, $http, $expireAfterInference)
        && $paidAttempts === 1 && $row(3)['phase'] === 'summarizing', 'an expired lease cannot publish a late paid result');
    $advance(3, $paidRun);
    $recovered = $view(3, true)['report'];
    first_review_check($recovered['total'] === 1 && $recovered['summary_status'] === 'unverified' && $recovered['summary'] === ''
        && $recovered['tokens_charged'] === null && count($summaries) === 1 && $paidAttempts === 1,
        'recovering an expired paid lease preserves the metadata report without replaying inference');

    agent_commerce_atomic_pa2($pdo, static function() use ($pdo, $cursorBefore): void {
        agent_connection_gmail_inbox_notify_v1($pdo, 1, ['total'=>1], $cursorBefore);
    });
    $changedRules = ['ignore_senders'=>'changed@example.test'];
    agent_connection_gmail_inbox_save_v1($pdo, $users[1], $form(1, $changedRules), $http);
    try { agent_connection_gmail_inbox_open_item_v1($pdo,$users[1],$ref,$openHttp); }
    catch (DomainException $error) { if (!in_array($error->getCode(),[403,404],true)) throw $error; }
    first_review_check($reads === 1,'a replaced review grant or policy invalidates old item references before Gmail access');
    first_review_check($view(1, true)['state'] === 'needs_start' && $row(1) === null && !$advance(1, $run)
        && array_column($noticeRows(1), 'title') === ['Gmail inbox review']
        && $view(2, true) === $otherReport && $profileCalls === [1, 2, 3],
        'changing filters purges the first review and its notice while preserving new-mail notices and other owners');
    $beforeGrantReplacement = $view(1, true)['start_token'];
    $stalePage = $view(1, true, $cursorBefore['revision']);
    $callsBefore = [$profileCalls, $listCalls, $metadataCalls, count($summaries)];
    first_review_check($beforeGrantReplacement !== $initialToken && $startRejected(1, $initialToken)
        && $stalePage['state'] === 'unavailable' && !isset($stalePage['start_token'])
        && $row(1) === null && [$profileCalls, $listCalls, $metadataCalls, count($summaries)] === $callsBefore,
        'a valid token rendered before a same-owner settings change cannot queue a review or start paid work');
    $start(1, 3, $beforeGrantReplacement);
    $replacedRun = $view(1)['run_id'];
    $pages[1] = [$page(['good', 'reply'])];
    $onMetadata = static function(int $owner, string $id) use ($pdo, $tokens, $grantPolicy, $fingerprint): void {
        if ($owner !== 1 || $id !== 'good') throw new LogicException('A stale worker continued reading Gmail.');
        $connection = agent_connections_find_v1($pdo, 1, 'gmail');
        agent_connections_store_gmail_v1(1, $tokens[1], $grantPolicy, $connection['authorization_key'], $fingerprint);
    };
    $readBefore = count($metadataCalls);
    first_review_check(!$advance(1, $replacedRun) && count($metadataCalls) === $readBefore+1 && !$advance(1, $replacedRun)
        && $view(1, true)['state'] === 'disabled' && $noticeRows(1) === [] && $view(2, true) === $otherReport
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_initial_v1 WHERE owner_user_id=1')->fetchColumn() === 0,
        'a replaced Gmail grant revokes a running scan, purges its report and notices, and leaves other owners alone');

    agent_connection_gmail_inbox_save_v1($pdo, $users[1], $form(1, $changedRules), $http);
    $callsBefore = [$profileCalls, $listCalls, $metadataCalls, count($summaries)];
    $newGrant = $view(1, true);
    first_review_check($newGrant['state'] === 'needs_start' && $newGrant['start_token'] !== $beforeGrantReplacement
        && $startRejected(1, $beforeGrantReplacement) && $row(1) === null
        && [$profileCalls, $listCalls, $metadataCalls, count($summaries)] === $callsBefore,
        'an old grant token cannot authorize a review after the same owner re-enables the replacement grant');

    echo 'AGENT_CONNECTION_GMAIL_INBOX_INITIAL=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php', 'ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
