<?php
declare(strict_types=1);

require_once __DIR__.'/agent-media-playback-v1.php';

/** Verify actual listening; a catalog result or a player start with no listening is insufficient. */
function vp3_media_native_listening_findings_v1(PDO $pdo,array $scope,?array $refs,DateTimeImmutable $now): array
{
    if(!table_exists('track_play_sessions')||db()!==$pdo)return [];
    [$user,$principal]=vp3_media_playback_user_v1($pdo,$scope['user'],$scope['agent_id'],$scope['conversation_id']);
    if(!vp3_media_playback_history_allowed_v1($pdo,$user,$principal))return [];
    $latest=$now->getTimestamp();
    $period=[(int)$user['id'],gmdate('Y-m-d H:i:s',$latest-7*86400),gmdate('Y-m-d H:i:s',$latest)];
    $listened='listened_seconds>0 AND (listened_seconds>=10 OR qualified_play=1 OR completed=1)';
    if($refs===null){
        $read=$pdo->prepare("SELECT track_id FROM track_play_sessions WHERE user_id=? AND started_at BETWEEN ? AND ? AND $listened
            GROUP BY track_id ORDER BY MAX(started_at) DESC,MAX(id) DESC LIMIT 12");
        $read->execute($period);
        $ids=$read->fetchAll(PDO::FETCH_COLUMN);
    }else{
        $ids=[];
        foreach($refs as $ref)if(preg_match('/\Amedia:listen:([1-9][0-9]{0,15})\z/D',$ref,$match))$ids[]=(int)$match[1];
    }
    if(!$ids)return [];
    $read=$pdo->prepare("SELECT id,started_at,listened_seconds,qualified_play,completed FROM track_play_sessions
        WHERE user_id=? AND started_at BETWEEN ? AND ? AND track_id=? AND $listened ORDER BY started_at DESC,id DESC LIMIT 16");
    $items=[];
    foreach($ids as $id){
        $id=(int)$id;
        $read->execute([...$period,$id]);
        $plays=$read->fetchAll(PDO::FETCH_ASSOC);
        if(!$plays)continue;
        $track=music_media_track($id,$user);
        if(!$track||!can_view_track($track,$user))continue;
        $details=vp3_media_track_details_v1($pdo,$track);
        if($details['title']==='')continue;
        $ref='media:listen:'.$id;
        $items[$ref]=vp3_media_native_listening_finding_v1($ref,$details,$plays,$user,$principal,$latest);
        if($refs===null&&count($items)>=4)break;
    }
    return $items;
}

function vp3_media_native_listening_finding_v1(string $ref,array $track,array $plays,array $user,array $principal,int $now): array
{
    $latest=$plays[0];
    $playedAt=strtotime((string)$latest['started_at'].' UTC')?:0;
    $qualified=count(array_filter($plays,static fn(array $play):bool=>(int)$play['qualified_play']===1||(int)$play['completed']===1));
    $completed=count(array_filter($plays,static fn(array $play):bool=>(int)$play['completed']===1));
    $artist=mb_substr($track['artist'],0,95);
    $album=mb_substr($track['album'],0,100);
    $genre=mb_substr($track['genre'],0,80);
    $subject='“'.mb_substr($track['title'],0,145).'”'.($artist!==''?' by '.$artist:'');
    $summary='You played '.$subject.' in the VP3 Music Player.';
    if($qualified>=2)$summary.=' VP3 recorded at least '.$qualified.' substantial listens in the last week.';
    if($album!=='')$summary.=' It is listed on “'.$album.'”.';
    if($genre!=='')$summary.=' The library lists its genre as '.$genre.'.';
    $context=$artist!==''?'more by '.$artist:'related tracks in the VP3 library';
    $engage=$playedAt>=$now-86400&&$qualified>=2&&$completed>=1
        &&((int)$latest['qualified_play']===1||(int)$latest['completed']===1);
    $actions=[['kind'=>'url','label'=>'Open track','href'=>$track['url']]];
    if($track['artist_url']!=='')$actions[]=['kind'=>'url','label'=>'Artist profile','href'=>$track['artist_url']];
    return ['ref'=>$ref,'revision'=>hash('sha256',json_encode([(int)$user['id'],$principal['agent_id'],$track,
        (int)$latest['id'],$playedAt,$qualified,$completed],JSON_THROW_ON_ERROR)),
        'kind'=>'native_played','title'=>$track['title'],'summary'=>$summary,'next_step'=>'Explore '.$context.'.',
        'question'=>$engage?'Want me to find '.$context.' based on these listens?':'',
        'url'=>$track['url'],'actions'=>$actions,'created_at'=>gmdate(DATE_ATOM,$playedAt),'trigger'=>$engage?'listened_finding':null,
        'status'=>'ready','priority'=>'low','specialist'=>'media'];
}
