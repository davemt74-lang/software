<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v290.php';

const VP3_SYSTEM_APPS_V300='vp3-music-server-v300-20260930';

function vp3_music_server_status_v300(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.music-server','music.status',[],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    vp3_media_read_counts_v1($result,['tracks','artists','albums','mapped_sources']);
    return [
      'contract'=>'vp3.music-server-cloud.v1',
      'app_key'=>'vp3.music-server',
      'tracks'=>(int)($payload['result']['tracks']??$payload['tracks']??0),
      'artists'=>(int)($payload['result']['artists']??$payload['artists']??0),
      'albums'=>(int)($payload['result']['albums']??$payload['albums']??0),
      'playlists'=>(int)($payload['result']['playlists']??$payload['playlists']??0),
      'favorites'=>(int)($payload['result']['favorites']??$payload['favorites']??0),
      'mapped_sources'=>(int)($payload['result']['mapped_sources']??$payload['mapped_sources']??0),
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ];
}

function vp3_music_server_search_v300(
    int $userId,string $query,int $limit=25,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.music-server','music.search',[
      'query'=>mb_substr(trim($query),0,200),'limit'=>max(1,min(100,$limit))
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    if(!is_array($result['tracks']??null)||!array_is_list($result['tracks']))throw new RuntimeException('Music search result is unavailable.');
    $tracks=[];
    foreach(array_slice($result['tracks'],0,max(1,min(100,$limit))) as $row){
        if(!is_array($row)||!is_string($row['title']??null)||!is_string($row['media_id']??null)||!is_string($row['artist']??null))throw new RuntimeException('Invalid music search track.');
        $tracks[]=[
          'media_id'=>(string)($row['media_id']??''),
          'title'=>(string)($row['title']??''),
          'artist'=>(string)($row['artist']??''),
          'album'=>(string)($row['album']??''),
          'track_no'=>(int)($row['track_no']??0),
        ];
    }
    return [
      'contract'=>'vp3.music-search-cloud.v1',
      'tracks'=>$tracks,
      'count'=>count($tracks),
      'total'=>(int)($result['total']??count($tracks)),
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_music_server_search_terms_v300(string $query): string
{
    $query=trim($query);
    if(preg_match('/["“”]([^"“”]{1,160})["“”]/u',$query,$m))return trim((string)$m[1]);
    $clean=preg_replace(
      '/\b(?:please|can you|could you|show me|find|search|play|queue|listen to|music server|music|songs?|tracks?|artists?|albums?|playlists?|by|from|for|the)\b/i',
      ' ',
      $query
    );
    $clean=preg_replace('/\s+/',' ',trim((string)$clean));
    return mb_substr($clean!==''?$clean:$query,0,200);
}


function vp3_music_server_agent_query_v300(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:music server|local music library|homeserver music)\b/i',$query))return $empty;
    if(!preg_match('/\b(?:find|search|show|list|look for|play|queue|listen|status|how many|indexed|library count)\b/i',$query))return $empty;
    $view=preg_match('/\b(?:find|search|show|list|play|queue|listen)\b/i',$query)?'search':'status';
    return vp3_media_agent_legacy_v1($query,$user,$conversationId,'music.library.read',$view,vp3_music_server_search_terms_v300($query),$remote);
}

function vp3_system_apps_capability_v300(): array
{
    return array_replace(vp3_system_apps_capability_v290(),[
      'music_server_contract'=>'vp3.music-server-cloud.v1',
      'music_server_search'=>true,
      'mapped_media_sources'=>true,
      'music_agent_read_projection'=>true,
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ]);
}
