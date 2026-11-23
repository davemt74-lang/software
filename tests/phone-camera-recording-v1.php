<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-tracking-chat-v1.php';

function has_permission(string $permission,array $user):bool { return true; }
function url(string $path):string { return '/vp3'.$path; }
function check_record(bool $ok,string $reason):void { if (!$ok) throw new LogicException($reason); }
function reject_record(callable $run,int $code):void {
    try {$run();} catch(RuntimeException $err) { if ($err->getCode()===$code) return; throw $err; }
    throw new LogicException('Expected rejection '.$code);
}
define('STONEFELLOW_ROOT',sys_get_temp_dir().'/phone-video-'.bin2hex(random_bytes(8)));
register_shutdown_function(static function() {
    if (!is_dir(STONEFELLOW_ROOT)) return;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(STONEFELLOW_ROOT,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir(STONEFELLOW_ROOT);
});
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("PRAGMA foreign_keys=ON;
    CREATE TABLE users(id INTEGER PRIMARY KEY,display_name TEXT,is_active INTEGER);
    INSERT INTO users VALUES(1,'Owner',1),(2,'Other owner',1);
    CREATE TABLE chat_conversations(id INTEGER PRIMARY KEY,user_id INTEGER,user_agent_id INTEGER,updated_at TEXT);
    INSERT INTO chat_conversations VALUES(8,1,NULL,NULL),(9,2,NULL,NULL),(10,1,NULL,NULL);
    CREATE TABLE chat_messages(id INTEGER PRIMARY KEY,user_id INTEGER,conversation_id INTEGER,role TEXT,message TEXT,context_json TEXT);
    CREATE TABLE user_media_assets(id INTEGER PRIMARY KEY,user_id INTEGER,media_type TEXT,mime_type TEXT,title TEXT,file_path TEXT,file_size INTEGER,
        duration_seconds REAL,source TEXT,created_at TEXT,updated_at TEXT)");
vp3_phone_schema_v1($pdo);vp3_phone_chat_schema_v1($pdo);vp3_phone_record_schema_v1($pdo,'');
$pair=static function()use($pdo):array {
    $invite=vp3_phone_owner_v1($pdo,1,'invite',['request_id'=>bin2hex(random_bytes(16))]);
    $phone=vp3_phone_device_v1($pdo,'','claim',['invite'=>$invite['invite'],'name'=>'Studio camera']);
    vp3_phone_owner_v1($pdo,1,'approve',['device_id'=>$phone['device']['id'],'generation'=>$phone['device']['generation'],
        'request_id'=>bin2hex(random_bytes(16)),'allow_location'=>false]);return $phone;
};
$phone=$pair(); $other=$pair(); $did=$phone['device']['id'];
$cam=vp3_phone_camera_owner_v1($pdo,1,0,8,'request',['device_id'=>$did])['camera']; $session=['session_id'=>$cam['session_id']];
$req=$session+['request_id'=>bin2hex(random_bytes(16))];
$request=fn(array $input)=>vp3_phone_record_owner_v1($pdo,1,0,8,'request',$input)['recording'];
$holder=fn(string $action,array $input)=>vp3_phone_record_holder_v1($pdo,$phone['token'],$action,$input)['recording'];
$submit=static fn(array $input,string $bytes)=>vp3_phone_record_chunk_v1($pdo,$phone['token'],$input,$bytes);
reject_record(fn()=>$request($req),409);
vp3_phone_camera_holder_v1($pdo,$phone['token'],'start',$session);
$r=$request($req);$ids=$session+['recording_id'=>$r['id']];
check_record($r['state']==='requested' && $request($req)===$r,'Request replay');
reject_record(fn()=>$request($session+['request_id'=>bin2hex(random_bytes(16))]),409);
reject_record(fn()=>vp3_phone_record_owner_v1($pdo,2,0,9,'status',$session),404);
reject_record(fn()=>vp3_phone_record_owner_v1($pdo,1,0,10,'status',$session),404);
reject_record(fn()=>vp3_phone_record_holder_v1($pdo,$other['token'],'accept',$ids),403);
reject_record(fn()=>$holder('accept',$ids+['owner_user_id'=>1]),422);
$clip=file_get_contents(__DIR__.'/fixtures/phone-camera-recording-vp8.webm');
$clip.="\xec\x28\x00\x00".str_repeat("\0",524288);
$chunkSize=VP3_PHONE_RECORD_CHUNK_V1;
$input=$ids+['total_bytes'=>(string)strlen($clip),'mime_type'=>'video/webm'];
$first=substr($clip,0,$chunkSize); $last=substr($clip,$chunkSize);
$accepted=$holder('accept',$ids); $take=['take_id'=>$accepted['take_id']];
$holdStatus=vp3_phone_camera_holder_v1($pdo,$phone['token'],'status',$session)['camera'];
check_record($holdStatus['recording']===$accepted && !array_key_exists('asset_id',$accepted),'Holder projection');
reject_record(fn()=>$submit($input+$take+['index'=>0],$first),409);
$review=$holder('review',$ids+$take); check_record($review['state']==='reviewing','Review');
reject_record(fn()=>$submit($input+$take+['index'=>1],$last),409);
reject_record(fn()=>$submit(array_replace($input,['total_bytes'=>VP3_PHONE_RECORD_MAX_V1+1])+$take+['index'=>0],$first),413);
reject_record(fn()=>$submit(array_replace($input,['mime_type'=>'application/pdf'])+$take+['index'=>0],$first),415);
$one=$submit($input+$take+['index'=>0],$first);
check_record($one['next_index']===1 && $submit($input+$take+['index'=>0],$first)===$one,'Chunk replay');
reject_record(fn()=>$submit($input+$take+['index'=>0],str_repeat('X',$chunkSize)),409);
$again=$holder('accept',$ids+$take);$newTake=['take_id'=>$again['take_id']];
check_record($again['take_id']!==$accepted['take_id'] && $holder('accept',$ids+$take)===$again
    && $pdo->query('SELECT COUNT(*) FROM vp3_phone_record_chunks_v1')->fetchColumn()===0,'Re-record resets staged bytes once');
$holder('review',$ids+$newTake);
reject_record(fn()=>$submit($input+$take+['index'=>0],$first),409);
$fake='not a video'; $bad=array_replace($input,['total_bytes'=>strlen($fake)])+$newTake+['index'=>0];
reject_record(fn()=>$submit($bad,$fake),422);
check_record($pdo->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn()===0,'Invalid media never creates an asset.');
$submit($input+$newTake+['index'=>0],$first);
$pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON chat_messages BEGIN SELECT RAISE(ABORT,'fixture'); END");
try {$submit($input+$newTake+['index'=>1],$last); throw new LogicException('Expected receipt write failure');} catch (PDOException) {}
check_record(!$pdo->query('SELECT COUNT(*) FROM user_media_assets')->fetchColumn() && !glob(media_studio_user_root(1).'/*'),'Receipt failure rolls back final file and asset');
$pdo->exec('DROP TRIGGER fail_receipt');
$done=$submit($input+$newTake+['index'=>1],$last);
check_record($done['recording']['state']==='submitted' && !isset($done['recording']['asset_id'],$done['recording']['file_url'])
    && $submit($input+$newTake+['index'=>1],$last)===$done && $holder('review',$ids+$newTake)===$done['recording'],'Lost final response safely recovered');
$receipt=vp3_phone_record_owner_v1($pdo,1,0,8,'status',$ids)['recording'];
check_record($receipt['file_url']==='/vp3/media-library-file-v86.php?id='.$receipt['asset_id'] && !isset($receipt['take_id']),'Only owner receives media address');
$asset=$pdo->query('SELECT * FROM user_media_assets')->fetch(PDO::FETCH_ASSOC);$path=STONEFELLOW_ROOT.'/'.$asset['file_path'];
check_record((int)$asset['user_id']===1 && $asset['mime_type']==='video/webm' && file_get_contents($path)===$clip
    && !$pdo->query('SELECT COUNT(*) FROM vp3_phone_record_chunks_v1')->fetchColumn(),'Original bytes, private user ownership, staging cleanup');
$raw=$pdo->query('SELECT message,context_json FROM chat_messages')->fetchAll(PDO::FETCH_ASSOC);
check_record($raw===[['message'=>'Private phone tracking update.','context_json'=>'{"source":"private_phone_tracking"}']],'No clip or title in shared message storage');
check_record(vp3_phone_record_pending_v1($pdo,1,0,8) && !vp3_phone_record_pending_v1($pdo,2,0,9),'Announcement scoping');
$updates=vp3_phone_record_announcements_v1($pdo,1,0,8);
check_record(count($updates)===1 && $updates[0]['actions'][0]['url']===$receipt['file_url'] && !vp3_phone_record_pending_v1($pdo,1,0,8),'One owner announcement including authenticated link');
$new=$request($session+['request_id'=>bin2hex(random_bytes(16))]);
$newIds=array_replace($ids,['recording_id'=>$new['id']]);
$newTake=['take_id'=>$holder('accept',$newIds)['take_id']];$holder('review',$newIds+$newTake);
$body=array_replace($input,$newIds)+$newTake;
$submit($body+['index'=>0],$first);
vp3_phone_camera_holder_v1($pdo,$phone['token'],'stop',$session);
check_record($pdo->query('SELECT COUNT(*) FROM vp3_phone_record_chunks_v1')->fetchColumn()===0,'Camera stop purges partial clips');
reject_record(fn()=>$submit($body+['index'=>1],$last),409);
$pdo->exec("UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=".$pdo->quote($did));
reject_record(fn()=>$holder('review',$ids+$take),403);
$pdo->exec('DELETE FROM vp3_phone_camera_sessions_v1');
$saved=$pdo->query('SELECT * FROM vp3_phone_chat_turns_v1')->fetch(PDO::FETCH_ASSOC);
check_record($saved['map_link']==='camera_clip' && vp3_phone_chat_actions_v1($saved['map_link'],0,8,$saved['map_device_id'])[0]['url']===$receipt['file_url']
    && is_file($path),'Owner keeps the private clip and durable Chat action after grant and session cleanup');
echo "PASS phone camera recording scope, consent, retry, chunk cleanup, format validation, private asset and durable receipt.\n";
