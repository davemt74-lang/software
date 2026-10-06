<?php
declare(strict_types=1);

/** Account-scoped, source-authoritative workspace replicas. Never executes copied schedules. */
const VP3_WORKSPACE_SYNC_V1='vp3.workspace-sync.v1';
const VP3_WORKSPACE_SYNC_CHUNK=65536;
const VP3_WORKSPACE_SYNC_MAX=67108864;
const VP3_WORKSPACE_ASSET_CHUNK=1048576;

function workspace_sync_registry_v1(): array
{
    // [table, own column] or [table, child key, parent table, parent key, parent owner column].
    // Every predicate is compiled from this registry, never a client-supplied table/column.
    $registry=[
      'profile'=>[['users','id'],['user_account_types','user_id'],['user_agent_preferences','user_id']],
      'contacts'=>[['crm_contacts','owner_user_id'],['vp3_agent_contacts','owner_user_id'],['user_relationships','owner_user_id']],
      'crm'=>[
        ['crm_leads','contact_id','crm_contacts','id','owner_user_id'],
        ['crm_tasks','lead_id','crm_leads','id','contact_id','crm_contacts','id','owner_user_id'],
        ['crm_activities','lead_id','crm_leads','id','contact_id','crm_contacts','id','owner_user_id'],
      ],
      'knowledge'=>[['knowledge_items','created_by_user_id'],['artist_transcript_folders_v177','created_by_user_id'],
        ['knowledge_chunks','knowledge_id','knowledge_items','id','created_by_user_id']],
      'transcriptions'=>[['artist_transcript_sessions_v172','created_by_user_id'],
        ['artist_transcript_segments_v172','session_id','artist_transcript_sessions_v172','id','created_by_user_id']],
      'calendar'=>[['user_calendar_events','owner_user_id']],
      'schedules'=>[['agent_scheduling_schedules','owner_user_id'],['agent_scheduling_bookings','owner_user_id'],
        ['agent_scheduling_event_types','schedule_id','agent_scheduling_schedules','id','owner_user_id'],
        ['agent_scheduling_availability','schedule_id','agent_scheduling_schedules','id','owner_user_id'],
        ['agent_scheduling_overrides','schedule_id','agent_scheduling_schedules','id','owner_user_id']],
      'meetings'=>[['video_meetings','owner_user_id'],
        ['video_meeting_participants','meeting_id','video_meetings','id','owner_user_id'],
        ['video_meeting_transcript_segments','meeting_id','video_meetings','id','owner_user_id'],
        ['video_meeting_notes','meeting_id','video_meetings','id','owner_user_id'],
        ['video_meeting_objectives','meeting_id','video_meetings','id','owner_user_id'],
        ['video_meeting_intelligence_state','meeting_id','video_meetings','id','owner_user_id']],
      'products'=>[['agent_commerce_products_v800','owner_user_id'],
        ['agent_commerce_product_bindings_v800','product_id','agent_commerce_products_v800','id','owner_user_id']],
      'orders'=>[['agent_commerce_orders_v800','owner_user_id'],
        ['agent_commerce_order_items_v800','order_id','agent_commerce_orders_v800','id','owner_user_id'],
        ['agent_commerce_payments_v800','order_id','agent_commerce_orders_v800','id','owner_user_id'],
        ['agent_commerce_refunds_v800','order_id','agent_commerce_orders_v800','id','owner_user_id']],
      'agents'=>[['user_agents','owner_user_id'],['agent_memory_items','user_id']],
      'chats'=>[['chat_conversations','user_id'],['chat_messages','conversation_id','chat_conversations','id','user_id']],
      'notifications'=>[['notifications','user_id']],
      'music'=>[['tracks','owner_user_id'],['albums','owner_user_id'],['playlists','owner_user_id'],
        ['playlist_tracks','playlist_id','playlists','id','owner_user_id']],
      'workspace_other'=>[],
      'artist_workspace'=>[['artist_workspaces_v181','artist_user_id'],
        ['artist_catalog_tracks_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_catalog_albums_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_catalog_photos_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_catalog_merch_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_catalog_shows_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_posts_v181','workspace_id','artist_workspaces_v181','id','artist_user_id'],
        ['artist_release_plans_v181','workspace_id','artist_workspaces_v181','id','artist_user_id']],
    ];
    return workspace_sync_extend_registry_v1($registry);
}

/** Discover account-owned content and its FK descendants, without exporting server authority. */
function workspace_sync_extend_registry_v1(array $registry): array
{
    static $catalog=null;
    $pdo=db();if(!$pdo)return $registry;
    if($catalog===null){
        $columns=[];$foreign=[];
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            foreach($pdo->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $row)
                $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']]=true;
            foreach($pdo->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)
                $foreign[$row['TABLE_NAME']][]=[$row['COLUMN_NAME'],$row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']];
        }else{
            foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) as $table){
                if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))continue;
                foreach($pdo->query('PRAGMA table_info(`'.$table.'`)')->fetchAll(PDO::FETCH_ASSOC) as $row)$columns[$table][$row['name']]=true;
                foreach($pdo->query('PRAGMA foreign_key_list(`'.$table.'`)')->fetchAll(PDO::FETCH_ASSOC) as $row)$foreign[$table][]=[$row['from'],$row['table'],$row['to']];
            }
        }
        $catalog=[$columns,$foreign];
    }
    [$columns,$foreign]=$catalog;$known=[];$group=[];$registered=[];
    foreach($registry as $dataset=>$descriptors)foreach($descriptors as $descriptor){
        $registered[$descriptor[0]]=true;
        if(workspace_sync_descriptor_ready_v1($descriptor)){$known[$descriptor[0]]=$descriptor;$group[$descriptor[0]]=$dataset;}
    }
    $blocked=static fn(string $table):bool => (bool)preg_match('/^(?:sqlite_|homeserver_|settings$|permissions$|role_permissions$|user_account_types$)|(?:auth|oauth|session_tokens|session_keys|credentials|secrets|api_keys|password|login|provider_connections|calendar_connections|device_connections|webhook|outbox|leases|locks|approvals|audit|usage|billing|claim_codes)/i',$table);
    $classify=static function(string $table):string{
        foreach(['crm'=>'crm','contacts'=>'contacts','calendar'=>'calendar','scheduling'=>'schedules','meeting'=>'meetings','transcript'=>'transcriptions','knowledge'=>'knowledge','commerce_product'=>'products','commerce_order'=>'orders','commerce_payment'=>'orders','commerce_refund'=>'orders','chat'=>'chats','notification'=>'notifications','artist_'=>'artist_workspace','memory'=>'agents','user_agents'=>'agents'] as $pattern=>$dataset)
            if(str_contains($table,$pattern))return $dataset;
        return 'workspace_other';
    };
    // Ownership fields are precedence-ordered: a creator does not override a different owner.
    foreach($columns as $table=>$fields){
        if(isset($registered[$table])||$blocked($table)||!preg_match('/^[a-zA-Z0-9_]+$/D',$table))continue;
        foreach(array_merge(['owner_user_id','workspace_owner_user_id','artist_user_id','created_by_user_id'],str_starts_with($table,'user_')?['user_id']:[]) as $owner){
            if(!isset($fields[$owner]))continue;
            $dataset=$classify($table);$descriptor=[$table,$owner];
            $registry[$dataset][]=$descriptor;$known[$table]=$descriptor;$group[$table]=$dataset;$registered[$table]=true;
            break;
        }
    }
    // Child tables inherit a single verified native authority chain. Roots always keep their own predicate.
    for($depth=0;$depth<6;$depth++){
        $added=false;
        foreach($foreign as $table=>$links){
            if(isset($registered[$table])||$blocked($table)||$table==='users')continue;
            foreach($links as [$column,$parent,$parentKey]){
                if(!isset($known[$parent])||$parent==='users')continue;
                $descriptor=array_merge([$table,$column,$parent,$parentKey],array_slice($known[$parent],1));
                $dataset=$group[$parent];$registry[$dataset][]=$descriptor;
                $known[$table]=$descriptor;$group[$table]=$dataset;$registered[$table]=true;$added=true;break;
            }
        }
        if(!$added)break;
    }
    foreach($registry as &$descriptors)usort($descriptors,static fn($a,$b)=>$a[0]<=>$b[0]);
    return $registry;
}

function workspace_sync_dataset_v1(mixed $value): string
{
    if(!is_string($value)||!array_key_exists($value,workspace_sync_registry_v1()))
        throw new InvalidArgumentException('Unknown workspace dataset.');
    return $value;
}

function workspace_sync_schema_v1(PDO $pdo): void
{
    $sqlite=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite';
    $text=$sqlite?'TEXT':'LONGTEXT';
    $tail=$sqlite?'':' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_workspace_snapshots_v1 (
      user_id BIGINT NOT NULL,source VARCHAR(16) NOT NULL,dataset VARCHAR(40) NOT NULL,
      revision CHAR(64) NOT NULL,body_json $text NOT NULL,byte_count BIGINT NOT NULL,
      record_count BIGINT NOT NULL,synced_at VARCHAR(40) NOT NULL,
      PRIMARY KEY(user_id,source,dataset))$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_workspace_assets_v1 (
      user_id BIGINT NOT NULL,dataset VARCHAR(40) NOT NULL,asset_id CHAR(64) NOT NULL,
      revision CHAR(64) NOT NULL,path VARCHAR(1024) NOT NULL,name VARCHAR(255) NOT NULL,
      sha256 CHAR(64) NOT NULL,size_bytes BIGINT NOT NULL,modified_at BIGINT NOT NULL,
      record_key VARCHAR(255) NOT NULL,field VARCHAR(120) NOT NULL,
      PRIMARY KEY(user_id,dataset,asset_id))$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS homeserver_workspace_chunks_v1 (
      user_id BIGINT NOT NULL,dataset VARCHAR(40) NOT NULL,revision CHAR(64) NOT NULL,
      byte_offset BIGINT NOT NULL,chunk_base64 $text NOT NULL,total_bytes BIGINT NOT NULL,
      session_hash CHAR(64) NOT NULL,updated_at VARCHAR(40) NOT NULL,
      PRIMARY KEY(user_id,dataset,byte_offset))$tail");
}

function workspace_sync_sensitive_v1(string $key): bool
{
    return (bool)preg_match('/(?:password|secret|token|credential|ciphertext|api_key|private_key|authorization|cookie|session_key|invite_key|download_key|encrypted|_enc$|bearer|signed_url|jwt|nonce)/i',$key);
}

function workspace_sync_safe_v1(mixed $value): mixed
{
    if(!is_array($value))return $value;
    $out=[];
    foreach($value as $key=>$child){
        if(is_string($key)&&workspace_sync_sensitive_v1($key))continue;
        if(is_string($child)&&preg_match('/(?:_json|metadata)$/',$key)){
            $decoded=json_decode($child,true);
            if(is_array($decoded))$child=json_encode(workspace_sync_safe_v1($decoded),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        $out[$key]=workspace_sync_safe_v1($child);
    }
    return $out;
}

function workspace_sync_descriptor_ready_v1(array $descriptor): bool
{
    [$table,$column]=array_splice($descriptor,0,2);
    if(!table_exists($table)||!column_exists($table,$column))return false;
    if(!$descriptor)return true;
    [$parent,$key]=array_splice($descriptor,0,2);
    return table_exists($parent)&&column_exists($parent,$key)&&
      workspace_sync_descriptor_ready_v1(array_merge([$parent],$descriptor));
}

function workspace_sync_predicate_v1(array $descriptor,string $alias='r'): string
{
    $table=array_shift($descriptor);$column=array_shift($descriptor);
    if(!$descriptor)return "$alias.`$column`=?";
    $parent=array_shift($descriptor);$parentKey=array_shift($descriptor);
    return "$alias.`$column` IN (SELECT p.`$parentKey` FROM `$parent` p WHERE ".
        workspace_sync_predicate_v1(array_merge([$parent],$descriptor),'p').')';
}

function workspace_sync_asset_path_v1(string $path): ?string
{
    $root=realpath(dirname(__DIR__).'/uploads');
    if(!$root||$path===''||str_contains($path,"\0")||preg_match('~^[a-z][a-z0-9+.-]*:~i',$path))return null;
    $candidate=realpath(dirname(__DIR__).'/'.ltrim(str_replace('\\','/',$path),'/'));
    if(!$candidate||!is_file($candidate)||!str_starts_with($candidate,$root.DIRECTORY_SEPARATOR))return null;
    return $candidate;
}

function workspace_sync_assets_v1(array $records,array $prior=[]): array
{
    $files=[];$cached=[];foreach($prior as $asset)$cached[$asset['path'].'|'.$asset['size_bytes'].'|'.$asset['modified_at']]=$asset['sha256'];
    foreach($records as $record){
        foreach($record['data'] as $field=>$value){
            if(!is_string($value)||!preg_match('/(?:file|audio|image|cover|photo|video|document|attachment|recording)_path$/',$field))continue;
            $path=workspace_sync_asset_path_v1($value);if(!$path)continue;
            $size=filesize($path);if($size===false||$size>4294967296)throw new RuntimeException('Workspace attachment exceeds transfer capacity.');
            $cacheKey=$value.'|'.$size.'|'.filemtime($path);
            $digest=$cached[$cacheKey]??hash_file('sha256',$path);if($digest===false)throw new RuntimeException('Workspace attachment is unavailable.');
            $id=hash('sha256',$record['table'].':'.$record['source_id'].':'.$field);
            $files[]=['asset_id'=>$id,'record_key'=>$record['table'].':'.$record['source_id'],'field'=>$field,
              'name'=>basename($path),'path'=>$value,'sha256'=>$digest,'size_bytes'=>$size,'modified_at'=>filemtime($path)];
        }
    }
    return $files;
}

function workspace_sync_asset_chunk_v1(PDO $pdo,int $uid,string $dataset,array $body): array
{
    $q=$pdo->prepare("SELECT a.* FROM homeserver_workspace_assets_v1 a JOIN homeserver_workspace_snapshots_v1 s ON s.user_id=a.user_id AND s.dataset=a.dataset AND s.source='cloud' AND s.revision=a.revision WHERE a.user_id=? AND a.dataset=? AND a.asset_id=? AND a.revision=?");
    $q->execute([$uid,$dataset,$body['asset_id']??'',$body['revision']??'']);$asset=$q->fetch(PDO::FETCH_ASSOC);
    if(!$asset)throw new RuntimeException('Workspace attachment changed; retry.',409);
    $path=workspace_sync_asset_path_v1($asset['path']);
    $offset=$body['offset']??null;$size=(int)$asset['size_bytes'];
    if(!$path||filesize($path)!==$size||filemtime($path)!==(int)$asset['modified_at'])
        throw new RuntimeException('Workspace attachment changed; retry.',409);
    if(!is_int($offset)||$offset<0||$offset%VP3_WORKSPACE_ASSET_CHUNK!==0||($size>0&&$offset>=$size)||($size===0&&$offset!==0))
        throw new InvalidArgumentException('Invalid workspace attachment offset.');
    $handle=fopen($path,'rb');if(!$handle)throw new RuntimeException('Workspace attachment unavailable.');
    try{if(fseek($handle,$offset)!==0)throw new RuntimeException('Workspace attachment unavailable.');$chunk=fread($handle,VP3_WORKSPACE_ASSET_CHUNK);if($chunk===false)throw new RuntimeException('Workspace attachment unavailable.');}
    finally{fclose($handle);}
    return ['revision'=>$asset['revision'],'asset_id'=>$asset['asset_id'],'sha256'=>$asset['sha256'],'offset'=>$offset,'total_bytes'=>$size,'chunk'=>base64_encode($chunk)];
}

function workspace_sync_export_v1(PDO $pdo,int $userId,string $dataset): array
{
    workspace_sync_dataset_v1($dataset);
    if($userId<1)throw new InvalidArgumentException('Invalid workspace owner.');
    $records=[];$available=[];$unavailable=[];
    foreach(workspace_sync_registry_v1()[$dataset] as $descriptor){
        $table=$descriptor[0];
        if(!workspace_sync_descriptor_ready_v1($descriptor)){$unavailable[]=$table;continue;}
        $available[]=$table;
        $stmt=$pdo->prepare("SELECT r.* FROM `$table` r WHERE ".workspace_sync_predicate_v1($descriptor));
        $stmt->execute([$userId]);
        while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
            // Profile replication carries account data, never authentication/authority grants.
            if($table==='users')$row=array_intersect_key($row,array_flip(['id','email','display_name','avatar_path','created_at','updated_at','timezone','profile_slug','bio','address']));
            $row=workspace_sync_safe_v1($row);
            ksort($row);
            $encoded=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $records[]=['table'=>$table,'source_id'=>(string)($row['id']??hash('sha256',$encoded)),'data'=>$row];
        }
    }
    usort($records,static fn($a,$b)=>[$a['table'],$a['source_id']]<=>[$b['table'],$b['source_id']]);
    $q=$pdo->prepare('SELECT path,size_bytes,modified_at,sha256 FROM homeserver_workspace_assets_v1 WHERE user_id=? AND dataset=?');$q->execute([$userId,$dataset]);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
    $payload=['contract'=>VP3_WORKSPACE_SYNC_V1,'source'=>'cloud','dataset'=>$dataset,'records'=>$records,
      'available_tables'=>$available,'unavailable_tables'=>$unavailable,'files'=>workspace_sync_assets_v1($records,$prior)];
    $body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(strlen($body)>VP3_WORKSPACE_SYNC_MAX)throw new RuntimeException('Workspace dataset exceeds transfer capacity; existing replica preserved.');
    return ['body'=>$body,'revision'=>hash('sha256',$body),'byte_count'=>strlen($body),'record_count'=>count($records)];
}

function workspace_sync_store_v1(PDO $pdo,int $userId,string $source,string $dataset,array $snapshot): void
{
    $q=$pdo->prepare('SELECT revision FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source=? AND dataset=?');$q->execute([$userId,$source,$dataset]);
    if($q->fetchColumn()===$snapshot['revision']){
        $pdo->prepare('UPDATE homeserver_workspace_snapshots_v1 SET synced_at=? WHERE user_id=? AND source=? AND dataset=?')->execute([gmdate(DATE_ATOM),$userId,$source,$dataset]);return;
    }
    $values=[$userId,$source,$dataset,$snapshot['revision'],$snapshot['body'],$snapshot['byte_count'],$snapshot['record_count'],gmdate(DATE_ATOM)];
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
      ?'ON CONFLICT(user_id,source,dataset) DO UPDATE SET revision=excluded.revision,body_json=excluded.body_json,byte_count=excluded.byte_count,record_count=excluded.record_count,synced_at=excluded.synced_at'
      :'ON DUPLICATE KEY UPDATE revision=VALUES(revision),body_json=VALUES(body_json),byte_count=VALUES(byte_count),record_count=VALUES(record_count),synced_at=VALUES(synced_at)';
    $pdo->prepare('INSERT INTO homeserver_workspace_snapshots_v1(user_id,source,dataset,revision,body_json,byte_count,record_count,synced_at) VALUES (?,?,?,?,?,?,?,?) '.$suffix)->execute($values);
}

function workspace_sync_read_v1(PDO $pdo,int $userId,string $source,string $dataset): ?array
{
    $q=$pdo->prepare('SELECT * FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source=? AND dataset=?');
    $q->execute([$userId,$source,$dataset]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function workspace_sync_session_lock_v1(PDO $pdo,array $session): array
{
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?'.$lock);$q->execute([(int)$session['user_id']]);
    $owner=$q->fetch(PDO::FETCH_ASSOC);
    if(!$owner||(array_key_exists('is_active',$owner)&&!(bool)$owner['is_active']))throw new HomeServerHttpsSessionError('HomeServer account unavailable.',401);
    $q=$pdo->prepare('SELECT * FROM homeserver_https_sessions WHERE user_id=?'.$lock);$q->execute([(int)$session['user_id']]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||!hash_equals((string)$live['device_id'],(string)$session['device_id'])||
      !hash_equals((string)$live['session_token_hash'],(string)$session['session_token_hash']))
        throw new HomeServerHttpsSessionError('HomeServer HTTPS session is not authorized.',401);
    if($live['status']!=='active')throw new HomeServerHttpsSessionError('HomeServer HTTPS session was revoked.',410);
    return $live;
}

function workspace_sync_receive_v1(PDO $pdo,array $session,string $dataset,array $body): array
{
    $uid=(int)$session['user_id'];$revision=$body['revision']??null;$chunk=$body['chunk']??null;
    $offset=$body['offset']??null;$total=$body['total_bytes']??null;
    if(!is_string($revision)||!preg_match('/^[a-f0-9]{64}$/D',$revision)||!is_int($offset)||!is_int($total)||
      $offset<0||$offset%VP3_WORKSPACE_SYNC_CHUNK!==0||$total<1||$total>VP3_WORKSPACE_SYNC_MAX||$offset>=$total||!is_string($chunk))
        throw new InvalidArgumentException('Invalid workspace chunk manifest.');
    $decoded=base64_decode($chunk,true);
    if($decoded===false||strlen($decoded)!==min(VP3_WORKSPACE_SYNC_CHUNK,$total-$offset))
        throw new InvalidArgumentException('Invalid workspace chunk size.');
    $q=$pdo->prepare("SELECT revision FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source='homeserver' AND dataset=?");$q->execute([$uid,$dataset]);$existing=$q->fetch(PDO::FETCH_ASSOC);
    if($existing&&hash_equals($existing['revision'],$revision))return ['committed'=>true,'revision'=>$revision];
    $pdo->prepare('DELETE FROM homeserver_workspace_chunks_v1 WHERE user_id=? AND dataset=? AND (revision<>? OR session_hash<>?)')
      ->execute([$uid,$dataset,$revision,$session['session_token_hash']]);
    $suffix=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
      ?'ON CONFLICT(user_id,dataset,byte_offset) DO UPDATE SET revision=excluded.revision,chunk_base64=excluded.chunk_base64,total_bytes=excluded.total_bytes,session_hash=excluded.session_hash,updated_at=excluded.updated_at'
      :'ON DUPLICATE KEY UPDATE revision=VALUES(revision),chunk_base64=VALUES(chunk_base64),total_bytes=VALUES(total_bytes),session_hash=VALUES(session_hash),updated_at=VALUES(updated_at)';
    $pdo->prepare('INSERT INTO homeserver_workspace_chunks_v1(user_id,dataset,revision,byte_offset,chunk_base64,total_bytes,session_hash,updated_at) VALUES(?,?,?,?,?,?,?,?) '.$suffix)
      ->execute([$uid,$dataset,$revision,$offset,$chunk,$total,$session['session_token_hash'],gmdate(DATE_ATOM)]);
    $q=$pdo->prepare('SELECT COUNT(*) FROM homeserver_workspace_chunks_v1 WHERE user_id=? AND dataset=? AND revision=?');$q->execute([$uid,$dataset,$revision]);
    if((int)$q->fetchColumn()!==(int)ceil($total/VP3_WORKSPACE_SYNC_CHUNK))return ['committed'=>false,'revision'=>$revision];
    $q=$pdo->prepare('SELECT byte_offset,chunk_base64,total_bytes FROM homeserver_workspace_chunks_v1 WHERE user_id=? AND dataset=? AND revision=? ORDER BY byte_offset');
    $q->execute([$uid,$dataset,$revision]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==(int)ceil($total/VP3_WORKSPACE_SYNC_CHUNK))return ['committed'=>false,'revision'=>$revision];
    $full='';
    foreach($rows as $i=>$row){
        if((int)$row['byte_offset']!==$i*VP3_WORKSPACE_SYNC_CHUNK||(int)$row['total_bytes']!==$total)throw new InvalidArgumentException('Workspace chunk coverage is inconsistent.');
        $full.=base64_decode($row['chunk_base64'],true);
    }
    if(strlen($full)!==$total||!hash_equals(hash('sha256',$full),$revision))throw new InvalidArgumentException('Workspace checksum mismatch.');
    $payload=json_decode($full,true,512,JSON_THROW_ON_ERROR);
    if(($payload['contract']??'')!==VP3_WORKSPACE_SYNC_V1||($payload['source']??'')!=='homeserver'||($payload['dataset']??'')!==$dataset||!is_array($payload['records']??null)||!array_is_list($payload['records']))
        throw new InvalidArgumentException('Invalid workspace snapshot.');
    $seen=[];
    foreach($payload['records'] as $record){
        if(!is_array($record)||!is_string($record['table']??null)||!preg_match('/^[a-zA-Z0-9_]+$/D',$record['table'])||
          !is_string($record['source_id']??null)||strlen($record['source_id'])>190||!is_array($record['data']??null))
            throw new InvalidArgumentException('Invalid workspace record.');
        $key=$record['table'].':'.$record['source_id'];
        if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate workspace record.');
        $seen[$key]=true;
    }
    workspace_sync_store_v1($pdo,$uid,'homeserver',$dataset,['body'=>$full,'revision'=>$revision,'byte_count'=>$total,'record_count'=>count($payload['records'])]);
    $pdo->prepare('DELETE FROM homeserver_workspace_chunks_v1 WHERE user_id=? AND dataset=?')->execute([$uid,$dataset]);
    return ['committed'=>true,'revision'=>$revision];
}

function workspace_sync_exchange_v1(array $session,array $body): array
{
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    workspace_sync_schema_v1($pdo); // DDL before account lock, never implicitly commits it.
    // Full record reads and first-time large file hashing must not hold the
    // account authority lock used by HTTPS heartbeat, disconnect and pairing.
    $prepared=null;
    if(($body['action']??'')==='prepare'){
        $dataset=workspace_sync_dataset_v1($body['dataset']??null);
        $prepared=workspace_sync_export_v1($pdo,(int)$session['user_id'],$dataset);
    }
    $pdo->beginTransaction();
    try{
        $live=workspace_sync_session_lock_v1($pdo,$session);$uid=(int)$live['user_id'];
        $action=$body['action']??'';$out=['ok'=>true,'contract'=>VP3_WORKSPACE_SYNC_V1,'account_id'=>(string)$uid];
        if($action==='catalog'){
            $out['datasets']=array_keys(workspace_sync_registry_v1());
            $q=$pdo->prepare("SELECT dataset,revision,synced_at,record_count FROM homeserver_workspace_snapshots_v1 WHERE user_id=? AND source='homeserver'");$q->execute([$uid]);$out['homeserver']=$q->fetchAll(PDO::FETCH_ASSOC);
        }else{
            $dataset=workspace_sync_dataset_v1($body['dataset']??null);
            if($action==='prepare'){
                $snapshot=$prepared;
                workspace_sync_store_v1($pdo,$uid,'cloud',$dataset,$snapshot);
                $assets=json_decode($snapshot['body'],true,512,JSON_THROW_ON_ERROR)['files'];
                $pdo->prepare('DELETE FROM homeserver_workspace_assets_v1 WHERE user_id=? AND dataset=?')->execute([$uid,$dataset]);
                $insert=$pdo->prepare('INSERT INTO homeserver_workspace_assets_v1(user_id,dataset,asset_id,revision,path,name,sha256,size_bytes,modified_at,record_key,field) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                foreach($assets as $asset)$insert->execute([$uid,$dataset,$asset['asset_id'],$snapshot['revision'],$asset['path'],$asset['name'],$asset['sha256'],$asset['size_bytes'],$asset['modified_at'],$asset['record_key'],$asset['field']]);
                unset($snapshot['body']);$out+=$snapshot;
            }elseif($action==='pull'){
                $snapshot=workspace_sync_read_v1($pdo,$uid,'cloud',$dataset);
                if(!$snapshot||!is_string($body['revision']??null)||!hash_equals($snapshot['revision'],$body['revision']))throw new RuntimeException('Workspace export changed; retry this dataset.',409);
                $offset=$body['offset']??null;$total=(int)$snapshot['byte_count'];
                if(!is_int($offset)||$offset<0||$offset%VP3_WORKSPACE_SYNC_CHUNK!==0||$offset>=$total)throw new InvalidArgumentException('Invalid workspace offset.');
                $out+=['revision'=>$snapshot['revision'],'offset'=>$offset,'total_bytes'=>$total,'chunk'=>base64_encode(substr($snapshot['body_json'],$offset,VP3_WORKSPACE_SYNC_CHUNK))];
            }elseif($action==='asset')$out+=workspace_sync_asset_chunk_v1($pdo,$uid,$dataset,$body);
            elseif($action==='push')$out+=workspace_sync_receive_v1($pdo,$live,$dataset,$body);
            else throw new InvalidArgumentException('Unknown workspace sync action.');
        }
        $pdo->commit();return $out;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
