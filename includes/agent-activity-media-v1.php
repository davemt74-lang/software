<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-connection-youtube-playback-v1.php';
require_once __DIR__.'/player-v76.php';

/** The same source-linked history is shown by the Media specialist and both Top of Mind surfaces. */
function vp3_agent_activity_youtube_playlist_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $now): array
{
    require_once __DIR__.'/agent-connection-youtube-owner-history-v1.php';
    $activity=vp3_youtube_owner_activity_v1($pdo,$user,$agentId,$conversationId,$now,includeReturnedResults:true);
    $playlist=['status'=>$activity['status'],'playlist'=>[],'searches'=>[],'played'=>[]];
    $sources=[];
    $queued=[];
    foreach($activity['searches'] as $record){
        $title=vp3_agent_activity_media_text_v1($record['results']['query']??'');
        if($title==='')continue;
        $videos=$record['results']['videos'];
        $available=($record['results_available']??false)===true;
        $count=count($videos);
        $playlist['searches'][]=['id'=>'youtube-search:'.$record['message_id'],'kind'=>'youtube_search','title'=>$title,
            'detail'=>$available?$count.($count===1?' saved result':' saved results'):'Earlier search',
            'actions'=>[vp3_agent_activity_youtube_origin_v1($record,$available?'Review results':'Review search')]];
        if(!$available)continue;
        if(!isset($playlist['current_results'])){
            $playlist['current_results']=['message_id'=>$record['message_id'],'expires_at'=>$record['expires_at'],
                'kind'=>($record['results']['suggestion_kind']??null)==='playlist'?'playlist':'search','title'=>$title,
                'videos'=>array_map(static fn(array $video):array=>['title'=>$video['title'],'channel'=>$video['channel_title']],$videos),
                'action'=>vp3_agent_activity_youtube_origin_v1($record,'Open results in Chat')];
        }
        $action=vp3_agent_activity_youtube_origin_v1($record,'Open in Chat');
        foreach($videos as $video){
            $id=$video['video_id'];
            if(isset($queued[$id]))continue;
            $queued[$id]=true;
            $sources[$record['message_id']][]=['video_id'=>$id,'title'=>$video['title'],'channel'=>$video['channel_title'],
                'expires_at'=>$record['expires_at'],'action'=>$action];
        }
    }
    // Reserve room for older result cards before taking more from each source.
    $selected=array_fill_keys(array_keys($sources),[]);
    $remaining=18;
    for($position=0;$position<6;++$position){
        foreach($sources as $messageId=>$videos){
            if(!isset($videos[$position]))continue;
            $selected[$messageId][]=$videos[$position];
            if(--$remaining===0)break 2;
        }
    }
    $playlist['playlist']=array_merge(...array_values($selected));
    $seen=[];
    foreach($activity['plays'] as $record){
        $title=vp3_agent_activity_media_text_v1($record['title']??'');
        $video=$record['video_id'];
        if($title===''||isset($seen[$video]))continue;
        $seen[$video]=true;
        $channel=vp3_agent_activity_media_text_v1($record['channel_title']??'',120);
        $position=(int)($record['resume_ms']??0);
        $seconds=intdiv(max(0,$position),1000);
        $detail=$channel;
        if($seconds>5)$detail.=($detail!==''?' · ':'').sprintf('%d:%02d',intdiv($seconds,60),$seconds%60);
        $playlist['played'][]=['id'=>'youtube-play:'.$record['play_id'],'kind'=>'youtube','title'=>$title,
            'detail'=>$detail,'actions'=>[vp3_agent_activity_youtube_origin_v1($record,'Review video')]];
        if(count($playlist['played'])>=6)break;
    }
    return $playlist+array_intersect_key($activity,['played_status'=>true]);
}

/** Separate a sitting report suggestion from an actual shared Chat handoff. */
function vp3_agent_activity_media_delivery_v1(PDO $pdo,array $scope): array
{
    require_once __DIR__.'/agent-initiative-engagement-status-v1.php';
    require_once __DIR__.'/agent-coordination-schedule-v1.php';
    $user=$scope['user'];$agent=$scope['agent_id'];$chat=$scope['conversation_id'];
    if($chat<1)return ['gate'=>'no_chat'];
    $policy=vp3_initiative_policy_v1($user,$agent);
    if(!$policy)return ['gate'=>'starting'];
    if(($policy['conversation_id']??null)!==$chat)return ['gate'=>'linked_elsewhere'];
    if(($policy['enabled']??false)!==true)return ['gate'=>'initiative_paused'];
    $checkpoint=vp3_coordination_scan_checkpoint_v1($user,$policy);
    $scan=$checkpoint['specialists']['media']??[];
    $projection=['gate'=>$scan['handoff_gate']??$scan['offer_gate']??'checking'];
    if(is_string($checkpoint['at']??null))$projection['checked_at']=$checkpoint['at'];
    $handoff=vp3_initiative_engagement_handoffs_v1($pdo,$user,$agent,$policy,vp3_initiative_report_gate_v1($pdo,$user,$chat,$agent),'media')['media']??null;
    if($handoff!==null){
        $projection['handoff']=$handoff;
        if(is_int($handoff['message_id']??null)&&$handoff['message_id']>0){
            $projection['handoff']['action']=vp3_agent_activity_youtube_origin_v1(
                ['source_agent_id'=>$agent,'conversation_id'=>$chat,'message_id'=>$handoff['message_id']],'View in Chat');
        }
    }
    return $projection;
}

function vp3_agent_activity_youtube_origin_v1(array $record,string $label): array
{
    $agent=$record['source_agent_id'];$chat=$record['conversation_id'];$message=$record['message_id'];
    return ['kind'=>'chat','label'=>$label,'agent_id'=>$agent,'conversation_id'=>$chat,'message_id'=>$message,
        'href'=>vp3_agent_activity_chat_href_v1($agent,$chat).'&message_id='.$message];
}

function vp3_agent_activity_media_text_v1(mixed $value,int $max=200): string
{
    if(!is_string($value))return '';
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',$value)??''),0,$max);
}

/** Reuse the Player's authorized track metadata and exact saved play actions. */
function vp3_agent_activity_native_listening_v1(array $live): array
{
    $items=[];
    foreach(player_recent_history($live,[],3,playedOnly:true) as $track){
        $id=(int)$track['id'];
        $title=vp3_agent_activity_media_text_v1($track['title']??'');
        if($title==='')continue;
        $album=vp3_agent_activity_media_text_v1($track['album']??'',120);
        $position=max(0,(int)($track['last_position_seconds']??0));
        $href=url('/track.php?id='.$id);
        $items[]=['id'=>'song:'.$id,'kind'=>'song','title'=>$title,
            'played_at'=>strtotime((string)$track['last_played_at'].' UTC')?:0,
            'detail'=>($album!==''?$album.' · ':'').'Last played'.($position>0?' · '.intdiv($position,60).':'.str_pad((string)($position%60),2,'0',STR_PAD_LEFT):''),
            'actions'=>[
                ['kind'=>'song','label'=>$position>5?'Resume':'Play','href'=>$href,'track_id'=>$id],
                ['kind'=>'url','label'=>'Review track','href'=>$href],
            ],
        ];
    }
    return $items;
}

/** Local media history for the live owner and selected private Agent. */
function vp3_agent_activity_media_v1(PDO $pdo,array $user,int $agentId,bool $includeYoutube=true): array
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

    if($includeYoutube&&has_permission('account.access',$live)&&agent_connection_youtube_enabled_v1()
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
            $sections['songs']['items']=vp3_agent_activity_native_listening_v1($live);
            $coverage['songs']='ready';
        }
    }catch(PDOException){
        $coverage['songs']='unavailable';
    }
    return ['sections'=>array_values($sections),'coverage'=>$coverage];
}
