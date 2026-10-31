<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v260.php';
require_once __DIR__.'/agent-media-read-v1.php';

const VP3_SYSTEM_APPS_V270='vp3-media-server-v270-20260930';

function vp3_media_server_status_v270(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.media.status',[],$remote);
    if(isset($payload['app_key'])&&$payload['app_key']!=='vp3.media-server')throw new RuntimeException('Media Server identity mismatch.');
    vp3_media_read_counts_v1($payload,['library_count']);
    if(!is_array($payload['types']??null))throw new RuntimeException('Media types are unavailable.');
    vp3_media_read_counts_v1($payload['types'],['video','audio','image']);
    return [
      'contract'=>'vp3.media-server-cloud.v1',
      'app_key'=>(string)($payload['app_key']??'vp3.media-server'),
      'installed_version'=>$payload['installed_version']??null,
      'lifecycle_state'=>(string)($payload['lifecycle_state']??''),
      'library_count'=>(int)($payload['library_count']??0),
      'roots'=>(int)($payload['roots']??0),
      'types'=>is_array($payload['types']??null)?$payload['types']:['video'=>0,'audio'=>0,'image'=>0],
      'recently_played'=>is_array($payload['recently_played']??null)?array_slice($payload['recently_played'],0,12):[],
      'remote'=>is_array($payload['remote']??null)?$payload['remote']:[],
      'transcoding'=>false,
      'absolute_paths_exposed'=>false,
      'source_media_exposed'=>false,
    ];
}

function vp3_media_server_search_v270(int $userId,string $query='',string $mediaType='',int $limit=20,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.media.search',[
      'query'=>mb_substr(trim($query),0,200),
      'media_type'=>mb_substr(strtolower(trim($mediaType)),0,20),
      'limit'=>max(1,min(50,$limit)),
    ],$remote);
    if(!is_array($payload['items']??null)||!array_is_list($payload['items']))throw new RuntimeException('Media search result is unavailable.');
    $items=[];
    foreach(array_slice($payload['items'],0,max(1,min(50,$limit))) as $row){
        if(!is_array($row)||!is_string($row['title']??null)||!is_string($row['media_id']??null)||!in_array($row['media_type']??null,['video','audio','image'],true))throw new RuntimeException('Invalid media search item.');
        $items[]=[
          'media_id'=>(string)($row['media_id']??''),
          'title'=>(string)($row['title']??''),
          'media_type'=>(string)($row['media_type']??''),
          'mime_type'=>(string)($row['mime_type']??''),
          'size_bytes'=>(int)($row['size_bytes']??0),
          'absolute_path_exposed'=>false,
        ];
    }
    return [
      'contract'=>'vp3.media-server-search-cloud.v1',
      'query'=>(string)($payload['query']??''),
      'media_type'=>(string)($payload['media_type']??''),
      'items'=>$items,
      'count'=>count($items),
      'source_media_exposed'=>false,
    ];
}

function vp3_media_server_agent_query_v270(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:media server|local media library|homeserver media)\b/i',$query))return $empty;
    if(!preg_match('/\b(?:find|search|show|list|look for|play|queue|listen|status|how many|indexed|library count)\b/i',$query))return $empty;
    $view=preg_match('/\b(?:find|search|show|list|look for|play)\b/i',$query)?'search':'status';
    $type=preg_match('/\b(?:movies?|videos?)\b/i',$query)?'video':(preg_match('/\b(?:photos?|images?)\b/i',$query)?'image':(preg_match('/\b(?:audio|music)\b/i',$query)?'audio':''));
    $terms=preg_replace('/\b(?:find|search|show|list|look for|play|my|media server|local media library|homeserver media|movies?|videos?|photos?|images?|audio|music|library)\b/i',' ',$query);
    return vp3_media_agent_legacy_v1($query,$user,$conversationId,'media.library.read',$view,trim(preg_replace('/\s+/',' ',$terms)),$remote,$type);
}

function vp3_system_apps_capability_v270(): array
{
    return array_replace(vp3_system_apps_capability_v260(),[
      'media_server_contract'=>'vp3.media-server-cloud.v1',
      'media_server_status'=>true,
      'media_server_search'=>true,
      'media_server_write_actions'=>'homeserver_confirmation_only',
      'media_server_source_paths_exposed'=>false,
      'media_server_source_bytes_exposed'=>false,
    ]);
}
