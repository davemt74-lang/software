<?php
declare(strict_types=1);

require_once __DIR__.'/media-studio-v86.php';

const VP3_PHONE_RECORD_CHUNK_V1=524288;
const VP3_PHONE_RECORD_MAX_V1=33554432;

function vp3_phone_record_schema_v1(PDO $pdo,string $options): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_camera_recordings_v1 (
        id CHAR(32) PRIMARY KEY, session_id CHAR(32) NOT NULL, request_id CHAR(32) NOT NULL, seq INT NOT NULL,
        state VARCHAR(12) NOT NULL, take_id CHAR(32) NULL, prior_take_id CHAR(32) NULL,
        mime_type VARCHAR(16) NULL, total_bytes INT NOT NULL DEFAULT 0, hashes TEXT NULL,
        asset_id BIGINT UNSIGNED NULL, receipt_id BIGINT UNSIGNED NULL, announced SMALLINT NOT NULL DEFAULT 0,
        UNIQUE(session_id,request_id), UNIQUE(session_id,seq),
        FOREIGN KEY (session_id) REFERENCES vp3_phone_camera_sessions_v1(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_record_chunks_v1 (
        recording_id CHAR(32) NOT NULL, chunk_index INT NOT NULL, contents MEDIUMBLOB NOT NULL,
        PRIMARY KEY(recording_id,chunk_index),
        FOREIGN KEY (recording_id) REFERENCES vp3_phone_camera_recordings_v1(id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_record_ready_v1(PDO $pdo): bool
{
    return schema_metadata_table_exists($pdo,'vp3_phone_camera_recordings_v1') && schema_metadata_table_exists($pdo,'vp3_phone_record_chunks_v1');
}

function vp3_phone_record_max_v1(): int
{
    return min(VP3_PHONE_RECORD_MAX_V1,max(0,media_studio_max_bytes('video')));
}

function vp3_phone_record_view_v1(?array $row,bool $holder): ?array
{
    if (!$row) return null;
    $view=['id'=>$row['id'],'state'=>$row['state'],'max_bytes'=>vp3_phone_record_max_v1(),'chunk_bytes'=>VP3_PHONE_RECORD_CHUNK_V1];
    if ($holder) {
        if ($row['take_id']!==null) $view['take_id']=$row['take_id'];
    } else {
        $view['request_id']=$row['request_id'];
        if ($row['state']==='submitted') {
            $view['asset_id']=(int)$row['asset_id'];
            $view['file_url']=media_studio_asset_url($view['asset_id']);
        }
    }
    return $view;
}

function vp3_phone_record_latest_v1(PDO $pdo,string $sid): ?array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_camera_recordings_v1 WHERE session_id=? ORDER BY seq DESC LIMIT 1',[$sid])->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_phone_record_clean_v1(PDO $pdo,array $row): void
{
    vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_record_chunks_v1 WHERE recording_id=?',[$row['id']]);
    // A worker killed during finalization can leave an unpublished file. Only
    // the unpublished take's server-generated path is ever eligible for cleanup.
    if ($row['take_id'] && $row['state']!=='submitted' && !empty($row['owner_user_id'])) {
        $prefix=media_studio_user_root((int)$row['owner_user_id']).'/phone-'.$row['id'].'-'.$row['take_id'];
        foreach (['.part','.mp4','.webm'] as $ext) {
            if (is_file($prefix.$ext)) unlink($prefix.$ext);
        }
    }
}

/** Call after ending camera grants while holding the same owner lock. */
function vp3_phone_record_expire_v1(PDO $pdo,int $owner): void
{
    if (!vp3_phone_record_ready_v1($pdo)) return;
    $rows=vp3_phone_sql_v1($pdo,"SELECT r.*,s.owner_user_id FROM vp3_phone_camera_recordings_v1 r
        JOIN vp3_phone_camera_sessions_v1 s ON s.id=r.session_id WHERE s.owner_user_id=? AND s.state='stopped'
        AND r.state IN ('requested','accepted','reviewing')",[$owner])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        vp3_phone_record_clean_v1($pdo,$row);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_recordings_v1 SET state='expired',hashes=NULL,total_bytes=0 WHERE id=?",[$row['id']]);
    }
}

function vp3_phone_record_owner_v1(PDO $pdo,int $owner,int $agent,int $cid,string $action,array $input): array
{
    if (!in_array($action,['request','status'],true) || array_diff(array_keys($input),['session_id','device_id',$action==='request'?'request_id':'recording_id']))
        throw new RuntimeException('Invalid recording request.',422);
    $sid=vp3_phone_camera_id_v1($input['session_id']??null);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$owner,$agent,$cid,$action,$input,$sid):array {
        vp3_phone_account_v1($pdo,$owner,true);
        if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) throw new RuntimeException('Camera unavailable.',403);
        if (!vp3_phone_record_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
        $now=time();
        vp3_phone_camera_end_v1($pdo,$owner,vp3_phone_camera_expiry_sql_v1(),vp3_phone_camera_expiry_times_v1($now));
        $session=vp3_phone_sql_v1($pdo,'SELECT s.*,d.device_status,d.expires_at AS device_expires FROM vp3_phone_camera_sessions_v1 s
            JOIN vp3_phone_devices_v1 d ON d.id=s.device_id AND d.owner_user_id=s.owner_user_id
            WHERE s.id=? AND s.owner_user_id=? AND s.agent_id=? AND s.conversation_id=?',[$sid,$owner,$agent,$cid])->fetch(PDO::FETCH_ASSOC);
        if (!$session || (isset($input['device_id']) && vp3_phone_camera_id_v1($input['device_id'])!==$session['device_id']))
            throw new RuntimeException('Camera unavailable.',404);
        $latest=vp3_phone_record_latest_v1($pdo,$sid);
        if ($action==='status') {
            $row=isset($input['recording_id'])?vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_camera_recordings_v1 WHERE session_id=? AND id=?',
                [$sid,vp3_phone_camera_id_v1($input['recording_id'])])->fetch(PDO::FETCH_ASSOC):$latest;
            return ['recording'=>vp3_phone_record_view_v1($row?:null,false)];
        }
        $request=vp3_phone_camera_id_v1($input['request_id']??null);
        $prior=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_camera_recordings_v1 WHERE session_id=? AND request_id=?',[$sid,$request])->fetch(PDO::FETCH_ASSOC);
        if ($prior) return ['recording'=>vp3_phone_record_view_v1($prior,false)];
        if (!schema_metadata_table_exists($pdo,'user_media_assets') || !schema_metadata_table_exists($pdo,'vp3_phone_chat_turns_v1'))
            throw new RuntimeException('Upgrade required.',503);
        if (!in_array($session['state'],['sharing','answered'],true) || $session['device_status']!=='approved' || (int)$session['device_expires']<=$now)
            throw new RuntimeException('Start camera sharing first.',409);
        if ($latest && in_array($latest['state'],['requested','accepted','reviewing'],true)) throw new RuntimeException('Recording already requested.',409);
        $id=bin2hex(random_bytes(16));
        vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_camera_recordings_v1(id,session_id,request_id,seq,state) VALUES(?,?,?,?,'requested')",
            [$id,$sid,$request,(int)($latest['seq']??0)+1]);
        return ['recording'=>vp3_phone_record_view_v1(vp3_phone_record_latest_v1($pdo,$sid),false)];
    });
}

/** Resolve the lock target before starting a MySQL REPEATABLE READ transaction. */
function vp3_phone_record_device_hint_v1(PDO $pdo,string $token): array
{
    if (!vp3_phone_record_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
    if (!vp3_phone_secret_valid_v1($token)) throw new RuntimeException('Phone unavailable.',403);
    $hash=vp3_extension_hash_v2000($token);
    $hint=vp3_phone_sql_v1($pdo,'SELECT owner_user_id,id FROM vp3_phone_devices_v1 WHERE token_hash=?',[$hash])->fetch(PDO::FETCH_ASSOC);
    if (!$hint) throw new RuntimeException('Phone unavailable.',403);
    return $hint+['token_hash'=>$hash];
}

/** The account lock is the first database read before the current device, grant and privacy checks. */
function vp3_phone_record_grant_v1(PDO $pdo,array $hint,array $input): array
{
    $owner=(int)$hint['owner_user_id'];
    vp3_phone_account_v1($pdo,$owner,true);
    $device=vp3_phone_row_v1($pdo,$owner,$hint['id']);
    if (!hash_equals($device['token_hash'],$hint['token_hash']) || $device['device_status']!=='approved' || (int)$device['expires_at']<=time())
        throw new RuntimeException('Phone unavailable.',403);
    $row=vp3_phone_sql_v1($pdo,'SELECT r.*,s.owner_user_id,s.agent_id,s.conversation_id,s.state AS camera_state,s.expires_at,s.holder_seen,s.owner_seen
        FROM vp3_phone_camera_recordings_v1 r JOIN vp3_phone_camera_sessions_v1 s ON s.id=r.session_id
        WHERE s.owner_user_id=? AND s.device_id=? AND s.id=? AND r.id=?'.vp3_phone_lock_v1($pdo),
        [$owner,$device['id'],vp3_phone_camera_id_v1($input['session_id']??null),vp3_phone_camera_id_v1($input['recording_id']??null)])->fetch(PDO::FETCH_ASSOC);
    if (!$row || !vp3_phone_camera_scope_v1($pdo,$owner,(int)$row['agent_id'],(int)$row['conversation_id'])) throw new RuntimeException('Camera unavailable.',403);
    $now=time(); $cutoff=$now-VP3_PHONE_CAMERA_LEASE_SECONDS_V1;
    if ($row['state']!=='submitted' && (!in_array($row['camera_state'],['sharing','answered'],true)
        || (int)$row['expires_at']<=$now || (int)$row['holder_seen']<=$cutoff || ($row['camera_state']==='answered' && (int)$row['owner_seen']<=$cutoff))) {
        throw new RuntimeException('Camera sharing ended.',409);
    }
    return $row+['device_name'=>$device['device_name']];
}

function vp3_phone_record_holder_v1(PDO $pdo,string $token,string $action,array $input): array
{
    if (!in_array($action,['accept','review','decline'],true) || array_diff(array_keys($input),['recording_id','session_id','take_id']))
        throw new RuntimeException('Invalid recording action.',422);
    $hint=vp3_phone_record_device_hint_v1($pdo,$token);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$hint,$action,$input):array {
        $row=vp3_phone_record_grant_v1($pdo,$hint,$input);
        $state=$row['state']; $take=$input['take_id']??null;
        if ($take!==null) $take=vp3_phone_camera_id_v1($take);
        if ($action==='accept') {
            if ($state==='accepted' && ($take===null || $take===$row['prior_take_id'])) return ['recording'=>vp3_phone_record_view_v1($row,true)];
            if (!(($state==='requested' && $take===null) || ($state==='reviewing' && $take===$row['take_id']))) throw new RuntimeException('Recording changed.',409);
            vp3_phone_record_clean_v1($pdo,$row);
            $row['take_id']=bin2hex(random_bytes(16)); $row['state']='accepted';
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_recordings_v1 SET take_id=?,prior_take_id=?,state='accepted',
                total_bytes=0,mime_type=NULL,hashes=NULL WHERE id=?",[$row['take_id'],$take,$row['id']]);
        } elseif ($action==='review') {
            if ($state==='submitted' && $take!==null && $take===$row['take_id']) return ['recording'=>vp3_phone_record_view_v1($row,true)];
            if ($take===null || $take!==$row['take_id'] || !in_array($state,['accepted','reviewing'],true)) throw new RuntimeException('Recording changed.',409);
            $row['state']='reviewing'; vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_recordings_v1 SET state='reviewing' WHERE id=?",[$row['id']]);
        } else {
            if (!in_array($state,['requested','accepted','reviewing','declined'],true) || ($take!==null && $take!==$row['take_id'])) throw new RuntimeException('Recording changed.',409);
            vp3_phone_record_clean_v1($pdo,$row);
            $row['state']='declined'; vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_recordings_v1 SET state='declined',total_bytes=0,hashes=NULL WHERE id=?",[$row['id']]);
        }
        return ['recording'=>vp3_phone_record_view_v1($row,true)];
    });
}

/** Publish media and the private Chat receipt in the same transaction as the final chunk. */
function vp3_phone_record_publish_v1(PDO $pdo,array $row,int $parts,int $size,string $mime): array
{
    require_once __DIR__.'/phone-recording-media-v1.php';
    require_once __DIR__.'/phone-tracking-chat-v1.php';
    $owner=(int)$row['owner_user_id'];
    $root=media_studio_ensure_user_root($owner);
    $base='phone-'.$row['id'].'-'.$row['take_id'];
    $filename=$base.'.'.media_studio_extension_for_mime($mime);
    $part=$root.'/'.$base.'.part';
    $out=fopen($part,'wb');
    if (!$out) throw new RuntimeException('Private video storage unavailable.',503);
    try {
        chmod($part,0600);
        for ($i=0;$i<$parts;$i++) {
            $chunk=vp3_phone_sql_v1($pdo,'SELECT contents FROM vp3_phone_record_chunks_v1 WHERE recording_id=? AND chunk_index=?',
                [$row['id'],$i])->fetchColumn();
            if (!is_string($chunk) || fwrite($out,$chunk)!==strlen($chunk)) throw new RuntimeException('Incomplete video upload.',503);
        }
        if (ftell($out)!==$size || !fflush($out)) throw new RuntimeException('Incomplete video upload.',503);
        fclose($out); $out=null;
        vp3_phone_record_validate_media_v1($part,$mime);
        $title=vp3_phone_chat_label_v1(['name'=>$row['device_name']]).' camera recording';
        // Use the library's schema and private storage; holder submissions must never run owner-side tools or memory writes.
        vp3_phone_sql_v1($pdo,"INSERT INTO user_media_assets(user_id,media_type,title,mime_type,file_path,file_size,duration_seconds,source,created_at,updated_at)
            VALUES (?,'video',?,?,?,?,0,'phone_camera',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)",[$owner,$title,$mime,'private/user-media/'.$owner.'/'.$filename,$size]);
        $asset=(int)$pdo->lastInsertId();
        $receipt=vp3_phone_chat_store_v1($pdo,$owner,(int)$row['agent_id'],(int)$row['conversation_id'],'',
            vp3_phone_chat_label_v1(['name'=>$row['device_name']]).' submitted a camera recording.','camera_clip',null,null,(string)$asset);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_recordings_v1 SET state='submitted',asset_id=?,receipt_id=? WHERE id=?",
            [$asset,$receipt['assistant_message_id'],$row['id']]);
        vp3_phone_sql_v1($pdo,'DELETE FROM vp3_phone_record_chunks_v1 WHERE recording_id=?',[$row['id']]);
        if (!rename($part,$root.'/'.$filename)) throw new RuntimeException('Could not publish private video.',503);
    } finally {
        if (is_resource($out)) fclose($out);
        if (is_file($part)) unlink($part);
    }
    $row['state']='submitted'; $row['asset_id']=$asset;
    return $row;
}

function vp3_phone_record_chunk_v1(PDO $pdo,string $token,array $input,string $chunk): array
{
    if (array_diff(array_keys($input),['session_id','recording_id','take_id','index','total_bytes','mime_type']))
        throw new RuntimeException('Invalid recording fields.',422);
    $take=vp3_phone_camera_id_v1($input['take_id']??null);
    $mime=$input['mime_type']??null;
    if (!in_array($mime,['video/webm','video/mp4'],true)) throw new RuntimeException('Unsupported recording format.',415);
    $index=filter_var($input['index']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    $size=filter_var($input['total_bytes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($size===false || $size>vp3_phone_record_max_v1()) throw new RuntimeException('Invalid recording size.',413);
    $parts=intdiv($size+VP3_PHONE_RECORD_CHUNK_V1-1,VP3_PHONE_RECORD_CHUNK_V1);
    if ($index===false || $index>=$parts || strlen($chunk)!==min(VP3_PHONE_RECORD_CHUNK_V1,$size-$index*VP3_PHONE_RECORD_CHUNK_V1))
        throw new RuntimeException('Invalid recording chunk.',422);
    $hint=vp3_phone_record_device_hint_v1($pdo,$token);
    return vp3_phone_transaction_v1($pdo,static function()use($pdo,$hint,$input,$index,$size,$take,$mime,$chunk,$parts):array {
        $row=vp3_phone_record_grant_v1($pdo,$hint,$input);
        if ($row['take_id']!==$take || !in_array($row['state'],['reviewing','submitted'],true)) throw new RuntimeException('Review recording before submitting.',409);
        $hashes=json_decode((string)$row['hashes'],true)??[];
        $next=count($hashes);
        if (($next>0 && ($mime!==$row['mime_type'] || $size!==(int)$row['total_bytes'])) || $index>$next) throw new RuntimeException('Upload changed.',409);
        $digest=hash('sha256',$chunk);
        if ($index<$next) {
            if (!hash_equals($hashes[$index],$digest)) throw new RuntimeException('Upload changed.',409);
            return ['next_index'=>$next,'recording'=>vp3_phone_record_view_v1($row,true)];
        }
        if ($row['state']==='submitted') throw new RuntimeException('Recording already submitted.',409);
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_record_chunks_v1(recording_id,chunk_index,contents) VALUES(?,?,?)',[$row['id'],$index,$chunk]);
        $hashes[]=$digest; $next++;
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_recordings_v1 SET hashes=?,mime_type=?,total_bytes=? WHERE id=?',
            [json_encode($hashes,JSON_THROW_ON_ERROR),$mime,$size,$row['id']]);
        if ($next===$parts) $row=vp3_phone_record_publish_v1($pdo,$row,$parts,$size,$mime);
        return ['next_index'=>$next,'recording'=>vp3_phone_record_view_v1($row,true)];
    });
}

function vp3_phone_record_upload_v1(PDO $pdo,string $token,array $post,array $files): array
{
    if (($post['action']??null)!=='camera_record_chunk' || array_keys($files)!==['chunk']) throw new RuntimeException('Invalid recording upload.',422);
    unset($post['action']);
    $file=$files['chunk'];
    if (!is_array($file) || ($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null)
        || !is_uploaded_file($file['tmp_name']) || (int)($file['size']??0)<1 || (int)$file['size']>VP3_PHONE_RECORD_CHUNK_V1) {
        throw new RuntimeException('Recording chunk unavailable.',413);
    }
    $chunk=file_get_contents($file['tmp_name'],false,null,0,VP3_PHONE_RECORD_CHUNK_V1+1);
    if (!is_string($chunk) || strlen($chunk)!==(int)$file['size']) throw new RuntimeException('Incomplete recording chunk.',422);
    return vp3_phone_record_chunk_v1($pdo,$token,$post,$chunk);
}

function vp3_phone_record_pending_v1(PDO $pdo,int $owner,int $agent,int $cid): bool
{
    if (!vp3_phone_record_ready_v1($pdo)) return false;
    return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_camera_recordings_v1 r JOIN vp3_phone_camera_sessions_v1 s ON s.id=r.session_id
        WHERE s.owner_user_id=? AND s.agent_id=? AND s.conversation_id=? AND r.state='submitted' AND r.announced=0 LIMIT 1",[$owner,$agent,$cid])->fetchColumn();
}

function vp3_phone_record_announcements_v1(PDO $pdo,int $owner,int $agent,int $cid): array
{
    $rows=vp3_phone_sql_v1($pdo,"SELECT t.*,r.id AS recording_id FROM vp3_phone_chat_turns_v1 t JOIN vp3_phone_camera_recordings_v1 r ON r.receipt_id=t.assistant_message_id
        WHERE t.owner_user_id=? AND t.agent_id=? AND t.conversation_id=? AND r.state='submitted' AND r.announced=0 ORDER BY t.assistant_message_id LIMIT 10",
        [$owner,$agent,$cid])->fetchAll(PDO::FETCH_ASSOC);
    $updates=[];
    foreach ($rows as $row) {
        $updates[]=vp3_phone_chat_announcement_v1($row)+['actions'=>vp3_phone_chat_actions_v1('camera_clip',$agent,$cid,$row['map_device_id'])];
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_recordings_v1 SET announced=1 WHERE id=?',[$row['recording_id']]);
    }
    return $updates;
}
