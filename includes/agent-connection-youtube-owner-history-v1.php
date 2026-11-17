<?php
declare(strict_types=1);

require_once __DIR__.'/agent-activity-scope-v1.php';
require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/agent-media-playback-v1.php';
require_once __DIR__.'/agent-public-research-plan-v1.php';
require_once __DIR__.'/agent-initiative-opportunity-sources-v1.php';

/** One private projection of real VP3 activity for the rail and Top of Mind. */
function vp3_youtube_owner_activity_v1(PDO $pdo,array $user,int $agentId,int $cid,?int $now=null,bool $includeReturnedResults=false): array
{
    $empty=['status'=>'unavailable','searches'=>[],'plays'=>[]];
    $now??=time();
    try{
        $history=vp3_youtube_owner_history_scope_v1($pdo,$user,$agentId,$cid,$now);
        if($history===null)return $empty;
        $searches=array_slice(vp3_youtube_owner_history_searches_v1($pdo,$history,$now,includeReturnedResults:$includeReturnedResults),0,12);
    }catch(RuntimeException){
        return $empty;
    }
    $coverage=[];
    try{
        $plays=array_slice(vp3_youtube_owner_history_plays_v1($pdo,$history,$now,includeReturnedResults:$includeReturnedResults),0,12);
    }catch(PDOException){
        $plays=[];
        $coverage=['played_status'=>'unavailable'];
    }
    return ['status'=>'ready',
        'searches'=>array_map(static fn(array $row):array=>array_diff_key($row,['user_message_id'=>true]),$searches),
        'plays'=>array_map(static fn(array $row):array=>array_diff_key($row,['results'=>true,'created_at_epoch'=>true]),$plays)]+$coverage;
}

function vp3_youtube_owner_history_scope_v1(PDO $pdo,array $user,int $agentId,int $cid,int $now): ?array
{
    if($cid<0||$agentId<0||vp3_participant_scope_v1()!==null||!agent_connection_youtube_enabled_v1()
        ||!agent_connection_youtube_consent_ready_v1($pdo)||!agent_connection_youtube_results_ready_v1($pdo))return null;
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agentId,$cid);
    $owner=(int)$scope['user']['id'];
    $consent=$pdo->prepare('SELECT accepted_at FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=? AND policy_version=?');
    $consent->execute([$owner,VP3_YOUTUBE_CONSENT_POLICY_V1]);
    $accepted=(int)$consent->fetchColumn();
    if($accepted<1||$accepted>$now)return null;
    $allowHistory=vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal']);
    return $scope+['owner_id'=>$owner,'accepted_at'=>$accepted,'allow_history'=>$allowHistory,
        'playback_ready'=>vp3_youtube_playback_ready_v1($pdo)];
}

/** Read owner-authored requests, with provider titles only while the matching result cache is live. */
function vp3_youtube_owner_history_searches_v1(PDO $pdo,array $scope,int $now,?array $ids=null,bool $recordedPlayback=false,
    bool $includeReturnedResults=false): array
{
    if($ids===[]||($recordedPlayback&&$ids===null))return [];
    $owner=$scope['owner_id'];
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $json="CASE WHEN JSON_VALID(m.context_json) THEN m.context_json ELSE '{}' END";
    $turn="JSON_EXTRACT($json,'$.chat_user_message_id')";
    $marker="JSON_EXTRACT($json,'$.youtube_search_results_v1')";
    $epoch=$mysql?'UNIX_TIMESTAMP(m.created_at)':"CAST(strftime('%s',m.created_at) AS INTEGER)";
    $int=$mysql?'UNSIGNED':'INTEGER';
    [$agentSql,$agentArgs]=$scope['allow_history']?['1=1',[]]:vp3_agent_chat_scope_sql_v380($scope['agent'],'c');
    $exact=$ids===null?'':' AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
    $query=$pdo->prepare("SELECT m.id AS message_id,c.id AS conversation_id,c.user_agent_id AS source_agent_id,
        $epoch AS created_at_epoch,m.context_json,u.id AS user_message_id,u.message AS request,u.context_json AS request_context,
        r.expires_at,r.payload_json
        FROM chat_conversations c
        JOIN chat_messages m ON m.conversation_id=c.id AND m.role='assistant' AND m.user_id IS NULL
        LEFT JOIN chat_messages u ON u.id=CAST($turn AS $int) AND u.role='user' AND u.user_id=c.user_id
          AND u.conversation_id=c.id AND u.id<m.id
        LEFT JOIN agent_connection_youtube_results_v1 r ON r.message_id=m.id
        WHERE c.user_id=? AND $agentSql AND CAST($epoch AS $int) BETWEEN CAST(? AS $int) AND CAST(? AS $int)
          AND $marker IS NOT NULL$exact ORDER BY m.created_at DESC,m.id DESC LIMIT 72");
    $query->execute([$owner,...$agentArgs,max($scope['accepted_at'],$now-30*86400),$now,...($ids??[])]);
    $out=[];$allowed=[$scope['agent_id']=>true];
    $videoReader=$includeReturnedResults&&!$recordedPlayback?'vp3_youtube_owner_history_preview_video_v1':'vp3_youtube_owner_history_video_v1';
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $context=json_decode((string)$row['context_json'],true,32);
        if(($context['youtube_search_results_v1']??null)!==true
            ||(($context['chat_user_message_id']??null)!==null&&($context['chat_user_message_id']??null)!==(int)$row['user_message_id']))continue;
        $payload=json_decode((string)$row['payload_json'],true,24);
        if(($payload['history']??false)===true)continue;
        $expires=min((int)$row['expires_at'],(int)($payload['expires_at']??$row['expires_at']));
        $returned=$includeReturnedResults&&vp3_youtube_owner_history_returned_result_v1($row,$context,$owner);
        if(!$returned&&!vp3_youtube_owner_history_private_turn_v1($row,$context,$owner,$recordedPlayback&&($payload['initiative_suggestion']??false)===true))continue;
        $generated=($payload['initiative_suggestion']??false)===true||($context['source']??null)==='agent_initiative';
        $queryText=agent_connection_youtube_chat_plan_v1((string)$row['request']);
        if($returned&&$expires>$now&&($queryText===null||$queryText==='')){
            $queryText=agent_connection_youtube_text_v1($payload['owner_topic']??null,180)
                ?:agent_connection_youtube_text_v1($payload['query']??null,180);
        }
        if($includeReturnedResults&&!$recordedPlayback&&strcasecmp(trim($queryText??''),'and make a playlist')===0)continue;
        if($generated||$queryText===null||!vp3_topic_research_subject_valid_v1($queryText)){
            if($recordedPlayback){
                // A real play can come from accepting an offer or following up on an older result.
                $queryText='';
            }elseif($returned&&!$generated){
                // An authorized result may have no topic suitable for a public search.
                $queryText='YouTube search';
            }else continue;
        }
        $sourceAgent=(int)($row['source_agent_id']??0);
        if(!isset($allowed[$sourceAgent])){
            // These are account-owned playback records. A named Agent's explicit
            // playback-history deny still fences both sides of a cross-Agent read.
            $allowed[$sourceAgent]=false;
            try{
                $source=vp3_agent_chat_resolve_agent_v380($pdo,$scope['user'],$sourceAgent);
                $allowed[$sourceAgent]=$scope['allow_history']&&vp3_media_playback_history_allowed_v1($pdo,$scope['user'],
                    vp3_agent_chat_principal_v380($scope['user'],$source));
            }catch(RuntimeException $error){
                if($error instanceof PDOException)throw $error;
            }
        }
        if(!$allowed[$sourceAgent])continue;
        $created=(int)$row['created_at_epoch'];
        if($row['expires_at']===null&&$created>$now-7*86400){
            // Cache cleanup cannot erase an owner-authored query with a successful Chat tool receipt.
            $receipt=vp3_tool_receipt_normalize_v1($context['tool_receipt']??null);
            if($recordedPlayback||$generated||(int)$row['user_message_id']<1
                ||($receipt['capability']??null)!=='youtube.search'||($receipt['status']??null)!=='read_complete'
                ||!vp3_topic_research_subject_valid_v1($queryText))continue;
        }
        $videos=$expires>$now&&is_array($payload['videos']??null)?array_values(array_filter(array_map(
            $videoReader,agent_connection_youtube_ordered_videos_v1($payload['videos'])))):[];
        $id=(int)$row['message_id'];
        $result=['query'=>$queryText,'videos'=>$videos];
        if($videos&&is_string($payload['suggestion_kind']??null))$result['suggestion_kind']=$payload['suggestion_kind'];
        $out[$id]=['message_id'=>$id,'conversation_id'=>(int)$row['conversation_id'],'source_agent_id'=>$sourceAgent,
            'created_at_epoch'=>$created,'results_available'=>$videos!==[],'expires_at'=>$expires>$now?$expires:null,'results'=>$result,
            'user_message_id'=>(int)$row['user_message_id']];
    }
    return $out;
}

/** A returned card can be displayed without using the reply as research or preference evidence. */
function vp3_youtube_owner_history_returned_result_v1(array $row,array $context,int $owner): bool
{
    $receipt=vp3_tool_receipt_normalize_v1($context['tool_receipt']??null);
    if(($receipt['capability']??null)!=='youtube.search'||$receipt['status']!=='read_complete'
        ||(int)$row['user_message_id']<1)return false;
    if(vp3_youtube_owner_history_private_turn_v1($row,$context,$owner,false))return true;
    if(($context['linked_reply_tool_v1']??null)!=='youtube')return false;
    $request=json_decode((string)$row['request_context'],true,24);
    $reply=$context['initiative_followthrough']??null;
    if(($request['initiative_reply_tool_v1']['tool']??null)!=='youtube'
        ||($reply['owner_user_id']??null)!==$owner||($reply['agent_id']??null)!==(int)$row['source_agent_id']
        ||($reply['conversation_id']??null)!==(int)$row['conversation_id']
        ||($reply['reply_message_id']??null)!==(int)$row['user_message_id'])return false;
    unset($request['skip_brain_archive'],$context['skip_brain_archive']);
    return vp3_initiative_opportunity_private_message_v1(json_encode($request,JSON_THROW_ON_ERROR))
        &&vp3_initiative_opportunity_private_message_v1(json_encode($context,JSON_THROW_ON_ERROR));
}

/** Match Chat's video catalog while leaving playback and its stricter evidence rules in Chat. */
function vp3_youtube_owner_history_preview_video_v1(array $video): ?array
{
    $id=$video['video_id']??null;
    if(!is_string($id)||preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id)!==1)return null;
    $title=vp3_agent_activity_text_v1(agent_connection_youtube_text_v1($video['title']??null,145),145);
    if($title==='')return null;
    return ['video_id'=>$id,'title'=>$title,
        'channel_title'=>vp3_agent_activity_text_v1(agent_connection_youtube_text_v1($video['channel_title']??null,85),85)];
}

function vp3_youtube_owner_history_private_turn_v1(array $row,array $context,int $owner,bool $acceptedPlay): bool
{
    if(vp3_initiative_opportunity_private_message_v1($row['request_context'])
        &&vp3_initiative_opportunity_private_message_v1($row['context_json']))return true;
    if(!$acceptedPlay||($context['casual_checkin_reply_v1']??false)!==true)return false;
    $request=json_decode((string)$row['request_context'],true,24);
    $outcome=$context['initiative_followthrough']??null;
    if(($request['casual_checkin_reply_v1']??false)!==true||($outcome['state']??null)!=='owner_resolved'
        ||($outcome['owner_user_id']??null)!==$owner||($outcome['agent_id']??null)!==(int)$row['source_agent_id']
        ||($outcome['conversation_id']??null)!==(int)$row['conversation_id']
        ||($outcome['reply_message_id']??null)!==(int)$row['user_message_id'])return false;
    // An accepted reply stays out of Brain archival without suppressing a separately recorded owner play.
    unset($request['skip_brain_archive'],$context['skip_brain_archive']);
    return vp3_initiative_opportunity_private_message_v1(json_encode($request,JSON_THROW_ON_ERROR))
        &&vp3_initiative_opportunity_private_message_v1(json_encode($context,JSON_THROW_ON_ERROR));
}

function vp3_youtube_owner_history_video_v1(array $video): ?array
{
    $id=$video['video_id']??null;
    if(!is_string($id)||!preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id)||($video['made_for_kids']??null)!==false)return null;
    $title=vp3_agent_activity_text_v1(agent_connection_youtube_text_v1($video['title']??null,145),145);
    if($title==='')return null;
    $channel=vp3_agent_activity_text_v1(agent_connection_youtube_text_v1($video['channel_title']??null,85),85);
    $out=['video_id'=>$id,'title'=>$title,'channel_title'=>$channel,'made_for_kids'=>false];
    if(($video['music_category']??null)===true)$out['music_category']=true;
    $context=$video['public_context']??null;
    if(is_array($context)&&is_bool($context['music']??null)){
        $publicTitle=agent_connection_youtube_context_text_v1($context['title']??null,160);
        if($publicTitle!=='')$out['public_context']=['title'=>$publicTitle,
            'channel'=>agent_connection_youtube_context_text_v1($context['channel']??null,90),
            'description'=>agent_connection_youtube_context_text_v1($context['description']??null,320),'music'=>$context['music']];
    }
    $thumbnail=$video['thumbnail_url']??null;
    if(is_string($thumbnail)&&preg_match('~\Ahttps://(?:i\.ytimg\.com|img\.youtube\.com)/(?:vi|vi_webp)/'.$id.'/[A-Za-z0-9_]{1,32}\.(?:jpg|webp)\z~D',$thumbnail)){
        $out['thumbnail_url']=$thumbnail;
    }
    return $out;
}

/** Require a recorded play before showing an item in the owner's playback activity. */
function vp3_youtube_owner_history_plays_v1(PDO $pdo,array $scope,int $now,?array $pin=null,bool $forSuggestion=false,
    bool $includeReturnedResults=false): array
{
    if(!$scope['allow_history']||!$scope['playback_ready'])return [];
    if($forSuggestion&&vp3_youtube_capture_key_v1($pdo,$scope['owner_id'])==='')return [];
    $records=$pin===null?vp3_youtube_history_recent_v1($pdo,$scope['owner_id'],limit:36):
        vp3_youtube_owner_history_exact_play_v1($pdo,$scope['owner_id'],$now,$pin);
    $ids=array_values(array_unique(array_filter(array_column($records,'message_id'))));
    $searches=vp3_youtube_owner_history_searches_v1($pdo,$scope,$now,$ids,!$forSuggestion,$includeReturnedResults&&!$forSuggestion);
    $out=[];
    foreach($records as $record){
        $search=$searches[$record['message_id']]??null;
        if($search===null||(int)$record['played_at']<$scope['accepted_at']||(int)$record['played_at']>$now)continue;
        $expires=(int)$record['expires_at'];
        if(isset($record['playlist_id'])){
            // The playlist's independently validated copy has its own expiry.
            $video=$record['playlist_video']??($pin===null?$record:null);
        }else{
            $video=vp3_youtube_history_video_v1($search['results'],(string)$record['video_id']);
            $expires=min($expires,(int)$search['expires_at']);
        }
        $video=$video===null?null:vp3_youtube_owner_history_video_v1($video);
        if($video===null||$expires<=$now)continue;
        $out[]=$video+['play_id'=>$record['play_id'],'message_id'=>$search['message_id'],
            'conversation_id'=>$search['conversation_id'],'source_agent_id'=>$search['source_agent_id'],
            'played_at'=>(int)$record['played_at'],'expires_at'=>$expires,'created_at_epoch'=>$search['created_at_epoch'],
            'results'=>$search['results']]+array_intersect_key($record,['playlist_id'=>true,'resume_ms'=>true]);
    }
    return $out;
}

function vp3_youtube_owner_history_exact_play_v1(PDO $pdo,int $owner,int $now,array $pin): array
{
    $query=$pdo->prepare('SELECT p.play_id,p.message_id,p.video_id,p.played_at,r.expires_at FROM vp3_youtube_playback_v1 p
        JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
        WHERE p.owner_user_id=? AND p.play_id=? AND p.message_id=? AND r.expires_at>?');
    $query->execute([$owner,$pin['play_id'],$pin['message_id'],$now]);
    $plays=$query->fetchAll(PDO::FETCH_ASSOC);
    if($plays||!vp3_youtube_playlists_ready_v1($pdo))return $plays;
    $query=$pdo->prepare('SELECT p.play_id,p.playlist_id,l.source_message_id AS message_id,p.video_id,p.played_at,l.cached_until,l.videos_json
        FROM vp3_youtube_playlist_plays_v1 p JOIN vp3_youtube_saved_playlists_v1 l
          ON l.owner_user_id=p.owner_user_id AND l.playlist_id=p.playlist_id
        WHERE p.owner_user_id=? AND p.play_id=? AND l.source_message_id=? AND l.cached_until>? AND p.played_at>?');
    $query->execute([$owner,$pin['play_id'],$pin['message_id'],$now,$now-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1]);
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $play){
        $play['playlist_video']=vp3_youtube_history_video_v1(['videos'=>(array)json_decode($play['videos_json'],true)],$play['video_id']);
        $play['expires_at']=min((int)$play['cached_until'],(int)$play['played_at']+VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1);
        return [$play];
    }
    return [];
}
