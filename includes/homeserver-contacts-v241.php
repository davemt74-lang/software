<?php
declare(strict_types=1);

/**
 * HomeServer v2.4 Section 2 — federated Contacts continuity.
 *
 * One logical search surface spans distinct native contact classes without
 * copying records between databases or collapsing their native semantics.
 */
const VP3_HOMESERVER_CONTACTS_V241='vp3-homeserver-contacts-v241-20260925';

function homeserver_contacts_v241_text(mixed $value,int $max=1600): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??(string)$value),0,$max,'…');
}

function homeserver_contacts_v241_matches(string $query,string $text): bool
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

function homeserver_contacts_v241_cloud_item(
    int $userId,string $class,string $authorityKey,string $name,
    string $content,?string $updatedAt=null,array $fields=[]
): array {
    $allowed=['profile_visitor','agent_radar','core_crm'];
    if(!in_array($class,$allowed,true))throw new RuntimeException('Unsupported VP3 Cloud contact class.');
    $key=$class.':'.homeserver_contacts_v241_text($authorityKey,120);
    $item=homeserver_federated_v240_envelope(
      'vp3_cloud','contacts',$key,$name,$content,$updatedAt
    );
    $projection=[
      'contact_class'=>$class,
      'display_name'=>homeserver_contacts_v241_text($name,240),
      'organization'=>homeserver_contacts_v241_text($fields['organization']??'',240)?:null,
      'email'=>homeserver_contacts_v241_text($fields['email']??'',320)?:null,
      'phone'=>homeserver_contacts_v241_text($fields['phone']??'',80)?:null,
      'relationship'=>homeserver_contacts_v241_text($fields['relationship']??'',160)?:null,
      'notes'=>homeserver_contacts_v241_text($fields['notes']??$content,2200),
      'updated_at'=>$updatedAt?:null,
      'authority_source'=>'vp3_cloud',
      'authority_key'=>$item['authority_key'],
      'canonical_id'=>$item['canonical_id'],
      'record_revision'=>$item['record_revision'],
      'federation_version'=>$item['federation_version'],
      'mirror_only'=>false,
      'read_only'=>$class!=='core_crm',
      'source_label'=>match($class){
        'profile_visitor'=>'VP3 Profile relationship',
        'agent_radar'=>'VP3 Agent Radar',
        default=>'VP3 CRM',
      },
      'mutation_route'=>match($class){
        'agent_radar'=>'cloud_native_agent_radar',
        'core_crm'=>'cloud_native_crm',
        default=>'cloud_native_relationship',
      },
      'allowed_mutations'=>match($class){
        'agent_radar'=>['watch','policy'],
        'core_crm'=>['update','delete'],
        default=>[],
      },
    ];
    homeserver_federated_v240_observe($userId,$item,'vp3_cloud');
    return $projection;
}

function homeserver_contacts_v241_cloud_contacts(
    int $userId,string $query='',int $limit=250
): array {
    $pdo=db();if(!$pdo||$userId<1)return [];
    $limit=max(1,min(500,$limit));$items=[];$seen=[];

    if(function_exists('profile_visitor_contact_list_v243')){
        try{
            foreach(profile_visitor_contact_list_v243($pdo,$userId,min(250,$limit)) as $row){
                if(!is_array($row))continue;
                $ref=homeserver_contacts_v241_text($row['contact_ref']??$row['contact_id']??'',120);
                if($ref==='')continue;
                $name=homeserver_contacts_v241_text($row['visitor_label']??'',240)?:('Guest '.$ref);
                $content=implode(' · ',array_filter([
                  !empty($row['signed_in'])?'signed-in member':'profile visitor',
                  homeserver_contacts_v241_text($row['relationship_scope']??'',160),
                  'visits '.(int)($row['visit_count']??0),
                  'conversations '.(int)($row['conversation_count']??0),
                ]));
                if(!homeserver_contacts_v241_matches($query,$name.' '.$content))continue;
                $item=homeserver_contacts_v241_cloud_item(
                  $userId,'profile_visitor',$ref,$name,$content,
                  (string)($row['last_seen_at']??''),
                  ['relationship'=>(string)($row['relationship_scope']??''),'notes'=>$content]
                );
                if(isset($seen[$item['canonical_id']]))continue;
                $seen[$item['canonical_id']]=true;$items[]=$item;
                if(count($items)>=$limit)return $items;
            }
        }catch(Throwable $ignored){}
    }

    if(table_exists('vp3_agent_contacts')){
        try{
            $s=$pdo->prepare("SELECT id,display_name,operator_name,visitor_class,relationship_status,
              verification_status,inferred_intent,risk_score,engagement_score,value_score,last_seen_at
              FROM vp3_agent_contacts WHERE owner_user_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 250");
            $s->execute([$userId]);
            foreach($s->fetchAll()?:[] as $row){
                $id=(int)($row['id']??0);if($id<1)continue;
                $name=homeserver_contacts_v241_text($row['display_name']??'',240)?:'Automated agent';
                $operator=homeserver_contacts_v241_text($row['operator_name']??'',240);
                $content=implode(' · ',array_filter([
                  $operator,
                  homeserver_contacts_v241_text($row['visitor_class']??'automated',80),
                  'relationship '.homeserver_contacts_v241_text($row['relationship_status']??'observed',80),
                  'verification '.homeserver_contacts_v241_text($row['verification_status']??'unverified',80),
                  homeserver_contacts_v241_text($row['inferred_intent']??'',240),
                  'risk '.(int)($row['risk_score']??0),
                  'engagement '.(int)($row['engagement_score']??0),
                  'value '.(int)($row['value_score']??0),
                ]));
                if(!homeserver_contacts_v241_matches($query,$name.' '.$content))continue;
                $item=homeserver_contacts_v241_cloud_item(
                  $userId,'agent_radar',(string)$id,$name,$content,
                  (string)($row['last_seen_at']??''),
                  ['organization'=>$operator,'relationship'=>(string)($row['relationship_status']??''),'notes'=>$content]
                );
                if(isset($seen[$item['canonical_id']]))continue;
                $seen[$item['canonical_id']]=true;$items[]=$item;
                if(count($items)>=$limit)return $items;
            }
        }catch(Throwable $ignored){}
    }

    if(function_exists('crm_v180_contacts_for_owner')){
        try{
            foreach(crm_v180_contacts_for_owner($pdo,$userId,min(500,$limit)) as $row){
                if(!is_array($row))continue;
                $id=(int)($row['id']??0);if($id<1)continue;
                $name=homeserver_contacts_v241_text($row['name']??$row['email']??'',240);
                if($name==='')$name='CRM contact';
                $relationship=homeserver_contacts_v241_text(
                  $row['lifecycle_stage']??$row['status']??'crm',
                  160
                );
                $content=implode(' · ',array_filter([
                  homeserver_contacts_v241_text($row['company']??'',240),
                  homeserver_contacts_v241_text($row['email']??'',320),
                  homeserver_contacts_v241_text($row['phone']??'',80),
                  $relationship,
                  homeserver_contacts_v241_text($row['source']??'',80),
                ]));
                if(!homeserver_contacts_v241_matches($query,$name.' '.$content))continue;
                $item=homeserver_contacts_v241_cloud_item(
                  $userId,'core_crm',(string)$id,$name,$content,
                  (string)($row['updated_at']??$row['created_at']??''),
                  [
                    'organization'=>(string)($row['company']??''),
                    'email'=>(string)($row['email']??''),
                    'phone'=>(string)($row['phone']??''),
                    'relationship'=>$relationship,
                    'notes'=>$content,
                  ]
                );
                if(isset($seen[$item['canonical_id']]))continue;
                $seen[$item['canonical_id']]=true;$items[]=$item;
                if(count($items)>=$limit)return $items;
            }
        }catch(Throwable $ignored){}
    }
    return $items;
}

function homeserver_contacts_v241_snapshot_records(
    int $userId,string $query='',int $limit=80
): array {
    $out=[];
    foreach(homeserver_contacts_v241_cloud_contacts($userId,$query,$limit) as $item){
        $out[]=[
          'key'=>(string)$item['authority_key'],
          'title'=>(string)$item['display_name'],
          'content'=>(string)$item['notes'],
          'updated_at'=>$item['updated_at'],
          'authoritative_source'=>'vp3_cloud',
          'authority_source'=>'vp3_cloud',
          'authority_key'=>(string)$item['authority_key'],
          'canonical_id'=>(string)$item['canonical_id'],
          'record_revision'=>(string)$item['record_revision'],
          'federation_version'=>'2.4',
          'mirror_only'=>false,
          'dataset'=>'contacts',
          'contact_class'=>(string)$item['contact_class'],
        ];
    }
    return $out;
}

function homeserver_contacts_v241_homeserver_contacts(
    int $userId,string $query='',int $limit=100
): array {
    if($userId<1||!function_exists('homeserver_execution_v230_execute'))return [];
    $limit=max(1,min(250,$limit));
    try{
        $run=homeserver_execution_v230_execute($userId,'contacts.list',[
          'query'=>homeserver_contacts_v241_text($query,240),'limit'=>$limit,
        ]);
        $outer=is_array($run['result']??null)?$run['result']:[];
        $rows=is_array($outer['items']??null)?$outer['items']:[];
        $out=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $canonical=homeserver_contacts_v241_text($row['canonical_id']??'',80);
            $key=homeserver_contacts_v241_text($row['authority_key']??'',180);
            if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical)||!str_starts_with($key,'address_book:'))continue;
            $expected=homeserver_federated_v240_canonical_id('homeserver','contacts',$key);
            if(!hash_equals($expected,$canonical))continue;
            $item=[
              'contact_class'=>'address_book',
              'display_name'=>homeserver_contacts_v241_text($row['display_name']??'',240)?:'HomeServer contact',
              'organization'=>homeserver_contacts_v241_text($row['organization']??'',240)?:null,
              'email'=>homeserver_contacts_v241_text($row['email']??'',320)?:null,
              'phone'=>homeserver_contacts_v241_text($row['phone']??'',80)?:null,
              'relationship'=>homeserver_contacts_v241_text($row['relationship']??'',160)?:null,
              'notes'=>homeserver_contacts_v241_text($row['notes']??'',2200),
              'updated_at'=>$row['updated_at']??null,
              'authority_source'=>'homeserver','authority_key'=>$key,'canonical_id'=>$canonical,
              'record_revision'=>homeserver_contacts_v241_text($row['record_revision']??'',64)?:null,
              'federation_version'=>'2.4','mirror_only'=>true,'read_only'=>false,
              'source_label'=>'HomeServer','mutation_route'=>'homeserver_governed',
              'allowed_mutations'=>['update','delete'],
            ];
            homeserver_federated_v240_observe($userId,[
              'authority_source'=>'homeserver','dataset'=>'contacts','authority_key'=>$key,
              'canonical_id'=>$canonical,'record_revision'=>$item['record_revision'],
              'title'=>$item['display_name'],'content'=>$item['notes'],'updated_at'=>$item['updated_at'],
            ],'vp3_cloud');
            $out[]=$item;
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function homeserver_contacts_v241_unified(
    int $userId,string $query='',int $limit=250
): array {
    $limit=max(1,min(500,$limit));$items=[];$seen=[];$counts=[];
    foreach(homeserver_contacts_v241_cloud_contacts($userId,$query,$limit) as $item){
        $canonical=(string)$item['canonical_id'];
        if(isset($seen[$canonical]))continue;
        $seen[$canonical]=true;$items[]=$item;
        $class=(string)$item['contact_class'];$counts[$class]=($counts[$class]??0)+1;
        if(count($items)>=$limit)break;
    }
    if(count($items)<$limit){
        foreach(homeserver_contacts_v241_homeserver_contacts($userId,$query,$limit-count($items)) as $item){
            $canonical=(string)$item['canonical_id'];
            if(isset($seen[$canonical]))continue;
            $seen[$canonical]=true;$items[]=$item;
            $class=(string)$item['contact_class'];$counts[$class]=($counts[$class]??0)+1;
            if(count($items)>=$limit)break;
        }
    }
    return [
      'version'=>'2.4','dataset'=>'contacts','items'=>$items,'count'=>count($items),
      'classes'=>$counts,
      'authority_rules'=>[
        'cloud_native_classes'=>['profile_visitor','agent_radar','core_crm'],
        'homeserver_native_classes'=>['address_book'],
        'no_cross_database_id_writes'=>true,
      ],
    ];
}

function homeserver_contacts_v241_request_homeserver(
    int $userId,string $action,array $payload
): array {
    $action=strtolower(trim($action));
    $tool=match($action){
      'create'=>'contacts.create','update'=>'contacts.update','delete'=>'contacts.delete',
      default=>throw new RuntimeException('Unsupported HomeServer contact action.'),
    };
    if(in_array($action,['update','delete'],true)){
        $canonical=homeserver_contacts_v241_text($payload['canonical_id']??'',80);
        if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical))throw new RuntimeException('A valid HomeServer contact canonical ID is required.');
        $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
        $s=$pdo->prepare("SELECT authority_source,authority_key,tombstoned FROM homeserver_federated_records
          WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud' ORDER BY last_seen_at DESC LIMIT 1");
        $s->execute([$userId,$canonical]);$known=$s->fetch();
        if(!$known||(string)$known['authority_source']!=='homeserver'||!str_starts_with((string)$known['authority_key'],'address_book:')||!empty($known['tombstoned'])){
            throw new RuntimeException('That contact is not a writable HomeServer address-book record.');
        }
    }
    if(!function_exists('homeserver_governed_v233_request'))throw new RuntimeException('HomeServer governed actions are unavailable.');
    return homeserver_governed_v233_request($userId,$tool,$payload);
}


function homeserver_contacts_v241_ensure_schema(?PDO $pdo=null): void
{
    $pdo ??= db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_contact_mutations (
      user_id INT UNSIGNED NOT NULL,
      mutation_id VARCHAR(128) NOT NULL,
      action_key VARCHAR(40) NOT NULL,
      request_hash CHAR(64) NOT NULL,
      canonical_id CHAR(45) NULL,
      result_json MEDIUMTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(user_id,mutation_id),
      INDEX idx_hs_contact_mutations_created (user_id,created_at),
      CONSTRAINT fk_hs_contact_mutations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function homeserver_contacts_v241_schema_ready(): bool
{
    return function_exists('table_exists')&&table_exists('homeserver_contact_mutations');
}

function homeserver_contacts_v241_mutation_id(mixed $value): string
{
    $id=trim((string)$value);
    if(!preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$id))throw new RuntimeException('Contact mutation_id must be 8 to 128 safe characters.');
    return $id;
}

function homeserver_contacts_v241_expected_revision(mixed $value): string
{
    $revision=strtolower(trim((string)$value));
    if(!preg_match('/^[0-9a-f]{64}$/',$revision))throw new RuntimeException('Contact expected_revision must be a SHA-256 value.');
    return $revision;
}

function homeserver_contacts_v241_mutation_hash(string $action,array $payload): string
{
    ksort($payload);
    return hash('sha256',json_encode(['action'=>$action,'payload'=>$payload],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}');
}

function homeserver_contacts_v241_mutation_replay(
    int $userId,string $mutationId,string $action,string $requestHash
): ?array {
    $pdo=db();if(!$pdo)return null;
    if(!homeserver_contacts_v241_schema_ready())homeserver_contacts_v241_ensure_schema($pdo);
    $s=$pdo->prepare('SELECT action_key,request_hash,result_json FROM homeserver_contact_mutations WHERE user_id=? AND mutation_id=? LIMIT 1');
    $s->execute([$userId,$mutationId]);$row=$s->fetch();
    if(!$row)return null;
    if((string)$row['action_key']!==$action||!hash_equals((string)$row['request_hash'],$requestHash)){
        throw new RuntimeException('Contact mutation_id was already used with different arguments.');
    }
    $result=json_decode((string)$row['result_json'],true);
    if(!is_array($result))throw new RuntimeException('Stored contact mutation result is unavailable.');
    $result['idempotent_replay']=true;
    return $result;
}

function homeserver_contacts_v241_mutation_record(
    int $userId,string $mutationId,string $action,string $requestHash,?string $canonicalId,array $result
): void {
    $pdo=db();if(!$pdo)return;
    if(!homeserver_contacts_v241_schema_ready())homeserver_contacts_v241_ensure_schema($pdo);
    $pdo->prepare('INSERT INTO homeserver_contact_mutations
      (user_id,mutation_id,action_key,request_hash,canonical_id,result_json)
      VALUES (?,?,?,?,?,?)')
      ->execute([$userId,$mutationId,$action,$requestHash,$canonicalId,
        json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}']);
}

function homeserver_contacts_v241_cloud_authority_row(int $userId,string $canonicalId): ?array
{
    $pdo=db();if(!$pdo||$userId<1)return null;
    $s=$pdo->prepare("SELECT authority_source,authority_key,tombstoned FROM homeserver_federated_records
      WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud'
      ORDER BY last_seen_at DESC LIMIT 1");
    $s->execute([$userId,$canonicalId]);$row=$s->fetch();
    return $row?:null;
}

function homeserver_contacts_v241_core_crm_row(int $userId,int $contactId): ?array
{
    $pdo=db();if(!$pdo||$userId<1||$contactId<1||!table_exists('crm_contacts'))return null;
    if(!function_exists('column_exists')||!column_exists('crm_contacts','owner_user_id'))return null;
    $s=$pdo->prepare("SELECT * FROM crm_contacts WHERE id=? AND owner_user_id=? AND status<>'archived' LIMIT 1");
    $s->execute([$contactId,$userId]);$row=$s->fetch();
    return $row?:null;
}

function homeserver_contacts_v241_core_crm_projection(int $userId,array $row): array
{
    $id=(int)($row['id']??0);
    if($id<1)throw new RuntimeException('CRM contact identity is invalid.');
    $name=homeserver_contacts_v241_text($row['name']??$row['email']??'',240)?:'CRM contact';
    $relationship=homeserver_contacts_v241_text($row['lifecycle_stage']??$row['status']??'crm',160);
    $content=implode(' · ',array_filter([
      homeserver_contacts_v241_text($row['company']??'',240),
      homeserver_contacts_v241_text($row['email']??'',320),
      homeserver_contacts_v241_text($row['phone']??'',80),
      $relationship,
      homeserver_contacts_v241_text($row['source']??'',80),
    ]));
    return homeserver_contacts_v241_cloud_item(
      $userId,'core_crm',(string)$id,$name,$content,
      (string)($row['updated_at']??$row['created_at']??''),
      [
        'organization'=>(string)($row['company']??''),
        'email'=>(string)($row['email']??''),
        'phone'=>(string)($row['phone']??''),
        'relationship'=>$relationship,
        'notes'=>$content,
      ]
    );
}

function homeserver_contacts_v241_core_crm_id(int $userId,string $canonicalId): int
{
    $canonicalId=strtolower(trim($canonicalId));
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonicalId))throw new RuntimeException('A valid contact canonical ID is required.');
    $known=homeserver_contacts_v241_cloud_authority_row($userId,$canonicalId);
    if(!$known||(string)$known['authority_source']!=='vp3_cloud'||!str_starts_with((string)$known['authority_key'],'core_crm:')||!empty($known['tombstoned'])){
        throw new RuntimeException('That contact is not a writable VP3 CRM record.');
    }
    $id=(int)substr((string)$known['authority_key'],9);
    if($id<1)throw new RuntimeException('CRM contact identity is invalid.');
    $expected=homeserver_federated_v240_canonical_id('vp3_cloud','contacts','core_crm:'.$id);
    if(!hash_equals($expected,$canonicalId))throw new RuntimeException('CRM contact identity does not match its authority.');
    return $id;
}

function homeserver_contacts_v241_cloud_crm_create(int $userId,array $payload): array
{
    $mutationId=homeserver_contacts_v241_mutation_id($payload['mutation_id']??'');
    $email=strtolower(trim((string)($payload['email']??'')));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('A valid CRM contact email is required.');
    $name=homeserver_contacts_v241_text($payload['display_name']??$payload['name']??$email,240);
    $phone=homeserver_contacts_v241_text($payload['phone']??'',80);
    $company=homeserver_contacts_v241_text($payload['organization']??$payload['company']??'',240);
    $request=['email'=>$email,'display_name'=>$name,'phone'=>$phone,'organization'=>$company];
    $hash=homeserver_contacts_v241_mutation_hash('contacts.create.cloud',$request);
    $replay=homeserver_contacts_v241_mutation_replay($userId,$mutationId,'contacts.create.cloud',$hash);
    if($replay)return $replay;
    $pdo=db();if(!$pdo||!function_exists('crm_v180_upsert_contact'))throw new RuntimeException('VP3 CRM is unavailable.');
    $id=crm_v180_upsert_contact($pdo,[
      'owner_user_id'=>$userId,'name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>$company,'source'=>'federated_contacts',
    ]);
    $row=homeserver_contacts_v241_core_crm_row($userId,$id);
    if(!$row)throw new RuntimeException('VP3 CRM contact could not be loaded after creation.');
    $item=homeserver_contacts_v241_core_crm_projection($userId,$row);
    $result=['contact'=>$item,'created'=>true,'authority_source'=>'vp3_cloud'];
    homeserver_contacts_v241_mutation_record($userId,$mutationId,'contacts.create.cloud',$hash,(string)$item['canonical_id'],$result);
    return $result;
}

function homeserver_contacts_v241_cloud_crm_update(int $userId,string $canonicalId,array $payload): array
{
    $mutationId=homeserver_contacts_v241_mutation_id($payload['mutation_id']??'');
    $expected=homeserver_contacts_v241_expected_revision($payload['expected_revision']??'');
    $allowed=['display_name','organization','email','phone','relationship'];
    $fields=[];
    foreach($allowed as $key)if(array_key_exists($key,$payload))$fields[$key]=$payload[$key];
    if(!$fields)throw new RuntimeException('No CRM contact fields were supplied.');
    $request=['canonical_id'=>$canonicalId,'expected_revision'=>$expected,'fields'=>$fields];
    $hash=homeserver_contacts_v241_mutation_hash('contacts.update.cloud',$request);
    $replay=homeserver_contacts_v241_mutation_replay($userId,$mutationId,'contacts.update.cloud',$hash);
    if($replay)return $replay;

    $id=homeserver_contacts_v241_core_crm_id($userId,$canonicalId);
    $current=homeserver_contacts_v241_core_crm_row($userId,$id);
    if(!$current)throw new RuntimeException('VP3 CRM contact not found.');
    $projection=homeserver_contacts_v241_core_crm_projection($userId,$current);
    if(!hash_equals((string)$projection['record_revision'],$expected))throw new RuntimeException('VP3 CRM contact changed after this edit was prepared. Refresh and try again.');

    $name=homeserver_contacts_v241_text($fields['display_name']??$current['name']??'',240);
    $company=homeserver_contacts_v241_text($fields['organization']??$current['company']??'',240);
    $phone=homeserver_contacts_v241_text($fields['phone']??$current['phone']??'',80);
    $email=strtolower(trim((string)($fields['email']??$current['email']??'')));
    if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('CRM contact email is invalid.');
    $relationship=homeserver_contacts_v241_text($fields['relationship']??$current['lifecycle_stage']??'',80);

    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare("UPDATE crm_contacts SET name=?,company=?,phone=?,email=?,email_normalized=?,
      lifecycle_stage=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status<>'archived'")
      ->execute([$name,$company,$phone,$email,$email,$relationship,$id,$userId]);
    $row=homeserver_contacts_v241_core_crm_row($userId,$id);
    if(!$row)throw new RuntimeException('VP3 CRM contact could not be loaded after update.');
    $item=homeserver_contacts_v241_core_crm_projection($userId,$row);
    $result=['contact'=>$item,'updated'=>true,'authority_source'=>'vp3_cloud'];
    homeserver_contacts_v241_mutation_record($userId,$mutationId,'contacts.update.cloud',$hash,$canonicalId,$result);
    return $result;
}

function homeserver_contacts_v241_cloud_crm_delete(int $userId,string $canonicalId,array $payload): array
{
    $mutationId=homeserver_contacts_v241_mutation_id($payload['mutation_id']??'');
    $expected=homeserver_contacts_v241_expected_revision($payload['expected_revision']??'');
    $request=['canonical_id'=>$canonicalId,'expected_revision'=>$expected];
    $hash=homeserver_contacts_v241_mutation_hash('contacts.delete.cloud',$request);
    $replay=homeserver_contacts_v241_mutation_replay($userId,$mutationId,'contacts.delete.cloud',$hash);
    if($replay)return $replay;

    $id=homeserver_contacts_v241_core_crm_id($userId,$canonicalId);
    $current=homeserver_contacts_v241_core_crm_row($userId,$id);
    if(!$current)throw new RuntimeException('VP3 CRM contact not found.');
    $projection=homeserver_contacts_v241_core_crm_projection($userId,$current);
    if(!hash_equals((string)$projection['record_revision'],$expected))throw new RuntimeException('VP3 CRM contact changed after this delete was prepared. Refresh and try again.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->prepare("UPDATE crm_contacts SET status='archived',updated_at=UTC_TIMESTAMP() WHERE id=? AND owner_user_id=? AND status<>'archived'")
      ->execute([$id,$userId]);
    if(function_exists('homeserver_federated_v240_mark_tombstone')){
        homeserver_federated_v240_mark_tombstone($userId,'vp3_cloud','contacts','core_crm:'.$id,'vp3_cloud');
    }else{
        $pdo->prepare("UPDATE homeserver_federated_records SET tombstoned=1,last_seen_at=UTC_TIMESTAMP()
          WHERE user_id=? AND canonical_id=? AND observed_source='vp3_cloud'")->execute([$userId,$canonicalId]);
    }
    $result=['deleted'=>true,'canonical_id'=>$canonicalId,'authority_source'=>'vp3_cloud'];
    homeserver_contacts_v241_mutation_record($userId,$mutationId,'contacts.delete.cloud',$hash,$canonicalId,$result);
    return $result;
}

function homeserver_contacts_v241_mutate(int $userId,string $action,array $payload): array
{
    $action=strtolower(trim($action));
    if(!in_array($action,['create','update','delete'],true))throw new RuntimeException('Unsupported contact mutation.');

    if($action==='create'){
        $authority=strtolower(trim((string)($payload['authority_source']??'homeserver')));
        if($authority==='vp3_cloud')return homeserver_contacts_v241_cloud_crm_create($userId,$payload);
        if($authority!=='homeserver')throw new RuntimeException('Unsupported contact authority source.');
        unset($payload['authority_source']);
        return homeserver_contacts_v241_request_homeserver($userId,'create',$payload);
    }

    $canonical=strtolower(trim((string)($payload['canonical_id']??'')));
    if(!preg_match('/^fd24_[0-9a-f]{40}$/',$canonical))throw new RuntimeException('A valid contact canonical ID is required.');
    $known=homeserver_contacts_v241_cloud_authority_row($userId,$canonical);
    if(!$known)throw new RuntimeException('Contact authority is unknown. Refresh Contacts and try again.');
    $source=(string)$known['authority_source'];
    $key=(string)$known['authority_key'];
    if($source==='homeserver'&&str_starts_with($key,'address_book:')){
        return homeserver_contacts_v241_request_homeserver($userId,$action,$payload);
    }
    if($source==='vp3_cloud'&&str_starts_with($key,'core_crm:')){
        return $action==='update'
          ?homeserver_contacts_v241_cloud_crm_update($userId,$canonical,$payload)
          :homeserver_contacts_v241_cloud_crm_delete($userId,$canonical,$payload);
    }
    throw new RuntimeException('This contact class does not support generic edits. Use its native relationship controls.');
}
