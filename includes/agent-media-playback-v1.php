<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';
require_once __DIR__.'/player-v76.php';
require_once __DIR__.'/recommendations.php';

function vp3_media_playback_user_v1(PDO $pdo,array $user,int $agentId,int $conversationId=0): array
{
    if($agentId<0||$conversationId<0||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null)){
        throw new RuntimeException('Open your private Agent Chat for media playback.',403);
    }
    $agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,['conversation_id'=>$conversationId],null);
    if(!has_permission('account.access',$live))throw new RuntimeException('Media access changed.',403);
    return [$live,$principal];
}

function vp3_media_playback_history_allowed_v1(PDO $pdo,array $user,array $principal): bool
{
    return $principal['kind']==='system'||(function_exists('user_data_policy_can_use_v236')
        &&user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','playback-history',false));
}

/** The exact track must still be usable through the native media transport. */
function vp3_media_playable_track_v1(PDO $pdo,array $user,int $id,bool $publishedOnly=false): ?array
{
    $track=music_media_track($id,$user);
    if(!$track||!can_view_track($track,$user)||($publishedOnly&&(int)($track['is_published']??0)!==1))return null;
    $path=music_media_local_path((string)($track['audio_path']??''));
    $catalogId=(int)($track['artist_track_id']??0);
    if($catalogId>0&&($id>=1000000000||$path===null||music_media_mime($path,'audio')===null)
        &&function_exists('artist_music_v185_public_track')){
        $catalog=artist_music_v185_public_track($pdo,$catalogId,$user);
        if($catalog)$path=artist_music_v185_resolve_audio($pdo,$catalog)??$path;
    }
    return $path!==null&&music_media_mime($path,'audio')!==null?$track:null;
}

function vp3_media_track_details_v1(PDO $pdo,array $track): array
{
    $id=(int)$track['id'];
    $clean=static fn(mixed $v):string=>is_string($v)
        ?mb_substr(trim(preg_replace('/[\p{Cc}\p{Cf}<>]+/u',' ',strip_tags($v))??''),0,180):'';
    $artist='';
    $artistUrl='';
    $workspace=(int)($track['catalog_workspace_id']??$track['workspace_id']??0);
    if($workspace>0&&table_exists('artist_workspaces_v181')){
        $profileJoin=table_exists('user_profiles')?' LEFT JOIN user_profiles p ON p.user_id=u.id AND p.is_public=1':'';
        $profileColumn=$profileJoin!==''?',p.username':'';
        $q=$pdo->prepare('SELECT u.display_name'.$profileColumn.' FROM artist_workspaces_v181 w
            JOIN users u ON u.id=w.artist_user_id AND u.is_active=1'.$profileJoin.' WHERE w.id=?');
        $q->execute([$workspace]);
        $identity=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $artist=$clean($identity['display_name']??'');
        if(is_string($identity['username']??null)&&preg_match('/\A[a-zA-Z0-9_.-]{1,120}\z/D',$identity['username'])){
            $artistUrl=url('/'.rawurlencode($identity['username']));
        }
    }
    return ['track_id'=>$id,'title'=>$clean($track['title']??''),'album'=>$clean($track['album']??''),'genre'=>$clean($track['genre']??''),'artist'=>$artist,
        'url'=>url('/track.php?id='.$id),'artist_url'=>$artistUrl];
}

function vp3_media_track_revision_v1(int $owner,int $agent,array $track): string
{
    $details=array_intersect_key($track,array_flip([
        'id','artist_track_id','workspace_id','catalog_workspace_id','is_published','visibility','title','album','audio_path',
    ]));
    ksort($details,SORT_STRING);
    return hash('sha256',json_encode([$owner,$agent,$details],JSON_THROW_ON_ERROR));
}

/** Read only the latest verified native listening session for this owner. */
function vp3_media_resume_candidate_v1(PDO $pdo,array $user,int $agentId,int $now): ?array
{
    [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agentId);
    return vp3_media_resume_read_v1($pdo,$live,$principal,$now);
}

function vp3_media_resume_read_v1(PDO $pdo,array $live,array $principal,int $now): ?array
{
    if(!table_exists('track_play_sessions')||!vp3_media_playback_history_allowed_v1($pdo,$live,$principal))return null;
    $q=$pdo->prepare('SELECT id,track_id,last_event_at,ended_at,listened_seconds,last_position_seconds,duration_seconds,completed
        FROM track_play_sessions WHERE user_id=? ORDER BY started_at DESC,id DESC LIMIT 1');
    $q->execute([(int)$live['id']]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $at=strtotime((string)$row['last_event_at'].' UTC');
    $position=(float)$row['last_position_seconds'];
    $duration=(float)$row['duration_seconds'];
    if($at===false||$at>$now||$at<=$now-172800||$position<10||$duration-$position<15
        ||$position/$duration>=0.95||(float)$row['listened_seconds']<10||(int)$row['completed']===1)return null;
    if($row['ended_at']===null&&$at>$now-35){
        if(!table_exists('track_play_events'))return null;
        $q=$pdo->prepare('SELECT event_type FROM track_play_events WHERE session_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([(int)$row['id']]);
        if($q->fetchColumn()!=='pause')return null;
    }
    $track=vp3_media_playable_track_v1($pdo,$live,(int)$row['track_id']);
    if($track===null)return null;
    $details=vp3_media_track_details_v1($pdo,$track);
    return $details+['position_seconds'=>$position,'duration_seconds'=>$duration,'played_at'=>gmdate(DATE_ATOM,$at),
        'revision'=>hash('sha256',json_encode([(int)$row['id'],$position,$duration,$at,$details,
            vp3_media_track_revision_v1((int)$live['id'],(int)$principal['agent_id'],$track)],JSON_THROW_ON_ERROR))];
}

/** Reuse the merged platform and Artist Workspace library and its existing owner preferences. */
function vp3_media_native_choices_v1(PDO $pdo,array $live,array $principal,string $taste,int $limit,int $excludeTrackId=0): array
{
    $map=player_visible_track_map($live);
    unset($map[$excludeTrackId]);
    if(!$map)return [];
    $historyAllowed=vp3_media_playback_history_allowed_v1($pdo,$live,$principal);
    $preferences=['ready'=>false];
    if($historyAllowed){
        require_once __DIR__.'/agent-media-preferences-v1.php';
        if(vp3_media_preferences_ready_v1($pdo))$preferences=vp3_media_preferences_read_v1($pdo,$live,(int)$principal['agent_id'],0,
            vp3_media_preferences_candidates_v1(array_values($map),'vp3'));
    }
    $ordered=$historyAllowed&&($preferences['ready']??false)!==true
        ?player_for_you($live,$map,count($map)):array_values($map);
    $terms=track_query_terms(mb_substr($taste,0,120));
    $moods=track_detect_moods($taste);
    $relevance=[];
    if($terms||$moods){
        $ranked=[];
        foreach($ordered as $track){
            $text=mb_strtolower(implode(' ',array_intersect_key($track,array_flip(['title','album','genre','mood','energy','keywords']))));
            $words=array_map('mb_strtolower',preg_split('/[^\p{L}\p{N}]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[]);
            $score=count(array_intersect($terms,$words));
            foreach($moods as $mood){
                if(array_intersect([$mood,...track_mood_aliases()[$mood]],$words))$score+=4;
            }
            $relevance[(string)$track['id']]=$score;
            $ranked[]=['track'=>$track,'score'=>$score];
        }
        usort($ranked,static fn(array $a,array $b):int=>$b['score']<=>$a['score']);
        $ordered=array_column($ranked,'track');
    }
    if(($preferences['ready']??false)===true)$ordered=vp3_media_preferences_rank_v1($ordered,$preferences,'vp3',$relevance);
    $choices=[];
    foreach($ordered as $row){
        $track=vp3_media_playable_track_v1($pdo,$live,(int)$row['id'],true);
        if($track===null)continue;
        $choices[]=$track;
        if(count($choices)>=$limit)break;
    }
    return $choices;
}

function vp3_media_native_pick_v1(PDO $pdo,array $user,int $agentId,string $taste='',int $excludeTrackId=0): ?array
{
    [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agentId);
    $track=vp3_media_native_choices_v1($pdo,$live,$principal,$taste,1,$excludeTrackId)[0]??null;
    return $track===null?null:vp3_media_track_details_v1($pdo,$track)
        +['revision'=>vp3_media_track_revision_v1((int)$live['id'],$agentId,$track)];
}

/** Recheck the exact published recommendation without rerunning the changing catalog ranking. */
function vp3_media_native_track_v1(PDO $pdo,array $user,int $agentId,int $trackId): ?array
{
    [$live]=vp3_media_playback_user_v1($pdo,$user,$agentId);
    $track=vp3_media_playable_track_v1($pdo,$live,$trackId,true);
    return $track===null?null:vp3_media_track_details_v1($pdo,$track)
        +['revision'=>vp3_media_track_revision_v1((int)$live['id'],$agentId,$track)];
}

/** Sign a short-lived action issued only for the live owner turn. The Player revalidates it. */
function vp3_media_playback_action_v1(array $live,int $agentId,int $conversationId,string $operation,array $selection=[]): array
{
    $action=['type'=>'media_playback','operation'=>$operation,'owner_user_id'=>(int)$live['id'],'agent_id'=>$agentId,
        'conversation_id'=>$conversationId,'expires_at'=>time()+300]+$selection;
    $proof=vp3_media_playback_proof_v1($action);
    if($proof===null)throw new RuntimeException('Native playback could not be authorized. Reopen Agent Chat.',503);
    return $action+['proof'=>$proof];
}

function vp3_media_playback_secret_v1(): ?string
{
    $secret=$_SESSION['vp3_media_playback_secret_v1']??null;
    if(!is_string($secret)&&session_status()===PHP_SESSION_ACTIVE){
        $secret=$_SESSION['vp3_media_playback_secret_v1']=bin2hex(random_bytes(32));
    }
    return is_string($secret)&&preg_match('/\A[a-f0-9]{64}\z/D',$secret)===1?$secret:null;
}

function vp3_media_playback_proof_v1(array $action): ?string
{
    $secret=vp3_media_playback_secret_v1();
    if($secret===null)return null;
    unset($action['proof']);
    ksort($action,SORT_STRING);
    return hash_hmac('sha256','vp3-native-media|'.json_encode($action,JSON_THROW_ON_ERROR),$secret);
}

function vp3_media_resume_action_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $trackId,string $revision,
    ?int $offerMessageId=null): ?array
{
    [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agentId,$conversationId);
    $source=vp3_media_resume_read_v1($pdo,$live,$principal,time());
    if($source===null||$source['track_id']!==$trackId||!hash_equals($source['revision'],$revision))return null;
    $selection=array_intersect_key($source,['track_id'=>true,'position_seconds'=>true,'revision'=>true]);
    if($offerMessageId!==null&&$offerMessageId>0)$selection['offer_message_id']=$offerMessageId;
    return vp3_media_playback_action_v1($live,$agentId,$conversationId,'resume',$selection);
}

function vp3_media_native_pick_action_v1(PDO $pdo,array $user,int $agentId,int $conversationId,int $trackId,string $revision,
    ?int $offerMessageId=null): ?array
{
    [$live]=vp3_media_playback_user_v1($pdo,$user,$agentId,$conversationId);
    $track=vp3_media_playable_track_v1($pdo,$live,$trackId,true);
    if($track===null||!hash_equals(vp3_media_track_revision_v1((int)$live['id'],$agentId,$track),$revision))return null;
    $selection=['track_id'=>$trackId,'revision'=>$revision];
    if($offerMessageId!==null&&$offerMessageId>0)$selection['offer_message_id']=$offerMessageId;
    return vp3_media_playback_action_v1($live,$agentId,$conversationId,'play',$selection);
}

/** Intent must be explicit. A question about DJ or a mention of a DJ stays an ordinary turn. */
function vp3_media_dj_chat_v1(PDO $pdo,array $user,array $principal,int $cid,string $query): ?array
{
    $query=trim($query);
    $resume=preg_match('/\A(?:\/music\s+resume|(?:please\s+)?(?:resume|continue)\s+(?:(?:my|the)\s+)?last\s+(?:track|song))[.!]?\z/iu',$query)===1;
    $stop=preg_match('/\A(?:\/dj\s+(?:stop|off)|(?:please\s+)?(?:stop|turn off)\s+(?:the\s+)?DJ(?:\s+mode)?)[.!]?\z/iu',$query)===1;
    $playlist=preg_match('/\A(?:\/music\s+playlist|(?:please\s+)?(?:make|create|prepare)\s+(?:me\s+)?(?:a\s+)?VP3\s+(?:music\s+)?playlist)(?:\s+(?:for\s+|with\s+)?([^?\r\n]{1,120}?))?[.!]?\z/iu',$query,$matches)===1;
    if(!$resume&&!$stop&&!$playlist&&!preg_match('/\A(?:\/dj(?:\s+start)?|(?:please\s+)?(?:start|turn on)\s+(?:the\s+)?DJ(?:\s+mode)?)(?:\s+(?:with\s+|for\s+)?([^?\r\n]{1,120}?))?[.!]?\z/iu',$query,$matches))return null;
    if($cid<1||!in_array($principal['kind']??null,['system','user_agent'],true))return null;
    [$live,$current]=vp3_media_playback_user_v1($pdo,$user,(int)$principal['agent_id'],$cid);
    $agent=(int)$current['agent_id'];
    if($resume){
        $source=vp3_media_resume_read_v1($pdo,$live,$current,time());
        require_once __DIR__.'/agent-media-stoneward-playback-v1.php';
        $stoneward=vp3_stoneward_playback_resume_v1($pdo,$live,$current);
        if($stoneward!==null&&($source===null||strtotime($stoneward['played_at'])>strtotime($source['played_at']))){
            require_once __DIR__.'/connected-sites-chat-v1.php';
            require_once __DIR__.'/connected-stoneward-v1.php';
            try{
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                $playback=vp3_stoneward_chat_resume_track_v1($pdo,$live,$current,$stoneward);
                vp3_media_playback_user_v1($pdo,$live,$agent,$cid);
                return ['handled'=>true,'answer'=>'“'.vp3_connected_sites_chat_text_v1($playback['title'],180).'” is ready at your saved position.',
                    'connected_site_playback_v1'=>$playback,
                    'connected_site_context_v1'=>['provider'=>'stoneward','connectionId'=>$stoneward['connection_id'],'actions'=>['listening.track.play']],
                    'sources'=>[['source'=>'connected-site:'.$stoneward['connection_id'].':listening.track.play',
                        'title'=>'Stonefellow · Resume track','url'=>$playback['origin'].'/']]];
            }catch(RuntimeException $error){
                return ['handled'=>true,'answer'=>vp3_stoneward_chat_play_error_v1($error),'connected_site_context_v1'=>[]];
            }
        }
        if($source===null)return ['handled'=>true,'answer'=>'I couldn’t verify a recent unfinished track to resume.'];
        return ['handled'=>true,'answer'=>'I’ll open “'.$source['title'].'” at your saved position.',
            'media_playback'=>vp3_media_playback_action_v1($live,$agent,$cid,'resume',array_intersect_key($source,
                ['track_id'=>true,'position_seconds'=>true,'revision'=>true]))];
    }
    if($stop)return ['handled'=>true,'answer'=>'I’ll stop the DJ queue in your Player.',
        'media_playback'=>vp3_media_playback_action_v1($live,$agent,$cid,'dj_stop')];
    $taste=trim((string)($matches[1]??''));
    if($taste===''){
        require_once __DIR__.'/agent-media-entertainment-v1.php';
        $taste=vp3_media_native_taste_v1($pdo,(int)$live['id']);
    }
    $tracks=vp3_media_native_choices_v1($pdo,$live,$current,$taste,8);
    if(!$tracks)return ['handled'=>true,'answer'=>'I couldn’t find a playable track in your VP3 Music Library for this queue.'];
    $revisions=[];
    foreach($tracks as $track)$revisions[]=['track_id'=>(int)$track['id'],
        'revision'=>vp3_media_track_revision_v1((int)$live['id'],$agent,$track)];
    $count=count($tracks).' '.(count($tracks)===1?'track':'tracks');
    $answer=$playlist?'I picked '.$count.' from your VP3 Music Library for a playlist draft.'
        :'I picked '.$count.' from your VP3 Music Library for the DJ queue.';
    return ['handled'=>true,'answer'=>$answer,
        'media_playback'=>vp3_media_playback_action_v1($live,$agent,$cid,$playlist?'playlist':'dj_start',['queue'=>$revisions])];
}

/** A forged or stale action cannot change which track the authorized Player opens. */
/** The Player resolver and its actual-start receipt share one signed action contract. */
function vp3_media_playback_verified_action_v1(array $user,array $action): bool
{
    $owner=(int)$user['id'];
    $agent=$action['agent_id']??null;
    $cid=$action['conversation_id']??null;
    $operation=$action['operation']??null;
    if(($action['type']??null)!=='media_playback'||($action['owner_user_id']??null)!==$owner||!is_int($agent)||$agent<0
        ||!is_int($cid)||$cid<1||!in_array($operation,['resume','play','dj_start','dj_stop','playlist'],true)
        ||!is_int($action['expires_at']??null)||$action['expires_at']<time()||$action['expires_at']>time()+300
        ||!is_string($action['proof']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$action['proof']))return false;
    $operationFields=match($operation){'resume'=>['track_id','revision','position_seconds'],'play'=>['track_id','revision'],
        'dj_stop'=>[],default=>['queue']};
    $allowed=['type','owner_user_id','agent_id','conversation_id','operation','expires_at','proof',...$operationFields];
    if(array_key_exists('offer_message_id',$action)){
        if(!in_array($operation,['resume','play'],true)||!is_int($action['offer_message_id'])||$action['offer_message_id']<1)return false;
        $allowed[]='offer_message_id';
    }
    if(array_key_exists('history_ref',$action)){
        if(!in_array($operation,['resume','play'],true)||isset($action['offer_message_id'])||!is_string($action['history_ref'])
            ||strlen($action['history_ref'])>240||!preg_match('/\Amh1\.[A-Za-z0-9_-]+\z/D',$action['history_ref']))return false;
        $allowed[]='history_ref';
    }
    if(count($action)!==count($allowed)||array_diff(array_keys($action),$allowed))return false;
    $proof=vp3_media_playback_proof_v1($action);
    return $proof!==null&&hash_equals($proof,$action['proof']);
}

function vp3_media_playback_resolve_v1(PDO $pdo,array $user,array $action): ?array
{
    if(!vp3_media_playback_verified_action_v1($user,$action))return null;
    $owner=(int)$user['id'];$agent=$action['agent_id'];$cid=$action['conversation_id'];$operation=$action['operation'];
    [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agent,$cid);
    $result=['operation'=>$operation,'owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$cid,'tracks'=>[]];
    if($operation==='dj_stop')return $result;
    if(isset($action['history_ref'])){
        require_once __DIR__.'/music-history-v1.php';
        try{$history=vp3_music_history_item_v1($pdo,$live,$agent,$cid,$action['history_ref']);}
        catch(RuntimeException){return null;}
        $item=$history['item'];
        if($item['source']!=='vp3'||(int)$item['source_id']!==($action['track_id']??null)
            ||!is_string($action['revision']??null)||!hash_equals($item['native_revision'],$action['revision']))return null;
        if($operation==='resume'&&(empty($history['entry']['resume_available'])||!is_numeric($action['position_seconds']??null)
            ||(float)$history['resume_seconds']!==(float)$action['position_seconds']))return null;
        $track=vp3_media_playable_track_v1($pdo,$live,(int)$item['source_id'],true);
        return $track===null?null:array_replace($result,['tracks'=>[track_media_payload($track)]]
            +($operation==='resume'?['position_seconds'=>(float)$history['resume_seconds']]:[]));
    }
    if($operation==='resume'){
        $source=vp3_media_resume_read_v1($pdo,$live,$principal,time());
        if($source===null||$source['track_id']!==$action['track_id']||!hash_equals($source['revision'],$action['revision'])
            ||$source['position_seconds']!==(float)$action['position_seconds'])return null;
        $track=vp3_media_playable_track_v1($pdo,$live,$source['track_id']);
        return $track===null?null:array_replace($result,['tracks'=>[track_media_payload($track)],
            'position_seconds'=>$source['position_seconds']]);
    }
    $selection=$operation==='play'?[['track_id'=>$action['track_id'],'revision'=>$action['revision']]]:$action['queue'];
    foreach($selection as $item){
        $track=vp3_media_playable_track_v1($pdo,$live,$item['track_id'],true);
        if($track===null||!hash_equals(vp3_media_track_revision_v1($owner,$agent,$track),$item['revision']))return null;
        $result['tracks'][]=track_media_payload($track);
    }
    return $result['tracks']?$result:null;
}

/** Both native receipt writers revalidate an explicitly supplied MC2 action before a start. */
function vp3_media_history_start_authorized_v1(PDO $pdo,?array $user,int $trackId,string $source,array $action,float $position): bool
{
    if(!$user||!in_array($source,['agent_chat','agent_player'],true)||($action['track_id']??null)!==$trackId)return false;
    $expected=($action['operation']??null)==='resume'?(float)($action['position_seconds']??-100):0.0;
    // A playing event may arrive a few frames after the authorized seek. It may
    // not claim an unrelated position under an otherwise valid signed action.
    if(!is_finite($position)||abs($position-$expected)>1.0)return false;
    try{return vp3_media_playback_resolve_v1($pdo,$user,$action)!==null;}
    catch(RuntimeException){return false;}
}
