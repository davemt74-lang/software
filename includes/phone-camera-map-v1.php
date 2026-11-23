<?php
declare(strict_types=1);

const VP3_PHONE_CAMERA_FRAME_LEASE_V1 = 12;
const VP3_PHONE_CAMERA_MAP_VIEWER_LEASE_V1 = 20;

function vp3_phone_camera_map_schema_v1(PDO $pdo,string $options): void
{
    // At most one extra receiver per holder-approved capture. Neither peer stores video.
    $pdo->exec("CREATE TABLE IF NOT EXISTS vp3_phone_camera_map_v1 (
        session_id CHAR(32) PRIMARY KEY, source_seen BIGINT NOT NULL, viewer_id CHAR(32) NULL,
        viewer_seen BIGINT NULL, offer_sdp TEXT NULL, answer_sdp TEXT NULL,
        FOREIGN KEY (session_id) REFERENCES vp3_phone_camera_sessions_v1(id) ON DELETE CASCADE
    )".$options);
}

function vp3_phone_camera_map_ready_v1(PDO $pdo): bool
{
    return schema_metadata_table_exists($pdo,'vp3_phone_camera_map_v1');
}

/** Called by the paired tab that actually owns the accepted camera track, under the account lock. */
function vp3_phone_camera_map_source_v1(PDO $pdo,array $session,array $input,int $now): ?array
{
    if (!vp3_phone_camera_map_ready_v1($pdo)) return null;
    $id=$session['id'];
    $row=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_camera_map_v1 WHERE session_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
    if (($input['map_source_live']??false)!==true) {
        if ($row && (int)$row['source_seen']>0) {
            vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET source_seen=0,viewer_seen=NULL,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=?',[$id]);
        }
        return null;
    }
    if (!$row) {
        vp3_phone_sql_v1($pdo,'INSERT INTO vp3_phone_camera_map_v1(session_id,source_seen) VALUES(?,?)',[$id,$now]);
        return null;
    }
    if ((int)$row['source_seen']<=$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1 || (int)$row['viewer_seen']<=$now-VP3_PHONE_CAMERA_MAP_VIEWER_LEASE_V1) {
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET source_seen=?,viewer_seen=NULL,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=?',[$now,$id]);
        return null;
    }
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET source_seen=? WHERE session_id=?',[$now,$id]);
    $viewer=['viewer_id'=>$row['viewer_id']];
    if ($row['answer_sdp']!==null) $viewer['answer_sdp']=$row['answer_sdp'];
    return $viewer;
}

/** Same device cookie and private camera session are checked before calling this. */
function vp3_phone_camera_map_offer_v1(PDO $pdo,array $session,array $input,int $now): void
{
    $viewer=vp3_phone_camera_id_v1($input['viewer_id']??null);
    $sdp=vp3_phone_camera_sdp_v1($input['sdp']??null,'sendonly');
    $row=vp3_phone_sql_v1($pdo,'SELECT offer_sdp FROM vp3_phone_camera_map_v1
        WHERE session_id=? AND viewer_id=? AND source_seen>? AND viewer_seen>?',
        [$session['id'],$viewer,$now-VP3_PHONE_CAMERA_FRAME_LEASE_V1,$now-VP3_PHONE_CAMERA_MAP_VIEWER_LEASE_V1])->fetch(PDO::FETCH_ASSOC);
    if (!$row || ($row['offer_sdp']!==null && $row['offer_sdp']!==$sdp)) throw new RuntimeException('Map viewer changed.',409);
    if ($row['offer_sdp']===null) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET offer_sdp=? WHERE session_id=? AND viewer_id=?',[$sdp,$session['id'],$viewer]);
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
function vp3_phone_camera_map_viewer_v1(PDO $pdo,array $row,string $action,array $input,int $now): array
{
    $viewer=vp3_phone_camera_id_v1($input['viewer_id']??null);
    if (vp3_phone_camera_id_v1($input['device_id']??null)!==$row['device_id']) throw new RuntimeException('Map camera unavailable.',404);
    if (!vp3_phone_camera_map_ready_v1($pdo)) throw new RuntimeException('Upgrade required.',503);
    $session=$row['id'];
    $map=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_camera_map_v1 WHERE session_id=?',[$session])->fetch(PDO::FETCH_ASSOC);
    if (!$map) return ['camera'=>null];
    $same=$map['viewer_id']===$viewer;
    $active=(int)$map['viewer_seen']>$now-VP3_PHONE_CAMERA_MAP_VIEWER_LEASE_V1;
    if ($action==='map_close') {
        if ($same || $map['viewer_id']===null) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET viewer_id=?,viewer_seen=NULL,
            offer_sdp=NULL,answer_sdp=NULL WHERE session_id=?',[$viewer,$session]);
        return ['camera'=>null];
    }
    $until=vp3_phone_camera_map_until_v1($row+$map);
    if ($until<=$now || !in_array($row['state'],['sharing','answered'],true) || $row['offer_sdp']===null) return ['camera'=>null];
    if ($action==='map_open' && !$same) {
        if ($active) throw new RuntimeException('Another map preview is open.',409);
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET viewer_id=?,viewer_seen=?,offer_sdp=NULL,answer_sdp=NULL WHERE session_id=?',
            [$viewer,$now,$session]);
        $map['offer_sdp']=$map['answer_sdp']=null;
    } else {
        if (!$same || !$active) return ['camera'=>null];
        if ($action==='map_answer') {
            $sdp=vp3_phone_camera_sdp_v1($input['sdp']??null,'recvonly');
            if ($map['offer_sdp']===null || ($map['answer_sdp']!==null && $map['answer_sdp']!==$sdp)) throw new RuntimeException('Map viewer changed.',409);
            if ($map['answer_sdp']===null) {
                vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET answer_sdp=? WHERE session_id=?',[$sdp,$session]);
                $map['answer_sdp']=$sdp;
            }
        }
        if ((int)$map['viewer_seen']<$now-2) vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_map_v1 SET viewer_seen=? WHERE session_id=?',[$now,$session]);
    }
    $hardExpiry=min((int)$row['started_at']+VP3_PHONE_CAMERA_MAX_SECONDS_V1,(int)$row['device_expires']);
    if ($map['answer_sdp']!==null && ((int)$row['expires_at']<$hardExpiry || (int)$row['owner_seen']<$now-2)) {
        vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_camera_sessions_v1 SET expires_at=?,owner_seen=? WHERE id=?',[$hardExpiry,$now,$session]);
    }
    $camera=['session_id'=>$session,'viewer_id'=>$viewer,'device_id'=>$row['device_id'],'device_name'=>$row['device_name'],
        'state'=>$map['answer_sdp']===null?'sharing':'answered','expires_at'=>$until,'relay_expires_at'=>$hardExpiry];
    if ($map['offer_sdp']!==null) $camera['offer_sdp']=$map['offer_sdp'];
    return ['camera'=>$camera];
}
