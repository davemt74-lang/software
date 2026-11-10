<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-email-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/agent-tool-json-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $name,mixed $fallback=''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function has_permission(string $permission,array $user): bool { return !empty($user['is_active']); }
function table_exists(string $table): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function writing_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS '.$label.PHP_EOL;
}
function writing_reject(int $code,callable $work,string $label): void
{
    try { $work(); }
    catch (RuntimeException|DomainException $error) {
        if ((int)$error->getCode() !== $code) throw $error;
        writing_check(true,$label);
        return;
    }
    throw new RuntimeException($label);
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Native MySQL is required for the Gmail writing fixture.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn,$login,$password,$options);
$database = 'vp3_gmail_writer_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot = sys_get_temp_dir().'/vp3-gmail-writer-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);
mkdir($fixtureRoot,0700);
$config = ['connections'=>['gmail'=>['client_id'=>'gmail-writer-fixture','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://writer-fixture.example.test/connection-oauth.php']]];

try {
    $pdo = new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL);
        INSERT INTO chat_conversations VALUES (11,1,NULL),(12,1,NULL),(21,2,NULL);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL)");
    agent_connections_install_v1($pdo);
    $grant = str_repeat('a',64);
    $insert = $pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,external_account_id,account_email,status,read_enabled,send_enabled,
        followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (7,1,'gmail','fixture-mailbox','owner@example.test','connected',1,1,1,?,?,?,'',?)");
    $insert->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('transport-fixture'),time()+3600]);
    $owner = ['id'=>1,'is_active'=>1];
    $principal = ['kind'=>'system','agent_id'=>0];
    $providerId = 'exact_original';
    $source = 'A private fixture message.';
    $requests = [];
    $transport = static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$requests,$providerId,$source): array {
        if ($method !== 'GET' || $payload !== null || $form) throw new RuntimeException('Drafting must not send email.');
        $path = (string)parse_url($url,PHP_URL_PATH);
        parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        if (str_ends_with($path,'/messages')) {
            $requests[] = 'list';
            return ['status'=>200,'json'=>['messages'=>[['id'=>$providerId]]]];
        }
        if (!str_ends_with($path,'/messages/'.$providerId)) throw new RuntimeException('Incorrect exact source.');
        $requests[] = $query['format'];
        return ['status'=>200,'json'=>['id'=>$providerId,'threadId'=>'exact_thread','labelIds'=>['INBOX'],
            'internalDate'=>(string)(time()*1000),'snippet'=>$source,'payload'=>['mimeType'=>'text/plain',
                'body'=>['data'=>rtrim(strtr(base64_encode($source),'+/','-_'),'=')],
                'headers'=>[['name'=>'From','value'=>'sender@example.test'],['name'=>'Subject','value'=>'Original subject'],
                    ['name'=>'Message-ID','value'=>'<original@example.test>']]]]];
    };
    $chat = static fn(string $query,int $turn,bool $voice=false,bool $web=false): array => agent_connection_email_chat_v1(
        $pdo,$owner,$principal,$query,11,$turn,null,$voice,$transport,null,$web);
    $act = static fn(string $id,string $action,array $extra=[],?callable $model=null): array => agent_connection_email_chat_action_v1(
        $pdo,$owner,$principal,11,['draft_id'=>$id,'action'=>$action]+$extra,'writer-fixture-session',$transport,$model);

    $spoken = $chat('Show Gmail from sender at example dot test',1,true,true);
    $typed = $chat('Show Gmail from sender@example.test',2);
    if (empty($spoken['actions']) || empty($typed['actions'])) throw new RuntimeException('Gmail voice/typed action was not prepared. '
        .($spoken['answer']??'No spoken result').' '.($typed['answer']??'No typed result'));
    $ref = $spoken['actions'][0]['item_ref'];
    writing_check($spoken['email_private_read_v1'] && $spoken['answer']===$typed['answer']
        && agent_connection_email_chat_search_item_v1($pdo,1,0,11,$ref)['message_id']===$providerId
        && $chat('Show Gmail from sender at example dot test',3,true)['tool_receipt']['status']==='blocked',
        'owner web voice and typed Chat receive the same actionable Gmail result while audio-only callers stay private');
    writing_reject(404,static fn()=>agent_connection_email_chat_search_item_v1($pdo,1,0,12,$ref),'a search result cannot be opened from a different Chat');
    $opened = agent_connection_email_chat_open_search_v1($pdo,$owner,$principal,11,$ref,$transport);
    writing_check($opened['body']===$source && $opened['can_reply'],'the search action opens the exact body and reply capability');

    $command = 'Draft reply to Gmail search item '.$ref;
    $before = count($requests);
    $queued = $chat($command,4);
    $draft = $queued['actions'][0]['id'];
    writing_check($queued['actions']===$chat($command,4)['actions'] && count($requests)===$before+1
        && $act($draft,'review')['status']==='write_queued' && agent_connection_email_chat_writing_status_v1($pdo,$owner,$principal,11)===null,
        'Auto Compose saves one queued exact reply without reading its body or running inference in the Chat request');
    $calls = 0;
    $compose = static function (string $json,array $service,array $control) use ($pdo,$owner,$principal,$act,$draft,$source,&$calls): array {
        $calls++;
        $data = json_decode($json,true,8,JSON_THROW_ON_ERROR);
        writing_check($data['original_message']['excerpt']===$source && $service['service']==='conversation'
            && $control['before_gmail_reply_dispatch']() && !$control['before_gmail_reply_dispatch'](),
            'the writer dispatches the original message once under its metered owner lease');
        writing_check(agent_connection_email_chat_writing_status_v1($pdo,$owner,$principal,11)['work']==='compose'
            && agent_connection_email_chat_writing_status_v1($pdo,$owner,$principal,12)===null,
            'only the originating Chat reports a live Email Specialist lease');
        writing_check($act($draft,'advance_write',[],static fn()=>throw new RuntimeException('Duplicate dispatch'))['status']==='writing',
            'a second advance sees the existing lease without invoking a model');
        return ['ok'=>true,'answer'=>'{"body":"Thanks for the update."}'];
    };
    $prepared = $act($draft,'advance_write',[],$compose);
    writing_check($calls===1 && $prepared['status']==='prepared' && $prepared['body']==='Thanks for the update.'
        && agent_connection_email_chat_writing_status_v1($pdo,$owner,$principal,11)===null,
        'the worker publishes an editable draft for approval and clears progress when it finishes');
    $revision = ['review_token'=>$prepared['review_token'],'instruction'=>'Make it friendlier.'];
    $act($draft,'revise_ai',$revision);
    writing_reject(409,static fn()=>$act($draft,'revise_ai',$revision),'stale approval cannot enqueue a duplicate revision');
    $failure = $act($draft,'advance_write',[],static fn()=>['ok'=>false]);
    writing_check($failure['status']==='write_failed' && $failure['body']===$prepared['body'],'a failed AI revision keeps the original draft and reports failure');
    $act($draft,'retry_write',['review_token'=>$failure['review_token']]);
    $after = $act($draft,'advance_write',[],static function(string $json,array $service,array $control): array {
        $data = json_decode($json,true,8,JSON_THROW_ON_ERROR);
        writing_check($data['current_draft']['body']==='Thanks for the update.' && $data['owner_instruction']==='Make it friendlier.'
            && !isset($data['original_message']) && $control['before_gmail_reply_dispatch'](),'explicit retry revises the saved body using the same owner direction');
        return ['ok'=>true,'answer'=>'{"body":"Thanks so much for the update!"}'];
    });
    writing_check($after['body']==='Thanks so much for the update!' && $after['subject']===$prepared['subject']
        && $after['to']===$prepared['to'] && $after['review_token']!==$prepared['review_token'],
        'AI revision preserves the original thread, recipient and subject and requires a new send approval');
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=?')->execute([str_repeat('b',64)]);
    writing_reject(409,static fn()=>agent_connection_email_chat_open_search_v1($pdo,$owner,$principal,11,$ref,$transport),
        'reconnecting invalidates the old exact Gmail action');
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
