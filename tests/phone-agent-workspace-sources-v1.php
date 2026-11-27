<?php
declare(strict_types=1);
ini_set('session.use_cookies','0');
ini_set('session.cache_limiter','');

// Native MySQL, production encryption, real private source readers and real ownership guards.
// The fixture never calls a live Gmail/image/model/push provider; explicit email reads use a local HTTP double.
require_once dirname(__DIR__).'/includes/ai-settings.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-sources-v1.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-email-composer-v1.php';
require_once dirname(__DIR__).'/includes/coding-canvas-store-v1.php';
require_once dirname(__DIR__).'/includes/media-studio-v86.php';

function db(): PDO {return $GLOBALS['pdo'];}
function current_user(): array {return ['id'=>1,'is_active'=>1];}
function setting(string $key,mixed $default=''): mixed {return $default;}
function url(string $path): string {return $path;}
function has_permission(string $permission,array $user): bool
{return $permission==='users.manage'?($user['role']??'')==='admin':!empty($user['is_active'])&&in_array($permission,['account.access','chat.access'],true);}
function personal_capability_has_v242(string $permission,array $user): bool
{return empty($GLOBALS['phoneSourceDenyKnowledge'])&&in_array($permission,['personal_knowledge.access','personal_knowledge.manage'],true);}
function table_exists(string $table): bool
{$q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$q->execute([$table]);return(bool)$q->fetchColumn();}
function column_exists(string $table,string $column): bool
{$q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$q->execute([$table,$column]);return(bool)$q->fetchColumn();}
function pas_check(bool $ok,string $label): void
{if(!$ok)throw new RuntimeException($label);$GLOBALS['pasChecks']++;echo 'PASS '.$label.PHP_EOL;}
function pas_reject(int $status,callable $work,string $label): void
{try{$work();}catch(RuntimeException|DomainException|InvalidArgumentException $error){if($error instanceof PDOException||$error->getCode()!==$status)throw $error;pas_check(true,$label);return;}throw new RuntimeException('Expected denial: '.$label);}
function pas_snapshot(): array
{$out=[];foreach(db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){$rows=array_map(static fn(array $r):string=>json_encode($r,JSON_THROW_ON_ERROR),db()->query('SELECT * FROM `'.$table.'`')->fetchAll());sort($rows);$out[$table]=$rows;}return $out;}

class PhoneSourceRaceStatement extends PDOStatement
{
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {$row=parent::fetch($mode,$cursorOrientation,$cursorOffset);if($row!==false&&is_callable($GLOBALS['phoneSourceRace']??null))($GLOBALS['phoneSourceRace'])($this->queryString);return $row;}
}

function pas_draft(int $owner=1,int $agent=7,int $chat=11,bool $reply=false): array
{
    $pdo=db();$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(?,?,'user','Save an email.','{}')")->execute([$chat,$owner]);
    $marker=(int)$pdo->lastInsertId();$id=bin2hex(random_bytes(16));$at=time()-60;
    $payload=['subject'=>'Private email '.$marker,'body'=>'BODY_SENTINEL_NEVER_IN_PHONE_INDEX','composer'=>['version'=>1,'saved_at'=>$at]];
    if($reply){$payload['reply']=['message_id'=>'source_message','thread_id'=>'source_thread','in_reply_to'=>'<source@example.test>','references'=>['<source@example.test>']];
        $payload['composer']=['version'=>2,'saved_at'=>$at,'created_at'=>$at,'origin'=>'organic_reply',
            'source'=>['reason'=>'The sender asked for a response.','priority'=>'high','deadline'=>null]];}
    $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$agent,$chat,$marker,$owner===1?7:9,str_repeat($owner===1?'a':'b',64),
            $owner===1?'owner@example.test':'other@example.test','recipient@example.test',ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),'prepared',0]);
    return ['id'=>$id,'marker'=>$marker,'payload'=>$payload];
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('This source integration fixture requires native MySQL.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);$name='vp3_phone_sources_'.bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixture=sys_get_temp_dir().'/vp3-phone-sources-'.bin2hex(random_bytes(6));define('STONEFELLOW_ROOT',$fixture);mkdir($fixture.'/private',0700,true);
session_save_path($fixture.'/private');
file_put_contents($fixture.'/private/ai-key.php','<?php return '.var_export(base64_encode(random_bytes(32)),true).';');
$config=['connections'=>['gmail'=>['client_id'=>'phone-fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://phone-fixture.example.test/connection-oauth.php']]];
$pasChecks=0;
try{
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$name,$login,$password,$options);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PhoneSourceRaceStatement::class]);
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL,display_name VARCHAR(100) DEFAULT 'Fixture owner',password_hash VARCHAR(190) DEFAULT 'fixture');
        INSERT INTO users(id,is_active) VALUES(1,1),(2,1);
        CREATE TABLE settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,display_name VARCHAR(100),is_active INT);
        INSERT INTO user_agents VALUES(7,1,'Main personal Agent',1),(8,1,'Sibling Agent',1),(9,2,'Foreign Agent',1);
        CREATE TABLE chat_conversations(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES(11,1,7),(12,1,7),(13,1,8),(14,1,NULL),(21,2,9);
        CREATE TABLE chat_messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED,user_id INT UNSIGNED NULL,
            role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_data_policies(owner_user_id INT,resource_type VARCHAR(40),resource_id VARCHAR(100),audience_scope VARCHAR(30),
            owner_agents_allowed SMALLINT,profile_agent_allowed SMALLINT,stonefellow_shared SMALLINT);
        CREATE TABLE user_agent_data_rules(agent_id BIGINT,resource_type VARCHAR(40),resource_id VARCHAR(100),access_mode VARCHAR(20));
        CREATE TABLE artist_transcript_folders_v177(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created_by_user_id INT UNSIGNED,
            folder_name VARCHAR(80),sort_order INT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(created_by_user_id,folder_name));
        CREATE TABLE knowledge_items(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190),description TEXT,file_name VARCHAR(255),
            file_type VARCHAR(50),mime_type VARCHAR(120),file_size INT UNSIGNED,content_text LONGTEXT,visibility VARCHAR(30),is_published INT,
            created_by_user_id INT UNSIGNED,knowledge_scope VARCHAR(30),folder_id BIGINT UNSIGNED NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE user_media_assets(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT,media_type VARCHAR(20),title VARCHAR(190),
            mime_type VARCHAR(80),file_path TEXT,file_size INT,duration_seconds DOUBLE,source VARCHAR(60),metadata_json TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE video_editor_projects(id BIGINT PRIMARY KEY,user_id INT)");
    agent_connections_install_v1($pdo);vp3_coding_canvas_schema_v1($pdo);
    require_once dirname(__DIR__).'/includes/agent-worker-cloud-v1910.php';
    agent_job_engine_ensure_schema_v1900($pdo);vp3_image_schema_v1($pdo);
    $q=$pdo->prepare("INSERT INTO agent_connections_v1(id,owner_user_id,provider,external_account_id,account_email,status,read_enabled,
        send_enabled,followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES(?,?,'gmail',?,?,'connected',1,1,1,?,?,?,'',?)");
    foreach([[7,1,'a','owner'],[9,2,'b','other']] as [$connection,$ownerId,$letter,$mail])$q->execute([$connection,$ownerId,'fixture-'.$mail,
        $mail.'@example.test',str_repeat($letter,64),agent_connections_client_fingerprint_v1(),ai_encrypt_secret('EXPIRED_OAUTH_NEVER_REFRESH'),time()-600]);
    $owner=current_user();$now=time();
    $draft=pas_draft();$reply=pas_draft(1,7,12,true);$sibling=pas_draft(1,8,13);$foreign=pas_draft(2,9,21);$system=pas_draft(1,0,14);
    $canvasId='10000000-0000-4000-8000-000000000001';
    $canvas=vp3_coding_canvas_save_draft_v1($pdo,$owner,7,12,$canvasId,'Private canvas',
        ['html'=>'<p>CODE_SENTINEL_PRIVATE_CANVAS</p>','css'=>'p { color: blue; }','js'=>''],['private_note'=>'STATE_SENTINEL']);
    $generation='20000000-0000-4000-8000-000000000001';$imageAt=gmdate('Y-m-d H:i:s',$now-30);
    $path=media_studio_ensure_user_root(1).'/fixture.png';file_put_contents($path,'fixture-private-image-bytes');
    $pdo->prepare("INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,metadata_json)
        VALUES(1,'photo','Image asset','image/png','private/user-media/1/fixture.png',27,0,'generated','{}')")->execute();$asset=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,workflow_type,title,goal,origin,source_kind,source_key,dedupe_key,execution_target,capability_key,status)
        VALUES(1,7,'generic','Fixture image','{}','chat','image_generation',?,?,'cloud','images.generate','completed')")->execute([$generation,hash('sha256',$generation)]);$run=(int)$pdo->lastInsertId();
    $imageConfig=['title'=>'Private generated image','prompt'=>'PROMPT_SENTINEL','model'=>'gpt-image-1','size'=>'1024x1024','quality'=>'medium','background'=>'opaque'];
    $pdo->prepare('INSERT INTO agent_image_generations_v1(generation_id,owner_user_id,agent_id,conversation_id,request_hash,run_id,config_json,asset_id,created_at)
        VALUES(?,1,7,11,?,?,?,?,?)')->execute([$generation,hash('sha256','image-fixture'),$run,json_encode($imageConfig),$asset,$imageAt]);
    $read=static fn(string $kind,string $id):?array=>vp3_phone_agent_source_resolve_v1($pdo,$owner,7,$kind,$id,time());
    $before=pas_snapshot();$feed=vp3_phone_agent_sources_discover_v1($pdo,$owner,7,time());
    $kinds=array_count_values(array_column($feed['items'],'source_kind'));
    pas_check(($kinds['gmail_draft']??0)===2&&($kinds['image']??0)===1&&($kinds['canvas']??0)===1,'real private draft, image and canvas sources join the selected Agent feed');
    pas_check($feed['coverage']['gmail_attention']==='disabled'&&!$feed['has_more'],'coverage distinguishes disabled Inbox Review from verified native sources');
    $encoded=json_encode($feed,JSON_THROW_ON_ERROR);
    foreach(['BODY_SENTINEL','CODE_SENTINEL','STATE_SENTINEL','PROMPT_SENTINEL','EXPIRED_OAUTH','review_token','message_enc','authorization_key','asset_url'] as $secret)
        pas_check(!str_contains($encoded,$secret),'index projection omits '.$secret);
    pas_check(pas_snapshot()===$before,'discovery and exact resolution write no source, approval, OAuth, workflow or media state');
    pas_check($read('gmail_draft',$sibling['id'])===null&&$read('gmail_draft',$foreign['id'])===null&&$read('gmail_draft',$system['id'])===null,'other Agent, account and Main namespace drafts cannot enter this selected Agent');
    $view=$read('gmail_draft',$reply['id']);
    pas_check($view['state']==='needs_review'&&$view['needs_action']&&$view['priority']==='important'&&$view['conversation_id']===12,'organic reply retains its exact Chat, saved reason and review state');
    pas_check($view['source_revision']===$read('gmail_draft',$reply['id'])['source_revision'],'semantic revision is stable across repeated reads and ephemeral review tokens');
    $detail=vp3_phone_agent_source_detail_v1($pdo,$owner,7,'gmail_draft',$reply['id'],time(),$view['source_revision']);
    pas_check($detail===['kind'=>'gmail_draft','target'=>$view['target']],'Gmail detail selects the canonical host without duplicating email or granting approval');
    $changed=$reply['payload'];$changed['body']='Changed exact saved body';$changed['composer']['saved_at']=time();
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($changed)),$reply['id']]);
    pas_reject(409,fn()=>vp3_phone_agent_source_detail_v1($pdo,$owner,7,'gmail_draft',$reply['id'],time(),$view['source_revision']),'an old phone item cannot open an edited email as the reviewed revision');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    $blocked=$read('gmail_draft',$reply['id']);
    pas_check($blocked['state']==='unavailable'&&!$blocked['needs_action']&&!str_contains(json_encode($blocked),'Private email'),'revoked Gmail read removes reply title and actionability');
    pas_check($read('gmail_draft',$draft['id'])['state']==='needs_review','send-only mailbox still permits the canonical new-email draft surface');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1,send_enabled=0 WHERE id=7');
    pas_check($read('gmail_draft',$draft['id'])['state']==='unavailable','revoked send disables a new-email review item');
    $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=1 WHERE id=7');
    $pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='sending' WHERE id=?")->execute([$draft['id']]);
    pas_check($read('gmail_draft',$draft['id'])['state']==='waiting','uncertain sending is not reported as sent or needing another approval');
    $pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='prepared' WHERE id=?")->execute([$draft['id']]);
    $pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$draft['marker']]);
    pas_check($read('gmail_draft',$draft['id'])===null,'deleted owner draft marker removes the source');
    $image=$read('image',$generation);pas_check($image['state']==='ready'&&$image['target']['generation_id']===$generation,'completed image requires the native job and current private file');
    $pdo->exec('UPDATE user_media_assets SET user_id=2 WHERE id='.$asset);
    pas_check($read('image',$generation)['state']==='unavailable','a moved private image cannot retain an available preview');
    $pdo->exec('UPDATE user_media_assets SET user_id=1 WHERE id='.$asset);
    $pdo->exec('UPDATE agent_workflow_runs SET agent_id=8 WHERE id='.$run);
    pas_check($read('image',$generation)===null,'mismatched canonical image workflow invalidates its source');
    $pdo->exec('UPDATE agent_workflow_runs SET agent_id=7 WHERE id='.$run);
    $card=$read('canvas',$canvasId);pas_check($card['target']['revision']===1&&$card['state']==='ready','canvas item uses a private exact revision without a public release');
    vp3_coding_canvas_save_draft_v1($pdo,$owner,7,12,$canvasId,'Private canvas',['html'=>'<p>Revision two</p>','css'=>'','js'=>''],[],0,1,null);
    pas_reject(409,fn()=>vp3_phone_agent_source_detail_v1($pdo,$owner,7,'canvas',$canvasId,time(),$card['source_revision']),'stale phone canvas reference cannot open a newer artifact silently');
    vp3_coding_canvas_command_grants_install_v1($pdo);
    $canvasCard=$read('canvas',$canvasId);$canvasRow=vp3_coding_canvas_require_row_v1($pdo,1,7,$canvasId);
    $settings=vp3_coding_canvas_command_settings_v1($pdo,$canvasRow);
    vp3_coding_canvas_commands_save_v1($pdo,$owner,7,12,$canvasId,
        ['app_data'=>['app_access'=>false,'specialist_review'=>false],
            'gmail'=>['app_access'=>false,'specialist_review'=>false,'scope'=>null]],$settings['revision']);
    pas_reject(409,fn()=>vp3_phone_agent_source_detail_v1($pdo,$owner,7,'canvas',$canvasId,time(),$canvasCard['source_revision']),
        'revoking native canvas command access invalidates the open artifact source revision');
    $canvasCard=$read('canvas',$canvasId);
    $pdo->prepare('UPDATE knowledge_items SET content_text=? WHERE id=?')->execute(['{"score":2}',(int)$canvasRow['state_item_id']]);
    $pdo->prepare('UPDATE vp3_coding_canvases_v1 SET state_version=state_version+1 WHERE id=?')->execute([$canvasId]);
    pas_check($read('canvas',$canvasId)['source_revision']===$canvasCard['source_revision'],
        'ordinary private app state changes do not create another semantic canvas notification');
    $GLOBALS['phoneSourceDenyKnowledge']=true;
    pas_reject(403,fn()=>$read('canvas',$canvasId),'private Knowledge permission is still required for a phone canvas');
    unset($GLOBALS['phoneSourceDenyKnowledge']);
    vp3_coding_canvas_set_hidden_v1($pdo,$owner,7,$canvasId,true);
    pas_check($read('canvas',$canvasId)===null,'hidden native canvas disappears from the Phone feed');
    vp3_coding_canvas_set_hidden_v1($pdo,$owner,7,$canvasId,false);
    $pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=12');
    pas_check($read('gmail_draft',$reply['id'])===null,'reassigned Gmail Chat cannot keep a source in the previous Agent');
    pas_reject(403,fn()=>$read('canvas',$canvasId),'canvas original private Chat is reauthorized independently of its stored Agent field');
    $pdo->exec('UPDATE chat_conversations SET user_agent_id=7 WHERE id=12');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_id'=>4];
    pas_reject(403,fn()=>vp3_phone_agent_sources_discover_v1($pdo,$owner,7,time()),'selected participant scope cannot read the private owner feed');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
    $GLOBALS['phoneSourceRace']=static function(string $sql)use($pdo):void{
        if(str_contains($sql,'m.id AS request_message_id')){unset($GLOBALS['phoneSourceRace']);$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');}
    };
    pas_reject(403,fn()=>$read('gmail_draft',$reply['id']),'account revocation during native read is checked before releasing a card');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    $small=vp3_phone_agent_sources_discover_v1($pdo,$owner,7,time(),1);
    pas_check(count($small['items'])===1&&$small['has_more'],'bounded discovery reports incomplete coverage rather than claiming a complete inbox');
    pas_reject(422,fn()=>$read('gmail_draft','https://example.test'),'a URL cannot be supplied as a native source selector');

    // Canonical mailbox triage has no historical Chat origin. Its selected-Agent Chat is discussion only.
    require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';
    agent_connection_gmail_inbox_install_v1($pdo);agent_connection_gmail_inbox_receipts_install_v1($pdo);
    agent_connection_gmail_inbox_initial_install_v1($pdo);agent_email_matters_install_v1($pdo);agent_email_triage_install_v1($pdo);
    $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_v1(owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms)
        VALUES(1,7,?,1,?,?,?)')->execute([str_repeat('a',64),'{"triage_enabled":true}',str_repeat('c',32),($now-3600)*1000]);
    $mailbox=agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);$policy=$mailbox['policy'];
    $attentionMessage=static fn(string $id,int $received,string $thread=''):array=>[
        'message_id'=>$id,'thread_id'=>$thread?:'thread_'.$id,'received_at_ms'=>$received*1000,
        'from'=>'sender@example.test','subject'=>'Important private deadline','important'=>true,'reply'=>false,'labels'=>['INBOX'],
        'assessment'=>['status'=>'assessed','reason'=>'deadline','deadline_date'=>gmdate('Y-m-d',$now+86400)],
    ];
    $recordAttention=static function(array $message,int $at,bool $historical=false)use($pdo,$policy):string{
        agent_commerce_atomic_pa2($pdo,static fn():array=>agent_connection_gmail_inbox_review_v1($pdo,$policy,
            [hash('sha256',$message['message_id'])=>$message],gmdate('Y-m-d H:i:s',$at),$historical));
        return agent_email_matters_source_key_v1($policy,'triage',$message['message_id']);
    };
    $attentionId=$recordAttention($attentionMessage('important_one',$now-20),$now-10);
    $historicalId=$recordAttention($attentionMessage('important_old',$now-7200),$now-5,true);
    $attention=$read('gmail_attention',$attentionId);$history=$read('gmail_attention',$historicalId);
    pas_check($attention!==null&&$attention['priority']==='important'&&$attention['needs_action']
        &&$attention['target']===['kind'=>'gmail_attention','agent_id'=>7,'conversation_id'=>11,'item_ref'=>$attentionId,
            'generation'=>$mailbox['generation'],'origin'=>'gmail_mailbox','conversation_role'=>'discussion_target'],
        'important email retains mailbox provenance and the oldest existing private discussion Chat');
    pas_check($history!==null&&!$history['notification_eligible']&&$attention['notification_eligible'],
        'historical important email is visible while its canonical quiet classification stays ineligible for push');
    $attentionBefore=pas_snapshot();$attentionFeed=vp3_phone_agent_sources_discover_v1($pdo,$owner,7,time());
    pas_check($attentionFeed['coverage']['gmail_attention']==='ready'&&pas_snapshot()===$attentionBefore
        &&!str_contains(json_encode($attentionFeed),'important_one')&&!str_contains(json_encode($attentionFeed),'thread_important'),
        'important email discovery uses saved proof without provider reads, raw provider IDs, or durable mutations');
    $matterId=agent_email_matters_source_key_v1($policy,'thread','thread_important_one');
    $pdo->prepare('UPDATE agent_email_matters_v1 SET last_seen_at=?,revision=revision+1 WHERE matter_id=?')->execute([gmdate('Y-m-d H:i:s'),$matterId]);
    $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET last_checked_at=? WHERE owner_user_id=1')->execute([gmdate('Y-m-d H:i:s')]);
    pas_check($read('gmail_attention',$attentionId)['source_revision']===$attention['source_revision'],
        'routine mailbox and matter polling cannot create new important-email notification semantics');
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='snoozed' WHERE matter_id=?")->execute([$matterId]);
    pas_check($read('gmail_attention',$attentionId)===null,'snoozed email cannot remain actionable on the owner phone');
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='needs_review',last_sent_at=? WHERE matter_id=?")->execute([$now,$matterId]);
    pas_check($read('gmail_attention',$attentionId)===null,'a later recorded send suppresses the older important arrival');
    $pdo->prepare('UPDATE agent_email_matters_v1 SET last_sent_at=0 WHERE matter_id=?')->execute([$matterId]);
    $newAttentionId=$recordAttention($attentionMessage('important_newer',$now-5,'thread_important_one'),$now-2);
    pas_check($read('gmail_attention',$attentionId)===null&&$read('gmail_attention',$newAttentionId)!==null,
        'a newer verified inbox anchor replaces the stale important-email card');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');
    pas_check($read('gmail_attention',$newAttentionId)===null,'Gmail read revocation removes saved important-email content');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
    $pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=0 WHERE owner_user_id=1');
    pas_check($read('gmail_attention',$newAttentionId)===null,'turning off Inbox Review withdraws mailbox-derived phone items');
    $pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=1 WHERE owner_user_id=1');

    // The real background bridge uses a saved explicit owner enrollment, not a simulated owner session.
    require_once dirname(__DIR__).'/includes/phone-agent-workspace-worker-v1.php';
    vp3_phone_schema_v1($pdo);vp3_phone_agent_workspace_install_v1($pdo);vp3_phone_agent_workspace_worker_install_v1($pdo);
    vp3_phone_agent_workspace_worker_install_v1($pdo);
    $clock=time()+10;$deviceId=bin2hex(random_bytes(16));$token=vp3_phone_token_v1(random_bytes(32));
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,generation,location_state,created_at,expires_at,last_seen_at)
        VALUES(?,1,?,'Fixture phone','guest','approved',1,'off',?,?,?)",[$deviceId,vp3_extension_hash_v2000($token),$clock,$clock+86400,$clock]);
    $device=vp3_phone_row_v1($pdo,1,$deviceId);
    $_SESSION=['user_id'=>1,'auth_password_fingerprint'=>hash('sha256','1:fixture'),'csrf_token'=>'phone-source-csrf'];
    $enrolled=vp3_phone_agent_workspace_request_v1($pdo,$owner,$token,['action'=>'enroll','device_id'=>$deviceId,
        'csrf_token'=>'phone-source-csrf','confirmed'=>true,'agent_id'=>7,'expected_revision'=>0],$clock);
    $liveScope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$clock);
    $enabled=vp3_phone_agent_workspace_request_v1($pdo,$owner,$token,['action'=>'preferences','device_id'=>$deviceId,
        'csrf_token'=>'phone-source-csrf','notifications_enabled'=>true,'expected_revision'=>(int)$liveScope['enrollment']['revision'],
        'enrollment_revision'=>(int)$liveScope['enrollment']['revision'],'scope_revision'=>$liveScope['scope_revision']],$clock);
    require_once dirname(__DIR__).'/includes/phone-agent-workspace-native-v1.php';
    $liveScope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$clock);
    $imageRefs=vp3_phone_agent_workspace_ingest_v1($pdo,1,7,[$read('image',$generation)],$clock);
    $imageContext=['scope'=>$liveScope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$liveScope,$imageRefs[0]['item_ref'])];
    $imageInput=['action'=>'get','owner_key'=>'1','csrf_token'=>'phone-source-csrf','generation_id'=>$generation];
    $nativeImage=vp3_phone_agent_native_image_v1($pdo,$imageContext,$imageInput);
    pas_check($nativeImage['generation']['id']===$generation&&$nativeImage['generation']['status']==='completed'
        &&$nativeImage['generation']['assets'][0]['available'],'native phone reader opens an actual Chat image in its canonical UUID namespace');
    pas_reject(403,fn()=>vp3_phone_agent_native_image_v1($pdo,$imageContext,['generation_id'=>'20000000-0000-4000-8000-000000000099']+$imageInput),
        'an image item cannot select another native generation');
    rename($path,$path.'.gone');
    pas_reject(409,fn()=>vp3_phone_agent_native_image_v1($pdo,$imageContext,$imageInput),'a vanished private image invalidates the already-open native phone viewer');
    rename($path.'.gone',$path);

    $emailRefs=vp3_phone_agent_workspace_ingest_v1($pdo,1,7,[$read('gmail_attention',$newAttentionId)],$clock);
    $emailContext=['scope'=>$liveScope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$liveScope,$emailRefs[0]['item_ref'])];
    $emailInput=['action'=>'attention_open','owner_key'=>'1','csrf_token'=>'phone-source-csrf','agent_id'=>7,'conversation_id'=>11,
        'item_ref'=>$newAttentionId,'generation'=>$mailbox['generation']];
    $pdo->prepare('UPDATE agent_connections_v1 SET access_token_enc=?,access_expires_at=? WHERE id=7')->execute([ai_encrypt_secret('local-fixture-access'),$clock+3600]);
    $httpCalls=0;$http=static function(string $method,string $url,array $headers,?array $body,bool $form)use(&$httpCalls,$now):array{
        $httpCalls++;
        if($method!=='GET'||!str_contains($url,'/messages/important_newer?format=full'))throw new RuntimeException('Unexpected Gmail fixture request.');
        $html='<style>.email {color:blue}</style><div class="email">PRIVATE_READ_BODY</div>';
        return ['status'=>200,'json'=>['id'=>'important_newer','threadId'=>'thread_important_one','internalDate'=>(string)(($now-5)*1000),
            'labelIds'=>['INBOX'],'payload'=>['mimeType'=>'text/html','headers'=>[
                ['name'=>'Subject','value'=>'Important private deadline'],['name'=>'From','value'=>'sender@example.test'],
                ['name'=>'To','value'=>'owner@example.test'],['name'=>'Message-ID','value'=>'<important_newer@example.test>']],
                'body'=>['data'=>rtrim(strtr(base64_encode($html),'+/','-_'),'=')]]]];
    };
    $nativeEmail=vp3_phone_agent_native_attention_v1($pdo,$emailContext,$emailInput,$http);
    pas_check($httpCalls===1&&str_contains($nativeEmail['message']['body'],'PRIVATE_READ_BODY')
        &&isset($nativeEmail['message']['display'])&&!isset($nativeEmail['message']['auto_reply']),
        'explicit important-email open uses the canonical formatted reader with one local provider fixture and no send authority');
    $revokeHttp=static function(...$args)use($http,$pdo):array{$response=$http(...$args);$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7');return $response;};
    pas_reject(403,fn()=>vp3_phone_agent_native_attention_v1($pdo,$emailContext,$emailInput,$revokeHttp),
        'revocation during explicit email read prevents the private body from being released');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
    $admin=['role'=>'admin'];vp3_phone_push_configure_v1($pdo,$admin,true,$clock);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:fixture@example.test','revision'=>1],$clock);
    vp3_phone_push_delivery_heartbeat_v1($pdo,$clock,['runtime'=>PHP_VERSION,'transport_ready'=>true,'code'=>'ready']);
    $pushConfig=vp3_phone_push_config_v1($pdo);
    $subscription=['endpoint'=>'https://web.push.apple.com/Qphone_agent_fixture','expirationTime'=>null,
        'keys'=>['p256dh'=>vp3_phone_push_keypair_v1()['public_key'],'auth'=>vp3_phone_push_b64_v1(random_bytes(16))]];
    vp3_phone_transaction_v1($pdo,static fn():array=>vp3_phone_push_holder_v1($pdo,$device,'push_register',[
        'device_id'=>$deviceId,'subscription_revision'=>0,'confirmed'=>true,'key_id'=>hash('sha256',$pushConfig['public_key']),
        'subscription'=>$subscription],$clock));
    $session=$_SESSION;unset($_SESSION);
    $baseline=vp3_phone_agent_workspace_sweep_v1($pdo,$clock+10,1);
    pas_check($baseline['indexed']>=3&&$baseline['unavailable']===0
        &&(int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===0,
        'browser-closed sweep silently backfills existing private work without announcing old items');
    pas_check(!isset($_SESSION),'background ingestion never manufactures an owner session');
    $new=pas_draft();$newPayload=$new['payload'];$newPayload['composer']['saved_at']=$clock+11;
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($newPayload)),$new['id']]);
    $sweep=vp3_phone_agent_workspace_sweep_v1($pdo,$clock+20,1);
    if($sweep['unavailable'])vp3_phone_agent_workspace_worker_enrollment_v1($pdo,vp3_phone_agent_workspace_enrollment_v1($pdo,1,$deviceId),$clock+20);
    $events=$pdo->query("SELECT * FROM vp3_phone_push_outbox_v1 WHERE source_type='agent_item'")->fetchAll();
    pas_check($sweep['unavailable']===0&&count($events)===1,'new semantic work after explicit opt-in queues one native opaque push event');
    $event=$events[0];$eventSource=vp3_phone_push_delivery_event_source_v1($event);
    $target=vp3_phone_push_source_target_v1($pdo,$device,$eventSource,$clock+20);
    pas_check($target===['tab'=>'agent','item_ref'=>$event['source_id']]&&!str_contains(json_encode($events),'Private email'),
        'holder push resolver exposes only the Agent tab and opaque item reference');
    pas_reject(401,fn()=>vp3_phone_agent_workspace_owner_v1($pdo,$owner),'a holder notification does not provide the private owner login');
    vp3_phone_agent_workspace_sweep_v1($pdo,$clock+21,1);
    pas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===1,'repeated sweeps cannot duplicate a semantic item notification');
    $queuedItem=$pdo->query("SELECT * FROM vp3_phone_agent_items_v1 WHERE item_ref='".$event['source_id']."'")->fetch();
    $index=json_encode($pdo->query('SELECT * FROM vp3_phone_agent_items_v1')->fetchAll());
    pas_check(!str_contains($index,'Private email')&&!str_contains($index,'BODY_SENTINEL')&&!str_contains($index,'review_token'),
        'durable work index stores native identities and revisions without private source content');
    $changedAgain=$newPayload;$changedAgain['body']='A newer draft needing a fresh review';$changedAgain['composer']['saved_at']=$clock+22;
    $pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')->execute([ai_encrypt_secret(json_encode($changedAgain)),$new['id']]);
    pas_check(!vp3_phone_push_source_current_v1($pdo,$device,$eventSource,$clock+23),'editing the email invalidates the prior queued notification before dispatch');
    vp3_phone_agent_workspace_sweep_v1($pdo,$clock+24,1);
    pas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===2,'a genuine new draft revision has its own single notification identity');
    $freshEvent=$pdo->query('SELECT * FROM vp3_phone_push_outbox_v1 ORDER BY source_revision DESC LIMIT 1')->fetch();
    $freshSource=vp3_phone_push_delivery_event_source_v1($freshEvent);$_SESSION=$session;
    $liveScope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$clock+25);
    vp3_phone_agent_workspace_request_v1($pdo,$owner,$token,['action'=>'mutate','device_id'=>$deviceId,'csrf_token'=>'phone-source-csrf',
        'enrollment_revision'=>(int)$liveScope['enrollment']['revision'],'scope_revision'=>$liveScope['scope_revision'],
        'item_ref'=>$freshEvent['source_id'],'expected_revision'=>(int)$freshEvent['source_revision'],'operation'=>'read'],$clock+25);
    pas_check(!vp3_phone_push_source_current_v1($pdo,$device,$freshSource,$clock+26),'explicit owner read suppresses further escalation without approving the email');
    pas_check($pdo->query("SELECT status FROM agent_connection_email_drafts_v1 WHERE id='".$new['id']."'")->fetchColumn()==='prepared',
        'reading an Agent item never sends or approves its Gmail draft');
    unset($_SESSION);
    $freshAttentionId=$recordAttention($attentionMessage('important_after_optin',$clock+27),$clock+28);
    $quietAttentionId=$recordAttention($attentionMessage('catchup_after_optin',$clock+27),$clock+28,true);
    $attentionSweep=vp3_phone_agent_workspace_sweep_v1($pdo,$clock+29,1);
    $attentionEvents=$pdo->query("SELECT o.* FROM vp3_phone_push_outbox_v1 o JOIN vp3_phone_agent_items_v1 i ON i.item_ref=o.source_id
        WHERE i.source_kind='gmail_attention'")->fetchAll();
    pas_check($attentionSweep['unavailable']===0&&count($attentionEvents)===1,
        'new important Gmail arrival queues one opaque push while a simultaneous quiet catch-up item stays silent');
    $attentionEvent=$attentionEvents[0];$attentionEventSource=vp3_phone_push_delivery_event_source_v1($attentionEvent);
    pas_check(vp3_phone_push_source_target_v1($pdo,$device,$attentionEventSource,$clock+29)
        ===['tab'=>'agent','item_ref'=>$attentionEvent['source_id']],
        'important email push exposes no sender, subject, body, raw Gmail ID, or assumed Chat origin');
    vp3_phone_agent_workspace_sweep_v1($pdo,$clock+30,1);
    pas_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===3,
        'repeated saved Gmail observations cannot generate duplicate important-email pushes');
    $freshMatter=agent_email_matters_source_key_v1($policy,'thread','thread_important_after_optin');
    $pdo->prepare("UPDATE agent_email_matters_v1 SET stage='resolved' WHERE matter_id=?")->execute([$freshMatter]);
    pas_check(!vp3_phone_push_source_current_v1($pdo,$device,$attentionEventSource,$clock+31),
        'resolving important Gmail work invalidates its queued notification before transport');
    $_SESSION=$session;

    // Explicit main-app Canvas delivery creates a separately approved no-login device grant.
    require_once dirname(__DIR__).'/includes/coding-canvas-phone-v1.php';
    session_id('phone-source-main-'.bin2hex(random_bytes(12)));session_start();$_SESSION=$session;
    $beforeCanvas=$pdo->query('SELECT * FROM vp3_coding_canvases_v1')->fetchAll();
    $beforeKnowledge=$pdo->query('SELECT * FROM knowledge_items')->fetchAll();
    $targets=vp3_coding_canvas_phone_v1($pdo,$owner,7,12,$canvasId,2,'phone_targets',[]);
    pas_check(count($targets['phones'])===1&&!$targets['phones'][0]['agent_access']['enabled'],'legacy phone enrollment is never migrated into no-login permission silently');
    $delivery=vp3_coding_canvas_phone_v1($pdo,$owner,7,12,$canvasId,2,'phone_send',[
        'device_id'=>$deviceId,'generation'=>(int)$device['generation'],'expected_revision'=>$targets['phones'][0]['agent_access']['revision'],
        'request_id'=>'30000000-0000-4000-8000-000000000001','confirmed'=>true]);
    pas_check($beforeCanvas===$pdo->query('SELECT * FROM vp3_coding_canvases_v1')->fetchAll()
        &&$beforeKnowledge===$pdo->query('SELECT * FROM knowledge_items')->fetchAll(),
        'actual private Canvas delivery preserves canonical code, app state, revision and publication state');
    $anonymous=['csrf_token'=>'paired-source-csrf','phone_agent_workspace_key_v1'=>bin2hex(random_bytes(32)),
        'agent_connection_email_review_v1'=>bin2hex(random_bytes(32))];$_SESSION=$anonymous;
    $paired=vp3_phone_agent_workspace_request_v1($pdo,null,$token,['action'=>'status','device_id'=>$deviceId],$clock+31);
    pas_check($paired['state']==='ready'&&$paired['authorization_mode']==='paired_device'&&!isset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']),
        'actual native sources are available through approved pairing without manufacturing a main-app session');

    // The wrapper's earlier authorization cannot outlive owner-mode revocation at the actual canonical send boundary.
    $sendDraft=pas_draft();$sendSource=$read('gmail_draft',$sendDraft['id']);
    $sendRefs=vp3_phone_agent_workspace_ingest_v1($pdo,1,7,[$sendSource],$clock+31);
    $sendScope=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$token,$deviceId,false,$clock+31);
    $sendContext=['scope'=>$sendScope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$sendScope,$sendRefs[0]['item_ref'])];
    $principal=vp3_phone_agent_source_scope_v1($pdo,$owner,7,11)['principal'];$approvalKey=random_bytes(32);
    $review=agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'review','draft_id'=>$sendDraft['id']],$approvalKey);
    $confirmation=['action'=>'confirm','draft_id'=>$sendDraft['id'],'confirmed'=>true,'watch_replies'=>false,'review_token'=>$review['review_token'],
        'agent_id'=>7,'conversation_id'=>11];
    vp3_phone_agent_native_action_v1($pdo,$sendContext,'email',$confirmation);
    session_id('phone-source-native-'.bin2hex(random_bytes(12)));session_start();$_SESSION=$anonymous;session_write_close();
    $GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$sendContext,'kind'=>'email'];
    define('VP3_PHONE_AGENT_NATIVE_V1','email');

    vp3_phone_agent_native_execution_current_v1($pdo);
    $saved=agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,[
        'action'=>'compose_save','draft_id'=>$sendDraft['id'],'review_token'=>$review['review_token'],
        'to'=>'recipient@example.test','subject'=>'Reviewed on my paired phone','body'=>'Saved without a phone login.','attachment_ids'=>[]],$approvalKey);
    pas_check($saved['subject']==='Reviewed on my paired phone'&&$saved['body']==='Saved without a phone login.'
        &&!isset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']),
        'an anonymous approved phone saves edits through the real Gmail composer without sending');
    pas_reject(409,fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$confirmation,$approvalKey),
        'a no-login phone must review its changed email again before confirming a send');
    $review=agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'review','draft_id'=>$sendDraft['id']],$approvalKey);
    $confirmation['review_token']=$review['review_token'];
    require_once dirname(__DIR__).'/includes/agent-email-preferences-http-v1.php';
    session_start();$preferencesCurrent=agent_email_preferences_http_current_v1($pdo,$owner,$principal,11);session_write_close();
    pas_check($preferencesCurrent()&&!isset($_SESSION['user_id']),
        'writing preference review retains the same anonymous session and exact native draft authority');
    $pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0,revision=revision+1 WHERE device_id=?')->execute([$deviceId]);
    $sendHttpCalls=0;$sendHttp=static function(...$args)use(&$sendHttpCalls):array{$sendHttpCalls++;throw new RuntimeException('A revoked phone must never dispatch Gmail.');};
    pas_reject(403,fn()=>agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,$confirmation,$approvalKey,$sendHttp),
        'canonical Gmail confirmation revalidates Phone Agent enrollment at the provider dispatch boundary');
    pas_check($sendHttpCalls===0&&(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0
        &&$pdo->query("SELECT status FROM agent_connection_email_drafts_v1 WHERE id='".$sendDraft['id']."'")->fetchColumn()==='prepared',
        'revoked phone approval sends nothing and releases only the unattempted canonical send claim');
    session_start();session_destroy();$_SESSION=$session;unset($GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']);
    $pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=1,revision=revision+1 WHERE device_id=?')->execute([$deviceId]);
    $expected=vp3_phone_agent_workspace_enrollment_v1($pdo,1,$deviceId);
    $pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET revision=revision+1 WHERE device_id=?')->execute([$deviceId]);
    pas_reject(409,fn()=>vp3_phone_agent_workspace_worker_enrollment_v1($pdo,$expected,$clock+32),
        'a worker holding an older enrollment revision cannot enqueue after a control change');
    $pdo->exec("UPDATE users SET password_hash='changed-password' WHERE id=1");unset($_SESSION);
    pas_check(vp3_phone_agent_workspace_sweep_v1($pdo,$clock+33,1)['unavailable']===1,'password rotation fences browser-closed owner-mode ingestion');
    $pdo->exec("UPDATE users SET password_hash='fixture' WHERE id=1");
    pas_check(!isset($_SESSION),'revocation checks also leave the worker without any synthetic session');
    $pdo->exec('DROP TABLE agent_connection_email_drafts_v1');
    $partial=vp3_phone_agent_sources_discover_v1($pdo,$owner,7,time());
    pas_check($partial['coverage']['gmail_draft']==='unavailable'&&$partial['coverage']['image']==='ready','missing optional Gmail storage is explicit and preserves other verified sources');
    echo 'PASS Phone Agent native sources: '.$pasChecks." checks.\n";
}finally{
    $server->exec('DROP DATABASE IF EXISTS `'.$name.'`');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($fixture);
}
