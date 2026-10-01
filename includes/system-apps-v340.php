<?php
declare(strict_types=1);

require_once __DIR__.'/system-apps-v330.php';

const VP3_SYSTEM_APPS_V340='vp3-media-library-v340-20260930';

function vp3_media_library_result_v340(array $payload): array
{
    return is_array($payload['result']??null)?$payload['result']:$payload;
}

function vp3_media_library_status_v340(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.status',[],false,$remote
    ));
    return [
      'contract'=>'vp3.media-library-cloud.v1',
      'metadata_items'=>(int)($result['metadata_items']??0),
      'tags'=>(int)($result['tags']??0),
      'relations'=>(int)($result['relations']??0),
      'history_entries'=>(int)($result['history_entries']??0),
      'collections'=>(int)($result['collections']??0),
      'smart_collections'=>(int)($result['smart_collections']??0),
      'duplicate_groups'=>(int)($result['duplicate_groups']??0),
      'exact_duplicate_groups'=>(int)($result['exact_duplicate_groups']??0),
      'cleanup_needs_review'=>(int)($result['cleanup_needs_review']??0),
      'artwork_assignments'=>(int)($result['artwork_assignments']??0),
      'artwork_ready'=>(int)($result['artwork_ready']??0),
      'artwork_attention'=>(int)($result['artwork_attention']??0),
      'homeserver_execution_authority'=>true,
      'cloud_execution_authority'=>false,
      'filesystem_paths_exposed'=>false,
    ];
}

function vp3_media_library_brain_context_v340(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.brain-context',['limit'=>8],false,$remote
    ));
    return [
      'contract'=>'vp3.media-library.brain-cloud.v1',
      'summary'=>is_array($result['summary']??null)?$result['summary']:[],
      'attention'=>array_values(array_filter((array)($result['attention']??[]),'is_array')),
      'recent'=>array_values(array_filter((array)($result['recent']??[]),'is_array')),
      'recent_changes'=>array_values(array_filter((array)($result['recent_changes']??[]),'is_array')),
      'source_files_modified'=>false,
      'filesystem_paths_exposed'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_media_library_search_v340(
    int $userId,string $query='',string $mediaType='',string $tag='',int $limit=12,?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('Account is required.');
    $mediaType=mb_strtolower(trim($mediaType));
    if(!in_array($mediaType,['','video','audio','image'],true))$mediaType='';
    $payload=vp3_app_control_invoke_v290($userId,'vp3.media-library','library.search',[
      'query'=>mb_substr(trim($query),0,200),
      'media_type'=>$mediaType,
      'tag'=>mb_substr(mb_strtolower(trim($tag)),0,80),
      'limit'=>max(1,min(50,$limit)),
    ],false,$remote);
    $result=vp3_media_library_result_v340($payload);
    $items=[];
    foreach((array)($result['items']??[]) as $row){
        if(!is_array($row))continue;
        $canonical=is_array($row['canonical']??null)?$row['canonical']:[];
        $meta=is_array($row['metadata']??null)?$row['metadata']:[];
        $items[]=[
          'media_id'=>(string)($row['media_id']??''),
          'media_type'=>(string)($canonical['media_type']??''),
          'title'=>(string)(($meta['title']??'')!==''?$meta['title']:($canonical['title']??'')),
          'rating'=>isset($meta['rating'])?(int)$meta['rating']:null,
          'favorite'=>!empty($meta['favorite']),
          'tags'=>array_values(array_filter((array)($meta['tags']??[]),'is_string')),
        ];
    }
    return [
      'contract'=>'vp3.media-library-search-cloud.v1',
      'items'=>$items,'count'=>count($items),
      'homeserver_execution_authority'=>true,
      'filesystem_paths_exposed'=>false,
    ];
}

function vp3_media_library_needs_metadata_v340(int $userId,int $limit=12,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.needs-metadata',['limit'=>max(1,min(50,$limit))],false,$remote
    ));
    $items=[];
    foreach((array)($result['items']??[]) as $row){
        if(!is_array($row))continue;
        $items[]=[
          'media_id'=>(string)($row['media_id']??''),
          'media_type'=>(string)($row['media_type']??''),
          'title'=>(string)($row['title']??''),
          'missing'=>array_values(array_filter((array)($row['missing']??[]),'is_string')),
        ];
    }
    return [
      'contract'=>'vp3.media-library-metadata-debt-cloud.v1',
      'items'=>$items,'count'=>count($items),
      'library_total'=>(int)($result['library_total']??0),
      'filesystem_paths_exposed'=>false,
    ];
}

function vp3_media_library_recent_changes_v340(int $userId,int $limit=12,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.recent-changes',['limit'=>max(1,min(50,$limit))],false,$remote
    ));
    $changes=[];
    foreach((array)($result['changes']??[]) as $row){
        if(!is_array($row))continue;
        $changes[]=[
          'history_id'=>(string)($row['history_id']??''),
          'media_id'=>(string)($row['media_id']??''),
          'title'=>(string)($row['title']??''),
          'media_type'=>(string)($row['media_type']??''),
          'source'=>(string)($row['source']??''),
          'reason'=>(string)($row['reason']??''),
          'created_at'=>(string)($row['created_at']??''),
        ];
    }
    return [
      'contract'=>'vp3.media-library-changes-cloud.v1',
      'changes'=>$changes,'count'=>count($changes),
      'filesystem_paths_exposed'=>false,
    ];
}

function vp3_media_library_collections_v340(int $userId,int $limit=20,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.collections',['limit'=>max(1,min(50,$limit))],false,$remote
    ));
    $collections=[];
    foreach((array)($result['collections']??[]) as $row){
        if(!is_array($row))continue;
        $collections[]=[
          'collection_id'=>(string)($row['collection_id']??''),
          'name'=>(string)($row['name']??''),
          'collection_type'=>(string)($row['collection_type']??''),
          'count'=>(int)($row['count']??0),
        ];
    }
    return ['contract'=>'vp3.media-library-collections-cloud.v1','collections'=>$collections,'count'=>count($collections)];
}

function vp3_media_library_cleanup_v340(int $userId,?callable $remote=null): array
{
    if($userId<1)throw new RuntimeException('Account is required.');
    $result=vp3_media_library_result_v340(vp3_app_control_invoke_v290(
      $userId,'vp3.media-library','library.cleanup.status',[],false,$remote
    ));
    return [
      'contract'=>'vp3.media-library-cleanup-cloud.v1',
      'duplicate_groups'=>(int)($result['duplicate_groups']??0),
      'exact_groups'=>(int)($result['exact_groups']??0),
      'candidate_groups'=>(int)($result['candidate_groups']??0),
      'needs_review'=>(int)($result['needs_review']??0),
      'metadata_conflict_groups'=>(int)($result['metadata_conflict_groups']??0),
      'scan_required'=>!empty($result['scan_required']),
      'automatic_source_deletion'=>false,
      'homeserver_execution_authority'=>true,
    ];
}

function vp3_media_library_query_terms_v340(string $query): string
{
    $query=trim($query);
    if(preg_match('/["“”]([^"“”]{1,160})["“”]/u',$query,$m))return trim((string)$m[1]);
    $clean=preg_replace(
      '/\b(?:please|can you|could you|show me|find|search|list|media library|metadata|media|items?|files?|tagged|with|for|the)\b/i',
      ' ',$query
    );
    return mb_substr(preg_replace('/\s+/',' ',trim((string)$clean))?:$query,0,200);
}

function vp3_media_library_agent_query_v340(
    string $query,array $user,int $conversationId=0,?callable $remote=null
): array {
    $empty=['handled'=>false,'answer'=>'','stem_media'=>[],'media'=>[],'actions'=>[],'sources'=>[]];
    $intent=(bool)preg_match(
      '/\b(?:media library|media metadata|metadata debt|missing metadata|needs metadata|media collection|smart collection|duplicate media|media duplicates|cleanup center|metadata conflict|album art|media poster|collection cover|contact sheet|recent media changes|changed media|tag this media|tag these media)\b/i',
      $query
    );
    if(!$intent)return $empty;
    $uid=(int)($user['id']??0);if($uid<1)return $empty;
    try{
        $status=vp3_media_library_status_v340($uid,$remote);
        $brain=vp3_media_library_brain_context_v340($uid,$remote);
    }catch(Throwable $e){return $empty;}

    $answer='Media Library has '.$status['metadata_items'].' enriched item'.($status['metadata_items']===1?'':'s')
      .', '.$status['collections'].' collection'.($status['collections']===1?'':'s')
      .', and '.$status['duplicate_groups'].' duplicate/review group'.($status['duplicate_groups']===1?'':'s').'. ';
    $extra=[];

    try{
        if(preg_match('/\b(?:missing metadata|needs metadata|metadata debt)\b/i',$query)){
            $debt=vp3_media_library_needs_metadata_v340($uid,12,$remote);
            $extra['metadata_debt']=$debt;
            $answer.=$debt['count'].' item'.($debt['count']===1?' needs':'s need').' metadata in the current bounded view. ';
            if($debt['count']>0)$answer.='One example is '.$debt['items'][0]['title'].'. ';
        }elseif(preg_match('/\b(?:recent media changes|changed media|recent metadata|what changed)\b/i',$query)){
            $changes=vp3_media_library_recent_changes_v340($uid,12,$remote);
            $extra['recent_changes']=$changes;
            $answer.=$changes['count'].' recent metadata change'.($changes['count']===1?' is':'s are').' available. ';
            if($changes['count']>0)$answer.='Most recent: '.$changes['changes'][0]['title'].'. ';
        }elseif(preg_match('/\b(?:duplicate|cleanup|metadata conflict)\b/i',$query)){
            $cleanup=vp3_media_library_cleanup_v340($uid,$remote);
            $extra['cleanup']=$cleanup;
            $answer.=$cleanup['needs_review'].' duplicate group'.($cleanup['needs_review']===1?' needs':'s need').' review';
            if($cleanup['metadata_conflict_groups']>0)$answer.=', including '.$cleanup['metadata_conflict_groups'].' with metadata conflicts';
            $answer.='. ';
        }elseif(preg_match('/\b(?:collection|smart collection)\b/i',$query)){
            $collections=vp3_media_library_collections_v340($uid,20,$remote);
            $extra['collections']=$collections;
            if($collections['count']>0)$answer.='A collection is '.$collections['collections'][0]['name'].' with '.$collections['collections'][0]['count'].' item'.($collections['collections'][0]['count']===1?'':'s').'. ';
        }else{
            $terms=vp3_media_library_query_terms_v340($query);
            $search=vp3_media_library_search_v340($uid,$terms,'','',12,$remote);
            $extra['search']=$search;
            if($search['count']>0)$answer.='A matching item is '.$search['items'][0]['title'].'. ';
        }
    }catch(Throwable $e){}

    if($status['cleanup_needs_review']>0)$answer.=$status['cleanup_needs_review'].' cleanup group'.($status['cleanup_needs_review']===1?' needs':'s need').' attention. ';
    if($status['artwork_attention']>0)$answer.=$status['artwork_attention'].' artwork assignment'.($status['artwork_attention']===1?' needs':'s need').' attention. ';

    $writeIntent=(bool)preg_match('/\b(?:tag|rename|rate|favorite|group|create collection|add to collection|remove from collection|undo|scan duplicates|review duplicate|generate|make|create|delete|remove).*(?:metadata|media|collection|duplicate|poster|cover|artwork|contact sheet)|\b(?:generate|make|create)\s+(?:a\s+)?(?:poster|cover|contact sheet)\b/i',$query);
    if($writeIntent){
        $answer.=' Changes use the governed HomeServer Media Library action path with confirmation where required; Cloud does not mutate media metadata or derivatives.';
    }

    if(function_exists('agent_tool_log'))agent_tool_log($user,'homeserver.media_library.read',$query,'success',[
      'metadata_items'=>$status['metadata_items'],'collections'=>$status['collections'],
      'cleanup_needs_review'=>$status['cleanup_needs_review'],'artwork_attention'=>$status['artwork_attention']
    ],$conversationId);

    return array_merge([
      'handled'=>true,'answer'=>$answer,'stem_media'=>[],'media'=>[],
      'actions'=>[['type'=>'open_url','label'=>'Open Media Library','url'=>url('/apps.php')]],
      'sources'=>[['source'=>'homeserver:media-library','title'=>'VP3 Media Library']],
      'media_library'=>$status,
      'media_library_brain_context'=>$brain,
      'governed_write_path'=>'vp3.app.agent-control.v3',
      'cloud_mutation_authority'=>false,
    ],$extra);
}

function vp3_system_apps_capability_v340(): array
{
    return array_replace(vp3_system_apps_capability_v330(),[
      'media_library_contract'=>'vp3.media-library-cloud.v1',
      'media_library_status'=>true,
      'media_library_search'=>true,
      'media_library_metadata_debt'=>true,
      'media_library_recent_changes'=>true,
      'media_library_collections'=>true,
      'media_library_cleanup_snapshot'=>true,
      'media_library_agent_chat'=>true,
      'media_library_agent_brain_context'=>true,
      'media_library_governed_writes'=>true,
      'media_library_cloud_mutation'=>false,
      'media_library_homeserver_execution'=>true,
      'media_library_filesystem_paths_exposed'=>false,
    ]);
}
