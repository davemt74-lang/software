<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v290.php';

const VP3_SYSTEM_APPS_V300='vp3-music-server-v300-20260930';

function vp3_music_server_status_v300(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.music-server','music.status',[],false,$remote);
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
    $tracks=[];
    foreach((array)($result['tracks']??[]) as $row){
        if(!is_array($row))continue;
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

function vp3_music_server_agent_query_v300(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:music|song|songs|artist|album|playlist|play queue|music server)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{
        $status=vp3_music_server_status_v300($uid,$remote);
        $search=vp3_music_server_search_v300($uid,$query,12,$remote);
    }catch(Throwable $e){return $empty;}
    $answer='Music Server has '.$status['tracks'].' track'.($status['tracks']===1?'':'s')
      .', '.$status['artists'].' artist'.($status['artists']===1?'':'s')
      .', and '.$status['albums'].' album'.($status['albums']===1?'':'s').'. ';
    if($status['mapped_sources']>0){
        $answer.='It is using '.$status['mapped_sources'].' mapped media source'.($status['mapped_sources']===1?'':'s').'. ';
    }
    if($search['count']>0){
        $first=$search['tracks'][0];
        $answer.='A matching track is '.$first['title'].' by '.$first['artist'].' on '.$first['album'].'.';
    }else{
        $answer.='No matching indexed track was found for that request.';
    }
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.music.read',$query,'success',[
      'tracks'=>$status['tracks'],'matches'=>$search['count']
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Music Server','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:music-server','title'=>'VP3 Music Server']],
      'music_server'=>$status,'music_search'=>$search,
    ];
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
