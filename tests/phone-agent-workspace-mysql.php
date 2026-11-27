<?php
declare(strict_types=1);
ini_set('session.use_cookies','0');
ini_set('session.cache_limiter','');
$workspaceSessionDir=sys_get_temp_dir().'/vp3-phone-workspace-session-'.bin2hex(random_bytes(8));
mkdir($workspaceSessionDir,0700);session_save_path($workspaceSessionDir);
register_shutdown_function(static function()use($workspaceSessionDir):void{
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    foreach(glob($workspaceSessionDir.'/sess_*')?:[] as $sessionFile)unlink($sessionFile);
    rmdir($workspaceSessionDir);
});

/** Native authority/CAS/storage acceptance. Only the native source boundary is a deterministic fixture. */
function db(): PDO {return $GLOBALS['workspacePdo'];}
function setting(string $key,mixed $default=null): mixed {return $key==='system_agent_name'?'Main fixture Agent':$default;}
function has_permission(string $permission,?array $user=null): bool
{return !empty($user['is_active'])&&!empty($user['workspace_access'])&&in_array($permission,['account.access','chat.access'],true);}
function vp3_phone_agent_source_resolve_v1(PDO $pdo,array $user,int $agent,string $kind,string $id,int $now): ?array
{
    $q=$pdo->prepare('SELECT * FROM workspace_native_sources WHERE owner_user_id=? AND agent_id=? AND source_kind=? AND source_id=? AND removed=0');
    $q->execute([(int)$user['id'],$agent,$kind,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
    $target=['agent_id'=>$agent,'conversation_id'=>(int)$r['conversation_id']]+match($kind){
        'gmail_draft'=>['kind'=>'gmail_draft','draft_id'=>$id],
        'canvas'=>['kind'=>'canvas','canvas_id'=>$id,'revision'=>(int)$r['version']],
        'image'=>['kind'=>'image','generation_id'=>$id],
        'gmail_attention'=>['kind'=>'gmail_attention','item_ref'=>$id,'generation'=>(int)$r['version'],
            'origin'=>'gmail_mailbox','conversation_role'=>'discussion_target'],
        default=>['kind'=>'chat_message','message_id'=>(int)$id],
    };
    $target=['kind'=>$target['kind']]+$target;
    if (!empty($GLOBALS['workspaceBadTarget'])) $target['url']='https://untrusted.example.test/';
    if (isset($GLOBALS['workspaceResolveHook'])) { $hook=$GLOBALS['workspaceResolveHook'];unset($GLOBALS['workspaceResolveHook']);$hook(); }
    return ['source_kind'=>$kind,'source_id'=>$id,'source_revision'=>hash('sha256',$r['version'].':'.$r['state'].':'.$r['conversation_id']),
        'conversation_id'=>(int)$r['conversation_id'],'title'=>'Result '.$id,'summary'=>'Canonical result summary.',
        'state'=>$r['state'],'needs_action'=>(bool)$r['needs_action'],'priority'=>'normal',
        'occurred_epoch'=>(int)$r['occurred_epoch'],'updated_epoch'=>(int)$r['updated_epoch'],'target'=>$target];
}
function vp3_phone_agent_sources_discover_v1(PDO $pdo,array $user,int $agent,int $now,int $limit=40): array
{
    $q=$pdo->prepare('SELECT source_kind,source_id FROM workspace_native_sources WHERE owner_user_id=? AND agent_id=? AND removed=0 ORDER BY updated_epoch DESC LIMIT '.($limit+1));
    $q->execute([(int)$user['id'],$agent]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$items=[];
    foreach(array_slice($rows,0,$limit) as $r){$v=vp3_phone_agent_source_resolve_v1($pdo,$user,$agent,$r['source_kind'],$r['source_id'],$now);if($v)$items[]=$v;}
    return ['items'=>$items,'has_more'=>count($rows)>$limit,'coverage'=>['agent_result'=>$GLOBALS['workspaceCoverage']??'ready']];
}
function vp3_phone_agent_source_detail_v1(PDO $pdo,array $user,int $agent,string $kind,string $id,int $now,string $expectedRevision): array
{
    if(isset($GLOBALS['workspaceDetailHook'])){$hook=$GLOBALS['workspaceDetailHook'];unset($GLOBALS['workspaceDetailHook']);$hook();}
    return ['kind'=>'agent_result','text'=>'Exact native result body '.$id];
}
require_once dirname(__DIR__).'/includes/phone-agent-workspace-v1.php';
require_once dirname(__DIR__).'/includes/phone-agent-workspace-native-v1.php';

function workspace_assert(bool $ok,string $label): void
{if(!$ok)throw new RuntimeException($label);$GLOBALS['workspaceChecks']=($GLOBALS['workspaceChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;}
function workspace_reject(int|array $code,callable $work,string $label): void
{try{$work();}catch(Throwable $error){if(!in_array($error->getCode(),is_array($code)?$code:[$code],true))throw new RuntimeException($label.' received '.$error->getCode().': '.$error->getMessage(),0,$error);workspace_assert(true,$label.' ('.$error->getCode().')');return;}throw new RuntimeException($label.' did not reject');}

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Native MySQL DSN required.');
$pdo=$GLOBALS['workspacePdo']=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$database='vp3_phone_workspace_'.bin2hex(random_bytes(6));$pdo->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.$database);
register_shutdown_function(static function()use($pdo,$database):void{if($pdo->inTransaction())$pdo->rollBack();$pdo->exec('DROP DATABASE '.$database);});
$pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active INT,workspace_access INT,password_hash VARCHAR(190));
    INSERT INTO users VALUES(1,1,1,'owner-password'),(2,1,1,'other-password'),(3,0,1,'inactive-password');
    CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY,owner_user_id INT UNSIGNED,is_active INT,display_name VARCHAR(190));
    INSERT INTO user_agents VALUES(7,1,1,'Longfellow'),(8,1,1,'Second'),(9,2,1,'Foreign secret Agent'),(10,1,0,'Disabled');
    CREATE TABLE chat_conversations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT UNSIGNED,user_agent_id BIGINT UNSIGNED NULL,artist_workspace_id BIGINT NULL,title VARCHAR(190));
    INSERT INTO chat_conversations VALUES(101,1,NULL,NULL,'Main'),(102,1,7,NULL,'Selected'),(103,1,8,NULL,'Other'),(104,1,NULL,NULL,'Another Main'),(201,2,NULL,NULL,'Foreign');
    CREATE TABLE vp3_phone_devices_v1(id CHAR(32) PRIMARY KEY,owner_user_id INT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,
        device_name VARCHAR(48),device_role VARCHAR(12),device_status VARCHAR(12),expires_at BIGINT,generation BIGINT,UNIQUE(owner_user_id,id),FOREIGN KEY(owner_user_id) REFERENCES users(id));
    CREATE TABLE workspace_native_sources(source_id VARCHAR(190) PRIMARY KEY,owner_user_id INT UNSIGNED,agent_id BIGINT UNSIGNED,conversation_id BIGINT UNSIGNED,
        source_kind VARCHAR(24),version INT,state VARCHAR(20),needs_action INT,occurred_epoch BIGINT,updated_epoch BIGINT,removed INT DEFAULT 0,body TEXT);");
$now=time();$a=bin2hex(random_bytes(16));$b=bin2hex(random_bytes(16));$c=bin2hex(random_bytes(16));
$tokenA=vp3_phone_token_v1(random_bytes(32));$tokenB=vp3_phone_token_v1(random_bytes(32));$tokenC=vp3_phone_token_v1(random_bytes(32));
$phone=$pdo->prepare("INSERT INTO vp3_phone_devices_v1 VALUES(?,?,?,'Fixture phone',?,'approved',?,1)");
$phone->execute([$a,1,vp3_extension_hash_v2000($tokenA),'owner',$now+3600]);
$phone->execute([$b,1,vp3_extension_hash_v2000($tokenB),'guest',$now+3600]);
$phone->execute([$c,2,vp3_extension_hash_v2000($tokenC),'owner',$now+3600]);
$seed=$pdo->prepare("INSERT INTO workspace_native_sources(source_id,owner_user_id,agent_id,conversation_id,source_kind,version,state,needs_action,occurred_epoch,updated_epoch,body) VALUES(?,?,?,?,'agent_result',1,'ready',?,?,?,'SECRET native body')");
for($i=1;$i<=25;$i++)$seed->execute([(string)$i,1,0,$i%2?101:104,$i%2,$now-100+$i,$now-100+$i]);
$seed->execute(['70',1,7,102,1,$now-2,$now-2]);$seed->execute(['80',1,8,103,1,$now-1,$now-1]);$seed->execute(['90',2,0,201,1,$now-1,$now-1]);
$user=['id'=>1];$other=['id'=>2];
$login=static function(int $owner=1,string $password='owner-password'):void{
    $_SESSION=['user_id'=>$owner,'auth_password_fingerprint'=>hash('sha256',$owner.':'.$password),'csrf_token'=>str_repeat('c',64),
        'phone_agent_workspace_key_v1'=>str_repeat('a',64)];
};
$request=static fn(array $input,?array $u=null,?string $token=null):array=>vp3_phone_agent_workspace_request_v1($pdo,$u??$user,$token??$tokenA,['device_id'=>$a]+$input,$now);
workspace_assert(!vp3_phone_agent_workspace_ready_v1($pdo),'uninstalled workspace fails readiness without creating tables');
vp3_phone_agent_workspace_install_v1($pdo);vp3_phone_agent_workspace_install_v1($pdo);
workspace_assert(vp3_phone_agent_workspace_ready_v1($pdo),'idempotent native schema installation');
$pdo->beginTransaction();workspace_reject(0,fn()=>vp3_phone_agent_workspace_install_v1($pdo),'schema refuses caller transaction');$pdo->rollBack();
$_SESSION=[];$anonymous=$request(['action'=>'status']);
workspace_assert($anonymous['state']==='approval_required'&&!isset($anonymous['csrf_token'])&&$anonymous['agents']===[]&&$anonymous['agent']===null,'owner role label alone reveals no private enrollment or Agent names');
workspace_reject(401,fn()=>$request(['action'=>'enroll','confirmed'=>true,'expected_revision'=>0,'csrf_token'=>str_repeat('c',64)]),'enrollment requires live owner session');
$login(2,'other-password');$wrong=$request(['action'=>'status'],$other);
workspace_assert($wrong['state']==='approval_required'&&!isset($wrong['csrf_token']),'another authenticated account cannot discover paired owner workspace');
$login();$status=$request(['action'=>'status']);
workspace_assert($status['state']==='not_enrolled'&&$status['owner_eligible']&&$status['revision']===0&&isset($status['csrf_token']),'approved exact owner may explicitly enroll');
workspace_assert(array_column($status['agents'],'id')===[0,7,8]&&$status['agent']['id']===0,'Main is default and Agent choices exclude foreign and inactive Agents');
workspace_reject(419,fn()=>$request(['action'=>'enroll','confirmed'=>true,'expected_revision'=>0]),'CSRF required despite valid holder credential');
workspace_reject(422,fn()=>$request(['action'=>'enroll','confirmed'=>false,'expected_revision'=>0,'csrf_token'=>$status['csrf_token']]),'explicit enrollment consent required');
workspace_reject(409,fn()=>$request(['action'=>'enroll','confirmed'=>true,'expected_revision'=>1,'csrf_token'=>$status['csrf_token']]),'stale enrollment revision rejected');
workspace_reject(403,fn()=>$request(['action'=>'feed','csrf_token'=>$status['csrf_token']]),'private feed inaccessible before enrollment');
$status=$request(['action'=>'enroll','confirmed'=>true,'expected_revision'=>0,'csrf_token'=>$status['csrf_token']]);
workspace_assert($status['state']==='ready'&&$status['agent']['id']===0&&$status['conversation_id']===104&&!$status['preferences']['notifications_enabled'],'explicit enrollment binds current credential and current private Main Chat');
$bound=static fn(array $s):array=>['csrf_token'=>$s['csrf_token'],'enrollment_revision'=>$s['enrollment_revision'],'scope_revision'=>$s['scope_revision']];
$base=$bound($status);$first=$request($base+['action'=>'feed','limit'=>10]);
workspace_assert(count($first['items'])===10&&$first['has_more']&&is_string($first['next_cursor']),'bounded first page returns an authenticated continuation');
workspace_assert($first['items'][0]['target']['message_id']===25&&$first['items'][9]['target']['message_id']===16,'newest canonical result orders first across private Chats');
workspace_assert($first['counts']===['unread'=>25,'needs_action'=>13,'new_canvases'=>0,'complete'=>true],'unread and pending owner action are separate verified counts');
$second=$request($base+['action'=>'feed','limit'=>10,'cursor'=>$first['next_cursor']]);
$third=$request($base+['action'=>'feed','limit'=>10,'cursor'=>$second['next_cursor']]);
workspace_assert(count($second['items'])===10&&count($third['items'])===5&&!$third['has_more']&&$third['next_cursor']===null,'pagination reaches the twenty-first item without repetition');
$refs=array_column([...$first['items'],...$second['items'],...$third['items']],'item_ref');
workspace_assert(count(array_unique($refs))===25&&(int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_agent_items_v1')->fetchColumn()===25,'repeated discovery keeps one canonical persistent reference');
$cols=$pdo->query('SHOW COLUMNS FROM vp3_phone_agent_items_v1')->fetchAll(PDO::FETCH_COLUMN);
workspace_assert(!array_intersect(['body','title','summary','message','target','credential_hash'],$cols),'canonical item index never duplicates messages, email bodies or credentials');
workspace_reject(409,fn()=>$request($base+['action'=>'feed','limit'=>10,'cursor'=>$first['next_cursor'].'a']),'tampered cursor rejected');
workspace_reject(409,fn()=>$request($base+['action'=>'feed','limit'=>20,'cursor'=>$first['next_cursor']]),'cursor binds page size');
workspace_reject(409,fn()=>$request($base+['action'=>'feed','filter'=>'needs_you','limit'=>10,'cursor'=>$first['next_cursor']]),'cursor binds filter');
$savedKey=$_SESSION['phone_agent_workspace_key_v1'];$_SESSION['phone_agent_workspace_key_v1']=str_repeat('b',64);
workspace_reject(409,fn()=>$request($base+['action'=>'feed','limit'=>10,'cursor'=>$first['next_cursor']]),'cursor and response scope reject another owner session');$_SESSION['phone_agent_workspace_key_v1']=$savedKey;
$item=$first['items'][0];$ref=$item['item_ref'];
$detail=$request($base+['action'=>'detail','item_ref'=>$ref]);
workspace_assert($detail['detail']['text']==='Exact native result body 25'&&$detail['item']['receipt']['read_at']===null,'native detail reads exact source without marking it read');
$mutate=static function(string $op,array $item,array $extra=[])use($request,&$base):array{return $request($base+['action'=>'mutate','item_ref'=>$item['item_ref'],'expected_revision'=>$item['revision'],'operation'=>$op]+$extra)['item'];};
$seen=$mutate('seen',$item);
workspace_assert($seen['receipt']['seen_at']!==null&&$seen['receipt']['read_at']===null&&$seen['receipt']['acknowledged_at']===null&&$seen['revision']===$item['revision']+1,'seen is a distinct CAS receipt, never read or action approval');
$seenAgain=$mutate('seen',$seen);workspace_assert($seenAgain['revision']===$seen['revision'],'repeating the current seen receipt is idempotent');
workspace_reject(409,fn()=>$mutate('read',$item),'stale parallel receipt mutation cannot overwrite current state');
$read=$mutate('read',$seen);workspace_assert($read['receipt']['read_at']!==null&&$read['receipt']['acknowledged_at']===null&&$read['needs_action'],'reading does not satisfy the native pending action');
$ack=$mutate('ack',$read);workspace_assert($ack['receipt']['acknowledged_at']!==null&&$ack['needs_action'],'acknowledgment does not execute or resolve the native source');
$fresh=$request($base+['action'=>'feed','filter'=>'needs_you','limit'=>20]);
workspace_assert(!in_array($ref,array_column($fresh['items'],'item_ref'),true)&&$fresh['counts']['unread']===24&&$fresh['counts']['needs_action']===12,'ack moves receipt into history without conflating unread or native action');
$pending=$fresh['items'][0];$snoozed=$mutate('snooze',$pending,['snooze_until'=>gmdate('Y-m-d\TH:i:s\Z',$now+3600)]);
workspace_assert($snoozed['receipt']['snoozed_until']!==null&&$snoozed['receipt']['read_at']===null&&$snoozed['receipt']['acknowledged_at']===null,'snooze suppresses attention without claiming read or acknowledgment');
$unsnoozed=$mutate('unsnooze',$snoozed);workspace_assert($unsnoozed['receipt']['snoozed_until']===null,'unsnooze restores attention eligibility');
workspace_reject(422,fn()=>$mutate('snooze',$unsnoozed,['snooze_until'=>gmdate('Y-m-d\TH:i:s\Z',$now-1)]),'past snooze rejected');
workspace_reject(422,fn()=>$mutate('snooze',$unsnoozed,['snooze_until'=>gmdate('Y-m-d\TH:i:s\Z',$now+31*86400)]),'unbounded snooze rejected');
$pdo->exec("UPDATE workspace_native_sources SET version=2 WHERE source_id='25'");
workspace_reject(409,fn()=>$mutate('read',$ack),'changed native source rejects a previously reviewed item revision');
$changed=$request($base+['action'=>'detail','item_ref'=>$ref])['item'];
workspace_assert($changed['item_ref']===$ref&&$changed['revision']>$ack['revision']&&$changed['receipt']===['seen_at'=>null,'read_at'=>null,'acknowledged_at'=>null,'snoozed_until'=>null],'semantic source revision keeps ref and resets receipts for the new content');
$GLOBALS['workspaceBadTarget']=true;workspace_reject(503,fn()=>$request($base+['action'=>'detail','item_ref'=>$ref]),'native target cannot smuggle arbitrary URLs');unset($GLOBALS['workspaceBadTarget']);
$GLOBALS['workspaceDetailHook']=static fn()=>$pdo->exec("UPDATE workspace_native_sources SET version=3 WHERE source_id='25'");
workspace_reject(409,fn()=>$request($base+['action'=>'detail','item_ref'=>$ref]),'source change during detail projection rejects stale body');
$GLOBALS['workspaceResolveHook']=static fn()=>$pdo->exec("UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE device_id='$a'");
workspace_reject(403,fn()=>$request($base+['action'=>'detail','item_ref'=>$ref]),'enrollment revocation during item resolution rejects private response');
workspace_assert((int)$pdo->query("SELECT enabled FROM vp3_phone_agent_enrollments_v1 WHERE device_id='$a'")->fetchColumn()===1,'failed mutation rolls back its isolated transaction');
$discuss=$request($base+['action'=>'discuss','item_ref'=>$ref]);workspace_assert($discuss['target']===['kind'=>'chat_message','agent_id'=>0,'conversation_id'=>101,'message_id'=>25],'Discuss returns native private Chat target without creating a user turn');
$hidden=$third['items'][0];$hiddenId=$hidden['target']['message_id'];
$pdo->prepare('UPDATE workspace_native_sources SET removed=1 WHERE source_id=?')->execute([(string)$hiddenId]);
workspace_reject(404,fn()=>$request($base+['action'=>'detail','item_ref'=>$hidden['item_ref']]),'deleted native source cannot be opened through its persistent reference');
$hiddenFeed=$request($base+['action'=>'feed','limit'=>20]);workspace_assert($hiddenFeed['counts']['unread']===24&&!in_array($hidden['item_ref'],array_column($hiddenFeed['items'],'item_ref'),true),'deleted source is omitted from live projection and counts');
$pdo->prepare('UPDATE workspace_native_sources SET removed=0 WHERE source_id=?')->execute([(string)$hiddenId]);
$GLOBALS['workspaceCoverage']='unavailable';$limited=$request($base+['action'=>'feed']);workspace_assert(!$limited['counts']['complete'],'failed source coverage cannot advertise complete zero or exact counts');unset($GLOBALS['workspaceCoverage']);
$saved=$status;$savedBase=$base;
$status=$request($base+['action'=>'select_agent','agent_id'=>7,'expected_revision'=>$status['revision']]);$base=$bound($status);
workspace_assert($status['agent']['id']===7&&$status['conversation_id']===102&&$status['scope_revision']!==$saved['scope_revision'],'Agent selection updates private Chat scope and fences earlier pages');
workspace_reject(409,fn()=>$request($savedBase+['action'=>'feed']),'old Agent response scope rejected');
workspace_reject(404,fn()=>$request($base+['action'=>'detail','item_ref'=>$ref]),'canonical item from another Agent cannot open');
$selected=$request($base+['action'=>'feed']);workspace_assert(count($selected['items'])===1&&$selected['items'][0]['target']['message_id']===70,'feed contains only selected Agent native sources');
workspace_reject(403,fn()=>$request($base+['action'=>'select_agent','agent_id'=>9,'expected_revision'=>$status['revision']]),'foreign Agent selection denied');
workspace_reject(403,fn()=>$request($base+['action'=>'select_agent','agent_id'=>10,'expected_revision'=>$status['revision']]),'inactive Agent selection denied');
$status=$request($base+['action'=>'select_agent','agent_id'=>0,'expected_revision'=>$status['revision']]);$base=$bound($status);
$device=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$a])->fetch(PDO::FETCH_ASSOC);
$row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE item_ref=?',[$ref])->fetch(PDO::FETCH_ASSOC);
$push=['type'=>'agent_item','id'=>$ref,'revision'=>(int)$row['revision'],'expires_epoch'=>$now+600];
workspace_assert(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'background notification opt-in defaults off');
$status=$request($base+['action'=>'preferences','notifications_enabled'=>true,'expected_revision'=>$status['revision']]);$base=$bound($status);
$session=$_SESSION;$_SESSION=[];
workspace_assert(vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now)&&$_SESSION===[],'restricted background predicate needs no synthetic owner session');$_SESSION=$session;
$current=$request($base+['action'=>'detail','item_ref'=>$ref])['item'];$seen=$mutate('seen',$current);
workspace_assert(vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'seen receipt alone does not claim a private result was read');
$read=$mutate('read',$seen);workspace_assert(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'read receipt suppresses pending generic notification');
$pdo->exec("UPDATE workspace_native_sources SET version=3,state='waiting' WHERE source_id='25'");$request($base+['action'=>'detail','item_ref'=>$ref]);
$row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_agent_items_v1 WHERE item_ref=?',[$ref])->fetch(PDO::FETCH_ASSOC);$push['revision']=(int)$row['revision'];
workspace_assert(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'waiting native source does not trigger a ready-item notification');
$pdo->exec("UPDATE workspace_native_sources SET version=4,state='ready' WHERE source_id='25'");$request($base+['action'=>'detail','item_ref'=>$ref]);
workspace_assert(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'outbox native source revision must still be exact');
$before=$status;$status=$request($base+['action'=>'revoke','expected_revision'=>$status['revision']]);
workspace_assert($status['state']==='not_enrolled'&&$status['revision']===$before['revision']+1,'owner withdrawal advances enrollment revision and removes private access');
workspace_reject(403,fn()=>$request($base+['action'=>'feed']),'withdrawn enrollment cannot serve cached private requests');
$status=$request(['action'=>'enroll','confirmed'=>true,'expected_revision'=>$status['revision'],'csrf_token'=>$status['csrf_token']]);$base=$bound($status);
workspace_assert($request($base+['action'=>'detail','item_ref'=>$ref])['item']['receipt']['seen_at']===null,'new explicit enrollment does not inherit prior credential-grant receipts');
$bStatus=vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenB,['action'=>'status','device_id'=>$b],$now);
$bStatus=vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenB,['action'=>'enroll','device_id'=>$b,'confirmed'=>true,'expected_revision'=>0,'csrf_token'=>$bStatus['csrf_token']],$now);
workspace_assert($bStatus['state']==='ready','guest role device can be explicitly enrolled only by actual authenticated owner');
workspace_reject(409,fn()=>vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenB,['action'=>'feed','device_id'=>$b,'cursor'=>$first['next_cursor'],'limit'=>10]+$bound($bStatus),$now),'cursor cannot replay across devices for the same owner');
$pdo->exec("UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id='$a'");
workspace_reject(403,fn()=>$request($base+['action'=>'feed']),'device revocation immediately blocks enrolled workspace');
$pdo->exec("UPDATE vp3_phone_devices_v1 SET device_status='approved',expires_at=".($now-1)." WHERE id='$a'");
workspace_reject(403,fn()=>$request($base+['action'=>'feed']),'expired device credential blocks enrolled workspace');
$pdo->exec("UPDATE vp3_phone_devices_v1 SET expires_at=".($now+3600)." WHERE id='$a'");
$newToken=vp3_phone_token_v1(random_bytes(32));$pdo->prepare('UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE id=?')->execute([vp3_extension_hash_v2000($newToken),$a]);
workspace_reject(403,fn()=>$request($base+['action'=>'feed']),'previous installation credential cannot read after rotation');
workspace_reject(403,fn()=>$request($base+['action'=>'feed'],null,$newToken),'new installation credential does not silently inherit owner enrollment');
$tokenA=$newToken;$rotated=vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenA,['action'=>'status','device_id'=>$a],$now);
workspace_assert($rotated['state']==='not_enrolled','rotation requires new explicit enrollment');
$status=vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenA,['action'=>'enroll','device_id'=>$a,'confirmed'=>true,'expected_revision'=>$rotated['revision'],'csrf_token'=>$rotated['csrf_token']],$now);$base=$bound($status);
$pdo->exec("UPDATE users SET password_hash='changed-password' WHERE id=1");
workspace_reject(401,fn()=>vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenA,['action'=>'feed','device_id'=>$a]+$base,$now),'live password change invalidates owner browser authority');
$login(1,'changed-password');
workspace_reject(403,fn()=>vp3_phone_agent_workspace_request_v1($pdo,$user,$tokenA,['action'=>'feed','device_id'=>$a]+$base,$now),'new login cannot reuse enrollment bound to previous password authority');
$device=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$a])->fetch(PDO::FETCH_ASSOC);
workspace_assert(!vp3_phone_agent_workspace_push_current_v1($pdo,$device,$push,$now),'password change also revokes saved background notification authority');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
workspace_reject(403,fn()=>vp3_phone_agent_workspace_owner_v1($pdo,$user),'disabled account cannot authorize private workspace');$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE users SET workspace_access=0 WHERE id=1');workspace_reject(403,fn()=>vp3_phone_agent_workspace_owner_v1($pdo,$user),'current owner permission revocation is checked in database');$pdo->exec('UPDATE users SET workspace_access=1 WHERE id=1');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>0,'participant'=>2];
workspace_reject(403,fn()=>vp3_phone_agent_workspace_owner_v1($pdo,$user),'participant mode never grants owner workspace');unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$login(2,'other-password');$pdo->exec('DELETE FROM chat_conversations WHERE id=201');
$chatCount=(int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn();
$otherStatus=vp3_phone_agent_workspace_request_v1($pdo,$other,$tokenC,['action'=>'status','device_id'=>$c],$now);
workspace_assert((int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn()===$chatCount,'status does not create a private Chat');
$otherStatus=vp3_phone_agent_workspace_request_v1($pdo,$other,$tokenC,['action'=>'enroll','device_id'=>$c,'confirmed'=>true,'expected_revision'=>0,'csrf_token'=>$otherStatus['csrf_token']],$now);
workspace_assert($otherStatus['state']==='ready'&&$otherStatus['conversation_id']>201&&(int)$pdo->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn()===$chatCount+1,'only explicit owner enrollment creates a missing canonical private Main Chat');
workspace_reject(404,fn()=>vp3_phone_agent_workspace_request_v1($pdo,$other,$tokenC,['action'=>'detail','device_id'=>$c,'item_ref'=>$ref]+$bound($otherStatus),$now),'another authenticated enrolled owner cannot replay a canonical item reference');

// Actual enrolled native host contexts; no native provider/dispatch action is executed by these delegation checks.
$nativeDraft=str_repeat('d',32);$nativeCanvas='10000000-0000-4000-8000-000000000002';$nativeAttention=str_repeat('e',64);
$nativeImage='20000000-0000-4000-8000-000000000002';
$nativeSeed=$pdo->prepare("INSERT INTO workspace_native_sources(source_id,owner_user_id,agent_id,conversation_id,source_kind,version,state,needs_action,occurred_epoch,updated_epoch,body) VALUES(?,2,0,?,?,1,?,1,?,?,'Native fixture only')");
foreach([[$nativeDraft,'gmail_draft','needs_review'],[$nativeCanvas,'canvas','ready'],[$nativeAttention,'gmail_attention','needs_review'],[$nativeImage,'image','ready']] as [$nativeId,$nativeKind,$nativeState])
    $nativeSeed->execute([$nativeId,$otherStatus['conversation_id'],$nativeKind,$nativeState,$now-1,$now-1]);
$stored=vp3_phone_agent_workspace_ingest_v1($pdo,2,0,[['source_kind'=>'gmail_draft','source_id'=>$nativeDraft],['source_kind'=>'canvas','source_id'=>$nativeCanvas],
    ['source_kind'=>'gmail_attention','source_id'=>$nativeAttention],['source_kind'=>'image','source_id'=>$nativeImage]],$now);
$nativeRefs=[];foreach($stored as $storedRef){$nativeRow=vp3_phone_sql_v1($pdo,'SELECT source_kind FROM vp3_phone_agent_items_v1 WHERE item_ref=?',[$storedRef['item_ref']])->fetch(PDO::FETCH_ASSOC);$nativeRefs[$nativeRow['source_kind']]=$storedRef['item_ref'];}
$_COOKIE['vp3_phone_device_v1']=$tokenC;
$nativeQuery=['device_id'=>$c,'scope_revision'=>$otherStatus['scope_revision'],'item_ref'=>$nativeRefs['gmail_draft']];
$emailContext=vp3_phone_agent_native_context_v1($pdo,$other,$nativeQuery,$now);
$emailInput=['action'=>'review','owner_key'=>'2','csrf_token'=>$_SESSION['csrf_token'],'agent_id'=>0,
    'conversation_id'=>$otherStatus['conversation_id'],'draft_id'=>$nativeDraft];
vp3_phone_agent_native_action_v1($pdo,$emailContext,'email',$emailInput);
workspace_assert(true,'native email bridge admits only the exact enrolled draft and private Chat');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'email',array_replace($emailInput,['draft_id'=>str_repeat('f',32)])),'native email bridge rejects another owner draft ID');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'email',array_replace($emailInput,['agent_id'=>7])),'native email bridge rejects Agent substitution');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'email',array_replace($emailInput,['conversation_id'=>101])),'native email bridge rejects Chat substitution');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'email',array_replace($emailInput,['action'=>'compose_create'])),'native email bridge cannot create an unrelated email');
$_COOKIE['vp3_phone_device_v1']=$tokenB;
workspace_reject(403,fn()=>vp3_phone_agent_native_context_v1($pdo,$other,$nativeQuery,$now),'native host cannot substitute a different owner device credential');$_COOKIE['vp3_phone_device_v1']=$tokenC;
workspace_reject(409,fn()=>vp3_phone_agent_native_context_v1($pdo,$other,array_replace($nativeQuery,['scope_revision'=>str_repeat('0',64)]),$now),'native host rejects stale enrollment scope');
$nativeScope=vp3_phone_agent_workspace_scope_v1($pdo,$other,$tokenC,$c,false,$now);
$canvasResolved=vp3_phone_agent_workspace_item_v1($pdo,$nativeScope,$nativeRefs['canvas']);
$canvasQuery=array_replace($nativeQuery,['item_ref'=>$nativeRefs['canvas'],'source_revision'=>$canvasResolved['item']['source_revision']]);
$canvasContext=vp3_phone_agent_native_context_v1($pdo,$other,$canvasQuery,$now);
$canvasInput=['action'=>'open','agent_id'=>0,'conversation_id'=>$otherStatus['conversation_id'],'canvas_id'=>$nativeCanvas,'revision'=>1];
vp3_phone_agent_native_action_v1($pdo,$canvasContext,'canvas',$canvasInput);
workspace_assert(true,'native Canvas bridge admits exact saved private artifact revision');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$canvasContext,'canvas',array_replace($canvasInput,['revision'=>2])),'native Canvas cannot silently switch artifact revisions');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$canvasContext,'canvas',array_replace($canvasInput,['action'=>'publish'])),'native Canvas viewer cannot publish a private artifact');
workspace_reject(409,fn()=>vp3_phone_agent_native_context_v1($pdo,$other,array_replace($canvasQuery,['source_revision'=>str_repeat('0',64)]),$now),'immutable native view binds the host source revision');
$pdo->prepare('UPDATE workspace_native_sources SET version=2 WHERE source_id=?')->execute([$nativeCanvas]);
workspace_reject(409,fn()=>vp3_phone_agent_native_response_current_v1($pdo,$canvasContext,'canvas'),'final native response suppresses stale Canvas output');
$attentionResolved=vp3_phone_agent_workspace_item_v1($pdo,$nativeScope,$nativeRefs['gmail_attention']);
$attentionContext=vp3_phone_agent_native_context_v1($pdo,$other,array_replace($nativeQuery,['item_ref'=>$nativeRefs['gmail_attention'],
    'source_revision'=>$attentionResolved['item']['source_revision']]),$now);
$attentionInput=['action'=>'attention_open','owner_key'=>'2','csrf_token'=>$_SESSION['csrf_token'],'agent_id'=>0,
    'conversation_id'=>$otherStatus['conversation_id'],'item_ref'=>$nativeAttention,'generation'=>1];
vp3_phone_agent_native_action_v1($pdo,$attentionContext,'email',$attentionInput);
workspace_assert($attentionContext['resolved']['source']['target']['conversation_role']==='discussion_target','Gmail attention carries mailbox provenance and explicit discussion Chat');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$attentionContext,'email',array_replace($attentionInput,['generation'=>2])),'attention read requires exact current review generation');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$attentionContext,'email',array_replace($attentionInput,['item_ref'=>str_repeat('a',64)])),'attention bridge cannot select another mailbox item');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$attentionContext,'email',array_replace($attentionInput,['action'=>'confirm'])),'important-email display never authorizes a send');
workspace_reject(419,fn()=>vp3_phone_agent_native_attention_v1($pdo,$attentionContext,array_replace($attentionInput,['csrf_token'=>'wrong'])),'direct attention helper checks real owner CSRF before any provider call');
workspace_reject(403,fn()=>vp3_phone_agent_native_attention_v1($pdo,$attentionContext,array_replace($attentionInput,['owner_key'=>'1'])),'direct attention helper rejects owner substitution before any provider call');
$imageResolved=vp3_phone_agent_workspace_item_v1($pdo,$nativeScope,$nativeRefs['image']);$imageContext=['scope'=>$nativeScope,'resolved'=>$imageResolved];
$imageInput=['action'=>'get','owner_key'=>'2','csrf_token'=>$_SESSION['csrf_token'],'generation_id'=>$nativeImage];
vp3_phone_agent_native_action_v1($pdo,$imageContext,'image',$imageInput);workspace_assert(true,'native saved image permits only its exact generation read');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$imageContext,'image',array_replace($imageInput,['action'=>'tick'])),'saved Chat image view cannot execute image work');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$imageContext,'image',array_replace($imageInput,['generation_id'=>$nativeCanvas])),'saved image view cannot read another generation');
workspace_reject(419,fn()=>vp3_phone_agent_native_image_v1($pdo,$imageContext,array_replace($imageInput,['csrf_token'=>'wrong'])),'direct image reader checks CSRF before accessing native image data');
$preview=['action'=>'preview','owner_key'=>'2','csrf_token'=>$_SESSION['csrf_token'],'prompt'=>'Fixture image'];
vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',$preview);workspace_assert(true,'email attachment image preview follows an explicit native review path');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',['action'=>'status']),'email composer cannot read general image generation history');
$requestKey=bin2hex(random_bytes(16));$submit=$preview+['request_key'=>$requestKey];$submit['action']='submit';
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',$submit),'image submission needs a review issued for this exact composer');
vp3_phone_agent_native_image_response_v1($emailContext,$preview,['ok'=>true,'request_key'=>$requestKey],$now);
vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',$submit);workspace_assert(true,'canonical image preview binds request identity to owner item and device scope');
$otherEmailContext=$emailContext;$otherEmailContext['resolved']['item']['item_ref']=str_repeat('a',32);
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$otherEmailContext,'image',$submit),'image review cannot be replayed into another draft composer');
$otherDeviceContext=$emailContext;$otherDeviceContext['scope']['scope_revision']=str_repeat('a',64);
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$otherDeviceContext,'image',$submit),'image review cannot be replayed into another enrollment scope');
workspace_reject(403,fn()=>vp3_phone_agent_native_image_binding_v1($emailContext,$submit,$now+601),'image preview binding expires independently of native review token');
$generationHash=hash('sha256','image:media-library:2:'.$requestKey);
$generationId=substr($generationHash,0,8).'-'.substr($generationHash,8,4).'-'.substr($generationHash,12,4).'-'.substr($generationHash,16,4).'-'.substr($generationHash,20,12);
workspace_reject(409,fn()=>vp3_phone_agent_native_image_response_v1($emailContext,$submit,['ok'=>true,'generation'=>['id'=>$nativeImage]],$now),'image response must match canonical request-derived generation identity');
vp3_phone_agent_native_image_response_v1($emailContext,$submit,['ok'=>true,'generation'=>['id'=>$generationId]],$now);
foreach(['get','tick','cancel'] as $action)vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',['action'=>$action,'generation_id'=>$generationId]);
workspace_assert(true,'only the bound attachment generation can be polled or cancelled');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'image',['action'=>'cancel','generation_id'=>$nativeImage]),'email composer cannot cancel unrelated native image work');
vp3_phone_agent_native_action_v1($pdo,$emailContext,'media',['action'=>'browse','type'=>'photo']);workspace_assert(true,'explicit attachment selection can browse owner photo library');
workspace_reject(422,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'media',['action'=>'begin','media_type'=>'video']),'email attachment bridge cannot begin an unrelated video upload');
$upload=bin2hex(random_bytes(16));workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'media',['action'=>'chunk','token'=>$upload]),'upload chunks require a token started by this composer');
vp3_phone_agent_native_upload_response_v1($emailContext,['action'=>'begin'],['ok'=>true,'token'=>$upload],$now);
vp3_phone_agent_native_action_v1($pdo,$emailContext,'media',['action'=>'chunk','token'=>$upload]);workspace_assert(true,'native upload token is bound to exact composer scope');
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$otherEmailContext,'media',['action'=>'finish','token'=>$upload]),'upload cannot be completed from another draft');
vp3_phone_agent_native_upload_response_v1($emailContext,['action'=>'finish','token'=>$upload],['ok'=>true],$now);
workspace_reject(403,fn()=>vp3_phone_agent_native_action_v1($pdo,$emailContext,'media',['action'=>'finish','token'=>$upload]),'completed upload binding cannot be replayed');
$pdo->prepare('UPDATE workspace_native_sources SET version=2 WHERE source_id=?')->execute([$nativeDraft]);
vp3_phone_agent_native_response_current_v1($pdo,$emailContext,'email');workspace_assert(true,'successful native email save may advance its own source revision');
$pdo->prepare("UPDATE workspace_native_sources SET state='unavailable' WHERE source_id=?")->execute([$nativeDraft]);
workspace_reject(403,fn()=>vp3_phone_agent_native_response_current_v1($pdo,$emailContext,'email'),'native email response never releases earlier private data after source access loss');
$pdo->prepare("UPDATE workspace_native_sources SET state='needs_review' WHERE source_id=?")->execute([$nativeDraft]);
$actualSession=$_SESSION;session_id('phone-agent-native-'.bin2hex(random_bytes(12)));session_start();$_SESSION=$actualSession;session_write_close();
$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$emailContext,'kind'=>'email'];
vp3_phone_agent_native_execution_current_v1($pdo);
workspace_assert(session_status()!==PHP_SESSION_ACTIVE,'native execution fence reopens actual owner session and releases it before provider work');
$pdo->beginTransaction();$pdo->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE device_id=?')->execute([$c]);
workspace_reject(403,fn()=>vp3_phone_agent_native_execution_current_v1($pdo),'device enrollment revoked after wrapper entry blocks execution inside the native transaction');$pdo->rollBack();
workspace_assert(session_status()!==PHP_SESSION_ACTIVE,'rejected native execution also releases the session lock');
$peer=new PDO($dsn,getenv('VP3_TEST_MYSQL_USER')?:'root',getenv('VP3_TEST_MYSQL_PASS')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$peer->exec('USE '.$database);
$pdo->beginTransaction();vp3_phone_sql_v1($pdo,'SELECT enabled FROM vp3_phone_agent_enrollments_v1 WHERE device_id=?',[$c])->fetchColumn();
$peer->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE device_id=?')->execute([$c]);
workspace_reject([403,409],fn()=>vp3_phone_agent_native_execution_current_v1($pdo),'final execution locking read rejects peer revocation beyond an established repeatable-read snapshot');
$pdo->rollBack();$peer->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=1 WHERE device_id=?')->execute([$c]);
session_start();$_SESSION=[];session_write_close();
workspace_reject(401,fn()=>vp3_phone_agent_native_execution_current_v1($pdo),'real owner logout after wrapper entry blocks native execution');
session_start();$_SESSION=$actualSession;session_write_close();
$pdo->prepare("UPDATE workspace_native_sources SET state='unavailable' WHERE source_id=?")->execute([$nativeDraft]);
workspace_reject(403,fn()=>vp3_phone_agent_native_execution_current_v1($pdo),'native source access lost after wrapper entry blocks execution');
unset($GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']);
workspace_reject(403,fn()=>vp3_phone_agent_native_execution_current_v1($pdo),'execution fence cannot run without a trusted wrapper context');
session_start();session_destroy();

// A main-app approval grants only this paired phone's native workspace, never a login.
require_once dirname(__DIR__).'/includes/coding-canvas-phone-v1.php';
session_id('phone-agent-main-grant-'.bin2hex(random_bytes(12)));session_start();
$login(2,'other-password');
$owner2=vp3_phone_agent_workspace_owner_v1($pdo,$other);
$cDevice=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$c])->fetch(PDO::FETCH_ASSOC);
$eBefore=vp3_phone_agent_workspace_enrollment_v1($pdo,2,$c);
$consent=['device_id'=>$c,'generation'=>1,'expected_revision'=>(int)$eBefore['revision'],'enabled'=>true,'confirmed'=>true,'agent_id'=>0,'notifications_enabled'=>false];
workspace_reject(422,fn()=>vp3_phone_transaction_v1($pdo,fn()=>vp3_phone_agent_device_grant_v1($pdo,$owner2,$cDevice,array_replace($consent,['confirmed'=>false]),$now)),
    'a device label or ordinary pairing cannot stand in for explicit main-app Agent approval');
workspace_reject(409,fn()=>vp3_phone_transaction_v1($pdo,fn()=>vp3_phone_agent_device_grant_v1($pdo,$owner2,$cDevice,array_replace($consent,['expected_revision'=>0]),$now)),
    'stale main-app grant cannot replace a newer enrollment');
workspace_reject(409,fn()=>vp3_phone_transaction_v1($pdo,fn()=>vp3_phone_agent_device_grant_v1($pdo,$owner2,$cDevice,array_replace($consent,['generation'=>2]),$now)),
    'approval must match the exact paired device generation');
$chat=(int)$eBefore['conversation_id'];
$pdo->prepare("UPDATE workspace_native_sources SET version=1,state='ready' WHERE source_id=?")->execute([$nativeCanvas]);
$pdo->exec("UPDATE workspace_native_sources SET removed=1 WHERE source_id='90'");
$pdo->prepare("UPDATE workspace_native_sources SET state='needs_review' WHERE source_id=?")->execute([$nativeDraft]);
$deliveryInput=['device_id'=>$c,'generation'=>1,'expected_revision'=>(int)$eBefore['revision'],'request_id'=>'30000000-0000-4000-8000-000000000001','confirmed'=>true];
$targets=vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_targets',[]);
workspace_assert(array_column($targets['phones'],'id')===[$c]&&!$targets['phones'][0]['agent_access']['enabled'],
    'Canvas recipient selection exposes only this owner’s approved phones and distinguishes missing device approval');
$login(2,'other-password');workspace_reject(422,fn()=>vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_send',array_replace($deliveryInput,['confirmed'=>false])),
    'Send Canvas requires an explicit access confirmation');
$login(2,'other-password');workspace_reject(409,fn()=>vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,2,'phone_send',$deliveryInput),
    'a stale Canvas version is not silently replaced by the latest artifact');
$login(2,'other-password');$delivered=vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_send',$deliveryInput)['phone_delivery'];
workspace_assert($delivered['item_ref']===$nativeRefs['canvas']&&!$delivered['duplicate'],
    'Send Canvas grants the exact device and delivers its canonical private source without making a copy or a publication');
$login(2,'other-password');$duplicate=vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_send',$deliveryInput)['phone_delivery'];
workspace_assert($duplicate['duplicate']&&(int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_canvas_deliveries_v1')->fetchColumn()===1,
    'retrying an uncertain Canvas delivery returns the original receipt once');
$login(2,'other-password');workspace_reject(409,fn()=>vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_send',array_replace($deliveryInput,['generation'=>2])),
    'a delivery retry cannot cross a device generation');
$_SESSION=['csrf_token'=>str_repeat('f',64),'phone_agent_workspace_key_v1'=>str_repeat('b',64)];
$pairedStatus=vp3_phone_agent_workspace_request_v1($pdo,null,$tokenC,['action'=>'status','device_id'=>$c],$now);
workspace_assert($pairedStatus['state']==='ready'&&$pairedStatus['authorization_mode']==='paired_device'&&!isset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']),
    'main-app approval opens AGENT with only a paired cookie and an anonymous session');
$pairedBound=$bound($pairedStatus);$pairedRequest=static fn(array $data):array=>vp3_phone_agent_workspace_request_v1($pdo,null,$tokenC,['device_id'=>$c]+$pairedBound+$data,$now);
$newer=$pdo->prepare("INSERT INTO workspace_native_sources(source_id,owner_user_id,agent_id,conversation_id,source_kind,version,state,needs_action,occurred_epoch,updated_epoch,body) VALUES(?,2,0,?,'agent_result',1,'ready',0,?,?,'Later result')");
for($n=300;$n<330;$n++)$newer->execute([(string)$n,$chat,$now,$now]);
$firstPinned=$pairedRequest(['action'=>'feed','limit'=>10]);
workspace_assert($firstPinned['items'][0]['item_ref']===$nativeRefs['canvas'],'an explicitly delivered older Canvas appears first on the recipient phone');
$allRefs=array_column($firstPinned['items'],'item_ref');$nextPage=$firstPinned;
while($nextPage['has_more']){$nextPage=$pairedRequest(['action'=>'feed','limit'=>10,'cursor'=>$nextPage['next_cursor']]);$allRefs=[...$allRefs,...array_column($nextPage['items'],'item_ref')];}
workspace_assert(count($allRefs)===count(array_unique($allRefs))&&count($allRefs)===34,'pinning a delivered Canvas preserves complete cursor pagination without repeating or omitting items');
$pairedFeed=$pairedRequest(['action'=>'feed']);
workspace_assert(in_array($nativeRefs['canvas'],$allRefs,true)
    &&in_array($nativeRefs['gmail_draft'],$allRefs,true),
    'the no-login AGENT feed includes saved email drafts and delivered custom canvases');
workspace_reject(401,fn()=>vp3_phone_agent_workspace_owner_v1($pdo,$other),'paired access does not create a main-app owner session');
workspace_reject(401,fn()=>$pairedRequest(['action'=>'enroll','confirmed'=>true,'expected_revision'=>$pairedStatus['revision']]),
    'the phone cannot elevate its pairing into a new owner grant');
workspace_reject(403,fn()=>$pairedRequest(['action'=>'select_agent','agent_id'=>0,'expected_revision'=>$pairedStatus['revision']]),
    'the phone cannot widen its approved Agent scope');
workspace_reject(401,fn()=>vp3_phone_agent_workspace_scope_v1($pdo,null,$tokenA,$c,false,$now),
    'a valid cookie for a different phone cannot open the approved device');
$pairedScope=vp3_phone_agent_workspace_scope_v1($pdo,null,$tokenC,$c,false,$now);
$pairedCanvas=vp3_phone_agent_workspace_item_v1($pdo,$pairedScope,$nativeRefs['canvas']);
$pairedQuery=['device_id'=>$c,'item_ref'=>$nativeRefs['canvas'],'scope_revision'=>$pairedStatus['scope_revision'],'source_revision'=>$pairedCanvas['item']['source_revision']];
$anonymousContext=vp3_phone_agent_native_context_v1($pdo,null,$pairedQuery,$now);
vp3_phone_agent_native_action_v1($pdo,$anonymousContext,'canvas',['action'=>'open','canvas_id'=>$nativeCanvas,'revision'=>1,'agent_id'=>0,'conversation_id'=>$chat]);
workspace_assert($anonymousContext['scope']['paired_access']&&!isset($_SESSION['user_id']),
    'the native Canvas bridge validates the exact canonical item without a user login');
$pairedEmail=['scope'=>$pairedScope,'resolved'=>vp3_phone_agent_workspace_item_v1($pdo,$pairedScope,$nativeRefs['gmail_draft'])];
vp3_phone_agent_native_action_v1($pdo,$pairedEmail,'email',['action'=>'review','draft_id'=>$nativeDraft,'agent_id'=>0,'conversation_id'=>$chat]);
$anonymousSession=$_SESSION;session_id('phone-agent-paired-'.bin2hex(random_bytes(12)));session_start();$_SESSION=$anonymousSession;session_write_close();
$GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']=['context'=>$pairedEmail,'kind'=>'email'];
vp3_phone_agent_native_execution_current_v1($pdo);
workspace_assert(session_status()!==PHP_SESSION_ACTIVE&&!isset($_SESSION['user_id']),
    'the final canonical email execution fence supports anonymous paired sessions');
$pdo->beginTransaction();vp3_phone_sql_v1($pdo,'SELECT revision FROM vp3_phone_agent_enrollments_v1 WHERE device_id=?',[$c])->fetchColumn();
$peer->prepare('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0,revision=revision+1 WHERE device_id=?')->execute([$c]);
workspace_reject([403,409],fn()=>vp3_phone_agent_native_execution_current_v1($pdo),
    'main-app revocation blocks a no-login native action even after a repeatable-read snapshot began');$pdo->rollBack();
session_start();$_SESSION=$anonymousSession;session_write_close();
workspace_assert(vp3_phone_agent_workspace_request_v1($pdo,null,$tokenC,['action'=>'status','device_id'=>$c],$now)['state']==='approval_required',
    'revocation leaves a visible approval gate and never asks the phone holder to log in');
workspace_reject(403,fn()=>vp3_phone_agent_workspace_scope_v1($pdo,null,$tokenC,$c,false,$now),'revoked paired access cannot serve another private page');
session_start();$login(2,'other-password');workspace_reject(409,fn()=>vp3_coding_canvas_phone_v1($pdo,$other,0,$chat,$nativeCanvas,1,'phone_send',$deliveryInput),
    'replaying a sent Canvas after access withdrawal cannot silently approve the phone again');
$login(2,'other-password');$eNow=vp3_phone_agent_workspace_enrollment_v1($pdo,2,$c);
vp3_phone_transaction_v1($pdo,fn()=>vp3_phone_agent_device_grant_v1($pdo,$owner2,$cDevice,array_replace($consent,['expected_revision'=>(int)$eNow['revision']]),$now));
$_SESSION=$anonymousSession;$newScope=vp3_phone_agent_workspace_scope_v1($pdo,null,$tokenC,$c,false,$now);
workspace_reject(409,fn()=>vp3_phone_agent_workspace_scope_current_v1($pdo,$pairedScope),'reapproving rotates the grant and invalidates old no-login review scopes');
$pdo->exec("UPDATE users SET password_hash='rotated-owner-password' WHERE id=2");
workspace_reject(403,fn()=>vp3_phone_agent_workspace_scope_current_v1($pdo,$newScope),'password rotation withdraws no-login delegated access');
$pdo->exec("UPDATE users SET password_hash='other-password' WHERE id=2");
$pdo->exec('UPDATE users SET workspace_access=0 WHERE id=2');
workspace_reject(403,fn()=>vp3_phone_agent_workspace_scope_current_v1($pdo,$newScope),'current account permissions still fence delegated phone access');
$pdo->exec('UPDATE users SET workspace_access=1 WHERE id=2');
$newCredential=vp3_phone_token_v1(random_bytes(32));$pdo->prepare('UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE id=?')->execute([vp3_extension_hash_v2000($newCredential),$c]);
workspace_reject(403,fn()=>vp3_phone_agent_workspace_scope_v1($pdo,null,$newCredential,$c,false,$now),'a newly paired installation does not inherit the previous installation’s Agent grant');
unset($GLOBALS['VP3_PHONE_AGENT_NATIVE_EXECUTION_CONTEXT_V1']);
session_start();session_destroy();

$pdo->exec('ALTER TABLE vp3_phone_agent_enrollments_v1 DROP COLUMN notifications_since_epoch');
workspace_assert(!vp3_phone_agent_workspace_ready_v1($pdo),'partial schema cannot advertise ready enrollment or background authority');
echo 'PHONE_AGENT_WORKSPACE_MYSQL PASS '.$GLOBALS['workspaceChecks'].PHP_EOL;
