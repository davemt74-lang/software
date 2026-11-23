<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/paired-phone-agent-v1.php';
function has_permission(string $permission,array $user): bool { return true; }
function check_phone_turn(bool $ok,string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function reject_phone_turn(callable $callback,int $code): void {
    try { $callback(); } catch (RuntimeException $e) {
        check_phone_turn($e->getCode()===$code,'Unexpected failure '.$e->getMessage());return;
    }
    throw new RuntimeException('Expected rejection '.$code);
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, display_name TEXT,is_active INTEGER)');
$db->exec("INSERT INTO users VALUES(1,'Owner',1),(2,'Other',1)");
$db->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT)');
$db->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT)');
vp3_phone_schema_v1($db); vp3_paired_phone_schema_v1($db,'');vp3_paired_phone_schema_v1($db,'');
check_phone_turn(vp3_paired_phone_ready_v1($db),'Upgrade readiness');
$phones=[];
foreach (['One','Two','Pending'] as $i=>$name) {
    $token=vp3_phone_token_v1(random_bytes(32));$id=bin2hex(random_bytes(16));$phones[]=[$id,$token];
    vp3_phone_sql_v1($db,'INSERT INTO vp3_phone_devices_v1 (id,owner_user_id,token_hash,device_name,device_role,device_status,
        generation,allow_location,location_state,created_at,expires_at,last_seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id,1,vp3_extension_hash_v2000($token),$name,'guest',$i===2?'pending':'approved',1,0,'off',time(),time()+120,time()]);
}
$id=$phones[0][0];$token=$phones[0][1]; $rid='11223344-5566-4aaa-8bbb-ccccccccccc1';
$status=fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_status',['device_id'=>$id]);
$params=['device_id'=>$id,'request_id'=>$rid,'message'=>'A phone prompt'];
check_phone_turn($status()===['turns'=>[]],'Idle poll does not create new threads');
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$phones[2][1],'chat_status',['device_id'=>$phones[2][0]]),403);
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$phones[1][1],'chat_status',['device_id'=>$id]),403);
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_send',$params+['agent_id'=>0]),422);
$turns=vp3_paired_phone_chat_v1($db,$token,'chat_send',$params);
check_phone_turn($turns['turns']===[['request_id'=>$rid,'message'=>'A phone prompt','reply'=>null,'state'=>'awaiting_owner']],'Message reaches the owner');
check_phone_turn(vp3_paired_phone_chat_v1($db,$token,'chat_send',$params)===$turns,'Lost response reuses the same message');
$changed=$params;$changed['message']='Different input';
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_send',$changed),409);
$markers=$db->query('SELECT message FROM chat_messages')->fetchAll(PDO::FETCH_COLUMN);
check_phone_turn($markers===['Private paired phone message.'],'Private message protected in generic Chat indexes');
$cid=(int)$db->query('SELECT conversation_id FROM vp3_paired_phone_threads_v1 LIMIT 1')->fetchColumn();
vp3_paired_phone_bind_v1($db,$phones[1][0],1,0,$cid);
check_phone_turn(vp3_paired_phone_chat_v1($db,$phones[1][1],'chat_status',['device_id'=>$phones[1][0]])===['turns'=>[]],'Another phone in the same conversation cannot see its messages');
$db->exec("UPDATE vp3_paired_phone_turns_v1 SET state='busy'");
check_phone_turn($status()===$turns,'Previously failed automatic turns wait for the owner without re-sending');
$db->exec("UPDATE vp3_paired_phone_turns_v1 SET state='pending',lease_until=1");
check_phone_turn($status()===$turns,'Expired automatic attempts wait for the owner');
$db->exec("UPDATE vp3_paired_phone_turns_v1 SET state='replied',reply='Old automatic answer'");
$db->exec('DROP TABLE vp3_paired_phone_owner_replies_v1');
check_phone_turn(!vp3_paired_phone_ready_v1($db),'Installations before owner replies request the additive upgrade');
vp3_paired_phone_schema_v1($db,'');
vp3_paired_phone_schema_v1($db,'');
check_phone_turn($status()['turns'][0]['reply_author']==='agent' && $status()['turns'][0]['reply']==='Old automatic answer',
    'The repeatable schema upgrade keeps existing phones, prompts, and the attributed Agent reply');
vp3_phone_sql_v1($db,"UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=?",[$id]);
reject_phone_turn($status,403);
echo "PASS paired phone Chat delivery, duplicate sends, legacy turns, scope isolation and revocation.\n";
