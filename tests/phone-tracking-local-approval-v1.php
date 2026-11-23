<?php
declare(strict_types=1);

// Exercise the real arrival consent, private task reader, offer and query approval together.
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
function approval_check(bool $ok, string $label): void {
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
    generation,location_state,created_at,expires_at,last_seen_at,fix_at,first_fix_at) VALUES(?,1,?,'Field iPhone','owner','approved',1,3,'live',?,?,?,?,?)")
    ->execute([$phone,str_repeat('0',64),$now,$now+2000,$now,$now,$now]);
$pdo->prepare("INSERT INTO vp3_phone_places_v1(owner_user_id,id,name,name_hash,latitude,longitude,accuracy,created_at)
    VALUES(1,?,'Studio',?,33.424242,-112.292929,5,?)")->execute([$place,hash('sha256','studio'),$now]);

$rule=str_repeat('c',32);
$pdo->prepare("INSERT INTO vp3_phone_arrival_rules_v1(id,owner_user_id,device_id,place_id,generation,agent_id,conversation_id,task_query,
    state,version,zone_state,accepted_at,visit_number) VALUES(?,1,?,?,3,7,1,'recording','active',2,'inside',?,1)")
    ->execute([$rule,$phone,$place,$now-10]);

$pdo->prepare("INSERT INTO vp3_phone_arrival_search_intents_v1(rule_id,item_term,merchant_category,open_today) VALUES(?,'XLR cables','music stores',1)")->execute([$rule]);
$pdo->exec("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,is_active,subject,memory_text,metadata_json)
    VALUES(31,1,7,'task',1,'Recording supplies','Buy XLR cables','{}')");
$pdo->prepare('INSERT INTO vp3_phone_arrival_events_v1(id,rule_id,visit_number,arrived_at) VALUES(?,?,1,?)')
    ->execute([str_repeat('e',32),$rule,$now]);
$input=['phone_updates'=>true,'conversation_id'=>1,'agent_context'=>['user_agent_id'=>7],'reminder_timezone'=>'America/Phoenix'];
$updates=vp3_phone_chat_updates_v1($pdo,$user,$principal,$input);
approval_check(count($updates)===1,'an eligible visit produces a private offer');
$id=(int)$updates[0]['message_id'];
$turn=static function(string $text,int $thread=1) use ($pdo,$user,$principal,$input): string {
    $plan=vp3_phone_chat_plan_v1($text);
    if (!$plan) throw new LogicException('Expected a local search command.');
    $pdo->beginTransaction();
    try {
        vp3_phone_account_v1($pdo,1,true);
        $reply=vp3_phone_local_approval_turn_v1($pdo,$user,$principal,$thread,$plan,$input);
        $pdo->commit();
        return $reply[0];
    } catch(Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
};
foreach (['33.42, -112.29','123 Main Street','Main Ave, Phoenix','https://example.com','Suite West','North@example.com'] as $bad) {
    approval_check(vp3_phone_local_area_v1($bad)===null,'reject precise locations, addresses and network locators');
}
approval_check(vp3_phone_local_area_v1('Roosevelt Row, Phoenix')==='Roosevelt Row, Phoenix'
    && vp3_phone_local_area_v1('St. Louis, Missouri')==='St. Louis, Missouri','allow public city and neighborhood names');
foreach (['Field iPhone','Studio','Recording supplies','Buy XLR cables'] as $privateLabel) {
    approval_check(!str_contains($turn("Search area for offer #$id: $privateLabel"),'Approve local search '),'a private label or task is never turned into search geography');
}
$publicFields=['area'=>'Phoenix, Arizona','item'=>'XLR cables','category'=>'music stores'];
foreach (['item'=>'XLR cables for Studio','category'=>'Field iPhone dealers'] as $field=>$privateTerm) {
    approval_check(vp3_phone_local_private_field_v1($pdo,$uid,$aid,$cid,$id,array_replace($publicFields,[$field=>$privateTerm]))===$field,
        'private place and phone labels cannot enter public product or merchant search terms');
}
approval_check(!str_contains($turn('Search in Phoenix'.PHP_EOL.'Private'),'Approve local search '),'multiline search area cannot become a preview');
$preview=$turn('Search in Phoenix, Arizona');
approval_check((bool)preg_match('/Approve local search ([A-F0-9]{12})/',$preview,$first),'one eligible offer accepts the ordinary area shortcut');
$stored=$pdo->query('SELECT * FROM vp3_phone_local_approvals_v1')->fetch(PDO::FETCH_ASSOC);
$public=json_decode($stored['public_json'],true,16,JSON_THROW_ON_ERROR);
approval_check(array_keys($public)===['area','item','category','open_today','date']
    && str_contains($preview,vp3_phone_local_public_query_v1($public))
    && !str_contains($stored['public_json'],'Studio') && !str_contains($stored['public_json'],'Recording supplies')
    && !str_contains($stored['public_json'],'33.424242'),'the visible preview matches the public payload and keeps private context out');
$second=$turn("Search area for offer #$id: Tempe, Arizona");
approval_check((bool)preg_match('/Approve local search ([A-F0-9]{12})/',$second,$next) && $next[1]!==$first[1],'changing the area invalidates its old permission');
approval_check(!str_contains($turn('Approve local search '.$first[1]),'Local search approved.'),'the first area cannot launch the revised query');
approval_check(!str_contains($turn('Approve local search '.$next[1],2),'Local search approved.'),'another conversation cannot use the preview');
$pdo->exec('UPDATE vp3_phone_local_approvals_v1 SET expires_at=1');
approval_check(str_contains($turn('Approve local search '.$next[1]),'expired'),'a stale preview requests a new public area');
$third=$turn("Search area for offer #$id: Mesa, Arizona");
preg_match('/Approve local search ([A-F0-9]{12})/',$third,$current);
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{\"task_status\":\"completed\"}' WHERE id=31");
approval_check(!str_contains($turn('Approve local search '.$current[1]),'Local search approved.'),'task completion before approval blocks dispatch');
$pdo->exec("UPDATE agent_memory_items SET metadata_json='{}' WHERE id=31");
approval_check($turn('Cancel local search '.$current[1])==='Local search cancelled.'
    && $turn('Approve local search '.$current[1])==='This local search was cancelled.'
    && vp3_phone_local_offer_consumed_v1($pdo,$id),'cancel closes only this visit and its code cannot be approved later');
$sources=vp3_phone_local_sources_v1([
    ['title'=>'Merchant','url'=>'https://store.example/hours','coverage'=>'page_excerpt'],
    ['title'=>'Duplicate','url'=>'https://store.example/hours','coverage'=>'page_excerpt'],
    ['title'=>'Unsafe','url'=>'javascript:alert(1)','coverage'=>'page_excerpt'],
    ['title'=>'Unverified','url'=>'https://lead.example'],
]);
approval_check(count($sources)===1 && $sources[0]['source']==='location-web:'.hash('sha256','https://store.example/hours')
    && $sources[0]['coverage']==='page_excerpt','the result has a native clickable source only for a safe, classified URL');
