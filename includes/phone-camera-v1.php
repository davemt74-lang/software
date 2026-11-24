<?php
declare(strict_types=1);

require_once __DIR__.'/phone-transactions-v1.php';

require_once __DIR__.'/phone-camera-map-v1.php';
require_once __DIR__.'/phone-camera-recording-v1.php';

const VP3_PHONE_CAMERA_REQUEST_SECONDS_V1 = 300;
const VP3_PHONE_CAMERA_LEASE_SECONDS_V1 = 30;
const VP3_PHONE_CAMERA_MAX_SECONDS_V1 = 14400;

function vp3_phone_camera_schema_v1(PDO $pdo, string $options): void
{
    $chatFk = schema_metadata_table_exists($pdo,'chat_conversations')
        ? ', FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_camera_sessions_v1 (
        id CHAR(32) PRIMARY KEY, owner_user_id INT UNSIGNED NOT NULL, device_id CHAR(32) NOT NULL,
        agent_id INT UNSIGNED NOT NULL, conversation_id BIGINT UNSIGNED NOT NULL,
        state VARCHAR(12) NOT NULL, expires_at BIGINT NOT NULL, started_at BIGINT NULL,
        holder_seen BIGINT NULL, owner_seen BIGINT NULL, offer_sdp TEXT NULL, answer_sdp TEXT NULL,
        announced_start SMALLINT NOT NULL DEFAULT 0, announced_stop SMALLINT NOT NULL DEFAULT 0,
        UNIQUE(owner_user_id,agent_id,conversation_id,id), UNIQUE(owner_user_id,device_id,id),
        FOREIGN KEY (owner_user_id,device_id) REFERENCES vp3_phone_devices_v1(owner_user_id,id) ON DELETE CASCADE
        $chatFk
    )".$options);
    vp3_phone_camera_map_schema_v1($pdo,$options);
    vp3_phone_record_schema_v1($pdo,$options);
}

function vp3_phone_camera_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id,state,announced_start,announced_stop,offer_sdp,answer_sdp FROM vp3_phone_camera_sessions_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function vp3_phone_camera_id_v1(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D',$value)) throw new RuntimeException('Invalid camera identifier.',422);
    return $value;
}

function vp3_phone_camera_scope_v1(PDO $pdo,int $owner,int $agent,int $cid): bool
{
    if ($cid<1 || $agent<0) return false;
    if ($agent>0 && !vp3_phone_sql_v1($pdo,'SELECT 1 FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1',[$agent,$owner])->fetchColumn()) return false;
    require_once __DIR__.'/agent-chat-boundary-v380.php';
    return vp3_agent_chat_conversation_v380($pdo,$cid,$owner,$agent ? ['id'=>$agent] : null)!==null;
}

/** Called under the account lock. Stopping always removes both descriptions, including ICE credentials. */
function vp3_phone_camera_end_v1(PDO $pdo,int $owner,string $selector,array $params): void
{
    $ended=vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_sessions_v1 SET state='stopped',offer_sdp=NULL,answer_sdp=NULL
        WHERE owner_user_id=? AND state<>'stopped' AND ($selector)",[$owner,...$params]);
    if ($ended->rowCount()>0 && vp3_phone_camera_map_ready_v1($pdo)) {
        vp3_phone_sql_v1($pdo,"DELETE FROM vp3_phone_camera_map_v1 WHERE session_id IN
            (SELECT id FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND state='stopped')",[$owner]);
    }
    if ($ended->rowCount()>0) vp3_phone_record_expire_v1($pdo,$owner);
}

function vp3_phone_camera_disconnect_v1(PDO $pdo,array $device): void
{
    // Established GPS accounts keep working during a rolling upgrade.
    if (vp3_phone_camera_ready_v1($pdo)) vp3_phone_camera_end_v1($pdo,(int)$device['owner_user_id'],'device_id=?',[$device['id']]);
}

function vp3_phone_camera_expiry_sql_v1(): string
{
    return "expires_at<=? OR (state IN ('sharing','answered') AND holder_seen<=?) OR (state='answered' AND owner_seen<=?)";
}

function vp3_phone_camera_expiry_times_v1(int $now): array
{
    $cutoff=$now-VP3_PHONE_CAMERA_LEASE_SECONDS_V1;
    return [$now,$cutoff,$cutoff];
}

/** A session can exchange exactly one video-only, one-way WebRTC offer and answer. */
function vp3_phone_camera_sdp_v1(mixed $sdp,string $direction): string
{
    if (!is_string($sdp) || strlen($sdp)>49152 || !str_starts_with($sdp,"v=0\n") && !str_starts_with($sdp,"v=0\r\n")
        || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/',$sdp)) throw new RuntimeException('Invalid camera session description.',422);
    $lines=explode("\n",str_replace("\r\n","\n",$sdp));
    $media=array_values(array_filter($lines,static fn($line)=>str_starts_with($line,'m=')));
    $directions=array_values(array_filter($lines,static fn($line)=>in_array($line,['a=sendonly','a=recvonly','a=inactive','a=sendrecv'],true)));
    if (count($media)!==1 || !str_starts_with($media[0],'m=video ') || $directions!==['a='.$direction]
        || str_contains(implode("\n",$lines),"\r")) throw new RuntimeException('Only one-way camera video is supported.',422);
    return $sdp;
}

function vp3_phone_camera_view_v1(array $row,string $deviceName,bool $holder): array
{
    $camera=['session_id'=>$row['id'],'device_id'=>$row['device_id'],'device_name'=>$deviceName,
        'state'=>$row['state'],'expires_at'=>(int)$row['expires_at']];
    // A signed-in handset can offer controls only in the originating private Chat.
    // The paired device cookie still authorizes every camera operation.
    if ($holder) {
        $camera['owner_user_id']=(int)$row['owner_user_id'];
        $camera['agent_id']=(int)$row['agent_id'];
        $camera['conversation_id']=(int)$row['conversation_id'];
    }
    // ICE credentials are installed at negotiation. A connected peer needs them for
    // TURN allocation refreshes even after the shorter handshake window closes.
    if (in_array($row['state'],['sharing','answered'],true)) {
        $camera['relay_expires_at']=min((int)$row['started_at']+VP3_PHONE_CAMERA_MAX_SECONDS_V1,(int)$row['device_expires']);
    }
    $field=$holder ? 'answer_sdp' : 'offer_sdp';
    if ($row['state']!=='stopped' && $row[$field]!==null) $camera[$field]=$row[$field];
    return ['camera'=>$camera];
}

function vp3_phone_camera_request_v1(PDO $pdo,int $owner,int $agent,int $cid,string $deviceId,int $now): array
{
    $device=vp3_phone_row_v1($pdo,$owner,$deviceId);
    if ($device['device_status']!=='approved' || (int)$device['expires_at']<=$now) throw new RuntimeException('This phone is unavailable.',403);
    $current=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND state<>'stopped'
        AND (device_id=? OR (agent_id=? AND conversation_id=?))".vp3_phone_lock_v1($pdo),[$owner,$deviceId,$agent,$cid])->fetch(PDO::FETCH_ASSOC);
    if ($current) {
        if ($current['device_id']!==$deviceId || (int)$current['agent_id']!==$agent || (int)$current['conversation_id']!==$cid)
            throw new RuntimeException('End the existing camera request first.',409);
        $current['device_expires']=$device['expires_at'];
        return vp3_phone_camera_view_v1($current,$device['device_name'],false);
    }
    vp3_phone_sql_v1($pdo,"DELETE FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND state='stopped' AND expires_at<?",[$owner,$now-86400]);
    $row=['id'=>bin2hex(random_bytes(16)),'device_id'=>$deviceId,'state'=>'requested',
        'expires_at'=>min($now+VP3_PHONE_CAMERA_REQUEST_SECONDS_V1,(int)$device['expires_at']),'offer_sdp'=>null];
    vp3_phone_sql_v1($pdo,"INSERT INTO vp3_phone_camera_sessions_v1(id,owner_user_id,device_id,agent_id,conversation_id,state,expires_at)
        VALUES(?,?,?,?,?,'requested',?)",[$row['id'],$owner,$deviceId,$agent,$cid,$row['expires_at']]);
    return vp3_phone_camera_view_v1($row,$device['device_name'],false);
}

/** The HTTP owner endpoint and private Chat separately verify that this browser is the signed-in owner. */
function vp3_phone_camera_owner_v1(PDO $pdo,int $owner,int $agent,int $cid,string $action,array $input): array
{
    if (!in_array($action,['request','status','answer','stop','map_open','map_status','map_answer','map_close','chat_open','chat_status','chat_answer','chat_close'],true)) throw new RuntimeException('Unknown owner camera action.',422);
    return vp3_phone_transaction_v1($pdo,static function() use ($pdo,$owner,$agent,$cid,$action,$input): array {
        vp3_phone_account_v1($pdo,$owner,true);
        if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) throw new RuntimeException('Camera conversation unavailable.',403);
        $now=time();
        vp3_phone_camera_end_v1($pdo,$owner,vp3_phone_camera_expiry_sql_v1(),vp3_phone_camera_expiry_times_v1($now));
        if ($action==='request') return vp3_phone_camera_request_v1($pdo,$owner,$agent,$cid,vp3_phone_camera_id_v1($input['device_id']??null),$now);
        $params=[$owner,$agent,$cid];
        if ($action==='status' && !array_key_exists('session_id',$input)) {
            $where="s.state<>'stopped'";
            if (array_key_exists('device_id',$input)) { $where.=' AND s.device_id=?'; $params[]=vp3_phone_camera_id_v1($input['device_id']); }
        } else {
            $where='s.id=?';
            $params[]=vp3_phone_camera_id_v1($input['session_id']??null);
        }
        $row=vp3_phone_sql_v1($pdo,"SELECT s.*,d.device_name,d.device_status,d.expires_at AS device_expires FROM vp3_phone_camera_sessions_v1 s
            JOIN vp3_phone_devices_v1 d ON d.id=s.device_id AND d.owner_user_id=s.owner_user_id
            WHERE s.owner_user_id=? AND s.agent_id=? AND s.conversation_id=? AND $where".vp3_phone_lock_v1($pdo),$params)->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($action==='status') return ['camera'=>null];
            throw new RuntimeException('Camera session unavailable.',404);
        }
        if ($row['device_status']!=='approved' || (int)$row['device_expires']<=$now) {
            vp3_phone_camera_end_v1($pdo,$owner,'id=?',[$row['id']]);
            return ['camera'=>null];
        }
        if (str_contains($action,'_')) {
            [$surface,$operation]=explode('_',$action,2);
            return vp3_phone_camera_viewer_v1($pdo,$row,$surface,$operation,$input,$now);
        }
        return vp3_phone_camera_transition_v1($pdo,$row,$action,$input,false,$now);
    });
}

function vp3_phone_camera_holder_v1(PDO $pdo,string $token,string $action,array $input): array
{
    if (!in_array($action,['status','prepare','start','offer','stop','map_offer','chat_offer'],true)) throw new RuntimeException('Unknown phone camera action.',422);
    if (!vp3_phone_secret_valid_v1($token)) throw new RuntimeException('Phone unavailable.',403);
    $hash=vp3_extension_hash_v2000($token);
    $hint=vp3_phone_sql_v1($pdo,'SELECT owner_user_id,id FROM vp3_phone_devices_v1 WHERE token_hash=?',[$hash])->fetch(PDO::FETCH_ASSOC);
    if (!$hint) throw new RuntimeException('Phone unavailable.',403);
    $owner=(int)$hint['owner_user_id'];
    $result=vp3_phone_transaction_v1($pdo,static function() use ($pdo,$owner,$hint,$hash,$action,$input): array {
        $allowed=vp3_phone_authorized_owner_v1($pdo,$owner,true)!==null;
        $device=vp3_phone_row_v1($pdo,$owner,$hint['id']);
        $now=time();
        if (!hash_equals($device['token_hash'],$hash)) throw new RuntimeException('Phone unavailable.',403);
        if (!$allowed || $device['device_status']!=='approved' || (int)$device['expires_at']<=$now) {
            vp3_phone_camera_end_v1($pdo,$owner,'device_id=?',[$device['id']]);
            return [];
        }
        vp3_phone_camera_end_v1($pdo,$owner,vp3_phone_camera_expiry_sql_v1(),vp3_phone_camera_expiry_times_v1($now));
        if ($action==='prepare') {
            // Resolve the private destination exclusively from this approved pairing under the account lock.
            require_once __DIR__.'/paired-phone-agent-v1.php';
            if (array_diff(array_keys($input),['device_id'])) throw new RuntimeException('Unexpected camera request fields.',422);
            if (vp3_phone_camera_id_v1($input['device_id']??null)!==$device['id']) throw new RuntimeException('Phone unavailable.',403);
            if (!vp3_paired_phone_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
            $thread=vp3_paired_phone_thread_v1($pdo,$device);
            $agent=(int)$thread['agent_id'];
            $cid=(int)$thread['conversation_id'];
            if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) throw new RuntimeException('Camera conversation unavailable.',403);
            $requested=vp3_phone_camera_request_v1($pdo,$owner,$agent,$cid,$device['id'],$now);
            $input=['session_id'=>$requested['camera']['session_id']];
            $action='status';
        }
        $params=[$owner,$device['id']];
        if ($action==='status' && !array_key_exists('session_id',$input)) $where="state<>'stopped'";
        else { $where='id=?'; $params[]=vp3_phone_camera_id_v1($input['session_id']??null); }
        $row=vp3_phone_sql_v1($pdo,"SELECT * FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND device_id=? AND $where"
            .vp3_phone_lock_v1($pdo),$params)->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($action==='status') return ['camera'=>null];
            throw new RuntimeException('Camera session unavailable.',404);
        }
        if (!vp3_phone_camera_scope_v1($pdo,$owner,(int)$row['agent_id'],(int)$row['conversation_id'])) {
            vp3_phone_camera_end_v1($pdo,$owner,'id=?',[$row['id']]);
            return ['camera'=>null];
        }
        $row['device_name']=$device['device_name'];
        $row['device_expires']=$device['expires_at'];
        if ($action==='map_offer' || $action==='chat_offer') {
            if (!in_array($row['state'],['sharing','answered'],true) || !vp3_phone_camera_map_ready_v1($pdo)) throw new RuntimeException('Map camera unavailable.',409);
            vp3_phone_camera_viewer_offer_v1($pdo,$row,$action==='map_offer'?'map':'chat',$input,$now);
        }
        $result=vp3_phone_camera_transition_v1($pdo,$row,$action,$input,true,$now);
        if ($action==='status' && vp3_phone_record_ready_v1($pdo)) {
            $result['camera']['recording']=vp3_phone_record_view_v1(vp3_phone_record_latest_v1($pdo,$row['id']),true);
        }
        if ($action==='status' && ($input['session_id']??null)===$row['id'] && ($input['heartbeat']??false)===true
            && array_key_exists('map_source_live',$input) && in_array($row['state'],['sharing','answered'],true)) {
            $result['camera']+=vp3_phone_camera_source_viewers_v1($pdo,$row,$input,$now);
        }
        return $result;
    });
    return $result ?: throw new RuntimeException('Phone unavailable.',403);
}

function vp3_phone_camera_transition_v1(PDO $pdo,array $row,string $action,array $input,bool $holder,int $now): array
{
    $id=$row['id'];
    if ($action==='stop') {
        // A denial from a stale permission prompt must not end another tab's accepted source.
        if ($holder && ($input['requested_only']??false)===true && !in_array($row['state'],['requested','stopped'],true)) {
            throw new RuntimeException('This camera request was already accepted.',409);
        }
        vp3_phone_camera_end_v1($pdo,(int)$row['owner_user_id'],'id=?',[$id]);
        $row['state']='stopped';
    } elseif ($row['state']==='stopped') {
        if ($action!=='status') throw new RuntimeException('Camera session has ended.',409);
    } elseif ($action==='start') {
        // Both Chat and the paired-phone page may show the same pending request.
        if ($row['state']!=='requested') throw new RuntimeException('This camera is already in use.',409);
        $row['state']='sharing';
        $row['started_at']=$now;
        $row['expires_at']=min($now+VP3_PHONE_CAMERA_REQUEST_SECONDS_V1,(int)$row['device_expires']);
        vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_sessions_v1 SET state='sharing',started_at=?,holder_seen=?,expires_at=? WHERE id=?",
            [$now,$now,$row['expires_at'],$id]);
    } elseif ($action==='offer' || $action==='answer') {
        if ($row['state']==='requested' || ($action==='answer' && $row['offer_sdp']===null)) throw new RuntimeException('Wait for the phone to share.',409);
        $field=$holder ? 'offer_sdp' : 'answer_sdp';
        $sdp=vp3_phone_camera_sdp_v1($input['sdp']??null,$holder ? 'sendonly' : 'recvonly');
        if ($row[$field]!==null && !hash_equals($row[$field],$sdp)) throw new RuntimeException('Camera negotiation already exists.',409);
        if ($row[$field]===null) {
            $row[$field]=$sdp;
            if (!$holder) {
                $row['state']='answered';
                $row['expires_at']=min((int)$row['started_at']+VP3_PHONE_CAMERA_MAX_SECONDS_V1,(int)$row['device_expires']);
            }
            vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_sessions_v1 SET $field=?,state=?,expires_at=? WHERE id=?",
                [$sdp,$row['state'],$row['expires_at'],$id]);
        }
    }
    // An idle phone tab can read/stop the grant but cannot keep a departed source alive.
    $renewLease=!$holder || $action!=='status' ||
        (($input['session_id']??null)===$id && ($input['heartbeat']??false)===true);
    if ($renewLease && ($row['state']==='sharing' || $row['state']==='answered')) {
        $clock=$holder ? 'holder_seen' : 'owner_seen';
        if ((int)$row[$clock] < $now-2) vp3_phone_sql_v1($pdo,"UPDATE vp3_phone_camera_sessions_v1 SET $clock=? WHERE id=?",[$now,$id]);
    }
    return vp3_phone_camera_view_v1($row,$row['device_name'],$holder);
}

function vp3_phone_camera_pending_v1(PDO $pdo,int $owner,int $agent,int $cid): bool
{
    if (!vp3_phone_camera_ready_v1($pdo)) return false;
    $expiry=vp3_phone_camera_expiry_sql_v1();
    return (bool)vp3_phone_sql_v1($pdo,"SELECT 1 FROM vp3_phone_camera_sessions_v1 WHERE owner_user_id=? AND agent_id=? AND conversation_id=?
        AND ((started_at IS NOT NULL AND announced_start=0) OR (state='stopped' AND announced_stop=0)
        OR (state<>'stopped' AND ($expiry))) LIMIT 1",[$owner,$agent,$cid,...vp3_phone_camera_expiry_times_v1(time())])->fetchColumn();
}

/** Private Chat calls this while holding the account lock and saves its announcements in the same transaction. */
function vp3_phone_camera_events_v1(PDO $pdo,int $owner,int $agent,int $cid): array
{
    if (!vp3_phone_camera_ready_v1($pdo)) return [];
    if (!vp3_phone_transaction_active_v1($pdo)) throw new LogicException('Camera events must be saved with their Chat turns.');
    vp3_phone_account_v1($pdo,$owner,true);
    if (!vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) return [];
    $now=time();
    vp3_phone_camera_end_v1($pdo,$owner,vp3_phone_camera_expiry_sql_v1(),vp3_phone_camera_expiry_times_v1($now));
    $rows=vp3_phone_sql_v1($pdo,"SELECT s.id,s.device_id,s.started_at,s.state,s.announced_start,s.announced_stop,d.device_name
        FROM vp3_phone_camera_sessions_v1 s JOIN vp3_phone_devices_v1 d ON d.owner_user_id=s.owner_user_id AND d.id=s.device_id
        WHERE s.owner_user_id=? AND s.agent_id=? AND s.conversation_id=? AND ((s.started_at IS NOT NULL AND s.announced_start=0)
        OR (s.state='stopped' AND s.announced_stop=0)) ORDER BY s.expires_at,s.id LIMIT 12".vp3_phone_lock_v1($pdo),
        [$owner,$agent,$cid])->fetchAll(PDO::FETCH_ASSOC);
    $events=[];
    foreach ($rows as $row) {
        $data=['session_id'=>$row['id'],'device_id'=>$row['device_id'],'device_name'=>$row['device_name']];
        if ($row['state']!=='stopped' && $row['started_at']!==null && !(int)$row['announced_start']) $events[]=$data+['event'=>'started'];
        if ($row['state']==='stopped' && !(int)$row['announced_stop']) {
            $events[]=$data+['event'=>$row['started_at']===null?'cancelled':'stopped'];
        }
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_sessions_v1 SET announced_start=?,announced_stop=? WHERE id=?',
            [(int)($row['started_at']!==null),(int)($row['state']==='stopped'),$row['id']]);
    }
    return $events;
}
