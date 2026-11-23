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
$modelCalls=0;
$model=static function($pdo,$owner,$agent,$history,$guard) use (&$modelCalls,$status,$db,$id,$params,$token): string {
    ++$modelCalls;
    check_phone_turn($guard()===true,'Dispatch guard');
    check_phone_turn($status()['turns'][0]['state']==='pending','Prompt visible before answer');
    $duplicate=vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,static fn()=>throw new LogicException());
    check_phone_turn($duplicate['turns'][0]['state']==='pending','In-flight replay is not a new request');
    check_phone_turn($history===[['role'=>'user','content'=>'A phone prompt']],'No unrelated private history');
    return 'Reply from the agent';
};
check_phone_turn($status()===['turns'=>[]],'Idle poll does not create new threads');
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$phones[2][1],'chat_status',['device_id'=>$phones[2][0]]),403);
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$phones[1][1],'chat_status',['device_id'=>$id]),403);
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_send',$params+['agent_id'=>0],$model),422);
$turns=vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,$model);
check_phone_turn($turns['turns'][0]['reply']==='Reply from the agent' && $turns['turns'][0]['state']==='replied','Roundtrip');
check_phone_turn(vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,$model)===$turns && $modelCalls===1,'Lost response replay idempotent');
check_phone_turn(vp3_paired_phone_chat_v1($db,$phones[1][1],'chat_status',['device_id'=>$phones[1][0]])===['turns'=>[]],'Second phone isolated');
$changed=$params;$changed['message']='Different input';
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_send',$changed,$model),409);
$markers=$db->query('SELECT message FROM chat_messages')->fetchAll(PDO::FETCH_COLUMN);
check_phone_turn($markers===array_fill(0,2,'Private paired phone message.'),'Private messages protected in generic Chat indexes');
$cid=(int)$db->query('SELECT conversation_id FROM vp3_paired_phone_threads_v1 LIMIT 1')->fetchColumn();
vp3_paired_phone_bind_v1($db,$phones[1][0],1,0,$cid);
check_phone_turn(vp3_paired_phone_chat_v1($db,$phones[1][1],'chat_status',['device_id'=>$phones[1][0]])===['turns'=>[]],'Second phone bound to same owner thread cannot read first phone');
$params['request_id']='11223344-5566-4aaa-8bbb-ccccccccccc2';
$failed=vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,static fn()=>throw new RuntimeException('provider unavailable',503));
check_phone_turn($failed['turns'][1]['state']==='failed' && $failed['turns'][1]['reply']===null,'Model failure is not an agent reply');
$db->exec('UPDATE vp3_paired_phone_turns_v1 SET attempted_at=0');
$retried=vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,static fn()=>'Recovered');
check_phone_turn(count($retried['turns'])===2 && $retried['turns'][1]['reply']==='Recovered','Retry recovers same message');
$params['request_id']='11223344-5566-4aaa-8bbb-ccccccccccc3';
$revoke=static function() use($db,$id): string {
    vp3_phone_sql_v1($db,"UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=?",[$id]);
    return 'Should never be delivered';
};
reject_phone_turn(fn()=>vp3_paired_phone_chat_v1($db,$token,'chat_send',$params,$revoke),403);
check_phone_turn(!$db->query("SELECT 1 FROM vp3_paired_phone_turns_v1 WHERE reply='Should never be delivered'")->fetchColumn(),'Revoke during inference prevents delivery');
echo "PASS paired phone Chat roundtrip, prompt/reply persistence, duplicate sends, retries, scope isolation and revocation.\n";
