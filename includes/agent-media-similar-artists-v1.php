<?php
declare(strict_types=1);

require_once __DIR__.'/agent-media-youtube-play-analysis-v1.php';

/** Match common punctuation and spacing variants while keeping the credited display name. */
function vp3_media_artist_identity_v1(string $name): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower($name))??'';
}

/** A recommendation must come from the search's exact public Music results. */
function vp3_media_similar_artist_results_v1(string $subject,array $videos): array
{
    $seed=vp3_media_artist_identity_v1($subject);
    if($seed===''||!vp3_topic_research_subject_valid_v1($subject))return [];
    $artists=[];
    foreach(agent_connection_youtube_ordered_videos_v1(array_values(array_filter($videos,'is_array'))) as $video){
        $context=$video['public_context']??null;
        if(($video['made_for_kids']??null)!==false||!is_array($context)||($context['music']??null)!==true
            ||!is_string($context['title']??null)||!is_string($context['channel']??null))continue;
        $name=vp3_media_youtube_attributed_artist_v1($context,'');
        if($name===null&&preg_match('/\A(.+?)\s+[-–—]\s+/u',$context['title'],$match)){
            // Plain channel names need a matching title credit. Review and recommendation
            // channels that mention an artist in the title do not establish attribution.
            $credit=trim($match[1]);
            if(vp3_media_artist_identity_v1($context['channel'])===vp3_media_artist_identity_v1($credit)
                &&mb_strlen($credit)<=80&&vp3_topic_research_subject_valid_v1($credit))$name=$credit;
        }
        if($name===null||mb_strlen($name)>80)continue;
        $key=vp3_media_artist_identity_v1($name);
        if($key===''||$key===$seed||isset($artists[$key]))continue;
        $artists[$key]=['artist'=>$name,'video'=>$video];
        if(count($artists)===3)break;
    }
    return array_values($artists);
}

function vp3_media_similar_artist_summary_v1(array $results): string
{
    if(!$results)return '';
    return 'Similar-artist suggestions from YouTube search results: '.implode(', ',array_column($results,'artist'))
        .'. Each has a public music video to try; the comparison is a suggestion.';
}

/** Keep attribution beside the exact playable IDs used by Chat, recall and saved playlists. */
function vp3_media_similar_artist_payload_results_v1(array $payload): array
{
    $subject=$payload['owner_topic']??null;
    if(!is_string($subject)||!is_array($payload['videos']??null))return [];
    $videos=$payload['videos'];
    if(($payload['suggestion_kind']??null)!=='similar'){
        $ids=[];
        foreach(is_array($payload['similar_artists']??null)?array_slice($payload['similar_artists'],0,3):[] as $item){
            if(is_array($item)&&is_string($item['video_id']??null))$ids[$item['video_id']]=true;
        }
        $videos=array_values(array_filter($videos,static fn($video):bool=>is_array($video)&&isset($ids[$video['video_id']??''])));
    }
    return vp3_media_similar_artist_results_v1($subject,$videos);
}

/** One card with at most six videos keeps every recommendation playable through existing actions. */
function vp3_media_similar_artist_attach_v1(?array $playlist,array $similar): ?array
{
    $artists=vp3_media_similar_artist_payload_results_v1($similar);
    if(!$artists)return $playlist;
    $related=array_column($artists,'video');
    $result=$playlist??$similar;
    if($playlist!==null){
        $relatedIds=array_fill_keys(array_column($related,'video_id'),true);
        $base=array_values(array_filter(agent_connection_youtube_ordered_videos_v1($playlist['videos']),
            static fn(array $video):bool=>!isset($relatedIds[$video['video_id']])));
        $result['videos']=[...array_slice($base,0,3),...$related];
        $result['expires_at']=min($playlist['expires_at'],$similar['expires_at']);
    }else $result['videos']=$related;
    $result['owner_topic']=$similar['owner_topic'];
    $result['similar_artists']=array_map(static fn(array $item):array=>
        ['artist'=>$item['artist'],'video_id'=>$item['video']['video_id']],$artists);
    return $result;
}

/** The existing artist worker may fetch one set of recommendations under its live grant. */
function vp3_media_similar_artist_search_v1(PDO $pdo,int $owner,string $subject,callable $authorize,?callable $http=null): ?array
{
    if(!vp3_topic_research_subject_valid_v1($subject)||!agent_connection_youtube_ready_v1()
        ||!agent_connection_youtube_consent_v1($pdo,$owner)||!vp3_youtube_research_enabled_v1($pdo,$owner,'artist')
        ||!agent_connection_youtube_quota_ready_v1($pdo))return null;
    $query='music artists similar to '.mb_substr($subject,0,120);
    try{
        $authorize();
        $day=agent_connection_youtube_reserve_v1($pdo,$owner);
        $videos=agent_connection_youtube_video_status_v1(agent_connection_youtube_search_v1($query,6,$http),$http);
        agent_connection_youtube_record_success_v1($pdo,$owner,$day);
        $authorize();
    }catch(AgentConnectionYoutubeExceptionV1){
        return null;
    }
    if(!agent_connection_youtube_consent_v1($pdo,$owner)||!vp3_youtube_research_enabled_v1($pdo,$owner,'artist'))return null;
    $artists=vp3_media_similar_artist_results_v1($subject,$videos);
    if(!$artists)return null;
    return ['query'=>$query,'owner_topic'=>$subject,'suggestion_kind'=>'similar','initiative_suggestion'=>true,
        'music_suggestion'=>true,'suggestion_reason'=>'Artist suggestions from a YouTube search, with public music videos to try.',
        'similar_artists'=>array_map(static fn(array $item):array=>
            ['artist'=>$item['artist'],'video_id'=>$item['video']['video_id']],$artists),
        'videos'=>array_column($artists,'video'),'expires_at'=>time()+7*86400];
}
