<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';

function has_permission(string $permission, array $user): bool
{
    return in_array($permission,['account.access','chat.access'],true);
}

function verify(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "ok - $label\n";
}

function rejects(int $code, callable $action, string $label): void
{
    try { $action(); } catch (RuntimeException $e) {
        verify($e->getCode() === $code,$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER)');
$db->exec("INSERT INTO users VALUES(11,'Owner',1),(22,'Other owner',1)");
vp3_phone_schema_v1($db);
vp3_phone_schema_v1($db);
verify(vp3_phone_schema_ready_v1($db),'history schema installs idempotently');

function paired(PDO $db, int $owner, string $label): array
{
    $request = vp3_phone_owner_v1($db,$owner,'invite',['request_id'=>bin2hex(random_bytes(12))]);
    $claim = vp3_phone_device_v1($db,'','claim',['invite'=>$request['invite'],'name'=>$label]);
    $approval = vp3_phone_owner_v1($db,$owner,'approve',[
        'device_id'=>$claim['device']['id'],'generation'=>$claim['device']['generation'],'request_id'=>bin2hex(random_bytes(12)),
    ]);
    return [$claim['token'],$approval['device']['id']];
}

[$token,$id] = paired($db,11,'iPhone');
[$peerToken,$peerId] = paired($db,11,'Pixel');
$phone = fn(string $token): array => vp3_phone_device_v1($db,$token,'status',[])['device'];
$holder = function(string $action,array $body=[],?string $credential=null) use ($db,$token,$phone): array {
    $credential ??= $token;
    $d=$phone($credential);
    return vp3_phone_device_v1($db,$credential,$action,$body+['device_id'=>$d['id'],'generation'=>$d['generation'],
        'history_version'=>$d['history_version']])['device'];
};
$owner = function(string $action,array $body=[]) use ($db,$phone,$token): array {
    $d=$phone($token);
    return vp3_phone_owner_v1($db,11,$action,$body+['device_id'=>$d['id'],'generation'=>$d['generation'],
        'history_version'=>$d['history_version'],'request_id'=>bin2hex(random_bytes(12))]);
};
$points = fn():array => vp3_phone_history_read_v1($db,11,$id)['points'];
$rawCount = fn():int => (int)$db->query("SELECT COUNT(*) FROM vp3_phone_history_points_v1")->fetchColumn();
$f = fn(int $ts):array => ['latitude'=>33.45,'longitude'=>-112.07,'accuracy'=>4.2,'fix_at'=>$ts];
$today = time();
verify($phone($token)['history_state']==='off' && !$points(),'existing pairing starts without a trail');
rejects(409,fn()=>$owner('request_history',['retention_days'=>1]),'history request needs live GPS consent');
$active = $holder('start');
$holder('fix',$f(time()));
$peer = $holder('start',[],$peerToken);
$holder('fix',$f(time()),$peerToken);
rejects(422,fn()=>$owner('request_history',['retention_days'=>2]),'only published retention durations are accepted');
rejects(403,fn()=>$holder('accept_history',['device_id'=>$id],$peerToken),'a different holder cannot accept');
$asked = $owner('request_history',['retention_days'=>7])['device'];
verify($asked['location_state']==='live' && $asked['history_state']==='requested' && !$points(),'a request never converts the live fix to history');
$declined = $holder('decline_history');
verify($declined['history_state']==='off' && $declined['location_state']==='live','holder can decline without stopping live sharing');
rejects(409,fn()=>$holder('accept_history',['history_version'=>$asked['history_version']]),'delayed acceptance cannot reopen a declined request');
$body=['retention_days'=>7,'history_version'=>$declined['history_version'],'request_id'=>bin2hex(random_bytes(12))];
$owner('request_history',$body);
verify($owner('request_history',$body)['duplicate'],'retrying an owner request has one effect');
rejects(409,fn()=>$owner('request_history',array_replace($body,['retention_days'=>30])),'a receipt cannot authorize a different duration');
$accepted = $holder('accept_history');
verify($accepted['location_state']==='live' && $accepted['history_state']==='active' && !$points(),'acceptance does not save a previously received fix');
$acceptedAt = (int)vp3_phone_row_v1($db,11,$id)['history_accepted_at'];
$holder('fix',$f($acceptedAt));
verify(!$points(),'a sample taken in the second of acceptance cannot be backfilled');
$update = $holder('fix',$f($acceptedAt+1)+['history_version'=>null]);
verify($update['fix_at']===$acceptedAt+1 && !$points(),'legacy live-only fix does not enter history');
$holder('fix',$f($acceptedAt+1));
verify(!$points(),'retrying the same live fix cannot promote it to a trail');
$holder('fix',$f($acceptedAt+2));
$holder('fix',$f($acceptedAt+2));
$p=$points();
verify(count($p)===1 && $p[0]['expires_at']===$acceptedAt+2+7*86400 && $p[0]['generation']===$active['generation']
    && $p[0]['history_version']===$accepted['history_version'],'only one new grant-bound sample is stored with its approved lifetime');
verify(!vp3_phone_history_read_v1($db,11,$peerId)['points'],'second live phone remains history-free');
rejects(404,fn()=>vp3_phone_history_read_v1($db,22,$id),'another account cannot read the trail');
$stopped=$holder('stop_history');
$holder('fix',$f($acceptedAt+3)+['history_version'=>$accepted['history_version']]);
verify($stopped['location_state']==='live' && count($points())===1,'holder history stop preserves live GPS and fences delayed uploads');
$owner('request_history',['retention_days'=>1]); $fresh=$holder('accept_history');
verify($points()[0]['expires_at']===$acceptedAt+2+86400,'shorter permission caps an existing point expiry');
$owner('request_history',['retention_days'=>30]); $fresh=$holder('accept_history');
verify($points()[0]['expires_at']===$acceptedAt+2+86400,'longer new permission cannot extend an earlier point');
$holder('fix',$f($acceptedAt+4)+['history_version'=>$accepted['history_version']]);
verify(count($points())===1,'a previous consent revision cannot add a point even after a later acceptance');
$delete = ['history_version'=>$fresh['history_version'],'request_id'=>bin2hex(random_bytes(12))];
$owner('delete_history',$delete);
verify($owner('delete_history',$delete)['duplicate'] && !$points() && $rawCount()===0
    && $phone($token)['location_state']==='live' && $phone($token)['history_state']==='off','delete erases samples, ends history and leaves live GPS on');
$holder('fix',$f($acceptedAt+5)+['history_version'=>$fresh['history_version']]);
verify($rawCount()===0,'queued fix after deletion cannot recreate a point');

// Backdate a fixture set without making the acceptance test sleep through its retention interval.
$insert=$db->prepare('INSERT INTO vp3_phone_history_points_v1
    (owner_user_id,device_id,fix_at,generation,history_version,expires_at,latitude,longitude,accuracy)
    VALUES (11,?,?,2,6,?,33.45,-112.07,5)');
for ($i=0;$i<205;$i++) $insert->execute([$id,$today-1000+$i,$today+86400]);
$insert->execute([$id,$today-1500,$today-1]);
$page=vp3_phone_history_read_v1($db,11,$id);
$older=vp3_phone_history_read_v1($db,11,$id,$page['next_before']);
verify(count($page['points'])===200 && count($older['points'])===5 && $older['next_before']===null
    && $older['points'][4]['fix_at']<$page['points'][0]['fix_at'] && $rawCount()===205,
    'expired points are erased and usable history pages do not overlap');
$insert->execute([$peerId,$today-1500,$today-1]);
verify(vp3_phone_history_sweep_v1($db,time())===1 && $rawCount()===205,'global maintenance erases dormant phone records on expiry');
$owner('request_history',['retention_days'=>1]); $holder('accept_history');
$end=$holder('stop');
verify($end['history_state']==='off' && $end['location_state']==='off' && count($points())===200,
    'stopping live sharing closes history and preserves accessible unexpired points');
$owner('revoke');
verify($rawCount()===0,'disconnect erases this pairing’s retained coordinates');
rejects(403,fn()=>vp3_phone_history_read_v1($db,11,$id),'revoked pairing cannot read the trail');
echo "PASS independent history consent, live-only fixes, revisions, retention, pagination, deletion, expiry and revocation.\n";
