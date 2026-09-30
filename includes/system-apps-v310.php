<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v300.php';

const VP3_SYSTEM_APPS_V310='vp3-photo-library-v310-20260930';

function vp3_photo_library_status_v310(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.photo-library','photos.status',[],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    return [
      'contract'=>'vp3.photo-library-cloud.v1',
      'app_key'=>'vp3.photo-library',
      'photos'=>(int)($result['photos']??0),
      'albums'=>(int)($result['albums']??0),
      'favorites'=>(int)($result['favorites']??0),
      'tags'=>(int)($result['tags']??0),
      'people'=>(int)($result['people']??0),
      'mapped_sources'=>(int)($result['mapped_sources']??0),
      'face_recognition_enabled'=>!empty($result['face_recognition_enabled']),
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ];
}

function vp3_photo_library_search_v310(
    int $userId,string $query,int $limit=24,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $payload=vp3_app_control_invoke_v290($userId,'vp3.photo-library','photos.search',[
      'query'=>mb_substr(trim($query),0,200),
      'limit'=>max(1,min(100,$limit)),
    ],false,$remote);
    $result=is_array($payload['result']??null)?$payload['result']:$payload;
    $photos=[];
    foreach((array)($result['photos']??[]) as $row){
        if(!is_array($row))continue;
        $photos[]=[
          'media_id'=>(string)($row['media_id']??''),
          'title'=>(string)($row['title']??''),
          'folder_album'=>(string)($row['folder_album']??''),
          'favorite'=>!empty($row['favorite']),
          'tags'=>array_values(array_filter((array)($row['tags']??[]),'is_string')),
        ];
    }
    return [
      'contract'=>'vp3.photo-search-cloud.v1',
      'photos'=>$photos,
      'count'=>count($photos),
      'total'=>(int)($result['total']??count($photos)),
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_photo_library_search_terms_v310(string $query): string
{
    $query=trim($query);
    if(preg_match('/["“”]([^"“”]{1,160})["“”]/u',$query,$m))return trim((string)$m[1]);
    $clean=preg_replace(
      '/\b(?:please|can you|could you|show me|find|search|open|photos?|pictures?|images?|photo library|album|albums|folder|folders|from|for|the)\b/i',
      ' ',
      $query
    );
    $clean=preg_replace('/\s+/',' ',trim((string)$clean));
    return mb_substr($clean!==''?$clean:$query,0,200);
}

function vp3_photo_library_agent_query_v310(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    if(!preg_match('/\b(?:photo library|photos?|pictures?|image library|photo album|photo albums)\b/i',$query))return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{
        $status=vp3_photo_library_status_v310($uid,$remote);
        $terms=vp3_photo_library_search_terms_v310($query);
        $search=vp3_photo_library_search_v310($uid,$terms,12,$remote);
    }catch(Throwable $e){return $empty;}
    $answer='Photo Library has '.$status['photos'].' photo'.($status['photos']===1?'':'s')
      .', '.$status['albums'].' album'.($status['albums']===1?'':'s')
      .', and '.$status['favorites'].' favorite'.($status['favorites']===1?'':'s').'. ';
    if($status['mapped_sources']>0){
        $answer.='It is using '.$status['mapped_sources'].' mapped media source'.($status['mapped_sources']===1?'':'s').'. ';
    }
    if($search['count']>0){
        $first=$search['photos'][0];
        $answer.='A matching photo is '.$first['title'];
        if($first['folder_album']!=='')$answer.=' in '.$first['folder_album'];
        $answer.='.';
    }else{
        $answer.='No matching indexed photo was found for that request.';
    }
    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.photos.read',$query,'success',[
      'photos'=>$status['photos'],'matches'=>$search['count']
    ],$conversationId);
    return [
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Photo Library','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:photo-library','title'=>'VP3 Photo Library']],
      'photo_library'=>$status,'photo_search'=>$search,
    ];
}

function vp3_system_apps_capability_v310(): array
{
    return array_replace(vp3_system_apps_capability_v300(),[
      'photo_library_contract'=>'vp3.photo-library-cloud.v1',
      'photo_library_search'=>true,
      'photo_library_agent_read_projection'=>true,
      'mapped_media_sources'=>true,
      'face_recognition_enabled'=>false,
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
    ]);
}
