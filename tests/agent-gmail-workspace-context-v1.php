<?php
declare(strict_types=1);

// Native stores, encryption, permission checks and model-context adapters.
// No Gmail transport or writing model is used by the read-only projection.
require_once dirname(__DIR__).'/includes/agent-gmail-workspace-context-v1.php';
require_once dirname(__DIR__).'/includes/user-agent-system-v236.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__).'/includes/agent-surface-context-v131.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function current_user(): array { return ['id'=>1,'is_active'=>1]; }
function csrf_token(): string { return 'workspace-fixture-session'; }
function setting(string $name,mixed $fallback=''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function has_permission(string $permission,array $user): bool
{
    return !empty($user['is_active']) && in_array($permission,['chat.access','account.access'],true);
}
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);return (bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $q->execute([$table,$column]);return (bool)$q->fetchColumn();
}
function workspace_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label.PHP_EOL;
}
function workspace_reject(int $status,callable $read,string $label): void
{
    try{$read();}catch(RuntimeException|DomainException $error){
        if($error instanceof PDOException||(int)$error->getCode()!==$status)throw $error;
        workspace_check(true,$label);return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}
class WorkspaceRaceStatement extends PDOStatement
{
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0): mixed
    {
        $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);
        if($row!==false&&is_callable($GLOBALS['workspace_race']??null))($GLOBALS['workspace_race'])($this->queryString);
        return $row;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        $rows=parent::fetchAll($mode,...$args);
        if(is_callable($GLOBALS['workspace_race']??null))($GLOBALS['workspace_race'])($this->queryString);
        return $rows;
    }
}
function workspace_draft(PDO $pdo,array $fields=[]): array
{
    $owner=$fields['owner']??1;$agent=$fields['agent']??7;$cid=$fields['conversation']??11;
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,?,'user','Review email.','{}')")
        ->execute([$cid,$owner]);$mid=(int)$pdo->lastInsertId();$id=bin2hex(random_bytes(16));
    $payload=$fields['payload']??['subject'=>$fields['subject']??'Private subject '.$mid,
        'body'=>'PRIVATE_BODY_NEVER_CONTEXT','composer'=>['version'=>1,'saved_at'=>time()]];
    $pdo->prepare('INSERT INTO agent_connection_email_drafts_v1
        (id,owner_user_id,agent_id,conversation_id,user_message_id,connection_id,authorization_key,account_email,recipient,message_enc,status,expires_at,sent_message_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$owner,$agent,$cid,$mid,$owner===1?7:9,
            $fields['grant']??str_repeat($owner===1?'a':'b',64),$owner===1?'owner@example.test':'other@example.test',
            'private-recipient@example.test',ai_encrypt_secret(json_encode($payload,JSON_THROW_ON_ERROR)),
            $fields['status']??'prepared',$fields['expires']??0,$fields['provider_id']??null]);
    return agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$cid,$id);
}
function workspace_receipt(PDO $pdo,array $draft,string $messageId,?string $source=null): int
{
    $context=['source'=>$source??'agent_connection_email','email_outcome'=>['draft_id'=>$draft['id'],'message_id'=>$messageId]];
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (?,NULL,'assistant','Receipt.',?)")
        ->execute([$draft['conversation_id'],json_encode($context,JSON_THROW_ON_ERROR)]);
    return (int)$pdo->lastInsertId();
}
function workspace_state(PDO $pdo): array
{
    $out=[];
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        $rows=array_map(static fn(array $row):string=>json_encode($row,JSON_THROW_ON_ERROR),$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
        sort($rows);$out[$table]=$rows;
    }
    return $out;
}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Native MySQL is required for Gmail workspace context.');
$login=getenv('VP3_TEST_MYSQL_USER')?:'root';$password=(string)getenv('VP3_TEST_MYSQL_PASS');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$server=new PDO($dsn,$login,$password,$options);$database='vp3_gmail_context_'.bin2hex(random_bytes(6));
$server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$fixtureRoot=sys_get_temp_dir().'/vp3-gmail-context-'.bin2hex(random_bytes(6));
define('STONEFELLOW_ROOT',$fixtureRoot);mkdir($fixtureRoot,0700);
$config=['connections'=>['gmail'=>['client_id'=>'context-fixture.apps.googleusercontent.com','client_secret'=>str_repeat('x',24),
    'redirect_uri'=>'https://context-fixture.example.test/connection-oauth.php']]];
$checks=0;
try{
    $pdo=new PDO(preg_replace('/;dbname=[^;]+/','',$dsn).';dbname='.$database,$login,$password,$options);
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[WorkspaceRaceStatement::class]);
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY,is_active INT NOT NULL);
        INSERT INTO users VALUES (1,1),(2,1);
        CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NOT NULL);
        CREATE TABLE user_data_policies (owner_user_id INT UNSIGNED,resource_type VARCHAR(40),resource_id VARCHAR(100),
            audience_scope VARCHAR(30),owner_agents_allowed SMALLINT,profile_agent_allowed SMALLINT,stonefellow_shared SMALLINT);
        CREATE TABLE user_agent_data_rules (agent_id BIGINT,resource_type VARCHAR(40),resource_id VARCHAR(100),access_mode VARCHAR(20));
        CREATE TABLE user_agents (id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(100) NOT NULL DEFAULT 'Fixture Agent',is_active INT NOT NULL);
        INSERT INTO user_agents (id,owner_user_id,is_active) VALUES (7,1,1),(8,1,1),(9,2,1);
        CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL,
            title VARCHAR(190) NOT NULL DEFAULT '',updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
        INSERT INTO chat_conversations (id,user_id,user_agent_id) VALUES (11,1,7),(12,1,7),(13,1,8),(14,1,NULL),(15,1,7),(21,2,9);
        CREATE TABLE chat_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,role VARCHAR(20),message TEXT,context_json TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    agent_connections_install_v1($pdo);
    $insert=$pdo->prepare("INSERT INTO agent_connections_v1 (id,owner_user_id,provider,external_account_id,account_email,status,
        read_enabled,send_enabled,followups_enabled,authorization_key,client_fingerprint,access_token_enc,refresh_token_enc,access_expires_at)
        VALUES (?,?,'gmail',?,?,'connected',1,1,1,?,?,?,'',?)");
    foreach([[7,1,'a','owner'],[9,2,'b','other']] as [$id,$ownerId,$letter,$name])$insert->execute([$id,$ownerId,'fixture-'.$name,
        $name.'@example.test',str_repeat($letter,64),agent_connections_client_fingerprint_v1(),ai_encrypt_secret('EXPIRED_ACCESS_NEVER_REFRESH'),time()-86400]);
    $owner=current_user();$principal=vp3_agent_chat_principal_v380($owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,7));
    $read=static fn():array=>agent_gmail_workspace_context_v1($pdo,$owner,$principal,11);
    $first=workspace_draft($pdo);workspace_draft($pdo,['conversation'=>12]);
    workspace_draft($pdo,['conversation'=>13,'agent'=>8,'subject'=>'OTHER_AGENT_SENTINEL']);
    workspace_draft($pdo,['conversation'=>14,'agent'=>0,'subject'=>'SYSTEM_AGENT_SENTINEL']);
    workspace_draft($pdo,['conversation'=>21,'owner'=>2,'agent'=>9,'subject'=>'OTHER_OWNER_SENTINEL']);
    workspace_draft($pdo,['conversation'=>15,'subject'=>'REASSIGNED_CHAT_SENTINEL']);
    $pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=15');
    $unknown=workspace_draft($pdo,['status'=>'sending','provider_id'=>'UNCERTAIN_PROVIDER_ID']);
    workspace_receipt($pdo,$unknown,'UNCERTAIN_PROVIDER_ID');
    $verified=workspace_draft($pdo,['status'=>'sent','provider_id'=>'VERIFIED_PROVIDER_ID']);
    workspace_receipt($pdo,$verified,'VERIFIED_PROVIDER_ID');
    $mismatch=workspace_draft($pdo,['status'=>'sent','provider_id'=>'MISMATCH_PROVIDER_ID']);
    workspace_receipt($pdo,$mismatch,'WRONG_PROVIDER_ID');
    $noReceipt=workspace_draft($pdo,['status'=>'sent','provider_id'=>'NO_RECEIPT_PROVIDER_ID']);
    workspace_receipt($pdo,$noReceipt,'NO_RECEIPT_PROVIDER_ID','forged_receipt');
    $orphan=workspace_draft($pdo,['subject'=>'DELETED_ORIGIN_SENTINEL']);
    $pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$orphan['user_message_id']]);
    $reply=['message_id'=>'private_source','thread_id'=>'private_thread','in_reply_to'=>'<private@example.test>','references'=>['<private@example.test>']];
    for($i=0;$i<4;$i++)workspace_draft($pdo,['conversation'=>12]);
    $organic=workspace_draft($pdo,['payload'=>['subject'=>'ORGANIC_SUBJECT','body'=>'PRIVATE_ORGANIC_BODY','reply'=>$reply,
        'composer'=>['version'=>2,'saved_at'=>time(),'created_at'=>time()-30,'origin'=>'organic_reply',
            'source'=>['reason'=>'Owner requested a delivery date.','priority'=>'high','deadline'=>'2026-12-07']]]]);
    $before=workspace_state($pdo);$snapshot=$read();
    workspace_check($snapshot['state']==='ready'&&$snapshot['read_available']&&$snapshot['send_available'],
        'current private owner and Agent resolve independent ready read/send grants');
    workspace_check($snapshot['drafts']['counts']['total']===12&&$snapshot['drafts']['counts']['pending']===9
        &&count($snapshot['drafts']['items'])===3&&$snapshot['drafts']['has_more'],
        'saved lifecycle counts span the selected Agent with bounded previews across its Chats');
    $proposal=array_column($snapshot['drafts']['items'],null,'draft_id')[$organic['id']];
    workspace_check($proposal['reason']==='Owner requested a delivery date.'&&$proposal['priority']==='high'
        &&$proposal['deadline']==='2026-12-07'&&$proposal['status']==='prepared',
        'organic draft previews retain their saved reason, priority and deadline without exposing the message body');
    workspace_check($snapshot['sent']['count']===1&&$snapshot['sent']['items'][0]['draft_id']===$verified['id']
        &&isset($snapshot['sent']['items'][0]['recorded_at'])&&!isset($snapshot['sent']['items'][0]['sent_at']),
        'only matching durable receipt plus sent status proves a local send, dated at receipt recording');
    $encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);
    foreach(['PRIVATE_BODY','PRIVATE_ORGANIC_BODY','OTHER_OWNER','OTHER_AGENT','SYSTEM_AGENT','REASSIGNED_CHAT',
        'DELETED_ORIGIN','PROVIDER_ID','EXPIRED_ACCESS','authorization_key','review_token','message_enc','"attachments":'] as $forbidden){
        workspace_check(!str_contains($encoded,$forbidden),'workspace excludes '.$forbidden);
    }
    workspace_check($snapshot['coverage']['mailbox_sent']==='on_demand_not_loaded'&&$snapshot['coverage']['sent']==='verified_vp3_sends_only'
        &&$snapshot['coverage']['message_bodies']==='not_loaded'&&$snapshot['capabilities']['view_sent']['surfaces']===['gmail_popup'],
        'live mailbox Sent and full message bodies are explicitly outside the saved-state coverage');
    workspace_check(workspace_state($pdo)===$before,'workspace reads do not write, refresh expired OAuth, approve, schedule or archive');
    workspace_reject(403,static fn()=>agent_gmail_workspace_context_v1($pdo,$owner,$principal,13),'another Agent Chat cannot authorize this projection');
    workspace_reject(403,static fn()=>agent_gmail_workspace_context_v1($pdo,$owner,array_replace($principal,['viewer_user_id'=>2]),11),
        'a different viewer cannot receive owner Gmail context');
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'participant'=>44];
    workspace_reject(403,$read,'selected participant scope cannot receive unrelated owner mailbox state');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);

    // Real composer lifecycle changes are re-read without a context cache.
    $created=agent_email_composer_create_v1($pdo,$owner,$principal,11,['request_id'=>bin2hex(random_bytes(16)),
        'to'=>'private-recipient@example.test','subject'=>'Fresh composed subject','body'=>'PRIVATE_SAVED_BODY'],'fixture-session');
    $afterCreate=$read();
    workspace_check($afterCreate['drafts']['counts']['pending']===10&&$afterCreate['drafts']['items'][0]['subject']==='Fresh composed subject',
        'a canonical composer save immediately appears in current work');
    agent_connection_email_chat_action_v1($pdo,$owner,$principal,11,['action'=>'discard','draft_id'=>$created['id'],
        'edit_revision'=>$created['edit_revision']],'fixture-session',static fn()=>throw new LogicException('Unexpected provider call'));
    workspace_check($read()['drafts']['counts']['pending']===9&&$read()['drafts']['counts']['discarded']===1,
        'a canonical discard immediately updates saved-state counts');

    $revision=$snapshot['mailbox_revision'];
    $pdo->exec('UPDATE agent_connections_v1 SET send_enabled=0 WHERE id=7');$readonly=$read();
    workspace_check($readonly['read_available']&&!$readonly['send_available']&&!$readonly['capabilities']['compose']['available']
        &&$readonly['capabilities']['view_sent']['available']&&$readonly['mailbox_revision']===$revision,
        'read-only Gmail retains Sent access without advertising compose or send');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0,send_enabled=1 WHERE id=7');$sendonly=$read();
    workspace_check(!$sendonly['read_available']&&$sendonly['send_available']&&$sendonly['capabilities']['compose']['available']
        &&!$sendonly['capabilities']['view_sent']['available']&&$sendonly['sent']['count']===null&&$sendonly['mailbox_revision']===$revision,
        'send-only Gmail can compose while unavailable reads remain unknown rather than zero');
    workspace_check(array_column($sendonly['drafts']['items'],null,'draft_id')[$organic['id']]['status']==='access_unavailable'
        &&!str_contains(json_encode($sendonly,JSON_THROW_ON_ERROR),'ORGANIC_SUBJECT'),
        'read revocation redacts source-derived draft details even when new-email send permission remains');
    $pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
    $oldGrant=str_repeat('c',64);$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([$oldGrant]);
    $changed=$read();
    workspace_check($changed['mailbox_revision']!==$revision&&$changed['sent']['count']===0
        &&!array_filter($changed['drafts']['items'],static fn(array $item):bool=>$item['content_available']),
        'a new Gmail authorization redacts prior-grant previews and excludes its old receipts');
    $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('a',64)]);
    $pdo->exec("UPDATE agent_connections_v1 SET client_fingerprint='changed-client' WHERE id=7");
    workspace_check(!$read()['read_available']&&!$read()['send_available'],'changed OAuth application fails both Gmail capabilities closed');
    $pdo->prepare('UPDATE agent_connections_v1 SET client_fingerprint=? WHERE id=7')->execute([agent_connections_client_fingerprint_v1()]);

    // Watch state comes from actual scoped storage plus verified delivery evidence.
    $watch=$pdo->prepare('INSERT INTO agent_connection_email_followups_v1 (owner_user_id,operation_key,connection_id,authorization_key,
        agent_id,conversation_id,origin_user_message_id,account_email,recipient_email,sent_message_id,thread_id,status,next_check_at,
        expires_at,reply_message_id,reply_chat_message_id) VALUES (1,?,7,?,7,11,?,?,?,\'\',\'private_thread\',?,?,?,?,?)');
    $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json) VALUES (11,NULL,'assistant','Observed.',?)")
        ->execute([json_encode(['source'=>'agent_connection_email_followup','email_followup'=>['connection_id'=>7,'agent_id'=>7]],JSON_THROW_ON_ERROR)]);
    $notice=(int)$pdo->lastInsertId();$future=gmdate('Y-m-d H:i:s',time()+3600);$past=gmdate('Y-m-d H:i:s',time()-3600);
    foreach([['waiting','waiting',$future,null,null],['expired','waiting',$past,null,null],
        ['observed','delivered',$future,'PRIVATE_REPLY_ID',$notice],['unverified','delivered',$future,'PRIVATE_UNVERIFIED_ID',$notice+999]] as [$op,$state,$expires,$provider,$message]){
        $watch->execute([$op,str_repeat('a',64),$first['user_message_id'],'owner@example.test','private-recipient@example.test',
            $state,$future,$expires,$provider,$message]);
    }
    $watches=$read()['followups'];
    workspace_check($watches['counts']===['waiting'=>1,'reply_observed'=>1,'unverified'=>1,'expired'=>1,'stopped'=>0],
        'waiting, expired, verified reply and unsupported delivery claims remain different states');
    $pdo->prepare('DELETE FROM chat_messages WHERE id=?')->execute([$notice]);
    workspace_check($read()['followups']['counts']['reply_observed']===0&&$read()['followups']['counts']['unverified']===2,
        'deleted delivery evidence cannot continue to claim an observed reply');

    agent_connection_gmail_inbox_install_v1($pdo);agent_connection_gmail_inbox_receipts_install_v1($pdo);
    agent_email_matters_install_v1($pdo);agent_email_triage_install_v1($pdo);
    $rules=['senders'=>['priority@example.test'],'domains'=>['important.example.test'],'subjects'=>['Invoice due'],
        'github_actions'=>true,'triage_enabled'=>true,'content_analysis'=>true];
    $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms,last_checked_at,next_check_at,last_status)
        VALUES (1,7,?,0,?,?,?,NOW(),?,\'checked\')')->execute([str_repeat('a',64),json_encode($rules,JSON_THROW_ON_ERROR),str_repeat('d',32),(time()-3600)*1000,$future]);
    $disabled=$read();
    workspace_check($disabled['inbox']['state']==='disabled'&&$disabled['preferences']['state']==='ready'
        &&!$disabled['preferences']['inbox_review_enabled']&&$disabled['preferences']['priority']['subjects']['sample']===['Invoice due'],
        'disabled review and current exact priority preferences are available without assuming inbox contents');
    $pdo->exec('UPDATE agent_connection_gmail_inbox_v1 SET enabled=1 WHERE owner_user_id=1');
    $policy=agent_connection_gmail_inbox_policy_v1($pdo,1);$bound=agent_email_matters_policy_scope_v1($policy);
    $finding=['subject'=>'Saved finding subject','summary'=>'A shipment date needs a reply.','next_step'=>'Review the saved draft.',
        'importance_reason'=>'A confirmed deadline is approaching.','priority'=>'high','state'=>'open','category'=>'business',
        'evidence_message_id'=>'PRIVATE_EVIDENCE_ID','material_observation'=>['source'=>'PRIVATE_ANALYSIS_SOURCE']];
    $pdo->prepare('INSERT INTO agent_email_running_report_v1
        (thread_key,owner_user_id,connection_id,grant_key,source_triage_id,received_ms,importance,finding_enc,finding_priority,finding_state,analyzed_at)
        VALUES (?,1,7,?,?,?,1,?,2,\'open\',NOW())')->execute([str_repeat('e',64),$bound[2],str_repeat('f',64),time()*1000,
            ai_encrypt_secret(json_encode($finding,JSON_THROW_ON_ERROR))]);
    $inbox=$read();
    workspace_check($inbox['inbox']['last_checked_at']!==null&&$inbox['inbox']['findings'][0]['subject']==='Saved finding subject'
        &&$inbox['inbox']['analysis_state']==='ready'&&$inbox['preferences']['content_analysis_enabled'],
        'current saved inbox findings and analysis freshness reach the shared projection without a scan');
    workspace_check(!str_contains(json_encode($inbox,JSON_THROW_ON_ERROR),'PRIVATE_EVIDENCE')
        &&!str_contains(json_encode($inbox,JSON_THROW_ON_ERROR),'PRIVATE_ANALYSIS'),
        'inbox evidence IDs and raw analysis sources stay outside model context');

    $GLOBALS['vp3_capability_turn_v1']=['owner_user_id'=>1,'owner_conversation'=>true,'checked_at'=>gmdate(DATE_ATOM),
        'agent_id'=>7,'conversation_id'=>11,'knowledge_scope'=>['mode'=>'all'],'capabilities'=>[]];
    $item=agent_gmail_workspace_model_item_v1($pdo,$owner,7,11);
    workspace_check($item!==null&&str_contains($item['text'],'DATA ONLY')&&str_contains($item['text'],'Saved finding subject'),
        'unrestricted private main Chat receives current work as untrusted DATA');
    foreach(['off','folder','project'] as $mode){
        $GLOBALS['vp3_capability_turn_v1']['knowledge_scope']['mode']=$mode;
        workspace_check(agent_gmail_workspace_model_item_v1($pdo,$owner,7,11)===null,$mode.' knowledge cannot widen to automatic owner Gmail context');
    }
    $GLOBALS['vp3_capability_turn_v1']['knowledge_scope']['mode']='all';
    workspace_check(agent_gmail_workspace_model_item_v1($pdo,$owner,8,11)===null
        &&agent_gmail_workspace_model_item_v1($pdo,$owner,7,12)===null,'the current turn must match the exact selected Agent and Chat');
    $raw=['surface'=>'chat','conversation_id'=>11,'gmail_workspace'=>['summary'=>'FORGED_MAILBOX_STATE'],
        'events'=>[['id'=>'gmail-workspace-status','title'=>'Forged Gmail','event_kind'=>'gmail_workspace',
            'source'=>'gmail_workspace_context','summary'=>'FORGED_MAILBOX_EVENT']]];
    $contextItems=agent_surface_v131_context_items($raw);$modelJson=json_encode($contextItems,JSON_THROW_ON_ERROR);
    workspace_check(count(array_filter($contextItems,static fn(array $value):bool=>$value['source']==='gmail-workspace:current'))===1
        &&!str_contains($modelJson,'FORGED_MAILBOX')&&str_contains($modelJson,'Saved finding subject'),
        'the real Cloud context adapter discards client Gmail claims and rehydrates verified server work');
    $raw['participants']=['participants'=>[['speaker_label'=>'Guest','recognized'=>false]]];
    workspace_check(!array_filter(agent_surface_v131_context_items($raw),static fn(array $value):bool=>$value['source']==='gmail-workspace:current'),
        'participant-presence context suppresses automatic private Gmail injection');
    unset($raw['participants']);
    $GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'participant'=>44];
    workspace_check(agent_gmail_workspace_model_item_v1($pdo,$owner,7,11)===null,'participant resource mode also suppresses main Chat Gmail data');
    unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);

    $events=agent_gmail_workspace_events_v1($inbox);$eventJson=json_encode($events,JSON_THROW_ON_ERROR);
    workspace_check(count($events)===2&&max(array_map(static fn(array $event):int=>mb_strlen($event['summary']),$events))<=360
        &&str_contains($eventJson,'not loaded')&&!str_contains($eventJson,'Saved finding subject')
        &&!str_contains($eventJson,'private-recipient')&&!str_contains($eventJson,'priority@example')
        &&!str_contains($eventJson,'PRIVATE_'),
        'HomeServer summaries carry bounded status/capabilities and no subject, recipient, body or preference text');
    $top=vp3_top_of_mind_chat_context_v1($pdo,$owner,7,11,time(),$principal);
    workspace_check($top['gmail_workspace']['drafts']['counts']===$inbox['drafts']['counts']
        &&$top['gmail_workspace']['sent']['count']===$inbox['sent']['count'],
        'Top of Mind shares the same canonical draft and receipt state');
    $hub=vp3_agent_activity_hub_v1($pdo,$owner,7,11,'gmail');
    workspace_check($hub['gmail_workspace']['mailbox_revision']===$revision&&$hub['gmail_workspace']['read_available'],
        'Gmail popup snapshot supplies the same mailbox revision and live read gate for revocation coherence');

    // A concurrent access change during a native preview read cannot leak the earlier result.
    foreach(['read','grant','origin','policy','account'] as $race){
        $GLOBALS['workspace_race']=static function(string $sql)use($pdo,$race):void{
            if(!str_contains($sql,'SELECT d.*,m.created_at'))return;
            unset($GLOBALS['workspace_race']);
            match($race){
                'read'=>$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE id=7'),
                'grant'=>$pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('c',64)]),
                'origin'=>$pdo->exec('UPDATE chat_conversations SET user_agent_id=8 WHERE id=12'),
                'policy'=>$pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET revision=? WHERE owner_user_id=1')->execute([str_repeat('c',32)]),
                'account'=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=1'),
            };
        };
        if($race==='policy'){
            // Change preferences after they have actually been read, before the final authority check.
            $GLOBALS['workspace_race']=static function(string $sql)use($pdo):void{
                if(!str_contains($sql,'SELECT thread_key,finding_enc'))return;
                unset($GLOBALS['workspace_race']);
                $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET revision=? WHERE owner_user_id=1')->execute([str_repeat('c',32)]);
            };
        }
        workspace_reject(in_array($race,['origin','account'],true)?403:409,$read,'late '.$race.' change fails closed before publishing context');
        unset($GLOBALS['workspace_race']);
        $pdo->exec('UPDATE users SET is_active=1 WHERE id=1; UPDATE chat_conversations SET user_agent_id=7 WHERE id=12; UPDATE agent_connections_v1 SET read_enabled=1 WHERE id=7');
        $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=? WHERE id=7')->execute([str_repeat('a',64)]);
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET revision=? WHERE owner_user_id=1')->execute([str_repeat('d',32)]);
    }
    $before=workspace_state($pdo);$read();
    workspace_check(workspace_state($pdo)===$before,'complete inbox, draft, receipt and followup projection remains read-only');
    echo 'PASS '.$checks.' Gmail workspace checks'.PHP_EOL;
}finally{
    unset($GLOBALS['workspace_race']);
    $server->exec('DROP DATABASE `'.$database.'`');
    if(is_dir($fixtureRoot)){
        $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixtureRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
        rmdir($fixtureRoot);
    }
}
