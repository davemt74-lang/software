<?php
declare(strict_types=1);

require_once __DIR__.'/music-chat-library-v1.php';
require_once __DIR__.'/agent-activity-scope-v1.php';

/** Read existing personal receipts. No schema installer, provider refresh or listening writer belongs here. */
const VP3_MUSIC_HISTORY_PROTOCOL_V1='vp3_music_history_v1';
const VP3_MUSIC_HISTORY_LIMIT_V1=50;
const VP3_MUSIC_HISTORY_CHUNK_V1=100;
const VP3_MUSIC_HISTORY_CURSOR_TTL_V1=3600;

function vp3_music_history_scope_v1(PDO $pdo,array $user,int $agent,int $cid): array
{
    if($cid<1||$agent<0||(function_exists('db')&&db()!==$pdo))throw new RuntimeException('Open your private Agent Chat.',403);
    if(function_exists('current_user')){
        $viewer=current_user();
        if(!$viewer||(int)$viewer['id']!==(int)($user['id']??0))throw new RuntimeException('Account access changed.',403);
    }
    $scope=vp3_agent_activity_scope_v1($pdo,$user,$agent,$cid);
    if(!vp3_media_playback_history_allowed_v1($pdo,$scope['user'],$scope['principal'])){
        throw new RuntimeException('This Agent cannot read your listening history.',403);
    }
    return $scope+['owner_user_id'=>(int)$scope['user']['id']];
}

/** Missing old-version tables are coverage gaps; operational SQL errors must not become empty history. */
function vp3_music_history_columns_v1(PDO $pdo,string $table,string $columns): bool
{
    try{$pdo->query('SELECT '.$columns.' FROM '.$table.' WHERE 1=0');return true;}
    catch(PDOException $error){
        $state=(string)($error->errorInfo[0]??$error->getCode());$message=$error->getMessage();
        if(in_array($state,['42S02','42S22'],true)||($state==='HY000'
            &&(str_contains($message,'no such table:')||str_contains($message,'no such column:'))))return false;
        throw $error;
    }
}

function vp3_music_history_timezone_v1(PDO $pdo,array $user,mixed $requested=null): string
{
    $zone=$requested;
    if($zone===null||$zone===''){
        $zone=$user['timezone']??'';
        if($zone===''&&function_exists('user_calendar_default_timezone_v1300'))$zone=user_calendar_default_timezone_v1300($pdo,$user);
        if($zone==='')$zone='UTC';
    }
    if(!is_string($zone)||strlen($zone)>80||!in_array($zone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true)){
        throw new RuntimeException('Choose a valid IANA timezone for listening history.',422);
    }
    return $zone;
}

function vp3_music_history_date_v1(mixed $value,DateTimeZone $zone): ?DateTimeImmutable
{
    if($value===null||$value==='')return null;
    if(!is_string($value)||!preg_match('/\A(?:19[7-9][0-9]|[2-9][0-9]{3})-[0-9]{2}-[0-9]{2}\z/D',$value)){
        throw new RuntimeException('Use a valid YYYY-MM-DD history date.',422);
    }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,$zone);$errors=DateTimeImmutable::getLastErrors();
    if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==$value){
        throw new RuntimeException('Use a valid local history date.',422);
    }
    return $date;
}

function vp3_music_history_instant_v1(mixed $value): ?int
{
    if($value===null||$value==='')return null;
    if(!is_string($value)||!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])\z/D',$value)){
        throw new RuntimeException('Use an RFC3339 history time with an explicit offset.',422);
    }
    $normalized=str_ends_with($value,'Z')?substr($value,0,-1).'+00:00':$value;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$normalized);$errors=DateTimeImmutable::getLastErrors();
    if(!$date||($errors&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i:sP')!==$normalized||$date->getTimestamp()<0){
        throw new RuntimeException('Use a valid history time.',422);
    }
    return $date->getTimestamp();
}

function vp3_music_history_iso_v1(int $epoch): string{return gmdate('Y-m-d\TH:i:s\Z',$epoch);}

function vp3_music_history_filters_v1(PDO $pdo,array $scope,array $input): array
{
    if(array_diff(array_keys($input),['source','query','from','to','from_at','until_at','timezone','limit','cursor'])){
        throw new RuntimeException('Unknown listening history filter.',422);
    }
    $source=$input['source']??'both';$query=$input['query']??'';$limit=$input['limit']??20;
    if(!in_array($source,['both','vp3','youtube'],true)||!is_string($query)||!mb_check_encoding($query,'UTF-8')
        ||mb_strlen($query)>200||preg_match('/[\p{Cc}\p{Cf}]/u',$query)||!is_int($limit)||$limit<1||$limit>VP3_MUSIC_HISTORY_LIMIT_V1){
        throw new RuntimeException('Choose a source, a short text filter and a page size from 1 to 50.',422);
    }
    $timezone=vp3_music_history_timezone_v1($pdo,$scope['user'],$input['timezone']??null);$zone=new DateTimeZone($timezone);
    $from=vp3_music_history_date_v1($input['from']??null,$zone);$to=vp3_music_history_date_v1($input['to']??null,$zone);
    $fromAt=vp3_music_history_instant_v1($input['from_at']??null);$untilAt=vp3_music_history_instant_v1($input['until_at']??null);
    if(($from||$to)&&($fromAt!==null||$untilAt!==null))throw new RuntimeException('Choose local dates or exact times, not both.',422);
    $start=$from?$from->getTimestamp():($fromAt??0);$end=$to?$to->modify('+1 day')->getTimestamp():$untilAt;
    if($end!==null&&$start>=$end)throw new RuntimeException('The history end must be after its start.',422);
    return ['filters'=>['source'=>$source,'query'=>trim($query),'from'=>$from?->format('Y-m-d'),'to'=>$to?->format('Y-m-d'),
        'from_at'=>$fromAt===null?null:vp3_music_history_iso_v1($fromAt),'until_at'=>$untilAt===null?null:vp3_music_history_iso_v1($untilAt),
        'timezone'=>$timezone],'limit'=>$limit,'start'=>$start,'end'=>$end];
}

function vp3_music_history_b64_v1(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function vp3_music_history_unb64_v1(string $value): string
{
    if(!preg_match('/\A[A-Za-z0-9_-]+\z/D',$value))throw new RuntimeException('Invalid history reference.',422);
    $raw=base64_decode(strtr($value,'-_','+/'),true);
    if($raw===false||vp3_music_history_b64_v1($raw)!==$value)throw new RuntimeException('Invalid history reference.',422);
    return $raw;
}

/** A durable opaque address, never a capability. No provider text or session bearer secret is persisted. */
function vp3_music_history_reference_v1(int $owner,string $kind,string $id): string
{
    return 'mh1.'.vp3_music_history_b64_v1(json_encode([1,$owner,$kind,$id],JSON_THROW_ON_ERROR));
}

function vp3_music_history_reference_parse_v1(array $scope,string $reference): array
{
    if(strlen($reference)>240||!str_starts_with($reference,'mh1.'))throw new RuntimeException('Choose an exact history entry.',422);
    try{$value=json_decode(vp3_music_history_unb64_v1(substr($reference,4)),true,8,JSON_THROW_ON_ERROR);}
    catch(JsonException){throw new RuntimeException('Choose an exact history entry.',422);}
    if(!is_array($value)||!array_is_list($value)||count($value)!==4||$value[0]!==1||!is_int($value[1])
        ||!in_array($value[2],['n','y','p'],true)||!is_string($value[3])
        ||($value[2]==='n'?preg_match('/\A[1-9][0-9]{0,18}\z/D',$value[3])!==1:preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D',$value[3])!==1)
        ||vp3_music_history_reference_v1($value[1],$value[2],$value[3])!==$reference){
        throw new RuntimeException('Choose an exact history entry.',422);
    }
    if($value[1]!==$scope['owner_user_id'])throw new RuntimeException('History entry unavailable.',404);
    return [$value[2],$value[3]];
}

function vp3_music_history_cursor_v1(array $payload): string
{
    $secret=vp3_media_playback_secret_v1();
    if($secret===null)throw new RuntimeException('Reopen your private Agent Chat to page through history.',503);
    $body=vp3_music_history_b64_v1(json_encode($payload,JSON_THROW_ON_ERROR));
    return 'mhc1.'.$body.'.'.hash_hmac('sha256','music-history-page-v1|'.$body,$secret);
}

function vp3_music_history_cursor_parse_v1(string $cursor,array $binding,int $now): array
{
    if(strlen($cursor)>4096||!preg_match('/\Amhc1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$cursor,$parts))throw new RuntimeException('Invalid history page. Start a new query.',422);
    $secret=vp3_media_playback_secret_v1();
    if($secret===null||!hash_equals(hash_hmac('sha256','music-history-page-v1|'.$parts[1],$secret),$parts[2])){
        throw new RuntimeException('History page expired. Start a new query.',409);
    }
    try{$data=json_decode(vp3_music_history_unb64_v1($parts[1]),true,12,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('Invalid history page.',422);}
    if(!is_array($data)||($data['binding']??null)!==$binding||!is_int($data['as_of']??null)||$data['as_of']>$now
        ||$data['as_of']<$now-VP3_MUSIC_HISTORY_CURSOR_TTL_V1||!is_int($data['native_max']??null)||$data['native_max']<0
        ||!is_array($data['after']??null)||count($data['after'])!==3||!is_int($data['after'][0])
        ||!in_array($data['after'][1],[1,2,3],true)||!is_string($data['after'][2])||strlen($data['after'][2])>40){
        throw new RuntimeException('History page changed or expired. Start a new query.',409);
    }
    return $data;
}

function vp3_music_history_sources_v1(PDO $pdo,array $scope): array
{
    $native=vp3_music_history_columns_v1($pdo,'track_play_sessions','id,track_id,user_id,started_at,last_position_seconds,duration_seconds,listened_seconds,completed');
    $measured=$native&&vp3_music_history_columns_v1($pdo,'music_listening_sessions_v1','session_id,asset_id,recording_id,workspace_id,catalog_track_id,identity_status,asset_binding,duration_ms,duration_source,accepted_played_ms,position_ms,natural_ended');
    $youtube=vp3_music_chat_youtube_allowed_v1($pdo,$scope);$accepted=0;
    if($youtube){
        $q=$pdo->prepare('SELECT accepted_at FROM agent_connection_youtube_consent_v1 WHERE owner_user_id=? AND policy_version=?');
        $q->execute([$scope['owner_user_id'],VP3_YOUTUBE_CONSENT_POLICY_V1]);$accepted=(int)$q->fetchColumn();
        $youtube=$accepted>0&&$accepted<=time();
    }
    $ordinary=$youtube&&vp3_music_history_columns_v1($pdo,'vp3_youtube_playback_v1','owner_user_id,play_id,message_id,video_id,played_at')
        &&vp3_music_history_columns_v1($pdo,'agent_connection_youtube_results_v1','message_id,payload_json,expires_at');
    $playlist=$youtube&&vp3_youtube_playlists_ready_v1($pdo);
    $capture=$youtube&&vp3_music_history_columns_v1($pdo,'vp3_youtube_history_settings_v1','owner_user_id,capture_key')
        &&vp3_youtube_capture_key_v1($pdo,$scope['owner_user_id'])!=='';
    $watch=$youtube&&vp3_youtube_watch_research_enabled_v1($pdo,$scope['owner_user_id']);
    return ['native'=>$native,'measured'=>$measured,'youtube'=>$youtube,'consent_at'=>$accepted,'ordinary'=>$ordinary,'playlist'=>$playlist,
        'capture'=>$capture,'watch'=>$watch,'watch_table'=>$ordinary&&vp3_youtube_watch_ready_v1($pdo),
        'position_table'=>$ordinary&&vp3_youtube_playhead_ready_v1($pdo)];
}

function vp3_music_history_coverage_v1(array $sources,int $asOf): array
{
    return ['summary'=>'Individual playback receipts retained by VP3, with current access and metadata checks. This is not a complete lifetime archive.',
        'notes'=>['Repeated plays remain separate. A recorded start does not prove that audio was heard.',
            'Pages include starts before '.vp3_music_history_iso_v1($asOf).'. New plays require a fresh query; measurement and availability can change.',
            'Dates use the selected timezone. Exact time ranges exclude their end. No preference or taste is inferred.'],
        'sources'=>[
            'vp3'=>['status'=>$sources['native']?'ready':'unavailable',
                'summary'=>$sources['native']?'All retained account-owned VP3 playback sessions can be paged. Removed history cannot be reconstructed.':'VP3 listening history is unavailable until the existing player schema is installed.',
                'notes'=>['Native receipt capture has no automatic history deletion in this service; the 24-hour receipt-session lifetime is not history retention.',
                    'Legacy totals are reported listening; newer native receipts use accepted measured time. Neither establishes audibility.',
                    'Metadata is redacted when current viewing access is unavailable. Play separately requires current playback authority; Resume also requires a matching current asset and measured position.'],
                'measurement_available'=>$sources['measured']],
            'youtube'=>['status'=>!$sources['youtube']?'disabled':(($sources['ordinary']||$sources['playlist'])?'ready':'unavailable'),
                'summary'=>!$sources['youtube']?'YouTube history is hidden while the connection or consent is disabled.':'Only YouTube plays recorded inside VP3 are included while their authorized metadata remains current.',
                'notes'=>['Only plays recorded since the current YouTube consent are available. Reaccepting a policy does not revive older receipts.',
                    'Search-result receipts expire with their existing result cache. Saved-playlist receipts are limited to seven days and their current metadata cache.',
                    'Expired, removed, shared, participant or inaccessible source records are omitted. This is not imported YouTube account watch history.',
                    $sources['capture']?'History capture is enabled.':'History capture is paused; retained starts remain readable and Resume is unavailable.',
                    $sources['watch']?'Measured watch time is shown only where a watch receipt exists.':'Watch measurement is off; retained starts are shown without watch duration.'],
                'capture_enabled'=>$sources['capture'],'measurement_enabled'=>$sources['watch']],
        ]];
}

/** skip_brain_archive controls memory, not a separately accepted private playback receipt. */
function vp3_music_history_private_context_v1(mixed $raw): bool
{
    if($raw===null||$raw==='')return true;
    if(!is_string($raw)||strlen($raw)>262144)return false;
    try{$context=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(JsonException){return false;}
    if(!is_array($context))return false;
    foreach(['participant_tasks_v1','participant_context','participant_scope','participant_id','voice_speaker','shared_context',
        'shared_chat','shared','shared_with','speaker_context','public_chat','public_profile','profile_visitor'] as $key){
        if(!empty($context[$key]))return false;
    }
    if(in_array($context['source']??null,['participant','participant_chat','shared_chat','team_chat','human_chat','public_chat','profile_agent','public_profile'],true))return false;
    return !isset($context['agent_context'])||(is_array($context['agent_context'])
        &&vp3_music_history_private_context_v1(json_encode($context['agent_context'],JSON_THROW_ON_ERROR)));
}

function vp3_music_history_youtube_context_v1(PDO $pdo,array $scope,array $row): bool
{
    if((int)($row['conversation_id']??0)<1||(int)($row['conversation_owner']??0)!==$scope['owner_user_id']
        ||($row['message_role']??null)!=='assistant'||$row['message_user_id']!==null
        ||!vp3_music_history_private_context_v1($row['context_json']??null))return false;
    $sourceAgent=(int)($row['source_agent_id']??0);
    try{
        $agent=vp3_agent_chat_resolve_agent_v380($pdo,$scope['user'],$sourceAgent);
        if(!vp3_media_playback_history_allowed_v1($pdo,$scope['user'],vp3_agent_chat_principal_v380($scope['user'],$agent)))return false;
    }catch(PDOException $error){throw $error;}catch(RuntimeException){return false;}
    $context=json_decode((string)($row['context_json']??''),true)?:[];
    if(isset($context['chat_user_message_id'])){
        if(!is_int($context['chat_user_message_id'])||$context['chat_user_message_id']<1||$context['chat_user_message_id']>=(int)$row['message_id'])return false;
        $q=$pdo->prepare("SELECT context_json FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
        $q->execute([$context['chat_user_message_id'],(int)$row['conversation_id'],$scope['owner_user_id']]);$request=$q->fetch(PDO::FETCH_ASSOC);
        if(!$request||!vp3_music_history_private_context_v1($request['context_json']))return false;
    }
    return true;
}

function vp3_music_history_native_resume_v1(PDO $pdo,array $row,array $track): ?float
{
    if(empty($row['asset_id'])||empty($row['recording_id'])||($row['identity_status']??null)!=='mapped'
        ||($row['duration_source']??null)!=='server_mp3_probe'||(int)($row['accepted_played_ms']??0)<=0
        ||(int)($row['natural_ended']??0)!==0||(int)($row['completed']??0)!==0)return null;
    $position=(int)($row['position_ms']??0);$duration=(int)($row['duration_ms']??0);
    if($position<1000||$duration<1||$position>=$duration-1000||$duration>43200000)return null;
    if(!vp3_music_history_columns_v1($pdo,'music_recordings_v1','recording_id,workspace_id,catalog_track_id,source_track_id,current_asset_id')
        ||!vp3_music_history_columns_v1($pdo,'music_assets_v1','asset_id,recording_id,workspace_id,stored_path')
        ||!vp3_music_history_columns_v1($pdo,'artist_catalog_tracks_v181','id,workspace_id,source_track_id,audio_path'))return null;
    $q=$pdo->prepare('SELECT a.stored_path,c.audio_path FROM music_recordings_v1 r
        JOIN music_assets_v1 a ON a.asset_id=r.current_asset_id AND a.recording_id=r.recording_id AND a.workspace_id=r.workspace_id
        JOIN artist_catalog_tracks_v181 c ON c.id=r.catalog_track_id AND c.workspace_id=r.workspace_id AND c.source_track_id=r.source_track_id
        WHERE r.recording_id=? AND r.current_asset_id=? AND r.workspace_id=? AND r.catalog_track_id=? AND r.source_track_id=?');
    $q->execute([$row['recording_id'],$row['asset_id'],(int)$row['workspace_id'],(int)$row['catalog_track_id'],(int)$row['track_id']]);$asset=$q->fetch(PDO::FETCH_ASSOC);
    if(!$asset||(string)$asset['stored_path']!==(string)$asset['audio_path']
        ||(string)$asset['stored_path']!==(string)($track['audio_path']??'')
        ||(int)($track['workspace_id']??0)!==(int)$row['workspace_id'])return null;
    return $position/1000;
}

function vp3_music_history_project_v1(PDO $pdo,array $scope,array $sources,string $kind,string $id,string $timezone,int $now): ?array
{
    $owner=$scope['owner_user_id'];$item=null;$display=null;$resume=null;$listened=null;$measurement='started_only';$expired=false;
    if($kind==='n'){
        if(!$sources['native'])return null;
        $versioned=$sources['measured']?'s.session_id AS measured_id,s.asset_id,s.recording_id,s.workspace_id,s.catalog_track_id,s.identity_status,s.asset_binding,s.duration_ms,s.duration_source,s.accepted_played_ms,s.position_ms,s.natural_ended':'NULL AS measured_id';
        $q=$pdo->prepare('SELECT p.*,'.$versioned.' FROM track_play_sessions p '
            .($sources['measured']?'LEFT JOIN music_listening_sessions_v1 s ON s.session_id=p.id ':'').'WHERE p.user_id=? AND p.id=?');
        $q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$row['started_at'],new DateTimeZone('UTC'));
        if(!$date)return null;$played=$date->getTimestamp();
        $track=music_media_track((int)$row['track_id'],$scope['user']);
        if($track&&can_view_track($track,$scope['user'])){
            $details=vp3_media_track_details_v1($pdo,$track);$payload=track_media_payload($track);
            $display=['source_id'=>(string)$row['track_id'],'title'=>$details['title'],'artist'=>$details['artist'],'album'=>$details['album'],
                'cover'=>is_string($payload['cover']??null)?$payload['cover']:''];
        }
        $item=vp3_music_chat_native_item_v1($pdo,$scope,(string)$row['track_id']);
        if($row['measured_id']!==null){
            if((int)$row['accepted_played_ms']>0){$listened=(int)$row['accepted_played_ms']/1000;$measurement='native_receipts';}
            if($item&&$track)$resume=vp3_music_history_native_resume_v1($pdo,$row,$track);
        }elseif(is_numeric($row['listened_seconds'])&&(float)$row['listened_seconds']>0){
            $listened=(float)$row['listened_seconds'];$measurement='native_legacy_reported';
        }
        $source='vp3';
    }else{
        if(!$sources['youtube']||($kind==='y'&&!$sources['ordinary'])||($kind==='p'&&!$sources['playlist']))return null;
        $context='m.id AS message_id,m.role AS message_role,m.user_id AS message_user_id,m.context_json,c.id AS conversation_id,c.user_id AS conversation_owner,c.user_agent_id AS source_agent_id';
        if($kind==='y'){
            $watch=$sources['watch']&&$sources['watch_table'];$position=$sources['capture']&&$sources['position_table'];
            $q=$pdo->prepare('SELECT p.*,r.payload_json,r.expires_at,'.$context.','
                .($watch?'w.watch_seconds,w.last_watched_at':'NULL AS watch_seconds,NULL AS last_watched_at').','
                .($position?'h.position_ms,h.position_seq,h.position_at':'NULL AS position_ms,0 AS position_seq,0 AS position_at')
                .' FROM vp3_youtube_playback_v1 p JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
                LEFT JOIN chat_messages m ON m.id=p.message_id LEFT JOIN chat_conversations c ON c.id=m.conversation_id '
                .($watch?'LEFT JOIN vp3_youtube_watch_v1 w ON w.owner_user_id=p.owner_user_id AND w.play_id=p.play_id ':'')
                .($position?'LEFT JOIN vp3_youtube_playhead_v1 h ON h.owner_user_id=p.owner_user_id AND h.play_id=p.play_id ':'')
                .'WHERE p.owner_user_id=? AND p.play_id=?');
        }else{
            $q=$pdo->prepare('SELECT p.*,l.videos_json,l.cached_until AS expires_at,'.$context.' FROM vp3_youtube_playlist_plays_v1 p
                JOIN vp3_youtube_saved_playlists_v1 l ON l.owner_user_id=p.owner_user_id AND l.playlist_id=p.playlist_id
                LEFT JOIN chat_messages m ON m.id=l.source_message_id LEFT JOIN chat_conversations c ON c.id=m.conversation_id
                WHERE p.owner_user_id=? AND p.play_id=?');
        }
        $q->execute([$owner,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||!vp3_music_history_youtube_context_v1($pdo,$scope,$row))return null;
        $played=(int)$row['played_at'];$expires=(int)$row['expires_at'];
        if($played<$sources['consent_at'])return null;
        if($kind==='p')$expires=min($expires,$played+VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1);
        $expired=$expires<=$now;
        if(!$expired){
            try{
                $payload=$kind==='y'?vp3_music_chat_json_v1($row['payload_json']):['videos'=>vp3_music_chat_json_v1($row['videos_json'])];
                if($kind==='y'&&isset($payload['expires_at']))$expires=min($expires,(int)$payload['expires_at']);
                $video=$expires>$now?vp3_music_chat_find_video_v1($payload,(string)$row['video_id']):null;
                if($video)$item=['source'=>'youtube','source_id'=>(string)$row['video_id'],'title'=>$video['title'],'artist'=>$video['channel_title'],
                    'album'=>'','duration'=>'','cover'=>$video['thumbnail_url'],'youtube_video'=>$video,'expires_at'=>$expires];
            }catch(RuntimeException){$item=null;}
        }
        if($sources['watch']&&(int)($row['watch_seconds']??0)>0){
            $listened=min(VP3_YOUTUBE_MAX_WATCH_SECONDS_V1,(int)$row['watch_seconds']);$measurement='youtube_watch_receipts';
        }
        $position=(int)($row['position_ms']??0);
        if($item&&$sources['capture']&&$position>=1000&&$position<=VP3_YOUTUBE_MAX_POSITION_MS_V1
            &&(int)($row['position_seq']??0)>0&&(int)($row['position_at']??0)>=$played&&(int)$row['position_at']<=$now)$resume=$position/1000;
        $source='youtube';$display=$item;
    }
    if($played<0||$played>$now)return null;
    $entry=['entry_ref'=>vp3_music_history_reference_v1($owner,$kind,$id),'source'=>$source,
        'source_id'=>$display['source_id']??'','title'=>$display['title']??($source==='vp3'?'Unavailable VP3 song':'Unavailable YouTube video'),
        'artist'=>$display['artist']??'','album'=>$display['album']??'','cover'=>$display['cover']??'',
        'played_at'=>vp3_music_history_iso_v1($played),
        'played_at_local'=>(new DateTimeImmutable('@'.$played))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i:sP'),
        'listened_seconds'=>$listened,'evidence'=>$listened===null?'started_only':'measured','measurement'=>$measurement,
        'resume_seconds'=>$resume,'resume_available'=>$resume!==null,'play_available'=>$item!==null,'metadata_available'=>$display!==null];
    return ['entry'=>$entry,'item'=>$item,'resume_seconds'=>$resume,'expired'=>$expired];
}

/** Exact quiet restore rechecks current authority and never reruns the original search. */
function vp3_music_history_entry_v1(PDO $pdo,array $user,int $agent,int $cid,string $reference): array
{
    $scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);[$kind,$id]=vp3_music_history_reference_parse_v1($scope,$reference);
    $data=vp3_music_history_project_v1($pdo,$scope,vp3_music_history_sources_v1($pdo,$scope),$kind,$id,vp3_music_history_timezone_v1($pdo,$scope['user']),time());
    if(!$data)throw new RuntimeException('This listening history entry is no longer available.',404);
    return $data['entry'];
}

/** Only this server-produced canonical item may bridge an explicit selection into MC1. */
function vp3_music_history_item_v1(PDO $pdo,array $user,int $agent,int $cid,string $reference): array
{
    $scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);[$kind,$id]=vp3_music_history_reference_parse_v1($scope,$reference);
    $data=vp3_music_history_project_v1($pdo,$scope,vp3_music_history_sources_v1($pdo,$scope),$kind,$id,vp3_music_history_timezone_v1($pdo,$scope['user']),time());
    if(!$data)throw new RuntimeException('This listening history entry is no longer available.',404);
    if(!$data['item'])throw new RuntimeException('This history item is no longer playable.',409);
    unset($data['expired']);return $data;
}

/** Bounded SQL chunks, no hidden scan ceiling. Current metadata filtering happens before page completion. */
function vp3_music_history_candidates_v1(PDO $pdo,array $scope,array $sources,array $filter,array $page,?array $after): array
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$cast=$mysql?'SIGNED':'INTEGER';$owner=$scope['owner_user_id'];$parts=[];$args=[];
    $start=$filter['start'];$end=min($page['as_of'],$filter['end']??$page['as_of']);$source=$filter['filters']['source'];
    if($end<=$start)return [];
    if($source!=='youtube'&&$sources['native']){
        $epoch=$mysql?"TIMESTAMPDIFF(SECOND,'1970-01-01 00:00:00',p.started_at)":"CAST(strftime('%s',p.started_at) AS INTEGER)";
        $key=$mysql?"CAST(LPAD(CAST(p.id AS CHAR),20,'0') AS BINARY)":"printf('%020d',p.id)";
        $parts[]="SELECT {$epoch} AS sort_at,1 AS sort_kind,{$key} AS sort_id FROM track_play_sessions p
            WHERE p.user_id=? AND p.id<=CAST(? AS {$cast}) AND p.started_at>=? AND p.started_at<?";
        array_push($args,$owner,$page['native_max'],gmdate('Y-m-d H:i:s',$start),gmdate('Y-m-d H:i:s',$end));
    }
    if($source!=='vp3'&&$sources['ordinary']){
        $key=$mysql?'CAST(p.play_id AS BINARY)':'p.play_id';
        $parts[]="SELECT p.played_at AS sort_at,2 AS sort_kind,{$key} AS sort_id FROM vp3_youtube_playback_v1 p
            JOIN agent_connection_youtube_results_v1 r ON r.message_id=p.message_id
            WHERE p.owner_user_id=? AND p.played_at>=CAST(? AS {$cast}) AND p.played_at<CAST(? AS {$cast}) AND r.expires_at>CAST(? AS {$cast})";
        array_push($args,$owner,max($start,$sources['consent_at']),$end,time());
    }
    if($source!=='vp3'&&$sources['playlist']){
        $key=$mysql?'CAST(p.play_id AS BINARY)':'p.play_id';
        $parts[]="SELECT p.played_at AS sort_at,3 AS sort_kind,{$key} AS sort_id FROM vp3_youtube_playlist_plays_v1 p
            JOIN vp3_youtube_saved_playlists_v1 l ON l.owner_user_id=p.owner_user_id AND l.playlist_id=p.playlist_id
            WHERE p.owner_user_id=? AND p.played_at>=CAST(? AS {$cast}) AND p.played_at<CAST(? AS {$cast}) AND l.cached_until>CAST(? AS {$cast})";
        array_push($args,$owner,max($start,$sources['consent_at'],time()-VP3_YOUTUBE_PLAYLIST_CACHE_SECONDS_V1+1),$end,time());
    }
    if(!$parts)return [];
    $where='';
    if($after!==null){
        $where=" WHERE (sort_at<CAST(? AS {$cast}) OR (sort_at=CAST(? AS {$cast}) AND sort_kind<CAST(? AS {$cast}))
            OR (sort_at=CAST(? AS {$cast}) AND sort_kind=CAST(? AS {$cast}) AND sort_id<?))";
        array_push($args,$after[0],$after[0],$after[1],$after[0],$after[1],$after[2]);
    }
    $q=$pdo->prepare('SELECT sort_at,sort_kind,sort_id FROM ('.implode(' UNION ALL ',$parts).') music_history_rows'.$where
        .' ORDER BY sort_at DESC,sort_kind DESC,sort_id DESC LIMIT '.VP3_MUSIC_HISTORY_CHUNK_V1);
    $q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);
}

function vp3_music_history_read_v1(PDO $pdo,array $user,int $agent,int $cid,array $input): array
{
    $scope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);$filter=vp3_music_history_filters_v1($pdo,$scope,$input);
    $sources=vp3_music_history_sources_v1($pdo,$scope);$now=time();$cursor=$input['cursor']??'';
    if($cursor===null)$cursor='';if(!is_string($cursor))throw new RuntimeException('Invalid history page.',422);
    $binding=[$scope['owner_user_id'],$agent,$cid,$filter['filters'],$filter['limit']];
    if($cursor!=='')$page=vp3_music_history_cursor_parse_v1($cursor,$binding,$now);
    else{
        $max=0;if($sources['native']){$q=$pdo->prepare('SELECT MAX(id) FROM track_play_sessions WHERE user_id=?');$q->execute([$scope['owner_user_id']]);$max=(int)$q->fetchColumn();}
        $page=['binding'=>$binding,'as_of'=>$now,'native_max'=>$max,'after'=>null];
    }
    $after=$page['after'];$items=[];$last=null;$more=false;$query=mb_strtolower($filter['filters']['query']);
    while(true){
        $rows=vp3_music_history_candidates_v1($pdo,$scope,$sources,$filter,$page,$after);
        foreach($rows as $row){
            $after=[(int)$row['sort_at'],(int)$row['sort_kind'],(string)$row['sort_id']];
            $kind=match($after[1]){1=>'n',2=>'y',3=>'p'};$id=$kind==='n'?ltrim($after[2],'0'):$after[2];
            $data=vp3_music_history_project_v1($pdo,$scope,$sources,$kind,$id,$filter['filters']['timezone'],time());
            if(!$data||($kind!=='n'&&!$data['item']))continue;
            $entry=$data['entry'];
            if($query!==''&&(!$entry['metadata_available']||!str_contains(mb_strtolower($entry['title'].' '.$entry['artist'].' '.$entry['album']),$query)))continue;
            if(count($items)===$filter['limit']){$more=true;break 2;}
            $items[]=$entry;$last=$after;
        }
        if(count($rows)<VP3_MUSIC_HISTORY_CHUNK_V1)break;
    }
    // A long filtered scan must not return titles captured before a live grant
    // changed. Revalidate the returned rows, including source-Agent provenance.
    $freshScope=vp3_music_history_scope_v1($pdo,$user,$agent,$cid);$freshSources=vp3_music_history_sources_v1($pdo,$freshScope);
    if($freshSources!==$sources)throw new RuntimeException('History settings changed. Start a new query.',409);
    foreach($items as &$entry){
        [$kind,$id]=vp3_music_history_reference_parse_v1($freshScope,$entry['entry_ref']);
        $fresh=vp3_music_history_project_v1($pdo,$freshScope,$freshSources,$kind,$id,$filter['filters']['timezone'],time());
        if(!$fresh||($kind!=='n'&&!$fresh['item'])||($query!==''&&(!$fresh['entry']['metadata_available']
            ||!str_contains(mb_strtolower($fresh['entry']['title'].' '.$fresh['entry']['artist'].' '.$fresh['entry']['album']),$query)))){
            throw new RuntimeException('History availability changed. Start a new query.',409);
        }
        $entry=$fresh['entry'];
    }
    unset($entry);
    $next=null;if($more){$page['after']=$last;$next=vp3_music_history_cursor_v1($page);}
    return ['protocol'=>VP3_MUSIC_HISTORY_PROTOCOL_V1,'items'=>$items,'filters'=>$filter['filters'],'limit'=>$filter['limit'],
        'as_of'=>vp3_music_history_iso_v1($page['as_of']),'timezone'=>$filter['filters']['timezone'],'next_cursor'=>$next,'has_more'=>$more,
        'coverage'=>vp3_music_history_coverage_v1($sources,$page['as_of'])];
}
