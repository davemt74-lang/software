<?php
declare(strict_types=1);

// Actual MySQL authority, Brain policy, receipts, drafts and notifications; only remote I/O is controlled.
require_once dirname(__DIR__,2).'/includes/agent-gmail-workspace-context-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-email-followthrough-draft-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-initiative-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-brain-v82.php';
require_once dirname(__DIR__,2).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__,2).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__,2).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__,2).'/includes/notifications.php';

function db(): PDO{return $GLOBALS['ft_pdo'];}
function current_user(): array{return ['id'=>1,'is_active'=>1];}
function csrf_token(): string{return 'followthrough-fixture-session';}
function setting(string $key,mixed $fallback=''): mixed{return $fallback;}
function url(string $path): string{return $path;}
function has_permission(string $permission,array $user): bool{return !empty($user['is_active']) && in_array($permission,['chat.access','account.access'],true);}
function table_exists(string $table): bool{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function ft_check(bool $pass,string $label): void{
    if(!$pass)throw new RuntimeException($label);$GLOBALS['ft_checks']=($GLOBALS['ft_checks'] ?? 0)+1;echo 'PASS '.$label.PHP_EOL;
}
function ft_reject(int $status,callable $work,string $label): void{
    try{$work();}catch(DomainException|RuntimeException $error){
        if($error instanceof PDOException || (int)$error->getCode()!==$status)throw $error;ft_check(true,$label);return;
    }throw new RuntimeException('Expected rejection: '.$label);
}
function ft_policy(bool $enabled=true,int $chat=11,int $agent=7): array{
    $policy=['enabled'=>$enabled,'owner_user_id'=>1,'agent_id'=>$agent,'conversation_id'=>$chat,'revision'=>bin2hex(random_bytes(16)),
        'screen_enabled'=>false,'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'allow_auto_drafts'=>false];
    $id=vp3_initiative_in_scope_v1(current_user(),$agent,static fn()=>agent_brain_v122_upsert_system_memory(current_user(),
        'initiative_policy','owner-opt-in','Owner selected Initiative policy.',$policy));
    if($id<1)throw new RuntimeException('Native Initiative policy did not persist.');return $policy;
}
function ft_row(string $id): array{
    $q=db()->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE id=?');$q->execute([$id]);return $q->fetch() ?: [];
}
function ft_watch(array $row): array{
    $q=db()->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?');
    $q->execute([$row['owner_user_id'],$row['active_operation_key'] ?? $row['operation_key']]);return $q->fetch() ?: [];
}
function ft_claim(array $row): array{
    $claim=bin2hex(random_bytes(16));$watch=ft_watch($row);
    db()->prepare("UPDATE agent_connection_email_followups_v1 SET status='waiting',claim_key=?,next_check_at=? WHERE owner_user_id=? AND operation_key=?")
        ->execute([$claim,gmdate('Y-m-d H:i:s',time()+120),$row['owner_user_id'],$watch['operation_key']]);
    return ['row'=>ft_row($row['id']),'watch'=>ft_watch($row),'claim'=>$claim];
}
function ft_message(string $id,string $thread,string $from,string $to,string $body,array $labels=['SENT'],string $parent='',?int $at=null): array{
    $headers=['From'=>$from,'To'=>$to,'Subject'=>'Exact subject '.substr($thread,7),'Message-ID'=>'<'.$id.'@example.test>',
        'In-Reply-To'=>$parent,'References'=>$parent,'Authentication-Results'=>'mx.google.com; dmarc=pass'];
    return ['id'=>$id,'threadId'=>$thread,'labelIds'=>$labels,'internalDate'=>(string)(($at ?? time()-300)*1000),
        'payload'=>['mimeType'=>'text/plain','headers'=>array_map(static fn($name,$value)=>['name'=>$name,'value'=>$value],array_keys($headers),$headers),
            'body'=>['data'=>rtrim(strtr(base64_encode($body),'+/','-_'),'=')]]];
}
function ft_http(string $method,string $url,array $headers,?array $body,bool $form): array{
    if(db()->inTransaction())throw new LogicException('Gmail I/O inside transaction.');
    if($method!=='GET' || $body!==null || $form)throw new LogicException('Preparation attempted an external mutation.');
    $GLOBALS['ft_http_calls']=($GLOBALS['ft_http_calls'] ?? 0)+1;
    if(is_callable($GLOBALS['ft_during_http'] ?? null)){
        $change=$GLOBALS['ft_during_http'];unset($GLOBALS['ft_during_http']);$change();
    }
    if(!empty($GLOBALS['ft_http_unavailable']))return ['status'=>503,'json'=>['error'=>['message'=>'Fixture Gmail source unavailable']]];
    if(preg_match('~/threads/([^?]+)~',$url,$m))return ['status'=>200,'json'=>['id'=>$m[1],'messages'=>$GLOBALS['ft_mail'][$m[1]] ?? []]];
    if(preg_match('~/messages/([^?]+)~',$url,$m)){
        foreach($GLOBALS['ft_mail'] ?? [] as $messages)foreach($messages as $message)if($message['id']===$m[1])return ['status'=>200,'json'=>$message];
        return ['status'=>404,'json'=>[]];
    }throw new LogicException('Unexpected Gmail read.');
}
function ft_seed(string $name,int $chat=11,bool $prepare=true,string $priority='normal'): array{
    $pdo=db();$q=$pdo->prepare('SELECT user_id,user_agent_id FROM chat_conversations WHERE id=?');$q->execute([$chat]);$scope=$q->fetch();
    $owner=(int)$scope['user_id'];$agent=(int)$scope['user_agent_id'];$connection=$owner===1?17:18;$grant=str_repeat($owner===1?'a':'b',64);
    $mailbox=$owner===1?'owner@example.test':'other@example.test';$recipient='recipient@example.test';
    $id=substr(hash('sha256','native followthrough '.$name),0,32);$operation=agent_connection_email_chat_operation_key_v1($id);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES (?,?,'user','Write the original email.',?)")
        ->execute([$chat,$owner,agent_commerce_time_pa2()]);$origin=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES (?,?,'user','Set my response deadline and prepare a brief follow-up.',?,?)")
        ->execute([$chat,$owner,'{"source":"agent_email_followthrough_deadline","skip_brain_archive":true}',agent_commerce_time_pa2()]);$marker=(int)$pdo->lastInsertId();
    $provider='sent_'.$name;$thread='thread_'.$name;$sentAt=time()-300;
    $payload=['subject'=>'Exact subject '.$name,'body'=>'PRIVATE_ORIGINAL_BODY_'.$name,'composer'=>['version'=>1,'saved_at'=>time()]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,
        account_email,recipient,message_enc,status,expires_at,sent_message_id) VALUES (?,?,?,?,?,?,?,?,?,?,'sent',0,?)")
        ->execute([$id,$owner,$agent,$chat,$origin,$connection,$grant,$mailbox,$recipient,ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),$provider]);
    $receipt=['operation_key'=>$operation,'authorization_key'=>$grant,'connection_id'=>$connection,'from'=>$mailbox,'to'=>$recipient,
        'message_id'=>$provider,'thread_id'=>$thread,'sent_at'=>$sentAt];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1(owner_user_id,connection_id,authorization_key,operation_key,payload_digest,status,provider_message_id,provider_thread_id,sent_at)
        VALUES(?,?,?,?,?,'sent',?,?,?)")->execute([$owner,$connection,$grant,$operation,hash('sha256',$name),$provider,$thread,$sentAt]);
    $draft=agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$chat,$id);
    if(!agent_connection_email_followup_register_v1($pdo,$owner,$agent,$chat,$receipt,['draft_id'=>$id,'user_message_id'=>$origin]))throw new RuntimeException('Native watch registration failed.');
    $config=['enabled'=>true,'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'time_zone'=>'America/Phoenix','prepare_if_overdue'=>$prepare,
        'priority'=>$priority,'user_message_id'=>$marker];
    $row=agent_commerce_atomic_pa2($pdo,static fn()=>agent_email_followthrough_register_v1($pdo,$draft,$receipt,$config));
    $pdo->prepare("UPDATE agent_email_followthrough_v1 SET outcome='overdue',check_state='verified',check_result='no_reply',checked_at=?,evidence_digest=? WHERE id=?")
        ->execute([agent_commerce_time_pa2(),hash('sha256','source '.$name),$row['id']]);
    $GLOBALS['ft_mail'][$thread]=[ft_message($provider,$thread,$mailbox,$recipient,$payload['body'],at:$sentAt)];
    return ft_claim(ft_row($row['id']))+['draft'=>$draft,'receipt'=>$receipt];
}
function ft_prepare(array $fixture,?callable $inference=null): void{
    agent_email_followthrough_prepare_v1(db(),$fixture['row'],$fixture['watch'],[],$fixture['claim'],'ft_http',$inference ?? 'ft_infer');
}
function ft_infer(string $prompt,string $protocol,callable $before,array $job,bool $transient): string{
    if(db()->inTransaction())throw new LogicException('Inference inside transaction.');
    $before();$GLOBALS['ft_dispatches']=($GLOBALS['ft_dispatches'] ?? 0)+1;
    $GLOBALS['ft_last_inference']=compact('prompt','protocol','job','transient');
    return '{"draft":"PRIVATE_GENERATED_BODY: Following up on my earlier request. Could you share an update?"}';
}
function ft_state(): array{
    $state=[];foreach(db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $rows=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),db()->query('SELECT * FROM `'.$table.'`')->fetchAll());sort($rows);$state[$table]=$rows;
    }return $state;
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Native MySQL is required.');
$pdo=$GLOBALS['ft_pdo']=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER') ?: 'root',(string)getenv('VP3_TEST_MYSQL_PASS'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='vp3_ft_context_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.$database);
$root=sys_get_temp_dir().'/vp3-followthrough-native-'.bin2hex(random_bytes(8));define('STONEFELLOW_ROOT',$root);mkdir($root,0700);
register_shutdown_function(static function()use($pdo,$database,$root):void{
    if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE '.$database);
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($root);
});
$config=['connections'=>['gmail'=>['client_id'=>'followthrough-context-fixture','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://fixture.example.test/connection-oauth.php']]];
$pdo->exec("SET time_zone='+00:00';
    CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL);INSERT INTO users VALUES(1,1),(2,1);
    CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,display_name VARCHAR(100) DEFAULT 'Fixture Agent',is_active INT);
    INSERT INTO user_agents(id,owner_user_id,is_active) VALUES(7,1,1),(8,1,1),(9,2,1);
    CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED,title VARCHAR(190) DEFAULT '',updated_at DATETIME);
    INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(11,1,7),(12,1,7),(13,1,8),(14,1,NULL),(21,2,9);
    CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED,
        role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE notifications(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,type VARCHAR(50),title VARCHAR(190),
        body VARCHAR(500),target_url VARCHAR(500),source_type VARCHAR(80),source_id BIGINT UNSIGNED,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE user_data_policies(owner_user_id INT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(100),
        audience_scope VARCHAR(30),owner_agents_allowed SMALLINT,profile_agent_allowed SMALLINT,stonefellow_shared SMALLINT);
    CREATE TABLE user_agent_data_rules(agent_id BIGINT,resource_type VARCHAR(40),resource_id VARCHAR(100),access_mode VARCHAR(20));
    CREATE TABLE agent_chat_archive(id BIGINT UNSIGNED PRIMARY KEY,user_agent_id BIGINT NULL,created_at DATETIME,
        INDEX idx_agent_archive_agent_created_v410(user_agent_id,created_at));
    CREATE TABLE agent_memory_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT NULL,
        memory_type VARCHAR(40),subject VARCHAR(190),memory_text TEXT,memory_hash VARCHAR(64),memory_scope_version INT DEFAULT 410,
        source_archive_id BIGINT NULL,confidence DOUBLE,occurrence_count INT,first_seen_at DATETIME,last_seen_at DATETIME,is_active INT,metadata_json TEXT,
        UNIQUE(user_id,memory_hash),INDEX idx_agent_memory_agent_type_v410(user_agent_id,memory_type),
        INDEX idx_agent_memory_agent_occurrence_v410(user_agent_id,occurrence_count))");
agent_connections_install_v1($pdo);
$q=$pdo->prepare("INSERT INTO agent_connections_v1(id,owner_user_id,provider,account_email,status,read_enabled,send_enabled,followups_enabled,
    authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at) VALUES(?,?,'gmail',?,'connected',1,1,1,?,?,?,'',?)");
foreach([[17,1,'a','owner'],[18,2,'b','other']] as [$id,$owner,$letter,$name])$q->execute([$id,$owner,$name.'@example.test',str_repeat($letter,64),
    agent_connections_client_fingerprint_v1(),ai_encrypt_secret('PRIVATE_ACCESS_'.$name),time()+3600]);
ft_policy();
$owner=current_user();$principal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,7));
