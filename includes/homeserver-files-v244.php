<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 5 — Files & Document continuity.
 *
 * File-backed My Knowledge records remain VP3 Cloud-authoritative. Tracked
 * HomeServer files remain HomeServer-authoritative. Cloud stores federation
 * identity/provenance only for remote files and never persists HomeServer paths
 * or file bytes.
 */
const VP3_HOMESERVER_FILES_V244='vp3-homeserver-files-v244-20260926';

function homeserver_files_v244_text(mixed $value,int $max=2200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_files_v244_matches(string $query,string $text): bool
{
    $query=trim($query);
    if($query==='')return true;
    if(function_exists('homeserver_shared_v210_matches'))return homeserver_shared_v210_matches($query,$text);
    $hay=mb_strtolower($text);
    foreach(preg_split('/\s+/u',mb_strtolower($query))?:[] as $term){
        $term=trim($term);
        if($term!==''&&!str_contains($hay,$term))return false;
    }
    return true;
}

function homeserver_files_v244_cloud_items(int $userId,string $query='',int $limit=100): array
{
    $pdo=db();
    if(!$pdo||$userId<1||!table_exists('knowledge_items'))return [];
    if(!column_exists('knowledge_items','created_by_user_id')
      ||!column_exists('knowledge_items','knowledge_scope')
      ||!column_exists('knowledge_items','file_path'))return [];

    $limit=max(1,min(250,$limit));
    $sql="SELECT i.id,i.folder_id,i.title,i.description,i.file_name,i.file_type,i.mime_type,i.file_size,i.updated_at";
    if(table_exists('artist_transcript_folders_v177'))$sql.=",f.folder_name";
    else $sql.=",NULL AS folder_name";
    $sql.=" FROM knowledge_items i";
    if(table_exists('artist_transcript_folders_v177'))$sql.=" LEFT JOIN artist_transcript_folders_v177 f ON f.id=i.folder_id AND f.created_by_user_id=i.created_by_user_id";
    $sql.=" WHERE i.created_by_user_id=? AND i.knowledge_scope='personal'
      AND COALESCE(i.file_path,'')<>'' AND COALESCE(i.file_name,'')<>''
      ORDER BY i.updated_at DESC,i.id DESC LIMIT 300";

    $s=$pdo->prepare($sql);
    $s->execute([$userId]);
    $out=[];
    foreach($s->fetchAll()?:[] as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $title=homeserver_files_v244_text($row['title']??$row['file_name']??'Cloud file',240);
        $name=homeserver_files_v244_text($row['file_name']??$title,255);
        $type=strtolower(homeserver_files_v244_text($row['file_type']??'',40));
        $mime=homeserver_files_v244_text($row['mime_type']??'',120);
        $folder=homeserver_files_v244_text($row['folder_name']??'Unfiled',120)?:'Unfiled';
        $description=homeserver_files_v244_text($row['description']??'',900);
        $size=max(0,(int)($row['file_size']??0));
        $summary=trim($description.' · file: '.$name.' · type: '.$type.' · mime: '.$mime.' · size: '.$size.' bytes · folder: '.$folder,' ·');
        if(!homeserver_files_v244_matches($query,$title.' '.$name.' '.$summary))continue;

        $key='knowledge_file:'.$id;
        $envelope=homeserver_federated_v240_envelope(
          'vp3_cloud','files',$key,$title,$summary,(string)($row['updated_at']??'')
        );
        homeserver_federated_v240_observe($userId,$envelope,'vp3_cloud');
        $out[]=[
          'id'=>$id,
          'name'=>$name,
          'title'=>$title,
          'description'=>$description,
          'file_type'=>$type,
          'mime_type'=>$mime,
          'size_bytes'=>$size,
          'folder_id'=>max(0,(int)($row['folder_id']??0)),
          'folder_name'=>$folder,
          'source_label'=>'VP3 Cloud',
          'updated_at'=>$row['updated_at']??null,
          'authority_source'=>'vp3_cloud',
          'authority_key'=>$key,
          'canonical_id'=>$envelope['canonical_id'],
          'record_revision'=>$envelope['record_revision'],
          'federation_version'=>'2.4',
          'mirror_only'=>false,
          'read_only'=>false,
          'mutation_route'=>'cloud_native_knowledge',
          'allowed_mutations'=>['update','delete'],
          'open_url'=>url('/knowledge-file.php?id='.$id),
          'edit_url'=>url('/knowledge.php?edit='.$id.'#knowledge-form'),
        ];
        if(count($out)>=$limit)break;
    }
    return $out;
}

function homeserver_files_v244_homeserver_items(int $userId,string $query='',int $limit=100): array
{
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return [];
    $limit=max(1,min(50,$limit));
    try{
        $run=homeserver_execution_v230_execute($userId,'files.list',[
          'query'=>homeserver_files_v244_text($query,240),
          'limit'=>$limit,
        ]);
        $value=$run['result']??[];
        if(function_exists('homeserver_reads_v231_rows'))$rows=homeserver_reads_v231_rows($value);
        else{
            while(is_array($value)&&isset($value['result'])&&is_array($value['result']))$value=$value['result'];
            if(is_array($value)&&isset($value['items'])&&is_array($value['items']))$rows=array_values(array_filter($value['items'],'is_array'));
            elseif(is_array($value)&&array_is_list($value))$rows=array_values(array_filter($value,'is_array'));
            else $rows=[];
        }
        $out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $canonical=strtolower(trim((string)($row['canonical_id']??'')));
            $key=homeserver_files_v244_text($row['authority_key']??'',180);
            $revision=strtolower(trim((string)($row['record_revision']??'')));
            if((string)($row['authority_source']??'')!=='homeserver')continue;
            if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical)||!str_starts_with($key,'local_file:'))continue;
            if(!preg_match('/^[0-9a-f]{64}$/',$revision))continue;
            $expected=homeserver_federated_v240_canonical_id('homeserver','files',$key);
            if(!hash_equals($expected,$canonical))continue;

            $name=homeserver_files_v244_text($row['name']??'HomeServer file',255);
            $source=homeserver_files_v244_text($row['source_label']??'Local folder',120);
            $collection=homeserver_files_v244_text($row['collection_name']??$row['collection_key']??'General',120);
            $kind=homeserver_files_v244_text($row['kind']??'',80);
            $size=max(0,(int)($row['size_bytes']??0));
            $ref=strtolower(homeserver_files_v244_text($row['ref']??'',96));
            if(!preg_match('/^hsf-[0-9]+-[0-9a-f]{16}$/',$ref))continue;
            $summary='source: '.$source.' · collection: '.$collection.' · kind: '.$kind.' · size: '.$size.' bytes';
            $item=[
              'id'=>0,
              'name'=>$name,
              'title'=>$name,
              'description'=>'',
              'file_type'=>strtolower(pathinfo($name,PATHINFO_EXTENSION)),
              'mime_type'=>'',
              'size_bytes'=>$size,
              'folder_id'=>0,
              'folder_name'=>$collection,
              'source_label'=>'HomeServer · '.$source,
              'updated_at'=>$row['updated_at']??null,
              'authority_source'=>'homeserver',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'federation_version'=>'2.4',
              'mirror_only'=>true,
              'read_only'=>empty($row['editable_text']),
              'mutation_route'=>'homeserver_owner_approval',
              'allowed_mutations'=>array_values(array_filter(
                (array)($row['allowed_mutations']??['delete']),
                static fn($x):bool=>in_array((string)$x,['update','delete'],true)
              )),
              'opaque_ref'=>$ref,
              'editable_text'=>!empty($row['editable_text']),
              'open_url'=>null,
              'edit_url'=>null,
            ];
            homeserver_federated_v240_observe($userId,[
              'authority_source'=>'homeserver',
              'dataset'=>'files',
              'authority_key'=>$key,
              'canonical_id'=>$canonical,
              'record_revision'=>$revision,
              'title'=>$name,
              'content'=>$summary,
              'updated_at'=>$item['updated_at'],
            ],'vp3_cloud');
            $out[]=$item;
            if(count($out)>=$limit)break;
        }
        return $out;
    }catch(Throwable $e){
        return [];
    }
}

function homeserver_files_v244_unified(int $userId,string $query='',int $limit=150): array
{
    $limit=max(1,min(300,$limit));
    $items=[];$seen=[];$counts=['vp3_cloud'=>0,'homeserver'=>0];

    foreach(homeserver_files_v244_cloud_items($userId,$query,$limit) as $item){
        $canonical=(string)$item['canonical_id'];
        if(isset($seen[$canonical]))continue;
        $seen[$canonical]=true;$items[]=$item;$counts['vp3_cloud']++;
        if(count($items)>=$limit)break;
    }
    if(count($items)<$limit){
        foreach(homeserver_files_v244_homeserver_items($userId,$query,min(50,$limit-count($items))) as $item){
            $canonical=(string)$item['canonical_id'];
            if(isset($seen[$canonical]))continue;
            $seen[$canonical]=true;$items[]=$item;$counts['homeserver']++;
            if(count($items)>=$limit)break;
        }
    }
    return [
      'version'=>'2.4',
      'dataset'=>'files',
      'items'=>$items,
      'count'=>count($items),
      'sources'=>$counts,
      'authority_rules'=>[
        'cloud_native'=>'personal_knowledge_file',
        'homeserver_native'=>'tracked_local_file',
        'native_source_remains_authoritative'=>true,
        'remote_records_are_mirrors'=>true,
        'homeserver_mutations_require_local_owner_approval'=>true,
        'absolute_homeserver_paths_persisted'=>false,
        'homeserver_file_bytes_persisted'=>false,
        'no_cross_database_id_writes'=>true,
      ],
    ];
}

function homeserver_files_v244_known_homeserver(int $userId,string $canonicalId): ?array
{
    $pdo=db();if(!$pdo||$userId<1)return null;
    $canonicalId=strtolower(trim($canonicalId));
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonicalId))return null;
    $s=$pdo->prepare("SELECT authority_source,authority_key,record_hash,tombstoned
      FROM homeserver_federated_records
      WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud'
      ORDER BY last_seen_at DESC LIMIT 1");
    $s->execute([$userId,$canonicalId]);$row=$s->fetch();
    if(!$row||(string)$row['authority_source']!=='homeserver'
      ||!str_starts_with((string)$row['authority_key'],'local_file:')
      ||!empty($row['tombstoned']))return null;
    $expected=homeserver_federated_v240_canonical_id('homeserver','files',(string)$row['authority_key']);
    return hash_equals($expected,$canonicalId)?$row:null;
}

function homeserver_files_v244_request_homeserver(int $userId,string $action,array $payload): array
{
    $action=strtolower(trim($action));
    if(!in_array($action,['update','delete'],true))throw new RuntimeException('Unsupported HomeServer file action.');
    $canonical=strtolower(trim((string)($payload['canonical_id']??'')));
    $known=homeserver_files_v244_known_homeserver($userId,$canonical);
    if(!$known)throw new RuntimeException('That file is not a writable HomeServer-native record.');

    $mutation=trim((string)($payload['mutation_id']??''));
    $revision=strtolower(trim((string)($payload['expected_revision']??'')));
    if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$mutation))throw new RuntimeException('A valid mutation_id is required.');
    if(!preg_match('/^[0-9a-f]{64}$/',$revision))throw new RuntimeException('A valid expected_revision is required.');
    if($action==='update'){
        $content=$payload['content']??null;
        if(!is_string($content)||trim($content)==='')throw new RuntimeException('File update content is required.');
        if(strlen($content)>50000)throw new RuntimeException('HomeServer governed file content is limited to 50,000 characters per request.');
    }
    $arguments=[
      'canonical_id'=>$canonical,
      'mutation_id'=>$mutation,
      'expected_revision'=>$revision,
    ];
    if($action==='update')$arguments['content']=(string)$payload['content'];

    if(!function_exists('homeserver_governed_v233_request'))throw new RuntimeException('HomeServer governed actions are unavailable.');
    return homeserver_governed_v233_request($userId,'files.'.$action,$arguments);
}
