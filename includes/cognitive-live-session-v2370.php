<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v23.70 — durable live-session projection.
 *
 * This layer extends the existing Agent activity state instead of replacing it.
 * Domain records remain authoritative. Low-level session events are written to
 * the durable Agent Event inbox without automatically promoting them to Brain.
 */
const VP3_COGNITIVE_LIVE_SESSION_V2370='vp3-cognitive-live-session-v2370-20260922';
const VP3_LIVE_SESSION_STALE_SECONDS_V2370=43200; // 12 hours
const VP3_LIVE_SESSION_SEGMENT_LIMIT_V2370=24;

function vp3_live_session_uuid_v2370(): string
{
    if(function_exists('agent_event_uuid_v1920'))return agent_event_uuid_v1920();
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}

function vp3_live_session_json_v2370(mixed $value): string
{
    $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($json)?$json:'{}';
}

function vp3_live_session_schema_ready_v2370(?PDO $pdo=null): bool
{
    $pdo=$pdo?:db();if(!$pdo)return false;
    if(!table_exists('agent_live_sessions_v2370')||!table_exists('agent_live_session_segments_v2370'))return false;
    foreach(['public_id','owner_user_id','agent_namespace','status','started_at','last_activity_at','last_heartbeat_at','active_seconds','idle_seconds','paused_seconds','interaction_count','resume_count','current_surface','current_context_key','current_conversation_id','last_actions_json'] as $column){
        if(!column_exists('agent_live_sessions_v2370',$column))return false;
    }
    foreach(['session_id','owner_user_id','segment_type','surface','started_at','ended_at','duration_seconds'] as $column){
        if(!column_exists('agent_live_session_segments_v2370',$column))return false;
    }
    return true;
}

function vp3_live_session_ensure_schema_v2370(?PDO $pdo=null): void
{
    $pdo=$pdo?:db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_live_sessions_v2370 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      public_id CHAR(36) NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      agent_namespace VARCHAR(80) NOT NULL DEFAULT 'system',
      status VARCHAR(24) NOT NULL DEFAULT 'active',
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resumed_at DATETIME NULL,
      last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_heartbeat_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ended_at DATETIME NULL,
      active_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
      idle_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
      paused_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
      interaction_count INT UNSIGNED NOT NULL DEFAULT 0,
      resume_count INT UNSIGNED NOT NULL DEFAULT 0,
      current_surface VARCHAR(30) NOT NULL DEFAULT 'chat',
      current_context_key VARCHAR(120) NOT NULL DEFAULT '',
      current_conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      current_project_ref VARCHAR(190) NOT NULL DEFAULT '',
      current_task_ref VARCHAR(190) NOT NULL DEFAULT '',
      current_goal_ref VARCHAR(190) NOT NULL DEFAULT '',
      last_user_action VARCHAR(500) NOT NULL DEFAULT '',
      last_agent_action VARCHAR(500) NOT NULL DEFAULT '',
      last_tool_action VARCHAR(500) NOT NULL DEFAULT '',
      last_browser_action VARCHAR(500) NOT NULL DEFAULT '',
      last_external_event VARCHAR(500) NOT NULL DEFAULT '',
      last_action_at DATETIME NULL,
      state_json LONGTEXT NULL,
      last_actions_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_agent_live_session_public_v2370 (public_id),
      INDEX idx_agent_live_session_owner_status_v2370 (owner_user_id,status,last_heartbeat_at,id),
      INDEX idx_agent_live_session_owner_started_v2370 (owner_user_id,started_at,id),
      CONSTRAINT fk_agent_live_session_owner_v2370 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_live_session_segments_v2370 (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      session_id BIGINT UNSIGNED NOT NULL,
      owner_user_id INT UNSIGNED NOT NULL,
      segment_type VARCHAR(24) NOT NULL,
      surface VARCHAR(30) NOT NULL DEFAULT 'chat',
      context_key VARCHAR(120) NOT NULL DEFAULT '',
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      ended_at DATETIME NULL,
      duration_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
      reason VARCHAR(120) NOT NULL DEFAULT '',
      object_refs_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_agent_live_segment_session_v2370 (session_id,started_at,id),
      INDEX idx_agent_live_segment_owner_v2370 (owner_user_id,started_at,id),
      INDEX idx_agent_live_segment_open_v2370 (session_id,ended_at,id),
      CONSTRAINT fk_agent_live_segment_session_v2370 FOREIGN KEY (session_id) REFERENCES agent_live_sessions_v2370(id) ON DELETE CASCADE,
      CONSTRAINT fk_agent_live_segment_owner_v2370 FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_live_session_agent_namespace_v2370(PDO $pdo,array $user,array $context=[]): string
{
    $agentId=max(0,(int)($context['user_agent_id']??$context['agent_id']??0));
    if(function_exists('vp3_cognitive_agent_namespace_v500')){
        try{return vp3_cognitive_agent_namespace_v500($pdo,$user,$agentId);}catch(Throwable $e){}
    }
    return $agentId>0?'agent:'.$agentId:'system';
}

function vp3_live_session_segment_type_v2370(string $state): string
{
    return match($state){
        'working'=>'active',
        'paused'=>'paused',
        'idle'=>'idle',
        default=>'active',
    };
}

function vp3_live_session_project_ref_v2370(array $context): string
{
    $project=max(0,(int)($context['project_id']??0));
    if($project>0)return 'project:'.$project;
    $track=max(0,(int)($context['track_id']??0));
    return $track>0?'track:'.$track:'';
}

function vp3_live_session_task_ref_v2370(array $context): string
{
    $key=trim((string)($context['task_key']??''));
    if($key!=='')return mb_strimwidth($key,0,190,'');
    $title=trim((string)($context['task_title']??''));
    return $title!==''?'title:'.mb_strimwidth($title,0,180,''):'';
}

function vp3_live_session_goal_ref_v2370(array $context): string
{
    $key=trim((string)($context['goal_key']??$context['goal_id']??''));
    if($key!=='')return 'goal:'.mb_strimwidth($key,0,180,'');
    $title=trim((string)($context['goal_title']??''));
    return $title!==''?'title:'.mb_strimwidth($title,0,180,''):'';
}

function vp3_live_session_last_meaningful_at_v2370(array $session): int
{
    $times=[];
    foreach(['last_activity_at','last_action_at','started_at'] as $field){
        $ts=strtotime((string)($session[$field]??''))?:0;
        if($ts>0)$times[]=$ts;
    }
    return $times?max($times):0;
}

function vp3_live_session_object_refs_v2370(array $session,array $context=[]): array
{
    $refs=[['type'=>'live_session','id'=>(string)($session['public_id']??''),'scope'=>'personal']];
    $conversation=max(0,(int)($context['conversation_id']??$session['current_conversation_id']??0));
    if($conversation>0)$refs[]=['type'=>'conversation','id'=>(string)$conversation,'scope'=>'personal'];
    $project=vp3_live_session_project_ref_v2370($context);
    if($project!==''){
        [$type,$id]=array_pad(explode(':',$project,2),2,'');
        if($type!==''&&$id!=='')$refs[]=['type'=>$type,'id'=>$id,'scope'=>'personal'];
    }
    return $refs;
}

function vp3_live_session_public_v2370(array $row): array
{
    $state=json_decode((string)($row['state_json']??''),true);if(!is_array($state))$state=[];
    $actions=json_decode((string)($row['last_actions_json']??''),true);if(!is_array($actions))$actions=[];
    return [
        'id'=>(int)($row['id']??0),
        'session_id'=>(string)($row['public_id']??''),
        'agent_namespace'=>(string)($row['agent_namespace']??'system'),
        'status'=>(string)($row['status']??'active'),
        'started_at'=>(string)($row['started_at']??''),
        'resumed_at'=>(string)($row['resumed_at']??''),
        'last_activity_at'=>(string)($row['last_activity_at']??''),
        'last_heartbeat_at'=>(string)($row['last_heartbeat_at']??''),
        'ended_at'=>(string)($row['ended_at']??''),
        'active_seconds'=>(int)($row['active_seconds']??0),
        'idle_seconds'=>(int)($row['idle_seconds']??0),
        'paused_seconds'=>(int)($row['paused_seconds']??0),
        'interaction_count'=>(int)($row['interaction_count']??0),
        'resume_count'=>(int)($row['resume_count']??0),
        'current_surface'=>(string)($row['current_surface']??'chat'),
        'current_context_key'=>(string)($row['current_context_key']??''),
        'current_conversation_id'=>(int)($row['current_conversation_id']??0),
        'current_project_ref'=>(string)($row['current_project_ref']??''),
        'current_task_ref'=>(string)($row['current_task_ref']??''),
        'current_goal_ref'=>(string)($row['current_goal_ref']??''),
        'last_user_action'=>(string)($row['last_user_action']??''),
        'last_agent_action'=>(string)($row['last_agent_action']??''),
        'last_tool_action'=>(string)($row['last_tool_action']??''),
        'last_browser_action'=>(string)($row['last_browser_action']??''),
        'last_external_event'=>(string)($row['last_external_event']??''),
        'last_action_at'=>(string)($row['last_action_at']??''),
        'state'=>$state,
        'last_actions'=>$actions,
        'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370,
    ];
}

function vp3_live_session_open_row_v2370(PDO $pdo,int $uid,bool $lock=false): ?array
{
    if($uid<1||!vp3_live_session_schema_ready_v2370($pdo))return null;
    $sql="SELECT * FROM agent_live_sessions_v2370 WHERE owner_user_id=? AND ended_at IS NULL AND status IN ('active','idle','paused') ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$uid]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_live_session_open_segment_v2370(PDO $pdo,int $sessionId,bool $lock=false): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM agent_live_session_segments_v2370 WHERE session_id=? AND ended_at IS NULL ORDER BY id DESC LIMIT 1".($lock?' FOR UPDATE':''));
    $stmt->execute([$sessionId]);$row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_live_session_close_segment_v2370(PDO $pdo,array $segment,string $endedAt): int
{
    $id=(int)($segment['id']??0);$sessionId=(int)($segment['session_id']??0);if($id<1||$sessionId<1)return 0;
    $start=strtotime((string)($segment['started_at']??''))?:strtotime($endedAt);$end=strtotime($endedAt)?:time();
    $duration=max(0,$end-$start);
    $pdo->prepare("UPDATE agent_live_session_segments_v2370 SET ended_at=?,duration_seconds=? WHERE id=? AND ended_at IS NULL")->execute([$endedAt,$duration,$id]);
    $column=match((string)($segment['segment_type']??'')){'idle'=>'idle_seconds','paused'=>'paused_seconds',default=>'active_seconds'};
    $pdo->exec("UPDATE agent_live_sessions_v2370 SET {$column}={$column}+".(int)$duration." WHERE id=".(int)$sessionId);
    return $duration;
}

function vp3_live_session_insert_segment_v2370(PDO $pdo,array $session,string $segmentType,string $surface,string $contextKey,string $reason,array $objectRefs=[]): int
{
    $stmt=$pdo->prepare("INSERT INTO agent_live_session_segments_v2370 (session_id,owner_user_id,segment_type,surface,context_key,reason,object_refs_json,started_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())");
    $stmt->execute([(int)$session['id'],(int)$session['owner_user_id'],$segmentType,$surface,$contextKey,mb_strimwidth($reason,0,120,''),vp3_live_session_json_v2370($objectRefs)]);
    return (int)$pdo->lastInsertId();
}

function vp3_live_session_record_event_v2370(PDO $pdo,array $user,array $session,string $type,array $payload=[],array $objectRefs=[]): void
{
    if(!function_exists('agent_event_ingest_v1920')||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo))return;
    $uid=(int)($user['id']??0);if($uid<1)return;
    $payload=[
        'session_id'=>(string)($session['public_id']??''),
        'agent_namespace'=>(string)($session['agent_namespace']??'system'),
        'object_refs'=>$objectRefs?:vp3_live_session_object_refs_v2370($session,$payload),
    ]+$payload;
    $eventKey=(string)($session['public_id']??'').'|'.$type.'|'.gmdate('YmdHis').'|'.substr(hash('sha256',vp3_live_session_json_v2370($payload)),0,16);
    try{
        $ingest=agent_event_ingest_v1920($pdo,$uid,'live_session',$type,$payload,[
            'verification_status'=>'trusted',
            'external_event_id'=>mb_strimwidth($eventKey,0,190,''),
            'occurred_at'=>gmdate('c'),
        ]);
        if(empty($ingest['duplicate'])&&is_array($ingest['event']??null)){
            $eventId=(int)($ingest['event']['id']??0);
            if($eventId>0)$pdo->prepare("UPDATE agent_event_inbox SET processing_status='processed',processed_at=UTC_TIMESTAMP(),last_error_code='',last_error_message='' WHERE id=? AND owner_user_id=?")->execute([$eventId,$uid]);
        }
    }catch(Throwable $e){
        error_log('VP3 v23.70 live-session event record failed: '.$e->getMessage());
    }
}

function vp3_live_session_start_v2370(PDO $pdo,array $user,string $surface='chat',string $state='working',array $context=[],string $reason='activity'): array
{
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in account is required.');
    $surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $status=in_array($state,['working','paused','idle'],true)?($state==='working'?'active':$state):'active';
    $namespace=vp3_live_session_agent_namespace_v2370($pdo,$user,$context);
    $contextKey=function_exists('agent_activity_v94_context_key')?agent_activity_v94_context_key($surface,$context):$surface;
    $stateJson=[
        'task_title'=>mb_strimwidth(trim((string)($context['task_title']??'')),0,190,''),
        'task_kind'=>mb_strimwidth(trim((string)($context['task_kind']??'')),0,60,''),
        'track_id'=>max(0,(int)($context['track_id']??0)),
        'project_id'=>max(0,(int)($context['project_id']??0)),
        'path'=>mb_strimwidth(trim((string)($context['path']??'')),0,500,''),
    ];
    $stmt=$pdo->prepare("INSERT INTO agent_live_sessions_v2370 (public_id,owner_user_id,agent_namespace,status,current_surface,current_context_key,current_conversation_id,current_project_ref,current_task_ref,current_goal_ref,state_json,last_actions_json,started_at,last_activity_at,last_heartbeat_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([
        vp3_live_session_uuid_v2370(),$uid,$namespace,$status,$surface,$contextKey,max(0,(int)($context['conversation_id']??0)),
        vp3_live_session_project_ref_v2370($context),vp3_live_session_task_ref_v2370($context),vp3_live_session_goal_ref_v2370($context),vp3_live_session_json_v2370($stateJson),'{}'
    ]);
    $id=(int)$pdo->lastInsertId();
    $row=$pdo->query("SELECT * FROM agent_live_sessions_v2370 WHERE id={$id}")->fetch()?:[];
    vp3_live_session_insert_segment_v2370($pdo,$row,vp3_live_session_segment_type_v2370($state),$surface,$contextKey,$reason,vp3_live_session_object_refs_v2370($row,$context));
    vp3_live_session_record_event_v2370($pdo,$user,$row,'session.started',['surface'=>$surface,'state'=>$status,'reason'=>$reason],vp3_live_session_object_refs_v2370($row,$context));
    return $row;
}

function vp3_live_session_finalize_v2370(PDO $pdo,array $user,array $session,string $reason='ended',?string $endedAt=null): array
{
    $uid=(int)($user['id']??0);if($uid<1||empty($session['id']))return $session;
    $endedAt=$endedAt?:gmdate('Y-m-d H:i:s');
    $segment=vp3_live_session_open_segment_v2370($pdo,(int)$session['id']);
    if($segment)vp3_live_session_close_segment_v2370($pdo,$segment,$endedAt);
    $pdo->prepare("UPDATE agent_live_sessions_v2370 SET status='ended',ended_at=?,last_heartbeat_at=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=?")->execute([$endedAt,$endedAt,(int)$session['id'],$uid]);
    $session['status']='ended';$session['ended_at']=$endedAt;
    vp3_live_session_record_event_v2370($pdo,$user,$session,'session.ended',['reason'=>$reason],vp3_live_session_object_refs_v2370($session));
    $stmt=$pdo->prepare('SELECT * FROM agent_live_sessions_v2370 WHERE id=? AND owner_user_id=? LIMIT 1');$stmt->execute([(int)$session['id'],$uid]);$row=$stmt->fetch();
    return is_array($row)?$row:$session;
}

function vp3_live_session_current_v2370(PDO $pdo,array $user,array $context=[],bool $create=true): ?array
{
    $uid=(int)($user['id']??0);if($uid<1||!vp3_live_session_schema_ready_v2370($pdo))return null;
    $row=vp3_live_session_open_row_v2370($pdo,$uid);
    if($row){
        $last=vp3_live_session_last_meaningful_at_v2370($row);
        if($last>0&&time()-$last>VP3_LIVE_SESSION_STALE_SECONDS_V2370){
            $cutoff=gmdate('Y-m-d H:i:s',$last+VP3_LIVE_SESSION_STALE_SECONDS_V2370);
            vp3_live_session_finalize_v2370($pdo,$user,$row,'stale_session_expired',$cutoff);
            $row=null;
        }
    }
    if(!$row&&$create)$row=vp3_live_session_start_v2370($pdo,$user,(string)($context['surface']??'chat'),'working',$context,'session_created');
    return $row;
}

function vp3_live_session_record_activity_v2370(array $user,string $surface,string $state,array $context=[],string $reason='heartbeat',bool $changed=false): array
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!vp3_live_session_schema_ready_v2370($pdo))return ['ready'=>false,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    $surface=preg_replace('/[^a-z0-9_-]/','',strtolower($surface))?:'chat';
    $state=in_array($state,['working','paused','idle','logged_out'],true)?$state:'idle';
    $context['surface']=$surface;

    try{
        $pdo->beginTransaction();
        $session=vp3_live_session_open_row_v2370($pdo,$uid,true);
        if($session){
            $last=vp3_live_session_last_meaningful_at_v2370($session);
            if($last>0&&time()-$last>VP3_LIVE_SESSION_STALE_SECONDS_V2370){
                $pdo->commit();
                vp3_live_session_finalize_v2370($pdo,$user,$session,'stale_session_expired',gmdate('Y-m-d H:i:s',$last+VP3_LIVE_SESSION_STALE_SECONDS_V2370));
                $session=null;
                $pdo->beginTransaction();
            }
        }
        if(!$session){
            $pdo->commit();
            if($state==='logged_out')return ['ready'=>true,'ended'=>true,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
            $session=vp3_live_session_start_v2370($pdo,$user,$surface,$state,$context,$reason);
            return vp3_live_session_snapshot_v2370($pdo,$user,false);
        }

        if($state==='logged_out'){
            $pdo->commit();vp3_live_session_finalize_v2370($pdo,$user,$session,'explicit_logout');
            return ['ready'=>true,'ended'=>true,'session_id'=>(string)$session['public_id'],'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
        }

        $contextKey=function_exists('agent_activity_v94_context_key')?agent_activity_v94_context_key($surface,$context):$surface;
        $segmentType=vp3_live_session_segment_type_v2370($state);
        $open=vp3_live_session_open_segment_v2370($pdo,(int)$session['id'],true);
        $previousType=(string)($open['segment_type']??($session['status']==='idle'?'idle':($session['status']==='paused'?'paused':'active')));
        $surfaceChanged=(string)($session['current_surface']??'')!==$surface;
        $contextChanged=(string)($session['current_context_key']??'')!==$contextKey;
        $projectRef=vp3_live_session_project_ref_v2370($context);
        $taskRef=vp3_live_session_task_ref_v2370($context);
        $goalRef=vp3_live_session_goal_ref_v2370($context);
        $focusChanged=(string)($session['current_project_ref']??'')!==$projectRef
            ||(string)($session['current_task_ref']??'')!==$taskRef
            ||(string)($session['current_goal_ref']??'')!==$goalRef;
        $segmentChanged=!$open||$previousType!==$segmentType||$surfaceChanged||$contextChanged||$focusChanged;

        if($segmentChanged&&$open)vp3_live_session_close_segment_v2370($pdo,$open,gmdate('Y-m-d H:i:s'));
        if($segmentChanged)vp3_live_session_insert_segment_v2370($pdo,$session,$segmentType,$surface,$contextKey,$reason,vp3_live_session_object_refs_v2370($session,$context));

        $resumed=in_array($previousType,['idle','paused'],true)&&$segmentType==='active';
        $status=$segmentType==='active'?'active':$segmentType;
        $stateJson=json_decode((string)($session['state_json']??''),true);if(!is_array($stateJson))$stateJson=[];
        $stateJson=array_replace($stateJson,[
            'task_title'=>mb_strimwidth(trim((string)($context['task_title']??'')),0,190,''),
            'task_kind'=>mb_strimwidth(trim((string)($context['task_kind']??'')),0,60,''),
            'track_id'=>max(0,(int)($context['track_id']??0)),
            'project_id'=>max(0,(int)($context['project_id']??0)),
            'goal_ref'=>$goalRef,
            'path'=>mb_strimwidth(trim((string)($context['path']??'')),0,500,''),
            'visible'=>!empty($context['visible']),
        ]);
        $sql="UPDATE agent_live_sessions_v2370 SET agent_namespace=?,status=?,current_surface=?,current_context_key=?,current_conversation_id=?,current_project_ref=?,current_task_ref=?,current_goal_ref=?,state_json=?,last_heartbeat_at=UTC_TIMESTAMP()";
        $params=[
            vp3_live_session_agent_namespace_v2370($pdo,$user,$context),$status,$surface,$contextKey,max(0,(int)($context['conversation_id']??0)),
            $projectRef,$taskRef,$goalRef,vp3_live_session_json_v2370($stateJson)
        ];
        if($segmentType==='active')$sql.=",last_activity_at=UTC_TIMESTAMP()";
        if($resumed){$sql.=",resumed_at=UTC_TIMESTAMP(),resume_count=resume_count+1";}
        $sql.=" WHERE id=? AND owner_user_id=?";$params[]=(int)$session['id'];$params[]=$uid;
        $pdo->prepare($sql)->execute($params);
        $pdo->commit();

        $fresh=vp3_live_session_open_row_v2370($pdo,$uid)?:$session;
        $refs=vp3_live_session_object_refs_v2370($fresh,$context);
        if($previousType!=='idle'&&$segmentType==='idle')vp3_live_session_record_event_v2370($pdo,$user,$fresh,'session.idle_started',['surface'=>$surface,'context_key'=>$contextKey,'reason'=>$reason],$refs);
        if($previousType==='idle'&&$segmentType==='active')vp3_live_session_record_event_v2370($pdo,$user,$fresh,'session.idle_ended',['surface'=>$surface,'context_key'=>$contextKey,'reason'=>$reason],$refs);
        if($resumed)vp3_live_session_record_event_v2370($pdo,$user,$fresh,'session.resumed',['surface'=>$surface,'context_key'=>$contextKey,'reason'=>$reason],$refs);
        if($surfaceChanged)vp3_live_session_record_event_v2370($pdo,$user,$fresh,'session.surface_changed',['from'=>(string)($session['current_surface']??''),'to'=>$surface,'reason'=>$reason],$refs);
        if(($contextChanged||$focusChanged)&&!$surfaceChanged)vp3_live_session_record_event_v2370($pdo,$user,$fresh,'session.focus_changed',['context_key'=>$contextKey,'project_ref'=>$projectRef,'task_ref'=>$taskRef,'goal_ref'=>$goalRef,'reason'=>$reason],$refs);

        return vp3_live_session_snapshot_v2370($pdo,$user,false);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('VP3 v23.70 live-session activity failed: '.$e->getMessage());
        return ['ready'=>false,'error'=>'session_update_failed','build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    }
}

function vp3_live_session_note_action_v2370(array $user,string $actor,string $eventType,string $summary,array $context=[]): array
{
    $pdo=db();$uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||!vp3_live_session_schema_ready_v2370($pdo))return ['ready'=>false,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    $actor=in_array($actor,['user','agent','tool','browser','external'],true)?$actor:'external';
    $eventType=preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/',strtolower(trim($eventType)))?strtolower(trim($eventType)):'session.action';
    $summary=mb_strimwidth(preg_replace('/\s+/u',' ',trim($summary))??'',0,500,'');
    $context['surface']=(string)($context['surface']??'chat');

    try{
        if($actor==='user'){
            vp3_live_session_record_activity_v2370($user,(string)$context['surface'],'working',$context,'user_action',false);
        }
        $session=vp3_live_session_current_v2370($pdo,$user,$context,true);if(!$session)return ['ready'=>false,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
        $actions=json_decode((string)($session['last_actions_json']??''),true);if(!is_array($actions))$actions=[];
        $actions[$actor]=['event_type'=>$eventType,'summary'=>$summary,'at'=>gmdate('c')];
        $actions=array_intersect_key($actions,array_flip(['user','agent','tool','browser','external']));
        $column=match($actor){'user'=>'last_user_action','agent'=>'last_agent_action','tool'=>'last_tool_action','browser'=>'last_browser_action',default=>'last_external_event'};
        $sql="UPDATE agent_live_sessions_v2370 SET {$column}=?,last_actions_json=?,last_action_at=UTC_TIMESTAMP(),last_heartbeat_at=UTC_TIMESTAMP(),interaction_count=interaction_count+1";
        $params=[$summary,vp3_live_session_json_v2370($actions)];
        if($actor==='user')$sql.=",last_activity_at=UTC_TIMESTAMP()";
        if(isset($context['conversation_id'])){$sql.=",current_conversation_id=?";$params[]=max(0,(int)$context['conversation_id']);}
        if(isset($context['knowledge_scope'])){
            $state=json_decode((string)($session['state_json']??''),true);if(!is_array($state))$state=[];
            $state['knowledge_scope']=$context['knowledge_scope'];$sql.=",state_json=?";$params[]=vp3_live_session_json_v2370($state);
        }
        $sql.=" WHERE id=? AND owner_user_id=?";$params[]=(int)$session['id'];$params[]=$uid;
        $pdo->prepare($sql)->execute($params);
        $fresh=vp3_live_session_open_row_v2370($pdo,$uid)?:$session;
        vp3_live_session_record_event_v2370($pdo,$user,$fresh,$eventType,[
            'actor'=>$actor,'summary'=>$summary,'surface'=>(string)($context['surface']??'chat'),'conversation_id'=>max(0,(int)($context['conversation_id']??0))
        ],vp3_live_session_object_refs_v2370($fresh,$context));
        return vp3_live_session_public_v2370($fresh);
    }catch(Throwable $e){
        error_log('VP3 v23.70 live-session action failed: '.$e->getMessage());
        return ['ready'=>false,'error'=>'session_action_failed','build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    }
}

function vp3_live_session_snapshot_v2370(PDO $pdo,array $user,bool $create=false): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!vp3_live_session_schema_ready_v2370($pdo))return ['ready'=>false,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    $session=vp3_live_session_current_v2370($pdo,$user,[], $create);
    if(!$session)return ['ready'=>true,'session'=>null,'segments'=>[],'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
    $limit=VP3_LIVE_SESSION_SEGMENT_LIMIT_V2370;
    $stmt=$pdo->prepare("SELECT segment_type,surface,context_key,started_at,ended_at,duration_seconds,reason FROM agent_live_session_segments_v2370 WHERE session_id=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([(int)$session['id']]);$segments=array_reverse($stmt->fetchAll()?:[]);
    $now=time();
    foreach($segments as &$segment){
        if(empty($segment['ended_at'])){
            $start=strtotime((string)($segment['started_at']??''))?:$now;
            $segment['duration_seconds']=max(0,$now-$start);
        }else $segment['duration_seconds']=(int)($segment['duration_seconds']??0);
    }unset($segment);
    $public=vp3_live_session_public_v2370($session);
    $open=end($segments);
    if(is_array($open)&&empty($open['ended_at'])){
        $duration=(int)($open['duration_seconds']??0);
        if(($open['segment_type']??'')==='idle')$public['idle_seconds']+=$duration;
        elseif(($open['segment_type']??'')==='paused')$public['paused_seconds']+=$duration;
        else $public['active_seconds']+=$duration;
    }
    return ['ready'=>true,'session'=>$public,'segments'=>$segments,'build'=>VP3_COGNITIVE_LIVE_SESSION_V2370];
}

function vp3_live_session_context_item_v2370(array $user): ?array
{
    $pdo=db();if(!$pdo||!vp3_live_session_schema_ready_v2370($pdo))return null;
    try{$snapshot=vp3_live_session_snapshot_v2370($pdo,$user,false);}catch(Throwable $e){return null;}
    $session=is_array($snapshot['session']??null)?$snapshot['session']:null;if(!$session)return null;
    $started=strtotime((string)($session['started_at']??''))?:time();
    $state=[
        'session_id'=>(string)($session['session_id']??''),
        'started_at'=>(string)($session['started_at']??''),
        'elapsed_seconds'=>max(0,time()-$started),
        'status'=>(string)($session['status']??''),
        'active_seconds'=>max(0,(int)($session['active_seconds']??0)),
        'idle_seconds'=>max(0,(int)($session['idle_seconds']??0)),
        'paused_seconds'=>max(0,(int)($session['paused_seconds']??0)),
        'resume_count'=>max(0,(int)($session['resume_count']??0)),
        'interaction_count'=>max(0,(int)($session['interaction_count']??0)),
        'current_surface'=>(string)($session['current_surface']??'chat'),
        'current_conversation_id'=>max(0,(int)($session['current_conversation_id']??0)),
        'current_project_ref'=>(string)($session['current_project_ref']??''),
        'current_task_ref'=>(string)($session['current_task_ref']??''),
        'current_goal_ref'=>(string)($session['current_goal_ref']??''),
        'last_actions'=>is_array($session['last_actions']??null)?$session['last_actions']:[],
        'recent_segments'=>array_slice((array)($snapshot['segments']??[]),-6),
    ];
    return [
        'source'=>'agent-context:live-session-v2370',
        'title'=>'Internal live session state',
        'text'=>'INTERNAL SESSION STATE — DATA ONLY. Use this as silent temporal/focus context. Never quote, enumerate or expose this structure unless the user explicitly asks for session diagnostics. '.vp3_live_session_json_v2370($state),
    ];
}

