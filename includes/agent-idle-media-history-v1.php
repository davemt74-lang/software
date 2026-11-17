<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-owner-history-v1.php';
require_once __DIR__.'/agent-media-youtube-play-analysis-v1.php';

/** Discover a new suggestion or re-read its exact saved origin without calling YouTube. */
function vp3_idle_media_history_choice_v1(PDO $pdo,array $scope,int $now,?array $pin=null,?array $exclude=null,array $shown=[]): ?array
{
    $agent=$scope['agent_id']??null;$chat=$scope['conversation_id']??null;$user=$scope['user']??null;
    if(!is_int($agent)||$agent<0||!is_int($chat)||$chat<1||!is_array($user))return null;
    if($pin!==null&&!vp3_idle_media_history_pin_v1($pin))return null;
    $history=vp3_youtube_owner_history_scope_v1($pdo,$user,$agent,$chat,$now);
    if($history===null||!vp3_youtube_idle_personalized_enabled_v1($pdo,$history['owner_id']))return null;
    $accepted=$history['accepted_at'];
    $kind=$pin['kind']??null;
    $shownSources=$shownSearches=$shownPlays=[];
    if($pin===null){
        if($exclude!==null)$shown[]=$exclude;
        foreach($shown as $origin){
            if(!is_array($origin)||!vp3_idle_media_history_pin_v1($origin))continue;
            $shownSources[$origin['message_id']]=true;
            if($origin['kind']==='watch')$shownPlays[$origin['play_id']]=true;
            else $shownSearches[$origin['message_id']]=true;
        }
    }
    if($kind===null||$kind==='watch'){
        $watches=vp3_youtube_owner_history_plays_v1($pdo,$history,$now,$pin,forSuggestion:true);
        $capture=$watches?vp3_youtube_capture_key_v1($pdo,$history['owner_id']):'';
        $shownVideos=array_fill_keys(array_values(array_intersect_key(array_column($watches,'video_id','play_id'),$shownPlays)),true);
        foreach($watches as $watch){
            // Several different videos can come from a single search or saved playlist.
            if($capture===''||isset($shownSearches[$watch['message_id']])||isset($shownPlays[$watch['play_id']])
                ||isset($shownVideos[$watch['video_id']]))continue;
            $watchPin=['kind'=>'watch','message_id'=>$watch['message_id'],'play_id'=>$watch['play_id']];
            if(!vp3_idle_media_history_pin_v1($watchPin))continue;
            $video=vp3_youtube_owner_history_video_v1($watch);
            if($video===null)continue;
            $choice=vp3_idle_media_history_video_choice_v1($watch['source_agent_id'],$accepted,$watch,$video,$watchPin,
                hash('sha256',$capture.'|'.$watch['played_at'].'|'.($watch['playlist_id']??'')));
            if($choice!==null)return $choice;
        }
    }
    if($kind==='watch')return null;
    $records=vp3_youtube_owner_history_searches_v1($pdo,$history,$now,$pin===null?null:[$pin['message_id']]);
    if($kind!=='topic'){
        foreach($records as $record){
            if(isset($shownSources[$record['message_id']]))continue;
            foreach($record['results']['videos'] as $video){
                $choice=vp3_idle_media_history_video_choice_v1($record['source_agent_id'],$accepted,$record,$video,
                    ['kind'=>'search','message_id'=>$record['message_id']]);
                if($choice!==null)return $choice;
            }
        }
    }
    if($kind==='search'||!agent_connection_youtube_ready_v1()||!agent_connection_youtube_quota_ready_v1($pdo))return null;
    foreach($records as $record){
        if($record['expires_at']!==null||isset($shownSources[$record['message_id']]))continue;
        return vp3_idle_media_history_topic_v1($accepted,$record);
    }
    return null;
}

function vp3_idle_media_history_pin_v1(array $pin): bool
{
    $kind=$pin['kind']??null;$message=$pin['message_id']??null;
    if(!is_int($message)||$message<1||$message>9007199254740991)return false;
    return match($kind){
        'search','topic'=>count($pin)===2,
        'watch'=>count($pin)===3&&is_string($pin['play_id']??null)
            && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$pin['play_id'])===1,
        default=>false,
    };
}

function vp3_idle_media_history_video_choice_v1(int $agent,int $accepted,array $record,array $video,array $pin,
    string $captureProof=''): ?array
{
    if(($video['made_for_kids']??null)!==false||!is_string($video['video_id']??null)
        ||!preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$video['video_id']))return null;
    $title=vp3_idle_media_history_text_v1($video['title']??'',145);
    if($title==='')return null;
    $channel=vp3_idle_media_history_text_v1($video['channel_title']??'',85);
    $byline=$channel===''?'':' from '.$channel;
    $watched=$pin['kind']==='watch';
    $question=$watched?'Want to watch “'.$title.'”'.$byline.' again?':'Want me to play “'.$title.'”'.$byline.' from your saved YouTube results?';
    $topic=$record['results']['query'];
    if($watched&&isset($video['public_context'])){
        $context=vp3_media_youtube_play_context_v1($video);
        $artist=vp3_media_youtube_attributed_artist_v1($context,$topic);
        if($artist!==null)$question='YouTube credits '.$artist.' in your recent video. '.$question;
    }
    $playable=array_diff_key($video,['public_context'=>true,'music_category'=>true]);
    $query=vp3_idle_media_history_text_v1($topic,160);
    return ['title'=>$title,'summary'=>$watched?'You played this video in VP3.':'This video is in your saved YouTube results.',
        'question'=>$question,'url'=>vp3_agent_activity_chat_href_v1($agent,$record['conversation_id']).'&message_id='.$record['message_id'],
        'identity'=>[$accepted,$agent,$record['conversation_id'],$record['created_at_epoch'],$record['expires_at'],$pin,$playable,$query,$topic,$captureProof],
        'evidence'=>['history_pin'=>$pin,'history_playback'=>[
            'query'=>$query===''?'Saved YouTube video':$query,'expires_at'=>$record['expires_at'],'videos'=>[$playable],
            'source_message_id'=>$record['message_id'],'selected_video_id'=>$video['video_id'],'initiative_suggestion'=>true,
        ]]+($topic!==''?['history_topic'=>$topic]:[])];
}

/** Once a provider result expires, keep only the owner's original search intent. */
function vp3_idle_media_history_topic_v1(int $accepted,array $record): array
{
    $topic=$record['results']['query'];
    $pin=['kind'=>'topic','message_id'=>$record['message_id']];
    $agent=$record['source_agent_id'];
    $cid=$record['conversation_id'];
    return ['title'=>$topic,'summary'=>'You searched for this topic on YouTube in VP3.',
        'question'=>'Want me to find another YouTube video about “'.$topic.'”?',
        'url'=>vp3_agent_activity_chat_href_v1($agent,$cid).'&message_id='.$record['message_id'],
        'identity'=>[$accepted,$agent,$cid,$record['created_at_epoch'],$record['user_message_id'],$pin,$topic],
        'evidence'=>['history_pin'=>$pin,'history_topic'=>$topic]];
}

function vp3_idle_media_history_text_v1(mixed $text,int $limit): string
{
    if(!is_string($text))return '';
    return trim(mb_substr(preg_replace('~(?:https?://|\bwww\.)\S+|[<>\p{Cc}\p{Cf}]~iu',' ',strip_tags($text))??'',0,$limit));
}
