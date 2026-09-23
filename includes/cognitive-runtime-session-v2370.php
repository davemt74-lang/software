<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.70 — Live Session cognitive adapter.
 */
const VP3_COGNITIVE_RUNTIME_SESSION_V2370='vp3-cognitive-runtime-session-v2370-20260922';

function vp3_cognitive_session_row_v2370(PDO $pdo,array $user,array $ref): ?array
{
    $uid=(int)($user['id']??0);if($uid<1)return null;
    $type=(string)($ref['type']??'');$id=(string)($ref['id']??'');
    if($type==='live_session'){
        $stmt=$pdo->prepare('SELECT * FROM agent_live_sessions_v2370 WHERE public_id=? AND owner_user_id=? LIMIT 1');
        $stmt->execute([$id,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
    }
    if($type==='session_segment'&&ctype_digit($id)){
        $stmt=$pdo->prepare('SELECT * FROM agent_live_session_segments_v2370 WHERE id=? AND owner_user_id=? LIMIT 1');
        $stmt->execute([(int)$id,$uid]);$row=$stmt->fetch();return is_array($row)?$row:null;
    }
    return null;
}

function vp3_cognitive_session_permission_v2370(PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'): bool
{
    if($operation!=='read'||!in_array((string)($ref['type']??''),['live_session','session_segment'],true))return false;
    if(!vp3_live_session_schema_ready_v2370($pdo))return false;
    return vp3_cognitive_session_row_v2370($pdo,$user,$ref)!==null;
}

function vp3_cognitive_session_context_v2370(PDO $pdo,array $user,string $agentNamespace,array $ref,array $options=[]): array
{
    $row=vp3_cognitive_session_row_v2370($pdo,$user,$ref);
    if(!$row)throw new RuntimeException('Live session context is unavailable.');
    if((string)$ref['type']==='session_segment'){
        return [
            'segment'=>[
                'id'=>(int)$row['id'],
                'session_id'=>(int)$row['session_id'],
                'segment_type'=>(string)$row['segment_type'],
                'surface'=>(string)$row['surface'],
                'context_key'=>(string)$row['context_key'],
                'started_at'=>(string)$row['started_at'],
                'ended_at'=>(string)$row['ended_at'],
                'duration_seconds'=>(int)$row['duration_seconds'],
                'reason'=>(string)$row['reason'],
            ],
            'authority'=>'agent_live_session_segments_v2370',
        ];
    }

    $public=vp3_live_session_public_v2370($row);
    $limit=12;
    $stmt=$pdo->prepare("SELECT id,segment_type,surface,context_key,started_at,ended_at,duration_seconds,reason FROM agent_live_session_segments_v2370 WHERE session_id=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([(int)$row['id']]);$segments=array_reverse($stmt->fetchAll()?:[]);
    return [
        'session'=>$public,
        'segments'=>$segments,
        'authority'=>'agent_live_sessions_v2370',
        'raw_event_payloads_exposed'=>false,
    ];
}

function vp3_cognitive_register_session_v2370(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['live_session']))return;
    vp3_cognitive_register_module_v500([
        'module'=>'live_session',
        'version'=>'cognitive-live-session-v2370',
        'objects'=>['live_session','session_segment'],
        'events'=>[
            'session.started','session.resumed','session.idle_started','session.idle_ended',
            'session.surface_changed','session.focus_changed','session.ended',
            'chat.message_sent','chat.response_completed','chat.response_interrupted',
            'chat.stop_requested','chat.knowledge_scope_changed',
        ],
        'permission_resolver'=>'vp3_cognitive_session_permission_v2370',
        'context_provider'=>'vp3_cognitive_session_context_v2370',
        'relationship_provider'=>null,
        'cards'=>[],
        'tools'=>[],
        'freshness_policy'=>['default_seconds'=>15,'session_state_seconds'=>5],
        'sensitivity_policy'=>[
            'owner_only'=>true,
            'raw_event_payloads_exposed'=>false,
            'ephemeral_browser_context_not_persisted'=>true,
        ],
        'surfaces'=>['memory','brief','away_digest','ask_user','chat_response'],
        'voice_safe'=>false,
    ]);
}

vp3_cognitive_register_session_v2370();
