<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';

function has_permission(string $permission, array $user): bool
{
    return in_array($permission,['account.access','chat.access'],true);
}

function check(bool $result, string $label): void
{
    if (!$result) throw new RuntimeException($label);
    echo "ok - $label\n";
}

function blocked(int $status, callable $operation, string $label): void
{
    try { $operation(); }
    catch (RuntimeException $error) { check($error->getCode()===$status,$label); return; }
    throw new RuntimeException("Expected rejection: $label");
}

function pair(PDO $db, int $owner, string $name): array
{
    $invite=vp3_phone_owner_v1($db,$owner,'invite',['request_id'=>bin2hex(random_bytes(12))]);
    $claim=vp3_phone_device_v1($db,'','claim',['invite'=>$invite['invite'],'name'=>$name]);
    $phone=$claim['device'];
    $approved=vp3_phone_owner_v1($db,$owner,'approve',['device_id'=>$phone['id'],'generation'=>$phone['generation'],
        'request_id'=>bin2hex(random_bytes(12))])['device'];
    return ['token'=>$claim['token'],'device'=>$approved];
}

function post(PDO $db, array $pair, string $action, array $body=[]): array
{
    $phone=vp3_phone_device_v1($db,$pair['token'],'status',[])['device'];
    return vp3_phone_device_v1($db,$pair['token'],$action,$body+['device_id'=>$phone['id'],'generation'=>$phone['generation']])['device'];
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER)');
$db->exec("INSERT INTO users VALUES(11,'Owner',1),(22,'Other owner',1)");
$db->exec('CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER)');
$db->exec('INSERT INTO chat_conversations VALUES(10,11,NULL),(20,11,7),(30,22,NULL)');
vp3_phone_schema_v1($db);
vp3_phone_schema_v1($db);
check(vp3_phone_schema_ready_v1($db),'fresh arrival tables and repeated upgrade');
$iphone=pair($db,11,'Field iPhone');
$guest=pair($db,11,'Guest phone');
$id=$iphone['device']['id'];
$placeId=bin2hex(random_bytes(16));
vp3_phone_sql_v1($db,'INSERT INTO vp3_phone_places_v1(owner_user_id,id,name,name_hash,latitude,longitude,accuracy,created_at)
    VALUES(?,?,?,?,?,?,?,?)',[11,$placeId,'Studio',hash('sha256','studio'),33.45,-112.07,4,time()]);
$request=fn(int $generation,int $agent=0,int $conversation=10,string $topic='recording') =>
    vp3_phone_arrival_request_v1($db,11,$agent,$conversation,$id,$generation,$placeId,$topic);
blocked(409,fn()=>$request($iphone['device']['generation']),'pairing alone cannot request arrival consent');
$i=post($db,$iphone,'start');
$g=post($db,$guest,'start');
check($i['arrival_rules']===[] && $g['arrival_rules']===[] && $i['history_state']==='off','live GPS and history do not grant arrivals');
blocked(403,fn()=>$request($i['generation'],7,10),'another Agent cannot bind the system conversation');
blocked(403,fn()=>$request($i['generation'],0,30),'another account’s conversation cannot be a destination');
blocked(404,fn()=>vp3_phone_arrival_request_v1($db,22,0,30,$id,$i['generation'],$placeId,'recording'),
    'another account cannot subscribe to this device');
$rule=$request($i['generation']);
check($rule['state']==='requested' && $rule['place_name']==='Studio' && $rule['device_name']==='Field iPhone' &&
    $rule['task_query']==='recording','request resolves to one phone, place, task topic and destination');
check($request($i['generation'])===$rule,'repeated setup keeps one permission request');
blocked(409,fn()=>$request($i['generation'],0,10,'mixing'),'a changed task topic needs a new holder decision');
check(count(vp3_phone_arrival_list_v1($db,11,0,10))===1 && vp3_phone_arrival_list_v1($db,11,7,20)===[],
    'each private Agent Chat sees its own rules');
$offered=post($db,$iphone,'status')['arrival_rules'][0];
check($offered['id']===$rule['id'] && !isset($offered['latitude'],$offered['longitude'],$offered['conversation_id'])
    && post($db,$guest,'status')['arrival_rules']===[],'holder sees only its requested places, without saved coordinates or conversation ID');
$ref=['rule_id'=>$rule['id'],'rule_version'=>1];
blocked(409,fn()=>post($db,$guest,'accept_arrival',$ref),'a second phone cannot approve the first phone’s rule');
$accepted=post($db,$iphone,'accept_arrival',$ref)['arrival_rules'][0];
check($accepted['state']==='active' && $accepted['version']===2 && post($db,$iphone,'accept_arrival',$ref)['arrival_rules'][0]===$accepted,
    'retrying the consenting holder’s acceptance preserves the same grant');
$grants=[$rule['id']=>2];
$events=fn():array=>$db->query('SELECT visit_number,arrived_at FROM vp3_phone_arrival_events_v1 ORDER BY visit_number')->fetchAll(PDO::FETCH_NUM);
$zone=fn()=>$db->query('SELECT zone_state FROM vp3_phone_arrival_rules_v1')->fetchColumn();
// Keep all simulated sensor seconds inside the production freshness window without sleeping.
$start=time()-42;
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET started_at=?',[$start-30]);
vp3_phone_sql_v1($db,'UPDATE vp3_phone_arrival_rules_v1 SET accepted_at=?',[$start]);
$fix=function(array $pair,int $tick,float $latitude,array $grants=[],float $accuracy=4) use($db,$start): array {
    return post($db,$pair,'fix',['fix_at'=>$start+$tick,'latitude'=>$latitude,'longitude'=>-112.07,'accuracy'=>$accuracy,'arrival_grants'=>$grants]);
};
$inside=33.45;
$outside=33.46;
$edge=33.4513;
blocked(422,fn()=>$fix($iphone,-25,$outside,$grants),'stale sensor fixes cannot prime an arrival');
$fix($iphone,0,$outside,$grants);
$fix($iphone,1,$outside,[]);
$fix($iphone,2,$outside,[$rule['id']=>1]);
$fix($guest,3,$outside,$grants);
$fix($guest,4,$inside,$grants);
check($zone()==='unknown' && !$events(),'pre-consent, legacy, stale revision and other-device fixes have no effect');
$fix($iphone,3,$inside,$grants);
$fix($iphone,4,$inside,$grants);
check($zone()==='inside' && !$events(),'initially inside establishes a baseline without an arrival');
$fix($iphone,5,$outside,$grants,1000);
$fix($iphone,6,$edge,$grants);
check($zone()==='inside','imprecise fixes and the boundary band cannot rearm an alert');
$fix($iphone,7,$outside,$grants);
$fix($iphone,8,$inside,$grants,90);
check($zone()==='outside' && !$events(),'uncertain entry waits for a confident fix');
$fix($iphone,9,$inside,$grants);
$fix($iphone,9,$inside,$grants);
post($db,$iphone,'status');
$fix($iphone,10,$inside,$grants);
check(count($events())===1 && (int)$events()[0][1]===$start+9,'replay, reopening and further indoor fixes keep one arrival');
$fix($iphone,11,$outside,$grants);
$fix($iphone,12,$inside,$grants);
check(array_map('intval',array_column($events(),0))===[1,2] && !vp3_phone_history_read_v1($db,11,$id)['points'],
    'a confirmed exit and re-entry permit a second visit while history stays empty');
vp3_phone_arrival_cancel_v1($db,11,7,20,$rule['id']);
check(count($events())===2 && $zone()==='inside','another conversation cannot cancel the original subscription');
$after=post($db,$iphone,'stop_arrival',['rule_id'=>$rule['id'],'rule_version'=>2]);
check($after['arrival_rules']===[] && $after['location_state']==='live' && !$events(),'withdrawing removes undelivered arrivals and preserves live sharing');
blocked(409,fn()=>post($db,$iphone,'accept_arrival',$ref),'delayed acceptance cannot resurrect a withdrawn rule');
$again=$request($i['generation']);
check($again['id']!==$rule['id'] && $again['version']===1,'a new request after withdrawal needs new consent');
post($db,$iphone,'decline_arrival',['rule_id'=>$again['id'],'rule_version'=>1]);
check(!vp3_phone_arrival_list_v1($db,11,0,10),'decline removes only this request');
$chatRule=$request($i['generation'],7,20,'');
check($chatRule['task_query']==='' && count(vp3_phone_arrival_list_v1($db,11,7,20))===1,'explicit all-open-tasks works in a named private Agent');
$db->exec('DELETE FROM chat_conversations WHERE id=20');
check(post($db,$iphone,'status')['arrival_rules']===[],'deleting the conversation withdraws the rule');
$request($i['generation']);
$stopped=post($db,$iphone,'stop');
check($stopped['arrival_rules']===[] && !(int)$db->query('SELECT COUNT(*) FROM vp3_phone_arrival_rules_v1')->fetchColumn(),
    'ending live sharing removes the session’s rules');
$new=post($db,$iphone,'start');
blocked(409,fn()=>$request($i['generation']),'a restarted live session cannot reuse its earlier generation');
$request($new['generation']);
vp3_phone_places_owner_v1($db,11,'delete_place',['place_id'=>$placeId,'request_id'=>bin2hex(random_bytes(12))]);
check(post($db,$iphone,'status')['arrival_rules']===[] && !$events(),'deleting a saved place removes attached rules and pending events');
echo "PASS Per-phone consent, precise arrivals, private destinations, once-per-visit receipts and independent live GPS.\n";
