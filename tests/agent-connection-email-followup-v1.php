<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-email-followup-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-email-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/notifications.php';

function db(): PDO { return $GLOBALS['followupPdo']; }
function url(string $path): string { return $path; }
function setting(string $key,mixed $default=null): mixed { return $default; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['account.access','chat.access'],true);
}
function table_exists(string $table): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function followup_check(bool $pass,string $label): void
{
    if (!$pass) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function followup_reject(int $status,callable $operation,string $label): void
{
    try { $operation(); }
    catch (DomainException $error) { followup_check($error->getCode()===$status,$label); return; }
    throw new RuntimeException($label);
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$pdo = $GLOBALS['followupPdo'] = new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$database = 'vp3_watch_delivery_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE '.$database);
$root = sys_get_temp_dir().'/vp3-watch-delivery-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$root);
mkdir($root,0700);
register_shutdown_function(static function() use($pdo,$database,$root): void {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec('DROP DATABASE '.$database);
    foreach (['ai-key.php','ai-key.php.lock'] as $file) if (is_file($root.'/private/'.$file)) unlink($root.'/private/'.$file);
    if (is_dir($root.'/private')) rmdir($root.'/private');
    rmdir($root);
});
$pdo->exec("SET time_zone='+00:00';
    CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL);
    INSERT INTO users VALUES (1,1),(2,1);
    CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,is_active INT);
    INSERT INTO user_agents VALUES (7,1,1),(8,1,1),(9,2,1);
    CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED,updated_at DATETIME);
    INSERT INTO chat_conversations VALUES (11,1,7,NULL),(12,1,7,NULL),(13,1,8,NULL),(21,2,9,NULL);
    CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED,
        role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME);
    CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,type VARCHAR(50),title VARCHAR(190),
        body VARCHAR(500),target_url VARCHAR(500),source_type VARCHAR(80),source_id BIGINT UNSIGNED,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE agent_connections_v1(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,provider VARCHAR(32),status VARCHAR(20),
        authorization_key CHAR(64),client_fingerprint CHAR(64),account_email VARCHAR(254),external_account_id VARCHAR(191),
        read_enabled INT,send_enabled INT,followups_enabled INT,
        access_token_enc TEXT,refresh_token_enc TEXT,access_expires_at BIGINT UNSIGNED);
    CREATE TABLE agent_connection_sends_v1(operation_key CHAR(64));");
agent_connection_email_draft_schema_v1($pdo);
agent_connection_email_followup_install_v1($pdo);
$config['connections']['gmail'] = ['client_id'=>'watch-delivery-fixture','client_secret'=>bin2hex(random_bytes(12))];
$grant = bin2hex(random_bytes(32));
$access = bin2hex(random_bytes(24));
$mailbox = 'owner@example.test';
$sender = 'reply@example.test';
$connect = $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (?,?,'gmail','connected',?,?,?,?,1,1,1,?,'',?)");
$connect->execute([17,1,$grant,agent_connections_client_fingerprint_v1(),$mailbox,'fixture-mailbox',ai_encrypt_secret($access),time()+3600]);
$connect->execute([18,2,bin2hex(random_bytes(32)),agent_connections_client_fingerprint_v1(),'other@example.test',
    'other-fixture-mailbox',ai_encrypt_secret(bin2hex(random_bytes(24))),time()+3600]);
$sent = ['operation_key'=>bin2hex(random_bytes(32)),'authorization_key'=>$grant,'connection_id'=>17,'from'=>$mailbox,'to'=>$sender,
    'message_id'=>'sent_example','thread_id'=>'thread_example','sent_at'=>time()-300];
followup_check(agent_connection_email_followup_register_v1($pdo,1,7,11,$sent),'register a verified thread watch');
foreach ([11,12] as $conversation) {
    $list = agent_connection_email_sender_watchlist_v1($pdo,1,7,$conversation);
    agent_connection_email_sender_watchlist_v1($pdo,1,7,$conversation,$sender,true,$list['key']);
}
$nowMs = (int)floor(microtime(true)*1000);
$pdo->prepare("UPDATE agent_connection_email_followups_v1 SET next_check_at='2001-01-01 00:00:00',
    sender_after_ms=CASE WHEN sender_after_ms IS NULL THEN NULL ELSE ? END")->execute([$nowMs-2000]);
$message = static function(string $id,int $at,string $from,string $to,string $rfcId,array $labels,string $parent=''): array {
    $headers = ['From'=>$from,'To'=>$to,'Subject'=>'Exact watched message','Message-ID'=>$rfcId,'In-Reply-To'=>$parent];
    return ['id'=>$id,'threadId'=>'thread_example','internalDate'=>(string)$at,'labelIds'=>$labels,
        'payload'=>['mimeType'=>'text/plain','headers'=>array_map(static fn(string $name,string $value):array => ['name'=>$name,'value'=>$value],
            array_keys($headers),$headers),'body'=>['data'=>base64_encode('Private message body.')]]];
};
$outgoing = $message('sent_example',$nowMs-300000,$mailbox,$sender,'<sent@example.test>',['SENT']);
$incoming = $message('received_example',$nowMs-1000,$sender,$mailbox,'<reply@example.test>',['INBOX'],'<sent@example.test>');
$calls = [];
$beforeFull = null;
$http = static function(string $method,string $url,array $headers,?array $payload,bool $form) use(
    $access,$outgoing,$incoming,&$calls,&$beforeFull): array {
    if ($method!=='GET' || $payload!==null || $form || !str_starts_with($url,VP3_GMAIL_API_V1.'/')
        || !in_array('Authorization: Bearer '.$access,$headers,true)) throw new LogicException('Unexpected fixture transport.');
    [$path,$query] = array_pad(explode('?',substr($url,strlen(VP3_GMAIL_API_V1)),2),2,'');
    parse_str($query,$params);
    $calls[] = [$path,$params['format']??''];
    if ($path === '/messages/received_example' && ($params['format']??'') === 'full' && $beforeFull) $beforeFull();
    $json = match($path) {
        '/threads/thread_example'=>['id'=>'thread_example','messages'=>[$outgoing,$incoming]],
        '/messages'=>['messages'=>[['id'=>'received_example']]],
        '/messages/received_example'=>$incoming,
        default=>throw new LogicException('Unexpected Gmail message in fixture.'),
    };
    return ['status'=>200,'json'=>$json];
};
followup_check(agent_connection_email_followup_sweep_v1($pdo,5,$http)===['processed'=>3,'delivered'=>2],
    'thread and sender watches share one delivery per matched email in each originating Chat');
followup_check(agent_connection_email_followup_sweep_v1($pdo,5,$http)===['processed'=>0,'delivered'=>0]
    && (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn()===2,
    'worker replay leaves exactly one owner notification per Chat');
$rows = $pdo->query('SELECT * FROM chat_messages ORDER BY conversation_id')->fetchAll();
followup_check(count($rows)===2 && !str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'received_example')
    && !str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'Private message body'),
    'durable Chat stores neither Gmail ID nor private body');
$id = (int)$rows[0]['id'];
$history = static fn(int $owner=1,int $agent=7,int $conversation=11,array $messages=[]): array => agent_connection_email_followup_history_v1(
    $pdo,['id'=>$owner],$agent,$conversation,$messages?:[$rows[0]]);
$actions = static fn(array $response): array => json_decode($response[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['actions']??[];
$hydrated = $history();
followup_check($actions($hydrated)===[['type'=>'gmail_followup','message_id'=>$id,'conversation_id'=>11,'can_reply'=>true]]
    && $actions($history(messages:$hydrated))===$actions($hydrated) && !$actions($history(conversation:12))
    && !$actions($history(2,9,21)),'reload provides one exact action group and never transfers it to another Chat or owner');
$open = static fn(): array => agent_connection_email_followup_open_v1($pdo,1,7,11,$id,$http);
followup_check($open()===['subject'=>'Exact watched message','from'=>$sender,'body'=>'Private message body.']
    && end($calls)===['/messages/received_example','full'], 'Open reads precisely the delivered Gmail message');
$resolve = static fn(): array => agent_connection_email_followup_message_v1($pdo,1,7,11,$id);
followup_check($resolve()===['message_id'=>'received_example','connection_id'=>17,'authorization_key'=>$grant],
    'Reply and Auto Compose resolve the same provider message and Gmail grant');
$user = ['id'=>1,'is_active'=>1];
$principal = ['kind'=>'user_agent','viewer_user_id'=>1,'owner_user_id'=>1,'agent_id'=>7];
$connection = agent_connection_email_chat_record_v1(1,17,'send');
$replyToNotice = static function(string $command,int $conversation=11) use($pdo,$user,$principal,$connection,$http): array {
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,created_at) VALUES (?,1,'user',?,UTC_TIMESTAMP())")
        ->execute([$conversation,$command]);
    $turn = (int)$pdo->lastInsertId();
    return agent_connection_email_chat_reply_v1($pdo,$user,$principal,$conversation,$turn,
        agent_connection_email_chat_plan_v1($command),$connection,null,$http,null);
};
foreach (['Reply'=>'prepared','Draft reply'=>'write_queued'] as $command=>$expectedStatus) {
    $result = $replyToNotice($command.' to Gmail follow-up '.$id);
    $draftQuery = $pdo->prepare('SELECT * FROM agent_connection_email_drafts_v1 WHERE id=?');
    $draftQuery->execute([$result['actions'][0]['id']]);
    $draft = $draftQuery->fetch();
    $payload = agent_connection_email_chat_payload_v1($draft);
    followup_check($result['actions'][0]['type']==='email_review' && $draft['status']===$expectedStatus
        && $draft['recipient']===$sender && $draft['authorization_key']===$grant && $payload['body']===''
        && $payload['reply']['message_id']==='received_example' && $payload['reply']['thread_id']==='thread_example'
        && end($calls)===['/messages/received_example','metadata'],
        $command.' prepares an unsent reply bound to the exact original message and thread');
}
$previousCalls = $calls;
followup_reject(404,static fn()=>$replyToNotice('Reply to Gmail follow-up '.$id,12),'a different Chat cannot prepare a reply to the notice');
followup_check($previousCalls===$calls,'a cross-Chat reply is rejected before reading Gmail');
followup_reject(404,static fn()=>agent_connection_email_followup_message_v1($pdo,1,8,13,$id),'another Agent cannot resolve a watch notice');
followup_reject(404,static fn()=>agent_connection_email_followup_message_v1($pdo,2,9,21,$id),'another owner cannot resolve a watch notice');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1'] = ['owner'=>1,'participant_id'=>1];
followup_reject(404,$resolve,'participant mode cannot resolve a private Gmail notice');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$pdo->exec('UPDATE agent_connections_v1 SET send_enabled=0');
followup_check($actions($history())[0]['can_reply']===false,'a read-only mailbox keeps Open and removes reply authority');
$beforeFull = static fn()=>$pdo->exec('UPDATE agent_connections_v1 SET followups_enabled=0');
followup_reject(403,$open,'a grant revoked during the provider read prevents private body disclosure');
followup_check(!$actions($history()) && !str_contains($history()[0]['message'],$sender),
    'revoked grant clears actions and redacts old sender text on reload');
$pdo->prepare('UPDATE agent_connections_v1 SET followups_enabled=1,authorization_key=?')->execute([bin2hex(random_bytes(32))]);
followup_reject(404,$resolve,'reconnecting Gmail cannot redirect a stale notice to a new grant');

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-triage-v1.php';
agent_connection_gmail_inbox_install_v1($pdo);
agent_connection_gmail_inbox_receipts_install_v1($pdo);
agent_connection_gmail_inbox_initial_install_v1($pdo);
agent_email_matters_install_v1($pdo);
agent_email_triage_install_v1($pdo);
$grant = $pdo->query('SELECT authorization_key FROM agent_connections_v1 WHERE id=17')->fetchColumn();
$pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
    (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
    VALUES (1,17,?,1,?,?,?)")->execute([$grant,'{"triage_enabled":true}',str_repeat('b',32),$nowMs-60000]);
$scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
$policy = $scope['policy'];
$matterScope = agent_email_matters_bound_scope_v1($scope);
$at = agent_commerce_time_pa2();
$email = static fn(string $id): array => [
    'message_id'=>$id,'thread_id'=>'thread_'.$id,'received_at_ms'=>$nowMs-1000,'from'=>$sender,'to'=>$mailbox,
    'subject'=>'Urgent permit renewal '.$id,'labels'=>['INBOX'],'reply'=>true,'important'=>false,
    'in_reply_to'=>'<sent_'.$id.'@example.test>','references'=>'','rfc_message_id'=>'<'.$id.'@example.test>',
    'assessment'=>['status'=>'assessed','reason'=>'deadline','deadline_date'=>'2031-02-03'],
];
$batch = static function(array ...$emails) use ($pdo,$policy,$scope,$at): array {
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$policy,$scope,$at,$emails): array {
        $messages = [];
        foreach ($emails as $email) $messages[hash('sha256',$email['message_id'])] = $email;
        $review = agent_connection_gmail_inbox_review_v1($pdo,$policy,$messages,$at);
        $raw = json_encode($review,JSON_THROW_ON_ERROR);
        $notice = agent_connection_gmail_inbox_notify_v1($pdo,1,$review,$policy);
        agent_connection_gmail_inbox_batch_save_v1($pdo,$policy,$raw,$notice);
        return agent_connection_gmail_inbox_batch_v1($pdo,$scope,agent_connection_gmail_inbox_batch_id_v1($policy,$raw));
    });
};
$post = static fn(): int => agent_commerce_atomic_pa2($pdo,static fn(): int =>
    agent_connection_gmail_inbox_update_post_v1($pdo,$scope,7,11));
$watchFor = static function(array $email,int $chat=11) use ($pdo,$grant,$sender,$mailbox,$policy,$matterScope,$at,$nowMs): array {
    $operation = bin2hex(random_bytes(32));
    $anchor = ['message_id'=>'sent_'.$email['message_id'],'thread_id'=>$email['thread_id'],'received_at_ms'=>$nowMs-300000,
        'from'=>$mailbox,'to'=>$sender,'rfc_message_id'=>$email['in_reply_to'],'labels'=>['SENT']];
    $sent = $anchor + ['operation_key'=>$operation,'authorization_key'=>$grant,'connection_id'=>17,'sent_at'=>intdiv($nowMs,1000)-300];
    if (!agent_connection_email_followup_register_v1($pdo,1,7,$chat,$sent)) throw new RuntimeException('Watch fixture registration failed.');
    $matter = agent_email_matters_source_key_v1($policy,'thread',$email['thread_id']);
    agent_email_matters_add_v1($pdo,$matterScope,$matter,$email['thread_id'],$email['subject'],$sender,$at);
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='awaiting_reply',last_operation_key=?,last_sent_at=? WHERE matter_id=?")
        ->execute([$operation,$sent['sent_at'],$matter]);
    $claim = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE operation_key=?')
        ->execute([$claim,gmdate('Y-m-d H:i:s',time()+300),$operation]);
    $lookup = $pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE operation_key=?');
    $lookup->execute([$operation]);
    $watch = $lookup->fetch();
    $reply = agent_connection_email_followup_match_v1($watch,[$anchor,$email]);
    if ($reply === null) throw new RuntimeException('Watch fixture could not verify the Gmail reply.');
    return [$watch,$claim,$reply];
};
$follow = static fn(array $fixture): bool => agent_connection_email_followup_deliver_v1($pdo,...$fixture);
$lookupBatch = static fn(array $row): array => agent_connection_gmail_inbox_batch_v1($pdo,$scope,$row['batch_id']);
$browserEmail = $email('browser_first');
$browserWatch = $watchFor($browserEmail);
$browserBatch = $batch($browserEmail);
followup_check($post()===1 && !$follow($browserWatch),'a verified watch reuses the browser-first Inbox Chat notice');
$browserPost = (int)$lookupBatch($browserBatch)['message_id'];
$watched = $pdo->prepare("SELECT status,reply_message_id,reply_chat_message_id FROM agent_connection_email_followups_v1 WHERE operation_key=?");
$watched->execute([$browserWatch[0]['operation_key']]);
$receipt = $watched->fetch();
followup_check($receipt['status']==='delivered' && $receipt['reply_message_id']==='browser_first' && (int)$receipt['reply_chat_message_id']===$browserPost
    && (int)$pdo->query("SELECT COUNT(*) FROM agent_email_matter_events_v1 WHERE event_kind='watched_reply' AND chat_message_id=$browserPost")->fetchColumn()===1
    && (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE source_type='agent_connection_email_followup' AND source_id=$browserPost")->fetchColumn()===0,
    'the shared notice keeps the exact watch receipt and matter event with no duplicate bell');

$delayedEmail = $email('watch_first_pending');
$delayedWatch = $watchFor($delayedEmail);
$delayedBatch = $batch($delayedEmail);
followup_check($follow($delayedWatch) && $post()===0 && (int)$lookupBatch($delayedBatch)['message_id']===0
    && !agent_connection_gmail_inbox_update_has_pending_v1($pdo,$scope)
    && (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE id='.(int)$delayedBatch['notification_id'])->fetchColumn()===0,
    'a delayed batch covered by a native notice retires without a second Chat post or bell');

$mixedEmail = $email('watch_first_mixed');
$mixedWatch = $watchFor($mixedEmail);
$unwatched = ['subject'=>$mixedEmail['subject']]+$email('unwatched_urgent');
$mixedBatch = $batch($mixedEmail,$unwatched);
followup_check($follow($mixedWatch) && $post()===1,'a partly covered Inbox batch still posts its uncovered alert');
$mixedPost = (int)$lookupBatch($mixedBatch)['message_id'];
$context = json_decode((string)$pdo->query("SELECT context_json FROM chat_messages WHERE id=$mixedPost")->fetchColumn(),true,12);
$mixedView = agent_connection_gmail_inbox_update_history_v1($pdo,$scope,7,11,$context,$mixedPost);
$mixedReport = agent_connection_gmail_inbox_batch_report_v1($mixedBatch['report_json']);
$unwatchedRef = agent_connection_gmail_inbox_item_reference_v1($scope,'batch:'.$mixedBatch['batch_id'],1,$mixedReport['items'][1]);
followup_check($mixedView['review']['total']===1 && $mixedView['review']['items'][0]['item_ref']===$unwatchedRef
    && !str_contains(json_encode($context),'watch_first_mixed')
    && $pdo->query('SELECT body FROM notifications WHERE id='.(int)$mixedBatch['notification_id'])->fetchColumn()==='One Gmail message needs your attention.',
    'history and bell retain the exact uncovered alert even when its sender and subject match the covered item');

$otherEmail = $email('other_chat');
$otherWatch = $watchFor($otherEmail,12);
$otherBatch = $batch($otherEmail);
followup_check($follow($otherWatch) && $post()===1 && (int)$lookupBatch($otherBatch)['conversation_id']===11,
    'a watch notice in a different private Chat cannot cover the current Chat alert');

$groupedEmails = [$email('shared_one'),$email('shared_two')];
$groupedWatches = array_map($watchFor,$groupedEmails);
$grouped = $batch(...$groupedEmails);
followup_check($post()===1 && !$follow($groupedWatches[0]) && !$follow($groupedWatches[1]),
    'two distinct verified watches can reuse their exact items in one grouped Chat update');
$groupedPost = (int)$lookupBatch($grouped)['message_id'];
followup_reject(404,static fn()=>agent_connection_email_followup_message_v1($pdo,1,7,11,$groupedPost),
    'a grouped Chat post cannot choose between two distinct watch receipts');
agent_commerce_atomic_pa2($pdo,static fn()=>agent_connection_gmail_inbox_revoke_v1($pdo,1,17,$grant));
$browserRows = $pdo->query("SELECT * FROM chat_messages WHERE id=$browserPost")->fetchAll();
$fallback = agent_connection_email_followup_history_v1($pdo,$user,7,11,$browserRows);
followup_check(($actions($fallback)[0]['message_id'] ?? null)===$browserPost
    && agent_connection_email_followup_message_v1($pdo,1,7,11,$browserPost)['message_id']==='browser_first',
    'a single verified shared watch remains actionable under its independent grant after Inbox Review is disabled');
