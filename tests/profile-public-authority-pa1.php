<?php
declare(strict_types=1);

/** Production services and SQL. Only inference/HomeServer transports are mocked. */
final class PA1PublicStatement extends PDOStatement
{
    protected function __construct(private PA1PublicPDO $connection){}
    public function execute(?array $params=null): bool
    {
        $ok=parent::execute($params);
        $hook=$this->connection->afterSql;
        if($hook)$hook($this->queryString,$this->connection);
        return $ok;
    }
}
final class PA1PublicPDO extends PDO
{
    public mixed $afterSql=null;public string $failSql='';
    public function prepare(string $sql,array $options=[]): PDOStatement|false
    {
        if($this->failSql!==''&&str_contains($sql,$this->failSql))throw new PDOException('SECRET injected database diagnostic');
        if($this->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql=preg_replace('/ FOR UPDATE\b/i','',$sql);
            $sql=preg_replace("/DATE_SUB\((?:UTC_TIMESTAMP|NOW)\(\),INTERVAL (\d+) (DAY|HOUR|MINUTE|SECOND)\)/i","datetime('now','-$1 $2')",$sql);
            if(str_starts_with($sql,'INSERT INTO profile_visit_sessions ')){
                $sql=str_contains($sql,'VALUES (?,NULL,?,0,0)')
                    ?"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,NULL,?,0,0) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET last_seen_at=NOW()"
                    :"INSERT INTO profile_visit_sessions(owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,?,?,?,?) ON CONFLICT(owner_user_id,session_key) DO UPDATE SET visitor_user_id=COALESCE(excluded.visitor_user_id,profile_visit_sessions.visitor_user_id),identity_disclosed=CASE WHEN excluded.visitor_user_id IS NULL THEN profile_visit_sessions.identity_disclosed ELSE excluded.identity_disclosed END,view_count=profile_visit_sessions.view_count+excluded.view_count,last_seen_at=NOW()";
            }
        }
        return parent::prepare($sql,$options);
    }
}
$mysqlDsn=getenv('VP3_TEST_MYSQL_DSN');$dsn='sqlite::memory:';$dbUser=null;$dbPass=null;
if($mysqlDsn){
    $dbUser=getenv('VP3_TEST_MYSQL_USER')?:'root';$dbPass=getenv('VP3_TEST_MYSQL_PASS')?:'root';
    $admin=new PDO($mysqlDsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_profile_public_pa1_'.bin2hex(random_bytes(5));
    $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=preg_match('/dbname=[^;]+/',$mysqlDsn)?preg_replace('/dbname=[^;]+/','dbname='.$database,$mysqlDsn):rtrim($mysqlDsn,';').';dbname='.$database;
    register_shutdown_function(static fn()=>$admin->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
}
$pdo=new PA1PublicPDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PA1PublicStatement::class,[$pdo]]);
if($mysqlDsn)$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
else{$pdo->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));$pdo->sqliteCreateFunction('UTC_TIMESTAMP',fn()=>gmdate('Y-m-d H:i:s'));}
$checks=0;$modelCalls=[];$modelHook=null;$homeHook=null;$homeAnswer=null;$caps=[];$seeded=false;
function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool{
    $p=db();$q=$p->prepare($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?':"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();
}
function column_exists(string $table,string $column): bool{return false;}
function setting(string $key,mixed $fallback=''): mixed{return $fallback;}
function url(string $path): string{return $path;}
function user_account_types_for_user_id(int $id,string $role): array{return [$role];}
function personal_capability_has_v242(string $key,array $user): bool{return $GLOBALS['caps'][$key]??false;}
function permissions_schema_ready(): bool{return $GLOBALS['seeded'];}
function personal_capability_seeded_v242(): bool{return $GLOBALS['seeded'];}
function user_roles_for_user(array $user): array{return [(string)$user['role']];}
function chat_policy_terms_v236(string $query): array{return array_values(array_filter(preg_split('/[^a-z]+/',strtolower($query)),fn($s)=>strlen($s)>3));}
function homeserver_shared_v210_exchange(int $owner,string $query): array{return ['datasets'=>['knowledge'=>[['key'=>'hours','title'=>'Home hours','content'=>'Home hours are Tuesday']]]];}
function homeserver_profile_v235_answer(int $owner,string $query,array $history,array $context): ?array{
    if($GLOBALS['homeHook'])($GLOBALS['homeHook'])();return $GLOBALS['homeAnswer'];
}
function ai_generate_chat_response(string $query,array $history,array $context,?array $user=null,?string $scope=null,?array $control=null): array{
    $GLOBALS['modelCalls'][]=compact('query','history','context','user','scope','control');
    if($GLOBALS['modelHook'])($GLOBALS['modelHook'])();return ['ok'=>true,'answer'=>'Fixture approved answer'];
}
require dirname(__DIR__).'/includes/auth.php';
require dirname(__DIR__).'/includes/notifications.php';
require dirname(__DIR__).'/includes/user-agent-system-v236.php';
require dirname(__DIR__).'/includes/shared-knowledge-index-v236.php';
require dirname(__DIR__).'/includes/profile-agent.php';
require dirname(__DIR__).'/includes/profile-agent-runtime.php';
require dirname(__DIR__).'/includes/profile-visitor-crm-v243.php';
require dirname(__DIR__).'/includes/profile-agent-boundary-v390.php';
require dirname(__DIR__).'/includes/profile-agent-public-service-v110.php';
require dirname(__DIR__).'/includes/profile-webmcp-analytics-v130.php';
require dirname(__DIR__).'/includes/agent-radar-external.php';
require dirname(__DIR__).'/includes/profile-webmcp-chat-v140.php';
// Execute the real origin validator without loading the unrelated capability graph.
$externalSource=file_get_contents(dirname(__DIR__).'/includes/profile-webmcp-external-v120.php');
$from=strpos($externalSource,'function vp3_profile_webmcp_external_origin_v120');$to=strpos($externalSource,'function vp3_profile_webmcp_external_profile_v120',$from);
eval(substr($externalSource,$from,$to-$from));
require __DIR__.'/fixtures/profile-public-authority-pa1.php';
function check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function denied(callable $work,string $label,string $code='PROFILE_AGENT_UNAVAILABLE'): void{
    try{$work();}catch(VP3ProfileAgentPublicException $e){check($e->publicCode===$code,$label.' ('.$e->publicCode.')');return;}
    throw new RuntimeException($label.' unexpectedly succeeded');
}
function loginVisitor(int $id): void{
    $_SESSION=[];if($id>0){$q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$id]);$_SESSION=['user_id'=>$id,'auth_password_fingerprint'=>auth_password_fingerprint($id,(string)$q->fetchColumn())];}
    reset_current_user_cache();
}
function resetPublic(): void{
    $p=db();$p->afterSql=null;$p->failSql='';if($p->inTransaction())$p->rollBack();
    $GLOBALS['modelCalls']=[];$GLOBALS['modelHook']=null;$GLOBALS['homeHook']=null;$GLOBALS['homeAnswer']=null;$GLOBALS['seeded']=false;
    $GLOBALS['caps']=['profile_agent.access'=>true,'profile_chat.access'=>true,'agent_brain.access'=>true];
    profile_public_authority_fixture_pa1($p);$_COOKIE=['sf_profile_visitor'=>str_repeat('a',64),'sf_profile_conversation'=>str_repeat('b',64)];loginVisitor(0);
    vp3_profile_webmcp_verified_chat_grant_v1(null,true);
}
function nativeContext(int $visitor=0): array{
    loginVisitor($visitor);return vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user());
}
function ageMessages(int $seconds=3): void{
    $p=db();$clock=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'DATE_SUB(NOW(),INTERVAL '.$seconds.' SECOND)':"datetime('now','-$seconds seconds')";
    $p->exec('UPDATE profile_agent_messages SET created_at='.$clock);
}
function countRows(string $table,string $where='1=1'): int{return(int)db()->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$where)->fetchColumn();}
function externalContext(string $session='FixtureSession_12345678',string $origin='https://shop.example.test'): array{
    $p=db();$property=$p->query('SELECT * FROM vp3_radar_properties WHERE id=44')->fetch();$profile=profile_by_username($p,'fixture-owner');
    $grant=vp3_profile_webmcp_chat_grant_create_v140($property,$profile,$origin,$session);
    vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,$origin,$session,$grant['grant']);
    return vp3_profile_webmcp_external_chat_context_v140($p,$property,$profile,$session);
}

if(defined('PA1_PUBLIC_BOOTSTRAP_ONLY')&&PA1_PUBLIC_BOOTSTRAP_ONLY)return;

resetPublic();$ctx=nativeContext(2);$result=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?');$cid=$result['conversation_id'];
check($result['answer']==='Fixture approved answer'&&count($modelCalls)===1,'actual service stores a bound member turn and its generated answer');
check(countRows('profile_agent_messages',"sender_type='system'")===1&&countRows('profile_agent_messages',"sender_type='visitor'")===1&&countRows('profile_agent_messages',"sender_type='agent'")===1,'binding metadata is one hidden record alongside actual visitor and Agent messages');
check(countRows('profile_events')===2&&countRows('notifications')===2,'binding creates no event or notification activity');
check(count(profile_agent_messages($pdo,$cid))===2,'owner transcript excludes binding record');
$state=vp3_profile_agent_public_state_service_v110($pdo,$ctx,$cid);$poll=vp3_profile_agent_public_poll_service_v110($pdo,$ctx,$cid);
check(count($state['messages'])===2&&count($poll['messages'])===2,'state and poll exclude binding records');
$json=json_encode([$state,$poll,$modelCalls]);
check(!str_contains($json,'credential_hash')&&!str_contains($json,$_COOKIE['sf_profile_conversation'])&&!str_contains($json,'PRIVATE_KNOWLEDGE_SECRET')&&!str_contains($json,'FOREIGN_KNOWLEDGE_SECRET'),'public output and model history contain neither binding material nor private/foreign knowledge');
check($modelCalls[0]['user']['id']===1&&$modelCalls[0]['scope']==='profile_agent'&&$modelCalls[0]['control']['profile_agent_id']===7,'anonymous/public inference stays in explicit Profile scope using owner allowance');
$crm=(int)$ctx['session_id'];$namespaceA=profile_conversation_browser_namespace_v1(1,2);
foreach([0,3] as $next){
    $other=nativeContext($next);check($other['session_id']===$crm,'switch to visitor '.$next.' preserves CRM contact continuity');
    foreach(['state','poll','message','handoff'] as $op){
        $before=[countRows('profile_agent_messages'),countRows('profile_events')];
        denied(fn()=>match($op){'state'=>vp3_profile_agent_public_state_service_v110($pdo,$other,$cid),'poll'=>vp3_profile_agent_public_poll_service_v110($pdo,$other,$cid),'message'=>vp3_profile_agent_public_message_service_v110($pdo,$other,'Cross-principal attempt',$cid),'handoff'=>vp3_profile_agent_public_request_owner_v110($pdo,$other,$cid)},'visitor '.$next.' cannot '.$op.' member A thread','CONVERSATION_NOT_FOUND');
        check($before===[countRows('profile_agent_messages'),countRows('profile_events')],'rejected '.$op.' adds no message or attention activity');
    }
    check(profile_conversation_browser_namespace_v1(1,$next)!==$namespaceA,'widget namespace separates visitor '.$next.' from member A');
}
$ownerThread=profile_runtime_conversation_owner($pdo,$cid,1);
check($ownerThread['visitor_label']==='Signed-in member'&&$ownerThread['identity_disclosed']===false&&!str_contains(json_encode($ownerThread),'Visitor B'),'historical owner thread keeps member A identity and does not borrow member B disclosure');
$aAgain=nativeContext(2);check(count(vp3_profile_agent_public_state_service_v110($pdo,$aAgain,$cid)['messages'])===2,'returning authenticated member A can resume its bound history');
$guest=nativeContext(0);ageMessages();$guestResult=vp3_profile_agent_public_message_service_v110($pdo,$guest,'Guest follow-up');$guestId=$guestResult['conversation_id'];
check((int)$pdo->query('SELECT visitor_user_id FROM profile_visit_sessions WHERE id='.$crm)->fetchColumn()===2,'anonymous return retains CRM member association separately');
check($pdo->query('SELECT visitor_user_id FROM profile_agent_conversations WHERE id='.$guestId)->fetchColumn()===null,'guest thread does not inherit the retained CRM member');
check(!profile_runtime_conversation_owner($pdo,$guestId,1)['signed_in'],'owner view labels the guest thread as a guest');
$guestAgain=nativeContext(0);check(count(vp3_profile_agent_public_state_service_v110($pdo,$guestAgain,$guestId)['messages'])===2,'same guest credential retains its own conversation');
$b=nativeContext(3);denied(fn()=>vp3_profile_agent_public_state_service_v110($pdo,$b,$guestId),'signed-in account cannot inherit prior guest thread','CONVERSATION_NOT_FOUND');

resetPublic();$ctx=nativeContext(0);$pdo->prepare("INSERT INTO profile_agent_conversations(owner_user_id,profile_agent_id,profile_session_id,visitor_user_id) VALUES(1,7,?,NULL)")->execute([$ctx['session_id']]);$legacy=(int)$pdo->lastInsertId();
denied(fn()=>vp3_profile_agent_public_state_service_v110($pdo,$ctx,$legacy),'legacy unbound thread fails closed publicly','CONVERSATION_NOT_FOUND');
check(vp3_profile_agent_owner_conversation_v390($pdo,$legacy,1)!==null,'owner still retains historical legacy thread access');
$bound=vp3_profile_agent_public_start_v1($pdo,$ctx);$cid=$bound['conversation']['conversation_id'];
check($cid!==$legacy&&$bound['messages']===[],'guarded start creates a fresh bound thread without exposing legacy history');
check(vp3_profile_agent_public_start_v1($pdo,$ctx)['conversation']['conversation_id']===$cid,'guarded repeated start reuses the exact authorized conversation');
foreach(['missing','malformed','duplicate','wrong_visitor','unknown_status'] as $fault){
    resetPublic();$ctx=nativeContext(0);$cid=vp3_profile_agent_public_start_v1($pdo,$ctx)['conversation']['conversation_id'];
    if($fault==='missing')$pdo->exec("DELETE FROM profile_agent_messages WHERE sender_type='system'");
    elseif($fault==='malformed')$pdo->exec("UPDATE profile_agent_messages SET context_json='{}' WHERE sender_type='system'");
    elseif($fault==='duplicate')$pdo->exec("INSERT INTO profile_agent_messages(conversation_id,sender_type,message,context_json) SELECT conversation_id,sender_type,message,context_json FROM profile_agent_messages WHERE sender_type='system'");
    elseif($fault==='wrong_visitor')$pdo->exec('UPDATE profile_agent_conversations SET visitor_user_id=2');
    else $pdo->exec("UPDATE profile_agent_conversations SET status='future_unknown'");
    denied(fn()=>vp3_profile_agent_public_poll_service_v110($pdo,$ctx,$cid),$fault.' conversation binding fails closed','CONVERSATION_NOT_FOUND');
}

$revocations=[
    'private Profile'=>fn()=>$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1'),
    'disabled Profile Agent'=>fn()=>$pdo->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1'),
    'changed selected Agent'=>fn()=>$pdo->exec('UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1'),
    'inactive Agent'=>fn()=>$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7'),
    'transferred Agent'=>fn()=>$pdo->exec('UPDATE user_agents SET owner_user_id=4 WHERE id=7'),
    'inactive owner'=>fn()=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=1'),
    'renamed Profile'=>fn()=>$pdo->exec("UPDATE user_profiles SET username='different' WHERE user_id=1"),
    'changed instructions'=>fn()=>$pdo->exec("UPDATE user_profiles SET profile_agent_instructions='New boundary' WHERE user_id=1"),
    'revoked owner capability'=>function(){$GLOBALS['caps']['profile_chat.access']=false;},
    'disabled visitor'=>fn()=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=2'),
    'visitor password changed'=>fn()=>$pdo->exec("UPDATE users SET password_hash='new-password' WHERE id=2"),
    'visitor logout'=>fn()=>loginVisitor(0),
    'visitor account switched'=>fn()=>loginVisitor(3),
    'conversation cookie replaced'=>function(){$_COOKIE['sf_profile_conversation']=str_repeat('f',64);},
    'session owner transferred'=>fn()=>$pdo->exec('UPDATE profile_visit_sessions SET owner_user_id=4'),
    'session key replaced'=>fn()=>$pdo->exec("UPDATE profile_visit_sessions SET session_key='changed'"),
];
foreach($revocations as $label=>$mutation){
    resetPublic();$ctx=nativeContext(2);$GLOBALS['modelHook']=$mutation;
    denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Tell me about the public hours'),$label.' during inference suppresses late reply');
    check(countRows('profile_agent_messages',"sender_type='agent'")===0&&countRows('profile_agent_messages',"sender_type='visitor'")===1,$label.' leaves the submitted visitor turn but saves no Agent answer');
}
foreach(['owner_joined','resolved'] as $status){
    resetPublic();$ctx=nativeContext(0);$GLOBALS['modelHook']=fn()=>$pdo->exec("UPDATE profile_agent_conversations SET status='$status'");
    $r=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Public hours please');
    check($r['answer']===''&&$r['conversation']['status']===$status&&countRows('profile_agent_messages',"sender_type='agent'")===0,'owner '.$status.' wins while inference is in progress');
}
foreach([
    'knowledge audience'=>fn()=>$pdo->exec("UPDATE user_data_policies SET audience_scope='private' WHERE resource_type='knowledge'"),
    'knowledge sharing switch'=>fn()=>$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='knowledge'"),
    'knowledge publication'=>fn()=>$pdo->exec('UPDATE knowledge_items SET is_published=0 WHERE id=101'),
    'knowledge ownership'=>fn()=>$pdo->exec('UPDATE knowledge_items SET created_by_user_id=4 WHERE id=101'),
    'knowledge deletion'=>fn()=>$pdo->exec('DELETE FROM knowledge_items WHERE id=101'),
    'Agent source deny'=>fn()=>$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','101','deny')"),
    'HomeServer sharing'=>fn()=>$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='homeserver_knowledge'"),
] as $label=>$mutation){
    resetPublic();$ctx=nativeContext(2);$GLOBALS['modelHook']=$mutation;
    denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the hours?'),$label.' revoked during inference suppresses its late answer');
    check(countRows('profile_agent_messages',"sender_type='agent'")===0,$label.' revocation stores no generated content');
}
resetPublic();$ctx=nativeContext(2);$first=vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the hours?');ageMessages();
vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Continue the conversation',$first['conversation_id']);
$lastMetadata=json_decode((string)$pdo->query("SELECT context_json FROM profile_agent_messages WHERE sender_type='agent' ORDER BY id DESC LIMIT 1")->fetchColumn(),true);
check(in_array('knowledge:101',array_column($lastMetadata['source_dependencies']??[],'source'),true),'derived answers retain prior approved source dependencies even without a new matching retrieval');
ageMessages();
$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type IN ('knowledge','homeserver_knowledge')");
vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Continue the conversation',$first['conversation_id']);
check(!str_contains(json_encode(end($modelCalls)['history']),'Fixture approved answer'),'revoked original and derived Agent answers are removed from subsequent model history');
resetPublic();$ctx=nativeContext(0);$GLOBALS['homeHook']=fn()=>$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Ask something'),'authority lost during HomeServer fallback prevents Cloud inference');
check(count($modelCalls)===0,'no second provider call after HomeServer-time revocation');

resetPublic();$ctx=nativeContext(0);vp3_profile_agent_public_message_service_v110($pdo,$ctx,'First new thread');$before=[countRows('profile_agent_conversations'),countRows('profile_agent_messages'),countRows('profile_events'),countRows('notifications')];
denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Second immediate new thread',0),'new conversation ID zero cannot reset two-second pacing','RATE_LIMITED');
check($before===[countRows('profile_agent_conversations'),countRows('profile_agent_messages'),countRows('profile_events'),countRows('notifications')],'rate rejection occurs before new thread, binding, message, or notification creation');
for($i=1;$i<8;$i++){ageMessages();vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Separate accepted thread '.$i);}
ageMessages();denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Ninth turn',0),'eight-per-minute bound covers all threads for this visitor session','RATE_LIMITED');
check(countRows('profile_agent_conversations')===8,'rejected ninth turn allocates no conversation');
resetPublic();$ctx=nativeContext(0);$pdo->failSql="VALUES (?,'system',NULL,'',?)";
try{vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Cannot bind');throw new RuntimeException('Binding failure unexpectedly succeeded');}catch(PDOException $e){}
$pdo->failSql='';check(countRows('profile_agent_conversations')===0&&countRows('profile_events')===0&&countRows('notifications')===0,'binding persistence failure atomically rolls back thread and attention');
resetPublic();$ctx=nativeContext(2);$pdo->afterSql=function(string $sql,PA1PublicPDO $p){if(str_contains($sql,"VALUES (?,'agent',NULL,?,?)")){$p->afterSql=null;$p->exec('UPDATE user_agents SET is_active=0 WHERE id=7');}};
denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Public hours'),'late authority change at result persistence is rechecked');
check(countRows('profile_agent_messages',"sender_type='agent'")===0,'late persistence revocation rolls back Agent answer');
resetPublic();$seeded=true;$ctx=nativeContext(2);$modelHook=fn()=>$pdo->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");
denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Public hours'),'real seeded role permission removal suppresses output');

resetPublic();$ctx=externalContext();$started=vp3_profile_webmcp_chat_start_v140($pdo,$ctx);$cid=$started['conversation']['conversation_id'];
check($started['messages']===[]&&countRows('profile_agent_messages',"sender_type='system'")===1,'signed connected-site start uses canonical hidden binding');
$again=externalContext();check(vp3_profile_webmcp_chat_start_v140($pdo,$again)['conversation']['conversation_id']===$cid,'renewed grant retains exact connected-site session conversation');
$other=externalContext('DifferentSession_12345678');denied(fn()=>vp3_profile_agent_public_state_service_v110($pdo,$other,$cid),'another signed site session cannot reuse the conversation','CONVERSATION_NOT_FOUND');
$otherOrigin=externalContext('FixtureSession_12345678','https://other.example.test');denied(fn()=>vp3_profile_agent_public_state_service_v110($pdo,$otherOrigin,$cid),'another signed origin cannot reuse the conversation','CONVERSATION_NOT_FOUND');
vp3_profile_webmcp_verified_chat_grant_v1(null,true);$property=$pdo->query('SELECT * FROM vp3_radar_properties WHERE id=44')->fetch();
denied(fn()=>vp3_profile_webmcp_external_chat_context_v140($pdo,$property,profile_by_username($pdo,'fixture-owner'),'FixtureSession_12345678'),'connected-site context requires successful request-local signed grant verification','CHAT_GRANT_INVALID');
foreach([
    'disabled property'=>fn()=>$pdo->exec('UPDATE vp3_radar_properties SET is_active=0 WHERE id=44'),
    'transferred property'=>fn()=>$pdo->exec('UPDATE vp3_radar_properties SET owner_user_id=4 WHERE id=44'),
    'rotated signing token'=>fn()=>$pdo->prepare('UPDATE vp3_radar_properties SET verification_token=? WHERE id=44')->execute([str_repeat('e',64)]),
    'changed origin domain'=>fn()=>$pdo->exec("UPDATE vp3_radar_properties SET domain='elsewhere.test' WHERE id=44"),
] as $label=>$mutation){
    resetPublic();$ctx=externalContext();$modelHook=$mutation;
    denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'What are the public hours?'),'connected-site '.$label.' during inference suppresses reply');
    check(countRows('profile_agent_messages',"sender_type='agent'")===0,'connected-site '.$label.' saves no stale answer');
}

resetPublic();$ctx=nativeContext(2);$p=$ctx['profile'];$a=$ctx['agent'];$v=$ctx['visitor'];
check(!vp3_profile_agent_context_authorized_v1($pdo,$p,$a,$v,[['source'=>'unknown:private']]),'unrecognized context provenance cannot be silently reused');
$pdo->exec("INSERT INTO user_data_policies VALUES(1,'knowledge','brain:301','public',1,1,0)");
check(vp3_profile_agent_context_authorized_v1($pdo,$p,$a,$v,[['source'=>'agent-brain:transcript-analysis:301']]),'active explicitly shared Brain memory passes current source policy');
$pdo->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=301');
check(!vp3_profile_agent_context_authorized_v1($pdo,$p,$a,$v,[['source'=>'agent-brain:transcript-analysis:301']]),'inactive Brain memory cannot remain an approved source');
$pdo->exec("UPDATE user_data_policies SET audience_scope='connections' WHERE resource_type='knowledge' AND resource_id='*'");
$pdo->exec("INSERT INTO user_relationships VALUES(1,2,'connection','accepted')");
check(vp3_profile_agent_context_authorized_v1($pdo,$p,$a,$v,[['source'=>'knowledge:101']]),'current accepted relationship permits the configured knowledge audience');
$pdo->exec('DELETE FROM user_relationships');
check(!vp3_profile_agent_context_authorized_v1($pdo,$p,$a,$v,[['source'=>'knowledge:101']]),'revoked relationship removes connection-scoped knowledge');
if($mysqlDsn){
    $pdo->exec("SET time_zone='+05:30'");resetPublic();$ctx=nativeContext(0);vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Non-UTC first message');
    denied(fn()=>vp3_profile_agent_public_message_service_v110($pdo,$ctx,'Non-UTC immediate message'),'MySQL reporting timezone cannot bypass real-time pacing','RATE_LIMITED');
    $pdo->exec("SET time_zone='+00:00'");
}
echo 'PROFILE_PUBLIC_AUTHORITY_PA1=PASS '.$checks.' cases ('.($mysqlDsn?'MySQL':'SQLite').", inference transports mocked)\n";
