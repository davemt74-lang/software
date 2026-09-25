<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 3 — federated Knowledge continuity.
 *
 * VP3 Cloud Knowledge remains Cloud-authoritative. HomeServer Knowledge remains
 * HomeServer-authoritative. The unified projection stores only federation
 * identity/provenance metadata for the remote side; it never copies native
 * Knowledge rows between databases.
 */
const VP3_HOMESERVER_KNOWLEDGE_V242='vp3-homeserver-knowledge-v242-20260925';

function homeserver_knowledge_v242_text(mixed $value,int $max=2200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_knowledge_v242_matches(string $query,string $text): bool
{
    $query=trim($query);
    if($query==='')return true;
    if(function_exists('homeserver_shared_v210_matches'))return homeserver_shared_v210_matches($query,$text);
    foreach(preg_split('/\s+/u',mb_strtolower($query))?:[] as $term){
        $term=trim($term);
        if($term!==''&&!str_contains(mb_strtolower($text),$term))return false;
    }
    return true;
}

function homeserver_knowledge_v242_cloud_items(
    int $userId,string $query='',int $limit=100
): array {
    $pdo=db();if(!$pdo||$userId<1||!table_exists('knowledge_items'))return [];
    if(!column_exists('knowledge_items','created_by_user_id')||!column_exists('knowledge_items','knowledge_scope'))return [];
    $limit=max(1,min(250,$limit));
    $s=$pdo->prepare("SELECT id,title,description,content_text,file_type,updated_at
      FROM knowledge_items
      WHERE created_by_user_id=? AND knowledge_scope='personal'
      ORDER BY updated_at DESC,id DESC LIMIT 300");
    $s->execute([$userId]);$out=[];
    foreach($s->fetchAll()?:[] as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $title=homeserver_knowledge_v242_text($row['title']??'Knowledge item',240);
        $content=trim((string)($row['description']??'').' '.(string)($row['content_text']??''));
        if(!homeserver_knowledge_v242_matches($query,$title.' '.$content))continue;
        $key='knowledge:'.$id;
        $envelope=homeserver_federated_v240_envelope(
          'vp3_cloud','knowledge',$key,$title,$content,(string)($row['updated_at']??'')
        );
        homeserver_federated_v240_observe($userId,$envelope,'vp3_cloud');
        $out[]=[
          'id'=>$id,
          'title'=>$title,
          'kind'=>homeserver_knowledge_v242_text($row['file_type']??'personal_note',40),
          'excerpt'=>homeserver_knowledge_v242_text($content,1800),
          'collection_key'=>'cloud_personal',
          'source_type'=>'cloud_personal',
          'source_label'=>'VP3 Cloud',
          'updated_at'=>$row['updated_at']??null,
          'authority_source'=>'vp3_cloud',
          'authority_key'=>$key,
          'canonical_id'=>$envelope['canonical_id'],
          'record_revision'=>$envelope['record_revision'],
          'federation_version'=>'2.4',
          'mirror_only'=>false,
          'read_only'=>false,
          'mutation_route'=>'cloud_native',
          'allowed_mutations'=>['update','delete'],
          'citation'=>null,
        ];
        if(count($out)>=$limit)break;
    }
    return $out;
}

function homeserver_knowledge_v242_homeserver_items(
    int $userId,string $query='',int $limit=100
): array {
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return [];
    $limit=max(1,min(50,$limit));
    try{
        $run=homeserver_execution_v230_execute($userId,'knowledge.search',[
          'query'=>homeserver_knowledge_v242_text($query,240),'limit'=>$limit,
        ]);
        $result=is_array($run['result']??null)?$run['result']:[];
        $rows=is_array($result['items']??null)?$result['items']:[];
        $out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $canonical=strtolower(trim((string)($row['canonical_id']??'')));
            $key=homeserver_knowledge_v242_text($row['authority_key']??'',180);
            if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical)||!str_starts_with($key,'knowledge_item:'))continue;
            $expected=homeserver_federated_v240_canonical_id('homeserver','knowledge',$key);
            if(!hash_equals($expected,$canonical))continue;
            $revision=strtolower(trim((string)($row['record_revision']??'')));
            if(!preg_match('/^[0-9a-f]{64}$/',$revision))continue;
            $readOnly=!empty($row['read_only']);
            $item=[
              'id'=>max(0,(int)($row['id']??0)),
              'title'=>homeserver_knowledge_v242_text($row['title']??'HomeServer Knowledge',240),
              'kind'=>homeserver_knowledge_v242_text($row['kind']??'note',40),
              'excerpt'=>homeserver_knowledge_v242_text($row['snippet']??'',1800),
              'collection_key'=>homeserver_knowledge_v242_text($row['collection_key']??'general',64),
              'source_type'=>homeserver_knowledge_v242_text($row['source_type']??'local_item',40),
              'source_label'=>'HomeServer',
              'updated_at'=>$row['updated_at']??null,
              'authority_source'=>'homeserver',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'federation_version'=>'2.4',
              'mirror_only'=>true,
              'read_only'=>$readOnly,
              'mutation_route'=>$readOnly?'source_managed_read_only':'homeserver_governed',
              'allowed_mutations'=>$readOnly?[]:['update','delete'],
              'citation'=>is_array($row['citation']??null)?$row['citation']:null,
            ];
            homeserver_federated_v240_observe($userId,[
              'authority_source'=>'homeserver','dataset'=>'knowledge','authority_key'=>$key,
              'canonical_id'=>$canonical,'record_revision'=>$revision,
              'title'=>$item['title'],'content'=>$item['excerpt'],'updated_at'=>$item['updated_at'],
            ],'vp3_cloud');
            $out[]=$item;
        }
        return $out;
    }catch(Throwable $e){
        return [];
    }
}

function homeserver_knowledge_v242_unified(
    int $userId,string $query='',int $limit=150
): array {
    $limit=max(1,min(300,$limit));$items=[];$seen=[];$counts=['vp3_cloud'=>0,'homeserver'=>0];
    foreach(homeserver_knowledge_v242_cloud_items($userId,$query,$limit) as $item){
        $canonical=(string)$item['canonical_id'];
        if(isset($seen[$canonical]))continue;
        $seen[$canonical]=true;$items[]=$item;$counts['vp3_cloud']++;
        if(count($items)>=$limit)break;
    }
    if(count($items)<$limit){
        foreach(homeserver_knowledge_v242_homeserver_items($userId,$query,min(50,$limit-count($items))) as $item){
            $canonical=(string)$item['canonical_id'];
            if(isset($seen[$canonical]))continue;
            $seen[$canonical]=true;$items[]=$item;$counts['homeserver']++;
            if(count($items)>=$limit)break;
        }
    }
    return [
      'version'=>'2.4','dataset'=>'knowledge','items'=>$items,'count'=>count($items),'sources'=>$counts,
      'authority_rules'=>[
        'cloud_native'=>'personal_knowledge',
        'homeserver_native'=>'local_knowledge',
        'native_source_remains_authoritative'=>true,
        'source_managed_local_items_read_only'=>true,
        'no_cross_database_id_writes'=>true,
      ],
    ];
}

function homeserver_knowledge_v242_known_homeserver(
    int $userId,string $canonicalId
): ?array {
    $pdo=db();if(!$pdo||$userId<1)return null;
    $canonicalId=strtolower(trim($canonicalId));
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonicalId))return null;
    $s=$pdo->prepare("SELECT authority_source,authority_key,tombstoned
      FROM homeserver_federated_records
      WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud'
      ORDER BY last_seen_at DESC LIMIT 1");
    $s->execute([$userId,$canonicalId]);$row=$s->fetch();
    if(!$row||(string)$row['authority_source']!=='homeserver'||!str_starts_with((string)$row['authority_key'],'knowledge_item:')||!empty($row['tombstoned']))return null;
    $expected=homeserver_federated_v240_canonical_id('homeserver','knowledge',(string)$row['authority_key']);
    return hash_equals($expected,$canonicalId)?$row:null;
}

function homeserver_knowledge_v242_request_homeserver(
    int $userId,string $action,array $payload
): array {
    $action=strtolower(trim($action));
    $tool=match($action){
      'create'=>'knowledge.create','update'=>'knowledge.update','delete'=>'knowledge.delete',
      default=>throw new RuntimeException('Unsupported HomeServer Knowledge action.'),
    };
    if($action==='create'){
        $content=(string)($payload['content']??'');
        if(strlen($content)>50000)throw new RuntimeException('HomeServer governed Knowledge content is limited to 50,000 characters per request.');
    }else{
        $canonical=strtolower(trim((string)($payload['canonical_id']??'')));
        if(!homeserver_knowledge_v242_known_homeserver($userId,$canonical)){
            throw new RuntimeException('That Knowledge item is not a writable HomeServer-native record.');
        }
        if($action==='update'&&isset($payload['content'])&&strlen((string)$payload['content'])>50000){
            throw new RuntimeException('HomeServer governed Knowledge content is limited to 50,000 characters per request.');
        }
    }
    if(!function_exists('homeserver_governed_v233_request'))throw new RuntimeException('HomeServer governed actions are unavailable.');
    return homeserver_governed_v233_request($userId,$tool,$payload);
}
