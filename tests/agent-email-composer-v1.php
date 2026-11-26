<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-email-composer-v1.php';
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
    $query=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);return (bool)$query->fetchColumn();
}
function composer_check(bool $ok,string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['composer_checks'] = ($GLOBALS['composer_checks'] ?? 0)+1;
    echo 'PASS '.$label.PHP_EOL;
}
function composer_reject(int $code,callable $work,string $label): void
{
    try { $work(); }
    catch (RuntimeException|DomainException $error) {
        if ((int)$error->getCode() !== $code) throw $error;
        composer_check(true,$label);return;
    }
    throw new RuntimeException($label);
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Native MySQL is required for the email composer fixture.');
$login=getenv('VP3_TEST_MYSQL_USER') ?: 'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);
$database='vp3_email_composer_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot=sys_get_temp_dir().'/vp3-email-composer-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$fixtureRoot);mkdir($fixtureRoot,0700);
$config=['connections'=>['gmail'=>['client_id'=>'composer-fixture','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://composer-fixture.example.test/connection-oauth.php']]];

try {
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL); INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,NULL),(12,1,NULL),(21,2,NULL);
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);
    $grant=str_repeat('a',64);
    $pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,external_account_id,account_email,status,read_enabled,send_enabled,
        followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (7,1,'gmail','fixture-mailbox','owner@example.test','connected',1,1,1,?,?,?,'',?)")
        ->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('fixture-access'),time()+3600]);
    $owner=['id'=>1,'is_active'=>1];$principal=['kind'=>'system','agent_id'=>0];$key='private-composer-fixture-key';
    $noProvider=static fn()=>throw new RuntimeException('A draft action unexpectedly contacted Gmail.');
    $act=static fn(array $input,?callable $model=null,?callable $transport=null): array => agent_connection_email_chat_action_v1(
        $pdo,$owner,$principal,11,$input,$key,$transport ?? $noProvider,$model);
    $review=static fn(string $id): array => $act(['action'=>'review','draft_id'=>$id]);
    $save=static fn(array $draft,string $to,string $subject,string $body,array $ids=[]): array => $act([
        'action'=>'compose_save','draft_id'=>$draft['id'],'review_token'=>$draft['review_token'],'to'=>$to,'subject'=>$subject,'body'=>$body,'attachment_ids'=>$ids]);

    foreach (['Compose email','Write a new email','New email'] as $command) {
        composer_check(agent_connection_email_chat_plan_v1($command)===['operation'=>'draft','to'=>'','instruction'=>''],
            $command.' opens a blank draft without inventing a recipient');
    }
    $create=['action'=>'compose_create','request_id'=>str_repeat('c',32)];
    $empty=$act($create);$again=$act($create+['body'=>'An ignored replay payload']);
    composer_check($empty['id']===$again['id'] && $again['body']==='' && $empty['can_edit'] && !$empty['can_send']
        && $empty['persistent'] && $empty['saved_at']!==null && $empty['attachments']===[],
        'idempotent creation saves one editable incomplete draft');
    $origin=$pdo->query('SELECT * FROM chat_messages')->fetchAll();
    composer_check(count($origin)===1 && $origin[0]['role']==='user' && $origin[0]['message']==='Compose a new email.'
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_drafts_v1')->fetchColumn()===1,
        'direct compose creates exactly one real generic owner request in the same transaction');
    composer_reject(422,static fn()=>$act(['action'=>'confirm','draft_id'=>$empty['id'],'review_token'=>$empty['review_token'],
        'confirmed'=>true,'watch_replies'=>false]),'an incomplete draft cannot create a send claim or reach Gmail');
    composer_check((int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,'incomplete send leaves no provider receipt');
    $partial=$save($empty,'','','A private draft sentence.');
    composer_check($partial['body']==='A private draft sentence.' && !$partial['can_send'] && $partial['can_edit']
        && $partial['edit_revision']!==$empty['edit_revision'],
        'Save draft accepts incomplete content for later editing');
    $saved=$save($partial,'recipient@example.test','Private subject','A private draft sentence.');
    composer_check($saved['can_send'] && $saved['review_token']!==$partial['review_token']
        && $saved['edit_revision']!==$partial['edit_revision'],'a complete saved revision has a fresh approval and content fingerprint');
    $row=agent_connection_email_chat_draft_v1($pdo,1,0,11,$saved['id']);
    composer_check((int)$row['expires_at']===0 && !str_contains($row['message_enc'],'private draft sentence')
        && !str_contains(json_encode($origin),'private draft sentence'), 'saved drafts persist without an arbitrary deadline and keep text encrypted outside Chat history');
    $later=agent_connection_email_chat_public_draft_v1($row,$key,time()+86400*365);
    composer_check($later['status']==='prepared' && $later['can_edit'] && $later['edit_revision']===$saved['edit_revision']
        && $later['review_token']!==$saved['review_token'],
        'a saved draft remains resumable with a stable content fingerprint when short-lived approvals are renewed');
    composer_reject(409,static fn()=>$save($partial,'attacker@example.test','Changed','Changed'),'stale saves cannot overwrite a newer recipient or body');
    composer_reject(409,static fn()=>$act(['action'=>'confirm','draft_id'=>$saved['id'],'review_token'=>$partial['review_token'],
        'confirmed'=>true,'watch_replies'=>false]),'an older review cannot approve the new recipient/content snapshot');
    composer_reject(422,static fn()=>$save($saved,"recipient@example.test\r\nBcc: other@example.test",'Subject','Body'),
        'recipient header injection is rejected');
    composer_reject(404,static fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,12,
        ['action'=>'review','draft_id'=>$saved['id']],$key,$noProvider),'a second Chat cannot resume the first Chat draft');
    composer_reject(404,static fn()=>agent_connection_email_chat_action_v1($pdo,['id'=>2,'is_active'=>1],$principal,21,
        ['action'=>'review','draft_id'=>$saved['id']],$key,$noProvider),'another owner cannot access the saved draft');
    $list=agent_email_composer_list_v1($pdo,$owner,$principal,11,$key);
    composer_check(count($list['drafts'])===1 && $list['from']==='owner@example.test' && $list['can_send']
        && !array_key_exists('body',$list['drafts'][0])
        && agent_email_composer_list_v1($pdo,$owner,$principal,12,$key)['drafts']===[],
        'draft listing is bounded to the current private Chat and omits body text');

    $queued=$act(['action'=>'compose_generate','draft_id'=>$saved['id'],'review_token'=>$saved['review_token'],'instruction'=>'Write a concise meeting request.']);
    composer_check($queued['status']==='write_queued' && !$queued['can_send'] && !$queued['can_edit']
        && $queued['body']===$saved['body'],'AI requests save their instruction before dispatch and preserve current text');
    $modelCalls=0;
    $model=static function(string $json,array $service,array $control) use (&$modelCalls,$act,$queued): array {
        $modelCalls++;$data=json_decode($json,true,8,JSON_THROW_ON_ERROR);
        composer_check($service['service']==='conversation' && $service['stage']==='vp3.email-draft.v1'
            && $control['protocol']==='vp3.email-draft.v1' && $control['before_gmail_reply_dispatch']()
            && !$control['before_gmail_reply_dispatch']() && !isset($data['original_message'],$data['to'],$data['attachments']),
            'new-mail inference uses a metered owner lease with no mailbox message, recipient, or attachment bytes');
        $nested=$act(['action'=>'advance_write','draft_id'=>$queued['id']],static fn()=>throw new RuntimeException('Duplicate model call'));
        composer_check($nested['status']==='writing','a concurrent advance reuses the active writing lease');
        return ['ok'=>true,'answer'=>'{"subject":"Meeting request","body":"Would Friday work for a meeting?"}'];
    };
    $written=$act(['action'=>'advance_write','draft_id'=>$queued['id']],$model);
    composer_check($modelCalls===1 && $written['status']==='prepared' && $written['subject']==='Meeting request'
        && $written['body']==='Would Friday work for a meeting?' && $written['to']==='recipient@example.test' && $written['can_send'],
        'one AI attempt updates subject/body while preserving the owner-selected recipient');
    $act(['action'=>'compose_generate','draft_id'=>$written['id'],'review_token'=>$written['review_token'],'instruction'=>'Make it warmer.']);
    $failed=$act(['action'=>'advance_write','draft_id'=>$written['id']],static fn()=>['ok'=>false]);
    composer_check($failed['status']==='write_failed' && $failed['body']===$written['body'] && $failed['can_edit'],
        'an AI failure preserves the saved draft and remains editable');
    $act(['action'=>'retry_write','draft_id'=>$failed['id'],'review_token'=>$failed['review_token']]);
    $written=$act(['action'=>'advance_write','draft_id'=>$failed['id']],static function(string $json,array $service,array $control): array {
        composer_check($control['before_gmail_reply_dispatch'](),'an explicit retry gets one new verified model attempt');
        return ['ok'=>true,'answer'=>'{"subject":"Meeting request","body":"Would Friday work for a meeting? Thank you."}'];
    });

    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (11,1,'user',?)")->execute(['Write a new email']);
    $turn=(int)$pdo->lastInsertId();
    $chat=agent_connection_email_chat_v1($pdo,$owner,$principal,'Write a new email',11,$turn,null,false,$noProvider);
    $repeat=agent_connection_email_chat_v1($pdo,$owner,$principal,'Write a new email',11,$turn,null,false,$noProvider);
    composer_check($chat['actions'][0]['type']==='email_review' && $chat['actions'][0]['id']===$repeat['actions'][0]['id'],
        'a blank conversational request opens the same persisted composer on replay');

    $legacyId=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message) VALUES (11,1,'user','Review the suggested Gmail reply.')")->execute();
    $replyOrigin=(int)$pdo->lastInsertId();
    $replyPayload=['subject'=>'Original subject','body'=>'A suggested reply.','reply'=>[
        'message_id'=>'reply_original','thread_id'=>'reply_thread','in_reply_to'=>'<reply_original@example.test>','references'=>['<reply_original@example.test>']]];
    $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,expires_at)
        VALUES (?,1,0,11,?,7,?,\'owner@example.test\',\'sender@example.test\',?,?)')
        ->execute([$legacyId,$replyOrigin,$grant,ai_encrypt_secret(json_encode($replyPayload,JSON_THROW_ON_ERROR)),time()+86400]);
    $reply=$review($legacyId);
    $legacyRow=agent_connection_email_chat_draft_v1($pdo,1,0,11,$legacyId);
    $legacyLater=agent_connection_email_chat_public_draft_v1($legacyRow,$key,(int)$legacyRow['expires_at']+1);
    composer_check(!$reply['persistent'] && $legacyLater['status']==='expired' && !$legacyLater['can_edit']
        && !$legacyLater['can_send'] && $legacyLater['saved_at']===null,
        'unsaved legacy proposals keep their original expiry and cannot regain edit or send authority by refreshing a token');
    composer_reject(422,static fn()=>$save($reply,'other@example.test',$reply['subject'],$reply['body']),
        'organic reply recipients cannot be redirected');
    composer_reject(422,static fn()=>$save($reply,$reply['to'],'Changed subject',$reply['body']),
        'organic reply subjects remain bound to the original Gmail thread');
    $reply=$save($reply,$reply['to'],$reply['subject'],'An owner-edited reply.');
    composer_check($reply['persistent'] && $reply['is_reply'] && $reply['body']==='An owner-edited reply.',
        'an organic proposal uses the common editor and becomes durable on explicit Save draft');

    $sendCalls=0;$sentRaw='';
    $transport=static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$sendCalls,&$sentRaw): array {
        if ($method!=='POST' || $url!==VP3_GMAIL_API_V1.'/messages/send' || $form) throw new RuntimeException('Unexpected fixture provider operation');
        $sendCalls++;$sentRaw=base64_decode(strtr($payload['raw'],'-_','+/'),true);
        return ['status'=>200,'json'=>['id'=>'fixture_sent_once','threadId'=>'fixture_sent_thread']];
    };
    $confirmation=['action'=>'confirm','draft_id'=>$written['id'],'review_token'=>$written['review_token'],'confirmed'=>true,'watch_replies'=>false];
    $sent=$act($confirmation,null,$transport);$sentAgain=$act($confirmation,null,$transport);
    composer_check($sent['status']==='sent' && $sentAgain['status']==='sent' && $sendCalls===1 && !$sent['can_edit']
        && str_contains($sentRaw,'To: recipient@example.test') && str_contains($sentRaw,'Subject: Meeting request'),
        'only the reviewed complete snapshot is sent once and repeated confirmation reuses the receipt');
    composer_reject(409,static fn()=>$save($written,'redirect@example.test','Changed','Changed'),
        'a sent draft cannot be edited or redirected');
    composer_check(!in_array($written['id'],array_column(agent_email_composer_list_v1($pdo,$owner,$principal,11,$key)['drafts'],'id'),true),
        'sent drafts leave the unfinished draft list');

    $uncertain=$act(['action'=>'compose_create','request_id'=>str_repeat('d',32),'to'=>'recipient@example.test','subject'=>'Uncertain send','body'=>'A complete message.']);
    $unknownPosts=0;$rfc='';
    $lost=static function(string $method,string $url,array $headers,?array $payload,bool $form) use (&$unknownPosts,&$rfc): array {
        if ($method==='POST') {
            $unknownPosts++;$raw=base64_decode(strtr($payload['raw'],'-_','+/'),true);
            preg_match('/Message-ID: (<[^<>]+>)/',$raw,$m);$rfc=$m[1];
            return ['status'=>503,'json'=>['error'=>['message'=>'fixture lost response']]];
        }
        if (str_contains($url,'/messages?')) return ['status'=>200,'json'=>['messages'=>[['id'=>'uncertain_sent']]]];
        return ['status'=>200,'json'=>['id'=>'uncertain_sent','threadId'=>'uncertain_thread','labelIds'=>['SENT'],
            'payload'=>['headers'=>[['name'=>'Message-ID','value'=>$rfc]]]]];
    };
    $unknownConfirm=['action'=>'confirm','draft_id'=>$uncertain['id'],'review_token'=>$uncertain['review_token'],'confirmed'=>true,'watch_replies'=>false];
    composer_reject(502,static fn()=>$act($unknownConfirm,null,$lost),'a lost provider acknowledgement remains unconfirmed');
    $pending=$review($uncertain['id']);
    composer_check($pending['status']==='sending' && !$pending['can_edit'],'an uncertain send freezes its original snapshot');
    composer_reject(409,static fn()=>$save($uncertain,'another@example.test','Different','Different'),
        'an uncertain send cannot be edited into another delivery');
    $reconciled=$act($unknownConfirm,null,$lost);
    composer_check($reconciled['status']==='sent' && $unknownPosts===1,'reconciliation finds the same RFC Message-ID without another send POST');

    $pdo->exec('CREATE TABLE user_media_assets (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,media_type VARCHAR(20),
        title VARCHAR(200),mime_type VARCHAR(100),file_path VARCHAR(500),file_size BIGINT,metadata_json TEXT)');
    $chunk=static fn(string $type,string $bytes): string => pack('N',strlen($bytes)).$type.$bytes.hash('crc32b',$type.$bytes,true);
    $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',1,1,8,6,0,0,0))
        .$chunk('IDAT',gzcompress("\x00\x30\x80\xa0\xff")).$chunk('IEND','');
    $imagePath=media_studio_ensure_user_root(1).'/fixture.png';file_put_contents($imagePath,$png);
    $pdo->prepare("INSERT INTO user_media_assets VALUES (71,1,'photo','Fixture image','image/png','private/user-media/1/fixture.png',?,'{}')")
        ->execute([strlen($png)]);
    $imageDraft=$act(['action'=>'compose_create','request_id'=>str_repeat('f',32),'to'=>'recipient@example.test','subject'=>'Image review','body'=>'Please see the image.']);
    $imageDraft=$save($imageDraft,$imageDraft['to'],$imageDraft['subject'],$imageDraft['body'],[71]);
    composer_check(count($imageDraft['attachments'])===1 && $imageDraft['attachments'][0]['asset_id']===71
        && !isset($imageDraft['attachments'][0]['sha256'],$imageDraft['attachments'][0]['bytes']),
        'attachment review exposes an owned preview and immutable metadata without file bytes');
    unlink($imagePath);
    composer_reject(409,static fn()=>$act(['action'=>'confirm','draft_id'=>$imageDraft['id'],'review_token'=>$imageDraft['review_token'],
        'confirmed'=>true,'watch_replies'=>false],null,$noProvider),'a deleted image stops dispatch before Gmail');
    $repair=$review($imageDraft['id']);
    composer_check($repair['status']==='prepared' && $repair['can_edit'] && count($repair['attachments'])===1,
        'a pre-dispatch attachment rejection restores editing only after its unused send claim is absent');
    $repaired=$save($repair,$repair['to'],$repair['subject'],$repair['body'],[]);
    composer_check($repaired['attachments']===[] && $repaired['review_token']!==$repair['review_token']
        && $repaired['edit_revision']!==$repair['edit_revision'],
        'removing an unavailable image creates a fresh reviewed snapshot');

    $revoked=$act(['action'=>'compose_create','request_id'=>str_repeat('e',32)]);
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('b',64)]);
    composer_reject(409,static fn()=>$save($revoked,'recipient@example.test','Subject','Body'),
        'a replacement Gmail grant cannot inherit the old composer approval');
    composer_check(!$review($revoked['id'])['can_edit'],'grant replacement removes composer actions');
    echo 'EMAIL_COMPOSER=PASS ('.$GLOBALS['composer_checks']." assertions, mysql)\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    if (is_file($fixtureRoot.'/private/user-media/1/fixture.png')) unlink($fixtureRoot.'/private/user-media/1/fixture.png');
    if (is_dir($fixtureRoot.'/private/user-media/1')) rmdir($fixtureRoot.'/private/user-media/1');
    if (is_dir($fixtureRoot.'/private/user-media')) rmdir($fixtureRoot.'/private/user-media');
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
