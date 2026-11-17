<?php
declare(strict_types=1);

require_once __DIR__.'/agent-media-youtube-play-analysis-v1.php';
require_once __DIR__.'/agent-initiative-image-v1.php';
require_once __DIR__.'/agent-coordination-rules-v1.php';

/** Exact playback records are independent of the mutable Media report summary. */
function vp3_initiative_media_image_ref_v1(mixed $ref): bool
{
    return is_string($ref)&&preg_match('/\Amedia-image:(?:music:[1-9][0-9]{0,15}|youtube:[1-9][0-9]{0,15}:[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\z/D',$ref)===1;
}

function vp3_initiative_media_image_enabled_v1(PDO $pdo,array $user,int $agentId): bool
{
    $settings=vp3_coordination_rules_v1($pdo,$user,$agentId);
    return ($settings['rules']['media']['image_concept']??null)==='ask';
}

/** Extract bounded visual cues. Never send URLs, private identifiers, or saved instructions to a provider. */
function vp3_initiative_media_image_text_v1(mixed $value,int $limit): string
{
    $text=agent_connection_youtube_context_text_v1($value,$limit);
    return vp3_initiative_image_prompt_valid_v1($text)?$text:'';
}

function vp3_initiative_media_image_evidence_v1(PDO $pdo,array $user,array $principal,int $cid,
    DateTimeImmutable $now,?array $refs,array &$coverage): array
{
    $coverage['media_images']='ready';
    $agentId=(int)$principal['agent_id'];
    if($cid<1||$refs===[]||vp3_participant_scope_v1()!==null||!vp3_media_playback_history_allowed_v1($pdo,$user,$principal)){
        return [];
    }
    if(!vp3_initiative_media_image_enabled_v1($pdo,$user,$agentId))return [];
    $refs=$refs===null?null:array_values(array_filter($refs,'vp3_initiative_media_image_ref_v1'));
    if($refs===[])return [];
    $out=[];
    foreach(['youtube','music'] as $type){
        $prefix='media-image:'.$type.':';
        $pins=$refs===null?null:array_values(array_filter($refs,static fn(string $ref):bool=>str_starts_with($ref,$prefix)));
        if($pins===[])continue;
        try{
            $rows=$type==='youtube' ? vp3_initiative_media_image_youtube_v1($pdo,$user,$agentId,$cid,$now->getTimestamp(),$pins)
                : vp3_initiative_media_image_native_v1($pdo,$user,$agentId,$now->getTimestamp(),$pins);
            $out+=$rows;
        }catch(PDOException $error){
            // A failed history reader does not erase verified results from the other source.
            $coverage['media_images']='unavailable';
        }
    }
    uasort($out,static fn(array $a,array $b):int=>$b['played_at']<=>$a['played_at']?:strcmp($a['ref'],$b['ref']));
    return $out;
}

function vp3_initiative_media_image_youtube_v1(PDO $pdo,array $user,int $agentId,int $cid,int $at,?array $pins): array
{
    $scope=vp3_youtube_owner_history_scope_v1($pdo,$user,$agentId,$cid,$at);
    if(!$scope||!$scope['allow_history']||!$scope['playback_ready'])return [];
    $capture=vp3_youtube_capture_key_v1($pdo,$scope['owner_id']);
    if($capture==='')return [];
    if($pins===null){
        $plays=vp3_youtube_owner_history_plays_v1($pdo,$scope,$at,includeReturnedResults:true);
    }else{
        $plays=[];
        foreach($pins as $ref){
            [,,$mid,$playId]=explode(':',$ref);
            array_push($plays,...vp3_youtube_owner_history_plays_v1($pdo,$scope,$at,
                ['message_id'=>(int)$mid,'play_id'=>$playId],includeReturnedResults:true));
        }
    }
    if(!hash_equals($capture,vp3_youtube_capture_key_v1($pdo,$scope['owner_id'])))return [];
    $rows=[];
    foreach($plays as $play){
        $ref='media-image:youtube:'.$play['message_id'].':'.$play['play_id'];
        if(!vp3_initiative_media_image_ref_v1($ref)||($pins===null&&$play['played_at']<=$at-86400))continue;
        $context=vp3_media_youtube_play_context_v1($play);
        $title=vp3_initiative_media_image_text_v1($context['title'],100);
        if($title==='')continue;
        $metadata=['channel'=>vp3_initiative_media_image_text_v1($context['channel'],80),
            'description'=>vp3_initiative_media_image_text_v1($context['description'],260),'music'=>($context['music']??false)===true];
        $rows[$ref]=vp3_initiative_media_image_source_v1($ref,'youtube:'.$play['video_id'],$title,$metadata,(int)$play['played_at'],
            vp3_agent_activity_youtube_origin_v1($play,'Review video')['href'],[$scope['owner_id'],$agentId,$capture]);
        if($pins===null&&count($rows)>=4)break;
    }
    return $rows;
}

function vp3_initiative_media_image_native_v1(PDO $pdo,array $user,int $agentId,int $at,?array $pins): array
{
    if(!table_exists('track_play_sessions'))return [];
    $owner=(int)$user['id'];
    $params=[$owner,gmdate('Y-m-d H:i:s',$at)];
    if($pins===null){
        $where=' AND started_at>?';
        $params[]=gmdate('Y-m-d H:i:s',$at-86400);
    }else{
        $ids=array_map(static fn(string $ref):int=>(int)substr($ref,strlen('media-image:music:')),$pins);
        $where=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        array_push($params,...$ids);
    }
    $q=$pdo->prepare("SELECT id,track_id,started_at FROM track_play_sessions WHERE user_id=? AND qualified_play=1
        AND listened_seconds>0 AND started_at<=?$where ORDER BY started_at DESC,id DESC LIMIT 12");
    $q->execute($params);
    $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $record){
        $ref='media-image:music:'.(int)$record['id'];
        if(!vp3_initiative_media_image_ref_v1($ref))continue;
        $track=music_media_track((int)$record['track_id'],$user);
        if(!$track||!can_view_track($track,$user))continue;
        $details=vp3_media_track_details_v1($pdo,$track);
        $title=vp3_initiative_media_image_text_v1($details['title'],100);
        $played=strtotime($record['started_at'].' UTC');
        if($title===''||$played===false)continue;
        $metadata=['artist'=>vp3_initiative_media_image_text_v1($details['artist'],80),
            'album'=>vp3_initiative_media_image_text_v1($details['album'],80),
            'genre'=>vp3_initiative_media_image_text_v1($details['genre'],50),'music'=>true];
        $rows[$ref]=vp3_initiative_media_image_source_v1($ref,'music:'.$details['track_id'],$title,$metadata,$played,
            $details['url'],[$owner,$agentId,vp3_media_track_revision_v1($owner,$agentId,$track)]);
        if($pins===null&&count($rows)>=4)break;
    }
    return $rows;
}

function vp3_initiative_media_image_source_v1(string $ref,string $asset,string $title,array $metadata,
    int $played,string $url,array $grant): array
{
    $revision=hash('sha256',json_encode([$ref,$asset,$title,$metadata,$played,$grant],JSON_THROW_ON_ERROR));
    return ['ref'=>$ref,'kind'=>'media_image_play','title'=>$title,'detail'=>'Verified listening activity from Media & Entertainment.',
        'specialist'=>'media','url'=>$url,'revision'=>$revision,'asset'=>$asset,'played_at'=>$played,'visual_context'=>$metadata];
}
