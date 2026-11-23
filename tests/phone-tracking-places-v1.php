<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-v1.php';
require_once dirname(__DIR__).'/includes/permissions.php';

function db(): PDO { global $db; return $db; }
function places_test(bool $pass,string $message): void {
    if (!$pass) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function places_denied(int $status,callable $operation,string $message): void {
    try { $operation(); } catch (RuntimeException $e) {
        places_test($status === $e->getCode(),$message.' ('.$e->getCode().')');
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}
$db = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER,role TEXT DEFAULT 'artist')");
$db->exec("INSERT INTO users(id,display_name,is_active) VALUES(11,'Owner A',1),(12,'Owner B',1)");
$db->exec('CREATE TABLE permissions(permission_key TEXT PRIMARY KEY)');
$db->exec('CREATE TABLE role_permissions(role TEXT,permission_key TEXT)');
$db->exec("INSERT INTO role_permissions VALUES('artist','account.access'),('artist','chat.access')");
vp3_phone_schema_v1($db);
vp3_phone_schema_v1($db);
places_test(vp3_phone_places_ready_v1($db),'places schema installs and upgrades idempotently');
$request = fn() => bin2hex(random_bytes(16));
$pair = function(int $owner,string $name,string $role='guest') use($db,$request): array {
    $invite=vp3_phone_owner_v1($db,$owner,'invite',['request_id'=>$request()]);
    $claim=vp3_phone_device_v1($db,'','claim',['invite'=>$invite['invite'],'name'=>$name]);
    $device=$claim['device'];
    $device=vp3_phone_owner_v1($db,$owner,'approve',['device_id'=>$device['id'],'request_id'=>$request(),'generation'=>$device['generation'],'role'=>$role])['device'];
    return [$claim['token'],$device];
};
[$token,$phone]=$pair(11,'First phone');
[$peerToken,$peer]=$pair(11,'Second phone','owner');
[$otherToken,$other]=$pair(12,'First phone');
$holder=fn(string $action,array $extra=[],?int $generation=null) => vp3_phone_device_v1($db,$token,$action,$extra+[
    'device_id'=>$phone['id'],'generation'=>$generation ?? $phone['generation']])['device'];
$places=fn(string $action,array $input=[],int $owner=11) => vp3_phone_places_owner_v1($db,$owner,$action,$input);
$waiting=$holder('start');
$g=$waiting['generation'];
$fix=['fix_at'=>time(),'latitude'=>33.45,'longitude'=>-112.07,'accuracy'=>12.5];
$live=$holder('fix',$fix,$g);
$save=['device_id'=>$phone['id'],'generation'=>$g,'request_id'=>$request(),'name'=>'  Studio  '];
places_test(!$live['allow_saved_places'] && $places('places')['places']===[],'a live guest starts with no retained place permission');
places_denied(403,fn()=>$places('save_place',$save),'fresh live sharing alone cannot create a place');
places_denied(403,fn()=>vp3_phone_device_v1($db,$peerToken,'set_place_permission',['device_id'=>$phone['id'],'generation'=>$g,'place_permission_version'=>1,'allow_saved_places'=>true]),'a different holder cannot grant place creation');
$permission=['place_permission_version'=>$live['place_permission_version'],'allow_saved_places'=>true];
$consent=$holder('set_place_permission',$permission,$g);
places_test($consent['allow_saved_places'] && $consent['place_permission_version']===$live['place_permission_version']+1,'holder can opt in during the current generation');
$again=$holder('set_place_permission',$permission,$g);
places_test($again['place_permission_version']===$consent['place_permission_version'],'retrying a permission choice is idempotent');
places_denied(422,fn()=>$holder('set_place_permission',['place_permission_version'=>$consent['place_permission_version'],'allow_saved_places'=>'yes'],$g),'a nonboolean holder choice is rejected');
places_denied(404,fn()=>$places('save_place',$save,12),'another account cannot save a paired phone location');
places_denied(422,fn()=>$places('save_place',array_replace($save,['name'=>"one\ntwo"])),'control characters cannot enter place names');
places_denied(422,fn()=>$places('save_place',array_replace($save,['name'=>str_repeat('x',81)])),'place names have a server-side limit');

$db->beginTransaction();
$first=$places('save_place',$save+['latitude'=>90,'longitude'=>0]);
$db->rollBack();
places_test($places('places')['places']===[],'a rolled-back Chat command leaves neither the place nor its receipt');
$saved=$places('save_place',$save+['latitude'=>90,'longitude'=>0]);
$place=$saved['place'];
places_test(!$saved['duplicate'] && $place['name']==='Studio' && $place['latitude']===33.45 && $place['longitude']===-112.07 && $place['accuracy']===12.5,
    'a saved place copies the fresh server-owned fix and normalizes its name');
places_test($places('save_place',$save)['duplicate'] && $places('save_place',$save)['place']===$place,'retrying a save returns the same place');
places_denied(409,fn()=>$places('save_place',array_replace($save,['name'=>'Elsewhere'])),'a request ID cannot be redirected to another place');
places_denied(409,fn()=>$places('save_place',array_replace($save,['request_id'=>$request(),'name'=>'STUDIO'])),'case-equivalent names cannot overwrite an existing place');
places_test($places('places')['places']===[$place] && $places('places',[],12)['places']===[],'a saved place is visible only to its account');
places_test(!isset(vp3_phone_device_v1($db,$otherToken,'status',[])['places']),'holder responses omit the owner’s saved places');
$otherInput=['device_id'=>$other['id'],'generation'=>$other['generation']];
$otherLive=vp3_phone_device_v1($db,$otherToken,'start',$otherInput)['device'];
$otherInput['generation']=$otherLive['generation'];
vp3_phone_device_v1($db,$otherToken,'fix',$otherInput+array_replace($fix,['latitude'=>42]));
vp3_phone_device_v1($db,$otherToken,'set_place_permission',$otherInput+['place_permission_version'=>1,'allow_saved_places'=>true]);
$otherPlace=$places('save_place',$otherInput+['name'=>'Studio','request_id'=>$save['request_id']],12)['place'];
places_test($otherPlace['id']!==$place['id'] && $otherPlace['latitude']===42.0 && $places('places',[],12)['places']===[$otherPlace],
    'two accounts can use an identical name and request identifier without sharing a place');
$two=$places('save_place',array_replace($save,['name'=>'Second','request_id'=>$request()]))['place'];
vp3_phone_sql_v1($db,'UPDATE vp3_phone_places_v1 SET created_at=? WHERE id=? AND owner_user_id=?',[$place['created_at']+1,$two['id'],11]);
places_test(array_column($places('places')['places'],'id')===[$two['id'],$place['id']],'places are listed newest first');
$places('delete_place',['place_id'=>$two['id'],'request_id'=>$request()]);
$db->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=? WHERE id=?')->execute([time()-1,$phone['id']]);
places_denied(403,fn()=>$places('save_place',array_replace($save,['name'=>'Expired','request_id'=>$request()])),'expiry invalidates the active holder permission');
$db->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=? WHERE id=?')->execute([time()+600,$phone['id']]);
$off=$holder('set_place_permission',['allow_saved_places'=>false,'place_permission_version'=>$consent['place_permission_version']],$g);
places_denied(409,fn()=>$holder('set_place_permission',$permission,$g),'late enable cannot revive a withdrawn grant');
places_denied(403,fn()=>$places('save_place',array_replace($save,['name'=>'After withdrawal','request_id'=>$request()])),'a withdrawal takes effect before the next place save');
$on=$holder('set_place_permission',['allow_saved_places'=>true,'place_permission_version'=>$off['place_permission_version']],$g);
vp3_phone_sql_v1($db,'UPDATE vp3_phone_devices_v1 SET fix_at=? WHERE id=?',[time()-60,$phone['id']]);
places_denied(409,fn()=>$places('save_place',array_replace($save,['name'=>'Old position','request_id'=>$request()])),'stale coordinates cannot become a named place');
$off=$holder('stop',[],$g);
places_test(!$off['allow_saved_places'] && $places('places')['places']===[$place],'stopping live sharing ends place permission and preserves an explicitly saved place');
$restarted=$holder('start',[],$off['generation']);
places_test(!$restarted['allow_saved_places'],'a new live session requires a new saved places choice');
places_denied(409,fn()=>$holder('set_place_permission',['allow_saved_places'=>true,'place_permission_version'=>$on['place_permission_version']],$g),'the previous session cannot re-enable saved places');
vp3_phone_owner_v1($db,11,'revoke',['device_id'=>$phone['id'],'generation'=>$restarted['generation'],'request_id'=>$request()]);
vp3_phone_sql_v1($db,'DELETE FROM vp3_phone_devices_v1 WHERE owner_user_id=? AND id=?',[11,$phone['id']]);
places_test($places('places')['places']===[$place],'deleting a revoked source phone does not delete its saved place');
$drop=['place_id'=>$place['id'],'request_id'=>$request()];
places_denied(404,fn()=>$places('delete_place',$drop,12),'another account cannot delete this place');
places_test($places('delete_place',$drop)===['deleted'=>true,'duplicate'=>false] && $places('delete_place',$drop)['duplicate'],'deletion and retry remove exactly the selected place');
places_denied(409,fn()=>$places('save_place',$save),'replaying creation after deletion cannot resurrect a place');
places_test($places('places')['places']===[],'permanent deletion is reflected on a fresh read');
places_test($places('places',[],12)['places']===[$otherPlace],'deleting the first account’s place preserves the second account’s namesake');
$db->exec('UPDATE users SET is_active=0 WHERE id=11');
places_denied(403,fn()=>$places('places'),'disabled owners cannot read places or retry stale operations');
