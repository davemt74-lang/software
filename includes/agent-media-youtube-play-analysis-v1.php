<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-owner-history-v1.php';
require_once __DIR__.'/agent-initiative-artist-v1.php';
require_once __DIR__.'/agent-activity-media-v1.php';

/** A confirmed older play can still use its saved display fields. */
function vp3_media_youtube_play_context_v1(array $video): array
{
    if(is_array($video['public_context']??null))return $video['public_context'];
    return ['title'=>agent_connection_youtube_context_text_v1($video['title']??null,160),
        'channel'=>agent_connection_youtube_context_text_v1($video['channel_title']??null,90),
        'description'=>'','music'=>($video['music_category']??null)===true];
}

/** Use an exact owner-named artist or a public Music-category channel attribution. */
function vp3_media_youtube_attributed_artist_v1(array $context,string $query): ?string
{
    if(($context['music']??null)!==true)return null;
    $channel=$context['channel'];
    $artist=$query===''?null:vp3_initiative_youtube_artist_subject_v1($query,[
        ['title'=>$context['title'],'channel_title'=>$channel,'music_category'=>true,'made_for_kids'=>false,'public_context'=>$context],
    ]);
    if($artist!==null)return $artist;
    if(!preg_match('/\A(.+?)(?:\s+[-–—]\s+Topic|VEVO|\s+Official(?: Artist Channel)?)\z/iu',$channel,$match))return null;
    $name=trim($match[1]);
    return vp3_topic_research_subject_valid_v1($name)?$name:null;
}

/** A report suggestion from private play history is not authority to search or build. */
function vp3_media_youtube_play_analysis_v1(PDO $pdo,array $scope,DateTimeImmutable $now,?array $selected=null): array
{
    [$candidates,$artistVideos]=vp3_media_youtube_play_candidates_v1($pdo,$scope,$now);
    if($selected!==null)$candidates=array_intersect_key($candidates,$selected);
    if(!$candidates)return [];
    require_once __DIR__.'/agent-media-preferences-v1.php';
    $preferences=vp3_media_preferences_ready_v1($pdo)?vp3_media_preferences_read_v1($pdo,$scope['user'],$scope['agent_id'],$scope['conversation_id'],
        vp3_media_preferences_candidates_v1(array_column($candidates,'play'),'youtube')):['ready'=>false];
    $findings=[];
    foreach($candidates as $candidate){
        $signal=null;
        if(($preferences['ready']??false)===true){
            $signal=$preferences['items']['youtube:'.$candidate['play']['video_id']]??null;
            if(!is_array($signal)||($signal['preference']??null)==='dislike')continue;
        }
        $finding=vp3_media_youtube_play_finding_v1($candidate,$artistVideos);
        if($signal!==null){
            $finding['summary'].=' '.vp3_media_preferences_text_v1($signal['reason']??'');
            $finding['preference_revision']=$signal['preference_revision'];
        }
        $findings[]=$finding;
        if($selected===null)break;
    }
    return $findings;
}

/** Reuse the play receipt and current provider metadata for every projection. */
function vp3_media_youtube_play_candidates_v1(PDO $pdo,array $scope,DateTimeImmutable $now): array
{
    $at=$now->getTimestamp();
    $history=vp3_youtube_owner_history_scope_v1($pdo,$scope['user'],$scope['agent_id'],$scope['conversation_id'],$at);
    if($history===null)return [[],[]];
    $plays=vp3_youtube_owner_history_plays_v1($pdo,$history,$at,includeReturnedResults:true);
    $artistVideos=[];
    $candidates=[];
    foreach($plays as $play){
        $context=vp3_media_youtube_play_context_v1($play);
        if($context['title']==='')continue;
        $artist=vp3_media_youtube_attributed_artist_v1($context,$play['results']['query']);
        if($artist!==null)$artistVideos[mb_strtolower($artist)][$play['video_id']]=true;
        $ref='youtube-play-analysis:'.$play['play_id'];
        $candidates[$ref]=['play'=>$play,'context'=>$context,'artist'=>$artist];
    }
    return [$candidates,$artistVideos];
}

/** The Media report tracks each watched video once, witnessed by its exact current saved play. */
function vp3_media_youtube_play_sources_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    if(!vp3_youtube_playback_ready_v1($pdo))return [];
    $owner=(int)$scope['user']['id'];
    $capture=vp3_youtube_capture_key_v1($pdo,$owner);
    if($capture==='')return [];
    [$candidates,$artistVideos]=vp3_media_youtube_play_candidates_v1($pdo,$scope,$now);
    $selected=$refs===null?null:array_fill_keys($refs,true);
    $items=[];
    foreach($candidates as $candidate){
        ['play'=>$play,'context'=>$context,'artist'=>$artist]=$candidate;
        $ref='media:youtube-play:'.$play['video_id'];
        if(isset($items[$ref])||($selected!==null&&!isset($selected[$ref])))continue;
        $finding=vp3_media_youtube_play_finding_v1($candidate,$artistVideos);
        $finding['summary']=mb_substr($finding['summary'],0,600);
        $at=$now->getTimestamp();
        $repeat=$artist!==null&&count($artistVideos[mb_strtolower($artist)])>=2;
        $merch=$artist!==null&&vp3_media_youtube_mentions_merch_v1($context['description']);
        if($repeat&&!$merch){
            $finding['next_step']='Compare the saved clips by '.$artist.' and identify a performance worth revisiting.';
            $finding['question']='Want me to compare “'.$finding['title'].'” with the other '.$artist.' clips you played?';
        }
        $engage=$play['played_at']>=$at-86400&&($repeat||$merch);
        $items[$ref]=array_diff_key($finding,['id'=>true])+['ref'=>$ref,
            'revision'=>hash('sha256',json_encode([$owner,$scope['agent_id'],$scope['conversation_id'],$capture,
                $play['play_id'],$play['expires_at'],$finding],JSON_THROW_ON_ERROR)),
            'url'=>$finding['actions'][0]['href'],'created_at'=>gmdate(DATE_ATOM,$play['played_at']),
            'trigger'=>$engage?'watched_finding':null,'priority'=>'low','status'=>'ready','specialist'=>'media'];
        if($refs===null&&count($items)>=4)break;
    }
    return hash_equals($capture,vp3_youtube_capture_key_v1($pdo,$owner))?$items:[];
}

function vp3_media_youtube_mentions_merch_v1(string $description): bool
{
    return preg_match('/\b(?:merch(?:andise)?|vinyl|official store)\b/iu',$description)===1;
}

function vp3_media_youtube_play_finding_v1(array $candidate,array $artistVideos): array
{
    ['play'=>$play,'context'=>$context,'artist'=>$artist]=$candidate;
    $title=$context['title'];$description=$context['description'];$channel=$context['channel'];
    $finding='You played “'.$title.'” in VP3.';
    if($channel!=='')$finding.=' YouTube lists '.$channel.' as the publisher.';
    if($description!=='')$finding.=' The public description says “'.$description.'”.';
    $topic=$play['results']['query'];
    if($artist===null){
        $next=$topic===''?'Review the video and its publisher.':'Look for more on “'.$topic.'”.';
        $question='Want to watch “'.$title.'” again?';
    }elseif(vp3_media_youtube_mentions_merch_v1($description)){
        $next='Check official artist sources for merchandise and store links.';
        $question='Want me to look for official merch from '.$artist.'?';
    }elseif(count($artistVideos[mb_strtolower($artist)])>=2){
        $next='Sketch a private artist Canvas for the videos, releases, shows and official links.';
        $question='Want a private artist Canvas for '.$artist.'?';
    }else{
        $next='Look for releases, shows and official merch from '.$artist.'.';
        $question='Want me to research '.$artist.'?';
    }
    return ['id'=>'youtube-play-analysis:'.$play['play_id'],'kind'=>'youtube_play_analysis','title'=>$title,
        'summary'=>$finding,'next_step'=>$next,'question'=>$question,
        'actions'=>[vp3_agent_activity_youtube_origin_v1($play,'Review video')]];
}

/** Present an existing Media finding to private assessment using the same current playback proof. */
function vp3_initiative_media_play_evidence_v1(PDO $pdo,array $user,array $policy,DateTimeImmutable $now,?array $selected): array
{
    if(($policy['enabled']??false)!==true||$selected===[]||!vp3_youtube_playback_ready_v1($pdo))return [];
    $owner=(int)$user['id'];
    $capture=vp3_youtube_capture_key_v1($pdo,$owner);
    if($capture==='')return [];
    $scope=['user'=>$user,'agent_id'=>vp3_initiative_binding_agent_v1($policy),'conversation_id'=>$policy['conversation_id']];
    $findings=vp3_media_youtube_play_analysis_v1($pdo,$scope,$now,$selected);
    if(!hash_equals($capture,vp3_youtube_capture_key_v1($pdo,$owner)))return [];
    $evidence=[];
    foreach($findings as $finding){
        $ref=$finding['id'];
        $evidence[$ref]=['ref'=>$ref,'kind'=>$finding['kind'],'specialist'=>'media','title'=>$finding['title'],
            'detail'=>$finding['summary'],'next_step'=>$finding['next_step'],'question'=>$finding['question'],
            'url'=>$finding['actions'][0]['href'],
            'revision'=>hash('sha256',json_encode([$scope['conversation_id'],$capture,$finding],JSON_THROW_ON_ERROR))];
    }
    return $evidence;
}
