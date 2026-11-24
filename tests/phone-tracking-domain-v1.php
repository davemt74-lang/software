<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/permissions.php';

function db(): PDO
{
    global $db;
    return $db;
}

function pt_assert(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
    echo "ok - $label\n";
}

function pt_reject(int $code, callable $call, string $label): void
{
    try { $call(); } catch (RuntimeException $e) {
        pt_assert($e->getCode() === $code, $label.' ('.$e->getCode().')');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, display_name TEXT,is_active INTEGER,role TEXT DEFAULT 'artist')");
$db->exec("INSERT INTO users(id,display_name,is_active) VALUES (11,'Receiver',1),(12,'Other receiver',1),(13,'Disabled',0)");
$db->exec('CREATE TABLE permissions(permission_key TEXT PRIMARY KEY)');
$db->exec('CREATE TABLE role_permissions(role TEXT,permission_key TEXT)');
$db->exec("INSERT INTO role_permissions VALUES ('artist','account.access'),('artist','chat.access')");
pt_assert(!vp3_phone_schema_ready_v1($db),'missing migration recognized');
vp3_phone_schema_v1($db);
vp3_phone_schema_v1($db);
pt_assert(vp3_phone_schema_ready_v1($db),'schema upgrade repeatable');
$owner = fn(string $op, array $body=[],int $id=11) => vp3_phone_owner_v1($db,$id,$op,$body);
$issue = fn(int $user=11) => $owner('invite',['request_id'=>bin2hex(random_bytes(16))],$user)['invite'];
$inviteReq = ['request_id'=>bin2hex(random_bytes(16))];
$invite = $owner('invite',$inviteReq);
pt_assert(!empty($invite['invite']) && $invite['expires_at'] >= time()+295 && $invite['expires_at'] <= time()+300,'five-minute one-use invitation');
$duplicate = $owner('invite',$inviteReq);
pt_assert($duplicate['duplicate'] && !isset($duplicate['invite']),'invite retry cannot issue or recover a second bearer');
$claim = ['invite'=>$invite['invite'],'name'=>'My iPhone','claim_key'=>bin2hex(random_bytes(32))];
$device = vp3_phone_device_v1($db,'','claim',$claim);
$id = $device['device']['id']; $token = $device['token'];
pt_assert($device['owner_name'] === 'Receiver' && $device['device']['status'] === 'pending','named pending phone identifies the sharing recipient');
pt_assert(strlen($token)===43 && strlen($device['device']['id'])===32,'opaque credential and separate stable device ID');
$recovered = vp3_phone_device_v1($db,'','claim',$claim);
pt_assert($recovered['token'] === $token && $recovered['device']['id'] === $id,'lost claim acknowledgement recovers only the original named device');
pt_reject(403,fn()=>vp3_phone_device_v1($db,'','claim',array_replace($claim,['claim_key'=>bin2hex(random_bytes(32))])),'another scanner cannot redeem used invite');
$deviceState = fn():array => vp3_phone_device_v1($db,$token,'status',[])['device'];
$deviceOp = fn(string $op,int $generation,array $body=[]) => vp3_phone_device_v1($db,$token,$op,$body+['generation'=>$generation,'device_id'=>$id])['device'];
$newCommand = fn(int $g) => ['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$id,'generation'=>$g];
pt_assert(count($owner('state')['devices'])===1 && $owner('state',[],12)['devices']===[],'pending badge belongs to the issuing account only');
pt_reject(404,fn()=>$owner('approve',$newCommand(1),12),'second account cannot approve another owner’s phone');
pt_reject(403,fn()=>$deviceOp('start',1),'pending phone cannot start GPS');
$approve=$newCommand(1)+['role'=>'owner'];
$accepted=$owner('approve',$approve)['device'];
require_once __DIR__.'/phone-communication-profile-cases-v1.php';
vp3_test_phone_communication_v1($db,11,12,$id,$token,'pt_assert');
require_once __DIR__.'/phone-information-exchange-cases-v1.php';
vp3_test_phone_information_v1($db,11,12,$id,$token,'pt_assert');
require_once __DIR__.'/phone-information-file-cases-v1.php';
vp3_test_phone_files_v1($db,11,12,$id,$token,'pt_assert');
require_once __DIR__.'/phone-remote-dialer-cases-v1.php';
vp3_test_phone_dialer_v1($db,11,12,$id,$token,'pt_assert');
pt_assert($accepted['status']==='approved' && $accepted['role']==='owner' && $accepted['location_state']==='off' && $accepted['latitude']===null,'owner approval does not grant live location');
pt_assert($accepted['preferences_version']===1 && $accepted['update_interval_seconds']===5 && $accepted['accuracy_mode']==='high',
    'newly paired phones report the original five-second, high-accuracy defaults');
$prefs = $newCommand($accepted['generation'])+['preferences_version'=>1,'update_interval_seconds'=>15,'accuracy_mode'=>'balanced'];
pt_reject(404,fn()=>$owner('set_preferences',$prefs,12),'another account cannot set phone preferences');
pt_reject(422,fn()=>$deviceOp('set_preferences',$accepted['generation'],$prefs),'holder credential cannot edit owner preferences');
foreach ([['update_interval_seconds'=>'15'],['update_interval_seconds'=>7],['accuracy_mode'=>'precise'],['preferences_version'=>0]] as $invalid) {
    pt_reject(422,fn()=>$owner('set_preferences',array_replace($prefs,$invalid)),'invalid preference is rejected');
}
$configured=$owner('set_preferences',$prefs)['device'];
pt_assert($configured['preferences_version']===2 && $configured['location_state']==='off' && $configured['generation']===$accepted['generation']
    && $deviceState()['update_interval_seconds']===15 && $deviceState()['accuracy_mode']==='balanced','owner settings reach the holder without starting sharing');
pt_assert($owner('set_preferences',$prefs)['duplicate'],'retry of a settings save has one effect');
pt_reject(409,fn()=>$owner('set_preferences',array_replace($prefs,['accuracy_mode'=>'high'])),'operation ID cannot be reused with different settings');
pt_reject(409,fn()=>$owner('set_preferences',array_replace($prefs,['request_id'=>bin2hex(random_bytes(16))])),
    'a stale settings editor cannot overwrite a newer edit');
$same=$newCommand($configured['generation'])+['preferences_version'=>2,'update_interval_seconds'=>15,'accuracy_mode'=>'balanced'];
pt_assert($owner('set_preferences',$same)['device']['preferences_version']===2,'saving the same preferences keeps the version');
pt_assert($owner('approve',$approve)['duplicate'],'duplicate approve is idempotent');
pt_reject(409,fn()=>$owner('deny',$approve),'same request ID cannot authorize a different action');
$startCommand=$newCommand($accepted['generation']);
$requested=$owner('request_start',$startCommand)['device'];
pt_assert($requested['location_state']==='requested' && $deviceState()['location_state']==='requested','agent request reaches phone without beginning capture');
$fix=['latitude'=>33.45,'longitude'=>-112.07,'accuracy'=>11.2,'fix_at'=>time()];
pt_reject(409,fn()=>$deviceOp('fix',$requested['generation'],$fix),'request alone cannot upload GPS');
$waiting=$deviceOp('start',$requested['generation']); $g=$waiting['generation'];
pt_assert($waiting['location_state']==='waiting' && $waiting['first_fix_at']===null && $g===$requested['generation'],'holder acceptance waits for a fresh reading');
pt_reject(422,fn()=>$deviceOp('fix',$g,array_replace($fix,['accuracy'=>-1])),'negative GPS accuracy denied');
pt_reject(422,fn()=>$deviceOp('fix',$g,array_replace($fix,['latitude'=>92])),'out-of-range coordinates denied');
pt_reject(422,fn()=>$deviceOp('fix',$g,array_replace($fix,['longitude'=>'0e05'])),'non-numeric field type denied');
pt_reject(422,fn()=>$deviceOp('fix',$g,array_replace($fix,['fix_at'=>time()-90])),'old cached fix cannot activate marker');
pt_reject(422,fn()=>$deviceOp('fix',$g,array_replace($fix,['fix_at'=>time()+121])),'future fix cannot keep a phone live');
$fix['fix_at']=time();
pt_reject(403,fn()=>$deviceOp('fix',$g,$fix+['device_id'=>str_repeat('e',32)]),'another paired-device cookie cannot consume an old tab’s request');
$live=$deviceOp('fix',$g,$fix);
pt_assert($live['location_state']==='live' && abs($live['latitude']-33.45)<1e-8 && $live['first_fix_at']===$fix['fix_at'],'first verified fix creates live marker');
$duringShare=$newCommand($g)+['preferences_version'=>2,'update_interval_seconds'=>30,'accuracy_mode'=>'high'];
$updated=$owner('set_preferences',$duringShare)['device'];
pt_assert($updated['preferences_version']===3 && $updated['generation']===$g && $updated['first_fix_at']===$live['first_fix_at']
    && $updated['location_state']==='live' && $deviceState()['latitude']===$live['latitude'],'changing live preferences preserves the current grant and first fix');
pt_assert($owner('set_preferences',$prefs)['device']['update_interval_seconds']===30,'replaying an older save does not undo newer settings');
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET expires_at=?,last_seen_at=? WHERE id=?',[time()+60,time(),$id]);
$retransmit=$deviceOp('fix',$g,$fix);
pt_assert($retransmit['first_fix_at']===$live['first_fix_at'] && $retransmit['generation']===$g,'retransmitted GPS fix does not reopen or renew the first-fix milestone');
pt_assert($retransmit['expires_at']>time()+86400*29,'frequent authenticated fixes renew the idle session');
pt_assert($owner('state',[],12)['devices']===[],'second account cannot see live location');
$db->exec("UPDATE vp3_phone_devices_v1 SET fix_at=fix_at-61,first_fix_at=first_fix_at-61");
pt_assert($deviceState()['location_state']==='stale','a fresh session heartbeat cannot make suspended GPS live');
$stopCommand=$newCommand($g); $stopped=$owner('stop',$stopCommand)['device'];
pt_assert($stopped['status']==='approved' && $stopped['location_state']==='off' && $stopped['latitude']===null && $stopped['first_fix_at']===null,'offline owner stop preserves pairing and removes live data');
pt_assert($db->query('SELECT latitude IS NULL AND longitude IS NULL AND accuracy IS NULL AND fix_at IS NULL FROM vp3_phone_devices_v1')->fetchColumn()==1,'stop erases raw coordinates from storage');
pt_reject(409,fn()=>$deviceOp('fix',$g,$fix),'queued fix rejected after owner stop');
pt_assert($owner('request_start',$startCommand)['device']['location_state']==='off','replay of an earlier command cannot restart consent');
$waiting=$deviceOp('start',$stopped['generation']);
pt_assert($owner('stop',$stopCommand)['device']['location_state']==='waiting','retry of an old stop cannot end a newer explicit grant');
$denied=$deviceOp('denied',$waiting['generation']);
pt_assert($denied['location_state']==='denied' && $denied['latitude']===null,'browser denial is visible and clears current location');
$waiting=$deviceOp('start',$denied['generation']);
$stopped=$deviceOp('stop',$waiting['generation']);
pt_assert($stopped['location_state']==='off','local phone stop closes live grant');
$credentials=$db->query('SELECT invite_hash FROM vp3_phone_invites_v1')->fetchColumn();
$registered=$db->query('SELECT token_hash FROM vp3_phone_devices_v1')->fetchColumn();
pt_assert($credentials===hash('sha256',$invite['invite']) && $registered===hash('sha256',$token),'stored credentials are one-way hashes');
pt_assert(!str_contains(json_encode($owner('state')), $token),'owner projection contains no phone credential');
$db->exec('UPDATE users SET is_active=0 WHERE id=11');
pt_reject(403,fn()=>$deviceState(),'disable account blocks existing phone session');
$db->exec('UPDATE users SET is_active=1 WHERE id=11');
$expired=$issue(12);
vp3_phone_sql_v1($db,'UPDATE vp3_phone_invites_v1 SET expires_at=? WHERE invite_hash=?',[time(),hash('sha256',$expired)]);
pt_reject(403,fn()=>vp3_phone_device_v1($db,'','claim',['invite'=>$expired,'name'=>'Late']),'expired invitation is unusable');
$guest=vp3_phone_device_v1($db,'','claim',['invite'=>$issue(),'name'=>'Connected only']);
$gid=$guest['device']['id']; $guestCommand = fn(int $g) => ['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$gid,'generation'=>$g];
$guestA=$owner('approve',$guestCommand(1)+['allow_location'=>false])['device'];
pt_assert($guestA['accuracy_mode']==='high' && $guestA['update_interval_seconds']===5 && $deviceState()['update_interval_seconds']===30,
    'each phone retains its own reporting preferences');
pt_reject(403,fn()=>$owner('set_preferences',$guestCommand($guestA['generation'])+
    ['preferences_version'=>1,'update_interval_seconds'=>15,'accuracy_mode'=>'balanced']),'changing settings cannot bypass a disabled location capability');
pt_reject(403,fn()=>vp3_phone_device_v1($db,$guest['token'],'start',['device_id'=>$gid,'generation'=>$guestA['generation']]),'paired GPS-disabled device cannot grant itself access');
pt_reject(403,fn()=>$owner('request_start',$guestCommand($guestA['generation'])),'owner request respects disabled location capability');
$bye=vp3_phone_device_v1($db,$guest['token'],'disconnect',['device_id'=>$gid,'generation'=>$guestA['generation']]);
pt_assert($bye['device']['status']==='revoked','holder can disconnect without GPS access');
pt_reject(403,fn()=>vp3_phone_device_v1($db,$guest['token'],'status',[]),'disconnected credential fails closed');
$late=vp3_phone_device_v1($db,'','claim',['invite'=>$issue(12),'name'=>'Waiting phone']);
$declined=$owner('deny',['request_id'=>bin2hex(random_bytes(16)),'device_id'=>$late['device']['id'],'generation'=>1],12);
pt_assert($declined['device']['status']==='denied' && $owner('state',[],12)['devices']===[],'denial removes pending device');
pt_reject(403,fn()=>vp3_phone_device_v1($db,$late['token'],'status',[]),'denied phone has no active session');
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET expires_at=?,last_seen_at=? WHERE id=?',[time()+1000,time()-50,$id]);
pt_assert($deviceState()['expires_at']>time()+86400*29,'verified heartbeat renews an active device session');
$hardLimit=time()+3600;
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET created_at=?,last_seen_at=? WHERE id=?',[$hardLimit-VP3_PHONE_MAX_DAYS_V1*86400,time()-50,$id]);
pt_assert($deviceState()['expires_at']===$hardLimit,'renewals respect the absolute session lifetime');
$db->beginTransaction();
$owner('revoke',$newCommand($stopped['generation']));
$db->rollBack();
pt_assert($deviceState()['status']==='approved','Chat can roll back owner mutation and receipt atomically');
$ownerSaw=$waiting['generation'];
pt_assert($ownerSaw < $stopped['generation'],'phone stopped after owner last viewed the pairing');
pt_reject(422,fn()=>$owner('revoke',$newCommand(0)),'revocation must reference a positive generation');
pt_reject(409,fn()=>$owner('revoke',$newCommand($stopped['generation']+1)),'revocation rejects a future generation');
pt_reject(409,fn()=>$owner('stop',$newCommand($ownerSaw)),'owner location controls still require the exact generation');
$revokeCommand=$newCommand($ownerSaw);
$revoke=$owner('revoke',$revokeCommand)['device'];
pt_assert((int)$db->query('SELECT COUNT(*) FROM vp3_phone_communication_profiles_v1')->fetchColumn()===0,'disconnect deletes the private communication profile');
pt_assert((int)$db->query('SELECT COUNT(*) FROM vp3_phone_information_v1')->fetchColumn()===0,'disconnect removes dependent private information exchange');
pt_assert((int)$db->query('SELECT COUNT(*) FROM vp3_phone_information_files_v1')->fetchColumn()===0,'disconnect removes dependent file storage');
pt_assert($revoke['status']==='revoked' && $revoke['generation']>$stopped['generation'] && $owner('state')['devices']===[],
    'owner disconnect still removes the same pairing after the holder stops GPS');
pt_assert($owner('revoke',$revokeCommand)['duplicate'],'repeated disconnect is acknowledged once');
pt_reject(403,fn()=>$deviceState(),'revoked credential cannot return to map or upload');
pt_reject(403,fn()=>$deviceOp('fix',$ownerSaw,$fix),'GPS callback from disconnected phone is fenced');
$replacement=vp3_phone_device_v1($db,'','claim',['invite'=>$issue(),'name'=>$claim['name']]);
$otherId=$replacement['device']['id'];
$owner('approve',['device_id'=>$otherId,'request_id'=>bin2hex(random_bytes(16)),'generation'=>1]);
pt_reject(404,fn()=>$owner('revoke',$newCommand($ownerSaw)),'old pairing ID cannot revoke a new pairing with the same device name');
pt_reject(409,fn()=>$owner('revoke',array_replace($revokeCommand,['device_id'=>$otherId])),'a disconnect receipt cannot be redirected to the replacement pairing');
pt_assert($otherId!==$id && $owner('state')['devices'][0]['id']===$otherId,'replacement pairing remains connected');

require_once __DIR__.'/phone-handoff-cases-v1.php';
vp3_test_phone_handoff_v1($db,11,12,'pt_assert');
require_once __DIR__.'/phone-push-cases-v1.php';
vp3_test_phone_push_v1($db,11,12,'pt_assert');
echo "PASS Phone Tracking pairing, sharing, handoff and per-phone preferences.\n";
