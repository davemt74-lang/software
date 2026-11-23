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
// Start with an actual Phase 2 turn: old private replies have a map link without a selected phone.
$pdo->exec("CREATE TABLE vp3_phone_chat_turns_v1 (
    assistant_message_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, user_message_id BIGINT UNSIGNED NULL UNIQUE,
    owner_user_id INT UNSIGNED NOT NULL, agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
    request_hash CHAR(64) NULL UNIQUE, query_hash CHAR(64) NULL, prompt VARCHAR(300) NOT NULL,
    answer TEXT NOT NULL, map_link VARCHAR(12) NOT NULL,
    FOREIGN KEY (assistant_message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE)");
$pdo->prepare("INSERT INTO chat_messages(id,conversation_id,user_id,role,message,context_json) VALUES(1,1,1,'assistant',?,?)")
    ->execute(['Private phone tracking update.','{"source":"private_phone_tracking"}']);
$pdo->exec("INSERT INTO vp3_phone_chat_turns_v1(assistant_message_id,owner_user_id,agent_id,conversation_id,prompt,answer,map_link)
    VALUES(1,1,7,1,'','Original private phone status.','map')");
phone_chat_test(!vp3_phone_chat_schema_ready_v1($pdo),'the Phase 2 Chat schema requests an upgrade');
vp3_phone_schema_v1($pdo); vp3_phone_chat_schema_v1($pdo); vp3_phone_chat_schema_v1($pdo);
phone_chat_test(vp3_phone_chat_schema_ready_v1($pdo),'chat schema installs repeatedly');
$user=current_user(); $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,7);
$principal=vp3_agent_chat_principal_v380($user,$agent);
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']=vp3_catch_up_page_binding_v1($user);
$legacy=$pdo->query('SELECT * FROM vp3_phone_chat_turns_v1 WHERE assistant_message_id=1')->fetch();
$oldRows=$pdo->query('SELECT * FROM chat_messages WHERE id=1')->fetchAll();
$oldHistory=vp3_phone_chat_history_v1($pdo,$user,7,1,$oldRows,[]);
$oldActions=json_decode($oldHistory[0]['context_json'],true,512,JSON_THROW_ON_ERROR)['actions'];
phone_chat_test($legacy['map_device_id']===null && $oldHistory[0]['message']==='Original private phone status.'
    && $oldActions===vp3_phone_chat_result_v1($legacy,'text')['actions']
    && $oldActions[0]['url']==='/vp3/phone-tracking.php?agent=7&conversation_id=1',
    'a real Phase 2 private turn migrates with a null device and hydrates its original map link');
$send=static function(string $text,array $extra=[],bool $private=true)use($pdo,$user,$agent,$principal):array{
    return vp3_agent_chat_send_v2160($pdo,$user,$agent,$principal,$extra+['message'=>$text,'conversation_id'=>1,
        'agent_context'=>['user_agent_id'=>7],'reminder_request_id'=>bin2hex(random_bytes(16))],false,null,$private);
};
$pair=static function(string $name,int $ownerId=1)use($pdo):array{
    $i=vp3_phone_owner_v1($pdo,$ownerId,'invite',['request_id'=>bin2hex(random_bytes(16))]);
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

// Drive two independent grants through the private dispatcher with an identically named phone in another account.
$approvedPhone=static function(string $name,string $role,int $ownerId=1)use($pdo,$pair):array{
    $phone=$pair($name,$ownerId); $device=$phone['device'];
    vp3_phone_owner_v1($pdo,$ownerId,'approve',['device_id'=>$device['id'],'generation'=>$device['generation'],
        'role'=>$role,'request_id'=>bin2hex(random_bytes(16))]);
    return $phone;
};
$holder=static fn(array $phone,string $action='status',array $input=[])=>vp3_phone_device_v1($pdo,$phone['token'],$action,
    ['device_id'=>$phone['device']['id']]+$input)['device'];
$iphone=$approvedPhone('iPhone','owner');
$pixel=$approvedPhone('Pixel','guest');
$otherAccount=$approvedPhone('iPhone','owner',2);
$send('Start sharing my iPhone’s location.');
$send('Start sharing location from the guest phone.');
$grants=[];
foreach ([$iphone,$pixel] as $p) {
    $state=$holder($p);
    phone_chat_test($state['location_state']==='requested','requested sharing for '.$state['name']);
    $grants[$state['name']]=$state['generation'];
    $holder($p,'start',['generation'=>$state['generation']]);
    $holder($p,'fix',['generation'=>$state['generation'],'fix_at'=>time()]+$fix);
}
$announcements=$activity();
$speeches=array_column($announcements,'speech');
phone_chat_test(count($speeches)===2 && count(array_unique(array_column($announcements,'message_id')))===2
    && count(array_filter($speeches,fn($s)=>str_starts_with($s,'iPhone is now sharing GPS')))===1
    && count(array_filter($speeches,fn($s)=>str_starts_with($s,'Pixel is now sharing GPS')))===1
    && $activity()===[],'each simultaneous first fix posts exactly once for its own named phone');

$stopKey=['idempotency_key'=>'two-phones-stop-once'];
$stop=$send('Stop sharing from my iPhone.',$stopKey);
$stopAgain=$send('Stop sharing from my iPhone.',$stopKey);
$notice=$stop['phone_updates'][0]??[];
phone_chat_test(str_contains($stop['answer'],'iPhone') && $notice['message_id']===$stop['assistant_message_id']
    && $notice['speech']===$stop['answer'] && $notice['owner_user_id']===1 && $notice['conversation_id']===1 && $notice['agent_id']===7
    && $stopAgain['assistant_message_id']===$stop['assistant_message_id'] && empty($stopAgain['phone_updates']),
    'a typed stop offers a single scoped spoken confirmation and replay reuses the same Chat turn');
phone_chat_test($holder($iphone)['location_state']==='off' && $holder($pixel)['location_state']==='live'
    && $holder($pixel)['generation']===$grants['Pixel'] && $holder($otherAccount)['location_state']==='off'
    && $holder($otherAccount)['generation']===2,'named stop leaves the peer phone live and another account untouched');
phone_chat_reject(fn()=>$holder($iphone,'fix',['generation'=>$grants['iPhone'],'fix_at'=>time()]+$fix),409,'late fix cannot revive the phone stopped in Chat');

$alike=$approvedPhone('pIXel','device');
$ambiguous=$send('Stop sharing from Pixel.');
phone_chat_test(str_contains($ambiguous['answer'],'same name') && empty($ambiguous['phone_updates'])
    && $holder($pixel)['location_state']==='live' && $holder($alike)['generation']===2,
    'case-equivalent device names require an explicit selection and neither phone changes');
$collision=$approvedPhone('Guest Phone','device');
$ambiguous=$send('Stop sharing from the guest phone.');
phone_chat_test(str_contains($ambiguous['answer'],'Which phone?') && $holder($pixel)['location_state']==='live'
    && $holder($collision)['generation']===2,'a nickname that matches a different phone’s role cannot stop either by mistake');
$explicit=$send('Stop sharing from phone named Guest Phone.',['input_mode'=>'voice']);
phone_chat_test(str_contains($explicit['answer'],'stopped for Guest Phone') && empty($explicit['phone_updates'])
    && $holder($pixel)['location_state']==='live' && $holder($collision)['generation']===3,
    'explicit nickname targets only that device and a voice reply never queues a second spoken stop');

// Give each sharing phone a distinct location. Only one holder has allowed persistent saved places.
foreach (vp3_phone_owner_v1($pdo,1,'state',[])['devices'] as $d) {
    vp3_phone_owner_v1($pdo,1,'revoke',['device_id'=>$d['id'],'generation'=>$d['generation'],'request_id'=>bin2hex(random_bytes(16))]);
}
$field=$approvedPhone('Field iPhone','owner');
$travel=$approvedPhone('Travel Android','guest');
foreach ([[$field,32.3012],[$travel,31.7044]] as [$phone,$lat]) {
    $live=$holder($phone,'start',['generation'=>$holder($phone)['generation']]);
    $holder($phone,'fix',['generation'=>$live['generation'],'latitude'=>$lat,'fix_at'=>time()]+$fix);
}
$allowPlaces=static function(array $phone,bool $allow)use($holder):void {
    $d=$holder($phone);
    $holder($phone,'set_place_permission',['generation'=>$d['generation'],'place_permission_version'=>$d['place_permission_version'],'allow_saved_places'=>$allow]);
};
$allowPlaces($field,true);
$places=static fn(int $owner=1)=>vp3_phone_places_owner_v1($pdo,$owner,'places')['places'];
$route=static fn(string $text,int $cid=1)=>vp3_phone_chat_route_v1($pdo,$user,$principal,['message'=>$text,
    'conversation_id'=>$cid,'agent_context'=>['user_agent_id'=>7]]);
$ask=$send('Save this location as Studio.');
phone_chat_test(str_contains($ask['answer'],'Which phone?') && str_contains($ask['answer'],'Field iPhone')
    && str_contains($ask['answer'],'Travel Android') && empty($ask['phone_updates']) && $places()===[],
    'multiple active phones prompt for a name even when only one permits saving places');
phone_chat_test($route('Field iPhone',2)===null && $route('some other task')===null
    && $route('Field iPhone')['action']==='place_choice','bare name belongs only to its original private conversation');
$wrong=$send('Travel Android.');
phone_chat_test(str_contains($wrong['answer'],'Allow saved places') && empty($wrong['phone_updates']) && $places()===[],
    'selecting a live-only guest phone explains the holder choice and saves nothing');
$send('Save this location as Studio.');
$selectionKey=['idempotency_key'=>'phase-three-field-selection'];
$selected=$send('Field iPhone',$selectionKey);
$selectionAgain=$send('Field iPhone',$selectionKey);
phone_chat_test(str_contains($selected['answer'],'Studio') && str_contains($selected['answer'],'Field iPhone')
    && count($selected['phone_updates'])===1 && $selectionAgain['assistant_message_id']===$selected['assistant_message_id']
    && empty($selectionAgain['phone_updates']) && $route('Field iPhone')===null
    && count($places())===1 && abs($places()[0]['latitude']-32.3012)<0.00001 && $places(2)===[],
    'bare reply saves exactly the selected account-private point once, replies privately and consumes its prompt');
$dup=$send('Save Field iPhone’s location as studio.');
phone_chat_test(str_contains($dup['answer'],'already uses that name') && empty($dup['phone_updates']) && count($places())===1,
    'case-insensitive duplicate name is reported inside Chat without replacing a saved place');
phone_chat_test(str_contains($selected['actions'][0]['url'],'panel=places') && !str_contains(json_encode($selected),'32.3012'),
    'private Chat opens the Places tab and omits the coordinates');
$send('Save this location as Warehouse.');
$allowPlaces($field,false); $allowPlaces($field,true);
$changed=$send('Field iPhone');
phone_chat_test(str_contains($changed['answer'],'changed') && empty($changed['phone_updates']) && count($places())===1,
    'a revoked and regranted holder permission invalidates a prior phone choice');
$send('Save this location as Warehouse.');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(1,1,'user','A different task')");
phone_chat_test($route('Field iPhone')===null,'an intervening ordinary user turn supersedes a saved-place question');
$send('Save this location as Warehouse.');
$pdo->exec('UPDATE vp3_phone_chat_place_choices_v1 SET expires_at=0 WHERE conversation_id=1');
phone_chat_test($route('Field iPhone')===null,'a bare phone name cannot resurrect an expired choice');
$send('Save this location as Warehouse.');
vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_devices_v1 SET fix_at=? WHERE id=?',[time()-120,$field['device']['id']]);
$stale=$send('Field iPhone');
phone_chat_test(str_contains($stale['answer'],'fresh location') && empty($stale['phone_updates']) && count($places())===1,
    'a fix that turns stale after the question cannot be persisted');
$history=$send('Start location history for Travel Android for 7 days.');
$fromHolder=$holder($travel);
phone_chat_test(str_contains($history['answer'],'Approve it on the phone') && $fromHolder['history_state']==='requested'
    && $fromHolder['history_retention_days']===7 && $holder($field)['history_state']==='off',
    'history request is scoped to one phone and requires a separate holder decision');
$holder($travel,'accept_history',['generation'=>$fromHolder['generation'],'history_version'=>$fromHolder['history_version']]);
$status=$send('Show location history for Travel Android.');
$url=$status['actions'][0]['url'];
$turnRow=vp3_phone_sql_v1($pdo,'SELECT * FROM chat_messages WHERE id=?',[$status['assistant_message_id']])->fetch();
$restored=vp3_phone_chat_history_v1($pdo,$user,7,1,[$turnRow],[]);
$restoredUrl=json_decode($restored[0]['context_json'],true,512,JSON_THROW_ON_ERROR)['actions'][0]['url'];
phone_chat_test(str_contains($status['answer'],'7 days') && str_contains($status['actions'][0]['url'],'panel=history')
    && str_contains($url,'device_id='.$travel['device']['id']) && $restoredUrl===$url
    && !str_contains(json_encode($status),'31.7044') && empty($status['phone_updates']),'both original and restored history links select the exact phone without raw points');
$send('Stop location history for Travel Android.');
phone_chat_test($holder($travel)['history_state']==='off' && count($places())===1,'stopping history leaves saved places intact');
$erased=$send('Delete location history for Travel Android.');
phone_chat_test(str_contains($erased['answer'],'Deleted') && count($places())===1,'explicit Chat deletion preserves independently saved places');
$general=json_encode($pdo->query("SELECT message,context_json FROM chat_messages WHERE context_json IS NOT NULL")->fetchAll());
phone_chat_test(!str_contains($general,'Studio') && !str_contains($general,'Field iPhone') && !str_contains($general,'32.3012'),
    'model history and general Chat indexes contain only content-free markers');
// The owner's scoped confirmation creates the request. Location history and continuous retention remain independent.
$setup='When my iPhone arrives at Studio, show me my open recording tasks.';
$rules=static fn(int $cid=1)=>vp3_phone_arrival_list_v1($pdo,1,7,$cid);
$proposal=$send($setup);
phone_chat_test(str_contains($proposal['answer'],'Field iPhone') && str_contains($proposal['answer'],'Studio')
    && str_contains($proposal['answer'],'open recording tasks') && str_contains($proposal['answer'],'Confirm arrival alert')
    && $rules()===[] && $holder($field)['history_state']==='off','setup names the exact phone, place and topic before creating a rule');
phone_chat_test($route('Yes',2)===null && $route('Yes')['action']==='arrival_reply','bare confirmation belongs only to the originating private conversation');
$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(1,1,'user','Different work')");
phone_chat_test($route('Yes')===null && $rules()===[],'intervening work invalidates the pending private confirmation');
$send($setup); $abandoned=$send('Cancel');
phone_chat_test(str_contains($abandoned['answer'],'cancelled') && $rules()===[] && $route('Yes')===null,'cancelled setup leaves no rule and consumes the question');
$send($setup);
$confirmKey=['idempotency_key'=>'phase-four-confirm-once'];
$accepted=$send('Confirm arrival alert',$confirmKey);
$again=$send('Confirm arrival alert',$confirmKey);
$created=$rules();
phone_chat_test(count($created)===1 && $created[0]['state']==='requested' && $created[0]['device_id']===$field['device']['id']
    && $created[0]['task_query']==='recording' && $created[0]['place_id']===$places()[0]['id']
    && $accepted['assistant_message_id']===$again['assistant_message_id'] && count($accepted['phone_updates'])===1 && empty($again['phone_updates']),
    'confirmation generates one holder request and a retry never repeats the announcement');
phone_chat_test(str_contains($send('List my arrival alerts')['answer'],'waiting for phone approval') && $rules(2)===[]
    && str_contains($send('List my arrival alerts',['conversation_id'=>2])['answer'],'no arrival alerts'),
    'another private conversation cannot see or claim this alert');
phone_chat_reject(fn()=>$send($setup,[],false),403,'public runtime cannot propose an arrival alert');
phone_chat_test(str_contains($send('Stop the arrival alert for Field iPhone at Studio')['answer'],'Stopped') && $rules()===[],
    'the owner can stop the exact alert from its own private Chat');
$otherIphone=$approvedPhone('Guest iPhone','guest');
$prompt=$send($setup);
phone_chat_test(str_contains($prompt['answer'],'Which phone?') && str_contains($prompt['answer'],'Guest iPhone')
    && str_contains($prompt['answer'],'Field iPhone') && $rules()===[],'an ambiguous device family cannot bind even when only one is currently sharing');
$offline=$send('Guest iPhone');
phone_chat_test(str_contains($offline['answer'],'Start live sharing') && $rules()===[],'selecting an inactive phone cannot silently substitute another one');
$send($setup); $pick=$send('Field iPhone');
phone_chat_test(str_contains($pick['answer'],'Confirm arrival alert') && $rules()===[],'named clarification still requires confirmation');
$pdo->exec('UPDATE vp3_phone_chat_place_choices_v1 SET expires_at=0 WHERE conversation_id=1');
phone_chat_test(str_contains($send('Confirm arrival alert')['answer'],'no current') && $rules()===[],'expired proposal cannot be confirmed');
$send($setup); $send('Field iPhone');
$lastGeneration=$holder($field)['generation'];
$holder($field,'stop',['generation'=>$lastGeneration]);
phone_chat_test(str_contains($send('Confirm arrival alert')['answer'],'session changed') && $rules()===[],'a stopped sharing generation invalidates the original proposal');
$holder($field,'start',['generation'=>$holder($field)['generation']]);
$holder($field,'stop',['generation'=>$holder($field)['generation']]);
$brackets=$approvedPhone('Field <iPhone>','owner');
$commandName=$approvedPhone('Stop sharing from Travel Android','guest');
foreach ([$brackets,$commandName] as $p) {
    $g=$holder($p,'start',['generation'=>$holder($p)['generation']])['generation'];
    $holder($p,'fix',['generation'=>$g,'fix_at'=>time()]+$fix);
    $allowPlaces($p,true);
}
$labelQuestion=$send('Save this location as Garage.');
phone_chat_test(str_contains($labelQuestion['answer'],'Field iPhone') && !str_contains($labelQuestion['answer'],'<iPhone>'),
    'the choice question offers the display-safe phone label');
$byLabel=$send('Field iPhone');
phone_chat_test(str_contains($byLabel['answer'],'Saved “Garage”') && count($places())===2,'the displayed label resolves even when the original nickname contains markup');
$send('Save this location as Workshop.');
$byCommand=$send('Stop sharing from Travel Android');
phone_chat_test(str_contains($byCommand['answer'],'Saved “Workshop”') && $holder($travel)['location_state']==='live'
    && count($places())===3,'a pending phone name takes precedence over an unrelated executable Chat command');
$send('Save this location as Booth.');
$byRaw=$send('Field <iPhone>');
phone_chat_test(str_contains($byRaw['answer'],'Saved “Booth”') && count($places())===4,'the raw nickname also selects the offered device');
