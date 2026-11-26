<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-email-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-email-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-boundary-v380.php';
require_once dirname(__DIR__).'/includes/knowledge-retrieval-v162.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
require_once dirname(__DIR__).'/includes/notifications.php';
require_once dirname(__DIR__).'/includes/agent-brain-v82.php';

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
    $GLOBALS['checks']=($GLOBALS['checks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function followup_reject(int $status,callable $operation,string $label): void
{
    try { $operation(); }
    catch (Throwable $error) { followup_check($error->getCode()===$status,$label); return; }
    throw new RuntimeException($label);
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn,'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$pdo = $GLOBALS['followupPdo'] = new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$database = 'vp3_deadline_'.bin2hex(random_bytes(6));
$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE '.$database);
$root = sys_get_temp_dir().'/vp3-deadline-'.bin2hex(random_bytes(8));
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
    CREATE TABLE agent_connection_sends_v1(owner_user_id INT UNSIGNED,connection_id BIGINT UNSIGNED,authorization_key CHAR(64),operation_key CHAR(64),status VARCHAR(16),provider_message_id VARCHAR(190),provider_thread_id VARCHAR(190),sent_at BIGINT UNSIGNED,UNIQUE(owner_user_id,operation_key));");
agent_connection_email_draft_schema_v1($pdo);
agent_connection_email_followup_install_v1($pdo);
agent_email_followthrough_install_v1($pdo);
$config['connections']['gmail'] = ['client_id'=>'watch-delivery-fixture','client_secret'=>bin2hex(random_bytes(12))];
$grant = bin2hex(random_bytes(32));
$access = bin2hex(random_bytes(24));
$mailbox = 'owner@example.test';
$sender = 'reply@example.test';
$connect = $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (?,?,'gmail','connected',?,?,?,?,1,1,1,?,'',?)");
$connect->execute([17,1,$grant,agent_connections_client_fingerprint_v1(),$mailbox,'fixture-mailbox',ai_encrypt_secret($access),time()+3600]);
$connect->execute([18,2,bin2hex(random_bytes(32)),agent_connections_client_fingerprint_v1(),'other@example.test',
    'other-fixture-mailbox',ai_encrypt_secret(bin2hex(random_bytes(24))),time()+3600]);
$user=['id'=>1,'is_active'=>1];$principal=['kind'=>'user_agent','agent_id'=>7,'owner_user_id'=>1,'viewer_user_id'=>1];
$settings=['enabled'=>true,'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'time_zone'=>'America/Phoenix','prepare_if_overdue'=>false,'priority'=>'normal'];
$deadline=agent_email_followthrough_settings_v1($settings);
followup_check($deadline===$settings,'deadline settings preserve UTC instant and explicit timezone');
foreach ([['deadline_at'=>'2026-02-30T01:00:00Z'],['deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-1)],
    ['deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+31*86400)],['time_zone'=>'+02:00'],['priority'=>'urgent'],['prepare_if_overdue'=>1],['unknown'=>true]] as $bad)
    followup_reject(422,static fn()=>agent_email_followthrough_settings_v1(array_replace($settings,$bad)),'invalid deadline configuration is rejected');
$seed=static function(string $suffix='one',int $chat=11,bool $report=true)use($pdo,$grant,$mailbox,$sender,$settings):array {
    $id=substr(hash('sha256','deadline fixture '.$suffix),0,32);$operation=agent_connection_email_chat_operation_key_v1($id);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES (?,1,'user','Write this email.',?)")->execute([$chat,agent_commerce_time_pa2()]);$origin=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES (?,1,'user','Set a response deadline.',?,?)")
        ->execute([$chat,'{"source":"agent_email_followthrough_deadline","skip_brain_archive":true}',agent_commerce_time_pa2()]);$marker=(int)$pdo->lastInsertId();
    $payload=['subject'=>'Follow-through '.$suffix,'body'=>'Owner sent request.','composer'=>['version'=>1,'saved_at'=>time()]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,sent_message_id)
        VALUES (?,1,7,?,?,17,?,?,?,?,'sent',0,?)")
        ->execute([$id,$chat,$origin,$grant,$mailbox,$sender,ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),'sent_'.$suffix]);
    $receipt=['operation_key'=>$operation,'authorization_key'=>$grant,'connection_id'=>17,'from'=>$mailbox,'to'=>$sender,
        'message_id'=>'sent_'.$suffix,'thread_id'=>'thread_'.$suffix,'sent_at'=>time()-300];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1 VALUES(1,17,?,?,'sent',?,?,?)")
        ->execute([$grant,$operation,$receipt['message_id'],$receipt['thread_id'],$receipt['sent_at']]);
    $draft=agent_connection_email_chat_draft_v1($pdo,1,7,$chat,$id);
    $registered=agent_connection_email_followup_register_v1($pdo,1,7,$chat,$receipt,['draft_id'=>$id,'user_message_id'=>$origin]);
    if ($report) followup_check($registered,'exact native thread watch registered');
    elseif (!$registered) throw new LogicException('Pagination fixture needs exact native watches.');
    $config=$settings+['version'=>1,'user_message_id'=>$marker];
    $row=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_register_v1($pdo,$draft,$receipt,$config));
    if ($report) {
        $again=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_register_v1($pdo,$draft,$receipt,$config));
        followup_check($row['id']===$again['id'],'verified-send registration replay does not duplicate a deadline');
    }
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=1 AND operation_key=?');$q->execute([$operation]);
    return ['row'=>$row,'watch'=>$q->fetch(),'draft'=>$draft,'receipt'=>$receipt,'settings'=>$config];
};
$fixture=$seed();$watch=$fixture['watch'];$row=$fixture['row'];
$nowMs=(time()-1)*1000;
$message=static function(string $id,int $at,string $from,string $to,array $labels,string $parent='')use($watch):array {
    $headers=['From'=>$from,'To'=>$to,'Subject'=>'Follow-through one','Message-ID'=>'<'.$id.'@example.test>','In-Reply-To'=>$parent,'References'=>$parent,'Authentication-Results'=>'mx.google.com; dmarc=pass'];
    return ['id'=>$id,'threadId'=>$watch['thread_id'],'internalDate'=>(string)$at,'labelIds'=>$labels,
        'payload'=>['headers'=>array_map(static fn($name,$value)=>['name'=>$name,'value'=>$value],array_keys($headers),$headers)]];
};
$anchor=$message($watch['sent_message_id'],$nowMs-300000,$mailbox,$sender,['SENT']);
$reply=$message('reply_one',$nowMs,$sender,$mailbox,['INBOX'],'<'.$watch['sent_message_id'].'@example.test>');
$normalized=static fn(array $messages):array=>array_map('agent_connection_gmail_message_v1',$messages);
$evidence=static fn(array $messages):array=>agent_email_followthrough_evidence_v1($watch,$normalized($messages));
followup_check($evidence([$anchor])['result']==='no_reply','complete exact sent anchor establishes a timestamped no-reply observation');
followup_check($evidence([$anchor,$reply])['result']==='reply','exact newer linked recipient reply is observed');
foreach ([[],[$reply],[$anchor,$anchor],[$reply,$anchor],[array_replace($anchor,['labelIds'=>['TRASH']])],
    [array_replace($anchor,['internalDate'=>'0'])],[array_replace($anchor,['threadId'=>'wrong'])],
    [array_replace($anchor,['internalDate'=>(string)(time()+100)*1000])]] as $bad)
    followup_check($evidence($bad)['state']==='unavailable','missing, duplicate, invalid or unordered anchor is never no-reply evidence');
$unmatched=$message('other_inbound',$nowMs,$sender,$mailbox,['INBOX']);
$laterSent=$message('later_sent',$nowMs,$mailbox,$sender,['SENT']);
followup_check($evidence([$anchor,$unmatched])['state']==='ambiguous','newer unmatched inbound mail blocks automatic preparation');
followup_check($evidence([$anchor,$laterSent])['state']==='ambiguous','a newer external-client outgoing email blocks duplicate nudging');
$list=agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture');
followup_check($list['state']==='ready'&&count($list['items'])===1&&$list['counts']['waiting']===1
    &&$list['coverage']==='tracked_sends_in_this_private_chat','bounded private Chat list reports saved verified deadlines');
followup_check(!str_contains(json_encode($list),$grant)&&!str_contains(json_encode($list),$watch['thread_id']),
    'public deadline metadata hides OAuth grants and Gmail provider identifiers');
followup_check(agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture')['items']===[],'another Chat cannot list these deadlines');
$input=['followthrough_ref'=>$row['id'],'expected_revision'=>1];
followup_reject(404,static fn()=>agent_email_followthrough_check_v1($pdo,$user,$principal,12,$input,'fixture'),'another Chat cannot act on the opaque reference');
followup_reject(422,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture',['limit'=>21]),'unbounded list input is rejected');
$calls=0;$during=null;$providerMessages=[$anchor];$providerStatus=200;
$http=static function(string $method,string $url,array $headers,?array $payload,bool $form)use(&$calls,&$during,&$providerMessages,&$providerStatus,$watch):array {
    $calls++;if ($method!=='GET'||$payload!==null||$form) throw new LogicException('Deadline checks must never send mail.');
    if (!str_starts_with($url,VP3_GMAIL_API_V1.'/threads/'.$watch['thread_id'].'?')) throw new LogicException('Only the exact thread may be read.');
    if ($during) {$work=$during;$during=null;$work();}
    return $providerStatus===200?['status'=>200,'json'=>['id'=>$watch['thread_id'],'messages'=>$providerMessages]]:
        ['status'=>$providerStatus,'json'=>['error'=>['message'=>'fixture unavailable']]];
};
$checked=agent_email_followthrough_check_v1($pdo,$user,$principal,11,$input,'fixture',$http);
followup_check($checked['outcome']==='waiting'&&$checked['check']['state']==='verified'&&$checked['check']['result']==='no_reply'&&$calls===1,
    'an on-demand successful check before deadline remains waiting and performs one read');
followup_check($checked['state_version']>$list['items'][0]['state_version']&&$checked['revision']===$list['items'][0]['revision'],
    'background evidence has an independent ordered state version without changing owner-control CAS');
$pdo->prepare('UPDATE agent_email_followthrough_v1 SET deadline_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()-30),$row['id']]);
$checked=agent_email_followthrough_check_v1($pdo,$user,$principal,11,$input,'fixture',$http);
followup_check($checked['outcome']==='overdue'&&$checked['check']['result']==='no_reply','only a successful current check marks the elapsed deadline overdue');
$providerMessages=[$anchor,$unmatched];$checked=agent_email_followthrough_check_v1($pdo,$user,$principal,11,$input,'fixture',$http);
followup_check($checked['check']['state']==='ambiguous'&&$checked['check']['result']===null&&$checked['outcome']!=='overdue',
    'ambiguous new thread activity withdraws overdue/no-reply automation');
$providerStatus=503;
try {agent_email_followthrough_check_v1($pdo,$user,$principal,11,$input,'fixture',$http);throw new LogicException('Expected provider error');}
catch (AgentConnectionGmailExceptionV1 $error) {followup_check($error->getCode()===503,'provider failure stays typed');}
$public=agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture')['items'][0];
followup_check($public['check']['state']==='unavailable'&&$public['check']['result']===null,'provider failure never becomes proof of no reply');
$providerStatus=200;$providerMessages=[$anchor];
$paused=agent_email_followthrough_update_v1($pdo,$user,$principal,11,$input+['operation'=>'pause'],'fixture');
followup_check($paused['paused']&&$paused['revision']===2&&!$paused['capabilities']['check'],'pause invalidates claims and check eligibility');
followup_reject(409,static fn()=>agent_email_followthrough_update_v1($pdo,$user,$principal,11,$input+['operation'=>'resolve'],'fixture'),'stale controls cannot override a newer owner decision');
$resumed=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>2,'operation'=>'resume'],'fixture');
followup_check(!$resumed['paused']&&$resumed['revision']===3&&$resumed['check']['state']==='never_checked','resume schedules a fresh check without inventing absence');
$beforeMessages=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$changed=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>3,'operation'=>'deadline']+array_diff_key($settings,['enabled'=>true]),'fixture');
followup_check($changed['revision']===4&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$beforeMessages+1,
    'an explicit changed deadline creates exactly one real owner marker');
followup_reject(409,static fn()=>agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>3,'operation'=>'deadline']+array_diff_key($settings,['enabled'=>true]),'fixture'),
    'replaying an old deadline action does not create another owner marker');
$during=static function()use($pdo,$user,$principal,$row):void {agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$row['id'],'expected_revision'=>4,'operation'=>'pause'],'fixture');};
$latest=agent_email_followthrough_check_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>4],'fixture',$http);
followup_check($latest['paused']&&$latest['revision']===5&&$latest['check']['state']==='never_checked','pause during provider I/O fences the stale check result');
$resume=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>5,'operation'=>'resume'],'fixture');
$providerMessages=[$anchor,$reply];
$latest=agent_email_followthrough_check_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>6],'fixture',$http);
followup_check($latest['outcome']==='replied'&&$latest['reply_target']['message_id']>0,'verified reply uses the canonical delivered Chat notice');
$notices=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='agent_email_followup'")->fetchColumn();
followup_reject(409,static fn()=>agent_email_followthrough_check_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>6],'fixture',$http),
    'completed reply observation cannot be replayed into a new worker check');
followup_check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='agent_email_followup'")->fetchColumn()===$notices,'reply replay does not duplicate the notification');
$replyNotice=(int)$latest['reply_target']['message_id'];
$q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$replyNotice]);$replyContext=$q->fetchColumn();
$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute(['{}',$replyNotice]);
$unverified=agent_email_followthrough_public_v1($pdo,agent_email_followthrough_for_watch_v1($pdo,$watch));
followup_check($unverified['reply_target']===null&&$unverified['check']['state']==='unavailable'&&$unverified['check']['result']===null,
    'tampered reply notice proof is not exposed as a verified reply link or no-reply evidence');
$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([$replyContext,$replyNotice]);
$closed=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$row['id'],'expected_revision'=>6,'operation'=>'resolve'],'fixture');
followup_check($closed['outcome']==='resolved'&&agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture')['items']===[],
    'explicit resolution closes tracking and the open view excludes it');
followup_check(agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture',['filter'=>'all'])['counts']['resolved']===1,
    'the all view retains explicit resolved outcomes');
$pdo->exec("UPDATE agent_connections_v1 SET followups_enabled=0 WHERE id=17");
followup_reject(403,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture'),'revoked follow-up permission rejects private tracking reads');
$pdo->exec("UPDATE agent_connections_v1 SET followups_enabled=1 WHERE id=17");
followup_check($calls===6,'only explicitly requested checks reached the fake Gmail transport');
$second=$seed('two');$secondRow=$second['row'];$secondWatch=$second['watch'];
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET deadline_at=?,outcome='overdue',check_state='verified',check_result='no_reply',checked_at=? WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s',time()-60),agent_commerce_time_pa2(),$secondRow['id']]);
$claim=bin2hex(random_bytes(16));
$pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE operation_key=?')
    ->execute([$claim,gmdate('Y-m-d H:i:s',time()+120),$secondWatch['operation_key']]);
$secondRow=agent_email_followthrough_for_watch_v1($pdo,$secondWatch);
followup_check(agent_email_followthrough_current_v1($pdo,$secondRow,$claim)['record']['id']===$secondRow['id'],
    'exact preparation guard binds source revision, control epoch, owner marker and active claim');
followup_reject(409,static fn()=>agent_email_followthrough_current_v1($pdo,$secondRow,str_repeat('0',32)),'another claim cannot consume verified no-reply work');
$extended=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_extend_claim_v1($pdo,$secondRow,$claim));
followup_check(strtotime($extended['watch']['next_check_at'])>time()+200&&strtotime($extended['watch']['next_check_at'])<=time()+240,
    'preparation extends the canonical claim to a bounded 240-second budget');
$binding=['followthrough_id'=>$secondRow['id'],'revision'=>1,'operation_key'=>$secondWatch['operation_key'],
    'message_id'=>$secondWatch['sent_message_id'],'thread_id'=>$secondWatch['thread_id']];
$paused2=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$secondRow['id'],'expected_revision'=>1,'operation'=>'pause'],'fixture');
followup_reject(409,static fn()=>agent_email_followthrough_current_v1($pdo,$secondRow,$claim),'pause fences a model commit with its old control epoch');
followup_check(agent_email_followthrough_binding_current_v1($pdo,1,7,11,$binding,false,false)['record']['source_revision']==1,
    'an explicit source reader can still inspect the preserved original after pause');
$resume2=agent_email_followthrough_update_v1($pdo,$user,$principal,11,['followthrough_ref'=>$secondRow['id'],'expected_revision'=>2,'operation'=>'resume'],'fixture');
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='overdue',check_state='verified',check_result='no_reply',checked_at=? WHERE id=?")
    ->execute([agent_commerce_time_pa2(),$secondRow['id']]);
$again=agent_email_followthrough_binding_current_v1($pdo,1,7,11,$binding);
followup_check((int)$again['record']['revision']===3&&(int)$again['record']['source_revision']===1,
    'resume and a fresh source check preserve the existing draft source revision');
$pdo->prepare("UPDATE agent_connection_sends_v1 SET provider_thread_id='wrong_thread' WHERE operation_key=?")->execute([$secondWatch['operation_key']]);
followup_reject(409,static fn()=>agent_email_followthrough_binding_current_v1($pdo,1,7,11,$binding),'a mismatched durable send ledger cannot authorize follow-through');
$pdo->prepare('UPDATE agent_connection_sends_v1 SET provider_thread_id=? WHERE operation_key=?')->execute([$secondWatch['thread_id'],$secondWatch['operation_key']]);
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
followup_reject(404,static fn()=>agent_email_followthrough_binding_current_v1($pdo,1,7,11,$binding),'a removed Agent cannot retain follow-through authority');
followup_reject(403,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture'),
    'a removed Agent invalidates private list authority instead of appearing as a transient failure');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec("UPDATE agent_connections_v1 SET client_fingerprint=REPEAT('0',64) WHERE id=17");
followup_reject(401,static fn()=>agent_email_followthrough_binding_current_v1($pdo,1,7,11,$binding),'replacement OAuth application invalidates the saved deadline source');
$pdo->prepare('UPDATE agent_connections_v1 SET client_fingerprint=? WHERE id=17')->execute([agent_connections_client_fingerprint_v1()]);
// The legacy worker uses strict reads only for registered deadline watches.
$anchor2=array_replace($anchor,['id'=>$secondWatch['sent_message_id'],'threadId'=>$secondWatch['thread_id']]);
$workerCalls=0;$workerHttp=static function($method,$url,$headers,$body,$form)use(&$workerCalls,$secondWatch,$anchor2):array {
    $workerCalls++;if($method!=='GET'||$body!==null||$form||!str_starts_with($url,VP3_GMAIL_API_V1.'/threads/'.$secondWatch['thread_id'].'?'))throw new LogicException('Unexpected worker transport');
    return ['status'=>200,'json'=>['id'=>$secondWatch['thread_id'],'messages'=>[$anchor2]]];
};
$pdo->prepare("UPDATE agent_connection_email_followups_v1 SET claim_key=NULL,next_check_at='2000-01-01 00:00:00' WHERE operation_key=?")->execute([$secondWatch['operation_key']]);
$sweep=agent_connection_email_followup_sweep_v1($pdo,5,$workerHttp);
followup_check($sweep['processed']===1&&$workerCalls===1&&agent_email_followthrough_for_watch_v1($pdo,$secondWatch)['check_result']==='no_reply',
    'the bounded existing worker advances deadlines without a new scheduler or provider write');
followup_check(agent_connection_email_followup_sweep_v1($pdo,5,$workerHttp)['processed']===0&&$workerCalls===1,
    'immediate worker replay honors the native next-check schedule');
$loadRow=static function(string $id)use($pdo):array {
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE id=?');$q->execute([$id]);return $q->fetch();
};
$loadWatch=static function(string $operation)use($pdo):array {
    $q=$pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=1 AND operation_key=?');$q->execute([$operation]);return $q->fetch();
};
$sentFollowup=static function(array $record,array $source,string $suffix,bool $watchRequested=true)use($pdo,$grant,$mailbox,$sender,$loadWatch):array {
    $id=substr(hash('sha256','vp3-gmail-followthrough-draft-v1:'.$record['id'].':'.(int)$record['source_revision'].':'.(int)$record['user_message_id']),0,32);
    $binding=['version'=>1,'followthrough_id'=>$record['id'],'revision'=>(int)$record['source_revision'],'operation_key'=>$source['operation_key'],
        'message_id'=>$source['sent_message_id'],'thread_id'=>$source['thread_id'],
        'in_reply_to'=>'<'.$source['sent_message_id'].'@example.test>','references'=>['<'.$source['sent_message_id'].'@example.test>']];
    $metadata=agent_email_review_queue_source_metadata_v1(['reason'=>'A verified response deadline passed.','priority'=>'normal','deadline'=>agent_email_followthrough_iso_v1($record['deadline_at'])]);
    $payload=['subject'=>'Follow-through '.$suffix,'body'=>'Owner reviewed follow-up.','outbound_followup'=>$binding,
        'composer'=>['version'=>3,'saved_at'=>time(),'created_at'=>time(),'origin'=>'outbound_followup','source'=>$metadata]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,watch_replies,sent_message_id)
        VALUES (?,1,7,?,?,17,?,?,?,?,'sent',0,?,?)")
        ->execute([$id,(int)$record['conversation_id'],(int)$record['user_message_id'],$grant,$mailbox,$sender,
            ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),(int)$watchRequested,'followup_'.$suffix]);
    $draft=agent_connection_email_chat_draft_v1($pdo,1,7,(int)$record['conversation_id'],$id);
    $operation=agent_connection_email_chat_operation_key_v1($id);
    $receipt=['operation_key'=>$operation,'authorization_key'=>$grant,'connection_id'=>17,'from'=>$mailbox,'to'=>$sender,
        'message_id'=>'followup_'.$suffix,'thread_id'=>$source['thread_id'],'sent_at'=>time()];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1 VALUES(1,17,?,?,'sent',?,?,?)")
        ->execute([$grant,$operation,$receipt['message_id'],$receipt['thread_id'],$receipt['sent_at']]);
    $pdo->prepare("UPDATE agent_email_followthrough_v1 SET generated_draft_id=?,generated_revision=?,draft_attempt_revision=?,draft_attempt_state='ready' WHERE id=?")
        ->execute([$id,$record['source_revision'],$record['source_revision'],$record['id']]);
    if ($watchRequested) followup_check(agent_connection_email_followup_register_v1($pdo,1,7,(int)$record['conversation_id'],$receipt,
        ['draft_id'=>$id,'user_message_id'=>(int)$record['user_message_id']]),'confirmed follow-up gets its own canonical reply watch');
    return ['draft'=>$draft,'receipt'=>$receipt,'watch'=>$watchRequested?$loadWatch($operation):null,'binding'=>$binding];
};
$sent=$sentFollowup($loadRow($secondRow['id']),$secondWatch,'two');
followup_reject(409,static fn()=>agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1(
    $pdo,$sent['draft'],array_replace($sent['receipt'],['message_id'=>'not_the_sent_message']))),'a tampered send receipt cannot alter follow-through');
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$sent['draft'],$sent['receipt']));
$afterSend=$loadRow($secondRow['id']);$newWatch=$loadWatch($sent['receipt']['operation_key']);
followup_check($afterSend['outcome']==='waiting'&&(bool)$afterSend['awaiting_new_deadline']
    &&$afterSend['active_operation_key']===$sent['receipt']['operation_key']&&$loadWatch($secondWatch['operation_key'])['status']==='stopped',
    'confirmed follow-up advances the exact reply anchor and waits for an explicit next deadline');
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$sent['draft'],$sent['receipt']));
followup_check($loadWatch($sent['receipt']['operation_key'])['status']==='waiting',
    'replaying the confirmed receipt cannot stop the newly active reply watch');
$claim=bin2hex(random_bytes(16));
$pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE operation_key=?')
    ->execute([$claim,gmdate('Y-m-d H:i:s',time()+120),$newWatch['operation_key']]);
$followupAnchor=array_replace($anchor2,['id'=>$newWatch['sent_message_id'],'internalDate'=>(string)((time()-1)*1000)]);
$noReply=agent_email_followthrough_evidence_v1($newWatch,$normalized([$anchor2,$followupAnchor]));
$observed=agent_email_followthrough_observe_v1($pdo,$newWatch,$claim,$noReply,null,false);
followup_check($observed['outcome']==='waiting'&&$observed['check_result']==='no_reply'&&$observed['awaiting_new_deadline'],
    'a later verified empty thread does not repeat automatic drafting after a follow-up was sent');
$newDeadline=agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$afterSend['id'],'expected_revision'=>3,'operation'=>'deadline']+array_diff_key($settings,['enabled'=>true]),'fixture');
$changedRow=$loadRow($afterSend['id']);
followup_check($newDeadline['revision']===4&&!$newDeadline['awaiting_new_deadline']&&(int)$changedRow['source_revision']===4
    &&$changedRow['active_operation_key']===$newWatch['operation_key']&&(int)$changedRow['user_message_id']!==(int)$afterSend['user_message_id'],
    'an explicit next deadline authorizes one new source revision on the latest sent anchor');
followup_reject(409,static fn()=>agent_email_followthrough_binding_current_v1($pdo,1,7,11,$sent['binding']),
    'a previously sent generated draft cannot act as the next deadline draft');

// A provider send may be confirmed after a later control; keep the send fact without undoing that control.
$pausedFixture=$seed('paused');$pausedSent=$sentFollowup($pausedFixture['row'],$pausedFixture['watch'],'paused');
$pause=agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$pausedFixture['row']['id'],'expected_revision'=>1,'operation'=>'pause'],'fixture');
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$pausedSent['draft'],$pausedSent['receipt']));
$pausedAfter=$loadRow($pausedFixture['row']['id']);
followup_check((bool)$pausedAfter['paused']&&(int)$pausedAfter['revision']===2&&$pausedAfter['active_operation_key']===$pausedSent['receipt']['operation_key']
    &&$loadWatch($pausedSent['receipt']['operation_key'])['status']==='paused','late send confirmation preserves Pause and points Resume at the newly sent email');
$pauseResume=agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$pausedAfter['id'],'expected_revision'=>2,'operation'=>'resume'],'fixture');
followup_check(!$pauseResume['paused']&&$pauseResume['awaiting_new_deadline']&&$pauseResume['followup_watch_registered']
    &&$loadWatch($pausedSent['receipt']['operation_key'])['status']==='waiting','resuming a late-confirmed follow-up uses its actual new watch without a new draft authorization');

$lateFixture=$seed('late');$lateSent=$sentFollowup($lateFixture['row'],$lateFixture['watch'],'late');
$deadline2=agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$lateFixture['row']['id'],'expected_revision'=>1,'operation'=>'deadline']+array_diff_key($settings,['enabled'=>true]),'fixture');
$newMarker=$loadRow($lateFixture['row']['id'])['user_message_id'];
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$lateSent['draft'],$lateSent['receipt']));
$lateAfter=$loadRow($lateFixture['row']['id']);
followup_check((int)$lateAfter['revision']===2&&(int)$lateAfter['source_revision']===2&&$lateAfter['user_message_id']===$newMarker
    &&!$lateAfter['awaiting_new_deadline']&&$lateAfter['active_operation_key']===null
    &&$lateAfter['followup_operation_key']===$lateSent['receipt']['operation_key'],
    'a late receipt preserves a newer deadline marker and only records immutable old-send facts');
agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$lateAfter['id'],'expected_revision'=>2,'operation'=>'pause'],'fixture');
followup_check($loadWatch($lateSent['receipt']['operation_key'])['status']==='paused',
    'Pause also fences an exact secondary reply watch created by late receipt confirmation');
agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$lateAfter['id'],'expected_revision'=>3,'operation'=>'resolve'],'fixture');
followup_check($loadWatch($lateSent['receipt']['operation_key'])['status']==='stopped',
    'Resolve prevents a receipt-linked secondary watch from later posting to the Chat');

$resolvedFixture=$seed('resolved');$resolvedSent=$sentFollowup($resolvedFixture['row'],$resolvedFixture['watch'],'resolved');
agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$resolvedFixture['row']['id'],'expected_revision'=>1,'operation'=>'resolve'],'fixture');
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$resolvedSent['draft'],$resolvedSent['receipt']));
followup_check($loadRow($resolvedFixture['row']['id'])['outcome']==='resolved'
    &&$loadWatch($resolvedSent['receipt']['operation_key'])['status']==='stopped','late confirmation never reopens an explicitly resolved follow-up');

$unwatchedFixture=$seed('unwatched');$unwatchedSent=$sentFollowup($unwatchedFixture['row'],$unwatchedFixture['watch'],'unwatched',false);
agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_note_sent_v1($pdo,$unwatchedSent['draft'],$unwatchedSent['receipt']));
$unwatched=agent_email_followthrough_public_v1($pdo,$loadRow($unwatchedFixture['row']['id']));
followup_check($unwatched['awaiting_new_deadline']&&!$unwatched['followup_watch_registered']&&!$unwatched['capabilities']['check']
    &&!$unwatched['capabilities']['change_deadline']&&$unwatched['capabilities']['resolve'],
    'disabled reply watching is explicit and cannot offer checks on an obsolete anchor');
followup_reject(409,static fn()=>agent_email_followthrough_update_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$unwatched['followthrough_ref'],'expected_revision'=>1,'operation'=>'deadline']+array_diff_key($settings,['enabled'=>true]),'fixture'),
    'changing a deadline cannot silently resurrect the old anchor after an unmonitored follow-up send');

// Check Gmail now remains a bounded source observation; model work belongs to the existing worker.
$manualFixture=$seed('manual');$manualWatch=$manualFixture['watch'];
$pdo->prepare("UPDATE agent_email_followthrough_v1 SET deadline_at=?,prepare_if_overdue=1 WHERE id=?")
    ->execute([gmdate('Y-m-d H:i:s',time()-30),$manualFixture['row']['id']]);
$manualAnchor=array_replace($anchor,['id'=>$manualWatch['sent_message_id'],'threadId'=>$manualWatch['thread_id']]);
$manualCalls=0;$manualHttp=static function($method,$url,$headers,$body,$form)use(&$manualCalls,$manualWatch,$manualAnchor):array {
    $manualCalls++;if($method!=='GET'||$body!==null||$form)throw new LogicException('Manual check must only read.');
    return ['status'=>200,'json'=>['id'=>$manualWatch['thread_id'],'messages'=>[$manualAnchor]]];
};
$manualChecked=agent_email_followthrough_check_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$manualFixture['row']['id'],'expected_revision'=>1],'fixture',$manualHttp);
$manualRow=$loadRow($manualFixture['row']['id']);$manualLiveWatch=$loadWatch($manualWatch['operation_key']);
followup_check($manualCalls===1&&$manualChecked['outcome']==='overdue'&&$manualRow['draft_attempt_key']===null
    &&$manualLiveWatch['claim_key']===null&&$manualLiveWatch['next_check_at']<=agent_commerce_time_pa2(),
    'manual check commits one provider observation and queues automatic preparation on the existing worker');
$beforeDeadline=time()-60;$observedAt=$beforeDeadline-10;
$pdo->prepare('UPDATE agent_email_followthrough_v1 SET deadline_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',$beforeDeadline),$manualRow['id']]);
$asOfClaim=bin2hex(random_bytes(16));
$pdo->prepare('UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=? WHERE operation_key=?')
    ->execute([$asOfClaim,gmdate('Y-m-d H:i:s',time()+120),$manualWatch['operation_key']]);
$asOfEvidence=agent_email_followthrough_evidence_v1($manualWatch,$normalized([$manualAnchor]),$observedAt);
$asOf=agent_email_followthrough_observe_v1($pdo,$manualWatch,$asOfClaim,$asOfEvidence,null,false);
followup_check($asOf['outcome']==='waiting'&&$asOf['check_result']==='no_reply'&&$asOf['checked_at']===gmdate('Y-m-d H:i:s',$observedAt),
    'a pre-deadline observation committed later retains its actual check time and cannot become overdue');
followup_reject(409,static fn()=>agent_email_followthrough_observe_v1($pdo,$manualWatch,$asOfClaim,
    array_replace($asOfEvidence,['checked_at'=>gmdate(DATE_ATOM,time()+30)]),null,false),
    'a future observation timestamp cannot authorize overdue follow-through');
for ($index=1;$index<=21;$index++) $seed('page_'.$index,12,false);
$page=agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20]);
followup_check(count($page['items'])===20&&$page['counts']['waiting']===21&&$page['has_more']&&is_string($page['next_cursor'])
    &&strlen($page['next_cursor'])<=2048,'twenty-one tracked sends produce a bounded complete first page and continuation');
$nextPage=agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20,'cursor'=>$page['next_cursor']]);
followup_check(count($nextPage['items'])===1&&!$nextPage['has_more']&&$nextPage['next_cursor']===null
    &&count(array_unique(array_column([...$page['items'],...$nextPage['items']],'followthrough_ref')))===21,
    'deadline and opaque-ID keyset paging reaches every tracked send exactly once');
$beyond=$nextPage['items'][0];
$exact=agent_email_followthrough_get_v1($pdo,$user,$principal,12,['followthrough_ref'=>$beyond['followthrough_ref']],'fixture');
followup_check($exact['followthrough_ref']===$beyond['followthrough_ref']&&$exact['mailbox_revision']===$page['mailbox_revision']
    &&$exact['state_version']===$beyond['state_version'],'an exact notification target remains readable beyond the first page');
followup_reject(404,static fn()=>agent_email_followthrough_get_v1($pdo,$user,$principal,11,
    ['followthrough_ref'=>$beyond['followthrough_ref']],'fixture'),'exact target reads cannot cross the original private Chat');
$otherPrincipal=['kind'=>'user_agent','agent_id'=>9,'owner_user_id'=>2,'viewer_user_id'=>2];
followup_reject(404,static fn()=>agent_email_followthrough_get_v1($pdo,['id'=>2,'is_active'=>1],$otherPrincipal,21,
    ['followthrough_ref'=>$beyond['followthrough_ref']],'fixture'),'another owner cannot use an opaque notification target');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,11,'fixture',['limit'=>20,'cursor'=>$page['next_cursor']]),
    'follow-through cursor is bound to the exact private Chat');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,array_replace($principal,['agent_id'=>8]),13,'fixture',['limit'=>20,'cursor'=>$page['next_cursor']]),
    'follow-through cursor is bound to the selected Agent');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'another-session',['limit'=>20,'cursor'=>$page['next_cursor']]),
    'follow-through cursor is bound to its browsing session');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>10,'cursor'=>$page['next_cursor']]),
    'follow-through cursor cannot change page size');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20,'filter'=>'all','cursor'=>$page['next_cursor']]),
    'follow-through cursor cannot change result filters');
$tampered=substr($page['next_cursor'],0,-1).(str_ends_with($page['next_cursor'],'0')?'1':'0');
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20,'cursor'=>$tampered]),
    'modified opaque cursor authentication is rejected');
$cursorScope=[1,7,12,$page['mailbox_revision'],'open',20];
$expired=agent_email_followthrough_cursor_v1('fixture',$cursorScope,[gmdate('Y-m-d H:i:s'),str_repeat('a',32)],time()-1);
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20,'cursor'=>$expired]),
    'expired browsing cursors require a fresh list');
$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=17')->execute([str_repeat('c',64)]);
followup_reject(409,static fn()=>agent_email_followthrough_list_v1($pdo,$user,$principal,12,'fixture',['limit'=>20,'cursor'=>$page['next_cursor']]),
    'mailbox reconnect invalidates old continuation authority');
$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=17')->execute([$grant]);
$decodedCursor=base64_decode(strtr(explode('.',$page['next_cursor'])[0],'-_','+/'),true);
followup_check(!str_contains($decodedCursor,$grant)&&!str_contains($decodedCursor,'page_')&&!str_contains($decodedCursor,$beyond['followthrough_ref']),
    'opaque continuation data exposes neither grants, provider IDs nor tracked record metadata');
$pdo->exec('ALTER TABLE agent_email_followthrough_v1 DROP COLUMN draft_attempt_reason');
followup_check(!agent_email_followthrough_ready_v1($pdo),'a partial lifecycle schema is unavailable before any follow-through read or mutation');
echo 'EMAIL_FOLLOWTHROUGH=PASS ('.($GLOBALS['checks']??0).' assertions, mysql)'.PHP_EOL;
