<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';

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
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function inbox_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function inbox_event(string $historyId, array $messages): array
{
    return ['id'=>$historyId,'messagesAdded'=>array_map(static fn(string $id): array => ['message'=>['id'=>$id]],$messages)];
}
function inbox_message(string $id, int $date, string $from, string $subject, array $labels=['INBOX'], string $cc='', string $reply=''): array
{
    $headers = ['From'=>$from,'Subject'=>$subject,'Cc'=>$cc,'In-Reply-To'=>$reply];
    return ['id'=>$id,'internalDate'=>(string)$date,'labelIds'=>$labels,'snippet'=>'PROVIDER_SNIPPET_SENTINEL',
        'payload'=>['headers'=>array_map(static fn(string $name,string $value): array => ['name'=>$name,'value'=>$value],array_keys($headers),$headers),
            'body'=>['data'=>'PROVIDER_BODY_SENTINEL']]];
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn,$login,$password,$options);
$database = 'vp3_inbox_fixture_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot = sys_get_temp_dir().'/vp3-inbox-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);
mkdir($fixtureRoot,0700);
$checks = 0;
putenv('VP3_GMAIL_CLIENT_ID=vp3-inbox-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=inbox-fixture-only');

try {
    $pdo = new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("SET time_zone='+00:00'");
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active TINYINT NOT NULL)');
    $pdo->exec('INSERT INTO users VALUES (1,1),(2,1)');
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id BIGINT UNSIGNED NULL)');
    $pdo->exec("CREATE TABLE notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL,
        type VARCHAR(50) NOT NULL, title VARCHAR(190) NOT NULL, body VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL,
        source_type VARCHAR(80) NOT NULL, source_id BIGINT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT NOT NULL DEFAULT 0, read_at DATETIME NULL)");
    agent_connections_install_v1($pdo);
    $owner = ['id'=>1,'is_active'=>1];
    $mailbox = 'owner@example.test';
    $token = ['external_account_id'=>'owner-fixture','account_email'=>$mailbox,'access_token'=>'fixture-inbox-access',
        'refresh_token'=>'fixture-inbox-refresh','expires_in'=>3600];
    $grantPolicy = ['read'=>true,'send'=>false,'followups'=>false];
    $client = agent_connections_client_fingerprint_v1();
    agent_connections_store_gmail_v1(1,$token,$grantPolicy,'',$client);
    agent_connections_store_gmail_v1(2,array_replace($token,['external_account_id'=>'second-fixture','account_email'=>'other@example.test']),$grantPolicy,'',$client);
    $connection = agent_connections_find_v1($pdo,1,'gmail');
    $settings = ['connection_id'=>(int)$connection['id'],'connection_revision'=>hash('sha256',$connection['authorization_key']),
        'inbox_revision'=>'','action'=>'save','enabled'=>'1','ignore_senders'=>'muted@example.test',
        'ignore_domains'=>'blocked.example.test','ignore_subjects'=>'private token phrase','ignore_github_actions'=>'1'];
    $baseline = '18446744073709551600';
    $first = '18446744073709551601';
    $second = '18446744073709551602';
    $third = '18446744073709551603';
    $latest = '18446744073709551609';
    $historyResponses = [];
    $historyQueries = [];
    $metadataIds = [];
    $profileCalls = 0;
    $messages = [];
    $afterMetadata = null;
    $http = static function(string $method,string $url,array $headers,?array $body,bool $form)
        use (&$baseline,&$historyResponses,&$historyQueries,&$metadataIds,&$profileCalls,&$messages,&$afterMetadata,$mailbox): array {
        if ($method !== 'GET' || $body !== null || $form || !in_array('Authorization: Bearer fixture-inbox-access',$headers,true)
            || !str_starts_with($url,VP3_GMAIL_API_V1)) throw new LogicException('Inbox review made an unexpected provider call.');
        $path = (string)parse_url($url,PHP_URL_PATH);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if (str_ends_with($path,'/profile')) {
            if ($query !== ['fields'=>'emailAddress,historyId']) throw new LogicException('Inbox baseline requested unexpected fields.');
            $profileCalls++;
            return ['status'=>200,'json'=>['emailAddress'=>$mailbox,'historyId'=>$baseline]];
        }
        if (str_ends_with($path,'/history')) {
            $historyQueries[] = $query;
            $response = array_shift($historyResponses);
            if (!$response) throw new LogicException('Unexpected Gmail history request.');
            return is_callable($response) ? $response() : $response;
        }
        if (!str_contains($path,'/messages/') || !str_contains($url,'metadataHeaders=From&metadataHeaders=Cc&metadataHeaders=Subject&metadataHeaders=In-Reply-To')
            || ($query['format']??'') !== 'metadata' || ($query['fields']??'') !== 'id,internalDate,labelIds,payload(headers)') {
            throw new LogicException('Inbox requested message content or unexpected metadata.');
        }
        $id = basename($path);
        $metadataIds[] = $id;
        if ($afterMetadata !== null) $afterMetadata();
        return isset($messages[$id]) ? ['status'=>200,'json'=>$messages[$id]] : ['status'=>404,'json'=>[]];
    };
    $reviewRow = static fn(): ?array => agent_connection_gmail_inbox_policy_v1(db(),1);
    $review = static fn(): array => json_decode($reviewRow()['last_review_json']??'[]',true,16,JSON_THROW_ON_ERROR);
    $notices = static fn(): array => db()->query("SELECT * FROM notifications WHERE type='agent_email_inbox_review' ORDER BY id")->fetchAll();
    $receipts = static fn(): int => (int)db()->query('SELECT COUNT(*) FROM agent_connection_gmail_inbox_receipts_v1')->fetchColumn();
    $due = static fn() => db()->exec("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
    $sweep = static fn(): array => agent_connection_gmail_inbox_sweep_v1(db(),5,$http);
    $history = static fn(array $events,string $last,string $next=''): array => ['status'=>200,'json'=>['history'=>$events,'historyId'=>$last,'nextPageToken'=>$next]];

    inbox_check($sweep() === ['processed'=>0,'reviewed'=>0] && !$historyQueries && !$profileCalls,
        'connected read permission does not authorize or poll Inbox Review by default');
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$settings,$http) === 'inbox_saved'
        && $profileCalls === 1 && $reviewRow()['history_id'] === $baseline && $reviewRow()['last_status'] === 'waiting',
        'explicit opt-in captures the verified current Gmail profile cursor without integer conversion');
    $enabledAt = (int)$reviewRow()['enabled_at_ms'];
    $arrived = $enabledAt+1000;
    $messages = [
        'new_mail'=>inbox_message('new_mail',$arrived,'Customer <customer@example.test>','No action required: Customer update'),
        'reply_mail'=>inbox_message('reply_mail',$arrived,'reply@example.test','Re: Urgent customer update · Action required',['INBOX','IMPORTANT'],'','<original@example.test>'),
        'blocked_sender'=>inbox_message('blocked_sender',$arrived,'Muted <muted@example.test>','Blocked sender subject'),
        'blocked_domain'=>inbox_message('blocked_domain',$arrived,'sender@blocked.example.test','Blocked domain subject'),
        'blocked_subject'=>inbox_message('blocked_subject',$arrived,'newsletter@example.test',str_repeat('x',300).'PRIVATE TOKEN PHRASE'),
        'github_ci'=>inbox_message('github_ci',$arrived,'notifications@github.com','CI failed',['INBOX'],'"CI, automation" <ci_activity@noreply.github.com>'),
        'github_pr'=>inbox_message('github_pr',$arrived,'notifications@github.com','PR comment',['INBOX'],'"ci_activity@noreply.github.com" <mention@noreply.github.com>'),
        'sent'=>inbox_message('sent',$arrived,$mailbox,'SENT_SENTINEL',['INBOX','SENT']),
        'archived'=>inbox_message('archived',$arrived,'customer@example.test','ARCHIVE_SENTINEL',[]),
        'historical'=>inbox_message('historical',$enabledAt,'customer@example.test','HISTORY_SENTINEL'),
        'epoch'=>inbox_message('epoch',0,'customer@example.test','EPOCH_SENTINEL'),
        'tail'=>inbox_message('tail',$arrived,'sender@sub.blocked.example.test','Exact domain lookalike stays'),
        'next'=>inbox_message('next',$arrived,'muted2@example.test','Exact sender lookalike stays'),
        'filtered_only'=>inbox_message('filtered_only',$arrived,'muted@example.test','FILTERED_SENTINEL'),
    ];
    $initialIds = ['new_mail','reply_mail','blocked_sender','blocked_domain','blocked_subject','github_ci','github_pr','sent','archived','historical','deleted','tail'];
    $reorderedIds = array_merge(['tail','deleted'],array_slice($initialIds,0,10));
    $tailEvent = inbox_event($second,['new_mail','next','epoch']);
    $historyResponses = [$history([inbox_event($first,$initialIds),$tailEvent],$latest,'page2'),
        $history([inbox_event($first,$reorderedIds),$tailEvent],$latest,'page2'),
        $history([inbox_event($first,$reorderedIds),$tailEvent],$latest,'page2'),
        $history([],$latest,'empty_page_continuation'),$history([inbox_event($third,['filtered_only'])],$latest)];

    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && count($metadataIds) === 10 && $reviewRow()['history_id'] === $baseline
        && $reviewRow()['pending_event_id'] === $first && (int)$reviewRow()['pending_offset'] === 10,
        'one pass bounds metadata work and commits a partial event without advancing its cursor');
    $initialReview = $review();
    inbox_check($initialReview['total'] === 3 && $initialReview['important'] === 1 && $initialReview['replies'] === 1 && $initialReview['filtered'] === 4
        && array_column($initialReview['items'],'category') === ['new','reply','new'] && count($notices()) === 1,
        'deterministic review keeps GitHub conversation activity, filters owner rules, and counts Gmail labels and incoming replies');
    $privateEvidence = json_encode([$initialReview,$notices()],JSON_THROW_ON_ERROR);
    inbox_check(!str_contains($privateEvidence,'PROVIDER_') && !str_contains($privateEvidence,'_SENTINEL')
        && !str_contains(json_encode($notices(),JSON_THROW_ON_ERROR),'customer@example.test')
        && !str_contains(json_encode($notices(),JSON_THROW_ON_ERROR),'Customer update') && $receipts() === 10,
        'only private reviewed headers and hash receipts persist while the Bell keeps generic counts');
    $itemView = agent_connection_gmail_inbox_latest_view_v1($pdo,$owner,$reviewRow()['revision']);
    $itemRef = $itemView['items'][0]['item_ref'];
    inbox_check(ai_decrypt_secret($initialReview['items'][0]['message_id_enc']) === 'new_mail'
        && !isset($itemView['items'][0]['message_id_enc'])
        && agent_connection_gmail_inbox_item_v1($pdo,$owner,$itemRef)['message_id'] === 'new_mail',
        'the latest batch exposes a protected selector for its exact saved message');
    $replyItem = $itemView['items'][1];
    inbox_check($replyItem['category'] === 'reply' && ($replyItem['important'] ?? false) === true
        && !isset($itemView['items'][0]['important'])
        && agent_connection_gmail_inbox_item_v1($pdo,$owner,$replyItem['item_ref'])['message_id'] === 'reply_mail',
        'the exact reply keeps both Gmail labels without marking other mail important');
    inbox_check(($replyItem['priority_signals'] ?? []) === ['urgent','action required']
        && !isset($itemView['items'][0]['priority_signals'])
        && str_contains($notices()[0]['body'],'1 message with priority keywords in the subject.')
        && !str_contains($notices()[0]['body'],'Urgent customer update'),
        'the background review counts matching messages and keeps exact priority wording inside the private report');
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && array_slice($metadataIds,10) === ['tail','deleted']
        && $review()['total'] === 1 && $reviewRow()['pending_event_id'] === $first,
        'changed provider event membership or ordering restarts the slice without duplicate reads or lost tail mail');
    inbox_check(agent_connection_gmail_inbox_item_v1($pdo,$owner,$itemRef)['message_id'] === 'new_mail',
        'replacing the latest view preserves previous exact selectors in the same mailbox grant');
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && array_slice($metadataIds,12) === ['next','epoch']
        && $review()['items'][0]['from'] === 'muted2@example.test' && $reviewRow()['history_id'] === $second
        && $review()['total'] === 1 && $reviewRow()['pending_event_id'] === '' && $receipts() === 14,
        'the cursor advances only to the last completed event while another history page remains');
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && $reviewRow()['history_id'] === $second
        && $reviewRow()['history_page_token'] === 'empty_page_continuation',
        'an empty filtered history page preserves its provider continuation');
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && $reviewRow()['history_id'] === $latest && $reviewRow()['history_page_token'] === null
        && $review()['total'] === 0 && $review()['filtered'] === 1 && count($notices()) === 3
        && array_column($historyQueries,'startHistoryId') === [$baseline,$baseline,$baseline,$second,$second]
        && ($historyQueries[4]['pageToken']??'') === 'empty_page_continuation'
        && ($historyQueries[0]['historyTypes']??'') === 'messageAdded' && ($historyQueries[0]['labelId']??'') === 'INBOX'
        && ($historyQueries[0]['maxResults']??'') === '5',
        'the final history page advances the exact mailbox cursor and a filtered-only review produces no notification');

    $historyResponses[] = ['status'=>429,'json'=>[]];
    $due();
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && $reviewRow()['history_id'] === $latest && $reviewRow()['last_status'] === 'retrying'
        && $reviewRow()['claim_key'] === null && $review()['filtered'] === 1,
        'provider throttling preserves the durable cursor and latest review while scheduling retry');
    $historyResponses[] = ['status'=>404,'json'=>[]];
    $due();
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && $reviewRow()['last_status'] === 'restart_required'
        && $reviewRow()['next_check_at'] === null && $sweep() === ['processed'=>0,'reviewed'=>0] && $profileCalls === 1,
        'expired history stops in restart_required without a fallback inbox scan or baseline');
    $baseline = '18446744073709551610';
    $restart = array_replace($settings,['action'=>'restart','inbox_revision'=>$reviewRow()['revision']]);
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$restart,$http) === 'inbox_restarted'
        && $profileCalls === 2 && $reviewRow()['history_id'] === $baseline && $review() === [] && !$notices() && $receipts() === 0,
        'owner restart captures a fresh cursor and clears previous private output and hash receipts');

    $raceId = '18446744073709551611';
    $raceHistory = $history([inbox_event($raceId,['raced','raced_second'])],$raceId);
    $arrived = (int)$reviewRow()['enabled_at_ms']+1000;
    $messages['raced'] = inbox_message('raced',$arrived,'race@example.test','Rules change during provider request');
    $messages['raced_second'] = inbox_message('raced_second',$arrived,'race@example.test','Lease can stop remaining reads');
    $afterMetadata = static function() use (&$afterMetadata,$pdo,$owner,$settings,$reviewRow,$http): void {
        $afterMetadata = null;
        agent_connection_gmail_inbox_save_v1($pdo,$owner,array_replace($settings,['inbox_revision'=>$reviewRow()['revision'],
            'ignore_senders'=>'race@example.test']),$http);
    };
    $historyResponses[] = $raceHistory;
    $before = count($metadataIds);
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && array_slice($metadataIds,$before) === ['raced']
        && $reviewRow()['history_id'] === $baseline && !$notices() && $receipts() === 0,
        'a concurrent rule change invalidates the old lease, stops further metadata reads, and drops stale output');
    $historyResponses[] = $raceHistory;
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && $reviewRow()['history_id'] === $raceId
        && $review()['filtered'] === 2 && !$notices() && $receipts() === 2,
        'a replacement claim retries the same cursor using the live rules');

    $leaseId = '18446744073709551612';
    $leaseHistory = $history([inbox_event($leaseId,['lease_message'])],$leaseId);
    $messages['lease_message'] = inbox_message('lease_message',$arrived,'customer@example.test','Lease review');
    $historyResponses[] = static function() use ($pdo,$leaseHistory): array {
        $pdo->exec("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
        return $leaseHistory;
    };
    $due();
    $before = count($metadataIds);
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && count($metadataIds) === $before && $reviewRow()['history_id'] === $raceId,
        'an expired lease cannot fetch metadata or advance the checkpoint');
    $pdo->exec("CREATE TRIGGER fixture_inbox_notice_failure BEFORE INSERT ON notifications FOR EACH ROW
        BEGIN IF NEW.type='agent_email_inbox_review' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'; END IF; END");
    $historyResponses[] = $leaseHistory;
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && $reviewRow()['history_id'] === $raceId && $receipts() === 2 && !$notices(),
        'notification failure rolls back receipts, cursor, and review together');
    $pdo->exec('DROP TRIGGER fixture_inbox_notice_failure');
    $historyResponses[] = $leaseHistory;
    $due();
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>1] && $reviewRow()['history_id'] === $leaseId
        && $receipts() === 3 && count($notices()) === 1 && $review()['total'] === 1 && $sweep() === ['processed'=>0,'reviewed'=>0],
        'retry after rollback commits and notifies exactly once');

    $due();
    $historyResponses[] = static function() use ($pdo,$token,$grantPolicy,$client): array {
        $previous = agent_connections_find_v1($pdo,1,'gmail');
        agent_connections_store_gmail_v1(1,$token,$grantPolicy,$previous['authorization_key'],$client);
        return ['status'=>200,'json'=>['historyId'=>'18446744073709551613','history'=>[inbox_event('18446744073709551613',['lease_message'])]]];
    };
    $before = count($metadataIds);
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && count($metadataIds) === $before && empty($reviewRow()['enabled'])
        && $review() === [] && !$notices() && $receipts() === 0 && agent_connection_gmail_inbox_policy_v1($pdo,2) === null,
        'a replacement Gmail grant cannot inherit, deliver, or expose the old opt-in and does not affect another owner');
    echo 'AGENT_CONNECTION_GMAIL_INBOX=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
