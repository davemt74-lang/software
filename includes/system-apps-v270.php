<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v260.php';

const VP3_SYSTEM_APPS_V270='vp3-media-server-v270-20260930';

function vp3_media_server_status_v270(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_system_apps_remote_v110($userId,'apps.media.status',[],$remote);
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
    $items=[];
    foreach((array)($payload['items']??[]) as $row){
        if(!is_array($row))continue;
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
    if(!preg_match('/\b(?:media server|media library|movies?|videos?|photos?|images?|audio library|music library|recently played|new media)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{$status=vp3_media_server_status_v270($uid,$remote);}
    catch(Throwable $e){return $empty;}

    $searchIntent=(bool)preg_match('/\b(?:find|search|show|list|look for|play)\b/i',$query);
    $type='';
    if(preg_match('/\b(?:movie|movies|video|videos)\b/i',$query))$type='video';
    elseif(preg_match('/\b(?:photo|photos|image|images)\b/i',$query))$type='image';
    elseif(preg_match('/\b(?:audio|music|song|songs)\b/i',$query))$type='audio';

    $search=null;
    if($searchIntent){
        $clean=preg_replace('/\b(?:find|search|show|list|look for|play|my|media server|media library|movies?|videos?|photos?|images?|audio|music|library)\b/i',' ',$query);
        $clean=trim(preg_replace('/\s+/',' ',$clean));
        try{$search=vp3_media_server_search_v270($uid,$clean,$type,20,$remote);}catch(Throwable $ignored){}
    }

    if($search&&$search['count']>0){
        $titles=array_map(static fn($row)=>(string)$row['title'],array_slice($search['items'],0,8));
        $answer='I found '.$search['count'].' matching Media Server item'.($search['count']===1?'':'s').': '.implode(', ',$titles).'.';
    }else{
        $types=(array)$status['types'];
        $answer='Media Server has '.$status['library_count'].' indexed item'.($status['library_count']===1?'':'s')
          .' — '.(int)($types['video']??0).' video, '.(int)($types['audio']??0).' audio, and '.(int)($types['image']??0).' image files.';
    }
    $answer.=' Folder grants, rescans, and remote-access changes remain HomeServer owner actions and require explicit confirmation.';
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.media_server.read',$query,'success',['library_count'=>$status['library_count'],'search_count'=>(int)($search['count']??0)],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Media Server','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:media-server','title'=>'VP3 Media Server']],
      'media_server'=>$status,
      'media_search'=>$search,
    ];
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
