<?php
declare(strict_types=1);

/**
 * ML1 metadata. Installation is upgrade-only; reads and catalog transactions never
 * execute DDL. Existing catalog/Player identities remain authoritative mappings.
 * Credits and identifiers here are assertions, not financial or legal clearance.
 */
const VP3_MUSIC_SONG_METADATA_V1 = 'music-song-metadata-v1-20261126';

function music_song_metadata_v1_indexes(): array
{
    return [
        'music_parties_v1'=>['music_parties_workspace_name_v1'=>['workspace_id','name','party_id']],
        'music_works_v1'=>['music_works_workspace_title_v1'=>['workspace_id','title','work_id']],
        'music_work_revisions_v1'=>['music_work_revisions_workspace_v1'=>['workspace_id','work_id','revision']],
        'music_recordings_v1'=>['music_recordings_workspace_work_v1'=>['workspace_id','work_id']],
        'music_assets_v1'=>['music_assets_workspace_recording_v1'=>['workspace_id','recording_id']],
        'music_metadata_revisions_v1'=>['music_metadata_revisions_workspace_v1'=>['workspace_id','recording_id','revision']],
    ];
}

function music_song_metadata_v1_schema_statements(string $driver='mysql'): array
{
    if(!in_array($driver,['mysql','sqlite'],true))throw new RuntimeException('Unsupported music metadata database.');
    $engine=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $text=$driver==='mysql'?'LONGTEXT':'TEXT';
    $tables=[
        "CREATE TABLE IF NOT EXISTS music_parties_v1 (
            party_id CHAR(36) NOT NULL PRIMARY KEY, workspace_id BIGINT NOT NULL,
            name VARCHAR(190) NOT NULL, ipi VARCHAR(32) NOT NULL DEFAULT '', pro VARCHAR(120) NOT NULL DEFAULT '',
            created_by_user_id BIGINT NULL, created_at DATETIME NOT NULL,
            UNIQUE (workspace_id,party_id)
        )",
        "CREATE TABLE IF NOT EXISTS music_works_v1 (
            work_id CHAR(36) NOT NULL PRIMARY KEY, workspace_id BIGINT NOT NULL,
            title VARCHAR(190) NOT NULL, revision INTEGER NOT NULL, metadata_json {$text} NOT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE (workspace_id,work_id)
        )",
        "CREATE TABLE IF NOT EXISTS music_work_revisions_v1 (
            work_id CHAR(36) NOT NULL, revision INTEGER NOT NULL, workspace_id BIGINT NOT NULL,
            actor_user_id BIGINT NULL, provenance VARCHAR(32) NOT NULL, metadata_json {$text} NOT NULL,
            created_at DATETIME NOT NULL, PRIMARY KEY (work_id,revision)
        )",
        "CREATE TABLE IF NOT EXISTS music_recordings_v1 (
            recording_id CHAR(36) NOT NULL PRIMARY KEY, workspace_id BIGINT NOT NULL,
            catalog_track_id BIGINT NOT NULL, source_track_id BIGINT NULL, work_id CHAR(36) NOT NULL,
            current_asset_id CHAR(36) NULL, revision INTEGER NOT NULL, metadata_json {$text} NOT NULL,
            archived_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE (workspace_id,catalog_track_id), UNIQUE (catalog_track_id)
        )",
        "CREATE TABLE IF NOT EXISTS music_assets_v1 (
            asset_id CHAR(36) NOT NULL PRIMARY KEY, workspace_id BIGINT NOT NULL, recording_id CHAR(36) NOT NULL,
            stored_path TEXT NOT NULL, source_fingerprint CHAR(64) NOT NULL, sha256 CHAR(64) NULL,
            byte_size BIGINT NULL, created_at DATETIME NOT NULL,
            UNIQUE (recording_id,source_fingerprint)
        )",
        "CREATE TABLE IF NOT EXISTS music_metadata_revisions_v1 (
            recording_id CHAR(36) NOT NULL, revision INTEGER NOT NULL, workspace_id BIGINT NOT NULL,
            actor_user_id BIGINT NULL, provenance VARCHAR(32) NOT NULL, action VARCHAR(24) NOT NULL,
            snapshot_json {$text} NOT NULL, created_at DATETIME NOT NULL,
            PRIMARY KEY (recording_id,revision)
        )",
        "CREATE TABLE IF NOT EXISTS music_metadata_migration_issues_v1 (
            issue_key VARCHAR(96) NOT NULL PRIMARY KEY, entity_type VARCHAR(24) NOT NULL,
            entity_id BIGINT NOT NULL, reason VARCHAR(64) NOT NULL, details_json {$text} NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'open', created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
        )",
        "CREATE TABLE IF NOT EXISTS music_catalog_requests_v1 (
            workspace_id BIGINT NOT NULL, user_id BIGINT NOT NULL, request_key VARCHAR(64) NOT NULL,
            payload_hash CHAR(64) NOT NULL, catalog_track_id BIGINT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (workspace_id,user_id,request_key)
        )",
    ];
    $indexes=music_song_metadata_v1_indexes();$statements=[];
    foreach($tables as $sql){
        if($driver==='mysql'){
            preg_match('/CREATE TABLE IF NOT EXISTS ([a-z0-9_]+)/',$sql,$match);
            foreach($indexes[$match[1]]??[] as $name=>$columns){
                $last=strrpos($sql,')');
                $sql=substr($sql,0,$last).', INDEX '.$name.' ('.implode(',',$columns).')'.substr($sql,$last);
            }
        }
        $statements[]=$sql.$engine;
    }
    if($driver==='sqlite')foreach($indexes as $table=>$tableIndexes)foreach($tableIndexes as $name=>$columns){
        $statements[]='CREATE INDEX IF NOT EXISTS '.$name.' ON '.$table.' ('.implode(',',$columns).')';
    }
    return $statements;
}

function music_song_metadata_v1_columns(PDO $pdo,string $table): array
{
    if(!preg_match('/\A[a-z][a-z0-9_]*\z/',$table))throw new LogicException('Invalid metadata table.');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
        return $pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_COLUMN,1)?:[];
    }
    $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return $q->fetchAll(PDO::FETCH_COLUMN)?:[];
}

function music_song_metadata_v1_schema_ready(PDO $pdo): bool
{
    $required=[
        'music_parties_v1'=>['party_id','workspace_id','name','ipi','pro'],
        'music_works_v1'=>['work_id','workspace_id','revision','metadata_json'],
        'music_work_revisions_v1'=>['work_id','revision','workspace_id','metadata_json'],
        'music_recordings_v1'=>['recording_id','workspace_id','catalog_track_id','source_track_id','work_id','current_asset_id','revision','metadata_json','archived_at'],
        'music_assets_v1'=>['asset_id','workspace_id','recording_id','stored_path','source_fingerprint'],
        'music_metadata_revisions_v1'=>['recording_id','revision','snapshot_json','provenance','action'],
        'music_metadata_migration_issues_v1'=>['issue_key','entity_type','entity_id','reason','details_json','status'],
        'music_catalog_requests_v1'=>['workspace_id','user_id','request_key','payload_hash','catalog_track_id','created_at'],
    ];
    try{
        $placeholders=implode(',',array_fill(0,count($required),'?'));
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
            $sql="SELECT m.name AS table_name,p.name AS column_name FROM sqlite_master m JOIN pragma_table_info(m.name) p WHERE m.type='table' AND m.name IN ({$placeholders})";
        }else{
            $sql="SELECT TABLE_NAME AS table_name,COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})";
        }
        $q=$pdo->prepare($sql);$q->execute(array_keys($required));$found=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $column)$found[$column['table_name']][]=$column['column_name'];
        foreach($required as $table=>$columns)if(array_diff($columns,$found[$table]??[]))return false;
    }
    catch(Throwable $e){return false;}
    return true;
}

function music_song_metadata_v1_require_schema(PDO $pdo): void
{
    if(!music_song_metadata_v1_schema_ready($pdo))throw new RuntimeException('Music metadata database upgrade is required.',503);
}

function music_song_metadata_v1_uuid(): string
{
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $hex=bin2hex($bytes);return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}

function music_song_metadata_v1_id(mixed $value,string $label,bool $optional=true): string
{
    if(!is_string($value))throw new RuntimeException('Choose a valid '.$label.'.',422);
    $value=strtolower(trim($value));
    if($optional&&$value==='')return '';
    if(!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',$value))throw new RuntimeException('Choose a valid '.$label.'.',422);
    return $value;
}

function music_song_metadata_v1_text(mixed $value,int $limit,string $label): string
{
    if(!is_string($value))throw new RuntimeException($label.' must be text.',422);
    $value=trim($value);
    if(str_contains($value,"\0")||!mb_check_encoding($value,'UTF-8')||mb_strlen($value)>$limit)throw new RuntimeException($label.' is too long or invalid.',422);
    return $value;
}

function music_song_metadata_v1_integer(mixed $value,string $label): int
{
    if(!is_int($value)&&!(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/',$value)))throw new RuntimeException('Choose a valid '.$label.'.',422);
    if((int)$value<0||(int)$value>2147483647)throw new RuntimeException('Choose a valid '.$label.'.',422);
    return (int)$value;
}

function music_song_metadata_v1_json(array $value): string
{
    return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

function music_song_metadata_v1_decode(string $value): array
{
    $decoded=json_decode($value,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($decoded))throw new RuntimeException('Stored music metadata is unavailable.');
    return $decoded;
}

function music_song_metadata_v1_require_manager(PDO $pdo,int $workspaceId,array $user): void
{
    if($workspaceId<1||(int)($user['id']??0)<1||!function_exists('music_workspace_resources_v330_can_manage')
        ||!music_workspace_resources_v330_can_manage($pdo,$workspaceId,'tracks',$user)){
        throw new RuntimeException('Music metadata management is unavailable for this workspace.',403);
    }
}

function music_song_metadata_v1_require_transaction(PDO $pdo): void
{
    if(!$pdo->inTransaction())throw new LogicException('Music metadata writes require the catalog transaction.');
}

/** Internal accessor: the calling writer must already have checked authority. */
function music_song_metadata_v1_row(PDO $pdo,int $workspaceId,int $catalogId,bool $lock=false): ?array
{
    if($workspaceId<1||$catalogId<1)return null;
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM music_recordings_v1 WHERE workspace_id=? AND catalog_track_id=?'.$suffix);
    $q->execute([$workspaceId,$catalogId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function music_song_metadata_v1_catalog(PDO $pdo,int $workspaceId,int $catalogId): ?array
{
    $q=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE workspace_id=? AND id=?');
    $q->execute([$workspaceId,$catalogId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function music_song_metadata_v1_defaults(array $track=[]): array
{
    return ['recording_version'=>'','isrc'=>'','isrc_status'=>'unknown','language'=>'','explicit_content'=>'unknown',
        'work_id'=>'','work_revision'=>0,'work_title'=>(string)($track['title']??''),'iswc'=>'','iswc_status'=>'unknown',
        'writers'=>[],'publishers'=>[],'performers'=>[],'label'=>['party_id'=>'','name'=>''],
        'copyright_notice'=>'','phonogram_notice'=>'','rights_notes'=>'','contact_email'=>''];
}

function music_song_metadata_v1_identifier(mixed $raw,mixed $status,string $kind): array
{
    $status=music_song_metadata_v1_text($status,20,$kind.' status');
    if(!in_array($status,['unknown','unassigned','provided'],true))throw new RuntimeException('Choose unknown, unassigned or provided for '.$kind.'.',422);
    $value=music_song_metadata_v1_text($raw,40,$kind);
    $value=strtoupper(preg_replace('/[\s.\-]+/u','',$value)??'');
    if($status!=='provided'){
        if($value!=='')throw new RuntimeException('Mark '.$kind.' as provided to save its value.',422);
        return ['', $status];
    }
    $pattern=$kind==='ISRC'?'/\A[A-Z]{2}[A-Z0-9]{3}[0-9]{7}\z/':'/\AT[0-9]{10}\z/';
    if(!preg_match($pattern,$value))throw new RuntimeException($kind.' format is invalid. This checks format only, not registration or ownership.',422);
    return [$value,$status];
}

function music_song_metadata_v1_party_row(PDO $pdo,int $workspaceId,string $partyId): ?array
{
    $q=$pdo->prepare('SELECT party_id,name,ipi,pro FROM music_parties_v1 WHERE workspace_id=? AND party_id=?');
    $q->execute([$workspaceId,$partyId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Party records are reusable immutable facts in ML1; selecting one cannot rename it. */
function music_song_metadata_v1_party(PDO $pdo,int $workspaceId,int $actorId,array $input,bool $label=false): array
{
    $allowed=$label?['party_id','name']:['party_id','name','ipi','pro','role'];
    if(array_diff(array_keys($input),$allowed))throw new RuntimeException('Unsupported credit fields. Financial shares are not part of song metadata.',422);
    $id=music_song_metadata_v1_id($input['party_id']??'','party');
    $name=music_song_metadata_v1_text($input['name']??'',190,'Credit name');
    $ipi=$label?'':music_song_metadata_v1_text($input['ipi']??'',32,'IPI/CAE');
    $pro=$label?'':music_song_metadata_v1_text($input['pro']??'',120,'PRO/CMO');
    $role=$label?'':music_song_metadata_v1_text($input['role']??'',100,'Contribution role');
    if($id!==''){
        $row=music_song_metadata_v1_party_row($pdo,$workspaceId,$id);
        if(!$row)throw new RuntimeException('Choose a party from this Music Workspace.',404);
        foreach($label?['name'=>$name]:['name'=>$name,'ipi'=>$ipi,'pro'=>$pro] as $key=>$value){
            if(array_key_exists($key,$input)&&$value!==''&&!hash_equals((string)$row[$key],$value))throw new RuntimeException('This saved party has different details. Select its current details or create a new party.',409);
        }
        return $label?['party_id'=>$id,'name'=>(string)$row['name']]:$row+['role'=>$role];
    }
    if($name===''){
        if($label)return ['party_id'=>'','name'=>''];
        throw new RuntimeException('Enter a name for each credit or remove the empty row.',422);
    }
    $id=music_song_metadata_v1_uuid();
    $q=$pdo->prepare('INSERT INTO music_parties_v1 (party_id,workspace_id,name,ipi,pro,created_by_user_id,created_at) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$id,$workspaceId,$name,$ipi,$pro,$actorId?:null,gmdate('Y-m-d H:i:s')]);
    return $label?['party_id'=>$id,'name'=>$name]:['party_id'=>$id,'name'=>$name,'ipi'=>$ipi,'pro'=>$pro,'role'=>$role];
}

function music_song_metadata_v1_credit_list(PDO $pdo,int $workspaceId,int $actorId,mixed $value,string $label): array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>50)throw new RuntimeException('Use a list of up to 50 '.$label.'.',422);
    $out=[];$seen=[];
    foreach($value as $item){
        if(!is_array($item)||array_is_list($item))throw new RuntimeException('Choose valid '.$label.'.',422);
        $party=music_song_metadata_v1_party($pdo,$workspaceId,$actorId,$item);
        $key=$party['party_id'].'|'.$party['role'];
        if(isset($seen[$key]))throw new RuntimeException('The same party and role appear twice in '.$label.'.',422);
        $seen[$key]=true;$out[]=$party;
    }
    return $out;
}

function music_song_metadata_v1_work_row(PDO $pdo,int $workspaceId,string $workId,bool $lock=false): ?array
{
    $suffix=$lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $q=$pdo->prepare('SELECT * FROM music_works_v1 WHERE workspace_id=? AND work_id=?'.$suffix);
    $q->execute([$workspaceId,$workId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}

function music_song_metadata_v1_work_snapshot(PDO $pdo,array $row,int $actorId,string $provenance): void
{
    $q=$pdo->prepare('INSERT INTO music_work_revisions_v1 (work_id,revision,workspace_id,actor_user_id,provenance,metadata_json,created_at) VALUES (?,?,?,?,?,?,?)');
    $q->execute([$row['work_id'],(int)$row['revision'],(int)$row['workspace_id'],$actorId?:null,$provenance,$row['metadata_json'],gmdate('Y-m-d H:i:s')]);
}

function music_song_metadata_v1_work_create(PDO $pdo,int $workspaceId,array $metadata,int $actorId,string $provenance): array
{
    $now=gmdate('Y-m-d H:i:s');
    $row=['work_id'=>music_song_metadata_v1_uuid(),'workspace_id'=>$workspaceId,'title'=>$metadata['work_title'],
        'revision'=>1,'metadata_json'=>music_song_metadata_v1_json($metadata),'created_at'=>$now,'updated_at'=>$now];
    $pdo->prepare('INSERT INTO music_works_v1 (work_id,workspace_id,title,revision,metadata_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')->execute(array_values($row));
    music_song_metadata_v1_work_snapshot($pdo,$row,$actorId,$provenance);return $row;
}

function music_song_metadata_v1_work_fields(array $metadata): array
{
    return array_intersect_key($metadata,array_flip(['work_title','iswc','iswc_status','writers','publishers']));
}

/** Resolve a canonical work. Shared works are selected, never silently rewritten. */
function music_song_metadata_v1_resolve_work(PDO $pdo,int $workspaceId,int $actorId,array $input,?array $recording,array $catalog): array
{
    $id=music_song_metadata_v1_id($input['work_id']??'','composition');
    if($id===''&&$recording)$id=(string)$recording['work_id'];
    $work=$id!==''?music_song_metadata_v1_work_row($pdo,$workspaceId,$id,true):null;
    if($id!==''&&!$work)throw new RuntimeException('Choose a composition from this Music Workspace.',404);
    // Linking a recording to another work reuses that work's canonical details.
    if($work&&(!$recording||$id!==$recording['work_id']))return $work;
    $defaults=music_song_metadata_v1_defaults($catalog);
    [$iswc,$iswcStatus]=music_song_metadata_v1_identifier($input['iswc']??$defaults['iswc'],$input['iswc_status']??$defaults['iswc_status'],'ISWC');
    $candidate=['work_title'=>music_song_metadata_v1_text($input['work_title']??$defaults['work_title'],190,'Composition title'),
        'iswc'=>$iswc,'iswc_status'=>$iswcStatus,
        'writers'=>music_song_metadata_v1_credit_list($pdo,$workspaceId,$actorId,$input['writers']??[],'writers'),
        'publishers'=>music_song_metadata_v1_credit_list($pdo,$workspaceId,$actorId,$input['publishers']??[],'publishers')];
    if(!$work)return music_song_metadata_v1_work_create($pdo,$workspaceId,$candidate,$actorId,'user_entered');
    $prior=music_song_metadata_v1_decode($work['metadata_json']);
    if(music_song_metadata_v1_json($candidate)===music_song_metadata_v1_json($prior))return $work;
    $expected=music_song_metadata_v1_integer($input['work_revision']??null,'composition revision');
    if($expected!==(int)$work['revision'])throw new RuntimeException('The composition changed. Reload its current details.',409);
    $q=$pdo->prepare('SELECT COUNT(*) FROM music_recordings_v1 WHERE workspace_id=? AND work_id=? AND recording_id<>?');
    $q->execute([$workspaceId,$id,$recording['recording_id']]);
    if((int)$q->fetchColumn()>0)throw new RuntimeException('This composition is shared by other recordings. Keep its canonical details; shared composition editing is a separate operation.',409);
    $work['revision']=(int)$work['revision']+1;$work['metadata_json']=music_song_metadata_v1_json($candidate);$work['title']=$candidate['work_title'];$work['updated_at']=gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE music_works_v1 SET title=?,revision=?,metadata_json=?,updated_at=? WHERE workspace_id=? AND work_id=?')->execute([$work['title'],$work['revision'],$work['metadata_json'],$work['updated_at'],$workspaceId,$id]);
    music_song_metadata_v1_work_snapshot($pdo,$work,$actorId,'user_entered');return $work;
}

function music_song_metadata_v1_asset(PDO $pdo,int $workspaceId,string $recordingId,array $catalog): ?string
{
    $stored=(string)($catalog['audio_path']??'');if($stored==='')return null;
    // Uploads have new immutable paths. Reusing an existing path keeps its identity.
    $q=$pdo->prepare('SELECT asset_id FROM music_assets_v1 WHERE workspace_id=? AND recording_id=? AND stored_path=? ORDER BY created_at,asset_id LIMIT 1');
    $q->execute([$workspaceId,$recordingId,$stored]);$existing=$q->fetchColumn();if($existing)return (string)$existing;
    $path=null;
    if(function_exists('artist_music_v185_resolve_audio'))$path=artist_music_v185_resolve_audio($pdo,$catalog);
    $hash=null;$size=null;
    if($path&&is_file($path)&&is_readable($path)){$h=hash_file('sha256',$path);$s=filesize($path);$hash=is_string($h)?$h:null;$size=$s===false?null:$s;}
    $id=music_song_metadata_v1_uuid();$fingerprint=hash('sha256',music_song_metadata_v1_json(['path'=>$stored,'sha256'=>$hash,'bytes'=>$size]));
    $pdo->prepare('INSERT INTO music_assets_v1 (asset_id,workspace_id,recording_id,stored_path,source_fingerprint,sha256,byte_size,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$workspaceId,$recordingId,$stored,$fingerprint,$hash,$size,gmdate('Y-m-d H:i:s')]);
    return $id;
}

function music_song_metadata_v1_snapshot(PDO $pdo,array $row,array $catalog,int $actorId,string $provenance,string $action): void
{
    $work=music_song_metadata_v1_work_row($pdo,(int)$row['workspace_id'],(string)$row['work_id']);
    if(!$work)throw new RuntimeException('The composition record is unavailable.');
    $snapshot=['recording_id'=>$row['recording_id'],'workspace_id'=>(int)$row['workspace_id'],'catalog_track_id'=>(int)$row['catalog_track_id'],
        'source_track_id'=>$row['source_track_id']===null?null:(int)$row['source_track_id'],'work_id'=>$row['work_id'],
        'work_revision'=>(int)$work['revision'],'asset_id'=>$row['current_asset_id'],'archived_at'=>$row['archived_at'],
        'catalog'=>array_intersect_key($catalog,array_flip(['title','album','album_id','description','genre','duration_seconds','visibility','is_published'])),
        'metadata'=>music_song_metadata_v1_decode($row['metadata_json'])];
    $pdo->prepare('INSERT INTO music_metadata_revisions_v1 (recording_id,revision,workspace_id,actor_user_id,provenance,action,snapshot_json,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$row['recording_id'],(int)$row['revision'],(int)$row['workspace_id'],$actorId?:null,$provenance,$action,music_song_metadata_v1_json($snapshot),gmdate('Y-m-d H:i:s')]);
}

/** Called only from the shared catalog writer, after its catalog/backing update. */
function music_song_metadata_v1_save(PDO $pdo,int $workspaceId,array $user,array $catalogTrack,array $input): array
{
    music_song_metadata_v1_require_schema($pdo);music_song_metadata_v1_require_transaction($pdo);
    music_song_metadata_v1_require_manager($pdo,$workspaceId,$user);
    $catalogId=(int)($catalogTrack['id']??0);$catalog=music_song_metadata_v1_catalog($pdo,$workspaceId,$catalogId);
    if(!$catalog)throw new RuntimeException('The recording is unavailable in this workspace.',404);
    $mapping=music_song_metadata_v1_mapping_issue($pdo,$catalog);
    if($mapping)throw new RuntimeException('The catalog and Player mapping requires review before editing metadata.',409);
    $row=music_song_metadata_v1_row($pdo,$workspaceId,$catalogId,true);
    if($row&&(int)($row['source_track_id']??0)>0&&(int)$row['source_track_id']!==(int)($catalog['source_track_id']??0)){
        throw new RuntimeException('The recording and Player mapping changed. Review the existing identities before editing metadata.',409);
    }
    if($row&&!empty($row['archived_at']))throw new RuntimeException('Restore this archived recording before editing it.',409);
    $explicit=array_key_exists('music_metadata',$input);
    if($explicit){
        if(!is_array($input['music_metadata'])||array_is_list($input['music_metadata']))throw new RuntimeException('Song metadata must be an object.',422);
        $expected=music_song_metadata_v1_integer($input['metadata_revision']??null,'metadata revision');
        if($expected!==(int)($row['revision']??0))throw new RuntimeException('The song metadata changed. Reload before saving.',409);
    }elseif(array_key_exists('metadata_revision',$input)){
        $expected=music_song_metadata_v1_integer($input['metadata_revision'],'metadata revision');
        if($expected!==(int)($row['revision']??0))throw new RuntimeException('The song metadata changed. Reload before saving.',409);
    }
    $actorId=(int)$user['id'];$defaults=music_song_metadata_v1_defaults($catalog);
    if(!$explicit&&$row){
        $metadata=music_song_metadata_v1_decode($row['metadata_json']);
        $work=music_song_metadata_v1_work_row($pdo,$workspaceId,(string)$row['work_id'],true);
        if(!$work)throw new RuntimeException('The composition record is unavailable.');
        $metadata=array_replace($metadata,music_song_metadata_v1_decode($work['metadata_json']),['work_id'=>$work['work_id'],'work_revision'=>(int)$work['revision']]);
    }else{
        $raw=$explicit?$input['music_metadata']:$defaults;
        if(array_diff(array_keys($raw),array_keys($defaults)))throw new RuntimeException('Unsupported song metadata fields. Rates, shares and payment instructions require a separate agreement workflow.',422);
        $raw=array_replace($defaults,$raw);
        [$isrc,$isrcStatus]=music_song_metadata_v1_identifier($raw['isrc'],$raw['isrc_status'],'ISRC');
        $explicitContent=music_song_metadata_v1_text($raw['explicit_content'],12,'Explicit-content status');
        if(!in_array($explicitContent,['unknown','yes','no'],true))throw new RuntimeException('Choose unknown, yes or no for explicit content.',422);
        $email=music_song_metadata_v1_text($raw['contact_email'],254,'Private contact email');
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid private contact email.',422);
        if(!is_array($raw['label'])||(array_is_list($raw['label'])&&$raw['label']!==[]))throw new RuntimeException('Choose a valid label.',422);
        $work=music_song_metadata_v1_resolve_work($pdo,$workspaceId,$actorId,$raw,$row,$catalog);
        $metadata=['recording_version'=>music_song_metadata_v1_text($raw['recording_version'],120,'Recording version'),
            'isrc'=>$isrc,'isrc_status'=>$isrcStatus,'language'=>music_song_metadata_v1_text($raw['language'],40,'Language'),
            'explicit_content'=>$explicitContent,'work_id'=>$work['work_id'],'work_revision'=>(int)$work['revision']];
        $metadata+=music_song_metadata_v1_decode($work['metadata_json']);
        $metadata+=['performers'=>music_song_metadata_v1_credit_list($pdo,$workspaceId,$actorId,$raw['performers'],'performers'),
            'label'=>music_song_metadata_v1_party($pdo,$workspaceId,$actorId,$raw['label'],true),
            'copyright_notice'=>music_song_metadata_v1_text($raw['copyright_notice'],500,'Composition copyright notice'),
            'phonogram_notice'=>music_song_metadata_v1_text($raw['phonogram_notice'],500,'Recording copyright notice'),
            'rights_notes'=>music_song_metadata_v1_text($raw['rights_notes'],10000,'Private rights notes'),'contact_email'=>$email];
    }
    $now=gmdate('Y-m-d H:i:s');$recordingId=$row?(string)$row['recording_id']:music_song_metadata_v1_uuid();
    $assetId=music_song_metadata_v1_asset($pdo,$workspaceId,$recordingId,$catalog);
    $values=['recording_id'=>$recordingId,'workspace_id'=>$workspaceId,'catalog_track_id'=>$catalogId,
        'source_track_id'=>(int)($catalog['source_track_id']??0)?:null,'work_id'=>$work['work_id'],'current_asset_id'=>$assetId,
        'revision'=>(int)($row['revision']??0)+1,'metadata_json'=>music_song_metadata_v1_json($metadata),
        'archived_at'=>null,'created_at'=>$row['created_at']??$now,'updated_at'=>$now];
    if($row){
        $pdo->prepare('UPDATE music_recordings_v1 SET source_track_id=?,work_id=?,current_asset_id=?,revision=?,metadata_json=?,updated_at=? WHERE workspace_id=? AND catalog_track_id=?')->execute([$values['source_track_id'],$values['work_id'],$assetId,$values['revision'],$values['metadata_json'],$now,$workspaceId,$catalogId]);
    }else{
        $pdo->prepare('INSERT INTO music_recordings_v1 (recording_id,workspace_id,catalog_track_id,source_track_id,work_id,current_asset_id,revision,metadata_json,archived_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($values));
    }
    music_song_metadata_v1_snapshot($pdo,$values,$catalog,$actorId,'user_entered',$row?'update':'create');
    return music_song_metadata_v1_envelope($pdo,$values,$catalog);
}

function music_song_metadata_v1_public_projection(array $row,array $catalog,array $metadata): array
{
    $credits=static function(array $rows):array{return array_map(static fn(array $party):array=>['name'=>(string)$party['name'],'role'=>(string)($party['role']??'')],$rows);};
    return ['recording_id'=>$row['recording_id'],'work_id'=>$row['work_id'],'title'=>(string)($catalog['title']??''),
        'album'=>(string)($catalog['album']??''),'recording_version'=>(string)($metadata['recording_version']??''),
        'isrc'=>(string)($metadata['isrc']??''),'isrc_status'=>(string)($metadata['isrc_status']??'unknown'),
        'work_title'=>(string)($metadata['work_title']??''),'iswc'=>(string)($metadata['iswc']??''),
        'iswc_status'=>(string)($metadata['iswc_status']??'unknown'),'identifier_validation'=>'format_only',
        'language'=>(string)($metadata['language']??''),'explicit_content'=>(string)($metadata['explicit_content']??'unknown'),
        'writers'=>$credits($metadata['writers']??[]),'publishers'=>$credits($metadata['publishers']??[]),
        'performers'=>$credits($metadata['performers']??[]),'label'=>['name'=>(string)($metadata['label']['name']??'')],
        'copyright_notice'=>(string)($metadata['copyright_notice']??''),'phonogram_notice'=>(string)($metadata['phonogram_notice']??'')];
}

/** Null is an anonymous viewer, even when the process has a signed-in session. */
function music_song_metadata_v1_audience_allows(string $visibility,?array $viewer): bool
{
    if(!function_exists('valid_visibility')||!valid_visibility($visibility))return false;
    if($visibility==='public')return true;
    return $viewer&&(int)($viewer['id']??0)>0&&function_exists('can_view_visibility')&&can_view_visibility($visibility,$viewer);
}

function music_song_metadata_v1_envelope(PDO $pdo,array $row,array $catalog): array
{
    $metadata=music_song_metadata_v1_decode($row['metadata_json']);
    $work=music_song_metadata_v1_work_row($pdo,(int)$row['workspace_id'],(string)$row['work_id']);
    if(!$work)throw new RuntimeException('The composition record is unavailable.');
    $metadata=array_replace($metadata,music_song_metadata_v1_decode($work['metadata_json']),['work_id'=>$work['work_id'],'work_revision'=>(int)$work['revision']]);
    $archived=!empty($row['archived_at']);$hasTitle=trim((string)($catalog['title']??''))!=='';
    $path=function_exists('artist_music_v185_resolve_audio')?artist_music_v185_resolve_audio($pdo,$catalog):null;
    $hasAudio=$path&&is_file($path)&&is_readable($path)&&filesize($path)>0;
    $published=(int)($catalog['is_published']??0)===1;
    $mappingIssue=music_song_metadata_v1_mapping_issue($pdo,$catalog);
    if((int)($row['source_track_id']??0)!==(int)($catalog['source_track_id']??0))$mappingIssue='recording_backing_conflict';
    $visibility=(string)($catalog['visibility']??'members');
    $audienceValid=function_exists('valid_visibility')&&valid_visibility($visibility);
    $sourceId=(int)($catalog['source_track_id']??0);$backingPublished=true;$audienceMatches=true;
    if($sourceId>0){
        $q=$pdo->prepare('SELECT is_published,visibility FROM tracks WHERE id=?');$q->execute([$sourceId]);$backing=$q->fetch(PDO::FETCH_ASSOC);
        $backingPublished=$backing&&(int)$backing['is_published']===1;
        $audienceMatches=$backing&&(string)$backing['visibility']===$visibility;
    }
    $playbackState='ready';$playbackReasons=[];
    if($archived){$playbackState='archived';$playbackReasons=['Restore this recording as a draft.'];}
    elseif($mappingIssue){$playbackState='unavailable';$playbackReasons=['The catalog and Player mapping needs review.'];}
    elseif(!$hasAudio){$playbackState='unavailable';$playbackReasons=['Add or repair the listening audio.'];}
    elseif(!$published){$playbackState='draft';$playbackReasons=['This recording is a draft.'];}
    elseif(!$backingPublished){$playbackState='unavailable';$playbackReasons=['The Player recording is unpublished.'];}
    elseif(!$audienceValid||!$audienceMatches){$playbackState='unavailable';$playbackReasons=['Set matching, valid audiences for the catalog and Player recording.'];}
    $readiness=[
        'catalog'=>['state'=>$archived?'archived':($hasTitle?'ready':'draft'),'ready'=>!$archived&&$hasTitle,'reasons'=>$hasTitle?[]:['Add a recording title.']],
        'playback'=>['state'=>$playbackState,'ready'=>$playbackState==='ready','reasons'=>$playbackReasons],
        'earnings'=>['state'=>'not_configured','ready'=>false,'reasons'=>['Music earnings and their funding policy are not configured.']],
        'payout'=>['state'=>'not_configured','ready'=>false,'reasons'=>['Music payouts are not configured.']],
    ];
    return ['recording_id'=>$row['recording_id'],'work_id'=>$row['work_id'],'asset_id'=>$row['current_asset_id'],
        'revision'=>(int)$row['revision'],'work_revision'=>(int)$work['revision'],'archived'=>$archived,
        'metadata'=>$metadata,'readiness'=>$readiness,'public'=>music_song_metadata_v1_public_projection($row,$catalog,$metadata)];
}

function music_song_metadata_v1_get(PDO $pdo,int $workspaceId,int $catalogId,array $user): ?array
{
    music_song_metadata_v1_require_manager($pdo,$workspaceId,$user);music_song_metadata_v1_require_schema($pdo);
    $catalog=music_song_metadata_v1_catalog($pdo,$workspaceId,$catalogId);if(!$catalog)return null;
    $row=music_song_metadata_v1_row($pdo,$workspaceId,$catalogId);if(!$row)return null;
    return music_song_metadata_v1_envelope($pdo,$row,$catalog);
}

function music_song_metadata_v1_public(PDO $pdo,int $workspaceId,int $catalogId,?array $viewer): ?array
{
    if(!music_song_metadata_v1_schema_ready($pdo))return null;
    $catalog=music_song_metadata_v1_catalog($pdo,$workspaceId,$catalogId);
    if(!$catalog||(int)($catalog['is_published']??0)!==1
        ||!music_song_metadata_v1_audience_allows((string)($catalog['visibility']??'members'),$viewer)
        ||music_song_metadata_v1_mapping_issue($pdo,$catalog))return null;
    $source=(int)($catalog['source_track_id']??0);
    if($source>0){
        $q=$pdo->prepare('SELECT workspace_id,is_published,visibility FROM tracks WHERE id=?');$q->execute([$source]);$backing=$q->fetch(PDO::FETCH_ASSOC);
        if(!$backing||(int)($backing['workspace_id']??0)!==$workspaceId||(int)$backing['is_published']!==1
            ||!music_song_metadata_v1_audience_allows((string)$backing['visibility'],$viewer))return null;
    }
    $row=music_song_metadata_v1_row($pdo,$workspaceId,$catalogId);if(!$row||!empty($row['archived_at']))return null;
    if((int)($row['source_track_id']??0)!==$source)return null;
    $metadata=music_song_metadata_v1_decode($row['metadata_json']);
    $work=music_song_metadata_v1_work_row($pdo,$workspaceId,(string)$row['work_id']);if(!$work)return null;
    $metadata=array_replace($metadata,music_song_metadata_v1_decode($work['metadata_json']));
    return music_song_metadata_v1_public_projection($row,$catalog,$metadata);
}

function music_song_metadata_v1_parties(PDO $pdo,int $workspaceId,array $user,int $limit=200): array
{
    music_song_metadata_v1_require_manager($pdo,$workspaceId,$user);music_song_metadata_v1_require_schema($pdo);
    $q=$pdo->prepare('SELECT party_id,name,ipi,pro FROM music_parties_v1 WHERE workspace_id=? ORDER BY name,party_id LIMIT '.max(1,min(500,$limit)));
    $q->execute([$workspaceId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function music_song_metadata_v1_works(PDO $pdo,int $workspaceId,array $user,int $limit=200): array
{
    music_song_metadata_v1_require_manager($pdo,$workspaceId,$user);music_song_metadata_v1_require_schema($pdo);
    $q=$pdo->prepare('SELECT work_id,revision,metadata_json FROM music_works_v1 WHERE workspace_id=? ORDER BY title,work_id LIMIT '.max(1,min(500,$limit)));
    $q->execute([$workspaceId]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[]=['work_id'=>$row['work_id'],'work_revision'=>(int)$row['revision']]+music_song_metadata_v1_decode($row['metadata_json']);
    return $out;
}

function music_song_metadata_v1_archive_state(PDO $pdo,int $workspaceId,int $catalogId,array $user,bool $archive): void
{
    music_song_metadata_v1_require_manager($pdo,$workspaceId,$user);music_song_metadata_v1_require_schema($pdo);music_song_metadata_v1_require_transaction($pdo);
    $catalog=music_song_metadata_v1_catalog($pdo,$workspaceId,$catalogId);$row=music_song_metadata_v1_row($pdo,$workspaceId,$catalogId,true);
    if(!$catalog||!$row)throw new RuntimeException('The recording is unavailable in this workspace.',404);
    if(music_song_metadata_v1_mapping_issue($pdo,$catalog)||(int)($row['source_track_id']??0)!==(int)($catalog['source_track_id']??0)){
        throw new RuntimeException('The catalog and Player mapping requires review before archiving or restoring this recording.',409);
    }
    if((!empty($row['archived_at']))===$archive)return;
    $row['archived_at']=$archive?gmdate('Y-m-d H:i:s'):null;$row['revision']=(int)$row['revision']+1;$row['updated_at']=gmdate('Y-m-d H:i:s');
    $pdo->prepare('UPDATE music_recordings_v1 SET archived_at=?,revision=?,updated_at=? WHERE workspace_id=? AND catalog_track_id=?')->execute([$row['archived_at'],$row['revision'],$row['updated_at'],$workspaceId,$catalogId]);
    // Restore never republishes a song. Retained asset bytes and catalog IDs are untouched.
    $pdo->prepare('UPDATE artist_catalog_tracks_v181 SET is_published=0 WHERE workspace_id=? AND id=?')->execute([$workspaceId,$catalogId]);
    if((int)($catalog['source_track_id']??0)>0)$pdo->prepare('UPDATE tracks SET is_published=0 WHERE workspace_id=? AND id=?')->execute([$workspaceId,(int)$catalog['source_track_id']]);
    $catalog['is_published']=0;music_song_metadata_v1_snapshot($pdo,$row,$catalog,(int)$user['id'],'user_entered',$archive?'archive':'restore');
}

function music_song_metadata_v1_archive(PDO $pdo,int $workspaceId,int $catalogId,array $user): void
{
    music_song_metadata_v1_archive_state($pdo,$workspaceId,$catalogId,$user,true);
}

function music_song_metadata_v1_restore(PDO $pdo,int $workspaceId,int $catalogId,array $user): void
{
    music_song_metadata_v1_archive_state($pdo,$workspaceId,$catalogId,$user,false);
}

/** Returns a review reason; never infers a workspace from an uploader/artist label. */
function music_song_metadata_v1_mapping_issue(PDO $pdo,array $catalog): ?string
{
    $workspaceId=(int)($catalog['workspace_id']??0);
    if($workspaceId<1)return 'catalog_workspace_unassigned';
    $q=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?');$q->execute([$workspaceId]);
    if(!$q->fetchColumn())return 'catalog_workspace_missing';
    $sourceId=(int)($catalog['source_track_id']??0);if($sourceId<1)return null;
    if(!in_array('workspace_id',music_song_metadata_v1_columns($pdo,'tracks'),true))return 'backing_workspace_schema_missing';
    $q=$pdo->prepare('SELECT workspace_id FROM tracks WHERE id=?');$q->execute([$sourceId]);$source=$q->fetch(PDO::FETCH_ASSOC);
    if(!$source)return 'backing_track_missing';
    if((int)($source['workspace_id']??0)!==$workspaceId)return 'backing_workspace_conflict';
    $q=$pdo->prepare('SELECT COUNT(*) FROM artist_catalog_tracks_v181 WHERE source_track_id=?');$q->execute([$sourceId]);
    if((int)$q->fetchColumn()!==1)return 'backing_multiple_catalog_links';
    return null;
}

function music_song_metadata_v1_migration_issue(PDO $pdo,string $type,int $id,string $reason,array $details): void
{
    $key=$type.':'.$id;$now=gmdate('Y-m-d H:i:s');
    $q=$pdo->prepare('SELECT issue_key FROM music_metadata_migration_issues_v1 WHERE issue_key=?');$q->execute([$key]);
    if($q->fetchColumn()){
        $pdo->prepare("UPDATE music_metadata_migration_issues_v1 SET reason=?,details_json=?,status='open',updated_at=? WHERE issue_key=?")->execute([$reason,music_song_metadata_v1_json($details),$now,$key]);
    }else{
        $pdo->prepare("INSERT INTO music_metadata_migration_issues_v1 (issue_key,entity_type,entity_id,reason,details_json,status,created_at,updated_at) VALUES (?,?,?,?,?,'open',?,?)")->execute([$key,$type,$id,$reason,music_song_metadata_v1_json($details),$now,$now]);
    }
}

function music_song_metadata_v1_import_legacy(PDO $pdo,array $catalog): void
{
    music_song_metadata_v1_require_transaction($pdo);
    $workspaceId=(int)$catalog['workspace_id'];$catalogId=(int)$catalog['id'];
    $metadata=music_song_metadata_v1_defaults($catalog);
    $work=music_song_metadata_v1_work_create($pdo,$workspaceId,music_song_metadata_v1_work_fields($metadata),0,'legacy_import');
    $metadata['work_id']=$work['work_id'];$metadata['work_revision']=1;
    $recordingId=music_song_metadata_v1_uuid();$now=gmdate('Y-m-d H:i:s');
    $row=['recording_id'=>$recordingId,'workspace_id'=>$workspaceId,'catalog_track_id'=>$catalogId,
        'source_track_id'=>(int)($catalog['source_track_id']??0)?:null,'work_id'=>$work['work_id'],
        'current_asset_id'=>music_song_metadata_v1_asset($pdo,$workspaceId,$recordingId,$catalog),
        'revision'=>1,'metadata_json'=>music_song_metadata_v1_json($metadata),'archived_at'=>null,'created_at'=>$now,'updated_at'=>$now];
    $pdo->prepare('INSERT INTO music_recordings_v1 (recording_id,workspace_id,catalog_track_id,source_track_id,work_id,current_asset_id,revision,metadata_json,archived_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute(array_values($row));
    music_song_metadata_v1_snapshot($pdo,$row,$catalog,0,'legacy_import','import');
}

/** Read-only migration preview. No defaults, parties or issue rows are created. */
function music_song_metadata_v1_report(PDO $pdo): array
{
    $report=['schema_ready'=>music_song_metadata_v1_schema_ready($pdo),'catalog_ready'=>false,
        'checked'=>0,'migratable'=>0,'already_migrated'=>0,'staged'=>0,'issues'=>[],'issue_counts'=>[]];
    if(array_diff(['id','workspace_id','source_track_id'],music_song_metadata_v1_columns($pdo,'artist_catalog_tracks_v181'))
        ||!in_array('id',music_song_metadata_v1_columns($pdo,'artist_workspaces_v181'),true)){
        $report['prerequisite']='Install the Music Workspace catalog before reviewing or migrating song metadata.';
        return $report;
    }
    $report['catalog_ready']=true;
    $recordingsReady=!array_diff(['catalog_track_id','workspace_id','source_track_id'],music_song_metadata_v1_columns($pdo,'music_recordings_v1'));
    $addIssue=static function(string $entity,int $id,string $reason,array $details=[])use(&$report):void{
        $report['staged']++;$report['issue_counts'][$reason]=($report['issue_counts'][$reason]??0)+1;
        if(count($report['issues'])<200)$report['issues'][]=['entity'=>$entity,'id'=>$id,'reason'=>$reason]+$details;
    };
    $rows=$pdo->query('SELECT id,workspace_id,source_track_id FROM artist_catalog_tracks_v181 ORDER BY workspace_id,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as $catalog){
        $report['checked']++;$catalogId=(int)$catalog['id'];$workspaceId=(int)($catalog['workspace_id']??0);
        $reason=music_song_metadata_v1_mapping_issue($pdo,$catalog);$assigned=false;
        if($recordingsReady){
            $q=$pdo->prepare('SELECT workspace_id,source_track_id FROM music_recordings_v1 WHERE catalog_track_id=?');$q->execute([$catalogId]);$assigned=$q->fetch(PDO::FETCH_ASSOC);
            if($assigned&&(int)$assigned['workspace_id']!==$workspaceId)$reason='recording_workspace_conflict';
            elseif($assigned&&(int)($assigned['source_track_id']??0)!==(int)($catalog['source_track_id']??0))$reason='recording_backing_conflict';
        }
        if($reason)$addIssue('catalog',$catalogId,$reason,['workspace_id'=>$workspaceId,'source_track_id'=>(int)($catalog['source_track_id']??0)]);
        elseif($assigned)$report['already_migrated']++;
        else $report['migratable']++;
    }
    if(in_array('id',music_song_metadata_v1_columns($pdo,'tracks'),true)){
        $legacy=$pdo->query('SELECT t.id FROM tracks t LEFT JOIN artist_catalog_tracks_v181 c ON c.source_track_id=t.id WHERE c.id IS NULL ORDER BY t.id')->fetchAll(PDO::FETCH_COLUMN)?:[];
        foreach($legacy as $id)$addIssue('player',(int)$id,'player_without_catalog_link');
    }
    $report['issues_truncated']=$report['staged']>count($report['issues']);
    if($report['schema_ready']){
        $report['open_issue_records']=(int)$pdo->query("SELECT COUNT(*) FROM music_metadata_migration_issues_v1 WHERE status='open'")->fetchColumn();
        $report['counts']=[];
        foreach(['music_recordings_v1','music_works_v1','music_parties_v1','music_assets_v1','music_metadata_revisions_v1','music_work_revisions_v1'] as $table){
            $report['counts'][$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        }
    }
    return $report;
}

/** Additive and rerunnable. Existing ambiguous mappings are reported, not repaired. */
function music_song_metadata_v1_install(PDO $pdo): array
{
    if($pdo->inTransaction())throw new LogicException('Install music metadata outside an active transaction.');
    $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach(music_song_metadata_v1_schema_statements($driver) as $sql)$pdo->exec($sql);
    if($driver==='mysql'){
        // A rerun can also add an index to an already-created ML1 table.
        $q=$pdo->query("SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'music_%_v1'");
        $existingIndexes=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $index)$existingIndexes[$index['TABLE_NAME']][$index['INDEX_NAME']]=true;
        foreach(music_song_metadata_v1_indexes() as $table=>$indexes)foreach($indexes as $name=>$columns){
            if(!isset($existingIndexes[$table][$name]))$pdo->exec('CREATE INDEX '.$name.' ON '.$table.' ('.implode(',',$columns).')');
        }
    }
    music_song_metadata_v1_require_schema($pdo);
    $report=['schema_ready'=>true,'checked'=>0,'migrated'=>0,'already_migrated'=>0,'staged'=>0,'issues'=>[]];
    if(!in_array('workspace_id',music_song_metadata_v1_columns($pdo,'artist_catalog_tracks_v181'),true)){
        throw new RuntimeException('Install the Music Workspace catalog before migrating song metadata.',503);
    }
    $rows=$pdo->query('SELECT id,workspace_id FROM artist_catalog_tracks_v181 ORDER BY workspace_id,id')->fetchAll(PDO::FETCH_ASSOC)?:[];
    foreach($rows as $candidate){
        $report['checked']++;$catalogId=(int)$candidate['id'];$workspaceId=(int)($candidate['workspace_id']??0);
        $pdo->beginTransaction();
        try{
            // The same workspace row lock as music_catalog_transaction serializes
            // migration with saves. Missing/unassigned workspaces still get staged.
            $lock=$driver==='mysql'?' FOR UPDATE':'';
            $q=$pdo->prepare('SELECT id FROM artist_workspaces_v181 WHERE id=?'.$lock);$q->execute([$workspaceId]);$q->fetchColumn();
            $q=$pdo->prepare('SELECT * FROM artist_catalog_tracks_v181 WHERE id=?'.$lock);$q->execute([$catalogId]);$catalog=$q->fetch(PDO::FETCH_ASSOC);
            if(!$catalog){$pdo->commit();continue;}
            if((int)($catalog['workspace_id']??0)!==$workspaceId)throw new RuntimeException('A catalog workspace changed during migration. Run the upgrade again to review the current mapping.',409);
            $reason=music_song_metadata_v1_mapping_issue($pdo,$catalog);
            $existing=$pdo->prepare('SELECT workspace_id,source_track_id FROM music_recordings_v1 WHERE catalog_track_id=?'.$lock);$existing->execute([$catalogId]);$assigned=$existing->fetch(PDO::FETCH_ASSOC);
            if($assigned&&(int)$assigned['workspace_id']!==$workspaceId)$reason='recording_workspace_conflict';
            elseif($assigned&&(int)($assigned['source_track_id']??0)!==(int)($catalog['source_track_id']??0))$reason='recording_backing_conflict';
            if($reason){
                music_song_metadata_v1_migration_issue($pdo,'catalog',$catalogId,$reason,['workspace_id'=>$workspaceId,'source_track_id'=>(int)($catalog['source_track_id']??0)]);
                $report['staged']++;if(count($report['issues'])<200)$report['issues'][]=['entity'=>'catalog','id'=>$catalogId,'reason'=>$reason];
            }elseif($assigned){
                $report['already_migrated']++;
                $pdo->prepare("UPDATE music_metadata_migration_issues_v1 SET status='resolved',updated_at=? WHERE issue_key=?")->execute([gmdate('Y-m-d H:i:s'),'catalog:'.$catalogId]);
            }else{
                music_song_metadata_v1_import_legacy($pdo,$catalog);$report['migrated']++;
                $pdo->prepare("UPDATE music_metadata_migration_issues_v1 SET status='resolved',updated_at=? WHERE issue_key=?")->execute([gmdate('Y-m-d H:i:s'),'catalog:'.$catalogId]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    // Unlinked legacy Player rows remain untouched until an explicit mapping is made.
    if(in_array('id',music_song_metadata_v1_columns($pdo,'tracks'),true)){
        $legacy=$pdo->query('SELECT t.id FROM tracks t LEFT JOIN artist_catalog_tracks_v181 c ON c.source_track_id=t.id WHERE c.id IS NULL ORDER BY t.id')->fetchAll(PDO::FETCH_COLUMN)?:[];
        foreach($legacy as $id){
            music_song_metadata_v1_migration_issue($pdo,'player',(int)$id,'player_without_catalog_link',[]);
            $report['staged']++;if(count($report['issues'])<200)$report['issues'][]=['entity'=>'player','id'=>(int)$id,'reason'=>'player_without_catalog_link'];
        }
        $pdo->exec("UPDATE music_metadata_migration_issues_v1 SET status='resolved' WHERE entity_type='player' AND EXISTS (SELECT 1 FROM artist_catalog_tracks_v181 c WHERE c.source_track_id=music_metadata_migration_issues_v1.entity_id)");
    }
    $report['open_issues']=(int)$pdo->query("SELECT COUNT(*) FROM music_metadata_migration_issues_v1 WHERE status='open'")->fetchColumn();
    return $report;
}
