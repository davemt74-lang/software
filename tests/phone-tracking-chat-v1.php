<?php
declare(strict_types=1);

// Use the real Chat dispatcher, principal and participant fences, and phone domain.
// Only the host session/permission helpers and application URL base are fixtures.
require_once dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';

function csrf_token(): string { return 'phone-chat-fixture-csrf'; }
function url(string $path): string { return '/vp3'.$path; }
function has_permission(string $key,array $user): bool { return true; }
function artist_workspace_v181_scope_id(array $user): int { return 0; }
function current_user(): array { return ['id'=>$GLOBALS['phoneTestOwner']??1]; }
function table_exists(string $table): bool {
    return (bool)vp3_phone_sql_v1($GLOBALS['phoneTestDb'],"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",[$table])->fetchColumn();
}
function phone_chat_test(bool $ok,string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "ok - $label\n";
}
function phone_chat_reject(callable $work,int $code,string $label): void {
    try { $work(); } catch (RuntimeException $e) { phone_chat_test($e->getCode()===$code,$label.' ('.$e->getCode().')'); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['phoneTestDb']=$pdo;
$pdo->exec("PRAGMA foreign_keys=ON");
$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER)");
$pdo->exec("CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER)");
$pdo->exec("CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,user_agent_id INTEGER,artist_workspace_id INTEGER,title TEXT,updated_at TEXT)");
$pdo->exec("CREATE TABLE chat_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT)");
$pdo->exec("INSERT INTO users VALUES (1,'Owner',1),(2,'Another owner',1)");
$pdo->exec("INSERT INTO user_agents VALUES (7,1,'Studio',1),(8,1,'Producer',1),(9,2,'Other',1)");
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (1,1,7),(2,1,7),(3,1,8),(4,2,9)");
vp3_phone_schema_v1($pdo); vp3_phone_chat_schema_v1($pdo); vp3_phone_chat_schema_v1($pdo);
phone_chat_test(vp3_phone_chat_schema_ready_v1($pdo),'chat schema installs repeatedly');
$user=current_user(); $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,7);
$principal=vp3_agent_chat_principal_v380($user,$agent);
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=vp3_catch_up_page_binding_v1($user);
$send=static function(string $text,array $extra=[],bool $private=true)use($pdo,$user,$agent,$principal):array{
    return vp3_agent_chat_send_v2160($pdo,$user,$agent,$principal,$extra+['message'=>$text,'conversation_id'=>1,
        'agent_context'=>['user_agent_id'=>7],'reminder_request_id'=>bin2hex(random_bytes(16))],false,null,$private);
};
$pair=static function(string $name)use($pdo):array{
    $i=vp3_phone_owner_v1($pdo,1,'invite',['request_id'=>bin2hex(random_bytes(16))]);
    return vp3_phone_device_v1($pdo,'','claim',['name'=>$name,'invite'=>$i['invite'],'claim_key'=>bin2hex(random_bytes(32))]);
};
$activity=static fn(int $cid=1)=>vp3_phone_chat_updates_v1($pdo,$user,$principal,['conversation_id'=>$cid,
    'agent_context'=>['user_agent_id'=>7],'phone_updates'=>true]);
$key=['idempotency_key'=>'retry-initial-connect','conversation_id'=>0];
$connect=$send('Connect my phone.',$key); $replay=$send('Connect my phone.',$key);
phone_chat_test($connect['assistant_message_id']===$replay['assistant_message_id'] && str_contains($connect['actions'][0]['url'],'connect=1')
    && str_contains($connect['actions'][0]['url'],'conversation_id='.$connect['conversation_id']),'connect opens scoped QR and new-chat retries reuse the same turn');
$phone=$pair('iPhone'); $other=$pair('Pixel');
phone_chat_test(vp3_phone_chat_route_v1($pdo,$user,$principal,['message'=>'Is HomeServer connected?','conversation_id'=>1])===null,
    'unrelated connection questions stay with their ordinary Chat handler');
phone_chat_test(str_contains($send('Is Pixel connected?')['answer'],'awaiting connection approval'),
    'a connection question naming an actual phone reads its current status');
$ambiguous=$send('Approve my phone.');
phone_chat_test(str_contains($ambiguous['answer'],'Which phone?') && count(array_filter(vp3_phone_owner_v1($pdo,1,'state',[])['devices'],
    fn($d)=>$d['status']==='pending'))===2,'unqualified approval cannot choose among pending devices');
$approve=$send('Approve iPhone.'); $deny=$send('Deny the Pixel phone.');
phone_chat_test(str_contains($approve['answer'],'connected') && str_contains($deny['answer'],'denied'),'named approval and denial go through normal Chat');
$startKey=['idempotency_key'=>'start-from-voice-once','input_mode'=>'voice'];
$start=$send('Start sharing my iPhone’s location.',$startKey);
phone_chat_test($start['input_mode']==='voice' && str_contains($start['answer'],'requested') && !str_contains($start['answer'],'is now sharing')
    && $start['assistant_message_id']===$send('Start sharing my iPhone’s location.',$startKey)['assistant_message_id'],'dictated possessive start only requests location and retries once');
$device=$phone['device']; $token=$phone['token']; $id=$device['id'];
$mobile=static fn(string $op,array $data=[])=>vp3_phone_device_v1($pdo,$token,$op,$data+['device_id'=>$id])['device'];
$state=$mobile('status'); $generation=$state['generation'];
phone_chat_test($activity()===[] && $state['location_state']==='requested','no success before holder consent');
$mobile('start',['generation'=>$generation]);
phone_chat_test($activity()===[],'no success before a first accepted fix');
$fix=['latitude'=>31.34567,'longitude'=>-111.45678,'accuracy'=>3,'fix_at'=>time(),'generation'=>$generation];
$mobile('fix',$fix);
phone_chat_test($activity(2)===[],'activity in another private conversation cannot claim the confirmation');
$update=$activity();
phone_chat_test(count($update)===1 && str_contains($update[0]['speech'],'is now sharing GPS') && $activity()===[],'one scoped announcement after the first fresh fix');
$rows=$pdo->query('SELECT * FROM chat_messages WHERE conversation_id=1 ORDER BY id')->fetchAll();
$history=vp3_phone_chat_history_v1($pdo,$user,7,1,$rows,[]);
phone_chat_test(str_contains(end($history)['message'],'is now sharing GPS')
    && !str_contains(json_encode($history),'31.34567') && !str_contains(json_encode($update),'111.45678'),'authorized history and announcements omit raw fixes');
phone_chat_test(!str_contains(json_encode($rows),'iPhone') && !str_contains(json_encode($rows),'31.34567'),'general Chat and model indexes store content-free markers');
phone_chat_test(str_contains($send('GPS status for iPhone.')['answer'],'current GPS fix'),'status uses the verified current device');
phone_chat_test(str_contains($send('Stop sharing from my iPhone.')['answer'],'stopped') && $mobile('status')['location_state']==='off','dictated stop clears the location grant and keeps pairing');
phone_chat_test(str_contains($send("Start sharing my iPhone's location.")['answer'],'requested'),'straight apostrophe selects the same phone');
$generation=$mobile('status')['generation']; $mobile('denied',['generation'=>$generation]);
phone_chat_test($activity()===[] && str_contains($send('Phone status.')['answer'],'permission denied'),'denied browser permission never announces sharing');
phone_chat_reject(fn()=>$send('Start sharing.',[],false),403,'public runtime cannot control phone');
phone_chat_reject(fn()=>$send('Start sharing.',['ephemeral_protocol'=>true]),403,'ephemeral Chat cannot request phone');
phone_chat_reject(fn()=>$send('Start sharing.',['conversation_id'=>3]),403,'another Agent Chat cannot control this request');
phone_chat_reject(fn()=>$send('Start sharing.',['conversation_id'=>4]),403,'another account conversation cannot control this request');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1];
phone_chat_reject(fn()=>$send('Start sharing.'),403,'participant cannot start phone sharing');
phone_chat_test(vp3_phone_chat_history_v1($pdo,$user,7,1,$rows,[])===$rows,'participant history receives only content-free markers');
unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']='old-page';
phone_chat_reject(fn()=>$send('Start sharing.'),403,'stale page cannot manage phones');
phone_chat_test($activity()===[] && vp3_phone_chat_history_v1($pdo,$user,7,1,$rows,[])===$rows,'stale page cannot poll or hydrate private phone updates');
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=vp3_catch_up_page_binding_v1($user);
$map=static fn(int $cid)=>vp3_phone_chat_map_start_v1($pdo,$user,['device_id'=>$id,'generation'=>$mobile('status')['generation'],
    'request_id'=>bin2hex(random_bytes(16)),'chat_origin'=>['agent_id'=>7,'conversation_id'=>$cid]]);
phone_chat_reject(fn()=>$map(3),403,'map cannot forge its Agent and Chat association');
$mapStart=$map(2)['device'];
$mobile('start',['generation'=>$mapStart['generation']]);
$mobile('fix',['generation'=>$mapStart['generation'],'fix_at'=>time()]+$fix);
phone_chat_test($activity()===[] && count($activity(2))===1 && $activity(2)===[],'map with exact Chat origin confirms once in that Chat');
$send('Disconnect my iPhone.');
phone_chat_test(!vp3_phone_owner_v1($pdo,1,'state',[])['devices'],'disconnect closes the selected pairing');
