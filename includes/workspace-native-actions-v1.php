<?php
declare(strict_types=1);

/** A small set of native editors, never arbitrary SQL or execution queues. */
function workspace_native_fields_v1(string $table): array
{
    return match($table){
        'crm_contacts'=>['display_name'=>120,'organization'=>190,'email'=>190,'phone'=>80,'relationship'=>80],
        'knowledge_items'=>['title'=>190,'description'=>10000,'content_text'=>50000],
        'user_calendar_events'=>['title'=>190,'description'=>10000,'location'=>500,'date'=>10,'start_time'=>5,'end_date'=>10,'end_time'=>5,'timezone'=>80,'all_day'=>0],
        default=>[],
    };
}

function workspace_native_revision_v1(array $row): string
{
    $row=workspace_sync_safe_v1($row);ksort($row);
    return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function workspace_native_schema_v1(PDO $pdo): void
{
    $text=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'TEXT':'LONGTEXT';
    $tail=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_workspace_mutations_v1 (
        user_id BIGINT NOT NULL,mutation_id VARCHAR(128) NOT NULL,request_hash CHAR(64) NOT NULL,
        record_key VARCHAR(240) NOT NULL,result_json $text NOT NULL,created_at VARCHAR(40) NOT NULL,
        PRIMARY KEY(user_id,mutation_id))$tail");
}

function workspace_native_owned_row_v1(PDO $pdo,int $uid,string $dataset,string $key): array
{
    if(!preg_match('/^([a-zA-Z0-9_]+):([1-9][0-9]{0,18})$/D',$key,$match))throw new InvalidArgumentException('Invalid workspace record key.');
    [, $table,$id]=$match;
    $descriptor=null;
    foreach(workspace_sync_registry_v1()[$dataset]??[] as $candidate)if($candidate[0]===$table)$descriptor=$candidate;
    if(!$descriptor||!workspace_sync_descriptor_ready_v1($descriptor)||!column_exists($table,'id'))throw new RuntimeException('Source record unavailable.',409);
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $s=$pdo->prepare("SELECT r.* FROM `$table` r WHERE r.id=? AND ".workspace_sync_predicate_v1($descriptor).$lock);
    $s->execute([$id,$uid]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Source record no longer available to this account.',409);
    return [$table,$row];
}

function workspace_native_authorize_v1(PDO $pdo,int $uid,string $table,array $row): array
{
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||!has_permission('account.access',$user))throw new RuntimeException('Workspace editing is unavailable for this account.',403);
    if($table==='knowledge_items'&&(($row['knowledge_scope']??'')!=='personal'||!personal_capability_has_v242('personal_knowledge.manage',$user)))throw new RuntimeException('Only permitted personal knowledge can be edited here.',403);
    if(in_array($table,['crm_contacts','user_calendar_events'],true)&&($row['status']??'active')!=='active')throw new RuntimeException('This record is no longer editable.',409);
    return $user;
}

function workspace_native_edit_v1(PDO $pdo,int $uid,string $dataset,array $body): array
{
    $key=$body['key']??'';
    if(!is_string($key))throw new InvalidArgumentException('Invalid workspace record key.');
    [$table,$row]=workspace_native_owned_row_v1($pdo,$uid,$dataset,$key);
    $limits=workspace_native_fields_v1($table);
    if(!$limits)throw new InvalidArgumentException('This record uses its original source editor.');
    $user=workspace_native_authorize_v1($pdo,$uid,$table,$row);
    if(($body['action']??'')==='edit')return ['record'=>['table'=>$table,'source_id'=>(string)$row['id'],'data'=>workspace_sync_safe_v1($row),'record_revision'=>workspace_native_revision_v1($row)],'editable_fields'=>$limits];
    $mutation=$body['mutation_id']??null;$expected=$body['expected_revision']??null;$fields=$body['fields']??null;
    if(!is_string($mutation)||!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D',$mutation)||!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_array($fields)||!$fields||array_is_list($fields))throw new InvalidArgumentException('A change ID, source revision and changed fields are required.');
    if(array_diff(array_keys($fields),array_keys($limits)))throw new InvalidArgumentException('Unsupported workspace edit field.');
    foreach($fields as $field=>$value){
        if($field==='all_day'){if(!is_bool($value))throw new InvalidArgumentException('All-day must be true or false.');continue;}
        if(!is_string($value)||mb_strlen($value)>$limits[$field])throw new InvalidArgumentException('Workspace field value is invalid or too long.');
    }
    if(strlen(json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))>65536)throw new InvalidArgumentException('Keep each workspace change under 64 KB.');
    ksort($fields);
    $hash=hash('sha256',json_encode([$dataset,$key,$expected,$fields],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    // Ownership and permissions are checked even for a replayed acknowledgement.
    $q=$pdo->prepare('SELECT request_hash,result_json FROM homeserver_workspace_mutations_v1 WHERE user_id=? AND mutation_id=?');$q->execute([$uid,$mutation]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
    if($receipt){
        if(!hash_equals($receipt['request_hash'],$hash))throw new RuntimeException('This change ID has already been used for different fields.',409);
        return json_decode($receipt['result_json'],true,512,JSON_THROW_ON_ERROR)+['idempotent_replay'=>true];
    }
    if(!hash_equals(workspace_native_revision_v1($row),$expected))throw new RuntimeException('This Cloud record changed. Reload it and review your edits before saving again.',409);
    if($table==='crm_contacts'){
        $row=workspace_native_contact_update_v1($pdo,$uid,(int)$row['id'],$fields);
    }elseif($table==='knowledge_items'){
        $row=workspace_native_knowledge_update_v1($pdo,$user,$row,$fields);
    }else{
        $input=array_merge(user_calendar_event_local_parts_v1300($row),(array)array_intersect_key($row,array_flip(['title','description','location'])),$fields);
        $input['expected_revision']=section12_revision($row);
        // Use the existing calendar/meeting lifecycle. Linked meetings remain source managed.
        if(function_exists('video_meeting_for_calendar_event_v1800')&&video_meeting_schema_ready_v1800($pdo)&&video_meeting_for_calendar_event_v1800($pdo,(int)$row['id']))throw new RuntimeException('Edit this linked video meeting in its Cloud editor.',422);
        try{$row=user_calendar_update_event_v1300($pdo,$user,(int)$row['id'],$input);}
        catch(RuntimeException $e){if($e->getCode()===0)throw new InvalidArgumentException($e->getMessage(),0,$e);throw $e;}
    }
    $result=['mutation_id'=>$mutation,'record_key'=>$key,'applied'=>true,'authority_source'=>'cloud','record_revision'=>workspace_native_revision_v1($row),'applied_at'=>gmdate(DATE_ATOM)];
    $pdo->prepare('INSERT INTO homeserver_workspace_mutations_v1(user_id,mutation_id,request_hash,record_key,result_json,created_at) VALUES(?,?,?,?,?,?)')->execute([$uid,$mutation,$hash,$key,json_encode($result,JSON_THROW_ON_ERROR),gmdate(DATE_ATOM)]);
    return $result;
}

/** Shared with the native CRM editor; preserves channel normalization. */
function workspace_native_contact_update_v1(PDO $pdo,int $uid,int $id,array $fields): array
{
    $q=$pdo->prepare("SELECT * FROM crm_contacts WHERE id=? AND owner_user_id=? AND status<>'archived'");$q->execute([$id,$uid]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('VP3 CRM contact not found.',409);
    $name=trim((string)($fields['display_name']??$row['name']));$company=trim((string)($fields['organization']??$row['company']));$phone=trim((string)($fields['phone']??$row['phone']));$email=strtolower(trim((string)($fields['email']??$row['email'])));$relationship=trim((string)($fields['relationship']??$row['lifecycle_stage']));
    if($name===''||mb_strlen($name)>120||mb_strlen($company)>190||mb_strlen($phone)>80||mb_strlen($email)>190||mb_strlen($relationship)>80||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Contact name, email or details are invalid.');
    $pdo->prepare("UPDATE crm_contacts SET name=?,company=?,phone=?,email=?,email_normalized=?,lifecycle_stage=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND owner_user_id=? AND status<>'archived'")->execute([$name,$company,$phone,$email,$email,$relationship,$id,$uid]);
    if(function_exists('crm_v180_sync_primary_channels'))crm_v180_sync_primary_channels($pdo,$id,$email,$phone);
    $q->execute([$id,$uid]);return $q->fetch(PDO::FETCH_ASSOC);
}

function workspace_native_knowledge_update_v1(PDO $pdo,array $user,array $row,array $fields): array
{
    $title=trim((string)($fields['title']??$row['title']));$description=trim((string)($fields['description']??$row['description']));$content=(string)($fields['content_text']??$row['content_text']);
    if($title===''||mb_strlen($title)>190||trim($content)==='')throw new InvalidArgumentException('Knowledge requires a title and text.');
    $pdo->prepare("UPDATE knowledge_items SET title=?,description=?,content_text=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND created_by_user_id=? AND knowledge_scope='personal'")->execute([$title,$description,$content,$row['id'],$user['id']]);
    if(array_key_exists('content_text',$fields))reindex_knowledge_item((int)$row['id'],$content);
    if(function_exists('shared_knowledge_index_sync_item_v236'))shared_knowledge_index_sync_item_v236($pdo,(int)$row['id']);
    $q=$pdo->prepare('SELECT * FROM knowledge_items WHERE id=? AND created_by_user_id=?');$q->execute([$row['id'],$user['id']]);return $q->fetch(PDO::FETCH_ASSOC);
}
