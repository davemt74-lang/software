<?php
declare(strict_types=1);

const VP3_PHONE_CAMERA_FRAME_LEASE_V1 = 12;
const VP3_PHONE_CAMERA_VIEWER_LEASE_V1 = 20;

function vp3_phone_camera_map_schema_v1(PDO $pdo,string $options): void
{
    // The source publishes one capture lease. Each private owner surface negotiates its own peer.
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_camera_map_v1 (
        session_id CHAR(32) PRIMARY KEY, source_seen BIGINT NOT NULL,
        FOREIGN KEY (session_id) REFERENCES vp3_phone_camera_sessions_v1(id) ON DELETE CASCADE
    )".$options);
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_camera_viewers_v1 (
        session_id CHAR(32) NOT NULL, surface VARCHAR(8) NOT NULL, viewer_id CHAR(32) NOT NULL,
        viewer_seen BIGINT NULL, offer_sdp TEXT NULL, answer_sdp TEXT NULL, PRIMARY KEY(session_id,surface),
        FOREIGN KEY (session_id) REFERENCES vp3_phone_camera_map_v1(session_id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_camera_map_ready_v1(PDO $pdo): bool
{
    return schema_metadata_table_exists($pdo,'vp3_phone_camera_map_v1') && schema_metadata_table_exists($pdo,'vp3_phone_camera_viewers_v1');
}

/** Called by the paired tab that actually owns the accepted camera track, under the account lock. */
function vp3_phone_camera_source_viewers_v1(PDO $pdo,array $session,array $input,int $now): array
{
    $viewers=['chat_viewer'=>null,'map_viewer'=>null];
    if (!vp3_phone_camera_map_ready_v1($pdo)) return $viewers;
    $id=$session['id'];
    $last=vp3_phone_sql_v1($pdo,'SELECT source_seen FROM vp3_phone_camera_map_v1 WHERE session_id=?',[$id])->fetchColumn();
    $live=($input['map_source_live']??false)===true;
    if ($last===false) {
        if ($live) vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_camera_map_v1(session_id,source_seen) VALUES(?,?)',[$id,$now]);
        return $viewers;
    }
    if (!$live || (int)$last<=$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1) {
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET viewer_seen=NULL,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=? AND viewer_seen IS NOT NULL',[$id]);
    }
    if ($live || (int)$last!==0) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET source_seen=? WHERE session_id=?',[$live?$now:0,$id]);
    if (!$live) return $viewers;
    $cutoff=$now-VP3_PHONE_CAMERA_VIEWER_LEASE_V1;
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET viewer_seen=NULL,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=? AND viewer_seen<=?',[$id,$cutoff]);
    $rows=vp3_phone_sql_v1($pdo,'SELECT surface,viewer_id,answer_sdp FROM vp3_phone_camera_viewers_v1 WHERE session_id=? AND viewer_seen>?',[$id,$cutoff]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $viewer=['viewer_id'=>$row['viewer_id']];
        if ($row['answer_sdp']!==null) $viewer['answer_sdp']=$row['answer_sdp'];
        $viewers[$row['surface'].'_viewer']=$viewer;
    }
    return $viewers;
}

/** Same device cookie and private camera session are checked before calling this. */
function vp3_phone_camera_viewer_offer_v1(PDO $pdo,array $session,string $surface,array $input,int $now): void
{
    $viewer=vp3_phone_camera_id_v1($input['viewer_id']??null);
    $sdp=vp3_phone_camera_sdp_v1($input['sdp']??null,'sendonly');
    $row=vp3_phone_sql_v1($pdo,'SELECT v.offer_sdp FROM vp3_phone_camera_viewers_v1 v
        JOIN vp3_phone_camera_map_v1 s ON s.session_id=v.session_id
        WHERE v.session_id=? AND v.surface=? AND v.viewer_id=? AND s.source_seen>? AND v.viewer_seen>?',
        [$session['id'],$surface,$viewer,$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1,$now-VP3_PHONE_CAMERA_VIEWER_LEASE_V1])->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['offer_sdp']!==null && $row['offer_sdp']!==$sdp)) throw new RuntimeException('Camera viewer changed.',409);
    if ($row['offer_sdp']===null) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET offer_sdp=? WHERE session_id=? AND surface=? AND viewer_id=?',[$sdp,$session['id'],$surface,$viewer]);
}

function vp3_phone_camera_map_until_v1(array $row): int
{
    $until=min((int)$row['expires_at'],(int)$row['device_expires'],
        (int)$row['source_seen']+VP3_PHONE_CAMERA_FRAME_LEASE_V1,
        (int)$row['holder_seen']+VP3_PHONE_CAMERA_LEASE_SECONDS_V1);
    return $row['state']==='answered' ? min($until,(int)$row['owner_seen']+VP3_PHONE_CAMERA_LEASE_SECONDS_V1) : $until;
}

/** Bounded to the owner's current tracking inventory. The map never receives negotiation data with its markers. */
function vp3_phone_camera_map_devices_v1(PDO $pdo,int $owner,array $devices): array
{
    if (!$devices || !vp3_phone_camera_map_ready_v1($pdo)) return $devices;
    $ids=array_column($devices,'id');
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $now=time();
    $rows=vp3_phone_sql_v1($pdo,"SELECT s.id,s.device_id,s.agent_id,s.conversation_id,s.state,s.expires_at,s.holder_seen,s.owner_seen,
        m.source_seen,d.expires_at AS device_expires FROM vp3_phone_camera_map_v1 m
        JOIN vp3_phone_camera_sessions_v1 s ON s.id=m.session_id
        JOIN vp3_phone_devices_v1 d ON d.owner_user_id=s.owner_user_id AND d.id=s.device_id
        WHERE s.owner_user_id=? AND d.device_status='approved' AND s.device_id IN ($slots)
        AND s.state IN ('sharing','answered') AND s.offer_sdp IS NOT NULL AND m.source_seen>? AND s.expires_at>?",
        [$owner,...$ids,$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1,$now])->fetchAll(PDO::FETCH_ASSOC);
    $live=[];
    foreach ($rows as $row) {
        $until=vp3_phone_camera_map_until_v1($row);
        $agent=(int)$row['agent_id'];
        $cid=(int)$row['conversation_id'];
        if ($until>$now && vp3_phone_camera_scope_v1($pdo,$owner,$agent,$cid)) {
            $live[$row['device_id']]=['session_id'=>$row['id'],'agent_id'=>$agent,'conversation_id'=>$cid,'expires_at'=>$until];
        }
    }
    foreach ($devices as &$device) $device['live_camera']=$live[$device['id']]??null;
    return $devices;
}

/** Called only from a private, authenticated owner action, with the account locked. */
function vp3_phone_camera_viewer_v1(PDO $pdo,array $row,string $surface,string $action,array $input,int $now): array
{
    $viewer=vp3_phone_camera_id_v1($input['viewer_id']??null);
    if (vp3_phone_camera_id_v1($input['device_id']??null)!==$row['device_id']) throw new RuntimeException('Camera unavailable.',404);
    if (!vp3_phone_camera_map_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
    $session=$row['id'];
    $slot=vp3_phone_sql_v1($pdo,'SELECT s.source_seen,v.viewer_id,v.viewer_seen,v.offer_sdp,v.answer_sdp
        FROM vp3_phone_camera_map_v1 s LEFT JOIN vp3_phone_camera_viewers_v1 v ON v.session_id=s.session_id AND v.surface=?
        WHERE s.session_id=?',[$surface,$session])->fetch(PDO::FETCH_ASSOC);
    // The consent announcement can reach Chat before the phone publishes its first captured frame.
    $starting=$surface==='chat' && $action==='open' && in_array($row['state'],['sharing','answered'],true)
        && (int)$row['started_at']>$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1;
    if (!$slot) return ['camera'=>null,'camera_starting'=>$starting];
    $same=$slot['viewer_id']===$viewer;
    $active=(int)$slot['viewer_seen']>$now-VP3_PHONE_CAMERA_VIEWER_LEASE_V1;
    if ($action==='close') {
        if ($same) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET viewer_seen=NULL,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=? AND surface=?',[$session,$surface]);
        return ['camera'=>null];
    }
    $until=vp3_phone_camera_map_until_v1($row+$slot);
    if ($until<=$now || !in_array($row['state'],['sharing','answered'],true) || $row['offer_sdp']===null) return ['camera'=>null,'camera_starting'=>$starting];
    if ($action==='open' && !$same) {
        if ($active) throw new RuntimeException('Another camera preview is open on this surface.',409);
        if ($slot['viewer_id']===null) {
            vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_camera_viewers_v1(session_id,surface,viewer_id,viewer_seen) VALUES(?,?,?,?)',[$session,$surface,$viewer,$now]);
        } else {
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET viewer_id=?,viewer_seen=?,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=? AND surface=?',[$viewer,$now,$session,$surface]);
        }
        $slot['offer_sdp']=$slot['answer_sdp']=null;
    } else {
        if (!$same || !$active) return ['camera'=>null];
        if ($action==='answer') {
            $sdp=vp3_phone_camera_sdp_v1($input['sdp']??null,'recvonly');
            if ($slot['offer_sdp']===null || ($slot['answer_sdp']!==null && $slot['answer_sdp']!==$sdp)) throw new RuntimeException('Camera viewer changed.',409);
            if ($slot['answer_sdp']===null) {
                vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET answer_sdp=? WHERE session_id=? AND surface=?',[$sdp,$session,$surface]);
                $slot['answer_sdp']=$sdp;
            }
        }
        if ((int)$slot['viewer_seen']<$now-2) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_viewers_v1 SET viewer_seen=? WHERE session_id=? AND surface=?',[$now,$session,$surface]);
    }
    $hardExpiry=min((int)$row['started_at']+VP3_PHONE_CAMERA_MAX_SECONDS_V1,(int)$row['device_expires']);
    if ($slot['answer_sdp']!==null && ((int)$row['expires_at']<$hardExpiry || (int)$row['owner_seen']<$now-2)) {
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_sessions_v1 SET expires_at=?,owner_seen=? WHERE id=?',[$hardExpiry,$now,$session]);
    }
    $camera=['session_id'=>$session,'viewer_id'=>$viewer,'device_id'=>$row['device_id'],'device_name'=>$row['device_name'],
        'state'=>$slot['answer_sdp']===null?'sharing':'answered','expires_at'=>$until,'relay_expires_at'=>$hardExpiry];
    if ($slot['offer_sdp']!==null) $camera['offer_sdp']=$slot['offer_sdp'];
    return ['camera'=>$camera];
}
