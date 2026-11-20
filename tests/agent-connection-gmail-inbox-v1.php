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
    return ['id'=>$id,'threadId'=>'thread_'.$id,'internalDate'=>(string)$date,'labelIds'=>$labels,'snippet'=>'PROVIDER_SNIPPET_SENTINEL',
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
            || ($query['format']??'') !== 'metadata' || ($query['fields']??'') !== 'id,threadId,internalDate,labelIds,payload(headers)') {
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
    $matterScope = static fn(): array => agent_connection_gmail_inbox_bound_scope_v1(db(),$owner);
    $scanStatus = static fn(): ?array => agent_email_matters_scan_status_v1(db(),$matterScope());

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
        'blocked_sender'=>inbox_message('blocked_sender',$arrived,'notifications@github.com','Blocked sender subject',['INBOX'],'ci_activity@noreply.github.com'),
        'blocked_domain'=>inbox_message('blocked_domain',$arrived,'notifications@github.com','Blocked domain subject',['INBOX'],'ci_activity@noreply.github.com'),
        'blocked_subject'=>inbox_message('blocked_subject',$arrived,'notifications@github.com',str_repeat('x',300).'PRIVATE TOKEN PHRASE',['INBOX'],'ci_activity@noreply.github.com'),
        'github_ci'=>inbox_message('github_ci',$arrived,'notifications@github.com','CI failed',['INBOX'],'"CI, automation" <ci_activity@noreply.github.com>'),
        'github_pr'=>inbox_message('github_pr',$arrived,'notifications@github.com','PR comment',['INBOX'],'"ci_activity@noreply.github.com" <mention@noreply.github.com>'),
        'sent'=>inbox_message('sent',$arrived,$mailbox,'SENT_SENTINEL',['INBOX','SENT']),
        'archived'=>inbox_message('archived',$arrived,'customer@example.test','ARCHIVE_SENTINEL',[]),
        'historical'=>inbox_message('historical',$enabledAt,'customer@example.test','HISTORY_SENTINEL'),
        'epoch'=>inbox_message('epoch',0,'customer@example.test','EPOCH_SENTINEL'),
        'tail'=>inbox_message('tail',$arrived,'sender@sub.blocked.example.test','Exact domain lookalike stays'),
        'next'=>inbox_message('next',$arrived,'muted2@example.test','Exact sender lookalike stays'),
        'filtered_only'=>inbox_message('filtered_only',$arrived,'notifications@github.com','FILTERED_SENTINEL',['INBOX'],'ci_activity@noreply.github.com'),
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
    inbox_check($scanStatus()['at'] === null && $scanStatus()['in_progress'] === true
        && agent_email_matters_summary_v1($pdo,$matterScope())['counts']['needs_review'] === 3,
        'the same cursor transaction stores exact new thread matters and withholds incomplete scan totals');
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
    $completedScan = $scanStatus();
    inbox_check($completedScan['total'] === 5 && $completedScan['important'] === 1 && $completedScan['replies'] === 1
        && is_string($completedScan['at']) && !$completedScan['in_progress'],
        'the completed scan reports deduplicated totals across all verified provider pages');
    require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-status-v1.php';
    $arrivalsView = static fn(): array => agent_connection_gmail_inbox_background_status_v1($pdo,$owner,0)['latest_arrivals'] ?? [];
    $lastArrival = $arrivalsView();
    inbox_check($lastArrival['total']===1 && $lastArrival['items'][0]['subject']==='Exact sender lookalike stays'
        && agent_connection_gmail_inbox_item_v1($pdo,$owner,$lastArrival['items'][0]['item_ref'])['message_id']==='next',
        'an ignored-only scan leaves the previous exact private arrival available');
    $historyResponses[] = $history([],$latest);
    $due();
    inbox_check($sweep()===['processed'=>1,'reviewed'=>0] && $scanStatus()['total']===0 && $arrivalsView()===$lastArrival,
        'an empty scan retains the visible exact arrival while the latest scan count is zero');
    $completedScan = $scanStatus();

    $historyResponses[] = ['status'=>429,'json'=>[]];
    $due();
    inbox_check($sweep() === ['processed'=>1,'reviewed'=>0] && $reviewRow()['history_id'] === $latest && $reviewRow()['last_status'] === 'retrying'
        && $reviewRow()['claim_key'] === null && $review()['filtered'] === 1,
        'provider throttling preserves the durable cursor and latest review while scheduling retry');
    inbox_check($scanStatus() === $completedScan,'a throttled provider does not overwrite the last verified scan');
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
    $messages['raced'] = inbox_message('raced',$arrived,'notifications@github.com','Rules change during provider request',['INBOX'],'ci_activity@noreply.github.com');
    $messages['raced_second'] = inbox_message('raced_second',$arrived,'notifications@github.com','Lease can stop remaining reads',['INBOX'],'ci_activity@noreply.github.com');
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

    $pdo->exec('ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME NULL');
    $pdo->exec('INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,NULL)');
    $pdo->exec("CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, conversation_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL, role VARCHAR(20) NOT NULL, message TEXT NOT NULL, context_json TEXT NULL, created_at DATETIME NOT NULL)");
    $connection = agent_connections_find_v1($pdo,1,'gmail');
    $baseline = '18446744073709551614';
    $triageCursor = '18446744073709551615';
    $triageSettings = array_replace($settings,[
        'connection_revision'=>hash('sha256',$connection['authorization_key']),
        'inbox_revision'=>'','triage_enabled'=>'1',
    ]);
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$triageSettings,$http)==='inbox_saved'
        && ($reviewRow()['rules']['triage_enabled'] ?? false) === true,
        'body-aware triage activates only after its separate Inbox Review setting is saved');
    $policy = $reviewRow();
    $arrived = (int)$policy['enabled_at_ms']+3000;
    $prior = ['message_id'=>'prior_delivery','thread_id'=>'thread_delivery','received_at_ms'=>(string)($arrived-1000),
        'from'=>'shipping@example.test','subject'=>'Delivery schedule','important'=>true,'reply'=>false];
    agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_matters_observe_inbox_v1($pdo,$policy,$prior,agent_commerce_time_pa2()));
    $matterId = agent_email_matters_source_key_v1($policy,'thread','thread_delivery');
    $delivery = inbox_message('delivery_reply',$arrived,'shipping@example.test','Re: Delivery schedule',['INBOX'],'','<prior_delivery@example.test>');
    $delivery['threadId'] = 'thread_delivery';
    $delivery['payload']['mimeType'] = 'text/plain';
    $delivery['payload']['headers'][] = ['name'=>'Message-ID','value'=>'<delivery_reply@example.test>'];
    $delivery['payload']['body']['data'] = base64_encode('Could we reschedule the delivery? BODY_PRIVATE_SENTINEL');
    $parent = inbox_message('prior_delivery',$arrived-1000,'shipping@example.test','Delivery schedule');
    $parent['threadId'] = 'thread_delivery';
    $parent['payload']['headers'][] = ['name'=>'Message-ID','value'=>'<prior_delivery@example.test>'];
    $renewal = inbox_message('renewal_notice',$arrived,'billing@example.test','Annual renewal',['INBOX','CATEGORY_PROMOTIONS']);
    $renewal['payload']['mimeType'] = 'text/plain';
    $renewal['payload']['headers'][] = ['name'=>'Date','value'=>gmdate(DATE_RFC2822,intdiv($arrived,1000))];
    $renewal['payload']['body']['data'] = base64_encode('Your subscription renewal is due tomorrow. BODY_PRIVATE_SENTINEL');
    $providerMessages = [];
    for ($i=1; $i<=23; $i++) {
        $id = 'promo_'.$i;
        $providerMessages[$id] = inbox_message($id,$arrived,'offers@example.test','Weekly sale',['INBOX','CATEGORY_PROMOTIONS']);
    }
    $providerMessages['renewal_notice'] = $renewal;
    $providerMessages['delivery_reply'] = $delivery;
    $bodyReads = [];
    $triageHttp = static function(string $method,string $url,array $headers,?array $body,bool $form)
        use ($baseline,$triageCursor,$history,$providerMessages,$parent,$delivery,&$bodyReads): array {
        if ($method !== 'GET' || $body !== null || $form || !in_array('Authorization: Bearer fixture-inbox-access',$headers,true)
            || !str_starts_with($url,VP3_GMAIL_API_V1.'/')) throw new LogicException('Unexpected triage provider request.');
        $path = (string)parse_url($url,PHP_URL_PATH);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if (str_ends_with($path,'/history')) {
            return $history(($query['startHistoryId']??null)===$baseline
                ? [inbox_event($triageCursor,array_keys($providerMessages))] : [],$triageCursor);
        }
        if (str_ends_with($path,'/threads/thread_delivery')) {
            return ['status'=>200,'json'=>['id'=>'thread_delivery','messages'=>[$parent,$delivery]]];
        }
        if (str_ends_with($path,'/threads/thread_renewal_notice')) {
            return ['status'=>200,'json'=>['id'=>'thread_renewal_notice','messages'=>[$providerMessages['renewal_notice']]]];
        }
        $id = basename($path);
        if (str_contains($path,'/messages/') && isset($providerMessages[$id])) {
            if (($query['format']??null)==='full') $bodyReads[] = $id;
            return ['status'=>200,'json'=>$providerMessages[$id]];
        }
        throw new LogicException('Unexpected triage provider read.');
    };
    for ($slice=0; $slice<3; $slice++) {
        $due();
        inbox_check(agent_connection_gmail_inbox_sweep_v1($pdo,1,$triageHttp)===['processed'=>1,'reviewed'=>1],
            'the real worker commits a bounded inbox triage slice');
    }
    $classified = agent_email_triage_summary_v1($pdo,$matterScope());
    $groups = array_column($classified['groups'],'count','key');
    $active = agent_email_triage_group_v1($pdo,$matterScope(),'active')['items'];
    $bySubject = array_column($active,null,'subject');
    $dueDate = gmdate('Y-m-d',intdiv($arrived,1000)+86400);
    sort($bodyReads);
    inbox_check(($groups['promotion'] ?? 0)===23,'23 promotions stay in one review group');
    inbox_check($bodyReads===['delivery_reply','renewal_notice'],'only the actionable candidates need a body read');
    inbox_check(($bySubject['Annual renewal']['due_date']??null)===$dueDate,'the body provides the renewal deadline');
    inbox_check(($bySubject['Re: Delivery schedule']['matter_id']??null)===$matterId,'the exact reply advances the existing delivery matter');
    inbox_check(($scanStatus()['total']??null)===25,'the complete scan counts all 25 eligible messages');
    $deliveryState = agent_connection_gmail_inbox_update_deliver_v1($pdo,$owner,0,11);
    $allPosts = $pdo->query("SELECT * FROM chat_messages WHERE conversation_id=11 AND role='assistant' ORDER BY id")->fetchAll();
    $allProjected = agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,11,$allPosts);
    $isRoutine = static fn(array $post): bool => (json_decode((string)$post['context_json'],true)['gmail_inbox_update_v1']['routine_digest']??false)===true;
    $posts = array_values(array_filter($allPosts,static fn(array $post): bool => !$isRoutine($post)));
    $projected = array_values(array_filter($allProjected,static fn(array $post): bool => !$isRoutine($post)));
    $routinePosts = array_values(array_filter($allProjected,$isRoutine));
    $routineItems = array_map(static fn(array $post): array => json_decode($post['context_json'],true)['gmail_inbox_review_v1']['review']['items'],$routinePosts);
    $postedCount = count($allPosts);
    $context = json_decode($projected[0]['context_json']??'',true,16);
    $chatItems = $context['gmail_inbox_review_v1']['review']['items']??[];
    inbox_check(count($notices())===1 && count($posts)===1 && count($chatItems)===2
        && in_array($dueDate,array_column(array_column($chatItems,'triage'),'due_date'),true)
        && ($deliveryState['inbox_updates']['pending']??true)===false
        && !str_contains(json_encode([$allProjected,$classified],JSON_THROW_ON_ERROR),'BODY_PRIVATE_SENTINEL'),
        'the private Chat receives one review with only the two meaningful changes, and no message body is retained');
    $routineRefs=array_column(array_merge(...$routineItems),'item_ref');
    inbox_check($postedCount===4&&array_map('count',$routineItems)===[10,10,3]
        &&count(array_unique($routineRefs))===23
        &&array_filter(array_merge(...$routineItems),static fn(array $item): bool => ($item['triage']['alert']??null)!==false)===[],
        'the same scan preserves all 23 routine headers in bounded separate import receipts without promoting them to attention alerts');

    require_once dirname(__DIR__).'/includes/agent-initiative-specialist-reports-v1.php';
    $planningChat=['conversation_id'=>11,'revision'=>str_repeat('a',32)];
    $reportProof=[];
    $specialistReports=vp3_initiative_specialist_reports_v1($pdo,$owner,$planningChat,[],$reportProof);
    $reportKey=array_key_first($reportProof['report_keys']);
    $reportJson=json_encode($specialistReports,JSON_THROW_ON_ERROR);
    inbox_check(count($specialistReports)===1&&$specialistReports[0]['specialist']==='email'
        &&($specialistReports[0]['latest_completed_scan']['new']??null)===25
        &&!str_contains($reportJson,'Annual renewal')&&!str_contains($reportJson,'BODY_PRIVATE_SENTINEL')
        &&is_string($reportKey)&&vp3_initiative_specialist_report_only_v1($pdo,$owner,$planningChat,$reportKey)===$specialistReports,
        'the exact linked Chat can assess only coarse Gmail facts from a verified completed scan');
    $differentChat=array_replace($planningChat,['conversation_id'=>12]);
    inbox_check(vp3_initiative_specialist_reports_v1($pdo,$owner,$differentChat,[],$reportProof)===[]
        &&$reportProof['report_keys']===[], 'another Chat cannot assess that Gmail scan');

    $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET history_id=?,next_check_at='2000-01-01 00:00:00' WHERE owner_user_id=1")
        ->execute([$baseline]);
    for ($slice=0; $slice<3; $slice++) {
        $due();
        agent_connection_gmail_inbox_sweep_v1($pdo,1,$triageHttp);
    }
    agent_connection_gmail_inbox_update_deliver_v1($pdo,$owner,0,11);
    inbox_check(count($notices())===1 && (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$postedCount
        && agent_email_triage_summary_v1($pdo,$matterScope())===$classified
        && $bodyReads===['delivery_reply','renewal_notice'],
        'replaying the verified provider event repeats neither assessment, classification nor notification');

    $historicalOffer = inbox_message('historical_offer',(int)$policy['enabled_at_ms']+1,'offers@example.test',
        'Earlier offer',['INBOX','CATEGORY_PROMOTIONS']);
    $checkBeforeRecovery = intdiv((int)$policy['enabled_at_ms'],1000)+1;
    $recoveredAt = ($checkBeforeRecovery+1)*1000;
    $remainingUs = $recoveredAt*1000-(int)floor(microtime(true)*1000000);
    if ($remainingUs > 0) usleep($remainingUs);
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET last_checked_at=? WHERE owner_user_id=1')
        ->execute([gmdate('Y-m-d H:i:s',$checkBeforeRecovery)]);
    $recoveredOffer = inbox_message('recovered_offer',$recoveredAt,'offers@example.test',
        'Offer received during the missed window',['INBOX','CATEGORY_PROMOTIONS']);
    $recoveryReads = [];
    $recoveryHttp = static function(string $method,string $url,array $headers,?array $body,bool $form)
        use ($mailbox,$triageCursor,$historicalOffer,$recoveredOffer,&$recoveryReads): array {
        if ($method !== 'GET' || $body !== null || $form || !in_array('Authorization: Bearer fixture-inbox-access',$headers,true)
            || !str_starts_with($url,VP3_GMAIL_API_V1.'/')) throw new LogicException('Unexpected recovery provider request.');
        $path = (string)parse_url($url,PHP_URL_PATH);
        $recoveryReads[] = basename($path);
        return match (basename($path)) {
            'history'=>['status'=>404,'json'=>[]],
            'profile'=>['status'=>200,'json'=>['emailAddress'=>$mailbox,'historyId'=>$triageCursor]],
            'messages'=>['status'=>200,'json'=>['messages'=>[['id'=>'promo_1'],['id'=>'historical_offer'],['id'=>'recovered_offer']]]],
            'historical_offer'=>['status'=>200,'json'=>$historicalOffer],
            'recovered_offer'=>['status'=>200,'json'=>$recoveredOffer],
            default=>throw new LogicException('Catch-up must skip an already received exact message.'),
        };
    };
    $beforeRecovery = $scanStatus();
    $due();
    agent_connection_gmail_inbox_sweep_v1($pdo,1,$recoveryHttp);
    $pendingRecovery = agent_connection_gmail_inbox_sync_view_v1($pdo,$reviewRow());
    inbox_check(($pendingRecovery['mode']??null)==='recovery' && ($pendingRecovery['status']??null)==='pending'
        && $recoveryReads===['history','profile'],'the native worker opens a recovery window after verifying the Gmail profile');
    $due();
    agent_connection_gmail_inbox_sweep_v1($pdo,1,$recoveryHttp);
    $finishedRecovery = agent_connection_gmail_inbox_sync_view_v1($pdo,$reviewRow());
    $historicalItems = agent_email_triage_group_v1($pdo,$matterScope(),'historical')['items'];
    inbox_check($recoveryReads===['history','profile','messages','historical_offer','recovered_offer'],
        'recovery skips a previously classified message');
    inbox_check(($finishedRecovery['status']??null)==='complete' && ($finishedRecovery['reviewed']??null)===2,
        'recovery completes with both earlier and missed-window messages saved once');
    inbox_check(count($historicalItems)===2 && in_array('Earlier offer',array_column($historicalItems,'subject'),true),
        'the earlier message remains in the quiet catch-up group');
    inbox_check(count($notices())===1 && (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$postedCount,
        'recovery stays quiet in both notifications and Chat');
    inbox_check($scanStatus()===array_replace($beforeRecovery,['in_progress'=>false]),
        'recovery preserves the last verified live scan while discarding incomplete page counts');
    $recoveredArrival = $arrivalsView();
    inbox_check($recoveredArrival['total']===1 && array_column($recoveredArrival['items'],'subject')===['Offer received during the missed window']
        && agent_connection_gmail_inbox_item_v1($pdo,$owner,$recoveredArrival['items'][0]['item_ref'])['message_id']==='recovered_offer',
        'the expired cursor recovery privately surfaces only the exact arrival missed since the last verified scan');
    $due();
    agent_connection_gmail_inbox_sweep_v1($pdo,1,$triageHttp);
    inbox_check(($scanStatus()['total']??null)===0 && ($scanStatus()['in_progress']??null)===false && $arrivalsView()===$recoveredArrival
        && count($notices())===1 && (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$postedCount,
        'the next verified empty live scan reports zero and posts nothing');

    require_once dirname(__DIR__).'/includes/agent-initiative-email-canvas-v1.php';
    $emailCanvas = static fn(?array $refs=null): array => vp3_initiative_email_canvas_evidence_v1(db(),$owner,$planningChat,$refs);
    inbox_check($emailCanvas()===[], 'a single dated obligation and other inbox volume do not suggest a Canvas');
    $recordDeadline = static function(string $message,string $thread,int $offset,?string $deadline) use ($pdo,$policy,$arrived): void {
        $observation = ['message_id'=>$message,'thread_id'=>$thread,'received_at_ms'=>(string)($arrived+$offset),
            'from'=>'deadline-private@example.test','subject'=>'Private thread '.strtoupper($thread),
            'important'=>false,'reply'=>false,'labels'=>['INBOX']];
        if ($deadline !== null) {
            $observation['assessment']=['status'=>'assessed','reason'=>'deadline','deadline_date'=>$deadline];
        }
        agent_commerce_atomic_pa2($pdo,static fn()=>agent_connection_gmail_inbox_review_v1($pdo,$policy,
            [agent_connection_gmail_inbox_key_v1($policy,$message)=>$observation],agent_commerce_time_pa2()));
    };
    $recordDeadline('canvas_one','canvas_order',10,$dueDate);
    $recordDeadline('canvas_one_updated','canvas_order',20,$dueDate);
    inbox_check($emailCanvas()===[], 'multiple messages from one exact thread do not count as separate obligations');
    $later = gmdate('Y-m-d',intdiv($arrived,1000)+3*86400);
    $recordDeadline('canvas_two','canvas_approval',30,$later);
    $candidate = $emailCanvas();
    $canvasRef = array_key_first($candidate);
    $canvasFacts = $candidate[$canvasRef]['email_canvas']??[];
    $canvasJson = json_encode($candidate,JSON_THROW_ON_ERROR);
    inbox_check(count($candidate)===1 && $canvasFacts['thread_count']===3 && $canvasFacts['deadline_day_count']===2
        && !str_contains($canvasJson,'Private thread') && !str_contains($canvasJson,'deadline-private@example.test')
        && !str_contains($canvasJson,'canvas_order') && !str_contains($canvasJson,'Annual renewal'),
        'three verified deadline threads across two dates produce one private-data-free opportunity');
    inbox_check($emailCanvas([$canvasRef])===$candidate && $emailCanvas(['task:123'])===[]
        && vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$differentChat,[$canvasRef])===[]
        && vp3_initiative_email_canvas_projection_v1($pdo,['id'=>2],$planningChat,$canvasRef)===null,
        'exact revalidation respects the selected source and excludes another Chat or owner');
    $projectCanvas = static fn(): ?array => vp3_initiative_email_canvas_projection_v1(db(),$owner,$planningChat,$canvasRef);
    $beforeRows = $projectCanvas()['rows'];
    inbox_check(count($beforeRows)===3 && array_column($beforeRows,'due_date')===[$dueDate,$dueDate,$later],
        'the exact live view has one row per thread in deadline order');
    $recordDeadline('unrelated_canvas_mail','unrelated_canvas_thread',40,null);
    inbox_check($emailCanvas([$canvasRef])===$candidate && $projectCanvas()['rows']===$beforeRows,
        'unrelated inbox mail leaves the same Canvas opportunity and view unchanged');
    $revisedDate = gmdate('Y-m-d',intdiv($arrived,1000)+4*86400);
    $recordDeadline('canvas_two_updated','canvas_approval',50,$revisedDate);
    $changed = $emailCanvas([$canvasRef]);
    $updatedRows = $projectCanvas()['rows'];
    inbox_check($changed[$canvasRef]['revision']===$candidate[$canvasRef]['revision']
        && array_column($updatedRows,'id')===array_column($beforeRows,'id') && $updatedRows[2]['due_date']===$revisedDate
        && $updatedRows[2]['source_ref']!==$beforeRows[2]['source_ref'],
        'a verified newer deadline updates the live row while the qualifying workflow proof stays stable');
    $operation = str_repeat('e',64);
    $sentAt = intdiv($arrived,1000)+5;
    $receipt = ['thread_id'=>'canvas_approval','message_id'=>'canvas_sent','operation_key'=>$operation,
        'sent_at'=>$sentAt,'connection_id'=>(int)$policy['connection_id'],'authorization_key'=>$policy['authorization_key']];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1 (owner_user_id,connection_id,authorization_key,operation_key,payload_digest,
        status,provider_message_id,provider_thread_id,sent_at) VALUES (1,?,?,?,?,'sent','canvas_sent','canvas_approval',?)")
        ->execute([$receipt['connection_id'],$receipt['authorization_key'],$operation,str_repeat('f',64),$sentAt]);
    $pdo->prepare("INSERT INTO agent_connection_email_followups_v1 (owner_user_id,operation_key,connection_id,authorization_key,
        agent_id,conversation_id,account_email,recipient_email,sent_message_id,thread_id,status,expires_at)
        VALUES (1,?,?,?,0,11,?,?,'canvas_sent','canvas_approval','waiting',?)")
        ->execute([$operation,$receipt['connection_id'],$receipt['authorization_key'],$mailbox,'deadline-private@example.test',
            gmdate('Y-m-d H:i:s',$sentAt+300)]);
    $savedSend=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_matters_note_send_v1($pdo,$policy,$receipt,'canvas_two_updated','Deadline follow-up'));
    inbox_check($savedSend && $projectCanvas()['rows'][2]['stage']==='awaiting_reply',
        'an exact verified send and its registered watch move only the matching Canvas row to waiting');
    $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='delivered',reply_message_id='canvas_reply',reply_chat_message_id=?
        WHERE operation_key=?")->execute([(int)$posts[0]['id'],$operation]);
    $watch = ['owner_user_id'=>1]+$receipt;
    $reply = ['thread_id'=>'canvas_approval','message_id'=>'canvas_reply','received_at_ms'=>($sentAt+1)*1000,
        'from'=>'deadline-private@example.test','subject'=>'The latest verified reply','labels'=>['INBOX']];
    $savedReply=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_matters_note_watched_reply_v1($pdo,$policy,$watch,$reply));
    $afterReply = $projectCanvas()['rows'];
    inbox_check($savedReply && array_slice($afterReply,0,2)===array_slice($updatedRows,0,2)
        && $afterReply[2]['id']===$updatedRows[2]['id'] && $afterReply[2]['stage']==='reply_received'
        && $afterReply[2]['deadline_current']===false,
        'a verified reply updates the same row and leaves its earlier deadline labelled as historical');
    $matter = agent_email_matters_find_v1($pdo,agent_email_matters_policy_scope_v1($policy),$updatedRows[2]['id']);
    agent_email_matters_update_v1($pdo,$owner,$updatedRows[2]['id'],(int)$matter['revision'],'resolve');
    inbox_check($emailCanvas()===[] && ($emailCanvas([$canvasRef])[$canvasRef]['email_canvas']['discovery_qualified']??null)===false
        && count($projectCanvas()['rows'])===2,
        'resolving a matter withdraws automatic discovery while the live board and explicit owner use remain available');
    $pdo->prepare('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=?')->execute([$policy['connection_id']]);
    inbox_check($emailCanvas([$canvasRef])===[] && $projectCanvas()===null,
        'revoking Gmail access prevents both discovery and live Canvas reads');
    $pdo->prepare('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=?')->execute([$policy['connection_id']]);
    $reportSettings = array_replace($settings,['inbox_revision'=>$reviewRow()['revision'],
        'connection_revision'=>hash('sha256',$policy['authorization_key']), 'connection_id'=>(int)$policy['connection_id'],
        'triage_enabled'=>'1','content_analysis'=>'1']);
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$reportSettings,$http)==='inbox_saved',
        'the owner can explicitly enable model analysis of triaged messages');
    $reportPolicy = agent_connection_gmail_inbox_policy_v1($pdo,1);
    $autoSync = agent_connection_gmail_inbox_sync_row_v1($pdo,$reportPolicy);
    inbox_check(($autoSync['mode'] ?? null)==='catchup' && $autoSync['status']==='pending'
        && $reportPolicy['next_check_at']<=agent_commerce_time_pa2(),
        'opting into content analysis schedules a quiet catch-up without a separate catch-up checkbox');
    $emptyCatchUp = static function (string $method,string $url): array {
        if ($method!=='GET' || !str_ends_with((string)parse_url($url,PHP_URL_PATH),'/messages')) {
            throw new LogicException('Expected a historical Gmail list request.');
        }
        return ['status'=>200,'json'=>['messages'=>[]]];
    };
    agent_connection_gmail_inbox_run_due_v1($pdo,$reportPolicy,$emptyCatchUp);
    inbox_check(agent_connection_gmail_inbox_sync_row_v1($pdo,$reportPolicy)['status']==='complete',
        'the automatic catch-up can be completed by the existing worker lease');
    $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET last_status='checked',last_checked_at=?,next_check_at=? WHERE owner_user_id=1")
        ->execute([agent_commerce_time_pa2(),gmdate('Y-m-d H:i:s',time()+300)]);
    $reportBundle = static function () use ($pdo,$owner): ?array {
        $monitor = agent_connection_gmail_inbox_background_status_v1($pdo,$owner,0);
        return $monitor['report'] ?? null;
    };
    $reportView = static fn(): ?array=>$reportBundle()['running_report'] ?? null;
    $reportMail = [];
    $reportAfter = max($arrived+10000,(int)$autoSync['before_ms']+1000);
    $reportInbox = static function (string $id,string $thread,int $offset,string $body,array $labels=['INBOX'])
        use ($pdo,$reportPolicy,$reportAfter,&$reportMail): array {
        $at = $reportAfter+$offset;
        $raw = inbox_message($id,$at,'venue@example.test','A question',$labels);
        $raw['threadId'] = $thread;
        $raw['payload']['mimeType'] = 'text/plain';
        $raw['payload']['body']['data'] = base64_encode($body);
        $reportMail[$id] = $raw;
        $message = ['message_id'=>$id,'thread_id'=>$thread,'received_at_ms'=>(string)$at,'from'=>'venue@example.test',
            'subject'=>'A question','labels'=>$labels,'important'=>false,'reply'=>$offset>1];
        return agent_commerce_atomic_pa2($pdo,static fn()=>agent_connection_gmail_inbox_review_v1($pdo,$reportPolicy,
            [agent_connection_gmail_inbox_key_v1($reportPolicy,$id)=>$message],agent_commerce_time_pa2()));
    };
    $reportInbox('report_ask','report_venue_thread',1,'We would like to host a jukebox session.');
    $quietReview = $reportInbox('report_promotion','report_promo_thread',0,'Routine promotion.',['INBOX','CATEGORY_PROMOTIONS']);
    inbox_check($reportView()===['pending'=>1,'unavailable'=>0,'findings'=>[]],
        'a content-only opportunity queues from unknown mail while routine promotions stay grouped');
    $diagnostics = agent_connection_gmail_diagnostics_v1($pdo,$owner,0,11);
    $stages = array_column($diagnostics['stages'],'state','label');
    $diagnosticJson = json_encode($diagnostics,JSON_THROW_ON_ERROR);
    inbox_check(count($stages)===5 && $stages['Classification']==='complete' && $stages['Content analysis']==='queued'
        && !str_contains($diagnosticJson,'report_venue_thread') && !str_contains($diagnosticJson,'A question')
        && !str_contains($diagnosticJson,'venue@example.test'),
        'Gmail diagnostics identify the queued content stage without exposing inbox data');
    $pdo->exec('CREATE TABLE agent_compute_preferences (user_id INT PRIMARY KEY)');
    $routeRead = agent_connection_gmail_diagnostics_v1($pdo,$owner,0,11);
    $pdo->exec('DROP TABLE agent_compute_preferences');
    inbox_check(array_column($routeRead['stages'],'state','label')['Gmail setup']==='complete'
        && $routeRead['stages'][1]['state']===$diagnostics['stages'][1]['state']
        && $routeRead['stages'][2] === $diagnostics['stages'][2]
        && ($routeRead['stages'][3]['code']??null)==='gmail_analysis_route_unavailable'
        && str_contains($routeRead['stages'][3]['detail'],'1 pending')
        && $routeRead['stages'][4] === $diagnostics['stages'][4],
        'an unreadable default compute preference preserves verified Gmail stages and queued counts');
    $wrongChat = agent_connection_gmail_diagnostics_v1($pdo,['id'=>2,'is_active'=>1],0,11);
    inbox_check(array_column($wrongChat['stages'],'state','label')['Gmail setup']==='blocked'
        && $wrongChat['stages'][3]['state']==='waiting','another owner cannot read diagnostics through a foreign Chat');
    $reportHttp = static function (string $method,string $url) use (&$reportMail): array {
        $path = (string)parse_url($url,PHP_URL_PATH);
        if (str_contains($path,'/threads/')) {
            $threadId = basename($path);
            $thread = array_filter($reportMail,static fn(array $message): bool=>$message['threadId']===$threadId);
            return ['status'=>200,'json'=>['id'=>$threadId,'messages'=>array_values($thread)]];
        }
        $id = basename($path);
        if (!isset($reportMail[$id])) throw new LogicException('Unexpected analysis source.');
        return ['status'=>200,'json'=>$reportMail[$id]];
    };
    $reportModel = static function (PDO $db,array $user,string $prompt,Closure $current): string {
        $current();
        $input = json_decode($prompt,true,flags:JSON_THROW_ON_ERROR);
        $latest = $input['messages'][$input['trigger_ref']-1]['body_excerpt'];
        $confirmed = str_contains($latest,'confirmed');
        $research = str_contains($latest,'public policy');
        if (str_contains($latest,'For information only')) {
            return json_encode(['category'=>'routine','priority'=>'low','state'=>'informational',
                'summary'=>'A venue shared an information-only notice.','importance_reason'=>'No action is requested.',
                'next_step'=>'','followup_question'=>'','evidence_ref'=>$input['trigger_ref'],'handoff'=>'none','handoff_question'=>''],JSON_THROW_ON_ERROR);
        }
        return json_encode(['category'=>'opportunity','priority'=>'high','state'=>$confirmed?'resolved':'open',
            'summary'=>$confirmed?'The venue confirmed the date.':'The venue wants to host a jukebox session.',
            'importance_reason'=>'A venue is considering an event.','next_step'=>$confirmed?'':'Check availability.',
            'followup_question'=>$confirmed || $research?'':'Which dates can you offer?', 'evidence_ref'=>$input['trigger_ref'],
            'handoff'=>$confirmed?'none':($research?'agent_work':'needs_attention'),
            'handoff_question'=>$research?'What is the public venue sound policy?':''],JSON_THROW_ON_ERROR);
    };
    $reportScope = static fn(): array=>agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    $monitorWithChecks = static fn(): array=>agent_connection_gmail_inbox_background_view_v1($pdo,$reportScope(),
        includeResults:false,includeCheckRef:true);
    inbox_check($monitorWithChecks()['state']==='checked' && !isset($monitorWithChecks()['content_check_ref']),
        'a current inbox scan leaves new content work to the server worker during its grace period');
    $pdo->exec("UPDATE agent_email_running_report_v1 SET due_at='2000-01-01 00:00:00' WHERE owner_user_id=1");
    $check = $monitorWithChecks();
    inbox_check($check['state']==='checked' && !isset($check['check_ref'])
        && preg_match('/^[a-f0-9]{64}$/D',$check['content_check_ref']??'')===1,
        'an overdue report thread receives an opaque continuation independent of the inbox scan timestamp');
    $reportCalls = [];
    $fallbackHttp = static function (string $method,string $url) use (&$reportCalls,$reportHttp): array {
        $reportCalls[] = (string)parse_url($url,PHP_URL_PATH);
        return $reportHttp($method,$url);
    };
    $reportRef = $check['content_check_ref'];
    foreach ([null,['agent_id'=>0,'conversation_id'=>999]] as $badChat) {
        agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,$reportRef,$fallbackHttp,$badChat,$reportModel);
    }
    agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,str_repeat('f',64),$fallbackHttp,
        ['agent_id'=>0,'conversation_id'=>11],$reportModel);
    inbox_check($reportCalls===[] && $reportView()['pending']===1,
        'missing or foreign Chat scope and stale report references do not read Gmail');
    $pdo->exec("CREATE TABLE agent_memory_items(id INT PRIMARY KEY,user_id INT NOT NULL,user_agent_id INT NULL,
        memory_type VARCHAR(40),subject VARCHAR(190),is_active INT NOT NULL);
        INSERT INTO agent_memory_items VALUES (1,1,NULL,'initiative_sweep','coordination-scan',1),
            (2,1,7,'initiative_sweep','coordination-scan',1),(3,1,7,'initiative_sweep','office-entry-scan',1),
            (4,2,7,'initiative_sweep','coordination-scan',1)");
    $checkpoints = static fn(): array => array_map('intval',$pdo->query('SELECT is_active FROM agent_memory_items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,$reportRef,$fallbackHttp,
        ['agent_id'=>0,'conversation_id'=>11],$reportModel);
    inbox_check($checkpoints()===[0,0,1,1],
        'saving the exact actionable Gmail result wakes only the owner’s existing coordination checkpoints');
    $pdo->exec('UPDATE agent_memory_items SET is_active=1');
    $firstReportCalls = $reportCalls;
    agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,$reportRef,$fallbackHttp,
        ['agent_id'=>0,'conversation_id'=>11],$reportModel);
    inbox_check(count($reportView()['findings'])===1 && count($firstReportCalls)===2 && $reportCalls===$firstReportCalls
        && $checkpoints()===[1,1,1,1] && str_ends_with($reportCalls[0],'/threads/report_venue_thread')
        && $monitorWithChecks()['last_checked_at']===$check['last_checked_at'],
        'a saved Chat processes one due report thread and rejects replay without rescanning the inbox');
    $contentPosts = static fn(): int => (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%gmail_content_chat_v1%'")->fetchColumn();
    $initialContentPosts = $contentPosts();
    $chatCount = (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
    $diagnostics = agent_connection_gmail_diagnostics_v1($pdo,$owner,0,11);
    inbox_check($initialContentPosts===0 && $diagnostics['stages'][3]['state']==='complete'
        && $diagnostics['stages'][4]['state']==='waiting'
        && !str_contains(json_encode($diagnostics,JSON_THROW_ON_ERROR),'Which dates'),
        'content analysis saves a finding for Top of Mind without creating a standalone Chat turn');
    $firstFinding = $reportView()['findings'][0];
    inbox_check($reportBundle()['handoffs']===['needs_attention'=>[$firstFinding],'agent_work'=>[]],
        'the specialist retains its selected owner question for Top of Mind');
    $legacyUpdate = agent_connection_gmail_inbox_update_deliver_v1($pdo,$owner,0,11);
    inbox_check(($legacyUpdate['inbox_updates']['pending']??true)===false
        && (int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$chatCount,
        'content analysis disables direct header Chat posting and stale client retries');

    $pdo->exec('INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (12,1,NULL),(13,1,NULL),(21,2,NULL)');
    require_once dirname(__DIR__).'/includes/agent-coordination-sources-v1.php';
    $sources = static fn(int $chat): array => vp3_coordination_gmail_sources_v1($pdo,
        ['user'=>$owner,'agent_id'=>0,'conversation_id'=>$chat],['agent_id'=>0,'conversation_id'=>$chat],null)['items'];
    $ref = 'gmail:'.$firstFinding['id'];
    $firstSource = $sources(11)[$ref] ?? [];
    inbox_check(($firstSource['trigger']??null)==='needs_attention'
        && $firstSource['question']==='Which dates can you offer?'
        && $firstSource['next_step']==='Check availability.'
        && $sources(12)[$ref]===$firstSource && $sources(21)===[],
        'Top of Mind sees the same exact actionable source in the Agent’s other private Chat and refuses a foreign Chat');

    $scope = $reportScope();
    $bound = agent_email_matters_policy_scope_v1($scope['policy']);
    $triageRow = agent_email_triage_find_v1($pdo,$bound,$firstFinding['item_ref']);
    $correction = agent_email_triage_correct_v1($pdo,$owner,$firstFinding['item_ref'],(int)$triageRow['revision'],'read_later');
    $quietSource = $sources(11)[$ref];
    inbox_check($correction!==null && $quietSource['trigger']===null && $quietSource['revision']===$firstSource['revision'],
        'a current owner correction changes Chat eligibility without invalidating existing evidence');
    $triageRow = agent_email_triage_find_v1($pdo,$bound,$firstFinding['item_ref']);
    agent_email_triage_correct_v1($pdo,$owner,$firstFinding['item_ref'],(int)$triageRow['revision'],'act_soon');

    // Preserve historical posts from the old delivery path without replaying them on upgrade.
    $savedFinding = $pdo->prepare('SELECT finding_enc FROM agent_email_running_report_v1 WHERE thread_key=?');
    $savedFinding->execute([$firstFinding['id']]);
    $sealed = (string)$savedFinding->fetchColumn();
    $legacyRef = agent_connection_gmail_content_ref_v1($scope,0,12,$firstFinding['id'],$sealed);
    $legacyContext = json_encode(['source'=>'agent_connection_gmail_content','skip_brain_archive'=>true,
        'gmail_content_chat_v1'=>['thread_key'=>$firstFinding['id'],'ref'=>$legacyRef]],JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at)
        VALUES (12,NULL,'assistant',?,?,?)")->execute([VP3_GMAIL_CONTENT_CHAT_MARKER_V1,$legacyContext,agent_commerce_time_pa2()]);
    $legacyPost = $pdo->query('SELECT * FROM chat_messages WHERE conversation_id=12 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $legacyChat = agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,12,[$legacyPost])[0];
    inbox_check($sources(13)[$ref]['legacy_delivered']===true
        && str_contains($legacyChat['message'],'Question for you: Which dates can you offer?')
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,11,[$legacyPost])[0]['message']===VP3_GMAIL_CONTENT_CHAT_MARKER_V1,
        'Top of Mind recognizes an exact legacy delivery while only its original private Chat hydrates the text');
    $reportInbox('report_reply','report_venue_thread',2,'We confirmed Friday.');
    inbox_check($firstFinding['followup_question']==='Which dates can you offer?'
        && $reportView()['findings'][0]['updating']===true
        && $reportBundle()['handoffs']['needs_attention']===[]
        && agent_email_running_report_sweep_v1($pdo,$reportHttp,$reportModel)===1,
        'the running report retains the current finding until a newer thread analysis finishes');
    $result = $reportView();
    $secondFinding = $result['findings'][0];
    inbox_check($result['pending']===0 && count($result['findings'])===1 && $firstFinding['id']===$secondFinding['id']
        && $secondFinding['state']==='resolved' && $secondFinding['item_ref']!==$firstFinding['item_ref']
        && !array_key_exists('evidence_message_id',$secondFinding)
        && agent_email_triage_source_v1($pdo,agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner),
            $secondFinding['item_ref'])['message_id']==='report_reply',
        'a new reply resolves the same finding and its private reference opens the exact evidence');
    $reportInbox('report_research','report_research_thread',1,'The public policy may limit amplified music.');
    inbox_check(agent_email_running_report_sweep_v1($pdo,$reportHttp,$reportModel)===1
        && count($reportView()['findings'])===2 && $reportBundle()['handoffs']['needs_attention']===[]
        && array_column($reportBundle()['handoffs']['agent_work'],'handoff_question')===['What is the public venue sound policy?'],
        'Agent work receives the selected research lead while the resolved finding stays in the full report');
    $pdo->exec('UPDATE agent_memory_items SET is_active=1');
    $reportInbox('report_routine','report_routine_thread',1,'For information only.');
    inbox_check(agent_email_running_report_sweep_v1($pdo,$reportHttp,$reportModel)===1 && $checkpoints()===[1,1,1,1],
        'saving a new information-only Gmail report leaves coordination checkpoints alone');
    $pdo->prepare('DELETE FROM agent_email_running_report_v1 WHERE thread_key=?')
        ->execute([agent_email_matters_source_key_v1($reportPolicy,'thread','report_routine_thread')]);
    $stoppedReview = $reportInbox('report_empty','report_empty_thread',1,'');
    $unreadable = static fn(): string => throw new LogicException('Empty Gmail body must not invoke the model.');
    inbox_check(agent_email_running_report_sweep_v1($pdo,$reportHttp,$unreadable)===1,
        'an empty Gmail body finishes the attempt before sending a model request');
    $scanProjection = static function (array $batch,?string $at=null) use ($pdo,$owner): array {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
        $scope['policy']['last_review_json'] = json_encode($batch,JSON_THROW_ON_ERROR);
        $scanAt = agent_connection_gmail_inbox_status_time_v1($batch['at'])->format('Y-m-d\TH:i:s\Z');
        return agent_connection_gmail_diagnostics_completed_scan_v1($pdo,$scope,['total'=>$batch['total'],'at'=>$at??$scanAt]);
    };
    $quietScan = $scanProjection($quietReview);
    $stoppedScan = $scanProjection($stoppedReview);
    $olderScan = $scanProjection($stoppedReview,'2000-01-01T00:00:00Z');
    inbox_check($quietScan[2]==='gmail_scan_no_current_analysis' && $stoppedScan[2]==='gmail_scan_analysis_links'
        && str_contains($stoppedScan[1],'0 complete, 0 pending, 1 stopped') && !isset($olderScan[2]),
        'exact scan diagnostics distinguish a routine classified arrival from a stopped analysis without attributing a prior saved batch');
    $reportInbox('report_failure','report_failure_thread',1,'A fresh venue request.');
    $denied = static fn(): string => throw vp3_initiative_provider_failure_v1([
        'provider'=>'anthropic','status'=>400,'failure_code'=>'anthropic_credit_balance_low','error'=>'private provider detail']);
    inbox_check(agent_email_running_report_sweep_v1($pdo,$reportHttp,$denied)===1,
        'an evidenced provider credit rejection becomes a scheduled Gmail retry');
    $reportInbox('report_bad_model','report_bad_model_thread',1,'A distinct venue request.');
    inbox_check(agent_email_running_report_sweep_v1($pdo,$reportHttp,static fn(): string=>'private unstructured answer')===1,
        'an unstructured model response becomes a scheduled Gmail retry');
    $failedRows = $pdo->query('SELECT failure_reason,failures,due_at FROM agent_email_running_report_v1 WHERE failures>0 ORDER BY failure_reason')->fetchAll(PDO::FETCH_ASSOC);
    $diagnostics = agent_connection_gmail_diagnostics_v1($pdo,$owner,0,11);
    $analysis = $diagnostics['stages'][3];
    inbox_check(array_column($failedRows,'failure_reason')===['anthropic_credit_balance_low','gmail_no_readable_body','invalid_response']
        && $failedRows[0]['due_at']!==null && $failedRows[1]['due_at']===null && $failedRows[2]['due_at']!==null
        && $analysis['state']==='retrying' && str_contains($analysis['detail'],'Anthropic reported')
        && str_contains($analysis['detail'],'no readable body') && str_contains($analysis['detail'],'required structured result')
        && !str_contains(json_encode($diagnostics,JSON_THROW_ON_ERROR),'private'),
        'analysis records safe, distinct provider, message and format causes without leaking response text');
    $pdo->exec('UPDATE agent_email_running_report_v1 SET failures=4,due_at=NULL,failure_reason=NULL WHERE failures BETWEEN 1 AND 3');
    $diagnostics = agent_connection_gmail_diagnostics_v1($pdo,$owner,0,11);
    $analysis = $diagnostics['stages'][3];
    $current = agent_connection_gmail_diagnostics_packet_v1([
        ['complete',''],['complete',''],['complete',''],[$analysis['state'],$analysis['detail'],$analysis['code']],
        ['waiting','Current verified finding is ready for Chat.','gmail_finding_ready_for_chat'],
    ]);
    inbox_check($analysis['state']==='partial' && str_contains($analysis['detail'],'2 analyzed, 0 pending, 3 stopped')
        && str_contains($analysis['detail'],'2 saved attempts have no specific cause')
        && $current['next_step']==='Current verified finding is ready for Chat.',
        'available findings stay usable and take priority over stopped analyses, including legacy unknown causes');
    $pdo->exec('DELETE FROM agent_email_running_report_v1 WHERE failures>0');
    $manualAt = (int)floor(microtime(true)*1000)-1;
    $manualId = 'report_existing_triage';
    $manualThread = 'report_existing_thread';
    $manual = inbox_message($manualId,$manualAt,'venue@example.test','A question');
    $manual['threadId'] = $manualThread;
    $manual['payload']['mimeType'] = 'text/plain';
    $manual['payload']['body']['data'] = base64_encode('We would like to host a jukebox session.');
    $reportMail[$manualId] = $manual;
    $triage = agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_triage_record_v1($pdo,$reportPolicy,
        ['message_id'=>$manualId,'thread_id'=>$manualThread,'received_at_ms'=>(string)$manualAt,
            'from'=>'venue@example.test','subject'=>'A question','labels'=>['INBOX'],'important'=>false,'reply'=>false],
        agent_commerce_time_pa2()));
    inbox_check(is_array($triage) && $triage['historical']===false,'the manual analysis fixture already has ordinary triage');
    $manualCalls = [];
    $manualHttp = static function (string $method,string $url) use ($reportHttp,$manualId,&$manualCalls): array {
        $path = (string)parse_url($url,PHP_URL_PATH);
        $manualCalls[] = basename($path);
        if (str_ends_with($path,'/messages')) return ['status'=>200,'json'=>['messages'=>[['id'=>$manualId]]]];
        return $reportHttp($method,$url);
    };
    try {
        agent_connection_gmail_inbox_analyze_recent_v1($pdo,$owner,0,999,$manualHttp,$reportModel);
        throw new LogicException('A foreign conversation must not analyze mail.');
    } catch (RuntimeException $error) {
        inbox_check($error->getCode()===403 && $manualCalls===[],'manual analysis rejects an unrelated Chat before reading mail');
    }
    $contentPostCount = (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%gmail_content_chat_v1%'")->fetchColumn();
    agent_connection_gmail_inbox_analyze_recent_v1($pdo,$owner,0,11,$manualHttp,$reportModel);
    $manualSync = agent_connection_gmail_inbox_sync_row_v1($pdo,$reportPolicy);
    inbox_check($manualSync['status']==='complete' && (int)$manualSync['checked']===1
        && in_array($manualThread,$manualCalls,true) && count($reportView()['findings'])===3
        && (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%gmail_content_chat_v1%'")->fetchColumn()===$contentPostCount,
        'manual analysis reads an already-triaged thread body, publishes a finding and keeps the catch-up quiet');
    $manualProviderCalls = $manualCalls;
    $manualSource = array_values(array_filter($sources(13),static fn(array $item): bool => $item['trigger']==='needs_attention'));
    inbox_check(count($manualSource)===1 && $manualSource[0]['legacy_delivered']===false && $manualCalls===$manualProviderCalls
        && $contentPosts()===$contentPostCount,
        'the manual catch-up finding is available to Top of Mind without a new provider call or standalone Chat post');
    unset($reportSettings['content_analysis']);
    $reportSettings['inbox_revision']=$reviewRow()['revision'];
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$reportSettings,$http)==='inbox_saved' && $reportView()===null
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_email_running_report_v1')->fetchColumn()===0
        && agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,12,[$legacyPost])[0]['message']===VP3_GMAIL_CONTENT_CHAT_MARKER_V1,
        'turning off content analysis removes its saved findings while Inbox Review remains enabled');

    // Restore the older deployment's shape: content opt-in saved with no report rows or catch-up.
    $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET rules_json=?,last_status='checked',last_checked_at=?,next_check_at=? WHERE owner_user_id=1")
        ->execute([$reportPolicy['rules_json'],agent_commerce_time_pa2(),gmdate('Y-m-d H:i:s',time()+300)]);
    agent_connection_gmail_inbox_sync_clear_v1($pdo,1);
    $legacyCheck = $monitorWithChecks();
    $bootstrapRef = $legacyCheck['content_check_ref'] ?? '';
    inbox_check($legacyCheck['state']==='checked' && !isset($legacyCheck['check_ref'])
        && preg_match('/^[a-f0-9]{64}$/D',$bootstrapRef)===1,
        'older opted-in accounts with eligible classifications receive one bootstrap reference');
    $emptyReads = 0;
    $legacyEmpty = static function (string $method,string $url) use (&$emptyReads,$emptyCatchUp): array {
        $emptyReads++;
        return $emptyCatchUp($method,$url);
    };
    for ($attempt=0;$attempt<2;$attempt++) {
        agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,$bootstrapRef,$legacyEmpty,
            ['agent_id'=>0,'conversation_id'=>11],$reportModel);
    }
    inbox_check($emptyReads===1 && agent_connection_gmail_inbox_sync_row_v1($pdo,$reportPolicy)['status']==='complete'
        && !isset($monitorWithChecks()['content_check_ref']) && $reportView()['findings']===[],
        'an empty bootstrap leaves a durable checkpoint and replay does not read Gmail again');
    agent_connection_gmail_inbox_sync_clear_v1($pdo,1);
    $manualCalls = [];
    $bootstrapRef = $monitorWithChecks()['content_check_ref'];
    agent_connection_gmail_inbox_check_overdue_v1($pdo,$owner,$bootstrapRef,$manualHttp,
        ['agent_id'=>0,'conversation_id'=>11],$reportModel);
    inbox_check(in_array($manualThread,$manualCalls,true) && count($reportView()['findings'])===1
        && (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%gmail_content_chat_v1%'")->fetchColumn()===$contentPostCount,
        'the legacy bootstrap quietly produces the first content finding from an already-classified email');

    // A saved owner watch overrides routine triage and gets its own private Chat
    // receipt, while unrelated mail remains in the normal bounded intake path.
    $prioritySettings=array_replace($settings,[
        'connection_revision'=>hash('sha256',$connection['authorization_key']),
        'inbox_revision'=>$reviewRow()['revision'],'triage_enabled'=>'1',
        'priority_senders'=>'vip@example.test',
        'priority_domains'=>'example.net',
        'priority_subjects'=>'Action please',
    ]);
    inbox_check(agent_connection_gmail_inbox_save_v1($pdo,$owner,$prioritySettings,$http)==='inbox_saved',
        'existing owner watches can be saved without replacing the GitHub workflow checkbox');
    $priorityPolicy=$reviewRow();
    $priorityTime=agent_commerce_time_pa2();
    $priorityArrived=(string)((int)$priorityPolicy['enabled_at_ms']+1000);
    $mailMeta=static fn(string $id,string $from,string $subject):array=>[
        'message_id'=>$id,'thread_id'=>'thread_'.$id,'received_at_ms'=>$priorityArrived,
        'from'=>$from,'subject'=>$subject,'important'=>false,'reply'=>false,'labels'=>['INBOX']];
    $priorityMessages=[
        'priority-sender-receipt'=> $mailMeta('priority-sender-receipt','VIP <vip@example.test>','Weekly sale'),
        'priority-subject-receipt'=>$mailMeta('priority-subject-receipt','sales@example.net','ACTION PLEASE: event update'),
        'routine-receipt'=>$mailMeta('routine-receipt','routine@other.test','Ordinary newsletter'),
    ];
    $priorityReview=agent_commerce_atomic_pa2($pdo,static function()use($pdo,$priorityPolicy,$priorityMessages,$priorityTime):array{
        $result=agent_connection_gmail_inbox_review_v1($pdo,$priorityPolicy,$priorityMessages,$priorityTime);
        agent_connection_gmail_inbox_batch_save_v1($pdo,$priorityPolicy,json_encode($result,JSON_THROW_ON_ERROR));
        return $result;
    });
    inbox_check($priorityReview['total']===3 && $priorityReview['priority_count']===2
        && $priorityReview['filtered']===0 && !empty($priorityReview['items'][0]['priority_matches'])
        && count($priorityReview['items'][1]['priority_matches']??[])===2
        && empty($priorityReview['items'][2]['priority_matches']),
        'one routine and two owner-watched emails retain exact priority reasons after the native Inbox Review scan');
    $priorityCount=static fn():int=>(int)$pdo->query(
        "SELECT COUNT(*) FROM chat_messages WHERE context_json LIKE '%gmail_inbox_priority_v1%'")->fetchColumn();
    $beforePriorityPosts=$priorityCount();
    agent_connection_gmail_inbox_update_deliver_v1($pdo,$owner,0,11);
    $afterPriorityPosts=$priorityCount();
    inbox_check($afterPriorityPosts===$beforePriorityPosts+2,
        'the existing private Agent Chat receives exactly one independent post for each of two watched messages');
    $prioritySaved=$pdo->query(
        "SELECT id,conversation_id,role,message,context_json FROM chat_messages
         WHERE context_json LIKE '%gmail_inbox_priority_v1%' ORDER BY id DESC LIMIT 2")->fetchAll();
    $prioritySaved=array_reverse($prioritySaved);
    $priorityPublic=agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,11,$prioritySaved);
    $priorityText=implode("\n",array_column($priorityPublic,'message'));
    inbox_check(count($priorityPublic)===2
        && str_contains($priorityText,'vip@example.test')
        && str_contains($priorityText,'Sender domain: example.net')
        && str_contains($priorityText,'Subject phrase: Action please')
        && !str_contains(json_encode($prioritySaved,JSON_THROW_ON_ERROR),'vip@example.test')
        && !str_contains(json_encode($prioritySaved,JSON_THROW_ON_ERROR),'ACTION PLEASE'),
        'Chat reveals exact watched messages only via live permission-checked projections, never its durable placeholder');
    $priorityContext=json_decode($priorityPublic[0]['context_json'],true,24,JSON_THROW_ON_ERROR);
    inbox_check(count($priorityContext['gmail_inbox_review_v1']['review']['items']??[])===1
        && preg_match('/\A[a-f0-9]{64}\z/D',(string)($priorityContext['gmail_inbox_review_v1']['review']['items'][0]['item_ref']??''))===1,
        'each watched Chat post carries one verified Open/Reply item reference');
    agent_connection_gmail_inbox_update_deliver_v1($pdo,$owner,0,11);
    inbox_check($priorityCount()===$afterPriorityPosts,
        'a retry or repeated Chat delivery cannot duplicate per-message priority posts');
    $priorityScope=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
    $priorityWorkflow=['from'=>'notifications@github.com','cc'=>'ci_activity@noreply.github.com',
        'subject'=>'ACTION PLEASE: CI status'];
    inbox_check(agent_connection_gmail_inbox_ignored_v1($priorityWorkflow,$priorityPolicy['rules'])
        && agent_connection_gmail_inbox_priority_matches_v1($priorityWorkflow,$priorityPolicy['rules'])===[],
        'GitHub Actions workflow email exclusion wins over a matching priority subject');
    $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET enabled=0 WHERE owner_user_id=1")->execute();
    $priorityRevoked=agent_connection_gmail_inbox_chat_history_v1($pdo,$owner,0,11,$prioritySaved);
    inbox_check(!str_contains(json_encode($priorityRevoked,JSON_THROW_ON_ERROR),'vip@example.test')
        && !str_contains(json_encode($priorityRevoked,JSON_THROW_ON_ERROR),'ACTION PLEASE'),
        'revoked Inbox Review access immediately hides watched sender and subject details from existing Chat posts');

    echo 'AGENT_CONNECTION_GMAIL_INBOX=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
