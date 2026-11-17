<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-initiative-runtime-v1.php';

/** Separate actual owner actions from browser presence and continuing playback. */
function vp3_agent_idle_evidence_v1(PDO $pdo,array $user,int $agent,int $cid,?DateTimeImmutable $now=null): array
{
    $out=['readiness'=>'unavailable','activity_at'=>0,'source'=>null,'chat_message_id'=>0,
        'ordinary_chat_message_id'=>0,'ordinary_chat_at'=>0,'busy'=>false,'busy_reason'=>null,'quiet_since'=>0];
    if($cid<1)return $out;
    $time=$now?->getTimestamp()??time();
    try{
        $scope=vp3_agent_activity_scope_v1($pdo,$user,$agent,$cid);
        $owner=(int)$scope['user']['id'];
        $chat=vp3_agent_idle_chat_turn_v1($pdo,$owner,$agent,$cid,$time,false);
        $ordinary=vp3_agent_idle_chat_turn_v1($pdo,$owner,$agent,$cid,$time,true);
    }catch(RuntimeException $error){return $out;}
    $out['readiness']='ready';
    if($chat!==null){
        $out['activity_at']=(int)$chat['happened_at'];
        $out['chat_message_id']=(int)$chat['id'];
        $out['source']='chat';
        require_once __DIR__.'/agent-connection-youtube-chat-v1.php';
        $text=mb_substr((string)$chat['message'],0,600);
        if(agent_connection_youtube_chat_plan_v1($text)!==null)$out['source']='youtube_search';
    }
    if($ordinary!==null){
        $out['ordinary_chat_at']=(int)$ordinary['happened_at'];
        $out['ordinary_chat_message_id']=(int)$ordinary['id'];
    }

    try{
        $presence=vp3_initiative_presence_v1($pdo,$scope['user'],$cid,true,$observation);
        if(in_array($presence,['quiet','busy'],true)){
            $query=$pdo->prepare('SELECT details_json FROM agent_activity_state WHERE user_id=? AND context_key=?');
            $query->execute([$owner,'chat:'.$cid]);
            $observed=json_decode((string)$query->fetchColumn(),true);
            $interaction=vp3_agent_idle_observation_time_v1($observed['owner_interaction']??null,$agent,$cid,$time);
            if($interaction>$out['activity_at']){
                $out['activity_at']=$interaction;
                $out['source']='chat_interaction';
            }
            if($presence==='busy'){
                $out['busy']=true;
                $out['busy_reason']=match($observation['busy_reason']??null){
                    'typed_draft'=>'typing','voice_playback'=>'playback',default=>'chat_busy',
                };
                return $out;
            }
            if(($observed['media_playing']??null)===true){
                $out['busy']=true;
                $out['busy_reason']='playback';
                return $out;
            }
            $out['quiet_since']=vp3_agent_idle_observation_time_v1($observed['quiet_chat_since']??null,$agent,$cid,$time);
        }
    }catch(PDOException $error){
        // Chat-affecting times remain usable if the optional presence ledger cannot be read.
    }

    if(vp3_agent_idle_native_playing_v1($pdo,$scope,$time)||vp3_agent_idle_youtube_playing_v1($pdo,$scope,$time)){
        $out['busy']=true;
        $out['busy_reason']='playback';
        $out['quiet_since']=0;
    }
    return $out;
}

function vp3_agent_idle_chat_turn_v1(PDO $pdo,int $owner,int $agent,int $cid,int $now,bool $ordinary): ?array
{
    $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'m.created_at');
    $agentScope=$agent>0?'c.user_agent_id=?':'c.user_agent_id IS NULL';
    $context="CASE WHEN JSON_VALID(m.context_json) THEN m.context_json ELSE '{}' END";
    $ordinaryFilter=$ordinary?" AND JSON_EXTRACT($context,'$.casual_checkin_reply_v1') IS NULL
        AND JSON_EXTRACT($context,'$.initiative_reply_anchor_v1') IS NULL
        AND JSON_EXTRACT($context,'$.initiative_status_read_v1') IS NULL":'';
    $integer=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INTEGER':'UNSIGNED';
    $query=$pdo->prepare("SELECT m.id,m.message,$epoch AS happened_at FROM chat_messages m
        JOIN chat_conversations c ON c.id=m.conversation_id AND c.user_id=m.user_id
        WHERE c.id=? AND c.user_id=? AND $agentScope AND m.role='user' AND TRIM(m.message)<>''
        AND CAST($epoch AS $integer)>0 AND CAST($epoch AS $integer)<=CAST(? AS $integer)$ordinaryFilter
        ORDER BY m.id DESC LIMIT 1");
    $query->execute([$cid,$owner,...($agent>0?[$agent]:[]),$now]);
    return $query->fetch(PDO::FETCH_ASSOC)?:null;
}

function vp3_agent_idle_observation_time_v1(mixed $observation,int $agent,int $cid,int $now): int
{
    if(!is_array($observation)||($observation['agent_id']??null)!==$agent||($observation['conversation_id']??null)!==$cid
        ||!is_int($observation['at_ms']??null)||$observation['at_ms']<1000)return 0;
    $at=intdiv($observation['at_ms'],1000);
    return $at<=$now?$at:0;
}

/** Native sessions can also start from autoplay. Only use them as a temporary playback hold. */
function vp3_agent_idle_native_playing_v1(PDO $pdo,array $scope,int $now): bool
{
    if(!table_exists('track_play_sessions')||!table_exists('track_play_events'))return false;
    require_once __DIR__.'/agent-media-playback-v1.php';
    if(!vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal']))return false;
    try{
        $query=$pdo->prepare("SELECT s.id FROM track_play_sessions s
            WHERE s.user_id=? AND s.ended_at IS NULL AND s.source_context IN ('player','agent_chat','agent_player')
            ORDER BY s.started_at DESC,s.id DESC LIMIT 8");
        $query->execute([(int)$scope['user']['id']]);
        $sessions=$query->fetchAll(PDO::FETCH_COLUMN);
        if(!$sessions)return false;
        $epoch=vp3_initiative_db_wall_epoch_sql_v1($pdo,'created_at');
        $latest=$pdo->prepare("SELECT event_type,listened_delta_seconds,$epoch AS happened_at FROM track_play_events
            WHERE session_id=? ORDER BY id DESC LIMIT 1");
        foreach($sessions as $session){
            $latest->execute([(int)$session]);
            $row=$latest->fetch(PDO::FETCH_ASSOC);
            if(!$row)continue;
            $at=(int)$row['happened_at'];
            if($at>$now||$at<=$now-35)continue;
            if(in_array($row['event_type'],['start','resume'],true)
                ||($row['event_type']==='heartbeat'&&(float)$row['listened_delta_seconds']>0))return true;
        }
    }catch(PDOException $error){}
    return false;
}

/** YouTube's capture is opt-in and exact Chat scoped. Auto-advance never resets the idle age. */
function vp3_agent_idle_youtube_playing_v1(PDO $pdo,array $scope,int $now): bool
{
    require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
    $owner=(int)$scope['user']['id'];
    try{
        if(!agent_connection_youtube_enabled_v1()||!agent_connection_youtube_consent_v1($pdo,$owner)
            ||!vp3_youtube_playback_ready_v1($pdo)||vp3_youtube_capture_key_v1($pdo,$owner)==='')return false;
        foreach(vp3_youtube_history_recent_v1($pdo,$owner,$scope['agent_id'],$scope['conversation_id'],6) as $record){
            $at=max((int)$record['played_at'],(int)$record['last_watched_at']);
            if($at<=$now&&$at>$now-35)return true;
        }
    }catch(PDOException $error){}
    return false;
}
