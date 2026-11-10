<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';

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
    $pdo->exec("CREATE TABLE user_agents (id BIGINT UNSIGNED PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, is_active TINYINT NOT NULL);
        INSERT INTO user_agents VALUES (17,1,1),(18,1,1);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id BIGINT UNSIGNED NULL,
            artist_workspace_id BIGINT UNSIGNED NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id,artist_workspace_id) VALUES (170,1,17,5),(180,1,18,NULL),(190,2,NULL,NULL),(171,1,17,NULL);
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL, role VARCHAR(20) NOT NULL, message TEXT NOT NULL, context_json TEXT, created_at DATETIME NOT NULL)");
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

    $beforeDeliveryCalls = [$profileCalls,$listCalls,$metadataCalls,count($summaries)];
    $deliveryRejected = static function(int $agent,int $cid,string $runId,int $status) use ($pdo,$users): bool {
        try { agent_connection_gmail_inbox_chat_deliver_v1($pdo,$users[1],$agent,$cid,$runId); }
        catch (RuntimeException $error) { return $error->getCode() === $status; }
        return false;
    };
    first_review_check($deliveryRejected(18,170,$run,403) && $deliveryRejected(0,190,$run,403)
        && $deliveryRejected(17,170,str_repeat('c',32),409)
        && agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$users[1]) === null,
        'only an exact current ready review and an owned main Chat can claim its existing report');
    $delivery = agent_connection_gmail_inbox_chat_deliver_v1($pdo,$users[1],17,170,$run);
    $anchor = $delivery['delivery'];
    $post = $pdo->query('SELECT id,role,message,context_json FROM chat_messages WHERE conversation_id=170')->fetch();
    first_review_check($anchor['state'] === 'posted' && $anchor['run_id'] === $run && $anchor['message_id'] === (int)$post['id']
        && $delivery['review']['chat_result']['summary'] === 'Fixture subject theme.'
        && $delivery['review']['chat_result']['summary_status'] === 'ready'
        && $delivery['review']['chat_result']['items'][0]['item_ref'] === $ref
        && !str_contains(json_encode($post,JSON_THROW_ON_ERROR),'Fixture subject theme.')
        && !str_contains(json_encode($post,JSON_THROW_ON_ERROR),'PRIVATE_SUBJECT_SENTINEL')
        && !isset($view(1,true)['report']['chat_delivery'])
        && str_contains($noticeRows(1)[0]['target_url'],'agent=17&conversation_id=170&message_id='.$anchor['message_id']),
        'an existing report backfills one content-free durable post in an owner workspace Chat and links its exact message');
    $contender = new PDO($fixtureDsn,$login,$password,$options);
    $replay = agent_connection_gmail_inbox_chat_deliver_v1($contender,$users[1],17,170,$run);
    first_review_check($replay['delivery'] === array_replace($anchor,['state'=>'existing'])
        && agent_connection_gmail_inbox_chat_deliver_v1($contender,$users[1],18,180,$run) === ['delivery'=>['state'=>'bound_elsewhere']]
        && agent_connection_gmail_inbox_chat_deliver_v1($contender,$users[1],17,171,$run) === ['delivery'=>['state'=>'bound_elsewhere']]
        && (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn() === 1
        && [$profileCalls,$listCalls,$metadataCalls,count($summaries)] === $beforeDeliveryCalls,
        'independent retries return the same post without a duplicate or any Gmail or model calls');
    $history = agent_connection_gmail_inbox_chat_history_v1($pdo,$users[1],17,170,[$post]);
    $hydrated = json_decode($history[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['gmail_inbox_review_v1'] ?? [];
    first_review_check(($hydrated['message_id'] ?? 0) === $anchor['message_id'] && ($hydrated['report_ref'] ?? null) === $anchor['report_ref']
        && $hydrated['review']['chat_result']['summary'] === $delivery['review']['chat_result']['summary']
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$users[1],18,180,[$post]) === [$post]
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$users[2],17,170,[$post]) === [$post],
        'history rehydrates the saved report only on its exact existing private Chat post');
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
    first_review_check($deliveryRejected(17,170,$run,409)
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$users[1],17,170,$history) === [$post]
        && agent_connection_gmail_inbox_chat_delivery_current_v1($pdo,$users[1]) === null,
        'changing the live Gmail grant removes previously hydrated details while preserving the safe Chat marker');
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

    // Exercise the real upgrade against a legacy TEXT table and an existing saved report.
    $pdo->exec('ALTER TABLE agent_connection_gmail_inbox_initial_v1 MODIFY checkpoint_json TEXT NULL, MODIFY report_json TEXT NULL');
    first_review_check(!agent_connection_gmail_inbox_storage_ready_v1($pdo) && $view(2,true) === $otherReport,
        'an upgrade check finds insufficient legacy capacity while preserving the existing report');
    agent_connections_install_v1($pdo);
    first_review_check(agent_connection_gmail_inbox_storage_ready_v1($pdo) && $view(2,true) === $otherReport,
        'the same database upgrade widens report storage without rescanning existing reviews');

    $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at='2099-01-01 00:00:00' WHERE owner_user_id<>1");
    $updateCalls = 0;
    $updates = static function(string $id,string $cursor) use ($pdo,&$updateCalls): array {
        $policy = agent_connection_gmail_inbox_policy_v1($pdo,1);
        $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
        $provider = static function(string $method,string $url,array $headers) use ($id,$cursor,$policy,&$updateCalls,$pdo): array {
            if ($method !== 'GET' || $pdo->inTransaction() || !in_array('Authorization: Bearer fixture-inbox-access-1',$headers,true)) {
                throw new LogicException('Unexpected inbox-update provider call.');
            }
            $updateCalls++;
            $path = (string)parse_url($url,PHP_URL_PATH);
            if (str_ends_with($path,'/history')) {
                return ['status'=>200,'json'=>['historyId'=>$cursor,
                    'history'=>[['id'=>$cursor,'messagesAdded'=>[['message'=>['id'=>$id]]]]]]];
            }
            if (!str_ends_with($path,'/messages/'.$id)) throw new LogicException('Unexpected inbox-update metadata call.');
            return ['status'=>200,'json'=>first_review_message($id,(int)$policy['enabled_at_ms']+2000,'update@example.test','Private '.$id)];
        };
        return agent_connection_gmail_inbox_sweep_v1($pdo,1,$provider);
    };
    first_review_check($updates('update_one','18446744073709551601') === ['processed'=>1,'reviewed'=>1]
        && $updates('update_two','18446744073709551602') === ['processed'=>1,'reviewed'=>1]
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_batches_v1 WHERE message_id IS NULL')->fetchColumn() === 2
        && count($noticeRows(1)) === 2,
        'two inbox updates received while away each retain their own report and private bell event');

    $onMetadata = null;
    $start(1,7);
    $fullRun = $view(1)['run_id'];
    $fullStartedAt = (int)$row(1)['started_at_ms'];
    $fullIds = array_map(static fn(int $n): string => 'full_'.$n, range(1,50));
    $wideSubject = str_repeat('🌍',240);
    $pages[1] = [];
    foreach (array_chunk($fullIds,5) as $index=>$ids) {
        $pages[1][] = $page($ids,$index < 9 ? 'full-page-'.($index+1) : '');
    }
    foreach ($fullIds as $id) {
        $messages[1][$id] = first_review_message($id,$fullStartedAt-500,'customer@example.test',$wideSubject);
    }
    for ($index=0;$index<10;$index++) {
        if (!$advance(1,$fullRun)) throw new RuntimeException('Full first review could not advance.');
    }
    first_review_check(count(json_decode($row(1)['checkpoint_json'],true,12,JSON_THROW_ON_ERROR)['items']) === 50
        && strlen($row(1)['checkpoint_json']) > 65535 && $advance(1,$fullRun),
        'every accepted message survives a full paginated checkpoint, including wide Unicode subjects');
    $fullView = $view(1,true)['report'];
    $lastSelector = $fullView['items'][49]['item_ref'];
    first_review_check($fullView['total'] === 50 && count($fullView['items']) === 50
        && agent_connection_gmail_inbox_item_v1($pdo,$users[1],$lastSelector)['message_id'] === 'full_50',
        'the fiftieth saved row exposes an exact selector and remains actionable without another Gmail search');
    $fullDelivery = agent_connection_gmail_inbox_chat_deliver_v1($pdo,$users[1],17,170,$fullRun);
    $backlog = agent_connection_gmail_inbox_update_deliver_v1($contender,$users[1],18,180);
    $replayedBacklog = agent_connection_gmail_inbox_update_deliver_v1($pdo,$users[1],17,170);
    first_review_check($backlog === $replayedBacklog && $backlog['inbox_updates']['pending'] === false
        && $backlog['inbox_updates']['latest']['conversation_id'] === 170 && $updateCalls === 4
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_batches_v1 WHERE conversation_id=170')->fetchColumn() === 2,
        'opening an unrelated Chat cannot move the backlog, and a second delivery does not duplicate it or contact Gmail');
    first_review_check($updates('update_three','18446744073709551603') === ['processed'=>1,'reviewed'=>1]
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_batches_v1 WHERE conversation_id=170')->fetchColumn() === 3,
        'the worker delivers new inbox updates to the established Chat without a browser request');
    $updatePosts = $pdo->query("SELECT id,role,message,context_json FROM chat_messages WHERE conversation_id=170
        AND context_json LIKE '%gmail_inbox_update_v1%' ORDER BY id")->fetchAll();
    $rehydrated = agent_connection_gmail_inbox_chat_history_v1($contender,$users[1],17,170,$updatePosts);
    $updateRefs = [];
    foreach ($rehydrated as $message) {
        $update = json_decode($message['context_json'],true,24,JSON_THROW_ON_ERROR)['gmail_inbox_review_v1'];
        $updateRefs[] = agent_connection_gmail_inbox_item_v1($pdo,$users[1],$update['review']['items'][0]['item_ref'])['message_id'];
    }
    $updateNotices = array_values(array_filter($noticeRows(1),static fn(array $notice): bool => $notice['title'] === 'Gmail inbox review'));
    first_review_check($updateRefs === ['update_one','update_two','update_three'] && $updateCalls === 6
        && !str_contains(json_encode($updatePosts,JSON_THROW_ON_ERROR),'Private update')
        && str_ends_with($updateNotices[0]['target_url'],'&message_id='.$updatePosts[0]['id'])
        && str_ends_with($updateNotices[1]['target_url'],'&message_id='.$updatePosts[1]['id'])
        && str_ends_with($updateNotices[2]['target_url'],'&message_id='.$updatePosts[2]['id'])
        && !isset($fullDelivery['review']['chat_result']['latest']),
        'every update has its own exact private actions and notice after reload, while the first review retains its independent report');
    first_review_check(agent_connection_gmail_inbox_chat_history_v1($pdo,$users[1],18,180,$rehydrated) === $updatePosts
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$users[2],0,190,$rehydrated) === $updatePosts,
        'a replay in another Agent or owner strips mailbox projections from persisted update markers');
    $disable = $form(1,$changedRules);
    unset($disable['enabled']);
    agent_connection_gmail_inbox_save_v1($pdo,$users[1],$disable,$http);
    first_review_check(agent_connection_gmail_inbox_chat_history_v1($pdo,$users[1],17,170,$rehydrated) === $updatePosts
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_batches_v1 WHERE owner_user_id=1')->fetchColumn() === 0,
        'revoking Inbox Review removes private batch data while leaving only the generic Chat markers');

    echo 'AGENT_CONNECTION_GMAIL_INBOX_INITIAL=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php', 'ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
