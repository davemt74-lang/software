<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/phone-camera-http-v1.php';

function has_permission(string $key,array $user): bool { return true; }
function check_camera(bool $value,string $description): void {
    if (!$value) throw new RuntimeException($description);
    echo "ok - $description\n";
}
function reject_camera(callable $call,int $status): void {
    try { $call(); } catch (RuntimeException $error) {
        if ($error->getCode()===$status) return;
        throw $error;
    }
    throw new RuntimeException('Expected rejection: '.$status);
}

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER);
    INSERT INTO users VALUES (1,'Owner',1),(2,'Another owner',1);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER);
    INSERT INTO chat_conversations VALUES (8,1,NULL),(9,2,NULL);");
vp3_phone_schema_v1($db);
$invite=vp3_phone_owner_v1($db,1,'invite',['request_id'=>bin2hex(random_bytes(16))]);
$phone=vp3_phone_device_v1($db,'','claim',['invite'=>$invite['invite'],'name'=>'Studio iPhone']);
$device=['device_id'=>$phone['device']['id']];
vp3_phone_owner_v1($db,1,'approve',$device+['request_id'=>bin2hex(random_bytes(16)),
    'generation'=>$phone['device']['generation'],'allow_location'=>false]);
$owner=fn($action,$input)=>vp3_phone_camera_owner_v1($db,1,0,8,$action,$input)['camera'];
$holder=fn($action,$input)=>vp3_phone_camera_holder_v1($db,$phone['token'],$action,$input)['camera'];
$camera=$owner('request',$device);
$session=['session_id'=>$camera['session_id']];
$pending=$holder('status',[]);
check_camera($pending['owner_user_id']===1 && $pending['agent_id']===0 && $pending['conversation_id']===8
    && !isset($pending['answer_sdp']),'the selected handset can bind its announcement to the exact private Chat');
$offer="v=0\r\nm=video 9 UDP/TLS/RTP/SAVPF 96\r\na=sendonly\r\n";
$answer=str_replace('sendonly','recvonly',$offer);
reject_camera(fn()=>$holder('offer',$session+['sdp'=>$offer]),409);
// The device expires in one hour, well before the four-hour maximum preview.
$deviceExpiry=time()+3600;
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET expires_at=? WHERE id=?',[$deviceExpiry,$device['device_id']]);
$grant=$holder('start',$session);
reject_camera(fn()=>$holder('start',$session),409);
reject_camera(fn()=>$holder('stop',$session+['requested_only'=>true]),409);
$oldPulse=time()-10;
vp3_phone_sql_v1($db,'UPDATE vp3_phone_camera_sessions_v1 SET holder_seen=? WHERE id=?',[$oldPulse,$session['session_id']]);
$holder('status',$session);
check_camera((int)$db->query('SELECT holder_seen FROM vp3_phone_camera_sessions_v1')->fetchColumn()===$oldPulse,
    'another phone tab cannot keep the source alive by reading its status');
$holder('status',$session+['heartbeat'=>true]);
check_camera((int)$db->query('SELECT holder_seen FROM vp3_phone_camera_sessions_v1')->fetchColumn()>$oldPulse,
    'the tab sending video can renew its exact active session');
reject_camera(fn()=>$holder('offer',$session+['sdp'=>$offer."m=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"]),422);
reject_camera(fn()=>$holder('offer',$session+['sdp'=>$answer]),422);
$holder('offer',$session+['sdp'=>$offer]);
reject_camera(fn()=>$holder('offer',$session+['sdp'=>str_replace('SAVPF 96','SAVPF 98',$offer)]),409);
$owner('answer',$session+['sdp'=>$answer]);
check_camera($holder('status',$session)['answer_sdp']===$answer && $owner('status',$session)['offer_sdp']===$offer,
    'consent permits one immutable, video-only offer and receive-only answer');
$clock=time()-VP3_PHONE_CAMERA_LEASE_SECONDS_V1-1;
vp3_phone_sql_v1($db,'UPDATE vp3_phone_camera_sessions_v1 SET owner_seen=? WHERE id=?',[$clock,$session['session_id']]);
check_camera($holder('status',$session)['state']==='stopped'
    && $db->query('SELECT offer_sdp IS NULL AND answer_sdp IS NULL FROM vp3_phone_camera_sessions_v1')->fetchColumn()==1,
    'loss of the viewing editor expires the lease and erases both peer descriptions');
$fresh=$owner('request',$device);
$holder('stop',$session);
check_camera($fresh['session_id']!==$session['session_id'] && $holder('status',[])['session_id']===$fresh['session_id'],
    'late requests for the previous session cannot stop a fresh request');
$GLOBALS['config']['phone_camera']=['ice_urls'=>['stun:relay.example:3478','turns:relay.example:5349?transport=tcp','https://invalid.example'],
    'turn_shared_secret'=>'server-side-test-secret'];
$servers=vp3_phone_camera_ice_v1($grant);
check_camera(vp3_phone_camera_ice_v1($pending)===[] && count($servers)===2
    && $servers[1]['credential']===base64_encode(hash_hmac('sha1',$servers[1]['username'],'server-side-test-secret',true))
    && !str_contains(json_encode($servers),'server-side-test-secret'),'only an accepted camera grant yields temporary relay credentials');
check_camera((int)explode(':',$servers[1]['username'])[0]===$deviceExpiry && $deviceExpiry>$grant['expires_at'],
    'the relay can refresh after negotiation but never beyond pairing or preview expiry');

$holder('stop',['session_id'=>$fresh['session_id']]);
require_once dirname(__DIR__).'/includes/paired-phone-agent-v1.php';
$db->exec('CREATE TABLE chat_messages(id INTEGER PRIMARY KEY)');
vp3_paired_phone_schema_v1($db,'');
vp3_paired_phone_bind_v1($db,$device['device_id'],1,0,8);
reject_camera(fn()=>$holder('prepare',['device_id'=>str_repeat('0',32)]),403);
reject_camera(fn()=>$holder('prepare',$device+['conversation_id'=>9]),422);
$self=$holder('prepare',$device);
check_camera($self['owner_user_id']===1 && $self['conversation_id']===8 && $self['state']==='requested'
    && $self['session_id']===$holder('prepare',$device)['session_id'],
    'the paired phone can prepare its own private preview with an idempotent retry');
$selfSession=['session_id'=>$self['session_id']];
check_camera($holder('start',$selfSession)['state']==='sharing' && $owner('status',$selfSession)['state']==='sharing',
    'explicit holder sharing is reachable in the linked private editor');
$holder('stop',$selfSession);
