<?php
declare(strict_types=1);

// Use the production phone, task, private Chat and receipt SQL against SQLite.
// Host identity and the editable per-task access policy are controlled by this fixture.
require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';

function current_user(): array { return ['id'=>1]; }
function has_permission(string $permission, array $user): bool { return true; }
function personal_capability_has_v242(string $permission, array $user): bool { return $GLOBALS['brainAccess']; }
function knowledge_retrieval_v162_owner_session(array $user, array $principal): bool { return (int)$user['id']===(int)$principal['owner_user_id']; }
function vp3_catch_up_page_binding_v1(array $user): string { return 'private-page-binding'; }
function vp3_participant_scope_v1(): ?array { return null; }
function vp3_participant_chat_actor_v1(PDO $pdo, array $user, array $input): ?array { return null; }
function vp3_participant_resource_v1(PDO $pdo, array $user, array $principal, string $kind, int $id): bool { return true; }
function user_data_policy_can_use_v236(PDO $pdo, array $principal, int $owner, string $kind, string $ref, bool $flag): bool {
    return !in_array($ref, $GLOBALS['denied'], true);
}
function vp3_capability_live_user_v1(PDO $pdo,array $user,array $principal,array $scope,?callable $authority): array {
    $q=$pdo->prepare('SELECT id FROM chat_conversations WHERE id=? AND user_id=? AND user_agent_id=?');
    $q->execute([$scope['conversation_id'],$user['id'],$principal['agent_id']]);
    if (!$q->fetchColumn()) throw new RuntimeException('Private conversation unavailable.',403);
    return $user;
}
function vp3_agent_chat_resolve_agent_v380(PDO $pdo, array $user, int $aid): array { return ['id'=>$aid]; }
function vp3_agent_chat_principal_v380(array $user, array $agent): array {
    return ['kind'=>'user_agent','agent_id'=>$agent['id'],'owner_user_id'=>$user['id']];
}
function table_exists(string $table): bool {
    $q=$GLOBALS['arrivalDb']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$table]);
    return (bool)$q->fetchColumn();
}
function url(string $path): string { return $path; }
function arrival_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['arrivalDb']=$pdo;
$GLOBALS['brainAccess']=true;
$GLOBALS['denied']=['brain:8'];
$_SERVER['HTTP_X_VP3_WORKING_ATTENTION']='private-page-binding';
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT);
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,conversation_id INTEGER,user_id INTEGER,role TEXT,message TEXT,context_json TEXT);
    CREATE TABLE agent_memory_items(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,memory_scope_version INTEGER DEFAULT 410,
       memory_type TEXT,is_active INTEGER,subject TEXT,memory_text TEXT,metadata_json TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'Owner',1),(2,'Other',1);
    INSERT INTO chat_conversations VALUES(1,1,7,NULL),(2,1,7,NULL),(3,1,8,NULL),(4,2,9,NULL)");
vp3_phone_schema_v1($pdo);
vp3_phone_chat_schema_v1($pdo);
require_once dirname(__DIR__).'/includes/agent-task-lists-v1.php';
vp3_task_lists_schema_v1($pdo);
$uid=1;
$aid=7;
$cid=1;
$user=['id'=>$uid];
$principal=vp3_agent_chat_principal_v380($user,['id'=>$aid]);
$phone=str_repeat('a',32);
$place=str_repeat('b',32);
$now=time();
$pdo->prepare("INSERT INTO vp3_phone_devices_v1(id,owner_user_id,token_hash,device_name,device_role,device_status,allow_location,
    generation,location_state,created_at,expires_at,last_seen_at) VALUES(?,1,?,'Field iPhone','owner','approved',1,3,'live',?,?,?)")
    ->execute([$phone,str_repeat('0',64),$now,$now+2000,$now]);
$pdo->prepare("INSERT INTO vp3_phone_places_v1(owner_user_id,id,name,name_hash,latitude,longitude,accuracy,created_at)
    VALUES(1,?,'Studio',?,33.424242,-112.292929,5,?)")->execute([$place,hash('sha256','studio'),$now]);

$rule=str_repeat('c',32);
$pdo->prepare("INSERT INTO vp3_phone_arrival_rules_v1(id,owner_user_id,device_id,place_id,generation,agent_id,conversation_id,task_query,
    state,version,zone_state,accepted_at,visit_number) VALUES(?,1,?,?,3,7,1,'recording','active',2,'inside',?,1)")
    ->execute([$rule,$phone,$place,$now-10]);
$event = static function(int $visit) use ($pdo,$rule,$now): void {
    $pdo->prepare('UPDATE vp3_phone_arrival_rules_v1 SET visit_number=? WHERE id=?')->execute([$visit,$rule]);
    $pdo->prepare('INSERT INTO vp3_phone_arrival_events_v1(id,rule_id,visit_number,arrived_at) VALUES(?,?,?,?)')
        ->execute([str_pad(dechex($visit),32,'0',STR_PAD_LEFT),$rule,$visit,$now]);
};
$task = static function(int $id,int $owner,?int $agent,string $title,string $status='open',int $active=1,string $body='') use ($pdo): void {
    $pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,is_active,subject,memory_text,metadata_json)
        VALUES(?,?,?,'task',?,?,?,?)")->execute([$id,$owner,$agent,$active,$title,$body,json_encode(['task_status'=>$status])]);
};
$task(1,1,7,'Recording mic check');
$task(2,1,7,'Recording closed','completed');
$task(3,1,7,'Shopping chores');
$task(4,1,8,'Wrong agent recording');
$task(5,2,7,'Other owner recording');
$task(6,1,7,'Recording retired','open',0);
$task(7,1,7,'Mix the vocals','waiting');
$task(8,1,7,'Recording restricted');
$task(9,1,7,'Check cables','in_progress',1,'Needed for the recording setup.');
$task(10,1,null,'System recording');
$pdo->prepare("INSERT INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(1,1,7,'Recording',?,'now')")
    ->execute([hash('sha256','recording')]);
$pdo->prepare("INSERT INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(1,7,?,'now')")->execute([hash('sha256','mix')]);
$input=['phone_updates'=>true,'conversation_id'=>1,'agent_context'=>['user_agent_id'=>7]];
$deliver=static function(array $extra=[]) use ($pdo,$user,$principal,$input): array {
    $pdo->beginTransaction();
    try {
        vp3_phone_account_v1($pdo,1,true);
        $result=vp3_phone_arrival_deliver_v1($pdo,$user,$principal,$extra+$input,1);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
};
$event(1);
arrival_check(vp3_phone_arrival_pending_v1($pdo,1,7,1) && !vp3_phone_arrival_pending_v1($pdo,1,7,2)
    && !vp3_phone_arrival_pending_v1($pdo,1,8,3) && !vp3_phone_arrival_pending_v1($pdo,2,9,4),'arrival queue is private to the exact original owner, Agent and conversation');
try {
    $deliver(['ephemeral_protocol'=>true]);
    throw new LogicException('Public protocol claimed a private arrival.');
} catch (RuntimeException $error) {
    arrival_check($error->getCode()===403 && vp3_phone_arrival_pending_v1($pdo,1,7,1),'public Chat cannot claim or consume an arrival');
}
// Simulate a failure precisely between the private turn and receipt. Retrying must write one message.
$pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON vp3_phone_arrival_briefings_v1 BEGIN SELECT RAISE(ABORT,'receipt failure'); END");
try {
    $deliver();
    throw new LogicException('Expected insertion failure.');
} catch (PDOException $error) {
    arrival_check((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===0
        && vp3_phone_arrival_pending_v1($pdo,1,7,1),'failed delivery rolls back both the private turn and outbox change');
}
$pdo->exec('DROP TRIGGER fail_receipt');
$updates=$deliver();
arrival_check(count($updates)===1 && $deliver()===[],'retries announce a single private message per visit');
$spoken=$updates[0]['speech'];
foreach (['Field iPhone','Studio','Recording mic check','Mix the vocals','Check cables'] as $label) {
    arrival_check(str_contains($spoken,$label),'arrival speaks the current scoped source: '.$label);
}
foreach (['Recording closed','Shopping chores','Wrong agent','Other owner','retired','restricted','System recording','33.424242'] as $noise) {
    arrival_check(!str_contains($spoken,$noise),'announcement excludes '.$noise);
}
$rows=$pdo->query('SELECT * FROM chat_messages ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
arrival_check(!str_contains(json_encode($rows),'Studio') && !str_contains(json_encode($rows),'Recording mic check'),'ordinary history contains only content-free markers');
$hydrated=static fn()=>vp3_phone_chat_history_v1($pdo,$user,7,1,$rows,$input)[0]['message'];
arrival_check($hydrated()===$spoken,'private history restores the same authorized arrival and tasks');
$GLOBALS['denied'][]='brain:1';
$task(11,1,7,'Recording brand new');
$after=$hydrated();
arrival_check(!str_contains($after,'Recording mic check') && !str_contains($after,'Recording brand new')
    && str_contains($after,'Mix the vocals'),'history never replays a revoked source or injects a later task');
$GLOBALS['brainAccess']=false;
arrival_check(str_contains($hydrated(),'Task access is unavailable.') && !str_contains($hydrated(),'Mix the vocals'),'losing Brain access withdraws private task details on reload');
$event(2);
$unavailable=$deliver();
arrival_check(count($unavailable)===1 && str_contains($unavailable[0]['speech'],'Task access is unavailable.'),'denied access at delivery emits arrival without task details');
$event(3);
$pdo->exec("UPDATE vp3_phone_devices_v1 SET generation=4");
arrival_check($deliver()===[] && !vp3_phone_arrival_pending_v1($pdo,1,7,1),'stale grant generations cannot deliver queued arrivals');
