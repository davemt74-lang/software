<?php
declare(strict_types=1);

// Isolated source integration. No production key, provider request or live push.
$root=sys_get_temp_dir().'/vp3-push-events-'.bin2hex(random_bytes(10));
mkdir($root.'/private',0700,true);define('STONEFELLOW_ROOT',$root);
$key=random_bytes(32);file_put_contents($root.'/private/ai-key.php','<?php return '.var_export(base64_encode($key),true).';');
register_shutdown_function(static function()use($root):void{unlink($root.'/private/ai-key.php');rmdir($root.'/private');rmdir($root);});
require_once dirname(__DIR__).'/includes/ai-settings.php';
require_once dirname(__DIR__).'/includes/paired-phone-agent-v1.php';
require_once dirname(__DIR__).'/includes/paired-phone-chat-owner-v1.php';
function has_permission(string $permission,array $user):bool
{return $permission==='users.manage'?($user['role']??'')==='admin':in_array($permission,['account.access','chat.access'],true);}
// Existing owner-session HTTP tests cover this boundary. Here use one explicit
// fixture principal so the production reply writer and its source hook execute.
function vp3_phone_chat_authorize_v1(PDO $pdo,array $user,array $principal,array $input,int $cid):void
{if(($user['id']??null)!==1||($principal['agent_id']??null)!==0||$cid!==8)throw new RuntimeException('Fixture private Chat denied.',403);}
$checks=0;
function ppe_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function ppe_reject(int $code,callable $fn,string $label):void
{try{$fn();}catch(RuntimeException $e){ppe_check($e->getCode()===$code,$label.' unexpected '.$e->getCode());return;}throw new RuntimeException($label.' not rejected');}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER)');
$pdo->exec("INSERT INTO users VALUES(1,'Owner',1),(2,'Other',1)");
$pdo->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT)');
$pdo->exec("INSERT INTO chat_conversations VALUES(8,1,NULL,NULL,'Private phone thread',CURRENT_TIMESTAMP),(9,1,NULL,NULL,'Different thread',CURRENT_TIMESTAMP)");
$pdo->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT)');
vp3_phone_schema_v1($pdo);vp3_paired_phone_schema_v1($pdo,'');
$now=time();$admin=['role'=>'admin'];
vp3_phone_push_configure_v1($pdo,$admin,true,$now);
vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:fixture@example.com','revision'=>1],$now);
vp3_phone_push_delivery_heartbeat_v1($pdo,$now,['runtime'=>PHP_VERSION,'transport_ready'=>true,'code'=>'ready']);
$phones=[];
foreach([1,1,2] as $i=>$owner){
    $id=bin2hex(random_bytes(16));$token=vp3_phone_token_v1(random_bytes(32));
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,generation,location_state,created_at,expires_at,last_seen_at)
        VALUES(?,?,?,?,?,'approved',1,'off',?,?,?)",[$id,$owner,vp3_extension_hash_v2000($token),'Fixture phone',$i===1?'owner':'guest',$now,$now+86400,$now]);
    $d=vp3_phone_row_v1($pdo,$owner,$id);$config=vp3_phone_push_config_v1($pdo);
    $sub=['endpoint'=>'https://web.push.apple.com/Qsource_fixture_'.$id,'expirationTime'=>null,
        'keys'=>['p256dh'=>vp3_phone_push_keypair_v1()['public_key'],'auth'=>vp3_phone_push_b64_v1(random_bytes(16))]];
    vp3_phone_transaction_v1($pdo,static fn()=>vp3_phone_push_holder_v1($pdo,$d,'push_register',['device_id'=>$id,'subscription_revision'=>0,
        'confirmed'=>true,'key_id'=>hash('sha256',$config['public_key']),'subscription'=>$sub],$now));
    $phones[]=['d'=>$d,'token'=>$token];
}
[$a,$b,$c]=$phones;$id=$a['d']['id'];
vp3_paired_phone_bind_v1($pdo,$id,1,0,8);vp3_paired_phone_bind_v1($pdo,$b['d']['id'],1,0,8);
$rid='11223344-5566-4aaa-8bbb-ccccccccccc1';
vp3_paired_phone_chat_v1($pdo,$a['token'],'chat_send',['device_id'=>$id,'request_id'=>$rid,'message'=>'Private holder question']);
ppe_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===0,'holder send is not pushed back to holder');
$replyArgs=['agent_id'=>0,'conversation_id'=>8,'device_id'=>$id,'request_id'=>$rid,'message'=>'Private owner response'];
$reply=vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],$replyArgs);
$event=$pdo->query('SELECT * FROM vp3_phone_push_outbox_v1')->fetch();
ppe_check($event['device_id']===$id&&$event['source_type']==='private_chat'&&$event['source_id']===(string)$reply['reply_message_id'],'production owner reply atomically queues exact phone source');
ppe_check(!str_contains(json_encode($event),'Private owner response')&&!str_contains(json_encode($event),'web.push.apple.com'),'outbox excludes private text and endpoint');
vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],$replyArgs);
ppe_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_outbox_v1')->fetchColumn()===1,'retried owner reply has one notification');
$source=vp3_phone_push_delivery_event_source_v1($event);
ppe_check(vp3_phone_push_source_current_v1($pdo,$a['d'],$source,$now),'current canonical phone source valid');
ppe_check(!vp3_phone_push_source_current_v1($pdo,$b['d'],$source,$now),'another phone with owner role and same private conversation cannot receive source');
ppe_check(!vp3_phone_push_source_current_v1($pdo,$c['d'],$source,$now),'another account cannot receive source');
ppe_check(!vp3_phone_push_source_current_v1($pdo,$a['d'],array_replace($source,['type'=>'voicemail']),$now),'owner voicemail is not an implicit holder notification grant');
$payloads=[];
$transport=static function(array $sub,array $vapid,string $payload,array $options)use(&$payloads):array{
    $payloads[]=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
    return ['state'=>'accepted','code'=>'provider_accepted','http_status'=>201,'retry_after'=>0,'dispatched'=>true];
};
vp3_phone_push_delivery_run_v1($pdo,1,'vp3_phone_push_source_current_v1',$transport,$now);
ppe_check(array_keys($payloads[0])===['v','event_ref','receipt_token'],'push payload carries only version, opaque reference and display capability');
$resolved=vp3_phone_device_v1($pdo,$a['token'],'push_resolve',['device_id'=>$id,'event_ref'=>$event['event_ref']]);
ppe_check($resolved['target']===['tab'=>'chat','request_id'=>$rid]&&$resolved['opened_epoch']>0,'real holder action resolves exact private Chat turn');
ppe_check(!isset($resolved['source']),'canonical source identifiers are not an unrestricted projection');
ppe_reject(404,fn()=>vp3_phone_device_v1($pdo,$b['token'],'push_resolve',['device_id'=>$b['d']['id'],'event_ref'=>$event['event_ref']]),'same account wrong phone cannot resolve');
ppe_reject(403,fn()=>vp3_phone_device_v1($pdo,$b['token'],'push_resolve',['device_id'=>$id,'event_ref'=>$event['event_ref']]),'cannot target another device id');
vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_threads_v1 SET conversation_id=9 WHERE device_id=?',[$id]);
ppe_reject(404,fn()=>vp3_phone_device_v1($pdo,$a['token'],'push_resolve',['device_id'=>$id,'event_ref'=>$event['event_ref']]),'rebound thread invalidates prior source');
vp3_phone_sql_v1($pdo,'UPDATE vp3_paired_phone_threads_v1 SET conversation_id=8 WHERE device_id=?',[$id]);

// Exact notification focus stays available beyond the normal recent page,
// while another holder can never retrieve that target through the focus field.
for($i=0;$i<31;$i++){
    $marker=vp3_paired_phone_marker_v1($pdo,1,8,'user');$next=sprintf('11223344-5566-4aaa-8bbb-%012d',$i+10);
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_paired_phone_turns_v1(device_id,request_id,owner_user_id,agent_id,conversation_id,user_message_id,message,state,attempted_at) VALUES(?,?,1,0,8,?,?,'waiting',?)",[$id,$next,$marker,'Later prompt',$now]);
}
$recent=vp3_paired_phone_chat_v1($pdo,$a['token'],'chat_status',['device_id'=>$id]);
$focused=vp3_paired_phone_chat_v1($pdo,$a['token'],'chat_status',['device_id'=>$id,'focus_request_id'=>$rid]);
ppe_check(count($recent['turns'])===30&&count($focused['turns'])===31&&$focused['turns'][0]['request_id']===$rid,'targeted status includes one older private turn');
ppe_check(vp3_paired_phone_chat_v1($pdo,$b['token'],'chat_status',['device_id'=>$b['d']['id'],'focus_request_id'=>$rid])['turns']===[],'focus identifier cannot cross phone boundary');

$profile=bin2hex(random_bytes(16));
vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,owner_requests,holder_requests,updated_epoch) VALUES(?,?,?,1,1,1,?)',[1,$id,$profile,$now]);
$exchange=bin2hex(random_bytes(16));$info=['device_id'=>$id,'generation'=>1,'profile_id'=>$profile,'profile_revision'=>1,'exchange_id'=>$exchange,'kind'=>'question','message'=>'Private information request'];
vp3_phone_information_owner_v1($pdo,1,'information_create',$info);
vp3_phone_information_owner_v1($pdo,1,'information_create',$info);
$requestEvent=$pdo->query("SELECT * FROM vp3_phone_push_outbox_v1 WHERE source_type='agent_request'")->fetch();
ppe_check($requestEvent&&$requestEvent['source_id']===$exchange&&(int)$pdo->query("SELECT COUNT(*) FROM vp3_phone_push_outbox_v1 WHERE source_type='agent_request'")->fetchColumn()===1,'canonical request creation queues one exact event');
vp3_phone_push_delivery_run_v1($pdo,1,'vp3_phone_push_source_current_v1',$transport,$now);
$opened=vp3_phone_device_v1($pdo,$a['token'],'push_resolve',['device_id'=>$id,'event_ref'=>$requestEvent['event_ref']]);
ppe_check($opened['target']===['tab'=>'requests','exchange_id'=>$exchange],'request notification resolves exact exchange');
vp3_phone_device_v1($pdo,$a['token'],'information_accept',['device_id'=>$id,'generation'=>1,'profile_id'=>$profile,'profile_revision'=>1,'exchange_id'=>$exchange,'version'=>1]);
ppe_reject(404,fn()=>vp3_phone_device_v1($pdo,$a['token'],'push_resolve',['device_id'=>$id,'event_ref'=>$requestEvent['event_ref']]),'already handled request cannot reopen actionable alert');
vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_communication_profiles_v1 SET holder_requests=0,revision=2 WHERE device_id=?',[$id]);
ppe_check(!vp3_phone_push_source_current_v1($pdo,$a['d'],vp3_phone_push_delivery_event_source_v1($requestEvent),$now),'request consent withdrawal invalidates source');

$later='11223344-5566-4aaa-8bbb-000000000010';
$before=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();
$pdo->exec("CREATE TRIGGER ppe_queue_failure BEFORE INSERT ON vp3_phone_push_outbox_v1 BEGIN SELECT RAISE(ABORT,'fixture queue failure'); END");
try{
    vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],array_replace($replyArgs,['request_id'=>$later,'message'=>'Atomic reply']));
    throw new RuntimeException('Queue failure was hidden.');
}catch(PDOException){ppe_check((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before,'enabled queue failure rolls back source marker and owner reply together');}
$pdo->exec('DROP TRIGGER ppe_queue_failure');
$atomic=vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],array_replace($replyArgs,['request_id'=>$later,'message'=>'Atomic reply']));
ppe_check($atomic['reply_message_id']>0&&(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$before+1,'retry after queue recovery commits source once');

$seed=$pdo->query("SELECT * FROM vp3_phone_push_outbox_v1 WHERE state='queued' LIMIT 1")->fetch();
for($i=1;$i<64;$i++){
    $backlog=array_replace($seed,['event_ref'=>bin2hex(random_bytes(16)),'source_key'=>hash('sha256','backlog-'.$i),'source_id'=>'fixture-backlog-'.$i]);
    vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_push_outbox_v1('.implode(',',array_keys($backlog)).') VALUES('.implode(',',array_fill(0,count($backlog),'?')).')',array_values($backlog));
}
$capacityArgs=array_replace($replyArgs,['request_id'=>'11223344-5566-4aaa-8bbb-000000000011','message'=>'Saved while optional queue is full']);
$capacity=vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],$capacityArgs);
ppe_check($capacity['reply_message_id']>0&&vp3_phone_push_delivery_status_v1($pdo,$now)['capacity_skipped_count']===1,'full optional queue preserves primary reply and reports skipped alert');
vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],$capacityArgs);
ppe_check(vp3_phone_push_delivery_status_v1($pdo,$now)['capacity_skipped_count']===1,'retried saved source does not duplicate capacity accounting');

// Missing optional push migrations must never reproduce the Phone loading
// regression. Private Chat remains usable through an additive rolling upgrade.
$pdo->exec('DROP TABLE vp3_phone_push_outbox_v1');$pdo->exec('DROP TABLE vp3_phone_push_delivery_config_v1');
ppe_check(vp3_phone_device_v1($pdo,$a['token'],'status',[])['device']['status']==='approved','missing delivery upgrade does not break Phone status');
$later='11223344-5566-4aaa-8bbb-000000000012';
$new=vp3_paired_phone_owner_reply_v1($pdo,['id'=>1],['agent_id'=>0],array_replace($replyArgs,['request_id'=>$later,'message'=>'Reply without optional push schema']));
ppe_check($new['reply_message_id']>0,'owner reply remains available before delivery upgrade');
ppe_reject(503,fn()=>vp3_phone_device_v1($pdo,$a['token'],'push_test',['device_id'=>$id,'subscription_revision'=>1,'confirmed'=>true,'request_id'=>bin2hex(random_bytes(16))]),'optional test asks for upgrade without affecting other actions');
echo 'PASS phone push canonical events: '.$checks." checks.\n";
