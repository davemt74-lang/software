<?php
declare(strict_types=1);

// Shared native MySQL fixture. Production encryption, schemas, source readers and authority
// helpers are retained. Provider operations exist only through explicit deterministic doubles.
ini_set('session.use_cookies','0');ini_set('session.cache_limiter','');
require_once dirname(__DIR__,2).'/includes/ai-settings.php';
require_once dirname(__DIR__,2).'/includes/phone-agent-email-calls-settings-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-agent-email-call-sources-v1.php';
require_once dirname(__DIR__,2).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__,2).'/includes/agent-email-composer-v1.php';
require_once dirname(__DIR__,2).'/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-recording-pipeline-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-browser-v1.php';
require_once dirname(__DIR__,2).'/includes/phone-voice-conversation-v140.php';
require_once dirname(__DIR__,2).'/includes/user-calendar-v1300.php';
require_once dirname(__DIR__,2).'/includes/agent-scheduling-v430.php';
require_once __DIR__.'/phone-call-window-v1.php';

function db(): PDO { return $GLOBALS['pecPdo']; }
function current_user(): array { return pec_user_v1((int)($_SESSION['user_id']??1)); }
function setting(string $key,mixed $fallback=''): mixed { return $GLOBALS['pecSettings'][$key]??$fallback; }
function url(string $path=''): string
{ return (!empty($GLOBALS['pecRelativeUrls'])?'':'https://cloud.example').'/'.ltrim($path,'/'); }
function csrf_token(): string { return $_SESSION['csrf_token']??''; }
function has_permission(string $permission,?array $user=null): bool
{
    $user??=current_user();
    if (empty($user['is_active'])) return false;
    if (in_array($permission,['users.manage','settings.manage'],true)) return ($user['role']??'')==='admin';
    return !empty($user[$permission==='account.access'?'account_access':'chat_access']);
}
function subscription_effective_entitlement_v340(?array $user,string $key): array
{
    return $GLOBALS['pecEntitlements'][(int)($user['id']??0)][$key]??['enabled'=>true,'limit'=>3,'unlimited'=>false];
}
function subscription_has_entitlement(?array $user,string $key): bool
{ return !empty(subscription_effective_entitlement_v340($user,$key)['enabled']); }
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();
}
function pec_user_v1(int $id=1): array
{ return vp3_phone_sql_v1(db(),'SELECT * FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:[]; }
function pec_session_v1(int $id=1): void
{
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();$user=pec_user_v1($id);
    $_SESSION=['user_id'=>$id,'auth_password_fingerprint'=>hash('sha256',$id.':'.($user['password_hash']??'')),
        'csrf_token'=>'fixture-email-calls-csrf'];
}
function pec_check_v1(bool $ok,string $label): void
{ if(!$ok)throw new RuntimeException($label);$GLOBALS['pecChecks']=($GLOBALS['pecChecks']??0)+1;echo 'PASS '.$label.PHP_EOL; }
function pec_reject_v1(callable $work,string $label,?string $reason=null): void
{
    try{$work();}catch(RuntimeException|DomainException $error){
        if($error instanceof PDOException||($reason!==null&&(!($error instanceof VP3PhoneAgentEmailCallException)||$error->reason!==$reason)))throw $error;
        pec_check_v1(true,$label);return;
    }throw new RuntimeException('Expected rejection: '.$label);
}

function pec_cleanup_v1(array $fixture): void
{
    if(!empty($GLOBALS['pecCleaned']))return;$GLOBALS['pecCleaned']=true;
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $fixture['server']->exec('DROP DATABASE IF EXISTS `'.$fixture['database'].'`');
    if(is_dir($fixture['root'])){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture['root'],FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($fixture['root']);
    }
}

/** Returns pdo, owner, now, context, device_id, token, second_device_id, number_id, agent_id and conversation_id. */
function pec_fixture_v1(): array
{
    $dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
    if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Email-call acceptance requires native MySQL.');
    $login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
    $server=new PDO($dsn,$login,$password,$options);$database='vp3_email_calls_'.bin2hex(random_bytes(5));
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $root=sys_get_temp_dir().'/vp3-email-calls-'.bin2hex(random_bytes(6));mkdir($root.'/private',0700,true);
    define('STONEFELLOW_ROOT',$root);session_save_path($root.'/private');
    file_put_contents($root.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
    $GLOBALS['config']=['connections'=>['gmail'=>['client_id'=>'fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
        'redirect_uri'=>'https://cloud.example/connection-oauth.php']]];
    $GLOBALS['pecSettings']=['phone_twilio_account_sid'=>'AC'.str_repeat('a',32),'phone_twilio_auth_token'=>ai_encrypt_secret('fixture-auth-token'),
        'phone_browser_api_key_sid_v1'=>'SK'.str_repeat('c',32),'phone_browser_twiml_app_sid_v1'=>'AP'.str_repeat('d',32),
        'phone_browser_api_key_secret_v1'=>ai_encrypt_secret('fixture-browser-secret'),
        'phone_voice_webhook_url_v130'=>'https://cloud.example/api/phone-voice-inbound-v130.php',
        'phone_voice_status_url_v130'=>'https://cloud.example/api/phone-voice-status-v130.php'];
    $GLOBALS['pecPdo']=$pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $fixture=['pdo'=>$pdo,'server'=>$server,'database'=>$database,'root'=>$root,'now'=>time(),
        'number_id'=>1,'agent_id'=>7,'conversation_id'=>11];
    register_shutdown_function(static fn()=>pec_cleanup_v1($fixture));
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL,password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT 'Fixture owner',role VARCHAR(20) DEFAULT 'member',account_access INT DEFAULT 1,chat_access INT DEFAULT 1);
        INSERT INTO users(id,is_active,password_hash) VALUES(1,1,'owner-fixture-password'),(2,1,'other-fixture-password');
        CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,display_name VARCHAR(100),is_active INT);
        INSERT INTO user_agents VALUES(7,1,'Selected Agent',1),(8,1,'Other private Agent',1),(9,2,'Foreign Agent',1);
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(11,1,7),(12,1,7),(13,1,8),(14,1,NULL),(21,2,9);
        CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED NULL,
            role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_data_policies(owner_user_id INT,resource_type VARCHAR(40),resource_id VARCHAR(100),audience_scope VARCHAR(30),
            owner_agents_allowed SMALLINT,profile_agent_allowed SMALLINT,stonefellow_shared SMALLINT);
        CREATE TABLE user_agent_data_rules(agent_id BIGINT,resource_type VARCHAR(40),resource_id VARCHAR(100),access_mode VARCHAR(20))");
    user_calendar_ensure_schema_v1300($pdo);
    vp3_phone_schema_v1($pdo);vp3_phone_ensure_schema_v100($pdo);vp3_phone_sms_ensure_schema_v110($pdo);
    vp3_phone_workspace_ensure_schema_v1($pdo);VP3OperatorOwnerPhoneV1::install($pdo);
    vp3_operator_admission_install_v1($pdo);vp3_operator_auto_call_ensure_schema_v1($pdo);vp3_phone_recording_ensure_schema_v1($pdo);
    vp3_phone_voice_ensure_schema_v130($pdo);vp3_phone_voice_ensure_v140($pdo);
    vp3_phone_agent_email_calls_install_v1($pdo);vp3_phone_agent_email_call_decisions_install_v1($pdo);
    agent_connections_install_v1($pdo);agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_gmail_inbox_receipts_install_v1($pdo);agent_connection_gmail_inbox_initial_install_v1($pdo);
    agent_email_matters_install_v1($pdo);agent_email_triage_install_v1($pdo);
    $now=$fixture['now'];$callWindow=vp3_phone_fixture_call_window_v1($now);$fixture['call_window']=$callWindow;
    pec_session_v1();$owner=pec_user_v1();$fixture['owner']=$owner;
    VP3OperatorOwnerPhoneV1::saveNumber($pdo,$owner,['phone_e164'=>'+16025550100','expected_revision'=>'0'],$now-7200);
    VP3OperatorOwnerPhoneV1::savePreferences($pdo,$owner,['expected_revision'=>'1','updates_requested'=>'1',
        'update_scope'=>'important','max_calls_per_day'=>'1',
        'min_gap_minutes'=>'240','max_duration_seconds'=>'180']+$callWindow,$now-7100);
    $pdo->exec("INSERT INTO phone_cloud_numbers_v100(id,provider,provider_sid,phone_e164,owner_user_id,agent_id,status,voice_enabled,sms_enabled)
        VALUES(1,'twilio','PN".str_repeat('b',32)."','+16025550101',1,7,'active',1,1)");
    vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
    vp3_phone_recording_save_policy_v1($pdo,$owner,7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
    foreach([1,2] as $index){
        $id=bin2hex(random_bytes(16));$token=vp3_phone_token_v1(random_bytes(32));$hash=hash('sha256',$token);
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,generation,location_state,created_at,expires_at,last_seen_at)
            VALUES(?,1,?,?,'owner','approved',1,'off',?,?,?)",[$id,$hash,'Owner phone '.$index,$now-7200,$now+86400,$now]);
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_agent_enrollments_v1(owner_user_id,device_id,grant_id,credential_hash,owner_fingerprint,
            enabled,revision,selected_agent_id,conversation_id,notifications_enabled,notifications_since_epoch,created_epoch,updated_epoch)
            VALUES(1,?,?,?,?,1,1,7,11,0,0,?,?)',[$id,bin2hex(random_bytes(16)),$hash,hash('sha256','1:'.$owner['password_hash']),$now-7200,$now-7200]);
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,phone_revision,phone_e164,
            owner_calls,holder_calls,holder_proactive,agent_call_consent,timezone,window_start,window_end,max_calls_per_day,min_gap_minutes,updated_epoch)
            VALUES(1,?,?,1,1,'+16025550100',1,1,1,2,?,?,?,1,240,?)",[$id,bin2hex(random_bytes(16)),
                $callWindow['timezone'],$callWindow['window_start'],$callWindow['window_end'],$now-7200]);
        if($index===1){$fixture['device_id']=$id;$fixture['token']=$token;}else{$fixture['second_device_id']=$id;$fixture['second_token']=$token;}
    }
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$fixture,$now):array{
        vp3_phone_agent_email_calls_save_v1($pdo,$owner,['expected_revision'=>0,'urgent_enabled'=>true,'drafts_enabled'=>true,
            'grace_seconds'=>60,'selected_device_id'=>$fixture['device_id'],'number_id'=>1,'confirmed'=>true],$now-3600);
        return ['saved'=>true];
    });
    $pdo->prepare("INSERT INTO agent_connections_v1(id,owner_user_id,provider,external_account_id,account_email,status,read_enabled,send_enabled,
        followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES(7,1,'gmail','fixture-owner','owner@example.test','connected',1,1,1,?,?,?,'',?)")
        ->execute([str_repeat('a',64),agent_connections_client_fingerprint_v1(),ai_encrypt_secret('LOCAL_FIXTURE_TOKEN'),$now+3600]);
    $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_v1(owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
        VALUES(1,7,?,1,?,?,?)')->execute([str_repeat('a',64),'{"triage_enabled":true}',str_repeat('c',32),($now-7200)*1000]);
    $fixture['context']=vp3_phone_agent_email_calls_context_v1($pdo,1,$now);
    $GLOBALS['pecMessages']=[];$GLOBALS['pecThreads']=[];$GLOBALS['pecHttpCalls']=0;
    return $fixture;
}

function pec_message_v1(string $id,string $thread,int $at,string $from='sender@example.test',array $labels=['INBOX'],string $subject='Private fixture subject'): array
{
    return ['id'=>$id,'threadId'=>$thread,'internalDate'=>(string)($at*1000),'labelIds'=>$labels,
        'payload'=>['headers'=>[['name'=>'From','value'=>$from],['name'=>'To','value'=>'owner@example.test'],
            ['name'=>'Subject','value'=>$subject],['name'=>'Message-ID','value'=>'<'.$id.'@example.test>']],
            'mimeType'=>'text/plain','body'=>['data'=>rtrim(strtr(base64_encode('BODY_SENTINEL_PRIVATE_MAIL'),'+/','-_'),'=')]]];
}

function pec_http_v1(string $method,string $url,array $headers,?array $body,bool $form): array
{
    ++$GLOBALS['pecHttpCalls'];
    if($method!=='GET'||$body!==null||$form)throw new LogicException('Fixture forbids any provider mutation.');
    $path=(string)parse_url($url,PHP_URL_PATH);$result=null;
    if(preg_match('~/messages/([^/]+)$~',$path,$m))$result=$GLOBALS['pecMessages'][$m[1]]??null;
    if(preg_match('~/threads/([^/]+)$~',$path,$m))$result=['id'=>$m[1],'messages'=>$GLOBALS['pecThreads'][$m[1]]??[]];
    if($result===null)throw new LogicException('Unexpected fixture provider URL: '.$path);
    return ['status'=>200,'json'=>$result];
}

function pec_attention_v1(array $fixture,string $id='incoming_one',?int $at=null,bool $historical=false): string
{
    $at??=$fixture['now']-600;$thread='thread_'.$id;
    $raw=pec_message_v1($id,$thread,$at);$GLOBALS['pecMessages'][$id]=$raw;$GLOBALS['pecThreads'][$thread]=[$raw];
    $scope=agent_connection_gmail_inbox_bound_scope_v1($fixture['pdo'],$fixture['owner']);$policy=$scope['policy'];
    $message=['message_id'=>$id,'thread_id'=>$thread,'received_at_ms'=>$at*1000,'from'=>'sender@example.test',
        'subject'=>'Private fixture subject','important'=>true,'reply'=>false,'labels'=>['INBOX'],
        'assessment'=>['status'=>'assessed','reason'=>'deadline','deadline_date'=>gmdate('Y-m-d',$fixture['now']+86400)]];
    agent_commerce_atomic_pa2($fixture['pdo'],static fn():array=>agent_connection_gmail_inbox_review_v1($fixture['pdo'],$policy,
        [hash('sha256',$id)=>$message],gmdate('Y-m-d H:i:s',$at+10),$historical));
    return agent_email_matters_source_key_v1($policy,'triage',$id);
}

function pec_draft_v1(array $fixture,string $sourceId='incoming_one',array $changes=[]): array
{
    $pdo=$fixture['pdo'];$agent=(int)($changes['agent_id']??7);$chat=(int)($changes['conversation_id']??11);
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(?,1,'user',?,'{}',?)")
        ->execute([$chat,'Please prepare this reply for review.',gmdate('Y-m-d H:i:s',$fixture['now']-500)]);
    $marker=(int)$pdo->lastInsertId();$id=bin2hex(random_bytes(16));$at=(int)($changes['created_epoch']??$fixture['now']-300);
    $raw=$GLOBALS['pecMessages'][$sourceId]??pec_message_v1($sourceId,'thread_'.$sourceId,$fixture['now']-600);
    $GLOBALS['pecMessages'][$sourceId]=$raw;$GLOBALS['pecThreads'][$raw['threadId']]??=[$raw];
    $original=agent_connection_gmail_reply_from_message_v1($raw,'owner@example.test');
    $payload=['subject'=>$original['subject'],'body'=>$changes['body']??'BODY_SENTINEL_PRIVATE_DRAFT','reply'=>$original['reply'],
        'composer'=>['version'=>2,'saved_at'=>$at,'created_at'=>$at,'origin'=>'organic_reply',
            'source'=>['reason'=>'PRIVATE_REASON_SENTINEL','priority'=>$changes['priority']??'normal','deadline'=>$changes['deadline']??null]]];
    if(!empty($changes['manual']))$payload['composer']=['version'=>1,'saved_at'=>$at];
    $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,
        connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at) VALUES(?,1,?,?,?,7,?,?,?, ?,?,0)')
        ->execute([$id,$agent,$chat,$marker,str_repeat('a',64),'owner@example.test',$original['to'],
            ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),$changes['status']??'prepared']);
    return ['id'=>$id,'marker'=>$marker,'payload'=>$payload];
}

function pec_peer_v1(array $fixture): PDO
{
    return new PDO(preg_replace('/;dbname=[^;]+/','',(string)getenv('VP3_TEST_MYSQL_DSN')).';dbname='.$fixture['database'],
        getenv('VP3_TEST_MYSQL_USER')?:'root',(string)getenv('VP3_TEST_MYSQL_PASS'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}

/** Seed a committed generated artifact against actual send/watch/deadline and provider-check authority. */
function pec_followthrough_v1(array $fixture,string $name): array
{
    require_once dirname(__DIR__,2).'/includes/agent-email-followthrough-draft-v1.php';
    require_once dirname(__DIR__,2).'/includes/agent-brain-v82.php';
    $pdo=$fixture['pdo'];$now=$fixture['now'];agent_email_followthrough_install_v1($pdo);
    $marker=$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at) VALUES(11,1,'user',?,'{}',?)");
    $marker->execute(['Send my original request.',gmdate('Y-m-d H:i:s',$now-1100)]);$originMarker=(int)$pdo->lastInsertId();
    $marker->execute(['Set a response deadline and prepare a follow-up.',gmdate('Y-m-d H:i:s',$now-900)]);$deadlineMarker=(int)$pdo->lastInsertId();
    $origin=bin2hex(random_bytes(16));$operation=agent_connection_email_chat_operation_key_v1($origin);
    $message='sent_'.$name;$thread='thread_'.$name;$sent=$now-1000;$subject='Private follow-up subject '.$name;
    $raw=pec_message_v1($message,$thread,$sent,'owner@example.test',['SENT'],$subject);
    $raw['payload']['headers'][1]['value']='recipient@example.test';
    $GLOBALS['pecMessages'][$message]=$raw;$GLOBALS['pecThreads'][$thread]=[$raw];
    $original=['subject'=>$subject,'body'=>'PRIVATE_ORIGINAL_BODY','composer'=>['version'=>1,'saved_at'=>$sent]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,
        connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,sent_message_id)
        VALUES(?,1,7,11,?,7,?,'owner@example.test','recipient@example.test',?,'sent',0,?)")
        ->execute([$origin,$originMarker,str_repeat('a',64),ai_encrypt_secret(json_encode($original,JSON_THROW_ON_ERROR)),$message]);
    $receipt=['operation_key'=>$operation,'authorization_key'=>str_repeat('a',64),'connection_id'=>7,'from'=>'owner@example.test',
        'to'=>'recipient@example.test','message_id'=>$message,'thread_id'=>$thread,'sent_at'=>$sent];
    $pdo->prepare("INSERT INTO agent_connection_sends_v1(owner_user_id,connection_id,authorization_key,operation_key,payload_digest,
        status,provider_message_id,provider_thread_id,sent_at) VALUES(1,7,?,?,?,'sent',?,?,?)")
        ->execute([str_repeat('a',64),$operation,hash('sha256',$name),$message,$thread,$sent]);
    if (!agent_connection_email_followup_register_v1($pdo,1,7,11,$receipt,['draft_id'=>$origin,'user_message_id'=>$originMarker]))
        throw new RuntimeException('Native follow-up watch did not register.');
    $draft=agent_connection_email_chat_draft_v1($pdo,1,7,11,$origin);
    $config=['enabled'=>true,'deadline_at'=>gmdate('Y-m-d\TH:i:s\Z',$now-600),'time_zone'=>'UTC','prepare_if_overdue'=>true,
        'priority'=>'high','user_message_id'=>$deadlineMarker];
    $row=agent_commerce_atomic_pa2($pdo,static fn():?array=>agent_email_followthrough_register_v1($pdo,$draft,$receipt,$config));
    $principal=vp3_agent_chat_principal_v380($fixture['owner'],vp3_agent_chat_resolve_agent_v380($pdo,$fixture['owner'],7));
    agent_email_followthrough_check_v1($pdo,$fixture['owner'],$principal,11,
        ['followthrough_ref'=>$row['id'],'expected_revision'=>(int)$row['revision']],str_repeat('k',32),'pec_http_v1');
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM agent_email_followthrough_v1 WHERE id=?',[$row['id']])->fetch(PDO::FETCH_ASSOC);
    $watch=vp3_phone_sql_v1($pdo,'SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=1 AND operation_key=?',[$operation])->fetch(PDO::FETCH_ASSOC);
    $source=agent_email_followthrough_source_v1($pdo,$row,$watch,'pec_http_v1',false);$id=agent_email_followthrough_draft_id_v1($row);
    $payload=['subject'=>$source['subject'],'body'=>'PRIVATE_GENERATED_FOLLOWUP','outbound_followup'=>$source['outbound_followup'],
        'composer'=>['version'=>3,'saved_at'=>$now-300,'created_at'=>$now-300,'origin'=>'outbound_followup',
            'source'=>['reason'=>'The owner response deadline passed with verified no reply.','priority'=>'high','deadline'=>$config['deadline_at']]]];
    $pdo->prepare("INSERT INTO agent_connection_email_drafts_v1(id,owner_user_id,agent_id,conversation_id,user_message_id,
        connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,watch_replies)
        VALUES(?,1,7,11,?,7,?,'owner@example.test',?,?,'prepared',0,1)")
        ->execute([$id,$deadlineMarker,str_repeat('a',64),$source['to'],ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR))]);
    $pdo->prepare("UPDATE agent_email_followthrough_v1 SET generated_draft_id=?,generated_revision=source_revision,
        draft_attempt_state='ready',draft_attempt_revision=source_revision,state_version=state_version+1 WHERE id=?")
        ->execute([$id,$row['id']]);
    return ['id'=>$id,'row_id'=>$row['id'],'operation'=>$operation,'origin'=>$origin,'principal'=>$principal,'payload'=>$payload,'thread'=>$thread];
}
