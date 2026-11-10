<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-youtube-v1.php';
require_once __DIR__.'/agent-connection-youtube-consent-v1.php';
require_once __DIR__.'/agent-connection-youtube-quota-v1.php';

/** Provider metadata awaiting Chat delivery belongs in the same expiring cache lifecycle. */
function vp3_initiative_youtube_playlist_install_v1(PDO $pdo): void
{
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $pdo->exec('CREATE TABLE IF NOT EXISTS vp3_initiative_youtube_playlist_v1 (
        run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        expires_at BIGINT UNSIGNED NOT NULL,
        payload_json TEXT NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE'
        .($mysql?', INDEX idx_initiative_youtube_playlist_expiry (expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':')'));
}

function vp3_initiative_youtube_playlist_ready_v1(PDO $pdo): bool
{
    try {
        return $pdo->query('SELECT run_id,owner_user_id,expires_at,payload_json FROM vp3_initiative_youtube_playlist_v1 WHERE 1=0')!==false;
    } catch(PDOException) { return false; }
}

function vp3_initiative_youtube_playlist_cleanup_v1(PDO $pdo): void
{
    if(!vp3_initiative_youtube_playlist_ready_v1($pdo))return;
    $stmt=$pdo->prepare('DELETE FROM vp3_initiative_youtube_playlist_v1 WHERE expires_at<=?');
    $stmt->execute([time()]);
}

/** Only exact, canonical YouTube video links returned by the research tool. */
function vp3_initiative_youtube_source_video_v1(string $source): ?string
{
    $url=parse_url($source);
    if(!$url||($url['scheme']??'')!=='https'||isset($url['user'])||isset($url['pass'])||isset($url['port']))return null;
    $host=strtolower($url['host']??'');$path=$url['path']??'';$id='';
    if($host==='youtu.be')$id=ltrim($path,'/');
    elseif(in_array($host,['youtube.com','www.youtube.com','m.youtube.com'],true)){
        if($path==='/watch'){
            parse_str($url['query']??'',$query);
            $id=$query['v']??'';
        }elseif(preg_match('~\A/(?:live|shorts|embed)/([A-Za-z0-9_-]{11})/?\z~D',$path,$match))$id=$match[1];
    }
    return is_string($id)&&preg_match('/\A[A-Za-z0-9_-]{11}\z/D',$id)?$id:null;
}

/** Prepare a small suggestion only for a verified YouTube interest assigned to report. */
function vp3_initiative_youtube_playlist_prepare_v1(PDO $pdo,int $owner,string $subject,string $kind,string $focus,array $sources,
    callable $authorize,?callable $http=null): ?array
{
    if(!agent_connection_youtube_ready_v1()||!agent_connection_youtube_consent_v1($pdo,$owner)
        ||!vp3_initiative_youtube_playlist_ready_v1($pdo))return null;
    $videos=[];
    $ids=[];
    foreach($sources as $source){
        $id=vp3_initiative_youtube_source_video_v1((string)($source['url']??''));
        if($id!==null)$ids[]=$id;
    }
    if(!$ids&&!in_array($focus,['playlist','videos'],true))return null;
    try{
        $authorize();
        foreach(agent_connection_youtube_playable_videos_v1($ids,$http) as $video)$videos[$video['video_id']]=$video;
        $authorize();
        // A deliberate playlist or video assignment can supplement thin web results with a normal quota-metered search.
        if(count($videos)<2&&in_array($focus,['playlist','videos'],true)&&agent_connection_youtube_quota_ready_v1($pdo)){
            $query=$kind==='artist'?$subject.' official live performances':$subject;
            $day=agent_connection_youtube_reserve_v1($pdo,$owner);
            $found=agent_connection_youtube_video_status_v1(agent_connection_youtube_search_v1($query,6,$http),$http);
            agent_connection_youtube_record_success_v1($pdo,$owner,$day);
            $authorize();
            foreach($found as $video){
                if(is_bool($video['made_for_kids']??null))$videos[$video['video_id']]??=$video;
            }
        }
    }catch(AgentConnectionYoutubeExceptionV1){
        // A playlist is optional. Keep the sourced report when the video provider is unavailable.
        return null;
    }
    if(count($videos)<2)return null;
    return ['query'=>$subject,'suggestion_kind'=>'playlist','initiative_suggestion'=>true,'music_suggestion'=>$kind==='artist',
        'suggestion_reason'=>'Verified YouTube videos for this research.','videos'=>array_slice(array_values($videos),0,6),
        'expires_at'=>time()+7*86400];
}

function vp3_initiative_youtube_playlist_store_v1(PDO $pdo,int $owner,int $runId,array $playlist): void
{
    $stmt=$pdo->prepare('INSERT INTO vp3_initiative_youtube_playlist_v1 (run_id,owner_user_id,expires_at,payload_json) VALUES (?,?,?,?)');
    $stmt->execute([$runId,$owner,$playlist['expires_at'],json_encode($playlist,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)]);
}

function vp3_initiative_youtube_playlist_load_v1(PDO $pdo,int $owner,int $runId): ?array
{
    if(!vp3_initiative_youtube_playlist_ready_v1($pdo)||!agent_connection_youtube_enabled_v1()
        ||!agent_connection_youtube_consent_v1($pdo,$owner))return null;
    $stmt=$pdo->prepare('SELECT expires_at,payload_json FROM vp3_initiative_youtube_playlist_v1 WHERE owner_user_id=? AND run_id=? AND expires_at>?');
    $stmt->execute([$owner,$runId,time()]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $playlist=json_decode((string)$row['payload_json'],true);
    if(!is_array($playlist)||($playlist['initiative_suggestion']??null)!==true||($playlist['suggestion_kind']??null)!=='playlist'
        ||($playlist['expires_at']??null)!==(int)$row['expires_at']||!is_array($playlist['videos']??null)
        ||count($playlist['videos'])<2||count($playlist['videos'])>6)return null;
    return $playlist;
}

function vp3_initiative_youtube_playlist_remove_v1(PDO $pdo,int $owner,int $runId): void
{
    $pdo->prepare('DELETE FROM vp3_initiative_youtube_playlist_v1 WHERE owner_user_id=? AND run_id=?')
        ->execute([$owner,$runId]);
}
