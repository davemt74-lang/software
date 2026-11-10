<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/player-v76.php';

function vp3_agent_activity_media_text_v1(mixed $value,int $max=200): string
{
    if(!is_string($value))return '';
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value)??''),0,$max);
}

/** Local media history for the live owner and selected private Agent. */
function vp3_agent_activity_media_v1(PDO $pdo,array $user,int $agentId): array
{
    $sections=[
        'youtube'=>['id'=>'youtube','label'=>'YouTube','items'=>[]],
        'songs'=>['id'=>'songs','label'=>'Songs','items'=>[]],
    ];
    $coverage=['youtube'=>'disabled','songs'=>'disabled'];
    $owner=(int)($user['id']??0);
    if($owner<1||$agentId<0||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        return ['sections'=>array_values($sections),'coverage'=>$coverage];
    }
    try{
        $query=$pdo->prepare('SELECT * FROM users WHERE id=?');
        $query->execute([$owner]);
        $live=$query->fetch(PDO::FETCH_ASSOC);
        if(!$live||empty($live['is_active'])||!has_permission('chat.access',$live)){
            return ['sections'=>array_values($sections),'coverage'=>$coverage];
        }
        vp3_agent_chat_resolve_agent_v380($pdo,$live,$agentId);
    }catch(RuntimeException){
        return ['sections'=>array_values($sections),'coverage'=>$coverage];
    }

    if(has_permission('account.access',$live)&&agent_connection_youtube_enabled_v1()
        &&agent_connection_youtube_consent_v1($pdo,$owner)&&agent_connection_youtube_results_ready_v1($pdo)){
        try{
            $playItems=[];
            $searchItems=[];
            $seenVideos=[];
            $records=agent_connection_youtube_activity_records_v1($pdo,$owner,$agentId,time());
            foreach($records['plays'] as $video){
                $videoId=(string)($video['video_id']??'');
                if(isset($seenVideos[$videoId])||(int)$video['conversation_id']<1||(int)$video['message_id']<1)continue;
                $title=vp3_agent_activity_media_text_v1($video['title']??'');
                if($title==='')continue;
                $seenVideos[$videoId]=true;
                $cid=(int)$video['conversation_id'];
                $mid=(int)$video['message_id'];
                $href=url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$cid);
                $channel=vp3_agent_activity_media_text_v1($video['channel_title']??'',120);
                $playItems[]=['time'=>(int)$video['played_at'],'item'=>[
                    'id'=>'youtube-play:'.$video['play_id'],'kind'=>'youtube','title'=>$title,
                    'detail'=>'Played'.($channel!==''?' · '.$channel:''),
                    'actions'=>[
                        ['kind'=>'youtube','label'=>'Review video','href'=>$href,'conversation_id'=>$cid,'message_id'=>$mid,'video_id'=>$videoId],
                        ['kind'=>'chat','label'=>'Open Chat','href'=>$href,'conversation_id'=>$cid,'message_id'=>$mid],
                    ],
                ]];
            }
            foreach($records['searches'] as $record){
                $mid=(int)$record['message_id'];
                $title=vp3_agent_activity_media_text_v1($record['results']['query']??'');
                if($title==='')continue;
                $cid=(int)$record['conversation_id'];
                $searchItems[]=['time'=>(int)$record['created_at_epoch'],'item'=>[
                    'id'=>'youtube-search:'.$mid,'kind'=>'youtube_search','title'=>$title,
                    'detail'=>($record['results']['suggestion_kind']??null)==='playlist'?'Playlist draft':'Search results',
                    'actions'=>[['kind'=>'chat','label'=>'Review results',
                        'href'=>url('/chat.php?agent='.($agentId?:'system').'&conversation_id='.$cid),
                        'conversation_id'=>$cid,'message_id'=>$mid]],
                ]];
            }
            $recentFirst=static fn(array $a,array $b):int=>$b['time']<=>$a['time']?:strcmp($a['item']['id'],$b['item']['id']);
            usort($searchItems,$recentFirst);
            $selected=array_slice($searchItems,0,2);
            $selected=array_merge($selected,array_slice($playItems,0,3-count($selected)));
            if(count($selected)<3)$selected=array_merge($selected,array_slice($searchItems,2,3-count($selected)));
            usort($selected,$recentFirst);
            $sections['youtube']['items']=array_column($selected,'item');
            $coverage['youtube']='ready';
        }catch(PDOException){
            $coverage['youtube']='unavailable';
        }
    }

    try{
        if(!table_exists('track_play_sessions')){
            $coverage['songs']='unavailable';
        }else{
            // Player library history intentionally spans the owner's Agents and Chats.
            foreach(player_recent_history($live,[],3) as $track){
                $id=(int)$track['id'];
                $title=vp3_agent_activity_media_text_v1($track['title']??'');
                if($title==='')continue;
                $album=vp3_agent_activity_media_text_v1($track['album']??'',120);
                $position=max(0,(int)($track['last_position_seconds']??0));
                $href=url('/track.php?id='.$id);
                $sections['songs']['items'][]=[
                    'id'=>'song:'.$id,'kind'=>'song','title'=>$title,
                    'detail'=>($album!==''?$album.' · ':'').'Last played'.($position>0?' · '.intdiv($position,60).':'.str_pad((string)($position%60),2,'0',STR_PAD_LEFT):''),
                    'actions'=>[
                        ['kind'=>'song','label'=>$position>5?'Resume':'Play','href'=>$href,'track_id'=>$id],
                        ['kind'=>'url','label'=>'Review track','href'=>$href],
                    ],
                ];
            }
            $coverage['songs']='ready';
        }
    }catch(PDOException){
        $coverage['songs']='unavailable';
    }
    return ['sections'=>array_values($sections),'coverage'=>$coverage];
}
