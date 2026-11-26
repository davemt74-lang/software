<?php
declare(strict_types=1);

// Production composer + send ledger against native MySQL; Gmail is an in-memory transport.
require_once dirname(__DIR__).'/includes/agent-email-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-followthrough-source-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/agent-tool-json-v1.php';

function db(): PDO {return $GLOBALS['pdo'];}
function setting(string $name,mixed $fallback=''): mixed {return $fallback;}
function url(string $path): string {return $path;}
function has_permission(string $permission,array $user): bool {return !empty($user['is_active']);}
function table_exists(string $table): bool {
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (bool)$q->fetchColumn();
}
function fs_check(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);$GLOBALS['fs_checks']=($GLOBALS['fs_checks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function fs_reject(int $code,callable $work,string $label): void {
    try {$work();} catch (DomainException|RuntimeException $e) {
        if ($e instanceof PDOException || (int)$e->getCode()!==$code) throw $e;fs_check(true,$label);return;
    } throw new RuntimeException('Expected rejection: '.$label);
}
function fs_message(string $id,string $thread,string $from,string $to,string $rfc,string $subject,string $body,array $labels=['SENT'],string $parent=''): array {
    $headers=['From'=>$from,'To'=>$to,'Subject'=>$subject,'Message-ID'=>$rfc,'References'=>$parent,'In-Reply-To'=>$parent];
    return ['id'=>$id,'threadId'=>$thread,'labelIds'=>$labels,'internalDate'=>(string)((time()-10)*1000),
        'payload'=>['mimeType'=>'text/plain','headers'=>array_map(static fn($name,$value)=>['name'=>$name,'value'=>$value],array_keys($headers),$headers),
            'body'=>['data'=>rtrim(strtr(base64_encode($body),'+/','-_'),'=')]]];
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('Native MySQL is required.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);$database='vp3_followthrough_source_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot=sys_get_temp_dir().'/vp3-followthrough-source-'.bin2hex(random_bytes(6));define('STONEFELLOW_ROOT',$fixtureRoot);mkdir($fixtureRoot,0700);
$config=['connections'=>['gmail'=>['client_id'=>'followthrough-source-fixture','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://fixture.example.test/connection-oauth.php']]];
try {
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL); INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,NULL),(12,1,NULL),(21,2,NULL);
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,
            role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);agent_email_followthrough_install_v1($pdo);
    $grant=str_repeat('a',64);
    $pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,account_email,status,read_enabled,send_enabled,followups_enabled,
        authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (7,1,'gmail','owner@example.test','connected',1,1,1,?,?,?,'',?)")
        ->execute([$grant,agent_connections_client_fingerprint_v1(),ai_encrypt_secret('fixture-access'),time()+3600]);
    $owner=['id'=>1,'is_active'=>1];$principal=['kind'=>'system','agent_id'=>0];$key='source-fixture-review-key';
    $mail=[];$posts=0;$wire='';$lose=false;$threadFailure=null;
    $http=static function(string $method,string $url,array $headers,?array $payload,bool $form)use(&$mail,&$posts,&$wire,&$lose,&$threadFailure):array {
        if ($method==='POST' && str_ends_with($url,'/messages/send')) {
            $posts++;$wire=base64_decode(strtr($payload['raw'],'-_','+/'),true);
            preg_match('/Message-ID: (<[^<>]+>)/',$wire,$m);$rfc=$m[1];
            preg_match('/To: ([^\r\n]+)/',$wire,$m);$to=$m[1];
            preg_match('/Subject: ([^\r\n]+)/',$wire,$m);$subject=mb_decode_mimeheader($m[1]);
            preg_match('/In-Reply-To: (<[^<>]+>)/',$wire,$m);$parent=$m[1]??'';
            $id='sent_'.$posts;$thread=$payload['threadId']??'thread_'.$posts;
            $mail[$id]=fs_message($id,$thread,'owner@example.test',$to,$rfc,$subject,'Original fixture email body.',['SENT'],$parent);
            if ($lose) return ['status'=>503,'json'=>['error'=>['message'=>'Lost acknowledgement']]];
            return ['status'=>200,'json'=>['id'=>$id,'threadId'=>$thread]];
        }
        if ($method!=='GET') throw new RuntimeException('Unexpected Gmail mutation');
        if (preg_match('~/threads/([^?]+)~',$url,$m)) {
            if ($threadFailure!==null) return ['status'=>200,'json'=>$threadFailure];
            return ['status'=>200,'json'=>['id'=>$m[1],'messages'=>array_values(array_filter($mail,static fn($v)=>$v['threadId']===$m[1]))]];
        }
        if (preg_match('~/messages/([^?]+)~',$url,$m)) return isset($mail[$m[1]])?['status'=>200,'json'=>$mail[$m[1]]]:['status'=>404,'json'=>[]];
        if (str_contains($url,'/messages?')) {
            parse_str(parse_url($url,PHP_URL_QUERY),$query);$rfc=substr($query['q']??'',strlen('rfc822msgid:'));
            $found=[];foreach ($mail as $v) foreach ($v['payload']['headers'] as $h)
                if ($h['name']==='Message-ID' && $h['value']===$rfc) $found[]=['id'=>$v['id']];
            return ['status'=>200,'json'=>['messages'=>$found]];
        }
        throw new RuntimeException('Unexpected Gmail fixture read');
    };
    $act=static fn(array $input):array=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$input,$key,$http);
    $review=static fn(string $id):array=>$act(['action'=>'review','draft_id'=>$id]);
    $save=static fn(array $d,array $settings):array=>$act(['action'=>'compose_save','draft_id'=>$d['id'],'review_token'=>$d['review_token'],
        'to'=>$d['to'],'subject'=>$d['subject'],'body'=>$d['body'],'attachment_ids'=>[],'followthrough'=>$settings]);
    $settings=['enabled'=>true,'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'time_zone'=>'America/Phoenix','prepare_if_overdue'=>false,'priority'=>'high'];
    $initial=$act(['action'=>'compose_create','request_id'=>str_repeat('b',32),'to'=>'buyer@example.test','subject'=>'Exact subject','body'=>'A private question.']);
    $tracked=$save($initial,$settings);
    fs_check($tracked['followthrough']===$settings && $tracked['edit_revision']!==$initial['edit_revision'],'saved response deadline participates in the exact review revision');
    $row=agent_connection_email_chat_draft_v1($pdo,1,0,11,$tracked['id']);$saved=agent_connection_email_chat_payload_v1($row);
    $marker=$saved['followthrough']['user_message_id'];
    fs_check($marker!==(int)$row['user_message_id'] && (int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn()===2,
        'explicit deadline save creates one separate real owner marker');
    $tracked=$save($tracked,$settings);
    fs_check((int)$pdo->query("SELECT COUNT(*) FROM chat_messages WHERE role='user'")->fetchColumn()===2,'ordinary saves preserve the same deadline authorization marker');
    fs_reject(409,static fn()=>$save($initial,$settings),'stale compose save cannot create a second deadline action');
    fs_reject(422,static fn()=>$save($tracked,array_replace($settings,['deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-1)])),
        'an explicitly changed past deadline is rejected');
    fs_reject(422,static fn()=>$act(['action'=>'confirm','draft_id'=>$tracked['id'],'review_token'=>$tracked['review_token'],'confirmed'=>true,'watch_replies'=>false]),
        'a saved deadline cannot silently lose its reply watch at send');
    fs_check($posts===0,'deadline review failures create no Gmail send');
    $sent=$act(['action'=>'confirm','draft_id'=>$tracked['id'],'review_token'=>$tracked['watch_review_token'],'confirmed'=>true,'watch_replies'=>true]);
    fs_check($sent['status']==='sent' && $sent['watch_registered'] && is_array($sent['followthrough_record']),
        'verified original send atomically registers its deadline and exact thread watch');
    $record=$pdo->query('SELECT * FROM agent_email_followthrough_v1')->fetch();
    $pdo->prepare('UPDATE agent_email_followthrough_v1 SET deadline_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-5),$record['id']]);
    $item=agent_email_followthrough_check_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$record['id'],'expected_revision'=>1],$key,$http);
    fs_check($item['outcome']==='overdue' && $item['check']['result']==='no_reply','a complete exact thread check establishes the elapsed deadline outcome');
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE id=?');$q->execute([$record['id']]);$record=$q->fetch();
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=1 AND operation_key=?');$q->execute([$record['operation_key']]);$watch=$q->fetch();
    $source=agent_email_followthrough_source_v1($pdo,$record,$watch,$http,true);
    fs_check($source['to']==='buyer@example.test' && $source['subject']==='Exact subject' && $source['body']==='Original fixture email body.'
        && $source['outbound_followup']['message_id']===$watch['sent_message_id'],'outbound source keeps the external recipient, original subject and sent anchor');
    fs_reject(409,static fn()=>agent_connection_gmail_reply_from_message_v1($mail[$watch['sent_message_id']],'owner@example.test'),
        'received-message reply validation still rejects SENT parents');
    $duplicate=$mail[$watch['sent_message_id']];$duplicate['payload']['headers'][]=['name'=>'To','value'=>'other@example.test'];
    fs_reject(409,static fn()=>agent_email_followthrough_original_v1($duplicate,'owner@example.test','buyer@example.test',$source['outbound_followup']),
        'duplicate recipient headers cannot redirect a follow-up');
    $threadFailure=['id'=>$watch['thread_id'],'messages'=>[$duplicate]];
    fs_reject(409,static fn()=>agent_connection_gmail_thread_v1('fixture-access',$watch['thread_id'],$http,true),
        'strict absence reads reject ambiguous raw thread headers');
    $threadFailure=['id'=>$watch['thread_id'],'messages'=>[$mail[$watch['sent_message_id']]],'nextPageToken'=>'incomplete'];
    fs_reject(409,static fn()=>agent_connection_gmail_thread_v1('fixture-access',$watch['thread_id'],$http,true),
        'partial thread responses cannot establish no reply');
    $unicode=$mail[$watch['sent_message_id']];$unicodeSubject=str_repeat('é',200);
    foreach ($unicode['payload']['headers'] as &$header) {
        if ($header['name']==='Subject') $header['value']=mb_encode_mimeheader($unicodeSubject,'UTF-8','B',"\r\n",strlen('Subject: '));
    } unset($header);
    $threadFailure=['id'=>$watch['thread_id'],'messages'=>[$unicode]];
    $unicodeEvidence=agent_email_followthrough_evidence_v1($watch,agent_connection_gmail_thread_v1('fixture-access',$watch['thread_id'],$http,true));
    fs_check($unicodeEvidence['state']==='verified' && $unicodeEvidence['result']==='no_reply'
        && agent_email_followthrough_original_v1($unicode,'owner@example.test','buyer@example.test',$source['outbound_followup'])['subject']===$unicodeSubject,
        'a legitimate maximum-length encoded Unicode subject remains verifiable without relaxing thread linkage');
    $threadFailure=null;

    // Store the same canonical output shape as the metered background preparation adapter.
    $draftId=substr(hash('sha256','vp3-gmail-followthrough-draft-v1:'.$record['id'].':'.(int)$record['source_revision'].':'.$marker),0,32);
    $now=time();$payload=['subject'=>$source['subject'],'body'=>'Following up on my earlier question.','outbound_followup'=>$source['outbound_followup'],
        'attachments'=>[],'composer'=>['version'=>3,'saved_at'=>$now,'created_at'=>$now,'origin'=>'outbound_followup',
            'source'=>['reason'=>'Response deadline passed; no reply observed in the verified thread.','priority'=>'high','deadline'=>$settings['deadline_at']]]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1 (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,
        account_email,recipient,message_enc,status,expires_at,watch_replies) VALUES (?,1,0,11,?,7,?,'owner@example.test','buyer@example.test',?,'prepared',0,1)")
        ->execute([$draftId,$marker,$grant,ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR))]);
    $pdo->prepare('UPDATE agent_email_followthrough_v1 SET generated_draft_id=?,generated_revision=source_revision WHERE id=?')->execute([$draftId,$record['id']]);
    $draft=$review($draftId);
    fs_check($draft['is_followup'] && $draft['is_reply'] && $draft['can_send'] && $draft['can_open_source'],
        'prepared follow-up uses the existing editable draft review with a distinct original source');
    $sourceView=agent_email_review_queue_source_v1($pdo,$owner,$principal,11,['draft_id'=>$draftId,'edit_revision'=>$draft['edit_revision']],$key,$http);
    fs_check($sourceView['message']['body']==='Original fixture email body.','follow-up source reader opens the exact sent email');
    fs_reject(422,static fn()=>$act(['action'=>'compose_save','draft_id'=>$draftId,'review_token'=>$draft['review_token'],
        'to'=>'redirect@example.test','subject'=>$draft['subject'],'body'=>$draft['body'],'attachment_ids'=>[]]),'follow-up recipient cannot be changed');
    fs_reject(422,static fn()=>$act(['action'=>'compose_save','draft_id'=>$draftId,'review_token'=>$draft['review_token'],
        'to'=>$draft['to'],'subject'=>'Different topic','body'=>$draft['body'],'attachment_ids'=>[]]),'follow-up subject cannot drift into another thread');
    $draft=$act(['action'=>'compose_save','draft_id'=>$draftId,'review_token'=>$draft['review_token'],
        'to'=>$draft['to'],'subject'=>$draft['subject'],'body'=>'My edited follow-up question.','attachment_ids'=>[]]);
    fs_check($draft['body']==='My edited follow-up question.' && $draft['source']['kind']==='outbound_followup' && $draft['watch_requested']===true,
        'manual body edits preserve exact follow-up provenance and its selected reply watch');
    $paused=agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$record['id'],'expected_revision'=>1,'operation'=>'pause'],$key);
    fs_check(!$review($draftId)['can_send'],'paused tracking disables sending its automatic proposal');
    $pausedSource=agent_email_review_queue_source_v1($pdo,$owner,$principal,11,['draft_id'=>$draftId,'edit_revision'=>$draft['edit_revision']],$key,$http);
    fs_check($pausedSource['message']['body']==='Original fixture email body.','pausing follow-up work preserves separately authorized read-only source inspection');
    fs_reject(409,static fn()=>$act(['action'=>'confirm','draft_id'=>$draftId,'review_token'=>$draft['watch_review_token'],'confirmed'=>true,'watch_replies'=>true]),
        'an older review token cannot send after the owner pauses');
    $resumed=agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$record['id'],'expected_revision'=>$paused['revision'],'operation'=>'resume'],$key);
    agent_email_followthrough_check_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$record['id'],'expected_revision'=>$resumed['revision']],$key,$http);
    $draft=$review($draftId);fs_check($draft['can_send'] && $draft['body']==='My edited follow-up question.',
        'resume plus a fresh no-reply check preserves the owner-edited draft source');
    $reply=fs_message('incoming_reply',$watch['thread_id'],'buyer@example.test','owner@example.test','<reply@example.test>','Exact subject','A new answer.',['INBOX'],$source['outbound_followup']['in_reply_to']);
    $reply['internalDate']=(string)((time()-1)*1000);$mail['incoming_reply']=$reply;
    $before=$posts;$stale=$act(['action'=>'confirm','draft_id'=>$draftId,'review_token'=>$draft['watch_review_token'],'confirmed'=>true,'watch_replies'=>true]);
    fs_check($stale['status']==='stale' && $posts===$before,'a reply arriving after draft creation blocks the Gmail POST');
    unset($mail['incoming_reply']);
    $pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='prepared' WHERE id=?")->execute([$draftId]);
    $newer=fs_message('external_sent',$watch['thread_id'],'owner@example.test','buyer@example.test','<external@example.test>','Exact subject','Sent from another client.');
    $newer['internalDate']=(string)((time()-1)*1000);$mail['external_sent']=$newer;$draft=$review($draftId);
    $blocked=$act(['action'=>'confirm','draft_id'=>$draftId,'review_token'=>$draft['watch_review_token'],'confirmed'=>true,'watch_replies'=>true]);
    fs_check($blocked['status']==='source_check' && $posts===$before,'a newer external-client send blocks the outdated follow-up');
    unset($mail['external_sent']);
    $draft=$act(['action'=>'review_latest','draft_id'=>$draftId]);fs_check($draft['can_send'],'a failed thread check can recover only by rechecking the exact unchanged source');
    $lose=true;
    $confirm=['action'=>'confirm','draft_id'=>$draftId,'review_token'=>$draft['watch_review_token'],'confirmed'=>true,'watch_replies'=>true];
    fs_reject(502,static fn()=>$act($confirm),'an uncertain follow-up POST stays unconfirmed');
    fs_check($posts===$before+1 && str_contains($wire,'To: buyer@example.test') && str_contains($wire,'In-Reply-To: '.$source['outbound_followup']['in_reply_to']),
        'the one follow-up POST addresses the external recipient with the exact RFC thread linkage');
    $live=$pdo->query("SELECT * FROM agent_email_followthrough_v1 WHERE id='".$record['id']."'")->fetch();
    agent_email_followthrough_update_v1($pdo,$owner,$principal,11,['followthrough_ref'=>$record['id'],'expected_revision'=>(int)$live['revision'],'operation'=>'resolve'],$key);
    $pdo->exec("CREATE TRIGGER fs_watch_unavailable BEFORE INSERT ON agent_connection_email_followups_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture watch write unavailable'");
    $lose=false;$reconciled=$act($confirm);$pdo->exec('DROP TRIGGER fs_watch_unavailable');
    fs_check($reconciled['status']==='sent' && $posts===$before+1,'reconciliation records the same sent outcome after resolve without a second POST');
    fs_check($reconciled['watch_registered']===false,'a failed optional reply-watch write preserves the confirmed send and reports monitoring unavailable');
    $final=$pdo->query("SELECT * FROM agent_email_followthrough_v1 WHERE id='".$record['id']."'")->fetch();
    fs_check($final['outcome']==='resolved' && $final['followup_sent_message_id']===$reconciled['message_id'],
        'a late confirmed receipt records the send but preserves the newer resolved outcome');
    $optional=$save($act(['action'=>'compose_create','request_id'=>str_repeat('c',32),'to'=>'buyer@example.test',
        'subject'=>'Optional tracking failure','body'=>'A separately reviewed email.']),$settings);
    $pdo->exec("CREATE TRIGGER fs_deadline_unavailable BEFORE INSERT ON agent_email_followthrough_v1 FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture deadline write unavailable'");
    $before=$posts;
    $optionalSent=$act(['action'=>'confirm','draft_id'=>$optional['id'],'review_token'=>$optional['watch_review_token'],'confirmed'=>true,'watch_replies'=>true]);
    $pdo->exec('DROP TRIGGER fs_deadline_unavailable');
    $savedOutcome=json_decode($pdo->query('SELECT context_json FROM chat_messages ORDER BY id DESC LIMIT 1')->fetchColumn(),true,16,JSON_THROW_ON_ERROR);
    fs_check($optionalSent['status']==='sent' && $optionalSent['watch_registered']===true && $posts===$before+1
        && $optionalSent['followthrough_record']===null && $savedOutcome['email_outcome']['response_deadline_registered']===false,
        'a failed optional deadline registration preserves the confirmed send and its independently registered reply watch');
    fs_check($pdo->query("SELECT COUNT(*) FROM agent_connection_sends_v1 WHERE status='pending'")->fetchColumn()==0,'no unused send claims remain after blocked and reconciled actions');
    echo 'EMAIL_FOLLOWTHROUGH_SOURCE=PASS ('.($GLOBALS['fs_checks']??0).' assertions, mysql)'.PHP_EOL;
} finally {
    $GLOBALS['pdo']=null;$server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    if (is_dir($fixtureRoot)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $entry) $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($fixtureRoot);
    }
}
