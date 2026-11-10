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
